@php($collection = $section['collection'] ?? null)
@php($owner = $collection ? $ctx->ownerType($collection) : null)
@php($onOwner = $owner !== null && $page?->type === $owner)
@php($items = $collection ? $ctx->pickEntries($collection, $section['filter'] ?? 'all', ($onOwner || ($child ?? false)) ? 48 : ($variant === 'compact' ? 8 : 6)) : collect())
{{-- A list with "tabs" switches between the tags its items share (offers for new players, holidays…): tags are filters, never sections. --}}
@php($tabs = ! empty($section['tabs']) ? $items->flatMap(fn ($e) => $e->tags())->countBy()->filter(fn ($n, $tag) => $tag !== 'featured' && \Illuminate\Support\Facades\Lang::has('theme.tag_labels.'.$tag, $ctx->lang) && $n < $items->count())->keys()->values() : collect())
@if ($items->isNotEmpty())
{{-- A spotlight or carousel of one or two items is just an oversized card. --}}
@php($shape = $ctx->tileShape($items))
{{-- Small pictures are never blown up into a spotlight card. --}}
@php($variant = ($items->count() < 3 || $shape['cap']) && in_array($variant, ['featured', 'carousel'], true) ? 'cards' : $variant)
<section class="section entry-list entry-list--{{ $variant }}" @if (! empty($section['key'])) id="{{ $section['key'] }}" @endif>
<div class="container">
<div class="section__head">@if (! empty($data['eyebrow']))<span class="section__eyebrow">{{ $data['eyebrow'] }}</span>@endif @if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->t('collections.'.$collection) }}</h2>@endif @if (! empty($data['intro']))<p>{{ $data['intro'] }}</p>@endif</div>
@php($shapeClass = trim(($shape['ratio'] ? ' tiles--shaped' : '').($items->count() <= 2 ? ' items--'.$items->count() : '').($shape['cap'] ? ' tiles--small' : '')))
@php($shapeStyle = $shape['ratio'] ? '--tile-ratio:'.$shape['ratio'].($shape['cap'] ? ';--tile-cap:'.$shape['cap'].'px' : '') : null)
@if ($tabs->isNotEmpty())<div class="lobby__tabs entry-list__tabs" data-tabs>@foreach (collect(['all'])->merge($tabs) as $tab)<button type="button" class="lobby__tab" data-tab="{{ $tab }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $tab === 'all' ? $ctx->t('all_items') : $ctx->t('tag_labels.'.$tab) }}</button>@endforeach</div>@endif
<div class="entry-list__items{{ $shapeClass ? ' '.$shapeClass : '' }}"@if ($shapeStyle) style="{{ $shapeStyle }}"@endif>
@foreach ($items as $entry)
@if ($tabs->isNotEmpty())<div class="entry-list__cell" data-tags="{{ implode(' ', $entry->tags()) }}">@endif
@include('theme.partials.entry-card', ['entry' => $entry])
@if ($tabs->isNotEmpty())</div>@endif
@endforeach
</div>
@if ($tabs->isNotEmpty())<script>(function(){var r=document.currentScript.parentNode;r.addEventListener('click',function(e){var t=e.target.closest('[data-tab]');if(!t)return;var tab=t.getAttribute('data-tab');r.querySelectorAll('[data-tab]').forEach(function(b){b.setAttribute('aria-pressed',b===t?'true':'false')});r.querySelectorAll('[data-tags]').forEach(function(el){el.hidden=tab!=='all'&&(' '+el.getAttribute('data-tags')+' ').indexOf(' '+tab+' ')<0})})})();</script>@endif
@if (! $onOwner && $owner && ! ($child ?? false))<p class="entry-list__more"><a class="btn btn--ghost" href="{{ $ctx->pageHref($owner) }}">{{ $ctx->t('view_all') }}</a></p>@endif
</div>
</section>
@endif
