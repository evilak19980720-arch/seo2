<?php

namespace App\Models;

use App\Enums\KeywordStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Keyword extends Model
{
    protected $fillable = ['site_id', 'keyword', 'intent', 'target_path', 'status', 'source', 'impressions', 'clicks'];

    protected function casts(): array
    {
        return ['status' => KeywordStatus::class];
    }

    protected static function booted(): void
    {
        static::saving(fn (Keyword $k) => $k->keyword = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $k->keyword))));
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
