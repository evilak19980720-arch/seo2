<?php

namespace App\Services\Render;

use App\Enums\PromptType;
use App\Models\Site;
use App\Services\Ai\AiManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * Interface strings of the theme (menus, buttons, labels, cookie banner,
 * legal fallbacks) for site languages without a hand-written lang file:
 * translated once by the AI from English, stored per language and loaded
 * into the translator, so no site shows English UI next to its own copy.
 */
class ThemeStrings
{
    /** @var array<string, bool> languages already loaded in this process */
    protected static array $loaded = [];

    public static function bundled(string $lang): bool
    {
        return is_file(lang_path("{$lang}/theme.php"));
    }

    public static function file(string $lang): string
    {
        return storage_path("app/theme-lang/{$lang}.json");
    }

    /** English source strings, flattened ("page_titles.home" => "Home"). */
    public static function source(): array
    {
        return Arr::dot(require lang_path('en/theme.php'));
    }

    /** Load a stored translation into the translator (no-op for bundled languages). */
    public static function load(string $lang): void
    {
        if (isset(self::$loaded[$lang]) || self::bundled($lang)) {
            return;
        }
        self::$loaded[$lang] = true;
        $data = is_file(self::file($lang)) ? json_decode((string) file_get_contents(self::file($lang)), true) : null;
        if (is_array($data['strings'] ?? null)) {
            Lang::addLines(collect($data['strings'])->mapWithKeys(fn ($v, $k) => ["theme.{$k}" => $v])->all(), $lang);
        }
    }

    /**
     * Make sure the site's language has interface strings; translates the
     * missing or changed ones. Returns a note for the task log, or null.
     */
    public static function ensure(Site $site, AiManager $ai): ?string
    {
        $lang = $site->language();
        if (self::bundled($lang)) {
            return null;
        }
        $source = self::source();
        $stored = is_file(self::file($lang)) ? (json_decode((string) file_get_contents(self::file($lang)), true) ?: []) : [];
        $strings = (array) ($stored['strings'] ?? []);
        $hashes = (array) ($stored['source'] ?? []);
        $todo = array_filter($source, fn ($text, $key) => ! isset($strings[$key]) || ($hashes[$key] ?? null) !== md5($text), ARRAY_FILTER_USE_BOTH);
        if ($todo === []) {
            self::load($lang);

            return null;
        }

        $done = 0;
        foreach (array_chunk($todo, 60, true) as $chunk) {
            try {
                $res = $ai->prompt(PromptType::UiTranslate, ['strings' => json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], $site)->json();
            } catch (Throwable) {
                continue;
            }
            foreach ($chunk as $key => $text) {
                $value = $res[$key] ?? data_get($res, $key);
                if (is_string($value) && trim($value) !== '' && self::samePlaceholders($text, $value)) {
                    $strings[$key] = trim($value);
                    $hashes[$key] = md5($text);
                    $done++;
                }
            }
        }
        File::ensureDirectoryExists(dirname(self::file($lang)));
        file_put_contents(self::file($lang), json_encode(['strings' => $strings, 'source' => $hashes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        unset(self::$loaded[$lang]);
        self::load($lang);

        return $done === count($todo)
            ? "界面文字已翻译为 {$site->locale}（{$done} 条）"
            : "界面文字翻译为 {$site->locale}：{$done}/".count($todo).' 条成功，其余暂用英文，下次生成会重试';
    }

    /** ":site", ":n" and HTML tags must survive the translation. */
    protected static function samePlaceholders(string $a, string $b): bool
    {
        $marks = fn ($s) => [preg_match_all('/:[a-z_]+/', $s, $m) ? array_count_values($m[0]) : [], preg_match_all('/<\/?[a-z0-9]+/i', $s, $t) ? array_count_values(array_map('strtolower', $t[0])) : []];

        return $marks($a) == $marks($b);
    }
}
