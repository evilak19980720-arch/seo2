<?php

namespace App\Jobs;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Services\Content\ArticlePipeline;
use App\Services\Content\PublishScheduler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cold-content rewrite: regenerate on the same keyword and URL. A rewrite
 * that passes guardrails goes back to the index; otherwise it stays noindex.
 */
class RewriteArticleJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public Article $article)
    {
        $this->onQueue('content');
    }

    public function handle(ArticlePipeline $pipeline, PublishScheduler $scheduler): void
    {
        $article = $this->article->load(['site.domain', 'site.brandProfile', 'keyword']);
        if (! $article->keyword) {
            return;
        }
        $noindexedAt = $article->noindexed_at;
        $rewritten = $pipeline->generate($article->site, $article->keyword, $article);

        $rewritten->update([
            'status' => $rewritten->status === ArticleStatus::Queued ? ArticleStatus::Published : ArticleStatus::Noindex,
            'noindexed_at' => $noindexedAt,
            'block_reason' => $rewritten->block_reason,
        ]);
        $scheduler->afterChange($rewritten->refresh());
    }
}
