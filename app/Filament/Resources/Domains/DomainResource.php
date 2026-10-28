<?php

namespace App\Filament\Resources\Domains;

use App\Filament\Resources\Domains\Pages\EditDomain;
use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Filament\Resources\Domains\Pages\ManageDnsRecords;
use App\Models\Domain;
use App\Services\Domains\DomainSync;
use App\Services\Integrations\Cloudflare;
use App\Services\Sites\SiteProvisioner;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
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

    public const CF_STATUS = ['active' => '已生效', 'pending' => '待修改 NS', 'missing' => 'CF 中已删除'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('域名')->columns(2)->schema([
                TextInput::make('name')->label('域名')->disabled(),
                TextInput::make('ip')->label('一键接入使用的 IP')->ip()->placeholder('默认使用服务器公网 IP'),
            ]),
            Section::make('状态')->columns(3)->schema([
                TextInput::make('cf_zone_id')->label('Cloudflare Zone ID')->disabled(),
                TextInput::make('cf_ssl_status')->label('SSL 模式')->disabled(),
                TextInput::make('gsc_status')->label('Search Console')->disabled(),
                Textarea::make('last_error')->label('最近错误')->disabled()->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->emptyStateHeading('还没有域名')
            ->emptyStateDescription('点击右上角「从 Cloudflare 同步」拉取 Cloudflare 账号下的全部域名。')
            ->columns([
                TextColumn::make('name')->label('域名')->searchable()->sortable()->copyable(),
                TextColumn::make('cf_status')->label('Cloudflare')->badge()
                    ->formatStateUsing(fn (?string $state) => self::CF_STATUS[$state] ?? '未接入')
                    ->color(fn (?string $state) => match ($state) {
                        'active' => 'success', 'pending' => 'warning', 'missing' => 'danger', default => 'gray',
                    })
                    ->description(fn (Domain $r) => $r->cf_status === 'pending' ? implode(' / ', $r->name_servers ?? []) : null),
                TextColumn::make('site.name')->label('绑定网站')->placeholder('未绑定'),
                TextColumn::make('cf_ssl_status')->label('SSL')->placeholder('—')->badge()->color('gray'),
                TextColumn::make('gsc_status')->label('Search Console')->badge()->color(fn ($state) => match ($state) {
                    'verified' => 'success', 'pending' => 'warning', 'failed' => 'danger', default => 'gray',
                }),
                TextColumn::make('cf_synced_at')->label('同步时间')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('bound')->label('绑定网站')->trueLabel('已绑定')->falseLabel('未绑定')
                    ->queries(true: fn ($query) => $query->whereHas('site'), false: fn ($query) => $query->whereDoesntHave('site')),
                SelectFilter::make('cf_status')->label('Cloudflare 状态')->options(self::CF_STATUS),
            ])
            ->recordActions([
                Action::make('dns')->label('解析')->icon(Heroicon::OutlinedListBullet)
                    ->url(fn (Domain $record) => self::getUrl('dns', ['record' => $record]))
                    ->visible(fn (Domain $record) => filled($record->cf_zone_id) && $record->cf_status !== 'missing'),
                Action::make('provision')->label('一键接入')->icon(Heroicon::OutlinedBolt)
                    ->requiresConfirmation()->modalDescription('@ 和 www 解析到服务器（开启代理）、SSL Full (strict)、签发源站证书。')
                    ->action(fn (Domain $record) => self::provision(collect([$record]))),
                EditAction::make()->label('编辑'),
                self::deleteAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulkDnsAction(),
                    BulkAction::make('provision')->label('批量一键接入')->icon(Heroicon::OutlinedBolt)
                        ->requiresConfirmation()->modalDescription('对选中域名：@ 和 www 解析到服务器（开启代理）、SSL Full (strict)、签发源站证书。')
                        ->action(fn (Collection $records) => self::provision($records)),
                    self::bulkDeleteAction(),
                ]),
            ]);
    }

    public static function syncAction(): Action
    {
        return Action::make('sync')->label('从 Cloudflare 同步')->icon(Heroicon::OutlinedArrowPath)
            ->action(function () {
                try {
                    $r = app(DomainSync::class)->pull();
                    Notification::make()->success()->title("同步完成：新增 {$r['added']}，更新 {$r['updated']}".($r['missing'] ? "，{$r['missing']} 个已从 Cloudflare 删除" : ''))->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('同步失败：'.$e->getMessage())->send();
                }
            });
    }

    public static function addAction(): Action
    {
        return Action::make('add')->label('添加域名')->icon(Heroicon::OutlinedPlus)
            ->modalDescription('域名会添加到 Cloudflare 账号；添加后把域名注册商处的 NS 改为 Cloudflare 提供的 NS。')
            ->schema([Textarea::make('domains')->label('域名（每行一个）')->rows(8)->required()])
            ->action(function (array $data) {
                $ok = [];
                $failed = [];
                foreach (preg_split('/[\s,]+/', $data['domains'], -1, PREG_SPLIT_NO_EMPTY) as $name) {
                    try {
                        $domain = app(DomainSync::class)->add($name);
                        $ok[] = $domain->name.'（NS：'.implode(', ', $domain->name_servers ?? []).'）';
                    } catch (Throwable $e) {
                        $failed[] = "{$name}：{$e->getMessage()}";
                    }
                }
                if ($ok) {
                    Notification::make()->success()->title('已添加 '.count($ok).' 个')->body(implode("\n", $ok))->persistent()->send();
                }
                if ($failed) {
                    Notification::make()->danger()->title(count($failed).' 个失败')->body(implode("\n", $failed))->persistent()->send();
                }
            });
    }

    protected static function deleteAction(): Action
    {
        return Action::make('delete')->label('删除')->icon(Heroicon::OutlinedTrash)->color('danger')
            ->disabled(fn (Domain $record) => $record->site()->exists())
            ->tooltip(fn (Domain $record) => $record->site()->exists() ? '已绑定网站，不能删除' : null)
            ->requiresConfirmation()
            ->schema([Toggle::make('delete_zone')->label('同时从 Cloudflare 删除这个域名')->helperText('不可恢复；不勾选则只从平台移除，下次同步会再出现')])
            ->action(function (array $data, Domain $record) {
                try {
                    app(DomainSync::class)->remove($record, (bool) ($data['delete_zone'] ?? false));
                    Notification::make()->success()->title("已删除 {$record->name}")->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    protected static function bulkDeleteAction(): BulkAction
    {
        return BulkAction::make('delete')->label('批量删除')->icon(Heroicon::OutlinedTrash)->color('danger')
            ->requiresConfirmation()->modalDescription('已绑定网站的域名会被跳过。')
            ->schema([Toggle::make('delete_zone')->label('同时从 Cloudflare 删除这些域名')->helperText('不可恢复')])
            ->action(function (array $data, Collection $records) {
                $done = 0;
                $errors = [];
                foreach ($records as $domain) {
                    try {
                        app(DomainSync::class)->remove($domain, (bool) ($data['delete_zone'] ?? false));
                        $done++;
                    } catch (Throwable $e) {
                        $errors[] = $e->getMessage();
                    }
                }
                Notification::make()->{$errors ? 'warning' : 'success'}()->title("已删除 {$done} 个")->body(implode("\n", $errors) ?: null)->send();
            });
    }

    /** Fields shared by the DNS page and the bulk DNS action. */
    public static function dnsFields(bool $bulk = false): array
    {
        return [
            Select::make('type')->label('类型')->required()->default('A')->live()
                ->options(array_combine($types = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS', 'CAA'], $types)),
            TextInput::make('name')->label('主机记录')->required()->default('@')
                ->helperText($bulk ? '@ 表示根域名，www 表示 www.域名；会应用到每个选中的域名' : '@ 表示根域名'),
            TextInput::make('content')->label('记录值')->required()
                ->default(fn () => Settings::get('server_ip') ?: config('platform.server_ip'))
                ->helperText(fn (Get $get) => match ($get('type')) {
                    'A' => 'IPv4 地址', 'AAAA' => 'IPv6 地址', 'CNAME' => $bulk ? '目标域名；填 @ 表示指向各自的根域名' : '目标域名',
                    'MX' => '邮件服务器', default => null,
                }),
            Select::make('ttl')->label('TTL')->default(1)->options([1 => '自动', 60 => '1 分钟', 300 => '5 分钟', 3600 => '1 小时', 86400 => '1 天']),
            TextInput::make('priority')->label('优先级')->numeric()->default(10)->visible(fn (Get $get) => $get('type') === 'MX'),
            Toggle::make('proxied')->label('开启 Cloudflare 代理（橙色云）')->default(true)
                ->visible(fn (Get $get) => in_array($get('type'), ['A', 'AAAA', 'CNAME'], true)),
        ];
    }

    protected static function bulkDnsAction(): BulkAction
    {
        return BulkAction::make('dns')->label('批量设置解析')->icon(Heroicon::OutlinedListBullet)
            ->modalDescription('A / AAAA / CNAME 会覆盖同名同类型的已有记录；TXT、MX 等会新增。')
            ->schema(self::dnsFields(bulk: true))
            ->action(function (array $data, Collection $records) {
                $cf = new Cloudflare;
                $done = 0;
                $errors = [];
                foreach ($records as $domain) {
                    try {
                        if (! $domain->cf_zone_id || $domain->cf_status === 'missing') {
                            throw new \RuntimeException('不在 Cloudflare 中');
                        }
                        $record = array_merge($data, [
                            'name' => $domain->fqdn($data['name']),
                            'content' => $data['type'] === 'CNAME' && trim($data['content']) === '@' ? $domain->name : $data['content'],
                        ]);
                        in_array($data['type'], ['A', 'AAAA', 'CNAME'], true)
                            ? $cf->upsertRecord($domain->cf_zone_id, $data['type'], $record['name'], $record['content'], (bool) ($data['proxied'] ?? false), (int) $data['ttl'])
                            : $cf->createRecord($domain->cf_zone_id, $record);
                        $done++;
                    } catch (Throwable $e) {
                        $errors[] = "{$domain->name}：{$e->getMessage()}";
                    }
                }
                Notification::make()->{$errors ? 'warning' : 'success'}()->title("已设置 {$done} 个域名")->body(implode("\n", $errors) ?: null)->persistent((bool) $errors)->send();
            });
    }

    protected static function provision(Collection|\Illuminate\Support\Collection $domains): void
    {
        $done = 0;
        $errors = [];
        foreach ($domains as $domain) {
            try {
                app(SiteProvisioner::class)->cloudflare($domain);
                $done++;
            } catch (Throwable $e) {
                $domain->update(['last_error' => $e->getMessage()]);
                $errors[] = "{$domain->name}：{$e->getMessage()}";
            }
        }
        Notification::make()->{$errors ? 'warning' : 'success'}()->title("已接入 {$done} 个域名")->body(implode("\n", $errors) ?: null)->persistent((bool) $errors)->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
            'edit' => EditDomain::route('/{record}/edit'),
            'dns' => ManageDnsRecords::route('/{record}/dns'),
        ];
    }
}
