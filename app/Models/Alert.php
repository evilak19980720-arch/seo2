<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $fillable = ['site_id', 'type', 'level', 'message', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Raise once: skip if an identical unresolved alert exists. */
    public static function raise(string $type, string $message, ?int $siteId = null, string $level = 'warning'): self
    {
        return static::firstOrCreate(
            ['type' => $type, 'site_id' => $siteId, 'resolved_at' => null],
            ['message' => $message, 'level' => $level],
        );
    }
}
