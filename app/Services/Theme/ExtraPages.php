<?php

namespace App\Services\Theme;

use Illuminate\Support\Str;

/**
 * Site-specific pages beyond the site-type preset, taken from the reference
 * site's menu (e.g. "Eventos", "Ranking"). Stored in the design config as
 * [{key, label, slug}] and merged into the site type config as page types
 * extra_1 … extra_N, so generation, menu, sitemap and rendering treat them
 * like any core page.
 */
class ExtraPages
{
    public const MAX = 5;

    public const BLOCKS = ['hero', 'prose', 'card_grid', 'cta'];

    /** @return array<int, array{key: string, label: string, slug: string}> */
    public static function clean(mixed $pages): array
    {
        $out = [];
        $slugs = [];
        foreach ((array) $pages as $page) {
            $label = is_array($page) && is_string($page['label'] ?? null) ? trim(strip_tags($page['label'])) : '';
            if ($label === '' || mb_strlen($label) > 40) {
                continue;
            }
            $slug = Str::slug(is_string($page['slug'] ?? null) && $page['slug'] !== '' ? $page['slug'] : $label);
            if ($slug === '' || in_array($slug, $slugs, true)) {
                continue;
            }
            $slugs[] = $slug;
            $out[] = ['key' => 'extra_'.(count($out) + 1), 'label' => $label, 'slug' => Str::limit($slug, 60, '')];
            if (count($out) >= self::MAX) {
                break;
            }
        }

        return $out;
    }

    /** Add the extra pages to a site type config, before the legal pages. */
    public static function merge(array $typeConfig, array $extraPages): array
    {
        // Drop extras merged earlier (the site's previous design) before adding the new ones.
        $typeConfig['pages'] = array_filter($typeConfig['pages'], fn ($p) => empty($p['extra']));
        if ($extraPages === []) {
            return $typeConfig;
        }
        $legal = array_intersect_key($typeConfig['pages'], array_flip(['privacy', 'terms']));
        $pages = array_diff_key($typeConfig['pages'], $legal);
        foreach ($extraPages as $page) {
            $pages[$page['key']] = ['label' => $page['label'], 'slug' => ['en' => $page['slug']], 'blocks' => self::BLOCKS, 'extra' => true];
        }
        $typeConfig['pages'] = $pages + $legal;

        return $typeConfig;
    }
}
