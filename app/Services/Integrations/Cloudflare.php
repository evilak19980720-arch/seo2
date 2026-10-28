<?php

namespace App\Services\Integrations;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare API v4: zones, DNS, SSL mode, Origin CA certificates and
 * cache purge. Token permissions: Zone:DNS:Edit, Zone:SSL and Certificates:Edit,
 * Zone:Cache Purge, Zone:Zone Settings:Edit (+ Zone:Edit to add zones).
 */
class Cloudflare
{
    public function __construct(protected ?string $token = null, protected ?string $accountId = null)
    {
        $this->token ??= Settings::get('cloudflare_api_token');
        $this->accountId ??= Settings::get('cloudflare_account_id');
        // Pasted tokens often carry spaces, newlines or a "Bearer " prefix, which Cloudflare
        // rejects as "Invalid request headers".
        $this->token = $this->token === null ? null : preg_replace('/\s+/', '', preg_replace('/^\s*Bearer\s+/i', '', $this->token));
        $this->accountId = $this->accountId === null ? null : trim($this->accountId);
    }

    public function configured(): bool
    {
        return filled($this->token);
    }

    protected function http(): PendingRequest
    {
        if (! $this->configured()) {
            throw new IntegrationException('Cloudflare API token is not configured.');
        }

        return Http::baseUrl('https://api.cloudflare.com/client/v4')->withToken($this->token)->acceptJson()->timeout(30);
    }

    protected function call(string $method, string $uri, array $data = []): array
    {
        $res = $this->http()->send($method, $uri, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        $json = $res->json() ?? [];
        if (! $res->successful() || ! ($json['success'] ?? false)) {
            $msg = collect($json['errors'] ?? [])->pluck('message')->implode('; ') ?: $res->body();
            throw new IntegrationException("Cloudflare {$method} {$uri}: {$msg}");
        }

        return $json;
    }

    public function verifyToken(): string
    {
        if (preg_match('/^[0-9a-f]{37}$/i', (string) $this->token)) {
            throw new IntegrationException('这是 Global API Key，不是 API Token。请在 Cloudflare「我的个人资料 → API 令牌」创建 API Token 后填写。');
        }

        try {
            return $this->call('GET', '/user/tokens/verify')['result']['status'] ?? 'unknown';
        } catch (IntegrationException $e) {
            // Account-owned tokens can only be verified under their account.
            if (! filled($this->accountId)) {
                throw $e;
            }

            return $this->call('GET', "/accounts/{$this->accountId}/tokens/verify")['result']['status'] ?? 'unknown';
        }
    }

    public function zoneId(string $domain, bool $create = true): string
    {
        $zones = $this->call('GET', '/zones', ['name' => $domain])['result'] ?? [];
        if ($zones !== []) {
            return $zones[0]['id'];
        }
        if (! $create || ! $this->accountId) {
            throw new IntegrationException("Zone {$domain} not found in Cloudflare.");
        }

        return $this->call('POST', '/zones', ['name' => $domain, 'account' => ['id' => $this->accountId], 'type' => 'full'])['result']['id'];
    }

    public function upsertRecord(string $zoneId, string $type, string $name, string $content, bool $proxied = false, int $ttl = 1): array
    {
        $existing = $this->call('GET', "/zones/{$zoneId}/dns_records", ['type' => $type, 'name' => $name])['result'] ?? [];
        $payload = ['type' => $type, 'name' => $name, 'content' => $content, 'ttl' => $ttl] + ($type === 'TXT' ? [] : ['proxied' => $proxied]);

        if ($type === 'TXT') {
            foreach ($existing as $record) {
                if (trim($record['content'], '"') === $content) {
                    return $record;
                }
            }

            return $this->call('POST', "/zones/{$zoneId}/dns_records", $payload)['result'];
        }

        return $existing !== []
            ? $this->call('PUT', "/zones/{$zoneId}/dns_records/{$existing[0]['id']}", $payload)['result']
            : $this->call('POST', "/zones/{$zoneId}/dns_records", $payload)['result'];
    }

    public function setting(string $zoneId, string $setting, mixed $value): void
    {
        $this->call('PATCH', "/zones/{$zoneId}/settings/{$setting}", ['value' => $value]);
    }

    /**
     * Issue an Origin CA certificate for the apex and www (15 years).
     *
     * @return array{certificate: string, private_key: string, expires_on: ?string}
     */
    public function createOriginCertificate(string $domain): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $domain], $key, ['digest_alg' => 'sha256']);
        openssl_csr_export($csr, $csrPem);
        openssl_pkey_export($key, $keyPem);

        $result = $this->call('POST', '/certificates', [
            'hostnames' => [$domain, '*.'.$domain],
            'requested_validity' => 5475,
            'request_type' => 'origin-ecc',
            'csr' => $csrPem,
        ])['result'];

        return ['certificate' => $result['certificate'], 'private_key' => $keyPem, 'expires_on' => $result['expires_on'] ?? null];
    }

    /** Purge specific URLs (Cloudflare accepts 30 per request). */
    public function purgeUrls(string $zoneId, array $urls): void
    {
        foreach (array_chunk(array_values(array_unique($urls)), 30) as $chunk) {
            $this->call('POST', "/zones/{$zoneId}/purge_cache", ['files' => $chunk]);
        }
    }
}
