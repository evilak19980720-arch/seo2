<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Integrations\Cloudflare;
use App\Services\Render\StaticSiteBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** "更新": re-render every static page of a site with its current template. */
class RebuildSiteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public Site $site)
    {
        $this->onQueue('generation');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('site-'.$this->site->id))->expireAfter(900)];
    }

    public function handle(StaticSiteBuilder $builder): void
    {
        $site = $this->site->load(['domain', 'brandProfile']);
        $paths = $builder->buildAll($site);

        $cf = new Cloudflare;
        if ($cf->configured() && $site->domain->cf_zone_id) {
            rescue(fn () => $cf->purgeUrls($site->domain->cf_zone_id, array_map(fn ($p) => $site->url($p), $paths)));
        }
    }
}
