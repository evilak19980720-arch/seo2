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
