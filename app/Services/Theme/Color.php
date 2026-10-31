<?php

namespace App\Services\Theme;

/** WCAG colour helpers used by the design-config validator. */
class Color
{
    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) === 1;
    }

    public static function normalize(string $hex): string
    {
        $hex = ltrim(strtolower($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }

    /** @return array{0:int,1:int,2:int} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim(self::normalize($hex), '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function toHex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...array_map(fn ($c) => max(0, min(255, (int) round($c))), $rgb));
    }

    public static function luminance(string $hex): float
    {
        $channels = array_map(function ($c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function mix(string $a, string $b, float $weight): string
    {
        $ra = self::rgb($a);
        $rb = self::rgb($b);

        return self::toHex([
            $ra[0] + ($rb[0] - $ra[0]) * $weight,
            $ra[1] + ($rb[1] - $ra[1]) * $weight,
            $ra[2] + ($rb[2] - $ra[2]) * $weight,
        ]);
    }

    /**
     * Move $fg towards black or white until it reaches $min contrast on $bg.
     */
    public static function ensureContrast(string $fg, string $bg, float $min = 4.5): string
    {
        if (self::contrast($fg, $bg) >= $min) {
            return $fg;
        }
        $target = self::luminance($bg) > 0.4 ? '#000000' : '#ffffff';
        for ($w = 0.1; $w <= 1.0001; $w += 0.1) {
            $candidate = self::mix($fg, $target, $w);
            if (self::contrast($candidate, $bg) >= $min) {
                return $candidate;
            }
        }

        return $target;
    }

    public static function readableOn(string $bg): string
    {
        return self::contrast('#ffffff', $bg) >= self::contrast('#111111', $bg) ? '#ffffff' : '#111111';
    }
}
