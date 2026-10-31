<?php

/*
| Theme engine whitelist. The AI design config may only reference what is
| listed here; DesignConfigValidator rejects or repairs everything else.
*/

// Slots brand-site variants (resources/theme/variants/{block}--{name}.css). Each one reuses an
// existing variant's markup (base) and restyles it; stage variants sit on the site's dark
// secondary colour (text and frames switch to it). A site's stylesheet holds only the ones it uses.
$variants = [
    'nav' => ['neon' => ['base' => 'bar'], 'glass' => ['base' => 'bar'], 'gold' => ['base' => 'split'], 'lobby' => ['base' => 'portal']],
    'hero' => ['jackpot' => ['base' => 'stacked', 'stage' => true], 'casino' => ['base' => 'split', 'stage' => true], 'vault' => ['base' => 'image-bg'], 'marquee' => ['base' => 'stacked', 'stage' => true], 'spotlight' => ['base' => 'stacked'], 'reels' => ['base' => 'split'], 'royal' => ['base' => 'split', 'stage' => true], 'arena' => ['base' => 'image-bg']],
    'features' => ['badges' => ['base' => 'cards'], 'glow' => ['base' => 'grid', 'stage' => true], 'tiles' => ['base' => 'cards'], 'ribbon' => ['base' => 'list'], 'panels' => ['base' => 'alternating'], 'medals' => ['base' => 'grid']],
    'gallery' => ['neon' => ['base' => 'grid', 'stage' => true], 'rail' => ['base' => 'strip'], 'polaroid' => ['base' => 'masonry'], 'framed' => ['base' => 'featured']],
    'specs' => ['neon' => ['base' => 'cards', 'stage' => true], 'chips' => ['base' => 'two-column'], 'scoreboard' => ['base' => 'table'], 'ribbon' => ['base' => 'list']],
    'faq' => ['glow' => ['base' => 'accordion'], 'neon' => ['base' => 'accordion', 'stage' => true], 'numbered' => ['base' => 'cards'], 'bordered' => ['base' => 'two-column']],
    'cta' => ['jackpot' => ['base' => 'banner', 'stage' => true], 'ribbon' => ['base' => 'split'], 'glow' => ['base' => 'card'], 'ticket' => ['base' => 'card'], 'stage' => ['base' => 'banner', 'stage' => true], 'vip' => ['base' => 'split']],
    'article_list' => ['neon' => ['base' => 'cards', 'stage' => true], 'rail' => ['base' => 'cards'], 'magazine' => ['base' => 'featured'], 'glossy' => ['base' => 'cards']],
    'footer' => ['neon' => ['base' => 'columns'], 'gold' => ['base' => 'dark'], 'slab' => ['base' => 'columns'], 'badges' => ['base' => 'centered']],
    'banner' => ['showcase' => ['base' => 'slider'], 'ribbon' => ['base' => 'wide'], 'framed' => ['base' => 'boxed'], 'glow' => ['base' => 'boxed'], 'stage' => ['base' => 'slider']],
    'card_grid' => ['lobby' => ['base' => 'tiles'], 'chips' => ['base' => 'compact'], 'poster' => ['base' => 'cards', 'stage' => true], 'ribbon' => ['base' => 'cards'], 'rail' => ['base' => 'tiles'], 'glossy' => ['base' => 'showcase']],
    'steps' => ['path' => ['base' => 'numbered'], 'arrows' => ['base' => 'cards'], 'pills' => ['base' => 'timeline'], 'ladder' => ['base' => 'timeline', 'stage' => true], 'glow' => ['base' => 'cards', 'stage' => true]],
    'stats' => ['jackpot' => ['base' => 'big', 'stage' => true], 'neon' => ['base' => 'strip'], 'rings' => ['base' => 'cards'], 'ticker' => ['base' => 'strip'], 'plaques' => ['base' => 'cards']],
    'notice' => ['neon' => ['base' => 'bar'], 'ticker' => ['base' => 'marquee'], 'pill' => ['base' => 'bar']],
    'download' => ['neon' => ['base' => 'card', 'stage' => true], 'glow' => ['base' => 'split'], 'phone' => ['base' => 'split', 'stage' => true], 'ribbon' => ['base' => 'banner', 'stage' => true]],
    'timeline' => ['neon' => ['base' => 'vertical', 'stage' => true], 'rail' => ['base' => 'compact'], 'glow' => ['base' => 'alternating']],
    'glossary' => ['chips' => ['base' => 'cards']],
    'checklist' => ['neon' => ['base' => 'cards', 'stage' => true], 'ticks' => ['base' => 'list'], 'glow' => ['base' => 'columns']],
    'callout' => ['neon' => ['base' => 'accent', 'stage' => true], 'ribbon' => ['base' => 'info']],
    'quote' => ['neon' => ['base' => 'card', 'stage' => true], 'glow' => ['base' => 'large']],
    'table' => ['neon' => ['base' => 'striped', 'stage' => true], 'scoreboard' => ['base' => 'striped'], 'glow' => ['base' => 'cards']],
    'link_grid' => ['neon' => ['base' => 'tiles', 'stage' => true], 'chips' => ['base' => 'pills']],
    'entry_list' => ['rail' => ['base' => 'carousel'], 'poster' => ['base' => 'cards'], 'glossy' => ['base' => 'cards'], 'ranked' => ['base' => 'list'], 'mosaic' => ['base' => 'featured'], 'chips' => ['base' => 'compact'], 'neon' => ['base' => 'cards', 'stage' => true]],
    'game_lobby' => ['neon' => ['base' => 'grid', 'stage' => true], 'poster' => ['base' => 'grid'], 'rail' => ['base' => 'compact'], 'glossy' => ['base' => 'grid']],
    'event_calendar' => ['neon' => ['base' => 'grid', 'stage' => true]],
];

$blocks = [
    'nav' => ['bar', 'centered', 'split', 'minimal', 'portal'],
    'hero' => ['split', 'centered', 'image-bg', 'stacked'],
    'features' => ['grid', 'list', 'cards', 'alternating'],
    'gallery' => ['grid', 'masonry', 'strip', 'featured'],
    'specs' => ['table', 'cards', 'two-column', 'list'],
    'faq' => ['accordion', 'two-column', 'cards', 'plain'],
    'cta' => ['banner', 'card', 'split', 'minimal'],
    'article_list' => ['cards', 'list', 'compact', 'featured'],
    'footer' => ['columns', 'simple', 'centered', 'dark'],
    'banner' => ['slider', 'wide', 'boxed'],
    'card_grid' => ['tiles', 'cards', 'compact', 'showcase'],
    'steps' => ['numbered', 'timeline', 'cards'],
    'stats' => ['strip', 'cards', 'big'],
    'notice' => ['bar', 'marquee'],
    'download' => ['banner', 'card', 'split'],
    'prose' => ['default', 'boxed', 'columns'],
    'contact' => ['default'],
    'legal' => ['default'],
    // Content blocks for pool sites (any page can combine them).
    'timeline' => ['vertical', 'alternating', 'compact'],
    'glossary' => ['list', 'columns', 'cards'],
    'checklist' => ['list', 'cards', 'columns'],
    'callout' => ['info', 'warning', 'accent'],
    'quote' => ['default', 'large', 'card'],
    'table' => ['striped', 'cards', 'plain'],
    'link_grid' => ['tiles', 'pills', 'list'],
    // Site data (games, promotions…) and their tools.
    'entry_list' => ['cards', 'list', 'compact', 'featured', 'carousel'],
    'game_lobby' => ['grid', 'compact'],
    'event_calendar' => ['list', 'grid'],
    'site_search' => ['default'],
    'cookie_settings' => ['default'],
    'html_sitemap' => ['default'],
];
foreach ($variants as $block => $list) {
    $blocks[$block] = array_merge($blocks[$block], array_keys($list));
}

// Visual systems: one per site, so every block on every page speaks the same visual language.
// A system fixes the skin and the variant families its blocks may use; a block whose family has
// no variant in the system uses its original layouts. Sites differ by system, not block by block.
$systems = [
    'clean' => ['skin' => 'classic', 'families' => []],
    'editorial' => ['skin' => 'editorial', 'families' => ['magazine', 'ribbon', 'bordered', 'numbered', 'framed', 'polaroid', 'ticks', 'rail', 'path']],
    'neon' => ['skin' => 'neon', 'families' => ['neon', 'glow', 'ticker', 'glass', 'spotlight']],
    'casino' => ['skin' => 'bold', 'families' => ['jackpot', 'casino', 'royal', 'vip', 'gold', 'scoreboard', 'plaques', 'medals', 'marquee', 'ticket', 'stage', 'vault']],
    'lobby' => ['skin' => 'lobby', 'families' => ['lobby', 'poster', 'glossy', 'rail', 'chips', 'tiles', 'showcase', 'mosaic', 'ranked', 'reels', 'phone']],
    'panels' => ['skin' => 'panels', 'families' => ['panels', 'badges', 'arrows', 'rings', 'framed', 'bordered', 'pill', 'arena', 'slab', 'ladder', 'pills']],
    // Slots brand-site systems: their skins (resources/theme/skins/{skin}.css) only decorate (type,
    // card frames, buttons, rules, backdrop), so they combine with any page skeleton.
    'luxe' => ['skin' => 'luxe', 'families' => ['gold', 'vip', 'royal', 'plaques', 'medals', 'vault', 'jackpot', 'framed', 'ticket']],
    'vegas' => ['skin' => 'vegas', 'families' => ['marquee', 'neon', 'glow', 'ticker', 'jackpot', 'stage', 'casino', 'spotlight', 'pill']],
    'dragon' => ['skin' => 'dragon', 'families' => ['royal', 'gold', 'badges', 'framed', 'ribbon', 'ticket', 'medals', 'path']],
    'candy' => ['skin' => 'candy', 'families' => ['glossy', 'chips', 'tiles', 'pills', 'rings', 'poster', 'phone', 'reels']],
    'olympus' => ['skin' => 'olympus', 'families' => ['framed', 'bordered', 'numbered', 'plaques', 'royal', 'scoreboard', 'magazine', 'ticks']],
    'cyber' => ['skin' => 'cyber', 'families' => ['neon', 'glass', 'ranked', 'scoreboard', 'arrows', 'slab', 'ladder', 'rail']],
    'velvet' => ['skin' => 'velvet', 'families' => ['vip', 'stage', 'royal', 'glow', 'spotlight', 'ticket', 'polaroid', 'mosaic']],
    'arcade' => ['skin' => 'arcade', 'families' => ['arena', 'slab', 'chips', 'ranked', 'badges', 'scoreboard', 'arrows', 'tiles']],
    'aztec' => ['skin' => 'aztec', 'families' => ['ribbon', 'ladder', 'path', 'medals', 'badges', 'bordered', 'mosaic', 'poster']],
    'aurora' => ['skin' => 'aurora', 'families' => ['glow', 'glass', 'glossy', 'showcase', 'rings', 'rail', 'spotlight', 'lobby']],
];

// Page skeletons ("frames"): one per site, on top of its system. A frame reshapes the whole page
// (header placement, hero scale, container width, how sections are laid out and separated, the
// footer) through resources/theme/frames/{name}.css, and limits the header, hero and footer to
// layouts that suit it, and fixes the phone skeleton (hero order, facts strip, footer, heading alignment,
// header and bottom bar) so phones differ by frame as much as desktops. Its sites take the neutral
// skin: the frame is their scheme. "classic" is no frame (the system's own skin).
$frames = [
    'classic' => [],
    'cinema' => ['nav' => ['bar', 'glass', 'neon'], 'hero' => ['image-bg', 'vault', 'arena'], 'footer' => ['dark', 'gold', 'slab'],
        'style.mhero' => ['text-top'], 'style.facts' => ['scroll'], 'style.mfooter' => ['center'], 'style.mheads' => ['left'], 'navigation.mobile_header' => ['standard'], 'navigation.mobile_bar_style' => ['flat', 'labels']],
    'palace' => ['nav' => ['centered', 'split', 'gold'], 'hero' => ['centered', 'stacked', 'spotlight'], 'footer' => ['centered', 'badges', 'gold'],
        'style.mhero' => ['card'], 'style.facts' => ['list'], 'style.mfooter' => ['center'], 'style.mheads' => ['center'], 'navigation.mobile_header' => ['center'], 'navigation.mobile_bar_style' => ['pill', 'raised']],
    'arena' => ['nav' => ['bar', 'neon', 'split'], 'hero' => ['split', 'casino', 'arena'], 'footer' => ['slab', 'neon', 'columns'],
        'style.mhero' => ['compact', 'text-top'], 'style.facts' => ['grid'], 'style.mfooter' => ['split'], 'style.mheads' => ['left'], 'navigation.mobile_header' => ['cta', 'standard'], 'navigation.mobile_bar_style' => ['cta', 'raised']],
    'lobby' => ['nav' => ['bar', 'neon', 'glass'], 'hero' => ['split', 'reels', 'jackpot'], 'footer' => ['columns', 'simple'],
        'style.mhero' => ['card'], 'style.facts' => ['scroll'], 'style.mfooter' => ['split'], 'style.mheads' => ['left'], 'navigation.mobile_header' => ['tabs', 'standard'], 'navigation.mobile_bar_style' => ['icons', 'raised']],
    'magazine' => ['nav' => ['portal', 'lobby'], 'hero' => ['split', 'image-bg'], 'footer' => ['columns', 'simple'],
        'style.mhero' => ['image-top'], 'style.facts' => ['list'], 'style.mfooter' => ['stack'], 'style.mheads' => ['left'], 'navigation.mobile_header' => ['standard', 'cta'], 'navigation.mobile_bar_style' => ['labels', 'flat']],
    'showcase' => ['nav' => ['bar', 'glass'], 'hero' => ['stacked', 'split', 'spotlight'], 'footer' => ['centered', 'columns'],
        'style.mhero' => ['card'], 'style.facts' => ['scroll'], 'style.mfooter' => ['center'], 'style.mheads' => ['center'], 'navigation.mobile_header' => ['center', 'standard'], 'navigation.mobile_bar_style' => ['pill']],
    'spotlight' => ['nav' => ['centered', 'bar', 'neon'], 'hero' => ['stacked', 'marquee', 'jackpot', 'spotlight'], 'footer' => ['neon', 'centered', 'dark'],
        'style.mhero' => ['text-top'], 'style.facts' => ['scroll', 'hidden'], 'style.mfooter' => ['center'], 'style.mheads' => ['center'], 'navigation.mobile_header' => ['center'], 'navigation.mobile_bar_style' => ['pill', 'icons']],
    'panorama' => ['nav' => ['bar', 'glass', 'split'], 'hero' => ['image-bg', 'vault', 'arena'], 'footer' => ['dark', 'slab'],
        'style.mhero' => ['image-top'], 'style.facts' => ['hidden', 'list'], 'style.mfooter' => ['stack'], 'style.mheads' => ['center'], 'navigation.mobile_header' => ['standard'], 'navigation.mobile_bar_style' => ['raised', 'flat']],
    'chapters' => ['nav' => ['bar', 'split', 'minimal'], 'hero' => ['split', 'centered'], 'footer' => ['columns', 'simple'],
        'style.mhero' => ['compact'], 'style.facts' => ['list'], 'style.mfooter' => ['stack'], 'style.mheads' => ['left'], 'navigation.mobile_header' => ['standard', 'cta'], 'navigation.mobile_bar_style' => ['labels']],
    'bento' => ['nav' => ['bar', 'glass'], 'hero' => ['split', 'casino', 'reels'], 'footer' => ['columns', 'badges'],
        'style.mhero' => ['card'], 'style.facts' => ['grid'], 'style.mfooter' => ['split'], 'style.mheads' => ['left'], 'navigation.mobile_header' => ['standard', 'cta'], 'navigation.mobile_bar_style' => ['raised', 'icons']],
    'jackpot' => ['nav' => ['centered', 'bar', 'gold'], 'hero' => ['stacked', 'jackpot', 'marquee', 'centered'], 'footer' => ['centered', 'gold', 'badges'],
        'style.mhero' => ['text-top'], 'style.facts' => ['grid'], 'style.mfooter' => ['center'], 'style.mheads' => ['center'], 'navigation.mobile_header' => ['center'], 'navigation.mobile_bar_style' => ['raised', 'cta']],
    'curtain' => ['nav' => ['centered', 'bar', 'neon'], 'hero' => ['stacked', 'spotlight', 'marquee', 'centered'], 'footer' => ['dark', 'neon', 'centered'],
        'style.mhero' => ['image-top'], 'style.facts' => ['hidden', 'scroll'], 'style.mfooter' => ['center'], 'style.mheads' => ['center'], 'navigation.mobile_header' => ['center', 'standard'], 'navigation.mobile_bar_style' => ['pill']],
    'duo' => ['nav' => ['bar', 'split', 'glass'], 'hero' => ['split', 'reels', 'casino'], 'footer' => ['columns', 'slab'],
        'style.mhero' => ['card'], 'style.facts' => ['list'], 'style.mfooter' => ['split'], 'style.mheads' => ['left'], 'navigation.mobile_header' => ['standard'], 'navigation.mobile_bar_style' => ['labels', 'raised']],
    'ticker' => ['nav' => ['bar', 'neon'], 'hero' => ['split', 'marquee', 'casino', 'jackpot'], 'footer' => ['neon', 'slab', 'columns'],
        'style.mhero' => ['compact'], 'style.facts' => ['scroll'], 'style.mfooter' => ['split'], 'style.mheads' => ['left'], 'navigation.mobile_header' => ['cta'], 'navigation.mobile_bar_style' => ['cta', 'icons']],
    'deck' => ['nav' => ['bar', 'glass', 'centered'], 'hero' => ['stacked', 'split', 'spotlight'], 'footer' => ['centered', 'badges'],
        'style.mhero' => ['card'], 'style.facts' => ['grid'], 'style.mfooter' => ['stack'], 'style.mheads' => ['center'], 'navigation.mobile_header' => ['standard'], 'navigation.mobile_bar_style' => ['raised', 'pill']],
    'reels' => ['nav' => ['bar', 'neon', 'split'], 'hero' => ['reels', 'split', 'casino'], 'footer' => ['columns', 'slab'],
        'style.mhero' => ['text-top'], 'style.facts' => ['scroll'], 'style.mfooter' => ['split'], 'style.mheads' => ['center'], 'navigation.mobile_header' => ['tabs', 'standard'], 'navigation.mobile_bar_style' => ['icons']],
    'vip' => ['nav' => ['centered', 'gold', 'split'], 'hero' => ['centered', 'royal', 'spotlight', 'stacked'], 'footer' => ['gold', 'centered'],
        'style.mhero' => ['image-top'], 'style.facts' => ['list'], 'style.mfooter' => ['center'], 'style.mheads' => ['center'], 'navigation.mobile_header' => ['center'], 'navigation.mobile_bar_style' => ['labels', 'flat']],
    'tournament' => ['nav' => ['bar', 'split', 'neon'], 'hero' => ['split', 'arena', 'casino'], 'footer' => ['slab', 'columns', 'neon'],
        'style.mhero' => ['compact'], 'style.facts' => ['grid'], 'style.mfooter' => ['stack'], 'style.mheads' => ['left'], 'navigation.mobile_header' => ['cta', 'standard'], 'navigation.mobile_bar_style' => ['cta']],
    'poster' => ['nav' => ['bar', 'glass', 'minimal'], 'hero' => ['image-bg', 'vault', 'stacked', 'split'], 'footer' => ['dark', 'simple'],
        'style.mhero' => ['image-top'], 'style.facts' => ['hidden', 'scroll'], 'style.mfooter' => ['stack'], 'style.mheads' => ['left'], 'navigation.mobile_header' => ['standard'], 'navigation.mobile_bar_style' => ['flat', 'raised']],
    'arch' => ['nav' => ['centered', 'bar', 'split'], 'hero' => ['split', 'stacked', 'centered'], 'footer' => ['centered', 'columns'],
        'style.mhero' => ['image-top', 'card'], 'style.facts' => ['list'], 'style.mfooter' => ['center'], 'style.mheads' => ['center'], 'navigation.mobile_header' => ['center', 'standard'], 'navigation.mobile_bar_style' => ['pill', 'raised']],
];

return [
    'systems' => $systems,
    'frames' => $frames,

    // A page shows at most this many blocks on the dark "stage" colour.
    'max_stage_blocks' => 1,

    // Block => available layout variants (resources/views/theme/blocks/{block}.blade.php)
    'blocks' => $blocks,
    'variants' => $variants,

    // Blocks every page gets from the layout, not from the page composition.
    'layout_blocks' => ['nav', 'footer'],

    // Self-hosted variable fonts (public/theme/fonts, latin subset, SIL OFL 1.1 from Fontsource), weights 300-800.
    'fonts' => [
        'system' => ['family' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', 'file' => null],
        'inter' => ['family' => '"Inter", system-ui, sans-serif', 'file' => 'inter-latin.woff2'],
        'montserrat' => ['family' => '"Montserrat", system-ui, sans-serif', 'file' => 'montserrat-latin.woff2'],
        'nunito' => ['family' => '"Nunito", system-ui, sans-serif', 'file' => 'nunito-latin.woff2'],
        'roboto-slab' => ['family' => '"Roboto Slab", Georgia, serif', 'file' => 'roboto-slab-latin.woff2'],
        'playfair' => ['family' => '"Playfair Display", Georgia, serif', 'file' => 'playfair-display-latin.woff2'],
        'space-grotesk' => ['family' => '"Space Grotesk", system-ui, sans-serif', 'file' => 'space-grotesk-latin.woff2'],
        'outfit' => ['family' => '"Outfit", system-ui, sans-serif', 'file' => 'outfit-latin.woff2'],
        'plus-jakarta' => ['family' => '"Plus Jakarta Sans", system-ui, sans-serif', 'file' => 'plus-jakarta-sans-latin.woff2'],
        'sora' => ['family' => '"Sora", system-ui, sans-serif', 'file' => 'sora-latin.woff2'],
        'manrope' => ['family' => '"Manrope", system-ui, sans-serif', 'file' => 'manrope-latin.woff2'],
        'dm-sans' => ['family' => '"DM Sans", system-ui, sans-serif', 'file' => 'dm-sans-latin.woff2'],
        'oswald' => ['family' => '"Oswald", "Arial Narrow", sans-serif', 'file' => 'oswald-latin.woff2'],
        'exo' => ['family' => '"Exo 2", system-ui, sans-serif', 'file' => 'exo-2-latin.woff2'],
        'rubik' => ['family' => '"Rubik", system-ui, sans-serif', 'file' => 'rubik-latin.woff2'],
    ],

    // Retired font names still found in stored designs.
    'font_aliases' => ['poppins' => 'outfit'],

    // Global style switches, rendered as body classes (st-{key}-{value}).
    'styles' => [
        'background' => ['plain', 'glow', 'grid', 'dots', 'gradient'],
        'cards' => ['bordered', 'flat', 'elevated', 'glass'],
        'buttons' => ['solid', 'gradient', 'outline'],
        'headings' => ['normal', 'uppercase', 'accent'],
        // Whole-site schemes (header colour, section rhythm, heading treatment, card frame): the biggest visible difference between sites.
        'skin' => ['classic', 'bold', 'editorial', 'lobby', 'panels', 'neon', 'luxe', 'vegas', 'dragon', 'candy', 'olympus', 'cyber', 'velvet', 'arcade', 'aztec', 'aurora'],
        // Phone skeleton: hero order, the facts strip under it, footer layout, section heading alignment.
        'mhero' => ['image-top', 'text-top', 'card', 'compact'],
        'facts' => ['grid', 'scroll', 'list', 'hidden'],
        'mfooter' => ['stack', 'center', 'split'],
        'mheads' => ['center', 'left'],
        // Phone parts drawn on their own, whatever the frame: hero element order, footer links, cookie notice.
        'mhorder' => ['classic', 'points-top', 'cta-top', 'lead-last'],
        'mlinks' => ['fold', 'open', 'inline'],
        'mcookie' => ['card', 'bar', 'top', 'compact'],
    ],

    'radius' => ['none' => '0', 'sm' => '4px', 'md' => '8px', 'lg' => '16px', 'pill' => '999px'],
    'density' => ['compact' => '0.75', 'normal' => '1', 'relaxed' => '1.3'],
    'shadow' => [
        'none' => 'none',
        'soft' => '0 2px 8px rgba(0,0,0,.08)',
        'medium' => '0 6px 20px rgba(0,0,0,.12)',
        'strong' => '0 12px 32px rgba(0,0,0,.2)',
    ],

    'min_contrast' => 4.5,
    'custom_css_max_bytes' => 16384,
    'articles_per_page' => 12,
];
