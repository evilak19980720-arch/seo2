<section class="section">
<div class="container prose">
<h1>{{ $page?->title }}</h1>
{!! $data['html'] ?? '' !!}
@if ($ctx->brand->company_name)<p><strong>{{ $ctx->brand->company_name }}</strong>@if ($ctx->brand->company_email) · <a href="mailto:{{ $ctx->brand->company_email }}">{{ $ctx->brand->company_email }}</a>@endif</p>@endif
<p class="muted">{{ $ctx->t('updated') }} {{ ($page?->updated_at ?? now())->format('d/m/Y') }}</p>
</div>
</section>
