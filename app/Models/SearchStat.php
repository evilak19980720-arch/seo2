<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchStat extends Model
{
    public $timestamps = false;

    protected $fillable = ['site_id', 'date', 'impressions', 'clicks', 'ctr', 'position', 'indexed_pages'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
