<?php

namespace App\Filament\Widgets;

use App\Models\Alert;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OpenAlerts extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('未处理告警')
            ->query(Alert::query()->whereNull('resolved_at')->with('site')->latest())
            ->columns([
                TextColumn::make('level')->label('级别')->badge()->color(fn ($state) => $state === 'danger' ? 'danger' : 'warning'),
                TextColumn::make('site.name')->label('站点')->placeholder('全局'),
                TextColumn::make('message')->label('内容')->wrap(),
                TextColumn::make('created_at')->label('时间')->since(),
            ])
            ->recordActions([
                Action::make('resolve')->label('标记已处理')->action(fn (Alert $record) => $record->update(['resolved_at' => now()])),
            ])
            ->paginated([5, 10])
            ->emptyStateHeading('没有未处理的告警');
    }
}
