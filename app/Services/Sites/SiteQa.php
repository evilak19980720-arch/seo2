<?php

namespace App\Services\Sites;

use App\Enums\AiPurpose;
use App\Enums\PromptType;
use App\Models\DesignVersion;
use App\Models\GenerationTask;
use App\Models\Site;
use App\Services\Ai\AiManager;
use App\Services\Content\SeoRules;
use App\Services\Integrations\Screenshot;
use App\Services\Render\SchemaValidator;
use App\Services\Render\SeoHead;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Theme\DesignConfigValidator;
use App\Support\Chromium;
use App\Support\Media;
use App\Support\PreviewServer;
use App\Support\Settings;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;
use Throwable;

/**
 * AI quality check after a site is built: program checks (SEO rules,
 * contrast, overflow, images) plus screenshots go to a vision model, which
 * scores the look and the SEO and answers with a change list limited to
 * design options and page titles / descriptions. The change is built and
 * measured again, and kept only when the score rises and no program check
 * gets worse; otherwise the site goes back to how it was.
 */
class SiteQa
{
    /** Design keys the reviewer may change; everything else stays as generated. */
    public const SCALARS = ['radius', 'density', 'shadow', 'nav', 'footer'];

    public function __construct(protected AiManager $ai, protected Screenshot $screenshot, protected StaticSiteBuilder $builder) {}

    public function enabled(): bool
    {
        return (bool) Settings::get('qa_enabled', true);
    }

    /**
     * @param  callable(Site): void  $rebuild  renders the site again after a change
     */
    public function run(Site $site, GenerationTask $task, callable $rebuild, bool $force = false): void
    {
        if (! $force && ! $this->enabled()) {
            return;
        }
        if ($reason = $this->unavailable()) {
            $task->progress("AI 质检：{$reason}，已跳过");

            return;
        }

        $rounds = max(1, min(3, (int) Settings::get('qa_rounds', 2)));
        $reference = $this->referenceShots($site);
        $current = $this->measure($site);
        try {
            $review = $this->review($site, $current, $reference);
        } catch (Throwable $e) {
            $task->progress('AI 质检失败：'.Str::limit($e->getMessage(), 150));

            return;
        }

        for ($round = 1; $round <= $rounds; $round++) {
            $task->progress("AI 质检 第 {$round} 轮：美观 {$review['scores']['design']} / SEO {$review['scores']['seo']}；".$this->checkLine($current)
                .($review['issues'] ? '；问题：'.Str::limit(implode('；', $review['issues']), 400) : ''));
            if ($review['scores']['design'] >= 92 && $review['scores']['seo'] >= 92) {
                $task->progress('AI 质检：分数已够高，不再修改');

                return;
            }

            $snapshot = $this->snapshot($site);
            $changes = $this->apply($site, $review);
            if ($changes === []) {
                $task->progress('AI 质检：没有可用的修改建议');

                return;
            }
            $task->progress('AI 质检 修改：'.Str::limit(implode('；', $changes), 500));
            $rebuild($site->refresh());

            $after = $this->measure($site);
            try {
                $next = $this->review($site, $after, $reference);
            } catch (Throwable $e) {
                $this->restore($site, $snapshot);
                $rebuild($site->refresh());
                $task->progress('AI 质检：复评失败，已退回修改前（'.Str::limit($e->getMessage(), 120).'）');

                return;
            }

            $better = $this->total($next) > $this->total($review) && $this->problems($after) <= $this->problems($current);
            if (! $better) {
                $this->restore($site, $snapshot);
                $rebuild($site->refresh());
                $task->progress("AI 质检：修改后 美观 {$next['scores']['design']} / SEO {$next['scores']['seo']}，".$this->checkLine($after).'，没有变好，已退回修改前');

                return;
            }
            $task->progress("AI 质检：修改后 美观 {$next['scores']['design']} / SEO {$next['scores']['seo']}，已保留");
            $current = $after;
            $review = $next;
        }
    }

    /** Why the check cannot run here, or null when it can. */
    public function unavailable(): ?string
    {
        if (! Chromium::bin()) {
            return '服务器未安装 Chromium，无法截图';
        }
        if ($this->ai->candidates(AiPurpose::forCall(PromptType::SiteReview->value), vision: true)->isEmpty()) {
            return '没有能看图的 AI 渠道';
        }

        return null;
    }

    /**
     * Program checks of the built site: SEO rules per page from the HTML,
     * and contrast / overflow / image problems read by the browser on the
     * home page and a few inner pages, on a phone and a desktop.
     *
     * @return array{seo: array<int, string>, browser: array<string, array>, shots: array<int, string>}
     */
    public function measure(Site $site): array
    {
        $dir = StaticSiteBuilder::dirFor($site);
        $pages = $site->pages()->where('noindex', false)->get(['type', 'slug']);
        $seo = $this->seoChecks($site, $dir, $pages->pluck('slug', 'type')->all());
        // Home plus the first menu pages: what visitors see most.
        $menu = array_flip((array) ($site->design_config['menu'] ?? []));
        $inner = $pages->where('type', '!=', 'home')->sortBy(fn ($p) => $menu[$p->type] ?? PHP_INT_MAX)->pluck('slug')->take(3)->all();
        $paths = array_values(array_unique(array_merge([''], $inner)));

        return PreviewServer::with($dir, function (string $base) use ($paths, $seo) {
            $browser = [];
            foreach ($paths as $path) {
                foreach (['mobile' => '412,3000', 'desktop' => '1366,3000'] as $device => $size) {
                    $browser['/'.ltrim($path, '/')." {$device}"] = $this->audit($base.'/'.($path === '' ? '' : trim($path, '/').'/').'?__qa=1', $size);
                }
            }

            return ['seo' => $seo, 'browser' => array_filter($browser), 'shots' => $this->screenshot->capture($base.'/')];
        });
    }

    /** @return array<string, mixed>|null what qa-audit.js found on one page */
    protected function audit(string $url, string $size): ?array
    {
        $dom = Chromium::run(['--window-size='.$size, '--virtual-time-budget=8000', '--dump-dom', $url], 45, fn (string $out) => str_contains($out, '</html>'));
        if ($dom && preg_match('#<pre id="__qa">(\{.*?\})</pre>#s', $dom, $m)) {
            return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true) ?: null;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $paths  page type => slug
     * @return array<int, string>
     */
    public function seoChecks(Site $site, string $dir, array $paths): array
    {
        $brand = $site->product_name;
        $issues = [];
        $seen = ['title' => [], 'description' => []];
        foreach ($paths as $type => $slug) {
            $file = rtrim($dir, '/').'/'.(trim($slug, '/') === '' ? '' : trim($slug, '/').'/').'index.html';
            if (! is_file($file)) {
                continue;
            }
            $html = (string) file_get_contents($file);
            $doc = new DOMDocument;
            @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
            $xp = new DOMXPath($doc);
            $title = trim((string) $xp->evaluate('string(//title)'));
            $description = trim((string) $xp->evaluate('string(//meta[@name="description"]/@content)'));
            $h1 = $xp->query('//h1')->length;
            $noAlt = $xp->query('//img[not(@alt)]')->length;

            $len = mb_strlen($title);
            if ($len < 20 || $len > 65) {
                $issues[] = "{$type}: title {$len} 字符（建议 20-65）";
            } elseif (SeoRules::weakTitle($title, $brand)) {
                $issues[] = "{$type}: title 太弱（只有品牌名或没写清是什么）";
            }
            $len = mb_strlen($description);
            if ($len < 70 || $len > 165) {
                $issues[] = "{$type}: description {$len} 字符（建议 70-160）";
            } elseif (SeoRules::weakDescription($description, $brand)) {
                $issues[] = "{$type}: description 太弱";
            }
            if ($h1 !== 1) {
                $issues[] = "{$type}: H1 有 {$h1} 个";
            }
            if ($noAlt > 0) {
                $issues[] = "{$type}: {$noAlt} 张图片没有 alt";
            }
            foreach (['title' => $title, 'description' => $description] as $key => $value) {
                $norm = SeoRules::normalize($value);
                if ($norm !== '' && isset($seen[$key][$norm])) {
                    $issues[] = "{$type}: {$key} 和 {$seen[$key][$norm]} 重复";
                }
                $seen[$key][$norm] ??= $type;
            }
            foreach (SchemaValidator::check($html) as $problem) {
                $issues[] = "{$type}: {$problem}";
            }
        }

        return $issues;
    }

    /** Number of program-check problems; a change may not raise it. */
    public function problems(array $measure): int
    {
        $n = count($measure['seo']);
        foreach ($measure['browser'] as $found) {
            $n += count($found['contrast'] ?? []) + (($found['overflow'] ?? 0) > 1 ? 1 : 0) + ($found['brokenImages'] ?? 0) + ($found['missingAlt'] ?? 0);
        }

        return $n;
    }

    protected function checkLine(array $measure): string
    {
        $contrast = array_sum(array_map(fn ($f) => count($f['contrast'] ?? []), $measure['browser']));
        $overflow = count(array_filter($measure['browser'], fn ($f) => ($f['overflow'] ?? 0) > 1));

        return '程序检查 '.$this->problems($measure).' 处（SEO '.count($measure['seo'])."，文字看不清 {$contrast}，横向溢出 {$overflow}）";
    }

    protected function total(array $review): int
    {
        return $review['scores']['design'] + $review['scores']['seo'];
    }

    /** @return array<int, string> data URIs of the reference: uploaded screenshots first, else a capture */
    protected function referenceShots(Site $site): array
    {
        $shots = [];
        foreach (array_slice((array) $site->reference_images, 0, 2) as $path) {
            $local = Media::localPath($path);
            if ($local && is_file($local) && filesize($local) < 4_000_000) {
                $shots[] = 'data:'.(mime_content_type($local) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($local));
            }
        }
        if ($shots === [] && $site->reference_url) {
            $shots = rescue(fn () => $this->screenshot->capture($site->reference_url), [], false);
        }

        return $shots;
    }

    /**
     * One vision call: scores, issues and a change list.
     *
     * @return array{scores: array{design: int, seo: int}, issues: array<int, string>, design: array, pages: array, drop_custom_css: bool}
     */
    public function review(Site $site, array $measure, array $referenceShots): array
    {
        $design = $site->design_config ?? [];
        $pages = $site->pages()->get(['type', 'title', 'meta_title', 'meta_description'])
            ->map(fn ($p) => ['type' => $p->type, 'h1' => $p->title, 'meta_title' => $p->meta_title ?: ($p->type === 'home' ? $site->title : $p->title), 'meta_description' => $p->meta_description ?: ($p->type === 'home' ? $site->description : '')])->values()->all();
        $images = array_merge(array_slice($measure['shots'], 0, 2), array_slice($referenceShots, 0, 2));

        $res = $this->ai->prompt(PromptType::SiteReview, [
            'brand' => $site->brandProfile?->toPromptContext() ?? "Product: {$site->product_name}",
            'checks' => json_encode(['seo' => array_slice($measure['seo'], 0, 40), 'browser' => $measure['browser']], JSON_UNESCAPED_UNICODE),
            'design' => json_encode(collect($design)->only(array_merge(['colors', 'fonts', 'style', 'navigation', 'pages'], self::SCALARS))->all(), JSON_UNESCAPED_UNICODE),
            'options' => json_encode($this->options(), JSON_UNESCAPED_UNICODE),
            'pages' => json_encode($pages, JSON_UNESCAPED_UNICODE),
            'custom_css' => filled($site->custom_css) ? Str::limit((string) $site->custom_css, 1500) : '(none)',
            'screenshots' => 'Images 1-'.min(2, count($measure['shots'])).': this site (desktop, then phone).'.(count($images) > count(array_slice($measure['shots'], 0, 2)) ? ' The rest: the reference site it should resemble.' : ' No reference screenshots.'),
        ], $site, $images)->json();

        $score = fn ($v) => max(0, min(100, (int) $v));

        return [
            'scores' => ['design' => $score($res['scores']['design'] ?? 0), 'seo' => $score($res['scores']['seo'] ?? 0)],
            'issues' => array_values(array_filter(array_map(fn ($i) => is_string($i) ? trim($i) : null, (array) ($res['issues'] ?? [])))),
            'design' => is_array($res['design'] ?? null) ? $res['design'] : [],
            'pages' => is_array($res['pages'] ?? null) ? $res['pages'] : [],
            'drop_custom_css' => (bool) ($res['drop_custom_css'] ?? false),
        ];
    }

    /** The values the reviewer may choose from. */
    public function options(): array
    {
        return [
            'colors' => 'hex values for '.implode(', ', DesignConfigValidator::COLOR_KEYS),
            'fonts' => array_keys(config('theme.fonts')),
            'radius' => array_keys(config('theme.radius')),
            'density' => array_keys(config('theme.density')),
            'shadow' => array_keys(config('theme.shadow')),
            'style' => config('theme.styles'),
            'nav' => config('theme.blocks.nav'),
            'footer' => config('theme.blocks.footer'),
            'navigation' => DesignConfigValidator::MOBILE + ['sticky' => [true, false], 'buttons' => [0, 1, 2]],
            'block_variants' => collect(config('theme.blocks'))->except(config('theme.layout_blocks'))->all(),
        ];
    }

    /**
     * Apply the change list within the allowed options.
     *
     * @return array<int, string> what changed, for the task log
     */
    public function apply(Site $site, array $review): array
    {
        $changes = [];
        $config = $site->design_config ?? [];
        $patch = $review['design'];
        $options = $this->options();

        foreach (self::SCALARS as $key) {
            $value = $patch[$key] ?? null;
            if (is_string($value) && in_array($value, $options[$key], true) && ($config[$key] ?? null) !== $value) {
                $changes[] = "{$key} → {$value}";
                $config[$key] = $value;
            }
        }
        foreach ((array) ($patch['colors'] ?? []) as $key => $value) {
            if (in_array($key, DesignConfigValidator::COLOR_KEYS, true) && is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) && strcasecmp($config['colors'][$key] ?? '', $value) !== 0) {
                $changes[] = "颜色 {$key} → {$value}";
                $config['colors'][$key] = strtolower($value);
            }
        }
        foreach (['heading', 'body'] as $role) {
            $value = $patch['fonts'][$role] ?? null;
            if (is_string($value) && in_array($value, $options['fonts'], true) && ($config['fonts'][$role] ?? null) !== $value) {
                $changes[] = "字体 {$role} → {$value}";
                $config['fonts'][$role] = $value;
            }
        }
        foreach ((array) ($patch['style'] ?? []) as $key => $value) {
            if (isset($options['style'][$key]) && in_array($value, $options['style'][$key], true) && ($config['style'][$key] ?? null) !== $value) {
                $changes[] = "风格 {$key} → {$value}";
                $config['style'][$key] = $value;
            }
        }
        foreach ((array) ($patch['navigation'] ?? []) as $key => $value) {
            if (isset($options['navigation'][$key]) && in_array($value, $options['navigation'][$key], true) && ($config['navigation'][$key] ?? null) !== $value) {
                $changes[] = "导航 {$key} → ".json_encode($value);
                $config['navigation'][$key] = $value;
            }
        }
        // Block variants only: the reviewer never adds, removes or reorders sections.
        foreach ((array) ($patch['pages'] ?? []) as $type => $blocks) {
            foreach ((array) $blocks as $entry) {
                $block = $entry['block'] ?? null;
                $variant = $entry['variant'] ?? null;
                if (! is_string($block) || ! in_array($variant, $options['block_variants'][$block] ?? [], true)) {
                    continue;
                }
                foreach ($config['pages'][$type] ?? [] as $i => $current) {
                    if (($current['block'] ?? null) === $block && ($current['variant'] ?? null) !== $variant) {
                        $config['pages'][$type][$i]['variant'] = $variant;
                        $changes[] = "{$type}.{$block} → {$variant}";
                    }
                }
            }
        }

        $css = $site->custom_css;
        if ($review['drop_custom_css'] && filled($css)) {
            $css = null;
            $changes[] = '去掉 AI 自定义 CSS';
        }
        if ($config !== ($site->design_config ?? []) || $css !== $site->custom_css) {
            $validated = (new DesignConfigValidator)->validate($config, $site->typeConfig());
            app(SiteGenerator::class)->saveDesign($site, $validated, $css, 'qa');
        }

        return array_merge($changes, $this->applyPages($site, $review['pages']));
    }

    /** @return array<int, string> */
    protected function applyPages(Site $site, array $patch): array
    {
        $brand = $site->product_name;
        $facts = $site->brandProfile?->toPromptContext() ?? $brand;
        $changes = [];
        foreach ($site->pages()->get() as $page) {
            $fields = $patch[$page->type] ?? null;
            if (! is_array($fields)) {
                continue;
            }
            $update = [];
            $title = trim(SeoRules::stripClaims((string) ($fields['meta_title'] ?? ''), $facts));
            if ($title !== '' && mb_strlen($title) >= 20 && mb_strlen($title) <= 65 && ! SeoRules::weakTitle($title, $brand) && ! SeoRules::seasonal($title)) {
                $update['meta_title'] = $title;
            }
            $description = trim(SeoRules::stripClaims((string) ($fields['meta_description'] ?? ''), $facts));
            if ($description !== '' && mb_strlen($description) >= 70 && ! SeoRules::weakDescription($description, $brand) && ! SeoRules::seasonal($description)) {
                $update['meta_description'] = SeoHead::limit($description, 160);
            }
            $update = array_filter($update, fn ($v, $k) => $v !== $page->{$k}, ARRAY_FILTER_USE_BOTH);
            if ($update) {
                $page->update($update);
                $changes[] = "{$page->type} 页 ".implode('、', array_keys($update));
            }
        }

        return $changes;
    }

    /** @return array<string, mixed> */
    protected function snapshot(Site $site): array
    {
        return [
            'site' => $site->only(['design_config', 'custom_css', 'design_version']),
            'version' => (int) $site->designVersions()->max('version'),
            'pages' => $site->pages()->get(['id', 'meta_title', 'meta_description'])->keyBy('id')->map(fn ($p) => $p->only(['meta_title', 'meta_description']))->all(),
        ];
    }

    protected function restore(Site $site, array $snapshot): void
    {
        $site->update($snapshot['site']);
        DesignVersion::where('site_id', $site->id)->where('version', '>', $snapshot['version'])->delete();
        foreach ($site->pages()->get() as $page) {
            if (isset($snapshot['pages'][$page->id])) {
                $page->update($snapshot['pages'][$page->id]);
            }
        }
    }
}
