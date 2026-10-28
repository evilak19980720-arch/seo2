<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Enums\ArticleTaskStatus;
use App\Enums\SiteStatus;
use App\Jobs\GenerateArticleJob;
use App\Models\Article;
use App\Models\ArticleTask;
use App\Models\User;
use App\Services\Content\ArticlePipeline;
use App\Services\Content\PublishScheduler;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\SiteGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticlePipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function liveSite()
    {
        $site = $this->makeSite(['domain' => 'galaxy.com.br', 'status' => SiteStatus::Live, 'launched_at' => now()]);
        app(SiteGenerator::class)->generatePages($site);
        app(StaticSiteBuilder::class)->buildAll($site->fresh());
        $site->keywords()->create(['keyword' => 'como jogar galaxy run', 'source' => 'gsc', 'impressions' => 50]);
        $site->keywords()->create(['keyword' => 'galaxy run dicas', 'source' => 'ai']);

        return $site->fresh(['domain', 'brandProfile']);
    }

    public function test_article_passes_guardrails_then_publishes_incrementally(): void
    {
        $this->fakeAi();
        $site = $this->liveSite();

        $article = app(ArticlePipeline::class)->generateNext($site);

        $this->assertSame(ArticleStatus::Queued, $article->status, (string) $article->block_reason);
        $this->assertSame('como jogar galaxy run', $article->keyword->keyword, 'Search Console keywords go first');
        $this->assertNotNull($article->prompt_id);
        $this->assertSame('written', $article->keyword->fresh()->status->value);

        $published = app(PublishScheduler::class)->publishNext($site);
        $this->assertSame($article->id, $published->id);

        $dir = StaticSiteBuilder::dirFor($site);
        $html = file_get_contents("{$dir}/blog/como-jogar-galaxy-run/index.html");
        $this->assertStringContainsString('"@type":"Article"', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://galaxy.com.br/blog/como-jogar-galaxy-run/">', $html);
        $this->assertStringContainsString('/blog/como-jogar-galaxy-run/', file_get_contents("{$dir}/blog/index.html"));
        $this->assertStringContainsString('/blog/como-jogar-galaxy-run/', file_get_contents("{$dir}/index.html"), 'home "latest articles" is rebuilt');
        $this->assertStringContainsString('<loc>https://galaxy.com.br/blog/como-jogar-galaxy-run/</loc>', file_get_contents("{$dir}/sitemap.xml"));
        $this->assertStringContainsString('como-jogar-galaxy-run', file_get_contents("{$dir}/feed.xml"));
    }

    public function test_article_queue_shows_each_step_and_the_result(): void
    {
        $this->fakeAi();
        $site = $this->liveSite();
        $seen = [];
        ArticleTask::updating(function ($task) use (&$seen) {
            if ($task->isDirty('status')) {
                $seen[] = $task->status->value;
            }
        });

        GenerateArticleJob::dispatchSync($site);

        $task = ArticleTask::sole();
        $this->assertSame(['generating', 'polishing', 'reviewing', 'checking', 'done'], $seen);
        $this->assertSame('como jogar galaxy run', $task->keyword);
        $this->assertNotNull($task->article_id);
        $this->assertNotNull($task->finished_at);

        // Nothing left to write: the next run is shown as skipped, with the reason.
        $site->keywords()->update(['status' => 'written']);
        GenerateArticleJob::dispatchSync($site);
        $this->assertSame(ArticleTaskStatus::Skipped, ArticleTask::latest('id')->first()->status);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('https://admin.test/admin/article-tasks')->assertOk()->assertSee('文章队列')->assertSee('已完成');
    }

    public function test_weekly_cap_by_site_age(): void
    {
        $site = $this->liveSite();
        $scheduler = app(PublishScheduler::class);
        $this->assertSame(3, $scheduler->weeklyCap($site));

        foreach (range(1, 4) as $i) {
            Article::create(['site_id' => $site->id, 'title' => "A{$i}", 'slug' => "a{$i}", 'type' => 'guide', 'body' => '<p>x</p>', 'status' => $i <= 3 ? 'published' : 'queued', 'published_at' => $i <= 3 ? now()->subDay() : null]);
        }
        $this->assertNull($scheduler->publishNext($site), 'cap of 3/week reached');

        $site->update(['launched_at' => now()->subMonths(4)]);
        $this->assertSame(5, $scheduler->weeklyCap($site->fresh()));
        $this->assertNotNull($scheduler->publishNext($site->fresh()));
    }

    public function test_guardrails_block_wrong_language_leaks_and_unsupported_facts(): void
    {
        $english = str_repeat('<h2>Section</h2><p>This is the best racing game and you will love it because the controls are simple and the levels are fun for everyone who plays it every day.</p>', 40);
        $this->fakeAi(['article' => ['title' => 'Guide', 'html' => $english], 'polished' => $english]);
        $article = app(ArticlePipeline::class)->generateNext($this->liveSite());

        $this->assertSame(ArticleStatus::Blocked, $article->status);
        $this->assertStringContainsString('语言与站点语言不一致', $article->block_reason);
    }

    public function test_fact_check_failure_blocks(): void
    {
        $this->fakeAi(['factcheck' => ['supported' => false, 'unsupported_claims' => ['Ganhou o prêmio de melhor jogo de 2025']]]);
        $article = app(ArticlePipeline::class)->generateNext($this->liveSite());

        $this->assertSame(ArticleStatus::Blocked, $article->status);
        $this->assertStringContainsString('事实核对未通过', $article->block_reason);
    }

    public function test_near_duplicate_is_blocked(): void
    {
        $this->fakeAi();
        $site = $this->liveSite();
        $first = app(ArticlePipeline::class)->generateNext($site);
        $this->assertSame(ArticleStatus::Queued, $first->status);

        $second = app(ArticlePipeline::class)->generateNext($site);
        $this->assertSame(ArticleStatus::Blocked, $second->status);
        $this->assertStringContainsString('相似度', $second->block_reason);
    }

    public function test_low_completeness_disables_auto_articles(): void
    {
        $site = $this->liveSite();
        $site->brandProfile->update(['description' => 'curto', 'media' => [], 'faq' => [], 'specs' => []]);

        $this->assertSame('品牌资料完整度不足', app(ArticlePipeline::class)->ineligibleReason($site->fresh('brandProfile')));
    }

    public function test_deleted_article_returns_410(): void
    {
        $this->fakeAi();
        $site = $this->liveSite();
        app(ArticlePipeline::class)->generateNext($site);
        $article = app(PublishScheduler::class)->publishNext($site);

        $article->update(['status' => ArticleStatus::Deleted]);
        app(PublishScheduler::class)->afterChange($article);

        $this->get('https://galaxy.com.br/blog/como-jogar-galaxy-run/')->assertStatus(410);
        $this->get('https://galaxy.com.br/blog/nao-existe/')->assertStatus(404);
    }
}
