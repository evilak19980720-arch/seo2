@php($nav = $ctx->navigation())
@if ($nav['mobile_bar'])
@php($bar = $ctx->mobileBar())
@php($cta = $bar['cta'])
@php($items = $bar['items'])
@php($style = $nav['mobile_bar_style'] === 'cta' && ! $cta ? 'flat' : $nav['mobile_bar_style'])
@if ($style === 'cta')
<nav class="mobile-bar mobile-bar--cta mobile-bar--i-{{ $nav['mobile_bar_icons'] }}" aria-label="{{ $ctx->t('pages') }}">
<a class="mobile-bar__home" href="/" aria-label="{{ $ctx->t('home') }}"{!! $bar['home_current'] ? ' aria-current="page"' : '' !!}><svg viewBox="0 0 24 24" aria-hidden="true">{!! $bar['home_icon'] !!}</svg></a>
<a class="btn mobile-bar__button" href="{{ $cta['url'] }}" @if ($ctx->rel($cta['url'])) rel="{{ $ctx->rel($cta['url']) }}" @endif>{{ $cta['label'] }}</a>
</nav>
@else
<nav class="mobile-bar mobile-bar--{{ $style }} mobile-bar--i-{{ $nav['mobile_bar_icons'] }}" aria-label="{{ $ctx->t('pages') }}">
<a href="/"{!! $bar['home_current'] ? ' aria-current="page"' : '' !!}><svg viewBox="0 0 24 24" aria-hidden="true">{!! $bar['home_icon'] !!}</svg><span>{{ $ctx->t('home') }}</span></a>
@foreach (array_slice($items, 0, 1) as $item)<a href="{{ $item['href'] }}"{!! $item['current'] ? ' aria-current="page"' : '' !!}><svg viewBox="0 0 24 24" aria-hidden="true">{!! $item['icon'] !!}</svg><span>{{ $item['label'] }}</span></a>@endforeach
@if ($cta)<a class="mobile-bar__cta" href="{{ $cta['url'] }}" @if ($ctx->rel($cta['url'])) rel="{{ $ctx->rel($cta['url']) }}" @endif><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11m0 0l-4.5-4.5M12 15l4.5-4.5M5 20h14"/></svg><span>{{ $cta['label'] }}</span></a>@endif
@foreach (array_slice($items, 1) as $i => $item)<a href="{{ $item['href'] }}"{!! $item['current'] ? ' aria-current="page"' : '' !!}><svg viewBox="0 0 24 24" aria-hidden="true">{!! $item['icon'] !!}</svg><span>{{ $item['label'] }}</span></a>@endforeach
</nav>
@endif
@endif
