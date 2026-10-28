<?php

return [
    // Where generated static sites live: {output_path}/{host}/...
    'output_path' => env('SITES_OUTPUT_PATH', storage_path('app/sites')),

    // The only host allowed to serve /admin; brand domains get 404.
    'admin_domain' => env('ADMIN_DOMAIN'),

    'server_ip' => env('SERVER_PUBLIC_IP'),

    // Cloudflare Origin CA certs, one pair per domain: {certs_path}/{domain}.pem/.key
    'certs_path' => env('SITES_CERTS_PATH', storage_path('app/certs')),

    'installed_lock' => storage_path('installed.lock'),

    'min_php_version' => '8.5.0',
    'min_mysql_version' => '8.0.0',

    // Lighthouse acceptance (mobile). Set LIGHTHOUSE_BIN empty to skip.
    'lighthouse' => [
        'bin' => env('LIGHTHOUSE_BIN', 'lighthouse'),
        'chrome_path' => env('CHROME_PATH'),
        'min_performance' => 90,
        'min_seo' => 90,
        'max_attempts' => 3,
    ],

    'chromium_bin' => env('CHROMIUM_BIN', 'chromium'),

    // Concurrency cap for full-site generation jobs.
    'generation_concurrency' => (int) env('GENERATION_CONCURRENCY', 2),
];
