@php($items = collect($data['items'] ?? [])->filter(fn ($it) => ! empty($it['title']))->take(12)->values())
@if ($items->count() >= 2)
<section class="section timeline timeline--{{ $variant }}">
<div class="container">
@include('theme.partials.section-head')
<ol class="timeline__list">
@foreach ($items as $item)
<li class="timeline__item"><span class="timeline__dot" aria-hidden="true"></span><div class="timeline__body"><h3>{{ $item['title'] }}</h3>@if (! empty($item['text']))<p>{{ $item['text'] }}</p>@endif</div></li>
@endforeach
</ol>
</div>
</section>
@endif
