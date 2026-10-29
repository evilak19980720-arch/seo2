<section class="section html-sitemap">
<div class="container">
<div class="section__head">@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->t('page_titles.sitemap') }}</h2>@endif @if (! empty($data['intro']))<p>{{ $data['intro'] }}</p>@endif</div>
<div class="html-sitemap__cols">
<div><h2>{{ $ctx->t('sitemap_pages') }}</h2><ul>
<li><a href="{{ $ctx->href() }}">{{ $ctx->t('home') }}</a></li>
@foreach ($ctx->navLinks(all: true) as $link)<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>@endforeach
@foreach ($ctx->legalLinks() as $link)@if (trim($link['href'], '/') !== $ctx->currentPath)<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>@endif @endforeach
</ul></div>
@foreach ($ctx->pages as $type => $parent)
@php($children = in_array($type, $ctx->hidden, true) ? collect() : $ctx->children($type))
@if ($children->isNotEmpty())
<div><h2><a href="{{ $ctx->href($parent->slug) }}">{{ $parent->title }}</a></h2><ul>
@foreach ($children as $child)<li><a href="{{ $child['href'] }}">{{ $child['title'] }}</a></li>@endforeach
</ul></div>
@endif
@endforeach
@foreach (\App\Models\Entry::COLLECTIONS as $collection)
@php($items = $ctx->ownerType($collection) ? $ctx->entries($collection) : collect())
@if ($items->isNotEmpty())
<div><h2>{{ $ctx->t('collections.'.$collection) }}</h2><ul>
@foreach ($items as $entry)<li><a href="{{ $ctx->entryHref($entry) }}">{{ $entry->title }}</a></li>@endforeach
</ul></div>
@endif
@endforeach
</div>
</div>
</section>
