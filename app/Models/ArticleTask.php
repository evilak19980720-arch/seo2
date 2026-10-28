<?php

namespace App\Models;

use App\Enums\ArticleTaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Progress of one article generation or rewrite, for the 文章队列 page. */
class ArticleTask extends Model
{
    use MassPrunable;

    protected $fillable = ['site_id', 'article_id', 'kind', 'keyword', 'status', 'note', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['status' => ArticleTaskStatus::class, 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** Move to a step; finished steps get their end time. */
    public function step(ArticleTaskStatus $status, array $attrs = []): void
    {
        $this->update($attrs + ['status' => $status]
            + ($status->isRunning() && ! $this->started_at ? ['started_at' => now()] : [])
            + ($status->isRunning() || $status === ArticleTaskStatus::Waiting ? [] : ['finished_at' => now()]));
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(30));
    }
}
