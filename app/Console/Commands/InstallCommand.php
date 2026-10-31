<?php

namespace App\Console\Commands;

use App\Services\Install\Installer;
use App\Services\Install\ServerConfig;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;

/** CLI version of the /install wizard (same steps, same Installer). */
class InstallCommand extends Command
{
    protected $signature = 'app:install {--force : Re-run even if already installed}';

    protected $description = '安装站群管理平台（与 /install 向导步骤相同）';

    public function handle(Installer $installer): int
    {
        if (Installer::installed() && ! $this->option('force')) {
            $this->error('已安装（storage/installed.lock 存在）。如需重装请加 --force。');

            return self::FAILURE;
        }

        $this->info('1/7 环境检测');
        foreach ($installer->requirements() as $r) {
            $this->line(sprintf('  %s %s%s', $r['ok'] ? '<info>✓</info>' : ($r['required'] ? '<error>✗</error>' : '<comment>!</comment>'), $r['label'], $r['ok'] ? '' : '  — '.$r['hint']));
        }
        if (! $installer->requirementsPass()) {
            $this->error('必需项未通过，请修复后重试。');

            return self::FAILURE;
        }

        $data = [];

        $this->info('2/7 数据库');
        while (true) {
            $data['db'] = [
                'host' => text('MySQL 主机', default: '127.0.0.1'),
                'port' => text('端口', default: '3306'),
                'database' => text('数据库名', default: 'site_network'),
                'username' => text('用户名', required: true),
                'password' => password('密码'),
            ];
            try {
                $this->line('  连接成功：MySQL '.$installer->testDatabase($data['db']));
                break;
            } catch (Throwable $e) {
                $this->error('  '.$e->getMessage());
            }
        }

        $this->info('3/7 Redis');
        while (true) {
            $data['redis'] = ['host' => text('Redis 主机', default: '127.0.0.1'), 'port' => text('端口', default: '6379'), 'password' => password('密码（可空）')];
            try {
                $this->line('  连接成功：Redis '.$installer->testRedis($data['redis']));
                break;
            } catch (Throwable $e) {
                $this->error('  '.$e->getMessage());
            }
        }

        $this->info('4/7 系统基础');
        $data['system'] = [
            'admin_domain' => text('后台专用域名', placeholder: 'admin.example.com', required: true),
            'server_ip' => text('服务器公网 IP', default: (string) $installer->detectPublicIp(), required: true),
            'timezone' => text('系统时区', default: 'America/Sao_Paulo'),
        ];

        $this->info('5/7 管理员账号');
        $data['admin'] = [
            'email' => text('邮箱', required: true, validate: fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : '邮箱格式不正确'),
            'password' => password('密码（≥10 位，含大小写、数字、符号）', validate: fn ($v) => preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{10,}$/', $v) ? null : '密码强度不足'),
            'mfa' => confirm('首次登录时强制绑定两步验证？', default: true),
        ];

        $this->info('6/7 外部服务（可回车跳过）');
        $services = [
            'cloudflare_api_token' => password('Cloudflare API Token'),
            'cloudflare_account_id' => text('Cloudflare Account ID'),
            ...$this->askAiProvider(),
            'gsc_service_account' => textarea('Google 服务账号 JSON'),
        ];
        if ($services['cloudflare_api_token']) {
            $this->line('  Cloudflare: '.rescue(fn () => $installer->testCloudflare($services['cloudflare_api_token'], $services['cloudflare_account_id'] ?? null), fn ($e) => '失败 '.$e->getMessage()));
        }
        if ($services['gsc_service_account']) {
            $this->line('  Google: '.rescue(fn () => $installer->testGoogle($services['gsc_service_account']), fn ($e) => '失败 '.$e->getMessage()));
        }
        $data['services'] = $services;

        $this->info('7/7 执行安装');
        $installer->install($data, fn ($line) => $this->line('  · '.$line));

        $this->newLine();
        $this->info('安装完成。服务器配置：');
        $this->line(ServerConfig::nginx($data['system']['admin_domain']));
        $this->newLine();
        $this->line(ServerConfig::supervisor());
        $this->newLine();
        $this->line(ServerConfig::crontab());

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    protected function askAiProvider(): array
    {
        $presets = config('ai_providers');
        $key = select('AI 服务商', collect($presets)->map(fn ($p) => "[{$p['group']}] {$p['label']}")->all(), default: 'openai', scroll: 12);
        $preset = $presets[$key];

        return [
            'ai_preset' => $key,
            'ai_name' => $preset['label'],
            'ai_driver' => $preset['driver'],
            'ai_base_url' => text('AI Base URL', default: $preset['base_url'], hint: $preset['hint'] ?? ''),
            'ai_api_key' => password('AI API Key'),
            'ai_model' => text('AI 模型', default: $preset['models'][0] ?? '', hint: '推荐：'.implode(', ', $preset['models'])),
            'ai_vision' => confirm('该模型支持视觉？', default: (bool) $preset['vision']),
        ];
    }
}
