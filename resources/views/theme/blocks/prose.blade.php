@if (filled($data['html'] ?? null))
<section class="section prose-block prose-block--{{ $variant ?? 'default' }}">
<div class="container prose">
@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@elseif (! empty($data['heading']))<h2>{{ $data['heading'] }}</h2>@endif
{!! $data['html'] !!}
</div>
</section>
@endif
