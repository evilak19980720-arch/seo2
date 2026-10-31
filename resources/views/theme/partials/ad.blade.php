@php($slotAds = $ads[$position] ?? [])
@if (count($slotAds))
@php($maxDesktop = collect($slotAds)->max('desktop_height') ?: 90)
@php($maxMobile = collect($slotAds)->max('mobile_height') ?: 100)
<aside class="ad-slot ad-slot--{{ $position }}" data-ad-slot="{{ $position }}" aria-label="{{ $ctx->t('advertisement') }}" style="--ad-h-m:{{ $maxMobile }}px;--ad-h-d:{{ $maxDesktop }}px">
@foreach ($slotAds as $ad)
<template data-ad-id="{{ $ad['id'] }}" data-w-d="{{ $ad['desktop_width'] }}" data-h-d="{{ $ad['desktop_height'] }}" data-w-m="{{ $ad['mobile_width'] }}" data-h-m="{{ $ad['mobile_height'] }}">{!! $ad['code'] !!}</template>
@endforeach
</aside>
@endif
