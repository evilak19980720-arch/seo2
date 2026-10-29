<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Points a newly bound domain (@ and www) at this server through Cloudflare. */
class ConnectDomainJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public Domain $domain) {}

    public function handle(SiteProvisioner $provisioner): void
    {
        $provisioner->connect($this->domain->fresh() ?? $this->domain);
    }
}
