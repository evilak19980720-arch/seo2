<?php

namespace Tests\Feature;

use App\Models\GenerationTask;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\SiteGenerator;
use App\Services\Theme\ReferenceAnalyzer;
use App\Services\Theme\SiteStyle;
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

        $style = SiteStyle::for($site->domain_id)->apply([]);
        $this->assertSame($style['style'], $site->design_config['style'], 'the look is the site\'s own');
        $this->assertCount(2, $site->design_config['extra_pages']);
        $this->assertSame('eventos', $site->pages()->where('type', 'extra_1')->value('slug'));

        $dir = StaticSiteBuilder::dirFor($site);
        $this->assertFileExists("{$dir}/eventos/index.html");
        $this->assertFileExists("{$dir}/ranking/index.html");
        $home = file_get_contents("{$dir}/index.html");
        $this->assertStringContainsString('class="'.collect($style['style'])->map(fn ($v, $k) => "st-{$k}-{$v}")->implode(' ').'"', $home);
        $this->assertStringContainsString('nav nav--'.$site->design_config['nav'], $home);
        $this->assertStringContainsString('notice notice--'.collect($site->design_config['pages']['home'])->firstWhere('block', 'notice')['variant'], $home);
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

    public function test_analyzer_reads_navigation_features_without_account_links(): void
    {
        Http::fake([
            'nav.test/s.css' => Http::response('.header{position:fixed;top:0} .tabbar{position:fixed;bottom:0}'),
            'nav.test/*' => Http::response('<html><head><link rel="stylesheet" href="/s.css"></head><body>
            <header class="header"><nav><ul>
              <li><a href="/">Início</a></li>
              <li><a href="/jogos">Jogos</a><ul><li><a href="/slots">Slots</a></li><li><a href="/cartas">Cartas</a></li></ul></li>
              <li><a href="/promo">Promoções</a></li><li><a href="/login">Entrar</a></li>
            </ul></nav><a class="btn" href="/login">Login</a><a class="btn" href="/cadastro">Cadastre-se</a><a class="btn-download" href="/app">Baixar App</a></header>
            <div class="tabbar"><a href="/">Início</a></div></body></html>'),
        ]);

        $nav = app(ReferenceAnalyzer::class)->analyze('https://nav.test/')['navigation'];

        $this->assertSame([['label' => 'Início', 'children' => []], ['label' => 'Jogos', 'children' => ['Slots', 'Cartas']], ['label' => 'Promoções', 'children' => []]], $nav['items']);
        $this->assertTrue($nav['dropdown']);
        $this->assertSame(1, $nav['buttons'], 'login/register buttons are not counted');
        $this->assertTrue($nav['mobile_bar']);
        $this->assertTrue($nav['sticky']);
        $this->assertTrue($nav['home_link']);
    }

    public function test_navigation_renders_dropdowns_buttons_and_mobile_bar(): void
    {
        $this->fakeAi(['design' => ['design' => [
            'menu' => ['product', 'guide', 'blog'],
            'navigation' => ['submenu' => ['product' => ['faq', 'media', 'product', 'nonsense']], 'buttons' => 2, 'mobile_bar' => true, 'sticky' => false, 'home_link' => true],
        ]]]);
        $site = $this->makeSite(['domain' => 'nav.com.br'], ['cta_links' => [['label' => 'Google Play', 'url' => 'https://play.google.com/x'], ['label' => 'App Store', 'url' => 'https://apps.apple.com/x']]]);

        app(SiteGenerator::class)->run(GenerationTask::create(['site_id' => $site->id, 'type' => 'full']));
        $site->refresh();

        $this->assertSame(['product' => ['faq', 'media']], $site->design_config['navigation']['submenu']);
        $home = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');
        $this->assertStringContainsString('class="nav__has-sub"', $home);
        $this->assertStringContainsString('<ul class="nav__sub"><li><a href="/perguntas-frequentes/">', $home);
        $this->assertStringContainsString('nav--static', $home);
        $this->assertSame(2, substr_count($home, 'btn nav__cta'));
        $this->assertMatchesRegularExpression('#class="mobile-bar mobile-bar--[a-z]+"#', $home);
        $this->assertStringContainsString('has-mobile-bar', $home);
        $this->assertStringContainsString('<li><a href="/">Início</a></li>', $home);
        // The footer still lists every page.
        $this->assertMatchesRegularExpression('#<footer.*perguntas-frequentes.*</footer>#s', $home);
    }
}
