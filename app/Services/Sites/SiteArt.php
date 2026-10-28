<?php

namespace App\Services\Sites;

use App\Enums\AiPurpose;
use App\Models\Site;
use App\Services\Ai\AiManager;
use App\Services\Render\MediaPublisher;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Render\ThemeContext;
use App\Support\Media;
use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * AI pictures for a site's picture slots (each page's hero or banner).
 * Real pictures (brand, reference) fill the slots first; only the slots past
 * them get an AI picture, drawn once in one site-wide style and kept on
 * regeneration. Items that stand for real things (games, promotions) are
 * never drawn: they show reference pictures only.
 */
class SiteArt
{
    /** Art directions; each site gets one, so sites of the network differ. */
    protected const STYLES = [
        'cinematic 3D render, soft volumetric light, depth of field',
        'bold flat vector illustration, clean shapes, subtle grain',
        'vibrant neon glow, dark ambient background, glossy highlights',
        'painterly digital art, rich textures, warm rim light',
        'isometric 3D scene, toy-like materials, bright studio light',
        'dreamy gradient illustration, floating elements, soft shadows',
    ];

    public function __construct(protected AiManager $ai) {}

    public function context(Site $site): ThemeContext
    {
        return new ThemeContext($site, new MediaPublisher(StaticSiteBuilder::dirFor($site), $site->domain?->name));
    }

    /**
     * Slots that still need an AI picture, within the per-site limit.
     *
     * @return array<int, string>
     */
    public function missing(Site $site, ?ThemeContext $ctx = null): array
    {
        $ctx ??= $this->context($site);
        $slots = $ctx->pictureSlots();
        $open = array_slice($slots, $ctx->realPictures()->count());
        $drawn = array_keys(array_filter((array) $site->ai_images, fn ($p) => filled($p['path'] ?? null)));
        $room = max(0, (int) Settings::get('ai_images_per_site', 8) - count(array_intersect($drawn, $slots)));

        return array_slice(array_values(array_diff($open, $drawn)), 0, $room);
    }

    public function canDraw(): bool
    {
        return $this->ai->candidates(AiPurpose::Image)->isNotEmpty();
    }

    /**
     * Draw the missing pictures, saving each one as soon as it is made.
     *
     * @param  callable(string): void|null  $progress
     * @return int pictures made
     */
    public function draw(Site $site, ?callable $progress = null): int
    {
        $ctx = $this->context($site);
        $missing = $this->missing($site, $ctx);
        $made = 0;
        foreach ($missing as $i => $slot) {
            [$block, $type] = explode(':', $slot, 2);
            $page = $ctx->pages->get($type);
            $binary = $this->ai->image($this->prompt($site, $ctx, $block, $type), $site);
            if ($binary === null) {
                $progress && $progress('AI 配图失败（'.($page->title ?? $type).'），其余位置不再生成，用默认设计');
                break;
            }
            $info = @getimagesizefromstring($binary);
            $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? 'png';
            $path = "art/{$site->id}/".Str::slug($slot).'-'.substr(md5($binary), 0, 10).".{$ext}";
            if (! Media::put($path, $binary)) {
                continue;
            }
            $images = (array) $site->fresh()->ai_images;
            $images[$slot] = ['path' => $path, 'alt' => Str::limit(($page->title ?? $site->product_name).' – '.$site->product_name, 120, '')];
            $site->forceFill(['ai_images' => $images])->save();
            $made++;
            $progress && $progress('AI 配图 '.($i + 1).'/'.count($missing).'：'.($page->title ?? $type));
        }

        return $made;
    }

    /** The site-wide art direction: one style, the design palette, the product's world. */
    public function style(Site $site, ThemeContext $ctx): string
    {
        $colors = collect($ctx->design['colors'] ?? [])->only(['primary', 'secondary', 'accent', 'background'])->filter()->implode(', ');
        $style = self::STYLES[crc32((string) ($site->domain?->name ?? $site->id)) % count(self::STYLES)];

        return "Art direction for every picture of this website: {$style}. Colour palette: {$colors}. "
            .'Subject world: '.$site->product_name.' – '.($ctx->brand->tagline ?: ($site->typeConfig()['label'] ?? $site->site_type)).'.';
    }

    public function prompt(Site $site, ThemeContext $ctx, string $block, string $type): string
    {
        $page = $ctx->pages->get($type);
        $about = trim(($page->title ?? Str::headline($type)).'. '.($page->meta_description ?? ''));

        return $this->style($site, $ctx)
            ." Wide {$block} picture for the \"{$about}\" page: an atmospheric scene that fits the page's topic, with empty space on one side for a headline."
            .' Mood and setting only: no text, letters, numbers, logos, watermarks, user interface, screenshots, real people or real brands.';
    }
}
