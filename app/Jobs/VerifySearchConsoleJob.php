<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Retries DNS-TXT verification until the record propagates, then submits the sitemap. */
class VerifySearchConsoleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public Domain $domain) {}

    public function backoff(): array
    {
        return [600, 1800, 3600, 7200, 14400, 28800, 43200];
    }

    public function handle(SiteProvisioner $provisioner): void
    {
        $provisioner->verifySearchConsole($this->domain);
        $this->domain->refresh();

        if ($this->domain->gsc_status !== 'verified') {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 43200);

            return;
        }
        if ($site = $this->domain->site) {
            $provisioner->submitSitemap($site);
        }
    }
}
