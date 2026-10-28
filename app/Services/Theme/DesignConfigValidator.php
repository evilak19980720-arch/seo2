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

        $result = [
            'colors' => $colors,
            'fonts' => $fonts,
            'radius' => $pick('radius', $theme['radius'], 'md'),
            'density' => $pick('density', $theme['density'], 'normal'),
            'shadow' => $pick('shadow', $theme['shadow'], 'soft'),
            'nav' => $this->variant('nav', $config['nav'] ?? null),
            'footer' => $this->variant('footer', $config['footer'] ?? null),
            'pages' => [],
        ];

        // Page compositions
        $pageTypes = array_map(fn ($p) => $p['blocks'], $siteTypeConfig['pages']) + self::SYSTEM_PAGES;
        foreach ($pageTypes as $pageType => $defaultBlocks) {
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
