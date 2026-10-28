@php($specs = collect($ctx->brand->specs ?? [])->filter(fn ($s) => filled($s['key'] ?? null) && filled($s['value'] ?? null)))
@if ($specs->isNotEmpty())
<section class="section specs specs--{{ $variant }}">
<div class="container">
<div class="section__head">@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->t('specs') }}</h2>@endif @if (! empty($data['intro']))<p>{{ $data['intro'] }}</p>@endif</div>
@if ($variant === 'table')
<table><tbody>@foreach ($specs as $s)<tr><th scope="row">{{ $s['key'] }}</th><td>{{ $s['value'] }}</td></tr>@endforeach</tbody></table>
@else
<dl>@foreach ($specs as $s)<div class="{{ $variant === 'cards' ? 'card' : '' }}"><dt>{{ $s['key'] }}</dt><dd>{{ $s['value'] }}</dd></div>@endforeach</dl>
@endif
</div>
</section>
@endif
