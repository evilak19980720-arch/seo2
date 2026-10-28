<?php

namespace App\Filament\Widgets;

use App\Models\SearchStat;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;

class SearchTrendChart extends ChartWidget
{
    use Concerns, InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected ?string $heading = '搜索表现趋势';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $rows = SearchStat::query()
            ->selectRaw('date, sum(impressions) as impressions, sum(clicks) as clicks')
            ->where('date', '>', now()->subDays(max(30, $this->days()) + 2)->toDateString())
            ->when($this->siteId(), fn ($q, $id) => $q->where('site_id', $id))
            ->groupBy('date')->orderBy('date')->get();

        return [
            'datasets' => [
                ['label' => '展示', 'data' => $rows->pluck('impressions')->map(fn ($v) => (int) $v)->all(), 'yAxisID' => 'y'],
                ['label' => '点击', 'data' => $rows->pluck('clicks')->map(fn ($v) => (int) $v)->all(), 'yAxisID' => 'y1'],
            ],
            'labels' => $rows->pluck('date')->map(fn ($d) => Carbon::parse($d)->format('m-d'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['position' => 'left'], 'y1' => ['position' => 'right', 'grid' => ['drawOnChartArea' => false]]]];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
