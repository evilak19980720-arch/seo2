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

    /**
     * All zones the token can see (limited to the configured account when set).
     *
     * @return array<int, array{id: string, name: string, status: string, name_servers: array<int, string>}>
     */
    public function listZones(): array
    {
        $zones = [];
        $page = 1;
        do {
            $json = $this->call('GET', '/zones', array_filter(['per_page' => 50, 'page' => $page, 'account.id' => $this->accountId]));
            foreach ($json['result'] ?? [] as $zone) {
                $zones[] = ['id' => $zone['id'], 'name' => $zone['name'], 'status' => $zone['status'], 'name_servers' => $zone['name_servers'] ?? []];
            }
            $pages = (int) ($json['result_info']['total_pages'] ?? 1);
        } while ($page++ < $pages);

        return $zones;
    }

    /** @return array{id: string, name: string, status: string, name_servers: array<int, string>} */
    public function createZone(string $domain): array
    {
        if (! $this->accountId) {
            throw new IntegrationException('添加域名到 Cloudflare 需要先在全局配置填写 Account ID');
        }
        $zone = $this->call('POST', '/zones', ['name' => $domain, 'account' => ['id' => $this->accountId], 'type' => 'full'])['result'];

        return ['id' => $zone['id'], 'name' => $zone['name'], 'status' => $zone['status'], 'name_servers' => $zone['name_servers'] ?? []];
    }

    public function deleteZone(string $zoneId): void
    {
        $this->call('DELETE', "/zones/{$zoneId}");
    }

    /** @return array<int, array<string, mixed>> */
    public function dnsRecords(string $zoneId): array
    {
        $records = [];
        $page = 1;
        do {
            $json = $this->call('GET', "/zones/{$zoneId}/dns_records", ['per_page' => 100, 'page' => $page]);
            $records = array_merge($records, $json['result'] ?? []);
            $pages = (int) ($json['result_info']['total_pages'] ?? 1);
        } while ($page++ < $pages);

        return $records;
    }

    public function createRecord(string $zoneId, array $record): array
    {
        return $this->call('POST', "/zones/{$zoneId}/dns_records", self::recordPayload($record))['result'];
    }

    public function updateRecord(string $zoneId, string $recordId, array $record): array
    {
        return $this->call('PUT', "/zones/{$zoneId}/dns_records/{$recordId}", self::recordPayload($record))['result'];
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        $this->call('DELETE', "/zones/{$zoneId}/dns_records/{$recordId}");
    }

    protected static function recordPayload(array $record): array
    {
        $payload = ['type' => $record['type'], 'name' => $record['name'], 'content' => $record['content'], 'ttl' => (int) ($record['ttl'] ?? 1)];
        if (in_array($record['type'], ['A', 'AAAA', 'CNAME'], true)) {
            $payload['proxied'] = (bool) ($record['proxied'] ?? false);
        }
        if (in_array($record['type'], ['MX', 'SRV', 'URI'], true)) {
            $payload['priority'] = (int) ($record['priority'] ?? 10);
        }

        return $payload;
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

    /**
     * Point a name at a value. For A/AAAA/CNAME every other address record on the
     * same name (other IPs, a CNAME vs A) is removed so the old origin stops
     * receiving traffic; TXT records are only added when missing.
     */
    public function upsertRecord(string $zoneId, string $type, string $name, string $content, bool $proxied = false, int $ttl = 1): array
    {
        $name = strtolower(rtrim($name, '.'));
        $sameName = array_values(array_filter($this->dnsRecords($zoneId), fn ($r) => strtolower(rtrim($r['name'], '.')) === $name));
        $payload = ['type' => $type, 'name' => $name, 'content' => $content, 'ttl' => $ttl] + ($type === 'TXT' ? [] : ['proxied' => $proxied]);

        if ($type === 'TXT') {
            foreach ($sameName as $record) {
                if ($record['type'] === 'TXT' && trim($record['content'], '"') === $content) {
                    return $record;
                }
            }

            return $this->call('POST', "/zones/{$zoneId}/dns_records", $payload)['result'];
        }

        $address = ['A', 'AAAA', 'CNAME'];
        $conflicting = array_filter($sameName, fn ($r) => in_array($r['type'], in_array($type, $address, true) ? $address : [$type], true));
        $keep = collect($conflicting)->firstWhere('type', $type);

        foreach ($conflicting as $record) {
            if ($record['id'] !== ($keep['id'] ?? null)) {
                $this->deleteRecord($zoneId, $record['id']);
            }
        }

        return $keep
            ? $this->call('PUT', "/zones/{$zoneId}/dns_records/{$keep['id']}", $payload)['result']
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
