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
use App\Services\Theme\SiteStyle;
use App\Services\Theme\Variants;
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
    /**
     * Design keys the reviewer may change. The site's identity (header and
     * footer type, phone menu, fonts, corners, card style, hero) was chosen
     * from the reference and stays as generated: a reviewer that may change
     * it pulls every site towards the same "safe" look.
     */
    public const SCALARS = ['density', 'shadow'];

    /** Blocks whose variant shapes the first impression; never changed by the reviewer. */
    public const IDENTITY_BLOCKS = ['hero', 'banner'];

    /** At most this many colours and block variants change in one round. */
    public const MAX_COLORS = 2;

    public const MAX_VARIANTS = 2;

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

        // A check that runs too long must never hold up the site: it stops between steps and keeps what is proven.
        $deadline = microtime(true) + 60 * max(3, (int) Settings::get('qa_max_minutes', 12));
        $late = fn () => microtime(true) > $deadline;
        $note = fn (string $step) => $task->progress('AI 质检 · '.$step);
        $snapshot = null;

        try {
            $rounds = max(1, min(3, (int) Settings::get('qa_rounds', 2)));
            $minDesign = max(0, min(100, (int) Settings::get('qa_min_design', 92)));
            $minSeo = max(0, min(100, (int) Settings::get('qa_min_seo', 92)));
            $note('截取参考网站');
            $reference = $this->referenceShots($site);
            $current = $this->measure($site, $note);
            $note('AI 看图打分中');
            $review = $this->review($site, $current, $reference);

            for ($round = 1; $round <= $rounds; $round++) {
                $task->progress("AI 质检 第 {$round} 轮：美观 {$review['scores']['design']} / SEO {$review['scores']['seo']}；".$this->checkLine($current)
                    .($review['issues'] ? '；问题：'.Str::limit(implode('；', $review['issues']), 400) : ''));
                if ($review['scores']['design'] >= $minDesign && $review['scores']['seo'] >= $minSeo) {
                    $task->progress("AI 质检：已达到合格分（美观 {$minDesign} / SEO {$minSeo}），不修改");

                    return;
                }
                if ($late()) {
                    $task->progress('AI 质检：已到时间上限，停止修改');

                    return;
                }

                $snapshot = $this->snapshot($site);
                $changes = $this->apply($site, $review, $current);
                if ($changes === []) {
                    $task->progress('AI 质检：没有可用的修改建议');

                    return;
                }
                $task->progress('AI 质检 修改：'.Str::limit(implode('；', $changes), 500));
                $note('按修改重新渲染');
                $rebuild($site->refresh());

                $after = $this->measure($site, $note);
                $note('AI 复评中');
                $next = $this->review($site, $after, $reference);

                $better = $this->total($next) > $this->total($review) && $this->problems($after) <= $this->problems($current);
                if (! $better) {
                    $this->undo($site, $snapshot, $rebuild);
                    $task->progress("AI 质检：修改后 美观 {$next['scores']['design']} / SEO {$next['scores']['seo']}，".$this->checkLine($after).'，没有变好，已退回修改前');

                    return;
                }
                $snapshot = null;
                $task->progress("AI 质检：修改后 美观 {$next['scores']['design']} / SEO {$next['scores']['seo']}，已保留");
                $current = $after;
                $review = $next;
            }
        } catch (Throwable $e) {
            report($e);
            if ($snapshot) {
                $this->undo($site, $snapshot, $rebuild);
            }
            $task->progress('AI 质检失败，'.($snapshot ? '已退回修改前，' : '').'网站按原样继续：'.Str::limit($e->getMessage(), 150));
        }
    }

    protected function undo(Site $site, array $snapshot, callable $rebuild): void
    {
        $this->restore($site, $snapshot);
        $rebuild($site->refresh());
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
    public function measure(Site $site, ?callable $note = null): array
    {
        $note ??= fn () => null;
        $dir = StaticSiteBuilder::dirFor($site);
        $pages = $site->pages()->where('noindex', false)->get(['type', 'slug']);
        $seo = $this->seoChecks($site, $dir, $pages->pluck('slug', 'type')->all());
        // Home plus the first menu pages: what visitors see most.
        $menu = array_flip((array) ($site->design_config['menu'] ?? []));
        $inner = $pages->where('type', '!=', 'home')->sortBy(fn ($p) => $menu[$p->type] ?? PHP_INT_MAX)->pluck('slug')->take(1)->all();
        $paths = array_values(array_unique(array_merge([''], $inner)));

        return PreviewServer::with($dir, function (string $base) use ($paths, $seo, $note) {
            $browser = [];
            foreach ($paths as $path) {
                foreach (['mobile' => '412,3000', 'desktop' => '1366,3000'] as $device => $size) {
                    $label = '/'.ltrim($path, '/')." {$device}";
                    $note("浏览器检查 {$label}");
                    $browser[$label] = $this->audit($base.'/'.($path === '' ? '' : trim($path, '/').'/').'?__qa=1', $size);
                }
            }
            $note('截图');
            $shots = array_values(array_filter(array_map(fn ($shot) => $this->shrink($shot), $this->screenshot->capture($base.'/'))));

            return ['seo' => $seo, 'browser' => array_filter($browser), 'shots' => $shots];
        });
    }

    /** A screenshot made small enough to send quickly: at most 900 px wide and 2000 px tall, as JPEG. */
    public function shrink(string $dataUri): ?string
    {
        $binary = base64_decode((string) preg_replace('#^data:[^,]+,#', '', $dataUri), true);
        $img = $binary !== false ? @imagecreatefromstring($binary) : false;
        if (! $img) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, 900 / $w);
        $out = imagecreatetruecolor((int) round($w * $scale), (int) round(min($h, 2000 / $scale) * $scale));
        imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, (int) round(imagesy($out) / $scale));
        ob_start();
        imagejpeg($out, null, 72);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
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
            $n += count($found['contrast'] ?? []) + (($found['overflow'] ?? 0) > 1 ? 1 : 0) + ($found['brokenImages'] ?? 0) + ($found['missingAlt'] ?? 0) + ($found['squeezed'] ?? 0);
        }

        return $n;
    }

    protected function checkLine(array $measure): string
    {
        $contrast = array_sum(array_map(fn ($f) => count($f['contrast'] ?? []), $measure['browser']));
        $overflow = count(array_filter($measure['browser'], fn ($f) => ($f['overflow'] ?? 0) > 1));
        $squeezed = array_sum(array_map(fn ($f) => $f['squeezed'] ?? 0, $measure['browser']));

        return '程序检查 '.$this->problems($measure).' 处（SEO '.count($measure['seo'])."，文字看不清 {$contrast}，横向溢出 {$overflow}，挤压变形 {$squeezed}）";
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

        return array_values(array_filter(array_map(fn ($shot) => $this->shrink($shot), $shots)));
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
            'density' => array_keys(config('theme.density')),
            'shadow' => array_keys(config('theme.shadow')),
            'block_variants' => collect(config('theme.blocks'))->except(array_merge(config('theme.layout_blocks'), self::IDENTITY_BLOCKS))->all(),
        ];
    }

    /**
     * Apply the change list within the allowed options.
     *
     * @return array<int, string> what changed, for the task log
     */
    public function apply(Site $site, array $review, ?array $measure = null): array
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
        // Colours only to fix text the program found unreadable, a few at a time.
        $clash = $measure === null || collect($measure['browser'] ?? [])->contains(fn ($f) => ! empty($f['contrast']));
        $colors = 0;
        foreach ($clash ? (array) ($patch['colors'] ?? []) : [] as $key => $value) {
            if ($colors < self::MAX_COLORS && in_array($key, DesignConfigValidator::COLOR_KEYS, true) && is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) && strcasecmp($config['colors'][$key] ?? '', $value) !== 0) {
                $changes[] = "颜色 {$key} → {$value}";
                $config['colors'][$key] = strtolower($value);
                $colors++;
            }
        }
        $variants = 0;
        // Block variants only: the reviewer never adds, removes or reorders sections.
        foreach ((array) ($patch['pages'] ?? []) as $type => $blocks) {
            foreach ((array) $blocks as $entry) {
                $block = $entry['block'] ?? null;
                $variant = $entry['variant'] ?? null;
                // Only a variant of the site's visual system, never a second stage block, and the
                // block changes on every page so it keeps one look across the site.
                if (! is_string($block) || ! in_array($variant, $options['block_variants'][$block] ?? [], true)
                    || ! in_array($variant, SiteStyle::allowedFor($config, $block), true) || ! empty(Variants::definition($block, $variant)['stage'])) {
                    continue;
                }
                $changed = false;
                foreach ((array) ($config['pages'] ?? []) as $pageType => $entries) {
                    foreach ((array) $entries as $i => $current) {
                        if ($variants < self::MAX_VARIANTS && ($current['block'] ?? null) === $block && ($current['variant'] ?? null) !== $variant) {
                            $config['pages'][$pageType][$i]['variant'] = $variant;
                            $changed = true;
                        }
                    }
                }
                if ($changed) {
                    $variants++;
                    $changes[] = "{$block} → {$variant}";
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
            // Titles are the admin's site title (home) or the column name: never rewritten.
            if ($page->type === 'home') {
                continue;
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
