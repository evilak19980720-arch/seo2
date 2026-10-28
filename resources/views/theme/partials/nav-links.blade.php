<ul class="nav__links">
@if ($nav['home_link'])<li><a href="/">{{ $ctx->t('home') }}</a></li>@endif
@foreach ($links as $link)
@if ($link['children'])
<li class="nav__has-sub"><a href="{{ $link['href'] }}">{{ $link['label'] }}</a><ul class="nav__sub">@foreach ($link['children'] as $child)<li><a href="{{ $child['href'] }}">{{ $child['label'] }}</a></li>@endforeach</ul></li>
@else
<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>
@endif
@endforeach
</ul>
