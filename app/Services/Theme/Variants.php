<?php

namespace App\Services\Theme;

/**
 * Restyled variants (config theme.variants): each one renders with an
 * existing variant's markup (its base) and adds its own class, plus
 * data-stage when it sits on the dark secondary colour. Its CSS lives in
 * resources/theme/variants/{block}--{name}.css and only reaches the sites
 * that use it.
 */
class Variants
{
    /** @return array{base: string, stage?: bool}|null */
    public static function definition(string $block, ?string $variant): ?array
    {
        return $variant === null ? null : (config("theme.variants.{$block}.{$variant}") ?: null);
    }

    /** The variant whose markup a variant uses (itself for the original ones). */
    public static function base(string $block, ?string $variant): ?string
    {
        return self::definition($block, $variant)['base'] ?? $variant;
    }

    /**
     * Add the variant's class (and data-stage) next to its base class, on the
     * first element that has it. A block that fell back to another layout
     * (too few items for the base) keeps that layout's plain look.
     */
    public static function tag(string $html, string $block, ?string $variant): string
    {
        $def = self::definition($block, $variant);
        if (! $def) {
            return $html;
        }
        $base = preg_quote($def['base'], '/');

        return preg_replace_callback('/<([a-z][a-z0-9]*)\b([^>]*?)\bclass="([^"]*?)\b([a-z][\w-]*?)--'.$base.'(?![\w-])([^"]*)"/', function ($m) use ($variant, $def) {
            $stage = ! empty($def['stage']) ? ' data-stage' : '';

            return "<{$m[1]}".rtrim($m[2])."{$stage} class=\"{$m[3]}{$m[4]}--{$def['base']} {$m[4]}--{$variant}{$m[5]}\"";
        }, $html, 1) ?? $html;
    }

    public static function file(string $block, string $variant): string
    {
        return resource_path("theme/variants/{$block}--{$variant}.css");
    }

    /** CSS of the restyled variants a design uses: nav, footer and every page's blocks. */
    public static function css(array $design): string
    {
        $used = [];
        foreach (['nav', 'footer'] as $block) {
            if (is_string($design[$block] ?? null)) {
                $used["{$block}--{$design[$block]}"] = [$block, $design[$block]];
            }
        }
        foreach ((array) ($design['pages'] ?? []) as $blocks) {
            foreach ((array) $blocks as $entry) {
                if (is_array($entry) && is_string($entry['block'] ?? null) && is_string($entry['variant'] ?? null)) {
                    $used["{$entry['block']}--{$entry['variant']}"] = [$entry['block'], $entry['variant']];
                }
            }
        }
        ksort($used);
        $css = '';
        foreach ($used as [$block, $variant]) {
            if (self::definition($block, $variant) && is_file($file = self::file($block, $variant))) {
                $css .= "\n".file_get_contents($file);
            }
        }

        return $css;
    }

    /** The design's page skeleton (config theme.frames), null for none. */
    public static function frame(array $design): ?string
    {
        $frame = $design['look']['frame'] ?? null;

        return is_string($frame) && config("theme.frames.{$frame}") && is_file(resource_path("theme/frames/{$frame}.css")) ? $frame : null;
    }

    /** CSS of the design's own skin (if it has a file) and page skeleton; the skeleton comes last and wins ties. */
    public static function frameCss(array $design): string
    {
        $skin = $design['style']['skin'] ?? null;
        $css = self::decorSkin($skin) ? "\n".file_get_contents(resource_path("theme/skins/{$skin}.css")) : '';

        return $css.(($frame = self::frame($design)) ? "\n".file_get_contents(resource_path("theme/frames/{$frame}.css")) : '');
    }

    /** Skins kept in their own file only decorate, so they go with any page skeleton. */
    public static function decorSkin(mixed $skin): bool
    {
        return is_string($skin) && preg_match('/^[a-z]+$/', $skin) && is_file(resource_path("theme/skins/{$skin}.css"));
    }

    /** Every variant and frame stylesheet (for the class-name vocabulary and checks). */
    public static function allCss(): string
    {
        return implode("\n", array_map('file_get_contents', array_merge(glob(resource_path('theme/variants/*.css')) ?: [], glob(resource_path('theme/skins/*.css')) ?: [], glob(resource_path('theme/frames/*.css')) ?: [])));
    }
}
