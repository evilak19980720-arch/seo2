<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ad extends Model
{
    protected $fillable = [
        'name', 'code', 'position', 'desktop_width', 'desktop_height', 'mobile_width', 'mobile_height', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::created(fn (Ad $ad) => $ad->logChange('created', $ad->getAttributes()));
        static::updated(fn (Ad $ad) => $ad->logChange('updated', $ad->getChanges()));
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class);
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(AdChangeLog::class);
    }

    public function stats(): HasMany
    {
        return $this->hasMany(AdStat::class);
    }

    public function logChange(string $action, array $changes): void
    {
        unset($changes['updated_at'], $changes['created_at']);
        $this->changeLogs()->create(['user_id' => auth()->id(), 'action' => $action, 'changes' => $changes]);
    }
}
