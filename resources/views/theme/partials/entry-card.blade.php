@php
    $href = $ctx->entryHref($entry);
    $thumb = $entry->image ? $ctx->image($entry->image, 640) : null;
    $status = $entry->status();
    $tag = collect(['hot', 'new', 'classic'])->first(fn ($t) => in_array($t, $entry->tags(), true));
    $badge = in_array($entry->collection, ['promotion', 'event'], true) ? ($status ? $ctx->t('status_'.$status) : null) : ($tag ? $ctx->t($tag) : null);
    $date = in_array($entry->collection, ['news', 'notice', 'event'], true) ? $entry->date() : null;
    $count = $entry->collection === 'provider' ? $ctx->entries('game')->filter(fn ($g) => \App\Services\Content\SeoRules::normalize((string) $g->get('provider')) === \App\Services\Content\SeoRules::normalize($entry->title))->count() : 0;
    $meta = array_values(array_filter([
        $entry->collection === 'game' ? $entry->get('provider') : null,
        $entry->collection === 'promotion' ? $entry->get('reward') : null,
        $count ? $ctx->t('game_count').': '.$count : null,
    ], fn ($v) => is_string($v) && $v !== ''));
@endphp
<article class="tile entry-card entry-card--{{ $entry->collection }}">
<div class="tile__media">@if ($thumb)@include('theme.partials.picture', ['img' => $thumb, 'alt' => $entry->title])@else<span class="tile__ph" aria-hidden="true">{{ mb_strtoupper(mb_substr($entry->title, 0, 1)) }}</span>@endif @if ($badge)<span class="tile__tag">{{ $badge }}</span>@endif</div>
<div class="tile__body">
<h3>@if ($href)<a class="entry-card__link" href="{{ $href }}">{{ $entry->title }}</a>@else{{ $entry->title }}@endif</h3>
@if ($meta || $date)<p class="entry-card__meta">{{ implode(' · ', $meta) }}@if ($meta && $date) · @endif @if ($date)<time datetime="{{ $date->toDateString() }}">{{ $date->format('d/m/Y') }}</time>@endif</p>@endif
@if ($entry->summary)<p>{{ \Illuminate\Support\Str::limit($entry->summary, 140) }}</p>@endif
</div>
</article>
