<?php

namespace App\Services\Sites;

use App\Enums\PromptType;
use App\Enums\SiteStatus;
use App\Jobs\GenerateSiteArtJob;
use App\Models\AiProvider;
use App\Models\Alert;
use App\Models\BrandProfile;
use App\Models\DesignVersion;
use App\Models\GenerationTask;
use App\Models\GoneUrl;
use App\Models\Page;
use App\Models\Site;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use App\Services\Content\LanguageDetector;
use App\Services\Content\SeoRules;
use App\Services\Content\Similarity;
use App\Services\Integrations\Lighthouse;
use App\Services\Integrations\Screenshot;
use App\Services\Render\HtmlSanitizer;
use App\Services\Render\SchemaValidator;
use App\Services\Render\SeoHead;
use App\Services\Render\SiteRenderer;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Render\ThemeStrings;
use App\Services\Theme\Color;
use App\Services\Theme\CustomCssValidator;
use App\Services\Theme\DefaultDesign;
use App\Services\Theme\DesignConfigValidator;
use App\Services\Theme\ExtraPages;
use App\Services\Theme\ReferenceAnalyzer;
use App\Services\Theme\SiteStyle;
use App\Support\Media;
use App\Support\Settings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Throwable;

/**
 * "生成网站": one task covers the whole site — meta, design config for every
 * page type, core page copy, keyword seed, static render and Lighthouse
 * acceptance. Only failing page types are regenerated.
 */
class SiteGenerator
{
    /** @var array<int, string> what happened during design generation, shown in the task log */
    public array $designNotes = [];

    public function __construct(
        protected AiManager $ai,
        protected StaticSiteBuilder $builder,
        protected Lighthouse $lighthouse,
        protected Screenshot $screenshot,
        protected ReferenceAnalyzer $reference,
        protected ReferenceImageImporter $images,
        protected SiteDataGenerator $data,
        protected SiteQa $qa,
        protected ReferenceLibrary $library,
    ) {}

    /** Reference analysis of the current run, reused for page content. */
    protected ?array $referenceData = null;

    /** Reference URL that referenceData was read from. */
    protected ?string $analyzedUrl = null;

    /** Inner pages of the reference read in this run (pool sites). */
    protected array $referencePages = [];

    public function run(GenerationTask $task): void
    {
        $site = $task->site()->with(['domain', 'brandProfile'])->first();
        $task->update(['status' => 'running', 'started_at' => now()]);
        $before = $site->status;
        if ($task->type !== 'qa') {
            $site->update(['status' => SiteStatus::Generating]);
        }

        $designOnly = $task->type === 'design';

        try {
            if ($task->type === 'qa') {
                // Quality check of the site as it is: nothing is generated again.
                $task->progress('渲染静态页面');
                $this->render($site, $task);
                $this->qa->run($site, $task, fn (Site $s) => $this->render($s, $task), force: true);
                $site->update(['status' => $before]);
                $task->progress('AI 质检完成');
                $task->update(['status' => 'succeeded', 'finished_at' => now()]);

                return;
            }

            // Interface strings first: titles, menus and fallbacks use them.
            if ($note = ThemeStrings::ensure($site, $this->ai)) {
                $task->progress($note);
            }

            if ($note = $this->completeBrand($site)) {
                $task->progress($note);
            }

            if (! $designOnly) {
                $task->progress('生成标题与描述');
                $this->ensureMeta($site);
            }

            if ($note = $this->localizeBrand($site)) {
                $task->progress($note);
            }

            if ($note = $this->ensureStructure($site, $task->type === 'full' && $this->isRegeneration($task))) {
                $task->progress($note);
            }

            $task->progress('生成设计配置');
            $this->generateDesign($site);
            foreach ($this->designNotes as $note) {
                $task->progress($note);
            }

            $sectioned = ! empty($site->typeConfig()['sectioned']);
            if ($sectioned && ! $designOnly) {
                if ($this->referenceData && $this->referencePages === []) {
                    $task->progress('读取参考网站内页');
                    $this->referencePages = $this->reference->crawl($this->referenceData);
                    $task->progress('参考网站内页：读到 '.count($this->referencePages).' 页，图块 '.collect($this->referenceSources())->sum(fn ($p) => count($p['cards'] ?? [])).' 个');
                }
                if ($this->referenceData && $site->reference_import_images) {
                    $task->progress('保存参考网站素材（名称、文字和图片成对保存，图片上传'.(Media::configured() ? '对象存储' : '本地存储').'）');
                    $stats = rescue(fn () => $this->library->capture($site, $this->referenceSources()), null);
                    $task->progress($stats ? "参考网站素材：卡片 {$stats['cards']} 个，图片共 {$stats['images']} 张，本次上传 {$stats['uploaded']} 张".($stats['failed'] ? "，{$stats['failed']} 张下载失败或不是图片" : '') : '参考网站素材保存失败');
                }
                $task->progress('生成站点数据（游戏、优惠、活动、公告…）');
                $count = $this->data->generate($site, $this->facts($site), $this->referenceSources(), $this->siteFeatures($site));
                foreach ($this->data->notes as $note) {
                    $task->progress('站点数据 · '.$note);
                }
                if ($count === 0) {
                    $task->progress('没有读到游戏、优惠等数据，相关列表页会先不收录（noindex）');
                }
                // Inner-page pictures join the home page's, so blocks without their own picture have more to show.
                $inner = collect($this->referencePages)->flatMap(fn ($p) => $p['images'] ?? [])->filter(fn ($i) => filled($i['url'] ?? null))->unique('url')->values()->all();
                if ($site->reference_import_images && $inner !== []) {
                    $added = rescue(fn () => $this->images->import($site, $inner), 0);
                    $task->progress('参考网站内页图片：发现 '.count($inner)." 张，新导入 {$added} 张");
                    $site->unsetRelation('brandProfile');
                }
            }

            if (! $designOnly || $site->pages()->doesntExist()) {
                $task->progress('生成核心页面文案');
                $this->generatePages($site);
            }

            if ($site->keywords()->doesntExist()) {
                $task->progress('扩展关键词库');
                $this->expandKeywords($site);
            }

            $task->progress('渲染静态页面');
            $this->render($site, $task);

            if ($this->qa->enabled()) {
                $task->progress('AI 质检（美观 + SEO）');
                $this->qa->run($site, $task, fn (Site $s) => $this->render($s, $task));
            }

            $task->progress('Lighthouse 验收');
            $this->acceptance($site, $task);

            $launch = (bool) Settings::get('auto_launch', true);
            $site->update([
                'status' => $launch ? SiteStatus::Live : SiteStatus::Preview,
                'launched_at' => $launch ? ($site->launched_at ?? now()) : $site->launched_at,
            ]);
            $task->progress($launch ? '生成成功，已上线' : '生成成功，等待手动上线');
            $task->update(['status' => 'succeeded', 'finished_at' => now()]);
            $this->queueArt($site->fresh(['domain', 'brandProfile']), $task);
        } catch (Throwable $e) {
            report($e);
            // A failed quality check leaves the site as it was.
            $site->update(['status' => $task->type === 'qa' ? $before : SiteStatus::Failed]);
            $task->progress('失败：'.$e->getMessage());
            $task->update(['status' => 'failed', 'finished_at' => now()]);
            Alert::raise('generation_failed', "站点 {$site->name} 生成失败：".Str::limit($e->getMessage(), 1000), $site->id, 'danger');

            throw $e;
        }
    }

    /** A full run on a generated site that was not retrying a failed run. */
    protected function isRegeneration(GenerationTask $task): bool
    {
        $last = GenerationTask::where('site_id', $task->site_id)->where('id', '<', $task->id)
            ->whereIn('type', ['full', 'design'])->latest('id')->first();

        return $last !== null && $last->status === 'succeeded';
    }

    /**
     * Pool presets: the site's pages and sections, seeded and stored. A retry
     * keeps them; a regeneration draws a new combination (a launched site
     * keeps its pages, so no URL disappears).
     */
    public function ensureStructure(Site $site, bool $reshuffle = false): ?string
    {
        $config = config("site_types.{$site->site_type}");
        if (empty($config['sectioned'])) {
            return null;
        }
        $structure = $site->structure;
        if ($reshuffle && $structure) {
            // Regeneration: a new combination of pages and sections, never the same one again.
            $round = (int) ($structure['round'] ?? 0);
            $same = fn (array $s) => $s['pages'] === $structure['pages'] && $s['collections'] === ($structure['collections'] ?? []);
            for ($try = 1; $try <= 10; $try++) {
                $next = SiteStructure::choose($site, $config, null, $round + $try);
                if (! $same($next)) {
                    break;
                }
            }
            $structure = $next;
        } else {
            $structure = SiteStructure::choose($site, $config, $structure, (int) ($structure['round'] ?? 0));
        }
        if ($structure === $site->structure) {
            return null;
        }
        $site->forceFill(['structure' => $structure])->save();

        return '站点栏目：'.count($structure['pages']).' 个页面，'.collect($structure['pages'])->flatten()->count().' 个板块';
    }

    /** What the site really has, so copy never describes missing features. */
    public function siteFeatures(Site $site): string
    {
        $names = ['app' => 'app download', 'company' => 'company details', 'license' => 'licence details', 'certification' => 'testing/certification details'];

        // Brand sites only: never user accounts, sign-up, log-in, deposits or withdrawals.
        return 'user accounts: no; payments or deposits: no; '
            .collect(SiteStructure::conditions($site))->map(fn ($has, $key) => $names[$key].': '.($has ? 'yes' : 'no'))->implode('; ');
    }

    /** The reference site, read once per run (a broken one never stops generation). */
    protected function analyzeReference(Site $site): ?array
    {
        if (! $site->reference_url) {
            return null;
        }
        if ($this->analyzedUrl !== $site->reference_url) {
            $this->analyzedUrl = $site->reference_url;
            $this->referencePages = [];
            $this->referenceData = rescue(fn () => $this->reference->analyze($site->reference_url), null);
        }

        return $this->referenceData;
    }

    /**
     * Tagline and intro left empty (or too short) in the admin are written
     * by the AI from the reference site's home and inner pages; other empty
     * profile facts (features, specs, FAQ) are filled the same way. Fields
     * the admin filled are never overwritten.
     */
    public function completeBrand(Site $site): ?string
    {
        $profile = $site->brandProfile ?? $site->brandProfile()->create(['product_name' => $site->product_name]);
        if (filled($profile->tagline) && mb_strlen((string) $profile->description) >= 200) {
            return null;
        }
        if (! $site->reference_url) {
            return '品牌资料的一句话介绍或详细介绍为空，且没有参考网站，无法自动补全';
        }
        if (! ($home = $this->analyzeReference($site))) {
            return '参考网站读不到，品牌资料无法自动补全';
        }
        $this->referencePages = rescue(fn () => $this->reference->crawl($home), []);

        try {
            $data = app(BrandImporter::class)->normalize($this->ai->prompt(PromptType::BrandComplete, [
                'brand' => $profile->toPromptContext(),
                'reference' => SiteDataGenerator::digest($this->referenceSources(), 16_000),
                'reference_name' => (string) ($home['title'] ?? '') ?: (string) parse_url($site->reference_url, PHP_URL_HOST),
            ], $site)->json());
        } catch (Throwable $e) {
            return '品牌资料自动补全失败：'.Str::limit($e->getMessage(), 1000);
        }

        $lang = $site->language();
        $text = fn ($v) => is_string($v) && trim($v) !== '' && LanguageDetector::matches($v, $lang) ? trim(strip_tags($v)) : null;
        $fill = [];
        if (blank($profile->tagline) && ($v = $text($data['tagline'] ?? null))) {
            $fill['tagline'] = SeoHead::limit($v, 200);
        }
        if (mb_strlen((string) $profile->description) < 200 && ($v = $text($data['description'] ?? null)) && mb_strlen($v) > mb_strlen((string) $profile->description)) {
            $fill['description'] = $v;
        }
        foreach (['selling_points', 'specs', 'faq'] as $key) {
            if (empty($profile->{$key}) && ! empty($data[$key])) {
                $fill[$key] = $data[$key];
            }
        }
        foreach (['target_audience', 'use_cases'] as $key) {
            if (blank($profile->{$key}) && ($v = $text($data[$key] ?? null))) {
                $fill[$key] = $v;
            }
        }
        if ($fill === []) {
            return '参考网站上没有可用于品牌介绍的内容，品牌资料未补全';
        }
        $profile->update($fill);
        $site->setRelation('brandProfile', $profile);

        return '品牌资料已从参考网站补全：'.implode('、', array_map(fn ($k) => ['tagline' => '一句话介绍', 'description' => '详细介绍', 'selling_points' => '卖点', 'specs' => '参数', 'faq' => 'FAQ', 'target_audience' => '目标用户', 'use_cases' => '使用场景'][$k], array_keys($fill)));
    }

    /** Reference pages read in this run: home first. */
    protected function referenceSources(): array
    {
        if (! $this->referenceData) {
            return [];
        }

        return [['label' => 'home'] + $this->referenceData, ...$this->referencePages];
    }

    /**
     * Sources a figure or claim may come from: the brand profile, and the
     * reference site's own text (its amounts, dates and terms are kept).
     */
    public function facts(Site $site): string
    {
        $reference = collect($this->referenceSources())->map(fn ($p) => ($p['content'] ?? '')."\n".collect($p['cards'] ?? [])->pluck('name')->implode("\n"))->implode("\n");

        return trim($this->brandContext($site)."\n".$reference);
    }

    /**
     * Homepage title and description. What the admin typed is used exactly as
     * written; only an empty field is filled (by AI, as returned, nothing
     * joined to it).
     */
    public function ensureMeta(Site $site): void
    {
        if (filled($site->title) && filled($site->description)) {
            return;
        }
        $title = trim((string) $site->title);
        $description = trim((string) $site->description);
        try {
            $res = $this->ai->prompt(PromptType::SiteMeta, ['brand' => $this->brandContext($site)], $site)->json();
            if ($title === '') {
                $title = Str::limit(trim(strip_tags((string) ($res['title'] ?? ''))), 250, '');
            }
            if ($description === '') {
                $description = trim(strip_tags(SeoRules::stripClaims((string) ($res['description'] ?? ''), $this->brandContext($site))));
            }
        } catch (Throwable) {
            // Fallbacks below.
        }

        $profile = $site->brandProfile;
        if ($title === '') {
            $title = $site->product_name;
        }
        if ($description === '') {
            $description = SeoHead::limit((string) ($profile?->description ?: $profile?->tagline), 300);
        }
        $site->forceFill(['title' => $title, 'description' => $description ?: null])->save();
    }

    /**
     * AI design config, validated; seeded default as fallback. With a reference
     * site its palette, fonts and menu are read directly and enforced on the
     * result, screenshots are added when Chromium and a vision model exist.
     */
    public function generateDesign(Site $site, ?string $feedback = null, ?array $onlyPages = null): array
    {
        $validator = new DesignConfigValidator;
        $config = null;
        $customCss = null;
        $this->designNotes = [];

        // A broken reference must never stop the site from being generated.
        $reference = $this->analyzeReference($site);
        if ($site->reference_url) {
            foreach ($this->reference->report($reference) as $line) {
                $this->designNotes[] = '参考网站 · '.$line;
            }
            if (! $reference) {
                $this->designNotes[] = $site->reference_images ? '参考网站读不到，改用上传的参考截图' : '参考网站读不到，可在「参考截图」上传几张首页截图代替';
            }
        }
        $this->referenceData = $reference;

        // Uploaded screenshots: their colours fill whatever the HTML/CSS analysis could not find.
        $shotPaths = collect($site->reference_images ?? [])->map(fn ($p) => Media::localPath($p))->filter(fn ($p) => $p && is_file($p))->values()->all();
        if ($shotPaths !== [] && ($fromImages = rescue(fn () => $this->reference->imageColors($shotPaths), null))) {
            $reference ??= ['url' => $site->reference_url, 'title' => null, 'colors' => [], 'fonts' => [], 'menu' => [], 'sections' => [], 'structure' => []];
            foreach (['primary', 'background', 'text'] as $key) {
                $reference['colors'][$key] = ($reference['colors'][$key] ?? null) ?: $fromImages[$key];
            }
            $reference['colors']['palette'] = array_values(array_unique(array_merge($reference['colors']['palette'] ?? [], $fromImages['palette'])));
        }

        if ($site->reference_import_images && ! empty($this->referenceData['images'])) {
            $count = rescue(fn () => $this->images->import($site, $this->referenceData['images']), 0);
            $this->designNotes[] = '参考网站图片：发现 '.count($this->referenceData['images'])." 张，新导入 {$count} 张到".(Media::configured() ? '对象存储' : '本地存储');
            $site->unsetRelation('brandProfile');
        }

        if ($site->reference_rewrite_content) {
            $chars = mb_strlen((string) ($this->referenceData['content'] ?? ''));
            $this->designNotes[] = $chars > 0 ? "参考网站正文 {$chars} 字，将交给 AI 二次创作页面内容" : '参考网站没有读到正文，页面内容只按品牌资料生成';
        }

        try {
            $images = $site->reference_url && $this->reference->browserAvailable() ? $this->screenshot->capture($site->reference_url) : [];
            $captured = count($images);
            foreach (array_slice(array_filter($shotPaths, fn ($p) => filesize($p) < 4_000_000), 0, 3) as $path) {
                $images[] = 'data:'.(mime_content_type($path) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($path));
            }
            $vision = AiProvider::where('is_active', true)->where('supports_vision', true)->exists();
            if ($images !== []) {
                $this->designNotes[] = $vision
                    ? '发给 AI 的截图：自动截图 '.$captured.' 张，上传 '.(count($images) - $captured).' 张'
                    : '有 '.count($images).' 张截图，但没有勾选"支持视觉"的 AI 接口，AI 看不到截图（到 AI 接口里勾选并使用支持图片的模型）';
            } elseif ($site->reference_url) {
                $this->designNotes[] = '没有截图发给 AI：'.($this->reference->browserAvailable() ? '自动截图失败（'.(implode('；', array_unique($this->screenshot->errors)) ?: '未知原因').'）' : '服务器未安装 Chromium').'，也没有上传参考截图';
            }
            if (! $vision) {
                $images = [];
            }
            // The design prompt gets the structure, not the full text and image list.
            $forDesign = $reference ? array_merge(Arr::except($reference, ['images', 'content']), ['content_excerpt' => Str::limit((string) ($reference['content'] ?? ''), 1500)]) : null;
            $vars = [
                'brand' => $this->brandContext($site),
                'logo_color' => $this->logoColor($site) ?? 'unknown',
                'whitelist' => json_encode($this->whitelist($site), JSON_UNESCAPED_UNICODE),
                'feedback' => $feedback ?? '',
                'reference_url' => $site->reference_url ?? '',
                'reference' => $forDesign ? json_encode($forDesign, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'none',
            ];
            // A cut-off or malformed answer is asked again once, shorter.
            for ($attempt = 1; ; $attempt++) {
                try {
                    $response = $this->ai->prompt(PromptType::DesignConfig, $vars, $site, $images);
                    $res = $response->json();
                    break;
                } catch (AiException $e) {
                    if ($attempt >= 2) {
                        throw $e;
                    }
                    $this->designNotes[] = 'AI 设计返回的 JSON 不完整或格式有误，已要求精简后重试';
                    $vars['feedback'] = trim($vars['feedback'].' Your previous answer was cut off or was not valid JSON. Return one compact JSON object: keep custom_css under 3000 characters and escape line breaks and quotes inside strings.');
                }
            }
            // CSS cut off mid-rule is worse than none.
            $customCss = $response->truncated ? null : ($res['custom_css'] ?? null);
            if ($response->truncated) {
                $this->designNotes[] = 'AI 设计输出被截断，已保留配色和版式，丢弃不完整的自定义 CSS';
            }
            $config = $res['design'] ?? $res;
        } catch (Throwable $e) {
            $this->designNotes[] = 'AI 设计生成失败，已用默认设计：'.Str::limit($e->getMessage(), 1000);
            $config = null;
        }

        $config ??= DefaultDesign::make($site->domain_id.'-'.$site->design_version, $site->typeConfig());
        if ($reference) {
            $config = $this->applyReference($config, $reference);
        }
        // The look is the site's own (header, fonts, corners, block variants…), never the reference's or the model's.
        // Generating the template again gives a new look: a new round of the seed, and the old picks count as the
        // most used, so every option that has an alternative changes. A redesign of a few pages keeps the look.
        $others = Site::whereKeyNot($site->id)->whereNotNull('design_config')->get(['id', 'design_config'])->pluck('design_config');
        $old = (array) ($site->design_config['look'] ?? []);
        $fresh = $onlyPages === null;
        $round = (int) ($old['round'] ?? 0) + ($fresh && $site->design_config ? 1 : 0);
        if ($fresh && $old !== []) {
            $others = $others->concat(array_fill(0, $others->count() + 1, ['look' => $old]));
        }
        // Validated first so every page and section is listed and gets the look's variant, not only the ones the model named.
        $config = SiteStyle::for(($site->domain_id ?: $site->id).($round ? "#{$round}" : ''), $others, $fresh ? [] : $old)->apply((new DesignConfigValidator)->validate($config, $site->typeConfig()) + $config);
        $config['look']['round'] = $round;
        $validated = $validator->validate($config, $site->typeConfig());

        if ($onlyPages !== null && $site->design_config) {
            $current = $validator->validate($site->design_config, $site->typeConfig());
            foreach ($onlyPages as $type) {
                $current['pages'][$type] = $validated['pages'][$type] ?? $current['pages'][$type];
            }
            $validated = $current;
        }

        $customCss = $customCss !== null ? CustomCssValidator::mobileSafe($customCss) : null;
        if ($customCss !== null && CustomCssValidator::errors($customCss) !== []) {
            $customCss = null;
        }

        $this->saveDesign($site, $validated, $customCss ?? $site->custom_css, 'ai');

        return $validator->repaired;
    }

    /** The reference's own colours, header behaviour and home sections win over whatever the model picked. */
    protected function applyReference(array $config, array $reference): array
    {
        foreach (['primary', 'background', 'text'] as $key) {
            if ($color = $reference['colors'][$key] ?? null) {
                $config['colors'][$key] = $color;
            }
        }
        if ($bg = $reference['colors']['background'] ?? null) {
            if (empty($reference['colors']['text'])) {
                $config['colors']['text'] = Color::luminance($bg) > 0.4 ? '#1a1a1a' : '#f2f2f2';
            }
            // Surface follows the reference background so cards don't clash with it.
            $config['colors']['surface'] = Color::mix($bg, Color::luminance($bg) > 0.4 ? '#000000' : '#ffffff', 0.05);
        }

        // Navigation behaves like the reference's (at least one download button stays).
        if ($nav = $reference['navigation'] ?? null) {
            $config['navigation'] = array_merge((array) ($config['navigation'] ?? []), [
                'buttons' => max(1, (int) $nav['buttons']),
                'mobile_bar' => (bool) $nav['mobile_bar'],
                'sticky' => (bool) $nav['sticky'] || ! empty($config['navigation']['sticky']),
                'home_link' => (bool) $nav['home_link'],
            ]);
            if (! $nav['dropdown']) {
                $config['navigation']['submenu'] = [];
            }
        }

        // Home follows the reference's section order; the model's variants are kept.
        $structure = array_values(array_filter($reference['structure'] ?? [], fn ($b) => isset(config('theme.blocks')[$b])));
        if (count($structure) >= 3) {
            $chosen = collect($config['pages']['home'] ?? [])->filter(fn ($e) => is_array($e))->keyBy('block');
            $home = array_map(fn ($b) => ['block' => $b, 'variant' => $chosen[$b]['variant'] ?? null], $structure);
            if (! array_intersect(array_slice($structure, 0, 2), ['banner', 'hero'])) {
                array_unshift($home, $chosen['hero'] ?? ['block' => 'hero', 'variant' => null]);
            }
            foreach ($chosen as $block => $entry) {
                if (! in_array($block, array_column($home, 'block'), true)) {
                    $home[] = $entry;
                }
            }
            $config['pages']['home'] = $home;
        }

        return $config;
    }

    /**
     * Brand profiles are often typed in Chinese; the fields the theme shows
     * directly (tagline, selling points, FAQ…) get a site-language copy.
     */
    public function localizeBrand(Site $site): ?string
    {
        $profile = $site->brandProfile;
        if (! $profile || $profile->localized_hash === ($hash = $profile->displayHash($site->locale))) {
            return null;
        }

        // Only fields not already in the site language are sent for translation.
        $fields = array_filter($profile->only(BrandProfile::DISPLAY_FIELDS), fn ($v) => filled($v)
            && ! LanguageDetector::matches(collect(Arr::wrap($v))->flatten()->filter(fn ($x) => is_string($x))->implode("\n"), $site->language()));
        if ($fields === []) {
            $profile->updateQuietly(['localized' => null, 'localized_hash' => $hash]);

            return null;
        }

        try {
            $data = $this->ai->prompt(PromptType::BrandTranslate, ['profile' => json_encode($fields, JSON_UNESCAPED_UNICODE)], $site)->json();
            $profile->updateQuietly(['localized' => array_intersect_key($data, $fields), 'localized_hash' => $hash]);

            return "品牌资料不是{$site->locale}，已转为站点语言用于页面展示（后台资料保持原样）";
        } catch (Throwable $e) {
            return '品牌资料转为站点语言失败：'.Str::limit($e->getMessage(), 1000);
        }
    }

    public function saveDesign(Site $site, array $config, ?string $customCss, string $source): void
    {
        $version = (int) $site->designVersions()->max('version') + 1;
        DesignVersion::create(['site_id' => $site->id, 'version' => $version, 'config' => $config, 'custom_css' => $customCss, 'source' => $source]);
        $site->update(['design_config' => $config, 'design_version' => $version, 'custom_css' => $customCss]);
    }

    /**
     * One AI call for all core pages of the site type; defaults fill any gaps.
     * Pages that come back thin, too close to the reference or to another
     * site's page get one more attempt with that feedback; still failing,
     * they are published as noindex so they never count against the site.
     */
    public function generatePages(Site $site): void
    {
        $types = $site->typeConfig()['pages'];
        $lang = $site->language();
        ThemeStrings::load($lang);
        $facts = $this->facts($site);

        // Labels in the site language: the admin's Chinese labels would be copied into page titles.
        // Ask for content of the blocks the design actually uses on each page (pool pages: of each section).
        $designBlocks = fn ($type, $p) => collect($site->design_config['pages'][$type] ?? [])->pluck('block')->unique()->values()->all() ?: $p['blocks'];
        $spec = collect($types)->map(fn ($p, $type) => array_filter([
            'label' => $this->defaultTitle($type, $p, $lang),
            'default_slug' => $p['slug'][$lang] ?? $p['slug']['en'],
            'blocks' => isset($p['sections']) ? null : $designBlocks($type, $p),
            'sections' => isset($p['sections']) ? collect($p['sections'])->mapWithKeys(fn ($s) => [$s['key'] => ['block' => $s['block'], 'topic' => $s['topic'].(empty($s['source']) ? '' : ' [sources only]')]])->all() : null,
            'min_words' => $this->minWords($site, $type) ?: null,
        ]))->all();
        $ai = $this->askPages($site, $spec);

        // A page that comes back thin or off gets another attempt with the reason;
        // a thin page gets a second one, since an empty column looks broken.
        $problems = $this->pageProblems($site, $ai, $facts);
        for ($round = 1; $problems !== [] && $round <= 2; $round++) {
            $retry = array_intersect_key($spec, $round === 1 ? $problems : array_filter($problems, fn ($why) => str_starts_with($why, 'Only ')));
            if ($retry === []) {
                break;
            }
            foreach ($retry as $type => $_) {
                $retry[$type]['feedback'] = $problems[$type];
            }
            foreach ($this->askPages($site, $retry) as $type => $data) {
                if (! is_array($data) || ! isset($retry[$type])) {
                    continue;
                }
                $left = $this->pageProblems($site, [$type => $data], $facts)[$type] ?? null;
                // Keep the longer answer even when it is still short.
                if ($left === null || SeoRules::wordCount($this->cleanBlocks($data['blocks'] ?? [], $type, $site, $facts)) > SeoRules::wordCount($this->cleanBlocks($ai[$type]['blocks'] ?? [], $type, $site, $facts))) {
                    $ai[$type] = $data;
                }
                if ($left === null) {
                    unset($problems[$type]);
                } else {
                    $problems[$type] = $left;
                }
            }
        }
        // Pages without a length target stay indexed when the extra attempts still came back short.
        $problems = array_filter($problems, fn ($why, $type) => ! str_starts_with($why, 'Only ') || $this->minWords($site, $type) > 0, ARRAY_FILTER_USE_BOTH);

        $usedSlugs = [];
        $titles = [];
        foreach ($types as $type => $def) {
            $data = is_array($ai[$type] ?? null) ? $ai[$type] : [];
            $existing = $site->pages()->where('type', $type)->first();

            $defaultSlug = $def['slug'][$lang] ?? $def['slug']['en'];
            $slug = $type === 'home' ? '' : $this->cleanSlug($data['slug'] ?? '', $defaultSlug);
            if ($existing && $existing->slug !== '' && $site->launched_at) {
                $slug = $existing->slug; // never move URLs of a live site
            }
            if ($type !== 'home' && in_array($slug, $usedSlugs, true)) {
                $slug = $defaultSlug;
            }
            $usedSlugs[] = $slug;

            $title = is_string($data['title'] ?? null) && LanguageDetector::matches($data['title'], $lang)
                ? SeoRules::bareTitle(strip_tags($data['title']), [$site->product_name, $site->name]) : '';
            // A column name never carries the brand; a name that was only the brand takes the default.
            $title = $title !== '' ? $title : $this->defaultTitle($type, $def, $lang);
            // Two pages about the same thing (e.g. "About the game" and "About us") get distinct titles.
            foreach ($titles as $other) {
                if (SeoRules::titleOverlap($title, $other, $site->product_name) >= 0.6) {
                    $title = $this->defaultTitle($type, $def, $lang);
                    break;
                }
            }
            $titles[] = $title;
            $content = $this->cleanBlocks($data['blocks'] ?? [], $type, $site, $facts);
            if ($type === 'home') {
                $content = $this->homeHeading($site, $content);
            }

            // The page's <title> is its column name as is (home: the admin's site title).
            $metaTitle = $type === 'home' ? ($site->title ?: $title) : $title;
            $metaDescription = $type === 'home' ? (string) $site->description : SeoRules::stripClaims((string) ($data['meta_description'] ?? ''), $facts);
            if (SeoRules::weakDescription($metaDescription, $site->product_name)) {
                $metaDescription = $this->excerpt($content, $this->slots($site, $type)) ?: (string) $site->description;
            }

            $site->pages()->updateOrCreate(['type' => $type], [
                'slug' => $slug,
                'title' => Str::limit(strip_tags($title), 120, ''),
                'meta_title' => trim(strip_tags($metaTitle)),
                'meta_description' => SeoHead::limit($metaDescription, 155),
                'content' => $content,
                'noindex' => isset($problems[$type]),
            ]);
        }

        // Pages the site type or design no longer has (extra pages, a changed site type).
        $site->pages()->whereNotIn('type', array_keys($types))->get()->each(function (Page $page) use ($site) {
            if ($site->launched_at) {
                GoneUrl::firstOrCreate(['host' => $site->domain->name, 'path' => trim($page->slug, '/')]);
            }
            $page->delete();
        });
    }

    /**
     * Pool sites have many pages: they are asked for a few at a time so the
     * answer stays complete.
     *
     * @return array<string, mixed> page type => AI page data
     */
    protected function askPages(Site $site, array $spec): array
    {
        $chunks = ! empty($site->typeConfig()['sectioned']) ? array_chunk($spec, 4, true) : [$spec];
        $pages = [];
        foreach ($chunks as $chunk) {
            try {
                $pages += (array) ($this->ai->prompt(PromptType::PageContent, [
                    'brand' => $this->brandContext($site),
                    'pages' => json_encode($chunk, JSON_UNESCAPED_UNICODE),
                    'reference_content' => $this->referenceContent($site),
                    'site_features' => $this->siteFeatures($site),
                ], $site)->json()['pages'] ?? []);
            } catch (Throwable) {
                // Defaults fill the pages of a failed chunk.
            }
        }

        return $pages;
    }

    /** Words a page needs; pool presets set their own per page (scaled by the global setting). */
    public function minWords(Site $site, string $type): int
    {
        $def = $site->typeConfig()['pages'][$type] ?? [];
        if (isset($def['min_words'])) {
            return (int) round($def['min_words'] * (int) config('platform.page_min_words', 400) / 400);
        }

        return SeoRules::minWords($type);
    }

    /** Whether a page has blocks whose copy the AI writes (not only search, sitemap, legal or other self-filling blocks). */
    protected function writesCopy(Site $site, string $type): bool
    {
        $def = $site->typeConfig()['pages'][$type] ?? [];
        $blocks = isset($def['sections']) ? array_column($def['sections'], 'block') : (collect($site->design_config['pages'][$type] ?? [])->pluck('block')->all() ?: ($def['blocks'] ?? []));

        return collect($blocks)->contains(fn ($b) => ! in_array($b, SiteRenderer::SELF_FILLING, true) && $b !== 'entry_list');
    }

    /** @return array<string, string> content slot => block of a page type (pool presets: section key => block). */
    protected function slots(Site $site, string $type): array
    {
        $def = $site->typeConfig()['pages'][$type] ?? [];
        if (isset($def['sections'])) {
            return array_column($def['sections'], 'block', 'key');
        }
        $blocks = ['hero', 'features', 'faq', 'cta', 'prose', 'gallery', 'specs', 'article_list', 'contact', 'legal', 'banner', 'card_grid', 'steps', 'stats', 'notice', 'download'];

        return array_combine($blocks, $blocks);
    }

    /**
     * Why each generated page is not good enough to index: too short, or
     * too similar to the reference site or to the same page of another site.
     *
     * @return array<string, string> page type => feedback for the model
     */
    public function pageProblems(Site $site, array $pages, string $facts): array
    {
        $problems = [];
        $reference = $site->reference_rewrite_content ? (string) ($this->referenceData['content'] ?? '') : '';
        $limit = (float) Settings::get('page_similarity', 0.6);
        foreach ($pages as $type => $data) {
            if (! is_array($data) || ! is_array($data['blocks'] ?? null)) {
                continue;
            }
            $content = $this->cleanBlocks($data['blocks'], $type, $site, $facts);
            $words = SeoRules::wordCount($content);
            $min = $this->minWords($site, $type);
            // Pages exempt from a length target still need more than a heading and one sentence when they have copy blocks.
            if ($min === 0 && $this->writesCopy($site, $type)) {
                $min = SeoRules::THIN_WORDS;
            }
            if ($min > 0 && $words < $min * 0.8) {
                $problems[$type] = "Only {$words} words; write at least {$min} words of useful, specific copy for this page: use the reference content for topics, details and facts so every section is full, and give each list section an intro that explains it.";

                continue;
            }
            if (! LanguageDetector::matches($this->plainText($content), $site->language())) {
                $problems[$type] = "Not written in {$site->languageName()}; write every block, heading and button in {$site->languageName()} only.";

                continue;
            }
            $exempt = isset($site->typeConfig()['pages'][$type]['min_words']) ? (int) $site->typeConfig()['pages'][$type]['min_words'] === 0 : in_array($type, SeoRules::NO_MIN_WORDS, true);
            if ($exempt) {
                continue;
            }
            $text = $this->plainText($content);
            $mine = Similarity::fingerprint($text);
            if ($reference !== '' && ($score = Similarity::compare($mine, Similarity::fingerprint($reference))) >= $limit) {
                $problems[$type] = sprintf('Too close to the reference site (similarity %.2f); rewrite with your own structure and wording.', $score);

                continue;
            }
            $others = Page::query()->where('type', $type)->where('site_id', '!=', $site->id)
                ->whereIn('site_id', Site::where('locale', $site->locale)->select('id'))->latest('id')->limit(300)->pluck('content');
            foreach ($others as $other) {
                if (is_array($other) && ($score = Similarity::compare($mine, Similarity::fingerprint($this->plainText($other)))) >= $limit) {
                    $problems[$type] = sprintf('Too similar to the same page on another site (similarity %.2f); write different angles and wording.', $score);
                    break;
                }
            }
        }

        return $problems;
    }

    protected function plainText(array $content): string
    {
        return collect($content)->flatten()->filter(fn ($v) => is_string($v))->map(fn ($v) => strip_tags($v))->implode(' ');
    }

    /** First real sentence(s) of a page, for its meta description. */
    protected function excerpt(array $content, array $slots): string
    {
        $fields = ['hero' => 'subheading', 'prose' => 'html', 'features' => 'intro', 'card_grid' => 'intro', 'entry_list' => 'intro', 'steps' => 'intro', 'cta' => 'text', 'contact' => 'html', 'legal' => 'html', 'faq' => 'heading'];
        foreach ($fields as $block => $field) {
            foreach (array_keys($slots, $block, true) as $key) {
                $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($content[$key][$field] ?? ''))));
                if (mb_strlen($text) >= 70) {
                    return SeoHead::limit($text, 155);
                }
            }
        }

        return '';
    }

    /**
     * The home <h1> names the product and what it is: seasonal or promo
     * headlines move to the eyebrow, the SEO title takes their place.
     */
    protected function homeHeading(Site $site, array $content): array
    {
        $slots = $this->slots($site, 'home');
        $hero = array_search('hero', $slots, true) ?: 'hero';
        $banner = array_search('banner', $slots, true) ?: 'banner';
        $block = isset($content[$hero]) || ! isset($content[$banner]) ? $hero : $banner;
        $heading = (string) ($content[$block]['heading'] ?? '');
        if ($heading === '' || SeoRules::seasonal($heading) || ! SeoRules::mentions($heading, $site->product_name)) {
            if ($heading !== '' && empty($content[$block]['eyebrow']) && mb_strlen($heading) <= 40) {
                $content[$block]['eyebrow'] = $heading;
            }
            $content[$block]['heading'] = Str::limit((string) ($site->title ?: $site->product_name), 120, '');
        }

        return $content;
    }

    /** Reference text for AI rewriting, when the site's switch is on. */
    protected function referenceContent(Site $site): string
    {
        if (! $site->reference_rewrite_content || ! $site->reference_url) {
            return 'none';
        }
        $this->referenceData ??= rescue(fn () => $this->reference->analyze($site->reference_url), null);
        if ($this->referencePages !== []) {
            return SiteDataGenerator::digest($this->referenceSources(), 12_000);
        }
        $content = trim((string) ($this->referenceData['content'] ?? ''));

        return $content !== '' ? Str::limit($content, 12_000) : 'none';
    }

    protected function defaultTitle(string $type, array $def, string $lang): string
    {
        // Never the admin's Chinese label: it would end up as a page title and menu item.
        return Lang::has("theme.page_titles.{$type}", $lang) ? __("theme.page_titles.{$type}", [], $lang) : Str::headline($type);
    }

    public function expandKeywords(Site $site): int
    {
        if (($site->brandProfile?->completeness ?? 0) < (int) Settings::get('min_completeness', 60)) {
            return 0;
        }
        try {
            $rows = $this->ai->prompt(PromptType::KeywordExpand, ['brand' => $this->brandContext($site), 'count' => 60], $site)->json()['keywords'] ?? [];
        } catch (Throwable) {
            return 0;
        }
        $created = 0;
        foreach ($rows as $row) {
            $kw = is_array($row) ? ($row['keyword'] ?? null) : $row;
            if (! is_string($kw) || mb_strlen(trim($kw)) < 3) {
                continue;
            }
            $model = $site->keywords()->firstOrCreate(['keyword' => mb_strtolower(trim($kw))], [
                'intent' => in_array($row['intent'] ?? '', ['informational', 'navigational', 'commercial', 'transactional'], true) ? $row['intent'] : 'informational',
                'source' => 'ai',
            ]);
            $created += $model->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    public function render(Site $site, ?GenerationTask $task = null): void
    {
        $site->refresh()->load(['domain', 'brandProfile']);
        $task?->update(['pages_total' => $site->pages()->count()]);
        $this->builder->buildAll($site, function ($step, $done, $total) use ($task) {
            $task?->update(['pages_done' => $done, 'pages_total' => $total]);
        });
    }

    /** Blocks that make a page heavy (pictures, sliders, long lists); pages without them all score like each other. */
    public const HEAVY_BLOCKS = ['hero', 'banner', 'gallery', 'card_grid', 'entry_list', 'game_lobby', 'download', 'article_list', 'event_calendar'];

    /** Pages audited per round at most, and minutes the acceptance may take. */
    public const ACCEPTANCE_PAGES = 10;

    public const ACCEPTANCE_MINUTES = 10;

    /**
     * Lighthouse a sample of the pages: home, one page per distinct heavy
     * composition and one plain page (the others share layout and CSS and
     * score the same). Pages that are noindex on purpose (an empty list, the
     * 404) count for speed only. Failing compositions are redesigned and only
     * they are audited again; a site that still misses the bar goes live with
     * a warning instead of failing.
     */
    public function acceptance(Site $site, GenerationTask $task): void
    {
        $dir = StaticSiteBuilder::dirFor($site);
        $paths = $site->pages()->pluck('slug')->all();
        foreach (SchemaValidator::checkSite($dir, $paths) as $path => $problems) {
            $task->progress("结构化数据 {$path}：".implode('；', $problems));
        }

        if (! Lighthouse::available()) {
            $task->progress('未安装 Lighthouse，跳过验收');

            return;
        }

        $min = config('platform.lighthouse');
        $deadline = microtime(true) + 60 * self::ACCEPTANCE_MINUTES;
        $sample = $this->acceptanceSample($site->refresh());
        $all = count($site->pages()->pluck('type')) + 2;
        $task->progress('Lighthouse 抽查 '.count($sample).' 页（共 '.$all.' 页，版式相同的页面只测一页）');

        for ($attempt = 1; $attempt <= $min['max_attempts']; $attempt++) {
            $results = $this->lighthouse->auditSite($dir, $sample);
            $failed = collect($results)->filter(fn ($r, $type) => $r !== null
                && ($r['performance'] < $min['min_performance'] || ($r['seo'] < $min['min_seo'] && ! $this->noindex($dir, $sample[$type]))));
            $task->progress("Lighthouse 第 {$attempt} 轮：".collect($results)->map(fn ($r, $t) => $r ? "{$t} P{$r['performance']}/S{$r['seo']}".($this->noindex($dir, $sample[$t]) ? '(noindex)' : '') : "{$t} n/a")->implode(', '));
            if ($failed->isEmpty()) {
                return;
            }
            // Only page compositions can be redesigned; the 404 and the blog list share the layout.
            $fixable = $failed->keys()->intersect(array_keys($site->design_config['pages'] ?? []))->values()->all();
            if ($fixable === [] || $attempt === $min['max_attempts'] || microtime(true) > $deadline) {
                break;
            }

            $feedback = 'These page types scored below 90 on mobile Lighthouse: '.implode(', ', $fixable).'. Use fewer, lighter blocks (fewer galleries above the fold).';
            $this->generateDesign($site->refresh(), $feedback, $fixable);
            $this->render($site, $task);
            $sample = array_intersect_key($sample, $failed->all());
        }

        $task->progress('Lighthouse：'.$failed->keys()->implode('、').' 未达到 '.$min['min_performance'].'/'.$min['min_seo'].' 分，网站照常继续（可稍后在后台单独优化）');
    }

    /**
     * @return array<string, string> page type => path
     */
    public function acceptanceSample(Site $site): array
    {
        $pages = $site->pages()->pluck('slug', 'type')->all();
        $design = $site->design_config['pages'] ?? [];
        $sample = ['home' => $pages['home'] ?? ''];
        $seen = [];
        $plain = null;
        foreach ($pages as $type => $slug) {
            if ($type === 'home') {
                continue;
            }
            $blocks = collect($design[$type] ?? [])->filter(fn ($b) => in_array($b['block'] ?? null, self::HEAVY_BLOCKS, true));
            if ($blocks->isEmpty()) {
                $plain ??= $type;

                continue;
            }
            $signature = $blocks->map(fn ($b) => $b['block'].':'.($b['variant'] ?? ''))->sort()->implode(',');
            if (! isset($seen[$signature]) && count($sample) < self::ACCEPTANCE_PAGES - 2) {
                $seen[$signature] = true;
                $sample[$type] = $slug;
            }
        }
        if ($plain !== null) {
            $sample[$plain] = $pages[$plain];
        }

        return $sample + ['blog' => 'blog/'];
    }

    /** Whether a built page asks not to be indexed (its SEO score then says nothing). */
    protected function noindex(string $dir, string $path): bool
    {
        $file = rtrim($dir, '/').'/'.(str_ends_with($path, '.html') ? $path : trim($path, '/').'/index.html');
        $file = str_replace('//', '/', $file);

        return is_file($file) && preg_match('/<meta[^>]+name=["\']robots["\'][^>]+noindex/i', (string) file_get_contents($file, false, null, 0, 20000));
    }

    public function brandContext(Site $site): string
    {
        return $site->brandProfile?->toPromptContext() ?? "Product: {$site->product_name}";
    }

    protected function whitelist(Site $site): array
    {
        $pages = ExtraPages::merge($site->typeConfig(), [])['pages'];

        return [
            'blocks' => collect(config('theme.blocks'))->except(config('theme.layout_blocks'))->all(),
            'nav' => config('theme.blocks.nav'),
            'footer' => config('theme.blocks.footer'),
            'fonts' => array_keys(config('theme.fonts')),
            'radius' => array_keys(config('theme.radius')),
            'density' => array_keys(config('theme.density')),
            'shadow' => array_keys(config('theme.shadow')),
            'style' => config('theme.styles'),
            // Pool pages take their blocks from the site's structure: only the other page types need a layout.
            'page_types' => array_keys(array_filter($pages, fn ($p) => ! isset($p['sections'])) + DesignConfigValidator::SYSTEM_PAGES),
            'menu_items' => array_values(array_merge(array_diff(array_keys($pages), ['home', 'privacy', 'terms']), ['blog'])),
            'extra_pages' => 'up to '.ExtraPages::MAX.' pages named extra_1…extra_'.ExtraPages::MAX.' in the order of "extra_pages"; usable in "menu" and "pages" (blocks: '.implode(', ', ExtraPages::BLOCKS).')',
        ];
    }

    /** Dominant non-neutral colour of the logo, if any. */
    /** Pages whose hero / banner has no real picture get an AI one, drawn in the background. */
    protected function queueArt(Site $site, GenerationTask $task): void
    {
        try {
            $art = app(SiteArt::class);
            if (! $art->canDraw()) {
                return;
            }
            if ($count = count($art->missing($site))) {
                $task->progress("后台生成 {$count} 张 AI 配图，画好后自动更新页面");
                GenerateSiteArtJob::dispatch($site, $task);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function logoColor(Site $site): ?string
    {
        if (! $site->logo_path || ! ($logo = Media::get($site->logo_path))) {
            return null;
        }
        $img = @imagecreatefromstring($logo);
        if (! $img) {
            return null;
        }
        $small = imagescale($img, 32, 32);
        $buckets = [];
        for ($x = 0; $x < 32; $x++) {
            for ($y = 0; $y < 32; $y++) {
                $rgba = imagecolorat($small, $x, $y);
                if ((($rgba >> 24) & 0x7F) > 100) {
                    continue;
                }
                $rgb = [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
                if (max($rgb) - min($rgb) < 30) {
                    continue; // skip greys/white/black
                }
                $key = implode(',', array_map(fn ($c) => intdiv($c, 32) * 32 + 16, $rgb));
                $buckets[$key] = ($buckets[$key] ?? 0) + 1;
            }
        }
        if ($buckets === []) {
            return null;
        }
        arsort($buckets);

        return Color::toHex(explode(',', array_key_first($buckets)));
    }

    protected function cleanSlug(string $slug, string $fallback): string
    {
        $parts = array_filter(array_map(fn ($p) => Str::slug($p), explode('/', strtolower($slug))));
        $clean = implode('/', array_slice($parts, 0, 2));

        return $clean !== '' && mb_strlen($clean) <= 60 ? $clean : $fallback;
    }

    /** Keep only known block fields; sanitize prose HTML; legal fallback text. */
    protected function cleanBlocks(array $blocks, string $type, Site $site, ?string $facts = null): array
    {
        $lang = $site->language();
        $facts ??= $this->facts($site);
        // Unverifiable promises (money, speed, superlatives) are dropped sentence by sentence.
        $str = fn ($v, $len = 300) => is_string($v) ? (Str::limit(SeoRules::stripClaims(trim(strip_tags($v)), $facts), $len, '') ?: null) : null;
        $label = fn ($v) => ($l = $str($v, 40)) !== null && ! SeoRules::accountLabel($l) ? $l : null;
        $out = [];

        foreach ($this->slots($site, $type) as $block => $kind) {
            $b = is_array($blocks[$block] ?? null) ? $blocks[$block] : [];
            $clean = array_filter([
                'heading' => $str($b['heading'] ?? null, 120),
                'subheading' => $str($b['subheading'] ?? null),
                'intro' => $str($b['intro'] ?? null, 400),
                'text' => $str($b['text'] ?? null, 400),
                'cta_label' => $label($b['cta_label'] ?? null),
                'label' => $label($b['label'] ?? null),
                'eyebrow' => $str($b['eyebrow'] ?? null, 40),
                'cite' => $str($b['cite'] ?? null, 80),
                'html' => isset($b['html']) && is_string($b['html']) ? (SeoRules::stripClaimsHtml(HtmlSanitizer::clean($b['html']), $facts) ?: null) : null,
            ]);
            if (isset($b['points']) && is_array($b['points'])) {
                $clean['points'] = collect($b['points'])->map(fn ($p) => $str($p, 40))->filter()->values()->take(4)->all() ?: null;
                $clean = array_filter($clean);
            }
            if (isset($b['items']) && is_array($b['items'])) {
                $clean['items'] = collect($b['items'])->filter(fn ($i) => is_array($i))->map(fn ($i) => array_filter([
                    'title' => $str($i['title'] ?? null, 120),
                    'text' => $str($i['text'] ?? null, 400),
                    'tag' => $str($i['tag'] ?? null, 20),
                    'question' => $str($i['question'] ?? null, 200),
                    'answer' => $str($i['answer'] ?? null, 800),
                ]))->filter()->values()->take(in_array($kind, ['glossary', 'table'], true) ? 24 : 12)->all();
            }
            if ($clean !== []) {
                $out[$block] = $clean;
            }
        }

        // Legal pages without copy get the generic text of their kind.
        foreach (array_keys($this->slots($site, $type), 'legal', true) as $key) {
            if (empty($out[$key]['html']) && Lang::has("theme.{$type}_html", $lang)) {
                $out[$key]['html'] = __("theme.{$type}_html", ['site' => e($site->name), 'product' => e($site->product_name)], $lang);
            }
        }

        return $out;
    }
}
