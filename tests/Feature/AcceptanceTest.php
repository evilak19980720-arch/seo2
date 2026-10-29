<?php

namespace Tests\Feature;

use App\Models\GenerationTask;
use App\Models\Site;
use App\Services\Integrations\Lighthouse;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\SiteGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        $site = $this->makeSite();
        $pages = [
            'home' => [['block' => 'banner', 'variant' => 'slider'], ['block' => 'card_grid', 'variant' => 'tiles']],
            'product' => [['block' => 'hero', 'variant' => 'split'], ['block' => 'entry_list', 'variant' => 'cards']],
            'updates' => [['block' => 'hero', 'variant' => 'split'], ['block' => 'entry_list', 'variant' => 'cards']],
            'media' => [['block' => 'gallery', 'variant' => 'grid']],
            'about' => [['block' => 'prose', 'variant' => 'default']],
            'terms' => [['block' => 'legal', 'variant' => 'default']],
        ];
        $site->update(['design_config' => ['pages' => $pages]]);
        foreach (array_keys($pages) as $type) {
            $site->pages()->create(['type' => $type, 'slug' => $type === 'home' ? '' : $type, 'title' => ucfirst($type)]);
        }

        return $site->fresh();
    }

    public function test_one_page_per_distinct_composition_is_audited(): void
    {
        $sample = app(SiteGenerator::class)->acceptanceSample($this->site());

        $this->assertEqualsCanonicalizing(['home', 'product', 'media', 'about', 'blog'], array_keys($sample), 'updates has the same blocks as product; terms is plain like about');
    }

    public function test_noindex_pages_count_for_speed_only_and_a_miss_never_fails_the_site(): void
    {
        $site = $this->site();
        $dir = StaticSiteBuilder::dirFor($site);
        @mkdir("{$dir}/blog", 0777, true);
        file_put_contents("{$dir}/blog/index.html", '<html><head><meta name="robots" content="noindex, follow"></head></html>');
        config(['platform.lighthouse.bin' => PHP_BINARY]);

        $calls = [];
        $this->app->instance(Lighthouse::class, new class($calls) extends Lighthouse
        {
            public function __construct(public array &$calls) {}

            public function auditSite(string $siteDir, array $paths): array
            {
                $this->calls[] = array_keys($paths);

                return collect($paths)->map(fn ($p, $t) => ['performance' => $t === 'media' ? 70 : 99, 'seo' => $t === 'blog' ? 61 : 92])->all();
            }
        });
        $generator = app(SiteGenerator::class);
        $task = GenerationTask::create(['site_id' => $site->id, 'type' => 'full', 'status' => 'running']);

        $generator->acceptance($site, $task);

        $log = collect($task->fresh()->log)->pluck('step')->implode("\n");
        $this->assertStringContainsString('blog P99/S61(noindex)', $log);
        $this->assertSame(['media'], end($calls), 'later rounds audit only what failed');
        $this->assertStringContainsString('media 未达到', $log);
        $this->assertStringNotContainsString('blog 未达到', $log);
    }
}
