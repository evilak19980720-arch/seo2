<?php

namespace App\Filament\Resources\ArticleTasks;

use App\Enums\ArticleTaskStatus;
use App\Filament\Concerns\EditableNavigation;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\ArticleTasks\Pages\ListArticleTasks;
use App\Models\ArticleTask;
use App\Models\Site;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** 文章队列: every article generation / rewrite and the step it is on. */
class ArticleTaskResource extends Resource
{
    use EditableNavigation;

    /** Listed under the articles menu item, whatever it is called now. */
    public static function getNavigationParentItem(): ?string
    {
        return (string) ArticleResource::getNavigationLabel();
    }

    protected static ?string $model = ArticleTask::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = '内容';

    protected static ?string $modelLabel = '文章任务';

    protected static ?string $pluralModelLabel = '文章队列';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $n = ArticleTask::whereIn('status', array_map(fn ($s) => $s->value, self::active()))->count();

        return $n ? (string) $n : null;
    }

    /** @return array<int, ArticleTaskStatus> */
    protected static function active(): array
    {
        return array_values(array_filter(ArticleTaskStatus::cases(), fn ($s) => $s === ArticleTaskStatus::Waiting || $s->isRunning()));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('site.name')->label('网站')->description(fn (ArticleTask $r) => $r->site?->domain?->name)->searchable(),
                TextColumn::make('status')->label('状态')->badge(),
                TextColumn::make('kind')->label('类型')->formatStateUsing(fn ($state) => $state === 'rewrite' ? '重写' : '新文章'),
                TextColumn::make('keyword')->label('关键词')->placeholder('—')->searchable()->wrap(),
                TextColumn::make('article.title')->label('文章')->placeholder('—')->wrap()->limit(60)
                    ->url(fn (ArticleTask $r) => $r->article_id ? ArticleResource::getUrl('edit', ['record' => $r->article_id]) : null),
                TextColumn::make('note')->label('结果 / 原因')->placeholder('—')->wrap()->limit(160),
                TextColumn::make('created_at')->label('加入队列')->dateTime('m-d H:i:s'),
                TextColumn::make('duration')->label('耗时')->state(fn (ArticleTask $r) => $r->started_at
                    ? ($r->finished_at ?? now())->diffForHumans($r->started_at, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'short' => true])
                    : null)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('网站')->searchable()
                    ->options(fn () => Site::with('domain')->get()->mapWithKeys(fn ($s) => [$s->id => "{$s->name} · {$s->domain?->name}"])),
                SelectFilter::make('status')->label('状态')->multiple()->options(ArticleTaskStatus::class),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListArticleTasks::route('/')];
    }
}
