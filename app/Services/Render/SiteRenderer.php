<?php

namespace App\Services\Render;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Models\Article;
use App\Models\Page;
use App\Models\Site;
use App\Services\Content\SeoRules;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;

/**
 * Renders a site's pages to HTML strings from the shared theme engine, the
 * site's design config and its content. Writing files is StaticSiteBuilder's job.
 */
class SiteRenderer
{
    public readonly ThemeContext $ctx;

    protected ?Collection $visibleArticles = null;

    protected ?array $assets = null;

    public function __construct(public readonly Site $site, string $siteDir)
    {
        $site->loadMissing(['domain', 'brandProfile']);
        $this->ctx = new ThemeContext($site, new MediaPublisher($siteDir));
        // Thin pages stay reachable by URL but are not promoted in menus.
        $this->ctx->hidden = $this->ctx->pages->filter(fn (Page $p) => $this->isNoindex($p))->keys()
            ->when(! $this->blogIsIndexable(), fn ($c) => $c->push('blog'))->values()->all();
    }

    /** Listing pages need a few posts before they are worth indexing. */
    public function minListed(): int
    {
        return max(1, (int) Settings::get('min_list_articles', 3));
    }

    public function blogIsIndexable(): bool
    {
        return $this->listedArticles()->count() >= $this->minListed();
    }

    /** Stored noindex, or a page that is only an (almost) empty article list. */
    public function isNoindex(Page $page): bool
    {
        if ($page->noindex) {
            return true;
        }
        $blocks = collect($this->ctx->blocks($page->type))->pluck('block');
        if ($page->type === 'home' || ! $blocks->contains('article_list') || $blocks->diff(['article_list', 'cta', 'download', 'notice'])->isNotEmpty()) {
            return false;
        }
        $listed = $page->type === 'updates' ? $this->listedArticles()->where('type', ArticleType::Update) : $this->listedArticles();

        return $listed->count() < $this->minListed();
    }

    public function perPage(): int
    {
        return (int) config('theme.articles_per_page', 12);
    }

    /** Published + noindexed articles (both have a detail page), newest first. */
    public function articles(): Collection
    {
        return $this->visibleArticles ??= $this->site->articles()
            ->whereIn('status', [ArticleStatus::Published->value, ArticleStatus::Noindex->value])
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->get();
    }

    /** Articles shown in listings (noindexed cold content is hidden from lists). */
    public function listedArticles(): Collection
    {
        return $this->articles()->where('status', ArticleStatus::Published);
    }

    public function blogPageCount(): int
    {
        return max(1, (int) ceil($this->listedArticles()->count() / $this->perPage()));
    }

    public function forgetArticles(): void
    {
        $this->visibleArticles = null;
    }

    public function renderPage(Page $page): string
    {
        $ctx = $this->ctx;
        $ctx->beginPage($page->slug);
        $isHome = $page->type === 'home';
        $head = SeoHead::for($ctx, $page->slug, $this->pageTitle($page), $this->pageDescription($page), $this->isNoindex($page));
        $head->image = $this->ogImage();
        $head->withWebPage($page->updated_at);
        if ($faq = $this->faqItems($page)) {
            $head->withFaq($faq);
        }

        if ($isHome || $page->type === 'product') {
            $head->withProductSchema();
        }
        if (! $isHome) {
            $head->withBreadcrumbs([
                ['name' => $ctx->t('home'), 'url' => $ctx->url()],
                ['name' => $page->title, 'url' => $ctx->url($page->slug)],
            ]);
        }

        $listed = $page->type === 'updates'
            ? $this->listedArticles()->where('type', ArticleType::Update)->take(24)
            : $this->listedArticles()->take(6);

        $content = $page->content ?? [];
        if (filled($content['prose']['html'] ?? null)) {
            // Contextual links from the page copy to the site's other pages and posts.
            $targets = array_filter($this->linkTargets(), fn ($href) => trim($href, '/') !== trim($page->slug, '/'));
            [$content['prose']['html']] = InternalLinker::link($content['prose']['html'], $targets, 4);
        }
        $body = $this->renderBlocks($page->type, $content, $page, ['articles' => $listed]);
        if (! $isHome && ! in_array($page->type, ['privacy', 'terms'], true)) {
            $body .= View::make('theme.partials.see-also', ['ctx' => $ctx, 'page' => $page])->render();
        }

        return $this->layout($head, $body);
    }

    /** SEO title: a bare brand name or empty title becomes "<page> – <brand>" (home: brand – tagline). */
    public function pageTitle(Page $page): string
    {
        $brand = $this->site->product_name;
        $title = $page->meta_title ?: ($page->type === 'home' ? $this->site->title : null) ?: $page->title;
        if (! SeoRules::weakTitle($title, $brand)) {
            return $title;
        }
        if ($page->type === 'home') {
            $tail = $this->ctx->brand->tagline ?: $this->ctx->t('page_titles.product');

            return SeoRules::mentions($tail, $brand) ? $tail : $brand.' – '.$tail;
        }

        return SeoRules::mentions($page->title, $brand) ? $page->title.' – '.$this->ctx->t('page_titles.'.$page->type) : $page->title.' – '.$brand;
    }

    /** Meta description: the page's own, else its first real paragraph, else the site's or brand's. */
    public function pageDescription(Page $page): string
    {
        $brand = $this->site->product_name;
        $candidates = [$page->meta_description];
        foreach (['hero' => 'subheading', 'prose' => 'html', 'features' => 'intro', 'card_grid' => 'intro', 'steps' => 'intro', 'cta' => 'text', 'contact' => 'html', 'legal' => 'html'] as $block => $field) {
            $candidates[] = $page->content[$block][$field] ?? null;
        }
        array_push($candidates, $this->site->description, $this->ctx->brand->tagline, $this->ctx->brand->description);
        foreach ($candidates as $text) {
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)));
            if (! SeoRules::weakDescription($text, $brand)) {
                return $text;
            }
        }

        return (string) $page->meta_description;
    }

    /** FAQ entries shown on a page (its own or the brand's), for FAQPage data. */
    protected function faqItems(Page $page): array
    {
        if (! collect($this->ctx->blocks($page->type))->contains('block', 'faq')) {
            return [];
        }
        $items = $page->content['faq']['items'] ?? $this->ctx->brand->faq ?? [];

        return collect($items)->filter(fn ($i) => filled($i['question'] ?? null) && filled($i['answer'] ?? null))->values()->all();
    }

    public function renderBlogIndex(int $n): string
    {
        $ctx = $this->ctx;
        $path = $n === 1 ? 'blog' : "blog/page/{$n}";
        $title = $ctx->t('blog_title').' – '.$this->site->name.($n > 1 ? ' – '.$ctx->t('page', ['n' => $n]) : '');
        $ctx->beginPage($path);
        $head = SeoHead::for($ctx, $path, $title, $ctx->t('blog_description', ['brand' => $this->site->product_name]), ! $this->blogIsIndexable());
        $head->withBreadcrumbs(array_filter([
            ['name' => $ctx->t('home'), 'url' => $ctx->url()],
            ['name' => $ctx->t('blog'), 'url' => $ctx->url('blog')],
            $n > 1 ? ['name' => $ctx->t('page', ['n' => $n]), 'url' => $ctx->url($path)] : null,
        ]));

        $articles = $this->listedArticles()->slice(($n - 1) * $this->perPage(), $this->perPage())->values();
        $total = $this->blogPageCount();
        $pagination = $total > 1 ? collect(range(1, $total))->map(fn ($i) => [
            'n' => $i, 'current' => $i === $n, 'href' => $i === 1 ? '/blog/' : "/blog/page/{$i}/",
        ])->all() : [];

        $body = $this->renderBlocks('blog', ['article_list' => ['heading' => $ctx->t('blog_title')]], null, [
            'articles' => $articles, 'pagination' => $pagination,
        ]);

        return $this->layout($head, $body);
    }

    public function renderArticle(Article $article): string
    {
        $ctx = $this->ctx;
        $ctx->beginPage($article->path());
        $head = SeoHead::for($ctx, $article->path(), $article->meta_title ?: $article->title, $article->meta_description, $article->status === ArticleStatus::Noindex);
        $featured = $article->featured_image ? $ctx->image($article->featured_image, 1400) : null;
        $head->image = $featured ? $this->site->baseUrl().$featured['src'] : $this->ogImage();
        $head->preloadImage = $featured['src'] ?? null;
        $head->withBreadcrumbs([
            ['name' => $ctx->t('home'), 'url' => $ctx->url()],
            ['name' => $ctx->t('blog'), 'url' => $ctx->url('blog')],
            ['name' => SeoHead::limit($article->title, 70), 'url' => $ctx->url($article->path())],
        ])->withArticle($article);

        [$bodyHtml] = InternalLinker::link(HtmlSanitizer::clean($article->body), $this->linkTargets($article));

        $html = View::make('theme.article', [
            'ctx' => $ctx, 'article' => $article, 'bodyHtml' => $bodyHtml, 'related' => $this->related($article),
        ])->render();
        $html .= $this->renderBlocks('article', [], null, ['articles' => collect()]);

        return $this->layout($head, $html);
    }

    public function renderNotFound(): string
    {
        $this->ctx->beginPage('404');
        $head = SeoHead::for($this->ctx, '404', $this->ctx->t('not_found_title').' – '.$this->site->name, null, true);
        $body = View::make('theme.not-found', ['ctx' => $this->ctx])->render();
        $body .= $this->renderBlocks('not_found', [], null, ['articles' => $this->listedArticles()->take(3)]);

        return $this->layout($head, $body);
    }

    /**
     * Phrase => URL map for automatic internal links: other articles' target
     * keywords and core pages' titles. Self links are excluded.
     *
     * @return array<string, string>
     */
    public function linkTargets(?Article $self = null): array
    {
        $targets = [];
        foreach ($this->listedArticles() as $other) {
            if ($self && $other->id === $self->id) {
                continue;
            }
            $phrase = $other->keyword?->keyword;
            if ($phrase) {
                $targets[$phrase] = $this->ctx->href($other->path());
            }
        }
        foreach ($this->ctx->pages as $page) {
            if (in_array($page->type, ['home', 'privacy', 'terms', 'contact'], true)) {
                continue;
            }
            $targets[mb_strtolower($page->title)] ??= $this->ctx->href($page->slug);
        }

        return $targets;
    }

    /** Up to 3 related articles by title/keyword token overlap, newest as tiebreaker. */
    public function related(Article $article, int $limit = 3): Collection
    {
        $tokens = fn (Article $a) => collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($a->title.' '.($a->keyword?->keyword ?? '')), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($t) => mb_strlen($t) > 3)->unique();
        $mine = $tokens($article);

        return $this->listedArticles()
            ->reject(fn ($a) => $a->id === $article->id)
            ->map(fn ($a) => ['a' => $a, 's' => $mine->intersect($tokens($a))->count() + ($a->type === $article->type ? 0.5 : 0)])
            ->sortByDesc(fn ($x) => [$x['s'], $x['a']->published_at?->timestamp])
            ->take($limit)
            ->pluck('a')
            ->values();
    }

    protected function renderBlocks(string $pageType, array $content, ?Page $page, array $extra = []): string
    {
        $html = '';
        $first = $page !== null || $pageType === 'blog';
        foreach ($this->ctx->blocks($pageType) as $entry) {
            // Strips (notice, stats) never carry the page's <h1>; the next block does.
            $isFirst = $first && ! in_array($entry['block'], ['notice', 'stats'], true);
            $html .= View::make('theme.blocks.'.$entry['block'], [
                'ctx' => $this->ctx,
                'variant' => $entry['variant'],
                'data' => $content[$entry['block']] ?? [],
                'page' => $page,
                'isFirst' => $isFirst,
            ] + $extra)->render();
            $first = $first && ! $isFirst;
        }

        return $html;
    }

    public function ogImageUrl(): ?string
    {
        return $this->ogImage();
    }

    /**
     * Per-site path for the click/impression beacon instead of one shared
     * "/_t/" on every site of the network (a footprint).
     */
    public static function trackingPrefix(Site $site): string
    {
        return 'x'.substr(hash_hmac('sha256', 'track|'.$site->id, (string) config('app.key')), 0, 6);
    }

    protected function ogImage(): ?string
    {
        $first = collect($this->ctx->brand->media ?? [])->firstWhere('type', 'image') ?? ($this->ctx->brand->media[0] ?? null);
        $img = $first ? $this->ctx->image($first['path'] ?? null, 1200) : null;

        return $img ? $this->site->baseUrl().$img['src'] : null;
    }

    protected ?MarkupScrambler $scrambler = null;

    public function scrambler(): ?MarkupScrambler
    {
        return MarkupScrambler::enabled() ? ($this->scrambler ??= new MarkupScrambler($this->site)) : null;
    }

    /** The theme stylesheet as this site serves it (own copy, own class names). */
    public function baseCss(): string
    {
        $css = (string) file_get_contents(resource_path('theme/base.css'));

        return $this->scrambler()?->css($css) ?? $css;
    }

    /** Content-hashed file name, so each site's copy is cached forever and changes bust it. */
    public function baseCssName(): string
    {
        return 's'.substr(md5($this->baseCss()), 0, 10).'.css';
    }

    protected function layout(SeoHead $head, string $body): string
    {
        $html = View::make('theme.layout', [
            'ctx' => $this->ctx,
            'head' => $head,
            'body' => $body,
            'assets' => $this->assets(),
            'ads' => $this->assets()['ads'],
        ])->render();

        return $this->scrambler()?->html($html) ?? $html;
    }

    public function assets(): array
    {
        if ($this->assets !== null) {
            return $this->assets;
        }

        $ads = ['top' => [], 'bottom' => []];
        foreach ($this->site->ads()->where('is_active', true)->get() as $ad) {
            $ads[$ad->position][] = $ad->only(['id', 'code', 'desktop_width', 'desktop_height', 'mobile_width', 'mobile_height']);
        }

        $fonts = collect($this->ctx->design['fonts'])->unique()
            ->map(fn ($f) => config("theme.fonts.{$f}.file"))
            ->filter(fn ($file) => $file && is_file(public_path("theme/fonts/{$file}")))
            ->map(fn ($file) => "/assets/fonts/{$file}")
            ->values()->all();

        return $this->assets = [
            'critical_css' => trim(preg_replace('/\s+/', ' ', file_get_contents(resource_path('theme/critical.css')))),
            'base_css' => '/assets/'.$this->baseCssName(),
            'site_css' => '/assets/site.css?v='.substr(md5($this->siteCss()), 0, 10),
            'preload_fonts' => array_slice($fonts, 0, 2),
            'cookie_banner' => (bool) Settings::get('cookie_banner', true),
            'ads' => $ads,
            'has_ads' => $ads['top'] !== [] || $ads['bottom'] !== [],
            'ads_js' => $this->adsScript(),
        ];
    }

    /** Per-site variables (~1KB) + @font-face + validated custom CSS. */
    public function siteCss(): string
    {
        $d = $this->ctx->design;
        $c = $d['colors'];
        $theme = config('theme');
        $fontFamily = fn ($key) => $theme['fonts'][$key]['family'] ?? $theme['fonts']['system']['family'];

        $css = '';
        foreach (collect($d['fonts'])->unique() as $font) {
            $file = $theme['fonts'][$font]['file'] ?? null;
            if ($file && is_file(public_path("theme/fonts/{$file}"))) {
                $name = trim(explode(',', $theme['fonts'][$font]['family'])[0], '"');
                $css .= "@font-face{font-family:\"{$name}\";src:url(/assets/fonts/{$file}) format(\"woff2\");font-weight:300 800;font-display:swap;unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}\n";
            }
        }

        $css .= ':root{'
            ."--c-primary:{$c['primary']};--c-on-primary:{$c['on_primary']};--c-secondary:{$c['secondary']};--c-on-secondary:{$c['on_secondary']};"
            ."--c-accent:{$c['accent']};--c-bg:{$c['background']};--c-surface:{$c['surface']};--c-text:{$c['text']};--c-muted:{$c['muted']};--c-link:{$c['link']};"
            .'--f-heading:'.$fontFamily($d['fonts']['heading']).';--f-body:'.$fontFamily($d['fonts']['body']).';'
            .'--radius:'.$theme['radius'][$d['radius']].';--space:'.$theme['density'][$d['density']].';--shadow:'.$theme['shadow'][$d['shadow']].'}'."\n";

        if (filled($this->site->custom_css)) {
            $css .= "/* custom */\n".$this->site->custom_css."\n";
        }

        return $this->scrambler()?->css($css) ?? $css;
    }

    /**
     * Ads load only after the page has rendered (load + idle), one random ad
     * per slot, inside a space-reserved container. Impressions are beaconed;
     * clicks are estimated from iframe focus and flagged as estimates.
     */
    protected function adsScript(): string
    {
        $siteId = (int) $this->site->id;
        $track = self::trackingPrefix($this->site);

        return <<<JS
(function(){function run(){var m=window.matchMedia('(min-width:860px)').matches;document.querySelectorAll('[data-ad-slot]').forEach(function(slot){var t=slot.querySelectorAll('template');if(!t.length)return;var tpl=t[Math.floor(Math.random()*t.length)],id=tpl.getAttribute('data-ad-id'),w=tpl.getAttribute(m?'data-w-d':'data-w-m'),h=tpl.getAttribute(m?'data-h-d':'data-h-m');var box=document.createElement('div');if(w)box.style.width=w+'px';if(h)box.style.minHeight=h+'px';box.style.maxWidth='100%';box.appendChild(tpl.content.cloneNode(true));box.querySelectorAll('script').forEach(function(old){var s=document.createElement('script');for(var i=0;i<old.attributes.length;i++)s.setAttribute(old.attributes[i].name,old.attributes[i].value);s.text=old.text;old.parentNode.replaceChild(s,old)});slot.appendChild(box);slot.setAttribute('data-ad-id',id);send(id,'imp')});window.addEventListener('blur',function(){var a=document.activeElement;if(a&&a.tagName==='IFRAME'){var s=a.closest('[data-ad-slot]');if(s)send(s.getAttribute('data-ad-id'),'click')}})}function send(id,e){try{navigator.sendBeacon('/{$track}/ad','ad='+id+'&site={$siteId}&e='+e)}catch(x){}}function idle(){(window.requestIdleCallback||function(f){setTimeout(f,200)})(run)}if(document.readyState==='complete')idle();else window.addEventListener('load',idle)})();
JS;
    }
}
