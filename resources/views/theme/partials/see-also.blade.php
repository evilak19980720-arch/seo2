@php($links = $ctx->relatedPages($page?->type ?? '', 3))
@if (count($links))
<nav class="section see-also" aria-label="{{ $ctx->t('see_also') }}">
<div class="container"><p class="see-also__title">{{ $ctx->t('see_also') }}</p><ul class="see-also__list">@foreach ($links as $link)<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>@endforeach</ul></div>
</nav>
@endif
