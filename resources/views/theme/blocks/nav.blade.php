@php($logo = $ctx->logo())
@php($cta = $ctx->primaryCta())
<header class="nav nav--{{ $variant }}">
<div class="container nav__inner">
<a class="nav__brand" href="/">@if ($logo)<img src="{{ $logo['src'] }}" width="42" height="42" alt="{{ $ctx->site->name }}">@else<span class="nav__mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($ctx->site->name, 0, 1)) }}</span>@endif<span>{{ $ctx->site->name }}</span></a>
<input class="nav__check" type="checkbox" id="nav-toggle" aria-label="{{ $ctx->t('pages') }}">
<label class="nav__toggle" for="nav-toggle" aria-hidden="true">☰</label>
<div class="nav__menu"><nav aria-label="{{ $ctx->t('pages') }}"><ul class="nav__links">
@foreach ($ctx->navLinks() as $link)
<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>
@endforeach
</ul></nav></div>
@if ($cta && in_array($variant, ['bar', 'split'], true))<a class="btn nav__cta" href="{{ $cta['url'] }}" rel="noopener">{{ $cta['label'] }}</a>@endif
</div>
</header>
