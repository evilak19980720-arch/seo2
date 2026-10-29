<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/**
 * A throwaway local PHP server for a built site directory, so checks run
 * before DNS points at us (Lighthouse, the AI quality check).
 */
class PreviewServer
{
    protected ?Process $process = null;

    public string $url = '';

    public function start(string $siteDir): string
    {
        $port = random_int(20000, 40000);
        $php = str_contains(basename(PHP_BINARY), 'fpm') ? (Binary::find('php') ?? 'php') : PHP_BINARY;
        $this->process = new Process([$php, '-S', "127.0.0.1:{$port}", base_path('resources/theme/preview-router.php')], null, [
            'SITE_DIR' => $siteDir,
            'THEME_DIR' => public_path('theme'),
        ]);
        $this->process->start();
        // Ready once the port answers (at most ~3 s).
        for ($i = 0; $i < 30; $i++) {
            usleep(100_000);
            if ($socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)) {
                fclose($socket);
                break;
            }
        }

        return $this->url = "http://127.0.0.1:{$port}";
    }

    public function stop(): void
    {
        $this->process?->stop(1);
        $this->process = null;
    }

    /** Run $callback with the site served; the server always stops afterwards. */
    public static function with(string $siteDir, callable $callback): mixed
    {
        $server = new self;
        try {
            return $callback($server->start($siteDir));
        } finally {
            $server->stop();
        }
    }
}
