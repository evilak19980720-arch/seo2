<?php

namespace App\Services\Render;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Whitelist sanitizer for AI / rich-editor article HTML. Only structural
 * content tags survive; scripts, styles, iframes and event attributes go.
 */
class HtmlSanitizer
{
    protected const TAGS = [
        'p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'a', 'blockquote', 'br',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img', 'figure', 'figcaption', 'code', 'pre', 'hr',
    ];

    protected const ATTRS = ['a' => ['href', 'title'], 'img' => ['src', 'alt', 'width', 'height'], 'th' => ['scope'], 'td' => ['colspan', 'rowspan']];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $doc->getElementById('__root');
        if (! $root) {
            return e(strip_tags($html));
        }
        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    protected static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if ($tag === 'h1') {
                    $tag = 'h2';
                    $child = self::rename($child, 'h2');
                }
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'noscript', 'svg'], true)) {
                    $node->removeChild($child);

                    continue;
                }
                if (! in_array($tag, self::TAGS, true)) {
                    self::walk($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    $allowed = self::ATTRS[$tag] ?? [];
                    $value = trim($attr->value);
                    if (! in_array($name, $allowed, true) || (in_array($name, ['href', 'src'], true) && preg_match('/^\s*(javascript|data|vbscript):/i', $value))) {
                        $child->removeAttribute($attr->name);
                    }
                }
                if ($tag === 'img') {
                    $child->setAttribute('loading', 'lazy');
                    $child->setAttribute('decoding', 'async');
                }
                self::walk($child);
            }
        }
    }

    protected static function rename(DOMElement $el, string $tag): DOMElement
    {
        $new = $el->ownerDocument->createElement($tag);
        while ($el->firstChild) {
            $new->appendChild($el->firstChild);
        }
        $el->parentNode->replaceChild($new, $el);

        return $new;
    }
}
