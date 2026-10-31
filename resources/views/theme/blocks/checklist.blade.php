@php($items = collect($data['items'] ?? [])->filter(fn ($it) => ! empty($it['title']))->take(10)->values())
@if ($items->count() >= 2)
<section class="section checklist checklist--{{ $variant }}">
<div class="container">
@include('theme.partials.section-head')
<ul class="checklist__items">
@foreach ($items as $item)
<li class="checklist__item"><span class="checklist__icon" aria-hidden="true">&#10003;</span><div><strong>{{ $item['title'] }}</strong>@if (! empty($item['text']))<p>{{ $item['text'] }}</p>@endif</div></li>
@endforeach
</ul>
</div>
</section>
@endif
