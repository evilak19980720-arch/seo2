<?php

namespace App\Services\Render;

use App\Models\BrandProfile;
use App\Models\Entry;
use App\Models\Page;
use App\Models\Site;
use App\Services\Content\SeoRules;
use App\Services\Sites\SiteStructure;
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

    /** Entries already listed on the current page, so sections show different items where they can. */
    protected array $shownEntries = [];

    protected ?Collection $entryCache = null;

    public function __construct(public readonly Site $site, public readonly MediaPublisher $media)
    {
        $this->brand = $site->brandProfile?->forDisplay() ?? new BrandProfile(['product_name' => $site->product_name]);
        $this->lang = $site->language();
        // Pages of types the site no longer has wait for the next generation to be removed.
        $types = $site->typeConfig()['pages'];
        $this->pages = $site->pages()->get()->filter(fn (Page $p) => isset($types[$p->type]))->keyBy('type');

        $config = $site->design_config ?: DefaultDesign::make($site->domain_id ?? $site->id, $site->typeConfig());
        $this->design = (new DesignConfigValidator)->validate($config, $site->typeConfig());
    }

    /** Start rendering another page. */
    public function beginPage(string $path): void
    {
        $this->currentPath = trim($path, '/');
        $this->usedImages = [];
        $this->shownEntries = [];
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
            ->reject(fn ($type) => $type === 'home' || in_array($type, $this->legalTypes(), true) || ! $this->pages->has($type))
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
        foreach (['product', 'games', 'guide', 'help', 'faq', 'about', 'contact'] as $type) {
            $page = $this->pages->get($type);
            if ($page && trim($page->slug, '/') !== $this->currentPath && ! in_array($type, $this->hidden, true)) {
                return ['label' => $this->t('read_more'), 'url' => $this->href($page->slug)];
            }
        }

        return null;
    }

    /** Page types listed under "Legal" in the footer instead of the page list. */
    public function legalTypes(): array
    {
        $pages = $this->site->typeConfig()['pages'];
        $legal = array_keys(array_filter($pages, fn ($p) => ($p['nav'] ?? null) === 'legal'));

        return $legal !== [] ? $legal : ['privacy', 'terms'];
    }

    /** @return array<int, array{label: string, href: string}> */
    public function legalLinks(): array
    {
        return collect($this->legalTypes())
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

    /** All entries of the site, in their sort order. */
    public function allEntries(): Collection
    {
        return $this->entryCache ??= $this->site->exists
            ? $this->site->entries()->orderBy('sort')->orderBy('id')->get()
            : collect();
    }

    /**
     * Entries of a collection matching a section filter: all, hot, new,
     * classic, featured, demo, active, upcoming, ongoing, ended, latest,
     * tag:x, type:x, theme:x or device:x.
     * "hot" and "new" fall back to sort order and date when nothing is tagged.
     */
    public function entries(string $collection, string $filter = 'all', ?int $limit = null): Collection
    {
        $items = $this->allEntries()->where('collection', $collection)->values();
        $byDate = fn (Collection $c) => $c->sortByDesc(fn (Entry $e) => $e->date()?->timestamp ?? 0)->values();
        $tagged = fn (string $tag) => $items->filter(fn (Entry $e) => in_array($tag, $e->tags(), true))->values();
        $live = fn () => $items->reject(fn (Entry $e) => $e->status() === 'ended');

        $result = match (true) {
            $filter === 'hot' => $tagged('hot')->whenEmpty(fn () => $items),
            $filter === 'new' => $tagged('new')->whenEmpty(fn () => $byDate($items)),
            $filter === 'featured' => $items->filter(fn (Entry $e) => $e->get('featured') || in_array('featured', $e->tags(), true))->whenEmpty($live),
            $filter === 'active' => $live(),
            $filter === 'ongoing' => $items->filter(fn (Entry $e) => in_array($e->status(), ['ongoing', null], true)),
            $filter === 'upcoming' && $collection === 'game' => $items->filter(fn (Entry $e) => in_array('upcoming', $e->tags(), true) || ($e->date()?->isFuture() ?? false)),
            in_array($filter, ['upcoming', 'ended'], true) => $items->filter(fn (Entry $e) => $e->status() === $filter),
            $filter === 'demo' => $items->filter(fn (Entry $e) => (bool) $e->get('demo')),
            str_starts_with($filter, 'type:') => $items->filter(fn (Entry $e) => in_array(substr($filter, 5), (array) $e->get('types', []), true)),
            str_starts_with($filter, 'theme:') => $items->filter(fn (Entry $e) => in_array(substr($filter, 6), (array) $e->get('theme_keys', []), true)),
            str_starts_with($filter, 'device:') => $items->filter(fn (Entry $e) => in_array(substr($filter, 7), (array) $e->get('devices', []), true)),
            $filter === 'latest' => $byDate($items),
            str_starts_with($filter, 'tag:') => $tagged(substr($filter, 4)),
            $filter === 'classic' => $tagged('classic'),
            default => in_array($collection, ['news', 'notice'], true) ? $byDate($items) : $items,
        };

        return ($limit ? $result->take($limit) : $result)->values();
    }

    /** Like entries(), preferring items no other section of this page listed yet. */
    public function pickEntries(string $collection, string $filter, int $limit): Collection
    {
        $all = $this->entries($collection, $filter);
        $fresh = $all->reject(fn (Entry $e) => isset($this->shownEntries[$e->id]));
        $chosen = ($fresh->count() >= min($limit, 3) ? $fresh : $all)->take($limit)->values();
        foreach ($chosen as $entry) {
            $this->shownEntries[$entry->id] = true;
        }

        return $chosen;
    }

    /** Page type hosting a collection's detail pages, when the site has it. */
    public function ownerType(string $collection): ?string
    {
        $owner = SiteStructure::owner($this->site->typeConfig(), $collection);

        return $owner && $this->pages->has($owner) ? $owner : null;
    }

    /** "{owner page slug}/{entry slug}", or null when the site has no page for the collection. */
    public function entryPath(Entry $entry): ?string
    {
        $owner = $this->ownerType($entry->collection);

        return $owner ? trim($this->pages[$owner]->slug.'/'.$entry->slug, '/') : null;
    }

    public function entryHref(Entry $entry): ?string
    {
        $path = $this->entryPath($entry);

        return $path !== null ? $this->href($path) : null;
    }

    /** Definition of a page section (collection, filter, link…) from the site type. */
    public function section(string $pageType, ?string $key): array
    {
        return collect($this->site->typeConfig()['pages'][$pageType]['sections'] ?? [])->firstWhere('key', $key) ?? [];
    }
}
