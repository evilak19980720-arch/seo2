@php
    $sections = collect($definition['sections'] ?? [])->map(fn ($s) => ($entry->sections[$s['key']] ?? null) ? ['key' => $s['key']] + (array) $entry->sections[$s['key']] : null)->filter(fn ($s) => filled($s['html'] ?? null))->values();
    $params = $entry->params();
    $media = collect((array) $entry->get('images', []))->filter(fn ($p) => is_string($p) && $p !== $entry->image)->map(fn ($p) => $ctx->image($p, 1200))->filter()->take(6)->values();
    $status = in_array($entry->collection, ['promotion', 'event'], true) ? $entry->status() : null;
    $date = $entry->date();
    // Classification parameters link to the page that groups them (provider page, type and theme collections);
    // figures (RTP, volatility…) stay on this page, their terms explained in the glossary.
    $classes = [];
    if ($entry->collection === 'game') {
        $provider = $ctx->allEntries()->where('collection', 'provider')->first(fn ($p) => is_string($entry->get('provider')) && \App\Services\Content\SeoRules::normalize($p->title) === \App\Services\Content\SeoRules::normalize($entry->get('provider')));
        if (is_string($entry->get('provider')) && filled($entry->get('provider'))) {
            $classes['provider'] = [['label' => $entry->get('provider'), 'href' => $provider ? $ctx->entryHref($provider) : null]];
        }
        foreach (['type' => ['slot_types', 'types'], 'theme' => ['themes', 'theme_keys']] as $name => [$pageType, $field]) {
            $found = collect((array) $entry->get($field, []))->filter(fn ($v) => is_string($v))->map(fn ($key) => $ctx->child($pageType, $key))->filter()->map(fn ($c) => ['label' => $c['title'], 'href' => $c['href']])->values()->all();
            if ($found !== []) {
                $classes[$name] = $found;
            }
        }
        $devices = collect((array) $entry->get('devices', []))->filter(fn ($v) => is_string($v))->map(fn ($d) => ['label' => \Illuminate\Support\Facades\Lang::has('theme.device_labels.'.$d, $ctx->lang) ? $ctx->t('device_labels.'.$d) : $d, 'href' => null])->values()->all();
        if ($devices !== []) {
            $classes['device'] = $devices;
        }
        if (isset($classes['theme'])) {
            unset($params['themes']);
        }
        // Each mechanic links to the knowledge article that explains it, when the site has one.
        $articles = $ctx->children('academy')->map(fn ($c) => $c + ['match' => (array) ($ctx->section('academy', $c['key'])['match'] ?? [])]);
        $mechanics = collect((array) $entry->get('mechanics', []))->filter(fn ($v) => is_string($v) && trim($v) !== '')->map(function ($m) use ($articles) {
            $article = $articles->first(fn ($a) => collect($a['match'])->contains(fn ($w) => str_contains(mb_strtolower($m), mb_strtolower($w))));

            return ['label' => trim($m), 'href' => $article['href'] ?? null];
        })->values()->all();
        if ($mechanics !== [] && collect($mechanics)->contains(fn ($m) => $m['href'] !== null)) {
            $classes['mechanic'] = $mechanics;
            unset($params['mechanics']);
        }
    }
    $glossary = $ctx->pages->has('academy') && collect($ctx->blocks('academy'))->contains('key', 'glossary') ? $ctx->href($ctx->pages['academy']->slug).'#glossary' : null;
    $paramLabel = fn ($key) => in_array($key, ['starts_at', 'ends_at'], true) ? $ctx->t($key === 'starts_at' ? 'starts' : 'ends') : $ctx->t($key);
    // The brand's own link (an app or the product) gives a game or offer page its next step.
    $action = in_array($entry->collection, ['game', 'promotion', 'event'], true) ? ($ctx->ctaLinks(1)[0] ?? null) : null;
    // One or two facts read better as a line under the intro than as a table.
    $fewParams = count($params) + count($classes) < 3;
    $sameProvider = filled($entry->get('provider')) && $related->isNotEmpty() && $related->every(fn ($e) => \App\Services\Content\SeoRules::normalize((string) $e->get('provider')) === \App\Services\Content\SeoRules::normalize((string) $entry->get('provider')));
    $relatedTitle = match ($entry->collection) {
        'game' => $sameProvider ? $ctx->t('more_from', ['name' => $entry->get('provider')]) : $ctx->t('more_items'),
        'provider' => $ctx->t('games_by', ['name' => $entry->title]),
        default => $ctx->t('more_items'),
    };
@endphp
<article class="section entry entry--{{ $entry->collection }}">
<div class="container">
<header class="entry__head">
<div class="entry__intro prose">
<h1>{{ $entry->title }}</h1>
@if ($status || ($date && in_array($entry->collection, ['news', 'notice'], true)))<p class="post-meta">@if ($status)<span class="entry__status entry__status--{{ $status }}">{{ $ctx->t('status_'.$status) }}</span>@endif @if ($date && in_array($entry->collection, ['news', 'notice'], true))<time datetime="{{ $date->toDateString() }}">{{ $date->format('d/m/Y') }}</time>@endif</p>@endif
@if ($entry->summary)<p class="entry__lead">{{ $entry->summary }}</p>@endif
@if (($params || $classes) && $fewParams)<ul class="entry__facts">@foreach ($classes as $key => $values)<li><span>{{ $ctx->t($key) }}</span> @include('theme.partials.param-links', ['values' => $values])</li>@endforeach @foreach ($params as $key => $value)<li><span>{{ $paramLabel($key) }}</span> {{ $value }}</li>@endforeach</ul>@endif
@if ($action)<p class="entry__action"><a class="btn btn--lg" href="{{ $action['url'] }}" @if ($ctx->rel($action['url'])) rel="{{ $ctx->rel($action['url']) }}" @endif>{{ $action['label'] }}</a></p>@endif
</div>
@if ($image)<div class="entry__media">@include('theme.partials.picture', ['img' => $image, 'alt' => $entry->title, 'eager' => true, 'cap' => true])</div>@endif
</header>
<div class="entry__body">
<div class="prose">
@foreach ($sections as $section)
@if (! empty($section['heading']))<h2>{{ $section['heading'] }}</h2>@endif
{!! $section['html'] !!}
@endforeach
</div>
@if (($params || $classes) && ! $fewParams)
<aside class="entry__params specs"><h2>{{ $entry->collection === 'game' ? $ctx->t('parameters') : $ctx->t('details') }}</h2><table><tbody>
@foreach ($classes as $key => $values)<tr><th scope="row">{{ $ctx->t($key) }}</th><td>@include('theme.partials.param-links', ['values' => $values])</td></tr>@endforeach
@foreach ($params as $key => $value)<tr><th scope="row">@if ($glossary && in_array($key, ['rtp', 'volatility', 'max_win', 'paylines'], true))<a href="{{ $glossary }}">{{ $paramLabel($key) }}</a>@else{{ $paramLabel($key) }}@endif</th><td>{{ $value }}</td></tr>@endforeach
</tbody></table></aside>
@endif
</div>
@if ($media->isNotEmpty())
<section class="entry__gallery gallery"><h2>{{ $ctx->t('gallery') }}</h2><div class="gallery__items">
@foreach ($media as $img)<figure>@include('theme.partials.picture', ['img' => $img, 'alt' => $entry->title.' '.($loop->index + 1)])</figure>@endforeach
</div></section>
@endif
@if ($related->isNotEmpty())
<aside class="related entry-list entry-list--compact"><h2>{{ $relatedTitle }}</h2><div class="entry-list__items">
@foreach ($related as $item)@include('theme.partials.entry-card', ['entry' => $item])@endforeach
</div></aside>
@endif
<p class="entry-list__more"><a class="btn btn--ghost" href="{{ $ctx->href($owner->slug) }}">{{ $ctx->t('view_all') }}</a></p>
</div>
@php($next = $ctx->entryLinks($entry))
@if (count($next))
<nav class="section see-also" aria-label="{{ $ctx->t('see_also') }}"><div class="container"><p class="see-also__title">{{ $ctx->t('see_also') }}</p><ul class="see-also__list">@foreach ($next as $link)<li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>@endforeach</ul></div></nav>
@endif
</article>
