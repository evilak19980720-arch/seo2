@if (count($articles ?? []))
<section class="section article-list article-list--{{ $variant }}">
<div class="container">
<div class="section__head">@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? ($page?->title ?? $ctx->t('blog_title')) }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->t('latest') }}</h2>@endif</div>
<div class="grid {{ in_array($variant, ['cards', 'featured'], true) ? 'grid--3' : '' }}">
@foreach ($articles as $article)
@include('theme.partials.post-card', ['article' => $article])
@endforeach
</div>
@if (! empty($pagination))
<nav class="pagination" aria-label="pagination">
@foreach ($pagination as $p)
@if ($p['current'])<span aria-current="page">{{ $p['n'] }}</span>@else<a href="{{ $p['href'] }}">{{ $p['n'] }}</a>@endif
@endforeach
</nav>
@endif
</div>
</section>
@elseif ($isFirst ?? false)
<section class="section"><div class="container"><h1>{{ $data['heading'] ?? ($page?->title ?? $ctx->t('blog_title')) }}</h1></div></section>
@endif
