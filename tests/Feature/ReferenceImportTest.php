<?php

namespace Tests\Feature;

use App\Models\GenerationTask;
use App\Services\Sites\SiteGenerator;
use App\Services\Theme\ReferenceAnalyzer;
use App\Support\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReferenceImportTest extends TestCase
{
    use RefreshDatabase;

    private static function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, random_int(0, 255), 80, 160));
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    private function fakeReference(): void
    {
        Http::fake([
            'ref.test/img/banner.png' => Http::response(self::png(1200, 500), 200, ['Content-Type' => 'image/png']),
            'ref.test/img/game1.png' => Http::response(self::png(600, 400), 200, ['Content-Type' => 'image/png']),
            'ref.test/img/tiny.png' => Http::response(self::png(40, 40), 200, ['Content-Type' => 'image/png']),
            'ref.test/*' => Http::response('<html><head><meta property="og:image" content="/img/banner.png"></head><body>
                <header><nav><a href="/">Home</a><a href="/jogos">Jogos</a></nav></header>
                <main><h1>Os melhores jogos do Brasil</h1><p>RefCasino traz centenas de jogos de slots com gráficos incríveis e rodadas rápidas.</p>
                <img src="/img/game1.png" alt="Jogo 1"><img data-src="/img/tiny.png"><img src="/img/logo.png"><img src="/img/icon.svg">
                <h2>Como começar</h2><p>Crie sua conta, escolha um jogo e divirta-se com responsabilidade.</p></main></body></html>'),
        ]);
    }

    public function test_analyzer_extracts_content_and_images(): void
    {
        $this->fakeReference();
        $analyzer = app(ReferenceAnalyzer::class);

        $ref = $analyzer->analyze('https://ref.test/');

        $this->assertStringContainsString('## Os melhores jogos do Brasil', $ref['content']);
        $this->assertStringContainsString('Crie sua conta', $ref['content']);
        $this->assertSame(['https://ref.test/img/banner.png', 'https://ref.test/img/game1.png', 'https://ref.test/img/tiny.png'], array_column($ref['images'], 'url'));
        $this->assertStringContainsString('图片：3 张', implode("\n", $analyzer->report($ref)));
    }

    public function test_js_rendered_and_protected_pages_are_reported(): void
    {
        config(['platform.chromium_bin' => '/nonexistent/chromium']);
        Http::fake([
            'spa.test/*' => Http::response('<html><body><div id="app"></div><script src="a.js"></script><script src="b.js"></script><script>boot()</script></body></html>'),
            'cf.test/*' => Http::response('<html><title>Just a moment...</title><body>cf-challenge</body></html>', 403),
        ]);
        $analyzer = app(ReferenceAnalyzer::class);

        $analyzer->analyze('https://spa.test/');
        $this->assertTrue($analyzer->diagnostics['js_rendered']);
        $this->assertStringContainsString('没有安装 Chromium', implode("\n", $analyzer->diagnostics['messages']));

        $this->assertNull($analyzer->analyze('https://cf.test/'));
        $this->assertTrue($analyzer->diagnostics['blocked']);
        $this->assertStringContainsString('请上传首页截图', implode("\n", $analyzer->report(null)));
    }

    public function test_switches_import_images_to_media_disk_and_send_reference_text_to_ai(): void
    {
        Storage::fake('public');
        Media::configure();
        $this->fakeAi();
        $this->fakeReference();
        $site = $this->makeSite(['reference_url' => 'https://ref.test/', 'reference_import_images' => true, 'reference_rewrite_content' => true]);

        app(SiteGenerator::class)->run(GenerationTask::create(['site_id' => $site->id, 'type' => 'full']));

        $media = collect($site->brandProfile->fresh()->media)->where('source', 'reference')->values();
        $this->assertCount(2, $media, 'banner + game image; the 40px image is skipped');
        Storage::disk(Media::DISK)->assertExists($media[0]['path']);
        $this->assertStringStartsWith("brand/ref-{$site->id}/", $media[0]['path']);

        $pageRequest = collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn (Request $r) => str_contains(json_encode($r->data()), 'Write the core pages'));
        $this->assertStringContainsString('centenas de jogos de slots', json_encode($pageRequest->data(), JSON_UNESCAPED_UNICODE));

        // Running again does not import the same images twice.
        app(SiteGenerator::class)->run(GenerationTask::create(['site_id' => $site->id, 'type' => 'design']));
        $this->assertCount(2, collect($site->brandProfile->fresh()->media)->where('source', 'reference'));
    }

    public function test_switches_off_means_no_import_and_no_reference_text(): void
    {
        $this->fakeAi();
        $this->fakeReference();
        $site = $this->makeSite(['reference_url' => 'https://ref.test/']);
        $this->assertTrue($site->fresh()->reference_import_images && $site->fresh()->reference_rewrite_content, 'both switches default to on');
        $site->update(['reference_import_images' => false, 'reference_rewrite_content' => false]);

        app(SiteGenerator::class)->run(GenerationTask::create(['site_id' => $site->id, 'type' => 'full']));

        $this->assertSame([], $site->brandProfile->fresh()->media);
        $pageRequest = collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn (Request $r) => str_contains(json_encode($r->data()), 'Write the core pages'));
        $this->assertStringNotContainsString('centenas de jogos', json_encode($pageRequest->data(), JSON_UNESCAPED_UNICODE));
    }
}
