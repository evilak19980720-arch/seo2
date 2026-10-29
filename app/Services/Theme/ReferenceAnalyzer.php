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
        // Doorway pages: a meta refresh or a full-page iframe around the real site.
        for ($hop = 0; $html !== null && $hop < 2 && ($next = $this->forward($html, $url)); $hop++) {
            $this->diagnostics['messages'][] = $next['why'].'：'.$next['url'];
            $url = $next['url'];
            $html = $this->page($url);
        }
        if ($html === null) {
            return null;
        }
        $this->keepCopy($url, $html);

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        $css = $this->styles($xpath, $url);
        // Links before pruning: dropdown menus are often hidden until hovered.
        $links = $this->links($xpath, $url);
        $this->prune($xpath);
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
            'links' => $links,
            'cards' => $this->cards($xpath, $url),
        ];
    }

    /**
     * Read the reference's inner pages (menu links first, then other links
     * on the home page), so site data (games, promotions, events, notices…)
     * comes from the whole site, not only its home page. Stops at $max pages
     * or after $seconds.
     *
     * @return array<int, array{url: string, label: string, title: ?string, content: string, cards: array<int, array{name: string, image: ?string, url: ?string}>}>
     */
    public function crawl(array $home, int $max = 12, int $seconds = 120): array
    {
        $saved = $this->diagnostics;
        $started = microtime(true);
        $seen = [rtrim((string) $home['url'], '/') => true];
        $pages = [];
        $queue = array_values($home['links'] ?? []);
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url((string) $home['url'], PHP_URL_HOST)));
        // Detail pages behind the cards (one promotion, one event…) carry their own pictures and terms.
        $details = 0;
        for ($i = 0; $i < count($queue); $i++) {
            $link = $queue[$i];
            if (microtime(true) - $started > $seconds) {
                break;
            }
            if (empty($link['detail']) && count(array_filter($pages, fn ($p) => empty($p['detail']))) >= $max) {
                continue;
            }
            $key = rtrim($link['url'], '/');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $html = rescue(fn () => $this->page($link['url']), null, false);
            if ($html === null) {
                continue;
            }
            $dom = new DOMDocument;
            @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($dom);
            $this->prune($xpath);
            $content = $this->content($xpath);
            $cards = $this->cards($xpath, $link['url']);
            // Same text as the home page (SPA shell, redirect to home): nothing new.
            if (mb_strlen($content) < 80 && $cards === []) {
                continue;
            }
            // A detail page's main picture belongs to the item it is about.
            if (! empty($link['detail']) && ($main = $this->images($xpath, '', $link['url'])[0]['url'] ?? null)) {
                array_unshift($cards, ['name' => $link['text'], 'image' => $main, 'url' => $link['url']]);
                $cards = array_values(collect($cards)->unique(fn ($c) => mb_strtolower($c['name']))->all());
            }
            $pages[] = [
                'url' => $link['url'],
                'label' => $link['text'],
                'title' => trim((string) $xpath->evaluate('string(//title)')) ?: null,
                'content' => Str::limit($content, 6000, ''),
                'cards' => $cards,
                'images' => $this->images($xpath, '', $link['url']),
                'detail' => ! empty($link['detail']),
            ];
            if (empty($link['detail'])) {
                foreach ($cards as $card) {
                    $url = (string) ($card['url'] ?? '');
                    if ($details < 8 && $url !== '' && preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) === $host
                        && ! isset($seen[rtrim($url, '/')]) && ! preg_match(self::ACCOUNT_PATTERN, $card['name'])) {
                        $queue[] = ['url' => $url, 'text' => $card['name'], 'detail' => true];
                        $details++;
                    }
                }
            }
        }
        $this->diagnostics = $saved;

        return $pages;
    }

    /**
     * Same-site links of a page, menu links first: no anchors, files,
     * account or cart pages. Hash routes of single-page apps ("#/promo") are
     * kept as distinct pages.
     *
     * @return array<int, array{url: string, text: string}>
     */
    protected function links(DOMXPath $xpath, string $base): array
    {
        $bare = fn (?string $host) => preg_replace('/^www\./', '', strtolower((string) $host));
        $host = $bare(parse_url($base, PHP_URL_HOST));
        $self = rtrim(preg_replace('/#.*$/', '', $base), '/');
        $out = [];
        foreach (['//nav//a | //header//a', '//body//a'] as $query) {
            foreach ($xpath->query($query) as $a) {
                $href = trim($a->getAttribute('href'));
                if ($href === '' || preg_match('/^(javascript|mailto|tel|sms|whatsapp):/i', $href) || preg_match('/^#(?![\/!])/', $href)) {
                    continue;
                }
                $url = $this->absolute($href, $base);
                [$path, $fragment] = array_pad(explode('#', $url, 2), 2, null);
                $url = $fragment !== null && preg_match('#^[/!]#', $fragment) ? $path.'#'.$fragment : $path;
                $parts = parse_url($url);
                if (! isset($parts['host']) || $bare($parts['host']) !== $host
                    || preg_match('/\.(jpe?g|png|gif|webp|avif|svg|ico|pdf|zip|rar|apk|ipa|exe|dmg|css|js|xml|json|txt|mp4|mp3)(\?|$)/i', $parts['path'] ?? '')
                    || preg_match('#/(log-?in|log-?out|sign-?(in|up)|register|cadastr\w*|account|minha-conta|cart|checkout|deposit\w*|withdraw\w*|saque|wp-admin|admin)(/|$)#i', ($parts['path'] ?? '').'/'.($parts['fragment'] ?? ''))) {
                    continue;
                }
                $text = trim(preg_replace('/\s+/u', ' ', $a->textContent)) ?: trim($a->getAttribute('title').' '.$a->getAttribute('aria-label'));
                if ($text !== '' && preg_match(self::ACCOUNT_PATTERN, $text)) {
                    continue;
                }
                $key = rtrim($url, '/');
                if ($key === $self || isset($out[$key])) {
                    continue;
                }
                $out[$key] = ['url' => $url, 'text' => Str::limit($text, 60, '')];
            }
        }

        return array_values(array_slice($out, 0, 80));
    }

    /**
     * Image tiles of a page (game cards, promotion banners, provider logos):
     * the image with the item's name, from its alt/title or the tile's own
     * short text, and the tile's link.
     *
     * @return array<int, array{name: string, image: ?string, url: ?string}>
     */
    public function cards(DOMXPath $xpath, string $base): array
    {
        $cards = [];
        foreach ($xpath->query('//body//img | //body//*[@data-bg or @data-background or @data-src][not(self::img)]') as $node) {
            $src = null;
            foreach (['data-src', 'data-original', 'data-lazy-src', 'data-lazy', 'data-bg', 'data-background', 'src'] as $attr) {
                if (($v = trim($node->getAttribute($attr))) !== '' && ! str_starts_with($v, 'data:')) {
                    $src = $this->absolute($v, $base);
                    break;
                }
            }
            if ($src === null || preg_match('/\.(svg|ico)(\?|$)|sprite|pixel|tracking|avatar|flag/i', $src)) {
                continue;
            }
            $name = trim($node->getAttribute('alt')) ?: trim($node->getAttribute('title'));
            $link = null;
            $blurb = '';
            for ($el = $node->parentNode, $depth = 0; $el instanceof \DOMElement && $depth < 5; $el = $el->parentNode, $depth++) {
                if ($link === null && $el->nodeName === 'a' && ($href = trim($el->getAttribute('href'))) !== '' && ! str_starts_with($href, 'javascript:')) {
                    $link = $this->absolute($href, $base);
                }
                $text = $this->blockText($xpath, $el);
                // A block with several pictures is a list, not this picture's card.
                $own = $xpath->evaluate('count(.//img)', $el) <= 1;
                // "Ver mais" / "Saiba mais" links next to the picture, not around it.
                if ($link === null && $own && mb_strlen($text) <= 600 && ($a = $xpath->query('.//a[@href]', $el)->item(0)) instanceof \DOMElement
                    && ($href = trim($a->getAttribute('href'))) !== '' && ! preg_match('/^(#|javascript:)/i', $href)) {
                    $link = $this->absolute($href, $base);
                }
                if (($name === '' || $this->isFileName($name)) && mb_strlen($text) >= 2 && mb_strlen($text) <= 60) {
                    $name = $text;
                } elseif (($name === '' || $this->isFileName($name)) && $own && mb_strlen($text) > 60 && ($title = $this->blockTitle($xpath, $el)) !== null) {
                    // Promotion / news cards: picture + heading + text in one block.
                    $name = $title;
                }
                if ($blurb === '' && $own && mb_strlen($text) > mb_strlen($name) + 20 && mb_strlen($text) <= 600) {
                    $rest = $name !== '' && str_starts_with($text, $name) ? ltrim(mb_substr($text, mb_strlen($name)), " \t:-–|") : $text;
                    $blurb = Str::limit($rest, 220);
                }
                if ($name !== '' && ! $this->isFileName($name) && $link !== null && $depth >= 2) {
                    break;
                }
            }
            if ($name === '' || $this->isFileName($name) || mb_strlen($name) > 60 || preg_match(self::ACCOUNT_PATTERN, $name)) {
                continue;
            }
            $key = mb_strtolower($name);
            $cards[$key] ??= array_filter(['name' => $name, 'image' => $src, 'url' => $link, 'text' => $blurb], fn ($v) => $v !== null && $v !== '') + ['url' => null];
            if (count($cards) >= 120) {
                break;
            }
        }

        return array_values($cards);
    }

    /** A block's visible text with its pieces kept apart ("Title" + "Body", not "TitleBody"). */
    protected function blockText(DOMXPath $xpath, \DOMElement $block): string
    {
        $parts = [];
        foreach ($xpath->query('.//text()', $block) as $node) {
            if (($t = trim($node->textContent)) !== '') {
                $parts[] = $t;
            }
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /** The heading of a content block: its first h1-h6, title-like element or bold line. */
    protected function blockTitle(DOMXPath $xpath, \DOMElement $block): ?string
    {
        $query = './/*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]'
            .' | .//*[contains(translate(@class, "TITLENAM", "titlenam"), "title") or contains(translate(@class, "TITLENAM", "titlenam"), "name")]'
            .' | .//strong | .//b';
        foreach ($xpath->query($query, $block) as $el) {
            $text = trim(preg_replace('/\s+/u', ' ', $el->textContent));
            if (mb_strlen($text) >= 3 && mb_strlen($text) <= 80 && ! $this->isFileName($text)) {
                return $text;
            }
        }

        return null;
    }

    /** "banner_02.png", "img123": an alt that is a file name says nothing. */
    protected function isFileName(string $text): bool
    {
        return (bool) preg_match('/^[\w-]+\.(png|jpe?g|webp|gif|avif)$|^(img|image|pic|banner|icon)[\s_-]?\d*$/i', trim($text));
    }

    /**
     * The page HTML: a plain request first; pages that build their content
     * with JavaScript (SPA) are rendered with headless Chromium when the
     * server has it. Bot-protection pages are reported, never bypassed.
     */
    protected function page(string $url): ?string
    {
        $d = &$this->diagnostics;
        $d['js_rendered'] = $d['blocked'] = false;
        $d['via'] = null;
        $html = null;
        try {
            $res = Http::withHeaders(['User-Agent' => self::UA, 'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8'])->timeout(12)->connectTimeout(6)->get($url);
            $d['status'] = $res->status();
            $html = $res->successful() ? $this->slim($res->body()) : null;
            $d['blocked'] = in_array($res->status(), [403, 429, 503], true) || ($html !== null && $this->isChallenge($html));
        } catch (Throwable $e) {
            $d['messages'][] = '请求参考网站失败：'.Str::limit($e->getMessage(), 120);
        }
        if ($html !== null && ! $d['blocked']) {
            $d['via'] = 'http';
        }

        $text = $html !== null ? $this->visibleTextLength($html) : 0;
        if ($html !== null && ! $d['blocked'] && $text < 600 && str_contains(strtolower($html), '<script')) {
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
            isset($d['saved']) ? '读到的页面源码：'.$d['saved'] : null,
        ]));
    }

    public function browserAvailable(): bool
    {
        return Chromium::bin() !== null;
    }

    protected function render(string $url): ?string
    {
        $out = (string) Chromium::run(['--window-size=1366,900', '--virtual-time-budget=10000', '--dump-dom', $url], 25);

        return strlen($out) > 200 ? $this->slim($out) : null;
    }

    /**
     * Keep the page under 3 MB without losing the body: SPA builds often
     * inline megabytes of CSS in <head>, so <style> blocks share a 1 MB
     * budget (enough for colours and fonts) before the rest is cut.
     */
    protected function slim(string $html): string
    {
        $budget = 1_000_000;
        $out = '';
        $pos = 0;
        // A plain scan: a regex over megabytes of CSS hits PCRE's backtrack limit.
        while (($open = stripos($html, '<style', $pos)) !== false && ($start = strpos($html, '>', $open)) !== false) {
            $close = stripos($html, '</style>', $start) ?: strlen($html);
            $css = substr($html, $start + 1, $close - $start - 1);
            if (strlen($css) > $budget) {
                // Cut at a rule boundary so the kept CSS still parses.
                $css = substr($css, 0, max(0, $budget));
                $css = ($end = strrpos($css, '}')) !== false ? substr($css, 0, $end + 1) : '';
            }
            $budget -= strlen($css);
            $out .= substr($html, $pos, $start + 1 - $pos).$css;
            $pos = $close;
        }
        $out .= substr($html, $pos);

        return substr($out, 0, 3_000_000);
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

        if ($length < 300) {
            // Apps built from <div>s only (common with Vue/React casino and shop sites).
            foreach ($this->looseTexts($xpath) as $text) {
                if (! in_array($text, $out, true)) {
                    $out[] = $text;
                    $length += mb_strlen($text);
                }
                if ($length > 12_000) {
                    break;
                }
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
            $src = preg_replace('/^url\(\s*[\'"]?([^\'")]+).*$/i', '$1', trim((string) $src));
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
            foreach (['data-src', 'data-original', 'data-lazy-src', 'data-lazy', 'data-original-src', 'data-url', 'data-echo', 'lazy-src', 'data-actualsrc', 'src'] as $attr) {
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
        foreach ($xpath->query('//*[@data-bg or @data-background or @data-background-image or @data-bg-src]') as $node) {
            $add($node->getAttribute('data-bg') ?: $node->getAttribute('data-background') ?: $node->getAttribute('data-background-image') ?: $node->getAttribute('data-bg-src'));
        }
        if (preg_match_all('/background(?:-image)?\s*:[^;}]*url\(\s*[\'"]?([^\'")]+)/i', $css, $m)) {
            foreach ($m[1] as $src) {
                $add($src);
            }
        }

        return array_slice(array_values($found), 0, 40);
    }

    /** Account links (login, register…) are never reproduced: brand sites have no accounts. */
    public const ACCOUNT_PATTERN = '/^(log ?in|log ?out|sign ?(in|up|out)|register|join now|create account|my account|entrar|cadastr|registr|criar conta|minha conta|iniciar sesi[oó]n|reg[ií]strate|crear cuenta|mi cuenta|connexion|se connecter|s\'inscrire|inscri|mon compte|anmelden|registrieren|konto|accedi|registrati|masuk|daftar|đăng nhập|đăng ký|เข้าสู่ระบบ|สมัคร|войти|регистрац|ログイン|新規登録|会員登録|로그인|회원가입|登录|登錄|注册|註冊|تسجيل)/iu';

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
                $name = strtolower($node->nodeName.' '.$node->getAttribute('class').' '.$node->getAttribute('id'));
                $kind = null;
                foreach (self::STRUCTURE_HINTS as $block => $pattern) {
                    if (preg_match('/(^|[\s_-])('.$pattern.')([\s_-]|$)/', $name)) {
                        $kind = $block;
                        break;
                    }
                }
                // A container of many image items reads as a card grid whatever its name;
                // with captions it is one even inside a slider (game and product rows).
                if ($node->childNodes->length >= 6) {
                    $withImages = $captioned = 0;
                    foreach ($node->childNodes as $child) {
                        if ($child instanceof \DOMElement && $child->getElementsByTagName('img')->length > 0) {
                            $withImages++;
                            $captioned += trim($child->textContent) !== '' ? 1 : 0;
                        }
                    }
                    if ($captioned >= 6 || ($kind === null && $withImages >= 6)) {
                        $kind = 'card_grid';
                    }
                }
            }
            if ($kind !== null && end($found) !== $kind && ! in_array($kind, $found, true)) {
                $found[] = $kind;
            }
            if (count($found) >= 10) {
                break;
            }
        }

        if (! in_array('notice', $found, true) && $xpath->query('//marquee | //*[contains(concat(" ", @class, " "), " js-marquee ") or contains(local-name(), "hot-news") or contains(local-name(), "marquee")]')->length) {
            array_unshift($found, 'notice');
        }

        return array_slice($found, 0, 10);
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

        if (str_starts_with($href, '?') || str_starts_with($href, '#')) {
            return $origin.($parts['path'] ?? '/').$href;
        }
        // Relative to the base directory ("/a/b" -> "/a/", "/a/" stays).
        $dir = str_ends_with($parts['path'] ?? '/', '/') ? ($parts['path'] ?? '/') : rtrim(dirname($parts['path']), '/').'/';
        $path = $dir.preg_replace('#^\./#', '', $href);
        while (preg_match('#/(?!\.\./)[^/]+/\.\./#', $path)) {
            $path = preg_replace('#/(?!\.\./)[^/]+/\.\./#', '/', $path, 1);
        }

        return $origin.str_replace('/../', '/', $path);
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
        foreach ($xpath->query('//nav') as $nav) {
            $items = [];
            foreach ($xpath->query('.//li[not(ancestor::li)]', $nav) as $li) {
                $first = $xpath->query('.//a | .//button | .//span', $li)->item(0);
                $text = $first ? trim(preg_replace('/\s+/u', ' ', $first->textContent)) : '';
                if ($text !== '' && mb_strlen($text) <= 40 && ! preg_match(self::ACCOUNT_PATTERN, $text) && ! in_array($text, $items, true)) {
                    $items[] = $text;
                }
            }
            if (count($items) >= 3) {
                return array_slice($items, 0, 12);
            }
        }
        foreach (['//header//nav//a', '//nav//a', '//header//a'] as $query) {
            $items = array_values(array_filter($this->texts($xpath, $query, 12), fn ($t) => ! preg_match(self::ACCOUNT_PATTERN, $t)));
            if (count($items) >= 2) {
                return $items;
            }
        }

        return $this->looseMenu($xpath);
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
                foreach ($xpath->query('.//ul//a | .//ol//a', $li) as $a) {
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
            if (preg_match('/login|log-in|logout|register|sign|entrar|cadastr|registr|conta|account|senha|password|member|daftar|masuk|inscri|anmeld/', $name) || preg_match(self::ACCOUNT_PATTERN, trim($node->textContent))) {
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

    protected const SKIP = 'self::script or self::style or self::noscript or self::template or self::svg or self::iframe';

    /** Text written directly inside any element (not only <p>/<h2>), outside header, nav and footer. */
    protected function looseTexts(DOMXPath $xpath): array
    {
        $out = [];
        $query = '//body//*[not('.self::SKIP.') and not(ancestor-or-self::header) and not(ancestor-or-self::nav) and not(ancestor-or-self::footer) and not(ancestor::*['.self::SKIP.'])]';
        foreach ($xpath->query($query) as $node) {
            if ($this->isChrome($node)) {
                continue;
            }
            $text = '';
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE || in_array($child->nodeName, ['b', 'strong', 'em', 'i', 'br', 'font', 'small'], true)) {
                    $text .= ' '.$child->textContent;
                }
            }
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            // A sentence or a heading, not a button label or a price.
            if (mb_strlen($text) < 12 || ! preg_match('/\p{L}{2,}.*\s.*\p{L}{2,}|\p{Han}{6,}/u', $text) || in_array($text, $out, true)) {
                continue;
            }
            $heading = preg_match('/(^|[\s_-])(title|tit|heading|headline|h[1-3])([\s_-]|$)/', strtolower($node->getAttribute('class')));
            $out[] = $heading && mb_strlen($text) <= 90 ? '## '.$text : $text;
            if (count($out) >= 200) {
                break;
            }
        }

        return $out;
    }

    /** Inside a header/menu-like container that has no semantic <header>/<nav> tag. */
    protected function isChrome(\DOMElement $node): bool
    {
        for ($n = $node; $n instanceof \DOMElement && $n->nodeName !== 'body'; $n = $n->parentNode) {
            if (preg_match('/(^|[\s_-])('.self::CHROME_PATTERN.')([\s_-]|$)/', strtolower($n->nodeName.' '.$n->getAttribute('class').' '.$n->getAttribute('id')))) {
                return true;
            }
        }

        return false;
    }

    protected const CHROME_PATTERN = 'header|navbar|nav|menu|topbar|top-?nav|tab-?bar|footer|sidebar|side-?menu|drawer';

    /**
     * Menu labels from a header/nav-like <div> when the page has no <nav>:
     * short texts of its leaf items, in document order.
     *
     * @return array<int, string>
     */
    protected function looseMenu(DOMXPath $xpath): array
    {
        $containers = $xpath->query('//body//*[not('.self::SKIP.')][contains(translate(concat(@class," ",@id," ",local-name()),"NAVMEUHDR","navmeuhdr"),"nav") or contains(translate(concat(@class," ",@id," ",local-name()),"NAVMEUHDR","navmeuhdr"),"menu") or contains(translate(concat(@class," ",@id," ",local-name()),"NAVMEUHDR","navmeuhdr"),"header")]');
        foreach ($containers as $box) {
            if (preg_match('/footer|bottom|tab-?bar|sidebar|drawer/', strtolower($box->getAttribute('class').' '.$box->getAttribute('id')))) {
                continue;
            }
            $items = [];
            foreach ($xpath->query('.//*[not(*) or self::a or self::li][not('.self::SKIP.')]', $box) as $leaf) {
                $text = trim(preg_replace('/\s+/u', ' ', $leaf->textContent));
                if (mb_strlen($text) < 2 || mb_strlen($text) > 30 || preg_match(self::ACCOUNT_PATTERN, $text) || preg_match('/^[\d\W_]+$/u', $text) || in_array($text, $items, true)) {
                    continue;
                }
                $items[] = $text;
                if (count($items) >= 12) {
                    break;
                }
            }
            if (count($items) >= 3) {
                return $items;
            }
        }

        return [];
    }

    /**
     * Where a doorway page sends visitors: a meta refresh, or an iframe that
     * fills a page with (almost) no text of its own.
     *
     * @return array{url: string, why: string}|null
     */
    protected function forward(string $html, string $base): ?array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.substr($html, 0, 500_000), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $refresh = (string) $xpath->evaluate('string(//meta[translate(@http-equiv,"REFSH","refsh")="refresh"]/@content)');
        if (preg_match('/url\s*=\s*[\'"]?([^\'";]+)/i', $refresh, $m)) {
            $target = $this->absolute(trim($m[1]), $base);
            if (preg_match('#^https?://#i', $target) && rtrim($target, '/') !== rtrim($base, '/')) {
                return ['url' => $target, 'why' => '参考网站跳转到'];
            }
        }
        if ($this->visibleTextLength($html) < 200) {
            foreach ($xpath->query('//iframe[@src]') as $frame) {
                $target = $this->absolute(trim($frame->getAttribute('src')), $base);
                $small = preg_match('/^\d+$/', $frame->getAttribute('width')) && (int) $frame->getAttribute('width') < 300;
                if (! $small && preg_match('#^https?://#i', $target) && ! preg_match('#google|facebook|youtube|doubleclick|recaptcha|hcaptcha|cloudflare#i', $target)) {
                    return ['url' => $target, 'why' => '参考网站内容在 iframe 里，改为读取'];
                }
            }
        }

        return null;
    }

    /** Drop what a visitor never sees as content: hidden nodes, slider clones, cookie banners. */
    protected function prune(DOMXPath $xpath): void
    {
        $noise = 'cookie|consent|gdpr|lgpd|privacy-banner';
        $query = '//body//*[@hidden or contains(translate(@style," ",""),"display:none") or contains(concat(" ",@class," ")," slick-cloned ") or contains(concat(" ",@class," ")," swiper-slide-duplicate ") or contains(concat(" ",@class," ")," cloned ")]';
        $remove = iterator_to_array($xpath->query($query));
        foreach ($xpath->query('//body//*[@class or @id]') as $node) {
            if (preg_match('/(^|[\s_-])('.$noise.')([\s_-]|$)/i', $node->getAttribute('class').' '.$node->getAttribute('id'))) {
                $remove[] = $node;
            }
        }
        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /** The HTML that was analysed, kept for troubleshooting (one file per host). */
    protected function keepCopy(string $url, string $html): void
    {
        try {
            $file = storage_path('app/reference/'.preg_replace('/[^a-z0-9.-]/', '_', strtolower((string) parse_url($url, PHP_URL_HOST))).'.html');
            @mkdir(dirname($file), 0775, true);
            if (@file_put_contents($file, $html) !== false) {
                $this->diagnostics['saved'] = $file;
            }
        } catch (Throwable) {
            // Troubleshooting copy only.
        }
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
