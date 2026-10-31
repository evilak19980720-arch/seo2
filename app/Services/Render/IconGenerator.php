<?php

namespace App\Services\Render;

use App\Models\Site;
use App\Services\Theme\Color;
use App\Support\Media;

/**
 * favicon.ico, apple-touch-icon (180), 192/512 PNG icons and the web
 * manifest, generated from the site's logo (or a letter mark fallback).
 */
class IconGenerator
{
    public static function generate(Site $site, string $dir, array $colors): void
    {
        $source = null;
        if ($site->logo_path) {
            $binary = Media::get($site->logo_path) ?? (is_file($site->logo_path) ? file_get_contents($site->logo_path) : null);
            $source = $binary ? @imagecreatefromstring($binary) : null;
        }
        $source ??= self::letterMark($site->name, $colors['primary'], $colors['on_primary']);

        foreach (['apple-touch-icon.png' => 180, 'icon-192.png' => 192, 'icon-512.png' => 512] as $file => $size) {
            imagepng(self::square($source, $size, $file === 'apple-touch-icon.png' ? $colors['background'] : null), "{$dir}/{$file}");
        }

        // ICO container with embedded 32px and 48px PNGs.
        $pngs = [];
        foreach ([32, 48] as $size) {
            ob_start();
            imagepng(self::square($source, $size));
            $pngs[$size] = ob_get_clean();
        }
        $ico = pack('vvv', 0, 1, count($pngs));
        $offset = 6 + 16 * count($pngs);
        $data = '';
        foreach ($pngs as $size => $png) {
            $ico .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($png), $offset);
            $offset += strlen($png);
            $data .= $png;
        }
        file_put_contents("{$dir}/favicon.ico", $ico.$data);

        file_put_contents("{$dir}/site.webmanifest", json_encode([
            'name' => $site->name,
            'short_name' => mb_substr($site->name, 0, 12),
            'lang' => $site->locale,
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => $colors['background'],
            'theme_color' => $colors['primary'],
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    protected static function square(\GdImage $src, int $size, ?string $background = null): \GdImage
    {
        $img = imagecreatetruecolor($size, $size);
        imagesavealpha($img, true);
        if ($background) {
            [$r, $g, $b] = Color::rgb($background);
            imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
        } else {
            imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $pad = $background ? (int) round($size * 0.1) : 0;
        $scale = min(($size - 2 * $pad) / $w, ($size - 2 * $pad) / $h);
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);
        imagecopyresampled($img, $src, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);

        return $img;
    }

    protected static function letterMark(string $name, string $bg, string $fg): \GdImage
    {
        $size = 512;
        $img = imagecreatetruecolor($size, $size);
        [$r, $g, $b] = Color::rgb($bg);
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
        [$r, $g, $b] = Color::rgb($fg);
        $color = imagecolorallocate($img, $r, $g, $b);
        $letter = mb_strtoupper(mb_substr(trim($name) ?: 'S', 0, 1));
        // Built-in GD font is tiny; draw it on a small canvas and upscale.
        $small = imagecreatetruecolor(16, 16);
        imagefill($small, 0, 0, imagecolorallocate($small, ...Color::rgb($bg)));
        imagestring($small, 5, 4, 0, preg_match('/^[A-Z0-9]$/', $letter) ? $letter : 'S', imagecolorallocate($small, ...Color::rgb($fg)));
        imagecopyresized($img, $small, 64, 64, 0, 0, 384, 384, 16, 16);
        unset($color);

        return $img;
    }
}
