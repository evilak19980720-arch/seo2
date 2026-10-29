<?php

namespace App\Services\Theme;

/**
 * The site's own look: header and footer type, phone header / menu / bar /
 * hero / facts / footer layout, fonts, corners, shadows, card / button /
 * heading style and every block's variant. The reference and the AI decide
 * colours, the menu, the header behaviour and which sections the home page
 * has; the look is never theirs.
 *
 * Each choice prefers the value the fewest other sites use (ties broken by
 * the site's seed), so sites differ as much as the options allow instead of
 * colliding by chance. The picks are stored in design_config.look; a page
 * redesign keeps them, a new template generation draws a new look.
 */
class SiteStyle
{
    /** Heading + body fonts that belong together. */
    public const FONT_PAIRS = [
        ['space-grotesk', 'inter'], ['montserrat', 'nunito'], ['playfair', 'inter'], ['oswald', 'inter'],
        ['exo', 'dm-sans'], ['sora', 'manrope'], ['outfit', 'plus-jakarta'], ['rubik', 'rubik'],
        ['plus-jakarta', 'plus-jakarta'], ['montserrat', 'inter'], ['roboto-slab', 'inter'], ['manrope', 'manrope'],
        ['dm-sans', 'dm-sans'], ['oswald', 'nunito'], ['sora', 'inter'], ['outfit', 'outfit'],
    ];

    /**
     * @param  array<string, array<string, int>>  $usage  key => value => number of other sites using it
     * @param  array<string, mixed>  $look  the site's earlier picks, kept
     */
    public function __construct(protected string $seed, protected array $usage = [], protected array $look = []) {}

    /**
     * @param  iterable<array>  $others  design configs of the other sites
     */
    public static function for(int|string $seed, iterable $others = [], array $look = []): self
    {
        $usage = [];
        foreach ($others as $config) {
            foreach (self::lookOf((array) $config) as $key => $value) {
                $usage[$key][(string) $value] = ($usage[$key][(string) $value] ?? 0) + 1;
            }
        }

        return new self((string) $seed, $usage, $look);
    }

    /** The identity values of a stored design (its own picks, or what older designs hold). */
    public static function lookOf(array $config): array
    {
        if (! empty($config['look']) && is_array($config['look'])) {
            return array_filter(array_diff_key($config['look'], ['round' => 0]), 'is_scalar');
        }
        $look = array_filter([
            'fonts' => isset($config['fonts']['heading'], $config['fonts']['body']) ? $config['fonts']['heading'].'+'.$config['fonts']['body'] : null,
            'nav' => $config['nav'] ?? null, 'footer' => $config['footer'] ?? null, 'radius' => $config['radius'] ?? null, 'shadow' => $config['shadow'] ?? null,
        ]);
        foreach ((array) ($config['style'] ?? []) as $key => $value) {
            $look["style.{$key}"] = $value;
        }
        foreach (array_keys(DesignConfigValidator::MOBILE) as $key) {
            if (isset($config['navigation'][$key])) {
                $look["navigation.{$key}"] = $config['navigation'][$key];
            }
        }

        return array_filter($look, 'is_scalar');
    }

    /** The kept value, else the least-used one; among equals the site's seed decides, so the pick is stable. */
    public function pick(string $key, array $allowed): mixed
    {
        $allowed = array_values($allowed);
        if (isset($this->look[$key]) && in_array($this->look[$key], $allowed, true)) {
            return $this->look[$key];
        }
        $counts = array_map(fn ($v) => $this->usage[$key][(string) $v] ?? 0, $allowed);
        $least = array_values(array_filter($allowed, fn ($v, $i) => $counts[$i] === min($counts), ARRAY_FILTER_USE_BOTH));
        // md5, not crc32: crc32 of nearby seeds (site 1, 2, 3…) gives the same remainders for many keys.
        $value = $least[hexdec(substr(md5($this->seed.'|'.$key), 0, 8)) % count($least)];

        return $this->look[$key] = $value;
    }

    public function apply(array $config): array
    {
        $theme = config('theme');
        $fonts = array_values(array_filter(self::FONT_PAIRS, fn ($p) => isset($theme['fonts'][$p[0]], $theme['fonts'][$p[1]])));
        [$heading, $body] = explode('+', $this->pick('fonts', array_map(fn ($p) => implode('+', $p), $fonts ?: [['system', 'system']])));

        $config['fonts'] = ['heading' => $heading, 'body' => $body];
        // "minimal" has no header buttons: only for sites that show none.
        $buttons = (int) ($config['navigation']['buttons'] ?? 1) > 0;
        $config['nav'] = $this->pick('nav', array_filter($theme['blocks']['nav'], fn ($v) => ! $buttons || Variants::base('nav', $v) !== 'minimal'));
        $config['footer'] = $this->pick('footer', $theme['blocks']['footer']);
        $config['radius'] = $this->pick('radius', array_keys($theme['radius']));
        $config['shadow'] = $this->pick('shadow', array_keys($theme['shadow']));
        foreach ($theme['styles'] as $key => $allowed) {
            $config['style'][$key] = $this->pick("style.{$key}", $allowed);
        }
        $config['navigation'] = (array) ($config['navigation'] ?? []);
        foreach (DesignConfigValidator::MOBILE as $key => $allowed) {
            $config['navigation'][$key] = $this->pick("navigation.{$key}", $allowed);
        }

        // The page's sections stay as chosen; each one gets the site's own variant.
        foreach ((array) ($config['pages'] ?? []) as $type => $blocks) {
            foreach ((array) $blocks as $i => $entry) {
                $block = is_array($entry) ? ($entry['block'] ?? null) : $entry;
                if (is_string($block) && ($allowed = $theme['blocks'][$block] ?? null)) {
                    $config['pages'][$type][$i] = ['block' => $block, 'variant' => $this->pickVariant("{$type}.{$block}.{$i}", $allowed)] + (is_array($entry) ? $entry : []);
                }
            }
        }
        $config['look'] = $this->look;

        return $config;
    }

    /** Block variants: seeded only (too many to track across sites, and never kept as identity). */
    protected function pickVariant(string $key, array $allowed): string
    {
        $allowed = array_values($allowed);

        return $allowed[hexdec(substr(md5($this->seed.'|'.$key), 0, 8)) % count($allowed)];
    }
}
