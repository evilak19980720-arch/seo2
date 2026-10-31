<?php

namespace App\Services\Sites;

use App\Models\Domain;
use App\Models\Site;
use App\Services\Integrations\Cloudflare;
use App\Services\Integrations\GoogleAuth;
use App\Services\Integrations\IndexNow;
use App\Services\Integrations\SearchConsole;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Domain-side setup for a new site: Cloudflare DNS + proxy + Full (strict)
 * + Origin CA certificate, then Search Console DNS verification. Each step is
 * idempotent and failures are recorded on the domain instead of aborting.
 */
class SiteProvisioner
{
    public function __construct(protected ?Cloudflare $cf = null, protected ?SearchConsole $gsc = null)
    {
        $this->cf ??= new Cloudflare;
        $this->gsc ??= new SearchConsole;
    }

    /** Cloudflare setup for one domain, recording the error on it instead of throwing. */
    public function connect(Domain $domain): ?string
    {
        if (! $this->cf->configured()) {
            return null;
        }
        try {
            $this->cloudflare($domain);

            return null;
        } catch (Throwable $e) {
            $domain->recordError($e->getMessage());

            return $e->getMessage();
        }
    }

    /** @return array<int, string> log lines */
    public function provision(Site $site): array
    {
        $domain = $site->domain;
        $log = [];

        if ($this->cf->configured()) {
            try {
                $log = array_merge($log, $this->cloudflare($domain));
            } catch (Throwable $e) {
                $domain->recordError($e->getMessage());
                $log[] = 'Cloudflare 失败: '.$e->getMessage();
            }
        } else {
            $log[] = 'Cloudflare 未配置，跳过';
        }

        if (GoogleAuth::configured() && $domain->cf_zone_id) {
            try {
                $log = array_merge($log, $this->verifySearchConsole($domain));
            } catch (Throwable $e) {
                $domain->recordError($e->getMessage(), ['gsc_status' => 'failed']);
                $log[] = 'Search Console 验证失败: '.$e->getMessage();
            }
        } else {
            $log[] = 'Search Console 未配置或域名未接入 Cloudflare，跳过验证';
        }

        return $log;
    }

    public function cloudflare(Domain $domain): array
    {
        $ip = $domain->ip ?: (Settings::get('server_ip') ?: config('platform.server_ip'));
        if (! $ip) {
            throw new \RuntimeException('未设置服务器公网 IP');
        }

        $zoneId = $domain->cf_zone_id ?: $this->cf->zoneId($domain->name);
        $this->cf->upsertRecord($zoneId, 'A', $domain->name, $ip, proxied: true);
        $this->cf->upsertRecord($zoneId, 'CNAME', 'www.'.$domain->name, $domain->name, proxied: true);
        $this->cf->setting($zoneId, 'ssl', 'strict');
        $this->cf->setting($zoneId, 'always_use_https', 'on');
        $this->cf->setting($zoneId, 'min_tls_version', '1.2');
        $log = ["Cloudflare: zone {$zoneId}, A {$ip} (proxied), SSL Full (strict)"];

        $certFile = rtrim(config('platform.certs_path'), '/')."/{$domain->name}.pem";
        $expires = $domain->certificate_expires_at;
        if (! is_file($certFile)) {
            // Check before issuing so a permission problem doesn't waste a certificate.
            $dir = dirname($certFile);
            @mkdir($dir, 0750, true);
            if (! is_writable($dir)) {
                throw new \RuntimeException("证书目录不可写：{$dir}。请执行 chown -R <PHP 运行用户> {$dir}（宝塔一般是 www），解析和 SSL 设置已完成，修复后重新点「一键接入」即可签发证书");
            }
            $cert = $this->cf->createOriginCertificate($domain->name);
            file_put_contents($certFile, $cert['certificate']);
            file_put_contents(substr($certFile, 0, -4).'.key', $cert['private_key']);
            chmod(substr($certFile, 0, -4).'.key', 0600);
            $expires = $cert['expires_on'] ? Carbon::parse($cert['expires_on']) : now()->addYears(15);
            $log[] = 'Origin CA 证书已签发';
        }

        $domain->update([
            'cf_zone_id' => $zoneId, 'cf_proxied' => true, 'cf_ssl_status' => 'strict', 'ip' => $ip,
            'certificate_expires_at' => $expires, 'last_error' => null,
        ]);

        return $log;
    }

    public function verifySearchConsole(Domain $domain): array
    {
        if ($domain->gsc_status === 'verified') {
            return ['Search Console 已验证'];
        }
        $token = $domain->gsc_verification_token ?: $this->gsc->verificationToken($domain->name);
        $this->cf->upsertRecord($domain->cf_zone_id, 'TXT', $domain->name, $token);
        $domain->update(['gsc_verification_token' => $token, 'gsc_status' => 'pending']);

        // DNS may need a moment; VerifySearchConsoleJob retries later if this fails.
        if ($this->gsc->verify($domain->name)) {
            $this->gsc->addSite($domain->name);
            $domain->update(['gsc_status' => 'verified']);

            return ['Search Console 验证通过并已添加资源'];
        }

        return ['Search Console TXT 已写入，等待 DNS 生效后重试验证'];
    }

    /** Submit sitemap to Google (Search Console) and Bing; ping IndexNow with core URLs. */
    public function submitSitemap(Site $site): array
    {
        $log = [];
        $sitemap = $site->baseUrl().'/sitemap.xml';
        if (GoogleAuth::configured() && $site->domain->gsc_status === 'verified') {
            try {
                $this->gsc->submitSitemap($site->domain->name, $sitemap);
                $log[] = 'Sitemap 已提交到 Search Console';
            } catch (Throwable $e) {
                $log[] = 'Sitemap 提交失败: '.$e->getMessage();
            }
        }
        $indexNow = new IndexNow;
        if ($indexNow->bingSubmitSitemap($site->url(), $sitemap)) {
            $log[] = 'Sitemap 已提交到 Bing';
        }
        $urls = $site->pages()->where('noindex', false)->get()->map(fn ($p) => $site->url($p->slug))->all();
        if ($indexNow->submit($site->domain->name, $urls)) {
            $log[] = 'IndexNow 已推送 '.count($urls).' 个 URL';
        }

        return $log;
    }
}
