<?php

namespace App\Filament\Resources\Alerts;

use App\Filament\Concerns\EditableNavigation;
use App\Filament\Resources\Alerts\Pages\ListAlerts;
use App\Models\Alert;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class AlertResource extends Resource
{
    use EditableNavigation;

    protected static ?string $model = Alert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = '系统';

    protected static ?string $modelLabel = '告警';

    protected static ?string $pluralModelLabel = '告警';

    public static function getNavigationBadge(): ?string
    {
        $n = Alert::whereNull('resolved_at')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('level')->label('级别')->badge()->color(fn ($state) => $state === 'danger' ? 'danger' : 'warning'),
                TextColumn::make('type')->label('类型')->formatStateUsing(fn ($state) => [
                    'traffic_drop' => '流量/索引下降', 'index_drop' => '索引下降', 'manual_action' => '人工处罚', 'certificate' => '证书',
                    'ai_budget' => 'AI 预算', 'generation_failed' => '生成失败',
                ][$state] ?? $state),
                TextColumn::make('site.name')->label('站点')->placeholder('全局'),
                TextColumn::make('message')->label('内容')->wrap(),
                TextColumn::make('created_at')->label('时间')->dateTime(),
                TextColumn::make('resolved_at')->label('处理时间')->dateTime()->placeholder('未处理'),
            ])
            ->filters([TernaryFilter::make('resolved_at')->label('已处理')->nullable()->default(false)])
            ->recordActions([
                Action::make('resolve')->label('标记已处理')->visible(fn (Alert $r) => $r->resolved_at === null)
                    ->action(fn (Alert $r) => $r->update(['resolved_at' => now()])),
            ])
            ->toolbarActions([
                BulkAction::make('resolveAll')->label('标记已处理')->action(fn (Collection $records) => $records->each->update(['resolved_at' => now()])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAlerts::route('/')];
    }
}
