<?php

namespace App\Services\Stats;

use App\Enums\ArticleStatus;
use App\Jobs\RewriteArticleJob;
use App\Models\Alert;
use App\Models\Site;
use App\Services\Content\PublishScheduler;
use App\Support\Settings;

/** Cold-content handling and the per-site circuit breaker. */
class ContentHealth
{
    public function __construct(protected PublishScheduler $scheduler) {}

    /**
     * 90 days with zero impressions → noindex + rewrite queue;
     * still zero 90 days after that → 410.
     */
    public function handleColdContent(Site $site): array
    {
        $days = (int) Settings::get('cold_days', 90);
        $result = ['noindexed' => 0, 'deleted' => 0];

        $site->articles()->where('status', ArticleStatus::Published->value)
            ->where('published_at', '<', now()->subDays($days))->whereNull('noindexed_at')
            ->where('impressions_total', 0)
            ->each(function ($article) use (&$result) {
                $article->update(['status' => ArticleStatus::Noindex, 'noindexed_at' => now()]);
                $this->scheduler->afterChange($article);
                RewriteArticleJob::dispatch($article);
                $result['noindexed']++;
            });

        $site->articles()->whereIn('status', [ArticleStatus::Noindex->value, ArticleStatus::Published->value])
            ->whereNotNull('noindexed_at')->where('noindexed_at', '<', now()->subDays($days))
            ->where('impressions_30d', 0)->where('impressions_total', 0)
            ->each(function ($article) use (&$result) {
                $article->update(['status' => ArticleStatus::Deleted]);
                $this->scheduler->afterChange($article);
                $result['deleted']++;
            });

        return $result;
    }

    /** Pause automatic publishing when impressions or indexed pages drop sharply. */
    public function circuitBreaker(Site $site): ?string
    {
        $threshold = (float) Settings::get('drop_threshold', 0.5);
        $stats = $site->searchStats()->where('date', '>=', now()->subDays(20)->toDateString())->orderBy('date')->get();
        $recent = $stats->where('date', '>', now()->subDays(9));
        $previous = $stats->where('date', '<=', now()->subDays(9))->where('date', '>', now()->subDays(16));

        $reason = null;
        $prevImp = $previous->sum('impressions');
        if ($prevImp >= 100 && $recent->sum('impressions') < $prevImp * (1 - $threshold)) {
            $reason = sprintf('7 日展示从 %d 降到 %d', $prevImp, $recent->sum('impressions'));
        }
        $indexNow = $stats->whereNotNull('indexed_pages')->last()?->indexed_pages;
        $indexBefore = $stats->whereNotNull('indexed_pages')->where('date', '<=', now()->subDays(7))->last()?->indexed_pages;
        if ($indexBefore >= 10 && $indexNow !== null && $indexNow < $indexBefore * (1 - $threshold)) {
            $reason = trim(($reason ? $reason.'；' : '')."已索引页数从 {$indexBefore} 降到 {$indexNow}");
        }

        if ($reason && ! $site->auto_publish_paused) {
            $site->update(['auto_publish_paused' => true, 'pause_reason' => '熔断：'.$reason]);
            Alert::raise('traffic_drop', "站点 {$site->name} 触发熔断，已暂停自动发布：{$reason}", $site->id, 'danger');
        }

        return $reason;
    }
}
