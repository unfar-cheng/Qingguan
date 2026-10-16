# 二期专用档（dedicated）自动开通 实现计划

> **面向 AI 代理的工作者：** 必需子技能：使用 superpowers:subagent-driven-development（推荐）或 superpowers:executing-plans 逐任务实现此计划。步骤使用复选框（`- [x]`）语法来跟踪进度。

**目标：** 平台超管点「通过」后全自动完成专用档开通：建库 `qingguan_t_<code>`、建租户专属账号 `qg_<code>`（HMAC 派生密码，不落库）、灌 schema 播种、建管理员、控制面翻 active；另带一个最小迁移扇出脚本。

**架构：** `TenantProvisioner::provision()` 按 mode 分流——shared 分支逐字节保持现状；dedicated 分支走「provision_db 连接做 DDL（建库/建账号/授权）→ 以租户自己的账号连专用库播种（复用 ProvisionRepo）→ 控制面守卫 UPDATE（带 db_name 回写）」。运行时 `Tenant::pdo()` 的 dedicated 分支改用派生凭据连接。规格见 `docs/superpowers/specs/2026-10-15-dedicated-provisioning-design.md`（已评审通过，本计划不重新决策）。

**技术栈：** PHP 8.3 原生（无框架无 composer）+ PDO；MySQL 8.0（生产）与 MariaDB 10.5（dev）双兼容；零依赖测试套件。

---

## 文件结构

| 文件 | 动作 | 职责 |
|---|---|---|
| `api/src/DedicatedDb.php` | 创建 | 专用库命名（`qingguan_t_<code>`）与凭据派生（`qg_<code>` / HMAC 密码）+ `provision_db` 连接工厂。纯静态无状态，Provisioner 与 Tenant 共用 |
| `api/src/App.php` | 修改 | 加 `overrideConfig()` 测试钩子（配对使用，先例：`Tenant::resetPools()`） |
| `api/src/Tenant.php` | 修改 | `pdo()` dedicated 分支改派生凭据；上下文无 `code` 时抛明确异常 |
| `api/src/Auth.php` | 修改 | `user()` 的 `Tenant::set()` 补 `code` 字段 |
| `api/src/TenantProvisioner.php` | 修改 | dedicated 分支：幂等 DDL + 播种 + 分支化状态 UPDATE（回写 db_name） |
| `api/src/repos/ProvisionRepo.php` | 修改 | `splitStatements()` private → public（供迁移脚本复用） |
| `api/src/controllers/PlatformController.php` | 修改 | `tenants()` SELECT 补 `t.db_name` |
| `platform.html` | 修改 | 隔离档列显示 db_name；通过弹窗按 mode 区分文案 |
| `api/config/config.sample.php` | 修改 | 补 `provision_db` 注释段 |
| `scripts/migrate_dedicated.php` | 创建 | 最小迁移扇出：对全部专用库逐个执行指定迁移，`--dry-run` 列清单 |
| `tests/test_dedicated_db.php` | 创建 | DedicatedDb 单测 + Tenant::pdo dedicated 路径测试 |
| `tests/test_provision.php` | 修改 | 「专用档本期拒绝」用例改为「未配置 provision_db 时友好拒绝」 |
| `version.json` / `AGENTS.md` | 修改 | 版本号 bump；数据库/运维口径更新 |

不改动：`db/schema.sql`（零 schema 变更）、`db/control/schema.sql`、shared 开通路径、register.html、login/外壳。

## 环境铁律（执行者必读）

- 本机用 **`php83`**（裸 `php` 是 7.4）；DB 管理 `sudo -n mysql`；测试直连真实 dev 库（`qingguan_dev`），**套件里绝不真建库/建账号**（dev 测试账号无建库权限，也不许产生垃圾库）。
- 提交信息中文 Conventional Commits；只 `git add` 明确路径，绝不 `git add -A`。
- 提交前门禁：`php83 tests/run.php`（60 passed，本计划完成后会变多）+ `php83 tests/guard_no_raw_business_sql.php`（guard ok）。
- 限流桶在 `api/runtime/ratelimit/`，撞 429 就 `rm -rf api/runtime/ratelimit/*`。

---

### 任务 1：`DedicatedDb` 派生类 + `App::overrideConfig` 测试钩子

**文件：**
- 创建：`tests/test_dedicated_db.php`
- 创建：`api/src/DedicatedDb.php`
- 修改：`api/src/App.php`（`config()` 方法后新增一个方法）
- 修改：`api/config/config.sample.php`

- [x] **步骤 1.1：写失败的测试**

创建 `tests/test_dedicated_db.php`（全文）：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

use QG\App;
use QG\DedicatedDb;
use QG\Tenant;

t('DedicatedDb：命名与派生格式', function () {
    assert_eq('qingguan_t_demo', DedicatedDb::name('demo'));
    assert_eq('qg_demo', DedicatedDb::user('demo'));
    assert_eq(64, strlen(DedicatedDb::pass('demo')), '派生密码应为 64 位 hex');
    assert_true((bool) preg_match('/^[0-9a-f]{64}$/', DedicatedDb::pass('demo')), '派生密码应为小写 hex');
});

t('DedicatedDb：同 code 派生稳定、不同 code 派生不同', function () {
    assert_eq(DedicatedDb::pass('demo'), DedicatedDb::pass('demo'));
    assert_true(DedicatedDb::pass('demo') !== DedicatedDb::pass('demo2'), '不同 code 密码必须不同');
    assert_true(DedicatedDb::name('a-b') !== DedicatedDb::name('a_b'), '连字符与下划线是不同 code');
});

t('DedicatedDb：非法 code 拒绝', function () {
    foreach (['', 'A', '-x', 'a_b!!', str_repeat('a', 31)] as $bad) {
        $thrown = false;
        try { DedicatedDb::name($bad); } catch (Throwable $e) { $thrown = true; }
        assert_true($thrown, "code 「{$bad}」应被拒绝");
    }
});

t('DedicatedDb：provision_db 未配置时抛可读异常', function () {
    $orig = App::config();
    $cfg = $orig;
    unset($cfg['provision_db']);
    App::overrideConfig($cfg);
    try {
        DedicatedDb::provisionPdo();
        assert_true(false, '应抛异常');
    } catch (RuntimeException $e) {
        assert_contains($e->getMessage(), '未配置');
    } finally {
        App::overrideConfig($orig);
    }
});

t('Tenant::pdo 专用档：上下文缺少 code 抛明确异常', function () {
    Tenant::resetPools();
    Tenant::set(['id' => 9002, 'mode' => 'dedicated', 'db_name' => 'qingguan_t_9002']);
    try {
        Tenant::pdo();
        assert_true(false, '应抛异常');
    } catch (RuntimeException $e) {
        assert_contains($e->getMessage(), '缺少 code');
    } finally {
        Tenant::set(null);
        Tenant::resetPools();
    }
});

t('Tenant::pdo 专用档：带 code 时走派生凭据连接（不存在的库→PDO 异常）', function () {
    Tenant::resetPools();
    Tenant::set(['id' => 9002, 'mode' => 'dedicated', 'db_name' => 'qingguan_t_9002', 'code' => '9002']);
    try {
        Tenant::pdo();
        assert_true(false, 'qingguan_t_9002 不存在，连接应失败');
    } catch (PDOException $e) {
        assert_true(true, 'PDO 连接异常 = 已走到专用连接路径（真库连通性在任务 7 e2e 验证）');
    } finally {
        Tenant::set(null);
        Tenant::resetPools();
    }
});
```

- [x] **步骤 1.2：运行测试验证失败**

运行：`php83 tests/run.php 2>&1 | tail -8`
预期：`test_dedicated_db.php` 的新用例 FAIL（类不存在，autoload 落空 → PHP Error「Class "QG\DedicatedDb" not found」）。

- [x] **步骤 1.3：实现**

创建 `api/src/DedicatedDb.php`（全文）：

```php
<?php
namespace QG;

/**
 * 专用档（dedicated）租户库的命名与凭据派生。
 * 密码 = HMAC-SHA256('db-pass:<code>', app.secret) 的 hex，现算现用、不落任何存储；
 * app.secret 轮换会使全部专用库账号密码失效——按 AGENTS.md 的重置口径用 ALTER USER 归位。
 * code 合法字符集与注册接口一致（2-30 位 [a-z0-9-]），含连字符的标识符在 DDL 里必须反引号包裹。
 */
class DedicatedDb
{
    /** 库名：qingguan_t_<code> */
    public static function name(string $code): string
    {
        self::assertCode($code);
        return 'qingguan_t_' . $code;
    }

    /** 库账号：qg_<code>（仅授权本库，见 Provisioner 的 GRANT） */
    public static function user(string $code): string
    {
        self::assertCode($code);
        return 'qg_' . $code;
    }

    /** 派生密码：64 位小写 hex */
    public static function pass(string $code): string
    {
        self::assertCode($code);
        return hash_hmac('sha256', 'db-pass:' . $code, App::secret());
    }

    /** 开通专用连接（config 的 provision_db 段）；未配置时抛可读异常，shared 路径完全不读取该段 */
    public static function provisionPdo(): \PDO
    {
        $c = App::config('provision_db');
        if (!$c || empty($c['pass'])) {
            throw new \RuntimeException('专用档开通未配置：config.php 缺少 provision_db 段（host/port/user/pass）');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], $c['port'], $c['charset'] ?? 'utf8mb4');
        return new \PDO($dsn, $c['user'], $c['pass'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    private static function assertCode(string $code): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,29}$/', $code)) {
            throw new \RuntimeException('非法租户代码：' . $code);
        }
    }
}
```

`api/src/App.php` 在 `config()` 方法后新增（`secret()` 之前）：

```php
    /** 测试用：整份替换配置（必须配对恢复原值，先例：Tenant::resetPools） */
    public static function overrideConfig(array $cfg): void
    {
        self::$cfg = $cfg;
    }
```

`api/config/config.sample.php` 在 `control_db` 段后新增：

```php
    // 专用档开通专用（二期）：能建 qingguan_t_% 库与 qg_% 账号，别的一概不能。
    // 缺失或 pass 为空时，专用档审核通过会报「未配置」并保持 pending，共享档完全不受影响。
    'provision_db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'qg_provision',
        'pass' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
```

- [x] **步骤 1.4：运行测试验证通过**

运行：`php83 tests/run.php 2>&1 | tail -3`
预期：`66 passed, 0 failed`（60 + 6）。

- [x] **步骤 1.5：Commit**

```bash
git add tests/test_dedicated_db.php api/src/DedicatedDb.php api/src/App.php api/config/config.sample.php
git commit -m "feat: DedicatedDb 派生类（专用库名/账号/密码）+ provision_db 连接工厂"
```

---

### 任务 2：Tenant 上下文带 code + `Tenant::pdo()` 专用档派生凭据

**文件：**
- 修改：`api/src/Tenant.php:40-60`（`pdo()` 整个方法）
- 修改：`api/src/Auth.php:110`（`Tenant::set` 补 `code`）

- [x] **步骤 2.1：改 `Auth::user()` 的租户上下文**

`api/src/Auth.php` 第 110 行：

```php
        Tenant::set(['id' => (int) $t['id'], 'mode' => $t['mode'], 'db_name' => $t['db_name']]);
```

改为：

```php
        Tenant::set(['id' => (int) $t['id'], 'mode' => $t['mode'], 'db_name' => $t['db_name'], 'code' => $t['code']]);
```

- [x] **步骤 2.2：改 `Tenant::pdo()`**

`api/src/Tenant.php` 的 `pdo()` 整个方法替换为：

```php
    /** 当前租户的数据面连接（共享档→共享库+共享账号；专用档→各自库+派生账号） */
    public static function pdo(): PDO
    {
        $ctx = self::$ctx;
        if (!$ctx) {
            Response::fail('缺少租户上下文', 403, 403);
        }
        $dedicated = ($ctx['mode'] ?? 'shared') === 'dedicated' && !empty($ctx['db_name']);
        $dbName = $dedicated ? $ctx['db_name'] : App::config('db')['name'];
        if (!isset(self::$pools[$dbName])) {
            $c = App::config('db');
            if ($dedicated) {
                $code = (string) ($ctx['code'] ?? '');
                if ($code === '') {
                    throw new \RuntimeException('专用档租户上下文缺少 code，无法派生库账号');
                }
                $user = DedicatedDb::user($code);
                $pass = DedicatedDb::pass($code);
            } else {
                $user = $c['user'];
                $pass = $c['pass'];
            }
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $dbName, $c['charset']);
            self::$pools[$dbName] = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pools[$dbName];
    }
```

- [x] **步骤 2.3：运行测试验证通过 + 全量回归**

运行：`php83 tests/run.php 2>&1 | tail -3 && php83 tests/guard_no_raw_business_sql.php | tail -1`
预期：`66 passed, 0 failed` + `guard ok`。
说明：「缺少 code 抛异常」用例在改动前必红（旧实现走共享凭据，抛的是 PDOException），本任务落地后转绿；「带 code 连不存在的库」用例两态都抛 PDOException，它的价值是锁住「专用路径真的尝试派生连接」，真库连通性在任务 7 e2e 验证。

- [x] **步骤 2.4：Commit**

```bash
git add api/src/Tenant.php api/src/Auth.php
git commit -m "feat: Tenant::pdo 专用档改派生凭据连接，租户上下文补 code"
```

---

### 任务 3：`TenantProvisioner` dedicated 分支

**文件：**
- 修改：`api/src/TenantProvisioner.php`（`provision()` 重排 + 新增 `provisionDedicated()`）
- 修改：`tests/test_provision.php:77-92`（「专用档本期拒绝」用例改写）

- [x] **步骤 3.1：改写失败用例（TDD 先行）**

`tests/test_provision.php` 第 77-92 行的 `t('专用档本期拒绝开通', ...)` 整块替换为：

```php
t('专用档：未配置 provision_db 时友好拒绝且保持 pending', function () {
    prov_cleanup();
    $t = prov_make_tenant();
    ControlDb::exec("UPDATE saas_tenant SET mode = 'dedicated' WHERE id = ?", [PROV_TID]);
    $t = ControlDb::one('SELECT * FROM saas_tenant WHERE id = ?', [PROV_TID]);
    // 用 overrideConfig 制造「未配置」环境，无论本机 config.php 是否已配 provision_db 都可复现
    $orig = App::config();
    $cfg = $orig;
    unset($cfg['provision_db']);
    App::overrideConfig($cfg);
    $msg = '';
    try {
        TenantProvisioner::provision($t, null);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
    } finally {
        App::overrideConfig($orig);
    }
    assert_contains($msg, '未配置', '应报「专用档开通未配置」类错误');
    $after = ControlDb::one('SELECT status, db_name FROM saas_tenant WHERE id = ?', [PROV_TID]);
    assert_eq('pending', $after['status'], '失败后状态应保持 pending');
    assert_eq('', (string) $after['db_name'], '失败后 db_name 应保持空');
    prov_cleanup();
});
```

- [x] **步骤 3.2：运行验证失败**

运行：`php83 tests/run.php 2>&1 | grep -A1 '专用档'`
预期：该用例 FAIL——现有实现抛「专用档（物理隔离）开通尚未开放，归二期」，消息不含「未配置」。

- [x] **步骤 3.3：实现 dedicated 分支**

`api/src/TenantProvisioner.php` 的 `provision()` 整个方法替换为，并新增私有方法：

```php
    public static function provision(array $tenant, ?int $platformUserId): void
    {
        $tid = (int) ($tenant['id'] ?? 0);
        if ($tid <= 0) {
            throw new \RuntimeException('租户 id 非法');
        }
        $mode = (string) ($tenant['mode'] ?? 'shared');
        if (!in_array($mode, ['shared', 'dedicated'], true)) {
            throw new \RuntimeException('未知服务档位：' . $mode);
        }
        $status = (string) ($tenant['status'] ?? '');
        if (!in_array($status, ['pending', 'active'], true)) {
            throw new \RuntimeException('当前状态不可开通：' . ($tenant['status'] ?? ''));
        }
        // 首次开通（pending）必须有账号与密码哈希；active 重放时哈希已在首次开通后清空，
        // 只要账号还在即可（管理员已存在，ensureAdminUser 会跳过）。
        if (empty($tenant['admin_username']) || ($status === 'pending' && empty($tenant['admin_password_hash']))) {
            throw new \RuntimeException('缺少管理员账号或密码哈希，无法开通');
        }

        if ($mode === 'shared') {
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
        } else {
            self::provisionDedicated($tenant, $tid);
        }

        // 状态守卫：UPDATE 只在 status='pending' 时命中。0 行说明期间被 reject/并发改写——
        // 已是 active 视为幂等达成；否则抛异常走 provision_failed，绝不把「已驳回」的租户翻成 active。
        // 专用档同时回写 db_name（失败重试时它是空的，成功才落）。
        if ($mode === 'shared') {
            $n = ControlDb::exec(
                "UPDATE saas_tenant SET status = 'active', reviewed_at = NOW(), reviewed_by = ?, admin_password_hash = '', reject_reason = '' WHERE id = ? AND status = 'pending'",
                [$platformUserId, $tid]
            );
        } else {
            $n = ControlDb::exec(
                "UPDATE saas_tenant SET status = 'active', db_name = ?, reviewed_at = NOW(), reviewed_by = ?, admin_password_hash = '', reject_reason = '' WHERE id = ? AND status = 'pending'",
                [DedicatedDb::name((string) $tenant['code']), $platformUserId, $tid]
            );
        }
        if ($n === 0) {
            $now = ControlDb::one('SELECT status FROM saas_tenant WHERE id = ?', [$tid]);
            if (($now['status'] ?? '') !== 'active') {
                throw new \RuntimeException('租户状态已变化（现为 ' . ($now['status'] ?? '?') . '），开通中止');
            }
        }
        ControlDb::insert('saas_audit', [
            'platform_user_id' => $platformUserId,
            'action'           => 'approve',
            'target_type'      => 'tenant',
            'target_id'        => $tid,
            'detail'           => $mode === 'shared'
                ? '开通成功（共享档播种 + 管理员 ' . $tenant['admin_username'] . '）'
                : '开通成功（专用库 ' . DedicatedDb::name((string) $tenant['code']) . ' + 管理员 ' . $tenant['admin_username'] . '）',
            'ip'               => App::ip(),
        ]);
    }

    /**
     * 专用档：幂等 DDL（建库/建账号/授权，走 provision_db）→ 以租户自己的账号连专用库播种
     * （顺带验证账号与授权真实可用）。任何一步失败抛异常，租户留 pending，重新点「通过」即续跑。
     * 不做自动回滚：绝不代码自动 DROP，半成品由重试的幂等性收编。
     */
    private static function provisionDedicated(array $tenant, int $tid): void
    {
        $code = (string) ($tenant['code'] ?? '');
        $name = DedicatedDb::name($code);
        $user = DedicatedDb::user($code);
        $pass = DedicatedDb::pass($code);

        $pdo = DedicatedDb::provisionPdo();
        // $name/$user/$pass 均来自 DedicatedDb 的白名单派生（[a-z0-9-]），反引号内无注入面
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        foreach (['localhost', '127.0.0.1'] as $host) {
            // CREATE IF NOT EXISTS + ALTER IDENTIFIED BY：两步合一覆盖「新建」与「密码归位」，
            // 且是 MySQL 8.0 与 MariaDB 10.5 的共同子集（SET PASSWORD 的明文语义两边不一致，不用）
            $pdo->exec("CREATE USER IF NOT EXISTS `{$user}`@`{$host}` IDENTIFIED BY '{$pass}'");
            $pdo->exec("ALTER USER `{$user}`@`{$host}` IDENTIFIED BY '{$pass}'");
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$name}`.* TO `{$user}`@`{$host}`");
        }

        // 步骤 4：播种。Tenant::set 带 code，Tenant::pdo() 会用派生账号连专用库——连不上/授权不对在这里暴露
        Tenant::set(['id' => $tid, 'mode' => 'dedicated', 'db_name' => $name, 'code' => $code]);
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
    }
```

文件头部 `use` 需要 `QG\DedicatedDb`（同命名空间，无需 use——`TenantProvisioner` 在 `QG` 命名空间下，`DedicatedDb` 也在，直接引用即可，不加 use）。

- [x] **步骤 3.4：运行测试验证通过**

运行：`php83 tests/run.php 2>&1 | tail -3 && php83 tests/guard_no_raw_business_sql.php | tail -1`
预期：`66 passed, 0 failed` + `guard ok`（shared 开通用例不受影响；新的「未配置」用例转绿）。

- [x] **步骤 3.5：Commit**

```bash
git add api/src/TenantProvisioner.php tests/test_provision.php
git commit -m "feat: 专用档自动开通（幂等建库建账号 + 派生凭据播种 + db_name 回写）"
```

---

### 任务 4：`splitStatements` 公开 + `scripts/migrate_dedicated.php`

**文件：**
- 修改：`api/src/repos/ProvisionRepo.php:76`（方法可见性）
- 创建：`scripts/migrate_dedicated.php`

- [x] **步骤 4.1：`splitStatements` 改 public**

`api/src/repos/ProvisionRepo.php` 第 76 行 `private static function splitStatements` 改为 `public static function splitStatements`，并在其 docblock 末尾追加一行：

```php
     * 公开给 scripts/migrate_dedicated.php 复用——迁移文件与 schema.sql 遵守同一套切分约定。
```

- [x] **步骤 4.2：写脚本**

创建 `scripts/migrate_dedicated.php`（全文）：

```php
#!/usr/bin/env php83
<?php
// 专用档迁移扇出（最小实现；《运维基础设施》阶段 2 正式机制落地后淘汰本脚本）。
// 用法：php83 scripts/migrate_dedicated.php <migration.sql> [--dry-run]
// 库清单 = 控制面 mode='dedicated' 且已开通（db_name 非空、active/suspended），按 db_name 排序。
// 每库独立执行、独立报告；某库失败不中断后续库；任一失败最终退出码 1。
// 连接走 config 的 provision_db 段（有 qingguan_t_% 的全部权限）。
// 语句切分沿用 ProvisionRepo::splitStatements 的约定：语句字符串值内不得含 ASCII 分号。
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
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
use QG\DedicatedDb;
use QG\repos\ProvisionRepo;

$file = $argv[1] ?? '';
$dry  = in_array('--dry-run', $argv, true);
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "用法：php83 scripts/migrate_dedicated.php <migration.sql> [--dry-run]\n");
    exit(1);
}
$sql = (string) file_get_contents($file);

$rows = ControlDb::all(
    "SELECT id, code, db_name FROM saas_tenant WHERE mode = 'dedicated' AND db_name <> '' AND status IN ('active','suspended') ORDER BY db_name"
);
if (!$rows) {
    echo "没有已开通的专用库，无事可做\n";
    exit(0);
}
echo ($dry ? '[dry-run] ' : '') . '待执行库（' . count($rows) . " 个）：\n";
foreach ($rows as $r) {
    echo "  - {$r['db_name']}（{$r['code']} #{$r['id']}）\n";
}
if ($dry) {
    exit(0);
}

$pdo  = DedicatedDb::provisionPdo();
$fail = 0;
foreach ($rows as $r) {
    $db = str_replace('`', '', $r['db_name']);
    try {
        $pdo->exec("USE `{$db}`");
        foreach (ProvisionRepo::splitStatements($sql) as $stmt) {
            $pdo->exec($stmt);
        }
        echo "OK   {$r['db_name']}\n";
    } catch (Throwable $e) {
        $fail++;
        echo "FAIL {$r['db_name']}：" . $e->getMessage() . "\n";
    }
}
exit($fail ? 1 : 0);
```

- [x] **步骤 4.3：验证脚本基本行为（无专用库时应空跑）**

运行：`php83 scripts/migrate_dedicated.php db/schema.sql --dry-run && php83 scripts/migrate_dedicated.php db/schema.sql`
预期：第一行列出（当前 0 个专用库）→ 输出 `没有已开通的专用库，无事可做`，退出码 0。
再验证缺参：`php83 scripts/migrate_dedicated.php` → 用法提示，退出码 1。

- [x] **步骤 4.4：全量回归 + Commit**

运行：`php83 tests/run.php 2>&1 | tail -1`（预期 `66 passed, 0 failed`）

```bash
git add api/src/repos/ProvisionRepo.php scripts/migrate_dedicated.php
git commit -m "feat: migrate_dedicated 最小迁移扇出脚本（splitStatements 公开复用）"
```

---

### 任务 5：platform.html 展示 db_name + 审核文案分流

**文件：**
- 修改：`api/src/controllers/PlatformController.php:76-78`（SELECT 补 `t.db_name`）
- 修改：`platform.html:32-34`（隔离档列）与 `platform.html:87-89`（approve 弹窗文案）

- [x] **步骤 5.1：后端 SELECT 补字段**

`PlatformController::tenants()` 的 SELECT 列表（`t.logo_url,` 后）加一列：

```php
                    t.admin_username, t.reject_reason, t.applied_at, t.reviewed_at, t.logo_url, t.db_name,
```

- [x] **步骤 5.2：前端隔离档列**

`platform.html` 第 32-34 行：

```html
      <el-table-column label="隔离档" width="80">
        <template #default="s">{{ s.row.mode === 'dedicated' ? '独享' : '共享' }}</template>
      </el-table-column>
```

改为：

```html
      <el-table-column label="隔离档" width="150">
        <template #default="s">{{ s.row.mode === 'dedicated' ? ('独享 ' + (s.row.db_name || '')) : '共享' }}</template>
      </el-table-column>
```

- [x] **步骤 5.3：approve 弹窗文案分流**

`platform.html` 的 `approve` 方法第 88-94 行：

```js
      approve: function (row) {
        var self = this;
        QG.confirm('确定通过「' + row.name + '」？将立即播种默认数据并创建管理员 ' + row.admin_username + '。').then(function () {
```

改为：

```js
      approve: function (row) {
        var self = this;
        var tip = row.mode === 'dedicated'
          ? '确定通过「' + row.name + '」？将创建独立数据库 ' + (row.db_name || 'qingguan_t_' + row.code) + '、专用账号并播种默认数据，创建管理员 ' + row.admin_username + '。'
          : '确定通过「' + row.name + '」？将立即播种默认数据并创建管理员 ' + row.admin_username + '。';
        QG.confirm(tip).then(function () {
```

- [x] **步骤 5.4：验证 + Commit**

运行：`php83 tests/run.php 2>&1 | tail -1`（预期 `66 passed, 0 failed`）；浏览器（或 curl）确认 platform.html 已登录时列表正常渲染。

```bash
git add api/src/controllers/PlatformController.php platform.html
git commit -m "feat: 平台租户列表展示专用库名，审核通过文案按档位区分"
```

---

### 任务 6：version bump + AGENTS.md 口径更新

**文件：**
- 修改：`version.json`
- 修改：`AGENTS.md`

- [x] **步骤 6.1：AGENTS.md 三处更新**

① §1 表格「DB」行下补一行（生产列）：

```markdown
| 专用档开通 | — | config 的 `provision_db` 段指向 `qg_provision` 账号（只管 `qingguan_t_%`），缺失时专用档审核通过报「未配置」 |
```

② §6 数据库小节「多租户连接选择」段后补：

```markdown
**专用档（dedicated）开通**（2026-10-15 起已实现，规格见 `docs/superpowers/specs/2026-10-15-dedicated-provisioning-design.md`）：
审核通过即全自动 `CREATE DATABASE qingguan_t_<code>` + 建 `qg_<code>` 账号（密码 = HMAC-SHA256('db-pass:'+code, app.secret) 的 hex，**不落库**，`DedicatedDb` 派生）+ 灌 schema.sql 播种。全链路幂等，失败留 pending，重新点「通过」即续跑；系统绝不自动 DROP 库/账号，清理走手工。`app.secret` 轮换会使全部专用库账号密码失效——用新 secret 现算派生值，对每个账号跑
`ALTER USER `qg_<code>`@`<host>` IDENTIFIED BY '<新派生hex>'`。
专用库出现后，表结构迁移 = 共享库跑一遍 + `php83 scripts/migrate_dedicated.php <file>` 逐库跑（`--dry-run` 先看清单）。
```

③ §9 已知坑追加第 9 条：

```markdown
9. **`qg_provision` 是高价值凭据但权限收敛**：仅全局 CREATE/CREATE USER + `qingguan_t_%`.* 的 ALL——读不到任何现有业务数据。provision_db 未配置时专用档开通报「未配置」并留 pending，共享档不受影响。专用档租户上下文必须带 `code`（派生凭据用），手工 `Tenant::set` 造 dedicated 上下文而不带 code 会抛「缺少 code」。
```

- [x] **步骤 6.2：version bump（先提交代码再用 HEAD hash）**

```bash
git add AGENTS.md && git commit -m "docs: AGENTS.md 补专用档开通与运维口径"
BUILD=$(git rev-parse --short=10 HEAD) && TIME=$(date '+%Y-%m-%d %H:%M') && python3 - "$BUILD" "$TIME" <<'EOF'
import json,sys
d=json.load(open('version.json'))
d['build']=sys.argv[1]; d['time']=sys.argv[2]
json.dump(d,open('version.json','w'),ensure_ascii=False,indent=2)
open('version.json','a').write('\n')
EOF
git add version.json && git commit -m "chore: version bump（$BUILD）"
```

---

### 任务 7：dev 环境准备 + 端到端验收（手工，不进测试套件）

**文件：** 无代码变更；产出验收结论。

- [x] **步骤 7.1：dev MariaDB 建开通账号**

```bash
sudo -n mysql -e "
CREATE USER IF NOT EXISTS 'qg_provision'@'localhost' IDENTIFIED BY '<dev强密码>';
CREATE USER IF NOT EXISTS 'qg_provision'@'127.0.0.1' IDENTIFIED BY '<dev强密码>';
GRANT CREATE ON *.* TO 'qg_provision'@'localhost';
GRANT CREATE ON *.* TO 'qg_provision'@'127.0.0.1';
GRANT CREATE USER ON *.* TO 'qg_provision'@'localhost';
GRANT CREATE USER ON *.* TO 'qg_provision'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`qingguan_t_%\`.* TO 'qg_provision'@'localhost' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON \`qingguan_t_%\`.* TO 'qg_provision'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;"
```

- [x] **步骤 7.2：dev config.php 补 `provision_db` 段**

手工编辑 `api/config/config.php`（不入库，勿提交），按 `config.sample.php` 任务 1 的段填写 `<dev强密码>`。

- [x] **步骤 7.3：端到端真跑（含连字符 code 实测）**

1. 浏览器 `https://qingguan-dev.sflswall.com.cn/register.html` 注册：租户代码 **`e2e-dedicated`**（含连字符，规格要求实测）、套餐选「隔离版（独享）」、管理员 `e2ededadmin`、密码自定强密码。
2. `platform / Platform@12345` 登录 `admin-login.html` → 通过该租户。预期：确认弹窗显示专用库文案；通过后列表「隔离档」列显示 `独享 qingguan_t_e2e-dedicated`。
3. `sudo -n mysql` 断言（逐条执行）：
   - `SHOW CREATE DATABASE \`qingguan_t_e2e-dedicated\`` → 存在且 utf8mb4；
   - `SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='qingguan_t_e2e-dedicated'` → 21；
   - `SHOW GRANTS FOR 'qg_e2e-dedicated'@'localhost'` → 仅本库；
   - `SELECT COUNT(*) FROM qingguan.sys_user WHERE tenant_id=<该租户id>` → **0**（种子必须全在专用库）；
   - `SELECT status, db_name FROM qingguan_control.saas_tenant WHERE code='e2e-dedicated'` → `active` + 库名。
4. 用 `e2e-dedicated / e2ededadmin` 登录外壳 → 首页正常、菜单可见 → 随便进一个订单页新建一条数据 → `sudo -n mysql -e "SELECT COUNT(*) FROM qingguan_t_e2e-dedicated.biz_order"` ≥ 1 且共享库 `qingguan.biz_order` 不含该租户 id 的行。
5. 幂等重放：平台后台对该租户重复点「通过」→ 应报「已是开通状态（幂等）」或幂等成功，`saas_audit` 无 `provision_failed`。
6. 清理（手工，系统不自动 DROP）：

```bash
sudo -n mysql -e "
DROP DATABASE IF EXISTS \`qingguan_t_e2e-dedicated\`;
DROP USER 'qg_e2e-dedicated'@'localhost';
DROP USER 'qg_e2e-dedicated'@'127.0.0.1';
DELETE FROM qingguan_control.saas_audit WHERE target_type='tenant' AND target_id=<该租户id>;
DELETE FROM qingguan_control.saas_tenant WHERE code='e2e-dedicated';"
rm -rf /data/qingguan/app/uploads/t<该租户id>
```

- [x] **步骤 7.4：记录验收结论到本计划文件**（勾选此任务并在末尾追加一行执行记录，含遇到的问题）。

---


## 执行记录（2026-10-15 ~ 2026-10-16，子代理驱动 + 双重审查）

- 任务 1 `90db536`：DedicatedDb + overrideConfig + config.sample。计划原文测试自相矛盾（name('a_b') 与正则冲突），按控制者裁决改测试侧（下划线保持非法）。65 passed/1 failed（预期失败项归任务 2）。
- 任务 2 `fe9c36a`+`69f46f8`+`35516c2`+`b503b29`：pdo 派生凭据 + 4 处上下文补 code。实现者发现规格遗漏的两条登录路径（AuthController::login / Auth::login）也缺 code，一并修复；质量审查建议的「code 校验提到池判断之前」与两条测试补强全部落地。67 passed。
- 任务 3 `e8271e5`+`b139a35`+`c80b0c2`：dedicated 分支。加固：DDL 失败定位助手、PROVISION_HOSTS 常量、播种事务去重（seedInTxn）、provision_db 不可达失败路径用例。68 passed。validate_password 检查项已补进任务 8.2（`6066592`）。
- 任务 4 `026b495`：migrate_dedicated 脚本（子代理因平台 429 中断后由控制者接手核验：与任务书逐字一致 + smoke 三连）。脚本 dry-run 实测能列出租户 `qingguan_t_e2e-dedicated`。
- 任务 5 `cf22bc5`+`57db89f`+`159ce34`+`837ba5b`：列表展示库名 + 审核文案分流。计划漏列的旧测试断言翻转（db_name 由「不得出现」改「必须出现」）经控制者批准；隔离档列兜底统一、shared 文案对齐规格「共享库」、断言改自播种精确值 + pageSize=500 防分页误报。
- 任务 6 `84391cb`+`f8bd2cf`+`31cddf6`+`c786ce8`：AGENTS.md 口径（专用档开通行、§4 多租户连接段后的运维口径、§9.7 半句更新、新增 §9.9、基线计数 60→68）+ version bump + provision 内 name 派生收敛。计划误写「§6 数据库小节」的锚点实为 §4「多租户连接选择」段，已按唯一锚点落地。
- 任务 7（dev 端到端验收，控制者手工执行）：
  - 建 `qg_provision`（双 host，权限=全局 CREATE+CREATE USER + qingguan_t_%.* ALL WITH GRANT OPTION，实测 SHOW GRANTS 符合）；config.php 补 provision_db 段（php var_export 程序化插入，未回显任何密钥，备份在 /tmp/config.php.pre_provision.bak）。
  - 注册 `e2e-dedicated`（含连字符 code 实测）→ 平台通过 → **一次点击即全自动开通成功**。
  - 断言全绿：库 utf8mb4_unicode_ci；21 表；双 host 账号仅授权本库（USAGE ON *.* + ALL ON 本库）；共享库 0 行种子；控制面 active + db_name 回写；审计 register+approve（detail 含专用库名）；专用库内管理员 + 3 角色 + 25 权限 + 59 字典。
  - 运行时链路：租户登录成功（派生凭据）、/api/menu 6 个顶级菜单（菜单行仅存在于专用库，读到即证明连接正确）、POST /api/companies 写入成功且 org_company 落在专用库（dedicated=1 / shared=0）。
  - 幂等重放：重复点通过返回「该租户已是开通状态（幂等）」，无 provision_failed。
  - 清理：DROP 库/双账号 + 控制面行，复核无残留。e2e 期间 platform 会话与限流桶已清。

### 任务 8：发布 breeder + 收尾回归

**文件：** 无代码变更；生产操作。

- [x] **步骤 8.1：全量回归**

```bash
php83 tests/run.php 2>&1 | tail -1        # 预期 68 passed, 0 failed
php83 tests/guard_no_raw_business_sql.php | tail -1   # guard ok
```

- [x] **步骤 8.2：breeder 建开通账号（SSH `ssh root@breeder`，MySQL 8.0 `sudo mysql -u root`）**

```sql
CREATE USER IF NOT EXISTS 'qg_provision'@'localhost' IDENTIFIED BY '<生产强密码>';
CREATE USER IF NOT EXISTS 'qg_provision'@'127.0.0.1' IDENTIFIED BY '<生产强密码>';
GRANT CREATE ON *.* TO 'qg_provision'@'localhost';
GRANT CREATE ON *.* TO 'qg_provision'@'127.0.0.1';
GRANT CREATE USER ON *.* TO 'qg_provision'@'localhost';
GRANT CREATE USER ON *.* TO 'qg_provision'@'127.0.0.1';
GRANT ALL PRIVILEGES ON `qingguan_t_%`.* TO 'qg_provision'@'localhost' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON `qingguan_t_%`.* TO 'qg_provision'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

发布前先备份：`sudo mysqldump -u root --databases qingguan qingguan_control --single-transaction > /var/backups/qingguan/pre-dedicated-$(date +%Y%m%d).sql`。

建账号前先确认 validate_password 未启用（派生密码为 64 位小写 hex，会被 MEDIUM/STRONG 策略拒绝）：
`SELECT * FROM mysql.component WHERE component_urn LIKE '%validate_password%';`（有行 = 已启用 → 先 `UNINSTALL COMPONENT 'file://component_validate_password';` 或调整策略，再执行上面的 CREATE USER）。

- [x] **步骤 8.3：breeder config.php 补 `provision_db` 段**（手工，密码 `<生产强密码>`，勿入库）。

- [x] **步骤 8.4：rsync 发布**（与既有发布流程一致，排除 `.git` / `api/config/config.php` / `uploads/` / `api/runtime/` / `ops/apache/breeder/` / `*.swp`，不加 `--delete`，`sudo rsync` 落位 + `chown www-data` + `sudo systemctl reload apache2`）。

- [x] **步骤 8.5：生产验证**：`curl -s https://qingguan.sflswall.com.cn/version.json`（新 build）；生产控制面确认 `SELECT COUNT(*) FROM qingguan_control.saas_tenant WHERE mode='dedicated'` 为 0（无专用库，属正常起点）；`php83 scripts/migrate_dedicated.php db/schema.sql --dry-run`（在生产 `/opt/qingguan` 下）输出「没有已开通的专用库，无事可做」。

- [x] **步骤 8.6：推送 main**：`git push origin main`（执行过程中应已随时推送，此处确认同步）。

---

## 自检记录

- **规格覆盖度**：规格 §2 六步流程 → 任务 3；§3 DedicatedDb/provision_db/config.sample → 任务 1；§4 运行时连接 → 任务 2；§5 脚本 → 任务 4、前端 → 任务 5；§6 测试策略 → 任务 1/2/3 单测 + 任务 7 e2e；§7 部署与运维 → 任务 7/8 + 任务 6 文档；§8 风险（双兼容、注入面、并发守卫）在任务 3 代码与注释中落实。规格 §7 的「secret 轮换 SQL 模板」并入任务 6 AGENTS.md 口径。
- **占位符**：无「待定/TODO」；任务 7/8 是手工验收任务，步骤均为可执行命令与逐条断言。
- **类型一致性**：`DedicatedDb::name/user/pass/provisionPdo` 四个签名在任务 1/2/3/4 中一致；`overrideConfig(array)` 在任务 1 定义、任务 1/3 使用；`Tenant::set` 上下文键统一为 `id/mode/db_name/code`；测试计数 60→66（任务 1 新增 6 个用例）。
- **已知偏离规格点（已在代码注释说明）**：规格 §2 步骤 2 写的 `SET PASSWORD` 改为 `CREATE USER IF NOT EXISTS + ALTER USER IDENTIFIED BY`——MariaDB 10.5 与 MySQL 8.0 对 `SET PASSWORD` 明文语义不一致，ALTER 是两者共同子集，幂等重置语义不变。

## 执行记录

（执行时逐任务追加：日期 + commit + 结果/问题）

### 任务 8 执行记录（2026-10-16，控制者手工执行）

- 8.1 全量回归：68 passed, 0 failed + guard ok（main 合并后复跑）。
- 8.2 生产备份 `/var/backups/qingguan/pre-dedicated-20261016.sql`；validate_password 未启用（mysql.component 0 行）；`qg_provision` 双 host 创建（权限与计划 SQL 逐条一致）；**MySQL 8.0 DDL 实测**（最终审查条件 1）：用 qg_provision 对一次性库名跑 建库/建账号/ALTER 归位/GRANT 四条 DDL → 派生账号登录成功 → 手工清理无残留。全程密码不回显。
- 8.3 config.php 补 provision_db 段（php var_export 程序化插入，备份 `/var/backups/qingguan/config.php.pre_provision.bak`；账号密码用 ALTER 后的现值，临时文件即用即删）。
- 8.4 rsync 发布（排除清单同前，不加 --delete）+ chown + `systemctl reload apache2`。
- 8.5 生产验证三件套：version.json build=`f8bd2cf9f7`；未登录 /api/platform/me → 401；生产 `/opt/qingguan` 下 `php scripts/migrate_dedicated.php db/schema.sql --dry-run` → 「没有已开通的专用库，无事可做」（provision_db 连接与控制面查询均正常）。
- 8.6 main 已推送（eac63c9..d53735f，fast-forward，分支 feat/dedicated-provisioning 按惯例保留）。
- 备注：生产首个专用租户开通时建议人工盯 `saas_audit` 的 `provision_failed`（DDL 已实测，风险敞口很小）。
