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
        self::paintPhoto($img, $seed);
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    /** A site without brand pictures whose reference left $count pictures (named cards and bare pictures). */
    private function site(int $count, int $h = 500)
    {
        Storage::fake('public');
        $site = $this->makeSite(['status' => 'live', 'domain' => 'refpics-'.$count.'.com.br']);
        $site->update(['design_config' => ['pages' => ['home' => [['block' => 'hero', 'variant' => 'split'], ['block' => 'card_grid', 'variant' => 'grid']]]]]);
        $site->pages()->create(['type' => 'home', 'slug' => '', 'title' => 'Galaxy Run', 'content' => []]);
        foreach (range(1, $count) as $i) {
            $path = "brand/ref-{$site->id}/{$i}.png";
            Storage::disk('public')->put($path, $this->png($i, 800, $h));
            $site->referenceItems()->create(['kind' => $i % 2 ? 'card' : 'image', 'name' => $i % 2 ? "Jogo {$i}" : null, 'image_url' => "https://ref.test/{$i}.png", 'image_hash' => md5((string) $i), 'image_path' => $path, 'width' => 800, 'height' => $h]);
        }

        return $site->fresh();
    }

    private function ctx($site): ThemeContext
    {
        return new ThemeContext($site, new MediaPublisher(StaticSiteBuilder::dirFor($site), $site->domain?->name));
    }

    public function test_hero_takes_a_reference_picture_when_the_site_has_no_picture(): void
    {
        $site = $this->site(4, 400);
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

    public function test_logos_banners_and_past_years_never_stand_in_and_matching_pictures_come_first(): void
    {
        Storage::fake('public');
        $site = $this->makeSite(['status' => 'live', 'domain' => 'roles.com.br']);
        $flat = imagecreatetruecolor(300, 300);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagefilledrectangle($flat, 60, 120, 240, 180, imagecolorallocate($flat, 20, 20, 20));
        ob_start();
        imagepng($flat);
        $logo = ob_get_clean();
        $rows = [
            ['logo', 'Pragmatic Play', $logo, 300, 300],
            ['wide', 'Bônus de recarga', $this->png(2, 1200, 400), 1200, 400],
            ['old', 'Heavyweight Event 2024', $this->png(3, 800, 500), 800, 500],
            ['telegram', 'Canal oficial no Telegram', $this->png(4, 800, 500), 800, 500],
            ['photo', null, $this->png(5, 800, 500), 800, 500],
        ];
        foreach ($rows as $i => [$key, $name, $binary, $w, $h]) {
            Storage::disk('public')->put("brand/ref-{$site->id}/{$key}.png", $binary);
            $site->referenceItems()->create(['kind' => $name ? 'card' : 'image', 'name' => $name, 'image_url' => "https://ref.test/{$key}.png", 'image_hash' => md5($key), 'image_path' => "brand/ref-{$site->id}/{$key}.png", 'width' => $w, 'height' => $h]);
        }
        $ctx = $this->ctx($site->fresh());
        $roles = $ctx->referencePictures()->mapWithKeys(fn ($p) => [basename($p['path'], '.png') => $p['role'].($p['expired'] ? '+old' : '')])->all();
        $this->assertSame('logo', $roles['logo']);
        $this->assertSame('banner', $roles['wide']);
        $this->assertSame('photo+old', $roles['old']);
        $this->assertEqualsCanonicalizing(['telegram', 'photo'], $ctx->photoPictures()->map(fn ($p) => basename($p['path'], '.png'))->all());

        $ctx->beginPage('/');
        $notice = $site->entries()->create(['collection' => 'notice', 'slug' => 'tg', 'title' => 'Siga nosso canal no Telegram']);
        $this->assertSame($ctx->image("brand/ref-{$site->id}/telegram.png", 640)['src'], $ctx->entryFallback($notice)['image']['src'], 'the picture that names it wins');
        $other = $site->entries()->create(['collection' => 'notice', 'slug' => 'x', 'title' => 'Manutenção programada']);
        $this->assertSame($ctx->image("brand/ref-{$site->id}/photo.png", 640)['src'], $ctx->entryFallback($other)['image']['src'], 'no fit: an ordinary picture, never the logo, banner or old event');
        $this->assertNull($ctx->entryFallback($site->entries()->create(['collection' => 'notice', 'slug' => 'y', 'title' => 'Outro aviso'])), 'nothing ordinary left: no picture rather than a wrong one');
        $this->assertSame("brand/ref-{$site->id}/logo.png", $ctx->matchPicture('Pragmatic Play', false)['path'] ?? null, 'a logo only for its own name');
    }
}
