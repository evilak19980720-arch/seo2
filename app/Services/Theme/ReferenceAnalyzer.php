<?php

namespace App\Services\Theme;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a reference website without a browser: palette (from its CSS),
 * fonts, header menu and section headings. The result steers the AI design
 * and is enforced afterwards, so the generated theme really follows the
 * reference instead of depending on screenshots and a vision model.
 */
class ReferenceAnalyzer
{
    protected const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    /**
     * @return array{url: string, title: ?string, colors: array{primary: ?string, background: ?string, text: ?string, palette: array<int, string>}, fonts: array<int, string>, menu: array<int, string>, sections: array<int, string>}|null
     */
    public function analyze(string $url): ?array
    {
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        $html = $this->fetch($url, 1_500_000);
        if ($html === null) {
            return null;
        }

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        $css = $this->styles($xpath, $url);

        return [
            'url' => $url,
            'title' => trim((string) $xpath->evaluate('string(//title)')) ?: null,
            'colors' => $this->colors($css, (string) $xpath->evaluate('string(//meta[@name="theme-color"]/@content)')),
            'fonts' => $this->fonts($css, $xpath),
            'menu' => $this->menu($xpath),
            'sections' => $this->texts($xpath, '//main//h2 | //section//h2 | //body//h2', 12),
        ];
    }

    /**
     * Palette of uploaded reference screenshots (for sites that can't be fetched):
     * background = most common colour, primary = most common saturated colour,
     * text = the common colour with the best contrast on the background.
     *
     * @param  array<int, string>  $paths  absolute image paths
     * @return array{primary: ?string, background: ?string, text: ?string, palette: array<int, string>}|null
     */
    public function imageColors(array $paths): ?array
    {
        $counts = [];
        foreach ($paths as $path) {
            $img = is_file($path) ? @imagecreatefromstring((string) file_get_contents($path)) : false;
            if (! $img) {
                continue;
            }
            $small = imagescale($img, 96, max(1, (int) round(96 * imagesy($img) / max(1, imagesx($img)))));
            for ($x = 0; $x < imagesx($small); $x++) {
                for ($y = 0; $y < imagesy($small); $y++) {
                    $rgb = imagecolorat($small, $x, $y);
                    // 16 levels per channel merges anti-aliasing and gradients.
                    $q = [(($rgb >> 16) & 0xF0) | 8, (($rgb >> 8) & 0xF0) | 8, ($rgb & 0xF0) | 8];
                    $hex = Color::toHex($q);
                    $counts[$hex] = ($counts[$hex] ?? 0) + 1;
                }
            }
        }
        if ($counts === []) {
            return null;
        }
        arsort($counts);
        $colors = array_keys($counts);
        $background = $colors[0];
        $saturated = array_values(array_filter($colors, fn ($c) => $this->saturation($c) > 0.35 && Color::contrast($c, $background) > 1.6));
        $text = collect(array_slice($colors, 0, 12))->sortByDesc(fn ($c) => Color::contrast($c, $background))->first();

        return [
            'primary' => $saturated[0] ?? null,
            'background' => $background,
            'text' => Color::contrast($text, $background) >= 4.5 ? $text : null,
            'palette' => array_slice(array_values(array_unique(array_merge(array_slice($saturated, 0, 5), array_slice($colors, 0, 5)))), 0, 10),
        ];
    }

    /** Match reference font names against the theme's self-hosted fonts. */
    public static function whitelistFont(array $referenceFonts): ?string
    {
        foreach ($referenceFonts as $name) {
            $slug = Str::slug(str_replace(['Display'], '', $name));
            foreach (array_keys(config('theme.fonts')) as $key) {
                if ($key !== 'system' && ($slug === $key || str_starts_with($slug, $key))) {
                    return $key;
                }
            }
        }

        return null;
    }

    protected function fetch(string $url, int $maxBytes): ?string
    {
        try {
            $res = Http::withHeaders(['User-Agent' => self::UA, 'Accept-Language' => 'en,pt;q=0.8'])
                ->timeout(15)->connectTimeout(8)->get($url);

            return $res->successful() ? substr($res->body(), 0, $maxBytes) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Inline <style> plus up to 4 stylesheets and style="" attributes. */
    protected function styles(DOMXPath $xpath, string $base): string
    {
        $css = '';
        foreach ($xpath->query('//style') as $node) {
            $css .= $node->textContent."\n";
        }
        $count = 0;
        foreach ($xpath->query('//link[contains(concat(" ", normalize-space(@rel), " "), " stylesheet ")]/@href') as $href) {
            if ($count++ >= 4) {
                break;
            }
            $css .= ($this->fetch($this->absolute($href->value, $base), 800_000) ?? '')."\n";
        }
        foreach ($xpath->query('//*[@style]/@style') as $style) {
            $css .= "x{{$style->value}}\n";
        }

        return $css;
    }

    protected function absolute(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (str_starts_with($href, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        return $origin.rtrim(dirname($parts['path'] ?? '/'), '/').'/'.$href;
    }

    /** @return array{primary: ?string, background: ?string, text: ?string, palette: array<int, string>} */
    protected function colors(string $css, string $themeColor): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css);

        $background = $this->ruleColor($css, '(?:html|body)', 'background(?:-color)?');
        $text = $this->ruleColor($css, '(?:html|body)', 'color');

        // Frequency of every colour used in the CSS; brand colours are the frequent saturated ones.
        $counts = [];
        preg_match_all('/#(?:[0-9a-f]{6}|[0-9a-f]{3})\b|rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*(?:0?\.\d+|1|0))?\s*\)/i', $css, $m);
        foreach ($m[0] as $raw) {
            if ($hex = $this->hex($raw)) {
                $counts[$hex] = ($counts[$hex] ?? 0) + 1;
            }
        }
        arsort($counts);

        $saturated = array_values(array_filter(array_keys($counts), fn ($hex) => $this->saturation($hex) > 0.25 && Color::luminance($hex) > 0.02 && Color::luminance($hex) < 0.9));
        $primary = $this->hex($themeColor) && $this->saturation($this->hex($themeColor)) > 0.2 ? $this->hex($themeColor) : ($saturated[0] ?? null);

        return [
            'primary' => $primary,
            'background' => $background,
            'text' => $text,
            'palette' => array_slice(array_values(array_unique(array_merge(array_filter([$primary]), $saturated, array_keys($counts)))), 0, 10),
        ];
    }

    protected function ruleColor(string $css, string $selector, string $property): ?string
    {
        if (preg_match_all('/(?:^|[},\s])'.$selector.'\s*(?:,[^{]*)?\{([^}]*)\}/i', $css, $rules)) {
            foreach (array_reverse($rules[1]) as $body) {
                if (preg_match('/(?:^|;|\s)'.$property.'\s*:\s*([^;!]+)/i', $body, $value)) {
                    if (preg_match('/#(?:[0-9a-f]{6}|[0-9a-f]{3})\b|rgba?\([^)]*\)/i', $value[1], $c) && ($hex = $this->hex($c[0]))) {
                        return $hex;
                    }
                }
            }
        }

        return null;
    }

    protected function hex(string $raw): ?string
    {
        $raw = strtolower(trim($raw));
        if (preg_match('/^#([0-9a-f]{3})$/', $raw, $m)) {
            return '#'.implode('', array_map(fn ($c) => $c.$c, str_split($m[1])));
        }
        if (preg_match('/^#[0-9a-f]{6}$/', $raw)) {
            return $raw;
        }
        if (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+))?/', $raw, $m)) {
            if (isset($m[4]) && (float) $m[4] < 0.6) {
                return null; // translucent overlays are not palette colours
            }

            return Color::toHex([min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3])]);
        }

        return null;
    }

    protected function saturation(string $hex): float
    {
        [$r, $g, $b] = array_map(fn ($v) => $v / 255, Color::rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);

        return $max <= 0 ? 0.0 : ($max - $min) / $max;
    }

    /** @return array<int, string> */
    protected function fonts(string $css, DOMXPath $xpath): array
    {
        $fonts = [];
        foreach ($xpath->query('//link[contains(@href, "fonts.googleapis.com")]/@href') as $href) {
            preg_match_all('/family=([^:&]+)/', urldecode($href->value), $m);
            foreach ($m[1] as $family) {
                $fonts[] = trim(str_replace('+', ' ', $family));
            }
        }
        preg_match_all('/font-family\s*:\s*([^;}]+)/i', $css, $m);
        foreach ($m[1] as $stack) {
            $first = trim(explode(',', $stack)[0], " \t\n\"'");
            if ($first !== '' && ! preg_match('/^(inherit|initial|var\(|sans-serif|serif|monospace|system-ui|-apple-system|icon|font ?awesome)/i', $first)) {
                $fonts[] = $first;
            }
        }

        return array_slice(array_values(array_unique($fonts)), 0, 5);
    }

    /** @return array<int, string> top-level menu labels from the header navigation */
    protected function menu(DOMXPath $xpath): array
    {
        foreach (['//header//nav//a', '//nav//a', '//header//a'] as $query) {
            $items = $this->texts($xpath, $query, 12);
            if (count($items) >= 2) {
                return $items;
            }
        }

        return [];
    }

    /** @return array<int, string> */
    protected function texts(DOMXPath $xpath, string $query, int $limit): array
    {
        $out = [];
        foreach ($xpath->query($query) as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            if ($text !== '' && mb_strlen($text) <= 60 && ! in_array($text, $out, true)) {
                $out[] = $text;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
