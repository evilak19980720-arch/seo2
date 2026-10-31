<?php

namespace Tests\Feature;

use App\Models\GenerationTask;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Render\ThemeContext;
use App\Services\Sites\SiteGenerator;
use App\Services\Theme\ReferenceAnalyzer;
use App\Services\Theme\SiteStyle;
use App\Services\Theme\Variants;
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
        $this->assertStringContainsString('class="'.collect($style['style'])->map(fn ($v, $k) => "st-{$k}-{$v}")->implode(' ').(($frame = Variants::frame($site->design_config)) ? " fr-{$frame}" : '').' pg-home', $home);
        $variantClass = fn ($block, $prefix, $variant) => $prefix.' '.$prefix.'--'.Variants::base($block, $variant);
        $this->assertStringContainsString($variantClass('nav', 'nav', $site->design_config['nav']), $home);
        $this->assertStringContainsString('notice--'.collect($site->design_config['pages']['home'])->firstWhere('block', 'notice')['variant'], $home);
        $this->assertStringContainsString('banner--'.collect($site->design_config['pages']['home'])->firstWhere('block', 'banner')['variant'], $home);
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
        $this->assertStringContainsString('<li><a href="/" aria-current="page">Início</a></li>', $home, 'the current page is marked');
        $this->assertStringContainsString('<a class="mobile-bar__cta" href="https://play.google.com/x"', $home, 'the brand link is the raised button');
        $this->assertSame('Perguntas', ThemeContext::shortLabel('Perguntas frequentes sobre o jogo', 'Perguntas frequentes'));
        $this->assertSame('Como jogar', ThemeContext::shortLabel('Guia completo de como jogar', 'Como jogar'));
        // The footer still lists every page.
        $this->assertMatchesRegularExpression('#<footer.*perguntas-frequentes.*</footer>#s', $home);
        // Footer links come in groups that fold on phones: the submenu item heads its own group, legal comes last.
        preg_match('#<footer.*</footer>#s', $home, $footer);
        preg_match_all('#<details class="footer__group"(?: data-fold)? open><summary class="footer__title">([^<]+)</summary>#', $footer[0], $titles);
        $this->assertGreaterThanOrEqual(2, count($titles[1]));
        $this->assertSame('Legal', end($titles[1]));
        $this->assertMatchesRegularExpression('#<summary class="footer__title">[^<]+</summary><ul>\s*<li><a href="/[^"]*/">[^<]+</a></li><li><a href="/perguntas-frequentes/">#', $footer[0]);
        $this->assertSame(1, substr_count($footer[0], 'href="/perguntas-frequentes/"'), 'each page once');
    }

    public function test_phone_parts_change_the_bottom_bar_footer_links_and_hero_order(): void
    {
        $this->fakeAi(['design' => ['design' => ['menu' => ['product', 'guide', 'blog'], 'navigation' => ['mobile_bar' => true]]]]);
        $site = $this->makeSite(['domain' => 'parts.com.br'], ['cta_links' => [['label' => 'Google Play', 'url' => 'https://play.google.com/x']]]);
        app(SiteGenerator::class)->run(GenerationTask::create(['site_id' => $site->id, 'type' => 'full']));
        $render = function (array $navigation, array $style) use ($site) {
            $config = $site->fresh()->design_config;
            $config['navigation'] = $navigation + $config['navigation'];
            $config['style'] = $style + $config['style'];
            $site->forceFill(['design_config' => $config])->save();
            app(StaticSiteBuilder::class)->buildAll($site->fresh());

            return file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');
        };

        $home = $render(['mobile_bar_items' => 'none'], ['mlinks' => 'inline', 'mhorder' => 'cta-top', 'mcookie' => 'top']);
        $this->assertStringNotContainsString('class="mobile-bar', $home, 'no bottom bar: the header menu does the work');
        $this->assertStringNotContainsString('has-mobile-bar', $home);
        $this->assertStringNotContainsString('data-fold', $home, 'links shown, never folded (and no folding script)');
        $this->assertMatchesRegularExpression('#<body class="[^"]*st-mhorder-cta-top[^"]*st-mlinks-inline[^"]*st-mcookie-top#', $home);

        $home = $render(['mobile_bar_items' => '3', 'mobile_bar_style' => 'raised'], ['mlinks' => 'fold']);
        preg_match('#<nav class="mobile-bar[^"]*".*?</nav>#s', $home, $bar);
        $this->assertSame(3, substr_count($bar[0], '<a '), 'home, one page and the brand button');
        $this->assertStringContainsString('mobile-bar__cta', $bar[0]);
        $this->assertStringContainsString('data-fold open', $home);
    }

    public function test_cookie_notice_only_shows_where_something_sets_cookies(): void
    {
        $this->fakeAi(['design' => ['design' => ['menu' => ['product', 'guide', 'blog']]]]);
        $site = $this->makeSite(['domain' => 'cookies.com.br']);
        app(SiteGenerator::class)->run(GenerationTask::create(['site_id' => $site->id, 'type' => 'full']));
        $home = fn () => file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');

        $this->assertStringNotContainsString('id="cookie"', $home(), 'a plain static site sets no cookies');
        $this->assertStringNotContainsString('localStorage', $home());

        $site->forceFill(['ga4_measurement_id' => 'G-TEST123'])->save();
        app(StaticSiteBuilder::class)->buildAll($site->fresh());
        $this->assertStringContainsString('id="cookie"', $home(), 'analytics sets cookies: ask first');
    }
}
