<?php

namespace App\Services\Render;

use App\Models\Article;
use App\Models\Entry;
use Illuminate\Support\Carbon;

/**
 * Builds the platform-owned <head> data: title, description, canonical,
 * robots, Open Graph/Twitter and JSON-LD. Templates never decide
 * these, so all sites stay technically consistent.
 */
class SeoHead
{
    public string $title = '';

    public string $description = '';

    public string $canonical = '';

    public bool $noindex = false;

    public ?string $image = null;

    public ?string $preloadImage = null;

    public string $ogType = 'website';

    /** @var array<int, array> */
    public array $schema = [];

    /** @var array<int, array{name: string, url: string}> */
    public array $breadcrumbs = [];

    public function __construct(public readonly ThemeContext $ctx) {}

    public static function for(ThemeContext $ctx, string $path, string $title, ?string $description, bool $noindex = false, bool $exact = false): self
    {
        // Pages show their title and description as written (admin fields, column names).
        $head = new self($ctx);
        $head->title = $exact ? trim($title) : self::limit($title, 60);
        $head->description = $exact ? trim((string) $description) : self::limit((string) $description, 155);
        $head->canonical = $ctx->url($path);
        $head->noindex = $noindex;
        $head->schema = $head->baseSchema();

        return $head;
    }

    public static function limit(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, ' ,.;:-–—').'…';
    }

    public function withBreadcrumbs(array $crumbs): self
    {
        $this->breadcrumbs = $crumbs;
        if (count($crumbs) > 1) {
            $this->schema[] = [
                '@type' => 'BreadcrumbList',
                'itemListElement' => collect($crumbs)->values()->map(fn ($c, $i) => [
                    '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['url'],
                ])->all(),
            ];
        }

        return $this;
    }

    public function withProductSchema(): self
    {
        $site = $this->ctx->site;
        $brand = $this->ctx->brand;
        $type = $site->typeConfig()['schema'] ?? 'SoftwareApplication';
        $specs = collect($brand->specs ?? [])->mapWithKeys(fn ($s) => [mb_strtolower($s['key'] ?? '') => $s['value'] ?? '']);

        $node = array_filter([
            '@type' => $type,
            'name' => $brand->product_name ?: $site->product_name,
            'description' => self::limit((string) ($brand->tagline ?: $brand->description), 300),
            'url' => $site->url(),
            'image' => $this->image,
            'inLanguage' => $this->ctx->seoLocale(),
            'brand' => $type === 'Product' ? ['@type' => 'Brand', 'name' => $brand->product_name ?: $site->product_name] : null,
            'publisher' => ['@id' => $site->url().'#organization'],
        ]);

        // Game / app specifics only when the brand profile states them. No
        // offers or aggregateRating: we never invent prices or reviews.
        if ($type === 'VideoGame') {
            $node += array_filter([
                'genre' => $specs->first(fn ($v, $k) => in_array($k, ['genre', 'gênero', 'genero', 'tipo', 'type', '类型'], true)),
                'gamePlatform' => $specs->first(fn ($v, $k) => in_array($k, ['platform', 'plataforma', 'plataformas', '平台'], true)),
            ]);
        }
        if ($type === 'SoftwareApplication') {
            $node += array_filter([
                'applicationCategory' => $specs->first(fn ($v, $k) => in_array($k, ['category', 'categoria', 'categoría', '类型'], true)),
                'operatingSystem' => $specs->first(fn ($v, $k) => in_array($k, ['platform', 'plataforma', 'os', 'sistema', '平台'], true)),
            ]);
        }
        $this->schema[] = $node;

        return $this;
    }

    /** VideoGame node of a game detail page, from the entry's known facts only. */
    public function withEntry(Entry $entry, string $path): self
    {
        $this->schema[] = array_filter([
            '@type' => 'VideoGame',
            'name' => $entry->title,
            'url' => $this->ctx->url($path),
            'description' => $entry->summary ? self::limit($entry->summary, 300) : null,
            'image' => $this->image,
            'genre' => array_values(array_filter((array) $entry->get('themes', []), 'is_string')) ?: null,
            'author' => filled($entry->get('provider')) ? ['@type' => 'Organization', 'name' => (string) $entry->get('provider')] : null,
            'inLanguage' => $this->ctx->seoLocale(),
            'publisher' => ['@id' => $this->ctx->site->url().'#organization'],
        ]);

        return $this;
    }

    /** WebPage node with its last real change, so freshness is explicit. */
    public function withWebPage(?\DateTimeInterface $modified): self
    {
        $this->schema[] = array_filter([
            '@type' => 'WebPage',
            '@id' => $this->canonical.'#webpage',
            'url' => $this->canonical,
            'name' => $this->title,
            'description' => $this->description ?: null,
            'inLanguage' => $this->ctx->seoLocale(),
            'isPartOf' => ['@id' => $this->ctx->site->url().'#website'],
            'dateModified' => $modified ? Carbon::instance($modified)->toAtomString() : null,
        ]);

        return $this;
    }

    /** @param  array<int, array{question: string, answer: string}>  $items */
    public function withFaq(array $items): self
    {
        $this->schema[] = [
            '@type' => 'FAQPage',
            'mainEntity' => collect($items)->take(20)->map(fn ($i) => [
                '@type' => 'Question',
                'name' => strip_tags($i['question']),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags($i['answer'])],
            ])->values()->all(),
        ];

        return $this;
    }

    public function withArticle(Article $article): self
    {
        $site = $this->ctx->site;
        $this->ogType = 'article';
        $this->schema[] = array_filter([
            '@type' => 'Article',
            'headline' => self::limit($article->title, 110),
            'description' => $this->description,
            'image' => $this->image,
            'datePublished' => $article->published_at?->toAtomString(),
            'dateModified' => ($article->updated_at ?? $article->published_at)?->toAtomString(),
            'inLanguage' => $this->ctx->seoLocale(),
            'mainEntityOfPage' => $this->canonical,
            'author' => ['@type' => 'Organization', 'name' => $article->author ?: $this->ctx->t('editorial', ['brand' => $site->name]), 'url' => $site->url()],
            'publisher' => ['@id' => $site->url().'#organization'],
        ]);

        return $this;
    }

    protected function baseSchema(): array
    {
        $site = $this->ctx->site;
        $brand = $this->ctx->brand;
        $logo = $this->ctx->logo();

        return [
            array_filter([
                '@type' => 'Organization',
                '@id' => $site->url().'#organization',
                'name' => $brand->company_name ?: $site->name,
                'url' => $site->url(),
                'logo' => $logo ? MediaPublisher::absolute($site, $logo['src']) : null,
                'email' => $brand->company_email,
                'address' => $brand->company_address,
            ]),
            [
                '@type' => 'WebSite',
                '@id' => $site->url().'#website',
                'name' => $site->name,
                'url' => $site->url(),
                'inLanguage' => $this->ctx->seoLocale(),
                'publisher' => ['@id' => $site->url().'#organization'],
            ],
        ];
    }

    public function jsonLd(): string
    {
        return json_encode(['@context' => 'https://schema.org', '@graph' => array_values($this->schema)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    }
}
