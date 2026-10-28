<?php

namespace App\Services\Sites;

use App\Enums\PromptType;
use App\Enums\SiteStatus;
use App\Models\AiProvider;
use App\Models\Alert;
use App\Models\BrandProfile;
use App\Models\DesignVersion;
use App\Models\GenerationTask;
use App\Models\GoneUrl;
use App\Models\Page;
use App\Models\Site;
use App\Services\Ai\AiManager;
use App\Services\Content\LanguageDetector;
use App\Services\Integrations\Lighthouse;
use App\Services\Integrations\Screenshot;
use App\Services\Render\HtmlSanitizer;
use App\Services\Render\SeoHead;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Theme\Color;
use App\Services\Theme\CustomCssValidator;
use App\Services\Theme\DefaultDesign;
use App\Services\Theme\DesignConfigValidator;
use App\Services\Theme\ExtraPages;
use App\Services\Theme\ReferenceAnalyzer;
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
    ) {}

    /** Reference analysis of the current run, reused for page content. */
    protected ?array $referenceData = null;

    public function run(GenerationTask $task): void
    {
        $site = $task->site()->with(['domain', 'brandProfile'])->first();
        $task->update(['status' => 'running', 'started_at' => now()]);
        $site->update(['status' => SiteStatus::Generating]);

        $designOnly = $task->type === 'design';

        try {
            if (! $designOnly) {
                $task->progress('生成标题与描述');
                $this->ensureMeta($site);
            }

            if ($note = $this->localizeBrand($site)) {
                $task->progress($note);
            }

            $task->progress('生成设计配置');
            $this->generateDesign($site);
            foreach ($this->designNotes as $note) {
                $task->progress($note);
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

            $task->progress('Lighthouse 验收');
            $this->acceptance($site, $task);

            $launch = (bool) Settings::get('auto_launch', true);
            $site->update([
                'status' => $launch ? SiteStatus::Live : SiteStatus::Preview,
                'launched_at' => $launch ? ($site->launched_at ?? now()) : $site->launched_at,
            ]);
            $task->progress($launch ? '生成成功，已上线' : '生成成功，等待手动上线');
            $task->update(['status' => 'succeeded', 'finished_at' => now()]);
        } catch (Throwable $e) {
            report($e);
            $site->update(['status' => SiteStatus::Failed]);
            $task->progress('失败：'.$e->getMessage());
            $task->update(['status' => 'failed', 'finished_at' => now()]);
            Alert::raise('generation_failed', "站点 {$site->name} 生成失败：".Str::limit($e->getMessage(), 200), $site->id, 'danger');

            throw $e;
        }
    }

    public function ensureMeta(Site $site): void
    {
        if (filled($site->title) && filled($site->description)) {
            return;
        }
        try {
            $res = $this->ai->prompt(PromptType::SiteMeta, ['brand' => $this->brandContext($site)], $site)->json();
            $title = (string) ($res['title'] ?? '');
            if (! Str::contains(Str::lower($title), Str::lower($site->product_name))) {
                $title = $site->product_name.' – '.$title;
            }
            $site->title = $site->title ?: SeoHead::limit($title, 60);
            $site->description = $site->description ?: SeoHead::limit((string) ($res['description'] ?? ''), 155);
        } catch (Throwable) {
            $site->title = $site->title ?: SeoHead::limit($site->product_name.' – '.($site->brandProfile?->tagline ?? $site->name), 60);
            $site->description = $site->description ?: SeoHead::limit((string) ($site->brandProfile?->tagline ?: $site->brandProfile?->description), 155);
        }
        $site->save();
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
        $reference = $site->reference_url ? rescue(fn () => $this->reference->analyze($site->reference_url), null) : null;
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
            $res = $this->ai->prompt(PromptType::DesignConfig, [
                'brand' => $this->brandContext($site),
                'logo_color' => $this->logoColor($site) ?? 'unknown',
                'whitelist' => json_encode($this->whitelist($site), JSON_UNESCAPED_UNICODE),
                'feedback' => $feedback ?? '',
                'reference_url' => $site->reference_url ?? '',
                'reference' => $forDesign ? json_encode($forDesign, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'none',
            ], $site, $images)->json();
            $customCss = $res['custom_css'] ?? null;
            $config = $res['design'] ?? $res;
        } catch (Throwable $e) {
            $this->designNotes[] = 'AI 设计生成失败，已用默认设计：'.Str::limit($e->getMessage(), 150);
            $config = null;
        }

        $config ??= DefaultDesign::make($site->domain_id.'-'.$site->design_version, $site->typeConfig());
        if ($reference) {
            $config = $this->applyReference($config, $reference);
        }
        $validated = $validator->validate($config, $site->typeConfig());

        if ($onlyPages !== null && $site->design_config) {
            $current = $validator->validate($site->design_config, $site->typeConfig());
            foreach ($onlyPages as $type) {
                $current['pages'][$type] = $validated['pages'][$type] ?? $current['pages'][$type];
            }
            $validated = $current;
        }

        if ($customCss !== null && CustomCssValidator::errors($customCss) !== []) {
            $customCss = null;
        }

        $this->saveDesign($site, $validated, $customCss ?? $site->custom_css, 'ai');

        return $validator->repaired;
    }

    /** The reference's own colours and fonts win over whatever the model picked. */
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
        if ($font = ReferenceAnalyzer::whitelistFont($reference['fonts'])) {
            $config['fonts'] = ['heading' => $font, 'body' => $font];
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
            return '品牌资料转为站点语言失败：'.Str::limit($e->getMessage(), 150);
        }
    }

    public function saveDesign(Site $site, array $config, ?string $customCss, string $source): void
    {
        $version = (int) $site->designVersions()->max('version') + 1;
        DesignVersion::create(['site_id' => $site->id, 'version' => $version, 'config' => $config, 'custom_css' => $customCss, 'source' => $source]);
        $site->update(['design_config' => $config, 'design_version' => $version, 'custom_css' => $customCss]);
    }

    /** One AI call for all core pages of the site type; defaults fill any gaps. */
    public function generatePages(Site $site): void
    {
        $types = $site->typeConfig()['pages'];
        $lang = $site->language();
        $ai = [];

        try {
            // Labels in the site language: the admin's Chinese labels would be copied into page titles.
            // Ask for content of the blocks the design actually uses on each page.
            $designBlocks = fn ($type, $p) => collect($site->design_config['pages'][$type] ?? [])->pluck('block')->unique()->values()->all() ?: $p['blocks'];
            $spec = collect($types)->map(fn ($p, $type) => ['label' => $this->defaultTitle($type, $p, $lang), 'default_slug' => $p['slug'][$lang] ?? $p['slug']['en'], 'blocks' => $designBlocks($type, $p)])->all();
            $ai = $this->ai->prompt(PromptType::PageContent, [
                'brand' => $this->brandContext($site),
                'pages' => json_encode($spec, JSON_UNESCAPED_UNICODE),
                'reference_content' => $this->referenceContent($site),
            ], $site)->json()['pages'] ?? [];
        } catch (Throwable) {
            $ai = [];
        }

        $usedSlugs = [];
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
                ? $data['title'] : $this->defaultTitle($type, $def, $lang);
            $content = $this->cleanBlocks($data['blocks'] ?? [], $type, $site);

            $site->pages()->updateOrCreate(['type' => $type], [
                'slug' => $slug,
                'title' => Str::limit(strip_tags($title), 120, ''),
                'meta_title' => SeoHead::limit($type === 'home' ? ($site->title ?: $title) : ($data['meta_title'] ?? "{$title} – {$site->product_name}"), 60),
                'meta_description' => SeoHead::limit($type === 'home' ? (string) $site->description : (string) ($data['meta_description'] ?? $site->description), 155),
                'content' => $content,
                'noindex' => false,
            ]);
        }

        // Extra pages the current design no longer has.
        $site->pages()->where('type', 'like', 'extra%')->whereNotIn('type', array_keys($types))->get()->each(function (Page $page) use ($site) {
            if ($site->launched_at) {
                GoneUrl::firstOrCreate(['host' => $site->domain->name, 'path' => trim($page->slug, '/')]);
            }
            $page->delete();
        });
    }

    /** Reference text for AI rewriting, when the site's switch is on. */
    protected function referenceContent(Site $site): string
    {
        if (! $site->reference_rewrite_content || ! $site->reference_url) {
            return 'none';
        }
        $this->referenceData ??= rescue(fn () => $this->reference->analyze($site->reference_url), null);
        $content = trim((string) ($this->referenceData['content'] ?? ''));

        return $content !== '' ? Str::limit($content, 12_000) : 'none';
    }

    protected function defaultTitle(string $type, array $def, string $lang): string
    {
        return Lang::has("theme.page_titles.{$type}", $lang) ? __("theme.page_titles.{$type}", [], $lang) : $def['label'];
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

    /** Lighthouse each page type; regenerate only failing page compositions. */
    public function acceptance(Site $site, GenerationTask $task): void
    {
        if (! Lighthouse::available()) {
            $task->progress('未安装 Lighthouse，跳过验收');

            return;
        }

        $min = config('platform.lighthouse');
        for ($attempt = 1; $attempt <= $min['max_attempts']; $attempt++) {
            $paths = $site->pages()->pluck('slug', 'type')->all() + ['blog' => 'blog/', 'not_found' => '404.html'];
            $results = $this->lighthouse->auditSite(StaticSiteBuilder::dirFor($site), $paths);

            $failed = collect($results)->filter(fn ($r) => $r !== null && ($r['performance'] < $min['min_performance'] || $r['seo'] < $min['min_seo']));
            $task->progress("Lighthouse 第 {$attempt} 轮：".collect($results)->map(fn ($r, $t) => $r ? "{$t} P{$r['performance']}/S{$r['seo']}" : "{$t} n/a")->implode(', '));
            if ($failed->isEmpty()) {
                return;
            }

            $feedback = 'These page types scored below 90 on mobile Lighthouse: '.$failed->keys()->implode(', ').'. Use fewer, lighter blocks (fewer galleries above the fold).';
            $this->generateDesign($site->refresh(), $feedback, $failed->keys()->all());
            $this->render($site, $task);
        }

        throw new \RuntimeException('Lighthouse 多次验收未通过');
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
            'page_types' => array_keys($pages + DesignConfigValidator::SYSTEM_PAGES),
            'menu_items' => array_values(array_merge(array_diff(array_keys($pages), ['home', 'privacy', 'terms']), ['blog'])),
            'extra_pages' => 'up to '.ExtraPages::MAX.' pages named extra_1…extra_'.ExtraPages::MAX.' in the order of "extra_pages"; usable in "menu" and "pages" (blocks: '.implode(', ', ExtraPages::BLOCKS).')',
        ];
    }

    /** Dominant non-neutral colour of the logo, if any. */
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
    protected function cleanBlocks(array $blocks, string $type, Site $site): array
    {
        $lang = $site->language();
        $str = fn ($v, $len = 300) => is_string($v) ? Str::limit(trim(strip_tags($v)), $len, '') : null;
        $out = [];

        foreach (['hero', 'features', 'faq', 'cta', 'prose', 'gallery', 'specs', 'article_list', 'contact', 'legal', 'banner', 'card_grid', 'steps', 'stats', 'notice', 'download'] as $block) {
            $b = is_array($blocks[$block] ?? null) ? $blocks[$block] : [];
            $clean = array_filter([
                'heading' => $str($b['heading'] ?? null, 120),
                'subheading' => $str($b['subheading'] ?? null),
                'intro' => $str($b['intro'] ?? null, 400),
                'text' => $str($b['text'] ?? null, 400),
                'cta_label' => $str($b['cta_label'] ?? null, 40),
                'label' => $str($b['label'] ?? null, 40),
                'eyebrow' => $str($b['eyebrow'] ?? null, 40),
                'html' => isset($b['html']) ? HtmlSanitizer::clean($b['html']) : null,
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
                ]))->filter()->values()->take(12)->all();
            }
            if ($clean !== []) {
                $out[$block] = $clean;
            }
        }

        if (in_array($type, ['privacy', 'terms'], true) && empty($out['legal']['html'])) {
            $out['legal']['html'] = __("theme.{$type}_html", ['site' => e($site->name), 'product' => e($site->product_name)], $lang);
        }

        return $out;
    }
}
