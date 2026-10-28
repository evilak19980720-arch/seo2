<?php

namespace Tests\Feature;

use App\Models\GenerationTask;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\SiteGenerator;
use App\Services\Theme\ReferenceAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RichTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyzer_reads_the_section_structure(): void
    {
        Http::fake(['ref.test/*' => Http::response('<html><body><header><nav class="menu"><a href="/">Início</a><a href="/eventos">Eventos</a></nav></header>
            <div class="notice-bar"><marquee>Bem-vindo</marquee></div>
            <div class="swiper banner"><img src="a.jpg"></div>
            <section class="game-list">'.str_repeat('<div><img src="g.jpg"><p>Jogo</p></div>', 8).'</section>
            <section id="faq"><h2>FAQ</h2></section>
            <div class="app-download">Baixar</div>
            <footer class="news">x</footer></body></html>')]);

        $ref = app(ReferenceAnalyzer::class)->analyze('https://ref.test/');

        $this->assertSame(['notice', 'banner', 'card_grid', 'faq', 'download'], $ref['structure']);
    }

    public function test_design_with_extra_pages_styles_and_new_blocks_renders_a_full_site(): void
    {
        $this->fakeAi(['design' => ['design' => [
            'colors' => ['primary' => '#22c55e', 'secondary' => '#0b1220', 'accent' => '#facc15', 'background' => '#0f172a', 'surface' => '#111c33', 'text' => '#e5e7eb', 'muted' => '#94a3b8'],
            'style' => ['background' => 'glow', 'cards' => 'glass', 'buttons' => 'gradient', 'headings' => 'zzz'],
            'nav' => 'portal',
            'menu' => ['product', 'extra_1', 'guide', 'extra_2', 'blog'],
            'extra_pages' => [['label' => 'Eventos', 'slug' => 'eventos'], ['label' => 'Ranking', 'slug' => 'ranking'], ['label' => '', 'slug' => 'x']],
            'pages' => ['home' => [
                ['block' => 'notice', 'variant' => 'marquee'], ['block' => 'banner', 'variant' => 'slider'], ['block' => 'stats', 'variant' => 'strip'],
                ['block' => 'card_grid', 'variant' => 'tiles'], ['block' => 'steps', 'variant' => 'timeline'], ['block' => 'download', 'variant' => 'split'],
            ]],
        ]]]);
        $site = $this->makeSite(['domain' => 'portal.com.br']);

        app(SiteGenerator::class)->run(GenerationTask::create(['site_id' => $site->id, 'type' => 'full']));
        $site->refresh();

        $this->assertSame(['background' => 'glow', 'cards' => 'glass', 'buttons' => 'gradient', 'headings' => 'normal'], $site->design_config['style']);
        $this->assertCount(2, $site->design_config['extra_pages']);
        $this->assertSame('eventos', $site->pages()->where('type', 'extra_1')->value('slug'));

        $dir = StaticSiteBuilder::dirFor($site);
        $this->assertFileExists("{$dir}/eventos/index.html");
        $this->assertFileExists("{$dir}/ranking/index.html");
        $home = file_get_contents("{$dir}/index.html");
        $this->assertStringContainsString('class="st-background-glow st-cards-glass st-buttons-gradient st-headings-normal"', $home);
        $this->assertStringContainsString('nav nav--portal', $home);
        $this->assertStringContainsString('notice notice--marquee', $home);
        $this->assertStringContainsString('banner banner--slider', $home);
        $this->assertStringContainsString('href="/eventos/"', $home);
        $this->assertSame(1, substr_count($home, '<h1'), 'the banner after the notice strip carries the h1');
        $this->assertStringContainsString('<loc>https://portal.com.br/eventos/</loc>', file_get_contents("{$dir}/sitemap.xml"));
    }

    public function test_dropped_extra_pages_are_removed(): void
    {
        $this->fakeAi(['design' => ['design' => ['extra_pages' => [['label' => 'Eventos', 'slug' => 'eventos']]]]]);
        $site = $this->makeSite();
        $generator = app(SiteGenerator::class);
        $generator->generateDesign($site);
        $generator->generatePages($site->fresh());
        $this->assertTrue($site->pages()->where('type', 'extra_1')->exists());

        $site->update(['design_config' => array_merge($site->fresh()->design_config, ['extra_pages' => []])]);
        $generator->generatePages($site->fresh());

        $this->assertFalse($site->pages()->where('type', 'extra_1')->exists());
    }
}
