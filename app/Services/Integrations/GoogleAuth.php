<?php

namespace App\Services\Integrations;

use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** OAuth2 access tokens for a Google service account (JWT bearer flow). */
class GoogleAuth
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/webmasters',
        'https://www.googleapis.com/auth/siteverification',
        'https://www.googleapis.com/auth/analytics.readonly',
    ];

    public static function credentials(): ?array
    {
        $raw = Settings::get('gsc_service_account');
        $json = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($json) && isset($json['client_email'], $json['private_key']) ? $json : null;
    }

    public static function configured(): bool
    {
        return self::credentials() !== null;
    }

    public static function token(): string
    {
        $creds = self::credentials() ?? throw new IntegrationException('Google service account JSON is not configured.');

        return Cache::remember('google.token.'.md5($creds['client_email']), 3300, function () use ($creds) {
            $now = time();
            $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
            $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $b64(json_encode([
                'iss' => $creds['client_email'],
                'scope' => implode(' ', self::SCOPES),
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            openssl_sign("{$header}.{$claims}", $signature, $creds['private_key'], OPENSSL_ALGO_SHA256);

            $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$header}.{$claims}.".$b64($signature),
            ]);
            if (! $res->successful()) {
                throw new IntegrationException('Google token error: '.$res->body());
            }

            return $res->json('access_token');
        });
    }

    public static function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken(self::token())->acceptJson()->timeout(60);
    }
}
