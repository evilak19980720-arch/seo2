@php($heroImg = $ctx->image($data['image'] ?? ($ctx->brand->media[0]['path'] ?? null), 1600))
@php($cta = $ctx->primaryCta())
<section class="hero hero--{{ $variant }}">
<div class="container hero__grid">
<div class="hero__text">
@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? ($page?->title ?? $ctx->brand->product_name) }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->brand->product_name }}</h2>@endif
<p class="hero__lead">{{ $data['subheading'] ?? $ctx->brand->tagline }}</p>
<div class="hero__actions">
@if ($cta)<a class="btn" href="{{ $cta['url'] }}" rel="noopener">{{ $data['cta_label'] ?? $cta['label'] }}</a>@endif
@if (! empty($data['secondary_href']))<a class="btn btn--ghost" href="{{ $data['secondary_href'] }}">{{ $data['secondary_label'] ?? $ctx->t('read_more') }}</a>@endif
</div>
</div>
@if ($heroImg)
<div class="hero__media">@include('theme.partials.picture', ['img' => $heroImg, 'alt' => $data['image_alt'] ?? ($ctx->brand->media[0]['alt'] ?? $ctx->brand->product_name), 'eager' => $isFirst ?? false])</div>
@endif
</div>
</section>
