@php
    // Links to other pages of the site, or to the filled sections of a page ("sections:<page>").
    $targets = $section['links'] ?? [];
    $links = [];
    if (is_string($targets) && str_starts_with($targets, 'sections:')) {
        $target = substr($targets, 9);
        if ($ctx->pages->has($target) && ! in_array($target, $ctx->hidden, true)) {
            foreach ($ctx->blocks($target) as $entry) {
                $def = $ctx->section($target, $entry['key'] ?? null);
                if (! empty($def['collection']) && $ctx->entries($def['collection'], $def['filter'] ?? 'all')->isNotEmpty()) {
                    $links[] = ['label' => $ctx->pages[$target]->content[$def['key']]['heading'] ?? $ctx->t('collections.'.$def['collection']), 'href' => $ctx->pageHref($target).'#'.$def['key'], 'count' => $ctx->entries($def['collection'], $def['filter'] ?? 'all')->count()];
                }
            }
        }
        // Section pills only help with several real groups: four or more, and not one item each.
        if (count($links) < 4 || max(array_column($links, 'count') ?: [0]) < 2) {
            $links = [];
        }
    } else {
        foreach ((array) $targets as $type) {
            if ($ctx->pages->has($type) && ! in_array($type, $ctx->hidden, true) && trim($ctx->pages[$type]->slug, '/') !== $ctx->currentPath) {
                $links[] = $ctx->link($type) + ['count' => null];
            }
        }
    }
@endphp
@if (count($links) >= 2)
<section class="section link-grid link-grid--{{ $variant }}">
<div class="container">
@include('theme.partials.section-head')
<ul class="link-grid__items">
@foreach ($links as $link)<li><a href="{{ $link['href'] }}"><span>{{ $link['label'] }}</span>@if ($link['count'])<small>{{ $link['count'] }}</small>@endif</a></li>@endforeach
</ul>
</div>
</section>
@endif
