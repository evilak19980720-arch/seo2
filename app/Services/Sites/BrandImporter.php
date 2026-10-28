<?php

namespace App\Services\Sites;

use App\Enums\PromptType;
use App\Models\Site;
use App\Services\Ai\AiManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Builds a structured brand profile from a store/product URL, a PDF or
 * images using a vision-capable model, and localizes a related site's profile.
 */
class BrandImporter
{
    public function __construct(protected AiManager $ai) {}

    /**
     * @param  array<int, string>  $files  public-disk paths (images or PDF)
     */
    public function import(Site $site, ?string $url, array $files = []): array
    {
        $material = '';
        $images = [];

        if ($url) {
            $res = Http::timeout(30)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; BrandImporter/1.0)', 'Accept-Language' => $site->locale])->get($url);
            if ($res->successful()) {
                $html = $res->body();
                preg_match_all('/<meta[^>]+(?:name|property)="(?:description|og:description|og:title)"[^>]+content="([^"]*)"/i', $html, $meta);
                $text = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', ' ', $html);
                $material .= "SOURCE URL: {$url}\n".implode("\n", $meta[1] ?? [])."\n".mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text)))), 0, 15000)."\n";
                preg_match_all('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $html, $og);
                $images = array_slice($og[1] ?? [], 0, 2);
            }
        }

        foreach ($files as $path) {
            $abs = Storage::disk('public')->path($path);
            $mime = mime_content_type($abs) ?: '';
            if (str_starts_with($mime, 'image/')) {
                $images[] = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($abs));
            } elseif ($mime === 'application/pdf') {
                $p = new Process(['pdftotext', '-layout', $abs, '-']);
                $p->run();
                $material .= "\nPDF:\n".mb_substr($p->getOutput(), 0, 15000);
            }
        }

        $data = $this->ai->prompt(PromptType::BrandExtract, ['material' => $material ?: '(see images)'], $site, array_slice($images, 0, 6))->json();

        return $this->normalize($data);
    }

    /** Copy a related site's profile into this site's language (then maintained separately). */
    public function localizeFrom(Site $site, Site $source): array
    {
        $profile = $source->brandProfile?->only([
            'product_name', 'tagline', 'description', 'selling_points', 'specs', 'target_audience', 'use_cases',
            'cta_links', 'faq', 'company_name', 'company_email', 'company_address',
        ]) ?? [];
        $data = $this->ai->prompt(PromptType::BrandTranslate, ['profile' => json_encode($profile, JSON_UNESCAPED_UNICODE)], $site)->json();

        return $this->normalize($data) + ['media' => $source->brandProfile?->media ?? []];
    }

    protected function normalize(array $d): array
    {
        $list = fn ($v) => array_values(array_filter(is_array($v) ? $v : [], fn ($x) => filled($x)));

        return [
            'product_name' => $d['product_name'] ?? null,
            'tagline' => $d['tagline'] ?? null,
            'description' => $d['description'] ?? null,
            'selling_points' => array_map('strval', array_filter($list($d['selling_points'] ?? []), 'is_string')),
            'specs' => array_values(array_filter($list($d['specs'] ?? []), fn ($s) => is_array($s) && filled($s['key'] ?? null))),
            'target_audience' => $d['target_audience'] ?? null,
            'use_cases' => is_array($d['use_cases'] ?? null) ? implode("\n", $d['use_cases']) : ($d['use_cases'] ?? null),
            'cta_links' => array_values(array_filter($list($d['cta_links'] ?? []), fn ($l) => is_array($l) && filter_var($l['url'] ?? '', FILTER_VALIDATE_URL))),
            'faq' => array_values(array_filter($list($d['faq'] ?? []), fn ($f) => is_array($f) && filled($f['question'] ?? null))),
            'company_name' => $d['company_name'] ?? null,
            'company_email' => filter_var($d['company_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
            'company_address' => $d['company_address'] ?? null,
        ];
    }
}
