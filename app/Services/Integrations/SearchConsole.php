<?php

namespace App\Services\Integrations;

/**
 * Site Verification + Search Console APIs. Properties are domain properties
 * (sc-domain:example.com) verified by a DNS TXT record written via Cloudflare.
 */
class SearchConsole
{
    public static function property(string $domain): string
    {
        return 'sc-domain:'.$domain;
    }

    public function verificationToken(string $domain): string
    {
        $res = GoogleAuth::http()->post('https://www.googleapis.com/siteVerification/v1/token', [
            'site' => ['type' => 'INET_DOMAIN', 'identifier' => $domain],
            'verificationMethod' => 'DNS_TXT',
        ]);
        $this->ensure($res, 'verification token');

        return $res->json('token');
    }

    public function verify(string $domain): bool
    {
        $res = GoogleAuth::http()->post('https://www.googleapis.com/siteVerification/v1/webResource?verificationMethod=DNS_TXT', [
            'site' => ['type' => 'INET_DOMAIN', 'identifier' => $domain],
        ]);

        return $res->successful();
    }

    public function addSite(string $domain): void
    {
        $res = GoogleAuth::http()->put('https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode(self::property($domain)));
        $this->ensure($res, 'add site');
    }

    public function submitSitemap(string $domain, string $sitemapUrl): void
    {
        $res = GoogleAuth::http()->put('https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode(self::property($domain)).'/sitemaps/'.rawurlencode($sitemapUrl));
        $this->ensure($res, 'submit sitemap');
    }

    /**
     * @param  array<int, string>  $dimensions  e.g. ['date'], ['query'], ['page']
     * @return array<int, array{keys: array, clicks: float, impressions: float, ctr: float, position: float}>
     */
    public function query(string $domain, string $start, string $end, array $dimensions, int $rowLimit = 5000): array
    {
        $res = GoogleAuth::http()->post('https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode(self::property($domain)).'/searchAnalytics/query', [
            'startDate' => $start,
            'endDate' => $end,
            'dimensions' => $dimensions,
            'rowLimit' => $rowLimit,
            'dataState' => 'final',
        ]);
        $this->ensure($res, 'search analytics');

        return $res->json('rows') ?? [];
    }

    /** URL Inspection: returns coverage verdict (PASS / NEUTRAL / FAIL ...). */
    public function inspect(string $domain, string $url): ?string
    {
        $res = GoogleAuth::http()->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
            'inspectionUrl' => $url,
            'siteUrl' => self::property($domain),
        ]);
        if (! $res->successful()) {
            return null;
        }

        return $res->json('inspectionResult.indexStatusResult.verdict');
    }

    protected function ensure($res, string $what): void
    {
        if (! $res->successful()) {
            throw new IntegrationException("Search Console {$what} failed: ".$res->body());
        }
    }
}
