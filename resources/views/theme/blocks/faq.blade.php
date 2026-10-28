@php($items = $data['items'] ?? collect($ctx->brand->faq ?? [])->map(fn ($f) => ['question' => $f['question'] ?? '', 'answer' => $f['answer'] ?? ''])->all())
@if (count($items))
<section class="section faq faq--{{ $variant }}">
<div class="container">
<div class="section__head">@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->t('faq') }}</h2>@endif</div>
<div class="faq__items {{ $variant === 'cards' ? 'grid grid--2' : '' }}">
@foreach ($items as $item)
@if ($variant === 'accordion')
<details @if ($loop->first) open @endif><summary>{{ $item['question'] }}</summary><div class="faq__answer">{{ $item['answer'] }}</div></details>
@else
<div class="faq__item {{ $variant === 'cards' ? 'card' : '' }}"><h3>{{ $item['question'] }}</h3><p class="muted">{{ $item['answer'] }}</p></div>
@endif
@endforeach
</div>
</div>
</section>
@endif
