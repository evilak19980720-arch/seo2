<?php

namespace App\Services\Integrations;

use App\Support\Binary;
use App\Support\PreviewServer;
use Symfony\Component\Process\Process;

/**
 * Mobile Lighthouse audit of a statically built page, served from a
 * throwaway local PHP server so it works before DNS points at us.
 */
class Lighthouse
{
    public static function available(): bool
    {
        return Binary::find(config('platform.lighthouse.bin')) !== null;
    }

    /**
     * @param  array<string, string>  $paths  label => url path, all audited against one server
     * @return array<string, array{performance: int, seo: int}|null>
     */
    public function auditSite(string $siteDir, array $paths): array
    {
        return PreviewServer::with($siteDir, function (string $base) use ($paths) {
            $results = [];
            foreach ($paths as $label => $path) {
                $results[$label] = $this->audit($base.'/'.ltrim($path, '/'));
            }

            return $results;
        });
    }

    /** @return array{performance: int, seo: int}|null */
    public function audit(string $url): ?array
    {
        $cmd = [
            config('platform.lighthouse.bin'), $url, '--quiet', '--output=json', '--output-path=stdout',
            '--only-categories=performance,seo', '--form-factor=mobile', '--chrome-flags=--headless=new --no-sandbox',
        ];
        $env = config('platform.lighthouse.chrome_path') ? ['CHROME_PATH' => config('platform.lighthouse.chrome_path')] : null;
        $p = new Process($cmd, null, $env, null, 180);
        $p->run();
        if (! $p->isSuccessful()) {
            return null;
        }
        $json = json_decode($p->getOutput(), true);

        return [
            'performance' => (int) round(($json['categories']['performance']['score'] ?? 0) * 100),
            'seo' => (int) round(($json['categories']['seo']['score'] ?? 0) * 100),
        ];
    }
}
