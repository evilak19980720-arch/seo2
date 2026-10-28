<?php

namespace App\Filament\Resources\Sites\RelationManagers;

use App\Models\GenerationTask;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Text;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GenerationTasksRelationManager extends RelationManager
{
    protected static string $relationship = 'generationTasks';

    protected static ?string $title = '生成任务';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('type')->label('类型')->formatStateUsing(fn ($state) => ['full' => '整站生成', 'rebuild' => '重新渲染', 'design' => '设计'][$state] ?? $state),
                TextColumn::make('status')->label('状态')->badge()->color(fn ($state) => match ($state) {
                    'succeeded' => 'success', 'failed' => 'danger', 'running' => 'info', default => 'gray',
                }),
                TextColumn::make('step')->label('当前步骤')->wrap(),
                TextColumn::make('pages_done')->label('已完成页面')->formatStateUsing(fn (GenerationTask $r) => "{$r->pages_done} / {$r->pages_total}"),
                TextColumn::make('started_at')->label('开始')->since(),
                TextColumn::make('finished_at')->label('结束')->since(),
            ])
            ->recordActions([
                Action::make('log')->label('日志')->icon('heroicon-o-document-text')->modalSubmitAction(false)
                    ->schema(fn (GenerationTask $record) => collect($record->log ?? [])->map(fn ($l) => Text::make("{$l['at']}  {$l['step']}"))->all()),
            ]);
    }
}
