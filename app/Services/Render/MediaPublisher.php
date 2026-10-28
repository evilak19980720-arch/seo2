<?php

namespace App\Services\Render;

use App\Support\Media;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Copies brand images into a site's static output as WebP (+ AVIF when GD
 * supports it), resized to a max width, and reports real dimensions so
 * templates can write width/height and avoid layout shift.
 */
class MediaPublisher
{
    /** @var array<string, array|null> */
    protected array $cache = [];

    public function __construct(protected string $siteDir) {}

    /**
     * @return array{src: string, avif: ?string, width: int, height: int}|null
     */
    public function publish(?string $source, int $maxWidth = 1600): ?array
    {
        if (! $source) {
            return null;
        }
        $key = $source.'@'.$maxWidth;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->convert($source, $maxWidth);
    }

    protected function convert(string $source, int $maxWidth): ?array
    {
        $name = substr(md5($source), 0, 16).'-'.$maxWidth;
        $webpPath = "{$this->siteDir}/media/{$name}.webp";
        $avifPath = "{$this->siteDir}/media/{$name}.avif";

        if (is_file($webpPath) && ($size = @getimagesize($webpPath))) {
            return ['src' => "/media/{$name}.webp", 'avif' => is_file($avifPath) ? "/media/{$name}.avif" : null, 'width' => $size[0], 'height' => $size[1]];
        }

        $binary = $this->read($source);
        if ($binary === null || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($binary);
        if (! $image) {
            return null;
        }

        $w = imagesx($image);
        $h = imagesy($image);
        if ($w > $maxWidth) {
            $scaled = imagescale($image, $maxWidth, (int) round($h * $maxWidth / $w), IMG_BICUBIC);
            imagedestroy($image);
            $image = $scaled;
            [$w, $h] = [imagesx($image), imagesy($image)];
        }
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        @mkdir(dirname($webpPath), 0775, true);
        imagewebp($image, $webpPath, 80);
        $avif = null;
        if (function_exists('imageavif') && (imagetypes() & IMG_AVIF)) {
            try {
                if (@imageavif($image, $avifPath, 55, 6)) {
                    $avif = "/media/{$name}.avif";
                }
            } catch (Throwable) {
                $avif = null;
            }
        }
        imagedestroy($image);

        return ['src' => "/media/{$name}.webp", 'avif' => $avif, 'width' => $w, 'height' => $h];
    }

    protected function read(string $source): ?string
    {
        try {
            if (preg_match('#^https?://#i', $source)) {
                $response = Http::timeout(20)->get($source);

                return $response->successful() ? $response->body() : null;
            }
            if (is_file($source)) {
                return file_get_contents($source);
            }

            return Media::get($source);
        } catch (Throwable) {
            return null;
        }
    }
}
