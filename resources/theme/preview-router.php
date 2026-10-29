<?php

// Router for `php -S`: serves a built site directory like Nginx would
// (used by Lighthouse acceptance runs before DNS points at the server).
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$siteDir = getenv('SITE_DIR');
$themeDir = getenv('THEME_DIR');

if (str_starts_with($uri, '/_theme/')) {
    $file = $themeDir.'/'.substr($uri, 8);
} else {
    $file = $siteDir.$uri;
    if (is_dir($file)) {
        $file = rtrim($file, '/').'/index.html';
    }
}

$real = realpath($file);
if ($real === false || (! str_starts_with($real, realpath($siteDir)) && ! str_starts_with($real, realpath($themeDir)))) {
    http_response_code(404);
    readfile($siteDir.'/404.html');

    return true;
}

$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'application/javascript', 'webp' => 'image/webp', 'avif' => 'image/avif', 'png' => 'image/png', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'xml' => 'application/xml', 'txt' => 'text/plain', 'webmanifest' => 'application/manifest+json'];
header('Content-Type: '.($types[pathinfo($real, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
// AI quality check: the page reports contrast, overflow and image problems into its own DOM.
if (isset($_GET['__qa']) && str_ends_with($real, '.html')) {
    echo str_replace('</body>', '<script>'.file_get_contents(__DIR__.'/qa-audit.js').'</script></body>', (string) file_get_contents($real));

    return true;
}
readfile($real);

return true;
