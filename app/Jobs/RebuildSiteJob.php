<?php

namespace App\Jobs;

use App\Enums\ArticleStatus;
use App\Models\Alert;
use App\Models\Site;
use App\Services\Integrations\Cloudflare;
use App\Services\Integrations\Lighthouse;
use App\Services\Render\SchemaValidator;
use App\Services\Render\StaticSiteBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** "更新": re-render every static page of a site with its current template. */
class RebuildSiteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public Site $site)
    {
        $this->onQueue('generation');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('site-'.$this->site->id))->expireAfter(900)];
    }

    public function handle(StaticSiteBuilder $builder, Lighthouse $lighthouse): void
    {
        $site = $this->site->load(['domain', 'brandProfile']);
        $paths = $builder->buildAll($site);

        $cf = new Cloudflare;
        if ($cf->configured() && $site->domain->cf_zone_id) {
            rescue(fn () => $cf->purgeUrls($site->domain->cf_zone_id, array_map(fn ($p) => $site->url($p), $paths)));
        }

        rescue(fn () => $this->check($site, $paths, $lighthouse));
    }

    /** After every rebuild: structured data, plus mobile Lighthouse of the home page and newest article. */
    protected function check(Site $site, array $paths, Lighthouse $lighthouse): void
    {
        $dir = StaticSiteBuilder::dirFor($site);
        $article = $site->articles()->where('status', ArticleStatus::Published->value)->latest('published_at')->first();
        $sample = array_values(array_filter(['', $article?->path()], fn ($p) => $p !== null));

        $schema = SchemaValidator::checkSite($dir, $sample);
        if ($schema !== []) {
            Alert::raise('schema', "站点 {$site->name} 结构化数据有问题：".collect($schema)->map(fn ($p, $path) => $path.' '.implode('、', $p))->implode('；'), $site->id);
        }

        if (! Lighthouse::enabled()) {
            return;
        }
        $min = config('platform.lighthouse');
        $results = $lighthouse->auditSite($dir, array_combine(array_map(fn ($p) => $p === '' ? 'home' : 'article', $sample), $sample));
        $low = collect($results)->filter(fn ($r) => $r !== null && ($r['performance'] < $min['min_performance'] || $r['seo'] < $min['min_seo']));
        if ($low->isNotEmpty()) {
            Alert::raise('lighthouse', "站点 {$site->name} 重建后 Lighthouse 未达标：".$low->map(fn ($r, $k) => "{$k} 性能 {$r['performance']} / SEO {$r['seo']}")->implode('，'), $site->id);
        }
    }
}
