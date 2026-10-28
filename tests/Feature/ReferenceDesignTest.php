<?php

namespace Tests\Feature;

use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\SiteGenerator;
use App\Services\Theme\ReferenceAnalyzer;
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
        $this->assertSame('montserrat', $design['fonts']['heading']);
        $this->assertSame(['faq', 'guide', 'updates', 'blog'], $design['menu']);
        $this->assertStringContainsString('#ff3366', implode("\n", $generator->designNotes));

        $generator->generatePages($site);
        app(StaticSiteBuilder::class)->buildAll($site->fresh());
        $html = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');
        preg_match('#<ul class="nav__links">(.*?)</ul>#s', $html, $nav);
        $this->assertLessThan(strpos($nav[1], 'blog'), strpos($nav[1], 'perguntas') ?: 0);
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
