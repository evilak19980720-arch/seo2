<?php

namespace App\Services\Sites;

use App\Services\Content\SeoRules;
use App\Support\Media;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What a picture is, so stand-in pictures fit where they go: a logo (a
 * provider or partner mark, a flat or cut-out graphic) only belongs to its
 * own name, a wide banner (usually a promotion with text on it) only to a
 * hero shown uncropped, and an ordinary picture can stand in anywhere.
 * Read once per file from its pixels, size and name, then cached.
 */
class PictureRole
{
    public const LOGO = 'logo';

    public const BANNER = 'banner';

    public const PHOTO = 'photo';

    /** Width / height from which a picture is a banner. */
    public const BANNER_RATIO = 1.9;

    protected const HINT = '/(^|[^a-z])(logos?|brand(ing)?|providers?|partners?|sponsors?|badges?|icons?|payments?|licen[cs]es?|seals?|certificat\w*|selo|parceir\w*|proveedor\w*|fornecedor\w*)([^a-z]|$)/i';

    public static function of(string $path, ?string $name = null, ?int $width = null, ?int $height = null): string
    {
        $key = md5($path.'|'.$name.'|'.$width.'x'.$height);

        return Cache::store('array')->rememberForever("picture-role:{$key}", fn () => Cache::rememberForever("picture-role:v2:{$key}",
            fn () => self::classify(rescue(fn () => Media::get($path), null, false), $path.' '.$name, $width, $height)));
    }

    /**
     * $hint: the picture's name, alt or URL. Only file names and words count:
     * folders like /brand/ or /uploads/ say nothing about the picture.
     */
    public static function classify(?string $binary, string $hint = '', ?int $width = null, ?int $height = null): string
    {
        $hint = preg_replace_callback('#\S*/\S*#', fn ($m) => pathinfo((string) parse_url($m[0], PHP_URL_PATH), PATHINFO_FILENAME), $hint);
        if (preg_match(self::HINT, $hint)) {
            return self::LOGO;
        }
        $stats = $binary !== null ? self::stats($binary) : null;
        $width = $stats['width'] ?? $width;
        $height = $stats['height'] ?? $height;
        if ($stats && ($stats['transparent'] >= 0.25 || ($stats['top'] >= 0.5 && $stats['colours'] <= 10))) {
            return self::LOGO;
        }
        if ($width && $height && $width / $height >= self::BANNER_RATIO) {
            return self::BANNER;
        }

        return self::PHOTO;
    }

    /**
     * Share of transparent pixels, share of the most common colour and the
     * number of colours covering at least 1% (on a 40×40 copy, 512 buckets).
     *
     * @return array{width: int, height: int, transparent: float, top: float, colours: int}|null
     */
    public static function stats(string $binary): ?array
    {
        try {
            $im = @imagecreatefromstring($binary);
            if (! $im) {
                return null;
            }
            $w = imagesx($im);
            $h = imagesy($im);
            $small = imagecreatetruecolor(40, 40);
            imagealphablending($small, false);
            imagesavealpha($small, true);
            imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
            imagecopyresampled($small, $im, 0, 0, 0, 0, 40, 40, $w, $h);
            $buckets = [];
            $transparent = 0;
            for ($y = 0; $y < 40; $y++) {
                for ($x = 0; $x < 40; $x++) {
                    $c = imagecolorat($small, $x, $y);
                    if ((($c >> 24) & 0x7F) >= 100) {
                        $transparent++;

                        continue;
                    }
                    $bucket = ((($c >> 16) & 0xFF) >> 5) << 6 | ((($c >> 8) & 0xFF) >> 5) << 3 | (($c & 0xFF) >> 5);
                    $buckets[$bucket] = ($buckets[$bucket] ?? 0) + 1;
                }
            }
            $opaque = max(1, 1600 - $transparent);
            arsort($buckets);

            return [
                'width' => $w, 'height' => $h,
                'transparent' => $transparent / 1600,
                'top' => $buckets ? reset($buckets) / $opaque : 0.0,
                'colours' => count(array_filter($buckets, fn ($n) => $n / $opaque >= 0.01)),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** A dated picture from a year already over ("2024 Heavyweight Event"). */
    public static function expired(?string $text): bool
    {
        preg_match_all('/(?<!\d)(20\d\d)(?!\d)/', (string) $text, $m);

        return $m[1] !== [] && max(array_map('intval', $m[1])) < (int) now()->year;
    }

    /**
     * How well a picture's words (its card name and text) fit a place's words:
     * the share of the place's own words found in the picture's, 0 to 1.
     * Brand names and short words don't count.
     */
    public static function match(string $place, string $picture, array $ignore = []): float
    {
        $words = fn (string $s) => array_values(array_unique(array_filter(
            preg_split('/\s+/u', SeoRules::normalize($s)),
            fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, $ignore, true) && ! in_array($w, self::STOP, true),
        )));
        $a = $words($place);
        $b = $words($picture);
        if ($a === [] || $b === []) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / count($a);
    }

    protected const STOP = ['the', 'and', 'for', 'with', 'your', 'you', 'our', 'are', 'from', 'this', 'that', 'all', 'new', 'more', 'how', 'what', 'why', 'para', 'com', 'dos', 'das', 'uma', 'seu', 'sua', 'mais', 'como', 'por', 'los', 'las', 'del', 'con', 'una', 'que', 'game', 'games', 'jogo', 'jogos', 'juego', 'juegos', 'online', 'play', 'official', 'oficial'];
}
