<?php

namespace App\Services\Theme;

/**
 * The site's own look: header and footer type, phone header / menu / bar,
 * fonts, corners, shadows, card / button / heading style and every block's
 * variant, picked from the whitelist by a seed that belongs to the site.
 * The reference and the AI decide colours, the menu, the header behaviour
 * and which sections the home page has; the look is never theirs, so sites
 * built from the same reference (or the same "safe" AI taste) still differ,
 * and a site keeps its look when it is generated again.
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

    public function __construct(protected string $seed) {}

    public static function for(int|string $seed): self
    {
        return new self((string) $seed);
    }

    /** One stable pick per key: the same site always gets the same value, other sites spread over all of them. */
    public function pick(string $key, array $allowed): mixed
    {
        $allowed = array_values($allowed);

        return $allowed[crc32($this->seed.'|'.$key) % count($allowed)];
    }

    public function apply(array $config): array
    {
        $theme = config('theme');
        $fonts = array_values(array_filter(self::FONT_PAIRS, fn ($p) => isset($theme['fonts'][$p[0]], $theme['fonts'][$p[1]])));
        [$heading, $body] = $this->pick('fonts', $fonts ?: [['system', 'system']]);

        $config['fonts'] = ['heading' => $heading, 'body' => $body];
        // "minimal" has no header buttons: only for sites that show none.
        $buttons = (int) ($config['navigation']['buttons'] ?? 1) > 0;
        $config['nav'] = $this->pick('nav', array_diff($theme['blocks']['nav'], $buttons ? ['minimal'] : []));
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
                    $config['pages'][$type][$i] = ['block' => $block, 'variant' => $this->pick("{$type}.{$block}.{$i}", $allowed)] + (is_array($entry) ? $entry : []);
                }
            }
        }

        return $config;
    }
}
