@if ($img && filled($img['src'] ?? null))
<picture>@if ($img['avif'])<source srcset="{{ $img['avif'] }}" type="image/avif">@endif<img @if (($cap ?? false) && $img['width'] < 800) class="pic--capped" style="max-width:min(100%,{{ (int) round($img['width'] * 1.5) }}px)" @endif src="{{ $img['src'] }}" width="{{ $img['width'] }}" height="{{ $img['height'] }}" alt="{{ $alt }}" @if ($eager ?? false) fetchpriority="high" decoding="async" @else loading="lazy" decoding="async" @endif></picture>
@endif
