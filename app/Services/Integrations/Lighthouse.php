<?php

namespace App\Services\Integrations;

use App\Support\Binary;
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
        $port = random_int(20000, 40000);
        $server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", base_path('resources/theme/preview-router.php')], null, [
            'SITE_DIR' => $siteDir,
            'THEME_DIR' => public_path('theme'),
        ]);
        $server->start();
        usleep(400_000);

        $results = [];
        try {
            foreach ($paths as $label => $path) {
                $results[$label] = $this->audit("http://127.0.0.1:{$port}/".ltrim($path, '/'));
            }
        } finally {
            $server->stop(1);
        }

        return $results;
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
