<?php

namespace App\Services\Sites;

use App\Models\Site;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Which pages and sections of a pool preset (config/game_site.php) a site
 * gets. The choice is seeded by the site, so it is stable between renders,
 * stored on the site at generation, and never drops a page a launched site
 * already has (its URL would 404).
 */
class SiteStructure
{
    /** Conditions a page or section can require, and whether the site meets them. */
    public static function conditions(Site $site): array
    {
        $brand = $site->brandProfile;

        return [
            'app' => collect($brand?->cta_links ?? [])->contains(fn ($l) => filled($l['url'] ?? null)),
            'company' => filled($brand?->company_name),
            'license' => filled($brand?->license_info),
            'certification' => filled($brand?->certification_info),
        ];
    }

    /**
     * A new round (regeneration) draws a new combination: only the pages a
     * launched site already has are kept, everything else is chosen again.
     *
     * @return array{pages: array<string, array<int, string>>} page type => chosen section keys, in pool order
     */
    public static function choose(Site $site, array $type, ?array $previous = null, int $round = 0): array
    {
        $rand = new Randomizer(new Mt19937(crc32('structure|'.($site->domain_id ?? $site->id).'|'.$site->id.($round ? "|{$round}" : ''))));
        $met = self::conditions($site);
        $ok = fn (array $def) => empty($def['requires']) || ($met[$def['requires']] ?? false);
        $keep = array_keys($previous['pages'] ?? []);
        if ($site->exists && $site->launched_at) {
            $keep = array_merge($keep, $site->pages()->pluck('type')->all());
        }

        $pages = [];
        foreach ($type['pages'] as $pageType => $def) {
            if (! $ok($def)) {
                continue;
            }
            // Every candidate draws its number, so keeping a page never shifts the other choices.
            $roll = $rand->nextFloat();
            $wanted = ! empty($def['required']) || in_array($pageType, $keep, true)
                || $roll < (float) ($type['optional_page_chance'] ?? 0.65);
            if (! $wanted) {
                continue;
            }
            $sections = [];
            foreach ($def['sections'] as $section) {
                if (! $ok($section)) {
                    continue;
                }
                $roll = $rand->nextFloat();
                if (! empty($section['required']) || in_array($section['key'], $previous['pages'][$pageType] ?? [], true)
                    || $roll < (float) ($type['optional_section_chance'] ?? 0.6)) {
                    $sections[] = $section['key'];
                }
            }
            // At least two sections so no page is a lone list.
            foreach ($def['sections'] as $section) {
                if (count($sections) >= min(2, count($def['sections'])) || ! $ok($section)) {
                    break;
                }
                if (! in_array($section['key'], $sections, true)) {
                    $sections[] = $section['key'];
                }
            }
            // Long pools are capped: optional sections beyond the limit are dropped at random.
            $max = (int) ($def['max_sections'] ?? $type['max_sections'] ?? 99);
            $fixed = array_column(array_filter($def['sections'], fn ($x) => ! empty($x['required'])), 'key');
            $kept = $previous['pages'][$pageType] ?? [];
            while (count($sections) > $max) {
                $optional = array_values(array_diff($sections, $fixed, $kept));
                if ($optional === []) {
                    break;
                }
                $sections = array_values(array_diff($sections, [$optional[$rand->getInt(0, count($optional) - 1)]]));
            }
            $order = array_column($def['sections'], 'key');
            usort($sections, fn ($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
            $pages[$pageType] = $sections;
        }

        // Detail pages (games, promotions…) combine their sections the same way.
        $collections = [];
        foreach ($type['collections'] ?? [] as $name => $collection) {
            $chosen = [];
            foreach ($collection['sections'] as $section) {
                if (! $ok($section)) {
                    continue;
                }
                $roll = $rand->nextFloat();
                if (! empty($section['required']) || in_array($section['key'], $previous['collections'][$name] ?? [], true)
                    || $roll < (float) ($type['optional_section_chance'] ?? 0.6)) {
                    $chosen[] = $section['key'];
                }
            }
            $collections[$name] = $chosen;
        }

        // Each block type gets one look per site (used when the design does not pick one).
        $variants = [];
        foreach ($pages as $pageType => $keys) {
            foreach ($type['pages'][$pageType]['sections'] as $section) {
                $options = config("theme.blocks.{$section['block']}", ['default']);
                if (in_array($section['key'], $keys, true) && ! isset($variants[$section['block']])) {
                    $variants[$section['block']] = $previous['variants'][$section['block']] ?? $options[$rand->getInt(0, count($options) - 1)];
                }
            }
        }

        return ['pages' => $pages, 'collections' => $collections, 'variants' => $variants] + ($round ? ['round' => $round] : []);
    }

    /** The preset reduced to the chosen pages; each page's "blocks" and "sections" follow the choice. */
    public static function apply(array $type, array $structure): array
    {
        $pages = [];
        foreach ($type['pages'] as $pageType => $def) {
            if (! isset($structure['pages'][$pageType])) {
                continue;
            }
            $chosen = $structure['pages'][$pageType];
            $def['sections'] = array_values(array_map(
                fn ($s) => $s + array_filter(['variant' => $structure['variants'][$s['block']] ?? null]),
                array_filter($def['sections'], fn ($s) => in_array($s['key'], $chosen, true)),
            ));
            $def['blocks'] = array_column($def['sections'], 'block');
            $pages[$pageType] = $def;
        }
        $type['pages'] = $pages;
        foreach ($type['collections'] ?? [] as $name => $collection) {
            if (isset($structure['collections'][$name])) {
                $chosen = $structure['collections'][$name];
                $type['collections'][$name]['sections'] = array_values(array_filter($collection['sections'], fn ($s) => in_array($s['key'], $chosen, true)));
            }
        }

        return $type;
    }

    /** Page type that hosts a collection's detail pages on this site, if any. */
    public static function owner(array $typeConfig, string $collection): ?string
    {
        foreach ($typeConfig['collections'][$collection]['owners'] ?? [] as $pageType) {
            if (isset($typeConfig['pages'][$pageType])) {
                return $pageType;
            }
        }

        return null;
    }

    /** Collections the site's chosen sections show. */
    public static function collections(array $typeConfig): array
    {
        $used = [];
        foreach ($typeConfig['pages'] as $def) {
            foreach ($def['sections'] ?? [] as $section) {
                if (! empty($section['collection'])) {
                    $used[$section['collection']] = true;
                }
            }
        }

        return array_keys($used);
    }
}
