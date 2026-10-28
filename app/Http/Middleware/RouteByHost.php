<?php

namespace App\Http\Middleware;

use App\Http\Controllers\FrontController;
use App\Services\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Splits traffic by host before anything else runs:
 * - not installed: only /install, and only via server IP / admin domain / localhost
 * - brand domains: only static fallbacks (404/410) and the ad beacon; /admin,
 *   /install etc. are 404 so 100 sites never expose one login page
 * - admin domain: the Filament panel; /install is 404 once installed
 */
class RouteByHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $admin = strtolower((string) config('platform.admin_domain'));
        $path = trim($request->path(), '/');
        $isInstallPath = $path === 'install' || str_starts_with($path, 'install/');

        if (! Installer::installed()) {
            // No database yet: keep sessions and cache off the DB.
            config(['session.driver' => 'file', 'cache.default' => 'file']);
            $trusted = filter_var($host, FILTER_VALIDATE_IP) || in_array($host, ['localhost', $admin], true);
            if (! $trusted) {
                abort(404);
            }
            if (! $isInstallPath && ! str_starts_with($path, 'livewire') && ! str_starts_with($path, '_theme')) {
                return redirect('/install');
            }

            return $next($request);
        }

        if ($isInstallPath) {
            abort(404);
        }

        $isAdminHost = $admin === '' || $host === $admin || in_array($host, ['localhost', '127.0.0.1'], true);
        if ($isAdminHost) {
            return $next($request);
        }

        // Brand domain reached PHP (Nginx had no static file for it).
        if ($path === '_t/ad') {
            return $next($request);
        }

        return app(FrontController::class)->serve($request);
    }
}
