@php($cta = $ctx->primaryCta())
@php($href = $data['url'] ?? ($cta['url'] ?? '/'))
<section class="section cta cta--{{ $variant }}">
<div class="container"><div class="cta__box">
<div>@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->brand->product_name }}</h2>@endif @if (! empty($data['text']) || $ctx->brand->tagline)<p>{{ $data['text'] ?? $ctx->brand->tagline }}</p>@endif</div>
<div class="cta__actions"><a class="btn" href="{{ $href }}" @if (str_starts_with($href, 'http')) rel="noopener" @endif>{{ $data['label'] ?? ($cta['label'] ?? $ctx->t('back_home')) }}</a></div>
</div></div>
</section>
