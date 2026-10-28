<?php

namespace App\Http\Controllers;

/** Serves /_theme/* on hosts without the Nginx alias (dev / preview). */
class ThemeAssetController
{
    public function __invoke(string $path)
    {
        $root = realpath(public_path('theme'));
        $file = realpath(public_path('theme/'.$path));
        abort_unless($root && $file && str_starts_with($file, $root) && is_file($file), 404);

        $type = str_ends_with($file, '.css') ? 'text/css' : (str_ends_with($file, '.woff2') ? 'font/woff2' : null);

        return response()->file($file, array_filter(['Content-Type' => $type, 'Cache-Control' => 'public, max-age=31536000, immutable']));
    }
}
