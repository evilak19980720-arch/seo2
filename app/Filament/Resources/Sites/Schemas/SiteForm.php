<?php

namespace App\Filament\Resources\Sites\Schemas;

use App\Enums\PromptType;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\BrandProfile;
use App\Models\Domain;
use App\Models\Site;
use App\Services\Ai\AiManager;
use App\Services\Render\SeoHead;
use App\Services\Sites\BrandImporter;
use App\Services\Theme\CustomCssValidator;
use App\Support\Media;
use Filament\Actions\Action;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Throwable;

class SiteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('site')->persistTabInQueryString()->columnSpanFull()->tabs([
                Tab::make('基本信息')->schema(self::basic()),
                Tab::make('品牌资料')->schema(self::brand()),
                Tab::make('设计')->schema(self::design())->visibleOn('edit'),
            ]),
        ]);
    }

    protected static function basic(): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('site_type')->label('站点类型')->required()->native(false)->default('game')
                    ->options(collect(config('site_types'))->map(fn ($t) => $t['label']))
                    ->disabledOn('edit')->helperText('决定页面组合；「游戏品牌站」从 16 个栏目里为每个站随机组合页面和板块。创建后不可修改'),
                TextInput::make('product_name')->label('产品名（品牌词）')->required()->maxLength(120),
                TextInput::make('name')->label('网站名称')->required()->maxLength(120),
                Select::make('domain_id')->label('根域名')->required()->searchable()->native(false)
                    ->options(fn (?Site $record) => Domain::query()
                        ->where(fn ($q) => $q->available()->when($record, fn ($q) => $q->orWhere('id', $record->domain_id)))
                        ->orderBy('name')->pluck('name', 'id'))
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['unique' => '这个域名已经绑定了其他网站'])
                    ->disabledOn('edit')
                    ->helperText('只列出从 Cloudflare 同步、且未绑定网站的域名；一个域名只能绑定一个网站'),
                Select::make('locale')->label('网站语言')->required()->searchable()->native(false)->default('pt-BR')
                    ->options(config('locales.languages'))->disabledOn('edit')
                    ->helperText('每个站点只用一种语言'),
                Select::make('target_country')->label('目标国家')->required()->searchable()->native(false)->default('BR')
                    ->options(config('locales.countries')),
                FileUpload::make('logo_path')->label('LOGO')->image()->disk(Media::DISK)->directory('logos')->maxSize(2048)
                    ->helperText('自动生成 favicon.ico、apple-touch-icon、192/512 图标和 manifest'),
                TextInput::make('reference_url')->label('参考网站')->url()
                    ->helperText('提取配色、字体、导航和版块结构；点右侧按钮可检测能否读取')
                    ->suffixAction(SiteResource::referenceCheckAction()),
                FileUpload::make('reference_images')->label('参考截图')->image()->multiple()->maxFiles(3)->maxSize(4096)
                    ->disk(Media::DISK)->directory('reference')->columnSpanFull()
                    ->helperText('参考网站有防护、无法抓取时上传 1-3 张截图（首页首屏最重要）；会从截图中提取配色，有视觉模型时也交给 AI 参考布局'),
                Toggle::make('reference_import_images')->label('导入参考网站图片')
                    ->helperText('生成时把参考网站的内容图片下载到对象存储，用于横幅、卡片和图库。仅用于你有权使用的网站，否则有版权风险'),
                Toggle::make('reference_rewrite_content')->label('参考网站内容二次创作')
                    ->helperText('生成页面时读取参考网站正文，由 AI 按其主题和结构重新撰写，不照抄句子；品牌名替换为本站产品'),
                Toggle::make('has_accounts')->label('网站有用户账户')
                    ->helperText('打开后才会出现用户中心、注册登录指南、账户安全等栏目；没有账户就不要打开，避免描述不存在的功能'),
                Toggle::make('has_payments')->label('网站有支付 / 充值')
                    ->helperText('打开后才会出现支付问题、金额限制等栏目'),
            ]),
            Section::make('标题与描述')->schema([
                TextInput::make('title')->label('首页标题')->maxLength(70)
                    ->helperText(fn (?string $state) => '约 60 字符，必须包含产品名；当前 '.mb_strlen((string) $state).' 字符')->live(onBlur: true)
                    ->suffixAction(self::aiMetaAction()),
                Textarea::make('description')->label('首页描述')->rows(2)->maxLength(320)
                    ->helperText(fn (?string $state) => '约 155 字符；当前 '.mb_strlen((string) $state).' 字符')->live(onBlur: true),
            ])->description('留空则在生成网站时由 AI 基于品牌资料生成'),
            Section::make('统计')->columns(2)->collapsible()->schema([
                TextInput::make('ga4_measurement_id')->label('GA4 衡量 ID')->placeholder('G-XXXXXXX'),
                TextInput::make('ga4_property_id')->label('GA4 媒体资源 ID')->numeric()->helperText('用于 Data API 拉取访问统计'),
            ]),
            Toggle::make('generate_now')->label('保存后立即生成网站')->default(true)->dehydrated(false)->visibleOn('create'),
        ];
    }

    protected static function brand(): array
    {
        return [
            Group::make()->relationship('brandProfile')->schema([
                Text::make(fn (?BrandProfile $record) => '资料完整度：'.($record?->completeness ?? 0).'% · 低于 60% 只生成核心页面，不开启自动文章')->columnSpanFull(),
                Grid::make(2)->schema([
                    TextInput::make('product_name')->label('产品名')->required(),
                    TextInput::make('tagline')->label('一句话介绍')->maxLength(200)->helperText('可留空：生成时由 AI 从参考网站提取'),
                ]),
                Textarea::make('description')->label('详细介绍')->rows(6)
                    ->helperText('AI 写作的事实依据。留空或少于 200 字时，生成网站会由 AI 读取参考网站首页和内页自动补全；已填的内容不会被覆盖'),
            ]),
        ];
    }

    protected static function design(): array
    {
        return [
            Actions::make([SiteResource::generateAction()]),
            Text::make(fn (?Site $record) => blank($record?->design_config)
                ? '这个网站还没有模版。点击上面的「AI 生成模版和页面」，AI 会根据品牌资料和参考网站生成专属设计（配色、字体、版式）和全部核心页面，进度在「生成任务」标签查看。'
                : '网站模版（设计配置）由 AI 生成并自动校验（字体白名单、对比度 ≥ 4.5:1、变体必须存在），可在下面微调；想重做就点上面的「重新生成」（可选只重新生成模版），旧版本保存在「设计版本」可回滚。保存后点击「更新」重新渲染静态页。'),
            CodeEditor::make('design_config')->label('设计配置 JSON')->language(Language::Json)
                ->formatStateUsing(fn ($state) => $state ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '')
                ->dehydrateStateUsing(fn ($state) => $state ? json_decode($state, true) : null)
                ->rule(fn () => function ($attribute, $value, $fail) {
                    if ($value && ! is_array(json_decode($value, true))) {
                        $fail('不是有效的 JSON');
                    }
                }),
            CodeEditor::make('custom_css')->label('自定义 CSS（可空）')->language(Language::Css)
                ->helperText('≤ 10KB；禁止 @import、外部 url() 和隐藏正文的写法；保存时会重跑 Lighthouse')
                ->rule(fn () => function ($attribute, $value, $fail) {
                    foreach (CustomCssValidator::errors($value) as $error) {
                        $fail($error);
                    }
                })
                ->hintAction(
                    Action::make('aiCss')->label('AI 生成')->icon('heroicon-o-sparkles')
                        ->schema([TextInput::make('request')->label('想要的效果')->placeholder('例如：按钮做成渐变发光')->required()])
                        ->action(function (array $data, Set $set, Get $get, ?Site $record) {
                            try {
                                $css = app(AiManager::class)->prompt(PromptType::CustomCss, ['request' => $data['request']], $record)->json()['css'] ?? '';
                                $errors = CustomCssValidator::errors($css);
                                if ($errors) {
                                    throw new \RuntimeException('AI 生成的 CSS 未通过校验：'.implode('；', $errors));
                                }
                                $set('custom_css', trim(($get('custom_css') ?? '')."\n".$css));
                            } catch (Throwable $e) {
                                Notification::make()->danger()->title($e->getMessage())->send();
                            }
                        })
                ),
            Hidden::make('design_version'),
        ];
    }

    protected static function aiMetaAction(): Action
    {
        return Action::make('aiMeta')->icon('heroicon-o-sparkles')->tooltip('AI 生成标题和描述')
            ->action(function (Set $set, Get $get, ?Site $record) {
                if (! $record) {
                    Notification::make()->warning()->title('请先保存站点和品牌资料')->send();

                    return;
                }
                try {
                    $res = app(AiManager::class)->prompt(PromptType::SiteMeta, ['brand' => $record->brandProfile?->toPromptContext() ?? ''], $record)->json();
                    $title = (string) ($res['title'] ?? '');
                    if (! str_contains(mb_strtolower($title), mb_strtolower($record->product_name))) {
                        $title = $record->product_name.' – '.$title;
                    }
                    $set('title', SeoHead::limit($title, 60));
                    $set('description', SeoHead::limit((string) ($res['description'] ?? ''), 155));
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    /** Header action: build the brand profile from a URL / PDF / images with a vision model. */
    public static function importBrandAction(): Action
    {
        return Action::make('importBrand')->label('导入品牌资料')->icon('heroicon-o-arrow-down-tray')
            ->modalDescription('输入应用商店链接或产品页链接，或上传 PDF / 图片，AI 提取后覆盖当前品牌资料中的对应字段。')
            ->schema([
                TextInput::make('url')->label('链接')->url(),
                FileUpload::make('files')->label('PDF / 图片')->multiple()->disk(Media::DISK)->directory('imports')->acceptedFileTypes(['application/pdf', 'image/*']),
            ])
            ->action(function (array $data, Site $record) {
                try {
                    $profile = app(BrandImporter::class)->import($record, $data['url'] ?? null, $data['files'] ?? []);
                    $record->brandProfile()->updateOrCreate([], array_filter($profile, fn ($v) => filled($v)));
                    Notification::make()->success()->title('品牌资料已导入，完整度 '.$record->brandProfile()->first()->completeness.'%')->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('导入失败：'.$e->getMessage())->send();
                }
            });
    }

    public static function copyBrandAction(): Action
    {
        return Action::make('copyBrand')->label('从其他网站复制')->icon('heroicon-o-language')
            ->visible(fn (Site $record) => Site::whereKeyNot($record->id)->exists())
            ->modalDescription('复制另一个网站的品牌资料并由 AI 转为本站语言，之后两份独立维护。')
            ->schema([
                Select::make('source_id')->label('来源网站')->required()->searchable()
                    ->options(fn (Site $record) => Site::with('domain')->whereKeyNot($record->id)->get()->mapWithKeys(fn ($s) => [$s->id => "{$s->name} · {$s->locale} · {$s->domain->name}"])),
            ])
            ->action(function (array $data, Site $record) {
                try {
                    $profile = app(BrandImporter::class)->localizeFrom($record, Site::findOrFail($data['source_id']));
                    $record->brandProfile()->updateOrCreate([], array_filter($profile, fn ($v) => filled($v)));
                    Notification::make()->success()->title('已复制并转为 '.$record->locale)->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }
}
