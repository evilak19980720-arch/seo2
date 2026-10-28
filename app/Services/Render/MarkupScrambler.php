<?php

namespace App\Services\Render;

use App\Models\Site;

/**
 * Gives every site its own class names (".hero__lead" becomes ".c3f9a1"),
 * consistently in its HTML, inline CSS and stylesheets, so a hundred sites
 * built from one theme do not share an identical, matchable markup footprint.
 * Ad code (inside <template>) and classes used by inline scripts are kept.
 */
class MarkupScrambler
{
    /** @var array<string, string> theme class => site class */
    protected array $map = [];

    protected static ?array $vocabulary = null;

    public function __construct(protected Site $site)
    {
        $used = [];
        foreach (self::vocabulary() as $name) {
            $hash = hash_hmac('sha256', $name, $site->id.'|'.config('app.key'));
            for ($len = 5; isset($used[$alias = 'c'.substr($hash, 0, $len)]); $len++);
            $used[$alias] = true;
            $this->map[$name] = $alias;
        }
    }

    public static function enabled(): bool
    {
        return (bool) config('platform.randomize_markup', true);
    }

    /** Class names defined by the theme stylesheets, minus those inline scripts rely on. */
    public static function vocabulary(): array
    {
        if (self::$vocabulary !== null) {
            return self::$vocabulary;
        }
        $css = file_get_contents(resource_path('theme/base.css')).file_get_contents(resource_path('theme/critical.css'));
        preg_match_all('/\.(-?[A-Za-z_][\w-]*)/', self::withoutUrls($css), $m);
        $names = array_unique($m[1]);

        $scripts = '';
        foreach (glob(resource_path('views/theme/{,*/}*.blade.php'), GLOB_BRACE) ?: [] as $view) {
            preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', (string) file_get_contents($view), $s);
            $scripts .= implode("\n", $s[1]);
        }
        $names = array_filter($names, fn ($n) => ! preg_match('/(?<![\w-])'.preg_quote($n, '/').'(?![\w-])/', $scripts));

        return self::$vocabulary = array_values($names);
    }

    public function css(string $css): string
    {
        if ($css === '') {
            return $css;
        }
        // Leave url(...) and strings alone: file names like "x.woff2" are not classes.
        $parts = preg_split('/(url\([^)]*\)|"[^"]*"|\'[^\']*\')/', $css, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $parts[$i] = preg_replace_callback('/\.(-?[A-Za-z_][\w-]*)/', fn ($m) => '.'.($this->map[$m[1]] ?? $m[1]), $part);
            }
        }

        return implode('', $parts);
    }

    public function html(string $html): string
    {
        // Ad templates and scripts are third-party / behaviour code: untouched.
        $parts = preg_split('/(<template\b.*?<\/template>|<script\b.*?<\/script>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }
            $part = preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', fn ($m) => $m[1].$this->css($m[2]).$m[3], $part);
            $parts[$i] = preg_replace_callback('/\sclass="([^"]*)"/', function ($m) {
                $classes = array_map(fn ($c) => $this->map[$c] ?? $c, preg_split('/\s+/', trim($m[1])));

                return ' class="'.implode(' ', $classes).'"';
            }, $part);
        }

        return implode('', $parts);
    }

    protected static function withoutUrls(string $css): string
    {
        return preg_replace('/url\([^)]*\)|"[^"]*"|\'[^\']*\'/', '', $css);
    }
}
