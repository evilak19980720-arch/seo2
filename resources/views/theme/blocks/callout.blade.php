@if (filled($data['text'] ?? null) || filled($data['html'] ?? null))
<section class="section callout-block">
<div class="container"><div class="callout callout--{{ $variant }}" role="note">
<span class="callout__icon" aria-hidden="true">{{ $variant === 'warning' ? '!' : 'i' }}</span>
<div>@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@elseif (! empty($data['heading']))<h2>{{ $data['heading'] }}</h2>@endif
@if (filled($data['text'] ?? null))<p>{{ $data['text'] }}</p>@endif
@if (filled($data['html'] ?? null)){!! $data['html'] !!}@endif</div>
</div></div>
</section>
@endif
