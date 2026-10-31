<?php

namespace App\Services\Content;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\Site;
use App\Services\Ai\AiManager;
use App\Support\Settings;

/**
 * Publishing gate: only duplication is checked (user decision) — the same
 * keyword twice on a site, or text too similar to an article on this site or
 * another site in the same language. Thresholds live in settings.
 */
class Guardrails
{
    public function __construct(protected AiManager $ai) {}

    /**
     * @return array{passed: bool, reasons: array<int, string>, similarity: ?float, fingerprint: array}
     */
    public function check(Article $article, Site $site): array
    {
        $reasons = [];
        $text = $article->plainText();

        // Keyword uniqueness inside the site
        if ($article->keyword_id && Article::where('site_id', $site->id)->where('keyword_id', $article->keyword_id)
            ->where('id', '!=', $article->id ?? 0)->whereNotIn('status', [ArticleStatus::Blocked->value, ArticleStatus::Deleted->value])->exists()) {
            $reasons[] = '关键词已被本站其他文章覆盖';
        }

        // Duplicate detection
        $fingerprint = Similarity::fingerprint($text, $this->ai->embed($text, $site));
        [$intra, $cross] = $this->maxSimilarity($article, $site, $fingerprint);
        if ($intra !== null && $intra >= (float) Settings::get('intra_site_similarity', 0.85)) {
            $reasons[] = sprintf('与本站已有文章相似度 %.2f 过高', $intra);
        }
        if ($cross !== null && $cross >= (float) Settings::get('cross_site_similarity', 0.80)) {
            $reasons[] = sprintf('与其他站文章相似度 %.2f 过高', $cross);
        }

        return [
            'passed' => $reasons === [],
            'reasons' => $reasons,
            'similarity' => max($intra ?? 0, $cross ?? 0) ?: null,
            'fingerprint' => $fingerprint,
        ];
    }

    /** @return array{0: ?float, 1: ?float} */
    protected function maxSimilarity(Article $article, Site $site, array $fingerprint): array
    {
        $intra = null;
        $cross = null;
        Article::query()
            ->select(['id', 'site_id', 'fingerprint'])
            ->whereNotNull('fingerprint')
            ->where('id', '!=', $article->id ?? 0)
            ->whereIn('status', [ArticleStatus::Published->value, ArticleStatus::Queued->value, ArticleStatus::Noindex->value])
            ->whereIn('site_id', Site::where('locale', $site->locale)->select('id'))
            ->latest('id')
            ->limit(3000)
            ->each(function (Article $other) use ($site, $fingerprint, &$intra, &$cross) {
                $score = Similarity::compare($fingerprint, $other->fingerprint);
                if ($score === null) {
                    return;
                }
                if ($other->site_id === $site->id) {
                    $intra = max($intra ?? 0, $score);
                } else {
                    $cross = max($cross ?? 0, $score);
                }
            });

        return [$intra, $cross];
    }
}
