<?php

namespace App\Services\Content;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Enums\KeywordStatus;
use App\Enums\PromptType;
use App\Enums\SiteStatus;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\Site;
use App\Services\Ai\AiManager;
use App\Services\Render\HtmlSanitizer;
use App\Services\Render\SeoHead;
use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * keyword → generate (with brand profile) → polish → AI review → guardrails →
 * queued (or blocked with reason). Publishing is PublishScheduler's job.
 */
class ArticlePipeline
{
    public function __construct(protected AiManager $ai, protected Guardrails $guardrails) {}

    /** Why a site may not get automatic articles right now, or null when it may. */
    public function ineligibleReason(Site $site): ?string
    {
        return match (true) {
            $site->status !== SiteStatus::Live => '站点未上线',
            $site->auto_publish_paused => '自动发布已暂停：'.$site->pause_reason,
            ($site->brandProfile?->completeness ?? 0) < (int) Settings::get('min_completeness', 60) => '品牌资料完整度不足',
            default => null,
        };
    }

    public function nextKeyword(Site $site): ?Keyword
    {
        return $site->keywords()
            ->where('status', KeywordStatus::Pending->value)
            ->whereNull('target_path')
            ->whereNotIn('id', Article::where('site_id', $site->id)->whereNotNull('keyword_id')->select('keyword_id'))
            // Real search demand from Search Console first, then AI seeds.
            ->orderByRaw("case when source = 'gsc' then 0 when source = 'manual' then 1 else 2 end")
            ->orderByDesc('impressions')
            ->orderBy('id')
            ->first();
    }

    public function generateNext(Site $site): ?Article
    {
        if ($this->ineligibleReason($site)) {
            return null;
        }
        $keyword = $this->nextKeyword($site);

        return $keyword ? $this->generate($site, $keyword) : null;
    }

    public function typeFor(Keyword $keyword): ArticleType
    {
        $k = $keyword->keyword;

        return match (true) {
            (bool) preg_match('/\b(guia|guide|tutorial|dicas|tips|como jogar|how to|truques|estratégia|strategy|guía|consejos)\b/u', $k) => ArticleType::Guide,
            (bool) preg_match('/^(o que|como|por que|porque|quando|onde|qual|quais|é |what|how|why|when|where|which|is |can |qué|cómo|cuál|dónde)\b/u', $k) && mb_strlen($k) < 45 => ArticleType::Faq,
            (bool) preg_match('/\b(atualiza|update|novidade|news|versão|version|patch|evento|event|actualiza)/u', $k) => ArticleType::Update,
            in_array($keyword->intent, ['commercial', 'transactional'], true) => ArticleType::UseCase,
            default => ArticleType::Guide,
        };
    }

    public function generate(Site $site, Keyword $keyword, ?Article $existing = null): Article
    {
        $type = $existing?->type ?? $this->typeFor($keyword);
        [$min, $max] = Settings::wordRange($type->value);
        $brand = $site->brandProfile?->toPromptContext() ?? "Product: {$site->product_name}";

        $draft = $this->ai->prompt(PromptType::ArticleGenerate, [
            'brand' => $brand,
            'keyword' => $keyword->keyword,
            'intent' => $keyword->intent,
            'article_type' => $type->getLabel().' ('.$type->value.')',
            'min_words' => $min,
            'max_words' => $max,
            'existing_titles' => $site->articles()->latest('id')->limit(40)->pluck('title')->implode("\n"),
        ], $site);
        $data = $draft->json();
        $promptId = $draft->promptId;

        $html = (string) ($data['html'] ?? '');
        try {
            $polished = $this->ai->prompt(PromptType::ArticlePolish, ['brand' => $brand, 'html' => $html, 'keyword' => $keyword->keyword], $site)->json();
            $html = (string) ($polished['html'] ?? $html);
        } catch (\Throwable) {
            // Polishing is best-effort; guardrails still run on the draft.
        }

        $title = Str::limit(trim(strip_tags((string) ($data['title'] ?? $keyword->keyword))), 110, '');
        $article = $existing ?? new Article(['site_id' => $site->id, 'keyword_id' => $keyword->id]);
        $article->fill([
            'prompt_id' => $promptId,
            'title' => $title,
            'slug' => $existing?->slug ?? $this->uniqueSlug($site, (string) ($data['slug'] ?? $title)),
            'type' => $type,
            'meta_title' => SeoHead::limit((string) ($data['meta_title'] ?? $title), 60),
            'meta_description' => SeoHead::limit((string) ($data['meta_description'] ?? ''), 155),
            'featured_image' => $existing?->featured_image ?? $this->pickImage($site),
            'featured_image_alt' => Str::limit((string) ($data['featured_image_alt'] ?? $title), 120, ''),
            'author' => __('theme.editorial', ['brand' => $site->name], $site->language()),
            'body' => HtmlSanitizer::clean($html),
        ]);
        $article->setRelation('keyword', $keyword);

        // AI review is the first filter; the deterministic guardrails are the real gate.
        $reasons = [];
        try {
            $review = $this->ai->prompt(PromptType::ArticleReview, ['brand' => $brand, 'keyword' => $keyword->keyword, 'html' => $article->body], $site)->json();
            if (($review['pass'] ?? true) === false) {
                $reasons[] = 'AI 审核未通过：'.collect($review['issues'] ?? [])->take(3)->implode('；');
            }
        } catch (\Throwable $e) {
            $reasons[] = 'AI 审核失败：'.mb_substr($e->getMessage(), 0, 100);
        }

        $check = $this->guardrails->check($article, $site);
        $reasons = array_merge($reasons, $check['reasons']);

        $article->fill([
            'status' => $reasons === [] ? ArticleStatus::Queued : ArticleStatus::Blocked,
            'block_reason' => $reasons === [] ? null : implode("\n", $reasons),
            'similarity_score' => $check['similarity'],
            'fingerprint' => $check['fingerprint'],
        ]);
        $article->save();

        if ($reasons === []) {
            $keyword->update(['status' => KeywordStatus::Written]);
        }

        return $article;
    }

    protected function uniqueSlug(Site $site, string $source): string
    {
        $base = Str::limit(Str::slug($source), 70, '') ?: 'post';
        $slug = $base;
        for ($i = 2; Article::where('site_id', $site->id)->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /** Rotate brand images as featured images (never invent imagery). */
    protected function pickImage(Site $site): ?string
    {
        $images = collect($site->brandProfile?->media ?? [])->where('type', 'image')->pluck('path')->filter()->values();
        if ($images->isEmpty()) {
            return null;
        }

        return $images[$site->articles()->count() % $images->count()];
    }
}
