<?php

/*
| Theme engine whitelist. The AI design config may only reference what is
| listed here; DesignConfigValidator rejects or repairs everything else.
*/

return [
    // Block => available layout variants (resources/views/theme/blocks/{block}/{variant}.blade.php)
    'blocks' => [
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
    ],

    // Blocks every page gets from the layout, not from the page composition.
    'layout_blocks' => ['nav', 'footer'],

    // Self-hosted fonts (public/fonts/{slug}/), latin subset only.
    'fonts' => [
        'system' => ['family' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', 'file' => null],
        'inter' => ['family' => '"Inter", system-ui, sans-serif', 'file' => 'inter-latin.woff2'],
        'poppins' => ['family' => '"Poppins", system-ui, sans-serif', 'file' => 'poppins-latin.woff2'],
        'montserrat' => ['family' => '"Montserrat", system-ui, sans-serif', 'file' => 'montserrat-latin.woff2'],
        'nunito' => ['family' => '"Nunito", system-ui, sans-serif', 'file' => 'nunito-latin.woff2'],
        'roboto-slab' => ['family' => '"Roboto Slab", Georgia, serif', 'file' => 'roboto-slab-latin.woff2'],
        'playfair' => ['family' => '"Playfair Display", Georgia, serif', 'file' => 'playfair-latin.woff2'],
        'space-grotesk' => ['family' => '"Space Grotesk", system-ui, sans-serif', 'file' => 'space-grotesk-latin.woff2'],
    ],

    // Global style switches, rendered as body classes (st-{key}-{value}).
    'styles' => [
        'background' => ['plain', 'glow', 'grid', 'dots', 'gradient'],
        'cards' => ['bordered', 'flat', 'elevated', 'glass'],
        'buttons' => ['solid', 'gradient', 'outline'],
        'headings' => ['normal', 'uppercase', 'accent'],
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
