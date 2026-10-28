<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenerationTask extends Model
{
    protected $fillable = ['site_id', 'type', 'status', 'step', 'pages_total', 'pages_done', 'log', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['log' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function progress(string $step, ?int $done = null): void
    {
        $log = $this->log ?? [];
        $log[] = ['at' => now()->toDateTimeString(), 'step' => $step];
        $this->update(['step' => $step, 'log' => $log] + ($done !== null ? ['pages_done' => $done] : []));
    }
}
