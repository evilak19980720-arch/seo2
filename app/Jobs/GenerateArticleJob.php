<?php

namespace App\Jobs;

use App\Enums\ArticleTaskStatus;
use App\Models\ArticleTask;
use App\Models\Site;
use App\Services\Content\ArticlePipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class GenerateArticleJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /** Row on the 文章队列 page, created when the job is queued. */
    public ArticleTask $task;

    public function __construct(public Site $site)
    {
        $this->onQueue('content');
        $this->task = ArticleTask::create(['site_id' => $site->id, 'kind' => 'new', 'status' => ArticleTaskStatus::Waiting]);
    }

    public function handle(ArticlePipeline $pipeline): void
    {
        // One article at a time per site.
        $lock = Cache::lock('articles-'.$this->site->id, 900);
        if (! $lock->get()) {
            $this->task->step(ArticleTaskStatus::Skipped, ['note' => '该站点已有文章在生成']);

            return;
        }
        try {
            $pipeline->generateNext($this->site->load(['domain', 'brandProfile']), $this->task);
        } catch (Throwable $e) {
            $this->task->step(ArticleTaskStatus::Failed, ['note' => Str::limit($e->getMessage(), 500)]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $e): void
    {
        if (! $this->task->fresh()?->status->isRunning() && $this->task->fresh()?->status !== ArticleTaskStatus::Waiting) {
            return;
        }
        $this->task->step(ArticleTaskStatus::Failed, ['note' => Str::limit($e?->getMessage() ?? '任务超时或被中断', 500)]);
    }
}
