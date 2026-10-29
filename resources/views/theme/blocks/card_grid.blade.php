@php($items = collect($data['items'] ?? [])->filter(fn ($it) => ! empty($it['title']))->values())
@php($fromGallery = $items->isEmpty())
@php($images = $fromGallery ? $ctx->gallery(12) : [])
@php($items = $items->isNotEmpty() ? $items : collect($images)->map(fn ($g) => ['title' => $g['caption'] ?: $g['alt'], 'text' => null]))
@php($items = $items->take($variant === 'compact' ? 12 : 8)->values())
@if ($items->isNotEmpty())
@php($cta = $ctx->primaryCta())
<section class="section card-grid card-grid--{{ $variant }}">
<div class="container">
<div class="section__head">@if (! empty($data['eyebrow']))<span class="section__eyebrow">{{ $data['eyebrow'] }}</span>@endif @if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->brand->product_name }}</h2>@endif @if (! empty($data['intro']))<p>{{ $data['intro'] }}</p>@endif</div>
<div class="card-grid__items{{ $items->count() <= 2 ? ' items--'.$items->count() : '' }}">
@foreach ($items as $i => $item)
@php($img = ($fromGallery ? ($images[$i] ?? null) : null) ?? $ctx->fallbackImage('card:'.$item['title'], 800, $item['title'].' '.($item['text'] ?? '')))
<article class="tile">
<div class="tile__media">@if ($img)@include('theme.partials.picture', ['img' => $img['image'], 'alt' => $img['alt']])@else<span class="tile__ph" aria-hidden="true">{{ mb_strtoupper(mb_substr($item['title'], 0, 1)) }}</span>@endif @if (! empty($item['tag']))<span class="tile__tag">{{ $item['tag'] }}</span>@endif</div>
<div class="tile__body"><h3>{{ $item['title'] }}</h3>@if (! empty($item['text']))<p>{{ $item['text'] }}</p>@endif
@if ($cta && $variant === 'showcase')<a class="btn" href="{{ $cta['url'] }}" @if ($ctx->rel($cta['url'])) rel="{{ $ctx->rel($cta['url']) }}" @endif>{{ $cta['label'] }}</a>@endif</div>
</article>
@endforeach
</div>
</div>
</section>
@endif
