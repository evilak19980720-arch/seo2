<?php

namespace App\Services\Install;

use App\Models\AiProvider;
use App\Models\User;
use App\Services\Integrations\Cloudflare;
use App\Services\Render\StaticSiteBuilder;
use App\Support\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PDO;
use Redis;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Shared logic of the web wizard (/install) and `php artisan app:install`.
 */
class Installer
{
    public const REQUIRED_EXTENSIONS = ['pdo_mysql', 'redis', 'mbstring', 'intl', 'curl', 'openssl', 'fileinfo', 'zip', 'bcmath'];

    public static function installed(): bool
    {
        return is_file(config('platform.installed_lock'));
    }

    /**
     * @return array<int, array{label: string, ok: bool, required: bool, hint: ?string}>
     */
    public function requirements(): array
    {
        $min = config('platform.min_php_version');
        $checks = [[
            'label' => "PHP ≥ {$min}（当前 ".PHP_VERSION.'）',
            'ok' => version_compare(PHP_VERSION, $min, '>='),
            'required' => true,
            'hint' => "升级 PHP 到 {$min} 或以上",
        ]];

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks[] = ['label' => "扩展 {$ext}", 'ok' => extension_loaded($ext), 'required' => true, 'hint' => "安装并启用 php-{$ext}"];
        }

        $imageOk = (extension_loaded('gd') && function_exists('imagewebp')) || (extension_loaded('imagick') && in_array('WEBP', \Imagick::queryFormats('WEBP'), true));
        $checks[] = ['label' => 'gd 或 imagick（支持 WebP）', 'ok' => $imageOk, 'required' => true, 'hint' => '安装 php-gd（带 libwebp）或 php-imagick'];
        $checks[] = ['label' => 'AVIF 输出（可选）', 'ok' => function_exists('imageavif') && (bool) (imagetypes() & IMG_AVIF), 'required' => false, 'hint' => 'GD 编译 libavif 后可额外输出 AVIF'];

        foreach (['storage' => storage_path(), 'bootstrap/cache' => base_path('bootstrap/cache'), '静态页输出目录' => config('platform.output_path'), 'public/theme' => public_path('theme')] as $label => $path) {
            @mkdir($path, 0775, true);
            $checks[] = ['label' => "{$label} 可写", 'ok' => is_writable($path), 'required' => true, 'hint' => "chown/chmod 使 PHP 进程可写：{$path}"];
        }
        $checks[] = ['label' => '.env 可写', 'ok' => is_writable(base_path('.env')) || (! file_exists(base_path('.env')) && is_writable(base_path())), 'required' => true, 'hint' => '确保项目根目录或 .env 可写'];

        $checks[] = ['label' => 'Node.js（Lighthouse 验收）', 'ok' => $this->binaryExists('node'), 'required' => false, 'hint' => '安装 Node.js 20+ 后 npm i -g lighthouse'];
        $checks[] = ['label' => 'Lighthouse CLI', 'ok' => $this->binaryExists((string) config('platform.lighthouse.bin')), 'required' => false, 'hint' => 'npm i -g lighthouse；未安装时跳过自动验收'];
        $checks[] = ['label' => 'Chromium（参考网站截图）', 'ok' => $this->binaryExists((string) config('platform.chromium_bin')), 'required' => false, 'hint' => '安装 chromium 并设置 CHROMIUM_BIN'];

        return $checks;
    }

    public function requirementsPass(): bool
    {
        return collect($this->requirements())->every(fn ($c) => $c['ok'] || ! $c['required']);
    }

    /** @return string MySQL version; throws on failure */
    public function testDatabase(array $db): string
    {
        $pdo = new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['database']}", $db['username'], $db['password'] ?? '', [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        if (str_contains(strtolower($version), 'mariadb') || version_compare(preg_replace('/[^0-9.].*$/', '', $version), config('platform.min_mysql_version'), '<')) {
            throw new \RuntimeException('需要 MySQL ≥ '.config('platform.min_mysql_version')."，当前 {$version}");
        }

        return $version;
    }

    public function testRedis(array $redis): string
    {
        $client = new Redis;
        $client->connect($redis['host'], (int) $redis['port'], 3);
        if (filled($redis['password'] ?? null)) {
            $client->auth($redis['password']);
        }

        return (string) ($client->info('server')['redis_version'] ?? 'ok');
    }

    public function detectPublicIp(): ?string
    {
        foreach (['https://api.ipify.org', 'https://ifconfig.me/ip'] as $url) {
            try {
                $ip = trim(Http::timeout(4)->get($url)->body());
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            } catch (Throwable) {
            }
        }

        return $_SERVER['SERVER_ADDR'] ?? null;
    }

    public function testCloudflare(string $token, ?string $accountId = null): string
    {
        return (new Cloudflare($token, filled($accountId) ? $accountId : null))->verifyToken();
    }

    public function testGoogle(string $json): string
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! isset($data['client_email'], $data['private_key'])) {
            throw new \RuntimeException('不是有效的服务账号 JSON');
        }
        $key = openssl_pkey_get_private($data['private_key']);
        if (! $key) {
            throw new \RuntimeException('私钥无法解析');
        }

        return $data['client_email'];
    }

    /**
     * Write .env, migrate, seed, create admin, store settings, write lock.
     *
     * @param  callable(string): void  $progress
     */
    public function install(array $data, callable $progress): void
    {
        $progress('写入 .env');
        $env = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://'.$data['system']['admin_domain'],
            'APP_TIMEZONE' => $data['system']['timezone'],
            'ADMIN_DOMAIN' => $data['system']['admin_domain'],
            'SERVER_PUBLIC_IP' => $data['system']['server_ip'],
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $data['db']['host'],
            'DB_PORT' => $data['db']['port'],
            'DB_DATABASE' => $data['db']['database'],
            'DB_USERNAME' => $data['db']['username'],
            'DB_PASSWORD' => $data['db']['password'] ?? '',
            'REDIS_HOST' => $data['redis']['host'],
            'REDIS_PORT' => $data['redis']['port'],
            'REDIS_PASSWORD' => filled($data['redis']['password'] ?? null) ? $data['redis']['password'] : 'null',
            'CACHE_STORE' => 'redis',
            'QUEUE_CONNECTION' => 'redis',
            'SESSION_DRIVER' => 'redis',
            // Empty: the secure flag follows the request scheme (see RouteByHost).
            'SESSION_SECURE_COOKIE' => '',
        ];
        if (! filled(config('app.key')) || ($data['regenerate_key'] ?? false)) {
            $env['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
        }
        EnvWriter::write(base_path('.env'), $env);
        $this->applyRuntimeConfig($env);

        $progress('运行数据库迁移');
        Artisan::call('migrate', ['--force' => true]);

        $progress('写入默认数据（提示词模板、护栏阈值）');
        Artisan::call('db:seed', ['--force' => true]);

        $progress('创建管理员账号');
        User::updateOrCreate(['email' => $data['admin']['email']], [
            'name' => $data['admin']['name'] ?? Str::before($data['admin']['email'], '@'),
            'password' => $data['admin']['password'],
            'role' => User::ROLE_ADMIN,
        ]);

        $progress('保存全局配置');
        $services = $data['services'] ?? [];
        Settings::setMany(array_filter([
            'server_ip' => $data['system']['server_ip'],
            'mfa_required' => (bool) ($data['admin']['mfa'] ?? false),
            'cloudflare_api_token' => $services['cloudflare_api_token'] ?? null,
            'cloudflare_account_id' => $services['cloudflare_account_id'] ?? null,
            'oss_driver' => $services['oss_driver'] ?? null,
            'oss_key' => $services['oss_key'] ?? null,
            'oss_secret' => $services['oss_secret'] ?? null,
            'oss_bucket' => $services['oss_bucket'] ?? null,
            'oss_region' => $services['oss_region'] ?? null,
            'oss_endpoint' => $services['oss_endpoint'] ?? null,
            'gsc_service_account' => $services['gsc_service_account'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        if (filled($services['ai_api_key'] ?? null)) {
            AiProvider::updateOrCreate(['name' => $services['ai_name'] ?: 'Default'], [
                'driver' => $services['ai_driver'] ?? 'openai',
                'base_url' => $services['ai_base_url'],
                'api_key' => $services['ai_api_key'],
                'model' => $services['ai_model'],
                'supports_vision' => (bool) ($services['ai_vision'] ?? false),
                'is_default' => true,
                'is_active' => true,
            ]);
        }

        $progress('发布前台共享资源');
        StaticSiteBuilder::publishSharedAssets();
        // Serve Livewire's JS as a real file: many Nginx setups answer *.js
        // from disk only, which 404s the /livewire-xxx/livewire.min.js route.
        Artisan::call('vendor:publish', ['--tag' => 'livewire:assets', '--force' => true]);
        Artisan::call('storage:link');

        $progress('写入安装锁文件');
        file_put_contents(config('platform.installed_lock'), now()->toIso8601String());
        Artisan::call('optimize:clear');
    }

    /** Make the freshly written .env effective inside the current process. */
    protected function applyRuntimeConfig(array $env): void
    {
        config([
            'app.key' => $env['APP_KEY'] ?? config('app.key'),
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $env['DB_HOST'],
            'database.connections.mysql.port' => $env['DB_PORT'],
            'database.connections.mysql.database' => $env['DB_DATABASE'],
            'database.connections.mysql.username' => $env['DB_USERNAME'],
            'database.connections.mysql.password' => $env['DB_PASSWORD'],
            'database.redis.default.host' => $env['REDIS_HOST'],
            'database.redis.default.port' => $env['REDIS_PORT'],
            'database.redis.default.password' => $env['REDIS_PASSWORD'] === 'null' ? null : $env['REDIS_PASSWORD'],
            'database.redis.cache.host' => $env['REDIS_HOST'],
            'database.redis.cache.port' => $env['REDIS_PORT'],
            'database.redis.cache.password' => $env['REDIS_PASSWORD'] === 'null' ? null : $env['REDIS_PASSWORD'],
            'cache.default' => 'redis',
            'platform.admin_domain' => $env['ADMIN_DOMAIN'],
            'platform.server_ip' => $env['SERVER_PUBLIC_IP'],
        ]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        DB::purge('mysql');
        app()->forgetInstance('cache');
        app()->forgetInstance('cache.store');
        Cache::clearResolvedInstances();
    }

    protected function binaryExists(string $bin): bool
    {
        if ($bin === '') {
            return false;
        }
        $p = Process::fromShellCommandline('command -v '.escapeshellarg($bin));
        $p->run();

        return $p->isSuccessful();
    }
}
