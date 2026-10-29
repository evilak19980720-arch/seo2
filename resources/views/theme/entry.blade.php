@php
    $sections = collect($definition['sections'] ?? [])->map(fn ($s) => ($entry->sections[$s['key']] ?? null) ? ['key' => $s['key']] + (array) $entry->sections[$s['key']] : null)->filter(fn ($s) => filled($s['html'] ?? null))->values();
    $params = $entry->params();
    $media = collect((array) $entry->get('images', []))->filter(fn ($p) => is_string($p) && $p !== $entry->image)->map(fn ($p) => $ctx->image($p, 1200))->filter()->take(6)->values();
    $video = is_string($entry->get('video')) && str_starts_with($entry->get('video'), 'https://') ? $entry->get('video') : null;
    $status = in_array($entry->collection, ['promotion', 'event'], true) ? $entry->status() : null;
    $date = $entry->date();
    $paramLabel = fn ($key) => in_array($key, ['starts_at', 'ends_at'], true) ? $ctx->t($key === 'starts_at' ? 'starts' : 'ends') : $ctx->t($key);
    $relatedTitle = match ($entry->collection) {
        'game' => filled($entry->get('provider')) ? $ctx->t('more_from', ['name' => $entry->get('provider')]) : $ctx->t('more_items'),
        'provider' => $ctx->t('games_by', ['name' => $entry->title]),
        default => $ctx->t('more_items'),
    };
@endphp
<article class="section entry entry--{{ $entry->collection }}">
<div class="container">
<header class="entry__head">
<div class="entry__intro prose">
<h1>{{ $entry->title }}</h1>
@if ($status || ($date && in_array($entry->collection, ['news', 'notice'], true)))<p class="post-meta">@if ($status)<span class="entry__status entry__status--{{ $status }}">{{ $ctx->t('status_'.$status) }}</span>@endif @if ($date && in_array($entry->collection, ['news', 'notice'], true))<time datetime="{{ $date->toDateString() }}">{{ $date->format('d/m/Y') }}</time>@endif</p>@endif
@if ($entry->summary)<p class="entry__lead">{{ $entry->summary }}</p>@endif
</div>
@if ($image)<div class="entry__media">@include('theme.partials.picture', ['img' => $image, 'alt' => $entry->title, 'eager' => true, 'cap' => true])</div>@endif
</header>
<div class="entry__body">
<div class="prose">
@foreach ($sections as $section)
@if (! empty($section['heading']))<h2>{{ $section['heading'] }}</h2>@endif
{!! $section['html'] !!}
@endforeach
</div>
@if ($params)
<aside class="entry__params specs"><h2>{{ $entry->collection === 'game' ? $ctx->t('parameters') : $ctx->t('details') }}</h2><table><tbody>
@foreach ($params as $key => $value)<tr><th scope="row">{{ $paramLabel($key) }}</th><td>{{ $value }}</td></tr>@endforeach
</tbody></table></aside>
@endif
</div>
@if ($media->isNotEmpty() || $video)
<section class="entry__gallery gallery"><h2>{{ $ctx->t('media') }}</h2><div class="gallery__items">
@foreach ($media as $img)<figure>@include('theme.partials.picture', ['img' => $img, 'alt' => $entry->title.' '.($loop->index + 1)])</figure>@endforeach
</div>@if ($video)<p><a href="{{ $video }}" rel="nofollow noopener">{{ $ctx->t('media') }}</a></p>@endif</section>
@endif
@if ($related->isNotEmpty())
<aside class="related entry-list entry-list--compact"><h2>{{ $relatedTitle }}</h2><div class="entry-list__items">
@foreach ($related as $item)@include('theme.partials.entry-card', ['entry' => $item])@endforeach
</div></aside>
@endif
<p class="entry-list__more"><a class="btn btn--ghost" href="{{ $ctx->href($owner->slug) }}">{{ $ctx->t('view_all') }}</a></p>
</div>
@if ($entry->collection === 'game')<script>(function(){try{var k='lobby:recent',r=JSON.parse(localStorage.getItem(k))||[],s=@json($entry->slug),i=r.indexOf(s);if(i>-1)r.splice(i,1);r.unshift(s);localStorage.setItem(k,JSON.stringify(r.slice(0,24)))}catch(e){}})();</script>@endif
</article>
