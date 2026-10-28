@php($logo = $ctx->logo())
@php($cta = $ctx->primaryCta())
<header class="nav nav--{{ $variant }}">
<div class="container nav__inner">
<a class="nav__brand" href="/">@if ($logo)<img src="{{ $logo['src'] }}" width="40" height="40" alt="{{ $ctx->site->name }}">@endif<span>{{ $ctx->site->name }}</span></a>
<nav aria-label="{{ $ctx->t('pages') }}"><ul class="nav__links">
@foreach ($ctx->navLinks() as $link)
<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>
@endforeach
</ul></nav>
@if ($cta && in_array($variant, ['bar', 'split'], true))<a class="btn nav__cta" href="{{ $cta['url'] }}" rel="noopener">{{ $cta['label'] }}</a>@endif
</div>
</header>
