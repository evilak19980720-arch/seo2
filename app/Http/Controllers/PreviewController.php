<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\Render\StaticSiteBuilder;
use Illuminate\Http\Request;

/** Admin-only preview of a site's built static files, links rewritten under /preview/{id}/. */
class PreviewController
{
    public function __invoke(Request $request, Site $site, string $path = '')
    {
        $root = StaticSiteBuilder::dirFor($site->load('domain'));
        $file = rtrim($root.'/'.trim($path, '/'), '/');
        if (is_dir($file)) {
            $file .= '/index.html';
        }
        $real = realpath($file);
        if ($real === false || ! str_starts_with($real, realpath($root) ?: '/nonexistent')) {
            abort(404);
        }

        if (! str_ends_with($real, '.html')) {
            return response()->file($real);
        }

        $prefix = '/preview/'.$site->id.'/';
        $html = preg_replace('#(href|src|srcset)="/(?!_theme/|/)#', '$1="'.$prefix, file_get_contents($real));
        $html = str_replace(['href="'.$site->baseUrl().'/', 'url(/assets/'], ['href="'.$prefix, 'url('.$prefix.'assets/'], $html);
        $html = str_replace('<head>', '<head><meta name="robots" content="noindex">', $html);

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
