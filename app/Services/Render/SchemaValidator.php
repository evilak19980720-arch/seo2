<?php

namespace App\Services\Render;

/**
 * Checks a built page's JSON-LD the way Google's rich-result rules need it:
 * valid JSON, and the required properties of the types we emit.
 */
class SchemaValidator
{
    /** type => required properties */
    public const REQUIRED = [
        'Organization' => ['name', 'url'],
        'WebSite' => ['name', 'url'],
        'WebPage' => ['url', 'name'],
        'BreadcrumbList' => ['itemListElement'],
        'Article' => ['headline', 'datePublished', 'author'],
        'FAQPage' => ['mainEntity'],
        'VideoGame' => ['name', 'url'],
        'SoftwareApplication' => ['name'],
        'Product' => ['name'],
    ];

    /** @return array<int, string> problems found (empty = fine) */
    public static function check(string $html): array
    {
        if (! preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m)) {
            return ['没有结构化数据'];
        }
        $problems = [];
        foreach ($m[1] as $json) {
            $data = json_decode($json, true);
            if (! is_array($data)) {
                $problems[] = '结构化数据不是有效的 JSON';

                continue;
            }
            foreach ($data['@graph'] ?? [$data] as $node) {
                $type = $node['@type'] ?? '?';
                foreach (self::REQUIRED[$type] ?? [] as $prop) {
                    if (empty($node[$prop])) {
                        $problems[] = "{$type} 缺少 {$prop}";
                    }
                }
                if ($type === 'FAQPage') {
                    foreach ($node['mainEntity'] ?? [] as $q) {
                        if (empty($q['name']) || empty($q['acceptedAnswer']['text'])) {
                            $problems[] = 'FAQPage 有问题缺少问题或答案';
                            break;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * Check the built home page, one inner page and the newest article of a site.
     *
     * @return array<string, array<int, string>> path => problems
     */
    public static function checkSite(string $dir, array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $file = rtrim($dir, '/').'/'.(trim($path, '/') === '' ? '' : trim($path, '/').'/').'index.html';
            if (is_file($file) && ($problems = self::check((string) file_get_contents($file)))) {
                $out[$path === '' ? '/' : $path] = $problems;
            }
        }

        return $out;
    }
}
