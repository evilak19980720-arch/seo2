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
        // One visual system per site: it fixes the skin and the variant families of every block.
        $system = $this->pick('system', array_keys($theme['systems'] ?? ['clean' => []]));
        $this->system = (array) ($theme['systems'][$system] ?? []);
        // The page skeleton: it narrows the header, hero and footer to the layouts it is drawn for. A look kept from
        // before frames existed stays frameless, so redesigning a page never reshapes the site.
        if ($this->look !== [] && ! isset($this->look['frame']) && isset($this->look['nav'])) {
            $this->look['frame'] = 'classic';
        }
        $this->frame = (array) ($theme['frames'][$this->pick('frame', array_keys($theme['frames'] ?? ['classic' => []]))] ?? []);
        // "minimal" has no header buttons: only for sites that show none.
        $buttons = (int) ($config['navigation']['buttons'] ?? 1) > 0;
        $navs = array_values(array_filter($theme['blocks']['nav'], fn ($v) => ! $buttons || Variants::base('nav', $v) !== 'minimal'));
        $config['nav'] = $this->pick('nav', $this->inFrame('nav', $this->inSystem('nav', $navs), $navs));
        $config['footer'] = $this->pick('footer', $this->inFrame('footer', $this->inSystem('footer', $theme['blocks']['footer']), $theme['blocks']['footer']));
        $config['radius'] = $this->pick('radius', array_keys($theme['radius']));
        $config['shadow'] = $this->pick('shadow', array_keys($theme['shadow']));
        foreach ($theme['styles'] as $key => $allowed) {
            $config['style'][$key] = match (true) {
                // A frame is the site's scheme: the skin stays neutral and is not a choice of its own.
                $key === 'skin' && $this->frame !== [] => 'classic',
                $key === 'skin' && in_array($this->system['skin'] ?? null, $allowed, true) => $this->look['style.skin'] = $this->system['skin'],
                default => $this->pick("style.{$key}", $this->inFrame("style.{$key}", $allowed)),
            };
        }
        $config['navigation'] = (array) ($config['navigation'] ?? []);
        foreach (DesignConfigValidator::MOBILE as $key => $allowed) {
            $config['navigation'][$key] = $this->pick("navigation.{$key}", $this->inFrame("navigation.{$key}", $allowed));
        }

        // The page's sections stay as chosen. A block type looks the same on every page of the
        // site (a game list on the home page and on the games page match), in the site's system;
        // a page keeps at most one block on the dark stage colour, the rest use their base layout.
        $maxStage = (int) ($theme['max_stage_blocks'] ?? 1);
        foreach ((array) ($config['pages'] ?? []) as $type => $blocks) {
            $stage = 0;
            foreach ((array) $blocks as $i => $entry) {
                $block = is_array($entry) ? ($entry['block'] ?? null) : $entry;
                if (is_string($block) && ($allowed = $theme['blocks'][$block] ?? null)) {
                    $variant = $this->pickVariant("block.{$block}", $this->inFrame($block, $this->inSystem($block, $allowed), $allowed));
                    if (! empty(Variants::definition($block, $variant)['stage']) && ++$stage > $maxStage) {
                        $variant = Variants::base($block, $variant);
                    }
                    $config['pages'][$type][$i] = ['block' => $block, 'variant' => $variant] + (is_array($entry) ? $entry : []);
                }
            }
        }
        $config['look'] = $this->frame !== [] ? array_diff_key($this->look, ['style.skin' => 1]) : $this->look;

        return $config;
    }

    /** @var array{skin?: string, families?: array<int, string>} the site's visual system */
    protected array $system = [];

    /** @var array<string, array<int, string>> the site's page skeleton: block => layouts it allows */
    protected array $frame = [];

    /** The layouts the frame allows among the system's ($own), else among all the block may take ($all), else $own. */
    protected function inFrame(string $block, array $own, ?array $all = null): array
    {
        $frame = $this->frame[$block] ?? [];

        return array_values(array_intersect($own, $frame)) ?: array_values(array_intersect($all ?? $own, $frame)) ?: array_values($own);
    }

    /**
     * The variants of a block that belong to the site's system: its family
     * variants when it has some, else the block's original layouts.
     */
    public function inSystem(string $block, array $allowed): array
    {
        $allowed = array_values($allowed);
        $families = $this->system['families'] ?? [];
        $restyled = array_keys(config("theme.variants.{$block}", []));
        $own = array_values(array_filter($allowed, fn ($v) => in_array($v, $restyled, true) && in_array($v, $families, true)));
        $original = array_values(array_filter($allowed, fn ($v) => ! in_array($v, $restyled, true)));

        return $own ?: ($original ?: $allowed);
    }

    /** Variants a block may take in a stored design, within its visual system. */
    public static function allowedFor(array $config, string $block): array
    {
        $style = new self('');
        $style->system = (array) config('theme.systems.'.($config['look']['system'] ?? ''), []);
        $style->frame = (array) config('theme.frames.'.($config['look']['frame'] ?? ''), []);
        $all = config("theme.blocks.{$block}", []);

        return $style->inFrame($block, $style->inSystem($block, $all), $all);
    }

    /** Block variants: seeded only (too many to track across sites, and never kept as identity). */
    protected function pickVariant(string $key, array $allowed): string
    {
        $allowed = array_values($allowed);

        return $allowed[hexdec(substr(md5($this->seed.'|'.$key), 0, 8)) % count($allowed)];
    }
}
