@php($cta = $ctx->primaryCta())
@php($groups = $ctx->footerGroups())
<footer class="footer footer--{{ $variant }}">
<div class="container">
<div class="footer__cols{{ count($groups) > 3 ? ' footer__cols--wide' : '' }}" style="--fg:{{ count($groups) > 3 ? min(6, count($groups)) : max(1, count($groups)) }}">
<div><span class="footer__brand">{{ $ctx->site->name }}</span>@if ($ctx->brand->tagline)<p>{{ $ctx->brand->tagline }}</p>@endif
@if ($cta && $variant !== 'simple')<a class="btn" href="{{ $cta['url'] }}" @if ($ctx->rel($cta['url'])) rel="{{ $ctx->rel($cta['url']) }}" @endif>{{ $cta['label'] }}</a>@endif</div>
@php($fold = ($ctx->design['style']['mlinks'] ?? 'fold') === 'fold')
@foreach ($groups as $group)<details class="footer__group"{{ $fold ? ' data-fold' : '' }} open><summary class="footer__title">{{ $group['title'] }}</summary><ul>
@foreach ($group['links'] as $link)<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>@endforeach
</ul></details>@endforeach
</div>
@if (($ctx->site->typeConfig()['responsible_notice'] ?? false))<p class="footer__notice">{{ $ctx->t('responsible_notice') }}</p>@endif
<p class="footer__legal">© {{ now()->year }} {{ $ctx->brand->company_name ?: $ctx->site->name }}. {{ $ctx->t('rights') }}</p>
</div>
</footer>
@if ($fold)<script>(function(){var w=matchMedia('(min-width:860px)');function f(){document.querySelectorAll('[data-fold]').forEach(function(d){d.open=w.matches})}f();w.addEventListener&&w.addEventListener('change',f)})();</script>@endif
