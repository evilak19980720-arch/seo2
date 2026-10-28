<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandProfile extends Model
{
    protected $fillable = [
        'site_id', 'product_name', 'tagline', 'description', 'selling_points', 'specs', 'target_audience',
        'use_cases', 'media', 'cta_links', 'faq', 'company_name', 'company_email', 'company_address', 'license_info', 'certification_info', 'completeness',
        'localized', 'localized_hash',
    ];

    /** Profile fields shown on the site; rendered from the site-language copy when the profile is written in another language. */
    public const DISPLAY_FIELDS = ['tagline', 'description', 'selling_points', 'specs', 'target_audience', 'use_cases', 'faq', 'company_address'];

    /** Copy of this profile with the site-language versions of the display fields. */
    public function forDisplay(): self
    {
        $copy = clone $this;
        foreach ((array) $this->localized as $field => $value) {
            if (in_array($field, self::DISPLAY_FIELDS, true) && filled($value)) {
                $copy->setAttribute($field, $value);
            }
        }

        return $copy;
    }

    public function displayHash(string $locale): string
    {
        return md5($locale.json_encode($this->only(self::DISPLAY_FIELDS), JSON_UNESCAPED_UNICODE));
    }

    protected function casts(): array
    {
        return [
            'localized' => 'array',
            'selling_points' => 'array',
            'specs' => 'array',
            'media' => 'array',
            'cta_links' => 'array',
            'faq' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (BrandProfile $profile) => $profile->completeness = $profile->computeCompleteness());
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Weighted completeness score (0-100). The minimum viable profile is
     * "an intro + some images + one download/buy link", so those weigh most.
     */
    public function computeCompleteness(): int
    {
        $checks = [
            'product_name' => [10, filled($this->product_name)],
            'tagline' => [5, filled($this->tagline)],
            'description' => [20, mb_strlen((string) $this->description) >= 200],
            'selling_points' => [10, count($this->selling_points ?? []) >= 3],
            'specs' => [10, count($this->specs ?? []) >= 2],
            'target_audience' => [5, filled($this->target_audience)],
            'use_cases' => [5, filled($this->use_cases)],
            'media' => [15, count($this->media ?? []) >= 2],
            'cta_links' => [10, count($this->cta_links ?? []) >= 1],
            'faq' => [5, count($this->faq ?? []) >= 3],
            'company' => [5, filled($this->company_name) && filled($this->company_email)],
        ];

        return (int) collect($checks)->sum(fn ($c) => $c[1] ? $c[0] : 0);
    }

    /** Compact text block used as the only factual source in prompts. */
    public function toPromptContext(): string
    {
        $lines = [
            'Product: '.$this->product_name,
            'Tagline: '.$this->tagline,
            'Description: '.$this->description,
            'Selling points: '.implode('; ', $this->selling_points ?? []),
            'Specs: '.collect($this->specs ?? [])->map(fn ($s) => ($s['key'] ?? '').': '.($s['value'] ?? ''))->implode('; '),
            'Target audience: '.$this->target_audience,
            'Use cases: '.$this->use_cases,
            'Links: '.collect($this->cta_links ?? [])->map(fn ($l) => ($l['label'] ?? '').' '.($l['url'] ?? ''))->implode('; '),
            'FAQ: '.collect($this->faq ?? [])->map(fn ($f) => 'Q: '.($f['question'] ?? '').' A: '.($f['answer'] ?? ''))->implode(' | '),
            'Company: '.trim($this->company_name.' '.$this->company_email.' '.$this->company_address),
            'Licence: '.$this->license_info,
            'Testing / certification: '.$this->certification_info,
        ];

        return implode("\n", array_filter($lines, fn ($l) => ! str_ends_with(trim($l), ':')));
    }
}
