@php($items = $ctx->gallery(8))
@if (count($items))
<section class="section section--alt gallery gallery--{{ $variant }}">
<div class="container">
<div class="section__head">@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->t('gallery') }}</h2>@endif @if (! empty($data['intro']))<p>{{ $data['intro'] }}</p>@endif</div>
<div class="gallery__items {{ in_array($variant, ['grid'], true) ? 'grid grid--3' : '' }}">
@foreach ($items as $item)
<figure>@include('theme.partials.picture', ['img' => $item['image'], 'alt' => $item['alt']])@if ($item['caption'])<figcaption>{{ $item['caption'] }}</figcaption>@endif</figure>
@endforeach
</div>
</div>
</section>
@endif
