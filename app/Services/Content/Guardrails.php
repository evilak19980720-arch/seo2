<?php

namespace App\Services\Content;

use App\Enums\ArticleStatus;
use App\Enums\PromptType;
use App\Models\Article;
use App\Models\Site;
use App\Services\Ai\AiManager;
use App\Support\Settings;
use Throwable;

/**
 * Deterministic publishing guardrails. With no human review these are the
 * main defence against scaled-content abuse; thresholds live in settings.
 */
class Guardrails
{
    public const LEAK_PATTERNS = [
        '/\b(as an ai|as a language model|i cannot|i\'m sorry|como (uma )?(ia|intelig[eê]ncia artificial)|como modelo de linguagem|lorem ipsum)\b/iu',
        '/\[(insert|inserir|placeholder|todo|nome do|seu )[^\]]*\]/iu',
        '/\{\{[^}]*\}\}|<\s*placeholder|\bTODO\b|XXX/u',
    ];

    public function __construct(protected AiManager $ai) {}

    /**
     * @return array{passed: bool, reasons: array<int, string>, similarity: ?float, fingerprint: array}
     */
    public function check(Article $article, Site $site): array
    {
        $reasons = [];
        $text = $article->plainText();

        // Language & format
        if (! LanguageDetector::matches($text, $site->language())) {
            $reasons[] = '语言与站点语言不一致（检测为 '.(LanguageDetector::detect($text) ?? '?').'）';
        }
        if (substr_count(strtolower((string) $article->body), '<h2') < 2) {
            $reasons[] = '缺少 H2 层级结构';
        }
        foreach (self::LEAK_PATTERNS as $pattern) {
            if (preg_match($pattern, $article->title.' '.$text, $m)) {
                $reasons[] = '包含占位符或 AI 泄露语句：'.mb_substr($m[0], 0, 40);
                break;
            }
        }
        [$min, $max] = Settings::wordRange($article->type->value);
        $words = $article->wordCount();
        if ($words < $min * 0.8 || $words > $max * 1.3) {
            $reasons[] = "字数 {$words} 不在 {$min}–{$max} 区间";
        }

        // Keyword uniqueness inside the site
        if ($article->keyword_id && Article::where('site_id', $site->id)->where('keyword_id', $article->keyword_id)
            ->where('id', '!=', $article->id ?? 0)->whereNotIn('status', [ArticleStatus::Blocked->value, ArticleStatus::Deleted->value])->exists()) {
            $reasons[] = '关键词已被本站其他文章覆盖';
        }
        if ($article->keyword && $article->keyword->target_path) {
            $reasons[] = '关键词已指向页面 /'.$article->keyword->target_path;
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

        // Fact check against the brand profile (only if everything else passed: saves tokens)
        if ($reasons === []) {
            $reasons = array_merge($reasons, $this->factCheck($article, $site));
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

    /** @return array<int, string> */
    protected function factCheck(Article $article, Site $site): array
    {
        try {
            $res = $this->ai->prompt(PromptType::FactCheck, [
                'brand' => $site->brandProfile?->toPromptContext() ?? '',
                'article' => mb_substr($article->plainText(), 0, 12000),
            ], $site)->json();
        } catch (Throwable $e) {
            return ['事实核对未能执行：'.mb_substr($e->getMessage(), 0, 120)];
        }

        if (($res['supported'] ?? false) === true) {
            return [];
        }
        $claims = collect($res['unsupported_claims'] ?? [])->map(fn ($c) => is_string($c) ? $c : json_encode($c, JSON_UNESCAPED_UNICODE))->take(5);

        return ['事实核对未通过：'.($claims->implode('；') ?: '存在品牌资料中找不到依据的内容')];
    }
}
