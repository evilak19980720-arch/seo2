@php($items = collect($data['items'] ?? [])->map(fn ($it) => ['key' => $it['text'] ?? '', 'value' => $it['title'] ?? ''])->filter(fn ($s) => filled($s['value']))->values())
@php($items = $items->isNotEmpty() ? $items : collect($ctx->brand->specs ?? [])->filter(fn ($s) => filled($s['key'] ?? null) && filled($s['value'] ?? null))->values())
@if ($items->count() >= 2)
<section class="stats-block stats-block--{{ $variant }}">
<div class="container">
@if (! empty($data['heading']))<h2 class="stats-block__title">{{ $data['heading'] }}</h2>@endif
<dl class="stats-block__grid">@foreach ($items->take(4) as $s)<div class="stats-block__item"><dd>{{ $s['value'] }}</dd><dt>{{ $s['key'] }}</dt></div>@endforeach</dl>
</div>
</section>
@endif
