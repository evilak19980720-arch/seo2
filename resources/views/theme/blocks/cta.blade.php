@php($cta = $ctx->primaryCta())
@php($linked = ! empty($section['link']) && $ctx->pages->has($section['link']) ? $ctx->link($section['link']) : null)
@php($linked = $linked && ! $ctx->isSelfLink($linked['href']) ? $linked : null)
@php($href = $linked['href'] ?? (filled($data['url'] ?? null) && ! $ctx->isSelfLink($data['url']) ? $data['url'] : ($cta['url'] ?? null)))
@php($data['label'] ??= $linked['label'] ?? null)
@php($data['text'] ??= $linked && $section['link'] === 'help' ? $ctx->t('support_text') : null)
<section class="section cta cta--{{ $variant }}">
<div class="container"><div class="cta__box">
<div>@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->brand->product_name }}</h2>@endif @if (! empty($data['text']) || $ctx->brand->tagline)<p>{{ $data['text'] ?? $ctx->brand->tagline }}</p>@endif</div>
@if ($href)<div class="cta__actions"><a class="btn" href="{{ $href }}" @if ($ctx->rel($href)) rel="{{ $ctx->rel($href) }}" @endif>{{ $data['label'] ?? ($cta['label'] ?? $ctx->t('read_more')) }}</a></div>@endif
</div></div>
</section>
