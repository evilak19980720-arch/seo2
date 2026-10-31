<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DesignVersion extends Model
{
    protected $fillable = ['site_id', 'version', 'config', 'custom_css', 'source'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }
}
