<?php

namespace App\Services\Integrations;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;

/** IndexNow (Bing, Yandex, …) and Bing Webmaster sitemap submission. */
class IndexNow
{
    public function submit(string $host, array $urls): bool
    {
        $key = Settings::get('indexnow_key');
        $urls = array_values(array_filter($urls, fn ($u) => ! str_ends_with($u, '.xml')));
        if (! $key || $urls === []) {
            return false;
        }

        $res = Http::timeout(20)->post('https://api.indexnow.org/indexnow', [
            'host' => $host,
            'key' => $key,
            'keyLocation' => "https://{$host}/{$key}.txt",
            'urlList' => array_slice($urls, 0, 10000),
        ]);

        return $res->successful() || $res->status() === 202;
    }

    public function bingSubmitSitemap(string $siteUrl, string $sitemapUrl): bool
    {
        $apiKey = Settings::get('bing_api_key');
        if (! $apiKey) {
            return false;
        }

        return Http::timeout(20)
            ->post('https://ssl.bing.com/webmaster/api.svc/json/SubmitFeed?apikey='.urlencode($apiKey), ['siteUrl' => $siteUrl, 'feedUrl' => $sitemapUrl])
            ->successful();
    }
}
