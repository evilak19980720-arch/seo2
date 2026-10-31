<?php

namespace App\Filament\Resources\Sites\RelationManagers;

use App\Support\Media;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/** Games, providers, promotions, events, notices and news of a pool site. Hand edits are kept on regeneration. */
class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = '站点数据';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! empty(config("site_types.{$ownerRecord->site_type}.sectioned"));
    }

    public static function collections(): array
    {
        return collect(config('site_types.game.collections'))->map(fn ($c) => $c['label'])->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('collection')->label('类型')->options(self::collections())->required()->native(false),
            TextInput::make('title')->label('名称')->required()->maxLength(120)
                ->afterStateUpdated(fn ($state, $set, $get) => blank($get('slug')) ? $set('slug', Str::slug((string) $state)) : null)->live(onBlur: true),
            TextInput::make('slug')->label('网址')->required()->maxLength(100)->alphaDash()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, $get) => $rule->where('site_id', $this->getOwnerRecord()->id)->where('collection', $get('collection'))),
            TextInput::make('sort')->label('排序')->numeric()->default(0),
            Textarea::make('summary')->label('简介')->rows(2)->columnSpanFull(),
            FileUpload::make('image')->label('图片')->image()->disk(Media::DISK)->directory('entries')->maxSize(4096),
            KeyValue::make('data')->label('数据')->columnSpanFull()
                ->helperText('游戏：provider、rtp、volatility、max_win… 优惠/活动：reward、requirement、starts_at、ends_at（YYYY-MM-DD）。标签、主题等列表请用英文逗号分隔。只填真实信息')
                ->formatStateUsing(fn ($state) => collect((array) $state)->map(fn ($v) => is_array($v) ? implode(', ', $v) : (is_bool($v) ? ($v ? '1' : '') : (string) $v))->all())
                ->dehydrateStateUsing(fn ($state) => collect((array) $state)->map(fn ($v, $k) => in_array($k, ['tags', 'themes', 'mechanics', 'images'], true) ? array_values(array_filter(array_map('trim', explode(',', (string) $v)))) : $v)->all()),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('sort')
            ->columns([
                ImageColumn::make('image')->label('')->disk(Media::DISK)->square(),
                TextColumn::make('title')->label('名称')->searchable(),
                TextColumn::make('collection')->label('类型')->badge()->formatStateUsing(fn ($state) => self::collections()[$state] ?? $state),
                TextColumn::make('summary')->label('简介')->limit(60),
                TextColumn::make('source')->label('来源')->badge()->color('gray')->formatStateUsing(fn ($state) => ['ai' => 'AI', 'manual' => '手动'][$state] ?? $state),
                TextColumn::make('updated_at')->label('更新')->since(),
            ])
            ->filters([SelectFilter::make('collection')->label('类型')->options(self::collections())])
            ->headerActions([CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['source' => 'manual'])])
            ->recordActions([
                // A hand-edited item is kept as it is when the site is regenerated.
                EditAction::make()->mutateDataUsing(fn (array $data) => ['source' => 'manual'] + $data),
                DeleteAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
