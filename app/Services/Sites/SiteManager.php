<?php

namespace App\Services\Sites;

use App\Enums\SiteStatus;
use App\Jobs\GenerateSiteJob;
use App\Jobs\RebuildSiteJob;
use App\Models\GenerationTask;
use App\Models\Site;
use App\Services\Render\StaticSiteBuilder;

/** Site lifecycle actions used by the admin panel and CLI. */
class SiteManager
{
    public function __construct(protected StaticSiteBuilder $builder) {}

    public function generate(Site $site, string $type = 'full'): GenerationTask
    {
        // Tasks left behind by a crashed worker are closed so the new one is the only active task.
        $site->generationTasks()->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'step' => '已被新的生成任务取代', 'finished_at' => now()]);
        $task = $site->generationTasks()->create(['type' => $type, 'status' => 'queued', 'step' => '排队中']);
        $site->update(['status' => SiteStatus::Generating]);
        GenerateSiteJob::dispatch($task);

        return $task;
    }

    /** A task is queued or running and has shown progress in the last 30 minutes (older ones are treated as dead). */
    public function isBusy(Site $site): bool
    {
        return $site->generationTasks()->whereIn('status', ['queued', 'running'])
            ->where('updated_at', '>=', now()->subMinutes(30))->exists();
    }

    public function rebuild(Site $site): void
    {
        RebuildSiteJob::dispatch($site);
    }

    public function launch(Site $site): void
    {
        $site->update(['status' => SiteStatus::Live, 'launched_at' => $site->launched_at ?? now()]);
        rescue(fn () => app(SiteProvisioner::class)->submitSitemap($site));
    }

    public function pause(Site $site): void
    {
        $site->update(['status' => SiteStatus::Paused, 'auto_publish_paused' => true, 'pause_reason' => '手动暂停']);
    }

    public function resume(Site $site): void
    {
        $site->update(['status' => SiteStatus::Live, 'auto_publish_paused' => false, 'pause_reason' => null]);
    }

    /**
     * Pages answer 410 Gone (not a DNS cut) so search engines drop them cleanly.
     * The site row is removed for good so the domain can be bound to a new site.
     */
    public function delete(Site $site): void
    {
        $this->builder->removeSite($site->load('domain'));
        $site->forceDelete();
    }
}
