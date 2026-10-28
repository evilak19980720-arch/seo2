<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProvider extends Model
{
    protected $fillable = [
        'name', 'driver', 'base_url', 'api_key', 'model', 'embedding_model', 'supports_vision', 'is_default',
        'is_active', 'max_concurrency', 'monthly_budget', 'input_price_per_million', 'output_price_per_million',
        'last_tested_at', 'last_test_result',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'supports_vision' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
            'monthly_budget' => 'float',
        ];
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
    }

    public function monthSpend(): float
    {
        return (float) $this->usageLogs()->where('created_at', '>=', now()->startOfMonth())->sum('cost');
    }

    public function overBudget(): bool
    {
        return $this->monthly_budget !== null && $this->monthly_budget > 0 && $this->monthSpend() >= $this->monthly_budget;
    }
}
