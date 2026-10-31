<?php

namespace App\Services\Render;

use DOMDocument;
use DOMText;
use DOMXPath;

/**
 * Inserts contextual internal links: first occurrence of each target phrase
 * inside paragraph/list text (never inside existing links or headings).
 */
class InternalLinker
{
    /**
     * @param  array<string, string>  $targets  phrase => absolute or root-relative URL
     */
    public static function link(string $html, array $targets, int $max = 5): array
    {
        $linked = [];
        if ($html === '' || $targets === []) {
            return [$html, $linked];
        }

        // Longer phrases first so "como jogar xyz" wins over "xyz".
        uksort($targets, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        foreach ($targets as $phrase => $url) {
            if (count($linked) >= $max || mb_strlen($phrase) < 3) {
                continue;
            }
            $pattern = '/(?<![\p{L}\p{N}])('.preg_quote($phrase, '/').')(?![\p{L}\p{N}])/iu';
            foreach ($xpath->query('//*[@id="__root"]//*[self::p or self::li]//text()[not(ancestor::a) and not(ancestor::h2) and not(ancestor::h3)]') as $text) {
                /** @var DOMText $text */
                if (! preg_match($pattern, $text->nodeValue, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $offset = mb_strlen(substr($text->nodeValue, 0, $m[1][1]));
                $length = mb_strlen($m[1][0]);
                $match = $text->splitText($offset);
                $match->splitText($length);
                $a = $doc->createElement('a');
                $a->setAttribute('href', $url);
                $a->appendChild($doc->createTextNode($match->nodeValue));
                $match->parentNode->replaceChild($a, $match);
                $linked[] = $url;

                break;
            }
        }

        $out = '';
        foreach ($doc->getElementById('__root')->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return [trim($out), array_values(array_unique($linked))];
    }
}
