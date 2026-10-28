<?php

namespace App\Services\Install;

/** Updates KEY=value pairs in a .env file, appending missing keys. */
class EnvWriter
{
    public static function write(string $path, array $values): void
    {
        if (! is_file($path)) {
            copy(base_path('.env.example'), $path);
        }
        $contents = file_get_contents($path);

        foreach ($values as $key => $value) {
            $line = $key.'='.self::quote((string) $value);
            $pattern = '/^#?\s*'.preg_quote($key, '/').'=.*$/m';
            $contents = preg_match($pattern, $contents)
                ? preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $contents, 1)
                : rtrim($contents)."\n".$line."\n";
        }

        file_put_contents($path, $contents);
    }

    protected static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_:\/.@+\-=,]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\n'], $value).'"';
    }
}
