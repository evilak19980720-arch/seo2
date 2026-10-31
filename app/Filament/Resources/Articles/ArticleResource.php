<?php

namespace App\Filament\Resources\Articles;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Filament\Concerns\EditableNavigation;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Jobs\GenerateArticleJob;
use App\Jobs\RewriteArticleJob;
use App\Models\Article;
use App\Models\Site;
use App\Services\Content\PublishScheduler;
use App\Services\Render\HtmlSanitizer;
use App\Support\Media;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use UnitEnum;

class ArticleResource extends Resource
{
    use EditableNavigation;

    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = '内容';

    protected static ?string $modelLabel = '文章';

    protected static ?string $pluralModelLabel = '文章管理';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make()->columnSpan(2)->schema([
                    TextInput::make('title')->label('标题')->required()->maxLength(200)
                        ->live(onBlur: true)->afterStateUpdated(fn ($state, $set, ?Article $record) => $record ? null : $set('slug', Str::slug($state))),
                    TextInput::make('slug')->label('slug')->required()->maxLength(120)->rule('regex:/^[a-z0-9-]+$/')
                        ->helperText(fn (Get $get) => 'URL：/blog/'.$get('slug').'/'),
                    RichEditor::make('body')->label('正文')->required()
                        ->toolbarButtons([['bold', 'italic', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList', 'blockquote'], ['table'], ['undo', 'redo']])
                        ->dehydrateStateUsing(fn ($state) => is_string($state) ? HtmlSanitizer::clean($state) : $state),
                ]),
                Section::make()->columnSpan(1)->schema([
                    Select::make('site_id')->label('归属站点')->required()->searchable()->native(false)
                        ->options(fn () => Site::with('domain')->get()->mapWithKeys(fn ($s) => [$s->id => "{$s->name} · {$s->domain->name}"]))
                        ->disabledOn('edit')->live(),
                    Select::make('type')->label('文章类型')->options(ArticleType::class)->required()->default('guide'),
                    Select::make('keyword_id')->label('目标关键词')->searchable()
                        ->relationship('keyword', 'keyword', fn ($query, Get $get) => $query->where('site_id', $get('site_id'))),
                    Select::make('status')->label('状态')->options(ArticleStatus::class)->default('queued')->required(),
                    DateTimePicker::make('published_at')->label('发布时间'),
                    TextInput::make('author')->label('作者')->placeholder('品牌编辑部'),
                    TextInput::make('meta_title')->label('SEO 标题')->maxLength(70)->helperText('约 60 字符'),
                    Textarea::make('meta_description')->label('SEO 描述')->rows(3)->maxLength(320)->helperText('约 155 字符'),
                    FileUpload::make('featured_image')->label('特色图')->image()->disk(Media::DISK)->directory('articles'),
                    TextInput::make('featured_image_alt')->label('特色图 alt'),
                    Textarea::make('block_reason')->label('拦截原因')->rows(4)->disabled()->visible(fn (?Article $record) => filled($record?->block_reason)),
                    TextInput::make('similarity_score')->label('相似度得分')->disabled(),
                    TextInput::make('prompt.version')->label('提示词版本')->disabled()
                        ->formatStateUsing(fn (?Article $record) => $record?->prompt ? "#{$record->prompt->id} v{$record->prompt->version}" : '—'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['site.domain', 'keyword']))
            ->columns([
                TextColumn::make('title')->label('标题')->searchable()->limit(60)
                    ->url(fn (Article $r) => $r->status->isRendered() ? $r->site->url($r->path()) : null, shouldOpenInNewTab: true),
                TextColumn::make('site.name')->label('站点')->sortable(),
                TextColumn::make('type')->label('类型')->badge()->color('gray'),
                TextColumn::make('keyword.keyword')->label('目标关键词')->limit(30)->toggleable(),
                TextColumn::make('status')->label('状态')->badge()->description(fn (Article $r) => $r->status === ArticleStatus::Blocked ? Str::limit((string) $r->block_reason, 50) : null),
                TextColumn::make('similarity_score')->label('相似度')->numeric(2)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('impressions_30d')->label('30 日展示')->numeric()->sortable(),
                TextColumn::make('index_verdict')->label('索引')->badge()->placeholder('—')->toggleable(),
                TextColumn::make('published_at')->label('发布时间')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('站点')->relationship('site', 'name')->searchable(),
                SelectFilter::make('status')->label('状态')->options(ArticleStatus::class),
                SelectFilter::make('type')->label('类型')->options(ArticleType::class),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('publishNow')->label('立即发布')->icon(Heroicon::OutlinedPaperAirplane)
                        ->visible(fn (Article $r) => in_array($r->status, [ArticleStatus::Queued, ArticleStatus::Blocked], true))
                        ->requiresConfirmation()->modalDescription('跳过每周发布上限立即发布。被拦截文章请先确认拦截原因已处理。')
                        ->action(fn (Article $r) => self::changeStatus($r, ArticleStatus::Published)),
                    Action::make('noindex')->label('设为 noindex')->icon(Heroicon::OutlinedEyeSlash)
                        ->visible(fn (Article $r) => $r->status === ArticleStatus::Published)
                        ->action(fn (Article $r) => self::changeStatus($r, ArticleStatus::Noindex)),
                    Action::make('index')->label('恢复索引')->icon(Heroicon::OutlinedEye)
                        ->visible(fn (Article $r) => $r->status === ArticleStatus::Noindex)
                        ->action(fn (Article $r) => self::changeStatus($r, ArticleStatus::Published)),
                    Action::make('rewrite')->label('AI 改写')->icon(Heroicon::OutlinedSparkles)
                        ->visible(fn (Article $r) => $r->keyword_id !== null)->requiresConfirmation()
                        ->action(function (Article $r) {
                            RewriteArticleJob::dispatch($r);
                            Notification::make()->success()->title('已加入改写队列')->send();
                        }),
                    Action::make('remove')->label('删除（410）')->icon(Heroicon::OutlinedTrash)->color('danger')
                        ->visible(fn (Article $r) => $r->status !== ArticleStatus::Deleted)
                        ->requiresConfirmation()->modalDescription('已发布的 URL 将返回 410 Gone。')
                        ->action(fn (Article $r) => self::changeStatus($r, ArticleStatus::Deleted)),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkNoindex')->label('设为 noindex')->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->where('status', ArticleStatus::Published)->each(fn ($r) => self::changeStatus($r, ArticleStatus::Noindex))),
                    BulkAction::make('bulkDelete')->label('删除（410）')->color('danger')->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each(fn ($r) => self::changeStatus($r, ArticleStatus::Deleted))),
                ]),
            ]);
    }

    public static function generateAction(): Action
    {
        return Action::make('generate')->label('为站点生成文章')->icon(Heroicon::OutlinedSparkles)
            ->schema([
                Select::make('site_id')->label('站点')->required()->searchable()
                    ->options(fn () => Site::where('status', 'live')->with('domain')->get()->mapWithKeys(fn ($s) => [$s->id => "{$s->name} · {$s->domain->name}"])),
            ])
            ->modalDescription('从该站关键词库取一个未覆盖的词，走完整管线（生成→润色→AI 审核→护栏），通过后进入发布队列。')
            ->action(function (array $data) {
                GenerateArticleJob::dispatch(Site::findOrFail($data['site_id']));
                Notification::make()->success()->title('已加入生成队列')->send();
            });
    }

    /** A published article without a date is published now (unpublished ones are not listed). */
    public static function withPublishDate(array $data): array
    {
        if (($data['status'] ?? null) instanceof ArticleStatus ? $data['status'] === ArticleStatus::Published : ($data['status'] ?? null) === ArticleStatus::Published->value) {
            $data['published_at'] = filled($data['published_at'] ?? null) ? $data['published_at'] : now();
        }

        return $data;
    }

    /**
     * After an admin save: update the site's pages when the article is (or
     * just stopped being) on the site, then say what was updated.
     */
    public static function syncSite(Article $article, bool $statusChanged): void
    {
        $article->refresh();
        if (! $article->site?->built_at || (! $article->status->isRendered() && ! $statusChanged)) {
            return;
        }
        $scheduler = app(PublishScheduler::class);
        $scheduler->afterChange($article);
        Notification::make()->success()->title('前台已更新')->body(implode('；', $scheduler->log))->send();
    }

    public static function changeStatus(Article $article, ArticleStatus $status): void
    {
        $article->update([
            'status' => $status,
            'published_at' => $status === ArticleStatus::Published ? ($article->published_at ?? now()) : $article->published_at,
            'noindexed_at' => $status === ArticleStatus::Noindex ? now() : ($status === ArticleStatus::Published ? null : $article->noindexed_at),
        ]);
        if ($article->site->built_at) {
            app(PublishScheduler::class)->afterChange($article->refresh());
        }
        Notification::make()->success()->title('已更新：'.$status->getLabel())->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'create' => CreateArticle::route('/create'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }
}
