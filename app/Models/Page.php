<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Page extends Model
{
    protected $fillable = ['site_id', 'type', 'slug', 'title', 'meta_title', 'meta_description', 'content', 'noindex', 'index_verdict', 'index_checked_at'];

    protected function casts(): array
    {
        return ['content' => 'array', 'noindex' => 'boolean', 'index_checked_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function path(): string
    {
        return trim($this->slug, '/');
    }
}
