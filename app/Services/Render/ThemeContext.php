<?php

namespace App\Services\Render;

use App\Models\BrandProfile;
use App\Models\Entry;
use App\Models\Page;
use App\Models\Site;
use App\Services\Content\SeoRules;
use App\Services\Sites\PictureRole;
use App\Services\Sites\ReferenceLibrary;
use App\Services\Sites\SiteStructure;
use App\Services\Theme\DefaultDesign;
use App\Services\Theme\DesignConfigValidator;
use Illuminate\Support\Arr;
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

    protected bool $taglineUsed = false;

    protected ?Collection $entryCache = null;

    /** Blocks whose picture is a page's main visual (a picture slot). */
    public const PICTURE_BLOCKS = ['hero', 'banner'];

    protected ?array $slots = null;

    protected ?Collection $poolCache = null;

    protected ?Collection $referenceCache = null;

    public function __construct(public readonly Site $site, public readonly MediaPublisher $media)
    {
        $this->brand = $site->brandProfile?->forDisplay() ?? new BrandProfile(['product_name' => $site->product_name]);
        $this->lang = $site->language();
        ThemeStrings::load($this->lang);
        // Pages of types the site no longer has wait for the next generation to be removed.
        $types = $site->typeConfig()['pages'];
        $this->pages = $site->pages()->get()->filter(fn (Page $p) => isset($types[$p->type]))->keyBy('type');

        $config = $site->design_config ?: DefaultDesign::make($site->domain_id ?? $site->id, $site->typeConfig());
        $this->design = self::withLatestArticles((new DesignConfigValidator)->validate($config, $site->typeConfig()));
    }

    /**
     * Every home page shows the latest articles (sectioned layouts and AI
     * designs may leave them out), so a new article is visible and linked
     * from the first page at once. Shown only while there are articles.
     */
    protected static function withLatestArticles(array $design): array
    {
        $home = $design['pages']['home'] ?? null;
        if ($home === null || collect($home)->contains('block', 'article_list')) {
            return $design;
        }
        // Before a closing call to action, else last.
        $at = ($home !== [] && (end($home)['block'] ?? null) === 'cta') ? count($home) - 1 : count($home);
        array_splice($home, $at, 0, [['block' => 'article_list', 'variant' => 'cards']]);
        $design['pages']['home'] = $home;

        return $design;
    }

    /** Start rendering another page. */
    public function beginPage(string $path): void
    {
        $this->currentPath = trim($path, '/');
        $this->usedImages = [];
        $this->shownEntries = [];
        $this->taglineUsed = false;
    }

    /**
     * The brand tagline as stand-in copy for a block without its own text,
     * once per page: a second block falls back to nothing instead of
     * repeating the same promo line (the footer keeps its own).
     */
    public function tagline(): ?string
    {
        if ($this->taglineUsed || blank($this->brand->tagline ?? null)) {
            return null;
        }
        $this->taglineUsed = true;

        return $this->brand->tagline;
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
        // Articles are the site's fresh content: once the blog is listed, the main menu links to it.
        if ($menu->isNotEmpty() && $available->contains('blog') && ! $menu->contains('blog') && ! collect($this->design['navigation']['submenu'] ?? [])->flatten()->contains('blog')) {
            $menu->push('blog');
        }
        $submenu = collect($this->design['navigation']['submenu'] ?? [])->map(fn ($children) => collect($children)->intersect($available)->values());

        if ($all) {
            $nested = $menu->flatMap(fn ($type) => [$type, ...($submenu[$type] ?? [])]);

            return $nested->merge($available->diff($nested))->map(fn ($type) => $this->link($type) + ['children' => []])->values()->all();
        }

        return ($menu->isEmpty() ? $available : $menu)
            ->map(fn ($type) => $this->link($type) + ['children' => collect($submenu[$type] ?? [])->map(fn ($child) => $this->link($child))->all()])
            ->all();
    }

    /**
     * Footer link groups, following the menu: a menu item with a submenu is
     * its own group, the other menu items form "Pages", every page outside
     * the menu "More". Phones show them folded (one tap opens a group).
     *
     * @return array<int, array{title: string, links: array<int, array{label: string, href: string}>}>
     */
    public function footerGroups(): array
    {
        $links = collect($this->navLinks(all: true))->keyBy('href');
        $groups = [];
        $plain = [];
        foreach ($this->navLinks() as $item) {
            if ($item['children'] !== []) {
                $groups[] = ['title' => $item['label'], 'links' => [Arr::except($item, 'children'), ...$item['children']]];
            } else {
                $plain[] = Arr::except($item, 'children');
            }
        }
        $used = collect($groups)->flatMap(fn ($g) => array_column($g['links'], 'href'))->merge(array_column($plain, 'href'))->all();
        $rest = $links->except($used)->map(fn ($l) => Arr::except($l, 'children'))->values()->all();
        if ($plain !== []) {
            array_unshift($groups, ['title' => $this->t('pages'), 'links' => $plain]);
        }
        if ($rest !== []) {
            $groups[] = ['title' => $this->t('more_pages'), 'links' => $rest];
        }

        return $groups;
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

    /**
     * The phone bottom bar: home, up to three (four without a CTA) menu pages
     * with labels short enough to read in a fifth of a phone, and the brand's
     * own call to action raised in the middle. A stand-in "Read more" link is
     * no call to action, so it is never raised. The current page is marked.
     *
     * @return array{items: array<int, array{label: string, href: string, current: bool}>, cta: ?array{label: string, url: string}, home_current: bool}
     */
    public function mobileBar(): array
    {
        $cta = $this->ctaLinks(1)[0] ?? null;
        $types = $this->pages->mapWithKeys(fn ($page, $type) => [$this->href($page->slug) => $type])->put($this->href('blog'), 'blog');
        $items = collect($this->navLinks())->take($cta ? 3 : 4)->map(function ($link) use ($types) {
            $type = $types[$link['href']] ?? null;
            $fallback = $type === 'blog' ? $this->t('blog') : ($type && Lang::has("theme.page_titles.{$type}", $this->lang) ? $this->t("page_titles.{$type}") : null);

            return ['label' => self::shortLabel($link['label'], $fallback), 'href' => $link['href'], 'current' => trim($link['href'], '/') === $this->currentPath];
        })->values()->all();
        if ($cta) {
            $cta['label'] = self::shortLabel($cta['label'], $this->t('get_started'));
        }

        return ['items' => $items, 'cta' => $cta, 'home_current' => $this->currentPath === ''];
    }

    /** A label that fits a bottom-bar slot: the page's own if short, else the default name, else its first word. */
    public static function shortLabel(string $label, ?string $fallback = null, int $max = 12): string
    {
        foreach (array_filter([trim($label), trim((string) $fallback)]) as $candidate) {
            if (mb_strlen($candidate) <= $max) {
                return $candidate;
            }
        }
        $source = $fallback !== null && trim($fallback) !== '' && mb_strlen(trim($fallback)) < mb_strlen(trim($label)) ? trim($fallback) : trim($label);
        $first = preg_split('/[\s\-–—|:·,]+/u', $source, -1, PREG_SPLIT_NO_EMPTY)[0] ?? $source;

        return mb_strlen($first) <= $max + 4 ? $first : mb_substr($first, 0, $max);
    }

    /** Header behaviour from the design (see DesignConfigValidator::navigation). */
    public function navigation(): array
    {
        return ($this->design['navigation'] ?? []) + ['submenu' => [], 'buttons' => 1, 'mobile_bar' => false, 'sticky' => true, 'home_link' => false,
            'mobile_header' => 'standard', 'mobile_menu' => 'dropdown', 'mobile_bar_style' => 'raised'];
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

    /** Whether a root-relative link is the page being rendered. */
    public function isCurrent(string $href): bool
    {
        return parse_url($href, PHP_URL_HOST) === null && trim((string) parse_url($href, PHP_URL_PATH), '/') === $this->currentPath;
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

    /**
     * Picture slots of the site in priority order: the first hero or banner
     * of each page, home first.
     *
     * @return array<int, string> e.g. ["hero:home", "banner:promotions"]
     */
    public function pictureSlots(): array
    {
        if ($this->slots !== null) {
            return $this->slots;
        }
        // Site type order (home, then the main pages), not database order.
        $order = array_flip(array_keys($this->site->typeConfig()['pages']));
        $types = collect($this->pages->keys())->sortBy(fn ($t) => [$t === 'home' ? 0 : 1, $order[$t] ?? PHP_INT_MAX])->values();
        $slots = [];
        foreach ($types as $type) {
            $blocks = array_column($this->blocks($type), 'block');
            $visual = collect($blocks)->first(fn ($b) => in_array($b, self::PICTURE_BLOCKS, true));
            if ($visual) {
                $slots[] = $visual.':'.$type;
            } elseif ($this->wantsCover($type, $blocks)) {
                $slots[] = 'cover:'.$type;
            }
        }

        return $this->slots = $slots;
    }

    /** Content pages without a hero get a cover picture; legal, utility and form pages do not. */
    protected function wantsCover(string $type, array $blocks): bool
    {
        if ($blocks === [] || in_array($type, $this->legalTypes(), true) || in_array($type, ['blog', 'article', 'not_found'], true)) {
            return false;
        }

        return ! in_array($blocks[0], ['legal', 'html_sitemap', 'site_search', 'contact', 'cookie_settings', 'glossary'], true);
    }

    /** Brand and reference pictures, in their order. */
    public function realPictures(): Collection
    {
        return collect($this->brand->media ?? [])
            ->filter(fn ($m) => ($m['type'] ?? 'image') === 'image' && filled($m['path'] ?? null))->values();
    }

    /**
     * The picture of a page's hero / banner: real pictures are shared out
     * first, one per slot; slots past them get the site's AI picture.
     *
     * @return array{path: string, alt: string}|null
     */
    public function slotPicture(string $block, ?string $pageType): ?array
    {
        if ($pageType === null) {
            return null;
        }
        $slot = $block.':'.$pageType;
        $index = array_search($slot, $this->pictureSlots(), true);
        $real = $this->realPictures();
        if ($index !== false && isset($real[$index])) {
            return ['path' => $real[$index]['path'], 'alt' => filled($real[$index]['alt'] ?? null) ? $real[$index]['alt'] : $this->brand->product_name];
        }
        $ai = $this->site->ai_images[$slot] ?? null;
        if (filled($ai['path'] ?? null)) {
            return ['path' => $ai['path'], 'alt' => $ai['alt'] ?? $this->brand->product_name];
        }
        // No AI picture: a reference picture the real ones left over (wide ones first), drawn in the site's own random order.
        if ($index !== false && ($pic = $this->referenceSlotPicture($index - $real->count()))) {
            return $pic;
        }
        // Then the real pictures in turn rather than leave the slot empty.
        if ($index !== false && $real->isNotEmpty()) {
            $pic = $real[$index % $real->count()];

            return ['path' => $pic['path'], 'alt' => filled($pic['alt'] ?? null) ? $pic['alt'] : $this->brand->product_name];
        }

        return null;
    }

    /**
     * Pictures downloaded from the reference site that are not among the
     * real pictures, in a random order stable for the site, each with its
     * role (logo, banner, photo) and whether it shows a year already over.
     *
     * @return Collection<int, array{path: string, alt: string, width: int, height: int, words: string, role: string, expired: bool}>
     */
    public function referencePictures(): Collection
    {
        if ($this->referenceCache !== null) {
            return $this->referenceCache;
        }
        $real = $this->realPictures()->pluck('path')->flip();
        $seed = (string) ($this->site->id ?? $this->site->domain_id ?? '');
        // A picture named after a provider is that provider's logo.
        $providers = $this->allEntries()->where('collection', 'provider')->map(fn (Entry $e) => SeoRules::normalize($e->title))->filter()->flip();

        return $this->referenceCache = ReferenceLibrary::pictures($this->site)
            ->reject(fn ($p) => isset($real[$p['path']]))
            ->map(fn ($p) => [
                'alt' => $p['alt'] ?? $this->brand->product_name,
                'words' => $p['words'] ?? (string) $p['alt'],
                'role' => filled($p['alt']) && isset($providers[SeoRules::normalize($p['alt'])])
                    ? PictureRole::LOGO : PictureRole::of($p['path'], ($p['source'] ?? '').' '.$p['alt'], $p['width'] ?: null, $p['height'] ?: null),
                'expired' => PictureRole::expired(($p['source'] ?? '').' '.($p['words'] ?? $p['alt'])),
            ] + $p)
            ->sortBy(fn ($p) => md5($seed.'|'.$p['path']))->values();
    }

    /** Reference pictures that may stand in for a card or a block: ordinary pictures, no logos, banners or past years. */
    public function photoPictures(): Collection
    {
        return $this->referencePictures()->filter(fn ($p) => $p['role'] === PictureRole::PHOTO && ! $p['expired'])->values();
    }

    /** The n-th reference picture for a hero / banner / cover slot: wide pictures first, then the rest; never a logo. */
    protected function referenceSlotPicture(int $n): ?array
    {
        $pictures = $this->referencePictures()->filter(fn ($p) => $p['role'] !== PictureRole::LOGO && ! $p['expired'])->values();
        if ($n < 0 || $pictures->isEmpty()) {
            return null;
        }
        // Unknown sizes count as wide enough: the hero shows them uncropped either way.
        $ordered = $pictures->partition(fn ($p) => ! $p['height'] || $p['width'] >= $p['height'] * 1.3)->flatten(1)->values();
        $pic = $ordered[$n % $ordered->count()];

        return ['path' => $pic['path'], 'alt' => $pic['alt']];
    }

    /**
     * The picture whose own words (card name and text, alt) fit the place's
     * words best: "Telegram channel" finds the Telegram picture. Logos only
     * when the place names them. Null when none fits well enough.
     *
     * @return array{path: string, alt: string}|null
     */
    public function matchPicture(?string $text, bool $onPage = true): ?array
    {
        if (blank($text)) {
            return null;
        }
        $ignore = collect([$this->brand->product_name, $this->site->name])->flatMap(fn ($n) => explode(' ', SeoRules::normalize((string) $n)))->filter()->all();
        $candidates = $this->realPictures()
            ->map(fn ($m) => ['path' => $m['path'], 'alt' => (string) ($m['alt'] ?? ''), 'words' => trim(($m['alt'] ?? '').' '.($m['caption'] ?? '')), 'role' => PictureRole::PHOTO, 'expired' => false])
            ->merge($this->referencePictures());
        $best = null;
        $score = 0.0;
        foreach ($candidates as $c) {
            if ($c['expired'] || ($onPage && isset($this->usedImages[$c['path']]))) {
                continue;
            }
            $s = PictureRole::match($text, $c['words'], $ignore);
            // A logo must carry the place's name, not just share a word with it.
            if ($c['role'] === PictureRole::LOGO && $s < 1.0) {
                continue;
            }
            if ($s > $score) {
                [$best, $score] = [$c, $s];
            }
        }

        return $best && $score >= 0.5 ? ['path' => $best['path'], 'alt' => filled($best['alt']) ? $best['alt'] : $this->brand->product_name] : null;
    }

    /**
     * Every picture that may stand in for a card (brand pictures, ordinary
     * reference pictures, then the pictures of games, promotions and other
     * entries), without repeats, logos or text banners.
     *
     * @return Collection<int, array{path: string, alt: string}>
     */
    public function picturePool(): Collection
    {
        return $this->poolCache ??= $this->realPictures()
            ->filter(fn ($m) => PictureRole::of($m['path'], $m['alt'] ?? null) === PictureRole::PHOTO)
            ->map(fn ($m) => ['path' => $m['path'], 'alt' => filled($m['alt'] ?? null) ? $m['alt'] : $this->brand->product_name])
            ->merge($this->photoPictures()->map(fn ($p) => ['path' => $p['path'], 'alt' => $p['alt']]))
            ->merge($this->allEntries()->filter(fn (Entry $e) => filled($e->image) && $e->collection !== 'provider')->map(fn (Entry $e) => ['path' => $e->image, 'alt' => $e->title]))
            ->unique('path')->values();
    }

    /**
     * A stand-in picture for a card or block that has none of its own: the
     * picture that fits its words ($text) when there is one, else a pool
     * picture not yet shown on this page, starting from a stable spot for the
     * key so the same card keeps the same picture. Null once every picture is
     * on the page: a page never shows one picture twice.
     *
     * @return array{image: array, alt: string}|null
     */
    public function fallbackImage(string $key, int $maxWidth = 800, ?string $text = null): ?array
    {
        if (($match = $this->matchPicture($text)) && $this->useImage($match['path']) && ($image = $this->image($match['path'], $maxWidth))) {
            return ['image' => $image, 'alt' => $match['alt']];
        }
        $pool = $this->picturePool();
        if ($pool->isEmpty()) {
            return null;
        }
        $start = crc32($key) % $pool->count();
        $ordered = $pool->slice($start)->merge($pool->slice(0, $start));
        foreach ($ordered as $pick) {
            if ($this->useImage($pick['path']) && ($image = $this->image($pick['path'], $maxWidth))) {
                return ['image' => $image, 'alt' => $pick['alt']];
            }
        }

        return null;
    }

    /**
     * A picture for an item (game, promotion, notice…) that has none of its
     * own: the picture that fits its name and summary first, else a random
     * ordinary reference picture not shown on this page, preferring ones no
     * other item owns, so an item never wears another item's cover while a
     * free picture is left.
     *
     * @return array{image: array, alt: string}|null
     */
    public function entryFallback(Entry $entry, int $maxWidth = 640): ?array
    {
        if (($match = $this->matchPicture($entry->title.' '.$entry->summary)) && $this->useImage($match['path']) && ($image = $this->image($match['path'], $maxWidth))) {
            return ['image' => $image, 'alt' => $entry->title];
        }
        $owned = $this->allEntries()->pluck('image')->filter()->flip();
        [$free, $taken] = $this->photoPictures()->partition(fn ($p) => ! isset($owned[$p['path']]));
        $pool = $free->values()->merge($taken->values());
        if ($pool->isEmpty()) {
            return null;
        }
        $start = hexdec(substr(md5($entry->collection.':'.$entry->id.':'.$entry->title), 0, 8)) % max(1, $free->count() ?: $pool->count());
        $ordered = $pool->slice($start)->merge($pool->slice(0, $start));
        foreach ($ordered as $pick) {
            if ($this->useImage($pick['path']) && ($image = $this->image($pick['path'], $maxWidth))) {
                return ['image' => $image, 'alt' => $entry->title];
            }
        }

        return null;
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

    /**
     * The header logo. A wide wordmark (already showing the name) is shown at
     * full height on its own, sharp enough for its width; a square mark sits
     * next to the name.
     *
     * @return array{src: string, avif: ?string, width: int, height: int, wordmark: bool}|null
     */
    public function logo(): ?array
    {
        $logo = $this->site->logo_path ? $this->image($this->site->logo_path, 160) : null;
        if (! $logo || empty($logo['height'])) {
            return $logo ? $logo + ['wordmark' => false] : null;
        }
        $wordmark = $logo['width'] / $logo['height'] >= 1.6;
        if ($wordmark) {
            $logo = $this->image($this->site->logo_path, 480) ?? $logo;
        }

        return $logo + ['wordmark' => $wordmark];
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
            // "New" means tagged new or dated: undated items are not new, the section then stays out.
            $filter === 'new' => $tagged('new')->whenEmpty(fn () => $byDate($items->filter(fn (Entry $e) => $e->date() !== null))),
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

    /**
     * The shape of a list's cards, from its items' own pictures: the most
     * common picture ratio (square game icons get square cards, so nothing
     * painted on them is cut off) and, for small pictures, the widest a card
     * may grow (1.5× the picture) so they are never blown up into a blur.
     *
     * @return array{ratio: ?string, cap: ?int} ratio null: too few own pictures, the layout keeps its own shape
     */
    public function tileShape(Collection $entries): array
    {
        $sizes = $entries->map(fn (Entry $e) => $e->image ? $this->image($e->image, 640) : null)->filter(fn ($i) => ($i['width'] ?? 0) > 0 && ($i['height'] ?? 0) > 0)->values();
        if ($sizes->count() < max(1, (int) ceil($entries->count() / 2))) {
            return ['ratio' => null, 'cap' => null];
        }
        $ratios = $sizes->map(fn ($i) => $i['width'] / $i['height'])->sort()->values();
        $median = $ratios[intdiv($ratios->count(), 2)];
        $ratio = match (true) {
            $median < 0.85 => '3/4',
            $median <= 1.18 => '1/1',
            $median <= 1.55 => '4/3',
            default => '16/9',
        };
        $widths = $sizes->pluck('width')->sort()->values();
        $width = $widths[intdiv($widths->count(), 2)];

        return ['ratio' => $ratio, 'cap' => $width < 420 ? (int) round($width * 1.5) : null];
    }

    /** Like entries(), but only items no other section of this page listed yet. */
    public function pickEntries(string $collection, string $filter, int $limit): Collection
    {
        $all = $this->entries($collection, $filter);
        $fresh = $all->reject(fn (Entry $e) => isset($this->shownEntries[$e->id]));
        // A later section never repeats what an earlier one listed: with nothing new left it stays out.
        $chosen = $fresh->take($limit)->values();
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
