# P0-B+C 账号与密码管理 实现计划

> **面向 AI 代理的工作者：** 必需子技能：使用 superpowers:subagent-driven-development（推荐）或 superpowers:executing-plans 逐任务实现此计划。步骤使用复选框（`- [ ]`）语法来跟踪进度。

**目标：** 平台一键代重置租户管理员密码（系统临时密码一次性展示 + 首登强制改密）+ 平台账号完整管理（增/停/启/重置/自改，等权 + 三条保护规则）+ 收款信息平台维护界面（`saas_setting` 替代 config.php 手工改）。

**架构：** `TempPassword` 纯函数生成临时密码（服务端只存哈希、明文一次性返回）；两张账号表各加 `must_change_password` 标记，登录响应与 me() 自动带出（`findWithRole`/`SELECT *` 全列），改密成功清零，前端强改弹窗复用「改密后重新登录」既有行为；平台设置走控制面 `saas_setting` 键值表（键白名单），`/auth/me` 的 `billing.notice` = 设置表优先、config 兜底。规格：`docs/superpowers/specs/2026-10-16-account-password-management-design.md`（已评审通过，本计划不重新决策）。

**技术栈：** PHP 8.3 原生 + PDO；Vue 3 global build（in-DOM 模板，**组件标签必须显式闭合**）；MySQL 8.0 / MariaDB 10.5 双兼容迁移。

---

## 环境铁律（执行者必读）

- 本机用 **`php83`**；DB 管理 `sudo -n mysql`（dev：数据面 `qingguan_dev`、控制面 `qingguan_control_dev`，另有专用库 `qingguan_t_woniu`）；测试直连真实 dev 库，合成数据高号段（920xxx）+ finally 清理。
- 门禁：`php83 tests/run.php`（基线 **78 passed**）+ `php83 tests/guard_no_raw_business_sql.php`（guard ok）不绿不提交；中文 Conventional Commits、只 `git add` 明确路径。
- in-DOM 模板**禁止自闭合组件标签** `<el-xxx />`。
- `api/config/config.php` 不入库不读取；本功能不再需要改它（billing_notice 数据源改走 saas_setting）。
- 工作分支：从 main 拉 `feat/account-password`。

## 文件结构

| 文件 | 动作 | 职责 |
|---|---|---|
| `api/src/TempPassword.php` | 创建 | 临时密码生成（12 位，无易混淆字符，random_int） |
| `tests/test_temp_password.php` | 创建 | 生成器单测 |
| `db/migration_20261016_platform_account.sql` | 创建 | 控制面迁移：saas_platform_user 列 + saas_setting 表 |
| `db/migration_20261016_user_force_change.sql` | 创建 | 数据面迁移：sys_user 列 |
| `db/control/schema.sql` / `db/schema.sql` | 修改 | 两表 DDL 同步（schema parity 硬闸） |
| `api/src/repos/SettingRepo.php` | 创建 | 平台设置仓储（get/all/put） |
| `api/src/repos/UserRepo.php` | 修改 | 加 `clearMustChangePassword(int $id): int` |
| `api/src/controllers/PlatformController.php` | 修改 | reset-admin-password、accounts 五接口、platformPassword、settings 两接口 |
| `api/src/controllers/AuthController.php` | 修改 | password() 清零强改标记；me() 的 notice 数据源改 saas_setting 优先 |
| `index.html` / `platform.html` | 修改 | 强改对话框（force 模式）、platform 第四视图「账号」、账单视图收款信息卡、租户行「重置密码」、页头「修改密码」 |
| `tests/test_account_http.php` | 创建 | 五组 HTTP 全链路用例 |
| `AGENTS.md` / `version.json` | 修改 | 口径 + bump |

---

### 任务 1：`TempPassword` 生成器 + 单测

**文件：** 创建 `api/src/TempPassword.php`、`tests/test_temp_password.php`

- [x] **步骤 1.1：写失败的测试** —— 创建 `tests/test_temp_password.php`（全文照抄）：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

use QG\TempPassword;

t('TempPassword：格式与字符集（无易混淆字符）', function () {
    $p = TempPassword::generate();
    assert_eq(12, strlen($p), '默认 12 位');
    assert_true((bool) preg_match('/^[A-HJ-NP-Za-hj-km-np-z2-9!@#$%^&*]{12}$/', $p), '字符集应排除 0O1lI：' . $p);
});

t('TempPassword：500 次生成无重复、长度可调、过短拒绝', function () {
    $seen = [];
    for ($i = 0; $i < 500; $i++) {
        $seen[TempPassword::generate()] = 1;
    }
    assert_eq(500, count($seen), '随机生成不应重复');
    assert_eq(16, strlen(TempPassword::generate(16)), '长度可调');
    $thrown = false;
    try { TempPassword::generate(7); } catch (Throwable $e) { $thrown = true; }
    assert_true($thrown, '过短长度应拒绝');
});
```

- [x] **步骤 1.2：运行验证失败** —— `php83 tests/run.php 2>&1 | tail -4`，预期 2 组新用例 FAIL（类不存在）。

- [x] **步骤 1.3：实现** —— 创建 `api/src/TempPassword.php`（全文照抄）：

```php
<?php
namespace QG;

/**
 * 临时密码生成（代重置 / 新增账号）。12 位，含大小写+数字+符号，剔除易混淆字符（0Oo、1lIi 形近字符族）。
 * 服务端只存哈希；明文仅出现在一次性响应里，不落库、不进审计与日志。
 */
class TempPassword
{
    private const CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&*';

    public static function generate(int $length = 12): string
    {
        if ($length < 8) {
            throw new \RuntimeException('临时密码长度至少 8 位');
        }
        $max = strlen(self::CHARSET);
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::CHARSET[random_int(0, $max - 1)];
        }
        return $out;
    }
}
```

- [x] **步骤 1.4：运行验证通过** —— `php83 tests/run.php 2>&1 | tail -1`，预期 **80 passed, 0 failed**（78+2）。

- [x] **步骤 1.5：Commit**

```bash
git add api/src/TempPassword.php tests/test_temp_password.php
git commit -m "feat: TempPassword 临时密码生成器（无易混淆字符）"
```

---

### 任务 2：两份迁移 + schema 同步 + dev 执行

**文件：** 创建 `db/migration_20261016_platform_account.sql`、`db/migration_20261016_user_force_change.sql`；修改 `db/control/schema.sql`、`db/schema.sql`

- [x] **步骤 2.1：控制面迁移** —— 创建 `db/migration_20261016_platform_account.sql`（全文照抄）：

```sql
-- 控制面：平台账号强改标记 + 平台设置表（幂等；执行库 = qingguan_control[_dev]）
SET NAMES utf8mb4;
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saas_platform_user' AND COLUMN_NAME = 'must_change_password') = 0,
  'ALTER TABLE `saas_platform_user` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''首登强制改密'' AFTER `status`',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saas_setting') = 0,
  'CREATE TABLE `saas_setting` (
     `setting_key` VARCHAR(50) NOT NULL,
     `setting_value` TEXT NOT NULL,
     `updated_by` INT UNSIGNED NULL,
     `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     PRIMARY KEY (`setting_key`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''平台设置（键值）''',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
```

- [x] **步骤 2.2：数据面迁移** —— 创建 `db/migration_20261016_user_force_change.sql`（全文照抄）：

```sql
-- 数据面：sys_user 首登强制改密标记（幂等；执行面 = 共享库手工 + 各专用库经 migrate_dedicated.php 扇出）
SET NAMES utf8mb4;
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sys_user' AND COLUMN_NAME = 'must_change_password') = 0,
  'ALTER TABLE `sys_user` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''首登强制改密'' AFTER `status`',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
```

- [x] **步骤 2.3：dev 执行（控制面 + 共享库 + 专用库扇出），各跑两遍验证幂等**

```bash
sudo -n mysql qingguan_control_dev < db/migration_20261016_platform_account.sql && sudo -n mysql qingguan_control_dev < db/migration_20261016_platform_account.sql && echo CONTROL_OK
sudo -n mysql qingguan_dev < db/migration_20261016_user_force_change.sql && sudo -n mysql qingguan_dev < db/migration_20261016_user_force_change.sql && echo SHARED_OK
php83 scripts/migrate_dedicated.php db/migration_20261016_user_force_change.sql
php83 scripts/migrate_dedicated.php db/migration_20261016_user_force_change.sql
sudo -n mysql -e "SHOW COLUMNS FROM qingguan_control_dev.saas_platform_user LIKE 'must_change_password'; SHOW COLUMNS FROM qingguan_dev.sys_user LIKE 'must_change_password'; SHOW COLUMNS FROM qingguan_t_woniu.sys_user LIKE 'must_change_password'; SHOW CREATE TABLE qingguan_control_dev.saas_setting\G" | head -25
```

预期：全部成功；三处列就位；saas_setting 结构正确。

- [x] **步骤 2.4：schema 同步** —— `db/control/schema.sql` 的 `saas_platform_user` DDL `status` 行后加：

```sql
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '首登强制改密',
```

文件末尾（`saas_bill` 之后）加：

```sql

CREATE TABLE IF NOT EXISTS `saas_setting` (
  `setting_key`   VARCHAR(50) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `updated_by`    INT UNSIGNED NULL COMMENT '最后更新的平台账号',
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台设置（键值）';
```

`db/schema.sql` 的 `sys_user` DDL `status` 行（`'1启用 0停用'` 注释行）后加：

```sql
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '首登强制改密',
```

- [x] **步骤 2.5：验证 + Commit**

```bash
php83 tests/run.php 2>&1 | tail -1    # 预期 80 passed（schema parity 转绿 = schema.sql 同步正确）
php83 tests/guard_no_raw_business_sql.php | tail -1
git add db/migration_20261016_platform_account.sql db/migration_20261016_user_force_change.sql db/control/schema.sql db/schema.sql
git commit -m "feat: 账号强改标记迁移（控制面+数据面+saas_setting）与 schema 同步"
```

---

### 任务 3：SettingRepo + 平台设置接口 + notice 数据源

**文件：** 创建 `api/src/repos/SettingRepo.php`；修改 `api/src/controllers/PlatformController.php`、`api/src/controllers/AuthController.php`

- [x] **步骤 3.1：SettingRepo** —— 创建 `api/src/repos/SettingRepo.php`（全文照抄）：

```php
<?php
namespace QG\repos;

use QG\ControlDb;

/** 平台设置（控制面 saas_setting 键值）。键以 PlatformController::SETTING_KEYS 白名单为准。 */
class SettingRepo
{
    public static function get(string $key): ?string
    {
        $v = ControlDb::val('SELECT setting_value FROM saas_setting WHERE setting_key = ?', [$key]);
        return $v === null ? null : (string) $v;
    }

    /** @param string[] $keys */
    public static function all(array $keys): array
    {
        $out = array_fill_keys($keys, null);
        if (!$keys) { return $out; }
        $ph = implode(',', array_fill(0, count($keys), '?'));
        foreach (ControlDb::all(
            'SELECT setting_key, setting_value FROM saas_setting WHERE setting_key IN (' . $ph . ')',
            $keys
        ) as $r) {
            $out[$r['setting_key']] = (string) $r['setting_value'];
        }
        return $out;
    }

    public static function put(string $key, string $value, int $platformUserId): void
    {
        ControlDb::exec(
            'INSERT INTO saas_setting (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?',
            [$key, $value, $platformUserId, $value, $platformUserId]
        );
    }
}
```

- [x] **步骤 3.2：平台设置接口** —— `PlatformController` 路由（accounts 路由在任务 5，本任务先加 settings 两条）：

```php
        $r->get('/platform/settings', [self::class, 'settingsGet']);
        $r->put('/platform/settings', [self::class, 'settingsPut']);
```

类常量与方法（`use QG\repos\SettingRepo;` 加进 use 列表）：

```php
    /** 平台设置键白名单：新增设置项先在这里登记 */
    private const SETTING_KEYS = ['billing_notice'];

    public static function settingsGet(): void
    {
        Auth::requirePlatform();
        Response::ok(SettingRepo::all(self::SETTING_KEYS));
    }

    public static function settingsPut(): void
    {
        $admin = Auth::requirePlatform();
        $updated = [];
        foreach (self::SETTING_KEYS as $key) {
            $v = Req::str($key);
            if ($v === '') { continue; }
            if (mb_strlen($v) > 2000) {
                Response::fail($key . ' 不能超过 2000 字');
            }
            SettingRepo::put($key, $v, (int) $admin['id']);
            $updated[] = $key;
        }
        if (!$updated) {
            Response::fail('没有需要更新的设置项');
        }
        self::audit((int) $admin['id'], 'setting_update', null, '更新平台设置：' . implode(',', $updated));
        Response::ok(SettingRepo::all(self::SETTING_KEYS), '设置已保存');
    }
```

- [x] **步骤 3.3：me() notice 数据源** —— `AuthController::me()` 的两处 notice 表达式改为 SettingRepo 优先（文件头加 `use QG\repos\SettingRepo;`）：

```php
        $notice = SettingRepo::get('billing_notice')
            ?: ((string) (App::config('app')['billing_notice'] ?? '') ?: '对公账户信息请联系平台获取');
```

兜底数组与 tenant 分支的 `'notice' => ...` 都用 `$notice`（只算一次）。

- [x] **步骤 3.4：验证 + Commit**

```bash
php83 tests/run.php 2>&1 | tail -1 && php83 tests/guard_no_raw_business_sql.php | tail -1   # 80 passed + guard ok
# 实呼验证（dev 站点）：平台登录 → PUT /api/platform/settings {"billing_notice":"测试收款信息"} → GET 回读 → demo 登录 /auth/me 的 billing.notice = "测试收款信息"；测后 DELETE FROM saas_setting WHERE setting_key='billing_notice' 恢复兜底
git add api/src/repos/SettingRepo.php api/src/controllers/PlatformController.php api/src/controllers/AuthController.php
git commit -m "feat: 平台设置接口（saas_setting）与 billing_notice 数据源改造"
```

---

### 任务 4：强改标记链路（被动侧）

**文件：** 修改 `api/src/repos/UserRepo.php`、`api/src/controllers/AuthController.php`

- [x] **步骤 4.1：UserRepo** —— `setPassword` 后加：

```php
    public static function clearMustChangePassword(int $id): int
    {
        return Db::exec('UPDATE sys_user SET must_change_password = 0 WHERE id = ?', [$id]);
    }
```

- [x] **步骤 4.2：password() 清零** —— `AuthController::password` 的 `UserRepo::setPassword(...)` 行后加：

```php
        UserRepo::clearMustChangePassword((int) $u['id']);
```

- [x] **步骤 4.3：验证自动带出** —— 说明性验证（无代码改动）：login 响应与 `/auth/me` 的 `$u` 来自 `UserRepo::findWithRole`（`SELECT u.*`）与 me() 的整包返回，`must_change_password` 列迁移后自动随行。用 dev 实呼确认：任意租户管理员登录 → `/auth/me` 含 `"must_change_password":false`。

```bash
php83 tests/run.php 2>&1 | tail -1 && php83 tests/guard_no_raw_business_sql.php | tail -1   # 80 passed + guard ok
git add api/src/repos/UserRepo.php api/src/controllers/AuthController.php
git commit -m "feat: 租户改密清零强改标记（登录与 /auth/me 自动带出）"
```

---

### 任务 5：平台代重置 + 平台账号接口

**文件：** 修改 `api/src/controllers/PlatformController.php`

- [ ] **步骤 5.1：路由** —— 现有路由后追加（use 列表加 `QG\Db;`、`QG\Tenant;`、`QG\TempPassword;`）：

```php
        $r->post('/platform/tenants/{id}/reset-admin-password', [self::class, 'resetAdminPassword']);
        $r->get('/platform/accounts', [self::class, 'accounts']);
        $r->post('/platform/accounts', [self::class, 'accountCreate']);
        $r->post('/platform/accounts/{id}/disable', [self::class, 'accountDisable']);
        $r->post('/platform/accounts/{id}/enable', [self::class, 'accountEnable']);
        $r->post('/platform/accounts/{id}/reset-password', [self::class, 'accountReset']);
        $r->post('/platform/password', [self::class, 'platformPassword']);
```

- [ ] **步骤 5.2：七个方法** —— `voidBill()` 后追加（全文照抄）：

```php
    /** 平台代重置租户管理员密码：临时密码一次性返回，管理员首登强制改密。 */
    public static function resetAdminPassword(array $p): void
    {
        $admin = Auth::requirePlatform();
        $t = ControlDb::one('SELECT * FROM saas_tenant WHERE id = ? LIMIT 1', [(int) ($p['id'] ?? 0)]);
        if (!$t || $t['status'] !== 'active') {
            Response::fail('仅已开通的租户可重置管理员密码');
        }
        if ($t['admin_username'] === '') {
            Response::fail('该租户没有管理员账号');
        }
        $temp = TempPassword::generate();
        Tenant::set(['id' => (int) $t['id'], 'mode' => $t['mode'], 'db_name' => $t['db_name'], 'code' => $t['code']]);
        try {
            $n = Db::exec(
                'UPDATE sys_user SET password_hash = ?, must_change_password = 1, status = 1 WHERE tenant_id = ? AND username = ?',
                [password_hash($temp, PASSWORD_DEFAULT), (int) $t['id'], $t['admin_username']]
            );
        } finally {
            Tenant::set(null);
        }
        if ($n === 0) {
            Response::fail('租户管理员账号不存在');
        }
        self::audit((int) $admin['id'], 'admin_reset', (int) $t['id'],
            '重置管理员账号 ' . $t['admin_username'] . ' 的密码（临时密码已线下交付）');
        Response::ok(['temp_password' => $temp], '已重置，请线下告知客户，其首登须修改密码');
    }

    public static function accounts(): void
    {
        Auth::requirePlatform();
        Response::ok(ControlDb::all(
            'SELECT id, username, real_name, email, status, must_change_password, last_login_at, created_at
               FROM saas_platform_user ORDER BY id'
        ));
    }

    public static function accountCreate(): void
    {
        $admin = Auth::requirePlatform();
        $username = Req::str('username');
        $realName = Req::require_('real_name', '姓名');
        $email = trim(Req::str('email'));
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            Response::fail('平台账号需为 3-30 位字母、数字或下划线');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::fail('邮箱格式不正确');
        }
        if (ControlDb::val('SELECT COUNT(*) FROM saas_platform_user WHERE username = ?', [$username])) {
            Response::fail('该平台账号已存在');
        }
        $temp = TempPassword::generate();
        try {
            ControlDb::insert('saas_platform_user', [
                'username' => $username, 'real_name' => $realName, 'email' => $email,
                'password_hash' => password_hash($temp, PASSWORD_DEFAULT),
                'must_change_password' => 1, 'status' => 1,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') { Response::fail('该平台账号已存在'); }
            throw $e;
        }
        self::audit((int) $admin['id'], 'account_create', null, '新增平台账号 ' . $username);
        Response::ok(['temp_password' => $temp], '已创建，请线下告知该账号，其首登须修改密码');
    }

    public static function accountDisable(array $p): void
    {
        $admin = Auth::requirePlatform();
        $id = (int) ($p['id'] ?? 0);
        if ($id === (int) $admin['id']) {
            Response::fail('不能停用自己的账号');
        }
        $target = ControlDb::one('SELECT id, username, status FROM saas_platform_user WHERE id = ? LIMIT 1', [$id]);
        if (!$target) {
            Response::fail('平台账号不存在');
        }
        ControlDb::begin();
        try {
            $n = ControlDb::exec('UPDATE saas_platform_user SET status = 0 WHERE id = ? AND status = 1', [$id]);
            if ($n === 0) {
                throw new \RuntimeException('账号状态已变化，请刷新后重试');
            }
            if ((int) ControlDb::val('SELECT COUNT(*) FROM saas_platform_user WHERE status = 1') === 0) {
                throw new \RuntimeException('至少保留一个启用中的平台账号');
            }
            ControlDb::commit();
        } catch (\Throwable $e) {
            ControlDb::rollback();
            Response::fail($e->getMessage());
        }
        self::audit((int) $admin['id'], 'account_disable', null, '停用平台账号 ' . $target['username']);
        Response::ok(null, '已停用');
    }

    public static function accountEnable(array $p): void
    {
        $admin = Auth::requirePlatform();
        $n = ControlDb::exec('UPDATE saas_platform_user SET status = 1 WHERE id = ? AND status = 0', [(int) ($p['id'] ?? 0)]);
        if ($n === 0) {
            Response::fail('平台账号不存在或已启用');
        }
        self::audit((int) $admin['id'], 'account_enable', null, '启用平台账号 #' . ($p['id'] ?? 0));
        Response::ok(null, '已启用');
    }

    public static function accountReset(array $p): void
    {
        $admin = Auth::requirePlatform();
        $id = (int) ($p['id'] ?? 0);
        $target = ControlDb::one('SELECT id, username FROM saas_platform_user WHERE id = ? LIMIT 1', [$id]);
        if (!$target) {
            Response::fail('平台账号不存在');
        }
        $temp = TempPassword::generate();
        ControlDb::exec(
            'UPDATE saas_platform_user SET password_hash = ?, must_change_password = 1, status = 1 WHERE id = ?',
            [password_hash($temp, PASSWORD_DEFAULT), $id]
        );
        self::audit((int) $admin['id'], 'account_reset', null, '重置平台账号 ' . $target['username'] . ' 的密码（临时密码已线下交付）');
        Response::ok(['temp_password' => $temp], '已重置，请线下告知该账号，其首登须修改密码');
    }

    public static function platformPassword(): void
    {
        $admin = Auth::requirePlatform();
        $old = Req::str('old_password');
        $new = Req::str('new_password');
        if (strlen($new) < 10) {
            Response::fail('新密码至少 10 位');
        }
        $row = ControlDb::one('SELECT password_hash FROM saas_platform_user WHERE id = ? LIMIT 1', [(int) $admin['id']]);
        if (!$row || !password_verify($old, $row['password_hash'])) {
            Response::fail('原密码不正确');
        }
        ControlDb::exec(
            'UPDATE saas_platform_user SET password_hash = ?, must_change_password = 0 WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), (int) $admin['id']]
        );
        self::audit((int) $admin['id'], 'account_password', null, '平台账号 ' . $admin['username'] . ' 修改了密码');
        Response::ok(null, '密码已修改');
    }
```

- [ ] **步骤 5.3：验证 + Commit**

```bash
php83 tests/run.php 2>&1 | tail -1 && php83 tests/guard_no_raw_business_sql.php | tail -1   # 80 passed + guard ok
php83 -l api/src/controllers/PlatformController.php
git add api/src/controllers/PlatformController.php
git commit -m "feat: 平台代重置租户管理员密码与平台账号管理接口"
```

---

### 任务 6：前端（强改对话框 / 第四视图「账号」/ 收款信息卡 / 重置按钮）

**文件：** 修改 `index.html`、`platform.html`（先各读全文）

- [ ] **步骤 6.1：index.html 强改对话框** —— 三处：
  ① data 加 `pwdForce: false,`（`pwdVisible` 旁）；
  ② created 的 `/auth/me` 成功回调（`self.user = u;` 之后）加：

```js
        if (u && u.must_change_password) {
          self.pwdForce = true;
          self.pwdForm = { old_password: '', new_password: '', confirm: '' };
          self.pwdVisible = true;
        }
```

  ③ 对话框标签改为：

```html
  <el-dialog v-model="pwdVisible" :title="pwdForce ? '首次登录请修改密码' : '修改密码'" width="420px"
    :close-on-click-modal="!pwdForce" :close-on-press-escape="!pwdForce" :show-close="!pwdForce">
```

footer 的取消按钮加 `v-if="!pwdForce"`；savePwd 成功回调不变（toast 后跳登录页，用新密码重登即正常进入）。

- [ ] **步骤 6.2：platform.html 强改 + 修改密码入口** ——
  ① data 加 `pwdVisible: false, pwdForce: false, saving: false, pwdForm: { old_password: '', new_password: '', confirm: '' }`；
  ② created 的 `/platform/me` 成功回调改为：

```js
      QG.get('/platform/me').then(function (u) {
        if (u && u.must_change_password) {
          self.pwdForce = true;
          self.pwdForm = { old_password: '', new_password: '', confirm: '' };
          self.pwdVisible = true;
        }
        self.load();
      }).catch(function (e) {
```

  ③ 页头「退出」按钮前加：`<el-button @click="openPwd">修改密码</el-button>`；方法加：

```js
      openPwd: function () {
        this.pwdForm = { old_password: '', new_password: '', confirm: '' };
        this.pwdForce = false;
        this.pwdVisible = true;
      },
      savePwd: function () {
        var f = this.pwdForm;
        if (!f.old_password || !f.new_password) { return QG.warn('请填写完整'); }
        if (f.new_password.length < 10) { return QG.warn('新密码至少 10 位'); }
        if (f.new_password !== f.confirm) { return QG.warn('两次输入的新密码不一致'); }
        var self = this;
        self.saving = true;
        QG.post('/platform/password', { old_password: f.old_password, new_password: f.new_password })
          .then(function () { QG.toast('密码已修改，请重新登录'); setTimeout(function () { location.href = '/admin-login.html'; }, 800); })
          .catch(function (e) { QG.err(e); })
          .finally(function () { self.saving = false; });
      },
```

  ④ 模板末尾（最后一个卡片/dialog 之后）加对话框：

```html
  <el-dialog v-model="pwdVisible" :title="pwdForce ? '首次登录请修改密码' : '修改密码'" width="420px"
    :close-on-click-modal="!pwdForce" :close-on-press-escape="!pwdForce" :show-close="!pwdForce">
    <el-form label-width="90px">
      <el-form-item label="原密码"><el-input v-model="pwdForm.old_password" type="password" show-password></el-input></el-form-item>
      <el-form-item label="新密码"><el-input v-model="pwdForm.new_password" type="password" show-password placeholder="至少 10 位"></el-input></el-form-item>
      <el-form-item label="确认新密码"><el-input v-model="pwdForm.confirm" type="password" show-password></el-input></el-form-item>
    </el-form>
    <template #footer>
      <el-button v-if="!pwdForce" @click="pwdVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="savePwd">确定</el-button>
    </template>
  </el-dialog>
```

- [ ] **步骤 6.3：第四视图「账号」** ——
  ① 视图组加 `<el-radio-button value="accounts">账号</el-radio-button>`；
  ② 账单卡片 `v-else-if="view === 'bills'"` 之后加：

```html
  <div class="card" v-else-if="view === 'accounts'" v-loading="accLoading">
    <div style="margin-bottom:12px">
      <el-button type="primary" @click="openAccount">新增账号</el-button>
    </div>
    <el-table :data="accRows">
      <el-table-column prop="id" label="ID" width="70"></el-table-column>
      <el-table-column prop="username" label="账号" width="130"></el-table-column>
      <el-table-column prop="real_name" label="姓名" width="120"></el-table-column>
      <el-table-column prop="email" label="邮箱" min-width="160"></el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="s">
          <el-tag :type="s.row.status === 1 ? 'success' : 'info'">{{ s.row.status === 1 ? '启用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="last_login_at" label="最近登录" width="160">
        <template #default="s">{{ s.row.last_login_at || '—' }}</template>
      </el-table-column>
      <el-table-column prop="created_at" label="创建时间" width="160"></el-table-column>
      <el-table-column label="操作" width="230" fixed="right">
        <template #default="s">
          <template v-if="s.row.status === 1">
            <el-button size="small" type="warning" plain @click="accountDisable(s.row)">停用</el-button>
          </template>
          <template v-else>
            <el-button size="small" type="success" plain @click="accountEnable(s.row)">启用</el-button>
          </template>
          <el-button size="small" type="primary" plain @click="accountReset(s.row)">重置密码</el-button>
        </template>
      </el-table-column>
    </el-table>
  </div>
  <el-dialog v-model="accVisible" title="新增平台账号" width="420px">
    <el-form label-width="90px">
      <el-form-item label="账号" required><el-input v-model="accForm.username" placeholder="3-30 位字母、数字或下划线"></el-input></el-form-item>
      <el-form-item label="姓名" required><el-input v-model="accForm.real_name"></el-input></el-form-item>
      <el-form-item label="邮箱"><el-input v-model="accForm.email"></el-input></el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="accVisible = false">取消</el-button>
      <el-button type="primary" :loading="accSaving" @click="createAccount">创建</el-button>
    </template>
  </el-dialog>
```

  ③ data 加 `accRows: [], accLoading: false, accVisible: false, accSaving: false, accForm: { username: '', real_name: '', email: '' }`；`onView` 加 `if (this.view === 'accounts') { this.loadAccounts(); }`；methods 加：

```js
      loadAccounts: function () {
        var self = this;
        self.accLoading = true;
        QG.get('/platform/accounts').then(function (d) { self.accRows = d || []; })
          .catch(function (e) { QG.err(e); })
          .finally(function () { self.accLoading = false; });
      },
      openAccount: function () {
        this.accForm = { username: '', real_name: '', email: '' };
        this.accVisible = true;
      },
      createAccount: function () {
        var self = this;
        var f = self.accForm;
        if (!f.username || !f.real_name) { return QG.warn('请填写账号和姓名'); }
        self.accSaving = true;
        QG.post('/platform/accounts', f)
          .then(function (d) {
            self.accVisible = false;
            self.showTempPassword(d.temp_password, f.username);
            self.loadAccounts();
          })
          .catch(function (e) { QG.err(e); })
          .finally(function () { self.accSaving = false; });
      },
      accountDisable: function (row) {
        var self = this;
        QG.confirm('确定停用「' + row.username + '」？其将无法登录平台。').then(function () {
          QG.post('/platform/accounts/' + row.id + '/disable', {})
            .then(function () { QG.toast('已停用'); self.loadAccounts(); })
            .catch(function (e) { QG.err(e); });
        }).catch(function () {});
      },
      accountEnable: function (row) {
        var self = this;
        QG.post('/platform/accounts/' + row.id + '/enable', {})
          .then(function () { QG.toast('已启用'); self.loadAccounts(); })
          .catch(function (e) { QG.err(e); });
      },
      accountReset: function (row) {
        var self = this;
        QG.confirm('将为「' + row.username + '」生成新的临时密码，旧密码立即失效，其首登须修改。继续？').then(function () {
          QG.post('/platform/accounts/' + row.id + '/reset-password', {})
            .then(function (d) { self.showTempPassword(d.temp_password, row.username); })
            .catch(function (e) { QG.err(e); });
        }).catch(function () {});
      },
      showTempPassword: function (temp, username) {
        ElementPlus.ElMessageBox.alert(
          '<div style="font-size:20px;font-family:monospace;letter-spacing:2px;text-align:center">' + temp + '</div>'
          + '<div style="color:#909399;font-size:12px;text-align:center">仅此一次展示，请复制后通过线下渠道告知「' + username + '」</div>',
          '临时密码',
          { dangerouslyUseHTMLString: true, confirmButtonText: '我已线下交付' });
      },
```

- [ ] **步骤 6.4：收款信息维护卡（账单视图）** —— 账单卡片的状态筛选条之前加：

```html
    <div style="margin-bottom:12px">
      <div style="margin-bottom:6px;color:#909399">收款信息（租户「账单中心」展示，保存后即时生效）</div>
      <el-input type="textarea" v-model="noticeDraft" :rows="3" placeholder="对公账户、开户行、账号、汇款备注要求"></el-input>
      <el-button size="small" type="primary" style="margin-top:6px" :loading="noticeSaving" @click="saveNotice">保存收款信息</el-button>
    </div>
```

data 加 `noticeDraft: '', noticeSaving: false`；`onView` 的 bills 分支加 `this.loadNotice();`；methods 加：

```js
      loadNotice: function () {
        var self = this;
        QG.get('/platform/settings').then(function (d) { self.noticeDraft = (d && d.billing_notice) || ''; })
          .catch(function () {});
      },
      saveNotice: function () {
        var self = this;
        if (!(self.noticeDraft || '').trim()) { return QG.warn('收款信息不能为空'); }
        self.noticeSaving = true;
        QG.put('/platform/settings', { billing_notice: self.noticeDraft })
          .then(function () { QG.toast('收款信息已更新，租户账单中心即时生效'); })
          .catch(function (e) { QG.err(e); })
          .finally(function () { self.noticeSaving = false; });
      },
```

- [ ] **步骤 6.5：租户行「重置密码」按钮** —— 租户表操作列（active 分支的「停用」按钮后）加：

```html
            <el-button size="small" plain @click="resetPwd(s.row)">重置密码</el-button>
```

操作列 `width="210"` 调到 `width="300"`；methods 加（`showTempPassword` 复用 6.3 的）：

```js
      resetPwd: function (row) {
        var self = this;
        QG.confirm('将为「' + row.code + '」的管理员 ' + row.admin_username + ' 生成新的临时密码，旧密码立即失效，客户首登须修改。继续？').then(function () {
          QG.post('/platform/tenants/' + row.id + '/reset-admin-password', {})
            .then(function (d) { self.showTempPassword(d.temp_password, row.admin_username); self.load(); })
            .catch(function (e) { QG.err(e); });
        }).catch(function () {});
      },
```

- [ ] **步骤 6.6：验证 + Commit**

```bash
python3 - <<'EOF'
import re,subprocess,tempfile
for f in ['index.html','platform.html']:
    html=open(f).read()
    for i,s in enumerate(re.findall(r'<script>(.*?)</script>', html, re.S)):
        t=tempfile.NamedTemporaryFile('w',suffix='.js',delete=False); t.write(s); t.close()
        r=subprocess.run(['node','--check',t.name]); assert r.returncode==0, f+':'+str(i)
print('JS_OK')
EOF
grep -nE '<el-[a-z-]+[^>]*/>' index.html platform.html || echo NO_SELFCLOSING
php83 tests/run.php 2>&1 | tail -1 && php83 tests/guard_no_raw_business_sql.php | tail -1   # 80 passed + guard ok
git add index.html platform.html
git commit -m "feat: 强改密码对话框、平台账号视图、收款信息维护卡与租户管理员重置入口"
```

---

### 任务 7：HTTP 测试 + AGENTS.md + version bump

**文件：** 创建 `tests/test_account_http.php`；修改 `AGENTS.md`、`version.json`

- [ ] **步骤 7.1：五组用例** —— 创建 `tests/test_account_http.php`（骨架照 test_billing_http：platform jar 静态缓存只登录一次；合成租户 920xxx 段 + 数据面播种管理员照 test_provision 惯例；finally 清理含 sys_user 的 must_change 清理无需特殊处理，删除行即可）：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

// 1. t('代重置：全链路') —— 造 active 租户+管理员（admin 旧密码 Aold12345）→ 平台 reset-admin-password
//    → 断言响应含 temp_password（12 位）；旧密码登录 fail；临时密码登录 code 0 且响应 must_change_password=true
//    → POST /api/auth/password {old: 临时, new: Bnew12345} → code 0；临时密码再登录 fail、新密码登录成功
//    → /auth/me 断言 must_change_password=false
// 2. t('平台账号：新增/停用/启用/重置') —— platform_jar → POST /api/platform/accounts {username:'accpt<n>', real_name:'冒烟'}
//    → 断言 temp_password；该账号用临时密码登录 /api/platform/login → must_change_password=true
//    → disable → 用旧临时密码登录 /api/platform/login 失败（status=0）；enable → 重置 → 新 temp 登录成功
// 3. t('平台账号：保护规则') —— 停自己（jar 对应账号 id，从 /platform/me 取）→ fail「不能停用自己的账号」
//    → 启用中的账号只剩 1 个时停它 → fail「至少保留一个启用中的平台账号」（注意先 enable 测试账号，使平台账号≥2 再构造：停用其他全部账号后只剩自己→停自己已被拦；
//      更简单的构造：新建第二个启用账号 → 先停自己会拦（自己规则）→ 用另一账号？——采用直插法：
//      SQL 把 platform 账号置 status=0 之外再留唯一启用者，断言停它被拦，测后恢复）
// 4. t('平台改密：旧密码错 fail / 成功清零') —— POST /api/platform/password {old:'wrong...', new:'Bnew12345'} → fail「原密码不正确」
//    → 正确旧密码（重置流程产出的临时密码）→ code 0 → /platform/me 断言 must_change_password=false
// 5. t('收款信息：白名单与回读') —— PUT /api/platform/settings {hacker_key:'x'} → fail「没有需要更新的设置项」
//    → PUT {billing_notice:'冒烟收款信息'} → GET 回读一致 → demo /auth/me 的 billing.notice = '冒烟收款信息' → 测后 DELETE saas_setting 行恢复兜底
```

实现注意：平台登录响应 data 含 `must_change_password`（login 自动带出）；`/platform/me` 同样；用例间共享 platform jar（静态缓存）避免限流；所有平台账号造数走 API（顺带测 create），清理只删测试新建的账号（`DELETE FROM saas_platform_user WHERE username LIKE 'accpt%'`），**platform 账号（id=1）绝对不动**（步骤 3 的直插法测试后必须恢复 status=1）。

- [ ] **步骤 7.2：回归 + AGENTS.md** —— `php83 tests/run.php 2>&1 | tail -1`（记录最终数字，预期 80+5=85 左右）；AGENTS.md：三处基线计数（73/78 → 实际值）、§3 代码地图加 `TempPassword.php` 与 `SettingRepo`、§6 补「账号与密码」段（临时密码一次性展示、must_change_password 双表标记、保护规则三条）、§9 坑补「强改弹窗为锁死风险单点，改其交互必须过 test_account_http」。

- [ ] **步骤 7.3：Commit + version bump**

```bash
git add tests/test_account_http.php AGENTS.md && git commit -m "test: 账号与密码管理全链路用例 + AGENTS.md 口径"
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

### 任务 8：手工验收 + 发布 + 合并（控制者执行）

- [ ] **步骤 8.0：最终整体审查**（子代理，`git diff main..HEAD`）——通过后合并。
- [ ] **步骤 8.1：合并 main** —— `git checkout main && git merge --ff-only feat/account-password && git push origin main`。
- [ ] **步骤 8.2：发布**（顺序：备份 → 控制面迁移×2 → 数据面共享库迁移×2 → **专用库扇出 dry-run+实跑** → rsync+reload → 验证）：

```bash
ssh breeder.sflswall.com.cn 'sudo sh -c "mysqldump -u root --databases qingguan qingguan_control --single-transaction > /var/backups/qingguan/pre-account-$(date +%Y%m%d).sql"'
scp db/migration_20261016_platform_account.sql db/migration_20261016_user_force_change.sql breeder.sflswall.com.cn:/tmp/
ssh breeder.sflswall.com.cn 'sudo mysql -u root qingguan_control < /tmp/migration_20261016_platform_account.sql && sudo mysql -u root qingguan_control < /tmp/migration_20261016_platform_account.sql && sudo mysql -u root qingguan < /tmp/migration_20261016_user_force_change.sql && sudo mysql -u root qingguan < /tmp/migration_20261016_user_force_change.sql && echo MIG_OK'
# rsync 发布（既有排除清单）后：
ssh breeder.sflswall.com.cn 'cd /opt/qingguan && php scripts/migrate_dedicated.php db/migration_20261016_user_force_change.sql --dry-run && php scripts/migrate_dedicated.php db/migration_20261016_user_force_change.sql'
```

- [ ] **步骤 8.3：生产验证** —— version.json 新 build；未登录 /api/platform/accounts → 401；平台登录 → /api/platform/accounts code 0；/api/platform/settings GET code 0；**在「账单」视图收款信息卡填入真实对公账户**；租户登录不受影响。
- [ ] **步骤 8.4：手工验收** —— 浏览器过两处强改对话框与临时密码一次性展示；记录到本计划末尾执行记录并提交推送。

---

## 自检记录

- **规格覆盖度**：§2 两份迁移+schema → 任务 2；§2.3 saas_setting → 任务 2/3；§3 TempPassword → 任务 1；§4 代重置+强改链路 → 任务 4/5/6；§5 平台账号六接口+强改 → 任务 5/6；§5.3 收款信息卡 → 任务 6；§6 测试 → 任务 1/7；§7 风险（强改锁死、扇出、零明文、兼容）→ 任务 6/7/8。
- **占位符**：无（任务 7 用例骨架给出断言目标与构造方法，测试代码由实现者按惯例补全——与既有两份计划同款做法）。
- **类型一致性**：`TempPassword::generate(int $length = 12): string`；`SettingRepo::get/all/put`；`UserRepo::clearMustChangePassword(int): int`；`SETTING_KEYS` 常量在任务 3 定义、任务 3/6 消费；审计动作 `admin_reset/account_create/account_disable/account_enable/account_reset/account_password/setting_update` 与规格 §5.1 一致。
- **计数**：基线 80 = 78+2（任务 1）；最终以实跑为准并同步 AGENTS.md（任务 7.2）。

## 执行记录

### 2026-10-16 · 任务 1–4

- **任务 1**（`79ce45f`，审查通过）：`TempPassword` + 单测。实现者发现计划自相矛盾（计划 CHARSET 含 `i`，而测试正则排除 `0Oo/1lIi` 形近字符族），按「不改测试」纪律把 CHARSET 收敛为 63 字符；控制者裁决接受，并修正计划/规格口径（`0c264a1`）。独立审查：Monte Carlo 2000 次 0 不匹配、63 字符全覆盖、80 passed。
- **任务 2**（`b2d2048`，审查通过）：两份迁移 + 两个 schema.sql 同步；dev 实跑控制面×2、共享库×2、专用库×2 全绿，`saas_setting`/两处 `must_change_password` 结构核对一致。**发现的既有缺陷**：扇出脚本幂等重跑必 FAIL（`scripts/migrate_dedicated.php` 用 `PDO::exec()` 执行会产生结果集的语句，下一句报 2014）→ 已修（`431b9e9`：`query()` + 取尽 `nextRowset()`），修复后专用库连续两遍 OK。
- **任务 3**（`893c229`，审查通过）+ 改进（`018e04a`）：`SettingRepo` + `GET/PUT /platform/settings` + `me()` notice 数据源。审查建议两条已落地：① 改为**先全量校验后写入**，避免多键白名单未来出现半更新；② `PUT` 显式空串现在表示**清空**（删 `saas_setting` 行、回落 config/默认文案），平台界面因此可把收款信息恢复为部署默认值。实呼验证：往返读写、白名单外键拒绝、2001 字拒绝、清空后 `me()` 回落兜底、审计 `平台设置：清空 billing_notice`。
- **任务 4**（`bbdbdcb`）：`UserRepo::clearMustChangePassword`（带 `tenant_id` 隔离，比计划原文更严）+ `AuthController::password` 清零。实呼确认 `must_change_password` 已随租户登录响应、`/auth/me`、`/platform/me` 自动带出（值为 int `0/1`，前端按真值判断即可）。
- 门禁：各提交前后 `php83 tests/run.php` = **80 passed, 0 failed**、`guard ok`。
- 备注：dev 站点共享限流桶（platform-login 10 次/10 分钟）在背靠背连跑测试时会打出 HTTP 级用例的偶发失败，非回归。
