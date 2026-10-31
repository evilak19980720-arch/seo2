<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One picture read from the reference site, with the card (name, text, link, detail page) it belongs to. */
class ReferenceItem extends Model
{
    protected $fillable = ['site_id', 'kind', 'name', 'text', 'detail', 'link', 'page_url', 'image_url', 'image_hash', 'image_path', 'width', 'height'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
