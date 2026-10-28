<?php

namespace App\Services\Install;

/** Generates copy-paste Nginx, Supervisor and crontab configuration. */
class ServerConfig
{
    public static function nginx(?string $adminDomain = null): string
    {
        $adminDomain ??= config('platform.admin_domain') ?: 'admin.example.com';
        $public = public_path();
        $sites = rtrim(config('platform.output_path'), '/');
        $certs = rtrim(config('platform.certs_path'), '/');
        $php = 'unix:/run/php/php8.5-fpm.sock';

        return <<<NGINX
# /etc/nginx/conf.d/site-network.conf
log_format sites '\$host \$remote_addr [\$time_local] "\$request" \$status \$body_bytes_sent "\$http_user_agent"';

# Cloudflare sends the visitor IP in CF-Connecting-IP (add all Cloudflare ranges with set_real_ip_from).
real_ip_header CF-Connecting-IP;

# ---- Admin panel: only on the dedicated admin domain ----
server {
    listen 443 ssl;
    http2 on;
    server_name {$adminDomain};
    ssl_certificate     {$certs}/{$adminDomain}.pem;
    ssl_certificate_key {$certs}/{$adminDomain}.key;

    root {$public};
    index index.php;
    client_max_body_size 50m;

    location ^~ /_theme/ { alias {$public}/theme/; expires 1y; add_header Cache-Control "public, immutable"; }
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        fastcgi_pass {$php};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
}

# ---- Brand sites: default server, static files by Host ----
server {
    listen 80 default_server;
    server_name _;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl default_server;
    http2 on;
    server_name _;
    # One Cloudflare Origin CA certificate per domain (issued automatically).
    ssl_certificate     {$certs}/\$ssl_server_name.pem;
    ssl_certificate_key {$certs}/\$ssl_server_name.key;
    access_log /var/log/nginx/sites.access.log sites;

    # www → apex, one canonical host per site
    if (\$host ~* ^www\.(.+)\$) { return 301 https://\$1\$request_uri; }

    root {$sites}/\$host;
    index index.html;
    charset utf-8;
    gzip on;
    gzip_types text/css application/javascript application/xml image/svg+xml application/manifest+json;

    # Admin and installer never exist on brand domains.
    location ~ ^/(admin|install|livewire[^/]*|filament|preview)(/|\$) { return 404; }

    location ^~ /_theme/ { alias {$public}/theme/; expires 1y; add_header Cache-Control "public, immutable"; }
    location ^~ /assets/ { expires 1y; add_header Cache-Control "public, immutable"; }
    location ^~ /media/  { expires 30d; add_header Cache-Control "public"; }
    location = /_t/ad    { try_files /nonexistent @php; }

    location / {
        add_header Cache-Control "public, max-age=300";
        # Directories without trailing slash → 301 to the canonical slash URL
        try_files \$uri \$uri/index.html @php;
    }

    # Misses go to PHP: real 404 page or 410 for deleted content.
    location @php {
        fastcgi_pass {$php};
        fastcgi_param SCRIPT_FILENAME {$public}/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        include fastcgi_params;
    }
}
NGINX;
    }

    public static function supervisor(): string
    {
        $base = base_path();

        return <<<CONF
; /etc/supervisor/conf.d/site-network.conf
[program:site-network-generation]
command=php {$base}/artisan queue:work redis --queue=generation --sleep=3 --tries=1 --timeout=1800
numprocs=2                      ; = max concurrent site generations
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
user=www-data
stopwaitsecs=1800

[program:site-network-content]
command=php {$base}/artisan queue:work redis --queue=content,default --sleep=3 --tries=1 --timeout=900
numprocs=3
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
user=www-data
stopwaitsecs=900
CONF;
    }

    public static function crontab(): string
    {
        return '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1';
    }
}
