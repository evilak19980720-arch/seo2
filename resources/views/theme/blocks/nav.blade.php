@php($logo = $ctx->logo())
@php($nav = $ctx->navigation())
@php($links = $ctx->navLinks())
@php($buttons = $ctx->ctaLinks($nav['buttons']))
@php($panel = $nav['mobile_menu'] !== 'dropdown')
<header class="nav nav--{{ $variant }} nav--m-{{ $nav['mobile_header'] }} nav--mm-{{ $nav['mobile_menu'] }} @if (! $nav['sticky']) nav--static @endif">
<div class="container nav__inner">
<a class="nav__brand" href="/">@if ($logo)<img src="{{ $logo['src'] }}" width="42" height="42" alt="{{ $ctx->site->name }}">@else<span class="nav__mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($ctx->site->name, 0, 1)) }}</span>@endif<span>{{ $ctx->site->name }}</span></a>
<input class="nav__check" type="checkbox" id="nav-toggle" autocomplete="off" aria-label="{{ $ctx->t('pages') }}">
<label class="nav__toggle" for="nav-toggle" aria-hidden="true"><span class="nav__open"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M4 7h16M4 12h16M4 17h11"/></svg></span><span class="nav__close"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M6 6l12 12M18 6L6 18"/></svg></span></label>
@if ($variant !== 'portal')
<div class="nav__menu">@if ($panel)<div class="nav__menu-head"><span>{{ $ctx->site->name }}</span><label class="nav__menu-close" for="nav-toggle" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path d="M6 6l12 12M18 6L6 18"/></svg></label></div>@endif<nav aria-label="{{ $ctx->t('pages') }}">@include('theme.partials.nav-links')</nav>@if ($panel && $buttons !== [])<a class="btn nav__menu-cta" href="{{ $buttons[0]['url'] }}" @if ($ctx->rel($buttons[0]['url'])) rel="{{ $ctx->rel($buttons[0]['url']) }}" @endif>{{ $buttons[0]['label'] }}</a>@endif</div>
@endif
@if ($buttons !== [] && $variant !== 'minimal' && ($variant !== 'centered' || $nav['mobile_header'] === 'cta'))
<div class="nav__actions @if ($variant === 'centered') nav__actions--mobile @endif">@foreach ($buttons as $i => $button)<a class="btn nav__cta {{ $i > 0 ? 'btn--ghost' : '' }}" href="{{ $button['url'] }}" @if ($ctx->rel($button['url'])) rel="{{ $ctx->rel($button['url']) }}" @endif>{{ $button['label'] }}</a>@endforeach</div>
@endif
</div>
@if ($variant === 'portal')
<div class="nav__menu nav__bar">@if ($panel)<div class="nav__menu-head"><span>{{ $ctx->site->name }}</span><label class="nav__menu-close" for="nav-toggle" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path d="M6 6l12 12M18 6L6 18"/></svg></label></div>@endif<nav class="container" aria-label="{{ $ctx->t('pages') }}">@include('theme.partials.nav-links')</nav>@if ($panel && $buttons !== [])<a class="btn nav__menu-cta" href="{{ $buttons[0]['url'] }}" @if ($ctx->rel($buttons[0]['url'])) rel="{{ $ctx->rel($buttons[0]['url']) }}" @endif>{{ $buttons[0]['label'] }}</a>@endif</div>
@endif
@if ($nav['mobile_header'] === 'tabs' && $links !== [])
<nav class="nav__tabs" aria-label="{{ $ctx->t('pages') }}"><a href="/">{{ $ctx->t('home') }}</a>@foreach ($links as $link)<a href="{{ $link['href'] }}">{{ $link['label'] }}</a>@endforeach</nav>
@endif
@if ($panel)<label class="nav__backdrop" for="nav-toggle" aria-hidden="true"></label>@endif
</header>
