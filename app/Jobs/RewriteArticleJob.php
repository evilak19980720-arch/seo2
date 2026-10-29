<?php

namespace App\Jobs;

use App\Enums\ArticleStatus;
use App\Enums\ArticleTaskStatus;
use App\Models\Article;
use App\Models\ArticleTask;
use App\Services\Content\ArticlePipeline;
use App\Services\Content\PublishScheduler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cold-content rewrite: regenerate on the same keyword and URL. A rewrite
 * that passes guardrails goes back to the index; otherwise it stays noindex.
 */
class RewriteArticleJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /** Row on the 文章队列 page, created when the job is queued. */
    public ArticleTask $task;

    public function __construct(public Article $article)
    {
        $this->onQueue('content');
        $this->task = ArticleTask::create(['site_id' => $article->site_id, 'article_id' => $article->id, 'kind' => 'rewrite', 'keyword' => $article->keyword?->keyword, 'status' => ArticleTaskStatus::Waiting]);
    }

    public function failed(?Throwable $e): void
    {
        $this->task->step(ArticleTaskStatus::Failed, ['note' => Str::limit($e?->getMessage() ?? '任务超时或被中断', 2000)]);
    }

    public function handle(ArticlePipeline $pipeline, PublishScheduler $scheduler): void
    {
        $article = $this->article->load(['site.domain', 'site.brandProfile', 'keyword']);
        if (! $article->keyword) {
            $this->task->step(ArticleTaskStatus::Skipped, ['note' => '文章没有目标关键词，无法重写']);

            return;
        }
        $noindexedAt = $article->noindexed_at;
        $rewritten = $pipeline->generate($article->site, $article->keyword, $article, $this->task);

        $rewritten->update([
            'status' => $rewritten->status === ArticleStatus::Queued ? ArticleStatus::Published : ArticleStatus::Noindex,
            'noindexed_at' => $noindexedAt,
            'block_reason' => $rewritten->block_reason,
        ]);
        $scheduler->afterChange($rewritten->refresh());
        $this->task->update(['note' => $rewritten->status === ArticleStatus::Published ? '重写通过，已重新发布' : "重写未通过，保持 noindex\n".$rewritten->block_reason]);
    }
}
