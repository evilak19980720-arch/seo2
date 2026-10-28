<?php

namespace App\Services\Theme;

/**
 * Rules for the optional per-site custom CSS: size cap, no @import, no
 * external url(), and nothing that hides main content (cloaking risk).
 */
class CustomCssValidator
{
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
