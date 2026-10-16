# P0-A 账单与有效期 实现计划

> **面向 AI 代理的工作者：** 必需子技能：使用 superpowers:subagent-driven-development（推荐）或 superpowers:executing-plans 逐任务实现此计划。步骤使用复选框（`- [ ]`）语法来跟踪进度。

**目标：** 引入账单实体与服务期——注册选周期生成账单、审核通过即 30 天试用、线下付款平台确认后到期日生效/顺延、租户侧条幅+账单中心、超宽限登录拦截。

**架构：** 控制面新增 `saas_bill`（金额快照 + unpaid/paid/void 状态机）与 `saas_tenant.expires_at`；服务期状态由纯函数 `QG\Billing` 实时派生（不落状态列）；租户侧条幅走 `/auth/me` 扩展字段，账单中心是新的一级菜单页（权限行对存量租户幂等补插 + schema.sql 默认种子）；平台侧在 platform.html 加第三个视图。规格：`docs/superpowers/specs/2026-10-16-billing-expiry-design.md`（已评审通过，本计划不重新决策）。

**技术栈：** PHP 8.3 原生（无框架无 composer）+ PDO；Vue 3 global build + Element Plus（in-DOM 模板，**组件标签必须显式闭合**）；MySQL 8.0 / MariaDB 10.5 双兼容迁移。

---

## 环境铁律（执行者必读）

- 本机用 **`php83`**；DB 管理 `sudo -n mysql`（dev：数据面 `qingguan_dev`、控制面 `qingguan_control_dev`）；测试直连真实 dev 库，合成数据用高号段 id 且 finally 清理。
- 提交信息中文 Conventional Commits；**只 `git add` 明确路径**；门禁 `php83 tests/run.php`（基线 69 passed）+ `php83 tests/guard_no_raw_business_sql.php`（guard ok）不绿不提交。
- `api/config/config.php` 不入库、**不要读它**（凭据在里面）；需要改它时用任务 6 步骤里的程序化插入（var_export 整体重写，不回显任何值），先备份到 `/tmp`。
- in-DOM 模板（platform/index/register/billing 页）**绝不允许自闭合组件标签** `<el-xxx />`——浏览器会吞掉后续兄弟节点（真实事故）。
- 工作分支：从 main 拉 `feat/billing-expiry`。

## 文件结构

| 文件 | 动作 | 职责 |
|---|---|---|
| `api/src/Billing.php` | 创建 | 服务期状态派生 + 到期日三分支重算（纯函数，GRACE_DAYS=30 常量） |
| `tests/test_billing_calc.php` | 创建 | Billing 单测（固定时钟，零 DB 副作用） |
| `db/migration_20261016_control_billing.sql` | 创建 | 控制面迁移：saas_tenant 两列 + saas_bill 表（幂等） |
| `db/migration_20261016_menu_billing.sql` | 创建 | 数据面迁移：对库内全部租户补插「账单中心」菜单（幂等） |
| `db/control/schema.sql` | 修改 | saas_tenant 列 + saas_bill DDL（新装机） |
| `db/schema.sql` | 修改 | 顶级菜单种子加「账单中心」行（新租户开租自动有） |
| `api/src/ControlDb.php` | 修改 | 补 begin/commit/rollback（确认收款需要原子性） |
| `api/src/TenantProvisioner.php` | 修改 | 两条状态守卫 UPDATE 加 `expires_at = NOW()`（审核即试用开始） |
| `api/src/controllers/TenantController.php` | 修改 | 注册收 bill_cycle + 生成第一张账单 |
| `api/src/controllers/PlatformController.php` | 修改 | reject 自动作废账单；新增 bills/confirmBill/voidBill；tenants 列表加 expires_at |
| `api/src/controllers/AuthController.php` | 修改 | login 加过期拦截分支；me() 加 billing 字段 |
| `api/src/Auth.php` | 修改 | user() 租户分支：超宽限视同未登录；带出 expires_at |
| `api/src/repos/BillRepo.php` | 创建 | 账单仓储（全部查询绑定 tenant_id，守卫纪律） |
| `api/src/controllers/BillController.php` | 创建 | 租户侧 GET/POST /api/bills（仅租户管理员） |
| `api/index.php` | 修改 | 注册 BillController（TenantController 之后） |
| `register.html` | 修改 | 付费周期单选（年付默认） |
| `pages/billing.html` | 创建 | 租户账单中心页 |
| `platform.html` | 修改 | 第三个视图「账单」+ 租户列表有效期列 |
| `index.html` | 修改 | 外壳顶部条幅 |
| `api/config/config.sample.php` | 修改 | app.billing_notice 键 |
| `tests/test_billing_http.php` | 创建 | 账单全链路 HTTP 用例 |
| `AGENTS.md` / `version.json` | 修改 | 口径 + bump |

---

### 任务 1：`QG\Billing` 纯函数 + 单测

**文件：** 创建 `api/src/Billing.php`、`tests/test_billing_calc.php`

- [ ] **步骤 1.1：写失败的测试** —— 创建 `tests/test_billing_calc.php`（全文）：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

use QG\Billing;

const T0 = 1792152000; // 2026-10-16 12:00:00 UTC+8 对应的固定时钟

t('Billing::state：none/normal/soon/grace/expired 五态', function () {
    assert_eq('none', Billing::state(null, false)['state']);
    assert_eq('normal', Billing::state('2027-03-01 00:00:00', false)['state'], '剩余>30天');
    $s = Billing::state(date('Y-m-d H:i:s', T0 + 15 * 86400), false, T0);
    assert_eq('soon', $s['state']);
    assert_eq(15, $s['days_left'], '剩余天数按自然日');
    $s = Billing::state(date('Y-m-d H:i:s', T0 - 10 * 86400), false, T0);
    assert_eq('grace', $s['state']);
    assert_true($s['trial'], '无付款记录的宽限 = 试用');
    assert_eq(20, $s['days_left'], '宽限剩余 = 距宽限截止');
    assert_eq('grace', Billing::state(date('Y-m-d H:i:s', T0 - 10 * 86400), true, T0)['state']);
    assert_true(!Billing::state(date('Y-m-d H:i:s', T0 - 10 * 86400), true, T0)['trial'], '有付款记录 = 非试用');
    assert_eq('expired', Billing::state(date('Y-m-d H:i:s', T0 - 31 * 86400), true, T0)['state'], '超宽限');
});

t('Billing::state：边界（恰好到期日、宽限最后一天）', function () {
    assert_eq('soon', Billing::state(date('Y-m-d H:i:s', T0), false, T0)['state'], '到期时刻=now 仍是临期(0天)');
    assert_eq('grace', Billing::state(date('Y-m-d H:i:s', T0 - 30 * 86400), true, T0)['state'], '宽限最后一天');
    assert_eq('expired', Billing::state(date('Y-m-d H:i:s', T0 - 30 * 86400 - 1), true, T0)['state']);
});

t('Billing::nextExpiry：三分支重算', function () {
    // 分支1：未到期续费 → 旧到期日 + 周期（到期日在 T0 之后才算「未到期」）
    assert_eq('2028-01-15 00:00:00', Billing::nextExpiry('year', '2027-01-15 00:00:00', T0));
    // 分支2：宽限内（含试用，expires_at=审核日）→ 旧到期日 + 周期（T0-11 天，仍在 30 天宽限内；试用期白送）
    assert_eq('2026-11-05 00:00:00', Billing::nextExpiry('month', '2026-10-05 00:00:00', T0));
    assert_eq('2027-10-05 00:00:00', Billing::nextExpiry('year', '2026-10-05 00:00:00', T0));
    // 分支3：已超宽限 → 付款日起算（now+周期，月付落在 T0 的下个月）
    $r = Billing::nextExpiry('month', '2026-08-01 00:00:00', T0);
    assert_true(strpos($r, date('Y-m', T0 + 16 * 86400)) === 0, '超宽限按 now+周期起算：' . $r);
    // NULL 到期（不应发生，兜底）→ now 起算
    assert_true(Billing::nextExpiry('month', null, T0) !== '');
    // 非法周期
    $thrown = false;
    try { Billing::nextExpiry('week', '2026-01-15 00:00:00', T0); } catch (Throwable $e) { $thrown = true; }
    assert_true($thrown, '非法周期应拒绝');
});
```

- [ ] **步骤 1.2：运行验证失败** —— `php83 tests/run.php 2>&1 | tail -4`，预期新用例 FAIL（类不存在）。

- [ ] **步骤 1.3：实现** —— 创建 `api/src/Billing.php`（全文）：

```php
<?php
namespace QG;

/**
 * 服务期计算（纯函数，不碰 DB）。
 * 状态机与三分支重算规则见 docs/superpowers/specs/2026-10-16-billing-expiry-design.md §3。
 * 约定：试用 = 「无 paid 账单 + expires_at=审核日」的宽限态，不落状态列。
 */
class Billing
{
    public const GRACE_DAYS = 30;

    /** @return array{state:string, days_left:int, trial:bool} state: none|normal|soon|grace|expired */
    public static function state(?string $expiresAt, bool $hasPaid, int $now = null): array
    {
        if ($expiresAt === null || $expiresAt === '') {
            return ['state' => 'none', 'days_left' => 0, 'trial' => false];
        }
        $now = $now ?: time();
        $exp = strtotime($expiresAt);
        $graceEnd = $exp + self::GRACE_DAYS * 86400;
        if ($now > $graceEnd) {
            return ['state' => 'expired', 'days_left' => 0, 'trial' => false];
        }
        if ($now > $exp) {
            return ['state' => 'grace', 'days_left' => self::daysBetween($now, $graceEnd), 'trial' => !$hasPaid];
        }
        $left = self::daysBetween($now, $exp);
        return ['state' => $left <= self::GRACE_DAYS ? 'soon' : 'normal', 'days_left' => $left, 'trial' => false];
    }

    /** 到期日三分支重算（确认收款时调用）：未到期/宽限内 → 旧到期日+周期；超宽限 → now+周期 */
    public static function nextExpiry(string $cycle, ?string $expiresAt, int $now): string
    {
        if (!in_array($cycle, ['month', 'year'], true)) {
            throw new \RuntimeException('未知付费周期：' . $cycle);
        }
        $exp = ($expiresAt !== null && $expiresAt !== '') ? strtotime($expiresAt) : false;
        $base = ($exp !== false && $now <= $exp + self::GRACE_DAYS * 86400) ? $exp : $now;
        return date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s', $base) . ' ' . ($cycle === 'month' ? '+1 month' : '+1 year')));
    }

    private static function daysBetween(int $from, int $to): int
    {
        return $to <= $from ? 0 : (int) ceil(($to - $from) / 86400);
    }
}
```

- [ ] **步骤 1.4：运行验证通过** —— `php83 tests/run.php 2>&1 | tail -1`，预期 **72 passed, 0 failed**（69+3，run.php 按 t() 组计数）。

- [ ] **步骤 1.5：Commit**

```bash
git add api/src/Billing.php tests/test_billing_calc.php
git commit -m "feat: Billing 服务期状态机与到期日三分支重算（纯函数）"
```

---

### 任务 2：控制面迁移 + db/control/schema.sql

**文件：** 创建 `db/migration_20261016_control_billing.sql`；修改 `db/control/schema.sql:21-44`（saas_tenant 列）与其末尾（saas_bill DDL）

- [ ] **步骤 2.1：写迁移** —— 创建 `db/migration_20261016_control_billing.sql`（全文；**在控制面库执行**）：

```sql
-- 控制面：账单与有效期（幂等；MySQL 8.0 与 MariaDB 10.5 通用；执行库 = qingguan_control[_dev]）
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saas_tenant' AND COLUMN_NAME = 'expires_at') = 0,
  'ALTER TABLE `saas_tenant` ADD COLUMN `bill_cycle` ENUM(''month'',''year'') NOT NULL DEFAULT ''year'' COMMENT ''注册申请的付费周期意愿'' AFTER `reject_reason`, ADD COLUMN `expires_at` DATETIME NULL COMMENT ''服务期到期日；NULL=不参与到期逻辑'' AFTER `bill_cycle`',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saas_bill') = 0,
  'CREATE TABLE `saas_bill` (
     `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
     `tenant_id` INT UNSIGNED NOT NULL,
     `cycle` ENUM(''month'',''year'') NOT NULL,
     `amount` DECIMAL(10,2) NOT NULL,
     `status` ENUM(''unpaid'',''paid'',''void'') NOT NULL DEFAULT ''unpaid'',
     `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     `paid_at` DATETIME NULL,
     `paid_channel` VARCHAR(30) NOT NULL DEFAULT '''',
     `paid_ref` VARCHAR(100) NOT NULL DEFAULT '''',
     `confirmed_by` INT UNSIGNED NULL,
     `void_reason` VARCHAR(200) NOT NULL DEFAULT '''',
     `remark` VARCHAR(200) NOT NULL DEFAULT '''',
     PRIMARY KEY (`id`),
     KEY `idx_tenant` (`tenant_id`,`status`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''账单''',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
```

- [ ] **步骤 2.2：dev 控制面执行 + 幂等复跑**

```bash
sudo -n mysql qingguan_control_dev < db/migration_20261016_control_billing.sql && sudo -n mysql qingguan_control_dev < db/migration_20261016_control_billing.sql && echo IDEMPOTENT_OK
sudo -n mysql -e "SHOW COLUMNS FROM qingguan_control_dev.saas_tenant LIKE 'expires_at'; SHOW COLUMNS FROM qingguan_control_dev.saas_tenant LIKE 'bill_cycle'; SHOW CREATE TABLE qingguan_control_dev.saas_bill\G" | head -30
```

预期：两次执行都成功；三处结构就位。

- [ ] **步骤 2.3：同步 `db/control/schema.sql`** —— `saas_tenant` DDL 的 `reject_reason` 行后加：

```sql
  `bill_cycle`          ENUM('month','year') NOT NULL DEFAULT 'year' COMMENT '注册申请的付费周期意愿',
  `expires_at`          DATETIME NULL COMMENT '服务期到期日；NULL=不参与到期逻辑',
```

文件末尾（`saas_audit` 表之后）加：

```sql
CREATE TABLE IF NOT EXISTS `saas_bill` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL,
  `cycle`        ENUM('month','year') NOT NULL COMMENT '服务周期',
  `amount`       DECIMAL(10,2) NOT NULL COMMENT '生成时快照套餐价',
  `status`       ENUM('unpaid','paid','void') NOT NULL DEFAULT 'unpaid',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at`      DATETIME NULL,
  `paid_channel` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '二期在线支付前恒 offline',
  `paid_ref`     VARCHAR(100) NOT NULL DEFAULT '' COMMENT '支付流水号/转账备注',
  `confirmed_by` INT UNSIGNED NULL COMMENT '确认收款的平台账号',
  `void_reason`  VARCHAR(200) NOT NULL DEFAULT '',
  `remark`       VARCHAR(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='账单';
```

- [ ] **步骤 2.4：Commit**

```bash
git add db/migration_20261016_control_billing.sql db/control/schema.sql
git commit -m "feat: 控制面迁移——saas_tenant 有效期列 + saas_bill 账单表"
```

---

### 任务 3：数据面菜单迁移 + schema.sql 种子

**文件：** 创建 `db/migration_20261016_menu_billing.sql`；修改 `db/schema.sql:462-473`（顶级菜单种子 UNION 列表）

- [ ] **步骤 3.1：写迁移** —— 创建 `db/migration_20261016_menu_billing.sql`（全文；**在每个数据面库执行**：共享库手工跑；未来专用库经 `scripts/migrate_dedicated.php`）：

```sql
-- 数据面：对库内全部已开通租户补插「账单中心」一级菜单（幂等；uk_tenant_code 去重；
-- 租户集合从 sys_permission 现有行派生，不跨库引用控制面——dev/prod 库名不同）
INSERT INTO `sys_permission` (`tenant_id`,`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT DISTINCT p.tenant_id, 0, '账单中心', 'billing', 1, '/pages/billing.html', 'Wallet', 2, 1
  FROM `sys_permission` p
  LEFT JOIN `sys_permission` x ON x.tenant_id = p.tenant_id AND x.code = 'billing'
 WHERE x.id IS NULL;
```

- [ ] **步骤 3.2：dev 共享库执行 + 幂等复跑**

```bash
sudo -n mysql qingguan_dev < db/migration_20261016_menu_billing.sql && sudo -n mysql qingguan_dev < db/migration_20261016_menu_billing.sql && echo IDEMPOTENT_OK
sudo -n mysql -e "SELECT tenant_id, id, name, sort FROM qingguan_dev.sys_permission WHERE code='billing';"
```

预期：租户 1（demo）一行；二次执行 0 行新增。

- [ ] **步骤 3.3：同步 `db/schema.sql`** —— 顶级菜单 UNION 列表（462-473 行）在「系统设置」行后加：

```sql
    UNION ALL SELECT '账单中心',   'billing',           '/pages/billing.html',      'Wallet',           2
```

注意该文件铁律：语句字符串值内不得含 ASCII 分号、行尾注释必须 `-- ` 带空格（ProvisionRepo::splitStatements 约定）。

- [ ] **步骤 3.4：Commit**

```bash
git add db/migration_20261016_menu_billing.sql db/schema.sql
git commit -m "feat: 账单中心菜单——存量租户幂等补插迁移 + schema.sql 默认种子"
```

---

### 任务 4：注册链路（cycle + 第一张账单 + 驳回作废）

**文件：** 修改 `api/src/controllers/TenantController.php:96-125`、`register.html:39-48/74/105-112`、`api/src/controllers/PlatformController.php:111-136`（reject）

- [ ] **步骤 4.1：TenantController::register** —— try 块前加校验（在 `$password` 校验之后）：

```php
        $cycle = Req::str('bill_cycle');
        if (!in_array($cycle, ['month', 'year'], true)) {
            Response::fail('请选择付费周期');
        }
```

`ControlDb::insert('saas_tenant', [...])` 数组加一项（`'applied_at'` 之后）：

```php
                'bill_cycle'          => $cycle,
```

审计 insert 之后、`Response::ok` 之前加第一张账单（金额快照，列名来自白名单，无注入面）：

```php
        $priceCol = $cycle === 'month' ? 'price_month' : 'price_year';
        $amount = (float) ControlDb::val("SELECT {$priceCol} FROM saas_plan WHERE id = ?", [$planId]);
        ControlDb::insert('saas_bill', [
            'tenant_id' => $id, 'cycle' => $cycle, 'amount' => $amount,
            'status' => 'unpaid', 'remark' => '注册账单',
        ]);
```

- [ ] **步骤 4.2：register.html** —— 套餐 `</el-form-item>`（48 行）后加：

```html
          <el-form-item label="付费周期" required>
            <el-radio-group v-model="form.bill_cycle">
              <el-radio-button value="year">年付</el-radio-button>
              <el-radio-button value="month">月付</el-radio-button>
            </el-radio-group>
            <div style="font-size:12px;color:#909399">审核通过后开始计费；未付款前有 30 天试用期</div>
          </el-form-item>
```

`form` 对象（74 行）加 `'bill_cycle': 'year'`；`submit` 校验（112 行 `if (!f.plan_id)` 旁）加 `if (!f.bill_cycle) { return QG.warn('请选择付费周期'); }`。

- [ ] **步骤 4.3：驳回自动作废** —— `PlatformController::reject` 的状态守卫成功分支（`$n !== 0` 后、`self::audit` 前）加：

```php
        ControlDb::exec(
            "UPDATE saas_bill SET status = 'void', void_reason = '注册申请被驳回' WHERE tenant_id = ? AND status = 'unpaid'",
            [(int) $t['id']]
        );
```

- [ ] **步骤 4.4：验证 + Commit**

```bash
php83 tests/run.php 2>&1 | tail -1        # 预期 72 passed（注册既有用例不传 cycle 会红——见步骤 4.5）
```

既有注册用例没传 bill_cycle → 会 FAIL，属预期；**在 `tests/test_register.php` 与 `tests/test_platform_api.php` 的注册 payload 里各加 `'bill_cycle' => 'year'`**，再补一条断言（test_register.php 注册成功用例内）：

```php
    $b = ControlDb::one('SELECT cycle, amount, status, remark FROM saas_bill WHERE tenant_id = ? ORDER BY id DESC LIMIT 1', [$tid]);
    assert_eq('year', $b['cycle'] ?? '', '注册应生成第一张账单');
    assert_eq('unpaid', $b['status'] ?? '', '首张账单待支付');
```

（`$tid` 变量名以该文件既有代码为准；若注册用例用完即删租户，断言放在删除之前，并把 `saas_bill` 的清理加进其 finally/清理段：`ControlDb::exec('DELETE FROM saas_bill WHERE tenant_id = ?', [$tid]);`）

```bash
php83 tests/run.php 2>&1 | tail -1        # 预期 72 passed, 0 failed
git add api/src/controllers/TenantController.php api/src/controllers/PlatformController.php register.html tests/test_register.php tests/test_platform_api.php
git commit -m "feat: 注册选付费周期并生成第一张账单，驳回自动作废待付账单"
```

---

### 任务 5：审核联动 + 登录拦截 + 鉴权扩展

**文件：** 修改 `api/src/TenantProvisioner.php`（两条守卫 UPDATE）、`api/src/controllers/AuthController.php:26-56/76-81`、`api/src/Auth.php:104-123`

- [ ] **步骤 5.1：开通即试用开始** —— `TenantProvisioner::provision` 两条 UPDATE（shared 与 dedicated 分支）各加一列：

```php
                "UPDATE saas_tenant SET status = 'active', expires_at = NOW(), reviewed_at = NOW(), reviewed_by = ?, admin_password_hash = '', reject_reason = '' WHERE id = ? AND status = 'pending'",
```

dedicated 分支同款（保留其 `db_name = ?` 列）。

- [ ] **步骤 5.2：登录拦截** —— `AuthController::login` 的 rejected 分支之后、`!== 'active'` 分支之前加：

```php
        if ($t['expires_at'] !== null
            && \QG\Billing::state($t['expires_at'], true)['state'] === 'expired') {
            Response::fail('服务已到期，请联系平台续费');
        }
```

- [ ] **步骤 5.3：会话失效 + 带出 expires_at** —— `Auth::user()` 租户分支（`status !== 'active'` 判断之后）加：

```php
        if ($t['expires_at'] !== null
            && \QG\Billing::state($t['expires_at'], true)['state'] === 'expired') {
            self::$user = [];
            return null;
        }
```

同分支 `$u['tenant_logo'] = $t['logo_url'];` 之后加：

```php
        $u['expires_at'] = $t['expires_at'];
```

- [ ] **步骤 5.4：/auth/me 加 billing 字段** —— `AuthController::me` 改为：

```php
    public static function me(): void
    {
        $u = Auth::requireLogin();
        $u['permissions'] = Auth::permissionCodes();
        $u['billing'] = ['state' => 'none', 'days_left' => 0, 'trial' => false, 'expires_at' => $u['expires_at'] ?? null];
        if (($u['scope'] ?? 'tenant') === 'tenant' && !empty($u['expires_at'])) {
            $hasPaid = (bool) ControlDb::val(
                'SELECT COUNT(*) FROM saas_bill WHERE tenant_id = ? AND status = ?',
                [(int) $u['tenant_id'], 'paid']
            );
            $u['billing'] = \QG\Billing::state($u['expires_at'], $hasPaid) + ['expires_at' => $u['expires_at']];
        }
        Response::ok($u);
    }
```

- [ ] **步骤 5.5：验证 + Commit**

```bash
php83 tests/run.php 2>&1 | tail -1 && php83 tests/guard_no_raw_business_sql.php | tail -1   # 72 passed + guard ok
git add api/src/TenantProvisioner.php api/src/controllers/AuthController.php api/src/Auth.php
git commit -m "feat: 审核通过即试用开始，超宽限登录/会话拦截，/auth/me 带服务期状态"
```

---

### 任务 6：ControlDb 事务 + BillRepo + 租户账单接口

**文件：** 修改 `api/src/ControlDb.php`、`api/config/config.sample.php`；创建 `api/src/repos/BillRepo.php`、`api/src/controllers/BillController.php`；修改 `api/index.php:60` 附近

- [ ] **步骤 6.1：ControlDb 补事务** —— 类尾加（镜像 `Db.php` 对 Tenant::pdo 的写法）：

```php
    public static function begin(): void
    {
        if (!self::pdo()->inTransaction()) { self::pdo()->beginTransaction(); }
    }

    public static function commit(): void
    {
        if (self::pdo()->inTransaction()) { self::pdo()->commit(); }
    }

    public static function rollback(): void
    {
        if (self::pdo()->inTransaction()) { self::pdo()->rollBack(); }
    }
```

- [ ] **步骤 6.2：BillRepo** —— 创建 `api/src/repos/BillRepo.php`（全文）：

```php
<?php
namespace QG\repos;

use QG\ControlDb;

/** 账单仓储（控制面 saas_bill）。租户侧入口一律传 Tenant::requireId() 的 id 并绑定 tenant_id。 */
class BillRepo
{
    public static function forTenant(int $tid, int $limit = 100): array
    {
        return ControlDb::all(
            'SELECT id, cycle, amount, status, created_at, paid_at, void_reason, remark
               FROM saas_bill WHERE tenant_id = ? ORDER BY id DESC LIMIT ' . (int) $limit,
            [$tid]
        );
    }

    public static function hasPaid(int $tid): bool
    {
        return (bool) ControlDb::val('SELECT COUNT(*) FROM saas_bill WHERE tenant_id = ? AND status = ?', [$tid, 'paid']);
    }

    public static function create(int $tid, string $cycle, float $amount, string $remark = ''): int
    {
        return ControlDb::insert('saas_bill', [
            'tenant_id' => $tid, 'cycle' => $cycle, 'amount' => $amount,
            'status' => 'unpaid', 'remark' => $remark,
        ]);
    }

    public static function findUnpaid(int $billId): ?array
    {
        $r = ControlDb::one('SELECT * FROM saas_bill WHERE id = ? AND status = ? LIMIT 1', [$billId, 'unpaid']);
        return $r ?: null;
    }
}
```

- [ ] **步骤 6.3：BillController** —— 创建 `api/src/controllers/BillController.php`（全文）：

```php
<?php
namespace QG\controllers;

use QG\Auth;
use QG\ControlDb;
use QG\Req;
use QG\Response;
use QG\Router;
use QG\Tenant;
use QG\repos\BillRepo;

/** 租户侧账单中心：仅租户管理员可见（本租户账单 + 自助续费生成待付账单）。 */
class BillController
{
    public static function routes(Router $r): void
    {
        $r->get('/bills', [self::class, 'list']);
        $r->post('/bills', [self::class, 'renew']);
    }

    public static function list(): void
    {
        Auth::requireLogin();
        if (!Auth::isTenantAdmin()) {
            Response::fail('仅租户管理员可查看账单', 403, 403);
        }
        Response::ok(BillRepo::forTenant(Tenant::requireId()));
    }

    public static function renew(): void
    {
        Auth::requireLogin();
        if (!Auth::isTenantAdmin()) {
            Response::fail('仅租户管理员可续费', 403, 403);
        }
        $cycle = Req::str('cycle');
        if (!in_array($cycle, ['month', 'year'], true)) {
            Response::fail('请选择月付或年付');
        }
        $tid = Tenant::requireId();
        $planId = (int) ControlDb::val('SELECT plan_id FROM saas_tenant WHERE id = ?', [$tid]);
        if (!$planId) {
            Response::fail('当前租户未关联套餐，请联系平台');
        }
        $col = $cycle === 'month' ? 'price_month' : 'price_year';
        $amount = (float) ControlDb::val("SELECT {$col} FROM saas_plan WHERE id = ?", [$planId]);
        BillRepo::create($tid, $cycle, $amount, '自助续费');
        Response::ok(null, '账单已生成，转账后请联系平台确认');
    }
}
```

- [ ] **步骤 6.4：路由注册** —— `api/index.php` 在 `\QG\controllers\TenantController::routes($router);`（60 行）之后加：

```php
\QG\controllers\BillController::routes($router);
```

- [ ] **步骤 6.5：config 样例** —— `api/config/config.sample.php` 的 `app` 段加（`page_size` 前）：

```php
        'billing_notice' => '对公账户：户名/账号/开户行（部署时填写）· 汇款请备注租户代码',
```

dev 与 breeder 的真实 `config.php` 用程序化插入补同一键（先 `cp api/config/config.php /tmp/config.php.pre_billing.bak`，再 `php83 -r` 读改写，**不回显任何值**；dev 的收款信息可先填占位文案，由用户后续替换）。

- [ ] **步骤 6.6：验证 + Commit**

```bash
php83 tests/run.php 2>&1 | tail -1 && php83 tests/guard_no_raw_business_sql.php | tail -1   # 72 passed + guard ok
git add api/src/ControlDb.php api/src/repos/BillRepo.php api/src/controllers/BillController.php api/index.php api/config/config.sample.php
git commit -m "feat: 租户账单接口（/api/bills 查看+自助续费）与 ControlDb 事务"
```

---

### 任务 7：平台账单接口 + 租户列表有效期列

**文件：** 修改 `api/src/controllers/PlatformController.php`（routes/tenants/reject 附近新增三个方法）

- [ ] **步骤 7.1：路由** —— `$r->get('/platform/audits', ...)` 后加：

```php
        $r->get('/platform/bills', [self::class, 'bills']);
        $r->post('/platform/bills/{id}/confirm', [self::class, 'confirmBill']);
        $r->post('/platform/bills/{id}/void', [self::class, 'voidBill']);
```

文件头 `use` 加 `QG\Billing;` 与 `QG\repos\BillRepo;`。

- [ ] **步骤 7.2：三个方法** —— `audits()` 后加：

```php
    /** 全局账单列表：可按状态与租户代码筛选；未知租户代码恒空集（与 audits 同语义）。 */
    public static function bills(): void
    {
        Auth::requirePlatform();
        [$page, $size, $offset] = Req::page();
        $where = '1=1';
        $bind  = [];
        $status = Req::str('status');
        if (in_array($status, ['unpaid', 'paid', 'void'], true)) {
            $where .= ' AND b.status = ?';
            $bind[] = $status;
        }
        $code = strtolower(trim(Req::str('tenant')));
        if ($code !== '') {
            $tid = ControlDb::val('SELECT id FROM saas_tenant WHERE code = ? LIMIT 1', [$code]);
            if (!$tid) {
                Response::page([], 0, $page, $size);
            }
            $where .= ' AND b.tenant_id = ?';
            $bind[] = (int) $tid;
        }
        $total = (int) ControlDb::val("SELECT COUNT(*) FROM saas_bill b WHERE {$where}", $bind);
        $rows  = ControlDb::all(
            "SELECT b.id, b.tenant_id, t.code AS tenant_code, t.name AS tenant_name, b.cycle, b.amount,
                    b.status, b.created_at, b.paid_at, b.void_reason, b.remark
               FROM saas_bill b LEFT JOIN saas_tenant t ON t.id = b.tenant_id
              WHERE {$where} ORDER BY b.id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        Response::page($rows, $total, $page, $size);
    }

    /** 确认收款：账单 paid（状态守卫）+ 服务期重算（Billing 三分支），两写原子。 */
    public static function confirmBill(array $p): void
    {
        $admin = Auth::requirePlatform();
        $bill = BillRepo::findUnpaid((int) ($p['id'] ?? 0));
        if (!$bill) {
            Response::fail('账单不存在或不是待支付状态');
        }
        $t = ControlDb::one('SELECT * FROM saas_tenant WHERE id = ? LIMIT 1', [(int) $bill['tenant_id']]);
        if (!$t || $t['status'] !== 'active') {
            Response::fail('租户未开通，暂不能确认收款');
        }
        $ref = Req::str('ref');
        $newExp = '';
        ControlDb::begin();
        try {
            $n = ControlDb::exec(
                "UPDATE saas_bill SET status = 'paid', paid_at = NOW(), paid_channel = 'offline', paid_ref = ?, confirmed_by = ? WHERE id = ? AND status = 'unpaid'",
                [$ref, (int) $admin['id'], (int) $bill['id']]
            );
            if ($n === 0) {
                throw new \RuntimeException('账单状态已变化，请刷新后重试');
            }
            $newExp = Billing::nextExpiry($bill['cycle'], $t['expires_at'], time());
            ControlDb::exec('UPDATE saas_tenant SET expires_at = ? WHERE id = ?', [$newExp, (int) $t['id']]);
            ControlDb::commit();
        } catch (\Throwable $e) {
            ControlDb::rollback();
            Response::fail('确认收款失败：' . $e->getMessage());
        }
        self::audit((int) $admin['id'], 'bill_paid', (int) $bill['tenant_id'],
            '账单 #' . $bill['id'] . '（' . ($bill['cycle'] === 'month' ? '月付' : '年付') . ' ¥' . $bill['amount'] . '）确认收款，服务期至 ' . $newExp);
        Response::ok(['expires_at' => $newExp], '已确认收款');
    }

    public static function voidBill(array $p): void
    {
        $admin = Auth::requirePlatform();
        $reason = Req::require_('reason', '作废原因');
        if (mb_strlen($reason) > 200) {
            Response::fail('作废原因不能超过 200 字');
        }
        $n = ControlDb::exec(
            "UPDATE saas_bill SET status = 'void', void_reason = ? WHERE id = ? AND status = 'unpaid'",
            [$reason, (int) ($p['id'] ?? 0)]
        );
        if ($n === 0) {
            Response::fail('账单不存在或不是待支付状态');
        }
        $bill = ControlDb::one('SELECT tenant_id, cycle, amount FROM saas_bill WHERE id = ? LIMIT 1', [(int) ($p['id'] ?? 0)]);
        self::audit((int) $admin['id'], 'bill_void', (int) $bill['tenant_id'],
            '账单 #' . ($p['id'] ?? 0) . '（' . ($bill['cycle'] === 'month' ? '月付' : '年付') . ' ¥' . $bill['amount'] . '）作废：' . $reason);
        Response::ok(null, '已作废');
    }
```

- [ ] **步骤 7.3：tenants 列表带出有效期** —— `tenants()` SELECT 的 `t.db_name,` 后加 `t.expires_at,`。

- [ ] **步骤 7.4：验证 + Commit**

```bash
php83 tests/run.php 2>&1 | tail -1 && php83 tests/guard_no_raw_business_sql.php | tail -1   # 72 passed + guard ok
git add api/src/controllers/PlatformController.php
git commit -m "feat: 平台账单接口（列表/确认收款/作废）与租户列表有效期"
```

---

### 任务 8：前端三件（billing 页 / platform 账单视图 / 外壳条幅）

**文件：** 创建 `pages/billing.html`；修改 `platform.html`、`index.html:50-51/118-131/161-171`

- [ ] **步骤 8.1：pages/billing.html** —— 创建（全文；照 pages 现有页的 4-script 顺序 + QG.mount 模式）：

```html
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>账单中心</title>
<link rel="icon" href="/favicon.ico">
<link rel="stylesheet" href="/assets/vendor/element-plus.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div id="app" v-cloak>
<div class="page" v-loading="loading">
  <div class="page-head">
    <h2 style="margin:0">账单中心</h2>
    <div class="spacer"></div>
    <el-button type="primary" @click="renewVisible = true">续费</el-button>
  </div>
  <div class="card">
    <el-alert v-if="notice" :title="notice" type="info" :closable="false" style="margin-bottom:12px"></el-alert>
    <el-table :data="rows">
      <el-table-column prop="id" label="账单号" width="90"></el-table-column>
      <el-table-column label="周期" width="90">
        <template #default="s">{{ s.row.cycle === 'month' ? '月付' : '年付' }}</template>
      </el-table-column>
      <el-table-column label="金额" width="110">
        <template #default="s">¥{{ s.row.amount }}</template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="s">
          <el-tag :type="{ unpaid: 'warning', paid: 'success', void: 'info' }[s.row.status] || 'info'">
            {{ { unpaid: '待支付', paid: '已支付', void: '已作废' }[s.row.status] || s.row.status }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="created_at" label="生成时间" width="160"></el-table-column>
      <el-table-column prop="paid_at" label="支付时间" width="160">
        <template #default="s">{{ s.row.paid_at || '—' }}</template>
      </el-table-column>
      <el-table-column prop="remark" label="备注" min-width="140"></el-table-column>
    </el-table>
  </div>
  <el-dialog v-model="renewVisible" title="续费" width="420px">
    <el-form label-width="90px">
      <el-form-item label="续费周期">
        <el-radio-group v-model="renewCycle">
          <el-radio-button value="year">年付</el-radio-button>
          <el-radio-button value="month">月付</el-radio-button>
        </el-radio-group>
      </el-form-item>
    </el-form>
    <div style="color:#909399;font-size:12px;line-height:1.8">{{ notice }}</div>
    <template #footer>
      <el-button @click="renewVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="doRenew">生成账单</el-button>
    </template>
  </el-dialog>
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
      return { rows: [], loading: false, saving: false, renewVisible: false, renewCycle: 'year', notice: '' };
    },
    created: function () {
      var self = this;
      QG.get('/auth/me').then(function (u) {
        self.notice = (u && u.scope === 'tenant') ? (window.billingNotice || '请联系平台获取收款账户信息') : '';
      }).catch(function () {});
      this.load();
    },
    methods: {
      load: function () {
        var self = this;
        self.loading = true;
        QG.get('/bills').then(function (d) { self.rows = d || []; })
          .catch(function (e) { QG.err(e); })
          .finally(function () { self.loading = false; });
      },
      doRenew: function () {
        var self = this;
        self.saving = true;
        QG.post('/bills', { cycle: self.renewCycle })
          .then(function () {
            QG.toast('账单已生成，转账后请联系平台确认');
            self.renewVisible = false;
            self.load();
          })
          .catch(function (e) { QG.err(e); })
          .finally(function () { self.saving = false; });
      }
    }
  };
});
</script>
</body>
</html>
```

注意：`window.billingNotice` 由「任务 8.3 的注入」提供——**本页不新增后端配置接口**，notice 文案在 `index.html` 外壳与账单页共享同一来源。为避免额外接口，账单页直接用兜底文案即可（`window.billingNotice` 未定义时走「请联系平台获取收款账户信息」）；部署者可在 `config.php` 的 `billing_notice` 里写全账户信息并同步到 `index.html` 的条幅 tooltip。若评审认为必须动态下发，改为 `GET /auth/me` 的 `billing.notice`（后端读 `App::config('app')['billing_notice']`）——**采用后者**：`me()` 的 billing 数组再加 `'notice' => (string) App::config('app')['billing_notice']`（任务 5.4 的代码已定型，在本任务补这一行），本页与条幅都从 `me()` 取，删除 `window.billingNotice` 兜底逻辑。

- [ ] **步骤 8.2：platform.html 账单视图** —— 三处：
  ① 视图切换组加 `<el-radio-button value="bills">账单</el-radio-button>`（放「审计日志」后）；
  ② 现「审计日志」卡片 `v-else` 改 `v-else-if="view === 'audits'"`，其后新增账单卡片：

```html
  <div class="card" v-else-if="view === 'bills'" v-loading="bLoading">
    <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center">
      <el-select v-model="bStatus" placeholder="全部状态" clearable style="width:140px"
        @change="function () { bq.page = 1; loadBills(); }">
        <el-option value="unpaid" label="待支付"></el-option>
        <el-option value="paid" label="已支付"></el-option>
        <el-option value="void" label="已作废"></el-option>
      </el-select>
      <el-input v-model="bTenant" placeholder="按租户代码过滤" clearable style="width:180px"
        @keyup.enter="function () { bq.page = 1; loadBills(); }"
        @clear="function () { bq.page = 1; loadBills(); }"></el-input>
      <el-button type="primary" plain @click="function () { bq.page = 1; loadBills(); }">查询</el-button>
    </div>
    <el-table :data="bRows">
      <el-table-column prop="id" label="账单号" width="90"></el-table-column>
      <el-table-column label="租户" min-width="170">
        <template #default="s">{{ s.row.tenant_code ? (s.row.tenant_code + ' · ' + s.row.tenant_name) : '—' }}</template>
      </el-table-column>
      <el-table-column label="周期" width="80">
        <template #default="s">{{ s.row.cycle === 'month' ? '月付' : '年付' }}</template>
      </el-table-column>
      <el-table-column label="金额" width="100">
        <template #default="s">¥{{ s.row.amount }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="s">
          <el-tag :type="{ unpaid: 'warning', paid: 'success', void: 'info' }[s.row.status] || 'info'">
            {{ { unpaid: '待支付', paid: '已支付', void: '已作废' }[s.row.status] || s.row.status }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="created_at" label="生成时间" width="160"></el-table-column>
      <el-table-column prop="paid_at" label="支付时间" width="160">
        <template #default="s">{{ s.row.paid_at || '—' }}</template>
      </el-table-column>
      <el-table-column label="操作" width="170" fixed="right">
        <template #default="s">
          <template v-if="s.row.status === 'unpaid'">
            <el-button size="small" type="primary" @click="confirmBill(s.row)">确认收款</el-button>
            <el-button size="small" type="danger" plain @click="voidBill(s.row)">作废</el-button>
          </template>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination style="margin-top:12px;justify-content:flex-end" layout="total, prev, pager, next"
      :total="bTotal" :page-size="bq.pageSize" :current-page="bq.page"
      @current-change="function (p) { bq.page = p; loadBills(); }"></el-pagination>
  </div>
```

  ③ data 加 `bRows: [], bTotal: 0, bLoading: false, bStatus: '', bTenant: '', bq: { page: 1, pageSize: 20 }`；`onView` 改为 `if (this.view === 'audits') { this.loadAudits(); } if (this.view === 'bills') { this.loadBills(); }`；methods 加：

```js
      loadBills: function () {
        var self = this;
        self.bLoading = true;
        QG.get('/platform/bills', {
          status: self.bStatus, tenant: (self.bTenant || '').trim(),
          page: self.bq.page, pageSize: self.bq.pageSize
        }).then(function (d) { self.bRows = d.list || []; self.bTotal = d.total || 0; })
          .catch(function (e) { QG.err(e); })
          .finally(function () { self.bLoading = false; });
      },
      confirmBill: function (row) {
        var self = this;
        QG.confirm('确认已收到「' + row.tenant_code + '」' + (row.cycle === 'month' ? '月付' : '年付') + ' ¥' + row.amount + '？确认后服务期立即顺延。').then(function () {
          QG.post('/platform/bills/' + row.id + '/confirm', {})
            .then(function () { QG.toast('已确认收款'); self.loadBills(); })
            .catch(function (e) { QG.err(e); });
        }).catch(function () {});
      },
      voidBill: function (row) {
        ElementPlus.ElMessageBox.prompt('请输入作废原因', '作废账单 #' + row.id, {
          inputPattern: /\S+/, inputErrorMessage: '原因不能为空'
        }).then(function (r) {
          QG.post('/platform/bills/' + row.id + '/void', { reason: r.value })
            .then(function () { QG.toast('已作废'); self.loadBills(); })
            .catch(function (e) { QG.err(e); });
        }).catch(function () {});
      },
```

  ④ 租户表「隔离档」列后加有效期列：

```html
      <el-table-column label="有效期" width="170">
        <template #default="s">{{ expiryText(s.row) }}</template>
      </el-table-column>
```

methods 加：

```js
      expiryText: function (row) {
        if (!row.expires_at) { return '—'; }
        var d = Math.ceil((new Date(row.expires_at).getTime() - Date.now()) / 86400000);
        if (d > 30) { return row.expires_at.slice(0, 10); }
        if (d > 0) { return row.expires_at.slice(0, 10) + '（剩 ' + d + ' 天）'; }
        var g = d + 30;
        return g > 0 ? row.expires_at.slice(0, 10) + '（宽限剩 ' + g + ' 天）' : row.expires_at.slice(0, 10) + '（已过期）';
      },
```

- [ ] **步骤 8.3：index.html 条幅** —— `.main` 的 `<div class="topbar">`（51 行）前插入：

```html
      <el-alert v-if="billTip" :title="billTip" :type="billTipType" :closable="billTipClosable"
        @close="billTipClosed = true" style="border-radius:0"></el-alert>
```

data（128 行 `pwdVisible` 旁）加 `billTipClosed: false,`；computed（`crumbs` 后）加：

```js
      billTip: function () {
        var b = this.user && this.user.billing;
        if (!b || this.billTipClosed) { return ''; }
        if (b.state === 'soon') { return '会员剩余 ' + b.days_left + ' 天，请及时续费（左侧「账单中心」可自助续费）'; }
        if (b.state === 'grace') {
          return b.trial
            ? '试用期剩余 ' + b.days_left + ' 天，付款后服务期自审核日起算（左侧「账单中心」可自助续费）'
            : '服务已到期，宽限剩余 ' + b.days_left + ' 天，请尽快续费';
        }
        return '';
      },
      billTipType: function () {
        var b = this.user && this.user.billing;
        return b && b.state === 'grace' ? 'error' : 'warning';
      },
      billTipClosable: function () {
        var b = this.user && this.user.billing;
        return !(b && b.state === 'grace');
      },
```

（`index.html` 不再需要 `window.billingNotice`——条幅文案不含账户信息，账户信息在账单中心页从 `/auth/me` 的 `billing.notice` 取。）

- [ ] **步骤 8.4：验证 + Commit**

```bash
python3 - <<'EOF'
import re,subprocess,tempfile
for f in ['pages/billing.html','platform.html','index.html']:
    html=open(f).read()
    for i,s in enumerate(re.findall(r'<script>(.*?)</script>', html, re.S)):
        t=tempfile.NamedTemporaryFile('w',suffix='.js',delete=False); t.write(s); t.close()
        r=subprocess.run(['node','--check',t.name]); assert r.returncode==0, f+':'+str(i)
print('JS_OK')
EOF
php83 tests/run.php 2>&1 | tail -1
git add pages/billing.html platform.html index.html
git commit -m "feat: 租户账单中心页、平台账单视图与外壳服务期条幅"
```

---

### 任务 9：HTTP 测试 + 收尾（文档/版本/验收/发布）

**文件：** 创建 `tests/test_billing_http.php`；修改 `AGENTS.md`、`version.json`

- [ ] **步骤 9.1：HTTP 全链路用例** —— 创建 `tests/test_billing_http.php`（骨架照 `tests/test_platform_api.php` 的 platform_jar/qg_post_json 惯例；合成租户 id 910xxx 段，finally 清理 `saas_bill`+`saas_tenant`+审计+数据面种子）：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

use QG\ControlDb;

function bh_cleanup(int $tid): void {
    QG\Tenant::resetPools();
    QG\ControlDb::exec('DELETE FROM saas_bill WHERE tenant_id = ?', [$tid]);
    QG\ControlDb::exec('DELETE FROM saas_audit WHERE target_type = ? AND target_id = ?', ['tenant', $tid]);
    ControlDbDelTenant($tid);
}
function ControlDbDelTenant(int $tid): void {
    QG\ControlDb::exec('DELETE FROM saas_tenant WHERE id = ?', [$tid]);
}
```

（实现者按 test_platform_api.php 的既有清理写法补齐；数据面种子行清理在租户 provision 后才存在——HTTP 用例推荐**不真正 provision**，直接以 SQL 造 active 租户行来测各接口，避免播种/清库成本。）用例清单：

1. `t('账单：pending 租户确认收款被拒')` —— SQL 造 pending 租户 + unpaid 账单 → 平台 confirm → 断言「租户未开通」；账单仍 unpaid。
2. `t('账单：确认收款后到期日=审核日+周期，重复确认被拒')` —— SQL 造 active 租户（expires_at=NULL→先手工置为审核日）+ unpaid 月付账单 → confirm → 断言 `"code":0` 且响应 expires_at ≈ 审核日+1 月；账单 paid；**再次** confirm → 非 0。
3. `t('账单：自助续费生成待付账单（仅管理员）')` —— 沿用 `test_platform_api.php` 的「审核端到端」产出的真实管理员（或 SQL 造 active 租户 + 数据面管理员，参照 test_provision 的播种方式）→ 管理员登录 → POST /api/bills {cycle:'month'} → 断言 unpaid 新账单 + 金额=套餐月价；非管理员登录同租户 → 403。
4. `t('账单：过期租户登录被拦截')` —— SQL 造 active 租户 expires_at = now-31 天 + 管理员（数据面播种或复用）→ POST /api/auth/login → 断言「服务已到期」；/api/auth/me（若已有会话 cookie 场景）→ 401 语义由单测/集成保证，此处至少断言登录拦截。
5. `t('账单：租户 token 只能读本租户账单')` —— 两个 active 租户各带 unpaid 账单 → A 的管理员 GET /api/bills → 断言只见 A 的账单号。

- [ ] **步骤 9.2：全量回归 + 计数更新** —— `php83 tests/run.php 2>&1 | tail -1`（记录最终数字，预期 75+N）；`AGENTS.md` 三处基线计数（35/36/162 行的 69 → 实际值）。

- [ ] **步骤 9.3：手工验收（dev，浏览器 + curl 混合，记录结论到本计划末尾）**

1. 注册新租户（选月付）→ 平台列表看到 `bill_cycle`；控制面 `saas_bill` 有 unpaid 注册账单、amount=套餐月价；
2. 平台通过 → 租户登录 → 外壳顶部红条幅「试用期剩余 30 天…」→ 账单中心可见账单 + 汇款说明；
3. 平台账单视图确认收款 → 到期日 = 审核日 + 1 月（三分支-试用转正）→ 租户刷新后条幅消失；
4. SQL 把该租户 `expires_at` 改到 15 天后 → 刷新出琥珀条幅（剩余 15 天）；
5. SQL 改到 31 天前 → 重新登录被拦「服务已到期」→ 平台确认一张年付账单 → 能登录、到期日 = 付款日 + 1 年；
6. 清理全部合成数据。

- [ ] **步骤 9.4：version bump + AGENTS.md 口径**

```bash
git add tests/test_billing_http.php AGENTS.md && git commit -m "test: 账单全链路 HTTP 用例 + AGENTS.md 基线计数"
BUILD=$(git rev-parse --short=10 HEAD) && TIME=$(date '+%Y-%m-%d %H:%M') && python3 - "$BUILD" "$TIME" <<'EOF'
import json,sys
d=json.load(open('version.json'))
d['build']=sys.argv[1]; d['time']=sys.argv[2]
json.dump(d,open('version.json','w'),ensure_ascii=False,indent=2)
open('version.json','a').write('\n')
EOF
git add version.json && git commit -m "chore: version bump（$BUILD）"
```

AGENTS.md 口径更新点：§6 数据库节补「账单与服务期」段（saas_bill 状态机、三分支重算引用规格、demo 租户 NULL 语义）；§9 已知坑补一条「`saas_bill` 确认收款必须走 PlatformController::confirmBill 的事务路径，手工改库会造成账单与到期日不一致」。

- [ ] **步骤 9.5：发布 breeder（发布顺序：控制面迁移 → 菜单迁移 → 代码 → 配置 → 验证）**

```bash
# 1) 备份
ssh breeder.sflswall.com.cn 'sudo sh -c "mysqldump -u root --databases qingguan qingguan_control --single-transaction > /var/backups/qingguan/pre-billing-$(date +%Y%m%d).sql"'
# 2) 控制面迁移（scp 后执行 + 幂等复跑）
scp db/migration_20261016_control_billing.sql db/migration_20261016_menu_billing.sql breeder.sflswall.com.cn:/tmp/
ssh breeder.sflswall.com.cn 'sudo mysql -u root qingguan_control < /tmp/migration_20261016_control_billing.sql && sudo mysql -u root qingguan_control < /tmp/migration_20261016_control_billing.sql && sudo mysql -u root qingguan < /tmp/migration_20261016_menu_billing.sql && sudo mysql -u root qingguan < /tmp/migration_20261016_menu_billing.sql && echo MIGRATIONS_OK'
# 3) rsync 发布（排除清单与既有发布一致）+ reload
# 4) config.php 补 billing_notice（程序化插入，不回显）
# 5) 验证：version.json 新 build；未登录 /api/bills → 401；平台登录后 /api/platform/bills → code 0
```

- [ ] **步骤 9.6：合并 main** —— `git checkout main && git merge --ff-only feat/billing-expiry && git push origin main`（分支保留不删）。

---

## 自检记录

- **规格覆盖度**：§2 数据模型 → 任务 2/3；§3 状态机与三分支 → 任务 1；§4.1 注册/驳回 → 任务 4；§4.2 审核联动 → 任务 5；§4.3 租户侧（条幅/账单中心/接口）→ 任务 6/8；§4.4 平台侧 → 任务 7/8；§4.5 鉴权链 → 任务 5；§5 测试 → 任务 1/9；§6 风险（控制面迁移双跑、菜单幂等键 uk_tenant_code 实证存在、时区用 DB/app 一致时间、试用=宽限实现耦合说明）→ 任务 2/3/9 步骤与代码注释。
- **占位符**：无。任务 9.1 的用例骨架给了完整结构，实现者按既有惯例补工具函数（计划明示参照文件）。
- **类型一致性**：`Billing::state(?string,bool,int=null):array{state,days_left,trial}` 与 `nextExpiry(string,?string,int):string` 在任务 5/7 调用一致；`BillRepo::forTenant/hasPaid/create/findUnpaid` 在任务 6/7 一致；`saas_bill` 列名与任务 2 DDL 一致。
- **基线计数**：写死「72 passed」为任务 1 完成后值（69+3，run.php 按 t() 组计数），任务 4/9 新增用例后以实跑为准并同步 AGENTS.md（步骤 9.2）。

## 执行记录

（执行时逐任务追加：日期 + commit + 结果/问题）
