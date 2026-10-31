@if (\App\Services\Render\SiteRenderer::needsConsent($ctx->site))
<section class="section cookie-settings">
<div class="container">
@include('theme.partials.section-head', ['fallback' => $ctx->t('cookie_settings')])
<p><button class="btn" type="button" data-cookie-reset>{{ $ctx->t('cookie_settings') }}</button></p>
</div>
<script>(function(){var b=document.currentScript.parentNode.querySelector('[data-cookie-reset]');b.addEventListener('click',function(){try{localStorage.removeItem('consent')}catch(e){}var c=document.getElementById('cookie');if(c)c.classList.add('is-open')})})();</script>
</section>
@endif
