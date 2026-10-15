# 自助开通与品牌 实现计划

> **面向 AI 代理的工作者：** 必需子技能：使用 superpowers:subagent-driven-development（推荐）或 superpowers:executing-plans 逐任务实现此计划。步骤使用复选框（`- [ ]`）语法来跟踪进度。

**目标：** 打通「企业自助注册 → 平台审核 → 自动开通播种 → 登录可用」链路，并交付租户 Logo 与附件按租户命名空间。

**架构：** 控制面复用现有 4 张 `saas_*` 表（不加表）；新增 `PlatformController`（`/api/platform/*`，scope=platform 隔离）与 `TenantController`（公开注册/品牌接口 + Logo 上传）；开通播种由 `TenantProvisioner` + `ProvisionRepo` 幂等执行 `db/schema.sql`；前端新增 `register.html` / `admin-login.html` / `platform.html` 三个顶层页。

**技术栈：** PHP 8.3（`php83`，无框架无 composer）、Vue 3 global build（无 npm 无构建）、MariaDB 10.5（dev）/ MySQL 8.0（prod）、零依赖自建测试（`tests/run.php`）。

**设计稿：** `docs/superpowers/specs/2026-10-15-self-service-onboarding-branding-design.md`（已评审）。

**环境铁律（摘自 AGENTS.md）：**
- 一律用 `php83`；DB 管理只能 `sudo -n mysql`（dev 库 `qingguan_dev` + `qingguan_control_dev`）。
- 提交前必须 `php83 tests/run.php` 全绿 + `php83 tests/guard_no_raw_business_sql.php` 输出 `guard ok`。当前基线 **31 passed, 0 failed**。
- 测试直连 dev 真实库；合成数据用高号段租户 id（9001+）并在用例末尾清理。
- 提交信息用中文 + Conventional Commits 前缀；分支 `feat/multi-tenant-foundation`。
- controller 里直接调 `ControlDb::` 是现状惯例（控制面表不受仓储守卫约束）；数据面 SQL 必须走 `repos/`。

---

## 文件结构

| 文件 | 职责 | 动作 |
|---|---|---|
| `api/src/Auth.php` | 加 `platformLogin()` / `requirePlatform()`；`verify()` 放行 `puid` payload | 修改 |
| `api/src/RateLimit.php` | 文件型滑动窗口限流器（存 `api/runtime/ratelimit/`） | 创建 |
| `api/src/controllers/PlatformController.php` | 平台登录/登出/me + 租户列表/approve/reject/suspend/enable | 创建 |
| `api/src/controllers/TenantController.php` | 公开：plans/code-check/register/branding；租户侧：logo 上传 | 创建 |
| `api/src/TenantProvisioner.php` | 幂等开通：播种 + 建管理员 + 置 active + 审计 | 创建 |
| `api/src/repos/ProvisionRepo.php` | 数据面播种执行（读 schema.sql 切分执行）+ 建管理员 SQL | 创建 |
| `api/index.php` | 注册两个新 controller | 修改 |
| `api/src/controllers/UploadController.php` | `$sub` 加 `t<tid>/` 前缀 | 修改 |
| `scripts/seed_platform_admin.php` | CLI 播/重置平台超管（bcrypt） | 创建 |
| `scripts/migrate_uploads_namespace.php` | 存量附件搬迁到 `t<tid>/`，`--dry-run` 支持 | 创建 |
| `admin-login.html` | 平台登录页（公开） | 创建 |
| `platform.html` | 平台后台：待审 + 全部租户列表，行内审核操作 | 创建 |
| `register.html` | 企业自助注册页（公开） | 创建 |
| `login.html` | 租户代码失焦拉品牌（名称 + Logo） | 修改 |
| `index.html` | 侧栏品牌区支持租户 Logo / 名称 | 修改 |
| `pages/sys-config.html` | 加「企业 Logo」上传卡片 | 修改 |
| `assets/js/common.js` | 401 跳转地址可用 `window.QG_LOGIN_URL` 覆盖；`QG.upload` 支持自定义 url | 修改 |
| `assets/css/app.css` | `.brand .logo` 样式 | 修改 |
| `tests/test_rate_limit.php` | 限流器单测 | 创建 |
| `tests/test_platform_auth.php` | Auth platform 分支单测 | 创建 |
| `tests/test_provision.php` | 开通播种幂等性测试（CLI，dev 库，租户 9001） | 创建 |
| `tests/test_register.php` | 注册接口 HTTP 测试 | 创建 |
| `tests/test_platform_api.php` | 平台登录 + 审核 + 端到端登录 HTTP 测试 | 创建 |
| `tests/test_branding_logo.php` | Logo 上传 / branding / 附件命名空间 HTTP 测试 | 创建 |
| `version.json` | 前端 build bump | 修改 |
| `AGENTS.md`、设计稿 | 口径更新（平台侧已接线、附件命名空间已落地、测试计数） | 修改 |

---

## 任务 1：Auth 平台分支补全（platformLogin / requirePlatform / verify 放行 puid）

**背景：** `Auth::user()`（api/src/Auth.php:90-101）已能消费 `scope=platform` 的 token，但有两个缺口：①没有任何地方签发 platform token（`login()` 硬编码 `'scope' => 'tenant'`）；②`verify()` 要求 payload 有 `uid`，platform payload 用的是 `puid`，会被判非法。

**文件：**
- 修改：`api/src/Auth.php`（verify 行 40 附近；文件尾部加两个方法）
- 测试：`tests/test_platform_auth.php`

- [ ] **步骤 1：编写失败的测试**

创建 `tests/test_platform_auth.php`：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

use QG\App;
use QG\Auth;

t('platform payload（puid，无 uid）能通过 verify', function () {
    $token = Auth::sign(['scope' => 'platform', 'puid' => 1, 'uname' => 'platform', 'exp' => time() + 3600]);
    $p = Auth::verify($token);
    assert_true($p !== null, '带 puid 的 platform token 应能通过校验');
    assert_eq('platform', $p['scope']);
    assert_eq(1, (int) $p['puid']);
});

t('过期 token 仍被拒', function () {
    $token = Auth::sign(['scope' => 'platform', 'puid' => 1, 'exp' => time() - 10]);
    assert_true(Auth::verify($token) === null, '过期 token 必须校验失败');
});
```

- [ ] **步骤 2：运行测试验证失败**

运行：`php83 tests/run.php 2>&1 | grep -A1 platform`
预期：FAIL「带 puid 的 platform token 应能通过校验」（`verify()` 里的 `empty($data['uid'])` 把它挡了）。

- [ ] **步骤 3：实现 verify 放行 + 两个新方法**

`api/src/Auth.php` 的 `verify()` 里，把：

```php
            if (!is_array($data) || empty($data['uid'])) {
```

改为：

```php
            if (!is_array($data) || (empty($data['uid']) && empty($data['puid']))) {
```

在 `Auth` 类尾部（`can()` 之后）加：

```php
    /** 平台管理员登录：签发 scope=platform 的令牌（payload 用 puid，无租户上下文） */
    public static function platformLogin(array $puser): void
    {
        $ttl = (int) App::config('app')['token_ttl'];
        $token = self::sign([
            'scope' => 'platform',
            'puid'  => (int) $puser['id'],
            'uname' => $puser['username'],
            'exp'   => time() + $ttl,
        ]);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE, $token, [
            'expires'  => time() + $ttl,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ]);
        $_COOKIE[self::COOKIE] = $token;
        self::$user = $puser;
    }

    /** 要求平台管理员：未登录 401；登录了但是租户令牌 403（两边令牌互不通用） */
    public static function requirePlatform(): array
    {
        $u = self::requireLogin();
        if (($u['scope'] ?? 'tenant') !== 'platform') {
            Response::fail('需要平台管理员权限', 403, 403);
        }
        return $u;
    }
```

- [ ] **步骤 4：运行测试验证通过**

运行：`php83 tests/run.php 2>&1 | tail -3`
预期：`33 passed, 0 failed`（31 + 新增 2）。

- [ ] **步骤 5：Commit**

```bash
git add api/src/Auth.php tests/test_platform_auth.php
git commit -m "feat: Auth 支持签发/校验平台令牌（platformLogin/requirePlatform）"
```

---

## 任务 2：文件型限流器 RateLimit

**文件：**
- 创建：`api/src/RateLimit.php`
- 测试：`tests/test_rate_limit.php`

- [ ] **步骤 1：编写失败的测试**

创建 `tests/test_rate_limit.php`：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

use QG\RateLimit;

t('窗口内前 N 次放行、第 N+1 次拦截', function () {
    RateLimit::$dir = sys_get_temp_dir() . '/qg-rl-' . uniqid();
    $bucket = 'test|' . uniqid();
    assert_true(RateLimit::allow($bucket, 2, 3600), '第 1 次应放行');
    assert_true(RateLimit::allow($bucket, 2, 3600), '第 2 次应放行');
    assert_true(!RateLimit::allow($bucket, 2, 3600), '第 3 次应拦截');
});

t('不同 bucket 互不影响', function () {
    RateLimit::$dir = sys_get_temp_dir() . '/qg-rl-' . uniqid();
    $a = 'a|' . uniqid();
    $b = 'b|' . uniqid();
    RateLimit::allow($a, 1, 3600);
    assert_true(!RateLimit::allow($a, 1, 3600), 'a 应已被限');
    assert_true(RateLimit::allow($b, 1, 3600), 'b 不应受 a 影响');
});
```

- [ ] **步骤 2：运行测试验证失败**

运行：`php83 tests/run.php 2>&1 | grep -B1 FAIL | head -5`
预期：FAIL，类 `QG\RateLimit` 不存在。

- [ ] **步骤 3：实现 RateLimit**

创建 `api/src/RateLimit.php`：

```php
<?php
namespace QG;

/**
 * 文件型滑动窗口限流器：按 bucket（如 "register|1.2.3.4"）计数。
 * 存 api/runtime/ratelimit/（该目录可写、不入库）。零迁移、单机够用。
 * 限流器自身故障（目录不可写等）放行，不挡业务。
 */
class RateLimit
{
    /** 测试可覆盖存储目录 */
    public static ?string $dir = null;

    /** 窗口内还有额度则记录本次并返回 true，否则返回 false */
    public static function allow(string $bucket, int $max, int $windowSec): bool
    {
        $dir = self::$dir ?? dirname(__DIR__) . '/runtime/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . sha1($bucket) . '.json';
        $now  = time();
        $fp   = @fopen($file, 'c+');
        if ($fp === false) {
            return true;
        }
        try {
            flock($fp, LOCK_EX);
            $raw  = stream_get_contents($fp);
            $hits = $raw ? json_decode($raw, true) : [];
            if (!is_array($hits)) {
                $hits = [];
            }
            $hits = array_values(array_filter($hits, function ($t) use ($now, $windowSec) {
                return is_int($t) && $t > $now - $windowSec;
            }));
            if (count($hits) >= $max) {
                return false;
            }
            $hits[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($hits));
            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
```

- [ ] **步骤 4：运行测试验证通过**

运行：`php83 tests/run.php 2>&1 | tail -3`
预期：`35 passed, 0 failed`。

- [ ] **步骤 5：Commit**

```bash
git add api/src/RateLimit.php tests/test_rate_limit.php
git commit -m "feat: 文件型滑动窗口限流器（注册/平台登录防灌防爆破）"
```

---

## 任务 3：ProvisionRepo + TenantProvisioner（开通播种核心，幂等）

**背景：** `db/schema.sql` 是数据面权威：DDL 全 `IF NOT EXISTS`、种子全 `ON DUPLICATE KEY UPDATE`，且 `SET @tenant_id := IFNULL(@tenant_id, 1)` 参数化。开通 = 在同一 PDO 连接上先 `SET @tenant_id := <tid>`，再把整个文件逐条执行（共享库上 DDL 是空操作，落地的是该 tid 的默认数据）。**硬约定：schema.sql 的语句字符串内不得出现 ASCII 分号**（切分器按 `;` 切段），注释行（`--` 开头）会被剔除。注意 DDL 会隐式提交事务——共享库上它们是空操作所以无害，事务只兜 INSERT 种子段。

**文件：**
- 创建：`api/src/repos/ProvisionRepo.php`
- 创建：`api/src/TenantProvisioner.php`
- 测试：`tests/test_provision.php`

- [ ] **步骤 1：编写失败的测试**

创建 `tests/test_provision.php`（CLI 直连 dev 库，合成租户 9001，全程清理）：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

use QG\App;
use QG\ControlDb;
use QG\Tenant;
use QG\TenantProvisioner;

const PROV_TID = 9001;
const PROV_CODE = 'testprov9001';

function prov_cleanup(): void
{
    // 数据面（共享库）清 9001 的种子与账号
    Tenant::set(['id' => PROV_TID, 'mode' => 'shared', 'db_name' => '']);
    $pdo = Tenant::pdo();
    foreach (['sys_user', 'sys_role_permission', 'sys_role', 'sys_permission', 'sys_config', 'sys_dict', 'sys_log'] as $tb) {
        $pdo->exec("DELETE FROM {$tb} WHERE tenant_id = " . PROV_TID);
    }
    Tenant::set(null);
    // 控制面清租户与审计
    ControlDb::exec('DELETE FROM saas_audit WHERE target_type = ? AND target_id = ?', ['tenant', PROV_TID]);
    ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [PROV_TID]);
}

function prov_make_tenant(): array
{
    ControlDb::exec('DELETE FROM saas_tenant WHERE code = ?', [PROV_CODE]);
    $id = ControlDb::insert('saas_tenant', [
        'code' => PROV_CODE, 'name' => '开通测试企业', 'mode' => 'shared',
        'plan_id' => null, 'status' => 'pending',
        'contact_name' => '测试', 'contact_phone' => '13800000000',
        'admin_username' => 'boss9001',
        'admin_password_hash' => password_hash('Prov@12345', PASSWORD_DEFAULT),
        'applied_at' => App::now(),
    ]);
    // 固定住 id，便于重复跑用例
    ControlDb::exec('UPDATE saas_tenant SET id = ? WHERE id = ?', [PROV_TID, $id]);
    return ControlDb::one('SELECT * FROM saas_tenant WHERE id = ?', [PROV_TID]);
}

t('开通：播种角色/权限/配置/字典并创建管理员，状态置 active', function () {
    prov_cleanup();
    $t = prov_make_tenant();
    TenantProvisioner::provision($t, null);
    Tenant::set(['id' => PROV_TID, 'mode' => 'shared', 'db_name' => '']);
    $pdo = Tenant::pdo();
    $st = fn($sql) => $pdo->query($sql)->fetchColumn();
    assert_true((int) $st('SELECT COUNT(*) FROM sys_role WHERE tenant_id = ' . PROV_TID) >= 3, '应播种 3 个角色');
    assert_true((int) $st('SELECT COUNT(*) FROM sys_permission WHERE tenant_id = ' . PROV_TID) >= 20, '应播种权限树');
    assert_true((int) $st('SELECT COUNT(*) FROM sys_config WHERE tenant_id = ' . PROV_TID) >= 17, '应播种配置');
    assert_true((int) $st('SELECT COUNT(*) FROM sys_dict WHERE tenant_id = ' . PROV_TID) > 0, '应播种字典');
    $u = $pdo->query('SELECT u.*, r.code AS role_code FROM sys_user u LEFT JOIN sys_role r ON r.id = u.role_id WHERE u.tenant_id = ' . PROV_TID . " AND u.username = 'boss9001'")->fetch();
    assert_true((bool) $u, '应创建管理员');
    assert_eq('tenant_admin', $u['role_code'] ?? '', '管理员应挂 tenant_admin 角色');
    assert_true(password_verify('Prov@12345', $u['password_hash']), '密码哈希应可验证');
    Tenant::set(null);
    $after = ControlDb::one('SELECT * FROM saas_tenant WHERE id = ?', [PROV_TID]);
    assert_eq('active', $after['status'], '开通后应 active');
    assert_eq('', $after['admin_password_hash'], '开通后密码哈希应清空');
    prov_cleanup();
});

t('开通幂等：重复执行不产生重复数据', function () {
    prov_cleanup();
    $t = prov_make_tenant();
    TenantProvisioner::provision($t, null);
    TenantProvisioner::provision(ControlDb::one('SELECT * FROM saas_tenant WHERE id = ?', [PROV_TID]), null);
    Tenant::set(['id' => PROV_TID, 'mode' => 'shared', 'db_name' => '']);
    $pdo = Tenant::pdo();
    assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM sys_user WHERE tenant_id = ' . PROV_TID)->fetchColumn(), '管理员不应重复');
    assert_eq(3, (int) $pdo->query('SELECT COUNT(*) FROM sys_role WHERE tenant_id = ' . PROV_TID)->fetchColumn(), '角色不应重复');
    Tenant::set(null);
    prov_cleanup();
});

t('专用档本期拒绝开通', function () {
    prov_cleanup();
    $t = prov_make_tenant();
    ControlDb::exec("UPDATE saas_tenant SET mode = 'dedicated' WHERE id = ?", [PROV_TID]);
    $t = ControlDb::one('SELECT * FROM saas_tenant WHERE id = ?', [PROV_TID]);
    $msg = '';
    try {
        TenantProvisioner::provision($t, null);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
    }
    assert_contains($msg, '专用档', '应明确拒绝专用档');
    $after = ControlDb::one('SELECT status FROM saas_tenant WHERE id = ?', [PROV_TID]);
    assert_eq('pending', $after['status'], '失败后状态应保持 pending');
    prov_cleanup();
});
```

- [ ] **步骤 2：运行测试验证失败**

运行：`php83 tests/run.php 2>&1 | grep -c FAIL`
预期：3 个 FAIL，`QG\TenantProvisioner` 类不存在。

- [ ] **步骤 3：实现 ProvisionRepo**

创建 `api/src/repos/ProvisionRepo.php`：

```php
<?php
namespace QG\repos;

use QG\Db;
use QG\Tenant;

/** 开通播种：把 db/schema.sql 按目标 tenant_id 落到当前数据面连接，并创建租户管理员。 */
class ProvisionRepo
{
    /** 幂等执行 db/schema.sql（DDL IF NOT EXISTS + 种子 ON DUPLICATE KEY UPDATE） */
    public static function seedFromSchema(int $tid): void
    {
        $file = dirname(__DIR__, 3) . '/db/schema.sql';
        $sql  = @file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException('读不到 db/schema.sql');
        }
        $pdo = Tenant::pdo();
        $pdo->exec('SET @tenant_id := ' . $tid);
        foreach (self::splitStatements($sql) as $stmt) {
            $pdo->exec($stmt);
        }
    }

    /** 幂等建管理员：已存在（uk_tenant_username）则跳过 */
    public static function ensureAdminUser(int $tid, string $username, string $passwordHash, string $realName): void
    {
        $roleId = Db::val('SELECT id FROM sys_role WHERE tenant_id = ? AND code = ? LIMIT 1', [$tid, 'tenant_admin']);
        if (!$roleId) {
            throw new \RuntimeException('角色播种缺失：tenant_admin（schema.sql 种子未生效）');
        }
        $exists = Db::val('SELECT id FROM sys_user WHERE tenant_id = ? AND username = ? LIMIT 1', [$tid, $username]);
        if ($exists) {
            return;
        }
        Db::insert('sys_user', [
            'tenant_id'     => $tid,
            'username'      => $username,
            'real_name'     => $realName !== '' ? $realName : $username,
            'password_hash' => $passwordHash,
            'role_id'       => (int) $roleId,
            'status'        => 1,
        ]);
    }

    /** 去注释行后按分号切语句。约定：schema.sql 字符串值内不得含 ASCII 分号。 */
    private static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\r?\n/', $sql);
        $kept  = [];
        foreach ($lines as $ln) {
            if (preg_match('/^\s*--/', $ln)) {
                continue;
            }
            $kept[] = $ln;
        }
        $stmts = [];
        foreach (explode(';', implode("\n", $kept)) as $s) {
            $s = trim($s);
            if ($s !== '') {
                $stmts[] = $s;
            }
        }
        return $stmts;
    }
}
```

再创建 `api/src/TenantProvisioner.php`：

```php
<?php
namespace QG;

use QG\repos\ProvisionRepo;

/**
 * 租户开通：幂等。重复调用安全；任何一步失败抛异常并把租户留在 pending，
 * 由调用方（PlatformController）记 saas_audit 的 provision_failed。
 */
class TenantProvisioner
{
    public static function provision(array $tenant, ?int $platformUserId): void
    {
        $tid = (int) ($tenant['id'] ?? 0);
        if ($tid <= 0) {
            throw new \RuntimeException('租户 id 非法');
        }
        if (($tenant['mode'] ?? 'shared') !== 'shared') {
            throw new \RuntimeException('专用档（物理隔离）开通尚未开放，归二期');
        }
        if (!in_array($tenant['status'] ?? '', ['pending', 'active'], true)) {
            throw new \RuntimeException('当前状态不可开通：' . ($tenant['status'] ?? ''));
        }
        if (empty($tenant['admin_username']) || empty($tenant['admin_password_hash'])) {
            throw new \RuntimeException('缺少管理员账号或密码哈希，无法开通');
        }

        Tenant::set(['id' => $tid, 'mode' => 'shared', 'db_name' => '']);
        try {
            Db::begin();
            try {
                ProvisionRepo::seedFromSchema($tid);
                ProvisionRepo::ensureAdminUser($tid, $tenant['admin_username'], $tenant['admin_password_hash'], (string) ($tenant['name'] ?? ''));
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
        } finally {
            Tenant::set(null);
        }

        ControlDb::exec(
            "UPDATE saas_tenant SET status = 'active', reviewed_at = NOW(), reviewed_by = ?, admin_password_hash = '', reject_reason = '' WHERE id = ?",
            [$platformUserId, $tid]
        );
        ControlDb::insert('saas_audit', [
            'platform_user_id' => $platformUserId,
            'action'           => 'approve',
            'target_type'      => 'tenant',
            'target_id'        => $tid,
            'detail'           => '开通成功（共享档播种 + 管理员 ' . $tenant['admin_username'] . '）',
            'ip'               => App::ip(),
        ]);
    }
}
```

- [ ] **步骤 4：运行测试验证通过**

运行：`php83 tests/run.php 2>&1 | tail -3 && php83 tests/guard_no_raw_business_sql.php`
预期：`38 passed, 0 failed` + `guard ok`。

若「应播种权限树」失败，先手工验证切分器：`php83 -r 'require "api/src/repos/ProvisionRepo.php";'` 之类排查，最常见原因是 schema.sql 某行注释没顶格或字符串里混入了 ASCII `;`。

- [ ] **步骤 5：Commit**

```bash
git add api/src/repos/ProvisionRepo.php api/src/TenantProvisioner.php tests/test_provision.php
git commit -m "feat: 开通播种器（按 tenant_id 幂等执行 schema.sql + 建管理员）"
```

---

## 任务 4：PlatformController（平台登录 + 租户审核）与路由注册

**文件：**
- 创建：`api/src/controllers/PlatformController.php`
- 修改：`api/index.php`（第 55-65 行注册段）
- 测试：`tests/test_platform_api.php`

- [ ] **步骤 1：编写失败的测试**

创建 `tests/test_platform_api.php`（HTTP；平台超管账号由任务 6 的脚本播种，用户名 `platform` 密码 `Platform@12345`，未播种则用例跳过）：

```php
<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/bootstrap.php';

use QG\App;
use QG\ControlDb;

function platform_jar(): ?string
{
    $jar = tempnam(sys_get_temp_dir(), 'qgpf');
    $r = qg_post_json('/api/platform/login', ['username' => 'platform', 'password' => 'Platform@12345'], ['-c', $jar]);
    if (strpos($r, '"code":0') === false) {
        @unlink($jar);
        return null; // 未播种平台超管或站点未起
    }
    return $jar;
}

t('平台登录成功且 /platform/me 返回 scope=platform', function () {
    if (!qg_site_up()) { return; }
    $jar = platform_jar();
    if (!$jar) { echo "      平台超管未播种，跳过（先跑 scripts/seed_platform_admin.php）\n"; return; }
    $me = qg_curl('/api/platform/me', ['-b', $jar]);
    assert_contains($me, '"scope":"platform"', '/platform/me 应带平台作用域：' . $me);
    @unlink($jar);
});

t('租户令牌调平台接口被 403', function () {
    if (!qg_site_up()) { return; }
    $jar = tempnam(sys_get_temp_dir(), 'qgck');
    qg_post_json('/api/auth/login', ['tenant' => 'demo', 'username' => 'admin', 'password' => 'admin123'], ['-c', $jar]);
    $r = qg_curl('/api/platform/tenants', ['-b', $jar]);
    assert_contains($r, '需要平台管理员权限', '租户令牌必须被平台接口拒绝：' . $r);
    @unlink($jar);
});

t('未登录调平台接口被 401', function () {
    if (!qg_site_up()) { return; }
    $r = qg_curl('/api/platform/tenants');
    assert_contains($r, '"code":401', '未登录应 401：' . $r);
});

t('审核端到端：注册→approve→新租户管理员可登录→重复 approve 幂等', function () {
    if (!qg_site_up()) { return; }
    $jar = platform_jar();
    if (!$jar) { echo "      平台超管未播种，跳过\n"; return; }
    $code = 'e2e' . substr(md5(uniqid()), 0, 8);
    // 注册
    $r = qg_post_json('/api/tenant/register', [
        'name' => '端到端测试企业', 'code' => $code, 'contact_name' => '测试',
        'contact_phone' => '13800000000', 'admin_username' => 'boss',
        'password' => 'E2ePass123', 'plan_id' => 1,
    ]);
    assert_contains($r, '"code":0', '注册应成功：' . $r);
    $tid = (int) ControlDb::val('SELECT id FROM saas_tenant WHERE code = ?', [$code]);
    assert_true($tid > 0, '控制面应有 pending 租户');
    // approve
    $r = qg_post_json('/api/platform/tenants/' . $tid . '/approve', [], ['-b', $jar]);
    assert_contains($r, '"code":0', 'approve 应成功：' . $r);
    // 重复 approve 幂等
    $r = qg_post_json('/api/platform/tenants/' . $tid . '/approve', [], ['-b', $jar]);
    assert_contains($r, '"code":0', '重复 approve 应幂等成功：' . $r);
    // 新租户管理员可登录
    $jar2 = tempnam(sys_get_temp_dir(), 'qgck');
    $r = qg_post_json('/api/auth/login', ['tenant' => $code, 'username' => 'boss', 'password' => 'E2ePass123'], ['-c', $jar2]);
    assert_contains($r, '"code":0', '新租户管理员应能登录：' . $r);
    @unlink($jar2);
    // 清理
    $pdo = qg_test_pdo();
    foreach (['sys_user', 'sys_role_permission', 'sys_role', 'sys_permission', 'sys_config', 'sys_dict', 'sys_log'] as $tb) {
        $pdo->exec("DELETE FROM {$tb} WHERE tenant_id = " . $tid);
    }
    ControlDb::exec('DELETE FROM saas_audit WHERE target_type = ? AND target_id = ?', ['tenant', $tid]);
    ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
    @unlink($jar);
});
```

注意：该用例依赖 `plan_id = 1` 存在（控制面已播 basic/isolated 两个套餐，basic 是 id=1）；若 dev 控制面套餐 id 不同，先 `sudo -n mysql -N -B -e "SELECT id,code FROM qingguan_control_dev.saas_plan"` 核对再调整。

- [ ] **步骤 2：运行测试验证失败**

运行：`php83 tests/run.php 2>&1 | grep FAIL`
预期：4 个 FAIL（`/api/platform/login` 接口不存在）。

- [ ] **步骤 3：实现 PlatformController 并注册路由**

创建 `api/src/controllers/PlatformController.php`：

```php
<?php
namespace QG\controllers;

use QG\App;
use QG\Auth;
use QG\ControlDb;
use QG\RateLimit;
use QG\Req;
use QG\Response;
use QG\Router;
use QG\TenantProvisioner;

/** 平台侧：超管登录 + 租户审核。全部路由在 /api/platform/* 下，令牌 scope=platform。 */
class PlatformController
{
    public static function routes(Router $r): void
    {
        $r->post('/platform/login', [self::class, 'login'], false);
        $r->post('/platform/logout', [self::class, 'logout']);
        $r->get('/platform/me', [self::class, 'me']);
        $r->get('/platform/tenants', [self::class, 'tenants']);
        $r->post('/platform/tenants/{id}/approve', [self::class, 'approve']);
        $r->post('/platform/tenants/{id}/reject', [self::class, 'reject']);
        $r->post('/platform/tenants/{id}/suspend', [self::class, 'suspend']);
        $r->post('/platform/tenants/{id}/enable', [self::class, 'enable']);
    }

    public static function login(): void
    {
        if (!RateLimit::allow('platform-login|' . App::ip(), 10, 600)) {
            Response::fail('尝试过于频繁，请 10 分钟后再试', 429, 429);
        }
        $username = Req::require_('username', '账号');
        $password = Req::str('password');
        if ($password === '') {
            Response::fail('密码不能为空');
        }
        $u = ControlDb::one('SELECT * FROM saas_platform_user WHERE username = ? AND status = 1 LIMIT 1', [$username]);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            Response::fail('账号或密码错误');
        }
        ControlDb::exec('UPDATE saas_platform_user SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
            [App::ip(), (int) $u['id']]);
        Auth::platformLogin($u);
        self::audit((int) $u['id'], 'login', null, '');
        unset($u['password_hash']);
        $u['scope'] = 'platform';
        Response::ok($u, '登录成功');
    }

    public static function logout(): void
    {
        Auth::logout();
        Response::ok(null, '已退出');
    }

    public static function me(): void
    {
        Response::ok(Auth::requirePlatform());
    }

    public static function tenants(): void
    {
        Auth::requirePlatform();
        [$page, $size, $offset] = Req::page();
        $status = Req::str('status');
        $where = '1=1';
        $bind  = [];
        if (in_array($status, ['pending', 'active', 'rejected', 'suspended'], true)) {
            $where .= ' AND t.status = ?';
            $bind[] = $status;
        }
        $total = (int) ControlDb::val("SELECT COUNT(*) FROM saas_tenant t WHERE {$where}", $bind);
        $rows  = ControlDb::all(
            "SELECT t.id, t.code, t.name, t.mode, t.status, t.contact_name, t.contact_phone, t.contact_email,
                    t.admin_username, t.reject_reason, t.applied_at, t.reviewed_at, t.logo_url,
                    p.name AS plan_name
               FROM saas_tenant t LEFT JOIN saas_plan p ON p.id = t.plan_id
              WHERE {$where} ORDER BY (t.status = 'pending') DESC, t.id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        Response::page($rows, $total, $page, $size);
    }

    public static function approve(array $p): void
    {
        $admin = Auth::requirePlatform();
        $t = self::findTenant($p);
        if ($t['status'] === 'active') {
            Response::ok(null, '该租户已是开通状态（幂等）');
        }
        if ($t['status'] !== 'pending') {
            Response::fail('当前状态不可审核通过：' . $t['status']);
        }
        try {
            TenantProvisioner::provision($t, (int) $admin['id']);
        } catch (\Throwable $e) {
            self::audit((int) $admin['id'], 'provision_failed', (int) $t['id'], mb_substr($e->getMessage(), 0, 500));
            Response::fail('开通失败：' . $e->getMessage());
        }
        Response::ok(null, '已开通');
    }

    public static function reject(array $p): void
    {
        $admin = Auth::requirePlatform();
        $t = self::findTenant($p);
        if ($t['status'] !== 'pending') {
            Response::fail('仅待审核的租户可驳回');
        }
        $reason = Req::str('reason');
        if ($reason === '') {
            Response::fail('驳回原因不能为空');
        }
        ControlDb::exec("UPDATE saas_tenant SET status = 'rejected', reject_reason = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?",
            [$reason, (int) $admin['id'], (int) $t['id']]);
        self::audit((int) $admin['id'], 'reject', (int) $t['id'], $reason);
        Response::ok(null, '已驳回');
    }

    public static function suspend(array $p): void
    {
        $admin = Auth::requirePlatform();
        $t = self::findTenant($p);
        if ($t['status'] !== 'active') {
            Response::fail('仅已开通的租户可停用');
        }
        ControlDb::exec("UPDATE saas_tenant SET status = 'suspended' WHERE id = ?", [(int) $t['id']]);
        self::audit((int) $admin['id'], 'suspend', (int) $t['id'], '');
        Response::ok(null, '已停用');
    }

    public static function enable(array $p): void
    {
        $admin = Auth::requirePlatform();
        $t = self::findTenant($p);
        if ($t['status'] !== 'suspended') {
            Response::fail('仅已停用的租户可重新启用');
        }
        ControlDb::exec("UPDATE saas_tenant SET status = 'active' WHERE id = ?", [(int) $t['id']]);
        self::audit((int) $admin['id'], 'enable', (int) $t['id'], '');
        Response::ok(null, '已启用');
    }

    private static function findTenant(array $p): array
    {
        $t = ControlDb::one('SELECT * FROM saas_tenant WHERE id = ? LIMIT 1', [(int) ($p['id'] ?? 0)]);
        if (!$t) {
            Response::fail('租户不存在');
        }
        return $t;
    }

    private static function audit(int $platformUserId, string $action, ?int $targetId, string $detail): void
    {
        ControlDb::insert('saas_audit', [
            'platform_user_id' => $platformUserId,
            'action'           => $action,
            'target_type'      => $targetId ? 'tenant' : '',
            'target_id'        => $targetId,
            'detail'           => $detail,
            'ip'               => App::ip(),
        ]);
    }
}
```

修改 `api/index.php` 注册段（在 `UploadController::routes($router);` 之后、`$router->dispatch(...)` 之前）加两行：

```php
\QG\controllers\PlatformController::routes($router);
\QG\controllers\TenantController::routes($router);
```

（TenantController 在任务 5 创建；本步骤先只加 PlatformController 那一行，任务 5 再加另一行。）

- [ ] **步骤 4：运行测试验证通过**

先确认 dev 站点在跑，然后：
运行：`php83 tests/run.php 2>&1 | tail -3`
预期：平台相关 4 个用例中，前 3 个转 PASS；「审核端到端」里的注册步会 FAIL（`/api/tenant/register` 还不存在）——这是预期内的半截状态，任务 5 补齐后转绿。若此时想保持全绿再提交，可先注释掉端到端用例、任务 5 再恢复；推荐直接继续任务 5 后一起提交。

- [ ] **步骤 5：Commit**

```bash
git add api/src/controllers/PlatformController.php api/index.php tests/test_platform_api.php
git commit -m "feat: 平台登录与租户审核接口（scope=platform 隔离）"
```

---

## 任务 5：TenantController（注册 / code-check / plans / branding 公开接口）

**文件：**
- 创建：`api/src/controllers/TenantController.php`
- 修改：`api/index.php`（加 `TenantController::routes($router);`，见任务 4）
- 测试：`tests/test_register.php`

- [ ] **步骤 1：编写失败的测试**

创建 `tests/test_register.php`：

```php
<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/bootstrap.php';

use QG\ControlDb;

t('公开套餐列表可访问且含 basic', function () {
    if (!qg_site_up()) { return; }
    $r = qg_curl('/api/plans/public');
    assert_contains($r, '"code":0', 'plans/public 应公开可取：' . $r);
    assert_contains($r, 'basic', '应包含 basic 套餐：' . $r);
    assert_true(strpos($r, 'price_month') !== false, '应带价格字段');
});

t('租户代码可用性校验：demo 已占用、非法格式被拒', function () {
    if (!qg_site_up()) { return; }
    $r = qg_curl('/api/tenant/code-check?code=demo');
    assert_contains($r, '"available":false', 'demo 应已占用：' . $r);
    $r = qg_curl('/api/tenant/code-check?code=' . urlencode('Bad_Code'));
    assert_contains($r, '"available":false', '非法格式不可用：' . $r);
});

t('注册校验：密码太短 / 联系方式全空 / 重复代码 被拒', function () {
    if (!qg_site_up()) { return; }
    $base = ['name' => '校验测试', 'code' => 'chk' . substr(md5(uniqid()), 0, 8),
        'contact_name' => '张三', 'admin_username' => 'boss', 'plan_id' => 1];
    $r = qg_post_json('/api/tenant/register', $base + ['contact_phone' => '13800000000', 'password' => 'short']);
    assert_contains($r, '密码至少 8 位', '短密码应被拒：' . $r);
    $r = qg_post_json('/api/tenant/register', $base + ['password' => 'LongEnough1']);
    assert_contains($r, '至少填一个', '联系方式全空应被拒：' . $r);
    $r = qg_post_json('/api/tenant/register', $base + ['code' => 'demo', 'contact_phone' => '13800000000', 'password' => 'LongEnough1']);
    assert_contains($r, '已被占用', '重复代码应被拒：' . $r);
});

t('合法注册落 pending，且登录提示审核中', function () {
    if (!qg_site_up()) { return; }
    $code = 'reg' . substr(md5(uniqid()), 0, 8);
    $r = qg_post_json('/api/tenant/register', [
        'name' => '注册测试企业', 'code' => $code, 'contact_name' => '李四',
        'contact_email' => 'test@example.com', 'admin_username' => 'boss',
        'password' => 'RegPass123', 'plan_id' => 1,
    ]);
    assert_contains($r, '"code":0', '注册应成功：' . $r);
    $row = ControlDb::one('SELECT * FROM saas_tenant WHERE code = ?', [$code]);
    assert_eq('pending', $row['status'] ?? '', '应落 pending');
    assert_true(password_verify('RegPass123', $row['admin_password_hash']), '密码应已哈希暂存');
    $r = qg_post_json('/api/auth/login', ['tenant' => $code, 'username' => 'boss', 'password' => 'RegPass123']);
    assert_contains($r, '审核中', 'pending 租户登录应提示审核中：' . $r);
    // 清理
    ControlDb::exec('DELETE FROM saas_audit WHERE target_type = ? AND target_id = ?', ['tenant', (int) $row['id']]);
    ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [(int) $row['id']]);
});
```

- [ ] **步骤 2：运行测试验证失败**

运行：`php83 tests/run.php 2>&1 | grep FAIL`
预期：4 个 FAIL（接口不存在）。

- [ ] **步骤 3：实现 TenantController 并注册路由**

创建 `api/src/controllers/TenantController.php`：

```php
<?php
namespace QG\controllers;

use QG\App;
use QG\Auth;
use QG\ControlDb;
use QG\RateLimit;
use QG\Req;
use QG\Response;
use QG\Router;
use QG\Tenant;

/** 租户侧公开接口（注册/品牌）+ 租户管理员 Logo 上传。 */
class TenantController
{
    public static function routes(Router $r): void
    {
        $r->get('/plans/public', [self::class, 'plans'], false);
        $r->get('/tenant/code-check', [self::class, 'codeCheck'], false);
        $r->post('/tenant/register', [self::class, 'register'], false);
        $r->get('/tenant/branding', [self::class, 'branding'], false);
        $r->post('/tenant/logo', [self::class, 'logo']);
    }

    /** 注册页渲染用：启用的套餐（不含敏感字段） */
    public static function plans(): void
    {
        $rows = ControlDb::all(
            'SELECT id, code, name, mode, price_month, price_year, max_users, max_orders, remark
               FROM saas_plan WHERE status = 1 ORDER BY sort, id'
        );
        Response::ok($rows);
    }

    public static function codeCheck(): void
    {
        $code = strtolower(trim(Req::str('code')));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,29}$/', $code)) {
            Response::ok(['available' => false, 'msg' => '格式：2-30 位小写字母、数字或短横线']);
        }
        $exists = (bool) ControlDb::val('SELECT COUNT(*) FROM saas_tenant WHERE code = ?', [$code]);
        Response::ok(['available' => !$exists, 'msg' => $exists ? '该代码已被占用' : '']);
    }

    public static function register(): void
    {
        if (!RateLimit::allow('register|' . App::ip(), 5, 3600)) {
            Response::fail('提交过于频繁，请 1 小时后再试', 429, 429);
        }
        $name = Req::require_('name', '企业名称');
        $code = strtolower(trim(Req::require_('code', '租户代码')));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,29}$/', $code)) {
            Response::fail('租户代码需为 2-30 位小写字母、数字或短横线');
        }
        $contactName = Req::require_('contact_name', '联系人');
        $phone = trim(Req::str('contact_phone'));
        $email = trim(Req::str('contact_email'));
        if ($phone === '' && $email === '') {
            Response::fail('手机号与邮箱至少填一个');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::fail('邮箱格式不正确');
        }
        $adminUsername = Req::require_('admin_username', '管理员账号');
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $adminUsername)) {
            Response::fail('管理员账号需为 3-30 位字母、数字或下划线');
        }
        $password = Req::str('password');
        if (strlen($password) < 8) {
            Response::fail('密码至少 8 位');
        }
        $planId = Req::int('plan_id');
        $plan = ControlDb::one('SELECT * FROM saas_plan WHERE id = ? AND status = 1 LIMIT 1', [$planId]);
        if (!$plan) {
            Response::fail('套餐不存在或已停用');
        }
        if (ControlDb::val('SELECT COUNT(*) FROM saas_tenant WHERE code = ?', [$code])) {
            Response::fail('该租户代码已被占用');
        }
        $id = ControlDb::insert('saas_tenant', [
            'code'                => $code,
            'name'                => $name,
            'mode'                => $plan['mode'],
            'plan_id'             => $planId,
            'status'              => 'pending',
            'contact_name'        => $contactName,
            'contact_phone'       => $phone,
            'contact_email'       => $email,
            'admin_username'      => $adminUsername,
            'admin_password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'applied_at'          => App::now(),
        ]);
        ControlDb::insert('saas_audit', [
            'platform_user_id' => null,
            'action'           => 'register',
            'target_type'      => 'tenant',
            'target_id'        => $id,
            'detail'           => json_encode(['code' => $code, 'name' => $name, 'plan' => $plan['code']], JSON_UNESCAPED_UNICODE),
            'ip'               => App::ip(),
        ]);
        Response::ok(['id' => $id], '申请已提交，审核通过后即可登录');
    }

    /** 登录页失焦拉品牌：只暴露名称与 Logo，不返回任何业务数据 */
    public static function branding(): void
    {
        $code = strtolower(trim(Req::str('code')));
        $t = $code === '' ? null
            : ControlDb::one('SELECT name, logo_url FROM saas_tenant WHERE code = ? LIMIT 1', [$code]);
        Response::ok([
            'found'    => (bool) $t,
            'name'     => $t['name'] ?? '',
            'logo_url' => $t['logo_url'] ?? '',
        ]);
    }

    /** 租户管理员上传 Logo：uploads/t<tid>/branding/logo.<ext>，URL 写回控制面 */
    public static function logo(): void
    {
        Auth::requireLogin();
        if (!Auth::isTenantAdmin()) {
            Response::fail('仅租户管理员可上传 Logo', 403, 403);
        }
        $tid = Tenant::requireId();
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::fail('请选择要上传的图片');
        }
        if ((int) $f['size'] > 2 * 1024 * 1024) {
            Response::fail('Logo 不能超过 2MB');
        }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            Response::fail('仅支持 PNG/JPG 图片');
        }
        $mime = mime_content_type($f['tmp_name']);
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            Response::fail('文件内容不是有效的图片');
        }
        $cfg = App::config('app');
        $sub = 't' . $tid . '/branding';
        $dir = rtrim($cfg['upload_dir'], '/\\') . '/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            Response::fail('无法创建存储目录');
        }
        foreach (glob($dir . '/logo.*') ?: [] as $old) {
            @unlink($old);
        }
        if (!move_uploaded_file($f['tmp_name'], $dir . '/logo.' . $ext)) {
            Response::fail('保存文件失败');
        }
        $url = rtrim($cfg['upload_url'], '/') . '/' . $sub . '/logo.' . $ext;
        ControlDb::exec('UPDATE saas_tenant SET logo_url = ? WHERE id = ?', [$url, $tid]);
        Response::ok(['logo_url' => $url], 'Logo 已更新');
    }
}
```

确认 `api/index.php` 已加 `TenantController::routes($router);`（任务 4 预留）。

- [ ] **步骤 4：运行测试验证通过**

运行：`php83 tests/run.php 2>&1 | tail -3 && php83 tests/guard_no_raw_business_sql.php`
预期：`46 passed, 0 failed`（38 + 注册 4 + 平台端到端转绿 + 平台前 3 已绿）+ `guard ok`。若「合法注册」用例报限流（429），说明同 IP 一小时内注册测试跑太多次，删掉 `api/runtime/ratelimit/` 下文件再跑。

- [ ] **步骤 5：Commit**

```bash
git add api/src/controllers/TenantController.php api/index.php tests/test_register.php tests/test_platform_api.php
git commit -m "feat: 企业自助注册与品牌公开接口（限流 + 落 pending）"
```

---

## 任务 6：平台超管播种脚本（scripts/seed_platform_admin.php）

**背景：** `saas_platform_user` 不提供注册入口，平台超管靠 CLI 手工播。`tests/test_platform_api.php` 依赖 dev 库里存在 `platform / Platform@12345`。

**文件：**
- 创建：`scripts/seed_platform_admin.php`

- [ ] **步骤 1：写脚本**

```php
#!/usr/bin/env php83
<?php
// 播种/重置平台超管。用法：php83 scripts/seed_platform_admin.php <username> <password≥10位> [姓名]
// 幂等：username 已存在则重置密码并启用。
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
$root = dirname(__DIR__) . '/api';
spl_autoload_register(function (string $class) use ($root): void {
    $prefix = 'QG\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require $root . '/src/App.php';
require $root . '/src/Response.php';

use QG\ControlDb;

$u = $argv[1] ?? '';
$p = $argv[2] ?? '';
if ($u === '' || strlen($p) < 10) {
    fwrite(STDERR, "用法：php83 scripts/seed_platform_admin.php <username> <password≥10位> [姓名]\n");
    exit(1);
}
$hash = password_hash($p, PASSWORD_DEFAULT);
$exists = (bool) ControlDb::val('SELECT COUNT(*) FROM saas_platform_user WHERE username = ?', [$u]);
if ($exists) {
    ControlDb::exec('UPDATE saas_platform_user SET password_hash = ?, real_name = ?, status = 1 WHERE username = ?',
        [$hash, $argv[3] ?? $u, $u]);
    echo "已重置：{$u}\n";
} else {
    $id = ControlDb::insert('saas_platform_user', [
        'username' => $u, 'real_name' => $argv[3] ?? $u, 'password_hash' => $hash, 'status' => 1,
    ]);
    echo "已创建：{$u} (#{$id})\n";
}
```

- [ ] **步骤 2：在 dev 播种并验证**

```bash
cd /data/qingguan/app
php83 scripts/seed_platform_admin.php platform 'Platform@12345' 平台超管
sudo -n mysql -N -B -e "SELECT username,status FROM qingguan_control_dev.saas_platform_user"
```

预期：输出「已创建：platform (#1)」（或已重置），查询返回 `platform 1`。

- [ ] **步骤 3：重跑平台 HTTP 用例确认登录可用**

运行：`php83 tests/run.php 2>&1 | grep -E "platform|平台"`
预期：平台 4 用例全 PASS。

- [ ] **步骤 4：Commit**

```bash
git add scripts/seed_platform_admin.php
git commit -m "feat: 平台超管播种脚本（CLI，幂等重置）"
```

---

## 任务 7：common.js 两处小改（401 跳转可覆盖 + upload 自定义 url）

**背景：** ① `common.js:111-115` 收到 `code:401` 时硬跳 `/login.html`——平台页（platform.html）会话过期应跳 `/admin-login.html`，所以支持 `window.QG_LOGIN_URL` 覆盖。② `QG.upload` 写死 POST `/upload`，Logo 上传要发 `/tenant/logo`。

**文件：**
- 修改：`assets/js/common.js:111-115`、`:130-135`

- [ ] **步骤 1：改 401 跳转**

把：

```js
        if (j.code === 401) {
          if (w.top !== w) { w.top.location.href = '/login.html'; }
          else { w.location.href = '/login.html'; }
          throw new Error('未登录');
        }
```

改为：

```js
        if (j.code === 401) {
          var loginUrl = w.QG_LOGIN_URL || '/login.html';
          if (w.top !== w && !w.QG_LOGIN_URL) { w.top.location.href = loginUrl; }
          else { w.location.href = loginUrl; }
          throw new Error('未登录');
        }
```

（顶层页设了 `QG_LOGIN_URL` 就跳自己指定的登录页；iframe 里的子页面维持「跳外壳登录页」的旧行为。）

- [ ] **步骤 2：QG.upload 支持自定义 url**

把：

```js
    upload: function (file, extra) {
      var fd = new FormData();
      fd.append('file', file);
      if (extra) Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
      return request('POST', '/upload', fd, true);
    },
```

改为：

```js
    upload: function (file, extra, url) {
      var fd = new FormData();
      fd.append('file', file);
      if (extra) Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
      return request('POST', url || '/upload', fd, true);
    },
```

- [ ] **步骤 3：回归 + Commit**

运行：`php83 tests/run.php 2>&1 | tail -3`（预期 `46 passed, 0 failed`——纯 JS 改动，确认无 PHP 回归）

```bash
git add assets/js/common.js
git commit -m "feat: 401 跳转地址可覆盖（QG_LOGIN_URL），upload 支持自定义接口"
```

---

## 任务 8：admin-login.html + platform.html（平台后台骨架）

**文件：**
- 创建：`admin-login.html`
- 创建：`platform.html`

- [ ] **步骤 1：创建 admin-login.html**

```html
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>平台管理登录 · 清关管理系统</title>
<link rel="icon" href="/favicon.ico">
<link rel="stylesheet" href="/assets/vendor/element-plus.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div id="app" v-cloak>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-logo">清</div>
      <h1>平台管理</h1>
      <div class="sub">清关管理系统 · 运营后台</div>
      <el-form @submit.prevent="submit" @keyup.enter="submit">
        <el-input v-model="form.username" placeholder="平台账号" size="large" clearable>
          <template #prefix><el-icon><User /></el-icon></template>
        </el-input>
        <el-input v-model="form.password" type="password" placeholder="密码" size="large" show-password>
          <template #prefix><el-icon><Lock /></el-icon></template>
        </el-input>
        <el-button type="primary" size="large" :loading="loading" @click="submit">登 录</el-button>
      </el-form>
      <div class="login-foot">© {{ year }} 清关管理系统</div>
    </div>
  </div>
</div>
<script src="/assets/vendor/vue.global.prod.js"></script>
<script src="/assets/vendor/element-plus.full.min.js"></script>
<script src="/assets/vendor/icons.iife.min.js"></script>
<script src="/assets/js/common.js"></script>
<script>
QG.mount(function () {
  return {
    data: function () {
      return { form: { username: '', password: '' }, loading: false, year: new Date().getFullYear() };
    },
    methods: {
      submit: function () {
        var self = this;
        if (!self.form.username || !self.form.password) {
          return QG.warn('请输入账号和密码');
        }
        self.loading = true;
        QG.post('/platform/login', self.form)
          .then(function () { location.href = '/platform.html'; })
          .catch(function (e) { QG.err(e); })
          .finally(function () { self.loading = false; });
      }
    }
  };
});
</script>
</body>
</html>
```

- [ ] **步骤 2：创建 platform.html**

注意 `<script>window.QG_LOGIN_URL = '/admin-login.html';</script>` 必须在 common.js **之前**，会话过期才会跳回平台登录页。`QG.confirm(msg, title)` 返回 Promise。

```html
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>租户审核 · 平台管理</title>
<link rel="icon" href="/favicon.ico">
<link rel="stylesheet" href="/assets/vendor/element-plus.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div id="app" v-cloak>
<div class="page" v-loading="loading">
  <div class="page-head">
    <h2 style="margin:0">租户审核</h2>
    <div class="spacer"></div>
    <el-radio-group v-model="status" @change="load">
      <el-radio-button value="pending">待审核</el-radio-button>
      <el-radio-button value="active">已开通</el-radio-button>
      <el-radio-button value="rejected">已驳回</el-radio-button>
      <el-radio-button value="suspended">已停用</el-radio-button>
      <el-radio-button value="">全部</el-radio-button>
    </el-radio-group>
    <el-button @click="logout">退出</el-button>
  </div>
  <div class="card">
    <el-table :data="rows">
      <el-table-column prop="id" label="ID" width="70"></el-table-column>
      <el-table-column prop="code" label="租户代码" width="120"></el-table-column>
      <el-table-column prop="name" label="企业名称" min-width="170"></el-table-column>
      <el-table-column prop="plan_name" label="套餐" width="140"></el-table-column>
      <el-table-column label="隔离档" width="80">
        <template #default="s">{{ s.row.mode === 'dedicated' ? '独享' : '共享' }}</template>
      </el-table-column>
      <el-table-column label="联系人" min-width="150">
        <template #default="s">{{ s.row.contact_name }} {{ s.row.contact_phone || s.row.contact_email }}</template>
      </el-table-column>
      <el-table-column prop="admin_username" label="管理员" width="100"></el-table-column>
      <el-table-column prop="applied_at" label="申请时间" width="160"></el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="s"><el-tag :type="tagType(s.row.status)">{{ statusText(s.row.status) }}</el-tag></template>
      </el-table-column>
      <el-table-column label="操作" width="210" fixed="right">
        <template #default="s">
          <template v-if="s.row.status === 'pending'">
            <el-button size="small" type="primary" @click="approve(s.row)">通过</el-button>
            <el-button size="small" type="danger" plain @click="reject(s.row)">驳回</el-button>
          </template>
          <el-button v-if="s.row.status === 'active'" size="small" type="warning" plain @click="suspend(s.row)">停用</el-button>
          <el-button v-if="s.row.status === 'suspended'" size="small" type="success" plain @click="enable(s.row)">启用</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination style="margin-top:12px;justify-content:flex-end" layout="total, prev, pager, next"
      :total="total" :page-size="q.pageSize" :current-page="q.page"
      @current-change="function (p) { q.page = p; load(); }"></el-pagination>
  </div>
</div>
</div>
<script>window.QG_LOGIN_URL = '/admin-login.html';</script>
<script src="/assets/vendor/vue.global.prod.js"></script>
<script src="/assets/vendor/element-plus.full.min.js"></script>
<script src="/assets/vendor/icons.iife.min.js"></script>
<script src="/assets/js/common.js"></script>
<script>
QG.mount(function () {
  return {
    data: function () {
      return { rows: [], total: 0, loading: false, status: 'pending', q: { page: 1, pageSize: 20 } };
    },
    created: function () { this.load(); },
    methods: {
      load: function () {
        var self = this;
        self.loading = true;
        QG.get('/platform/tenants', { status: self.status, page: self.q.page, pageSize: self.q.pageSize })
          .then(function (d) { self.rows = d.list || []; self.total = d.total || 0; })
          .catch(function (e) { QG.err(e); })
          .finally(function () { self.loading = false; });
      },
      statusText: function (s) {
        return { pending: '待审核', active: '已开通', rejected: '已驳回', suspended: '已停用' }[s] || s;
      },
      tagType: function (s) {
        return { pending: 'warning', active: 'success', rejected: 'danger', suspended: 'info' }[s] || 'info';
      },
      approve: function (row) {
        var self = this;
        QG.confirm('确定通过「' + row.name + '」？将立即播种默认数据并创建管理员 ' + row.admin_username + '。').then(function () {
          QG.post('/platform/tenants/' + row.id + '/approve', {})
            .then(function () { QG.toast('已开通'); self.load(); })
            .catch(function (e) { QG.err(e); });
        }).catch(function () {});
      },
      reject: function (row) {
        var self = this;
        ElementPlus.ElMessageBox.prompt('请输入驳回原因（会展示给申请人）', '驳回 ' + row.name, {
          inputPattern: /\S+/, inputErrorMessage: '原因不能为空'
        }).then(function (r) {
          QG.post('/platform/tenants/' + row.id + '/reject', { reason: r.value })
            .then(function () { QG.toast('已驳回'); self.load(); })
            .catch(function (e) { QG.err(e); });
        }).catch(function () {});
      },
      suspend: function (row) {
        var self = this;
        QG.confirm('确定停用「' + row.name + '」？其用户将无法登录。').then(function () {
          QG.post('/platform/tenants/' + row.id + '/suspend', {})
            .then(function () { QG.toast('已停用'); self.load(); })
            .catch(function (e) { QG.err(e); });
        }).catch(function () {});
      },
      enable: function (row) {
        var self = this;
        QG.post('/platform/tenants/' + row.id + '/enable', {})
          .then(function () { QG.toast('已启用'); self.load(); })
          .catch(function (e) { QG.err(e); });
      },
      logout: function () {
        QG.post('/platform/logout', {}).finally(function () { location.href = '/admin-login.html'; });
      }
    }
  };
});
</script>
</body>
</html>
```

- [ ] **步骤 3：浏览器验证 + Commit**

打开 `https://qingguan-dev.sflswall.com.cn/admin-login.html`，用 `platform / Platform@12345` 登录 → 应进入 `platform.html` 并看到待审/全部租户列表（demo 租户在「已开通」里）。然后：

```bash
git add admin-login.html platform.html
git commit -m "feat: 平台登录页与租户审核后台"
```

---

## 任务 9：register.html（企业自助注册页）

**文件：**
- 创建：`register.html`

- [ ] **步骤 1：创建 register.html**

```html
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>企业注册 · 清关管理系统</title>
<link rel="icon" href="/favicon.ico">
<link rel="stylesheet" href="/assets/vendor/element-plus.css">
<link rel="stylesheet" href="/assets/css/app.css">
<style>
  .reg-card { max-width: 640px; margin: 40px auto; background: #fff; border-radius: 14px; padding: 32px 36px; }
  .plan-item { border: 1px solid #dcdfe6; border-radius: 10px; padding: 14px 16px; margin-bottom: 10px; cursor: pointer; }
  .plan-item.active { border-color: var(--qg-blue); background: #f0f6ff; }
  .plan-price { color: #e6a23c; font-weight: 600; }
</style>
</head>
<body>
<div id="app" v-cloak>
  <div class="login-wrap" style="align-items:flex-start">
    <div class="reg-card">
      <template v-if="!done">
        <h1 style="margin:0 0 4px">注册企业账号</h1>
        <div class="sub" style="margin-bottom:20px">提交后平台将在 1 个工作日内完成审核</div>
        <el-form label-width="100px" @submit.prevent="submit">
          <el-form-item label="企业名称" required>
            <el-input v-model="form.name" placeholder="营业执照上的企业全称" @blur="suggestCode"></el-input>
          </el-form-item>
          <el-form-item label="租户代码" required>
            <el-input v-model="form.code" placeholder="登录用，如 pengcheng" @blur="checkCode"></el-input>
            <div v-if="codeMsg" :style="{ color: codeOk ? '#67c23a' : '#f56c6c', fontSize: '12px' }">{{ codeMsg }}</div>
          </el-form-item>
          <el-form-item label="联系人" required><el-input v-model="form.contact_name"></el-input></el-form-item>
          <el-form-item label="手机号"><el-input v-model="form.contact_phone"></el-input></el-form-item>
          <el-form-item label="邮箱"><el-input v-model="form.contact_email"></el-input></el-form-item>
          <el-form-item label="管理员账号" required><el-input v-model="form.admin_username" placeholder="3-30 位字母、数字或下划线"></el-input></el-form-item>
          <el-form-item label="登录密码" required><el-input v-model="form.password" type="password" show-password placeholder="至少 8 位"></el-input></el-form-item>
          <el-form-item label="确认密码" required><el-input v-model="form.password2" type="password" show-password></el-input></el-form-item>
          <el-form-item label="套餐" required>
            <div style="width:100%">
              <div v-for="p in plans" :key="p.id" class="plan-item" :class="{ active: form.plan_id === p.id }" @click="form.plan_id = p.id">
                <div><b>{{ p.name }}</b> <span class="plan-price">¥{{ p.price_month }}/月</span></div>
                <div style="font-size:12px;color:#909399">
                  {{ p.mode === 'dedicated' ? '独享数据库，物理隔离' : '共享数据库，逻辑隔离' }} · {{ p.remark }}
                </div>
              </div>
            </div>
          </el-form-item>
          <el-form-item>
            <el-button type="primary" size="large" :loading="loading" @click="submit">提交申请</el-button>
            <el-button size="large" @click="location.href = '/login.html'">返回登录</el-button>
          </el-form-item>
        </el-form>
      </template>
      <template v-else>
        <el-result icon="success" title="申请已提交" sub-title="平台审核通过后即可用「租户代码 + 管理员账号」登录，请留意登录页提示。">
          <template #extra>
            <el-button type="primary" @click="location.href = '/login.html'">去登录页</el-button>
          </template>
        </el-result>
      </template>
    </div>
  </div>
</div>
<script src="/assets/vendor/vue.global.prod.js"></script>
<script src="/assets/vendor/element-plus.full.min.js"></script>
<script src="/assets/vendor/icons.iife.min.js"></script>
<script src="/assets/js/common.js"></script>
<script>
QG.mount(function () {
  return {
    data: function () {
      return {
        form: { name: '', code: '', contact_name: '', contact_phone: '', contact_email: '',
                admin_username: '', password: '', password2: '', plan_id: 0 },
        plans: [], codeOk: false, codeMsg: '', loading: false, done: false
      };
    },
    created: function () {
      var self = this;
      QG.get('/plans/public').then(function (d) {
        self.plans = d || [];
        if (self.plans.length) { self.form.plan_id = self.plans[0].id; }
      }).catch(function (e) { QG.err(e); });
    },
    methods: {
      suggestCode: function () {
        // 不猜拼音，只在代码为空时提示规则，让用户自己填
        if (!this.form.code && this.form.name) { this.codeMsg = '建议用企业名拼音缩写，2-30 位小写字母/数字/短横线'; }
      },
      checkCode: function () {
        var self = this;
        var code = (self.form.code || '').trim().toLowerCase();
        self.form.code = code;
        if (!code) { self.codeOk = false; self.codeMsg = ''; return; }
        QG.get('/tenant/code-check', { code: code }).then(function (d) {
          self.codeOk = !!d.available;
          self.codeMsg = d.available ? '✓ 该代码可用' : (d.msg || '该代码不可用');
        }).catch(function () {});
      },
      submit: function () {
        var self = this;
        var f = self.form;
        if (!f.name || !f.code || !f.contact_name || !f.admin_username || !f.password) {
          return QG.warn('请完整填写带 * 的必填项');
        }
        if (!f.contact_phone && !f.contact_email) { return QG.warn('手机号与邮箱至少填一个'); }
        if (f.password.length < 8) { return QG.warn('密码至少 8 位'); }
        if (f.password !== f.password2) { return QG.warn('两次输入的密码不一致'); }
        if (!f.plan_id) { return QG.warn('请选择套餐'); }
        if (!self.codeOk) { return QG.warn('请先确认租户代码可用'); }
        self.loading = true;
        QG.post('/tenant/register', f)
          .then(function () { self.done = true; })
          .catch(function (e) { QG.err(e); })
          .finally(function () { self.loading = false; });
      }
    }
  };
});
</script>
</body>
</html>
```

- [ ] **步骤 2：浏览器验证 + Commit**

打开 `https://qingguan-dev.sflswall.com.cn/register.html`：套餐自动渲染两个档位；输入 `demo` 失焦提示已占用；走一遍真实注册（用一个随即代码，注册成功后到平台后台通过，再用新租户登录验证，最后在控制面删掉该测试租户及其种子数据——清理 SQL 参照 `tests/test_provision.php` 的 `prov_cleanup()`）。然后：

```bash
git add register.html
git commit -m "feat: 企业自助注册页"
```

---

## 任务 10：login.html 与 index.html 的品牌展示 + app.css

**文件：**
- 修改：`login.html`（品牌区 + 失焦拉取）
- 修改：`index.html:21-25`（侧栏品牌区）
- 修改：`assets/css/app.css`（追加 Logo 样式）

- [ ] **步骤 1：login.html 加品牌**

品牌区模板，把：

```html
      <div class="login-logo">清</div>
      <h1>清关管理系统</h1>
```

改为：

```html
      <img v-if="brand.logo" :src="brand.logo" class="login-logo-img" alt="">
      <div v-else class="login-logo">清</div>
      <h1>{{ brand.name || '清关管理系统' }}</h1>
```

租户代码输入框加失焦事件：

```html
        <el-input v-model="form.tenant" placeholder="请输入租户代码" size="large" clearable @blur="onTenantBlur">
```

data 的返回对象里加 `brand: { name: '', logo: '' }`；methods 里加：

```js
      onTenantBlur: function () {
        var self = this;
        var code = (self.form.tenant || '').trim();
        if (!code) { self.brand = { name: '', logo: '' }; return; }
        QG.get('/tenant/branding', { code: code }).then(function (d) {
          self.brand = (d && d.found) ? { name: d.name, logo: d.logo_url } : { name: '', logo: '' };
        }).catch(function () {});
      },
```

- [ ] **步骤 2：index.html 侧栏品牌区**

`index.html:21-25` 的品牌块：

```html
  <div class="brand">
    <div class="dot">清</div>
    <div class="txt">清关管理系统</div>
  </div>
```

改为：

```html
  <div class="brand">
    <img v-if="user.tenant_logo" :src="user.tenant_logo" class="logo" alt="">
    <div v-else class="dot">清</div>
    <div class="txt">{{ user.tenant_name || '清关管理系统' }}</div>
  </div>
```

（`user` 已由外壳 `QG.get('/auth/me')` 加载；`tenant_name` / `tenant_logo` 由 `Auth::user()` 提供，无需改后端。）

- [ ] **步骤 3：app.css 追加样式**

在 `assets/css/app.css` 末尾追加：

```css
/* 租户 Logo */
.brand .logo { width: 32px; height: 32px; border-radius: 8px; object-fit: contain; background: #fff; flex: none; }
.login-logo-img { width: 56px; height: 56px; border-radius: 12px; object-fit: contain; margin: 0 auto 12px; display: block; background: #fff; }
```

（`.brand .dot` 是 32px 圆角块，`.logo` 与其同尺寸对齐；若实际渲染尺寸不符，以 `.brand .dot` 的真实宽高为准微调。）

- [ ] **步骤 4：浏览器验证 + Commit**

登录页输入 `demo` 失焦 → 标题变「示例企业（原单机数据）」；清空 → 退回「清关管理系统」。登录后外壳侧栏显示租户名（无 Logo 时仍是「清」块 + 租户名）。然后：

```bash
git add login.html index.html assets/css/app.css
git commit -m "feat: 登录页与外壳按租户代码展示企业名称/Logo"
```

---

## 任务 11：sys-config.html Logo 上传 + 品牌/上传 HTTP 测试

**文件：**
- 修改：`pages/sys-config.html`
- 测试：`tests/test_branding_logo.php`

- [ ] **步骤 1：编写失败的测试**

创建 `tests/test_branding_logo.php`：

```php
<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/bootstrap.php';

use QG\App;
use QG\ControlDb;

/** 登录 demo/admin 拿 cookie jar；站点不在返回 null */
function admin_jar(): ?string
{
    if (!qg_site_up()) { return null; }
    $jar = tempnam(sys_get_temp_dir(), 'qgck');
    $r = qg_post_json('/api/auth/login', ['tenant' => 'demo', 'username' => 'admin', 'password' => 'admin123'], ['-c', $jar]);
    if (strpos($r, '"code":0') === false) { @unlink($jar); return null; }
    return $jar;
}

/** 1x1 透明 PNG */
function tmp_png(): string
{
    $f = tempnam(sys_get_temp_dir(), 'qgpng') . '.png';
    file_put_contents($f, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    return $f;
}

t('Logo：png 上传成功并写回控制面 logo_url', function () {
    $jar = admin_jar();
    if (!$jar) { return; }
    $png = tmp_png();
    $r = qg_curl('/api/tenant/logo', ['-b', $jar, '-F', 'file=@' . $png . ';type=image/png']);
    assert_contains($r, '"code":0', 'png 应上传成功：' . $r);
    assert_contains($r, 't1/branding/logo.png', 'URL 应在 t1/branding 下：' . $r);
    $url = ControlDb::val("SELECT logo_url FROM saas_tenant WHERE code = 'demo'");
    assert_contains((string) $url, '/uploads/t1/branding/logo.png', '控制面应写回 logo_url');
    // 清理：还原 logo_url，删除落盘文件
    ControlDb::exec("UPDATE saas_tenant SET logo_url = '' WHERE code = 'demo'");
    $cfg = App::config('app');
    @unlink(rtrim($cfg['upload_dir'], '/\\') . '/t1/branding/logo.png');
    @unlink($png);
    @unlink($jar);
});

t('Logo：svg 被拒（XSS 风险）', function () {
    $jar = admin_jar();
    if (!$jar) { return; }
    $svg = tempnam(sys_get_temp_dir(), 'qgsvg') . '.svg';
    file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $r = qg_curl('/api/tenant/logo', ['-b', $jar, '-F', 'file=@' . $svg . ';type=image/svg+xml']);
    assert_contains($r, '仅支持 PNG/JPG', 'svg 应被拒：' . $r);
    @unlink($svg);
    @unlink($jar);
});

t('branding：demo 返回企业名，未知代码 found=false', function () {
    if (!qg_site_up()) { return; }
    $r = qg_curl('/api/tenant/branding?code=demo');
    assert_contains($r, '"found":true', 'demo 应命中：' . $r);
    assert_contains($r, '示例企业', '应返回租户名：' . $r);
    $r = qg_curl('/api/tenant/branding?code=nosuch');
    assert_contains($r, '"found":false', '未知代码 found=false：' . $r);
});

t('订单附件上传路径带 t<tid>/ 前缀', function () {
    $jar = admin_jar();
    if (!$jar) { return; }
    $png = tmp_png();
    $r = qg_curl('/api/upload', ['-b', $jar, '-F', 'file=@' . $png . ';type=image/png']);
    assert_contains($r, '"code":0', '上传应成功：' . $r);
    assert_contains($r, '"file_path":"t1/order/', 'file_path 应带 t1/ 前缀：' . $r);
    // 清理落盘文件
    $cfg = App::config('app');
    if (preg_match('/"file_path":"([^"]+)"/', $r, $m)) {
        @unlink(rtrim($cfg['upload_dir'], '/\\') . '/' . str_replace('\\/', '/', $m[1]));
    }
    @unlink($png);
    @unlink($jar);
});

t('附件搬迁脚本 dry-run 可执行且幂等', function () {
    $out = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && php83 scripts/migrate_uploads_namespace.php --dry-run 2>&1');
    assert_contains($out, 'dry-run', '应输出 dry-run 摘要：' . $out);
});
```

注意：`Response::json` 用了 `JSON_UNESCAPED_SLASHES`，所以 `file_path` 里的 `/` 不会转义，断言按 `"file_path":"t1/order/` 写（若失败以实际输出为准调整）。

- [ ] **步骤 2：运行测试验证失败**

运行：`php83 tests/run.php 2>&1 | grep FAIL`
预期：Logo 两个用例 FAIL（接口 404）；附件前缀用例 FAIL（仍是 `order/` 前缀）；搬迁脚本用例 FAIL（脚本不存在）。branding 用例此时应已 PASS（任务 5 已实现）。

- [ ] **步骤 3：sys-config.html 加 Logo 卡片**

在「企业信息」卡片（`pages/sys-config.html` 的 `.card`）里、`<el-form>` 之后（或卡片内合适位置）加：

```html
    <el-form label-width="130px" style="max-width:680px">
      <el-form-item label="企业 Logo">
        <div style="display:flex;align-items:center;gap:12px">
          <img v-if="logoUrl" :src="logoUrl" style="height:44px;border:1px solid #ebeef5;border-radius:8px;padding:3px;background:#fff">
          <el-button size="small" :disabled="!isAdmin" @click="$refs.logoFile.click()">选择图片</el-button>
          <input ref="logoFile" type="file" accept=".png,.jpg,.jpeg" style="display:none" @change="uploadLogo">
          <span class="muted" style="font-size:12px">PNG/JPG ≤2MB，展示在登录页与系统左上角{{ isAdmin ? '' : '（仅管理员可更换）' }}</span>
        </div>
      </el-form-item>
    </el-form>
```

data 返回对象加 `logoUrl: '', isAdmin: false`；`created` 里（或 load 里）补：

```js
      QG.me().then(function (u) {
        self.isAdmin = (u.role_code === 'tenant_admin');
        self.logoUrl = u.tenant_logo || '';
      });
```

methods 加：

```js
      uploadLogo: function (e) {
        var self = this;
        var f = e.target.files && e.target.files[0];
        e.target.value = '';
        if (!f) { return; }
        QG.upload(f, {}, '/tenant/logo').then(function (d) {
          self.logoUrl = d.logo_url + '?_=' + Date.now();
          QG.toast('Logo 已更新，刷新页面后外壳与登录页生效');
        }).catch(function (e2) { QG.err(e2); });
      },
```

注意 `QG.me()` 是异步的，`isAdmin` 初始 false 会让按钮短暂禁用——可接受（页面加载即取）。

- [ ] **步骤 4：运行测试验证 Logo 用例通过**

运行：`php83 tests/run.php 2>&1 | grep -E "Logo|branding"`
预期：Logo 与 branding 用例 PASS（附件前缀与搬迁脚本仍 FAIL，任务 12 处理）。

- [ ] **步骤 5：Commit**

```bash
git add pages/sys-config.html tests/test_branding_logo.php
git commit -m "feat: 收发信息页支持上传租户 Logo（PNG/JPG ≤2MB，MIME 复核）"
```

---

## 任务 12：附件按租户命名空间（写入改造 + 存量搬迁脚本）

**文件：**
- 修改：`api/src/controllers/UploadController.php:45`（`$sub` 行）
- 创建：`scripts/migrate_uploads_namespace.php`

- [ ] **步骤 1：改 UploadController**

确认文件顶部 `use` 块有 `use QG\Tenant;`（没有就加），然后把（约 45-46 行）：

```php
        $sub = 'order/' . date('Y') . '/' . date('m');
```

改为：

```php
        $sub = 't' . Tenant::requireId() . '/order/' . date('Y') . '/' . date('m');
```

过渡期兼容性（设计 §5.6）：旧文件仍按 `order/...` 旧 URL 可访问，新上传进 `t<tid>/`，搬迁脚本收口前两边都能看。

- [ ] **步骤 2：创建搬迁脚本**

```php
#!/usr/bin/env php83
<?php
// 存量附件搬迁：uploads/order/... → uploads/t<tid>/order/...，并同步 biz_order_file.file_path。
// 幂等：file_path 已带 tN/ 前缀的行不再匹配 'order/%'，重复执行零改动。
// 用法：php83 scripts/migrate_uploads_namespace.php [--dry-run]
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
$root = dirname(__DIR__) . '/api';
spl_autoload_register(function (string $class) use ($root): void {
    $prefix = 'QG\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require $root . '/src/App.php';
require $root . '/src/Response.php';

use QG\App;

$dry = in_array('--dry-run', $argv, true);
$cfg = App::config('db');
$app = App::config('app');
$base = rtrim($app['upload_dir'], '/\\');

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']),
    $cfg['user'], $cfg['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$rows = $pdo->query("SELECT id, tenant_id, file_path FROM biz_order_file WHERE file_path LIKE 'order/%'")->fetchAll();
$total = count($rows);
$moved = 0;
$missing = 0;
echo ($dry ? '[dry-run] ' : '') . "待搬迁：{$total} 条\n";
$upd = $pdo->prepare('UPDATE biz_order_file SET file_path = ? WHERE id = ?');
foreach ($rows as $r) {
    $old = $r['file_path'];
    $new = 't' . (int) $r['tenant_id'] . '/' . $old;
    $src = $base . '/' . $old;
    $dst = $base . '/' . $new;
    if (!is_file($src)) {
        echo "  [缺文件] #{$r['id']} {$old}（跳过，DB 不动）\n";
        $missing++;
        continue;
    }
    if ($dry) {
        echo "  {$old} -> {$new}\n";
        continue;
    }
    if (!is_dir(dirname($dst)) && !mkdir(dirname($dst), 0775, true)) {
        echo "  [失败] 无法建目录：" . dirname($dst) . "\n";
        $missing++;
        continue;
    }
    if (!rename($src, $dst)) {
        echo "  [失败] 移动失败：{$old}\n";
        $missing++;
        continue;
    }
    $upd->execute([$new, (int) $r['id']]);
    $moved++;
}
echo ($dry ? '[dry-run] ' : '') . "完成：迁移 {$moved}，缺文件/失败 {$missing}，共 {$total}\n";
```

- [ ] **步骤 3：dev 上先 dry-run 再实跑**

```bash
cd /data/qingguan/app
php83 scripts/migrate_uploads_namespace.php --dry-run
php83 scripts/migrate_uploads_namespace.php
php83 scripts/migrate_uploads_namespace.php   # 再跑一遍验证幂等：待搬迁 0 条
```

预期：dry-run 列出 `order/... -> t1/order/...` 清单；实跑「完成：迁移 N，缺文件/失败 0」；二跑「待搬迁：0 条」。最后抽查一个订单详情页，旧附件仍能打开（URL 已是 `t1/...`）。

- [ ] **步骤 4：全套件回归 + Commit**

运行：`php83 tests/run.php 2>&1 | tail -3 && php83 tests/guard_no_raw_business_sql.php`
预期：全部 PASS（含任务 11 的附件前缀与搬迁脚本用例）+ `guard ok`。注意 `tests/test_repo_file.php:16` 用合成路径 `order/2026/10/unit-test.pdf` 只是 DB 行、不涉及真实文件，不受命名空间影响。

```bash
git add api/src/controllers/UploadController.php scripts/migrate_uploads_namespace.php
git commit -m "feat: 附件按租户命名空间 uploads/t<tid>/（含存量搬迁脚本）"
```

---

## 任务 13：收尾回归 + version.json + 文档口径

**文件：**
- 修改：`version.json`
- 修改：`AGENTS.md`
- 修改：`docs/superpowers/specs/2026-10-15-self-service-onboarding-branding-design.md`

- [ ] **步骤 1：全套件回归**

```bash
cd /data/qingguan/app
php83 tests/run.php
php83 tests/guard_no_raw_business_sql.php
```

预期：全绿（基线 31 + 本计划新增 20 = **51 passed, 0 failed**；如个别用例数有出入以实际为准并同步改 AGENTS.md）+ `guard ok`。HTTP 用例依赖 dev 站点在线。

- [ ] **步骤 2：bump version.json**

```bash
cd /data/qingguan/app
BUILD=$(git rev-parse --short=10 HEAD)
TIME=$(date '+%Y-%m-%d %H:%M')
printf '{\n  "build": "%s",\n  "time": "%s",\n  "files": 22\n}\n' "$BUILD" "$TIME" > version.json
```

（`files` 字段保持原值 22 即可，它是旧打包脚本留下的计数，前端只用 `build`。）

- [ ] **步骤 3：更新 AGENTS.md**

- §3 代码地图：`api/src/controllers/` 由「7 个」改为「9 个：Auth/Order/Plan/Org/Finance/System/Upload + Platform（平台审核）/Tenant（注册与品牌）」；新增 `api/src/TenantProvisioner.php`、`api/src/RateLimit.php`、`scripts/` 两脚本、三个顶层页的说明。
- §4 认证：删掉/改写「平台登录目前只有 Auth 里的分支，没有注册任何 controller/route」——平台侧已由 `PlatformController` 接线，`Auth::requirePlatform()` 做 scope 隔离。
- §7 测试基线计数更新为实际值。
- §9 已知坑：删掉第 4 条「平台侧还没接线」、第 6 条「附件按租户分目录尚未实现」（均已落地）；补一条新坑：「schema.sql 的语句字符串内不得含 ASCII 分号（ProvisionRepo 按分号切段）」。
- §8 补一句：改完前端要 bump `version.json` 的 `build`（取 `git rev-parse --short=10 HEAD`）。

- [ ] **步骤 4：更新设计稿状态**

`docs/superpowers/specs/2026-10-15-self-service-onboarding-branding-design.md`：
- 头部「状态：设计稿，待评审」→「状态：已实现（2026-10-15，见 plans/2026-10-15-self-service-onboarding-branding.md）」。
- §2 与 §10 里关于「`tenant_id` 全表 DEFAULT 1」的表述：标注已于 2026-10-15 通过 `db/migration_20261015_drop_tenant_default.sql` 去除（二期前置清理项已完成）。

- [ ] **步骤 5：Commit + push**

```bash
git add version.json AGENTS.md docs/superpowers/specs/2026-10-15-self-service-onboarding-branding-design.md
git commit -m "docs: 自助开通与品牌落地后的口径更新 + version bump"
git push
```

---

## 上线（breeder）清单（本计划之外，执行时参考 AGENTS.md §2 发布顺序）

1. 备份 → 跑 `db/migration_20261015_drop_tenant_default.sql` 与 `db/migration_20261015_config_cleanup.sql`（都幂等）→ 同步代码 → reload apache2。
2. breeder 上跑 `php83 scripts/seed_platform_admin.php <生产超管账号> <强密码>`（**不要用 dev 的 Platform@12345**）。
3. 跑附件搬迁脚本（先 `--dry-run`）。
4. 核对 breeder vhost（未入库）有与 dev 相同的 db/ops/tests/docs 拒绝规则。
5. 验证：register.html 注册 → platform.html 通过 → 新租户登录 → 上传 Logo → 登录页品牌展示。
