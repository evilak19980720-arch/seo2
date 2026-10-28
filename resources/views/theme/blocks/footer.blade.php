<footer class="footer footer--{{ $variant }}">
<div class="container">
<div class="footer__cols">
<div><strong>{{ $ctx->site->name }}</strong>@if ($ctx->brand->tagline)<p>{{ $ctx->brand->tagline }}</p>@endif</div>
<div>@if ($variant === 'columns')<p><strong>{{ $ctx->t('pages') }}</strong></p>@endif<ul>
@foreach ($ctx->navLinks(all: true) as $link)<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>@endforeach
</ul></div>
<div>@if ($variant === 'columns')<p><strong>{{ $ctx->t('legal') }}</strong></p>@endif<ul>
@foreach ($ctx->legalLinks() as $link)<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>@endforeach
</ul></div>
</div>
<p class="footer__legal">© {{ now()->year }} {{ $ctx->brand->company_name ?: $ctx->site->name }}. {{ $ctx->t('rights') }}</p>
</div>
</footer>
