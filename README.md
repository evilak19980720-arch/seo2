# 站群管理平台（单站版）

Laravel 13 + Filament 5 的品牌官网站群平台：每个域名一个产品、一种语言，AI 生成设计配置与内容，平台输出纯静态 HTML，由 Nginx 按 Host 直接提供。

## 环境要求

- PHP 8.5+（扩展：gd、intl、mbstring、pdo_mysql、redis、zip）
- MySQL 8、Redis
- Node 不需要；验收用的 Lighthouse 与 Chromium 需另装（`npm i -g lighthouse`，`chromium` 在 PATH 中或设置 `CHROMIUM_BIN`）

## 安装

```bash
composer install --no-dev -o
cp .env.example .env && php artisan key:generate
```

然后二选一：

- 浏览器用服务器 IP 打开 `/install`，按向导填写数据库、Redis、管理员账号和后台域名；
- 或命令行：`php artisan app:install`。

安装器会写入 `.env`、迁移数据库、导入默认提示词，并输出 Nginx / Supervisor / crontab 配置。

## 运行组件

| 组件 | 说明 |
| --- | --- |
| Nginx | 后台域名走 PHP；品牌域名直接读 `storage/app/sites/{host}`，屏蔽 `/admin` `/install` |
| 队列 | 两组 Supervisor 进程：`generation` 队列（建站）与 `content,default` 队列（文章、重建），配置由安装器输出 |
| 计划任务 | `* * * * * php artisan schedule:run` |
| 证书 | Cloudflare Origin CA（ECC），`php artisan app:issue-cert {domain}` |

## 宝塔面板部署注意

品牌站域名绑定在后台站点上时，宝塔默认的静态缓存规则会让 `.css`、`.png` 请求直接去 `public/` 找文件而返回 404。请在站点配置文件里删除 `location ~ .*\.(gif|jpg|jpeg|png|bmp|swf)$` 和 `location ~ .*\.(js|css)?$` 两段，并加入：

```
location ^~ /_theme/ { alias /www/wwwroot/<项目目录>/public/theme/; expires 30d; access_log off; }
```

（新版每个品牌站都在自己的 `/assets/` 下有一份主题 CSS 和字体，不再引用 `/_theme/`；保留这段配置对旧页面无害。）

## SEO 规则（生成与渲染时自动执行）

- 首页标题只有品牌名、描述少于 70 字时，生成时重写，渲染时也会兜底补全。
- 首页 H1 必须包含品牌，季节和促销标题会挪到眉标题。
- 品牌资料里没有的金额、百分比、到账时间、「最好 / 第一 / 领先」等说法，会逐句删除。
- 核心页面字数不够（默认 400 词，首页 600 词，`.env` 里的 `PAGE_MIN_WORDS`），或者和参考站、其他站同类页面过于相似，会重新生成一次；仍不合格就设为 noindex，并撤出导航和 sitemap。
- 博客和更新公告少于 3 篇（后台可调）时不收录，也不进导航和 sitemap。
- 同一页面不重复使用同一张图片；CTA 不会指向首页或当前页，外链加 nofollow，注册和登录类按钮文字会被替换。
- html lang、og:locale 和 schema 使用「语言 + 目标国家」（如 en-PH）；FAQ 页输出 FAQPage，每页输出带更新日期的 WebPage。
- robots.txt 放行 AI 搜索爬虫、屏蔽 AI 训练爬虫，两项都可以在后台关闭。
- 每个站点使用自己的 class 名、CSS 文件名和统计路径，降低站群足迹。调试时可设 `RANDOMIZE_MARKUP=false`。
- 每次重建后会自动检查结构化数据；装了 Lighthouse 的话还会跑一次，不达标就发告警。Search Console 抽查中未收录比例过高时也会告警。

## 参考网站读取（可选：安装 Chromium）

很多参考网站是 JavaScript 渲染的，直接请求只能拿到空壳。服务器装上 Chromium 后，会自动用无头浏览器渲染读取，并截图交给视觉模型：

推荐安装 Google 官方的 chrome-headless-shell（专为服务器设计；完整版 Chromium 在没有桌面环境的服务器上常常卡住不退出）：

```
apt install -y chromium || yum install -y chromium   # 只为装好运行依赖库
cd /opt && V=$(curl -s https://googlechromelabs.github.io/chrome-for-testing/LATEST_RELEASE_STABLE) \
  && curl -sLO https://storage.googleapis.com/chrome-for-testing-public/$V/linux64/chrome-headless-shell-linux64.zip \
  && unzip -o chrome-headless-shell-linux64.zip && chmod -R 755 /opt/chrome-headless-shell-linux64
ldd /opt/chrome-headless-shell-linux64/chrome-headless-shell | grep "not found"   # 没有输出才算依赖齐全
# .env 中设置：CHROMIUM_BIN=/opt/chrome-headless-shell-linux64/chrome-headless-shell
```

CHROMIUM_BIN 留空或为 chromium 时，会依次查找 chrome-headless-shell、chromium、chromium-browser、google-chrome。PHP 需允许 proc_open、proc_get_status；站点开了防跨站（open_basedir）时，要把 /opt/chrome-headless-shell-linux64 加进去或关闭防跨站。

站点编辑页「参考网站」右侧的放大镜按钮可以检测能否读取，以及读到了什么。有人机验证（如 Cloudflare 防护）的网站无法自动读取，请上传首页截图，并在 AI 接口里勾选「支持视觉」。

## 使用流程

1. 全局配置：Cloudflare Token、Google 服务账号、AI 服务商、发布配额。
2. 域名管理：「从 Cloudflare 同步」拉取账号下全部域名（也可在平台内添加/删除），管理解析记录或批量设置解析，「一键接入」写入解析、SSL Full (strict) 和源站证书。
3. 网站管理：新建站点（根域名只能选未绑定的 Cloudflare 域名，一域一站），填写品牌资料，点击「AI 生成模版和页面」生成设计、核心页面和关键词库；验收通过后上线。
4. 文章按周配额自动生成、AI 审核、护栏检查后定时发布；发布后自动增量重建、清 CDN 缓存、推送 IndexNow。

## 测试

```bash
php artisan test
```
