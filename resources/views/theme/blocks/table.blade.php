@php($rows = collect($data['items'] ?? [])->filter(fn ($it) => ! empty($it['title']) && ! empty($it['text']))->take(20)->values())
@if ($rows->isNotEmpty())
<section class="section data-table data-table--{{ $variant }}">
<div class="container">
@include('theme.partials.section-head')
@if ($variant === 'cards')
<dl class="data-table__cards">@foreach ($rows as $row)<div><dt>{{ $row['title'] }}</dt><dd>{{ $row['text'] }}</dd></div>@endforeach</dl>
@else
<div class="data-table__wrap"><table><tbody>@foreach ($rows as $row)<tr><th scope="row">{{ $row['title'] }}</th><td>{{ $row['text'] }}</td></tr>@endforeach</tbody></table></div>
@endif
</div>
</section>
@endif
