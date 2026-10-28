<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    protected $fillable = ['ai_provider_id', 'site_id', 'purpose', 'input_tokens', 'output_tokens', 'cost', 'success', 'error'];

    protected function casts(): array
    {
        return ['success' => 'boolean'];
    }
}
