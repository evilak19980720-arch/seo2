<?php

namespace App\Filament\Widgets;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\SearchStat;
use App\Models\Site;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SearchOverview extends StatsOverviewWidget
{
    use Concerns, InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $stats = SearchStat::query()->where('date', '>', $this->since())
            ->when($this->siteId(), fn ($q, $id) => $q->where('site_id', $id));
        $impressions = (int) (clone $stats)->sum('impressions');
        $clicks = (int) (clone $stats)->sum('clicks');
        $position = (clone $stats)->where('impressions', '>', 0)->avg('position');

        $sites = Site::query()->when($this->siteId(), fn ($q, $id) => $q->whereKey($id));
        $articles = Article::query()->when($this->siteId(), fn ($q, $id) => $q->where('site_id', $id));

        return [
            Stat::make('展示', number_format($impressions))->description("近 {$this->days()} 日（Search Console）"),
            Stat::make('点击', number_format($clicks))->description('CTR '.($impressions ? round($clicks / $impressions * 100, 2) : 0).'%'),
            Stat::make('平均排名', $position ? number_format($position, 1) : '—'),
            Stat::make('已索引页数', number_format((int) (clone $sites)->sum('indexed_pages')))->description('URL 检查抽样'),
            Stat::make('站点', (clone $sites)->count())->description((clone $sites)->where('status', 'live')->count().' 个已上线'),
            Stat::make('文章', (clone $articles)->where('status', ArticleStatus::Published->value)->count())
                ->description('待发布 '.(clone $articles)->where('status', ArticleStatus::Queued->value)->count().' · 被拦截 '.(clone $articles)->where('status', ArticleStatus::Blocked->value)->count()),
        ];
    }
}
