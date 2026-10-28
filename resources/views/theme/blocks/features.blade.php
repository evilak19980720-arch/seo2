@php($items = $data['items'] ?? collect($ctx->brand->selling_points ?? [])->map(fn ($p) => ['title' => $p, 'text' => null])->all())
@if (count($items))
@php($mediaList = $variant === 'alternating' ? $ctx->gallery(count($items)) : [])
<section class="section features features--{{ $variant }}">
<div class="container">
<div class="section__head"><h2>{{ $data['heading'] ?? $ctx->brand->product_name }}</h2>@if (! empty($data['intro']))<p>{{ $data['intro'] }}</p>@endif</div>
<div class="grid {{ in_array($variant, ['grid', 'cards'], true) ? 'grid--3' : '' }}">
@foreach ($items as $i => $item)
<div class="feature {{ $variant === 'cards' ? 'card' : '' }}">
@if ($variant === 'alternating' && isset($mediaList[$i]))<div class="feature__media">@include('theme.partials.picture', ['img' => $mediaList[$i]['image'], 'alt' => $mediaList[$i]['alt']])</div>@endif
<div><h3>{{ $item['title'] }}</h3>@if (! empty($item['text']))<p>{{ $item['text'] }}</p>@endif</div>
</div>
@endforeach
</div>
</div>
</section>
@endif
