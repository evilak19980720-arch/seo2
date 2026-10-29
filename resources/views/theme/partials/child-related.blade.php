{{-- Under a level-3 page: the games a knowledge article is about, then the other pages of the same section. --}}
@if ($games->isNotEmpty())
<section class="section entry-list entry-list--cards">
<div class="container">
<div class="section__head"><h2>{{ $ctx->t('games_using') }}</h2></div>
<div class="entry-list__items{{ $games->count() <= 2 ? ' items--'.$games->count() : '' }}">
@foreach ($games as $entry)
@include('theme.partials.entry-card', ['entry' => $entry])
@endforeach
</div>
</div>
</section>
@endif
@if ($siblings->isNotEmpty())
<nav class="section see-also" aria-label="{{ $ctx->t('more_in', ['page' => $page->title]) }}">
<div class="container"><p class="see-also__title"><a href="{{ $ctx->href($page->slug) }}">{{ $ctx->t('more_in', ['page' => $page->title]) }}</a></p><ul class="see-also__list">@foreach ($siblings as $sibling)<li><a href="{{ $sibling['href'] }}">{{ $sibling['title'] }}</a></li>@endforeach</ul></div>
</nav>
@endif
