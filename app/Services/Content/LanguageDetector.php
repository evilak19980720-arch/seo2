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
        'fil' => ['ang', 'ng', 'sa', 'na', 'mga', 'ay', 'at', 'ito', 'ka', 'mo', 'kung', 'hindi', 'lang', 'po', 'din', 'rin', 'naman', 'ko', 'namin', 'natin', 'siya', 'kayo', 'may', 'wala', 'pa', 'ba', 'yung', 'nang', 'iyong', 'inyong'],
        'vi' => ['của', 'và', 'các', 'có', 'được', 'trong', 'cho', 'không', 'người', 'là', 'những', 'với', 'một', 'này', 'để', 'bạn', 'khi', 'đã', 'từ', 'chơi', 'trò', 'sẽ', 'cũng', 'như', 'hoặc'],
        'ms' => ['yang', 'dan', 'di', 'ini', 'dengan', 'untuk', 'tidak', 'dari', 'dalam', 'akan', 'pada', 'juga', 'ke', 'kerana', 'ada', 'itu', 'boleh', 'anda', 'atau', 'kami'],
        'it' => ['di', 'che', 'il', 'la', 'per', 'un', 'una', 'non', 'con', 'del', 'della', 'le', 'gli', 'sono', 'più', 'come', 'anche', 'nel', 'alla', 'questo', 'ma', 'puoi', 'tuo', 'essere', 'dei'],
        'nl' => ['de', 'het', 'een', 'van', 'en', 'in', 'is', 'dat', 'op', 'te', 'voor', 'met', 'zijn', 'niet', 'je', 'ook', 'aan', 'om', 'als', 'bij', 'worden', 'kan', 'jouw', 'deze', 'naar'],
        'pl' => ['i', 'w', 'na', 'nie', 'z', 'się', 'do', 'to', 'że', 'jest', 'o', 'jak', 'ale', 'po', 'co', 'tak', 'za', 'od', 'dla', 'może', 'są', 'lub', 'ten', 'przez', 'twoje'],
        'tr' => ['ve', 'bir', 'bu', 'için', 'ile', 'de', 'da', 'çok', 'daha', 'olan', 'gibi', 'ne', 'en', 'olarak', 'sonra', 'kadar', 'her', 'veya', 'ama', 'sizin', 'oyun', 'değil', 'var', 'mi', 'ise'],
    ];

    /** Languages written in their own script: the text must be mostly in it. */
    protected const SCRIPTS = [
        'th' => '\p{Thai}', 'ar' => '\p{Arabic}', 'fa' => '\p{Arabic}', 'hi' => '\p{Devanagari}', 'ru' => '\p{Cyrillic}', 'uk' => '\p{Cyrillic}',
        'ja' => '\p{Hiragana}|\p{Katakana}|\p{Han}', 'ko' => '\p{Hangul}', 'zh' => '\p{Han}', 'el' => '\p{Greek}', 'he' => '\p{Hebrew}',
    ];

    /** Codes that are the same language for this purpose. */
    protected const ALIASES = ['tl' => 'fil', 'in' => 'id'];

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

    /**
     * True when the text is (probably) in $lang. Script languages (Thai,
     * Arabic, Chinese…) need most letters in their script; Latin-script
     * languages must not be mostly another script, and are told apart by
     * their common words. Too little text counts as a match.
     */
    public static function matches(string $text, string $lang): bool
    {
        $lang = self::ALIASES[$lang] ?? $lang;
        $letters = preg_match_all('/\p{L}/u', $text);
        if (isset(self::SCRIPTS[$lang])) {
            // Brand and game names stay in Latin letters, so a share is enough.
            return $letters < 12 || preg_match_all('/'.self::SCRIPTS[$lang].'/u', $text) / $letters >= 0.35;
        }
        $foreign = preg_match_all('/[^\p{Latin}\P{L}]/u', $text);
        if ($letters > 0 && $foreign / $letters > 0.2) {
            return false;
        }
        if (! isset(self::STOPWORDS[$lang])) {
            return true;
        }
        $detected = self::detect($text);

        // Malay and Indonesian share most common words.
        return $detected === null || $detected === $lang || (in_array($lang, ['ms', 'id'], true) && in_array($detected, ['ms', 'id'], true));
    }
}
