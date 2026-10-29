<?php

namespace Tests\Feature;

use App\Models\Keyword;
use App\Services\Content\ArticlePipeline;
use App\Services\Render\MediaPublisher;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Render\ThemeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Pictures downloaded from the reference site fill every place that has no picture of its own. */
class ReferencePictureTest extends TestCase
{
    use RefreshDatabase;

    private function png(int $seed, int $w = 800, int $h = 400): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, $seed * 40 % 255, 90, 160));
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    /** A site without brand pictures whose reference left $count pictures (named cards and bare pictures). */
    private function site(int $count)
    {
        Storage::fake('public');
        $site = $this->makeSite(['status' => 'live', 'domain' => 'refpics-'.$count.'.com.br']);
        $site->update(['design_config' => ['pages' => ['home' => [['block' => 'hero', 'variant' => 'split'], ['block' => 'card_grid', 'variant' => 'grid']]]]]);
        $site->pages()->create(['type' => 'home', 'slug' => '', 'title' => 'Galaxy Run', 'content' => []]);
        foreach (range(1, $count) as $i) {
            $path = "brand/ref-{$site->id}/{$i}.png";
            Storage::disk('public')->put($path, $this->png($i));
            $site->referenceItems()->create(['kind' => $i % 2 ? 'card' : 'image', 'name' => $i % 2 ? "Jogo {$i}" : null, 'image_url' => "https://ref.test/{$i}.png", 'image_hash' => md5((string) $i), 'image_path' => $path, 'width' => 800, 'height' => 400]);
        }

        return $site->fresh();
    }

    private function ctx($site): ThemeContext
    {
        return new ThemeContext($site, new MediaPublisher(StaticSiteBuilder::dirFor($site), $site->domain?->name));
    }

    public function test_hero_takes_a_reference_picture_when_the_site_has_no_picture(): void
    {
        $site = $this->site(4);
        $pic = $this->ctx($site)->slotPicture('hero', 'home');

        $this->assertNotNull($pic);
        $this->assertStringStartsWith("brand/ref-{$site->id}/", $pic['path']);
        $this->assertSame($pic, $this->ctx($site->fresh())->slotPicture('hero', 'home'), 'the pick is stable for the site');
    }

    public function test_items_without_a_picture_get_different_reference_pictures(): void
    {
        $site = $this->site(5);
        $site->entries()->create(['collection' => 'game', 'slug' => 'owned', 'title' => 'Owned', 'image' => "brand/ref-{$site->id}/1.png"]);
        $games = collect(['Alpha', 'Beta', 'Gama'])->map(fn ($t) => $site->entries()->create(['collection' => 'game', 'slug' => strtolower($t), 'title' => $t]));
        $ctx = $this->ctx($site->fresh());
        $ctx->beginPage('/');

        $picked = $games->map(fn ($g) => $ctx->entryFallback($g)['image']['src'] ?? null);
        $this->assertCount(3, $picked->filter()->unique(), 'one different picture per item on a page');
        $owned = $ctx->image("brand/ref-{$site->id}/1.png", 640)['src'];
        $this->assertNotContains($owned, $picked->all(), 'another item\'s own picture comes last');
    }

    public function test_entry_cards_show_a_reference_picture_instead_of_the_named_placeholder(): void
    {
        $site = $this->site(3);
        $game = $site->entries()->create(['collection' => 'game', 'slug' => 'alpha', 'title' => 'Alpha']);
        $ctx = $this->ctx($site->fresh());
        $ctx->beginPage('/');
        $html = view('theme.partials.entry-card', ['ctx' => $ctx, 'entry' => $game])->render();

        $this->assertStringNotContainsString('tile__ph', $html);
        $this->assertStringContainsString('alt="Alpha"', $html);
    }

    public function test_articles_without_pictures_get_reference_pictures_between_sections(): void
    {
        $site = $this->site(4);
        $keyword = Keyword::create(['site_id' => $site->id, 'keyword' => 'como jogar galaxy run', 'source' => 'ai']);
        $html = collect(range(1, 6))->map(fn ($i) => "<h2>Parte {$i}</h2><p>Texto {$i}.</p>")->implode('');

        $out = app(ArticlePipeline::class)->withPictures($site, $keyword, '<p>Intro.</p>'.$html, "brand/ref-{$site->id}/1.png");

        $this->assertSame(3, substr_count($out, '<figure><img src="media:brand/ref-'.$site->id.'/'));
        $this->assertStringNotContainsString('/1.png', $out, 'never the featured picture again');
        $this->assertStringStartsWith('<p>Intro.</p><h2>Parte 1</h2>', $out, 'nothing above the first section');
        $this->assertSame($out, app(ArticlePipeline::class)->withPictures($site, $keyword, $out), 'an article with pictures is left alone');
    }
}
