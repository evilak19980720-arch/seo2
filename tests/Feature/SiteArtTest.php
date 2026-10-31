<?php

namespace Tests\Feature;

use App\Jobs\GenerateSiteArtJob;
use App\Models\AiProvider;
use App\Models\GenerationTask;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\SiteArt;
use App\Services\Sites\SiteGenerator;
use App\Support\Media;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteArtTest extends TestCase
{
    use RefreshDatabase;

    private array $prompts = [];

    private static function png(): string
    {
        $img = imagecreatetruecolor(1200, 800);
        imagefill($img, 0, 0, imagecolorallocate($img, random_int(0, 255), 90, 200));
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    private function site(array $brand = [])
    {
        Http::fake(['ai.test/v1/images/*' => function (Request $r) {
            $this->prompts[] = $r['prompt'];

            return Http::response(['data' => [['b64_json' => base64_encode(self::png())]]]);
        }]);
        $this->fakeAi();
        AiProvider::query()->update(['image_model' => 'gpt-image-1']);
        $site = $this->makeSite(['domain' => 'art-'.uniqid().'.com.br', 'site_type' => 'game'], $brand + ['media' => []]);
        app(SiteGenerator::class)->generatePages($site);
        app(StaticSiteBuilder::class)->buildAll($site->fresh());

        return $site->fresh(['domain', 'brandProfile']);
    }

    public function test_slots_without_real_pictures_get_ai_pictures_in_one_style(): void
    {
        $site = $this->site();
        $art = app(SiteArt::class);
        $slots = $art->context($site)->pictureSlots();
        $this->assertSame('home', explode(':', $slots[0])[1], 'home comes first');
        $missing = $art->missing($site);
        $this->assertSame(array_slice($slots, 0, 8), $missing);

        $task = GenerationTask::create(['site_id' => $site->id, 'type' => 'full', 'status' => 'succeeded']);
        GenerateSiteArtJob::dispatchSync($site, $task);

        $site->refresh();
        $this->assertCount(count($missing), $site->ai_images);
        $this->assertCount(count($missing), $this->prompts);
        $style = strstr($this->prompts[0], '.', true);
        foreach ($this->prompts as $prompt) {
            $this->assertStringStartsWith($style, $prompt, 'every picture shares the art direction');
            $this->assertStringContainsString('no text', $prompt);
        }
        $first = $site->ai_images[$slots[0]]['path'];
        $this->assertTrue(Media::exists($first));
        $home = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');
        $this->assertMatchesRegularExpression('#<img[^>]+src="[^"]*/media/[^"]+"#', $home, 'the home hero shows its picture');
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="https://[^"]+/media/#', $home, 'and it is the share image');
        $this->assertStringContainsString('AI 配图完成', collect($task->fresh()->log)->last()['step']);

        $cover = collect($slots)->first(fn ($s) => str_starts_with($s, 'cover:') && isset($site->ai_images[$s]));
        $this->assertNotNull($cover, 'inner content pages get a cover picture');
        $page = $site->pages()->where('type', explode(':', $cover)[1])->sole();
        $this->assertMatchesRegularExpression('#<div class="container page-cover"><picture>.*?<img[^>]+fetchpriority="high"#s', file_get_contents(StaticSiteBuilder::dirFor($site).'/'.trim($page->slug, '/').'/index.html'));
        $this->assertNotContains('cover:privacy', $slots, 'legal pages stay plain');

        // Regeneration keeps the pictures: nothing is drawn again.
        $this->assertSame([], $art->missing($site));
        GenerateSiteArtJob::dispatchSync($site);
        $this->assertCount(count($missing), $this->prompts);
    }

    public function test_real_pictures_come_first_and_the_limit_holds(): void
    {
        Settings::set('ai_images_per_site', 2);
        Media::put('brand/a.png', self::png());
        Media::put('brand/b.png', self::png());
        $site = $this->site(['media' => [['type' => 'image', 'path' => 'brand/a.png', 'alt' => 'A'], ['type' => 'image', 'path' => 'brand/b.png', 'alt' => 'B']]]);
        $art = app(SiteArt::class);
        $ctx = $art->context($site);
        $slots = $ctx->pictureSlots();

        $this->assertSame(array_slice($slots, 2, 2), $art->missing($site));
        [$block, $type] = explode(':', $slots[1]);
        $this->assertSame('brand/b.png', $ctx->slotPicture($block, $type)['path'], 'each real picture goes to one slot');

        $art->draw($site);
        $this->assertSame(array_slice($slots, 2, 2), array_keys($site->fresh()->ai_images));
        $this->assertSame([], $art->missing($site->fresh()));
    }

    public function test_nothing_is_queued_without_an_image_model(): void
    {
        $this->fakeAi();
        $site = $this->makeSite(['domain' => 'noart-'.uniqid().'.com.br']);

        $this->assertFalse(app(SiteArt::class)->canDraw());
        $this->assertNull($site->ai_images);
    }
}
