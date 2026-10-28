<?php

use App\Enums\ArticleStatus;
use App\Enums\SiteStatus;
use App\Jobs\GenerateArticleJob;
use App\Models\AnalyticsCache;
use App\Models\Alert;
use App\Models\Domain;
use App\Models\Site;
use App\Services\Content\ArticlePipeline;
use App\Services\Content\PublishScheduler;
use App\Services\Integrations\Analytics;
use App\Services\Integrations\GoogleAuth;
use App\Services\Stats\ContentHealth;
use App\Services\Stats\CrawlerLogParser;
use App\Services\Stats\SearchConsoleSync;
use App\Support\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

$liveSites = fn () => Site::with(['domain', 'brandProfile'])->where('status', SiteStatus::Live->value)->get();

Artisan::command('content:generate', function (ArticlePipeline $pipeline, PublishScheduler $scheduler) use ($liveSites) {
    foreach ($liveSites() as $site) {
        // Keep roughly one week of buffer in the queue; never more.
        $queued = $site->articles()->where('status', ArticleStatus::Queued->value)->count();
        if ($pipeline->ineligibleReason($site) === null && $queued < $scheduler->weeklyCap($site) && $pipeline->nextKeyword($site)) {
            GenerateArticleJob::dispatch($site);
            $this->line("queued generation for {$site->domain->name}");
        }
    }
})->purpose('Queue AI article generation for sites below their buffer');

Artisan::command('content:publish', function (PublishScheduler $scheduler) use ($liveSites) {
    foreach ($liveSites() as $site) {
        if ($article = $scheduler->publishNext($site)) {
            $this->line("published {$site->domain->name}/{$article->path()}");
        }
    }
})->purpose('Publish queued articles within each site\'s weekly cap');

Artisan::command('content:health', function (ContentHealth $health) use ($liveSites) {
    foreach ($liveSites() as $site) {
        $r = $health->handleColdContent($site);
        if ($reason = $health->circuitBreaker($site)) {
            $this->warn("{$site->domain->name}: circuit breaker — {$reason}");
        }
        if ($r['noindexed'] || $r['deleted']) {
            $this->line("{$site->domain->name}: noindexed {$r['noindexed']}, deleted {$r['deleted']}");
        }
    }
})->purpose('Cold content (noindex / 410) and traffic-drop circuit breaker');

Artisan::command('stats:search-console {--keywords} {--index}', function (SearchConsoleSync $sync) use ($liveSites) {
    if (! GoogleAuth::configured()) {
        return $this->warn('Search Console service account not configured.');
    }
    foreach ($liveSites() as $site) {
        if ($site->domain->gsc_status !== 'verified') {
            continue;
        }
        rescue(function () use ($sync, $site) {
            $sync->syncDaily($site);
            if ($this->option('keywords')) {
                $this->line("{$site->domain->name}: +".$sync->syncKeywords($site).' keywords');
            }
            if ($this->option('index')) {
                $sync->sampleIndexStatus($site);
            }
        });
    }
})->purpose('Pull Search Console performance, keyword backflow and index status');

Artisan::command('stats:analytics', function (Analytics $ga) use ($liveSites) {
    if (! GoogleAuth::configured()) {
        return $this->warn('Google service account not configured.');
    }
    foreach ($liveSites()->whereNotNull('ga4_property_id') as $site) {
        rescue(function () use ($ga, $site) {
            foreach ($ga->daily($site->ga4_property_id, now()->subDays(7)->toDateString(), 'yesterday') as $date => $row) {
                AnalyticsCache::updateOrCreate(['site_id' => $site->id, 'date' => $date], $row);
            }
        });
    }
})->purpose('Pull GA4 traffic into analytics_cache');

Artisan::command('stats:crawlers {file?}', function (CrawlerLogParser $parser, ?string $file = null) {
    $file ??= env('NGINX_SITES_LOG', '/var/log/nginx/sites.access.log');
    $key = 'crawler_log_offset:'.md5($file);
    $offset = (int) cache()->get($key, 0);
    cache()->forever($key, $parser->parse($file, $offset));
})->purpose('Count verified search engine crawler hits from the Nginx log');

Artisan::command('alerts:check', function () {
    foreach (Domain::whereNotNull('certificate_expires_at')->where('certificate_expires_at', '<', now()->addDays(21))->get() as $d) {
        Alert::raise('certificate', "域名 {$d->name} 的源站证书将于 {$d->certificate_expires_at->toDateString()} 过期", $d->site?->id, 'danger');
    }
    foreach (\App\Models\AiProvider::where('is_active', true)->get() as $p) {
        if ($p->monthly_budget && $p->monthSpend() >= 0.9 * $p->monthly_budget) {
            Alert::raise('ai_budget', "AI 服务商 {$p->name} 本月已用 ".round($p->monthSpend(), 2)." / {$p->monthly_budget}", null, 'warning');
        }
    }
})->purpose('Certificate and AI budget alerts');

Artisan::command('sites:rebuild {domain?}', function (\App\Services\Render\StaticSiteBuilder $builder, ?string $domain = null) {
    $sites = Site::with(['domain', 'brandProfile'])->whereIn('status', [SiteStatus::Live->value, SiteStatus::Preview->value, SiteStatus::Paused->value])
        ->when($domain, fn ($q) => $q->whereHas('domain', fn ($d) => $d->where('name', Domain::normalize($domain))))->get();
    foreach ($sites as $site) {
        $builder->buildAll($site);
        $this->line("rebuilt {$site->domain->name}");
    }
})->purpose('Re-render static pages (all sites, or one domain)');

Schedule::command('content:generate')->hourlyAt(5)->withoutOverlapping();
Schedule::command('content:publish')->cron('20 9-21/3 * * *')->withoutOverlapping(); // spread over the day
Schedule::command('stats:search-console --index')->dailyAt('05:30')->withoutOverlapping();
Schedule::command('stats:search-console --keywords')->weeklyOn(1, '06:30')->withoutOverlapping();
Schedule::command('stats:analytics')->dailyAt('06:00');
Schedule::command('content:health')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('stats:crawlers')->everyTenMinutes()->withoutOverlapping();
Schedule::command('alerts:check')->dailyAt('08:00');
