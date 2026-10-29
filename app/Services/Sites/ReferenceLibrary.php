<?php

namespace App\Services\Sites;

use App\Models\ReferenceItem;
use App\Models\Site;
use App\Support\Media;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything pictured on the reference site, kept the way it was found:
 * each card's picture together with its name, the text next to it, its
 * link and the text of its detail page (a promotion's terms, a game's
 * description). Every picture is uploaded to the media disk (OSS) once.
 * Games, promotions and other items later take their picture from here by
 * name, so a picture always stays with the item it showed on the reference.
 */
class ReferenceLibrary
{
    /** Pictures fetched at the same time. */
    protected const PARALLEL = 8;

    /** @var array{cards: int, images: int, uploaded: int, failed: int} */
    public array $stats = ['cards' => 0, 'images' => 0, 'uploaded' => 0, 'failed' => 0];

    /**
     * @param  array<int, array>  $pages  reference pages, home first (ReferenceAnalyzer::analyze + crawl)
     */
    public function capture(Site $site, array $pages): array
    {
        $this->stats = ['cards' => 0, 'images' => 0, 'uploaded' => 0, 'failed' => 0];
        $found = $this->collect($pages);

        foreach ($found as $hash => $row) {
            $item = ReferenceItem::firstOrNew(['site_id' => $site->id, 'image_hash' => $hash]);
            // A named card wins over a bare picture; the first name found is kept.
            if (! $item->exists || ($row['kind'] === 'card' && $item->kind !== 'card')) {
                $item->fill($row);
            } else {
                $item->fill(array_filter(['text' => $item->text ?: $row['text'], 'detail' => $item->detail ?: $row['detail'], 'link' => $item->link ?: $row['link']]));
            }
            $item->save();
        }

        $this->upload($site->referenceItems()->whereNull('image_path')->get());
        $this->stats['cards'] = $site->referenceItems()->where('kind', 'card')->count();
        $this->stats['images'] = $site->referenceItems()->count();

        return $this->stats;
    }

    /**
     * Cards and pictures of all pages, keyed by picture URL hash; a detail
     * page's text joins the card that links to it.
     *
     * @return array<string, array>
     */
    public function collect(array $pages): array
    {
        $details = [];
        foreach ($pages as $page) {
            if (! empty($page['detail'])) {
                $details[rtrim((string) $page['url'], '/')] = Str::limit(trim((string) ($page['content'] ?? '')), 3000, '');
            }
        }

        $found = [];
        foreach ($pages as $page) {
            foreach ($page['cards'] ?? [] as $card) {
                $url = trim((string) ($card['image'] ?? ''));
                $name = Str::limit(trim((string) ($card['name'] ?? '')), 200, '');
                if (! preg_match('#^https?://#i', $url) || isset($found[md5($url)]) && $found[md5($url)]['kind'] === 'card') {
                    continue;
                }
                $link = trim((string) ($card['url'] ?? ''));
                $found[md5($url)] = [
                    'kind' => $name !== '' ? 'card' : 'image',
                    'name' => $name ?: null,
                    'text' => filled($card['text'] ?? null) ? Str::limit(trim($card['text']), 2000, '') : null,
                    'detail' => $details[rtrim($link, '/')] ?? null,
                    'link' => $link !== '' ? Str::limit($link, 1000, '') : null,
                    'page_url' => Str::limit((string) ($page['url'] ?? ''), 1000, ''),
                    'image_url' => Str::limit($url, 1000, ''),
                ];
            }
            foreach ($page['images'] ?? [] as $image) {
                $url = trim((string) ($image['url'] ?? ''));
                if (! preg_match('#^https?://#i', $url) || isset($found[md5($url)])) {
                    continue;
                }
                $found[md5($url)] = [
                    'kind' => 'image', 'name' => filled($image['alt'] ?? null) ? Str::limit(trim($image['alt']), 200, '') : null, 'text' => null, 'detail' => null, 'link' => null,
                    'page_url' => Str::limit((string) ($page['url'] ?? ''), 1000, ''), 'image_url' => Str::limit($url, 1000, ''),
                ];
            }
        }

        return $found;
    }

    /** Download (several at a time) and store each picture on the media disk. */
    protected function upload(Collection $items): void
    {
        foreach ($items->chunk(self::PARALLEL) as $chunk) {
            try {
                $responses = Http::pool(fn (Pool $pool) => $chunk->map(fn (ReferenceItem $item) => $pool->as((string) $item->id)
                    ->timeout(20)->connectTimeout(6)->withHeaders(['Accept' => 'image/avif,image/webp,image/*;q=0.8'])->get($item->image_url))->all());
            } catch (Throwable) {
                $responses = [];
            }
            foreach ($chunk as $item) {
                $res = $responses[(string) $item->id] ?? null;
                $binary = $res instanceof Response && $res->successful() && strlen($res->body()) <= 10_000_000 ? $res->body() : null;
                $info = $binary !== null ? @getimagesizefromstring($binary) : false;
                $ext = $info ? ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2]] ?? null) : null;
                // Raster pictures of any size but tracking pixels.
                if (! $ext || $info[0] < 16 || $info[1] < 16) {
                    $this->stats['failed']++;

                    continue;
                }
                $path = "brand/ref-{$item->site_id}/".md5($binary).".{$ext}";
                if (Media::exists($path) || Media::put($path, $binary)) {
                    $item->update(['image_path' => $path, 'width' => $info[0], 'height' => $info[1]]);
                    $this->stats['uploaded']++;
                } else {
                    $this->stats['failed']++;
                }
            }
        }
    }

    /**
     * Uploaded cards of a site, in the shape ReferenceAnalyzer cards have
     * (name, image, url, text) plus the stored path.
     *
     * @return Collection<int, array{name: string, image: string, url: ?string, text: ?string, path: string}>
     */
    public static function cards(Site $site): Collection
    {
        return $site->referenceItems()->where('kind', 'card')->whereNotNull('image_path')->orderBy('id')->get()
            ->map(fn (ReferenceItem $i) => ['name' => (string) $i->name, 'image' => $i->image_url, 'url' => $i->link, 'text' => trim(($i->text ?? '').' '.Str::limit((string) $i->detail, 300, '')), 'path' => $i->image_path]);
    }
}
