@php($cta = $ctx->primaryCta())
@php($href = filled($data['url'] ?? null) && ! $ctx->isSelfLink($data['url']) ? $data['url'] : ($cta['url'] ?? null))
<section class="section cta cta--{{ $variant }}">
<div class="container"><div class="cta__box">
<div>@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->brand->product_name }}</h2>@endif @if (! empty($data['text']) || $ctx->brand->tagline)<p>{{ $data['text'] ?? $ctx->brand->tagline }}</p>@endif</div>
@if ($href)<div class="cta__actions"><a class="btn" href="{{ $href }}" @if ($ctx->rel($href)) rel="{{ $ctx->rel($href) }}" @endif>{{ $data['label'] ?? ($cta['label'] ?? $ctx->t('read_more')) }}</a></div>@endif
</div></div>
</section>
