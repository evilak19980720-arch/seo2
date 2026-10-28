<?php

namespace App\Services\Stats;

use App\Models\CrawlerStat;
use App\Models\Domain;
use Illuminate\Support\Facades\Cache;

/**
 * Parses the Nginx access log (platform log_format "sites") for Googlebot /
 * Bingbot hits, verified by reverse + forward DNS, plus 4xx/5xx counts.
 * Format: $host $remote_addr [$time_local] "$request" $status $body_bytes_sent "$http_user_agent"
 */
class CrawlerLogParser
{
    public const BOTS = [
        'googlebot' => ['ua' => '/Googlebot|Google-InspectionTool|GoogleOther/i', 'hosts' => ['.googlebot.com', '.google.com', '.googleusercontent.com']],
        'bingbot' => ['ua' => '/bingbot|BingPreview/i', 'hosts' => ['.search.msn.com']],
    ];

    public function parse(string $file, int $fromOffset = 0): int
    {
        if (! is_readable($file)) {
            return $fromOffset;
        }
        if (filesize($file) < $fromOffset) {
            $fromOffset = 0; // log rotated
        }
        $fh = fopen($file, 'r');
        fseek($fh, $fromOffset);
        $siteIds = Domain::query()->whereHas('site')->with('site:id,domain_id')->get()->mapWithKeys(fn ($d) => [$d->name => $d->site->id]);

        $agg = [];
        while (($line = fgets($fh)) !== false) {
            if (! preg_match('/^(\S+) (\S+) \[([^\]]+)\] "[^"]*" (\d{3}) \S+ "([^"]*)"/', $line, $m)) {
                continue;
            }
            [, $host, $ip, $time, $status, $ua] = $m;
            $host = preg_replace('/^www\./', '', strtolower($host));
            $siteId = $siteIds[$host] ?? null;
            if (! $siteId) {
                continue;
            }
            foreach (self::BOTS as $bot => $def) {
                if (! preg_match($def['ua'], $ua)) {
                    continue;
                }
                $date = date('Y-m-d', strtotime(str_replace('/', ' ', preg_replace('/:/', ' ', $time, 1))) ?: time());
                $key = "{$siteId}|{$date}|{$bot}";
                $agg[$key] ??= ['hits' => 0, 'fake_hits' => 0, 'errors_4xx' => 0, 'errors_5xx' => 0];
                if (! $this->verified($ip, $def['hosts'])) {
                    $agg[$key]['fake_hits']++;
                    break;
                }
                $agg[$key]['hits']++;
                $status >= 500 ? $agg[$key]['errors_5xx']++ : ($status >= 400 ? $agg[$key]['errors_4xx']++ : null);
                break;
            }
        }
        $offset = ftell($fh);
        fclose($fh);

        foreach ($agg as $key => $counts) {
            [$siteId, $date, $bot] = explode('|', $key);
            $stat = CrawlerStat::firstOrCreate(['site_id' => $siteId, 'date' => $date, 'bot' => $bot]);
            foreach ($counts as $col => $n) {
                $stat->increment($col, $n);
            }
        }

        return $offset;
    }

    public function verified(string $ip, array $suffixes): bool
    {
        return Cache::remember("crawler-ip:{$ip}", 86400, function () use ($ip, $suffixes) {
            $host = @gethostbyaddr($ip);
            if (! $host || $host === $ip) {
                return false;
            }
            $ok = collect($suffixes)->contains(fn ($s) => str_ends_with(strtolower($host), $s));
            if (! $ok) {
                return false;
            }
            $forward = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                ? collect(dns_get_record($host, DNS_AAAA) ?: [])->pluck('ipv6')->map(fn ($v) => inet_pton($v))->contains(inet_pton($ip))
                : in_array($ip, gethostbynamel($host) ?: [], true);

            return $forward;
        });
    }
}
