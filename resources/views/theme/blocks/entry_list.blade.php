@php($collection = $section['collection'] ?? null)
@php($owner = $collection ? $ctx->ownerType($collection) : null)
@php($onOwner = $owner !== null && $page?->type === $owner)
@php($items = $collection ? $ctx->pickEntries($collection, $section['filter'] ?? 'all', $onOwner ? 48 : ($variant === 'compact' ? 8 : 6)) : collect())
@if ($items->isNotEmpty())
<section class="section entry-list entry-list--{{ $variant }}" @if (! empty($section['key'])) id="{{ $section['key'] }}" @endif>
<div class="container">
<div class="section__head">@if (! empty($data['eyebrow']))<span class="section__eyebrow">{{ $data['eyebrow'] }}</span>@endif @if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->t('collections.'.$collection) }}</h2>@endif @if (! empty($data['intro']))<p>{{ $data['intro'] }}</p>@endif</div>
<div class="entry-list__items">
@foreach ($items as $entry)
@include('theme.partials.entry-card', ['entry' => $entry])
@endforeach
</div>
@if (! $onOwner && $owner)<p class="entry-list__more"><a class="btn btn--ghost" href="{{ $ctx->pageHref($owner) }}">{{ $ctx->t('view_all') }} · {{ $ctx->link($owner)['label'] }}</a></p>@endif
</div>
</section>
@endif
