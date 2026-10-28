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
        $this->assertStringContainsString('#ff3366', $generator->designNotes[0]);

        $generator->generatePages($site);
        app(StaticSiteBuilder::class)->buildAll($site->fresh());
        $html = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');
        preg_match('#<ul class="nav__links">(.*?)</ul>#s', $html, $nav);
        $this->assertLessThan(strpos($nav[1], 'blog'), strpos($nav[1], 'perguntas') ?: 0);
    }
}
