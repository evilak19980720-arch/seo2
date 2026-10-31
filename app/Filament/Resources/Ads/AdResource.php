<?php

namespace App\Filament\Resources\Ads;

use App\Filament\Concerns\EditableNavigation;
use App\Filament\Resources\Ads\Pages\CreateAd;
use App\Filament\Resources\Ads\Pages\EditAd;
use App\Filament\Resources\Ads\Pages\ListAds;
use App\Filament\Resources\Ads\RelationManagers\ChangeLogsRelationManager;
use App\Jobs\RebuildSiteJob;
use App\Models\Ad;
use App\Models\Site;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class AdResource extends Resource
{
    use EditableNavigation;

    protected static ?string $model = Ad::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = '变现';

    protected static ?string $modelLabel = '广告';

    protected static ?string $pluralModelLabel = '广告管理';

    /** Ad JS runs on every bound site, so only admins may touch it. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label('名称')->required(),
                Select::make('position')->label('位置')->options(['top' => '顶部', 'bottom' => '底部'])->required()->default('top'),
                CodeEditor::make('code')->label('JS 代码')->language(Language::Html)->required()->columnSpanFull()
                    ->helperText('异步加载，首屏内容渲染后执行；移动端不要使用遮挡主内容的全屏弹窗。所有修改都会记录日志。'),
                Fieldset::make('桌面端尺寸（预留空间防止布局跳动）')->schema([
                    TextInput::make('desktop_width')->label('宽')->numeric()->suffix('px'),
                    TextInput::make('desktop_height')->label('高')->numeric()->suffix('px')->required(),
                ]),
                Fieldset::make('移动端尺寸')->schema([
                    TextInput::make('mobile_width')->label('宽')->numeric()->suffix('px'),
                    TextInput::make('mobile_height')->label('高')->numeric()->suffix('px')->required(),
                ]),
                Select::make('sites')->label('绑定站点')->multiple()->relationship('sites', 'name')->preload()->searchable()
                    ->getOptionLabelFromRecordUsing(fn (Site $s) => "{$s->name} · {$s->locale}")
                    ->helperText('同一站点同一位置绑定多条广告时，每次访问随机展示一条'),
                Toggle::make('is_active')->label('启用')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $since = now()->subDays(7)->toDateString();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('sites')
                ->withSum(['stats as imp_7d' => fn ($s) => $s->where('date', '>', $since)], 'impressions')
                ->withSum(['stats as clicks_7d' => fn ($s) => $s->where('date', '>', $since)], 'estimated_clicks'))
            ->columns([
                TextColumn::make('name')->label('名称')->searchable(),
                TextColumn::make('position')->label('位置')->formatStateUsing(fn ($state) => $state === 'top' ? '顶部' : '底部'),
                TextColumn::make('desktop_height')->label('尺寸（桌面 / 移动）')
                    ->formatStateUsing(fn (Ad $r) => ($r->desktop_width ?: 'auto').'×'.$r->desktop_height.' / '.($r->mobile_width ?: 'auto').'×'.$r->mobile_height),
                TextColumn::make('sites_count')->label('绑定站点'),
                TextColumn::make('imp_7d')->label('7 日展示')->numeric()->default(0),
                TextColumn::make('clicks_7d')->label('7 日点击（估算）')->numeric()->default(0)->tooltip('跨域 iframe 无法精确统计点击，以广告平台后台为准'),
                ToggleColumn::make('is_active')->label('启用'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    /** Ads are baked into static HTML: re-render the bound sites after a change. */
    public static function rebuildSites(Ad $ad, array $extraSiteIds = []): void
    {
        $ids = array_unique(array_merge($ad->sites()->pluck('sites.id')->all(), $extraSiteIds));
        Site::whereIn('id', $ids)->whereNotNull('built_at')->get()->each(fn ($s) => RebuildSiteJob::dispatch($s));
    }

    public static function getRelations(): array
    {
        return [ChangeLogsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAds::route('/'),
            'create' => CreateAd::route('/create'),
            'edit' => EditAd::route('/{record}/edit'),
        ];
    }
}
