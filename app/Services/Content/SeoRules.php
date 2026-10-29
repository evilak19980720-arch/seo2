<?php

namespace App\Services\Content;

use App\Services\Theme\ReferenceAnalyzer;
use DOMDocument;
use DOMXPath;

/**
 * Deterministic on-page SEO rules shared by generation and rendering: weak
 * titles and descriptions, seasonal headings, unverifiable claims, short
 * navigation labels, thin pages. Language-independent where possible.
 */
class SeoRules
{
    /** Pages whose length is not a quality signal. */
    public const NO_MIN_WORDS = ['contact', 'privacy', 'terms', 'media', 'updates'];

    /** Seasonal / event headlines age badly and say nothing about the product. */
    public const SEASONAL = '/\b(spring|summer|autumn|fall season|winter|christmas|xmas|easter|halloween|black friday|cyber monday|new year|valentine|ramadan|diwali|carnival|primavera|ver[aã]o|outono|inverno|natal|p[aá]scoa|carnaval|ano novo|navidad|semana santa|a[nñ]o nuevo|oto[nñ]o|invierno|f[eê]te|no[eë]l|weihnachten|ostern|tết|songkran|lunar new year)\b|春节|圣诞|中秋|国庆|元旦|新年|夏季|春季|冬季|秋季/iu';

    /** Superlatives and guarantees that need proof. */
    public const SUPERLATIVES = '/\b(best|#\s?1|no\.?\s?1|number one|leading|top[- ]rated|largest|biggest|most trusted|most popular|guaranteed?|unbeatable|official partner|award[- ]winning|melhor(es)?|maior(es)?|l[ií]der|garantid[oa]s?|n[uú]mero 1|mejor(es)?|m[aá]s grande|garantizad[oa]s?|le meilleur|le plus grand|garanti)\b|最好|最大|第一|领先|顶级|保证|唯一/iu';

    /** Numbers that promise money, speed or odds. */
    public const NUMERIC_CLAIM = '/(?:[$€£¥₱₹₫฿]|R\$|US\$|PHP|BRL|USD)\s?\d|\d[\d.,]*\s?(?:%|k\b|mil\b|million|milh[õo]es|millones|万|亿)|\d+\s?(?:x|倍)\b|\b\d+\s?(?:sec(?:ond)?s?|segundos?|min(?:ute)?s?|minutos?|hours?|horas?|分钟|秒|小时)\b|\b(?:bonus|b[oô]nus|rebate|cashback|jackpot|奖金|返水|彩金)\b[^.!?]{0,30}\d|\d[^.!?]{0,20}\b(?:bonus|b[oô]nus|rebate|cashback|jackpot|free spins|giros gr[aá]tis)\b/iu';

    public static function normalize(string $text): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }

    /** A title that is only the brand (or too short to describe the page). */
    public static function weakTitle(?string $title, string $brand): bool
    {
        $t = self::normalize((string) $title);

        return $t === '' || $t === self::normalize($brand) || mb_strlen((string) $title) < 25;
    }

    public static function weakDescription(?string $description, string $brand): bool
    {
        $d = self::normalize((string) $description);

        return mb_strlen((string) $description) < 70 || $d === self::normalize($brand);
    }

    public static function mentions(string $text, string $brand): bool
    {
        $brand = self::normalize($brand);

        return $brand !== '' && str_contains(' '.self::normalize($text).' ', ' '.$brand.' ');
    }

    public static function seasonal(string $text): bool
    {
        return (bool) preg_match(self::SEASONAL, $text);
    }

    /**
     * The claims in $text that the brand facts do not back: money and speed
     * figures, percentages, superlatives and guarantees.
     *
     * @return array<int, string>
     */
    public static function unsupportedClaims(string $text, string $facts): array
    {
        $found = [];
        $facts = mb_strtolower($facts);
        foreach ([self::NUMERIC_CLAIM, self::SUPERLATIVES] as $pattern) {
            if (preg_match_all($pattern, $text, $m)) {
                foreach ($m[0] as $claim) {
                    // Backed when the figure or word itself appears in the brand profile.
                    $core = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}%]+/u', ' ', $claim)));
                    $numbers = preg_match_all('/\d[\d.,]*/', $claim, $n) ? $n[0] : [];
                    $backed = $numbers !== []
                        ? collect($numbers)->every(fn ($num) => self::factNear($facts, $num, $core))
                        : ($core !== '' && str_contains($facts, $core));
                    if (! $backed) {
                        $found[] = trim($claim);
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** A figure is backed when the profile states it next to one of the claim's words ("3 minutes", "bônus de 198"). */
    protected static function factNear(string $facts, string $number, string $claim): bool
    {
        $words = array_filter(preg_split('/[^\p{L}]+/u', $claim), fn ($w) => mb_strlen($w) >= 3);
        if ($words === []) {
            return str_contains($facts, $number);
        }
        for ($pos = mb_strpos($facts, $number); $pos !== false; $pos = mb_strpos($facts, $number, $pos + 1)) {
            $window = mb_substr($facts, max(0, $pos - 30), mb_strlen($number) + 60);
            foreach ($words as $word) {
                if (str_contains($window, mb_substr($word, 0, 5))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Drop the sentences of a plain text that carry unsupported claims. */
    public static function stripClaims(string $text, string $facts): string
    {
        if (self::unsupportedClaims($text, $facts) === []) {
            return $text;
        }
        $sentences = preg_split('/(?<=[.!?。！？])\s+/u', $text) ?: [$text];
        $kept = array_filter($sentences, fn ($s) => self::unsupportedClaims($s, $facts) === []);

        return implode(' – ', array_map('trim', $kept));
    }

    /** Same for HTML: paragraphs and list items with unsupported claims are removed or trimmed. */
    public static function stripClaimsHtml(string $html, string $facts): string
    {
        if (trim($html) === '' || self::unsupportedClaims(strip_tags($html), $facts) === []) {
            return $html;
        }
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?><div id="r">'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//p | //li | //td | //blockquote')) as $node) {
            if ($xpath->query('.//p | .//li', $node)->length || self::unsupportedClaims($node->textContent, $facts) === []) {
                continue;
            }
            $clean = self::stripClaims(trim($node->textContent), $facts);
            if ($clean === '') {
                $node->parentNode?->removeChild($node);
            } else {
                $node->textContent = $clean;
            }
        }
        $root = $dom->getElementById('r');
        $out = '';
        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /** Words left dangling when a brand is taken out of a name ("Games at", "Sobre o", "Hilfe von"). */
    private const CONNECTORS = ['at', 'on', 'in', 'by', 'of', 'for', 'from', 'with', 'the', 'a', 'an', 'to', 'and', '&', '+', 'de', 'do', 'da', 'dos', 'das', 'no', 'na', 'nos', 'nas', 'em', 'o', 'os', 'as', 'e', 'en', 'el', 'la', 'los', 'las', 'del', 'al', 'y', 'con', 'para', 'por', 'von', 'vom', 'bei', 'im', 'am', 'der', 'die', 'das', 'und', 'mit', 'du', 'des', 'le', 'les', 'chez', 'et', 'di', 'della', 'il', 'ed', 'di', 'dan', 'di', 'của', 'tại', 'và'];

    /**
     * A column name with no brand or site name in it: "Jogos – Brand",
     * "Brand | Jogos", "Games at Brand", "Brand Game Lobby", "Brand's News",
     * "关于Brand" all keep only the column's own words. Empty when nothing
     * but the brand was there (the caller then uses the default name).
     */
    public static function bareTitle(string $title, array $names): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        $names = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $names), fn ($n) => mb_strlen($n) >= 2)));
        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $out = $title;
        foreach ($names as $name) {
            // Latin-side boundaries only, so "关于Brand" and "Brand游戏" are found too.
            $out = preg_replace('/(?<![\p{Latin}\p{N}])'.preg_quote($name, '/').'(?:[\'’]s)?(?![\p{Latin}\p{N}])/iu', ' ', $out) ?? $out;
        }
        if ($out === $title) {
            return $title;
        }
        $words = preg_split('/\s+/u', trim(preg_replace('/\s*[-–—|:·•»,]+\s*(?=\s|$)|(?<=^|\s)[-–—|:·•»,]+\s*/u', ' ', $out)), -1, PREG_SPLIT_NO_EMPTY);
        $dangling = fn ($w) => in_array(mb_strtolower(trim($w, '-–—|:·•»,.?!¿¡')), self::CONNECTORS, true) || trim($w, '-–—|:·•»,.?!¿¡') === '';
        while ($words && $dangling($words[0])) {
            array_shift($words);
        }
        while ($words && $dangling(end($words))) {
            array_pop($words);
        }
        $bare = trim(implode(' ', $words));
        if ($bare !== '' && preg_match('/^\p{Ll}/u', $bare) && preg_match('/^\p{Lu}/u', $title)) {
            $bare = mb_strtoupper(mb_substr($bare, 0, 1)).mb_substr($bare, 1);
        }

        return mb_strlen($bare) >= 2 ? $bare : '';
    }

    /** Navigation wants 1–4 words; long SEO titles are cut at their separator. */
    public static function navLabel(string $title, ?string $fallback = null): string
    {
        $title = trim($title);
        if (mb_strlen($title) <= 24 && count(preg_split('/\s+/u', $title)) <= 4) {
            return $title;
        }
        $head = trim(preg_split('/\s+[-–—|:·]\s+|[:|]/u', $title)[0] ?? '');
        if ($head !== '' && mb_strlen($head) <= 24) {
            return $head;
        }

        return $fallback ?? mb_substr($head, 0, 24);
    }

    /** CTA and button labels never ask visitors to sign up or log in: brand sites have no accounts. */
    public static function accountLabel(?string $label): bool
    {
        return $label !== null && (bool) preg_match(ReferenceAnalyzer::ACCOUNT_PATTERN, trim($label));
    }

    /** Visible words of a page's stored blocks. */
    public static function wordCount(array $content): int
    {
        $text = collect($content)->flatten()->filter(fn ($v) => is_string($v))->map(fn ($v) => strip_tags($v))->implode(' ');
        if (preg_match_all('/\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Hangul}|\p{Thai}/u', $text) > mb_strlen($text) / 3) {
            return (int) round(mb_strlen(preg_replace('/\s+/u', '', $text)) / 2);
        }

        return count(preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
    }

    public static function minWords(string $type): int
    {
        if (in_array($type, self::NO_MIN_WORDS, true)) {
            return 0;
        }

        $min = (int) config('platform.page_min_words', 400);

        return $type === 'home' ? (int) round($min * 1.5) : $min;
    }

    /** Overlap of two titles (word Jaccard), used to catch two pages about the same thing. */
    public static function titleOverlap(string $a, string $b, string $brand = ''): float
    {
        $strip = fn ($s) => array_diff(array_unique(explode(' ', self::normalize($s))), array_merge([''], explode(' ', self::normalize($brand))));
        $x = $strip($a);
        $y = $strip($b);
        if ($x === [] || $y === []) {
            return 0.0;
        }

        return count(array_intersect($x, $y)) / count(array_unique(array_merge($x, $y)));
    }
}
