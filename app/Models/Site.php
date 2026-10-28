<?php

namespace App\Models;

use App\Enums\SiteStatus;
use App\Services\Sites\SiteStructure;
use App\Services\Theme\ExtraPages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'domain_id', 'site_type', 'product_name', 'name', 'logo_path', 'locale', 'target_country', 'title',
        'description', 'reference_url', 'reference_images', 'reference_import_images', 'reference_rewrite_content', 'has_accounts', 'has_payments', 'design_config', 'structure', 'design_version', 'custom_css', 'status',
        'auto_publish_paused', 'pause_reason', 'indexed_pages', 'impressions_7d', 'clicks_7d',
        'ga4_property_id', 'ga4_measurement_id', 'launched_at', 'built_at',
    ];

    protected function casts(): array
    {
        return [
            'design_config' => 'array',
            'reference_images' => 'array',
            'reference_import_images' => 'boolean',
            'reference_rewrite_content' => 'boolean',
            'has_accounts' => 'boolean',
            'has_payments' => 'boolean',
            'structure' => 'array',
            'status' => SiteStatus::class,
            'auto_publish_paused' => 'boolean',
            'launched_at' => 'datetime',
            'built_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function brandProfile(): HasOne
    {
        return $this->hasOne(BrandProfile::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function ads(): BelongsToMany
    {
        return $this->belongsToMany(Ad::class);
    }

    public function designVersions(): HasMany
    {
        return $this->hasMany(DesignVersion::class);
    }

    public function generationTasks(): HasMany
    {
        return $this->hasMany(GenerationTask::class);
    }

    public function searchStats(): HasMany
    {
        return $this->hasMany(SearchStat::class);
    }

    public function host(): string
    {
        return $this->domain->name;
    }

    public function baseUrl(): string
    {
        return 'https://'.$this->host();
    }

    public function url(string $path = ''): string
    {
        $path = trim($path, '/');

        return $this->baseUrl().'/'.($path === '' ? '' : $path.'/');
    }

    public function language(): string
    {
        return strtolower(explode('-', $this->locale)[0]);
    }

    /** @var array<string, array> */
    protected array $typeConfigCache = [];

    public function typeConfig(): array
    {
        $config = config('site_types.'.$this->site_type, config('site_types.brand'));
        if (! empty($config['sectioned'])) {
            // Pool presets: only this site's chosen pages and sections.
            $key = md5(json_encode([$this->site_type, $this->structure]));

            return $this->typeConfigCache[$key] ??= SiteStructure::apply($config, $this->structure ?: SiteStructure::choose($this, $config));
        }

        return ExtraPages::merge($config, $this->design_config['extra_pages'] ?? []);
    }

    public function ageInMonths(): float
    {
        $start = $this->launched_at ?? $this->created_at;

        return $start ? $start->diffInDays(now()) / 30 : 0;
    }

    public function isPubliclyServed(): bool
    {
        return in_array($this->status, [SiteStatus::Live], true);
    }
}
