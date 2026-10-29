<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\GenerationTask;
use App\Services\Sites\SiteGenerator;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

/**
 * Full "生成网站" run: Cloudflare + Search Console provisioning, whole-site
 * generation, then sitemap submission. Runs on the "generation" queue, whose
 * worker count (Supervisor numprocs) caps concurrent site builds.
 */
class GenerateSiteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public GenerationTask $task)
    {
        $this->onQueue('generation');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('site-'.$this->task->site_id))->expireAfter(1800)];
    }

    /** A worker killed by the time limit never reaches the generator's own error handling: close the task here. */
    public function failed(?Throwable $e): void
    {
        $task = $this->task->fresh();
        if ($task && in_array($task->status, ['queued', 'running'], true)) {
            $task->progress('失败：'.($e ? Str::limit($e->getMessage(), 200) : '任务被中止'));
            $task->update(['status' => 'failed', 'finished_at' => now()]);
            if ($task->type !== 'qa' && $task->site?->status === SiteStatus::Generating) {
                $task->site->update(['status' => SiteStatus::Failed]);
            }
        }
    }

    public function handle(SiteProvisioner $provisioner, SiteGenerator $generator): void
    {
        $site = $this->task->site()->with('domain')->first();

        if ($this->task->type === 'full') {
            foreach ($provisioner->provision($site) as $line) {
                $this->task->progress($line);
            }
        }

        $generator->run($this->task);

        $site->refresh();
        if ($site->status->value === 'live') {
            foreach ($provisioner->submitSitemap($site) as $line) {
                $this->task->progress($line);
            }
            if ($site->domain->gsc_status === 'pending') {
                VerifySearchConsoleJob::dispatch($site->domain)->delay(now()->addMinutes(10));
            }
        }
    }
}
