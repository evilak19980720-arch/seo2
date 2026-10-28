<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdStat extends Model
{
    public $timestamps = false;

    protected $fillable = ['ad_id', 'site_id', 'date', 'impressions', 'estimated_clicks'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
