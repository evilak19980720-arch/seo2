<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Global configuration stored in the `settings` table, cached as one array.
 * Secrets are encrypted at rest; every key has a default so the platform runs
 * before anything is configured.
 */
class Settings
{
    public const CACHE_KEY = 'platform.settings';

    /** Keys whose values are encrypted at rest. */
    public const SECRET_KEYS = [
        'cloudflare_api_token', 'oss_secret', 'gsc_service_account', 'bing_api_key',
    ];

    public static function defaults(): array
    {
        return [
            // Site generation
            'auto_launch' => true,
            'mfa_required' => false,
            'server_ip' => null,
            'cookie_banner' => true,
            'robots_allow_ai_search' => true,
            'robots_block_ai_training' => true,

            // Publish caps per site per week, by site age
            'cap_months_0_3' => 3,
            'cap_months_3_6' => 5,
            'cap_after_6' => 5,
            'cap_step' => 2,
            'cap_max' => 14,
            'cap_raise_ratio' => 0.5,

            // Word ranges per article type
            'words_guide' => [800, 1500],
            'words_update' => [300, 700],
            'words_faq' => [300, 600],
            'words_use_case' => [600, 1200],

            // Guardrails
            'min_completeness' => 60,
            'intra_site_similarity' => 0.85,
            'cross_site_similarity' => 0.80,
            'page_similarity' => 0.6,
            'min_list_articles' => 3,
            'cold_days' => 90,
            'drop_threshold' => 0.5,
            'articles_generated_per_run' => 1,
            'ai_images_per_site' => 8,

            // Cloudflare
            'cloudflare_api_token' => null,
            'cloudflare_account_id' => null,

            // Object storage
            'oss_driver' => 's3',
            'oss_key' => null,
            'oss_secret' => null,
            'oss_bucket' => null,
            'oss_region' => null,
            'oss_endpoint' => null,
            'oss_cdn_url' => null,

            // Search engines
            'gsc_service_account' => null,
            'bing_api_key' => null,
            'indexnow_key' => null,

            // AI provider per purpose: [purpose => [primary, primary_model, fallback, fallback_model]]
            'ai_routes' => [],
        ];
    }

    public static function all(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => self::load() ?? throw new RuntimeException('settings unavailable'));
        } catch (Throwable) {
            // Database or cache not configured yet (installer, package:discover) — use defaults, cache nothing.
            return self::defaults();
        }
    }

    /** Stored settings merged over defaults, or null when the settings table is unreachable. */
    protected static function load(): ?array
    {
        try {
            if (! Schema::hasTable('settings')) {
                return null;
            }
            $values = self::defaults();
            foreach (Setting::all() as $row) {
                $raw = $row->encrypted && $row->value !== null ? Crypt::decryptString($row->value) : $row->value;
                $values[$row->key] = json_decode((string) $raw, true);
            }

            return $values;
        } catch (Throwable) {
            return null;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::setMany([$key => $value]);
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $encrypted = in_array($key, self::SECRET_KEYS, true);
            $json = json_encode($value);
            Setting::updateOrCreate(['key' => $key], [
                'value' => $encrypted && $value !== null ? Crypt::encryptString($json) : $json,
                'encrypted' => $encrypted,
            ]);
        }
        Cache::forget(self::CACHE_KEY);
    }

    public static function wordRange(string $type): array
    {
        return self::get('words_'.$type, [600, 1200]);
    }
}
