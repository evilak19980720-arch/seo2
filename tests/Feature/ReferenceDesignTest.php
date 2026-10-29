<?php

namespace Tests\Feature;

use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\SiteGenerator;
use App\Services\Theme\ReferenceAnalyzer;
use App\Services\Theme\SiteStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReferenceDesignTest extends TestCase
{
    use RefreshDatabase;

    private function fakeReference(): void
    {
        Http::fake([
            'ref.test/style.css' => Http::response('body{background:#0b0f1a;color:#e6e9f2;font-family:"Montserrat",sans-serif} .btn{background:#ff3366} .btn:hover{background:#ff3366} a{color:#ff3366} .card{border:1px solid rgba(255,255,255,.1)} .shadow{color:#000} hr{border-color:#000000}'),
            'ref.test/*' => Http::response('<html><head><title>Ref</title><link rel="stylesheet" href="/style.css">
                <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700" rel="stylesheet"></head>
                <body><header><nav><a href="/">Home</a><a href="/guide">Guide</a><a href="/news">News</a><a href="/faq">FAQ</a></nav></header>
                <main><section><h2>Features</h2></section><section><h2>Screenshots</h2></section></main></body></html>'),
        ]);
    }

    public function test_analyzer_reads_palette_fonts_and_menu(): void
    {
        $this->fakeReference();

        $ref = app(ReferenceAnalyzer::class)->analyze('https://ref.test/');

        $this->assertSame('#ff3366', $ref['colors']['primary']);
        $this->assertSame('#0b0f1a', $ref['colors']['background']);
        $this->assertSame('#e6e9f2', $ref['colors']['text']);
        $this->assertContains('Montserrat', $ref['fonts']);
        $this->assertSame(['Home', 'Guide', 'News', 'FAQ'], $ref['menu']);
        $this->assertSame(['Features', 'Screenshots'], $ref['sections']);
        $this->assertSame('montserrat', ReferenceAnalyzer::whitelistFont($ref['fonts']));
    }

    public function test_analyzer_reads_spa_with_huge_inline_css_and_sidebar_menu(): void
    {
        // Shaped like a rendered AngularJS casino app: 4 MB of inline CSS before the body,
        // hashed class names, custom elements, the menu in a sidebar <nav>.
        $games = implode('', array_map(fn ($i) => "<div class=\"slick-slide\"><li><img src=\"/g{$i}.png\"><p>Game number {$i}</p></li></div>", range(1, 8)));
        Http::fake(['spa.test/*' => Http::response('<html><head><title>Spa</title><style>.a{color:#ffdc50}'.str_repeat('._x1{margin:0}', 300_000).'</style></head><body>
            <gupw-header><header class="_3vHa"><gupw-hot-news><div class="js-marquee"><ul><li>Bagong address ng website ngayon</li></ul></div></gupw-hot-news>
            <button>Sign up</button><button>Login</button></header></gupw-header>
            <gupw-sidebar><section class="_1wPQ"><gupw-nav><nav><ul>
              <li class="home"><a><i></i><span>Home</span></a></li>
              <li class="slot"><a><i></i><span>SLOT</span></a><div class="_2xvn"><ol><li><a><img src="/j.png"><span>JILI Slot</span></a></li><li><a><span>PG Slot</span></a></li></ol></div></li>
              <li class="live"><a><i></i><span>LIVE Casino</span></a></li>
              <li class="promotion"><a><i></i><span>Promotions</span></a></li>
            </ul></nav></gupw-nav></section></gupw-sidebar>
            <div class="_1h0f"><gupw-slide-banner><section class="DQFg"><a href="/"><img src="/banner.jpg"></a></section></gupw-slide-banner>
            <gupw-home><section><div class="_353n">PG Casino</div><ul class="x73Q slick-slider"><div class="slick-list"><div class="slick-track">'.$games.'</div></div></ul></section>
            <section><div class="_9Pb">Maglaro ng daan-daang laro araw-araw at makakuha ng malaking rebate tuwing linggo sa aming website.</div></section>
            </gupw-home></div><gupw-footer><footer><p>Copyright reserved here</p></footer></gupw-footer></body></html>')]);

        $ref = app(ReferenceAnalyzer::class)->analyze('https://spa.test/');

        $this->assertSame(['Home', 'SLOT', 'LIVE Casino', 'Promotions'], $ref['menu']);
        $this->assertSame(['JILI Slot', 'PG Slot'], $ref['navigation']['items'][1]['children']);
        $this->assertTrue($ref['navigation']['dropdown']);
        $this->assertSame(['notice', 'banner', 'card_grid'], array_slice($ref['structure'], 0, 3));
        $this->assertStringContainsString('Maglaro ng daan-daang laro', $ref['content']);
        $this->assertStringContainsString('Game number 3', $ref['content']);
        $this->assertSame('#ffdc50', $ref['colors']['primary']);
    }

    private function pageWith(string $body): string
    {
        return '<html><head><title>T</title></head><body>'.$body.'</body></html>';
    }

    public function test_analyzer_follows_doorway_pages(): void
    {
        $real = $this->pageWith('<nav><a href="/">Home</a><a href="/a">About</a><a href="/b">Blog</a></nav><p>'.str_repeat('Real content of the actual website. ', 30).'</p>');
        Http::fake([
            'refresh.test/*' => Http::response('<html><head><meta http-equiv="Refresh" content="0; URL=https://frame.test/"></head><body></body></html>'),
            'frame.test/*' => Http::response($this->pageWith('<iframe src="https://real.test/" width="100%" height="100%"></iframe>')),
            'real.test/*' => Http::response($real),
        ]);

        $analyzer = app(ReferenceAnalyzer::class);
        $ref = $analyzer->analyze('https://refresh.test/');

        $this->assertSame('https://real.test/', $ref['url']);
        $this->assertSame(['Home', 'About', 'Blog'], $ref['menu']);
        $this->assertStringContainsString('Real content', $ref['content']);
        $this->assertStringContainsString('iframe', implode("\n", $analyzer->report($ref)));
    }

    public function test_analyzer_skips_hidden_clones_cookie_banners_and_account_links(): void
    {
        $slides = implode('', array_map(fn ($i) => "<div class=\"swiper-slide\"><img data-lazy=\"/s{$i}.jpg\"><p>Slide caption {$i}</p></div>", range(1, 3)));
        Http::fake(['mix.test/*' => Http::response($this->pageWith('
            <header><nav><ul><li><a>Inicio</a></li><li><a>Juegos</a></li><li><a>Noticias</a></li><li><a>Iniciar sesión</a></li><li><a>Regístrate</a></li><li><a>Đăng nhập</a></li></ul></nav></header>
            <div class="swiper">'.$slides.'<div class="swiper-slide swiper-slide-duplicate"><p>Slide caption 1 copy</p></div></div>
            <div hidden><p>Secret hidden paragraph text here</p></div>
            <div style="display: none"><p>Another invisible paragraph text</p></div>
            <div id="cookie-consent"><p>We use cookies to improve your experience</p></div>
            <section data-bg="url(\'/bg.jpg\')"><p>'.str_repeat('Visible paragraph about the product. ', 20).'</p></section>'))]);

        $ref = app(ReferenceAnalyzer::class)->analyze('https://mix.test/');

        $this->assertSame(['Inicio', 'Juegos', 'Noticias'], $ref['menu']);
        $this->assertStringNotContainsString('copy', $ref['content']);
        $this->assertStringNotContainsString('hidden paragraph', $ref['content']);
        $this->assertStringNotContainsString('invisible', $ref['content']);
        $this->assertStringNotContainsString('cookies', $ref['content']);
        $urls = array_column($ref['images'], 'url');
        $this->assertContains('https://mix.test/s2.jpg', $urls);
        $this->assertContains('https://mix.test/bg.jpg', $urls);
    }

    public function test_generated_design_follows_reference_even_if_ai_ignores_it(): void
    {
        $this->fakeReference();
        $this->fakeAi(['design' => ['design' => [
            'colors' => ['primary' => '#2255ff', 'background' => '#ffffff', 'text' => '#111111'],
            'fonts' => ['heading' => 'playfair', 'body' => 'inter'],
            'menu' => ['faq', 'guide', 'updates', 'blog', 'nonsense'],
        ]]]);
        $site = $this->makeSite(['reference_url' => 'https://ref.test/']);

        $generator = app(SiteGenerator::class);
        $generator->generateDesign($site);
        $design = $site->fresh()->design_config;

        $this->assertSame('#0b0f1a', $design['colors']['background']);
        $this->assertSame('#e6e9f2', $design['colors']['text']);
        $this->assertSame(SiteStyle::for($site->domain_id)->apply([])['fonts'], $design['fonts'], 'fonts are the site\'s own, not the model\'s');
        $this->assertSame(['faq', 'guide', 'updates', 'blog'], $design['menu']);
        $this->assertStringContainsString('#ff3366', implode("\n", $generator->designNotes));

        $generator->generatePages($site);
        app(StaticSiteBuilder::class)->buildAll($site->fresh());
        $html = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');
        preg_match('#<ul class="nav__links">(.*?)</ul>#s', $html, $nav);
        $this->assertLessThan(strpos($nav[1], 'guia/'), strpos($nav[1], 'perguntas') ?: 0);
        $this->assertStringNotContainsString('/blog/', $nav[1], 'an empty blog is not in the menu');
    }

    public function test_uploaded_screenshots_provide_the_palette(): void
    {
        $img = imagecreatetruecolor(400, 300);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 12, 30));               // dark background
        imagefilledrectangle($img, 20, 20, 180, 80, imagecolorallocate($img, 250, 200, 0));   // yellow buttons
        imagefilledrectangle($img, 20, 120, 380, 130, imagecolorallocate($img, 240, 240, 240)); // text lines
        $path = tempnam(sys_get_temp_dir(), 'ref').'.png';
        imagepng($img, $path);

        $colors = app(ReferenceAnalyzer::class)->imageColors([$path]);

        $this->assertSame('#080818', $colors['background']);
        $this->assertSame('#f8c808', $colors['primary']);
        $this->assertSame('#f8f8f8', $colors['text']);
    }

    public function test_chinese_brand_profile_is_shown_in_site_language(): void
    {
        $this->fakeAi(['brand' => ['tagline' => 'Entretenimento em um só lugar', 'selling_points' => ['Interface traduzida']]]);
        $site = $this->makeSite();
        $site->brandProfile->update(['tagline' => 'VIP345是一款面向巴西用户的娱乐应用', 'selling_points' => ['翻译后的界面']]);

        $note = app(SiteGenerator::class)->localizeBrand($site->fresh());
        $generator = app(SiteGenerator::class);
        $generator->generatePages($site);
        app(StaticSiteBuilder::class)->buildAll($site->fresh());
        $html = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');

        $this->assertNotNull($note);
        $this->assertStringNotContainsString('娱乐应用', $html);
        $this->assertStringContainsString('Entretenimento em um só lugar', $html);
        $this->assertSame('VIP345是一款面向巴西用户的娱乐应用', $site->brandProfile->fresh()->tagline, 'admin copy stays as typed');
        $this->assertNull(app(SiteGenerator::class)->localizeBrand($site->fresh()), 'unchanged profile is not re-translated');
    }
}
