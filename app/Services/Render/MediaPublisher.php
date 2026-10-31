<?php

namespace App\Services\Render;

use App\Models\Site;
use App\Support\Media;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copies brand images into a site's static output as WebP (+ AVIF when GD
 * supports it), resized to a max width, and reports real dimensions so
 * templates can write width/height and avoid layout shift. With object
 * storage configured, the converted files are uploaded there (public, long
 * cache) and pages point at the OSS / CDN URL; otherwise, or when the
 * bucket's files cannot be read publicly, they are served from the site.
 */
class MediaPublisher
{
    /** @var array<string, array|null> */
    protected array $cache = [];

    /** Whether uploaded files are publicly readable; checked once per build. */
    protected ?bool $remoteOk = null;

    /** @var array<string, string>|null local file name => public URL */
    protected ?array $manifest = null;

    public function __construct(protected string $siteDir, protected ?string $host = null) {}

    /** Absolute URL of a published image (local paths get the site's base URL). */
    public static function absolute(Site $site, string $src): string
    {
        return preg_match('#^https?://#i', $src) ? $src : $site->baseUrl().$src;
    }

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

        $local = $this->convert($source, $maxWidth);

        return $this->cache[$key] = $local ? $this->remote($local) : null;
    }

    /** Swap local /media/ paths for object-storage URLs when possible. */
    protected function remote(array $image): array
    {
        if ($this->host === null || $this->remoteOk === false || ! Media::configured()) {
            return $image;
        }
        $urls = [];
        foreach (['src', 'avif'] as $field) {
            if ($image[$field] === null) {
                continue;
            }
            $url = $this->upload(basename($image[$field]));
            if ($url === null) {
                return $image;
            }
            $urls[$field] = $url;
        }

        return $urls + $image;
    }

    protected function upload(string $file): ?string
    {
        $this->manifest ??= is_file($m = "{$this->siteDir}/media/.oss.json") ? (json_decode((string) file_get_contents($m), true) ?: []) : [];
        $key = 'sites/'.$this->host.'/media/'.$file;
        if (isset($this->manifest[$file]) && $this->remoteOk !== null) {
            return $this->manifest[$file];
        }
        try {
            $disk = Storage::disk(Media::DISK);
            $url = $this->manifest[$file] ?? null;
            if ($url === null) {
                $ok = $disk->put($key, (string) file_get_contents("{$this->siteDir}/media/{$file}"), [
                    'visibility' => 'public',
                    'mimetype' => str_ends_with($file, '.avif') ? 'image/avif' : 'image/webp',
                    'CacheControl' => 'public, max-age=31536000, immutable',
                ]);
                $url = $ok ? $disk->url($key) : null;
            }
            // The first file of a build proves the bucket serves files publicly.
            $this->remoteOk ??= $url !== null && Http::timeout(10)->head($url)->successful();
        } catch (Throwable) {
            $this->remoteOk = false;
        }
        if (! $this->remoteOk || $url === null) {
            $this->remoteOk = false;

            return null;
        }
        $this->manifest[$file] = $url;
        file_put_contents("{$this->siteDir}/media/.oss.json", json_encode($this->manifest, JSON_UNESCAPED_SLASHES));

        return $url;
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
