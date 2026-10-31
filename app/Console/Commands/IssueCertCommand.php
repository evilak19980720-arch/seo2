<?php

namespace App\Console\Commands;

use App\Services\Integrations\Cloudflare;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Issue a Cloudflare Origin CA certificate for any hostname (e.g. the admin domain). */
class IssueCertCommand extends Command
{
    protected $signature = 'app:issue-cert {hostname} {--dns : Also point the hostname at the server IP (proxied)}';

    protected $description = '为指定域名签发 Cloudflare Origin CA 证书';

    public function handle(): int
    {
        $host = strtolower($this->argument('hostname'));
        $cf = new Cloudflare;
        $labels = explode('.', $host);
        $zoneId = null;
        for ($i = 0; $i < count($labels) - 1 && ! $zoneId; $i++) {
            $zoneId = rescue(fn () => $cf->zoneId(implode('.', array_slice($labels, $i)), create: false), null, false);
        }
        if (! $zoneId) {
            $this->error("Cloudflare 中找不到 {$host} 所属的 Zone");

            return self::FAILURE;
        }

        if ($this->option('dns')) {
            $cf->upsertRecord($zoneId, 'A', $host, (string) Settings::get('server_ip', config('platform.server_ip')), proxied: true);
        }

        $cert = $cf->createOriginCertificate($host);
        $dir = rtrim(config('platform.certs_path'), '/');
        File::ensureDirectoryExists($dir, 0750);
        file_put_contents("{$dir}/{$host}.pem", $cert['certificate']);
        file_put_contents("{$dir}/{$host}.key", $cert['private_key']);
        chmod("{$dir}/{$host}.key", 0600);
        $this->info("证书已写入 {$dir}/{$host}.pem（有效期至 {$cert['expires_on']}）");

        return self::SUCCESS;
    }
}
