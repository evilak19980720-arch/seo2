<?php

namespace App\Services\Stats;

use App\Enums\ArticleStatus;
use App\Enums\KeywordStatus;
use App\Models\Alert;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\SearchStat;
use App\Models\Site;
use App\Services\Integrations\SearchConsole;

/** Daily Search Console pull: site totals, per-article impressions, keyword backflow, index sampling. */
class SearchConsoleSync
{
    public function __construct(protected SearchConsole $gsc) {}

    public function syncDaily(Site $site, int $days = 10): void
    {
        $domain = $site->domain->name;
        // GSC data lags ~2-3 days; re-pull a window so late rows are filled.
        $end = now()->subDays(2)->toDateString();
        $start = now()->subDays($days + 2)->toDateString();

        foreach ($this->gsc->query($domain, $start, $end, ['date']) as $row) {
            SearchStat::updateOrCreate(['site_id' => $site->id, 'date' => $row['keys'][0]], [
                'impressions' => (int) $row['impressions'],
                'clicks' => (int) $row['clicks'],
                'ctr' => round($row['ctr'], 4),
                'position' => round($row['position'], 2),
            ]);
        }

        $last7 = $site->searchStats()->where('date', '>', now()->subDays(9)->toDateString())->get();
        $site->update(['impressions_7d' => (int) $last7->sum('impressions'), 'clicks_7d' => (int) $last7->sum('clicks')]);

        // Per-URL impressions → articles (30d and lifetime-ish total).
        $byPage = collect($this->gsc->query($domain, now()->subDays(32)->toDateString(), $end, ['page']))
            ->mapWithKeys(fn ($r) => [rtrim(parse_url($r['keys'][0], PHP_URL_PATH) ?? '', '/') => (int) $r['impressions']]);
        $site->articles()->whereIn('status', [ArticleStatus::Published->value, ArticleStatus::Noindex->value])->each(function (Article $a) use ($byPage) {
            $imp = $byPage['/'.$a->path()] ?? 0;
            $a->forceFill([
                'impressions_30d' => $imp,
                // Highest 30-day window seen; zero means "never had impressions".
                'impressions_total' => max($a->impressions_total, $imp),
            ])->saveQuietly();
        });
    }

    /** Weekly: real search queries flow back into the keyword library. */
    public function syncKeywords(Site $site): int
    {
        $rows = $this->gsc->query($site->domain->name, now()->subDays(30)->toDateString(), now()->subDays(2)->toDateString(), ['query'], 1000);
        $created = 0;
        foreach ($rows as $row) {
            $query = mb_strtolower(trim($row['keys'][0]));
            if (mb_strlen($query) < 3 || mb_strlen($query) > 120) {
                continue;
            }
            $kw = Keyword::firstOrNew(['site_id' => $site->id, 'keyword' => $query]);
            $created += $kw->exists ? 0 : 1;
            $kw->fill([
                'impressions' => (int) $row['impressions'],
                'clicks' => (int) $row['clicks'],
                'source' => $kw->exists ? $kw->source : 'gsc',
                'intent' => $kw->intent ?? 'informational',
            ]);
            if ($kw->exists && $kw->status === KeywordStatus::Written && $row['impressions'] > 0) {
                $kw->status = KeywordStatus::HasImpressions;
            }
            $kw->save();
        }

        return $created;
    }

    /**
     * Raise an alert when Google keeps most of a settled site out of its index
     * ("Crawled - currently not indexed" usually means thin or duplicate pages).
     *
     * @param  array<string, int>  $coverage  coverage state => count among non-indexed URLs
     */
    public function alertCoverage(Site $site, int $checked, array $coverage): void
    {
        $failed = array_sum($coverage);
        $settled = $site->launched_at && $site->launched_at->lt(now()->subDays(21));
        if (! $settled || $checked < 10 || $failed / max(1, $checked) < 0.4) {
            return;
        }
        arsort($coverage);
        $reasons = collect($coverage)->take(3)->map(fn ($n, $state) => "{$state} ×{$n}")->implode('；');
        Alert::raise('index_coverage', "站点 {$site->name} 抽查 {$checked} 个网址，{$failed} 个未被收录：{$reasons}。常见原因是内容单薄或与其他页面重复。", $site->id);
    }

    /** URL Inspection sampling (quota ~2000/day/property): oldest-checked first. */
    public function sampleIndexStatus(Site $site, int $limit = 60): void
    {
        $domain = $site->domain->name;
        $items = $site->pages()->where('noindex', false)->orderByRaw('index_checked_at is not null, index_checked_at')->limit(intdiv($limit, 3))->get()
            ->concat($site->articles()->where('status', ArticleStatus::Published->value)->orderByRaw('index_checked_at is not null, index_checked_at')->limit($limit)->get())
            ->take($limit);

        $coverage = [];
        foreach ($items as $item) {
            $path = $item instanceof Article ? $item->path() : $item->slug;
            $detail = $this->gsc->inspectDetail($domain, $site->url($path));
            if (($detail['verdict'] ?? null) !== null) {
                $item->forceFill(['index_verdict' => $detail['verdict'], 'index_checked_at' => now()])->saveQuietly();
                if ($detail['verdict'] !== 'PASS' && filled($detail['coverage'])) {
                    $coverage[$detail['coverage']] = ($coverage[$detail['coverage']] ?? 0) + 1;
                }
            }
        }
        $this->alertCoverage($site, $items->count(), $coverage);

        $indexed = $site->pages()->where('index_verdict', 'PASS')->count()
            + $site->articles()->where('status', ArticleStatus::Published->value)->where('index_verdict', 'PASS')->count();
        $site->update(['indexed_pages' => $indexed]);
        SearchStat::updateOrCreate(['site_id' => $site->id, 'date' => now()->toDateString()], ['indexed_pages' => $indexed]);
    }
}
