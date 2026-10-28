<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Services\Ai\AiManager;
use App\Services\Install\Installer;
use App\Services\Install\ServerConfig;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Web installation wizard; step data is kept in the (file) session until "执行安装". */
class InstallController
{
    public const STEPS = ['requirements' => '环境检测', 'database' => '数据库', 'redis' => 'Redis', 'system' => '系统基础', 'admin' => '管理员账号', 'services' => '外部服务', 'run' => '执行安装'];

    public function __construct(protected Installer $installer) {}

    public function show(Request $request, string $step = 'requirements')
    {
        abort_unless(isset(self::STEPS[$step]) || $step === 'done', 404);
        if ($step === 'done') {
            abort_unless(Installer::installed(), 404);
        }

        return view('install.wizard', [
            'step' => $step,
            'steps' => self::STEPS,
            'data' => $request->session()->get('install', []),
            'requirements' => $step === 'requirements' ? $this->installer->requirements() : [],
            'canContinue' => $step !== 'requirements' || $this->installer->requirementsPass(),
            'detectedIp' => $step === 'system' ? $this->installer->detectPublicIp() : null,
            'config' => $step === 'done' ? ['nginx' => ServerConfig::nginx(), 'supervisor' => ServerConfig::supervisor(), 'cron' => ServerConfig::crontab()] : null,
        ]);
    }

    public function store(Request $request, string $step)
    {
        $data = $request->session()->get('install', []);
        $test = $request->boolean('test');

        try {
            switch ($step) {
                case 'requirements':
                    abort_unless($this->installer->requirementsPass(), 422);

                    return redirect('/install/database');

                case 'database':
                    $db = $request->validate([
                        'host' => 'required', 'port' => 'required|integer', 'database' => 'required', 'username' => 'required', 'password' => 'nullable',
                    ]);
                    $version = $this->installer->testDatabase($db);
                    $request->session()->put('install.db', $db);
                    if ($test) {
                        return back()->with('ok', "连接成功，MySQL {$version}")->withInput();
                    }

                    return redirect('/install/redis');

                case 'redis':
                    $redis = $request->validate(['host' => 'required', 'port' => 'required|integer', 'password' => 'nullable']);
                    $version = $this->installer->testRedis($redis);
                    $request->session()->put('install.redis', $redis);
                    if ($test) {
                        return back()->with('ok', "连接成功，Redis {$version}")->withInput();
                    }

                    return redirect('/install/system');

                case 'system':
                    $request->session()->put('install.system', $request->validate([
                        'admin_domain' => ['required', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
                        'server_ip' => 'required|ip',
                        'timezone' => 'required|timezone',
                    ]));

                    return redirect('/install/admin');

                case 'admin':
                    $request->session()->put('install.admin', $request->validate([
                        'email' => 'required|email',
                        'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()->symbols()],
                        'mfa' => 'nullable|boolean',
                    ]));

                    return redirect('/install/services');

                case 'services':
                    $services = $request->except(['_token', 'test']);
                    if ($test) {
                        $msgs = [];
                        if (filled($services['cloudflare_api_token'] ?? null)) {
                            $msgs[] = 'Cloudflare: '.$this->installer->testCloudflare($services['cloudflare_api_token']);
                        }
                        if (filled($services['gsc_service_account'] ?? null)) {
                            $msgs[] = 'Google 服务账号: '.$this->installer->testGoogle($services['gsc_service_account']);
                        }
                        if (filled($services['ai_api_key'] ?? null)) {
                            $provider = new AiProvider(['name' => 'test', 'driver' => $services['ai_driver'] ?? 'openai', 'base_url' => $services['ai_base_url'] ?? '', 'api_key' => $services['ai_api_key'], 'model' => $services['ai_model'] ?? '']);
                            $msgs[] = 'AI: '.$this->testAi($provider);
                        }
                        $request->session()->put('install.services', $services);

                        return back()->with('ok', implode('；', $msgs) ?: '没有可测试的项')->withInput();
                    }
                    $request->session()->put('install.services', $services);

                    return redirect('/install/run');

                case 'run':
                    foreach (['db', 'redis', 'system', 'admin'] as $required) {
                        if (empty($data[$required])) {
                            return redirect('/install/'.($required === 'db' ? 'database' : $required))->withErrors(['step' => '请先完成此步骤']);
                        }
                    }
                    $log = [];
                    $this->installer->install($data, function ($line) use (&$log) {
                        $log[] = $line;
                    });
                    $request->session()->forget('install');

                    return redirect('/install/done')->with('log', $log);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return back()->withErrors(['step' => $e->getMessage()])->withInput();
        }

        abort(404);
    }

    protected function testAi(AiProvider $provider): string
    {
        return app(AiManager::class)->test($provider);
    }
}
