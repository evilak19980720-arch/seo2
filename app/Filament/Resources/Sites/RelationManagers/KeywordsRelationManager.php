<?php

namespace App\Filament\Resources\Sites\RelationManagers;

use App\Enums\KeywordStatus;
use App\Models\Site;
use App\Services\Sites\SiteGenerator;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class KeywordsRelationManager extends RelationManager
{
    protected static string $relationship = 'keywords';

    protected static ?string $title = '关键词库';

    public const INTENTS = ['informational' => '信息型', 'navigational' => '导航型', 'commercial' => '商业调查', 'transactional' => '交易型'];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('keyword')->label('关键词')->required()->maxLength(190)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('site_id', $this->getOwnerRecord()->id)),
            Select::make('intent')->label('搜索意图')->options(self::INTENTS)->default('informational')->required(),
            TextInput::make('target_path')->label('目标页面')->placeholder('例如 guia/como-jogar；留空则用于生成文章')
                ->helperText('一个关键词只对应一个页面，避免站内自相竞争'),
            Select::make('status')->label('状态')->options(KeywordStatus::class)->default('pending'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('keyword')
            ->defaultSort('impressions', 'desc')
            ->columns([
                TextColumn::make('keyword')->label('关键词')->searchable(),
                TextColumn::make('intent')->label('意图')->formatStateUsing(fn ($state) => self::INTENTS[$state] ?? $state),
                TextColumn::make('target_path')->label('目标页面')->placeholder('文章'),
                TextColumn::make('status')->label('状态')->badge(),
                TextColumn::make('source')->label('来源')->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => ['ai' => 'AI 扩展', 'gsc' => 'Search Console', 'manual' => '手动'][$state] ?? $state),
                TextColumn::make('impressions')->label('展示')->numeric()->sortable(),
                TextColumn::make('clicks')->label('点击')->numeric()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('状态')->options(KeywordStatus::class),
                SelectFilter::make('source')->label('来源')->options(['ai' => 'AI 扩展', 'gsc' => 'Search Console', 'manual' => '手动']),
            ])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['source' => 'manual']),
                Action::make('bulkAdd')->label('批量添加')->icon('heroicon-o-queue-list')
                    ->schema([
                        Textarea::make('keywords')->label('关键词（每行一个，使用站点语言）')->rows(10)->required(),
                        Select::make('intent')->label('搜索意图')->options(self::INTENTS)->default('informational'),
                    ])
                    ->action(function (array $data) {
                        $site = $this->getOwnerRecord();
                        $n = 0;
                        foreach (preg_split('/\R/u', $data['keywords']) as $line) {
                            $kw = mb_strtolower(trim($line));
                            if (mb_strlen($kw) >= 2) {
                                $n += $site->keywords()->firstOrCreate(['keyword' => $kw], ['intent' => $data['intent'], 'source' => 'manual'])->wasRecentlyCreated ? 1 : 0;
                            }
                        }
                        Notification::make()->success()->title("新增 {$n} 个关键词")->send();
                    }),
                Action::make('aiExpand')->label('AI 扩展')->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()->modalDescription('基于品牌资料扩展关键词（资料完整度需 ≥ 60%）。')
                    ->action(function () {
                        /** @var Site $site */
                        $site = $this->getOwnerRecord()->load('brandProfile');
                        $n = app(SiteGenerator::class)->expandKeywords($site);
                        Notification::make()->{$n ? 'success' : 'warning'}()->title($n ? "新增 {$n} 个关键词" : '没有新增（检查资料完整度或 AI 配置）')->send();
                    }),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
