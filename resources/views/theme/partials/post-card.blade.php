@php($thumb = $article->featured_image ? $ctx->image($article->featured_image, 800) : null)
<a class="post-card" href="{{ $ctx->href($article->path()) }}">
@include('theme.partials.picture', ['img' => $thumb, 'alt' => $article->featured_image_alt ?: $article->title])
<h3>{{ $article->title }}</h3>
@if ($article->meta_description)<p class="muted">{{ \Illuminate\Support\Str::limit($article->meta_description, 140) }}</p>@endif
<time datetime="{{ $article->published_at?->toDateString() }}">{{ $article->published_at?->format('d/m/Y') }}</time>
</a>
