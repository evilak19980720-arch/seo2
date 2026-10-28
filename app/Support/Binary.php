<?php

namespace App\Support;

/**
 * Finds external programs (chromium, lighthouse, pdftotext) without a shell.
 * Panels such as Baota disable proc_open/shell_exec by default; then nothing
 * external can run and callers must skip the feature instead of crashing.
 */
class Binary
{
    public static function canRun(): bool
    {
        return function_exists('proc_open') && function_exists('proc_get_status');
    }

    /** Absolute path of an executable, or null when it is missing or programs can't be started. */
    public static function find(?string $bin): ?string
    {
        $bin = trim((string) $bin);
        if ($bin === '' || ! self::canRun()) {
            return null;
        }
        if (str_contains($bin, '/')) {
            return @is_file($bin) && @is_executable($bin) ? $bin : null;
        }
        $dirs = array_merge(explode(PATH_SEPARATOR, (string) getenv('PATH')), ['/usr/local/bin', '/usr/bin', '/bin', '/snap/bin']);
        foreach (array_unique(array_filter($dirs)) as $dir) {
            $path = rtrim($dir, '/').'/'.$bin;
            // open_basedir may forbid looking outside the site directory.
            if (@is_file($path) && @is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
