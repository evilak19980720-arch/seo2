<?php

namespace App\Filament\Pages;

use App\Services\Install\ServerConfig;
use App\Services\Integrations\Cloudflare;
use App\Services\Integrations\GoogleAuth;
use App\Support\Media;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

class GlobalSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = '系统';

    protected static ?string $title = '全局配置';

    protected static ?string $slug = 'settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        $values = Settings::all();
        foreach (['words_guide', 'words_update', 'words_faq', 'words_use_case'] as $key) {
            [$values[$key.'_min'], $values[$key.'_max']] = $values[$key];
        }
        // Never echo stored secrets back into the browser.
        foreach (Settings::SECRET_KEYS as $key) {
            $values[$key] = null;
        }
        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $secretHint = fn (string $key) => filled(Settings::get($key)) ? '已设置，留空则不修改' : '未设置';

        return $schema->statePath('data')->components([
            Tabs::make()->tabs([
                Tab::make('发布与护栏')->schema([
                    Section::make('每站发布上限（篇/周，按站龄）')->columns(3)->schema([
                        TextInput::make('cap_months_0_3')->label('0–3 个月')->numeric()->minValue(0)->required(),
                        TextInput::make('cap_months_3_6')->label('3–6 个月')->numeric()->minValue(0)->required(),
                        TextInput::make('cap_after_6')->label('6 个月以后（基础）')->numeric()->minValue(0)->required(),
                        TextInput::make('cap_raise_ratio')->label('上调条件：近 30 天有展示的文章占比 >')->numeric()->step(0.05)->required(),
                        TextInput::make('cap_step')->label('每次上调')->numeric()->required(),
                        TextInput::make('cap_max')->label('上限封顶')->numeric()->required(),
                    ]),
                    Section::make('字数区间（按文章类型）')->columns(4)->schema([
                        TextInput::make('words_guide_min')->label('教程攻略 最少')->numeric(), TextInput::make('words_guide_max')->label('最多')->numeric(),
                        TextInput::make('words_update_min')->label('更新公告 最少')->numeric(), TextInput::make('words_update_max')->label('最多')->numeric(),
                        TextInput::make('words_faq_min')->label('FAQ 扩展 最少')->numeric(), TextInput::make('words_faq_max')->label('最多')->numeric(),
                        TextInput::make('words_use_case_min')->label('使用场景 最少')->numeric(), TextInput::make('words_use_case_max')->label('最多')->numeric(),
                    ]),
                    Section::make('自动化质量护栏')->columns(3)->description('起始建议值，上线后按 Search Console 数据调整')->schema([
                        TextInput::make('min_completeness')->label('品牌资料完整度门槛 %')->numeric()->required(),
                        TextInput::make('intra_site_similarity')->label('站内相似度上限')->numeric()->step(0.01)->required(),
                        TextInput::make('cross_site_similarity')->label('跨站相似度上限')->numeric()->step(0.01)->required(),
                        TextInput::make('page_similarity')->label('核心页面相似度上限')->numeric()->step(0.01)->required()->helperText('与参考站或其他站同类页面相比，超过就重写，仍超过就不收录'),
                        TextInput::make('min_list_articles')->label('列表页最少文章数')->numeric()->required()->helperText('博客、更新公告少于这个数时不收录、不进导航'),
                        TextInput::make('cold_days')->label('冷内容天数')->numeric()->required(),
                        TextInput::make('drop_threshold')->label('熔断下降比例')->numeric()->step(0.05)->required(),
                    ]),
                    Section::make('站点生成')->columns(2)->schema([
                        Toggle::make('auto_launch')->label('生成成功后自动上线并提交 sitemap')->helperText('关闭则生成后先预览，手动上线'),
                        Toggle::make('cookie_banner')->label('显示 Cookie 同意横幅（GA4 Consent Mode）'),
                        Toggle::make('robots_allow_ai_search')->label('robots.txt 放行 AI 搜索爬虫')->helperText('OAI-SearchBot、PerplexityBot、Claude-SearchBot 等，会在 AI 搜索里引用并链接网站'),
                        Toggle::make('robots_block_ai_training')->label('robots.txt 屏蔽 AI 训练爬虫')->helperText('GPTBot、Google-Extended、CCBot 等，不影响 Google 搜索'),
                        Toggle::make('mfa_required')->label('后台强制两步验证'),
                        TextInput::make('server_ip')->label('服务器公网 IP')->ip(),
                    ]),
                ]),
                Tab::make('Cloudflare')->schema([
                    TextInput::make('cloudflare_api_token')->label('API Token')->password()->revealable()->helperText($secretHint('cloudflare_api_token')),
                    TextInput::make('cloudflare_account_id')->label('Account ID')->helperText('自动添加新 Zone 时需要'),
                    Text::make('所需权限：Zone · DNS · Edit；Zone · SSL and Certificates · Edit；Zone · Cache Purge · Purge；Zone · Zone Settings · Edit；（自动添加域名）Zone · Zone · Edit。另请在 Cloudflare 安全设置中放行已验证的搜索引擎爬虫（Verified Bots）。'),
                ]),
                Tab::make('对象存储')->schema([
                    Select::make('oss_driver')->label('类型')->options(['s3' => 'Amazon S3 / 兼容', 'oss' => '阿里云 OSS']),
                    Fieldset::make('凭据')->columns(2)->schema([
                        TextInput::make('oss_key')->label('Access Key'),
                        TextInput::make('oss_secret')->label('Secret')->password()->revealable()->helperText($secretHint('oss_secret')),
                        TextInput::make('oss_bucket')->label('Bucket'),
                        TextInput::make('oss_region')->label('Region'),
                        TextInput::make('oss_endpoint')->label('Endpoint'),
                        TextInput::make('oss_cdn_url')->label('CDN 域名')->url(),
                    ]),
                    Text::make('填写 Access Key、Secret、Bucket 并保存后，后台上传的 LOGO、品牌图片、参考截图、文章图片等都会存到对象存储；未配置时存在服务器本地。阿里云 OSS 的 Region 填 cn-hangzhou 这类地域 ID，Endpoint 可留空自动生成。'),
                ]),
                Tab::make('搜索引擎')->schema([
                    Textarea::make('gsc_service_account')->label('Google 服务账号 JSON')->rows(6)->helperText($secretHint('gsc_service_account').'；需启用 Search Console API、Site Verification API、Analytics Data API'),
                    TextInput::make('bing_api_key')->label('Bing Webmaster API Key')->password()->revealable()->helperText($secretHint('bing_api_key')),
                    TextInput::make('indexnow_key')->label('IndexNow Key')->helperText('每个站点根目录自动生成 {key}.txt'),
                ]),
                Tab::make('服务器配置')->schema([
                    Text::make('Nginx'), Textarea::make('_nginx')->rows(16)->disabled()->dehydrated(false)->formatStateUsing(fn () => ServerConfig::nginx()),
                    Text::make('Supervisor'), Textarea::make('_supervisor')->rows(8)->disabled()->dehydrated(false)->formatStateUsing(fn () => ServerConfig::supervisor()),
                    Text::make('crontab'), TextInput::make('_cron')->disabled()->dehydrated(false)->formatStateUsing(fn () => ServerConfig::crontab()),
                ]),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('保存')->submit('save'),
                        Action::make('testCloudflare')->label('测试 Cloudflare')->color('gray')->action(fn () => $this->test('cloudflare')),
                        Action::make('testGoogle')->label('测试 Google')->color('gray')->action(fn () => $this->test('google')),
                        Action::make('testOss')->label('测试对象存储')->color('gray')->action(fn () => $this->test('oss')),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach (['words_guide', 'words_update', 'words_faq', 'words_use_case'] as $key) {
            $data[$key] = [(int) $data[$key.'_min'], (int) $data[$key.'_max']];
            unset($data[$key.'_min'], $data[$key.'_max']);
        }
        foreach (Settings::SECRET_KEYS as $key) {
            if (blank($data[$key] ?? null)) {
                unset($data[$key]);
            }
        }
        foreach (['cap_months_0_3', 'cap_months_3_6', 'cap_after_6', 'cap_step', 'cap_max', 'min_completeness', 'cold_days', 'min_list_articles'] as $int) {
            $data[$int] = (int) $data[$int];
        }
        foreach (['cap_raise_ratio', 'intra_site_similarity', 'cross_site_similarity', 'page_similarity', 'drop_threshold'] as $float) {
            $data[$float] = (float) $data[$float];
        }

        Settings::setMany(array_intersect_key($data, Settings::defaults() + ['server_ip' => 1, 'mfa_required' => 1]));
        Notification::make()->success()->title('已保存')->send();
    }

    protected function test(string $service): void
    {
        try {
            $result = match ($service) {
                'cloudflare' => 'Token '.(new Cloudflare)->verifyToken(),
                'oss' => $this->testOss(),
                default => '获取 token 成功（'.strlen(GoogleAuth::token()).' 字符）',
            };
            Notification::make()->success()->title($result)->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    protected function testOss(): string
    {
        if (! Media::configured()) {
            throw new \RuntimeException('请先填写 Access Key、Secret、Bucket 并保存');
        }
        if ($error = Media::test()) {
            throw new \RuntimeException('对象存储连接失败：'.$error);
        }

        return '对象存储读写正常';
    }
}
