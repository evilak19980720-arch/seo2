<?php

namespace App\Services\Domains;

use App\Models\Domain;
use App\Services\Integrations\Cloudflare;
use Illuminate\Support\Facades\DB;

/** Mirrors the Cloudflare zone list into the domains table. */
class DomainSync
{
    public function __construct(protected ?Cloudflare $cf = null)
    {
        $this->cf ??= new Cloudflare;
    }

    /** @return array{added: int, updated: int, missing: int} */
    public function pull(): array
    {
        $zones = $this->cf->listZones();
        $added = $updated = 0;

        DB::transaction(function () use ($zones, &$added, &$updated) {
            foreach ($zones as $zone) {
                $domain = Domain::firstOrNew(['name' => Domain::normalize($zone['name'])]);
                $domain->exists ? $updated++ : $added++;
                $domain->fill(self::attributes($zone))->save();
            }
        });

        // Zones removed in Cloudflare stay listed (a site may still use them) but can't be picked.
        $missing = Domain::whereNotNull('cf_zone_id')->whereNotIn('cf_zone_id', array_column($zones, 'id'))
            ->update(['cf_status' => 'missing', 'cf_synced_at' => now()]);

        return compact('added', 'updated', 'missing');
    }

    /** Add a domain as a new Cloudflare zone and store it. */
    public function add(string $name): Domain
    {
        $name = Domain::normalize($name);
        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $name)) {
            throw new \InvalidArgumentException("域名格式不正确：{$name}");
        }

        return Domain::updateOrCreate(['name' => $name], self::attributes($this->cf->createZone($name)));
    }

    /** Remove locally; optionally delete the zone in Cloudflare too. Bound domains are refused. */
    public function remove(Domain $domain, bool $deleteZone = false): void
    {
        if ($domain->site()->exists()) {
            throw new \RuntimeException("{$domain->name} 已绑定网站「{$domain->site->name}」，请先删除网站");
        }
        if ($deleteZone && $domain->cf_zone_id && $domain->cf_status !== 'missing') {
            $this->cf->deleteZone($domain->cf_zone_id);
        }
        $domain->delete();
    }

    protected static function attributes(array $zone): array
    {
        return ['cf_zone_id' => $zone['id'], 'cf_status' => $zone['status'], 'name_servers' => $zone['name_servers'], 'cf_synced_at' => now()];
    }
}
