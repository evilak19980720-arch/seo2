<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Support\Media;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Downloads the reference site's content images into the media disk (OSS
 * when configured) and appends them to the brand profile's media, after the
 * images uploaded by hand, so banners, card grids and galleries have
 * pictures. Only runs when the site's "import reference images" switch is on.
 */
class ReferenceImageImporter
{
    public const MAX = 12;

    /**
     * @param  array<int, array{url: string, alt: string}>  $images
     * @return int number of new images
     */
    public function import(Site $site, array $images): int
    {
        $profile = $site->brandProfile;
        if (! $profile || $images === []) {
            return 0;
        }

        $media = $profile->media ?? [];
        $known = collect($media)->pluck('source_url')->filter()->all();
        $imported = collect($media)->where('source', 'reference')->count();
        $hashes = [];
        $added = 0;

        foreach ($images as $image) {
            if ($imported + $added >= self::MAX) {
                break;
            }
            if (in_array($image['url'], $known, true)) {
                continue;
            }
            $binary = $this->download($image['url']);
            $info = $binary !== null ? @getimagesizefromstring($binary) : false;
            // Content-sized raster images only (no icons, badges or banners a few pixels high).
            if (! $info || $info[0] < 320 || $info[1] < 180 || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                continue;
            }
            $hash = md5($binary);
            if (isset($hashes[$hash])) {
                continue;
            }
            $hashes[$hash] = true;

            $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
            $path = "brand/ref-{$site->id}/{$hash}.{$ext}";
            try {
                Storage::disk(Media::DISK)->put($path, $binary);
            } catch (Throwable) {
                continue;
            }
            $media[] = ['type' => 'image', 'path' => $path, 'alt' => $site->product_name, 'caption' => null, 'source' => 'reference', 'source_url' => $image['url']];
            $added++;
        }

        if ($added > 0) {
            $profile->update(['media' => $media]);
        }

        return $added;
    }

    protected function download(string $url): ?string
    {
        try {
            $res = Http::timeout(15)->connectTimeout(6)->withHeaders(['Accept' => 'image/avif,image/webp,image/*;q=0.8'])->get($url);
            $type = strtolower((string) $res->header('Content-Type'));

            return $res->successful() && str_starts_with($type, 'image/') && strlen($res->body()) <= 8_000_000 ? $res->body() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
