<?php

namespace App\Services\Render;

use App\Enums\ArticleStatus;
use App\Models\Site;

class SitemapBuilder
{
    public static function robots(Site $site): string
    {
        return "User-agent: *\nAllow: /\nDisallow: /_t/\n\nSitemap: {$site->baseUrl()}/sitemap.xml\n";
    }

    /** Only indexable, 200, canonical URLs with real lastmod. */
    public static function sitemap(Site $site, SiteRenderer $renderer): string
    {
        $urls = [];
        $latestArticle = $renderer->listedArticles()->max('updated_at');

        foreach ($renderer->ctx->pages as $page) {
            if ($page->noindex) {
                continue;
            }
            $hasList = collect($renderer->ctx->blocks($page->type))->contains('block', 'article_list');
            $lastmod = $hasList && $latestArticle && $latestArticle > $page->updated_at ? $latestArticle : $page->updated_at;
            $urls[] = [$site->url($page->slug), $lastmod];
        }
        if ($renderer->listedArticles()->isNotEmpty()) {
            $urls[] = [$site->url('blog'), $latestArticle];
        }
        foreach ($renderer->articles() as $article) {
            if ($article->status === ArticleStatus::Published) {
                $urls[] = [$site->url($article->path()), $article->updated_at ?? $article->published_at];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as [$loc, $lastmod]) {
            $xml .= '<url><loc>'.htmlspecialchars($loc, ENT_XML1).'</loc>'.($lastmod ? '<lastmod>'.\Illuminate\Support\Carbon::parse($lastmod)->toAtomString().'</lastmod>' : '')."</url>\n";
        }

        return $xml.'</urlset>'."\n";
    }

    public static function feed(Site $site, SiteRenderer $renderer): string
    {
        $items = '';
        foreach ($renderer->listedArticles()->take(20) as $article) {
            $items .= '<item><title>'.htmlspecialchars($article->title, ENT_XML1).'</title>'
                .'<link>'.htmlspecialchars($site->url($article->path()), ENT_XML1).'</link>'
                .'<guid isPermaLink="true">'.htmlspecialchars($site->url($article->path()), ENT_XML1).'</guid>'
                .'<pubDate>'.$article->published_at?->toRssString().'</pubDate>'
                .'<description>'.htmlspecialchars((string) $article->meta_description, ENT_XML1).'</description></item>'."\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0"><channel><title>'.htmlspecialchars($site->name, ENT_XML1).'</title>'
            .'<link>'.$site->url().'</link><description>'.htmlspecialchars((string) $site->description, ENT_XML1).'</description>'
            .'<language>'.strtolower($site->locale).'</language>'."\n".$items.'</channel></rss>'."\n";
    }
}
