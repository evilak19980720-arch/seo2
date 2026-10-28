<?php

namespace App\Http\Controllers;

use App\Models\GoneUrl;
use App\Services\Render\StaticSiteBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fallback for brand domains when Nginx found no static file: serves the
 * file (useful without Nginx), 301s missing trailing slashes, 410 for deleted
 * content and a real 404 with the site's own 404 page otherwise.
 */
class FrontController
{
    public function serve(Request $request): Response
    {
        $host = preg_replace('/^www\./', '', strtolower($request->getHost()));
        if ($host !== strtolower($request->getHost())) {
            return redirect()->away('https://'.$host.$request->getRequestUri(), 301);
        }

        $root = StaticSiteBuilder::root().'/'.$host;
        $path = trim(rawurldecode($request->getPathInfo()), '/');
        $notFound = is_file("{$root}/404.html") ? file_get_contents("{$root}/404.html") : 'Not Found';

        if (str_contains($path, '..')) {
            return response($notFound, 404, ['Cache-Control' => 'no-store']);
        }

        if (is_dir($root)) {
            $file = $path === '' ? "{$root}/index.html" : "{$root}/{$path}";
            if (is_dir($file) && is_file("{$file}/index.html")) {
                if (! str_ends_with($request->getPathInfo(), '/')) {
                    return redirect()->away('https://'.$host.'/'.$path.'/'.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
                }
                $file .= '/index.html';
            }
            if (is_file($file) && str_starts_with(realpath($file), realpath($root))) {
                return str_ends_with($file, '.html')
                    ? response(file_get_contents($file), 200, $this->headers($file))
                    : response()->file($file, $this->headers($file));
            }
        }

        if (GoneUrl::isGone($host, $path)) {
            return response($notFound, 410, ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex', 'Cache-Control' => 'no-store']);
        }

        // no-store: a briefly missing asset must not stay cached as 404 in browsers / Cloudflare.
        return response($notFound, 404, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    protected function headers(string $file): array
    {
        $types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'xml' => 'application/xml', 'txt' => 'text/plain', 'webp' => 'image/webp', 'avif' => 'image/avif', 'png' => 'image/png', 'ico' => 'image/x-icon', 'webmanifest' => 'application/manifest+json', 'woff2' => 'font/woff2', 'js' => 'text/javascript', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml'];

        return ['Content-Type' => $types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'];
    }
}
