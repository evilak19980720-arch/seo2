<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProvider extends Model
{
    protected $fillable = [
        'name', 'preset', 'driver', 'base_url', 'options', 'api_key', 'model', 'embedding_model', 'supports_vision', 'is_default',
        'is_active', 'max_concurrency', 'monthly_budget', 'input_price_per_million', 'output_price_per_million',
        'last_tested_at', 'last_test_result',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'options' => 'array',
            'supports_vision' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
            'monthly_budget' => 'float',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function presets(): array
    {
        return config('ai_providers');
    }

    /** Preset options grouped for a select: [group => [key => label]]. */
    public static function presetOptions(): array
    {
        return collect(self::presets())->groupBy('group', preserveKeys: true)
            ->map(fn ($items) => $items->map(fn ($p) => $p['label'])->all())->all();
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
