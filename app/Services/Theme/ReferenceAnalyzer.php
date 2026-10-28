<?php

namespace App\Services\Theme;

use App\Support\Binary;
use App\Support\Chromium;
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
     * @return array{url: string, title: ?string, colors: array{primary: ?string, background: ?string, text: ?string, palette: array<int, string>}, fonts: array<int, string>, menu: array<int, string>, sections: array<int, string>, structure: array<int, string>}|null
     */
    /**
     * What happened on the last analyze() call, for the task log and the
     * admin "检测参考站" check: how the page was read and what was found.
     *
     * @var array{status: ?int, via: ?string, bytes: int, text_chars: int, js_rendered: bool, blocked: bool, browser: bool, messages: array<int, string>}
     */
    public array $diagnostics = [];

    public function analyze(string $url): ?array
    {
        $this->diagnostics = ['status' => null, 'via' => null, 'bytes' => 0, 'text_chars' => 0, 'js_rendered' => false, 'blocked' => false, 'browser' => $this->browserAvailable(), 'messages' => []];
        if (! preg_match('#^https?://#i', $url)) {
            $this->diagnostics['messages'][] = '参考网站地址无效';

            return null;
        }

        $html = $this->page($url);
        if ($html === null) {
            return null;
        }

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        $css = $this->styles($xpath, $url);
        $content = $this->content($xpath);

        return [
            'url' => $url,
            'title' => trim((string) $xpath->evaluate('string(//title)')) ?: null,
            'colors' => $this->colors($css, (string) $xpath->evaluate('string(//meta[@name="theme-color"]/@content)')),
            'fonts' => $this->fonts($css, $xpath),
            'menu' => $this->menu($xpath),
            'navigation' => $this->navigation($xpath, $css),
            'sections' => $this->texts($xpath, '//main//h2 | //section//h2 | //body//h2', 12),
            'structure' => $this->structure($xpath),
            'content' => $content,
            'images' => $this->images($xpath, $css, $url),
        ];
    }

    /**
     * The page HTML: a plain request first; pages that build their content
     * with JavaScript (SPA) are rendered with headless Chromium when the
     * server has it. Bot-protection pages are reported, never bypassed.
     */
    protected function page(string $url): ?string
    {
        $d = &$this->diagnostics;
        $html = null;
        try {
            $res = Http::withHeaders(['User-Agent' => self::UA, 'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8'])->timeout(12)->connectTimeout(6)->get($url);
            $d['status'] = $res->status();
            $html = $res->successful() ? substr($res->body(), 0, 3_000_000) : null;
            $d['blocked'] = in_array($res->status(), [403, 429, 503], true) || ($html !== null && $this->isChallenge($html));
        } catch (Throwable $e) {
            $d['messages'][] = '请求参考网站失败：'.Str::limit($e->getMessage(), 120);
        }
        if ($html !== null && ! $d['blocked']) {
            $d['via'] = 'http';
        }

        $text = $html !== null ? $this->visibleTextLength($html) : 0;
        if ($html !== null && ! $d['blocked'] && $text < 600 && substr_count(strtolower($html), '<script') >= 3) {
            $d['js_rendered'] = true;
            $d['messages'][] = "参考网站是 JavaScript 渲染的页面，直接读取只拿到 {$text} 个字符";
        }

        if ($html === null || $d['js_rendered']) {
            if ($d['blocked']) {
                $d['messages'][] = '参考网站有防护（HTTP '.($d['status'] ?? '?').' / 人机验证），无法自动读取，请上传首页截图';
            } elseif ($d['browser']) {
                $rendered = $this->render($url);
                if ($rendered !== null && $this->isChallenge($rendered)) {
                    $d['blocked'] = true;
                    $d['messages'][] = '参考网站要求人机验证，无法自动读取，请上传首页截图';
                } elseif ($rendered !== null && $this->visibleTextLength($rendered) > $text) {
                    $html = $rendered;
                    $d['via'] = 'browser';
                    $d['messages'][] = '已用无头浏览器渲染后读取';
                } elseif ($rendered === null) {
                    $d['messages'][] = '无头浏览器渲染失败：'.(Chromium::$lastError ?: '没有输出');
                } else {
                    $d['messages'][] = '无头浏览器渲染后文字仍然很少（'.$this->visibleTextLength($rendered).' 字），页面可能要滚动或交互才加载内容，请上传首页截图';
                }
            } else {
                $d['messages'][] = Binary::canRun()
                    ? '服务器没有安装 Chromium，无法渲染 JavaScript 页面（安装后在 .env 设置 CHROMIUM_BIN），或上传首页截图'
                    : 'PHP 禁用了 proc_open，无法启动 Chromium（宝塔：PHP 设置 → 禁用函数里删除 proc_open、proc_get_status），或上传首页截图';
            }
        }

        if ($html === null || $d['blocked']) {
            return null;
        }
        $d['bytes'] = strlen($html);
        $d['text_chars'] = $this->visibleTextLength($html);

        return $html;
    }

    /**
     * Human-readable report of what was read (and why not), shown in the
     * admin check and logged on every generation task.
     *
     * @return array<int, string>
     */
    public function report(?array $ref): array
    {
        $d = $this->diagnostics;
        $lines = $d['messages'] ?? [];
        if (! $ref) {
            return $lines ?: ['参考网站读取失败'];
        }
        $nav = $ref['navigation'] ?? [];
        array_unshift($lines, '读取方式：'.(($d['via'] ?? null) === 'browser' ? '无头浏览器渲染' : '直接请求').'（HTTP '.($d['status'] ?? '?').'，可见文字 '.($d['text_chars'] ?? 0).' 字）');

        return array_merge($lines, array_filter([
            '标题：'.($ref['title'] ?? '无'),
            '配色：主色 '.($ref['colors']['primary'] ?? '未识别').'，背景 '.($ref['colors']['background'] ?? '未识别').'，文字 '.($ref['colors']['text'] ?? '未识别'),
            '字体：'.(implode(' / ', $ref['fonts']) ?: '未识别'),
            '菜单 '.count($ref['menu']).' 项：'.Str::limit(implode('、', $ref['menu']), 120),
            ($nav['dropdown'] ?? false) || ($nav['mobile_bar'] ?? false) ? '导航：'.implode('、', array_filter([($nav['dropdown'] ?? false) ? '下拉子菜单' : null, ($nav['mobile_bar'] ?? false) ? '手机底部栏' : null])) : null,
            '版块：'.(implode(' → ', $ref['structure'] ?? []) ?: '未识别'),
            '正文：'.mb_strlen($ref['content'] ?? '').' 字',
            '图片：'.count($ref['images'] ?? []).' 张',
        ]));
    }

    public function browserAvailable(): bool
    {
        return Chromium::bin() !== null;
    }

    protected function render(string $url): ?string
    {
        $out = (string) Chromium::run(['--window-size=1366,900', '--virtual-time-budget=10000', '--dump-dom', $url], 25);

        return strlen($out) > 200 ? substr($out, 0, 3_000_000) : null;
    }

    protected function isChallenge(string $html): bool
    {
        return (bool) preg_match('/cf-challenge|challenge-platform|Just a moment\.\.\.|Attention Required! \| Cloudflare|captcha-delivery|px-captcha|Checking your browser/i', substr($html, 0, 200_000));
    }

    protected function visibleTextLength(string $html): int
    {
        $text = preg_replace('/<(script|style|noscript|template|svg)[^>]*>.*?<\/\1>/is', ' ', $html);

        return mb_strlen(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $text)))));
    }

    /** Main text in reading order (headings and paragraphs), for AI rewriting. */
    protected function content(DOMXPath $xpath): string
    {
        $out = [];
        $length = 0;
        foreach ($xpath->query('//body//*[self::h1 or self::h2 or self::h3 or self::p or self::li or self::dt or self::dd][not(ancestor::nav) and not(ancestor::footer) and not(ancestor::script)]') as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            if (mb_strlen($text) < 3 || in_array($text, $out, true) || ($node->nodeName === 'li' && $xpath->query('.//p | .//h2 | .//h3', $node)->length)) {
                continue;
            }
            $line = in_array($node->nodeName, ['h1', 'h2', 'h3'], true) ? '## '.$text : $text;
            $out[] = $line;
            $length += mb_strlen($line);
            if ($length > 12_000) {
                break;
            }
        }

        return implode("\n", $out);
    }

    /**
     * Content images (img/srcset/lazy attributes, og:image, CSS backgrounds),
     * absolute URLs in page order, icons and tracking pixels left out.
     *
     * @return array<int, array{url: string, alt: string}>
     */
    protected function images(DOMXPath $xpath, string $css, string $base): array
    {
        $found = [];
        $add = function (?string $src, string $alt = '') use (&$found, $base) {
            $src = trim((string) $src);
            if ($src === '' || str_starts_with($src, 'data:') || preg_match('/\.(svg|ico|gif)(\?|$)|sprite|pixel|tracking|logo|icon|avatar|flag/i', $src)) {
                return;
            }
            $url = $this->absolute($src, $base);
            if (preg_match('#^https?://#i', $url) && ! isset($found[$url])) {
                $found[$url] = ['url' => $url, 'alt' => Str::limit(trim($alt), 120, '')];
            }
        };

        foreach ($xpath->query('//meta[@property="og:image"]/@content') as $attr) {
            $add($attr->value);
        }
        foreach ($xpath->query('//img | //source') as $node) {
            $alt = $node->getAttribute('alt');
            foreach (['data-src', 'data-original', 'data-lazy-src', 'src'] as $attr) {
                if ($node->getAttribute($attr) !== '') {
                    $add($node->getAttribute($attr), $alt);
                    break;
                }
            }
            if (($srcset = $node->getAttribute('srcset') ?: $node->getAttribute('data-srcset')) !== '') {
                // The largest candidate of the srcset.
                $candidates = array_map(fn ($c) => preg_split('/\s+/', trim($c)), explode(',', $srcset));
                usort($candidates, fn ($a, $b) => (int) ($b[1] ?? 0) <=> (int) ($a[1] ?? 0));
                $add($candidates[0][0] ?? null, $alt);
            }
        }
        if (preg_match_all('/background(?:-image)?\s*:[^;}]*url\(\s*[\'"]?([^\'")]+)/i', $css, $m)) {
            foreach ($m[1] as $src) {
                $add($src);
            }
        }

        return array_slice(array_values($found), 0, 40);
    }

    /** Account links (login, register…) are never reproduced: brand sites have no accounts. */
    public const ACCOUNT_PATTERN = '/^(log ?in|sign ?(in|up)|register|entrar|login|cadastr|cadastre-se|registr|criar conta|minha conta|my account|登录|注册)/iu';

    /** Section kinds, as theme block names, guessed from class/id names in document order. */
    public const STRUCTURE_HINTS = [
        'notice' => 'marquee|notice|announce|ticker|bulletin|noticia-bar|aviso',
        'banner' => 'banner|slider|swiper|carousel|slick|hero',
        'stats' => 'stat|counter|numbers|metric',
        'card_grid' => 'game-?list|games|lobby|card-?list|cards|grid|product-?list|items|tiles|jogos',
        'features' => 'feature|advantage|benefit|why|vantage',
        'steps' => 'step|how-?to|process|passo',
        'gallery' => 'gallery|screenshot|album|galeria',
        'faq' => 'faq|accordion|question|pergunta',
        'download' => 'download|app-?store|google-?play|baixar',
        'article_list' => 'news|article|blog|post|noticia',
    ];

    /** @return array<int, string> */
    public function structure(DOMXPath $xpath): array
    {
        $found = [];
        $nodes = $xpath->query('//body//*[(@class or @id) and not(ancestor-or-self::header) and not(ancestor-or-self::footer) and not(ancestor-or-self::nav) and not(self::a) and not(self::span) and not(self::img) and not(self::i)] | //marquee');
        foreach ($nodes as $node) {
            if ($node->nodeName === 'marquee') {
                $kind = 'notice';
            } else {
                $name = strtolower($node->getAttribute('class').' '.$node->getAttribute('id'));
                $kind = null;
                foreach (self::STRUCTURE_HINTS as $block => $pattern) {
                    if (preg_match('/(^|[\s_-])('.$pattern.')([\s_-]|$)/', $name)) {
                        $kind = $block;
                        break;
                    }
                }
                // A container of many image items reads as a card grid whatever its name.
                if ($kind === null && $node->childNodes->length >= 6) {
                    $withImages = 0;
                    foreach ($node->childNodes as $child) {
                        $withImages += $child instanceof \DOMElement && $child->getElementsByTagName('img')->length > 0 ? 1 : 0;
                    }
                    $kind = $withImages >= 6 ? 'card_grid' : null;
                }
            }
            if ($kind !== null && end($found) !== $kind && ! in_array($kind, $found, true)) {
                $found[] = $kind;
            }
            if (count($found) >= 10) {
                break;
            }
        }

        return $found;
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
                ->timeout(6)->connectTimeout(4)->get($url);

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
            $items = array_values(array_filter($this->texts($xpath, $query, 12), fn ($t) => ! preg_match(self::ACCOUNT_PATTERN, $t)));
            if (count($items) >= 2) {
                return $items;
            }
        }

        return [];
    }

    /**
     * How the reference's navigation behaves: menu tree, header buttons,
     * mobile bottom bar, sticky header, leading "home" link.
     *
     * @return array{items: array<int, array{label: string, children: array<int, string>}>, dropdown: bool, buttons: int, mobile_bar: bool, sticky: bool, home_link: bool}
     */
    public function navigation(DOMXPath $xpath, string $css = ''): array
    {
        $items = [];
        $root = null;
        foreach (['//header//nav', '//nav', '//header'] as $query) {
            foreach ($xpath->query($query) as $node) {
                if ($xpath->query('.//a', $node)->length >= 2) {
                    $root = $node;
                    break 2;
                }
            }
        }
        if ($root) {
            $clean = fn ($node) => trim(preg_replace('/\s+/u', ' ', $node->textContent));
            foreach ($xpath->query('.//li[not(ancestor::li)]', $root) as $li) {
                $first = $xpath->query('.//a | .//button | .//span', $li)->item(0);
                $label = $first ? $clean($first) : '';
                if ($label === '' || mb_strlen($label) > 40 || preg_match(self::ACCOUNT_PATTERN, $label)) {
                    continue;
                }
                $children = [];
                foreach ($xpath->query('.//ul//a', $li) as $a) {
                    $text = $clean($a);
                    if ($text !== '' && $text !== $label && mb_strlen($text) <= 40 && ! in_array($text, $children, true) && ! preg_match(self::ACCOUNT_PATTERN, $text)) {
                        $children[] = $text;
                    }
                }
                $items[] = ['label' => $label, 'children' => array_slice($children, 0, 8)];
                if (count($items) >= 12) {
                    break;
                }
            }
        }
        if ($items === []) {
            $items = array_map(fn ($label) => ['label' => $label, 'children' => []], $this->menu($xpath));
        }

        $buttons = 0;
        foreach ($xpath->query('//header//a | //header//button') as $node) {
            $name = strtolower($node->getAttribute('class').' '.$node->getAttribute('id').' '.$node->textContent);
            // Login / register buttons are left out: brand sites have no accounts.
            if (preg_match('/login|log-in|register|sign|entrar|cadastr|registr|conta|account|senha/', $name)) {
                continue;
            }
            if (preg_match('/btn|button|download|baixar|jogar|play|app/', $name) && ! $xpath->query('ancestor::li', $node)->length) {
                $buttons++;
            }
        }

        $barPattern = 'tab-?bar|bottom-?nav|bottom-?bar|footer-?nav|footer-?menu|mobile-?nav|fixed-?bottom|navbar-?bottom|menu-?bottom';
        $mobileBar = false;
        foreach ($xpath->query('//*[@class or @id]') as $node) {
            if (preg_match('/(^|[\s_-])('.$barPattern.')([\s_-]|$)/', strtolower($node->getAttribute('class').' '.$node->getAttribute('id')))) {
                $mobileBar = true;
                break;
            }
        }

        return [
            'items' => $items,
            'dropdown' => collect($items)->contains(fn ($i) => $i['children'] !== []),
            'buttons' => min(2, $buttons),
            'mobile_bar' => $mobileBar || (bool) preg_match('/(\.('.$barPattern.')|#('.$barPattern.'))[^{]*\{[^}]*position\s*:\s*fixed[^}]*bottom/i', $css),
            'sticky' => (bool) preg_match('/(header|\.header|#header|\.navbar|\.nav|\.top-?bar)[\w.#-]*\s*\{[^}]*position\s*:\s*(fixed|sticky)/i', $css),
            'home_link' => isset($items[0]) && (bool) preg_match('/^(home|in[ií]cio|inicio|首页|página inicial|accueil|start)$/iu', $items[0]['label']),
        ];
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
