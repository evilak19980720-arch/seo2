<?php

namespace Tests\Feature;

use App\Services\Render\MediaPublisher;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Render\ThemeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PictureFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function png(int $seed): string
    {
        $img = imagecreatetruecolor(800, 500);
        imagefill($img, 0, 0, imagecolorallocate($img, $seed * 40 % 255, 120, 200));
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    private function siteWithPictures(int $count)
    {
        Storage::fake('public');
        $media = [];
        foreach (range(1, $count) as $i) {
            Storage::disk('public')->put("brand/ref-{$i}.png", $this->png($i));
            $media[] = ['type' => 'image', 'path' => "brand/ref-{$i}.png", 'alt' => "Foto {$i}", 'source' => 'reference'];
        }
        $site = $this->makeSite(['status' => 'live', 'domain' => 'pics-'.$count.'.com.br'], ['media' => $media]);
        $site->update(['design_config' => ['pages' => ['home' => [['block' => 'hero', 'variant' => 'split'], ['block' => 'card_grid', 'variant' => 'grid']]]]]);
        $site->pages()->create(['type' => 'home', 'slug' => '', 'title' => 'Galaxy Run', 'content' => [
            'card_grid' => ['items' => [['title' => 'Modo corrida'], ['title' => 'Modo arena'], ['title' => 'Modo história'], ['title' => 'Modo treino']]],
        ]]);

        return $site->fresh();
    }

    public function test_blocks_without_their_own_picture_use_reference_pictures_without_repeats(): void
    {
        $site = $this->siteWithPictures(6);
        app(StaticSiteBuilder::class)->buildAll($site);
        $html = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');
        $main = substr($html, strpos($html, '<main'), strpos($html, '</main>') - strpos($html, '<main'));

        $this->assertStringNotContainsString('tile__ph', $main, 'every card has a picture');
        preg_match_all('#<img [^>]*src="(/media/[^"]+)"#', $main, $imgs);
        $pictures = array_map(fn ($s) => preg_replace('/-\d+\.\w+$/', '', $s), $imgs[1]);
        $this->assertCount(5, $pictures, 'hero + four cards');
        $this->assertSame($pictures, array_values(array_unique($pictures)), 'no picture twice on a page');
    }

    public function test_cards_keep_the_placeholder_once_every_picture_is_shown(): void
    {
        $site = $this->siteWithPictures(2);
        app(StaticSiteBuilder::class)->buildAll($site);
        $html = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');

        $this->assertSame(3, substr_count($html, 'tile__ph'), 'hero and first card take the two pictures');
    }

    public function test_picture_slots_reuse_real_pictures_when_there_is_no_ai_picture(): void
    {
        $site = $this->siteWithPictures(1);
        $ctx = new ThemeContext($site, new MediaPublisher(StaticSiteBuilder::dirFor($site), $site->domain?->name));
        foreach ($ctx->pictureSlots() as $slot) {
            [$block, $type] = explode(':', $slot);
            $this->assertSame('brand/ref-1.png', $ctx->slotPicture($block, $type)['path'] ?? null, $slot);
        }
    }
}
