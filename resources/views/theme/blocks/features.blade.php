@php($items = $data['items'] ?? collect($ctx->brand->selling_points ?? [])->map(fn ($p) => ['title' => $p, 'text' => null])->all())
@if (count($items))
{{-- Each point gets the picture that fits its words, else an ordinary stand-in (never a logo or a text banner). --}}
@php($mediaList = $variant === 'alternating' ? collect($items)->map(fn ($it) => $ctx->fallbackImage('feature:'.($it['title'] ?? ''), 900, ($it['title'] ?? '').' '.($it['text'] ?? '')))->filter()->all() : [])
{{-- Without images or descriptions these layouts look empty; cards read well with titles alone. --}}
@php($variant = ($variant === 'alternating' && $mediaList === []) || ($variant === 'list' && collect($items)->every(fn ($it) => empty($it['text']))) ? 'cards' : $variant)
<section class="section features features--{{ $variant }}">
<div class="container">
<div class="section__head">@if (! empty($data['eyebrow']))<span class="section__eyebrow">{{ $data['eyebrow'] }}</span>@endif<h2>{{ $data['heading'] ?? $ctx->brand->product_name }}</h2>@if (! empty($data['intro']))<p>{{ $data['intro'] }}</p>@endif</div>
<div class="grid {{ in_array($variant, ['grid', 'cards'], true) ? (count($items) === 4 ? 'grid--2' : 'grid--3') : '' }}">
@foreach ($items as $i => $item)
<div class="feature">
@if ($variant === 'alternating' && isset($mediaList[$i]))<div class="feature__media">@include('theme.partials.picture', ['img' => $mediaList[$i]['image'], 'alt' => $mediaList[$i]['alt']])</div>@endif
<div>@if ($variant !== 'alternating' || ! isset($mediaList[$i]))<span class="feature__icon" aria-hidden="true">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>@endif<h3>{{ $item['title'] }}</h3>@if (! empty($item['text']))<p>{{ $item['text'] }}</p>@endif</div>
</div>
@endforeach
</div>
</div>
</section>
@endif
