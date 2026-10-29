<?php

namespace Tests\Feature;

use App\Models\GenerationTask;
use App\Models\Site;
use App\Services\Ai\AiManager;
use App\Services\Integrations\Screenshot;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\SiteQa;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteQaTest extends TestCase
{
    use RefreshDatabase;

    /** A quality check with scripted measurements and reviews (no browser, no AI). */
    private function qa(array $reviews, array $measures): SiteQa
    {
        return new class(app(AiManager::class), app(Screenshot::class), app(StaticSiteBuilder::class), $reviews, $measures) extends SiteQa
        {
            public function __construct($ai, $shot, $builder, public array $reviews, public array $measures)
            {
                parent::__construct($ai, $shot, $builder);
            }

            public function unavailable(): ?string
            {
                return null;
            }

            public function measure(Site $site, ?callable $note = null): array
            {
                return array_shift($this->measures);
            }

            public function review(Site $site, array $measure, array $referenceShots): array
            {
                return array_shift($this->reviews) + ['issues' => [], 'design' => [], 'pages' => [], 'drop_custom_css' => false];
            }
        };
    }

    private function site(): Site
    {
        $site = $this->makeSite(['domain' => 'qa.com.br', 'status' => 'live', 'custom_css' => '.nav{background:#000}']);
        $site->update(['design_config' => ['nav' => 'split', 'radius' => 'md', 'colors' => ['primary' => '#2563eb', 'secondary' => '#0f172a', 'accent' => '#f59e0b', 'background' => '#ffffff', 'surface' => '#f8fafc', 'text' => '#111827', 'muted' => '#4b5563'], 'pages' => ['home' => [['block' => 'hero', 'variant' => 'split'], ['block' => 'faq', 'variant' => 'list']]]]]);
        $site->pages()->create(['type' => 'home', 'slug' => '', 'title' => 'Galaxy Run', 'meta_title' => 'Galaxy Run', 'meta_description' => 'Curto']);

        return $site->fresh();
    }

    private function measure(int $seo = 0, int $contrast = 0): array
    {
        return ['seo' => array_fill(0, $seo, 'x'), 'browser' => ['/ mobile' => ['contrast' => array_fill(0, $contrast, ['ratio' => 1.2])]], 'shots' => []];
    }

    private const PATCH = [
        'design' => [
            'nav' => 'bar', 'radius' => 'lg', 'density' => 'relaxed', 'style' => ['cards' => 'flat'], 'colors' => ['primary' => '#7c3aed', 'text' => 'red', 'muted' => '#374151', 'surface' => '#eeeeee'], 'fonts' => ['heading' => 'comic-sans', 'body' => 'manrope'],
            'navigation' => ['mobile_menu' => 'sheet', 'mobile_header' => 'floating'], 'pages' => ['home' => [['block' => 'hero', 'variant' => 'image-bg'], ['block' => 'faq', 'variant' => 'nope'], ['block' => 'faq', 'variant' => 'cards'], ['block' => 'gallery', 'variant' => 'grid']]],
        ],
        'pages' => ['home' => ['meta_title' => 'Galaxy Run: jogo de corrida espacial grátis', 'meta_description' => 'Corra entre planetas e desvie de asteroides no Galaxy Run, o jogo de corrida espacial grátis para celular.'], 'missing' => ['meta_title' => 'x']],
        'drop_custom_css' => true,
    ];

    public function test_a_better_change_is_kept_within_the_allowed_options(): void
    {
        $site = $this->site();
        $task = GenerationTask::create(['site_id' => $site->id, 'type' => 'qa', 'status' => 'running']);
        $builds = 0;
        $this->qa([['scores' => ['design' => 60, 'seo' => 70]] + self::PATCH, ['scores' => ['design' => 80, 'seo' => 85]]], [$this->measure(3, 2), $this->measure(1, 0)])
            ->run($site, $task, function () use (&$builds) {
                $builds++;
            }, force: true);

        $site->refresh();
        $design = $site->design_config;
        $this->assertSame(1, $builds);
        // The site's identity stays as generated: no reviewer pulls every site to the same look.
        $this->assertSame('split', $design['nav']);
        $this->assertSame('md', $design['radius']);
        $this->assertNotSame('manrope', $design['fonts']['body'] ?? null);
        $this->assertNotSame('flat', $design['style']['cards'] ?? null);
        $this->assertNotSame('sheet', $design['navigation']['mobile_menu'] ?? null);
        $home = collect($design['pages']['home']);
        $this->assertSame('split', $home->firstWhere('block', 'hero')['variant'], 'the hero is identity too');
        // Repairs: spacing, unreadable colours (at most two), broken blocks.
        $this->assertSame('relaxed', $design['density']);
        $this->assertSame('#7c3aed', $design['colors']['primary']);
        $this->assertSame('#111827', $design['colors']['text'], 'invalid values are ignored');
        $this->assertSame('#374151', $design['colors']['muted']);
        $this->assertSame('#f8fafc', $design['colors']['surface'], 'at most two colours per round');
        $this->assertSame('cards', $home->firstWhere('block', 'faq')['variant']);
        $this->assertNull($home->firstWhere('block', 'gallery'), 'variants change, sections are never added');
        $this->assertNull($site->custom_css);
        $this->assertSame('Galaxy Run: jogo de corrida espacial grátis', $site->pages()->first()->meta_title);
        $this->assertSame('qa', $site->designVersions()->latest('version')->value('source'));
        $this->assertStringContainsString('已保留', collect($task->fresh()->log)->pluck('step')->implode("\n"));
    }

    public function test_colours_stay_when_no_text_is_unreadable(): void
    {
        $site = $this->site();
        $changes = $this->qa([], [])->apply($site, ['design' => self::PATCH['design'], 'pages' => [], 'drop_custom_css' => false], $this->measure(2, 0));

        $this->assertSame('#2563eb', $site->fresh()->design_config['colors']['primary']);
        $this->assertNotContains('颜色 primary → #7c3aed', $changes);
    }

    public function test_a_change_that_does_not_help_is_undone(): void
    {
        $site = $this->site();
        $versions = $site->designVersions()->count();
        $task = GenerationTask::create(['site_id' => $site->id, 'type' => 'qa', 'status' => 'running']);
        $builds = 0;
        // Higher score, but a program check got worse: undone.
        $this->qa([['scores' => ['design' => 60, 'seo' => 70]] + self::PATCH, ['scores' => ['design' => 90, 'seo' => 90]]], [$this->measure(1, 0), $this->measure(1, 3)])
            ->run($site, $task, function () use (&$builds) {
                $builds++;
            }, force: true);

        $site->refresh();
        $this->assertSame(2, $builds, 'built with the change, then again without it');
        $this->assertSame('split', $site->design_config['nav']);
        $this->assertSame('.nav{background:#000}', $site->custom_css);
        $this->assertSame('Galaxy Run', $site->pages()->first()->meta_title);
        $this->assertSame($versions, $site->designVersions()->count());
        $this->assertStringContainsString('已退回', collect($task->fresh()->log)->pluck('step')->implode("\n"));
    }

    public function test_high_scores_need_no_change_and_the_switch_turns_it_off(): void
    {
        $site = $this->site();
        $task = GenerationTask::create(['site_id' => $site->id, 'type' => 'full', 'status' => 'running']);
        $this->qa([['scores' => ['design' => 95, 'seo' => 93]] + self::PATCH], [$this->measure()])->run($site, $task, fn () => $this->fail('no rebuild'));
        $this->assertSame('split', $site->fresh()->design_config['nav']);

        $count = count($task->fresh()->log);
        Settings::set('qa_enabled', false);
        $this->qa([], [])->run($site, $task, fn () => $this->fail('no rebuild'));
        $this->assertCount($count, $task->fresh()->log, 'switched off: nothing runs');
    }

    public function test_the_pass_marks_come_from_the_settings(): void
    {
        $site = $this->site();
        $task = GenerationTask::create(['site_id' => $site->id, 'type' => 'qa', 'status' => 'running']);
        Settings::set('qa_min_design', 80);
        Settings::set('qa_min_seo', 85);
        $this->qa([['scores' => ['design' => 81, 'seo' => 86]] + self::PATCH], [$this->measure()])->run($site, $task, fn () => $this->fail('passed: no change'), force: true);
        $this->assertStringContainsString('已达到合格分（美观 80 / SEO 85）', collect($task->fresh()->log)->pluck('step')->implode("\n"));

        Settings::set('qa_min_seo', 90);
        $builds = 0;
        $this->qa([['scores' => ['design' => 81, 'seo' => 86]] + self::PATCH, ['scores' => ['design' => 85, 'seo' => 92]]], [$this->measure(), $this->measure()])
            ->run($site->fresh(), $task, function () use (&$builds) {
                $builds++;
            }, force: true);
        $this->assertSame(1, $builds, 'below the SEO mark: changed');
    }
}
