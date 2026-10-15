# 多租户 SaaS 化 · 运维基础设施 实现计划

> **面向 AI 代理的工作者：** 必需子技能：使用 superpowers:subagent-driven-development（推荐）或 superpowers:executing-plans 逐任务实现此计划。步骤使用复选框（`- [ ]`）语法来跟踪进度。

**目标：** 在一期地基（控制面 + 仓储层 + 租户代码登录 + 共享档隔离，已完成）之后、自助开通之前，先把两件「多租户化必然会遇到、越晚做越贵」的运维能力做掉：**多库统一的表结构升级**（第 15 节）与**平台字典统一下发**（第 14 节）。

**为什么现在是唯一的好时机：** 目前生产与本机都只有「共享库 + 1 个租户」，物理隔离档的专用库一台都还没有。此时把迁移机制与版本表立起来，迁移成本是「归置两个文件 + 回填一行版本」；等到二期有了 N 个专用库再补，就要在 N 个库上做考古。

**技术栈：** PHP 8.3 原生（无框架、无 composer）+ MySQL 8.0 / MariaDB 10.5（两边都要能跑）。沿用一期的极简断言器（`php83 tests/run.php`）。

**前置：** 一期地基计划（`2026-10-14-multi-tenant-phase1-foundation.md`）已执行完毕。本计划的分支从一期的 `feat/multi-tenant-foundation` 切出。

---

## 文件结构

**新建**
- `db/migrations/0001_multitenant.sql`、`db/migrations/0002_role_rename.sql` —— 一期两个迁移归一到序号目录（内容不变）。
- `db/migrations/0003_dict_source.sql` —— `sys_dict` 加 `source` / `template_version`。
- `db/migrations/0004_schema_version.sql` —— 各数据面库的 `schema_version` 表（由执行器自举，不用手工跑）。
- `db/control/migration_0001_dict_template.sql` —— 控制面 `saas_dict_template`。
- `db/control/seed_dict_template.sql` —— 从现有租户 #1 的 `sys_dict` 导出平台模板种子（幂等）。
- `scripts/fanout.php` —— **多库执行器**：列出所有数据面库 → 按序执行 → 逐库记录结果 → 失败即停。
- `scripts/migrate.php` —— 表结构迁移入口（`--dry-run` / `--only=` / `--to=` / 自动备份）。
- `scripts/export_dict_template.php` —— 把租户 #1 的 `sys_dict` 导成模板种子 SQL。
- `scripts/publish_dict.php` —— 字典下发入口（`--dry-run` 出差异预览 / `--apply` 真发）。
- `api/src/repos/DictTemplateRepo.php` —— 控制面模板读写。
- `api/src/DictPublisher.php` —— 差异计算 + 发布（调 `fanout` 的执行器部分）。
- `tests/test_schema_version.php`、`tests/test_dict_template.php`、`tests/test_dict_publish.php`、`tests/test_fanout.php`

**修改**
- `api/src/repos/DictRepo.php` —— 列表带 `source`；租户自建项的写入打 `source='tenant'`；平台项不可被租户覆盖。
- `api/src/controllers/SystemController.php` —— 字典增删改的「平台项置灰」判定。
- `pages/sys-dict.html` —— 平台下发项显示「平台」标签、编辑/删除置灰。
- `docs/superpowers/specs/2026-10-14-multi-tenant-saas-design.md` —— 实施后把第 14/15 节状态改为「已实现」。
- ~~`db/schema.sql` —— 头部注释改口：它是单租户单机版基线，只作参考，新库一律由 `db/migrations/` 建。~~
  > **【2026-10-15 已反转为：已完成】** `db/schema.sql` 反而升格为数据面结构**唯一权威**（新库灌它、结构变更同步它、`tests/test_schema_parity.php` 兜底一致）。本条不再做，新库走 schema.sql，旧库走 migrations，两者靠 parity 测试汇合。

**明确不改**
- 一期已交付的仓储层与守卫规则（本计划的所有新 SQL 同样受守卫约束）。
- 平台后台的 HTTP 页面（登录页/审核页）——属于「自助开通与品牌」计划；本计划的平台侧入口先用 CLI（`scripts/publish_dict.php`），HTTP 入口在那边接。

---

## 阶段 1：把「迁移」立成一等公民

### 任务 1：迁移目录归一 + `schema_version`

**文件：**
- 新建：`db/migrations/0001_multitenant.sql`、`db/migrations/0002_role_rename.sql`
- 修改：`docs/superpowers/specs/2026-10-14-multi-tenant-saas-design.md`（第 15 节补一句「已落地」）

- [ ] **步骤 1：搬文件**

```bash
cd /data/qingguan/app
git mv db/migration_20261015_multitenant.sql db/migrations/0001_multitenant.sql
git mv db/migration_20261015_role_rename.sql  db/migrations/0002_role_rename.sql
```

`db/control/schema.sql` 与 `db/control/seed_tenant1.sql` 不动（控制面结构量小，且只在装机时跑一次）。

- [ ] **步骤 2：改所有引用**

搜 `migration_20261015`：`docs/superpowers/plans/2026-10-14-multi-tenant-phase1-foundation.md`（任务 3/15 与执行记录里的命令）与执行记录都要改成新路径，并注明「旧路径已归一」。

- [ ] **步骤 3：Commit**

```bash
git add -A db/migrations db/migration_20261015_multitenant.sql db/migration_20261015_role_rename.sql docs
git commit -m "chore: 迁移脚本归一到 db/migrations/ 序号目录"
```

### 任务 2：多库执行器 `scripts/fanout.php`

**目的：** 把「数据面有哪些库、按什么顺序、每库怎么记结果」做成一个可复用的执行器。迁移（本计划）和字典下发（本计划）以及将来的建库/播种（二期）都调它。

**文件：**
- 创建：`scripts/fanout.php`
- 测试：`tests/test_fanout.php`

约定（实现照此，不要临时发明）：

```php
namespace QG\Scripts;

class Fanout
{
    /** 目标清单：顺序固定 = 控制面库 → 共享库 → 各专用库（db_name 升序） */
    public static function targets(): array
    // 返回 [['kind'=>'control','name'=>'qingguan_control','pdo'=>PDO], ['kind'=>'shared','name'=>'qingguan',...], ['kind'=>'dedicated',...]]

    /**
     * 逐库执行。$runner 收到 ($target, $pdo)，返回 ['ok'=>bool,'note'=>string]。
     * 任一库返回 ok=false 立即停止；已完成的库不回滚（因为每步都幂等）。
     * 返回 [['target'=>string,'ok'=>bool,'note'=>string], ...]
     */
    public static function run(callable $runner, callable $log = null): array
}
```

- 专用库来源：控制面 `SELECT code, db_name FROM saas_tenant WHERE mode='dedicated' AND status='active' AND db_name<>''`。二期之前这条查询恒为空，执行器要能正确处理「0 个专用库」。
- 连接：共享库用 `App::config('db')`，控制面用 `App::config('control_db')`，专用库在共享库的连接参数上换 `dbname`（同一个 MySQL 账号要有权限，二期再引入每库独立账号）。
- 连不上的库 → 该目标 `ok=false`，停止。

- [ ] **步骤 1：写失败测试**

`tests/test_fanout.php`：断言「目标顺序是 控制面 在前、共享库第二」；「runner 返回 false 时，后面的目标不再被调用」（用一个计数器验证）。

- [ ] **步骤 2：实现 `scripts/fanout.php`**

- [ ] **步骤 3：跑测试** `php83 tests/run.php` → 新增用例 PASS。

- [ ] **步骤 4：Commit** `git commit -m "feat: 增加多库执行器 Fanout"`

### 任务 3：`scripts/migrate.php`

**文件：**
- 创建：`scripts/migrate.php`、`db/migrations/0004_schema_version.sql`（内容只有 `CREATE TABLE IF NOT EXISTS schema_version`，由执行器在每个库上自举）
- 测试：`tests/test_schema_version.php`

行为（逐条都要有）：

1. 目标与顺序复用 `Fanout::targets()`。
2. 每个库：先自举 `schema_version`（幂等建表），读 `MAX(version)`。
3. 列出 `db/migrations/*.sql` 里序号 > 当前版本的文件，按序号升序逐个执行；**每个文件执行成功后立刻** `INSERT INTO schema_version(version, note, applied_at)`。失败即停，打印「库名 / 文件 / 错误」。
4. `--dry-run`：只打印每个库缺哪些文件，不执行、不建表。
5. `--only=<db_name>`：只跑指定库。
6. `--to=<NNNN>`：只跑到指定序号。
7. 执行前对每个目标库 `mysqldump` 到 `db/backup/<库名>_<YmdHis>.sql.gz`；`--no-backup` 可跳过（本机 dev 用）。
8. 已跑过一期迁移的老库（本机 dev、生产共享库）要能**回填**：`--baseline 2` 表示「把 1、2 号迁移记为已应用，不执行」，用于给已经有这些结构的历史库补版本行。

- [ ] **步骤 1：写 `0004_schema_version.sql`**

```sql
CREATE TABLE IF NOT EXISTS `schema_version` (
  `version`    INT UNSIGNED NOT NULL,
  `note`       VARCHAR(200) NOT NULL DEFAULT '',
  `applied_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='已应用的表结构迁移版本';
```

- [ ] **步骤 2：实现 `scripts/migrate.php`**

CLI 形式：`php83 scripts/migrate.php [--dry-run] [--only=qingguan_dev] [--to=0003] [--baseline=N] [--no-backup]`

- [ ] **步骤 3：在本机 dev 上真跑**

```bash
cd /data/qingguan/app
php83 scripts/migrate.php --dry-run                 # 期望：每个库都提示「当前无版本行，可用 --baseline=2 回填」
php83 scripts/migrate.php --baseline=2 --no-backup  # 回填 1、2 号
php83 scripts/migrate.php --dry-run                 # 期望：两个库都「无待执行」
```

- [ ] **步骤 4：写门禁测试 `tests/test_schema_version.php`**

```php
t('开发库的 schema_version 已到 migrations 的最大序号', function () {
    $max = 0;
    foreach (glob(dirname(__DIR__) . '/db/migrations/[0-9]*.sql') as $f) {
        $max = max($max, (int) substr(basename($f), 0, 4));
    }
    $cur = (int) qg_test_val('SELECT COALESCE(MAX(version),0) FROM schema_version');
    assert_eq($cur, $max, '开发库有未执行的迁移，请先跑 scripts/migrate.php');
});
```

- [ ] **步骤 5：Commit** `git commit -m "feat: 增加多库迁移执行器与 schema_version 门禁"`

---

## 阶段 2：字典统一下发

### 任务 4：控制面模板表与种子

**文件：**
- 创建：`db/control/migration_0001_dict_template.sql`、`db/control/seed_dict_template.sql`、`scripts/export_dict_template.php`
- 创建：`api/src/repos/DictTemplateRepo.php`
- 测试：`tests/test_dict_template.php`

- [ ] **步骤 1：控制面 DDL**

```sql
CREATE TABLE IF NOT EXISTS `saas_dict_template` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dict_type`        VARCHAR(50)  NOT NULL,
  `dict_key`         VARCHAR(80)  NOT NULL,
  `dict_label`       VARCHAR(80)  NOT NULL DEFAULT '',
  `sort`             INT NOT NULL DEFAULT 0,
  `status`           TINYINT NOT NULL DEFAULT 1,
  `version`          INT NOT NULL DEFAULT 1,
  `created_at`       DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_key` (`dict_type`,`dict_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台字典模板';
```

> 列名以租户 `sys_dict` 的真实列名为准（先 `SHOW CREATE TABLE sys_dict` 对一遍，别照抄这里的示意）。

- [ ] **步骤 2：导出模板种子**

`scripts/export_dict_template.php` 读共享库 `sys_dict WHERE tenant_id=1`，输出 `INSERT ... ON DUPLICATE KEY UPDATE` 到 `db/control/seed_dict_template.sql`（81 行左右）。把它提交进仓库，作为「平台初始字典」。

- [ ] **步骤 3：`DictTemplateRepo` + 测试**

方法：`all()`、`page()`、`upsert(array $row): void`（同时把 `version` 自增到 `MAX(version)+1`）、`maxVersion(): int`、`diffAgainst(array $tenantRows): array`（返回新增/改动/停用/冲突四组）。

- [ ] **步骤 4：跑测试 + Commit** `git commit -m "feat: 控制面字典模板表与种子"`

### 任务 5：租户 `sys_dict` 加 `source`

**文件：**
- 创建：`db/migrations/0003_dict_source.sql`
- 修改：`api/src/repos/DictRepo.php`、`api/src/controllers/SystemController.php`、`pages/sys-dict.html`
- 测试：`tests/test_dict_source.php`（并入 `tests/test_dict_template.php` 也可）

- [ ] **步骤 1：迁移（幂等，写法沿用 0001）**

`sys_dict` 加 `source ENUM('platform','tenant') NOT NULL DEFAULT 'platform'`、`template_version INT NOT NULL DEFAULT 0`；存量行 `UPDATE ... SET source='platform'`。

- [ ] **步骤 2：仓储与接口**

- `DictRepo::list()` / `page()` 返回里带上 `source`；
- 租户侧新增/修改 → `source='tenant'`（若改的是平台项，接口直接拒绝：「平台字典项由平台统一维护，租户不可修改」）；
- 删除平台项同样拒绝（只能停用）；
- `pages/sys-dict.html`：`source==='platform'` 的行显示「平台」标签，编辑/删除按钮置灰。

- [ ] **步骤 3：跑测试 + Commit** `git commit -m "feat: 字典区分平台下发与租户自建"`

### 任务 6：下发器 `DictPublisher` 与 CLI

**文件：**
- 创建：`api/src/DictPublisher.php`、`scripts/publish_dict.php`
- 测试：`tests/test_dict_publish.php`

- [ ] **步骤 1：差异计算**

`DictPublisher::preview(): array` → 对共享库和每个专用库，算出四组：`add` / `update` / `disable` / `conflict`（冲突 = 同 `(dict_type,dict_key)` 但租户行是 `source='tenant'`）。`preview()` 只读，不改任何东西。

- [ ] **步骤 2：发布**

`DictPublisher::apply(): array` → 用 `Fanout::run()` 逐库执行：

```sql
-- 新增（模板有、租户没有）
INSERT INTO sys_dict (tenant_id, dict_type, dict_key, dict_label, sort, status, source, template_version)
VALUES (?,?,?,?,?,1,'platform',?)
ON DUPLICATE KEY UPDATE dict_label=VALUES(dict_label), sort=VALUES(sort), status=VALUES(status), template_version=VALUES(template_version);
-- 只覆盖 platform 行：把 WHERE 条件写成 source='platform'（用 UPDATE ... WHERE 实现，别用 ON DUPLICATE KEY 一把梭，否则会盖掉租户自建项）
-- 模板删掉的项 → UPDATE sys_dict SET status=0 WHERE ... AND source='platform'
```

每库结束后写 `saas_audit(action='dict_publish', target_type='db', target_id=?, detail=json)` 留痕（租户侧可以只留一条汇总）。

- [ ] **步骤 3：CLI**

```bash
php83 scripts/publish_dict.php --dry-run   # 打印四组差异，供人工确认
php83 scripts/publish_dict.php --apply     # 真下发
```

- [ ] **步骤 4：测试**

`tests/test_dict_publish.php` 造一个平台模板项 + 一个同 key 的租户自建项，断言：新增项落到两个库、租户自建项没被覆盖、冲突被列出来、重复 `apply` 不产生重复行（幂等）。

- [ ] **步骤 5：Commit** `git commit -m "feat: 平台字典下发（预览 + 逐库执行 + 审计）"`

---

## 阶段 3：收口

### 任务 7：端到端验证与文档

- [ ] **步骤 1：完整走一遍**

```bash
php83 scripts/migrate.php --dry-run        # 无待执行
php83 scripts/publish_dict.php --dry-run   # 四组差异
php83 scripts/publish_dict.php --apply     # 下发
php83 tests/run.php                        # 全绿
```

再用 HTTP 验证一次：`demo/admin/admin123` 登录后 `GET /api/dicts` 能看到带 `source` 的项；新建一个字典项 → `source='tenant'`；再发布一次平台字典 → 租户自建项仍在。

- [x] ~~**步骤 2：把 `db/schema.sql` 的头部注释改口**（单租户基线 → 只作参考，新库走 migrations）。~~ **【2026-10-15 反向后完成】** 头部注释已按新定位写好：schema.sql = 数据面结构唯一权威 + 租户参数化播种，幂等，parity 测试把闸。

- [ ] **步骤 3：更新设计文档**，把第 14/15 节状态改为「已实现」，并记录实际命令。

- [ ] **步骤 4：Commit** `git commit -m "docs: 运维基础设施（迁移 + 字典下发）执行完成"`

---

## 之后

1. **「自助开通与品牌」计划**：注册页 + 平台审核后台 + 开通播种（把本计划的 `Fanout` 执行器用在「逐库播种」上）+ Logo + 附件租户命名空间。
2. 本计划里 `publish_dict.php` 的 HTTP 入口（平台后台的「字典模板」页）归到那个计划，与平台超管登录一起做。
