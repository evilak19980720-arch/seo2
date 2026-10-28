<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>安装向导 · 站群管理平台</title>
<style>
:root{--bg:#f5f6f8;--card:#fff;--text:#111827;--muted:#6b7280;--line:#e5e7eb;--primary:#d97706;--ok:#059669;--bad:#dc2626}
@media (prefers-color-scheme:dark){:root{--bg:#0b0f17;--card:#111827;--text:#f3f4f6;--muted:#9ca3af;--line:#1f2937}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.6 system-ui,-apple-system,"PingFang SC","Microsoft YaHei",sans-serif}
.wrap{max-width:860px;margin:0 auto;padding:32px 16px}
h1{font-size:22px;margin:0 0 4px}.muted{color:var(--muted)}
.steps{display:flex;flex-wrap:wrap;gap:6px;margin:20px 0}.steps span{padding:4px 10px;border-radius:999px;background:var(--line);font-size:13px}.steps .on{background:var(--primary);color:#fff}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:24px}
label{display:block;font-weight:600;margin:14px 0 4px}input,select,textarea{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:transparent;color:inherit;font:inherit}
textarea{min-height:120px;font-family:ui-monospace,monospace;font-size:13px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:640px){.row{grid-template-columns:1fr}}
.actions{display:flex;gap:10px;margin-top:22px;flex-wrap:wrap}
button,.btn{min-height:44px;padding:0 18px;border-radius:8px;border:0;background:var(--primary);color:#fff;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
button.ghost{background:transparent;color:var(--text);border:1px solid var(--line)}button:disabled{opacity:.5;cursor:not-allowed}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:14px}.alert.ok{background:#05966922;color:var(--ok)}.alert.bad{background:#dc262622;color:var(--bad)}
table{width:100%;border-collapse:collapse}td{padding:8px 4px;border-bottom:1px solid var(--line);vertical-align:top}
.pass{color:var(--ok);font-weight:600}.fail{color:var(--bad);font-weight:600}.warn{color:var(--primary);font-weight:600}
pre{background:#0b0f17;color:#e5e7eb;padding:14px;border-radius:8px;overflow:auto;font-size:12.5px;max-height:420px}
fieldset{border:1px solid var(--line);border-radius:10px;padding:4px 16px 16px;margin:16px 0}legend{padding:0 6px;font-weight:700}
</style>
</head>
<body>
<div class="wrap">
<h1>站群管理平台 · 安装向导</h1>
<p class="muted">按步骤完成安装，完成后向导自动关闭。也可在命令行运行 <code>php artisan app:install</code>。</p>

<div class="steps">
@foreach ($steps as $key => $label)
<span class="{{ $key === $step ? 'on' : '' }}">{{ $loop->iteration }}. {{ $label }}</span>
@endforeach
</div>

<div class="card">
@if (session('ok'))<div class="alert ok">{{ session('ok') }}</div>@endif
@if ($errors->any())<div class="alert bad">{{ $errors->first() }}</div>@endif

@switch($step)
@case('requirements')
<table>
@foreach ($requirements as $r)
<tr><td>{{ $r['label'] }}</td><td style="width:90px" class="{{ $r['ok'] ? 'pass' : ($r['required'] ? 'fail' : 'warn') }}">{{ $r['ok'] ? '通过' : ($r['required'] ? '未通过' : '可选') }}</td><td class="muted">{{ $r['ok'] ? '' : $r['hint'] }}</td></tr>
@endforeach
</table>
<form method="post" action="/install/requirements">@csrf<div class="actions"><button {{ $canContinue ? '' : 'disabled' }}>下一步</button><a class="btn" style="background:var(--line);color:var(--text)" href="/install">重新检测</a></div></form>
@break

@case('database')
@php($d = old() ?: ($data['db'] ?? []))
<form method="post" action="/install/database">@csrf
<div class="row"><div><label>主机</label><input name="host" value="{{ $d['host'] ?? '127.0.0.1' }}" required></div><div><label>端口</label><input name="port" value="{{ $d['port'] ?? 3306 }}" required></div></div>
<label>数据库名</label><input name="database" value="{{ $d['database'] ?? 'site_network' }}" required>
<div class="row"><div><label>用户名</label><input name="username" value="{{ $d['username'] ?? '' }}" required></div><div><label>密码</label><input name="password" type="password" value="{{ $d['password'] ?? '' }}"></div></div>
<p class="muted">需要 MySQL 8.0 或以上，数据库需预先创建（utf8mb4）。</p>
<div class="actions"><button class="ghost" name="test" value="1">测试连接</button><button>下一步</button></div>
</form>
@break

@case('redis')
@php($d = old() ?: ($data['redis'] ?? []))
<form method="post" action="/install/redis">@csrf
<div class="row"><div><label>主机</label><input name="host" value="{{ $d['host'] ?? '127.0.0.1' }}" required></div><div><label>端口</label><input name="port" value="{{ $d['port'] ?? 6379 }}" required></div></div>
<label>密码（可空）</label><input name="password" type="password" value="{{ $d['password'] ?? '' }}">
<div class="actions"><button class="ghost" name="test" value="1">测试连接</button><button>下一步</button></div>
</form>
@break

@case('system')
@php($d = old() ?: ($data['system'] ?? []))
<form method="post" action="/install/system">@csrf
<label>后台专用域名</label><input name="admin_domain" value="{{ $d['admin_domain'] ?? request()->getHost() }}" placeholder="admin.example.com" required>
<p class="muted">后台只能通过这个域名访问；所有品牌站域名访问 /admin 和 /install 都返回 404。</p>
<label>服务器公网 IP</label><input name="server_ip" value="{{ $d['server_ip'] ?? $detectedIp }}" required>
<p class="muted">用于 Cloudflare 批量解析，已自动检测，可修改。</p>
<label>系统时区</label><input name="timezone" value="{{ $d['timezone'] ?? 'America/Sao_Paulo' }}" required>
<div class="actions"><button>下一步</button></div>
</form>
@break

@case('admin')
@php($d = old() ?: ($data['admin'] ?? []))
<form method="post" action="/install/admin">@csrf
<label>管理员邮箱</label><input name="email" type="email" value="{{ $d['email'] ?? '' }}" required>
<div class="row"><div><label>密码</label><input name="password" type="password" required></div><div><label>确认密码</label><input name="password_confirmation" type="password" required></div></div>
<p class="muted">至少 10 位，包含大小写字母、数字和符号。</p>
<label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" name="mfa" value="1" style="width:auto" {{ ($d['mfa'] ?? false) ? 'checked' : '' }}> 首次登录时强制绑定两步验证（TOTP）</label>
<div class="actions"><button>下一步</button></div>
</form>
@break

@case('services')
@php($d = old() ?: ($data['services'] ?? []))
<form method="post" action="/install/services">@csrf
<p class="muted">以下均可跳过，之后在「全局配置」中补充。</p>
<fieldset><legend>Cloudflare</legend>
<label>API Token</label><input name="cloudflare_api_token" value="{{ $d['cloudflare_api_token'] ?? '' }}">
<p class="muted">所需权限：Zone · DNS · Edit；Zone · SSL and Certificates · Edit；Zone · Cache Purge · Purge；Zone · Zone Settings · Edit（自动添加域名还需 Zone · Zone · Edit）。</p>
<label>Account ID（自动添加新 Zone 时需要）</label><input name="cloudflare_account_id" value="{{ $d['cloudflare_account_id'] ?? '' }}">
</fieldset>
<fieldset><legend>对象存储</legend>
<div class="row"><div><label>类型</label><select name="oss_driver"><option value="s3">Amazon S3 / 兼容</option><option value="oss" @selected(($d['oss_driver'] ?? '') === 'oss')>阿里云 OSS</option></select></div><div><label>Bucket</label><input name="oss_bucket" value="{{ $d['oss_bucket'] ?? '' }}"></div></div>
<div class="row"><div><label>Access Key</label><input name="oss_key" value="{{ $d['oss_key'] ?? '' }}"></div><div><label>Secret</label><input name="oss_secret" type="password" value="{{ $d['oss_secret'] ?? '' }}"></div></div>
<div class="row"><div><label>Region</label><input name="oss_region" value="{{ $d['oss_region'] ?? '' }}"></div><div><label>Endpoint</label><input name="oss_endpoint" value="{{ $d['oss_endpoint'] ?? '' }}"></div></div>
</fieldset>
<fieldset><legend>第一个 AI 服务商</legend>
@php($presets = config('ai_providers'))
<div class="row"><div><label>服务商</label><select name="ai_preset" id="ai_preset">@foreach (collect($presets)->groupBy('group', preserveKeys: true) as $group => $items)<optgroup label="{{ $group }}">@foreach ($items as $key => $p)<option value="{{ $key }}" @selected(($d['ai_preset'] ?? 'openai') === $key)>{{ $p['label'] }}</option>@endforeach</optgroup>@endforeach</select></div><div><label>名称</label><input name="ai_name" id="ai_name" value="{{ $d['ai_name'] ?? 'OpenAI' }}"></div></div>
<input type="hidden" name="ai_driver" id="ai_driver" value="{{ $d['ai_driver'] ?? 'openai' }}">
<label>Base URL</label><input name="ai_base_url" id="ai_base_url" value="{{ $d['ai_base_url'] ?? 'https://api.openai.com/v1' }}">
<p class="muted" id="ai_hint"></p>
<div class="row"><div><label>API Key</label><input name="ai_api_key" type="password" value="{{ $d['ai_api_key'] ?? '' }}"></div><div><label>模型（可直接输入其他模型名）</label><input name="ai_model" id="ai_model" list="ai_models" value="{{ $d['ai_model'] ?? 'gpt-5' }}"><datalist id="ai_models"></datalist></div></div>
<script>
(() => {
  const presets = @json($presets);
  const $ = id => document.getElementById(id);
  const models = () => { const p = presets[$('ai_preset').value]; $('ai_models').innerHTML = (p.models || []).map(m => `<option value="${m}">`).join(''); $('ai_hint').textContent = p.hint || ''; return p; };
  models();
  $('ai_preset').addEventListener('change', () => {
    const p = models();
    $('ai_driver').value = p.driver; $('ai_base_url').value = p.base_url; $('ai_model').value = (p.models || [])[0] || ''; $('ai_name').value = p.label;
    const vision = document.querySelector('input[name=ai_vision]'); if (vision) vision.checked = !!p.vision;
  });
})();
</script>
<label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" name="ai_vision" value="1" style="width:auto" {{ ($d['ai_vision'] ?? false) ? 'checked' : '' }}> 支持视觉（图片输入）</label>
</fieldset>
<fieldset><legend>Google（Search Console / GA4）</legend>
<label>服务账号 JSON</label><textarea name="gsc_service_account">{{ $d['gsc_service_account'] ?? '' }}</textarea>
<p class="muted">在 Google Cloud 启用 Search Console API、Site Verification API、Analytics Data API；GA4 媒体资源需把该服务账号加为查看者。</p>
</fieldset>
<div class="actions"><button class="ghost" name="test" value="1">测试已填项</button><button>下一步</button></div>
</form>
@break

@case('run')
<p>将执行：写入 <code>.env</code>、生成 APP_KEY、运行数据库迁移与默认数据（提示词模板、护栏阈值）、创建管理员、保存外部服务配置，最后写入锁文件。</p>
<ul class="muted">
<li>数据库：{{ $data['db']['username'] ?? '?' }}@{{ $data['db']['host'] ?? '?' }}/{{ $data['db']['database'] ?? '?' }}</li>
<li>Redis：{{ $data['redis']['host'] ?? '?' }}:{{ $data['redis']['port'] ?? '?' }}</li>
<li>后台域名：{{ $data['system']['admin_domain'] ?? '?' }}，服务器 IP：{{ $data['system']['server_ip'] ?? '?' }}</li>
<li>管理员：{{ $data['admin']['email'] ?? '?' }}</li>
</ul>
<form method="post" action="/install/run" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='安装中…'">@csrf<div class="actions"><button>开始安装</button></div></form>
@break

@case('done')
<div class="alert ok">安装完成。现在可以通过本页地址（服务器 IP）或后台域名进入后台；配置好下面的 Nginx 和证书后，请改用 https://后台域名 访问。</div>
@if ($log ?? null)<ol class="muted">@foreach ($log as $line)<li>{{ $line }}</li>@endforeach</ol>@endif
<h3>1. Nginx 配置</h3><pre>{{ $config['nginx'] }}</pre>
<h3>2. Supervisor 队列进程</h3><pre>{{ $config['supervisor'] }}</pre>
<h3>3. crontab</h3><pre>{{ $config['cron'] }}</pre>
<p class="muted">后台域名本身也需要源站证书：接入 Cloudflare 后运行 <code>php artisan app:issue-cert {{ config('platform.admin_domain') }}</code>。</p>
<div class="actions"><a class="btn" href="/admin/login">进入后台登录</a></div>
@break
@endswitch
</div>
</div>
</body>
</html>
