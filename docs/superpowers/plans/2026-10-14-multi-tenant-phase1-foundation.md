# 多租户 SaaS 化 · 一期地基 实现计划

> **面向 AI 代理的工作者：** 必需子技能：使用 superpowers:subagent-driven-development（推荐）或 superpowers:executing-plans 逐任务实现此计划。步骤使用复选框（`- [ ]`）语法来跟踪进度。

**目标：** 把单租户单机版清关系统改造成「控制面 / 数据面分离」的多租户系统，一期先跑通**共享档**（共享库 + `tenant_id` 行级隔离）与**租户代码登录**，并把现有数据平滑迁移成租户 #1。

**架构：** 新增控制面库（`qingguan_control`）存放租户/套餐/平台管理员/审计；业务数据仍在数据面库，所有表加 `tenant_id`；后端引入 `Tenant` 运行期上下文与 `Db` 连接路由；把 171 处散落在 controller 的手写 SQL 收拢进 `api/src/repos/*` 仓储层，仓储方法强制带 `tenant_id`，并用守卫脚本禁止仓储外出现业务表 SQL。

**技术栈：** PHP 8.3（原生，无框架/无 composer）+ MySQL 8.0（utf8mb4）+ 静态 Vue3/Element Plus 前端（无构建）。测试用自带的极简断言器（`php tests/run.php`），不引入 PHPUnit。

---

## 范围与分期说明

本文件是**一期·地基**计划（可独立交付、可独立测试）。一期的另一半「自助开通与品牌」——注册页 + 平台审核后台 + 开通播种 + Logo + 附件租户命名空间——放在 `2026-10-14-multi-tenant-phase1-onboarding.md`，在本地基跑通后再写、再执行。

一期地基交付后应达到：任意两个租户用「租户代码 + 账号」登录后，彼此的订单/抬头/财务/附件完全不可见；现有单租户数据仍是租户 #1 且功能不退化；没有任何业务 SQL 绕过租户过滤（守卫脚本保证）。

专用档（每租户独立库）的自动开通属于二期，本计划只预留 `Tenant` 的 `mode` 分支，不实现建库。

---

## 文件结构

**新建（数据面/运行时）**
- `api/src/Tenant.php` —— 租户运行期上下文（单例）：`id / mode / db_name / pdo()`。
- `api/src/ControlDb.php` —— 控制面 PDO（独立连接）。
- `api/src/repos/CompanyRepo.php` —— `org_company`
- `api/src/repos/PlanRepo.php` —— `biz_plan`
- `api/src/repos/OrderRepo.php` —— `biz_order` + 8 张订单子表（`biz_order_container/hscode/address/file/isf/inspect/pickup/log`）
- `api/src/repos/FinanceRepo.php` —— `fin_account/fin_recharge/fin_payable`
- `api/src/repos/UserRepo.php` —— `sys_user`
- `api/src/repos/RoleRepo.php` —— `sys_role` + `sys_role_permission`
- `api/src/repos/PermissionRepo.php` —— `sys_permission`
- `api/src/repos/DictRepo.php` —— `sys_dict`
- `api/src/repos/ConfigRepo.php` —— `sys_config`
- `api/src/repos/LogRepo.php` —— `sys_log` + `biz_order_log`
- `api/src/controllers/TenantAuthController.php` —— 新登录（租户解析）；沿用 `AuthController` 的 me/password/menu/logout 改名而来

**新建（控制面 & 迁移 & 测试）**
- `db/control/schema.sql` —— 控制面 4 张表。
- `db/migration_20261015_multitenant.sql` —— 数据面：所有表加 `tenant_id`、改唯一键、补索引、回填租户 #1。
- `tests/lib.php`、`tests/run.php` —— 极简断言器与 runner。
- `tests/bootstrap.php` —— 载入 `App::config()` 与测试库配置。
- `tests/test_tenant_context.php`、`tests/test_isolation.php`、`tests/test_guard.php`
- `tests/guard_no_raw_business_sql.php` —— 守卫脚本（也被 runner 调用）。

**修改**
- `api/src/Db.php` —— `pdo()` 委托给 `Tenant::pdo()`。
- `api/src/App.php` —— `ip()` 不变；新增 `controlDbConfig()` 读取控制面连接。
- `api/config/config.sample.php`、`api/config/config.php` —— 增加 `control_db` 段。
- `api/src/Auth.php` —— 会话解析支持 `scope`（tenant/platform）与 `tid`；`super` → `tenant_admin`。
- `api/src/controllers/AuthController.php` —— 登录改为「租户代码 + 用户名 + 密码」；`menu()` 的 `super` 判定改名；其余（me/password/logout）保留。
- 7 个 controller —— 删除对 `Db::` 的直接调用，改为调用仓储；`$created_by` 等由 `Tenant`/`Auth` 注入。
- `login.html` —— 增加「租户代码」输入项。
- `api/src/controllers/SystemController.php` —— `super` 判定改名。

**明确不改**
- `assets/vendor/*`、`pages/*` 的业务逻辑、`db/schema.sql`（保持为「全新部署用的单租户基线」历史文件，不改；多租户结构由 migration 表达）。

---

## 阶段 0：准备

### 任务 0：切分支 + 建测试脚手架

**文件：**
- 创建：`tests/lib.php`、`tests/run.php`

- [ ] **步骤 1：切分支**

```bash
cd /data/qingguan/app
git checkout -b feat/multi-tenant-foundation
```

- [ ] **步骤 2：写极简断言器**

`tests/lib.php`：

```php
<?php
// 极简断言库：保持"零依赖"，不引入 composer/PHPUnit。
$GLOBALS['__tests'] = [];

function t(string $name, callable $fn): void
{
    $GLOBALS['__tests'][$name] = $fn;
}

function assert_true($cond, string $msg = ''): void
{
    if (!$cond) { throw new RuntimeException('assert_true 失败：' . $msg); }
}

function assert_eq($a, $b, string $msg = ''): void
{
    if ($a !== $b) {
        throw new RuntimeException('assert_eq 失败：' . $msg
            . '（' . var_export($a, true) . ' !== ' . var_export($b, true) . '）');
    }
}

function assert_contains(string $hay, string $needle, string $msg = ''): void
{
    if (strpos($hay, $needle) === false) {
        throw new RuntimeException('assert_contains 失败：' . $msg);
    }
}
```

- [ ] **步骤 3：写 runner**

`tests/run.php`：

```php
<?php
require __DIR__ . '/lib.php';

foreach (glob(__DIR__ . '/test_*.php') as $f) {
    require $f;
}

$pass = 0; $fail = 0;
foreach ($GLOBALS['__tests'] as $name => $fn) {
    try {
        $fn();
        echo "PASS  {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "FAIL  {$name}\n      " . $e->getMessage() . "\n";
        $fail++;
    }
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **步骤 4：跑一次（此刻应 0 passed, 0 failed，退出码 0）**

运行：`php tests/run.php`
预期：输出 `0 passed, 0 failed`，无报错。

- [ ] **步骤 5：Commit**

```bash
git add tests/lib.php tests/run.php
git commit -m "test: 增加极简断言器与测试 runner"
```

---

## 阶段 1：控制面

### 任务 1：控制面库与 4 张表

**文件：**
- 创建：`db/control/schema.sql`
- 修改：`api/config/config.sample.php`、`api/config/config.php`

- [ ] **步骤 1：写控制面 DDL**

`db/control/schema.sql`：

```sql
-- 控制面库：qingguan_control（跨租户对象）。MySQL 8.0 / utf8mb4。
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `saas_plan` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(50)  NOT NULL,
  `name`        VARCHAR(100) NOT NULL,
  `mode`        ENUM('shared','dedicated') NOT NULL DEFAULT 'shared',
  `price_month` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `price_year`  DECIMAL(10,2) NOT NULL DEFAULT 0,
  `max_users`   INT NOT NULL DEFAULT 0,
  `max_orders`  INT NOT NULL DEFAULT 0,
  `remark`      VARCHAR(255) NOT NULL DEFAULT '',
  `status`      TINYINT NOT NULL DEFAULT 1,
  `sort`        INT NOT NULL DEFAULT 0,
  `created_at`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SaaS 套餐';

CREATE TABLE IF NOT EXISTS `saas_tenant` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                VARCHAR(50)  NOT NULL COMMENT '租户代码，登录用',
  `name`                VARCHAR(100) NOT NULL,
  `logo_url`            VARCHAR(255) NOT NULL DEFAULT '',
  `mode`                ENUM('shared','dedicated') NOT NULL DEFAULT 'shared',
  `plan_id`             INT UNSIGNED NULL,
  `db_name`             VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '专用档库名',
  `status`              ENUM('pending','active','rejected','suspended') NOT NULL DEFAULT 'pending',
  `contact_name`        VARCHAR(50)  NOT NULL DEFAULT '',
  `contact_phone`       VARCHAR(30)  NOT NULL DEFAULT '',
  `contact_email`       VARCHAR(100) NOT NULL DEFAULT '',
  `admin_username`      VARCHAR(50)  NOT NULL DEFAULT '',
  `admin_password_hash` VARCHAR(255) NOT NULL DEFAULT '',
  `reject_reason`       VARCHAR(255) NOT NULL DEFAULT '',
  `applied_at`          DATETIME NULL,
  `reviewed_at`         DATETIME NULL,
  `reviewed_by`         INT UNSIGNED NULL,
  `created_at`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户';

CREATE TABLE IF NOT EXISTS `saas_platform_user` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `real_name`     VARCHAR(50)  NOT NULL DEFAULT '',
  `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
  `email`         VARCHAR(100) NOT NULL DEFAULT '',
  `status`        TINYINT NOT NULL DEFAULT 1,
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(50) NOT NULL DEFAULT '',
  `created_at`    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台管理员';

CREATE TABLE IF NOT EXISTS `saas_audit` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_user_id` INT UNSIGNED NULL,
  `action`           VARCHAR(50) NOT NULL,
  `target_type`      VARCHAR(50) NOT NULL DEFAULT '',
  `target_id`        INT UNSIGNED NULL,
  `detail`           VARCHAR(1000) NOT NULL DEFAULT '',
  `ip`               VARCHAR(50) NOT NULL DEFAULT '',
  `created_at`       DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target_type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台侧操作审计';

-- 两个套餐（示例价格，上线前按实际改）
INSERT INTO `saas_plan` (`code`,`name`,`mode`,`price_month`,`price_year`,`max_users`,`max_orders`,`remark`,`status`,`sort`) VALUES
  ('basic',    '标准版（共享）', 'shared',    600.00,  6000.00,  20,  5000, '数据与其他租户共享一套库，逻辑隔离', 1, 1),
  ('isolated', '隔离版（独享）', 'dedicated', 1500.00, 15000.00, 50, 50000, '独立数据库，物理隔离（二期开通）', 1, 2);
```

- [ ] **步骤 2：建库并导入**

运行：
```bash
mysql -h 127.0.0.1 -u root -p -e "CREATE DATABASE IF NOT EXISTS qingguan_control DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -h 127.0.0.1 -u root -p qingguan_control < db/control/schema.sql
mysql -h 127.0.0.1 -u root -p qingguan_control -e "SELECT id,code,mode,price_month FROM saas_plan;"
```
预期：两条套餐记录。

- [ ] **步骤 3：给租户库账号授权控制面只读+租户表读写**

> 便于后端用同一个账号连接两库；也可给控制面单独账号。此处复用 `qingguan` 账号并授权控制面库。

运行：
```bash
mysql -h 127.0.0.1 -u root -p -e "GRANT SELECT, INSERT, UPDATE ON qingguan_control.* TO 'qingguan'@'127.0.0.1'; GRANT SELECT, INSERT, UPDATE ON qingguan_control.* TO 'qingguan'@'localhost'; FLUSH PRIVILEGES;"
```
预期：无报错。

- [ ] **步骤 4：config 增加控制面段**

在 `api/config/config.sample.php` 的 `return [...]` 顶层、`'db'` 之后加入：

```php
    'control_db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'qingguan_control',
        'user'    => 'qingguan',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
```

并在本机 `api/config/config.php` 同步加一段（`pass` 填真实密码；该文件不入库）。

- [ ] **步骤 5：Commit**

```bash
git add db/control/schema.sql api/config/config.sample.php
git commit -m "feat: 增加控制面库结构与配置项"
```

### 任务 2：ControlDb 连接

**文件：**
- 创建：`api/src/ControlDb.php`
- 测试：`tests/test_control_db.php`

- [ ] **步骤 1：写失败测试**

`tests/test_control_db.php`：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

t('ControlDb 能连上控制面并读到套餐', function () {
    $rows = QG\ControlDb::all('SELECT code, mode FROM saas_plan ORDER BY sort');
    assert_true(count($rows) >= 2, '套餐至少 2 条');
    assert_eq($rows[0]['code'], 'basic');
});
```

- [ ] **步骤 2：写 bootstrap**

`tests/bootstrap.php`：

```php
<?php
require_once __DIR__ . '/lib.php';
$root = dirname(__DIR__) . '/api';
spl_autoload_register(function (string $class) use ($root): void {
    $prefix = 'QG\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $root . '/src/' . $rel . '.php';
    if (is_file($file)) { require $file; }
});
require $root . '/src/App.php';
require $root . '/src/Response.php';
```

- [ ] **步骤 3：运行测试验证失败**

运行：`php tests/run.php`
预期：FAIL，报错 `Class "QG\ControlDb" not found`。

- [ ] **步骤 4：实现 ControlDb**

`api/src/ControlDb.php`：

```php
<?php
namespace QG;

use PDO;
use PDOException;

class ControlDb
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) { return self::$pdo; }
        $c = App::config('control_db');
        if (!$c) { Response::fail('缺少 control_db 配置', 500); }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
        try {
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            Response::fail('控制面数据库连接失败：' . $e->getMessage(), 500);
        }
        return self::$pdo;
    }

    public static function all(string $sql, array $bind = []): array
    {
        $st = self::pdo()->prepare($sql); $st->execute($bind); return $st->fetchAll();
    }

    public static function one(string $sql, array $bind = []): ?array
    {
        $st = self::pdo()->prepare($sql); $st->execute($bind);
        $row = $st->fetch(); return $row === false ? null : $row;
    }

    public static function val(string $sql, array $bind = [])
    {
        $st = self::pdo()->prepare($sql); $st->execute($bind);
        $v = $st->fetchColumn(); return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $bind = []): int
    {
        $st = self::pdo()->prepare($sql); $st->execute($bind); return $st->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $st = self::pdo()->prepare($sql); $st->execute(array_values($data));
        return (int) self::pdo()->lastInsertId();
    }
}
```

- [ ] **步骤 5：运行测试验证通过**

运行：`php tests/run.php`
预期：`PASS  ControlDb 能连上控制面并读到套餐`。

- [ ] **步骤 6：Commit**

```bash
git add api/src/ControlDb.php tests/bootstrap.php tests/test_control_db.php
git commit -m "feat: 增加控制面连接 ControlDb"
```

---

## 阶段 2：数据面 schema 迁移

### 任务 3：加 `tenant_id`、改唯一键、回填租户 #1

**文件：**
- 创建：`db/migration_20261015_multitenant.sql`

- [ ] **步骤 1：写迁移脚本**

> 规则：**21 张表全部加 `tenant_id INT UNSIGNED NOT NULL DEFAULT 1`**；把现有唯一键改成带 `tenant_id` 的复合键；业务表 `tenant_id` 前置为第一列。脚本幂等（用 `INFORMATION_SCHEMA` 判断列/索引是否存在），可重复执行。

`db/migration_20261015_multitenant.sql` 关键片段（节选，**完整脚本按此模式覆盖全部 21 张表**）：

```sql
SET NAMES utf8mb4;

-- 1) 加列（对每张表重复此模式；此处以 sys_user / biz_order 为例）
ALTER TABLE `sys_user`  ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `biz_order` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`;
-- ... 其余 19 张表同样处理（含 8 张订单子表、3 张 fin_*、org_company、biz_plan、sys_role、
--     sys_permission、sys_role_permission、sys_config、sys_dict、sys_log）

-- 2) 改唯一键（旧键名 → 新复合键名）
ALTER TABLE `sys_user`    DROP INDEX `uk_username`,  ADD UNIQUE KEY `uk_tenant_username` (`tenant_id`,`username`);
ALTER TABLE `sys_role`    DROP INDEX `uk_code`,      ADD UNIQUE KEY `uk_tenant_code`     (`tenant_id`,`code`);
ALTER TABLE `sys_permission` DROP INDEX `uk_code`,   ADD UNIQUE KEY `uk_tenant_code`     (`tenant_id`,`code`);
ALTER TABLE `sys_config`  DROP INDEX `uk_cfg_key`,   ADD UNIQUE KEY `uk_tenant_cfg_key`  (`tenant_id`,`cfg_key`);
ALTER TABLE `sys_dict`    DROP INDEX `uk_type_key`,  ADD UNIQUE KEY `uk_tenant_type_key` (`tenant_id`,`dict_type`,`dict_key`);
ALTER TABLE `biz_plan`    DROP INDEX `uk_plan_code`, ADD UNIQUE KEY `uk_tenant_plan`     (`tenant_id`,`plan_code`);
ALTER TABLE `biz_order`   DROP INDEX `uk_order_no`,  ADD UNIQUE KEY `uk_tenant_order_no` (`tenant_id`,`order_no`);
ALTER TABLE `fin_account` DROP INDEX `uk_company`,   ADD UNIQUE KEY `uk_tenant_company`  (`tenant_id`,`company_id`);
-- sys_role_permission 的 uk_rp(role_id,permission_id) 不用改：role_id/permission_id 在共享库里全局唯一

-- 3) 回填（现有数据全部归租户 #1；DEFAULT 1 已保证，这句是显式兜底）
UPDATE `sys_user` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
-- ... 其余表同理（若 DEFAULT 1 生效，此步无实际改动）

-- 4) 常用列表补复合索引
ALTER TABLE `biz_order`   ADD KEY `idx_tenant_list` (`tenant_id`,`is_deleted`,`id`);
ALTER TABLE `org_company` ADD KEY `idx_tenant_list` (`tenant_id`,`is_deleted`,`id`);
ALTER TABLE `biz_order_file` ADD KEY `idx_tenant_order` (`tenant_id`,`order_id`);
```

- [ ] **步骤 2：在本机测试库上跑一遍**

运行（用 dev 库，先备份）：
```bash
mysqldump -h 127.0.0.1 -u qingguan -p qingguan > /tmp/qingguan_before_mt.sql
mysql -h 127.0.0.1 -u qingguan -p qingguan < db/migration_20261015_multitenant.sql
mysql -h 127.0.0.1 -u qingguan -p qingguan -e "SHOW CREATE TABLE sys_user\G" | grep -E "uk_tenant_username|tenant_id"
```
预期：`sys_user` 出现 `tenant_id` 列与 `uk_tenant_username`。

- [ ] **步骤 3：写测试断言结构与回填**

`tests/test_schema_mt.php`：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

t('所有表都有 tenant_id 且现有行归租户 1', function () {
    $tables = ['sys_user','sys_role','sys_permission','sys_role_permission','sys_config','sys_dict','sys_log',
        'org_company','biz_plan','biz_order','biz_order_container','biz_order_hscode','biz_order_address',
        'biz_isf','biz_inspect','biz_pickup','biz_order_file','biz_order_log',
        'fin_account','fin_recharge','fin_payable'];
    foreach ($tables as $tbl) {
        $col = QG\Db::val("SHOW COLUMNS FROM `{$tbl}` LIKE 'tenant_id'");
        assert_true($col !== null, "{$tbl} 缺 tenant_id");
    }
    assert_eq((int) QG\Db::val('SELECT COUNT(*) FROM sys_user WHERE tenant_id <> 1'), 0, '现有用户都应归租户 1');
});
```

> 注意：此测试需要 `Tenant` 上下文（`Db::val` 走数据面连接）。阶段 3 完成后 `Db::pdo()` 才能工作；本步骤先临时把该测试改为直连 `QG\ControlDb` 之外的普通 PDO 亦可，或在阶段 3 后一并跑。**执行者请把本测试放到任务 6 之后再跑通。**

- [ ] **步骤 4：Commit**

```bash
git add db/migration_20261015_multitenant.sql tests/test_schema_mt.php
git commit -m "feat: 数据面迁移——全表加 tenant_id 并改造唯一键"
```

### 任务 4：登记租户 #1 与套餐

**文件：**
- 创建：`db/control/seed_tenant1.sql`

- [ ] **步骤 1：写种子 SQL**

```sql
SET NAMES utf8mb4;
INSERT INTO `saas_tenant`
  (`code`,`name`,`mode`,`plan_id`,`status`,`contact_name`,`admin_username`,`applied_at`,`reviewed_at`)
VALUES
  ('demo','示例企业（原单机数据）','shared',
   (SELECT `id` FROM `saas_plan` WHERE `code`='basic'),'active','系统管理员','admin',NOW(),NOW());
```

- [ ] **步骤 2：导入并核对**

运行：
```bash
mysql -h 127.0.0.1 -u qingguan -p qingguan_control < db/control/seed_tenant1.sql
mysql -h 127.0.0.1 -u qingguan -p qingguan_control -e "SELECT id,code,mode,status FROM saas_tenant;"
```
预期：一行 `demo / shared / active`。

- [ ] **步骤 3：Commit**

```bash
git add db/control/seed_tenant1.sql
git commit -m "feat: 登记租户 #1（原有单机数据）"
```

---

## 阶段 3：租户上下文与连接路由

### 任务 5：Tenant 上下文

**文件：**
- 创建：`api/src/Tenant.php`
- 测试：`tests/test_tenant_context.php`

- [ ] **步骤 1：写失败测试**

`tests/test_tenant_context.php`：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

t('Tenant 未设置时 id 为 0，requireId 抛错', function () {
    QG\Tenant::set(null);
    assert_eq(QG\Tenant::id(), 0);
    $threw = false;
    try { QG\Tenant::requireId(); } catch (Throwable $e) { $threw = true; }
    assert_true($threw, '无上下文时 requireId 应报错');
});

t('Tenant 设置后能读回 id 与 mode', function () {
    QG\Tenant::set(['id' => 7, 'mode' => 'shared', 'db_name' => '']);
    assert_eq(QG\Tenant::id(), 7);
    assert_eq(QG\Tenant::mode(), 'shared');
    QG\Tenant::set(null);
});
```

- [ ] **步骤 2：运行验证失败**

运行：`php tests/run.php`
预期：FAIL，`Class "QG\Tenant" not found`。

- [ ] **步骤 3：实现 Tenant**

`api/src/Tenant.php`：

```php
<?php
namespace QG;

use PDO;

class Tenant
{
    private static ?array $ctx = null;
    private static array $pools = [];

    public static function set(?array $ctx): void
    {
        self::$ctx = $ctx;
    }

    public static function ctx(): ?array
    {
        return self::$ctx;
    }

    public static function id(): int
    {
        return (int) (self::$ctx['id'] ?? 0);
    }

    public static function mode(): string
    {
        return self::$ctx['mode'] ?? 'shared';
    }

    public static function requireId(): int
    {
        $id = self::id();
        if ($id <= 0) {
            Response::fail('缺少租户上下文', 403, 403);
        }
        return $id;
    }

    /** 当前租户的数据面连接（共享档→共享库；专用档→各自库） */
    public static function pdo(): PDO
    {
        $ctx = self::$ctx;
        if (!$ctx) {
            Response::fail('缺少租户上下文', 403, 403);
        }
        $dbName = (($ctx['mode'] ?? 'shared') === 'dedicated' && !empty($ctx['db_name']))
            ? $ctx['db_name']
            : App::config('db')['name'];
        if (!isset(self::$pools[$dbName])) {
            $c   = App::config('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $dbName, $c['charset']);
            self::$pools[$dbName] = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pools[$dbName];
    }

    /** 测试用：清空连接池 */
    public static function resetPools(): void
    {
        self::$pools = [];
    }
}
```

- [ ] **步骤 4：运行验证通过**

运行：`php tests/run.php`
预期：两个 Tenant 用例 PASS。

- [ ] **步骤 5：Commit**

```bash
git add api/src/Tenant.php tests/test_tenant_context.php
git commit -m "feat: 增加租户运行期上下文 Tenant"
```

### 任务 6：Db 委托给 Tenant

**文件：**
- 修改：`api/src/Db.php:11-33`

- [ ] **步骤 1：改 `Db::pdo()`**

把 `api/src/Db.php` 里的 `pdo()` 方法体整体替换为：

```php
    public static function pdo(): PDO
    {
        return Tenant::pdo();
    }
```

（`all/one/val/exec/insert/update/begin/commit/rollback` 全部不变，它们内部都调 `self::pdo()`。）

- [ ] **步骤 2：加一条集成测试（需 DB）**

`tests/test_db_tenant.php`：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

t('设置租户上下文后 Db 能读数据', function () {
    QG\Tenant::set(['id' => 1, 'mode' => 'shared', 'db_name' => '']);
    $n = QG\Db::val('SELECT COUNT(*) FROM sys_user WHERE tenant_id = 1');
    assert_true((int) $n >= 1, '租户 1 至少有一个用户');
});
```

- [ ] **步骤 3：运行阶段 2 与阶段 3 的测试**

运行：`php tests/run.php`
预期：`test_schema_mt`、`test_db_tenant`、`test_tenant_context` 全 PASS（此步把任务 3 步骤 3 的测试也跑通）。

- [ ] **步骤 4：Commit**

```bash
git add api/src/Db.php tests/test_db_tenant.php
git commit -m "refactor: Db 连接委托给租户上下文"
```

---

（计划续下：阶段 4 仓储层、阶段 5 登录与角色改名、阶段 6 隔离验证）

---

## 阶段 4：仓储层（把 171 处手写 SQL 收拢）

### 任务 7：仓储约定 + 守卫脚本

**文件：**
- 创建：`tests/guard_no_raw_business_sql.php`、`tests/test_guard.php`

**三条硬规则（后续所有仓储任务都遵守）：**

1. **业务 SQL 只允许写在 `api/src/repos/*.php` 里**；controller / Auth / App 一律不再直接 `Db::`。
2. **仓储里每条碰业务表的 SQL 都必须出现 `tenant_id`**（主表用 `xxx.tenant_id = ?`；`INSERT`/`UPDATE` 的数据里带 `tenant_id`；子表通过 `order_id` 关联时也要在子查询里补 `tenant_id`）。
3. **`tenant_id` 只在仓储里注入**：写方法统一 `$data['tenant_id'] = Tenant::requireId();`；读方法统一 `?` 绑定 `Tenant::requireId()`。

- [ ] **步骤 1：写守卫脚本**

`tests/guard_no_raw_business_sql.php`：

```php
<?php
// 守卫：① 仓储之外不得直接调 Db::；② 仓储里碰业务表的 SQL 必须带 tenant_id。
// 退出码非 0 = 有违规。
$root = dirname(__DIR__) . '/api/src';
$businessTables = ['org_company','biz_plan','biz_order','biz_order_container','biz_order_hscode',
    'biz_order_address','biz_isf','biz_inspect','biz_pickup','biz_order_file','biz_order_log',
    'fin_account','fin_recharge','fin_payable','sys_user','sys_role','sys_permission',
    'sys_role_permission','sys_config','sys_dict','sys_log'];
$errors = [];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    $p = $f->getPathname();
    if (!str_ends_with($p, '.php') || !str_contains($p, '/repos/')
        && (str_ends_with($p, '/Db.php') || str_ends_with($p, '/ControlDb.php') || !str_contains($p, '/api/src/'))) {
        continue;
    }
    $src = file_get_contents($p);
    $isRepo = str_contains($p, '/repos/');

    if (!$isRepo) {
        if (preg_match('/\bDb::(all|one|val|exec|insert|update)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $line = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
            $errors[] = "仓储外直接用了 Db::{$m[1][0]}()：{$p}:{$line}";
        }
        continue;
    }

    if (preg_match_all('/[\'"]([^\'"]*(?:SELECT|INSERT|UPDATE|DELETE)[^\'"]*)[\'"]/i', $src, $ms, PREG_OFFSET_CAPTURE)) {
        foreach ($ms[1] as $hit) {
            $sql = $hit[0];
            foreach ($businessTables as $tbl) {
                if (preg_match('/\b' . preg_quote($tbl, '/') . '\b/i', $sql) && stripos($sql, 'tenant_id') === false) {
                    $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
                    $errors[] = "仓储 SQL 缺 tenant_id：{$p}:{$line} → " . substr(preg_replace('/\s+/', ' ', $sql), 0, 90);
                }
            }
        }
    }
}

if ($errors) { fwrite(STDERR, implode("\n", array_unique($errors)) . "\n"); exit(1); }
echo "guard ok\n";
```

- [ ] **步骤 2：接入 runner**

`tests/test_guard.php`：

```php
<?php
require_once __DIR__ . '/lib.php';

t('守卫：无仓储外 Db:: 调用、仓储 SQL 均带 tenant_id', function () {
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/guard_no_raw_business_sql.php') . ' 2>&1';
    exec($cmd, $out, $code);
    assert_eq($code, 0, "守卫未通过：\n" . implode("\n", $out));
});
```

- [ ] **步骤 3：运行（此刻会失败——因为 controller 还在直接调 Db::）**

运行：`php tests/run.php`
预期：`FAIL  守卫...`，列出当前所有「仓储外直接用了 Db::」的位置（就是待迁移清单）。

- [ ] **步骤 4：Commit（守卫与失败清单一起入库，作为迁移基线）**

```bash
git add tests/guard_no_raw_business_sql.php tests/test_guard.php
git commit -m "test: 增加租户隔离守卫脚本（当前红灯=待迁移清单）"
```

### 任务 8：CompanyRepo（OrgController 11 处）

**文件：**
- 创建：`api/src/repos/CompanyRepo.php`
- 修改：`api/src/controllers/OrgController.php`
- 测试：`tests/test_repo_company.php`

- [ ] **步骤 1：写 CompanyRepo（含真实 SQL）**

`api/src/repos/CompanyRepo.php`：

```php
<?php
namespace QG\repos;

use QG\Db;
use QG\Tenant;

class CompanyRepo
{
    /** 列表（对齐 OrgController::index 的第 45-49 行，补 tenant_id） */
    public static function page(int $type, string $keyword, string $status, int $size, int $offset): array
    {
        $tid   = Tenant::requireId();
        $where = ['tenant_id = ?', 'is_deleted = 0', 'company_type = ?'];
        $bind  = [$tid, $type];
        if ($keyword !== '') {
            $where[] = '(company_name LIKE ? OR city LIKE ? OR address LIKE ?)';
            array_push($bind, "%{$keyword}%", "%{$keyword}%", "%{$keyword}%");
        }
        if ($status !== '') { $where[] = 'status = ?'; $bind[] = (int) $status; }
        $w     = implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) FROM org_company WHERE {$w}", $bind);
        $rows  = Db::all("SELECT * FROM org_company WHERE {$w} ORDER BY id DESC LIMIT {$size} OFFSET {$offset}", $bind);
        return [$rows, $total];
    }

    /** 下拉（对齐 OrgController::options 第 65-72 行） */
    public static function options(int $type, string $kw): array
    {
        $tid  = Tenant::requireId();
        $bind = [$tid, $type];
        $sql  = 'SELECT id, company_name, country, state_province, city, zip_code, address, phone, contact, bond_no
                   FROM org_company WHERE tenant_id = ? AND is_deleted = 0 AND status = 1 AND company_type = ?';
        if ($kw !== '') { $sql .= ' AND company_name LIKE ?'; $bind[] = "%{$kw}%"; }
        $sql .= ' ORDER BY company_name LIMIT 200';
        return Db::all($sql, $bind);
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM org_company WHERE tenant_id = ? AND id = ? AND is_deleted = 0',
            [Tenant::requireId(), $id]);
    }

    public static function exists(int $id): bool
    {
        return (bool) Db::val('SELECT id FROM org_company WHERE tenant_id = ? AND id = ? AND is_deleted = 0',
            [Tenant::requireId(), $id]);
    }

    public static function create(array $data): int
    {
        $data['tenant_id'] = Tenant::requireId();
        return Db::insert('org_company', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Db::update('org_company', $data, 'tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    public static function softDelete(int $id): int
    {
        return Db::exec('UPDATE org_company SET is_deleted = 1 WHERE tenant_id = ? AND id = ?',
            [Tenant::requireId(), $id]);
    }

    /** 进口商统计口径（对齐 OrgController::stats 的聚合查询，补 tenant_id） */
    public static function orderStats(int $companyId): array
    {
        $tid = Tenant::requireId();
        $row = Db::one(
            "SELECT
               SUM(customs_status = 'released')   AS released,
               SUM(customs_status = 'forecast')   AS forecast,
               SUM(customs_status = 'inspecting') AS inspecting
             FROM biz_order WHERE tenant_id = ? AND is_deleted = 0 AND importer_id = ?",
            [$tid, $companyId]
        );
        $inspected = (int) Db::val(
            'SELECT COUNT(DISTINCT i.order_id) FROM biz_inspect i
               JOIN biz_order o ON o.id = i.order_id AND o.tenant_id = i.tenant_id
              WHERE i.tenant_id = ? AND o.deleted_at IS NULL AND o.importer_id = ?',
            [$tid, $companyId]
        );
        return ['released' => (int) ($row['released'] ?? 0), 'forecast' => (int) ($row['forecast'] ?? 0),
                'inspecting' => (int) ($row['inspecting'] ?? 0), 'inspected' => $inspected];
    }
}
```

> 执行者：`orderStats` 的聚合口径以 `OrgController::stats()`/`statOf()` 现有实现为准，按上面的写法补 `tenant_id`；`o.deleted_at` 若 `biz_order` 用的是 `is_deleted` 则相应调整。

- [ ] **步骤 2：改写 OrgController 调用仓储**

把 `OrgController.php` 里所有 `Db::` 调用替换为 `CompanyRepo::` 对应方法（`use QG\repos\CompanyRepo;`）。例如 `index()` 里：

```php
        [$rows, $total] = CompanyRepo::page($type, $keyword, $status, $size, $offset);
        // 原 self::statOf() 内的聚合改为 CompanyRepo::orderStats($id)
```

- [ ] **步骤 3：写测试**

`tests/test_repo_company.php`：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

t('CompanyRepo 只返回本租户的记录', function () {
    QG\Tenant::set(['id' => 1, 'mode' => 'shared', 'db_name' => '']);
    [$rows, $total] = QG\repos\CompanyRepo::page(1, '', '', 10, 0);
    foreach ($rows as $r) { assert_eq((int) $r['tenant_id'], 1, '不应混入其它租户'); }
    assert_true($total >= count($rows), 'total 应不小于本页条数');
});
```

- [ ] **步骤 4：运行**

运行：`php tests/run.php`
预期：`CompanyRepo` 用例 PASS；守卫里 OrgController 的报错消失。

- [ ] **步骤 5：Commit**

```bash
git add api/src/repos/CompanyRepo.php api/src/controllers/OrgController.php tests/test_repo_company.php
git commit -m "refactor: OrgController 迁移进 CompanyRepo（含 tenant_id 隔离）"
```

### 任务 9：PlanRepo（PlanController 9 处）

**文件：**
- 创建：`api/src/repos/PlanRepo.php`
- 修改：`api/src/controllers/PlanController.php`

- [ ] **步骤 1：实现 PlanRepo**

按任务 8 的配方，把 `PlanController.php` 的 9 处 `Db::`（`all` 列表 / `one` 详情 / `val` 存在性 / `insert` / `update` / `exec` 删除）迁进 `PlanRepo`，全部补 `tenant_id`。方法：`page() / find($id) / exists($id) / create($data) / update($id,$data) / softDelete($id) / usedByOrders($planId)`。

关键写方法：

```php
    public static function create(array $data): int
    {
        $data['tenant_id'] = Tenant::requireId();
        return Db::insert('biz_plan', $data);
    }
    public static function update(int $id, array $data): int
    {
        return Db::update('biz_plan', $data, 'tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }
```

> 注意 `biz_plan.plan_code` 现已带 `tenant_id` 复合唯一键，查重时要 `WHERE tenant_id = ? AND plan_code = ? AND id <> ?`。

- [ ] **步骤 2：改写 PlanController 调仓储**

- [ ] **步骤 3：写测试**

`tests/test_repo_plan.php`：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

t('PlanRepo 同租户可复用 plan_code、跨租户互不影响', function () {
    QG\Tenant::set(['id' => 1, 'mode' => 'shared', 'db_name' => '']);
    $a = QG\repos\PlanRepo::create(['plan_code' => 'T8-CODE', 'plan_name' => '租户1方案', 'status' => 1]);
    assert_true($a > 0);
    QG\Tenant::set(['id' => 2, 'mode' => 'shared', 'db_name' => '']);
    $b = QG\repos\PlanRepo::create(['plan_code' => 'T8-CODE', 'plan_name' => '租户2方案', 'status' => 1]);
    assert_true($b > 0, '第二个租户用同一 plan_code 不应撞唯一键');
    // 清理
    QG\Db::exec('DELETE FROM biz_plan WHERE tenant_id IN (1,2) AND plan_code = ?', ['T8-CODE']);
    QG\Tenant::set(null);
});
```

- [ ] **步骤 4：运行 + Commit**

运行：`php tests/run.php` → 预期 PASS。
```bash
git add api/src/repos/PlanRepo.php api/src/controllers/PlanController.php tests/test_repo_plan.php
git commit -m "refactor: PlanController 迁移进 PlanRepo"
```

### 任务 10：FinanceRepo（FinanceController 29 处）

**文件：**
- 创建：`api/src/repos/FinanceRepo.php`
- 修改：`api/src/controllers/FinanceController.php`

- [ ] **步骤 1：实现 FinanceRepo**

覆盖 `fin_account / fin_recharge / fin_payable`（其中充值审核走事务，事务里每一条 SQL 都要带 `tenant_id`）。方法：`accounts() / rechargePage() / payablePage() / createRecharge($data) / approveRecharge($id)` 等，按 `FinanceController.php` 现有 29 处 `Db::` 逐一映射。

事务示例（审核入账）：

```php
    public static function approveRecharge(int $id): void
    {
        $tid = Tenant::requireId();
        Db::begin();
        try {
            $r = Db::one('SELECT * FROM fin_recharge WHERE tenant_id = ? AND id = ? AND status = 0',
                [$tid, $id]);
            if (!$r) { throw new \RuntimeException('充值单不存在或已处理'); }
            Db::update('fin_recharge', ['status' => 1], 'tenant_id = ? AND id = ?', [$tid, $id]);
            Db::exec('UPDATE fin_account SET balance = balance + ? WHERE tenant_id = ? AND id = ?',
                [$r['amount'], $tid, $r['account_id']]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }
```

- [ ] **步骤 2：改写 FinanceController 调仓储**
- [ ] **步骤 3：写测试**

`tests/test_repo_finance.php`：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

t('充值审核的事务只在同租户内生效', function () {
    $t1 = 9101; $t2 = 9102;
    QG\Db::exec('DELETE FROM fin_account WHERE tenant_id IN (?,?)', [$t1, $t2]);
    QG\Db::exec('DELETE FROM fin_recharge WHERE tenant_id IN (?,?)', [$t1, $t2]);

    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    $acc1 = QG\Db::insert('fin_account', ['tenant_id' => $t1, 'company_id' => 0, 'balance' => 100, 'status' => 1]);
    $rec1 = QG\Db::insert('fin_recharge', ['tenant_id' => $t1, 'account_id' => $acc1, 'amount' => 50, 'status' => 0]);

    QG\Tenant::set(['id' => $t2, 'mode' => 'shared', 'db_name' => '']);
    $acc2 = QG\Db::insert('fin_account', ['tenant_id' => $t2, 'company_id' => 0, 'balance' => 100, 'status' => 1]);

    // 租户2 拿租户1 的充值单 id 审核，应被拒
    $threw = false;
    try { QG\repos\FinanceRepo::approveRecharge((int) $rec1); } catch (Throwable $e) { $threw = true; }
    assert_true($threw, '跨租户审核应被拒');

    // 租户2 余额不受影响
    assert_eq((int) QG\Db::val('SELECT balance FROM fin_account WHERE tenant_id = ? AND id = ?', [$t2, $acc2]), 100);

    QG\Db::exec('DELETE FROM fin_recharge WHERE tenant_id IN (?,?)', [$t1, $t2]);
    QG\Db::exec('DELETE FROM fin_account WHERE tenant_id IN (?,?)', [$t1, $t2]);
    QG\Tenant::set(null);
});
```

> 列名（`account_id`/`company_id`/`balance` 等）以 `fin_account` / `fin_recharge` 的实际 schema 为准调整。
- [ ] **步骤 4：运行 + Commit**

```bash
git add api/src/repos/FinanceRepo.php api/src/controllers/FinanceController.php tests/test_repo_finance.php
git commit -m "refactor: FinanceController 迁移进 FinanceRepo"
```

### 任务 11：OrderRepo（OrderController 57 处，最重）

**文件：**
- 创建：`api/src/repos/OrderRepo.php`
- 修改：`api/src/controllers/OrderController.php`

- [ ] **步骤 1：迁移列表查询**

`OrderController::LIST_SELECT`（第 13-23 行）与 `LIST_FROM`（第 25-29 行）整体搬进 `OrderRepo`，并在 `buildWhere()`（第 50-90 行起）产出的 where 里**开头追加** `o.tenant_id = ?`（绑定 `Tenant::requireId()`），同时给 `LIST_SELECT` 里三个相关子查询补 `tenant_id`：

```php
    private const LIST_SELECT = '
        o.*, pl.plan_name, pl.plan_code, pl.intro AS plan_intro,
        imp.company_name AS importer_name,
        (SELECT GROUP_CONCAT(c.container_no ORDER BY c.sort SEPARATOR ", ")
           FROM biz_order_container c WHERE c.tenant_id = o.tenant_id AND c.order_id = o.id) AS container_nos,
        (SELECT COUNT(*) FROM biz_order_file f
           WHERE f.tenant_id = o.tenant_id AND f.order_id = o.id) AS file_count,
        (SELECT ad.company FROM biz_order_address ad
           WHERE ad.tenant_id = o.tenant_id AND ad.order_id = o.id AND ad.addr_type = 1 LIMIT 1) AS shipper_name,
        (SELECT ad.company FROM biz_order_address ad
           WHERE ad.tenant_id = o.tenant_id AND ad.order_id = o.id AND ad.addr_type = 2 LIMIT 1) AS consignee_name,
        (SELECT i.declare_date FROM biz_isf i
           WHERE i.tenant_id = o.tenant_id AND i.order_id = o.id ORDER BY i.id DESC LIMIT 1) AS declare_date,
        u.real_name AS creator_name';
```

`LIST_FROM` 的 JOIN 都靠 `o.plan_id / o.importer_id / o.created_by`（租户内 id），保持不变；主 `WHERE` 加 `o.tenant_id = ?` 即可。

- [ ] **步骤 2：迁移详情/写/子表**

`show()` 的 7 张子表查询、`store()/update()` 的事务写入、`changeStatus/verify/declareIsf/addInspect/delInspect/logs` 的读写，逐条搬进 `OrderRepo` 并补 `tenant_id`（子表 `INSERT` 时 `$data['tenant_id'] = $tid`；按 `order_id` 查子表时用 `WHERE tenant_id = ? AND order_id = ?`）。

- [ ] **步骤 3：权限码判定保留**

`OrderController.php:398` 的 `Auth::can($code)` 判定保留在 controller；仓储只管数据。

- [ ] **步骤 4：写隔离测试（关键）**

`tests/test_isolation.php`：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

t('订单列表严格按租户隔离', function () {
    // 用两行不同租户的订单做夹具
    $t1 = 9001; $t2 = 9002;
    QG\Db::exec('DELETE FROM biz_order WHERE tenant_id IN (?, ?) AND order_no LIKE ?', [$t1, $t2, 'ISO-%']);

    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    QG\Db::insert('biz_order', ['tenant_id' => $t1, 'order_no' => 'ISO-A', 'is_deleted' => 0, 'status' => 1]);
    QG\Tenant::set(['id' => $t2, 'mode' => 'shared', 'db_name' => '']);
    QG\Db::insert('biz_order', ['tenant_id' => $t2, 'order_no' => 'ISO-B', 'is_deleted' => 0, 'status' => 1]);

    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    $a = QG\Db::all("SELECT order_no FROM biz_order WHERE tenant_id = ? AND order_no LIKE 'ISO-%'", [$t1]);
    assert_eq(count($a), 1, '租户1 只应看到自己的 ISO-A');
    assert_eq($a[0]['order_no'], 'ISO-A');

    QG\Db::exec('DELETE FROM biz_order WHERE tenant_id IN (?, ?) AND order_no LIKE ?', [$t1, $t2, 'ISO-%']);
    QG\Tenant::set(null);
});
```

- [ ] **步骤 5：运行 + Commit**

```bash
git add api/src/repos/OrderRepo.php api/src/controllers/OrderController.php tests/test_isolation.php
git commit -m "refactor: OrderController 迁移进 OrderRepo（含子表与列表隔离）"
```

### 任务 12：UserRepo / RoleRepo / PermissionRepo / DictRepo / ConfigRepo / LogRepo（SystemController 48 处 + AuthController 5 处 + Auth.php 2 处）

**文件：**
- 创建：`api/src/repos/{UserRepo,RoleRepo,PermissionRepo,DictRepo,ConfigRepo,LogRepo}.php`
- 修改：`api/src/controllers/SystemController.php`、`api/src/controllers/AuthController.php`、`api/src/Auth.php`、`api/src/App.php`

- [ ] **步骤 1：实现各仓储**

按表域拆分（`SystemController` 一个文件里同时管这 6 类表）：

| 仓储 | 覆盖表 | 关键方法（按 SystemController 现有方法映射） |
|---|---|---|
| `UserRepo` | `sys_user` | `page/find(tenant,id)/findByUsername(username)/findWithRole(id)/create/update/touchLogin(id)/softDelete(id)/existsUsername` |
| `RoleRepo` | `sys_role` + `sys_role_permission` | `list/save/syncPermissions/delete/find` |
| `PermissionRepo` | `sys_permission` | `tree/all/list/codesForRole(roleId)/create/update/delete/menuTree` |
| `DictRepo` | `sys_dict` | `list/options/create/update/delete` |
| `ConfigRepo` | `sys_config` | `list/save` |
| `LogRepo` | `sys_log` + `biz_order_log` | `page/write/orderLogs` |

**关键：`RoleRepo::syncPermissions()` 是「全量覆盖式」写授权**（先 `DELETE` 该角色全部授权再逐条 `INSERT IGNORE`），必须改成只对 `tenant_id` 内操作：

```php
    public static function syncPermissions(int $roleId, array $permIds): void
    {
        $tid = Tenant::requireId();
        Db::exec('DELETE FROM sys_role_permission WHERE tenant_id = ? AND role_id = ?', [$tid, $roleId]);
        foreach (array_unique(array_filter(array_map('intval', $permIds))) as $pid) {
            if ($pid > 0) {
                Db::insert('sys_role_permission', ['tenant_id' => $tid, 'role_id' => $roleId, 'permission_id' => $pid]);
            }
        }
    }
```

> 注意：`sys_role_permission` 的唯一键 `uk_rp(role_id, permission_id)` 未带 `tenant_id`——因为 role_id/permission_id 在共享库中全局唯一，安全；但表仍新增了 `tenant_id` 列用于守卫与审计。

- [ ] **步骤 2：App::log 与 Auth 改走仓储**

`App::log()`（`api/src/App.php` 唯一 1 处 `Db::insert('sys_log'...)`）改为 `LogRepo::write(...)`，写入时带 `tenant_id`（平台侧操作走 `ControlDb`，另定）。
`Auth.php` 的 2 处查询（`user()` 的会话用户、`permissionCodes()`）改用 `UserRepo`/`PermissionRepo`，并带 `tenant_id`。

- [ ] **步骤 3：写测试**

`tests/test_repo_sys.php`：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

t('UserRepo 列表按租户隔离', function () {
    QG\Tenant::set(['id' => 1, 'mode' => 'shared', 'db_name' => '']);
    $rows = QG\repos\UserRepo::page('', '', 1, 50, 0)[0];
    foreach ($rows as $u) { assert_eq((int) $u['tenant_id'], 1, '不应出现他租户用户'); }
    QG\Tenant::set(null);
});

t('syncPermissions 只覆盖本租户授权，同 code 角色不撞键', function () {
    $t1 = 9201; $t2 = 9202;
    QG\Db::exec('DELETE FROM sys_role_permission WHERE tenant_id IN (?,?)', [$t1, $t2]);
    QG\Db::exec('DELETE FROM sys_role WHERE tenant_id IN (?,?)', [$t1, $t2]);

    QG\Tenant::set(['id' => $t1, 'mode' => 'shared', 'db_name' => '']);
    $r1 = QG\Db::insert('sys_role', ['tenant_id' => $t1, 'name' => 'A', 'code' => 'SAME', 'status' => 1]);
    QG\repos\RoleRepo::syncPermissions((int) $r1, [1, 2]);

    QG\Tenant::set(['id' => $t2, 'mode' => 'shared', 'db_name' => '']);
    $r2 = QG\Db::insert('sys_role', ['tenant_id' => $t2, 'name' => 'B', 'code' => 'SAME', 'status' => 1]);
    QG\repos\RoleRepo::syncPermissions((int) $r2, [3]);

    assert_eq((int) QG\Db::val('SELECT COUNT(*) FROM sys_role_permission WHERE tenant_id = ? AND role_id = ?', [$t1, $r1]), 2);
    assert_eq((int) QG\Db::val('SELECT COUNT(*) FROM sys_role_permission WHERE tenant_id = ? AND role_id = ?', [$t2, $r2]), 1);

    QG\Db::exec('DELETE FROM sys_role_permission WHERE tenant_id IN (?,?)', [$t1, $t2]);
    QG\Db::exec('DELETE FROM sys_role WHERE tenant_id IN (?,?)', [$t1, $t2]);
    QG\Tenant::set(null);
});
```

- [ ] **步骤 4：运行 + Commit**

```bash
git add api/src/repos/UserRepo.php api/src/repos/RoleRepo.php api/src/repos/PermissionRepo.php \
        api/src/repos/DictRepo.php api/src/repos/ConfigRepo.php api/src/repos/LogRepo.php \
        api/src/controllers/SystemController.php api/src/controllers/AuthController.php \
        api/src/Auth.php api/src/App.php tests/test_repo_sys.php
git commit -m "refactor: 系统元数据表迁移进仓储（User/Role/Permission/Dict/Config/Log）"
```

### 任务 13：FileRepo（UploadController 10 处）

**文件：**
- 创建：`api/src/repos/FileRepo.php`
- 修改：`api/src/controllers/UploadController.php`

- [ ] **步骤 1：实现 FileRepo**

覆盖 `biz_order_file`（`listByOrder/create/delete`）以及 `UploadController::options()` 里读 `sys_dict/org_company/biz_plan` 的 4 条聚合查询——后者改用 `DictRepo/CompanyRepo/PlanRepo`，不要重复写 SQL。

- [ ] **步骤 2：改写 UploadController + 运行守卫**

运行：`php tests/guard_no_raw_business_sql.php`
预期：`guard ok`（此时全部 controller 已迁移完）。

- [ ] **步骤 3：运行全部测试 + Commit**

运行：`php tests/run.php` → 预期全绿。
```bash
git add api/src/repos/FileRepo.php api/src/controllers/UploadController.php
git commit -m "refactor: UploadController 迁移进 FileRepo，仓储层收口完成"
```

---

## 阶段 5：租户代码登录与角色改名

### 任务 14：登录改为「租户代码 + 用户名 + 密码」

**文件：**
- 修改：`api/src/controllers/AuthController.php`（`login()`）、`api/src/Auth.php`（`login()/user()/permissionCodes()`）、`login.html`
- 测试：`tests/test_login.py`→ 用 `tests/test_login.php`

- [ ] **步骤 1：写失败测试（HTTP 级，需服务在跑）**

`tests/test_login.php`（用 curl 打本机 dev 站点）：

```php
<?php
require_once __DIR__ . '/lib.php';
$base = getenv('QG_BASE') ?: 'http://127.0.0.1:8080';

t('缺租户代码时登录被拒', function () use ($base) {
    $r = shell_exec("curl -s -X POST {$base}/api/auth/login -H 'Content-Type: application/json' -d '{\"username\":\"admin\",\"password\":\"admin123\"}'");
    assert_contains($r, '租户代码');
});

t('正确租户代码 + 账号可登录', function () use ($base) {
    $r = shell_exec("curl -s -X POST {$base}/api/auth/login -H 'Content-Type: application/json' -d '{\"tenant\":\"demo\",\"username\":\"admin\",\"password\":\"admin123\"}'");
    assert_contains($r, '"code":0');
});
```

- [ ] **步骤 2：改 AuthController::login**

```php
    public static function login(): void
    {
        $code     = Req::require_('tenant', '租户代码');
        $username = Req::require_('username', '账号');
        $password = Req::str('password');
        if ($password === '') { Response::fail('密码不能为空'); }

        $t = ControlDb::one('SELECT * FROM saas_tenant WHERE code = ? LIMIT 1', [$code]);
        if (!$t) { Response::fail('租户代码不存在'); }
        if ($t['status'] === 'pending')  { Response::fail('申请审核中，通过后即可登录'); }
        if ($t['status'] === 'rejected') { Response::fail('申请未通过' . ($t['reject_reason'] !== '' ? '：' . $t['reject_reason'] : '')); }
        if ($t['status'] !== 'active')   { Response::fail('该租户已停用，请联系平台'); }

        Tenant::set(['id' => (int) $t['id'], 'mode' => $t['mode'], 'db_name' => $t['db_name']]);

        $u = UserRepo::findByUsername($username);   // 内部带 tenant_id
        if (!$u || !password_verify($password, $u['password_hash'])) { Response::fail('账号或密码错误'); }
        if ((int) $u['status'] !== 1) { Response::fail('该账号已被停用，请联系管理员'); }

        UserRepo::touchLogin((int) $u['id']);
        Auth::login($u, $t);
        App::log('auth', 'login', (int) $u['id'], $username);
        unset($u['password_hash']);
        Response::ok($u, '登录成功');
    }
```

- [ ] **步骤 3：改 Auth::login / Auth::user / permissionCodes**

```php
    public static function login(array $user, array $tenant): void
    {
        $ttl   = (int) App::config('app')['token_ttl'];
        $token = self::sign([
            'scope' => 'tenant', 'tid' => (int) $tenant['id'],
            'uid' => (int) $user['id'], 'uname' => $user['username'], 'exp' => time() + $ttl,
        ]);
        // ...setcookie 原样保留...
    }

    public static function user(): ?array
    {
        if (self::$user !== null) { return self::$user ?: null; }
        $payload = self::verify($_COOKIE[self::COOKIE] ?? null);
        if (!$payload) { self::$user = []; return null; }

        if (($payload['scope'] ?? '') === 'platform') {
            $u = ControlDb::one('SELECT * FROM saas_platform_user WHERE id = ? AND status = 1 LIMIT 1',
                [(int) ($payload['puid'] ?? 0)]);
            if (!$u) { self::$user = []; return null; }
            unset($u['password_hash']);
            $u['scope'] = 'platform';
            self::$user = $u;
            return $u;
        }

        $t = ControlDb::one('SELECT * FROM saas_tenant WHERE id = ? LIMIT 1', [(int) ($payload['tid'] ?? 0)]);
        if (!$t || $t['status'] !== 'active') { self::$user = []; return null; }
        Tenant::set(['id' => (int) $t['id'], 'mode' => $t['mode'], 'db_name' => $t['db_name']]);

        $u = UserRepo::findWithRole((int) $payload['uid']);   // 带 tenant_id
        if (!$u || (int) $u['status'] !== 1) { self::$user = []; return null; }
        unset($u['password_hash']);
        self::$user = $u;
        return $u;
    }

    public static function isTenantAdmin(): bool
    {
        $u = self::user();
        return $u && ($u['role_code'] ?? '') === 'tenant_admin';
    }

    public static function permissionCodes(): array
    {
        $u = self::user();
        if (!$u || ($u['scope'] ?? '') === 'platform') { return []; }
        if ($u['role_code'] === 'tenant_admin') { return ['*']; }
        return PermissionRepo::codesForRole((int) $u['role_id']);
    }
```

> 三处 `super` 判定（`Auth.php:127`、`Auth.php:142`、`AuthController.php:83` / 现 `menu()`）统一改成 `'tenant_admin'`。

- [ ] **步骤 4：改 login.html 加「租户代码」输入**

在 `login.html` 的账号输入项前插入一项，并让 `submit()` 提交 `tenant`：

```html
        <el-input v-model="form.tenant" placeholder="请输入租户代码" size="large" clearable>
          <template #prefix><el-icon><OfficeBuilding /></el-icon></template>
        </el-input>
```

```js
      data: function () {
        return { form: { tenant: '', username: '', password: '' }, loading: false, year: new Date().getFullYear() };
      },
```

- [ ] **步骤 5：跑测试 + 手动验证**

运行：`php tests/run.php`（登录用例需服务在跑）
浏览器打开 `https://qingguan.sflswall.com.cn/login.html`，用 `demo / admin / admin123` 登录成功。

- [ ] **步骤 6：Commit**

```bash
git add api/src/controllers/AuthController.php api/src/Auth.php login.html tests/test_login.php
git commit -m "feat: 登录改为租户代码解析，Auth 支持 tenant/platform scope"
```

### 任务 15：角色改名 super → tenant_admin

**文件：**
- 创建：`db/control/../migration_20261015_role_rename.sql`（放 `db/`）
- 修改：代码里 `super` 字面量的三处（任务 14 已改 `Auth.php` 与 `menu()`）

- [ ] **步骤 1：写数据迁移**

`db/migration_20261015_role_rename.sql`：

```sql
-- 租户内的「超级管理员」改名「租户系统管理员」，code：super → tenant_admin
UPDATE `sys_role` SET `code` = 'tenant_admin', `name` = '租户系统管理员' WHERE `code` = 'super';
```

- [ ] **步骤 2：执行并核对**

```bash
mysql -h 127.0.0.1 -u qingguan -p qingguan < db/migration_20261015_role_rename.sql
mysql -h 127.0.0.1 -u qingguan -p qingguan -e "SELECT id,code,name FROM sys_role WHERE tenant_id=1;"
```
预期：`tenant_admin / 租户系统管理员`。

- [ ] **步骤 3：写测试**

`tests/test_role_rename.php`：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

t('不存在 code=super 的角色，且租户管理员为 tenant_admin', function () {
    QG\Tenant::set(['id' => 1, 'mode' => 'shared', 'db_name' => '']);
    assert_eq(QG\Db::val("SELECT COUNT(*) FROM sys_role WHERE code = 'super'"), 0);
    assert_true((int) QG\Db::val("SELECT COUNT(*) FROM sys_role WHERE code = 'tenant_admin'") >= 1);
});
```

- [ ] **步骤 4：运行 + Commit**

```bash
git add db/migration_20261015_role_rename.sql tests/test_role_rename.php
git commit -m "feat: 角色 super 改名 tenant_admin（租户系统管理员）"
```

---

## 阶段 6：收口验证

### 任务 16：全量回归 + 隔离总测 + 计划自检

**文件：**
- 修改：`docs/superpowers/plans/2026-10-14-multi-tenant-phase1-foundation.md`（勾选完成项）

- [ ] **步骤 1：跑守卫与全部测试**

运行：
```bash
php tests/guard_no_raw_business_sql.php
php tests/run.php
```
预期：`guard ok`；所有用例 PASS。

- [ ] **步骤 2：手动冒烟（真实浏览器）**

用 `demo / admin / admin123` 登录，走一遍：首页看板、清关订单列表、抬头管理、报价方案、财务、系统设置（员工/角色/字典/配置/菜单）都不报错。再直接构造一个跨租户 id 调接口（如 `GET /orders/{他租户id}`），应返回「记录不存在」。

- [ ] **步骤 3：部署到 breeder 验证**

在本机执行（`breeder` 的 SSH 别名见环境说明）：

```bash
# 1) 同步代码（只回传代码，不带本机 config.php / 上传 / 运行时）
rsync -a --exclude '.git' --exclude 'api/config/config.php' --exclude 'uploads/' --exclude 'api/runtime/' \
  /data/qingguan/app/ breeder.sflswall.com.cn:/tmp/qg_release/
ssh breeder.sflswall.com.cn 'sudo rsync -a /tmp/qg_release/ /opt/qingguan/ && sudo chown -R www-data:www-data /opt/qingguan && sudo chmod -R 775 /opt/qingguan/uploads /opt/qingguan/api/runtime'

# 2) 控制面库
ssh breeder.sflswall.com.cn 'sudo mysql -e "CREATE DATABASE IF NOT EXISTS qingguan_control DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
ssh breeder.sflswall.com.cn 'sudo mysql qingguan_control' < db/control/schema.sql
ssh breeder.sflswall.com.cn 'sudo mysql qingguan_control' < db/control/seed_tenant1.sql

# 3) 数据面迁移（先备份）
ssh breeder.sflswall.com.cn 'sudo mysqldump qingguan | gzip > /tmp/qingguan_pre_mt.sql.gz'
ssh breeder.sflswall.com.cn 'sudo mysql qingguan' < db/migration_20261015_multitenant.sql
ssh breeder.sflswall.com.cn 'sudo mysql qingguan' < db/migration_20261015_role_rename.sql

# 4) 远程 config.php 补 control_db 段后 reload（PHP 无需重启）
ssh breeder.sflswall.com.cn 'sudo systemctl reload apache2'
```

> `rsync` 故意不加 `--delete`，避免误删远程 `uploads/`；本机若无 `rsync`，用 `tar -cz -C /data/qingguan/app . | ssh … tar xz -C /tmp/qg_release` 等价替代。远程 `api/config/config.php` 由部署单独维护，本次需手动补 `control_db` 段。

- [ ] **步骤 4：Commit 计划勾选**

```bash
git add docs/superpowers/plans/2026-10-14-multi-tenant-phase1-foundation.md
git commit -m "docs: 一期地基计划执行完成"
```
