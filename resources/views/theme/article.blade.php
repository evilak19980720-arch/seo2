@php($featured = $article->featured_image ? $ctx->image($article->featured_image, 1400) : null)
<article class="section">
<div class="container">
<header class="prose">
<h1>{{ $article->title }}</h1>
<p class="post-meta">{{ $ctx->t('by') }} {{ $article->author ?: $ctx->t('editorial', ['brand' => $ctx->site->name]) }} · {{ $ctx->t('published') }} <time datetime="{{ $article->published_at?->toAtomString() }}">{{ $article->published_at?->format('d/m/Y') }}</time>@if ($article->updated_at && $article->published_at && $article->updated_at->gt($article->published_at->copy()->addDay())) · {{ $ctx->t('updated') }} <time datetime="{{ $article->updated_at->toAtomString() }}">{{ $article->updated_at->format('d/m/Y') }}</time>@endif</p>
</header>
@if ($featured)<div class="prose">@include('theme.partials.picture', ['img' => $featured, 'alt' => $article->featured_image_alt ?: $article->title, 'eager' => true])</div>@endif
<div class="prose">{!! $bodyHtml !!}</div>
@if (count($related))
<aside class="related">
<h2>{{ $ctx->t('related') }}</h2>
<div class="grid grid--3">
@foreach ($related as $rel)
@include('theme.partials.post-card', ['article' => $rel])
@endforeach
</div>
</aside>
@endif
</div>
</article>
