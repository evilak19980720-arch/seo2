<?php

namespace App\Models;

use App\Enums\PromptType;
use Illuminate\Database\Eloquent\Model;

class Prompt extends Model
{
    protected $fillable = ['type', 'site_type', 'locale', 'version', 'system', 'template', 'is_active'];

    protected function casts(): array
    {
        return ['type' => PromptType::class, 'is_active' => 'boolean'];
    }

    /**
     * Most specific active prompt for (type × site type × language):
     * exact match beats wildcard (null) on each axis.
     */
    public static function resolve(PromptType $type, ?string $siteType = null, ?string $locale = null): ?self
    {
        $language = $locale ? strtolower(explode('-', $locale)[0]) : null;

        return static::query()
            ->where('type', $type->value)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('site_type')->orWhere('site_type', $siteType))
            ->where(fn ($q) => $q->whereNull('locale')->orWhere('locale', $locale)->orWhere('locale', $language))
            ->get()
            ->sortByDesc(fn (Prompt $p) => [
                ($p->site_type !== null ? 2 : 0) + ($p->locale === $locale ? 1 : ($p->locale !== null ? 0.5 : 0)),
                $p->version,
            ])
            ->first();
    }

    public function render(array $vars): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $this->template);
    }
}
