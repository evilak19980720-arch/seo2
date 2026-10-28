<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrawlerStat extends Model
{
    public $timestamps = false;

    protected $fillable = ['site_id', 'date', 'bot', 'hits', 'fake_hits', 'errors_4xx', 'errors_5xx'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
