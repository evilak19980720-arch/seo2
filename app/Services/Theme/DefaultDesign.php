<?php

namespace App\Services\Theme;

/**
 * Deterministic, seed-based design config. Used when no AI provider is
 * configured or AI output keeps failing validation, so every site still
 * gets a distinct, valid look.
 */
class DefaultDesign
{
    public const PALETTES = [
        ['#2563eb', '#0f172a', '#f59e0b', '#ffffff', '#f1f5f9', '#0f172a', '#475569'],
        ['#16a34a', '#052e16', '#facc15', '#ffffff', '#f0fdf4', '#111827', '#4b5563'],
        ['#9333ea', '#1e1b4b', '#ec4899', '#fdfcff', '#f5f3ff', '#1f1235', '#5b5375'],
        ['#dc2626', '#1c1917', '#f97316', '#ffffff', '#fef2f2', '#1c1917', '#57534e'],
        ['#0891b2', '#083344', '#22c55e', '#ffffff', '#ecfeff', '#0b1f2a', '#475569'],
        ['#facc15', '#18181b', '#38bdf8', '#0b0b0f', '#18181b', '#f4f4f5', '#a1a1aa'],
        ['#ea580c', '#431407', '#0ea5e9', '#fffaf5', '#fff1e6', '#1f130b', '#5c4a3d'],
        ['#4f46e5', '#111827', '#10b981', '#0f172a', '#1e293b', '#f8fafc', '#cbd5e1'],
    ];

    public static function make(int|string $seed, array $siteTypeConfig): array
    {
        $rand = new \Random\Randomizer(new \Random\Engine\Mt19937(crc32((string) $seed)));
        $pick = fn (array $items) => $items[$rand->getInt(0, count($items) - 1)];

        $palette = $pick(self::PALETTES);
        $fonts = array_keys(config('theme.fonts'));
        $blocks = config('theme.blocks');

        $pages = [];
        foreach ($siteTypeConfig['pages'] + DesignConfigValidator::SYSTEM_PAGES as $type => $page) {
            $list = $page['blocks'] ?? $page;
            $pages[$type] = array_map(fn ($b) => ['block' => $b, 'variant' => $pick($blocks[$b])], $list);
        }

        return [
            'colors' => array_combine(DesignConfigValidator::COLOR_KEYS, $palette),
            'fonts' => ['heading' => $pick($fonts), 'body' => $pick(['system', 'inter', 'nunito'])],
            'radius' => $pick(array_keys(config('theme.radius'))),
            'density' => $pick(array_keys(config('theme.density'))),
            'shadow' => $pick(array_keys(config('theme.shadow'))),
            'nav' => $pick($blocks['nav']),
            'footer' => $pick($blocks['footer']),
            'pages' => $pages,
        ];
    }
}
