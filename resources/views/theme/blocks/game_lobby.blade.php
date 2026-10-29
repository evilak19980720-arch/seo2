@php
    $games = $ctx->entries('game');
    $values = fn ($key) => $games->flatMap(fn ($g) => array_unique((array) $g->get($key, [])))->filter(fn ($v) => is_string($v) && trim($v) !== '')->map(fn ($v) => trim($v))->values();
    // A filter helps when it splits the games: two or more values, at least one shared by several games.
    $filters = array_map(fn ($list) => $list->unique()->sort()->values(), array_filter([
        'provider' => $games->map(fn ($g) => is_string($g->get('provider')) ? trim($g->get('provider')) : null)->filter()->values(),
        'theme' => $values('themes'),
        'mechanic' => $values('mechanics'),
    ], fn ($list) => $list->unique()->count() > 1 && $list->countBy()->max() > 1));
    // Tabs that would always be empty are left out. No personal tabs (recently played, favourites): brand sites have no accounts.
    $tabs = collect(['all', 'hot', 'new', 'classic'])->filter(fn ($t) => $t === 'all' || $games->contains(fn ($g) => in_array($t, $g->tags(), true)));
@endphp
@if ($games->isNotEmpty())
<section class="section lobby lobby--{{ $variant }}" data-lobby>
<div class="container">
<div class="section__head">@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@else<h2>{{ $data['heading'] ?? $ctx->t('page_titles.lobby') }}</h2>@endif @if (! empty($data['intro']))<p>{{ $data['intro'] }}</p>@endif</div>
<div class="lobby__bar">
<div class="lobby__tabs">@foreach ($tabs as $tab)<button type="button" class="lobby__tab" data-tab="{{ $tab }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $ctx->t($tab) }}</button>@endforeach</div>
<div class="lobby__tools">
<input class="lobby__search" type="search" data-q placeholder="{{ $ctx->t('search_games') }}" aria-label="{{ $ctx->t('search_games') }}">
@foreach ($filters as $name => $options)
<select class="lobby__select" data-filter="{{ $name }}" aria-label="{{ $ctx->t($name) }}"><option value="">{{ $ctx->t($name) }}: {{ $ctx->t('any') }}</option>@foreach ($options as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</select>
@endforeach
</div>
</div>
@php($shape = $ctx->tileShape($games))
@php($shapeClass = trim(($shape['ratio'] ? ' tiles--shaped' : '').($shape['cap'] ? ' tiles--small' : '')))
@php($shapeStyle = $shape['ratio'] ? '--tile-ratio:'.$shape['ratio'].($shape['cap'] ? ';--tile-cap:'.$shape['cap'].'px' : '') : null)
<div class="lobby__grid{{ $shapeClass ? ' '.$shapeClass : '' }}"@if ($shapeStyle) style="{{ $shapeStyle }}"@endif>
@foreach ($games as $game)
<div class="lobby__item" data-game="{{ $game->slug }}" data-tags="{{ implode(' ', $game->tags()) }}" data-name="{{ mb_strtolower($game->title.' '.$game->get('provider')) }}" data-provider="|{{ is_string($game->get('provider')) ? trim($game->get('provider')) : '' }}|" data-theme="|{{ implode('|', array_map('trim', array_filter((array) $game->get('themes', []), 'is_string'))) }}|" data-mechanic="|{{ implode('|', array_map('trim', array_filter((array) $game->get('mechanics', []), 'is_string'))) }}|">
@include('theme.partials.entry-card', ['entry' => $game])
</div>
@endforeach
</div>
<p class="muted lobby__empty" data-empty hidden>{{ $ctx->t('no_results') }}</p>
</div>
<script>(function(){var r=document.currentScript.parentNode,items=[].slice.call(r.querySelectorAll('[data-game]')),q=r.querySelector('[data-q]'),sel=[].slice.call(r.querySelectorAll('[data-filter]')),empty=r.querySelector('[data-empty]'),tab='all';function run(){var text=(q&&q.value||'').toLowerCase().trim(),shown=0;items.forEach(function(el){var ok=tab==='all'||(' '+el.getAttribute('data-tags')+' ').indexOf(' '+tab+' ')>-1;if(ok&&text)ok=el.getAttribute('data-name').indexOf(text)>-1;sel.forEach(function(x){if(ok&&x.value)ok=el.getAttribute('data-'+x.getAttribute('data-filter')).indexOf('|'+x.value+'|')>-1});el.hidden=!ok;if(ok)shown++});empty.hidden=shown>0}r.addEventListener('click',function(e){var t=e.target.closest('[data-tab]');if(!t)return;tab=t.getAttribute('data-tab');r.querySelectorAll('[data-tab]').forEach(function(b){b.setAttribute('aria-pressed',b===t?'true':'false')});run()});if(q)q.addEventListener('input',run);sel.forEach(function(x){x.addEventListener('change',run)});run()})();</script>
</section>
@endif
