{{-- The level-3 pages of a split page (types, themes, knowledge articles): one tile each, with a real picture of what it lists. --}}
<section class="section card-grid card-grid--default child-grid">
<div class="container">
<div class="section__head"><h2>{{ $ctx->t('child_grid') }}</h2></div>
<div class="card-grid__items{{ $children->count() <= 2 ? ' items--'.$children->count() : '' }}">
@foreach ($children as $child)
@php($def = $ctx->section($child['type'], $child['key']))
@php($first = ! empty($def['collection']) ? $ctx->entries($def['collection'], $def['filter'] ?? 'all')->first(fn ($e) => filled($e->image)) : null)
@php($img = $first ? $ctx->image($first->image, 800) : null)
@php($fallback = $img ? null : $ctx->fallbackImage('child:'.$child['key'], 800, $child['title'].' '.$child['excerpt']))
<article class="tile">
<a class="tile__media" href="{{ $child['href'] }}" tabindex="-1" aria-hidden="true">@if ($img)@include('theme.partials.picture', ['img' => $img, 'alt' => $first->title])@elseif ($fallback)@include('theme.partials.picture', ['img' => $fallback['image'], 'alt' => $fallback['alt']])@else<span class="tile__ph">{{ mb_strtoupper(mb_substr($child['title'], 0, 1)) }}</span>@endif</a>
<div class="tile__body"><h3><a href="{{ $child['href'] }}">{{ $child['title'] }}</a></h3>@if ($child['excerpt'] !== '')<p>{{ $child['excerpt'] }}</p>@endif @if ($child['count'])<p class="muted">{{ $ctx->t('games_count', ['n' => $child['count']]) }}</p>@endif</div>
</article>
@endforeach
</div>
</div>
</section>
