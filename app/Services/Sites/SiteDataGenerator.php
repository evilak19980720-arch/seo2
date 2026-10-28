<?php

namespace App\Services\Sites;

use App\Enums\PromptType;
use App\Models\Entry;
use App\Models\GoneUrl;
use App\Models\Site;
use App\Services\Ai\AiManager;
use App\Services\Content\SeoRules;
use App\Services\Render\HtmlSanitizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fills a pool site's data (games, providers, promotions, events, notices,
 * news) from the reference site's pages and the brand profile: one call
 * lists the items with their facts, then detail copy is written in small
 * batches. Figures survive only when the sources state them; entries edited
 * by hand are never overwritten.
 */
class SiteDataGenerator
{
    /** Data fields kept per collection (anything else the model returns is dropped). */
    public const FIELDS = [
        'game' => ['provider', 'themes', 'mechanics', 'tags', 'types', 'theme_keys', 'devices', 'demo', 'rtp', 'volatility', 'max_win', 'paylines', 'reels', 'min_bet', 'max_bet', 'release_date', 'series'],
        'provider' => ['country', 'founded'],
        'promotion' => ['tags', 'reward', 'requirement', 'starts_at', 'ends_at', 'status'],
        'event' => ['tags', 'reward', 'requirement', 'starts_at', 'ends_at', 'status'],
        'notice' => ['date', 'tags'],
        'news' => ['date', 'tags'],
    ];

    public const TAGS = [
        'game' => ['hot', 'new', 'classic', 'featured', 'upcoming'],
        'promotion' => ['new_user', 'regular', 'festival', 'featured', 'anniversary', 'app', 'invite'],
        'event' => ['featured', 'launch', 'anniversary', 'festival', 'community', 'quiz', 'creative', 'collab', 'live', 'offline'],
        'notice' => ['maintenance', 'game_maintenance', 'rules', 'restored', 'new_game', 'removed', 'game_update', 'payments', 'policy', 'channels'],
        'news' => ['brand', 'new_games', 'features', 'updates', 'partners', 'editorial', 'monthly', 'community'],
    ];

    /** Fields that are figures: kept only when every number in them appears in the sources. */
    public const FIGURES = ['rtp', 'max_win', 'paylines', 'reels', 'min_bet', 'max_bet', 'reward', 'requirement', 'founded'];

    public const DATES = ['release_date', 'starts_at', 'ends_at', 'date'];

    public const MAX_ITEMS = ['game' => 30, 'provider' => 12, 'promotion' => 12, 'event' => 12, 'notice' => 12, 'news' => 12];

    /** @var array<int, string> what happened, for the task log */
    public array $notes = [];

    public function __construct(protected AiManager $ai, protected ReferenceImageImporter $images) {}

    /**
     * The reference as one text for the model: each page's text, then the
     * image tiles (name | image | link) found on all pages.
     *
     * @param  array<int, array>  $pages  home first, then the crawled inner pages
     */
    public static function digest(array $pages, int $limit = 24_000): string
    {
        if ($pages === []) {
            return 'none';
        }
        $out = '';
        $share = (int) max(1500, ($limit * 0.75) / count($pages));
        foreach ($pages as $page) {
            $text = trim((string) ($page['content'] ?? ''));
            if ($text === '') {
                continue;
            }
            $out .= '### '.trim(($page['label'] ?? '').' – '.($page['title'] ?? ''), ' –').' ('.$page['url'].")\n".Str::limit($text, $share)."\n\n";
        }
        $tiles = collect($pages)->flatMap(fn ($p) => $p['cards'] ?? [])->unique(fn ($c) => mb_strtolower($c['name']))->take(150);
        if ($tiles->isNotEmpty()) {
            $out .= "### TILES\n".$tiles->map(fn ($c) => $c['name'].' | '.($c['image'] ?? '').' | '.($c['url'] ?? ''))->implode("\n");
        }

        return Str::limit(trim($out), $limit) ?: 'none';
    }

    /**
     * @param  array<int, array>  $pages  reference pages (home first) or []
     * @return int entries saved
     */
    public function generate(Site $site, string $facts, array $pages, string $features): int
    {
        $this->notes = [];
        $type = $site->typeConfig();
        $collections = array_values(array_filter(SiteStructure::collections($type), fn ($c) => SiteStructure::owner($type, $c) !== null));
        if ($collections === []) {
            return 0;
        }

        try {
            $res = $this->ai->prompt(PromptType::SiteData, [
                'brand' => $site->brandProfile?->toPromptContext() ?? "Product: {$site->product_name}",
                'reference_url' => $site->reference_url ?: 'none',
                'reference' => self::digest($pages),
                'collections' => implode(', ', $collections),
                'max_items' => max(self::MAX_ITEMS),
                'site_features' => $features,
                'game_types' => self::vocabulary('game_types'),
                'game_themes' => self::vocabulary('game_themes'),
            ], $site)->json();
        } catch (Throwable $e) {
            $this->notes[] = '站点数据生成失败：'.Str::limit($e->getMessage(), 150);

            return 0;
        }

        $tiles = collect($pages)->flatMap(fn ($p) => $p['cards'] ?? [])->values();
        $known = $tiles->pluck('image')->merge(collect($pages)->flatMap(fn ($p) => array_column($p['images'] ?? [], 'url')))->filter()->flip();
        $saved = collect();
        foreach ($collections as $collection) {
            $kept = $this->saveCollection($site, $collection, is_array($res[$collection] ?? null) ? $res[$collection] : [], $facts, $tiles, $known);
            $saved = $saved->merge($kept);
            $this->notes[] = ($type['collections'][$collection]['label'] ?? $collection).'：'.$kept->count().' 条';
        }

        $this->writeSections($site, $saved->filter(fn (Entry $e) => $e->source !== 'manual')->values(), $facts, $pages, $features);

        return $saved->count();
    }

    /** @return Collection<int, Entry> */
    protected function saveCollection(Site $site, string $collection, array $items, string $facts, Collection $tiles, Collection $known): Collection
    {
        $factsLower = mb_strtolower($facts);
        $kept = collect();
        $slugs = [];
        foreach (array_slice(array_filter($items, 'is_array'), 0, self::MAX_ITEMS[$collection]) as $i => $item) {
            $title = Str::limit(trim(strip_tags((string) ($item['title'] ?? ''))), 120, '');
            $slug = Str::slug((string) ($item['slug'] ?? '')) ?: Str::slug($title);
            if ($title === '' || $slug === '' || isset($slugs[$slug])) {
                continue;
            }
            $slugs[$slug] = true;
            $slug = Str::limit($slug, 100, '');

            $existing = $site->entries()->where('collection', $collection)->where('slug', $slug)->first();
            if ($existing?->source === 'manual') {
                $kept->push($existing);

                continue;
            }

            $entry = $site->entries()->updateOrCreate(['collection' => $collection, 'slug' => $slug], [
                'title' => $title,
                'summary' => Str::limit(SeoRules::stripClaims(trim(strip_tags((string) ($item['summary'] ?? ''))), $facts), 300, '') ?: null,
                'data' => $this->cleanData($collection, $item, $factsLower),
                'image' => $this->image($site, $title, (string) ($item['image'] ?? ''), $tiles, $known) ?? $existing?->image,
                'sort' => $i,
                'source' => 'ai',
            ]);
            $kept->push($entry);
        }

        // Items the new data no longer has: removed (a launched site answers 410 for their pages).
        $stale = $site->entries()->where('collection', $collection)->where('source', 'ai')->whereNotIn('id', $kept->pluck('id'))->get();
        foreach ($stale as $old) {
            if ($site->launched_at && ($owner = SiteStructure::owner($site->typeConfig(), $collection)) && ($page = $site->pages()->where('type', $owner)->first())) {
                GoneUrl::firstOrCreate(['host' => $site->domain->name, 'path' => trim($page->slug.'/'.$old->slug, '/')]);
            }
            $old->delete();
        }

        return $kept;
    }

    public function cleanData(string $collection, array $item, string $factsLower): array
    {
        $data = [];
        foreach (self::FIELDS[$collection] ?? [] as $field) {
            $value = $item[$field] ?? null;
            if ($field === 'demo') {
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    $data['demo'] = true;
                }

                continue;
            }
            if (in_array($field, ['tags', 'themes', 'mechanics', 'types', 'theme_keys', 'devices'], true)) {
                $list = collect(is_array($value) ? $value : explode(',', (string) $value))
                    ->filter(fn ($v) => is_string($v))->map(fn ($v) => Str::limit(trim(strip_tags($v)), 30, ''))->filter()->unique();
                // Category keys come from fixed vocabularies so lists and filters can match them.
                $allowed = match ($field) {
                    'tags' => self::TAGS[$collection] ?? [],
                    'types' => array_keys((array) config('game_site.game_types')),
                    'theme_keys' => array_keys((array) config('game_site.game_themes')),
                    'devices' => ['mobile', 'desktop', 'tablet'],
                    default => null,
                };
                if ($allowed !== null) {
                    $list = $list->map(fn ($v) => Str::snake(mb_strtolower($v)))->intersect($allowed);
                }
                if ($list->isNotEmpty()) {
                    $data[$field] = $list->take(6)->values()->all();
                }

                continue;
            }
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }
            $value = Str::limit(trim(strip_tags((string) $value)), 80, '');
            if (in_array($field, self::DATES, true)) {
                $date = rescue(fn () => Carbon::parse($value), null, false);
                if ($date && $date->year >= 1990 && $date->year <= now()->year + 2) {
                    $data[$field] = $date->toDateString();
                }

                continue;
            }
            if ($field === 'status') {
                if (in_array($value, ['upcoming', 'ongoing', 'ended'], true)) {
                    $data[$field] = $value;
                }

                continue;
            }
            // A figure is kept only when the sources state each of its numbers.
            if (in_array($field, self::FIGURES, true) && preg_match_all('/\d+(?:[.,]\d+)?/', $value, $m)
                && collect($m[0])->contains(fn ($n) => ! str_contains($factsLower, $n) && ! str_contains($factsLower, strtr($n, '.,', ',.')))) {
                continue;
            }
            $data[$field] = $value;
        }
        if (! empty($item['featured']) && $collection === 'promotion') {
            $data['featured'] = true;
        }

        return $data;
    }

    /** The item's image: the URL the model matched, else a tile with the same name; only URLs read from the reference. */
    protected function image(Site $site, string $title, string $url, Collection $tiles, Collection $known): ?string
    {
        if (! $site->reference_import_images) {
            return null;
        }
        if ($url === '' || ! $known->has($url)) {
            $name = SeoRules::normalize($title);
            $url = (string) ($tiles->first(fn ($t) => SeoRules::normalize($t['name']) === $name)['image'] ?? '');
        }

        return $url !== '' ? rescue(fn () => $this->images->store($site, $url), null, false) : null;
    }

    /** Detail copy, six items per call, with the reference text that mentions each item. */
    protected function writeSections(Site $site, Collection $entries, string $facts, array $pages, string $features): void
    {
        $definitions = $site->typeConfig()['collections'] ?? [];
        $written = 0;
        foreach ($entries->chunk(6) as $batch) {
            $items = [];
            $reference = '';
            foreach ($batch as $entry) {
                $items[$entry->slug] = array_filter([
                    'collection' => $entry->collection,
                    'title' => $entry->title,
                    'summary' => $entry->summary,
                    'data' => $entry->data ?: null,
                    'sections' => collect($definitions[$entry->collection]['sections'] ?? [])->mapWithKeys(fn ($s) => [$s['key'] => $s['topic']])->all(),
                ]);
                $reference .= self::mentions($pages, $entry->title);
            }
            try {
                $res = $this->ai->prompt(PromptType::EntryContent, [
                    'brand' => $site->brandProfile?->toPromptContext() ?? "Product: {$site->product_name}",
                    'reference' => Str::limit($reference, 8000) ?: 'none',
                    'items' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'site_features' => $features,
                ], $site)->json()['items'] ?? [];
            } catch (Throwable) {
                continue;
            }
            foreach ($batch as $entry) {
                $sections = [];
                foreach ($definitions[$entry->collection]['sections'] ?? [] as $def) {
                    $section = $res[$entry->slug][$def['key']] ?? null;
                    if (! is_array($section) || ! is_string($section['html'] ?? null)) {
                        continue;
                    }
                    $html = SeoRules::stripClaimsHtml(HtmlSanitizer::clean($section['html']), $facts);
                    if (trim(strip_tags($html)) !== '') {
                        $sections[$def['key']] = ['heading' => Str::limit(trim(strip_tags((string) ($section['heading'] ?? ''))), 120, '') ?: null, 'html' => $html];
                    }
                }
                if ($sections !== []) {
                    $entry->update(['sections' => $sections]);
                    $written++;
                }
            }
        }
        $this->notes[] = "详情页文案：{$written} 条";
    }

    /** "key = label" list of a game vocabulary for the prompt. */
    public static function vocabulary(string $name): string
    {
        return collect((array) config("game_site.{$name}"))->map(fn ($label, $key) => "{$key} = {$label}")->implode('; ');
    }

    /** Lines of the reference pages around mentions of a name. */
    public static function mentions(array $pages, string $name): string
    {
        $out = '';
        foreach ($pages as $page) {
            $text = (string) ($page['content'] ?? '');
            $pos = mb_stripos($text, $name);
            if ($pos !== false) {
                $out .= '['.$page['url'].'] '.mb_substr($text, max(0, $pos - 200), 1200)."\n";
            }
        }

        return $out;
    }
}
