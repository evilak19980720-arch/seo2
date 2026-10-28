<?php

namespace App\Services\Render;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\GoneUrl;
use App\Models\Site;
use App\Support\Settings;
use Illuminate\Support\Facades\File;

/**
 * Writes a site's static output: {output}/{host}/... read directly by Nginx.
 * Full builds go to a temp dir and are swapped in atomically; incremental
 * builds rewrite only the affected files (each via temp file + rename).
 */
class StaticSiteBuilder
{
    public static function root(): string
    {
        return rtrim(config('platform.output_path'), '/');
    }

    public static function dirFor(Site $site): string
    {
        return self::root().'/'.$site->domain->name;
    }

    /**
     * Render every page of the site.
     *
     * @param  callable(string $step, int $done, int $total): void|null  $progress
     * @return array<int, string> written URL paths
     */
    public function buildAll(Site $site, ?callable $progress = null, ?string $targetDir = null): array
    {
        self::publishSharedAssets();
        $final = $targetDir ?? self::dirFor($site);
        $tmp = $final.'.building';
        File::deleteDirectory($tmp);
        File::ensureDirectoryExists($tmp);
        // Keep converted media between builds to avoid re-encoding.
        if (is_dir("{$final}/media")) {
            File::copyDirectory("{$final}/media", "{$tmp}/media");
        }

        $renderer = new SiteRenderer($site, $tmp);
        $pages = $renderer->ctx->pages;
        $articles = $renderer->articles();
        $entries = $renderer->entryPages();
        $total = $pages->count() + $entries->count() + $renderer->blogPageCount() + $articles->count() + 1;
        $done = 0;
        $written = [];
        $tick = function (string $step) use (&$done, $total, $progress) {
            $done++;
            $progress && $progress($step, $done, $total);
        };

        foreach ($pages as $page) {
            $this->write($tmp, $page->slug, $renderer->renderPage($page));
            $written[] = $page->slug;
            $tick("page:{$page->type}");
        }
        foreach ($entries as $entry) {
            $path = $renderer->ctx->entryPath($entry);
            $this->write($tmp, $path, $renderer->renderEntry($entry));
            $written[] = $path;
            $tick("entry:{$entry->collection}");
        }
        for ($n = 1; $n <= $renderer->blogPageCount(); $n++) {
            $path = $n === 1 ? 'blog' : "blog/page/{$n}";
            $this->write($tmp, $path, $renderer->renderBlogIndex($n));
            $written[] = $path;
            $tick('blog');
        }
        foreach ($articles as $article) {
            $this->write($tmp, $article->path(), $renderer->renderArticle($article));
            $written[] = $article->path();
            $tick('article');
        }
        $this->writeFile("{$tmp}/404.html", $renderer->renderNotFound());
        $tick('404');

        $this->writeSupportFiles($site, $renderer, $tmp);

        // Atomic swap.
        $old = $final.'.old';
        File::deleteDirectory($old);
        if (is_dir($final)) {
            rename($final, $old);
        }
        rename($tmp, $final);
        File::deleteDirectory($old);

        if ($targetDir === null) {
            $site->newQuery()->whereKey($site->id)->update(['built_at' => now()]);
            GoneUrl::where('host', $site->domain->name)->whereNull('path')->delete();
        }

        return $written;
    }

    /**
     * Incremental rebuild after an article was published, updated, noindexed
     * or deleted. Returns the absolute URLs whose content changed.
     *
     * @return array<int, string>
     */
    public function rebuildForArticle(Article $article): array
    {
        $site = $article->site()->with('domain')->first();
        $dir = self::dirFor($site);
        if (! is_dir($dir)) {
            return array_map(fn ($p) => $site->url($p), $this->buildAll($site));
        }

        $renderer = new SiteRenderer($site, $dir);
        $paths = [];

        if ($article->status->isRendered()) {
            $this->write($dir, $article->path(), $renderer->renderArticle($article));
            GoneUrl::where('host', $site->domain->name)->where('path', $article->path())->delete();
        } else {
            File::deleteDirectory("{$dir}/{$article->path()}");
            if ($article->status === ArticleStatus::Deleted && $article->published_at) {
                GoneUrl::firstOrCreate(['host' => $site->domain->name, 'path' => $article->path()]);
            }
        }
        $paths[] = $article->path();

        // Listing pages and their pagination.
        $count = $renderer->blogPageCount();
        for ($n = 1; $n <= $count; $n++) {
            $path = $n === 1 ? 'blog' : "blog/page/{$n}";
            $this->write($dir, $path, $renderer->renderBlogIndex($n));
            $paths[] = $path;
        }
        // Remove pagination pages that no longer exist.
        for ($n = $count + 1; is_dir("{$dir}/blog/page/{$n}"); $n++) {
            File::deleteDirectory("{$dir}/blog/page/{$n}");
        }

        // Pages that list articles: article lists (home "latest", news) and the HTML sitemap.
        foreach ($renderer->ctx->pages as $page) {
            if (collect($renderer->ctx->blocks($page->type))->contains(fn ($b) => in_array($b['block'] ?? null, ['article_list', 'html_sitemap'], true))) {
                $this->write($dir, $page->slug, $renderer->renderPage($page));
                $paths[] = $page->slug;
            }
        }

        // Older articles that now link to (or list as related) this one.
        $phrase = $article->keyword?->keyword;
        foreach ($renderer->articles() as $other) {
            if ($other->id === $article->id) {
                continue;
            }
            $mentions = $phrase && mb_stripos($other->plainText(), $phrase) !== false;
            $relates = $renderer->related($other)->contains('id', $article->id);
            $linkedBefore = str_contains((string) @file_get_contents("{$dir}/{$other->path()}/index.html"), '/'.$article->path().'/');
            if ($mentions || $relates || $linkedBefore) {
                $this->write($dir, $other->path(), $renderer->renderArticle($other));
                $paths[] = $other->path();
            }
        }

        $this->writeSupportFiles($site, $renderer, $dir, iconsAndCss: false);
        $paths[] = 'sitemap.xml';
        $paths[] = 'feed.xml';
        if (is_file("{$dir}/search.json")) {
            $paths[] = 'search.json';
        }

        return array_values(array_unique(array_map(
            fn ($p) => str_contains($p, '.') ? $site->baseUrl().'/'.$p : $site->url($p),
            $paths,
        )));
    }

    /** Take a deleted site offline: files removed, host answers 410. */
    public function removeSite(Site $site): void
    {
        File::deleteDirectory(self::dirFor($site));
        GoneUrl::firstOrCreate(['host' => $site->domain->name, 'path' => null]);
    }

    public function writeSupportFiles(Site $site, SiteRenderer $renderer, string $dir, bool $iconsAndCss = true): void
    {
        if ($iconsAndCss) {
            File::ensureDirectoryExists("{$dir}/assets");
            $this->writeFile("{$dir}/assets/site.css", $renderer->siteCss());
            // Each site serves its own copy of the theme CSS and fonts (no shared /_theme/ path).
            foreach (glob("{$dir}/assets/s[0-9a-f]*.css") ?: [] as $old) {
                @unlink($old);
            }
            $this->writeFile("{$dir}/assets/".$renderer->baseCssName(), $renderer->baseCss());
            File::ensureDirectoryExists("{$dir}/assets/fonts");
            foreach (glob(public_path('theme/fonts/*.woff2')) ?: [] as $font) {
                copy($font, "{$dir}/assets/fonts/".basename($font));
            }
            IconGenerator::generate($site, $dir, $renderer->ctx->design['colors']);
        }
        $this->writeFile("{$dir}/robots.txt", SitemapBuilder::robots($site));
        $this->writeFile("{$dir}/sitemap.xml", SitemapBuilder::sitemap($site, $renderer));
        $this->writeFile("{$dir}/feed.xml", SitemapBuilder::feed($site, $renderer));
        if ($renderer->ctx->pages->contains(fn ($p) => collect($renderer->ctx->blocks($p->type))->contains('block', 'site_search'))) {
            $this->writeFile("{$dir}/search.json", json_encode($renderer->searchIndex(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        if ($key = Settings::get('indexnow_key')) {
            $this->writeFile("{$dir}/{$key}.txt", $key);
        }
    }

    /** Copy the shared base stylesheet and fonts into public/theme (served as /_theme/). */
    public static function publishSharedAssets(): void
    {
        File::ensureDirectoryExists(public_path('theme/fonts'));
        $src = resource_path('theme/base.css');
        $dst = public_path('theme/base.css');
        if (! is_file($dst) || md5_file($dst) !== md5_file($src)) {
            copy($src, $dst);
        }
    }

    protected function write(string $dir, string $path, string $html): void
    {
        $path = trim($path, '/');
        $file = $path === '' ? "{$dir}/index.html" : "{$dir}/{$path}/index.html";
        $this->writeFile($file, $html);
    }

    protected function writeFile(string $file, string $contents): void
    {
        File::ensureDirectoryExists(dirname($file));
        $tmp = $file.'.tmp'.getmypid();
        file_put_contents($tmp, $contents);
        rename($tmp, $file);
    }
}
