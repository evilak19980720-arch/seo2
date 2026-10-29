<?php

namespace App\Services\Content;

use App\Enums\ArticleStatus;
use App\Enums\ArticleTaskStatus;
use App\Enums\ArticleType;
use App\Enums\KeywordStatus;
use App\Enums\PromptType;
use App\Enums\SiteStatus;
use App\Models\Article;
use App\Models\ArticleTask;
use App\Models\Keyword;
use App\Models\Site;
use App\Services\Ai\AiManager;
use App\Services\Render\HtmlSanitizer;
use App\Services\Render\SeoHead;
use App\Services\Sites\PictureRole;
use App\Services\Sites\ReferenceLibrary;
use App\Support\Media;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * keyword → generate (with brand profile) → polish → AI review → guardrails →
 * queued (or blocked with reason). Publishing is PublishScheduler's job.
 */
class ArticlePipeline
{
    /** Narrowest reference picture put in an article. */
    protected const ARTICLE_PICTURE_WIDTH = 400;

    /** Pictures put in an article that came without any. */
    protected const MAX_INSERTED_PICTURES = 3;

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

    public function generateNext(Site $site, ?ArticleTask $task = null): ?Article
    {
        if ($reason = $this->ineligibleReason($site)) {
            $task?->step(ArticleTaskStatus::Skipped, ['note' => $reason]);

            return null;
        }
        $keyword = $this->nextKeyword($site);
        if (! $keyword) {
            $task?->step(ArticleTaskStatus::Skipped, ['note' => '没有待写的关键词']);

            return null;
        }

        return $this->generate($site, $keyword, null, $task);
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

    public function generate(Site $site, Keyword $keyword, ?Article $existing = null, ?ArticleTask $task = null): Article
    {
        $task?->step(ArticleTaskStatus::Generating, ['keyword' => $keyword->keyword, 'article_id' => $existing?->id]);
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
        $task?->step(ArticleTaskStatus::Polishing);
        try {
            $polished = $this->ai->prompt(PromptType::ArticlePolish, ['brand' => $brand, 'html' => $html, 'keyword' => $keyword->keyword], $site)->json();
            $html = (string) ($polished['html'] ?? $html);
        } catch (\Throwable) {
            // Polishing is best-effort; guardrails still run on the draft.
        }

        $featured = $existing?->featured_image ?? $this->pickImage($site, $keyword->keyword);
        if (str_contains($html, 'data-illustration')) {
            $task?->step(ArticleTaskStatus::Illustrating);
            $html = $this->illustrate($site, $keyword, $html, $featured);
        }
        $html = $this->withPictures($site, $keyword, $html, $featured);

        $title = Str::limit(trim(strip_tags((string) ($data['title'] ?? $keyword->keyword))), 110, '');
        $article = $existing ?? new Article(['site_id' => $site->id, 'keyword_id' => $keyword->id]);
        $article->fill([
            'prompt_id' => $promptId,
            'title' => $title,
            'slug' => $existing?->slug ?? $this->uniqueSlug($site, (string) ($data['slug'] ?? $title)),
            'type' => $type,
            'meta_title' => SeoHead::limit((string) ($data['meta_title'] ?? $title), 60),
            'meta_description' => SeoHead::limit((string) ($data['meta_description'] ?? ''), 155),
            'featured_image' => $featured,
            'featured_image_alt' => Str::limit((string) ($data['featured_image_alt'] ?? $title), 120, ''),
            'author' => __('theme.editorial', ['brand' => $site->name], $site->language()),
            'body' => SeoRules::stripTracesHtml(HtmlSanitizer::clean($html)),
        ]);
        $article->setRelation('keyword', $keyword);

        // Only duplication can stop an article (user decision); everything else passes.
        $reasons = [];
        $task?->step(ArticleTaskStatus::Checking);
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
        $task?->step($reasons === [] ? ArticleTaskStatus::Done : ArticleTaskStatus::Blocked, [
            'article_id' => $article->id,
            'note' => $reasons === [] ? '《'.$article->title.'》已通过，等待发布' : implode("\n", $reasons),
        ]);

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

    /**
     * Replace the model's <figure data-illustration="…"> placeholders with
     * AI-generated pictures stored on the media disk (OSS). Without an image
     * model, or when generation fails, a brand picture or a random picture
     * downloaded from the reference site takes the place (never one twice in
     * an article), else the placeholder is dropped.
     */
    public function illustrate(Site $site, Keyword $keyword, string $html, ?string $featured = null): string
    {
        $spare = $this->sparePictures($site, $featured, $keyword->keyword);
        $made = 0;

        return preg_replace_callback('/<figure\b[^>]*data-illustration\s*=\s*(["\'])(.*?)\1[^>]*>.*?<\/figure>/is', function ($m) use ($site, $keyword, &$spare, &$made) {
            $what = trim(html_entity_decode(strip_tags($m[2])));
            if ($what === '' || $made >= 3) {
                return '';
            }
            $path = null;
            $binary = $this->ai->image("Editorial illustration for a website article about \"{$keyword->keyword}\" ({$site->product_name}, {$site->typeConfig()['label']}). Picture: {$what}. Clean modern digital illustration, vivid colours, no text, letters, logos, watermarks or real people.", $site);
            if ($binary !== null) {
                $info = @getimagesizefromstring($binary);
                $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? 'png';
                $candidate = "articles/{$site->id}/".md5($binary).".{$ext}";
                $path = Media::put($candidate, $binary) ? $candidate : null;
            }
            $path ??= $spare->shift();
            if ($path === null) {
                return '';
            }
            $made++;

            return '<figure><img src="media:'.e($path).'" alt="'.e(Str::limit($what, 120, '')).'"></figure>';
        }, $html) ?? $html;
    }

    /** Brand pictures, then the reference site's pictures large enough for an article, each in random order. */
    protected function sparePictures(Site $site, ?string $featured = null, string $topic = ''): Collection
    {
        // Never the article's own featured picture, which already shows above the text.
        return $this->articlePictures($site, $topic)->reject(fn ($p) => $p === $featured)->values();
    }

    /**
     * Pictures an article may use: the ones whose words fit its topic first,
     * then ordinary brand and reference pictures in random order. Logos, text
     * banners and pictures of a past year only when their words fit.
     *
     * @return Collection<int, string>
     */
    protected function articlePictures(Site $site, string $topic, bool $onlyFitting = false): Collection
    {
        $ignore = collect([$site->product_name, $site->name])->flatMap(fn ($n) => explode(' ', SeoRules::normalize((string) $n)))->filter()->all();
        $all = collect($site->brandProfile?->media ?? [])->where('type', 'image')->filter(fn ($m) => filled($m['path'] ?? null))
            ->map(fn ($m) => ['path' => $m['path'], 'words' => trim(($m['alt'] ?? '').' '.($m['caption'] ?? '')), 'role' => PictureRole::of($m['path'], $m['alt'] ?? null), 'expired' => false])
            ->merge(ReferenceLibrary::pictures($site, self::ARTICLE_PICTURE_WIDTH)->map(fn ($p) => ['path' => $p['path'], 'words' => $p['words'] ?? (string) $p['alt'],
                'role' => PictureRole::of($p['path'], ($p['source'] ?? '').' '.$p['alt'], $p['width'] ?: null, $p['height'] ?: null), 'expired' => PictureRole::expired(($p['source'] ?? '').' '.($p['words'] ?? ''))]))
            ->unique('path')->values();
        $scored = $all->map(fn ($p) => $p + ['score' => $topic !== '' ? PictureRole::match($topic, $p['words'], $ignore) : 0.0]);
        $fitting = $scored->filter(fn ($p) => $p['score'] >= 0.5 && ($p['role'] !== PictureRole::LOGO || $p['score'] >= 1.0))->sortByDesc('score');
        if ($onlyFitting) {
            return $fitting->pluck('path')->values();
        }
        $plain = $scored->filter(fn ($p) => $p['role'] === PictureRole::PHOTO && ! $p['expired'])->sortBy(fn ($p) => md5($site->id.'|'.$p['path']));

        return $fitting->merge($plain)->pluck('path')->unique()->values();
    }

    /**
     * An article left without any picture (no placeholders, no image model)
     * gets random reference / brand pictures after some of its sections, one
     * every few sections, so long text is broken up like on a real site.
     */
    public function withPictures(Site $site, Keyword $keyword, string $html, ?string $featured = null): string
    {
        if (preg_match('/<img\b/i', $html)) {
            return $html;
        }
        $spare = $this->sparePictures($site, $featured, $keyword->keyword);
        $sections = preg_match_all('/<h2\b/i', $html);
        if ($spare->isEmpty() || $sections < 2) {
            return $html;
        }
        $want = min(self::MAX_INSERTED_PICTURES, $spare->count(), intdiv($sections, 2));
        // Before the 2nd, 4th… section heading: never above the first one, where the intro is.
        $at = 0;
        $placed = 0;

        return preg_replace_callback('/<h2\b/i', function ($m) use (&$at, &$placed, $want, $spare, $keyword) {
            $at++;
            if ($at % 2 !== 0 || $placed >= $want) {
                return $m[0];
            }
            $path = $spare[$placed++];

            return '<figure><img src="media:'.e($path).'" alt="'.e(Str::limit($keyword->keyword, 120, '')).'"></figure>'.$m[0];
        }, $html) ?? $html;
    }

    protected function pickImage(Site $site, string $topic = ''): ?string
    {
        // The picture that fits the topic, else the next ordinary one in turn.
        if ($fit = $this->articlePictures($site, $topic, onlyFitting: true)->first()) {
            return $fit;
        }
        $images = $this->articlePictures($site, '');

        return $images->isEmpty() ? null : $images[$site->articles()->count() % $images->count()];
    }
}
