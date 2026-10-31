<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsCache extends Model
{
    public $timestamps = false;

    protected $table = 'analytics_cache';

    protected $fillable = ['site_id', 'date', 'users', 'sessions', 'pageviews', 'payload'];

    protected function casts(): array
    {
        return ['date' => 'date', 'payload' => 'array'];
    }
}
