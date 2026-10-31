<?php

namespace App\Services\Theme;

/**
 * Validates an AI-produced design config against the theme whitelist and
 * repairs what can be repaired. Returns the repaired config plus a list of
 * issues; callers regenerate when issues are not repairable.
 */
class DesignConfigValidator
{
    public const COLOR_KEYS = ['primary', 'secondary', 'accent', 'background', 'surface', 'text', 'muted'];

    /** Page types every site gets regardless of site type. */
    public const SYSTEM_PAGES = [
        'blog' => ['article_list'],
        'article' => [],
        'not_found' => ['cta'],
    ];

    /** @var array<int, string> */
    public array $issues = [];

    /** @var array<int, string> issues that were auto-repaired */
    public array $repaired = [];

    public function validate(array $config, array $siteTypeConfig): array
    {
        $this->issues = [];
        $this->repaired = [];
        $theme = config('theme');

        // Colours
        $defaults = ['primary' => '#2563eb', 'secondary' => '#0f172a', 'accent' => '#f59e0b', 'background' => '#ffffff', 'surface' => '#f8fafc', 'text' => '#111827', 'muted' => '#4b5563'];
        $colors = [];
        foreach (self::COLOR_KEYS as $key) {
            $value = $config['colors'][$key] ?? null;
            if (! Color::isHex($value)) {
                $this->repaired[] = "colors.{$key} invalid, using default";
                $value = $defaults[$key];
            }
            $colors[$key] = Color::normalize($value);
        }
        $min = (float) $theme['min_contrast'];
        foreach (['text' => 'background', 'muted' => 'background'] as $fg => $bg) {
            if (Color::contrast($colors[$fg], $colors[$bg]) < $min) {
                $colors[$fg] = Color::ensureContrast($colors[$fg], $colors[$bg], $min);
                $this->repaired[] = "colors.{$fg} contrast on {$bg} < {$min}, adjusted";
            }
        }
        if (Color::contrast($colors['text'], $colors['surface']) < $min) {
            $colors['surface'] = Color::mix($colors['background'], $colors['text'], 0.04);
            $this->repaired[] = 'colors.surface contrast too low, adjusted';
        }
        $colors['on_primary'] = Color::readableOn($colors['primary']);
        if (Color::contrast($colors['on_primary'], $colors['primary']) < $min) {
            $colors['primary'] = Color::ensureContrast($colors['primary'], $colors['on_primary'], $min);
            $this->repaired[] = 'colors.primary too light for button text, darkened';
        }
        $colors['on_secondary'] = Color::readableOn($colors['secondary']);
        $colors['link'] = Color::ensureContrast($colors['primary'], $colors['background'], $min);

        // Fonts: whitelist, at most two families
        $fonts = [];
        foreach (['heading', 'body'] as $role) {
            $font = $config['fonts'][$role] ?? 'system';
            $font = $theme['font_aliases'][$font] ?? $font;
            if (! isset($theme['fonts'][$font])) {
                $this->repaired[] = "fonts.{$role} [{$font}] not in whitelist, using system";
                $font = 'system';
            }
            $fonts[$role] = $font;
        }

        $pick = function (string $key, array $allowed, string $fallback) use ($config) {
            $value = $config[$key] ?? $fallback;
            if (! array_key_exists($value, $allowed)) {
                $this->repaired[] = "{$key} [{$value}] invalid, using {$fallback}";

                return $fallback;
            }

            return $value;
        };

        $style = [];
        foreach ($theme['styles'] as $key => $allowed) {
            $value = $config['style'][$key] ?? null;
            if ($value !== null && ! in_array($value, $allowed, true)) {
                $this->repaired[] = "style.{$key} [{$value}] invalid, using {$allowed[0]}";
            }
            $style[$key] = in_array($value, $allowed, true) ? $value : $allowed[0];
        }

        // Extra pages mirror reference menu sections the site type has no page for.
        $extraPages = ExtraPages::clean($config['extra_pages'] ?? []);
        $siteTypeConfig = ExtraPages::merge($siteTypeConfig, $extraPages);

        $result = [
            'colors' => $colors,
            'fonts' => $fonts,
            'radius' => $pick('radius', $theme['radius'], 'md'),
            'density' => $pick('density', $theme['density'], 'normal'),
            'shadow' => $pick('shadow', $theme['shadow'], 'soft'),
            'style' => $style,
            'nav' => $this->variant('nav', $config['nav'] ?? null),
            'footer' => $this->variant('footer', $config['footer'] ?? null),
            'menu' => $this->menu($config['menu'] ?? [], $siteTypeConfig),
            'extra_pages' => $extraPages,
            'pages' => [],
            // The site's own look picks (SiteStyle), kept across regenerations.
            'look' => array_filter((array) ($config['look'] ?? []), 'is_scalar'),
        ];
        if (! empty($siteTypeConfig['sectioned'])) {
            // Pool presets decide their menu: main pages in the header, the rest under their parent or in the footer.
            $result['menu'] = $this->sectionedMenu($siteTypeConfig);
            $noDropdown = is_array($config['navigation'] ?? null) && ($config['navigation']['submenu'] ?? null) === [];
            $config['navigation'] = (array) ($config['navigation'] ?? []);
            $config['navigation']['submenu'] = $noDropdown ? [] : $this->sectionedSubmenu($siteTypeConfig, $result['menu']);
        }
        $result['navigation'] = $this->navigation($config['navigation'] ?? [], $result['menu'], $siteTypeConfig, $result['nav'], crc32(json_encode($colors).implode(',', $result['menu'])));

        // Page compositions
        $pageTypes = array_map(fn ($p) => $p['blocks'], $siteTypeConfig['pages']) + self::SYSTEM_PAGES;
        foreach ($pageTypes as $pageType => $defaultBlocks) {
            if (isset($siteTypeConfig['pages'][$pageType]['sections'])) {
                // Sectioned pages: one block per chosen section, in order; the model only picks variants.
                $variants = collect((array) ($config['pages'][$pageType] ?? []))->filter(fn ($e) => is_array($e) && isset($e['block']))->mapWithKeys(fn ($e) => [$e['block'] => $e['variant'] ?? null]);
                $result['pages'][$pageType] = array_map(fn ($section) => [
                    'block' => $section['block'],
                    // The design's choice (it follows the reference) first, else the site's own random look.
                    'variant' => $this->variant($section['block'], $variants[$section['block']] ?? $section['variant'] ?? null),
                    'key' => $section['key'],
                ], $siteTypeConfig['pages'][$pageType]['sections']);

                continue;
            }
            $blocks = [];
            foreach ((array) ($config['pages'][$pageType] ?? []) as $entry) {
                $block = is_array($entry) ? ($entry['block'] ?? null) : $entry;
                if (! isset($theme['blocks'][$block]) || in_array($block, $theme['layout_blocks'], true)) {
                    $this->repaired[] = "pages.{$pageType}: unknown block [{$block}] removed";

                    continue;
                }
                $blocks[] = ['block' => $block, 'variant' => $this->variant($block, is_array($entry) ? ($entry['variant'] ?? null) : null)];
            }
            // Required blocks for the page's purpose must survive the AI's choices.
            foreach ($defaultBlocks as $required) {
                if (in_array($required, ['prose', 'contact', 'legal', 'article_list'], true) && ! collect($blocks)->contains('block', $required)) {
                    $blocks[] = ['block' => $required, 'variant' => $this->variant($required, null)];
                    $this->repaired[] = "pages.{$pageType}: required block [{$required}] added";
                }
            }
            if ($blocks === [] && $defaultBlocks !== []) {
                $blocks = array_map(fn ($b) => ['block' => $b, 'variant' => $this->variant($b, null)], $defaultBlocks);
                $this->repaired[] = "pages.{$pageType}: missing, default composition used";
            }
            $result['pages'][$pageType] = $blocks;
        }

        return $result;
    }

    /** Main-nav pages of a pool preset, in pool order, capped. */
    protected function sectionedMenu(array $siteTypeConfig): array
    {
        // A positioning gives the menu in its own order (News included).
        if (isset($siteTypeConfig['menu'])) {
            return array_values(array_filter($siteTypeConfig['menu'], fn ($t) => $t === 'blog' || (($siteTypeConfig['pages'][$t]['nav'] ?? null) === 'main')));
        }
        $main = array_keys(array_filter($siteTypeConfig['pages'], fn ($p) => ($p['nav'] ?? null) === 'main'));
        // Preferred order: what players look for first; the rest keep pool order.
        $order = array_flip($siteTypeConfig['main_nav_order'] ?? []);
        usort($main, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        $max = (int) ($siteTypeConfig['main_nav_max'] ?? 6);
        // "help" (the parent of the secondary pages) is kept as the last item.
        $help = in_array('help', $main, true) ? ['help'] : [];

        return array_values(array_merge(array_slice(array_diff($main, $help), 0, $max - count($help)), $help));
    }

    protected function sectionedSubmenu(array $siteTypeConfig, array $menu): array
    {
        $submenu = [];
        foreach ($siteTypeConfig['pages'] as $type => $page) {
            if (($page['nav'] ?? null) === 'secondary' && in_array($page['parent'] ?? null, $menu, true)) {
                $submenu[$page['parent']][] = $type;
            }
        }

        return $submenu;
    }

    /** Header menu: known page types (plus "blog") in the given order, no duplicates. */
    protected function menu(mixed $menu, array $siteTypeConfig): array
    {
        $allowed = array_merge(array_diff(array_keys($siteTypeConfig['pages']), ['home', 'privacy', 'terms']), ['blog']);

        return array_values(array_unique(array_filter((array) $menu, fn ($type) => is_string($type) && in_array($type, $allowed, true))));
    }

    /**
     * Header behaviour: dropdown children per top-level menu item, number of
     * CTA buttons, mobile bottom bar, sticky header, leading home link.
     */
    /** Phone header, menu and bottom bar looks; sites without a choice get a stable mix of them. */
    public const MOBILE = [
        'mobile_header' => ['standard', 'center', 'cta', 'tabs'],
        'mobile_menu' => ['dropdown', 'drawer', 'drawer-left', 'fullscreen', 'sheet'],
        'mobile_bar_style' => ['raised', 'flat', 'pill', 'cta', 'icons', 'labels'],
        // Slots of the phone bottom bar, home included; "none" shows no bar (the header menu does the work).
        'mobile_bar_items' => ['5', '4', '3', 'none'],
        // How the bar's icons are painted.
        'mobile_bar_icons' => ['line', 'bold', 'duo', 'chip', 'dot'],
    ];

    protected function navigation(mixed $nav, array $menu, array $siteTypeConfig, string $variant, int $seed = 0): array
    {
        $nav = is_array($nav) ? $nav : [];
        $allowed = $this->menu(array_keys($siteTypeConfig['pages'] + ['blog' => 1]), $siteTypeConfig);
        $used = $menu;
        $submenu = [];
        foreach ((array) ($nav['submenu'] ?? []) as $parent => $children) {
            if (! in_array($parent, $menu, true)) {
                continue;
            }
            foreach ((array) $children as $child) {
                if (is_string($child) && in_array($child, $allowed, true) && ! in_array($child, $used, true)) {
                    $submenu[$parent][] = $child;
                    $used[] = $child;
                }
            }
        }

        return [
            'submenu' => $submenu,
            'buttons' => max(0, min(2, (int) ($nav['buttons'] ?? 1))),
            'mobile_bar' => (bool) ($nav['mobile_bar'] ?? false) || ! empty($siteTypeConfig['mobile_bar']),
            'sticky' => (bool) ($nav['sticky'] ?? true),
            'home_link' => (bool) ($nav['home_link'] ?? Variants::base('nav', $variant) === 'portal'),
        ] + collect(self::MOBILE)->map(function (array $allowed, string $key) use ($nav, $seed) {
            $value = $nav[$key] ?? null;

            return in_array($value, $allowed, true) ? $value : $allowed[intdiv($seed, 7 ** array_search($key, array_keys(self::MOBILE))) % count($allowed)];
        })->all();
    }

    protected function variant(string $block, ?string $variant): string
    {
        $allowed = config("theme.blocks.{$block}", ['default']);
        if ($variant !== null && in_array($variant, $allowed, true)) {
            return $variant;
        }
        if ($variant !== null) {
            $this->repaired[] = "{$block}: variant [{$variant}] does not exist";
        }

        return $allowed[0];
    }
}
