<?php

namespace App\Filament\Resources\Sites;

use App\Enums\SiteStatus;
use App\Filament\Concerns\EditableNavigation;
use App\Filament\Resources\Sites\Pages\CreateSite;
use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Filament\Resources\Sites\RelationManagers\DesignVersionsRelationManager;
use App\Filament\Resources\Sites\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\Sites\RelationManagers\GenerationTasksRelationManager;
use App\Filament\Resources\Sites\RelationManagers\KeywordsRelationManager;
use App\Filament\Resources\Sites\RelationManagers\PagesRelationManager;
use App\Filament\Resources\Sites\Schemas\SiteForm;
use App\Models\AiProvider;
use App\Models\Site;
use App\Services\Sites\SiteManager;
use App\Services\Theme\ReferenceAnalyzer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

class SiteResource extends Resource
{
    use EditableNavigation;

    protected static ?string $model = Site::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = '站点';

    protected static ?string $modelLabel = '网站';

    protected static ?string $pluralModelLabel = '网站管理';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SiteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('domain'))
            ->poll('15s')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('网站名称')->searchable()->description(fn (Site $r) => $r->product_name),
                TextColumn::make('domain.name')->label('域名')->searchable()
                    ->url(fn (Site $r) => $r->baseUrl(), shouldOpenInNewTab: true),
                TextColumn::make('site_type')->label('站点类型')->formatStateUsing(fn ($state) => config("site_types.{$state}.label", $state)),
                TextColumn::make('locale')->label('语言')->badge()->color('gray'),
                TextColumn::make('indexed_pages')->label('已索引')->numeric()->sortable(),
                TextColumn::make('impressions_7d')->label('7 日展示/点击')->sortable()
                    ->formatStateUsing(fn (Site $r) => number_format($r->impressions_7d).' / '.number_format($r->clicks_7d)),
                TextColumn::make('status')->label('状态')->badge()
                    ->description(fn (Site $r) => $r->auto_publish_paused && $r->status === SiteStatus::Live ? '自动发布已暂停' : null),
            ])
            ->filters([
                SelectFilter::make('status')->label('状态')->options(SiteStatus::class),
                SelectFilter::make('site_type')->label('站点类型')->options(collect(config('site_types'))->map(fn ($t) => $t['label'])),
                SelectFilter::make('locale')->label('语言')->options(config('locales.languages')),
            ])
            ->recordActions([
                Action::make('rebuild')->label('更新')->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (Site $r) => in_array($r->status, [SiteStatus::Live, SiteStatus::Preview, SiteStatus::Paused], true))
                    ->action(function (Site $r) {
                        app(SiteManager::class)->rebuild($r);
                        Notification::make()->success()->title('已加入重新渲染队列')->send();
                    }),
                EditAction::make(),
                ActionGroup::make([
                    self::generateAction(),
                    Action::make('preview')->label('预览')->icon(Heroicon::OutlinedEye)
                        ->url(fn (Site $r) => route('site.preview', $r), shouldOpenInNewTab: true)
                        ->visible(fn (Site $r) => $r->built_at !== null),
                    Action::make('launch')->label('上线')->icon(Heroicon::OutlinedRocketLaunch)->color('success')
                        ->visible(fn (Site $r) => $r->status === SiteStatus::Preview)
                        ->requiresConfirmation()->action(fn (Site $r) => app(SiteManager::class)->launch($r)),
                    Action::make('pause')->label('暂停')->icon(Heroicon::OutlinedPause)->color('warning')
                        ->visible(fn (Site $r) => $r->status === SiteStatus::Live)
                        ->requiresConfirmation()->modalDescription('暂停自动发布；已生成的页面继续可访问。')
                        ->action(fn (Site $r) => app(SiteManager::class)->pause($r)),
                    Action::make('resume')->label('启用')->icon(Heroicon::OutlinedPlay)->color('success')
                        ->visible(fn (Site $r) => $r->status === SiteStatus::Paused || ($r->status === SiteStatus::Live && $r->auto_publish_paused))
                        ->action(fn (Site $r) => app(SiteManager::class)->resume($r)),
                    Action::make('siteSearch')->label('site: 查询')->icon(Heroicon::OutlinedMagnifyingGlass)
                        ->url(fn (Site $r) => 'https://www.google.com/search?q='.urlencode('site:'.$r->domain->name), shouldOpenInNewTab: true),
                    self::deleteAction(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkGenerate')->label('批量生成')->icon(Heroicon::OutlinedSparkles)->requiresConfirmation()
                        ->modalDescription('进入生成队列，按队列并发数依次执行。')
                        ->action(fn (Collection $records) => $records->each(fn (Site $s) => app(SiteManager::class)->generate($s))),
                    BulkAction::make('bulkPause')->label('暂停')->icon(Heroicon::OutlinedPause)->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each(fn (Site $s) => app(SiteManager::class)->pause($s))),
                    BulkAction::make('bulkResume')->label('启用')->icon(Heroicon::OutlinedPlay)
                        ->action(fn (Collection $records) => $records->each(fn (Site $s) => app(SiteManager::class)->resume($s))),
                    BulkAction::make('bulkDelete')->label('删除')->icon(Heroicon::OutlinedTrash)->color('danger')->requiresConfirmation()
                        ->modalDescription('页面将返回 410 Gone，域名释放为可用。')
                        ->action(fn (Collection $records) => $records->each(fn (Site $s) => app(SiteManager::class)->delete($s))),
                ]),
            ]);
    }

    /** Reads the reference site right away and reports what generation will get from it. */
    public static function referenceCheckAction(): Action
    {
        return Action::make('checkReference')->icon(Heroicon::OutlinedMagnifyingGlass)->tooltip('检测能否读取参考网站')
            ->action(function (?string $state) {
                if (blank($state)) {
                    Notification::make()->warning()->title('请先填写参考网站')->send();

                    return;
                }
                @set_time_limit(120);
                $analyzer = app(ReferenceAnalyzer::class);
                try {
                    $ref = $analyzer->analyze($state);
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('检测出错')->body($e->getMessage())->persistent()->send();

                    return;
                }
                $vision = AiProvider::where('is_active', true)->where('supports_vision', true)->exists();
                $lines = array_merge($analyzer->report($ref), [
                    '截图：'.($analyzer->browserAvailable() ? '可自动截图' : '服务器无 Chromium，无法自动截图').'；'.($vision ? '有支持视觉的 AI 接口' : '没有勾选"支持视觉"的 AI 接口，AI 看不到截图'),
                ]);
                Notification::make()->{$ref ? 'success' : 'danger'}()->title($ref ? '参考网站可以读取' : '参考网站读取失败')
                    ->body(new HtmlString(collect($lines)->map(fn ($l) => e($l))->implode('<br>')))->persistent()->send();
            });
    }

    public static function generateAction(): Action
    {
        return Action::make('generate')->icon(Heroicon::OutlinedSparkles)
            ->label(fn (Site $r) => match (true) {
                app(SiteManager::class)->isBusy($r) => '生成中…',
                $r->status === SiteStatus::Failed => '重试生成',
                blank($r->design_config) => 'AI 生成模版和页面',
                default => '重新生成',
            })
            ->color(fn (Site $r) => $r->status === SiteStatus::Failed ? 'danger' : 'primary')
            ->modalHeading('生成网站')
            ->modalDescription('已上线站点不会改动已有 URL。旧设计保存在「设计版本」，可随时回滚。')
            ->schema([
                Radio::make('type')->label('生成范围')->default('full')->options([
                    'full' => '整站：模版（设计）+ 页面文案 + 渲染与验收',
                    'design' => '只重新生成模版（配色、字体、版式），保留现有页面文案',
                ]),
            ])
            ->disabled(fn (Site $r) => app(SiteManager::class)->isBusy($r))
            ->action(function (array $data, Site $r) {
                app(SiteManager::class)->generate($r, $data['type'] ?? 'full');
                Notification::make()->success()->title('已加入生成队列，可在「生成任务」查看进度')
                    ->body('需要服务器上的队列进程在运行（Supervisor），否则任务会一直停在“排队中”。')->send();
            });
    }

    public static function deleteAction(): Action
    {
        return Action::make('deleteSite')->label('删除')->icon(Heroicon::OutlinedTrash)->color('danger')
            ->requiresConfirmation()->modalDescription('页面将返回 410 Gone（而不是直接断开解析），域名释放为可用。')
            ->action(fn (Site $r) => app(SiteManager::class)->delete($r))
            ->successRedirectUrl(fn () => self::getUrl('index'));
    }

    public static function getRelations(): array
    {
        return [
            KeywordsRelationManager::class,
            PagesRelationManager::class,
            EntriesRelationManager::class,
            GenerationTasksRelationManager::class,
            DesignVersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSites::route('/'),
            'create' => CreateSite::route('/create'),
            'edit' => EditSite::route('/{record}/edit'),
        ];
    }
}
