# AGENTS.md · 清关管理系统

面向在本仓库工作的开发者与 AI 代理。**仓库根目录（`/data/qingguan/app`）就是站点根目录（docroot）**，所以「代码目录」与「运行目录」是同一个地方——改动会立刻被线上/开发站点看到。

## 0. 一句话

货代/报关订单管理 SaaS：**PHP 8.3 原生（无框架、无 composer）+ Vue 3 global build（无 npm、无构建步骤）**，控制面/数据面双库多租户。前端是 `v-show` 保活的 iframe 多标签外壳，不是 SPA 路由。

## 1. 两个环境（事实来源）

| | 本机开发 | 线上生产 |
|---|---|---|
| 站点 | https://qingguan-dev.sflswall.com.cn | https://qingguan.sflswall.com.cn |
| 主机 | 本机 | `breeder`（SSH 别名；文档里用 `breeder.sflswall.com.cn`） |
| 系统 | Alibaba Cloud Linux 3 | Ubuntu |
| Web | Apache httpd（vhost 复制到 `/etc/httpd/conf.d/`） | Apache2 |
| Docroot | `/data/qingguan/app` | `/opt/qingguan`（属主 `www-data:www-data`） |
| PHP | 8.3.35，命令名 **`php83`**；系统 `php` 是 **7.4.33** | 全局 `php8.3-fpm`（reload apache2 即可，PHP 无需重启） |
| DB | **MariaDB 10.5.29** @127.0.0.1:3306，`qingguan_dev` + `qingguan_control_dev`，账号 `qingguan_dev` | **MySQL 8.0.46**，`qingguan` + `qingguan_control` |
| 专用档开通 | config 补 `provision_db` 段（dev 用 dev 强密码） | config 的 `provision_db` 段指向 `qg_provision` 账号（只管 `qingguan_t_%`），缺失时专用档审核通过报「未配置」并留 pending |
| 证书 | certbot webroot（HTTP-01），`/data/qingguan/var/letsencrypt/live/qingguan-dev.sflswall.com.cn/` | acme.sh tls-alpn-01，`/etc/ssl/qingguan/{fullchain,privkey}.pem` |
| 备份 | — | `/var/backups/qingguan/` |

- 本机 php-fpm：`/opt/remi/php83/root/usr/sbin/php-fpm -y /data/qingguan/app/ops/php83-fpm.conf`，监听 `127.0.0.1:9083`，以 `frappe` 用户跑（池配置故意省略 `user/group`，因为本机 `sudo` 只放行了 `dnf`/`mysql`/`cp` 与少数 `systemctl`）。PID/日志在 `/data/qingguan/var/`。重载：`kill -USR2 $(cat /data/qingguan/var/php83-fpm.pid)`。
- 证书续期走 `frappe` 的 crontab（`23 4,16 * * *` certbot renew + reload httpd），另有 `@reboot` 拉起 php-fpm。
- 日志：本机 `/var/log/httpd/qingguan-dev-{error,access}.log`；breeder `${APACHE_LOG_DIR}/qingguan-{error,access}.log`。
- vhost 文件在 `ops/apache/`（本机 `qingguan-dev-*`，breeder `breeder/qingguan-*`，后者**尚未入库**）。
- **迁移脚本必须 MySQL 8.0 与 MariaDB 10.5 两边都能跑**，别用 MySQL 8 专有语法。

## 2. 常用命令

全部在 `/data/qingguan/app` 下执行：

```bash
# 跑测试（78 个用例；HTTP 用例需要站点在跑，否则自动跳过）
php83 tests/run.php                     # 期望 78 passed, 0 failed
php83 tests/guard_no_raw_business_sql.php   # 期望 guard ok

# 只跑部分 / 指向临时站点
QG_BASE=http://127.0.0.1:8080 php83 tests/run.php

# 免 Apache 起本机站点（临时验证用）
php83 -S 127.0.0.1:8080 -t /data/qingguan/app tools/dev-server.php

# 升级 vhost / fpm 配置后
sudo cp ops/apache/qingguan-dev-ssl.conf /etc/httpd/conf.d/ && sudo systemctl reload httpd
kill -USR2 $(cat /data/qingguan/var/php83-fpm.pid)

# 应用数据面迁移（改代码之前跑；先备份）
mysqldump -h 127.0.0.1 -u qingguan_dev -p qingguan_dev > /tmp/qingguan_before.sql
mysql -h 127.0.0.1 -u qingguan_dev -p qingguan_dev < db/migration_20261015_multitenant.sql
mysql -h 127.0.0.1 -u qingguan_dev -p qingguan_dev < db/migration_20261015_role_rename.sql
mysql -h 127.0.0.1 -u qingguan_dev -p qingguan_dev < db/migration_20261015_order_log_detail.sql
```

发布到 breeder（`rsync` **故意不加 `--delete`**，避免清掉远程 `uploads/`）：

```bash
rsync -a --exclude '.git' --exclude 'api/config/config.php' --exclude 'uploads/' --exclude 'api/runtime/' \
  /data/qingguan/app/ breeder.sflswall.com.cn:/tmp/qg_release/
ssh breeder.sflswall.com.cn 'sudo rsync -a /tmp/qg_release/ /opt/qingguan/ && sudo chown -R www-data:www-data /opt/qingguan && sudo chmod -R 775 /opt/qingguan/uploads /opt/qingguan/api/runtime'
ssh breeder.sflswall.com.cn 'sudo systemctl reload apache2'
```

远程 `api/config/config.php` **不入库、由部署单独维护**，新增配置键（如 `control_db`）要在 breeder 上手工补。发布顺序：备份 → 迁移（在切代码**之前**，新代码依赖新结构）→ 同步代码 → reload → 验证。

## 3. 目录与代码地图

```
api/index.php          唯一入口：autoload(QG\) → 载入 config → 装异常/错误处理器 → 注册路由 → dispatch
api/src/App.php        配置门面、secret()、ip()、pageSize()、App::log()
api/src/Router.php     显式路由 + class Req（请求取值：input/str/int/page/require_/date）
api/src/Response.php   统一响应 {code,msg,data}
api/src/Auth.php       HMAC-SHA256 签名 token（HttpOnly cookie qg_token）、scope、权限码
api/src/Tenant.php     每请求租户上下文 + 按库名缓存的 PDO 池
api/src/Db.php         数据面薄封装，**写死走 Tenant::pdo()**
api/src/ControlDb.php  控制面连接（跨租户对象）
api/src/controllers/   10 个：Auth/Bill/Order/Plan/Org/Finance/System/Upload + Platform（平台登录与租户审核）/Tenant（注册/品牌公开接口 + Logo 上传）
api/src/repos/         13 个：Order/Bill/Finance/User/Permission/Role/Company/Plan/Log/Dict/File/Config（+ProvisionRepo 开通播种）
api/src/RateLimit.php  文件型滑动窗口限流（api/runtime/ratelimit/，fail-open）
api/src/TenantProvisioner.php  幂等开通：按 tenant_id 执行 db/schema.sql 播种 + 建管理员（SQL 在 repos/ProvisionRepo）
index.html             外壳：左侧菜单(来自 /api/menu) + 保活 iframe 多标签 + 版本水印 + 租户品牌（名称/Logo）
login.html             登录页（租户代码 + 用户名 + 密码；输入租户代码失焦拉 /api/tenant/branding 显示企业品牌）
register.html          企业自助注册页（公开，→ saas_tenant status=pending）
admin-login.html       平台管理登录页（公开，→ /api/platform/login）
platform.html          平台后台：租户审核（通过/驳回/停用/启用），scope=platform；页面在 common.js 之前设 window.QG_LOGIN_URL 跳回平台登录
pages/*.html           17 个子页面
assets/js/common.js    全局 QG：API 封装、分页列表 mixin、下拉缓存、QG.can、QG.selfCheck
assets/js/orders.js    QG.orderListPage(module) 生成 4 个订单列表页；assets/js/org.js 同理
assets/vendor/         Vue 3 + Element Plus + icons，本地文件，无 CDN、无 npm
tools/dev-server.php   php -S 的 /api/* 转发路由
tools/selfcheck.html   诊断页（故意不进菜单），可用 QG.selfCheck() 从控制台调
api/install.php        装机用一次性页面，**本仓库遗留、已废弃**（见 §8）
tests/                 零依赖测试 + 守卫脚本
db/                    控制面 / 数据面 schema、迁移、种子
docs/superpowers/      specs（设计）+ plans（实现计划，含执行记录）
ops/                   Apache vhost 与 php83-fpm 池配置
```

## 4. 后端约定

**响应信封**：`{code, msg, data}`，`code=0` 才是成功。`Response::fail()` 的 **HTTP 状态默认 200**，业务失败靠 `code` 表达（前端 `common.js` 只看 `code`）。`code=401` 表示未登录。CLI 下 `fail()` 改为抛 `RuntimeException`——这样测试才能断言失败路径，所以测试里可以用异常断言。

**路由**：显式声明，非约定式。`Router::add($method, '/orders/{id}', [Ctrl::class, 'method'], $auth = true)`，`{name}` 参数以关联数组传给 handler。`$auth=true` 只做「登录了没」的粗粒度门禁；细粒度权限（`Auth::can('system.role')`）写在各 handler 里。新增 controller 要在 `api/index.php` 里按顺序注册（顺序有意义）。

**认证**：不是 session、不是标准 JWT——是 `b64(payload).b64(hmac_sha256(payload, app.secret))` 放在 HttpOnly cookie `qg_token` 里。两种 scope：
- `tenant`：靠 `saas_tenant` 解析租户，要求 `status='active'`；`tenant_admin` 角色短路为权限 `['*']`，其余走 `PermissionRepo::codesForRole()`。
- `platform`：读 `saas_platform_user`，**不设租户上下文**。平台侧已由 `PlatformController` 接线（`/api/platform/*`，全部经 `Auth::requirePlatform()`，令牌互不通用：租户令牌调平台接口 403，反之平台令牌无租户上下文 403）。平台超管靠 `php83 scripts/seed_platform_admin.php` CLI 播种（无注册入口）。

**多租户连接选择**：唯一变量是 `Tenant::pdo()`——`mode==='dedicated' && db_name` 用专用库，否则用共享库 `App::config('db')['name']`。连接按库名缓存在 `Tenant::$pools`。没有租户上下文就 403。

**专用档（dedicated）开通**（2026-10-15 起已实现，规格见 `docs/superpowers/specs/2026-10-15-dedicated-provisioning-design.md`）：审核通过即全自动 `CREATE DATABASE qingguan_t_<code>` + 建 `qg_<code>` 账号（密码 = HMAC-SHA256('db-pass:'+code, app.secret) 的 hex，**不落库**，`api/src/DedicatedDb.php` 派生）+ 灌 schema.sql 播种。全链路幂等，失败留 pending，重新点「通过」即续跑；**系统绝不自动 DROP**，库/账号清理走手工。`app.secret` 轮换会使全部专用库账号密码失效——用新 secret 现算派生值，对每个账号跑 `ALTER USER `qg_<code>`@`<host>` IDENTIFIED BY '<新派生hex>'`。专用库出现后，表结构迁移 = 共享库跑一遍 + `php83 scripts/migrate_dedicated.php <file>` 逐库跑（`--dry-run` 先看清单）。运维监控点：`saas_audit` 的 `provision_failed` 记录即开通失败告警源（当前人工查看）。secret 轮换时生成新派生值的现成命令：`php83 -r 'echo hash_hmac("sha256", "db-pass:<code>", "<新secret>");'`。

**账单与服务期**（2026-10-16 起，规格见 `docs/superpowers/specs/2026-10-16-billing-expiry-design.md`）：控制面 `saas_bill`（unpaid/paid/void 状态机，金额为生成时套餐价快照）+ `saas_tenant.expires_at/bill_cycle`。注册选周期即生成首张账单；审核通过 `expires_at=NOW()`（30 天试用）；平台「确认收款」在事务内按 `Billing::nextExpiry` 三分支重算到期日（未到期/宽限内=旧值+周期；超宽限=now+周期）；宽限 30 天（`Billing::GRACE_DAYS`），超宽限登录与会话一律拦截；`expires_at` 为 NULL 的租户（如 demo）永不参与到期逻辑。租户侧条幅与「账单中心」页消费 `/auth/me` 的 `billing` 字段。

**仓储层是硬约束（有守卫脚本）**：`tests/guard_no_raw_business_sql.php` 会红灯，如果
1. `api/src/repos/` **之外**（`Db.php`/`ControlDb.php` 除外）出现 `Db::all|one|val|exec|insert|update`；
2. 仓储里某个引号字符串含 SELECT/INSERT/UPDATE/DELETE 且提到 21 张业务表之一，却没出现 `tenant_id`。

规矩：controller 只做校验（`Req`）、调仓储、包事务（`Db::begin/commit/rollback` 是唯一豁免）、写日志、回响应。仓储是全静态类（`create(array): int` / `update(int, array): int` / `find` / `page(...): array` 返回 `[$rows,$total]`），**凡触业务表的方法都要 `Tenant::requireId()` 且 SQL 里带 `tenant_id = ?`**，`create()` 注入 `$data['tenant_id']`。`tenant_id = ?` 要写在字符串字面量里（守卫按字面量判断）；排序字段要白名单。跨表子查询也要重复 `x.tenant_id = y.tenant_id`。

## 5. 前端约定

- **无构建**：每个 HTML 页按固定顺序引 4 个本地 script（vue、element-plus、icons、`/assets/js/common.js`），然后 `QG.mount(...)`。
- **外壳是 iframe 多标签**：菜单来自 `GET /api/menu`（最多两级），点菜单 push 一个 tab 并用 `v-show` 保活 iframe（切 tab 不重载）。`frameSrc()` 带 `?_v=<build>`，`QG.fetchBuild()` 每 180 秒轮询 `/version.json`，build 变了弹通知让用户手动刷新。
- **`QG` 全局**（`assets/js/common.js`）：`QG.get/post/put/del/upload`、`QG.options()`（`/dict/options` 的进程内缓存）、`QG.mount`、`QG.me`（缓存到 `QG_USER`）、`QG.can(code)`（读 `QG_USER.permissions`，支持 `'*'`）、`QG.listPage(cfg)`（标准列表页 mixin）、`QG.selfCheck()`。任何非 GET 写到 `plans|companies|dicts?|configs` 会自动失效下拉缓存并广播给其他 iframe。
- **加一个页面要三件事**：① `pages/` 下新增 HTML；② API 路由；③ **往 `sys_permission` 插一行**——菜单 100% 由库驱动，不是前端写死的。可见需 `status=1`、`type=1`、`parent_id` 合法（或 0）且角色在 `sys_role_permission` 里有授权（只授子节点也能渲染，祖先由 `AuthController::menu()` 自动补）。`type=2` 是按钮级权限，不进菜单。UI 路径：系统设置 → 权限管理 → 菜单节点。
- 按钮置灰用 `QG.can('order.xxx.new')`（服务端会再查一次）。注意 `QG.mount` 不预载 `QG_USER`，页面里要在 `created()` 里异步取（`assets/js/orders.js` 有注释说明这个坑）。

## 6. 数据库

**控制面库**（`db/control/schema.sql`，装机时跑一次）：`saas_plan`、`saas_tenant`（`code` 唯一、`mode shared|dedicated`、`db_name`、`status pending|active|rejected|suspended`、`bill_cycle`/`expires_at` 服务期）、`saas_platform_user`、`saas_audit`、`saas_bill`。`db/control/seed_tenant1.sql` 把原有单机数据登记成租户 #1（`code='demo'`）。

**数据面库**（共享库或每租户专用库，21 张表）：7 张 `sys_*` + `org_company`、`biz_plan`、`biz_order` 及子表、`biz_isf`、`biz_inspect`、`biz_pickup`、`fin_account`、`fin_recharge`、`fin_payable`。**所有业务表都带 `tenant_id`**，唯一键已改造成 `uk_tenant_*` 复合键，另有 `idx_tenant_list`/`idx_tenant_order`。

**两套迁移命名，别混**：
- `upgrade-YYYY-MM-DDx.sql`：遗留的**厂商版**增量脚本（宝塔/Nginx 单租户装机用），只含 `INSERT/SELECT/UPDATE`，靠唯一键 upsert 保证幂等，无 DDL。
- `migration_YYYYMMDD_<slug>.sql`：多租户时代的**开发者迁移**，会 `ALTER TABLE`。幂等写法为 `information_schema` 探查 + `SET @s := IF(...)` + `PREPARE/EXECUTE`（刻意避开 MySQL 8 专有语法，好让 MariaDB 10.5 也能跑）。

**`db/schema.sql` 是数据面库的唯一权威**（2026-10-15 起改口，替代旧的「只作参考」）：

> **新建租户库 / 开租播种都用它；任何一次表结构变更也必须同步改它的 DDL 段。一致性由 `tests/test_schema_parity.php` 把住（逐列逐键比对，漂移即红）。**

文件内容 = 21 张表结构（与开发库逐列逐键一致）+ 租户参数化的产品默认数据（菜单/权限、3 个产品角色、默认授权、配置默认项、功能字典）。它**幂等**（DDL 全部 `IF NOT EXISTS`、数据全部 `ON DUPLICATE KEY UPDATE`），且用 `SET @tenant_id := <id>` 指定目标租户（默认 1）。**不预置**：`sys_user`（管理员由开通流程按控制面 `saas_tenant.admin_*` 创建）、`biz_plan` 等业务数据。用法：

```bash
# 新装 / 租户 #1（不 SET 默认 1）
mysql -u<user> -p <库名> < db/schema.sql
# 为租户 <id> 建库/播种
mysql -u<user> -p <库名> -e "SET @tenant_id := <id>; SOURCE db/schema.sql;"
```

⚠️ 但**本机开发库仍必须从生产库整库克隆（结构 + 数据），不要拿 `db/schema.sql` 建 dev 库**——schema.sql 只管结构与默认数据，不带生产业务数据；dev 库的价值在于它是「生产克隆 + 全部迁移」的活副本，parity 测试就是拿它当参照物。历史教训：dev 库用旧 schema.sql 建、线上库用厂商数据导出恢复，两者差 373 行，导致迁移在生产上「旧唯一键没删掉、新的加上了」。

配套规矩：**迁移脚本不许假设旧唯一键/索引的名字**，要按「哪个键恰好覆盖这些列」从 `information_schema` 查真实键名再操作。`db/check-menu-node.sql` 是 9 步「菜单不显示」排查/修复脚本（纯 SELECT + 幂等 INSERT/UPDATE，可整段粘贴执行），不是迁移。

**已知未落地**：`docs/superpowers/plans/2026-10-14-multi-tenant-ops-infrastructure.md` 规划的 `db/migrations/NNNN_*.sql` + `scripts/{fanout,migrate,export_dict_template,publish_dict}.php` + `schema_version` 表 + 平台字典下发**尚未实现**（`db/migrations/` 不存在；`scripts/` 已有 `seed_platform_admin.php` 与 `migrate_uploads_namespace.php` 两个独立脚本，但计划里的那批还没有）。当前迁移仍以 `db/migration_20261015_*.sql` 手工执行。做这件事时按该计划走，不要另发明机制。

## 7. 测试

- 零依赖自建断言器：`tests/lib.php` 提供 `t($name, $fn)`、`assert_true/assert_eq/assert_contains`；`tests/run.php` glob `tests/test_*.php` 后逐个跑，打印 `PASS/FAIL` 并在失败时退出非零。
- **测试连的是 `api/config/config.php` 指向的真实开发库**（`bootstrap.php` 读 `App::config('db')` / `control_db`）——**没有独立测试库，也没有读 `.env`**。隔离靠使用高号段合成租户 id（9001/9002/9101…）并在用例结束时清理。因为直连真实库，别在测试里做破坏性操作。
- HTTP 用例走 **`curl` 命令行**（`tests/http.php`，因为 breeder 上没装 `php-curl`），默认打 `QG_BASE` 或 `https://qingguan-dev.sflswall.com.cn`；站点不通会**自动跳过**（`qg_site_up()` 探测 `/api/auth/me`）。部分用例依赖已播种的 `demo / admin / admin123`。
- 预期基线：`php83 tests/run.php` → `78 passed, 0 failed`；守卫 → `guard ok`。**这两个不绿不要提交。**
- 限流与测试的交互：`register` 5 次/小时/IP、`platform-login` 10 次/10 分钟、`tenant-login` 20 次/10 分钟（存 `api/runtime/ratelimit/`）。一次全量套件恰好用满部分桶——**`tests/bootstrap.php` 在套件启动时会自动清空该目录**，所以重复跑全绿；手工打接口验证时若撞 429，`rm -rf api/runtime/ratelimit/*` 即可。
- **结构把闸**：`tests/test_schema_parity.php` 会把 `db/schema.sql` 里的 21 条 CREATE TABLE 落到当前库（`__parity__` 前缀临时表）再与真实表逐列逐键比对——**改了 `db/migration_*.sql` 的结构却忘了同步 `db/schema.sql` 的 DDL 段时它会红**。这是「每次表结构变更也要维护好 db/schema.sql」的强制手段。

## 8. 部署与运维纪律

- **必须用 `php83`**，裸 `php` 是 7.4，连语法都过不了。
- 需要的 PHP 扩展：`pdo_mysql`、`mbstring`、`fileinfo`、`json`、`openssl`（`curl` 扩展在生产缺失，别在代码里用）。
- 只允许 `uploads/` 与 `api/runtime/` 可写（775），其余只读。
- vhost 已禁止 Web 访问 `db/`、`ops/`、`tests/`、`docs/`、`.git/`、`install.php`、`api/runtime/`（限流计数文件），以及 `*.sql|md|log|ini|conf|sh|env|bak`；`uploads/` 禁止执行 `.php/php5/phtml/phar`。HTML/JS/CSS 为 `no-cache`，`version.json` 为 `no-store`——**改完前端要重新生成 `version.json` 的 `build`**（无打包脚本时可取 `git rev-parse --short=10 HEAD`），否则已打开的页面不会刷新。（`common.js` 里提到生成脚本 `_tools/pack-zips.py`，该文件不在本仓库中。）
- `api/config/config.php`（从 `config.sample.php` 复制）**含密钥、不入库**：`db` / `control_db` / `app`（`secret` 必须换掉、`token_ttl`、`upload_dir`、`upload_url`、`max_upload`、`allow_ext`、`page_size`、`debug`）。`.env` 也不入库，但**代码里没读它**（只有 `tests/http.php` 读环境变量 `QG_BASE`）。
- **`api/install.php` 是遗留物、别用**：它插 `sys_user` 时**不写 `tenant_id`**（多租户前的代码），而且无鉴权——vhost 已把它禁掉。
- `部署说明.md` 是**厂商的宝塔/Nginx 版说明，已经过时**（里面还写着 `role_code='super'`，实际已改名为 `tenant_admin`）。**本仓库的部署事实以 `ops/` 与 `docs/superpowers/` 为准。**

## 9. 已知坑（改代码前先看一眼）

1. **严格 SQL 模式**：空值写 `NULL`，别写 `''`。`sys_log.target_id` 是整型列，写 `''` 会直接报 `Incorrect integer value`（「系统设置 → 保存」那条日志就踩过）。
2. **「加页面」不等于「加菜单」**：菜单在 `sys_permission` 里，不插行左侧栏就什么都不显示。别硬编码节点 id（历史上 `parent_id=40` 造出过孤儿节点）；注意「悬空授权」（授了子节点没授父分组）；最多两级；改完菜单按 `Ctrl+F5`。
3. **权限只控制菜单可见性，不控制数据**——数据隔离靠 `tenant_id`（见 §4 守卫）。唯一例外是订单新建的 5 个按钮权限（按 `from_module` 判，否则 403）。
4. **平台侧已接线但权限树仍有层级坑**：`SystemController::permissionTree()` 还需支持任意层级（按钮是第 3 级），否则按钮权限会静默消失。
5. 下拉缓存的正则 `OPTS_SOURCES` 必须是 `dicts?`（复数）——早先写成单数 `dict` 造成过「新字典项不出现在下拉框」的真实 bug。
6. 附件路径已按租户分目录 `uploads/t<tid>/order/Y/m/`（2026-10-15 起），存量旧路径 `order/...` 由 `php83 scripts/migrate_uploads_namespace.php` 搬迁（幂等，先 `--dry-run`）。**在 breeder 上跑迁移要用 `sudo -u www-data php83 ...`（或跑完 `chown -R www-data:www-data /opt/qingguan/uploads`）**——root 跑会让新目录属主变 root，php-fpm 之后写不进去。
7. **面向物理隔离档（`mode='dedicated'`）**：专用库已随 woniu 租户首次落地（`qingguan_t_woniu`）；写多库逻辑时要能正确处理「N 个专用库」，迁移扇出用 `php83 scripts/migrate_dedicated.php <file>`（`--dry-run` 先看清单）。开通播种器对专用档全自动开通（2026-10-15 起）。
8. **schema.sql 的语句字符串内不得含 ASCII 分号**：开通播种由 `ProvisionRepo::splitStatements()` 去注释行后按 `;` 切段执行，值里混入 `;` 会切坏语句。同理行尾注释必须 `-- `（带空格）。改 schema.sql 前先看 `ProvisionRepo` 头注释的三条约定。
9. **`qg_provision` 是高价值凭据但权限收敛**：仅全局 CREATE/CREATE USER + `qingguan_t_%`.* 的 ALL——读不到任何现有业务数据。provision_db 未配置时专用档开通报「未配置」并留 pending，共享档不受影响。专用档租户上下文必须带 `code`（派生凭据用），手工 `Tenant::set` 造 dedicated 上下文而不带 code 会抛「缺少 code」。
10. **`saas_bill` 确认收款必须走 `PlatformController::confirmBill` 的事务路径**（账单 paid 与 `saas_tenant.expires_at` 重算两写原子）——手工改库会造成账单与到期日不一致，且 paid 是终态无法重放。

## 10. 提交与分支

- 提交信息用**中文**，沿用 Conventional Commits 前缀：`feat:` / `fix:` / `refactor:` / `test:` / `chore:` / `docs:`（看 `git log` 对齐风格）。
- 分支模型（2026-10-15 起）：`main` 为集成分支（与线上 dev 站点同步演进），功能开发开 `feat/<topic>` 分支、完成并审查后 fast-forward 回 `main`。《自助开通与品牌》即以 `feat/multi-tenant-foundation` 方式并入（该分支已合并，保留未删）。
- 远端 `origin` = `git@github.com:lilingyun-outlookcom/qingguan.git`。
- 本仓库**没有 CI、没有 `composer.json`、没有 `package.json`**——「提交前跑一遍 `php83 tests/run.php` + 守卫」就是全部门禁。
- 小步提交：多租户改造面很大（原本 163 处 `Db::` 调用），逐个 controller/仓储迁移并保持测试常绿。
