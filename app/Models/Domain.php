<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Domain extends Model
{
    protected $fillable = [
        'name', 'ip', 'cf_zone_id', 'cf_status', 'name_servers', 'cf_synced_at', 'cf_proxied',
        'cf_ssl_status', 'gsc_status', 'gsc_verification_token', 'certificate_expires_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'cf_proxied' => 'boolean',
            'name_servers' => 'array',
            'cf_synced_at' => 'datetime',
            'certificate_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Domain $domain) {
            $domain->name = static::normalize($domain->name);
        });
    }

    public static function normalize(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('#^https?://#', '', $name);
        $name = preg_replace('#^www\.#', '', $name);

        return rtrim(explode('/', $name)[0], '.');
    }

    public function site(): HasOne
    {
        return $this->hasOne(Site::class);
    }

    /** In Cloudflare and not bound to a site — the only domains a new site may use. */
    public function scopeAvailable($query)
    {
        return $query->whereNotNull('cf_zone_id')
            ->where(fn ($q) => $q->whereNull('cf_status')->orWhere('cf_status', '!=', 'missing'))
            ->whereDoesntHave('site');
    }

    /** Host label for a DNS name: "@" → apex, "www" → www.apex, "x" → x.apex. */
    public function fqdn(string $host): string
    {
        $host = strtolower(trim($host, ". \t"));

        return match (true) {
            $host === '' || $host === '@' => $this->name,
            str_ends_with($host, $this->name) => $host,
            default => "{$host}.{$this->name}",
        };
    }
}
