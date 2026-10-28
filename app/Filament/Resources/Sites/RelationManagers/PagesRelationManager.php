<?php

namespace App\Filament\Resources\Sites\RelationManagers;

use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PagesRelationManager extends RelationManager
{
    protected static string $relationship = 'pages';

    protected static ?string $title = '核心页面';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('页面标题')->required(),
            TextInput::make('slug')->label('URL')->disabled(fn ($record) => $record?->type === 'home')
                ->helperText('上线后修改 URL 会产生 404，非必要不要改')
                ->rule('regex:/^[a-z0-9-]+(\/[a-z0-9-]+)?$/'),
            TextInput::make('meta_title')->label('SEO 标题')->maxLength(70),
            Textarea::make('meta_description')->label('SEO 描述')->rows(2)->maxLength(320),
            Toggle::make('noindex')->label('noindex'),
            CodeEditor::make('content')->label('区块文案 JSON')->language(Language::Json)->columnSpanFull()
                ->formatStateUsing(fn ($state) => json_encode($state ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->dehydrateStateUsing(fn ($state) => json_decode((string) $state, true) ?? []),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('type')->label('类型')->formatStateUsing(fn ($state) => $this->getOwnerRecord()->typeConfig()['pages'][$state]['label'] ?? $state),
                TextColumn::make('title')->label('标题'),
                TextColumn::make('slug')->label('URL')->formatStateUsing(fn ($state) => '/'.($state === '' ? '' : $state.'/'))
                    ->url(fn ($record) => $this->getOwnerRecord()->url($record->slug), shouldOpenInNewTab: true),
                IconColumn::make('noindex')->label('noindex')->boolean(),
                TextColumn::make('index_verdict')->label('索引')->badge()->placeholder('未检查'),
                TextColumn::make('updated_at')->label('更新')->since(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
