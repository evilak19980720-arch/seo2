<?php

namespace App\Jobs;

use App\Models\GenerationTask;
use App\Models\Site;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\SiteArt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Draws a site's missing AI pictures after it went live, then rebuilds the
 * pages so they show them. Runs in the background: generation never waits
 * for pictures.
 */
class GenerateSiteArtJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public Site $site, public ?GenerationTask $task = null) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('site-art-'.$this->site->id))->expireAfter(1800)->dontRelease()];
    }

    public function handle(SiteArt $art, StaticSiteBuilder $builder): void
    {
        $site = $this->site->fresh(['domain', 'brandProfile']);
        if (! $site) {
            return;
        }
        $made = $art->draw($site, fn (string $line) => $this->task?->progress($line));
        if ($made > 0 && is_dir(StaticSiteBuilder::dirFor($site))) {
            $builder->buildAll($site->fresh(['domain', 'brandProfile']));
            $this->task?->progress("AI 配图完成：{$made} 张，页面已更新");
        }
    }
}
