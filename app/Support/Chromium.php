<?php

namespace App\Support;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs headless Chromium the way it works under a panel's PHP user (e.g.
 * "www" on Baota): no sandbox, no /dev/shm, a throwaway profile and HOME,
 * and the error output kept so failures can be shown instead of guessed.
 */
class Chromium
{
    public const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    public static string $lastError = '';

    /** Programs tried when CHROMIUM_BIN is left at its default; the headless shell is the most reliable on servers. */
    public const CANDIDATES = ['chrome-headless-shell', 'chromium', 'chromium-browser', 'google-chrome', 'google-chrome-stable'];

    public static function bin(): ?string
    {
        $configured = trim((string) config('platform.chromium_bin'));
        if ($configured !== '' && $configured !== 'chromium') {
            return Binary::find($configured);
        }
        foreach (self::CANDIDATES as $candidate) {
            if ($path = Binary::find($candidate)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Run Chromium and return its stdout. Full Chromium builds sometimes never
     * exit after --dump-dom/--screenshot on servers without dbus, so $done is
     * polled and the process is stopped as soon as the result is there.
     *
     * @param  array<int, string>  $args
     * @param  callable(string): bool|null  $done  receives stdout so far
     */
    public static function run(array $args, int $timeout = 35, ?callable $done = null): ?string
    {
        self::$lastError = '';
        if (! $bin = self::bin()) {
            self::$lastError = 'Chromium 不可用';

            return null;
        }
        $done ??= fn (string $out) => stripos($out, '</html>') !== false;
        $home = sys_get_temp_dir().'/chromium-'.bin2hex(random_bytes(4));
        @mkdir($home, 0700, true);
        $p = null;
        try {
            $p = new Process(array_merge([
                $bin, '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--no-first-run',
                '--no-default-browser-check', '--disable-extensions', '--mute-audio', '--hide-scrollbars',
                '--disable-background-networking', '--disable-component-update', '--disable-sync',
                '--user-data-dir='.$home.'/profile', '--user-agent='.self::UA,
            ], $args), null, ['HOME' => $home, 'XDG_CONFIG_HOME' => $home, 'XDG_CACHE_HOME' => $home], null, null);
            $p->start();
            $deadline = microtime(true) + $timeout;
            $finished = false;
            while ($p->isRunning()) {
                if ($done($p->getOutput())) {
                    usleep(200_000);
                    $finished = true;
                    break;
                }
                if (microtime(true) > $deadline) {
                    break;
                }
                usleep(100_000);
            }
            $out = $p->getOutput();
            if ($p->isRunning()) {
                $p->stop(2);
                if (! $finished && ! $done($out)) {
                    self::$lastError = "Chromium {$timeout} 秒内没有完成（".basename($bin).'），'.self::hint($p->getErrorOutput());

                    return $out !== '' ? $out : null;
                }
            } elseif (! $p->isSuccessful() && trim($out) === '') {
                self::$lastError = 'Chromium 退出码 '.$p->getExitCode().'：'.self::hint($p->getErrorOutput());
            }

            return $out;
        } catch (Throwable $e) {
            self::$lastError = 'Chromium 无法启动：'.mb_substr(strtok($e->getMessage(), "\n"), 0, 160);

            return null;
        } finally {
            if ($p?->isRunning()) {
                $p->stop(0);
            }
            self::removeDir($home);
        }
    }

    /** The last meaningful stderr line (dbus noise is normal on servers). */
    protected static function hint(string $stderr): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $stderr)), fn ($l) => $l !== '' && ! str_contains($l, 'dbus') && ! str_contains($l, 'zygote'));

        return mb_substr(end($lines) ?: '建议安装 chrome-headless-shell 并在 .env 设置 CHROMIUM_BIN', 0, 200);
    }

    protected static function removeDir(string $dir): void
    {
        try {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($dir);
        } catch (Throwable) {
            // Temp leftovers are harmless.
        }
    }
}
