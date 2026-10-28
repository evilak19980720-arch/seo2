@php($text = $data['text'] ?? $ctx->brand->tagline)
@if ($text)
@php($cta = $ctx->primaryCta())
<div class="notice notice--{{ $variant }}" role="note">
<div class="container notice__inner"><span class="notice__icon" aria-hidden="true">★</span>
<div class="notice__text"><span>{{ $text }}</span>@if ($variant === 'marquee')<span aria-hidden="true">{{ $text }}</span>@endif</div>
@if ($cta)<a class="notice__link" href="{{ $cta['url'] }}" @if ($ctx->rel($cta['url'])) rel="{{ $ctx->rel($cta['url']) }}" @endif>{{ $data['label'] ?? $cta['label'] }} →</a>@endif
</div>
</div>
@endif
