@php($items = collect($data['items'] ?? [])->map(fn ($it) => ['term' => $it['title'] ?? $it['question'] ?? null, 'text' => $it['text'] ?? $it['answer'] ?? null])->filter(fn ($it) => $it['term'] && $it['text'])->take(24)->values())
@if ($items->count() >= 2)
<section class="section glossary glossary--{{ $variant }}">
<div class="container">
@include('theme.partials.section-head')
<dl class="glossary__items">
@foreach ($items as $item)
<div class="glossary__item"><dt>{{ $item['term'] }}</dt><dd>{{ $item['text'] }}</dd></div>
@endforeach
</dl>
</div>
</section>
@endif
