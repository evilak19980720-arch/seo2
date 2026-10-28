<?php

namespace App\Filament\Resources\Domains;

use App\Filament\Resources\Domains\Pages\CreateDomain;
use App\Filament\Resources\Domains\Pages\EditDomain;
use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Models\Domain;
use App\Services\Sites\SiteProvisioner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;
use UnitEnum;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = '站点';

    protected static ?string $modelLabel = '域名';

    protected static ?string $pluralModelLabel = '域名管理';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('域名')->columns(2)->schema([
                TextInput::make('name')->label('域名')->required()->unique(ignoreRecord: true)
                    ->helperText('填写根域名，如 jogo-exemplo.com.br；www 会自动 301 到根域名'),
                TextInput::make('ip')->label('解析 IP')->ip()->placeholder('默认使用服务器公网 IP'),
                Select::make('status')->label('状态')->options(['available' => '可用', 'used' => '已用'])->default('available')->required(),
                Select::make('source')->label('来源')->options(['new' => '新注册', 'expired' => '过期域名'])->default('new')->required()->live(),
            ]),
            Section::make('过期域名历史检查')->columns(1)->visible(fn (Get $get) => $get('source') === 'expired')
                ->description('过期域名只分配给主题相关的产品；与产品无关的老域名建站属于 Google 的“过期域名滥用”，不建议使用。')
                ->schema([
                    TextInput::make('origin_topic')->label('原站主题'),
                    Textarea::make('history_notes')->label('历史检查备注')->rows(4)->placeholder('Wayback 快照情况、外链是否有垃圾特征（博彩/成人/药品锚文本等）'),
                ]),
            Section::make('状态')->columns(3)->collapsed()->schema([
                TextInput::make('cf_zone_id')->label('Cloudflare Zone ID'),
                TextInput::make('cf_ssl_status')->label('SSL 模式')->disabled(),
                TextInput::make('gsc_status')->label('Search Console')->disabled(),
                Textarea::make('last_error')->label('最近错误')->disabled()->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('域名')->searchable()->copyable(),
                TextColumn::make('status')->label('状态')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'used' ? '已用' : '可用')
                    ->color(fn ($state) => $state === 'used' ? 'gray' : 'success'),
                TextColumn::make('source')->label('来源')->formatStateUsing(fn ($state) => $state === 'expired' ? '过期域名' : '新注册'),
                TextColumn::make('ip')->label('解析 IP')->placeholder('默认'),
                IconColumn::make('cf_proxied')->label('CF 代理')->boolean(),
                TextColumn::make('cf_ssl_status')->label('证书')->placeholder('—')->badge(),
                TextColumn::make('gsc_status')->label('Search Console')->badge()->color(fn ($state) => match ($state) {
                    'verified' => 'success', 'pending' => 'warning', 'failed' => 'danger', default => 'gray',
                }),
                TextColumn::make('site.name')->label('所属站点')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('状态')->options(['available' => '可用', 'used' => '已用']),
                SelectFilter::make('source')->label('来源')->options(['new' => '新注册', 'expired' => '过期域名']),
            ])
            ->recordActions([
                Action::make('provision')->label('接入 Cloudflare')->icon(Heroicon::OutlinedCloud)
                    ->requiresConfirmation()->modalDescription('写入 A/CNAME 记录（橙色云）、SSL Full (strict)、签发 Origin CA 证书。')
                    ->action(function (Domain $record) {
                        try {
                            $log = app(SiteProvisioner::class)->cloudflare($record);
                            Notification::make()->success()->title(implode('；', $log))->send();
                        } catch (Throwable $e) {
                            $record->update(['last_error' => $e->getMessage()]);
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    /** Header action on the list page: paste many domains at once. */
    public static function importAction(): Action
    {
        return Action::make('import')->label('批量导入')->icon(Heroicon::OutlinedArrowUpTray)
            ->schema([
                Textarea::make('domains')->label('域名（每行一个）')->rows(10)->required(),
                Select::make('source')->label('来源')->options(['new' => '新注册', 'expired' => '过期域名'])->default('new'),
            ])
            ->action(function (array $data) {
                $count = 0;
                foreach (preg_split('/[\s,]+/', $data['domains'], -1, PREG_SPLIT_NO_EMPTY) as $name) {
                    $name = Domain::normalize($name);
                    if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $name)) {
                        $count += Domain::firstOrCreate(['name' => $name], ['source' => $data['source']])->wasRecentlyCreated ? 1 : 0;
                    }
                }
                Notification::make()->success()->title("已导入 {$count} 个域名")->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
            'create' => CreateDomain::route('/create'),
            'edit' => EditDomain::route('/{record}/edit'),
        ];
    }
}
