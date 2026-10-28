<?php

namespace App\Services\Content;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\Site;
use App\Services\Integrations\Cloudflare;
use App\Services\Integrations\IndexNow;
use App\Services\Render\StaticSiteBuilder;
use App\Support\Settings;
use Throwable;

/**
 * Weekly per-site caps by site age, then: publish → incremental rebuild →
 * Cloudflare purge of the changed URLs → IndexNow.
 */
class PublishScheduler
{
    public function __construct(protected StaticSiteBuilder $builder) {}

    public function weeklyCap(Site $site): int
    {
        $months = $site->ageInMonths();
        if ($months < 3) {
            return (int) Settings::get('cap_months_0_3', 3);
        }
        if ($months < 6) {
            return (int) Settings::get('cap_months_3_6', 5);
        }

        $cap = (int) Settings::get('cap_after_6', 5);
        $published = $site->articles()->where('status', ArticleStatus::Published->value)->where('published_at', '<', now()->subDays(30));
        $total = (clone $published)->count();
        $withImpressions = (clone $published)->where('impressions_30d', '>', 0)->count();
        if ($total > 0 && $withImpressions / $total > (float) Settings::get('cap_raise_ratio', 0.5)) {
            $cap = min((int) Settings::get('cap_max', 14), $cap + (int) Settings::get('cap_step', 2));
        }

        return $cap;
    }

    public function publishedThisWeek(Site $site): int
    {
        return $site->articles()->whereNotNull('published_at')->where('published_at', '>=', now()->subDays(7))->count();
    }

    /** Publish at most one queued article for the site if under its cap. */
    public function publishNext(Site $site): ?Article
    {
        if ($site->auto_publish_paused || $this->publishedThisWeek($site) >= $this->weeklyCap($site)) {
            return null;
        }
        $article = $site->articles()->where('status', ArticleStatus::Queued->value)->orderBy('id')->first();
        if (! $article) {
            return null;
        }

        $article->update(['status' => ArticleStatus::Published, 'published_at' => now()]);
        $this->afterChange($article);

        return $article;
    }

    /** Rebuild affected pages, purge CDN cache and notify IndexNow. */
    public function afterChange(Article $article): array
    {
        $urls = $this->builder->rebuildForArticle($article);
        $site = $article->site;

        try {
            $cf = new Cloudflare;
            if ($cf->configured() && $site->domain->cf_zone_id) {
                $cf->purgeUrls($site->domain->cf_zone_id, $urls);
            }
        } catch (Throwable $e) {
            report($e);
        }

        try {
            (new IndexNow)->submit($site->domain->name, $urls);
        } catch (Throwable $e) {
            report($e);
        }

        return $urls;
    }
}
