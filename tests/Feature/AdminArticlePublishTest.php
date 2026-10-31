<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Enums\SiteStatus;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Models\Article;
use App\Models\User;
use App\Services\Content\PublishScheduler;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\SiteGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminArticlePublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_article_published_from_the_admin_goes_live_and_can_be_taken_down(): void
    {
        $this->fakeAi();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $site = $this->makeSite(['domain' => 'manual-'.uniqid().'.com.br', 'status' => SiteStatus::Live, 'launched_at' => now()]);
        app(SiteGenerator::class)->generatePages($site);
        app(StaticSiteBuilder::class)->buildAll($site->fresh());
        $dir = StaticSiteBuilder::dirFor($site);

        Livewire::test(CreateArticle::class)->fillForm([
            'site_id' => $site->id, 'title' => 'Dicas manuais', 'slug' => 'dicas-manuais', 'type' => 'guide',
            'status' => ArticleStatus::Published->value, 'body' => '<p>Texto escrito à mão para o blog.</p>',
        ])->call('create')->assertHasNoFormErrors();

        $article = Article::where('slug', 'dicas-manuais')->sole();
        $this->assertNotNull($article->published_at, 'published without a date means now');
        $this->assertFileExists("{$dir}/blog/dicas-manuais/index.html");
        $this->assertStringContainsString('/blog/dicas-manuais/', file_get_contents("{$dir}/blog/index.html"));
        $this->assertStringContainsString('/blog/dicas-manuais/', file_get_contents("{$dir}/sitemap.xml"));

        Livewire::test(EditArticle::class, ['record' => $article->id])
            ->fillForm(['status' => ArticleStatus::Deleted->value])->call('save')->assertHasNoFormErrors();

        $this->assertFileDoesNotExist("{$dir}/blog/dicas-manuais/index.html");
        $this->assertStringNotContainsString('/blog/dicas-manuais/', file_get_contents("{$dir}/blog/index.html"));
    }

    public function test_a_published_ai_article_shows_on_the_home_page_of_any_layout(): void
    {
        $this->fakeAi();
        // Sectioned game layouts have no "latest articles" section of their own.
        $site = $this->makeSite(['domain' => 'pool-'.uniqid().'.com.br', 'site_type' => 'game', 'status' => SiteStatus::Live, 'launched_at' => now()]);
        app(SiteGenerator::class)->generatePages($site);
        app(StaticSiteBuilder::class)->buildAll($site->fresh());
        $dir = StaticSiteBuilder::dirFor($site);
        $this->assertStringNotContainsString('article-list', file_get_contents("{$dir}/index.html"), 'no empty section before there are articles');

        $article = $site->articles()->create(['title' => 'Primeiro artigo', 'slug' => 'primeiro-artigo', 'type' => 'guide', 'status' => ArticleStatus::Queued, 'body' => '<p>Olá</p>']);
        app(PublishScheduler::class)->publish($article);

        $home = file_get_contents("{$dir}/index.html");
        $this->assertStringContainsString('href="/blog/primeiro-artigo/"', $home);
        $this->assertStringContainsString('article-list', $home);
        $menu = fn () => preg_match('#<div class="nav__menu[^"]*">(.*?)</nav>#s', file_get_contents("{$dir}/index.html"), $m) ? $m[1] : '';
        $this->assertStringNotContainsString('href="/blog/"', $menu(), 'the blog joins the menu once it has enough posts');

        foreach (['segundo-artigo', 'terceiro-artigo'] as $slug) {
            app(PublishScheduler::class)->publish($site->articles()->create(['title' => $slug, 'slug' => $slug, 'type' => 'guide', 'status' => ArticleStatus::Queued, 'body' => '<p>Olá</p>']));
        }
        $this->assertStringContainsString('href="/blog/"', $menu());
    }
}
