<?php

namespace App\Models;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Article extends Model
{
    protected $fillable = [
        'site_id', 'keyword_id', 'prompt_id', 'title', 'slug', 'type', 'meta_title', 'meta_description',
        'featured_image', 'featured_image_alt', 'author', 'body', 'status', 'block_reason', 'similarity_score',
        'fingerprint', 'impressions_total', 'impressions_30d', 'published_at', 'noindexed_at', 'index_verdict', 'index_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ArticleStatus::class,
            'type' => ArticleType::class,
            'fingerprint' => 'array',
            'published_at' => 'datetime',
            'noindexed_at' => 'datetime',
            'index_checked_at' => 'datetime',
            'similarity_score' => 'float',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    public static function blogPrefix(string $language): string
    {
        return 'blog';
    }

    public function path(): string
    {
        return 'blog/'.$this->slug;
    }

    public function scopeVisible($query)
    {
        return $query->whereIn('status', [ArticleStatus::Published->value, ArticleStatus::Noindex->value]);
    }

    public function plainText(): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $this->body))));
    }

    public function wordCount(): int
    {
        return count(preg_split('/\s+/u', $this->plainText(), -1, PREG_SPLIT_NO_EMPTY));
    }
}
