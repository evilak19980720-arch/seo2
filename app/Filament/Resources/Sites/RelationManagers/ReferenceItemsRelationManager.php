<?php

namespace App\Filament\Resources\Sites\RelationManagers;

use App\Support\Media;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** What was collected from the reference site: each card's picture with its name, text, terms and link (read-only). */
class ReferenceItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'referenceItems';

    protected static ?string $title = '参考素材';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                ImageColumn::make('image_path')->label('图片')->disk(Media::DISK)->square(),
                TextColumn::make('kind')->label('类型')->badge()->formatStateUsing(fn ($state) => $state === 'card' ? '卡片' : '图片'),
                TextColumn::make('name')->label('名称')->searchable()->wrap(),
                TextColumn::make('text')->label('卡片文字 / 优惠条件')->limit(80)->wrap()
                    ->description(fn ($record) => $record->detail ? '详情页：'.Str::limit($record->detail, 120) : null),
                TextColumn::make('link')->label('链接')->limit(40)->url(fn ($record) => $record->link, true),
                TextColumn::make('image_path')->label('上传')->formatStateUsing(fn ($state) => $state ? '已上传' : '失败')
                    ->badge()->color(fn ($state) => $state ? 'success' : 'danger')->default('失败'),
                TextColumn::make('width')->label('尺寸')->formatStateUsing(fn ($record) => $record->width ? "{$record->width}×{$record->height}" : '—'),
            ])
            ->filters([
                SelectFilter::make('kind')->label('类型')->options(['card' => '卡片', 'image' => '图片']),
            ]);
    }
}
