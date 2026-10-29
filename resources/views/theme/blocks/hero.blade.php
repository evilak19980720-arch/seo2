@php($slotPic = $ctx->slotPicture('hero', $page?->type))
@php($heroSrc = $data['image'] ?? $slotPic['path'] ?? collect($ctx->brand->media ?? [])->firstWhere('type', 'image')['path'] ?? ($ctx->brand->media[0]['path'] ?? null))
@php($heroImg = $ctx->useImage($heroSrc) ? $ctx->image($heroSrc, 1600) : null)
@php($heroAlt = collect($ctx->brand->media ?? [])->firstWhere('path', $heroSrc)['alt'] ?? ($slotPic && $slotPic['path'] === $heroSrc ? $slotPic['alt'] : null))
@php($stand = $heroImg ? null : $ctx->fallbackImage('hero:'.($page?->type ?? ''), 1600, $data['heading'] ?? $page?->title))
@php($heroImg = $heroImg ?? $stand['image'] ?? null)
@php($heroAlt = $stand['alt'] ?? $heroAlt)
@php($cta = $ctx->primaryCta())
@php($logo = $ctx->site->logo_path ? $ctx->image($ctx->site->logo_path, 480) : null)
@php($points = array_slice(array_values(array_filter($data['points'] ?? ($ctx->brand->selling_points ?? []), 'is_string')), 0, 3))
@php($isHome = ($page?->type ?? null) === 'home')
{{-- A wide banner (2:1 or wider) usually has its own text painted in: shown whole, never cropped, in the site's own hero layout. --}}
@php($wide = $heroImg && ($heroImg['width'] ?? 0) >= 2 * max(1, (int) ($heroImg['height'] ?? 0)))
<section class="hero hero--{{ $variant }}{{ $wide ? ' hero--wide-img' : '' }}">
<div class="container hero__grid">
<div class="hero__text">
@if (! empty($data['eyebrow']))<span class="hero__eyebrow">{{ $data['eyebrow'] }}</span>@endif
@php($title = ($isFirst ?? false) ? ($data['heading'] ?? ($isHome ? $ctx->brand->product_name : ($page?->title ?? $ctx->brand->product_name))) : ($data['heading'] ?? $ctx->brand->product_name))
@php($titleClass = $ctx->titleClass($title))
@if ($isFirst ?? false)<h1{!! $titleClass !!}>{{ $title }}</h1>@else<h2{!! $titleClass !!}>{{ $title }}</h2>@endif
@php($lead = $data['subheading'] ?? $ctx->tagline())
@if ($lead)<p class="hero__lead">{{ $lead }}</p>@endif
<div class="hero__actions">
@if ($cta)<a class="btn btn--lg" href="{{ $cta['url'] }}" @if ($ctx->rel($cta['url'])) rel="{{ $ctx->rel($cta['url']) }}" @endif>{{ $data['cta_label'] ?? $cta['label'] }}</a>@endif
@if (! empty($data['secondary_href']))<a class="btn btn--ghost btn--lg" href="{{ $data['secondary_href'] }}">{{ $data['secondary_label'] ?? $ctx->t('read_more') }}</a>@endif
</div>
@if (count($points))<ul class="hero__points">@foreach ($points as $point)<li>{{ $point }}</li>@endforeach</ul>@endif
</div>
@if ($heroImg)
<div class="hero__media">@include('theme.partials.picture', ['img' => $heroImg, 'alt' => $data['image_alt'] ?? ($heroAlt ?: $ctx->brand->product_name), 'eager' => $isFirst ?? false])</div>
@else
<div class="hero__art" aria-hidden="true">@if ($logo)<img src="{{ $logo['src'] }}" width="{{ $logo['width'] ?? 240 }}" height="{{ $logo['height'] ?? 240 }}" alt="">@else<span>{{ mb_strtoupper(mb_substr($ctx->brand->product_name ?: $ctx->site->name, 0, 1)) }}</span>@endif</div>
@endif
</div>
</section>
@php($specs = collect($ctx->brand->specs ?? [])->filter(fn ($s) => filled($s['key'] ?? null) && filled($s['value'] ?? null))->take(4))
@if ($isHome && ($isFirst ?? false) && $specs->count() >= 2 && ! collect($ctx->design['pages']['home'] ?? [])->contains('block', 'specs'))
<div class="stats"><div class="container"><dl class="stats__grid">@foreach ($specs as $s)<div class="stats__item"><dt>{{ $s['key'] }}</dt><dd>{{ $s['value'] }}</dd></div>@endforeach</dl></div></div>
@endif
