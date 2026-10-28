<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Filament\Resources\Domains\DomainResource;
use App\Models\Domain;
use App\Services\Integrations\Cloudflare;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Throwable;

/** Live view of a domain's Cloudflare DNS records with add / edit / delete. */
class ManageDnsRecords extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = DomainResource::class;

    /** @var array<string, array<string, mixed>>|null records of the current request */
    protected ?array $dnsCache = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(filled($this->domain()->cf_zone_id), 404);
    }

    public function getTitle(): string
    {
        return $this->domain()->name.' 解析记录';
    }

    protected function domain(): Domain
    {
        return $this->getRecord();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function records(): array
    {
        if ($this->dnsCache === null) {
            try {
                $this->dnsCache = collect((new Cloudflare)->dnsRecords($this->domain()->cf_zone_id))->keyBy('id')->all();
            } catch (Throwable $e) {
                Notification::make()->danger()->title('读取解析失败：'.$e->getMessage())->send();
                $this->dnsCache = [];
            }
        }

        return $this->dnsCache;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search): Collection => collect($this->records())
                ->when(filled($search), fn ($c) => $c->filter(fn ($r) => str_contains(strtolower($r['name'].' '.$r['content']), strtolower($search))))
                ->sortBy(fn ($r) => [$r['type'], $r['name']]))
            ->searchable()
            ->paginated(false)
            ->columns([
                TextColumn::make('type')->label('类型')->badge()->color('gray'),
                TextColumn::make('name')->label('主机记录')
                    ->formatStateUsing(fn (string $state) => $state === $this->domain()->name ? '@' : str_replace('.'.$this->domain()->name, '', $state)),
                TextColumn::make('content')->label('记录值')->limit(60)->copyable(),
                IconColumn::make('proxied')->label('代理')->boolean(),
                TextColumn::make('ttl')->label('TTL')->formatStateUsing(fn ($state) => (int) $state === 1 ? '自动' : $state),
            ])
            ->headerActions([
                Action::make('create')->label('添加记录')->icon(Heroicon::OutlinedPlus)
                    ->schema(DomainResource::dnsFields())
                    ->action(fn (array $data) => $this->write(fn (Cloudflare $cf) => $cf->createRecord($this->domain()->cf_zone_id, $this->payload($data)), '已添加')),
            ])
            ->recordActions([
                Action::make('edit')->label('编辑')->icon(Heroicon::OutlinedPencilSquare)
                    ->schema(DomainResource::dnsFields())
                    ->fillForm(fn (array $record) => [
                        'type' => $record['type'], 'content' => $record['content'], 'ttl' => $record['ttl'],
                        'proxied' => $record['proxied'] ?? false, 'priority' => $record['priority'] ?? 10,
                        'name' => $record['name'] === $this->domain()->name ? '@' : str_replace('.'.$this->domain()->name, '', $record['name']),
                    ])
                    ->action(fn (array $data, array $record) => $this->write(fn (Cloudflare $cf) => $cf->updateRecord($this->domain()->cf_zone_id, $record['id'], $this->payload($data)), '已保存')),
                Action::make('delete')->label('删除')->icon(Heroicon::OutlinedTrash)->color('danger')->requiresConfirmation()
                    ->action(fn (array $record) => $this->write(fn (Cloudflare $cf) => $cf->deleteRecord($this->domain()->cf_zone_id, $record['id']), '已删除')),
            ]);
    }

    protected function payload(array $data): array
    {
        return array_merge($data, ['name' => $this->domain()->fqdn($data['name'])]);
    }

    protected function write(callable $call, string $message): void
    {
        try {
            $call(new Cloudflare);
            $this->dnsCache = null;
            Notification::make()->success()->title($message)->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
