<?php

namespace App\Services\Render;

use App\Models\BrandProfile;
use App\Models\Page;
use App\Models\Site;
use App\Services\Content\SeoRules;
use App\Services\Theme\DefaultDesign;
use App\Services\Theme\DesignConfigValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;

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

    /** Page types left out of menus (thin or noindexed pages), and "blog" while it has too few posts. */
    public array $hidden = [];

    /** Path of the page being rendered, so links and CTAs never point to it. */
    public string $currentPath = '';

    /** Media already shown on the current page: an image appears once per page. */
    protected array $usedImages = [];

    public function __construct(public readonly Site $site, public readonly MediaPublisher $media)
    {
        $this->brand = $site->brandProfile?->forDisplay() ?? new BrandProfile(['product_name' => $site->product_name]);
        $this->lang = $site->language();
        $this->pages = $site->pages()->get()->keyBy('type');

        $config = $site->design_config ?: DefaultDesign::make($site->domain_id ?? $site->id, $site->typeConfig());
        $this->design = (new DesignConfigValidator)->validate($config, $site->typeConfig());
    }

    /** Start rendering another page. */
    public function beginPage(string $path): void
    {
        $this->currentPath = trim($path, '/');
        $this->usedImages = [];
    }

    /** html lang / og:locale: the language with the target market, e.g. en + PH = en-PH. */
    public function seoLocale(): string
    {
        $country = strtoupper((string) $this->site->target_country);

        return $country !== '' && preg_match('/^[A-Z]{2}$/', $country) ? $this->lang.'-'.$country : $this->site->locale;
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

    /** Body classes for the design's global style switches. */
    public function bodyClass(): string
    {
        return collect($this->design['style'] ?? [])->map(fn ($value, $key) => "st-{$key}-{$value}")->implode(' ');
    }

    public function blocks(string $pageType): array
    {
        return $this->design['pages'][$pageType] ?? [];
    }

    /**
     * Header menu in the order of the design's "menu" (taken from the reference
     * site), each item with its dropdown children; with $all a flat list of
     * every page follows, for the footer.
     *
     * @return array<int, array{label: string, href: string, children: array<int, array{label: string, href: string}>}>
     */
    public function navLinks(bool $all = false): array
    {
        $available = collect(array_keys($this->site->typeConfig()['pages']))
            ->reject(fn ($type) => in_array($type, ['home', 'privacy', 'terms'], true) || ! $this->pages->has($type))
            ->push('blog')->reject(fn ($type) => in_array($type, $this->hidden, true))->values();
        $menu = collect($this->design['menu'] ?? [])->intersect($available)->values();
        $submenu = collect($this->design['navigation']['submenu'] ?? [])->map(fn ($children) => collect($children)->intersect($available)->values());

        if ($all) {
            $nested = $menu->flatMap(fn ($type) => [$type, ...($submenu[$type] ?? [])]);

            return $nested->merge($available->diff($nested))->map(fn ($type) => $this->link($type) + ['children' => []])->values()->all();
        }

        return ($menu->isEmpty() ? $available : $menu)
            ->map(fn ($type) => $this->link($type) + ['children' => collect($submenu[$type] ?? [])->map(fn ($child) => $this->link($child))->all()])
            ->all();
    }

    /** @return array{label: string, href: string} */
    public function link(string $type): array
    {
        if ($type === 'blog') {
            return ['label' => $this->t('blog'), 'href' => $this->href('blog')];
        }
        $fallback = Lang::has("theme.page_titles.{$type}", $this->lang) ? $this->t("page_titles.{$type}") : null;

        // Menus get short labels; the page keeps its full SEO title.
        return ['label' => SeoRules::navLabel($this->pages[$type]->title, $fallback), 'href' => $this->href($this->pages[$type]->slug)];
    }

    /** Pages linked from a page's "see also" list: menu order, never the page itself. */
    public function relatedPages(string $type, int $limit = 3): array
    {
        return collect($this->navLinks(all: true))
            ->reject(fn ($l) => trim($l['href'], '/') === $this->currentPath)
            ->take($limit)->values()->all();
    }

    /** Header behaviour from the design (see DesignConfigValidator::navigation). */
    public function navigation(): array
    {
        return ($this->design['navigation'] ?? []) + ['submenu' => [], 'buttons' => 1, 'mobile_bar' => false, 'sticky' => true, 'home_link' => false];
    }

    /**
     * Brand call-to-action links, cleaned: links back to this site's home or
     * to the current page are dropped (a CTA must lead somewhere), account
     * wording ("Sign up", "Login") is replaced, external links get nofollow.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function ctaLinks(int $limit = 2): array
    {
        return collect($this->brand->cta_links ?? [])
            ->filter(fn ($l) => filled($l['url'] ?? null) && ! $this->isSelfLink($l['url']))
            ->map(fn ($l) => [
                'label' => filled($l['label'] ?? null) && ! SeoRules::accountLabel($l['label']) ? $l['label'] : $this->t('get_started'),
                'url' => $l['url'],
            ])->take($limit)->values()->all();
    }

    /** Whether a URL points to this site's home or to the page being rendered. */
    public function isSelfLink(string $url): bool
    {
        $parts = parse_url(trim($url));
        $host = strtolower($parts['host'] ?? '');
        if ($host !== '' && $host !== strtolower($this->site->domain?->name ?? '') && $host !== 'www.'.strtolower($this->site->domain?->name ?? '')) {
            return false;
        }
        $path = trim($parts['path'] ?? '', '/');

        return ! isset($parts['fragment']) && ($path === '' || $path === $this->currentPath);
    }

    /** rel for a link: external links are nofollow, internal ones need nothing. */
    public function rel(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === '' || $host === strtolower($this->site->domain?->name ?? '') ? '' : 'nofollow noopener';
    }

    /** Most useful internal page to send a visitor to when the brand has no CTA link. */
    public function fallbackCta(): ?array
    {
        foreach (['product', 'guide', 'faq', 'about', 'contact'] as $type) {
            $page = $this->pages->get($type);
            if ($page && trim($page->slug, '/') !== $this->currentPath && ! in_array($type, $this->hidden, true)) {
                return ['label' => $this->t('read_more'), 'url' => $this->href($page->slug)];
            }
        }

        return null;
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
        return $this->ctaLinks(1)[0] ?? $this->fallbackCta();
    }

    public function image(?string $source, int $maxWidth = 1600): ?array
    {
        return $this->media->publish($source, $maxWidth);
    }

    /**
     * Brand images not yet shown on this page; they count as shown from now
     * on, so a page never repeats an image (and its alt) across blocks.
     *
     * @return array<int, array{image: array, alt: string, caption: ?string}>
     */
    public function gallery(int $limit = 8): array
    {
        $items = [];
        foreach ($this->brand->media ?? [] as $item) {
            if (($item['type'] ?? 'image') !== 'image' || ! $this->useImage($item['path'] ?? null)) {
                continue;
            }
            if ($img = $this->image($item['path'] ?? null, 1200)) {
                $items[] = ['image' => $img, 'alt' => filled($item['alt'] ?? null) ? $item['alt'] : ($item['caption'] ?? $this->brand->product_name), 'caption' => $item['caption'] ?? null];
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /** Mark an image as shown on the current page; false when it already was. */
    public function useImage(?string $path): bool
    {
        if (! $path || isset($this->usedImages[$path])) {
            return false;
        }
        $this->usedImages[$path] = true;

        return true;
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
