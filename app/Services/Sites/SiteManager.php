<?php

namespace App\Services\Sites;

use App\Enums\SiteStatus;
use App\Jobs\GenerateSiteJob;
use App\Jobs\RebuildSiteJob;
use App\Models\GenerationTask;
use App\Models\Site;
use App\Services\Render\StaticSiteBuilder;
use Illuminate\Support\Facades\DB;

/** Site lifecycle actions used by the admin panel and CLI. */
class SiteManager
{
    public function __construct(protected StaticSiteBuilder $builder) {}

    public function generate(Site $site, string $type = 'full'): GenerationTask
    {
        $task = $site->generationTasks()->create(['type' => $type, 'status' => 'queued', 'step' => '排队中']);
        $site->update(['status' => SiteStatus::Generating]);
        GenerateSiteJob::dispatch($task);

        return $task;
    }

    public function rebuild(Site $site): void
    {
        RebuildSiteJob::dispatch($site);
    }

    /** Keep hreflang relations symmetric across all language sites of a product. */
    public function syncRelations(Site $site, array $relatedIds): void
    {
        DB::transaction(function () use ($site, $relatedIds) {
            $relatedIds = array_values(array_diff(array_map('intval', $relatedIds), [$site->id]));
            $previous = $site->relatedSites()->pluck('sites.id')->all();
            $site->relatedSites()->sync($relatedIds);
            foreach (array_diff($previous, $relatedIds) as $removed) {
                Site::find($removed)?->relatedSites()->detach($site->id);
            }
            foreach ($relatedIds as $id) {
                Site::find($id)?->relatedSites()->syncWithoutDetaching([$site->id]);
            }
        });
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

    /** Pages answer 410 Gone (not a DNS cut) so search engines drop them cleanly. */
    public function delete(Site $site): void
    {
        $this->builder->removeSite($site->load('domain'));
        $site->update(['status' => SiteStatus::Deleted]);
        $site->domain->update(['status' => 'available']);
        $site->relatedSites()->detach();
        DB::table('site_relations')->where('related_site_id', $site->id)->delete();
        $site->delete();
    }
}
