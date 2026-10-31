@php($cta = $ctx->primaryCta())
@php($linked = ! empty($section['link']) && $ctx->pages->has($section['link']) ? $ctx->link($section['link']) : null)
@php($linked = $linked && ! $ctx->isSelfLink($linked['href']) ? $linked : null)
@php($href = $linked['href'] ?? (filled($data['url'] ?? null) && ! $ctx->isSelfLink($data['url']) ? $data['url'] : ($cta['url'] ?? null)))
@php($data['label'] ??= $linked['label'] ?? null)
@php($data['text'] ??= $linked && $section['link'] === 'help' ? $ctx->t('support_text') : null)
@php($own = filled($data['heading'] ?? null) || filled($data['text'] ?? null) || ($isFirst ?? false))
@php($text = $data['text'] ?? $ctx->tagline())
{{-- A promo box with no copy of its own shows up once per page, not after every section. --}}
@if ($own || $linked || $text)
<section class="section cta cta--{{ $variant }}">
<div class="container"><div class="cta__box">
<div>@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->brand->product_name }}</h2>@endif @if (filled($text))<p>{{ $text }}</p>@endif</div>
@if ($href)<div class="cta__actions"><a class="btn" href="{{ $href }}" @if ($ctx->rel($href)) rel="{{ $ctx->rel($href) }}" @endif>{{ $data['label'] ?? ($cta['label'] ?? $ctx->t('read_more')) }}</a></div>@endif
</div></div>
</section>
@endif
