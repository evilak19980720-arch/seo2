<?php

namespace App\Services\Render;

use App\Models\BrandProfile;
use App\Models\Page;
use App\Models\Site;
use App\Services\Theme\DefaultDesign;
use App\Services\Theme\DesignConfigValidator;
use Illuminate\Support\Collection;

/**
 * Everything a theme view needs about the site being rendered. Built once per
 * build and shared by every page so a full-site render stays cheap.
 */
class ThemeContext
{
    public readonly BrandProfile $brand;

    public readonly array $design;

    public readonly string $lang;

    /** @var Collection<string, Page> keyed by page type */
    public readonly Collection $pages;

    public function __construct(public readonly Site $site, public readonly MediaPublisher $media)
    {
        $this->brand = $site->brandProfile ?? new BrandProfile(['product_name' => $site->product_name]);
        $this->lang = $site->language();
        $this->pages = $site->pages()->get()->keyBy('type');

        $config = $site->design_config ?: DefaultDesign::make($site->domain_id ?? $site->id, $site->typeConfig());
        $this->design = (new DesignConfigValidator)->validate($config, $site->typeConfig());
    }

    public function t(string $key, array $replace = []): string
    {
        return __('theme.'.$key, $replace, $this->lang);
    }

    public function url(string $path = ''): string
    {
        return $this->site->url($path);
    }

    /** Root-relative link used inside HTML (canonical/OG use absolute URLs). */
    public function href(string $path = ''): string
    {
        $path = trim($path, '/');

        return $path === '' ? '/' : '/'.$path.'/';
    }

    public function pageHref(string $type): ?string
    {
        $page = $this->pages->get($type);

        return $page ? $this->href($page->slug) : null;
    }

    public function blocks(string $pageType): array
    {
        return $this->design['pages'][$pageType] ?? [];
    }

    /** @return array<int, array{label: string, href: string}> */
    public function navLinks(): array
    {
        $links = [];
        foreach ($this->site->typeConfig()['pages'] as $type => $def) {
            if (in_array($type, ['home', 'privacy', 'terms'], true) || ! $this->pages->has($type)) {
                continue;
            }
            $links[] = ['label' => $this->pages[$type]->title, 'href' => $this->href($this->pages[$type]->slug)];
        }
        $links[] = ['label' => $this->t('blog'), 'href' => $this->href('blog')];

        return $links;
    }

    /** @return array<int, array{label: string, href: string}> */
    public function legalLinks(): array
    {
        return collect(['privacy', 'terms'])
            ->filter(fn ($t) => $this->pages->has($t))
            ->map(fn ($t) => ['label' => $this->pages[$t]->title, 'href' => $this->href($this->pages[$t]->slug)])
            ->values()->all();
    }

    public function primaryCta(): ?array
    {
        $link = collect($this->brand->cta_links ?? [])->first(fn ($l) => filled($l['url'] ?? null));

        return $link ? ['label' => $link['label'] ?? $this->t('read_more'), 'url' => $link['url']] : null;
    }

    public function image(?string $source, int $maxWidth = 1600): ?array
    {
        return $this->media->publish($source, $maxWidth);
    }

    /** @return array<int, array{image: array, alt: string, caption: ?string}> */
    public function gallery(int $limit = 8): array
    {
        $items = [];
        foreach ($this->brand->media ?? [] as $item) {
            if (($item['type'] ?? 'image') !== 'image') {
                continue;
            }
            if ($img = $this->image($item['path'] ?? null, 1200)) {
                $items[] = ['image' => $img, 'alt' => $item['alt'] ?? $this->brand->product_name, 'caption' => $item['caption'] ?? null];
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function videos(): array
    {
        return collect($this->brand->media ?? [])->where('type', 'video')->filter(fn ($v) => filled($v['url'] ?? null))->values()->all();
    }

    public function logo(): ?array
    {
        return $this->site->logo_path ? $this->image($this->site->logo_path, 160) : null;
    }
}
