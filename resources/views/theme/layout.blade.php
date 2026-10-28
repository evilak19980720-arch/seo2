@php($site = $ctx->site)
<!DOCTYPE html>
<html lang="{{ $site->locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $head->title }}</title>
@if ($head->description)<meta name="description" content="{{ $head->description }}">
@endif
<meta name="robots" content="{{ $head->noindex ? 'noindex, follow' : 'index, follow, max-image-preview:large' }}">
<link rel="canonical" href="{{ $head->canonical }}">
<meta property="og:type" content="{{ $head->ogType }}">
<meta property="og:site_name" content="{{ $site->name }}">
<meta property="og:title" content="{{ $head->title }}">
<meta property="og:description" content="{{ $head->description }}">
<meta property="og:url" content="{{ $head->canonical }}">
<meta property="og:locale" content="{{ str_replace('-', '_', $site->locale) }}">
@if ($head->image)<meta property="og:image" content="{{ $head->image }}">
@endif
<meta name="twitter:card" content="{{ $head->image ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $head->title }}">
<meta name="twitter:description" content="{{ $head->description }}">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="{{ $ctx->design['colors']['primary'] }}">
<link rel="alternate" type="application/rss+xml" title="{{ $site->name }}" href="/feed.xml">
@foreach ($assets['preload_fonts'] as $font)
<link rel="preload" href="{{ $font }}" as="font" type="font/woff2" crossorigin>
@endforeach
@if (! empty($head->preloadImage))<link rel="preload" as="image" href="{{ $head->preloadImage }}" fetchpriority="high">
@endif
<style>{!! $assets['critical_css'] !!}</style>
<link rel="stylesheet" href="{{ $assets['base_css'] }}">
<link rel="stylesheet" href="{{ $assets['site_css'] }}">
<script type="application/ld+json">{!! $head->jsonLd() !!}</script>
@if ($site->ga4_measurement_id)
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('consent','default',{ad_storage:'denied',analytics_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});try{if(localStorage.getItem('consent')==='granted')gtag('consent','update',{ad_storage:'granted',analytics_storage:'granted',ad_user_data:'granted',ad_personalization:'granted'})}catch(e){}gtag('js',new Date());gtag('config','{{ $site->ga4_measurement_id }}');</script>
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $site->ga4_measurement_id }}"></script>
@endif
</head>
<body class="{{ $ctx->bodyClass() }}">
<a class="skip" href="#main">{{ $ctx->t('skip') }}</a>
@include('theme.blocks.nav', ['variant' => $ctx->design['nav']])
@include('theme.partials.ad', ['position' => 'top'])
<main id="main">
@if (count($head->breadcrumbs) > 1)
<nav class="breadcrumb container" aria-label="breadcrumb"><ol>
@foreach ($head->breadcrumbs as $crumb)
<li>@if (! $loop->last)<a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>@else<span aria-current="page">{{ $crumb['name'] }}</span>@endif</li>
@endforeach
</ol></nav>
@endif
{!! $body !!}
</main>
@include('theme.partials.ad', ['position' => 'bottom'])
@include('theme.blocks.footer', ['variant' => $ctx->design['footer']])
@if ($assets['cookie_banner'])
<div class="cookie" id="cookie" role="dialog" aria-live="polite"><p>{{ $ctx->t('cookie_text') }} <a href="{{ $ctx->pageHref('privacy') }}">{{ $ctx->pages->get('privacy')?->title }}</a></p><div class="cookie__actions"><button class="btn" data-consent="granted">{{ $ctx->t('cookie_accept') }}</button><button class="btn btn--ghost" data-consent="denied">{{ $ctx->t('cookie_reject') }}</button></div></div>
<script>(function(){var b=document.getElementById('cookie'),v;try{v=localStorage.getItem('consent')}catch(e){}if(!v)b.classList.add('is-open');b.addEventListener('click',function(e){var c=e.target.getAttribute('data-consent');if(!c)return;try{localStorage.setItem('consent',c)}catch(e){}if(c==='granted'&&window.gtag)gtag('consent','update',{ad_storage:'granted',analytics_storage:'granted',ad_user_data:'granted',ad_personalization:'granted'});b.classList.remove('is-open')})})();</script>
@endif
@if ($assets['has_ads'])
<script>{!! $assets['ads_js'] !!}</script>
@endif
</body>
</html>
