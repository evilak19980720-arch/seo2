<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Domain extends Model
{
    protected $fillable = [
        'name', 'status', 'ip', 'source', 'origin_topic', 'history_notes', 'cf_zone_id', 'cf_proxied',
        'cf_ssl_status', 'gsc_status', 'gsc_verification_token', 'certificate_expires_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'cf_proxied' => 'boolean',
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

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }
}
