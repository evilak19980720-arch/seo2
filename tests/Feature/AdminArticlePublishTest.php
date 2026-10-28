<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Enums\SiteStatus;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Models\Article;
use App\Models\User;
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
}
