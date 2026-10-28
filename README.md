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

## 使用流程

1. 全局配置：Cloudflare Token、Google 服务账号、AI 服务商、发布配额。
2. 域名管理：批量导入域名，一键接入 Cloudflare。
3. 网站管理：新建站点、填写品牌资料，点击「生成网站」生成设计、核心页面和关键词库；验收通过后上线。
4. 文章按周配额自动生成、AI 审核、护栏检查后定时发布；发布后自动增量重建、清 CDN 缓存、推送 IndexNow。

## 测试

```bash
php artisan test
```
