<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Content\ArticlePipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class GenerateArticleJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public Site $site)
    {
        $this->onQueue('content');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('articles-'.$this->site->id))->dontRelease()->expireAfter(900)];
    }

    public function handle(ArticlePipeline $pipeline): void
    {
        $pipeline->generateNext($this->site->load(['domain', 'brandProfile']));
    }
}
