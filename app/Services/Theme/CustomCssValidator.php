<?php

namespace App\Services\Theme;

/**
 * Rules for the optional per-site custom CSS: size cap, no @import, no
 * external url(), and nothing that hides main content (cloaking risk).
 */
class CustomCssValidator
{
    /**
     * AI CSS: fixed widths wider than a phone (outside min-width media
     * queries) become min(Npx, 100%), so they can never push the page sideways.
     */
    public static function mobileSafe(string $css): string
    {
        $parts = preg_split('/(@media[^{]*min-width[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\})/i', $css, -1, PREG_SPLIT_DELIM_CAPTURE);

        return implode('', array_map(fn ($part, $i) => $i % 2 ? $part : preg_replace_callback(
            '/(?<![\w-])((?:min-)?width\s*:\s*)(\d+(?:\.\d+)?)px/i',
            fn ($m) => (float) $m[2] > 360 ? "{$m[1]}min({$m[2]}px,100%)" : $m[0],
            $part,
        ), $parts, array_keys($parts)));
    }

    /** Parts the theme owns: the phone menu mechanics and fixed bars. AI CSS may colour them but not move or hide them. */
    public const OWNED = '/\.(nav__menu|nav__check|nav__toggle|nav__backdrop|nav__close|nav__open|mobile-bar|cookie)\b/';

    /**
     * AI CSS made safe for the theme, rule by rule:
     * - a rule that paints a background also gets a readable text colour and
     *   the matching colour tokens, so links, dropdowns and buttons inside it
     *   follow; a text colour too close to its own background is corrected;
     * - fixed / sticky positioning and floats are dropped, and absolute
     *   positioning is kept only on ::before / ::after decorations;
     * - the phone menu, its toggle and the fixed bars keep their layout.
     *
     * @param  array<string, string>  $colors  validated design colours (primary, on_primary, background, text…)
     */
    public static function themeSafe(string $css, array $colors): string
    {
        $out = '';
        $offset = 0;
        $len = strlen($css);
        while ($offset < $len && preg_match('/\G\s*([^{}]+)\{/', $css, $m, 0, $offset)) {
            $head = trim($m[1]);
            $offset += strlen($m[0]);
            if (str_starts_with($head, '@')) {
                $depth = 1;
                $start = $offset;
                while ($offset < $len && $depth > 0) {
                    $depth += ['{' => 1, '}' => -1][$css[$offset]] ?? 0;
                    $offset++;
                }
                $inner = substr($css, $start, $offset - $start - 1);
                $out .= preg_match('/^@(media|supports|layer)/i', $head) ? $head.'{'.self::themeSafe($inner, $colors).'}' : $head.'{'.$inner.'}';

                continue;
            }
            $end = strpos($css, '}', $offset);
            $end = $end === false ? $len : $end;
            $body = substr($css, $offset, $end - $offset);
            $offset = $end + 1;
            $decls = self::rule($head, $body, $colors);
            if ($decls !== []) {
                $out .= $head.'{'.implode(';', $decls).'}';
            }
        }

        return $out;
    }

    /** @return array<int, string> the rule's declarations after the checks of themeSafe() */
    protected static function rule(string $selector, string $body, array $colors): array
    {
        $decorative = (bool) preg_match('/::?(before|after)\b/i', $selector);
        $owned = (bool) preg_match(self::OWNED, $selector);
        $decls = [];
        foreach (preg_split('/;(?![^(]*\))/', $body) as $decl) {
            if (! str_contains($decl, ':')) {
                continue;
            }
            [$prop, $value] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            if ($prop === '' || $value === '') {
                continue;
            }
            // The palette belongs to the validated design: AI CSS may not redefine the colour tokens.
            if (str_starts_with($prop, '--c-') || $prop === '--line') {
                continue;
            }
            if ($prop === 'float' || ($prop === 'position' && (preg_match('/fixed|sticky/i', $value) || (! $decorative && preg_match('/absolute/i', $value))))) {
                continue;
            }
            if ($owned && in_array($prop, ['display', 'visibility', 'opacity', 'position', 'transform', 'top', 'right', 'bottom', 'left', 'inset', 'z-index', 'width', 'height', 'max-height'], true)) {
                continue;
            }
            $decls[$prop] = $value;
        }
        if ($decorative) {
            return array_map(fn ($p, $v) => "{$p}:{$v}", array_keys($decls), $decls);
        }

        $bg = self::resolve($decls['background-color'] ?? $decls['background'] ?? '', $colors);
        if ($bg !== null) {
            // Always a plain hex here: the tokens below are derived from it, and a var() would refer to itself.
            $fg = self::resolve($decls['color'] ?? '', $colors);
            if ($fg !== null) {
                $text = Color::ensureContrast($fg, $bg, 4.5);
            } else {
                // Spelled out even when the page text would do: the rule may sit inside a coloured header or footer.
                $text = $colors['text'] ?? '#111111';
                $text = Color::contrast($text, $bg) >= 4.5 ? $text : Color::readableOn($bg);
            }
            $decls['color'] = $text;
            $decls += [
                '--c-text' => $text, '--c-link' => $text, '--c-muted' => Color::mix($text, $bg, 0.22),
                '--c-bg' => $bg, '--c-surface' => Color::mix($bg, $text, 0.08), '--line' => Color::mix($bg, $text, 0.16),
                // Outline buttons keep the brand colour only where it stands out on this background.
                '--c-outline' => Color::contrast($colors['primary'] ?? $text, $bg) >= 3 ? ($colors['primary'] ?? $text) : $text,
            ];
        } elseif (isset($decls['color']) && ! preg_match('/^(inherit|currentcolor|var\(--c-(text|muted|link)\))/i', $decls['color'])) {
            // A text colour without a background of its own may land on any background: the theme's context colours decide instead.
            unset($decls['color']);
        }

        return array_map(fn ($p, $v) => "{$p}:{$v}", array_keys($decls), $decls);
    }

    /** The solid colour a CSS value starts with (#hex, rgb(), a theme variable), or null when unknown or see-through. */
    protected static function resolve(string $value, array $colors): ?string
    {
        $value = trim(preg_replace('/!important/i', '', $value));
        if ($value === '' || preg_match('/^(transparent|inherit|initial|unset|currentcolor|none)\b/i', $value)) {
            return null;
        }
        $map = ['primary' => 'primary', 'on-primary' => 'on_primary', 'secondary' => 'secondary', 'on-secondary' => 'on_secondary', 'accent' => 'accent', 'bg' => 'background', 'surface' => 'surface', 'text' => 'text', 'muted' => 'muted', 'link' => 'link'];
        if (preg_match('/(var\(--c-([a-z-]+)\)|#[0-9a-f]{3,8}\b|rgba?\([^)]*\))/i', $value, $m, PREG_OFFSET_CAPTURE)) {
            // A colour mixed with transparency, or one with alpha, shows what is behind it.
            if (preg_match('/transparent/i', $value) || preg_match('/rgba\([^)]*,\s*0?\.\d+\s*\)|#[0-9a-f]{8}\b/i', $m[0][0])) {
                return null;
            }
            if (! empty($m[2][0])) {
                $hex = $colors[$map[strtolower($m[2][0])] ?? ''] ?? null;

                return $hex && Color::isHex($hex) ? Color::normalize($hex) : null;
            }
            if (str_starts_with($m[0][0], '#')) {
                return Color::isHex($m[0][0]) ? Color::normalize($m[0][0]) : null;
            }
            $parts = array_map('floatval', preg_split('/[\s,\/]+/', trim(preg_replace('/^rgba?\(|\)$/i', '', $m[0][0]))));

            return count($parts) >= 3 ? Color::toHex(array_slice($parts, 0, 3)) : null;
        }

        return null;
    }

    /** @return array<int, string> errors (empty = valid) */
    public static function errors(?string $css): array
    {
        $css = (string) $css;
        if (trim($css) === '') {
            return [];
        }

        $errors = [];
        $max = (int) config('theme.custom_css_max_bytes');
        if (strlen($css) > $max) {
            $errors[] = "CSS 超过 {$max} 字节";
        }
        if (stripos($css, '@import') !== false) {
            $errors[] = '禁止使用 @import';
        }
        if (preg_match_all('/url\(\s*[\'"]?([^\'")]+)/i', $css, $m)) {
            foreach ($m[1] as $url) {
                $url = trim($url);
                if (preg_match('#^(https?:)?//#i', $url) || str_starts_with(strtolower($url), 'data:text') || str_starts_with(strtolower($url), 'javascript:')) {
                    $errors[] = "禁止引用外部资源：{$url}";
                }
            }
        }
        if (preg_match('/<\s*\/?\s*(script|style)|expression\s*\(|behavior\s*:|-moz-binding/i', $css)) {
            $errors[] = '包含不允许的脚本写法';
        }

        // Phones first: fixed widths wider than a phone belong in a min-width media query.
        $outside = preg_replace('/@media[^{]*min-width[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/i', '', $css);
        if (preg_match_all('/(?<![\w-])(?:min-)?width\s*:\s*(\d+(?:\.\d+)?)px/i', (string) $outside, $w)) {
            foreach ($w[1] as $px) {
                if ((float) $px > 360) {
                    $errors[] = "固定宽度 {$px}px 会撑破手机屏幕，请写在 @media (min-width: …) 里";
                    break;
                }
            }
        }

        // Rules that hide main content areas.
        $contentSelectors = '(main|article|\.prose|\.content|\.page|\.block|section|h1|h2|p|body|html)';
        if (preg_match_all('/([^{}]+)\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER)) {
            foreach ($rules as $rule) {
                $selector = $rule[1];
                $body = strtolower($rule[2]);
                if (! preg_match('/(^|[\s,>+~])'.$contentSelectors.'([\s,.:#\[>+~]|$)/i', $selector)) {
                    continue;
                }
                if (preg_match('/display\s*:\s*none|visibility\s*:\s*hidden|opacity\s*:\s*0(\.0+)?\s*(;|!|$)|font-size\s*:\s*0|text-indent\s*:\s*-\d{3,}|(left|top)\s*:\s*-\d{4,}|clip\s*:\s*rect\(\s*0|height\s*:\s*0[^.]/', $body)) {
                    $errors[] = '禁止隐藏正文区域：'.trim($selector);
                }
            }
        }

        return array_values(array_unique($errors));
    }
}
