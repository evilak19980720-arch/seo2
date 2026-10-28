<?php

namespace App\Services\Render;

use App\Enums\ArticleStatus;
use App\Models\Site;
use App\Support\Settings;
use Illuminate\Support\Carbon;

class SitemapBuilder
{
    /** Crawlers that collect training data (blocked by default; search keeps working). */
    public const TRAINING_BOTS = ['GPTBot', 'Google-Extended', 'CCBot', 'ClaudeBot', 'anthropic-ai', 'Applebot-Extended', 'Bytespider', 'meta-externalagent', 'cohere-training-data-crawler', 'Diffbot', 'omgili', 'Timpibot'];

    /** AI search and assistant fetchers: they cite and link the site, so they are allowed. */
    public const AI_SEARCH_BOTS = ['OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Perplexity-User', 'Claude-SearchBot', 'Claude-User', 'DuckAssistBot', 'MistralAI-User'];

    public static function robots(Site $site): string
    {
        $private = '/'.SiteRenderer::trackingPrefix($site).'/';
        $out = "User-agent: *\nAllow: /\nDisallow: {$private}\n\n";
        if (Settings::get('robots_allow_ai_search', true)) {
            $out .= 'User-agent: '.implode("\nUser-agent: ", self::AI_SEARCH_BOTS)."\nAllow: /\nDisallow: {$private}\n\n";
        }
        if (Settings::get('robots_block_ai_training', true)) {
            $out .= 'User-agent: '.implode("\nUser-agent: ", self::TRAINING_BOTS)."\nDisallow: /\n\n";
        }

        return $out."Sitemap: {$site->baseUrl()}/sitemap.xml\n";
    }

    /** Only indexable, 200, canonical URLs with real lastmod, plus the images each page shows. */
    public static function sitemap(Site $site, SiteRenderer $renderer): string
    {
        $urls = [];
        $latestArticle = $renderer->listedArticles()->max('updated_at');
        $og = $renderer->ogImageUrl();

        foreach ($renderer->ctx->pages as $page) {
            if ($renderer->isNoindex($page)) {
                continue;
            }
            $hasList = collect($renderer->ctx->blocks($page->type))->contains('block', 'article_list');
            $lastmod = $hasList && $latestArticle && $latestArticle > $page->updated_at ? $latestArticle : $page->updated_at;
            $urls[] = [$site->url($page->slug), $lastmod, $page->type === 'home' && $og ? [$og] : []];
        }
        foreach ($renderer->entryPages() as $entry) {
            $image = $entry->image ? $renderer->ctx->image($entry->image, 1400) : null;
            $urls[] = [$site->url($renderer->ctx->entryPath($entry)), $entry->updated_at, $image ? [MediaPublisher::absolute($site, $image['src'])] : []];
        }
        if ($renderer->blogIsIndexable()) {
            $urls[] = [$site->url('blog'), $latestArticle, []];
        }
        foreach ($renderer->articles() as $article) {
            if ($article->status === ArticleStatus::Published) {
                $image = $article->featured_image ? $renderer->ctx->image($article->featured_image, 1400) : null;
                $urls[] = [$site->url($article->path()), $article->updated_at ?? $article->published_at, $image ? [MediaPublisher::absolute($site, $image['src'])] : []];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";
        foreach ($urls as [$loc, $lastmod, $images]) {
            $xml .= '<url><loc>'.htmlspecialchars($loc, ENT_XML1).'</loc>'.($lastmod ? '<lastmod>'.Carbon::parse($lastmod)->toAtomString().'</lastmod>' : '');
            foreach ($images as $img) {
                $xml .= '<image:image><image:loc>'.htmlspecialchars($img, ENT_XML1).'</image:loc></image:image>';
            }
            $xml .= "</url>\n";
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
