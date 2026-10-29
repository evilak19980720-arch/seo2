<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\Alert;
use App\Models\GenerationTask;
use App\Services\Content\SeoRules;
use App\Services\Render\MarkupScrambler;
use App\Services\Render\SchemaValidator;
use App\Services\Render\SiteRenderer;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\SiteGenerator;
use App\Services\Stats\SearchConsoleSync;
use App\Services\Theme\DesignConfigValidator;
use App\Services\Theme\Variants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeoOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rules_detect_weak_meta_seasons_claims_and_account_labels(): void
    {
        $this->assertTrue(SeoRules::weakTitle('55BMW', '55BMW'));
        $this->assertTrue(SeoRules::weakTitle(' 55bmw ', '55BMW'));
        $this->assertFalse(SeoRules::weakTitle('55BMW – free online card and slot games', '55BMW'));
        $this->assertTrue(SeoRules::weakDescription('55BMW', '55BMW'));

        $this->assertTrue(SeoRules::seasonal('Welcome Spring with 55BMW!'));
        $this->assertTrue(SeoRules::seasonal('Promoção de Natal'));
        $this->assertFalse(SeoRules::seasonal('Galaxy Run: corrida espacial'));

        $facts = 'Product: Galaxy Run. Free to play. Specs: 3 game modes';
        $this->assertNotEmpty(SeoRules::unsupportedClaims('First deposit bonus 198 get 198!', $facts));
        $this->assertNotEmpty(SeoRules::unsupportedClaims('Withdrawals in 3 minutes.', $facts));
        $this->assertNotEmpty(SeoRules::unsupportedClaims("Asia's leading gaming house.", $facts));
        $this->assertNotEmpty(SeoRules::unsupportedClaims('O melhor jogo do Brasil.', $facts));
        $this->assertSame([], SeoRules::unsupportedClaims('Choose between 3 game modes.', $facts), 'figures from the profile are fine');
        $this->assertSame('Race between planets.', SeoRules::stripClaims('Race between planets. Win a 10 million jackpot today!', $facts));
        $html = SeoRules::stripClaimsHtml('<h2>Why</h2><p>Fast races.</p><ul><li>Guaranteed 100% cashback</li><li>Online mode</li></ul>', $facts);
        $this->assertStringNotContainsString('cashback', $html);
        $this->assertStringContainsString('<li>Online mode</li>', $html);

        $this->assertSame('About 55BMW', SeoRules::navLabel('About 55BMW - Your Ultimate Gaming Destination'));
        $this->assertSame('FAQ', SeoRules::navLabel('FAQ'));
        foreach (['Sign Up Now', 'Login', 'Cadastre-se', 'Regístrate', 'Đăng ký', '注册'] as $label) {
            $this->assertTrue(SeoRules::accountLabel($label), $label);
        }
        $this->assertFalse(SeoRules::accountLabel('Baixar grátis'));
    }

    public function test_weak_admin_title_is_regenerated_and_rendered_with_brand_and_keyword(): void
    {
        $this->fakeAi();
        $site = $this->makeSite(['title' => 'Galaxy Run', 'description' => 'Galaxy Run']);

        app(SiteGenerator::class)->ensureMeta($site);

        $this->assertSame('Galaxy Run: jogo de corrida espacial grátis', $site->fresh()->title);
        $this->assertGreaterThanOrEqual(70, mb_strlen($site->fresh()->description), 'the AI description was too short, the profile fills it');
    }

    public function test_render_falls_back_when_stored_meta_is_only_the_brand(): void
    {
        $site = $this->makeSite(['title' => 'Galaxy Run', 'description' => 'Galaxy Run', 'status' => 'live']);
        $site->pages()->create(['type' => 'home', 'slug' => '', 'title' => 'Galaxy Run', 'meta_title' => 'Galaxy Run', 'meta_description' => 'Galaxy Run', 'content' => []]);

        app(StaticSiteBuilder::class)->buildAll($site->fresh());
        $html = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');

        preg_match('#<title>(.*?)</title>#', $html, $title);
        preg_match('#<meta name="description" content="([^"]*)"#', $html, $description);
        $this->assertNotSame('Galaxy Run', $title[1]);
        $this->assertStringContainsString('Galaxy Run', $title[1]);
        $this->assertGreaterThanOrEqual(70, mb_strlen(html_entity_decode($description[1])));
    }

    public function test_empty_lists_are_noindexed_and_left_out_of_menus_and_sitemap(): void
    {
        $this->fakeAi();
        $site = $this->makeSite();
        app(SiteGenerator::class)->run(GenerationTask::create(['site_id' => $site->id, 'type' => 'full']));
        $dir = StaticSiteBuilder::dirFor($site);

        $blog = file_get_contents("{$dir}/blog/index.html");
        $this->assertStringContainsString('content="noindex, follow"', $blog);
        $this->assertStringContainsString('Novidades, guias e dicas sobre Galaxy Run', $blog);
        $updates = file_get_contents("{$dir}/novidades/index.html");
        $this->assertStringContainsString('content="noindex, follow"', $updates);

        $home = file_get_contents("{$dir}/index.html");
        $this->assertStringNotContainsString('href="/blog/"', $home);
        $this->assertStringNotContainsString('href="/novidades/"', $home);
        $sitemap = file_get_contents("{$dir}/sitemap.xml");
        $this->assertStringNotContainsString('/blog/', $sitemap);
        $this->assertStringNotContainsString('/novidades/', $sitemap);
    }

    public function test_images_are_not_repeated_and_ctas_never_point_to_the_page_itself(): void
    {
        Storage::fake('public');
        $png = imagecreatetruecolor(800, 500);
        ob_start();
        imagepng($png);
        Storage::disk('public')->put('brand/one.png', ob_get_clean());

        $site = $this->makeSite(['status' => 'live', 'domain' => 'cta.com.br'], [
            'media' => [['type' => 'image', 'path' => 'brand/one.png', 'alt' => 'Tela inicial do jogo']],
            'cta_links' => [['label' => 'Sign Up Now', 'url' => 'https://cta.com.br/']],
        ]);
        $site->update(['design_config' => ['pages' => ['home' => [['block' => 'hero', 'variant' => 'split'], ['block' => 'card_grid', 'variant' => 'grid'], ['block' => 'gallery', 'variant' => 'grid'], ['block' => 'cta', 'variant' => 'band']]]]]);
        $site->pages()->create(['type' => 'home', 'slug' => '', 'title' => 'Galaxy Run', 'content' => [
            'card_grid' => ['items' => [['title' => 'JILI Slot'], ['title' => 'PG Slot'], ['title' => 'Live Casino']]],
        ]]);
        $site->pages()->create(['type' => 'product', 'slug' => 'sobre-o-jogo', 'title' => 'Sobre o jogo', 'content' => []]);

        app(StaticSiteBuilder::class)->buildAll($site->fresh());
        $html = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');

        $main = substr($html, strpos($html, '<main'), strpos($html, '</main>') - strpos($html, '<main'));
        preg_match_all('#<img [^>]*src="(/media/[^"]+)"#', $main, $imgs);
        $this->assertSame(1, count(array_unique(array_map(fn ($s) => preg_replace('/-\d+\.\w+$/', '', $s), $imgs[1]))));
        $this->assertSame(1, substr_count($main, 'alt="Tela inicial do jogo"'));
        $this->assertStringNotContainsString('alt="JILI Slot"', $main);

        $this->assertStringNotContainsString('href="https://cta.com.br/"', substr($html, strpos($html, '<body')));
        $this->assertStringNotContainsString('Sign Up', $html);
        $this->assertStringContainsString('href="/sobre-o-jogo/"', $main, 'the CTA falls back to an inner page');
    }

    public function test_market_locale_faq_schema_robots_and_tracking_path(): void
    {
        $site = $this->makeSite(['locale' => 'en-US', 'target_country' => 'PH', 'status' => 'live']);
        $site->update(['design_config' => ['pages' => ['faq' => [['block' => 'faq', 'variant' => 'list']]]]]);
        $site->pages()->create(['type' => 'home', 'slug' => '', 'title' => 'Galaxy Run', 'content' => []]);
        $site->pages()->create(['type' => 'faq', 'slug' => 'faq', 'title' => 'FAQ', 'content' => ['faq' => ['items' => [['question' => 'Is it free?', 'answer' => 'Yes.']]]]]);
        $site->ads()->create(['name' => 'a', 'position' => 'top', 'code' => '<div class="hero">ad</div>', 'is_active' => true]);

        app(StaticSiteBuilder::class)->buildAll($site->fresh());
        $dir = StaticSiteBuilder::dirFor($site);
        $faq = file_get_contents("{$dir}/faq/index.html");

        $this->assertStringContainsString('<html lang="en-PH">', $faq);
        $this->assertStringContainsString('content="en_PH"', $faq);
        $this->assertStringContainsString('"@type":"FAQPage"', $faq);
        $this->assertStringContainsString('"@type":"WebPage"', $faq);
        $this->assertSame([], SchemaValidator::check($faq));

        $robots = file_get_contents("{$dir}/robots.txt");
        $this->assertMatchesRegularExpression('/User-agent: OAI-SearchBot.*?Allow: \//s', $robots);
        $this->assertMatchesRegularExpression('/User-agent: GPTBot.*?Disallow: \/\n/s', $robots);
        $prefix = SiteRenderer::trackingPrefix($site);
        $this->assertStringContainsString("Disallow: /{$prefix}/", $robots);
        $this->assertStringContainsString("/{$prefix}/ad", file_get_contents("{$dir}/index.html"));
        $this->assertStringNotContainsString('/_t/', file_get_contents("{$dir}/index.html"));
        $this->call('POST', "https://{$site->domain->name}/{$prefix}/ad", [], [], [], [], 'ad=1&site='.$site->id.'&e=imp')->assertNoContent();
    }

    public function test_each_site_gets_its_own_class_names_and_assets(): void
    {
        config(['platform.randomize_markup' => true]);
        $a = $this->makeSite(['status' => 'live']);
        $b = $this->makeSite(['status' => 'live']);
        foreach ([$a, $b] as $site) {
            $site->pages()->create(['type' => 'home', 'slug' => '', 'title' => 'Galaxy Run', 'content' => []]);
            $site->ads()->create(['name' => 'a', 'position' => 'top', 'code' => '<div class="hero">third-party</div>', 'is_active' => true]);
            app(StaticSiteBuilder::class)->buildAll($site->fresh());
        }
        $htmlA = file_get_contents(StaticSiteBuilder::dirFor($a).'/index.html');
        $htmlB = file_get_contents(StaticSiteBuilder::dirFor($b).'/index.html');

        $this->assertStringNotContainsString('class="hero ', $htmlA);
        $this->assertStringNotContainsString('/_theme/', $htmlA);
        $this->assertStringContainsString('<div class="hero">third-party</div>', $htmlA, 'ad code is untouched');
        $this->assertStringContainsString("querySelector('.banner__track')", file_get_contents(resource_path('views/theme/blocks/banner.blade.php')));
        $this->assertNotContains('banner__track', MarkupScrambler::vocabulary(), 'classes used by inline scripts keep their names');

        preg_match('#href="/assets/(s[0-9a-f]+\.css)"#', $htmlA, $css);
        $this->assertNotEmpty($css);
        $stylesheet = file_get_contents(StaticSiteBuilder::dirFor($a).'/assets/'.$css[1]);
        preg_match_all('#\sclass="([^"]*)"#', preg_replace('#<template.*?</template>#s', '', $htmlA), $classes);
        $vocabulary = MarkupScrambler::vocabulary();
        foreach (array_unique(preg_split('/\s+/', implode(' ', $classes[1]))) as $class) {
            $this->assertNotContains($class, $vocabulary, "theme class {$class} was not renamed");
        }
        $this->assertStringNotContainsString('.hero__lead', $stylesheet);

        preg_match('#<header class="([^" ]+)#', $htmlA, $navA);
        preg_match('#<header class="([^" ]+)#', $htmlB, $navB);
        $this->assertNotSame($navA[1], $navB[1], 'two sites do not share class names');
    }

    public function test_thin_or_duplicated_pages_are_retried_then_noindexed(): void
    {
        config(['platform.page_min_words' => 400]);
        $this->fakeAi();
        $site = $this->makeSite();
        $generator = app(SiteGenerator::class);
        $generator->generateDesign($site);
        $generator->generatePages($site->fresh());

        $product = $site->pages()->where('type', 'product')->first();
        $this->assertTrue($product->noindex, 'the fake AI only returns a few words');
        $this->assertFalse($site->pages()->where('type', 'contact')->first()->noindex, 'contact pages have no length rule');
        $calls = collect(Http::recorded())->filter(fn ($r) => str_contains((string) $r[0]->body(), 'Write the core pages'));
        $this->assertCount(2, $calls, 'one retry with feedback');
        $this->assertStringContainsString('feedback', (string) $calls->last()[0]->body());

        $html = (new SiteRenderer($site->fresh(), $this->tmp))->renderPage($site->pages()->where('type', 'home')->first());
        $this->assertStringNotContainsString('href="/sobre-o-jogo/"', substr($html, 0, strpos($html, '<main')), 'noindexed pages are not in the menu');
    }

    public function test_home_heading_drops_seasonal_headlines_and_unbacked_claims(): void
    {
        AiProvider::create(['name' => 'fake', 'driver' => 'openai', 'base_url' => 'https://ai.test/v1', 'api_key' => 'k', 'model' => 'm', 'is_default' => true, 'is_active' => true]);
        Http::fake(['ai.test/*' => fn ($request) => Http::response(['choices' => [['message' => ['content' => json_encode(['pages' => [
            'home' => ['title' => 'Início', 'blocks' => ['hero' => ['heading' => 'Bem-vindo à primavera!', 'subheading' => 'Corra entre planetas. Saque em 3 minutos garantido!', 'cta_label' => 'Cadastre-se']]],
        ]])]]], 'usage' => []])]);
        $site = $this->makeSite(['title' => 'Galaxy Run: jogo de corrida espacial grátis', 'description' => str_repeat('Galaxy Run corrida espacial. ', 4)]);

        app(SiteGenerator::class)->generatePages($site);
        $hero = $site->pages()->where('type', 'home')->first()->content['hero'];

        $this->assertSame('Galaxy Run: jogo de corrida espacial grátis', $hero['heading']);
        $this->assertSame('Bem-vindo à primavera!', $hero['eyebrow']);
        $this->assertSame('Corra entre planetas.', $hero['subheading']);
        $this->assertArrayNotHasKey('cta_label', $hero);
    }

    public function test_coverage_alert_after_many_unindexed_urls(): void
    {
        $site = $this->makeSite(['status' => 'live']);
        $site->forceFill(['launched_at' => now()->subDays(30)])->save();

        app(SearchConsoleSync::class)->alertCoverage($site, 20, ['Crawled - currently not indexed' => 9, 'Discovered - currently not indexed' => 3]);

        $alert = Alert::where('type', 'index_coverage')->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('Crawled - currently not indexed ×9', $alert->message);
    }

    public function test_theme_css_only_selects_classes_by_name_so_scrambled_sites_keep_working(): void
    {
        // Every site gets its own class names; [class*="nav--mm-"] would never match ".c3f9a1".
        $css = file_get_contents(resource_path('theme/base.css')).file_get_contents(resource_path('theme/critical.css')).Variants::allCss();
        $this->assertDoesNotMatchRegularExpression('/\[class\s*[*^$|~]?=/', $css);
        foreach (array_diff(DesignConfigValidator::MOBILE['mobile_menu'], ['dropdown']) as $menu) {
            $this->assertStringContainsString(".nav--mm-{$menu}", $css, "{$menu} opens");
        }
    }
}
