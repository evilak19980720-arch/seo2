@php($links = collect($ctx->brand->cta_links ?? [])->filter(fn ($l) => filled($l['url'] ?? null))->take(4)->values())
@if ($links->isNotEmpty())
@php($logo = $ctx->logo())
<section class="section download download--{{ $variant }}">
<div class="container"><div class="download__box">
<div class="download__text">
@if ($logo && $variant !== 'banner')<img class="download__logo" src="{{ $logo['src'] }}" width="72" height="72" alt="{{ $ctx->site->name }}">@endif
@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->brand->product_name }}</h2>@endif
@if (! empty($data['text']) || $ctx->brand->tagline)<p>{{ $data['text'] ?? $ctx->brand->tagline }}</p>@endif
</div>
<div class="download__links">@foreach ($links as $i => $link)<a class="btn {{ $i > 0 ? 'btn--ghost' : '' }} btn--lg" href="{{ $link['url'] }}" rel="noopener">{{ $link['label'] ?? $link['url'] }}</a>@endforeach</div>
</div></div>
</section>
@endif
