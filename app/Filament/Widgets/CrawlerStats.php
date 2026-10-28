<?php

namespace App\Filament\Widgets;

use App\Models\AdStat;
use App\Models\AnalyticsCache;
use App\Models\CrawlerStat;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CrawlerStats extends StatsOverviewWidget
{
    use Concerns, InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected ?string $heading = '蜘蛛、访问与广告';

    protected function getStats(): array
    {
        $since = now()->subDays($this->days())->toDateString();
        $crawl = CrawlerStat::query()->where('date', '>', $since)->when($this->siteId(), fn ($q, $id) => $q->where('site_id', $id));
        $ga = AnalyticsCache::query()->where('date', '>', $since)->when($this->siteId(), fn ($q, $id) => $q->where('site_id', $id));
        $ads = AdStat::query()->where('date', '>', $since)->when($this->siteId(), fn ($q, $id) => $q->where('site_id', $id));

        return [
            Stat::make('Googlebot 抓取', number_format((int) (clone $crawl)->where('bot', 'googlebot')->sum('hits')))
                ->description('伪造 UA '.(int) (clone $crawl)->where('bot', 'googlebot')->sum('fake_hits')),
            Stat::make('Bingbot 抓取', number_format((int) (clone $crawl)->where('bot', 'bingbot')->sum('hits'))),
            Stat::make('抓取错误', (int) (clone $crawl)->sum('errors_4xx').' / '.(int) (clone $crawl)->sum('errors_5xx'))->description('4xx / 5xx'),
            Stat::make('访问用户', number_format((int) (clone $ga)->sum('users')))->description('GA4 · 浏览 '.number_format((int) (clone $ga)->sum('pageviews'))),
            Stat::make('广告展示', number_format((int) (clone $ads)->sum('impressions'))),
            Stat::make('广告点击（估算）', number_format((int) (clone $ads)->sum('estimated_clicks')))->description('以广告平台后台数据为准'),
        ];
    }
}
