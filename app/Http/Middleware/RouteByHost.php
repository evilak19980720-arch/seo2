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
 * - admin domain or server IP: the Filament panel; /install is 404 once installed
 */
class RouteByHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $admin = strtolower((string) config('platform.admin_domain'));
        $path = trim($request->path(), '/');
        $isInstallPath = $path === 'install' || str_starts_with($path, 'install/');

        // Secure cookies only over HTTPS (Cloudflare/X-Forwarded-Proto is trusted), so the
        // panel still works over plain HTTP before the admin domain has a certificate.
        config(['session.secure' => $request->isSecure()]);

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

        // Brand sites are always domains, so a bare IP (e.g. right after installing via
        // http://SERVER_IP/install) is treated as the admin host too.
        $isAdminHost = $admin === '' || $host === $admin || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP);
        if ($isAdminHost) {
            return $next($request);
        }

        // Brand domain reached PHP (Nginx had no static file for it).
        // Ad beacon and the shared theme assets (when Nginx has no /_theme/ alias, e.g. panel-managed sites).
        if (preg_match('#^(_t|x[0-9a-f]{6})/ad$#', $path) || str_starts_with($path, '_theme/')) {
            return $next($request);
        }

        return app(FrontController::class)->serve($request);
    }
}
