@php($items = collect($data['items'] ?? [])->filter(fn ($it) => ! empty($it['title']))->take(6)->values())
@if ($items->count() >= 2)
<section class="section steps steps--{{ $variant }}">
<div class="container">
<div class="section__head">@if (! empty($data['eyebrow']))<span class="section__eyebrow">{{ $data['eyebrow'] }}</span>@endif @if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->t('how_to_start') }}</h2>@endif @if (! empty($data['intro']))<p>{{ $data['intro'] }}</p>@endif</div>
<ol class="steps__list">
@foreach ($items as $i => $item)
<li class="step"><span class="step__n" aria-hidden="true">{{ $i + 1 }}</span><div><h3>{{ $item['title'] }}</h3>@if (! empty($item['text']))<p>{{ $item['text'] }}</p>@endif</div></li>
@endforeach
</ol>
</div>
</section>
@endif
