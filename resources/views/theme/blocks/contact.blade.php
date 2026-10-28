<section class="section contact">
<div class="container prose">
@if ($isFirst ?? true)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->t('page_titles.contact') }}</h2>@endif
{!! $data['html'] ?? '' !!}
<dl>
@if ($ctx->brand->company_name)<div><dt>{{ $ctx->t('company') }}</dt><dd>{{ $ctx->brand->company_name }}</dd></div>@endif
@if ($ctx->brand->company_email)<div><dt>{{ $ctx->t('contact_email') }}</dt><dd><a href="mailto:{{ $ctx->brand->company_email }}">{{ $ctx->brand->company_email }}</a></dd></div>@endif
@if ($ctx->brand->company_address)<div><dt>{{ $ctx->t('contact_address') }}</dt><dd>{{ $ctx->brand->company_address }}</dd></div>@endif
</dl>
@foreach ($ctx->brand->cta_links ?? [] as $link)
@if (! empty($link['url']))<p><a class="btn" href="{{ $link['url'] }}" @if ($ctx->rel($link['url'])) rel="{{ $ctx->rel($link['url']) }}" @endif>{{ $link['label'] ?? $link['url'] }}</a></p>@endif
@endforeach
</div>
</section>
