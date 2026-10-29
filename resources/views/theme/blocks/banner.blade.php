@php($slotPic = $ctx->slotPicture('banner', $page?->type))
@php($slotImg = $slotPic && $ctx->useImage($slotPic['path']) ? $ctx->image($slotPic['path'], 1600) : null)
@php($images = array_merge($slotImg ? [['image' => $slotImg, 'alt' => $slotPic['alt']]] : [], $ctx->gallery($slotImg ? 2 : 3)))
@php($cta = $ctx->primaryCta())
@php($slides = collect($data['items'] ?? [])->filter(fn ($s) => ! empty($s['title']))->values()->all() ?: [['title' => $data['heading'] ?? $ctx->brand->product_name, 'text' => $data['text'] ?? $ctx->brand->tagline]])
@php($slides = array_slice($slides, 0, max(1, $variant === 'slider' ? 3 : 1)))
<section class="banner banner--{{ $variant }}" @if ($variant === 'slider' && count($slides) > 1) data-slider @endif>
<div class="{{ $variant === 'boxed' ? 'container' : '' }}">
<div class="banner__track">
@foreach ($slides as $i => $slide)
@php($img = $images[$i]['image'] ?? $ctx->fallbackImage('banner:'.($page?->type ?? '').':'.$i, 1600, $slide['heading'] ?? null)['image'] ?? null)
<div class="banner__slide @if (! $img) banner__slide--art @endif">
@if ($img)<div class="banner__media">@include('theme.partials.picture', ['img' => $img, 'alt' => $images[$i]['alt'], 'eager' => ($isFirst ?? false) && $i === 0])</div>@endif
<div class="container banner__body">
@if (($isFirst ?? false) && $i === 0)<h1>{{ $slide['title'] }}</h1>@else<h2>{{ $slide['title'] }}</h2>@endif
@if (! empty($slide['text']))<p>{{ $slide['text'] }}</p>@endif
@if ($cta)<a class="btn btn--lg" href="{{ $cta['url'] }}" @if ($ctx->rel($cta['url'])) rel="{{ $ctx->rel($cta['url']) }}" @endif>{{ $data['cta_label'] ?? $cta['label'] }}</a>@endif
</div>
</div>
@endforeach
</div>
@if ($variant === 'slider' && count($slides) > 1)
<div class="banner__dots">@foreach ($slides as $i => $slide)<button type="button" aria-label="{{ $i + 1 }}" data-go="{{ $i }}" @if ($i === 0) aria-current="true" @endif></button>@endforeach</div>
<script>(function(s){var t=s.querySelector('.banner__track'),d=s.querySelectorAll('[data-go]'),i=0,n=d.length;function go(k){i=(k+n)%n;t.scrollTo({left:t.clientWidth*i,behavior:'smooth'});d.forEach(function(b,j){b.toggleAttribute('aria-current',j===i)})}d.forEach(function(b){b.addEventListener('click',function(){go(+b.dataset.go)})});var h=setInterval(function(){go(i+1)},6000);t.addEventListener('pointerdown',function(){clearInterval(h)})})(document.currentScript.closest('[data-slider]'));</script>
@endif
</div>
</section>
