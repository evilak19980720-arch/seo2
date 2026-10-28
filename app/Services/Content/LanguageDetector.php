<?php

namespace App\Services\Content;

/**
 * Stopword-frequency language guess, good enough to catch an article that
 * came back in the wrong language (e.g. English or Spanish instead of pt-BR).
 */
class LanguageDetector
{
    protected const STOPWORDS = [
        'pt' => ['de', 'que', 'não', 'para', 'com', 'uma', 'os', 'no', 'se', 'na', 'por', 'mais', 'as', 'dos', 'como', 'mas', 'você', 'ao', 'das', 'seu', 'sua', 'também', 'pode', 'são', 'está', 'jogo', 'isso', 'muito', 'ou', 'quando'],
        'es' => ['de', 'que', 'el', 'la', 'los', 'del', 'las', 'por', 'un', 'para', 'con', 'no', 'una', 'su', 'al', 'lo', 'como', 'más', 'pero', 'sus', 'le', 'ya', 'o', 'este', 'sí', 'porque', 'esta', 'entre', 'cuando', 'muy'],
        'en' => ['the', 'and', 'to', 'of', 'a', 'in', 'is', 'you', 'that', 'it', 'for', 'on', 'with', 'as', 'are', 'this', 'be', 'your', 'can', 'or', 'by', 'from', 'at', 'have', 'an', 'will', 'not', 'more', 'all', 'when'],
        'fr' => ['de', 'la', 'le', 'et', 'les', 'des', 'en', 'un', 'du', 'une', 'que', 'est', 'pour', 'qui', 'dans', 'par', 'plus', 'pas', 'au', 'sur', 'ne', 'se', 'ce', 'il', 'sont', 'vous', 'avec', 'mais', 'nous', 'ou'],
        'id' => ['yang', 'dan', 'di', 'ini', 'dengan', 'untuk', 'tidak', 'dari', 'dalam', 'akan', 'pada', 'juga', 'ke', 'karena', 'ada', 'itu', 'bisa', 'anda', 'atau', 'kami'],
        'de' => ['der', 'die', 'und', 'in', 'den', 'von', 'zu', 'das', 'mit', 'sich', 'des', 'auf', 'für', 'ist', 'im', 'dem', 'nicht', 'ein', 'eine', 'als', 'auch', 'es', 'an', 'werden', 'aus', 'er', 'hat', 'dass', 'sie', 'nach'],
    ];

    public static function detect(string $text): ?string
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 30) {
            return null;
        }
        $counts = array_count_values($words);
        $scores = [];
        foreach (self::STOPWORDS as $lang => $list) {
            $scores[$lang] = array_sum(array_map(fn ($w) => $counts[$w] ?? 0, $list)) / count($words);
        }
        arsort($scores);

        return reset($scores) > 0.05 ? array_key_first($scores) : null;
    }

    /** True when the text is (probably) in $lang, or the language is not covered. */
    public static function matches(string $text, string $lang): bool
    {
        // CJK text on a site in a Latin-script language (e.g. a profile typed in Chinese).
        if (! in_array($lang, ['zh', 'ja', 'ko'], true)) {
            $letters = preg_match_all('/\p{L}/u', $text);
            $cjk = preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text);
            if ($letters > 0 && $cjk / $letters > 0.2) {
                return false;
            }
        }
        if (! isset(self::STOPWORDS[$lang])) {
            return true;
        }
        $detected = self::detect($text);

        return $detected === null || $detected === $lang;
    }
}
