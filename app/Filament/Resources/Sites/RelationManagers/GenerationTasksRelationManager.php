<?php

namespace App\Filament\Resources\Sites\RelationManagers;

use App\Models\GenerationTask;
use App\Services\Sites\SiteManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
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
                TextColumn::make('type')->label('类型')->formatStateUsing(fn ($state) => ['full' => '整站生成', 'rebuild' => '重新渲染', 'design' => '设计', 'qa' => 'AI 质检'][$state] ?? $state),
                TextColumn::make('status')->label('状态')->badge()->color(fn ($state) => match ($state) {
                    'succeeded' => 'success', 'failed' => 'danger', 'running' => 'info', default => 'gray',
                }),
                TextColumn::make('step')->label('当前步骤')->wrap(),
                TextColumn::make('pages_done')->label('已完成页面')->formatStateUsing(fn (GenerationTask $r) => "{$r->pages_done} / {$r->pages_total}"),
                TextColumn::make('started_at')->label('开始')->since(),
                TextColumn::make('finished_at')->label('结束')->since(),
            ])
            ->headerActions([$this->generate('generate', '重新生成')->icon('heroicon-o-sparkles')])
            ->recordActions([
                $this->generate('retry', '重试')->icon('heroicon-o-arrow-path')->color('danger')
                    ->visible(fn (GenerationTask $record) => $record->status === 'failed' && $record->is($this->getOwnerRecord()->generationTasks()->latest('id')->first()))
                    ->fillForm(fn (GenerationTask $record) => ['type' => $record->type === 'design' ? 'design' : 'full']),
                Action::make('log')->label('日志')->icon('heroicon-o-document-text')->modalSubmitAction(false)
                    ->schema(fn (GenerationTask $record) => collect($record->log ?? [])->map(fn ($l) => Text::make("{$l['at']}  {$l['step']}"))->all()),
            ]);
    }

    protected function generate(string $name, string $label): Action
    {
        return Action::make($name)->label($label)
            ->modalHeading('生成网站')
            ->schema([
                Radio::make('type')->label('生成范围')->default('full')->options([
                    'full' => '整站：模版（设计）+ 页面文案 + 渲染与验收',
                    'design' => '只重新生成模版（配色、字体、版式），保留现有页面文案',
                    'qa' => '只做 AI 质检（美观 + SEO），按结果微调设计和标题描述',
                ]),
            ])
            ->disabled(fn () => app(SiteManager::class)->isBusy($this->getOwnerRecord()))
            ->action(function (array $data) {
                app(SiteManager::class)->generate($this->getOwnerRecord(), $data['type'] ?? 'full');
                Notification::make()->success()->title('已加入生成队列')->send();
            });
    }
}
