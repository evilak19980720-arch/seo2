@php($logo = $ctx->logo())
@php($nav = $ctx->navigation())
@php($links = $ctx->navLinks())
@php($buttons = $ctx->ctaLinks($nav['buttons']))
<header class="nav nav--{{ $variant }} @if (! $nav['sticky']) nav--static @endif">
<div class="container nav__inner">
<a class="nav__brand" href="/">@if ($logo)<img src="{{ $logo['src'] }}" width="42" height="42" alt="{{ $ctx->site->name }}">@else<span class="nav__mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($ctx->site->name, 0, 1)) }}</span>@endif<span>{{ $ctx->site->name }}</span></a>
<input class="nav__check" type="checkbox" id="nav-toggle" aria-label="{{ $ctx->t('pages') }}">
<label class="nav__toggle" for="nav-toggle" aria-hidden="true">☰</label>
@if ($variant !== 'portal')
<div class="nav__menu"><nav aria-label="{{ $ctx->t('pages') }}">@include('theme.partials.nav-links')</nav></div>
@endif
@if ($buttons !== [] && $variant !== 'minimal' && $variant !== 'centered')
<div class="nav__actions">@foreach ($buttons as $i => $button)<a class="btn nav__cta {{ $i > 0 ? 'btn--ghost' : '' }}" href="{{ $button['url'] }}" rel="noopener">{{ $button['label'] }}</a>@endforeach</div>
@endif
</div>
@if ($variant === 'portal')
<div class="nav__menu nav__bar"><nav class="container" aria-label="{{ $ctx->t('pages') }}">@include('theme.partials.nav-links')</nav></div>
@endif
</header>
