<?php

namespace App\Filament\Resources\Prompts;

use App\Enums\PromptType;
use App\Filament\Concerns\EditableNavigation;
use App\Filament\Resources\Prompts\Pages\CreatePrompt;
use App\Filament\Resources\Prompts\Pages\EditPrompt;
use App\Filament\Resources\Prompts\Pages\ListPrompts;
use App\Models\Prompt;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class PromptResource extends Resource
{
    use EditableNavigation;

    protected static ?string $model = Prompt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = '系统';

    protected static ?string $modelLabel = '提示词';

    protected static ?string $pluralModelLabel = '提示词';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->columnSpanFull()->schema([
                Select::make('type')->label('类型')->options(PromptType::class)->required(),
                Select::make('site_type')->label('站点类型')->placeholder('全部')->options(collect(config('site_types'))->map(fn ($t) => $t['label'])),
                Select::make('locale')->label('语言')->placeholder('全部')->searchable()->options(config('locales.languages') + ['pt' => '所有葡语', 'es' => '所有西语', 'en' => '所有英语']),
                TextInput::make('version')->label('版本')->numeric()->default(1)->required(),
                Textarea::make('system')->label('System')->rows(4)->columnSpanFull(),
                Textarea::make('template')->label('模板')->rows(22)->required()->columnSpanFull()
                    ->helperText('变量：{{brand}} {{language}} {{country}} {{product_name}} {{site_name}} {{site_type}} 以及各类型专用变量。直接用目标语言创作，不要先写中文再翻译。'),
                Toggle::make('is_active')->label('启用')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('type')
            ->columns([
                TextColumn::make('type')->label('类型')->badge(),
                TextColumn::make('site_type')->label('站点类型')->placeholder('全部')->formatStateUsing(fn ($state) => config("site_types.{$state}.label", $state)),
                TextColumn::make('locale')->label('语言')->placeholder('全部'),
                TextColumn::make('version')->label('版本')->formatStateUsing(fn ($state) => 'v'.$state),
                IconColumn::make('is_active')->label('启用')->boolean(),
                TextColumn::make('articles_count')->label('使用文章数')->counts('articles'),
                TextColumn::make('updated_at')->label('更新')->since(),
            ])
            ->filters([SelectFilter::make('type')->label('类型')->options(PromptType::class)])
            ->recordActions([
                Action::make('newVersion')->label('新版本')->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->requiresConfirmation()->modalDescription('复制为新版本并启用，旧版本停用（文章记录仍保留其使用的版本）。')
                    ->visible(fn ($livewire) => ($livewire->activeTab ?? 'latest') !== 'history')
                    ->action(fn (Prompt $record) => redirect(self::getUrl('edit', ['record' => self::copyAsLatest($record)]))),
                Action::make('restore')->label('恢复此版本')->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->requiresConfirmation()->modalDescription('把这个旧版本复制为最新版本并启用，当前版本停用后移到历史版本。')
                    ->visible(fn ($livewire) => ($livewire->activeTab ?? null) === 'history')
                    ->action(fn (Prompt $record) => self::copyAsLatest($record)),
                EditAction::make(),
            ]);
    }

    /** Copy a prompt as the newest version of its group and make it the only active one. */
    public static function copyAsLatest(Prompt $record): Prompt
    {
        $group = Prompt::where(['type' => $record->type, 'site_type' => $record->site_type, 'locale' => $record->locale]);
        $new = $record->replicate();
        $new->version = (int) (clone $group)->max('version') + 1;
        $new->is_active = true;
        (clone $group)->update(['is_active' => false]);
        $new->save();

        return $new;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrompts::route('/'),
            'create' => CreatePrompt::route('/create'),
            'edit' => EditPrompt::route('/{record}/edit'),
        ];
    }
}
