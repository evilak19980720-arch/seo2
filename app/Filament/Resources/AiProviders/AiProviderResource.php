<?php

namespace App\Filament\Resources\AiProviders;

use App\Filament\Resources\AiProviders\Pages\CreateAiProvider;
use App\Filament\Resources\AiProviders\Pages\EditAiProvider;
use App\Filament\Resources\AiProviders\Pages\ListAiProviders;
use App\Models\AiProvider;
use App\Services\Ai\AiManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class AiProviderResource extends Resource
{
    protected static ?string $model = AiProvider::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = '系统';

    protected static ?string $modelLabel = 'AI 服务商';

    protected static ?string $pluralModelLabel = 'AI 服务商';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label('名称')->required(),
                Select::make('driver')->label('接口类型')->options(['openai' => 'OpenAI 兼容（/chat/completions）', 'anthropic' => 'Anthropic（/v1/messages）'])->required()->default('openai'),
                TextInput::make('base_url')->label('Base URL')->url()->required()->default('https://api.openai.com/v1')
                    ->helperText('直连官方或兼容接口，不走代理'),
                TextInput::make('api_key')->label('API Key')->password()->revealable()
                    ->required(fn (?AiProvider $record) => $record === null)
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(fn (?AiProvider $record) => $record ? '留空则不修改' : '加密存储'),
                TextInput::make('model')->label('模型')->required(),
                TextInput::make('embedding_model')->label('Embedding 模型')->helperText('用于语义去重；留空则用本地文本指纹'),
                Toggle::make('supports_vision')->label('supports_vision（支持图片输入）'),
                Toggle::make('is_default')->label('默认服务商'),
                Toggle::make('is_active')->label('启用')->default(true),
                TextInput::make('max_concurrency')->label('并发上限')->numeric()->default(4)->minValue(1),
                TextInput::make('monthly_budget')->label('月预算')->numeric()->prefix('$')->helperText('超出后自动切换到下一个服务商并告警'),
                TextInput::make('input_price_per_million')->label('输入单价 / 百万 token')->numeric()->prefix('$')->default(0),
                TextInput::make('output_price_per_million')->label('输出单价 / 百万 token')->numeric()->prefix('$')->default(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('名称'),
                TextColumn::make('driver')->label('类型')->badge()->color('gray'),
                TextColumn::make('model')->label('模型'),
                IconColumn::make('supports_vision')->label('视觉')->boolean(),
                IconColumn::make('is_default')->label('默认')->boolean(),
                IconColumn::make('is_active')->label('启用')->boolean(),
                TextColumn::make('spend')->label('本月用量')
                    ->state(fn (AiProvider $r) => '$'.number_format($r->monthSpend(), 2).($r->monthly_budget ? ' / $'.number_format($r->monthly_budget, 0) : ''))
                    ->color(fn (AiProvider $r) => $r->overBudget() ? 'danger' : null),
                TextColumn::make('last_test_result')->label('最近测试')->limit(40)->description(fn (AiProvider $r) => $r->last_tested_at?->diffForHumans()),
            ])
            ->recordActions([
                Action::make('test')->label('测试密钥')->icon(Heroicon::OutlinedBolt)
                    ->action(function (AiProvider $record) {
                        $result = app(AiManager::class)->test($record);
                        Notification::make()->{str_starts_with($result, 'OK') ? 'success' : 'danger'}()->title($result)->send();
                    }),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiProviders::route('/'),
            'create' => CreateAiProvider::route('/create'),
            'edit' => EditAiProvider::route('/{record}/edit'),
        ];
    }
}
