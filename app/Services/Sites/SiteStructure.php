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

        // Positioning presets (config "positionings"): the menu, dropdowns and home page follow one
        // positioning; content pages get a few sections each.
        $plan = ! empty($type['positionings']) ? self::plan($type, $rand, $met, $previous, $round) : null;

        $pages = [];
        foreach ($type['pages'] as $pageType => $def) {
            if (! $ok($def)) {
                continue;
            }
            if ($plan !== null) {
                // Every candidate still draws its number, so the section rolls below stay comparable.
                $rand->nextFloat();
                if (! in_array($pageType, $plan['pages'], true) && ! in_array($pageType, $keep, true)) {
                    continue;
                }
                $pages[$pageType] = self::sectionsFor($type, $pageType, $def, $rand, $ok, $previous, $plan);

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

        $nav = $plan === null ? [] : ['positioning' => $plan['positioning'], 'nav' => [
            'menu' => array_values(array_filter($plan['menu'], fn ($t) => $t === 'blog' || isset($pages[$t]))),
            'parents' => array_filter($plan['parents'], fn ($parent, $child) => isset($pages[$child], $pages[$parent]), ARRAY_FILTER_USE_BOTH),
        ]];

        return ['pages' => $pages, 'collections' => $collections, 'variants' => $variants] + $nav + ($round ? ['round' => $round] : []);
    }

    /**
     * The site's positioning and the pages it brings: home, the menu and its
     * dropdowns, the fixed footer pages and a few optional ones. A
     * regeneration draws a positioning other than the last one.
     *
     * @return array{positioning: string, menu: array<int, string>, parents: array<string, string>, pages: array<int, string>, home: array<int, string>}
     */
    public static function plan(array $type, Randomizer $rand, array $met, ?array $previous, int $round): array
    {
        $names = array_keys($type['positionings']);
        $last = $previous['positioning'] ?? null;
        $choices = $round && $last && count($names) > 1 ? array_values(array_diff($names, [$last])) : $names;
        $name = $choices[$rand->getInt(0, count($choices) - 1)];
        // A retry (the stored structure is passed whole) keeps its positioning; the draw above still runs so the rest stays the same.
        if ($last && isset($previous['pages'], $type['positionings'][$last])) {
            $name = $last;
        }
        $def = $type['positionings'][$name];
        $ok = fn (string $page) => isset($type['pages'][$page]) && (empty($type['pages'][$page]['requires']) || ($met[$type['pages'][$page]['requires']] ?? false));

        $menu = array_values(array_filter($def['menu'], fn ($t) => $t === 'blog' || $ok($t)));
        $app = $type['app_page'] ?? null;
        if ($app && $ok($app) && ! in_array($app, $menu, true) && count($menu) < (int) ($type['main_nav_max'] ?? 8) - 1) {
            $at = array_search('help', $menu, true);
            array_splice($menu, $at === false ? count($menu) : $at, 0, [$app]);
        }
        $parents = [];
        foreach ($def['children'] ?? [] as $parent => $children) {
            foreach ($children as $child) {
                if (in_array($parent, $menu, true) && $ok($child) && ! in_array($child, $menu, true) && ! isset($parents[$child])) {
                    $parents[$child] = $parent;
                }
            }
        }
        $optional = array_values(array_filter($type['footer_optional'] ?? [], $ok));
        [$min, $max] = $type['footer_optional_count'] ?? [0, 0];
        $count = min(count($optional), $rand->getInt((int) $min, (int) $max));
        $extra = $count > 0 ? array_slice($rand->shuffleArray($optional), 0, $count) : [];
        // Promotion terms belong to sites that list promotions.
        $terms = in_array('promotions', $menu, true) || isset($parents['promotions']) ? ['bonus_terms'] : [];
        $pages = array_values(array_unique(array_filter(array_merge(['home'], $menu, array_keys($parents), $type['footer_pages'] ?? [], $extra, $terms), $ok)));

        return ['positioning' => $name, 'menu' => $menu, 'parents' => $parents, 'pages' => $pages, 'home' => $def['home'] ?? []];
    }

    /** Sections of one page under a positioning: home in the positioning's order, other pages a few each. */
    protected static function sectionsFor(array $type, string $pageType, array $def, Randomizer $rand, callable $ok, ?array $previous, array $plan): array
    {
        $available = array_values(array_filter($def['sections'], $ok));
        if ($pageType === 'home') {
            $keys = array_column($available, 'key');

            return array_values(array_filter($plan['home'], fn ($k) => in_array($k, $keys, true)));
        }
        $rolls = [];
        foreach ($available as $section) {
            $rolls[$section['key']] = $rand->nextFloat();
        }
        [$min, $max] = $type['page_sections'] ?? [2, 99];
        $max = (int) ($def['max_sections'] ?? $max);
        $kept = $previous['pages'][$pageType] ?? [];
        $fixed = array_column(array_filter($available, fn ($x) => ! empty($x['required'])), 'key');
        // Optional sections in the order of their draw (lowest first), up to the page's size.
        $optional = array_values(array_diff(array_column($available, 'key'), $fixed));
        usort($optional, fn ($a, $b) => (in_array($b, $kept, true) <=> in_array($a, $kept, true)) ?: ($rolls[$a] <=> $rolls[$b]));
        $size = max(min((int) $min, count($available)), min($max, count($fixed) + (int) round(count($optional) * (float) ($type['optional_section_chance'] ?? 0.55))));
        $chosen = array_slice(array_merge($fixed, $optional), 0, max($size, count($fixed)));
        $order = array_column($def['sections'], 'key');
        usort($chosen, fn ($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));

        return $chosen;
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
        // The positioning's menu: its pages in the header, their dropdown pages under them, every
        // other page in the footer (legal pages stay legal).
        if (isset($structure['nav']['menu'])) {
            $menu = $structure['nav']['menu'];
            $parents = (array) ($structure['nav']['parents'] ?? []);
            foreach ($pages as $pageType => $def) {
                if ($pageType === 'home') {
                    continue;
                }
                if (in_array($pageType, $menu, true)) {
                    $pages[$pageType]['nav'] = 'main';
                    unset($pages[$pageType]['parent']);
                } elseif (isset($parents[$pageType])) {
                    $pages[$pageType]['nav'] = 'secondary';
                    $pages[$pageType]['parent'] = $parents[$pageType];
                } elseif (($def['nav'] ?? null) !== 'legal') {
                    $pages[$pageType]['nav'] = 'footer';
                    unset($pages[$pageType]['parent']);
                }
            }
            $type['menu'] = $menu;
            $type['positioning'] = $structure['positioning'] ?? null;
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
