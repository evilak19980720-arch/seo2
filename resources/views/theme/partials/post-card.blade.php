@php($thumb = ($article->featured_image ? $ctx->image($article->featured_image, 800) : null) ?? $ctx->fallbackImage('post:'.$article->id)['image'] ?? null)
<a class="post-card" href="{{ $ctx->href($article->path()) }}">
@if ($thumb)@include('theme.partials.picture', ['img' => $thumb, 'alt' => $article->featured_image_alt ?: $article->title])@else<span class="post-card__ph" aria-hidden="true">{{ mb_strtoupper(mb_substr($article->title, 0, 1)) }}</span>@endif
<span class="post-card__body">
<h3>{{ $article->title }}</h3>
@if ($article->meta_description)<p class="muted">{{ \App\Services\Render\SeoHead::limit($article->meta_description, 140) }}</p>@endif
<time datetime="{{ $article->published_at?->toDateString() }}">{{ $article->published_at?->format('d/m/Y') }}</time>
</span>
</a>
