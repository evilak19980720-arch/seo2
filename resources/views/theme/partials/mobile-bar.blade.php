@php($nav = $ctx->navigation())
@if ($nav['mobile_bar'])
@php($cta = $ctx->primaryCta())
@php($items = array_slice($ctx->navLinks(), 0, $cta ? 3 : 4))
@php($icons = ['<path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>', '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>', '<path d="M4 5a2 2 0 0 1 2-2h12v18H6a2 2 0 0 1-2-2z"/><path d="M8 7h6M8 11h6"/>', '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>', '<path d="M4 6h16M4 12h16M4 18h10"/>'])
<nav class="mobile-bar" aria-label="{{ $ctx->t('pages') }}">
<a href="/"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons[0] !!}</svg><span>{{ $ctx->t('home') }}</span></a>
@foreach (array_slice($items, 0, 1) as $item)<a href="{{ $item['href'] }}"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons[1] !!}</svg><span>{{ $item['label'] }}</span></a>@endforeach
@if ($cta)<a class="mobile-bar__cta" href="{{ $cta['url'] }}" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11m0 0l-4.5-4.5M12 15l4.5-4.5M5 20h14"/></svg><span>{{ $cta['label'] }}</span></a>@endif
@foreach (array_slice($items, 1) as $i => $item)<a href="{{ $item['href'] }}"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons[$i + 2] ?? $icons[4] !!}</svg><span>{{ $item['label'] }}</span></a>@endforeach
</nav>
@endif
