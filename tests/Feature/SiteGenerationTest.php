<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Models\GenerationTask;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\SiteGenerator;
use App\Services\Theme\Color;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_site_generation_builds_valid_static_site(): void
    {
        $this->fakeAi();
        $site = $this->makeSite(['domain' => 'galaxy-run.com.br']);
        $task = GenerationTask::create(['site_id' => $site->id, 'type' => 'full']);

        app(SiteGenerator::class)->run($task);

        $site->refresh();
        $this->assertSame(SiteStatus::Live, $site->status);
        $this->assertSame('succeeded', $task->fresh()->status);
        $this->assertStringContainsString('Galaxy Run', $site->title);
        $this->assertLessThanOrEqual(60, mb_strlen($site->title));

        // Design config was repaired against the whitelist.
        $design = $site->design_config;
        $this->assertSame('system', $design['fonts']['heading']);
        $this->assertGreaterThanOrEqual(4.5, Color::contrast($design['colors']['text'], $design['colors']['background']));
        $this->assertSame('accordion', collect($design['pages']['home'])->firstWhere('block', 'faq')['variant']);
        $this->assertFalse(collect($design['pages']['home'])->contains('block', 'nonsense'));
        $this->assertNull($site->custom_css, 'invalid AI custom CSS must be dropped');
        $this->assertSame(1, $site->designVersions()->count());

        // Pages: AI slugs are cleaned, unknown ones fall back to defaults, legal pages get text.
        $this->assertSame('sobre-o-jogo', $site->pages()->where('type', 'product')->value('slug'));
        $this->assertSame('perguntas-frequentes', $site->pages()->where('type', 'faq')->value('slug'));
        $this->assertStringContainsString('LGPD', json_encode($site->pages()->where('type', 'privacy')->value('content')));
        $this->assertGreaterThan(0, $site->keywords()->count());

        $dir = StaticSiteBuilder::dirFor($site);
        foreach (['index.html', 'sobre-o-jogo/index.html', 'guia/como-jogar/index.html', '404.html', 'robots.txt', 'sitemap.xml', 'feed.xml', 'favicon.ico', 'apple-touch-icon.png', 'icon-512.png', 'site.webmanifest', 'assets/site.css'] as $file) {
            $this->assertFileExists("{$dir}/{$file}");
        }

        $home = file_get_contents("{$dir}/index.html");
        $this->assertStringContainsString('<html lang="pt-BR">', $home);
        $this->assertStringContainsString('<link rel="canonical" href="https://galaxy-run.com.br/">', $home);
        $this->assertStringContainsString('"@type":"VideoGame"', $home);
        $this->assertStringNotContainsString('aggregateRating', $home);
        $this->assertStringNotContainsString('offers', $home);
        $this->assertSame(1, substr_count($home, '<h1'));
        $this->assertStringNotContainsString('meta name="keywords"', $home);

        $guide = file_get_contents("{$dir}/guia/como-jogar/index.html");
        $this->assertStringNotContainsString('<script>x()', $guide, 'AI HTML must be sanitized');
        $this->assertStringContainsString('BreadcrumbList', $guide);

        $this->assertStringContainsString('Sitemap: https://galaxy-run.com.br/sitemap.xml', file_get_contents("{$dir}/robots.txt"));
        $this->assertStringContainsString('<loc>https://galaxy-run.com.br/sobre-o-jogo/</loc>', file_get_contents("{$dir}/sitemap.xml"));
    }

    public function test_generation_without_ai_falls_back_to_defaults(): void
    {
        $site = $this->makeSite();
        $task = GenerationTask::create(['site_id' => $site->id, 'type' => 'rebuild']);
        Settings::set('auto_launch', false);

        app(SiteGenerator::class)->run($task);

        $site->refresh();
        $this->assertSame(SiteStatus::Preview, $site->status);
        $this->assertNotEmpty($site->design_config['pages']['home']);
        $this->assertSame('Sobre o jogo', $site->pages()->where('type', 'product')->value('title'));
        $this->assertFileExists(StaticSiteBuilder::dirFor($site).'/index.html');
    }

    public function test_pages_declare_no_cross_site_hreflang(): void
    {
        $site = $this->makeSite(['domain' => 'jogo.com.br', 'status' => 'live']);
        app(SiteGenerator::class)->generatePages($site);

        app(StaticSiteBuilder::class)->buildAll($site->fresh());

        $this->assertStringNotContainsString('hreflang', file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html'));
    }
}
