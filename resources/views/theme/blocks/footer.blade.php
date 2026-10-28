@php($cta = $ctx->primaryCta())
<footer class="footer footer--{{ $variant }}">
<div class="container">
<div class="footer__cols">
<div><span class="footer__brand">{{ $ctx->site->name }}</span>@if ($ctx->brand->tagline)<p>{{ $ctx->brand->tagline }}</p>@endif
@if ($cta && $variant !== 'simple')<a class="btn" href="{{ $cta['url'] }}" rel="noopener">{{ $cta['label'] }}</a>@endif</div>
<div class="footer__pages">@if ($variant !== 'simple')<p class="footer__title">{{ $ctx->t('pages') }}</p>@endif<ul>
@foreach ($ctx->navLinks(all: true) as $link)<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>@endforeach
</ul></div>
<div>@if ($variant !== 'simple')<p class="footer__title">{{ $ctx->t('legal') }}</p>@endif<ul>
@foreach ($ctx->legalLinks() as $link)<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>@endforeach
@if ($ctx->brand->company_email)<li><a href="mailto:{{ $ctx->brand->company_email }}">{{ $ctx->brand->company_email }}</a></li>@endif
</ul></div>
</div>
<p class="footer__legal">© {{ now()->year }} {{ $ctx->brand->company_name ?: $ctx->site->name }}. {{ $ctx->t('rights') }}</p>
</div>
</footer>
