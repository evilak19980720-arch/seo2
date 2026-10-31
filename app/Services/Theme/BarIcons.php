<?php

namespace App\Services\Theme;

/**
 * Icons of the phone bottom bar: each page gets an icon that says what it is
 * (games, promotions, help…), drawn in one of a few shapes per topic, so
 * sites differ. Line icons on a 24px grid; the bar's style decides how they
 * are painted (line, bold, duotone, on a chip, with a dot).
 */
class BarIcons
{
    /** Shapes per topic (inner SVG of a 24×24 view box). */
    public const GLYPHS = [
        'home' => [
            '<path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>',
            '<path d="M4 10.5L12 4l8 6.5V20H4z"/><path d="M10 20v-5h4v5"/>',
            '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M2.5 11.5L12 3.5l9.5 8"/><path d="M12 14v2"/>',
        ],
        'games' => [
            '<path d="M6 8h12a4 4 0 0 1 4 4v1a4 4 0 0 1-7 2.6l-1-.6h-4l-1 .6A4 4 0 0 1 2 13v-1a4 4 0 0 1 4-4z"/><path d="M7 10.5v3M5.5 12h3"/><path d="M16 11h.01M18 13h.01"/>',
            '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            '<rect x="3" y="3" width="18" height="18" rx="4"/><path d="M8 8h.01M16 16h.01M12 12h.01M16 8h.01M8 16h.01"/>',
        ],
        'slots' => [
            '<rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M8.7 5v14M15.3 5v14"/><path d="M4.5 12h2M11 12h2M17.5 12h2"/>',
            '<circle cx="7" cy="17" r="3"/><circle cx="17" cy="17" r="3"/><path d="M7 14c1-5 4-8 9-10M17 14c-.6-4-.8-7-1-10"/>',
            '<path d="M7 4h10M9 4c0 6 6 6 6 12v4H9v-4"/><path d="M12 20v-4"/>',
        ],
        'providers' => [
            '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
            '<path d="M4 21V5l8-2v18M12 9h8v12M3 21h18"/><path d="M8 8h.01M8 12h.01M8 16h.01M16 13h.01M16 17h.01"/>',
            '<rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"/>',
        ],
        'promo' => [
            '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v8h14v-8M12 8v12"/><path d="M12 8C10 4 6 4 6 6.5S10 8 12 8zm0 0c2-4 6-4 6-1.5S14 8 12 8z"/>',
            '<path d="M3 12V4h8l10 10-8 8z"/><path d="M7.5 8.5h.01"/>',
            '<circle cx="12" cy="12" r="9"/><path d="M9 15l6-6M9 9.5h.01M15 14.5h.01"/>',
        ],
        'events' => [
            '<path d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 6H5a3 3 0 0 0 3 4M16 6h3a3 3 0 0 1-3 4M12 13v4M8 21h8M9 17h6"/>',
            '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M8 14h3v3H8z"/>',
            '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
        ],
        'notice' => [
            '<path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
            '<path d="M3 10v4h4l8 5V5l-8 5z"/><path d="M18 9a4 4 0 0 1 0 6"/>',
            '<circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16.5h.01"/>',
        ],
        'news' => [
            '<path d="M4 5h13v14H6a2 2 0 0 1-2-2z"/><path d="M17 9h3v8a2 2 0 0 1-2 2"/><path d="M8 9h5M8 13h5"/>',
            '<path d="M4 5a2 2 0 0 1 2-2h12v18H6a2 2 0 0 1-2-2z"/><path d="M8 7h6M8 11h6"/>',
            '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        ],
        'help' => [
            '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7M12 17h.01"/>',
            '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="13" width="4" height="6" rx="1.5"/><rect x="17" y="13" width="4" height="6" rx="1.5"/><path d="M19 19a3 3 0 0 1-3 2h-3"/>',
            '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/>',
        ],
        'guide' => [
            '<path d="M4 5a2 2 0 0 1 2-2h12v18H6a2 2 0 0 1-2-2z"/><path d="M8 7h6M8 11h6"/>',
            '<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c3 2 9 2 12 0v-5M22 9v5"/>',
            '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-4 10.5c.7.7 1 1.5 1 2.5h6c0-1 .3-1.8 1-2.5A6 6 0 0 0 12 3z"/>',
        ],
        'app' => [
            '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/>',
            '<path d="M12 4v11m0 0l-4.5-4.5M12 15l4.5-4.5M5 20h14"/>',
            '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M12 7v7m0 0l-3-3m3 3l3-3"/>',
        ],
        'about' => [
            '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5h.01"/>',
            '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
            '<path d="M5 21V4l7 3 7-3v17"/><path d="M5 12l7 3 7-3"/>',
        ],
        'community' => [
            '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 5a3 3 0 0 1 0 6M18 14a5 5 0 0 1 3 6"/>',
            '<path d="M4 5h11v8H8l-4 3z"/><path d="M15 9h5v8l-3-2h-6v-2"/>',
            '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        ],
        'more' => [
            '<path d="M4 6h16M4 12h16M4 18h10"/>',
            '<path d="M12 3l2 6 6 2-6 2-2 6-2-6-6-2 6-2z"/>',
            '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
        ],
    ];

    /** Page type → topic. Page types not listed fall back to words in the type name, then "more". */
    public const TOPICS = [
        'home' => 'home', 'games' => 'games', 'game_lobby' => 'games', 'slot_types' => 'slots', 'themes' => 'slots',
        'providers' => 'providers', 'promotions' => 'promo', 'bonus_terms' => 'promo', 'events' => 'events',
        'notices' => 'notice', 'blog' => 'news', 'news' => 'news', 'help' => 'help', 'faq' => 'help', 'contact' => 'help',
        'guide' => 'guide', 'academy' => 'guide', 'download' => 'app', 'app' => 'app', 'about' => 'about',
        'brand_story' => 'about', 'company' => 'about', 'security' => 'about', 'fairness' => 'about', 'responsible' => 'about',
        'community' => 'community', 'official_channels' => 'community', 'press' => 'news', 'product' => 'games', 'media' => 'slots',
    ];

    public static function topic(?string $pageType): string
    {
        if ($pageType === null) {
            return 'more';
        }
        if (isset(self::TOPICS[$pageType])) {
            return self::TOPICS[$pageType];
        }
        foreach (['game' => 'games', 'promo' => 'promo', 'bonus' => 'promo', 'event' => 'events', 'news' => 'news', 'blog' => 'news',
            'help' => 'help', 'faq' => 'help', 'guide' => 'guide', 'download' => 'app', 'app' => 'app', 'about' => 'about'] as $word => $topic) {
            if (str_contains($pageType, $word)) {
                return $topic;
            }
        }

        return 'more';
    }

    /** The icon for a page type on one site: the site's seed picks which shape each topic gets. */
    public static function svg(?string $pageType, string $seed): string
    {
        $topic = self::topic($pageType);
        $shapes = self::GLYPHS[$topic];

        return $shapes[hexdec(substr(md5($seed.'|'.$topic), 0, 8)) % count($shapes)];
    }
}
