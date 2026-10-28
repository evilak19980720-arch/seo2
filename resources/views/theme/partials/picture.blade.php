@if ($img)
<picture>@if ($img['avif'])<source srcset="{{ $img['avif'] }}" type="image/avif">@endif<img src="{{ $img['src'] }}" width="{{ $img['width'] }}" height="{{ $img['height'] }}" alt="{{ $alt }}" @if ($eager ?? false) fetchpriority="high" decoding="async" @else loading="lazy" decoding="async" @endif></picture>
@endif
