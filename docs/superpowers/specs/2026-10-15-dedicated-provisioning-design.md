# 二期 · 专用档（dedicated）租户自动开通 设计

- 日期：2026-10-15
- 状态：设计已确认（自动化程度 / 账号模型 / 失败处理 / 迁移过渡四项均经选择拍板）；待写成实现计划
- 前置：《多租户 SaaS 化设计》（2026-10-14）已落地共享档全链路；本文只覆盖其 §5 遗留的专用档开通
- 影响范围：开通链路（TenantProvisioner / ProvisionRepo 复用）、运行时连接选择（Tenant::pdo）、config、一个迁移辅助脚本、platform.html 两处展示

## 1. 背景与目标

《多租户 SaaS 化设计》把专用档开通划归二期，现状：

- 注册页的「隔离版（dedicated）」套餐**已经可选**，客户选它并通过审核时，`TenantProvisioner::provision()` 抛「专用档（物理隔离）开通尚未开放，归二期」。
- 运行时连接已就绪：`Tenant::pdo()` 对 `mode='dedicated' && db_name` 走专用库，但**没有任何流程会创建专用库**，生产上专用库数量为 0。
- 控制面 `saas_tenant` 已有 `db_name` 列（专用档专用），无需 schema 变更。

目标：平台超管点「通过」后**全自动**完成专用档开通——建库、建账号、灌 schema、播种、建管理员，无需登服务器。

### 已拍板的决策（澄清问题记录）

| 决策点 | 选择 | 关键理由 |
|---|---|---|
| 自动化程度 | **全自动**（Web 触发） | 体验最好；高权限收敛到一个专用开通账号 |
| 专用库账号模型 | **每租户独立账号 + HMAC 派生密码**（不落库） | 真·物理隔离；零新增存储与攻击面；代价是 secret 轮换需重建账号（§7） |
| 失败处理 | **幂等重试，不做自动回滚** | 与共享档同一哲学；无不可逆自动操作（绝不代码自动 DROP）；半成品被重试幂等收编 |
| 迁移过渡 | **带最小脚本** `scripts/migrate_dedicated.php` | 专用库出现后逐库跑迁移的手工需求真实存在；阶段 2 正式扇出机制落地后淘汰它 |

### 非目标（YAGNI，本期不做）

- 专用库的**删除/回收**自动化（suspend/enable/reject 不碰库；删除走手工，步骤见 §7）。
- 迁移版本表 / 扇出机制的正式实现（《运维基础设施》阶段 2 范畴）。
- 在线计费、资源配额强制（`max_users`/`max_orders` 维持现状：展示与登记，不强制）。
- 专用库连接的独立 host（与共享库同实例；跨实例属于后续运维议题）。
- CLI 开通脚本——全自动下重试即后台再点一次通过，两套入口是浪费。

## 2. 开通链路（核心流程）

入口不变：`PlatformController::approve` → `TenantProvisioner::provision()`。`provision()` 按 mode 分流；shared 分支保持现状，dedicated 分支按顺序执行：

```
1. CREATE DATABASE IF NOT EXISTS `qingguan_t_<code>`
   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci（与共享库一致，实现时核对共享库实际值后定死）
2. CREATE USER IF NOT EXISTS 'qg_<code>'@'localhost' 与 'qg_<code>'@'127.0.0.1'
   + SET PASSWORD ... = '<派生密码>'     ← 幂等重置：账号已存在但密码漂移时归位
3. GRANT ALL PRIVILEGES ON `qingguan_t_<code>`.* TO 两个账号（仅本库）
4. 以 'qg_<code>' 连接专用库（顺带验证账号可用）：
   ProvisionRepo::seedFromSchema($tid) + ProvisionRepo::ensureAdminUser(...)（现有代码原样复用；
   schema.sql 的 DDL 全 IF NOT EXISTS、种子全 ON DUPLICATE KEY UPDATE，天然幂等）
5. 控制面守卫 UPDATE status 'pending'→'active'（现有代码原样复用，防并发/驳回竞态）
6. saas_audit 记 approve，detail 注明 db_name
```

- **每一步幂等**；任一步失败：异常上抛 → 租户留在 pending → 记 `provision_failed` 审计（复用现有 catch）→ 平台排查后重新点「通过」即从中断处继续（已完成的步骤因幂等被跳过/收编）。
- 步骤 1–3 走 `provision_db` 连接（§3）；步骤 4 走新建的租户账号连接（本身就是对 2–3 的验证）；步骤 5–6 走 `ControlDb`。
- `db_name = qingguan_t_<code>` 在开通时写入 `saas_tenant`（开通失败则保持空，重试成功后写入）。
- **code 含连字符**：`[a-z0-9-]` 是合法 code 字符集。MySQL 库名/账号名含 `-` 必须反引号/引号包裹，DDL 统一走反引号；PDO DSN 的 `dbname` 值原生支持 `-`。实现时**必须用一个含 `-` 的 code 实测**（建库 + 连接 + DML 全链路），这是 §6 验收项之一。
- suspend / enable / reject / 登录拦截：**不碰专用库**，只动控制面 `saas_tenant.status`（现有逻辑直接生效——`Auth::user()` 对 `status != 'active'` 的租户拒绝建立会话，`Tenant::pdo()` 根本不会被调）。

## 3. 凭据派生与配置

### 新类 `api/src/DedicatedDb.php`（纯静态，无状态）

```php
DedicatedDb::name($code)  // 'qingguan_t_' . $code
DedicatedDb::user($code)  // 'qg_' . $code
DedicatedDb::pass($code)  // hash_hmac('sha256', 'db-pass:' . $code, App::secret()) 的 hex
```

- 供 `TenantProvisioner` 与 `Tenant::pdo()` 共用，**密码不落任何存储**。
- 派生依赖 `app.secret`：轮换 secret 会同时失效所有专用库账号密码（SET PASSWORD 重试机制即修复路径，见 §7）。
- Tenant 上下文需带 `code`：`Auth::user()` 的 `Tenant::set()` 与 Provisioner 的 `Tenant::set()` 各补 `'code' => $t['code']`（`db_name` 已有）。

### config.php 新增 `provision_db` 段（与 `db`/`control_db` 平级）

```php
'provision_db' => [   // 开通专用：能建 qingguan_t_% 库与 qg_% 账号，别的一概不能
    'host' => '127.0.0.1', 'port' => 3306,
    'user' => 'qg_provision', 'pass' => '...',
],
```

- 对应 MySQL 账号权限（部署时一次性创建，SQL 模板见 §7）：全局 `CREATE`（建库需要）+ 全局 `CREATE USER` + `GRANT ALL ON \`qingguan_t_%\`.* WITH GRANT OPTION`。
- 业务账号 `db.user` 权限**零变化**；`control_db` 零变化。
- **降级行为**：`provision_db` 缺失或 pass 为空时，dedicated approve 返回友好报错（「专用档开通未配置，请联系平台」）并留 pending；shared 路径完全不读取该段、不受任何影响。
- `config.sample.php` 同步补注释段；**`api/config/config.php` 不入库**，dev 与 breeder 各自手工补（breeder 发布步骤见 §7）。

## 4. 运行时连接（Tenant::pdo dedicated 分支）

现状已按 `mode==='dedicated' && db_name` 选库，仅需把连接凭据从「共享 `db` 账号」改为派生专用账号：

- host/port：复用 `config('db')` 的 host/port（同实例）。
- dbname：`Tenant` 上下文的 `db_name`。
- user/pass：`DedicatedDb::user($code)` / `DedicatedDb::pass($code)`（上下文新增的 `code` 字段）。
- 连接池仍按库名缓存（`Tenant::$pools` 现状不变）。
- shared 档路径逐字节不变；无 `code` 的旧上下文（仅测试手工 set 的场景）在 dedicated 分支抛明确异常「租户上下文缺少 code」，不允许静默回退共享账号。

## 5. 脚本与前端

### `scripts/migrate_dedicated.php`（新增，最小扇出）

```bash
php83 scripts/migrate_dedicated.php db/migration_XXXX.sql          # 逐库执行
php83 scripts/migrate_dedicated.php db/migration_XXXX.sql --dry-run  # 只列出将执行的库清单
```

- 库清单来源：`saas_tenant WHERE mode='dedicated' AND db_name != '' AND status IN ('active','suspended')`，按 `db_name` 排序（与《运维基础设施》阶段 2 计划的顺序约定一致）。
- 走 `provision_db` 连接（有 `qingguan_t_%` 的 ALL 权限，可执行 DDL）。
- 每库独立执行并独立报告成功/失败；某库失败不中断后续库，最后汇总退出码非零。
- 语句切分复用 `ProvisionRepo::splitStatements()` 的同一套约定（不得在语句字符串内用 ASCII 分号）。
- 阶段 2 的正式机制（版本表、`--to`、`--only`）落地后删除本脚本。

### 前端 `platform.html`（两处小改）

- 租户列表：dedicated 行展示 `db_name`（shared 行显示「共享库」）。
- 「通过」确认弹窗文案按 mode 区分（dedicated 注明将创建独立数据库与专用账号）。
- `register.html` / 外壳 / 登录页：零改动（隔离版套餐本就可选）。

## 6. 测试策略

测试套件零依赖、直连 dev 真实库（`tests/bootstrap.php`），**不在套件里真建库/真建账号**（dev 的 `qingguan_dev` 账号无建库权限，也不允许测试产生库/账号垃圾）：

1. **单测 `tests/test_dedicated_db.php`**：`DedicatedDb` 三函数——命名格式正确、同 code 派生稳定、不同 code 派生不同、非法字符 code 抛异常。
2. **单测 TenantProvisioner 守卫分支**：dedicated 租户在 `provision_db` 未配置时的报错文案与状态不变（pending）；shared 路径不受 `provision_db` 缺失影响（回归）。
3. **现有 60 用例回归**：不选 isolated 套餐，行为不变。
4. **dev 端到端真跑（验收步骤，不进套件）**：dev MariaDB 建开通账号 + 配 `provision_db` → 注册含 `-` 的 code（如 `e2e-dedicated`）→ 审核通过 → 断言：库已建且 21 表齐全、`qg_e2e-dedicated` 账号可连且仅授权本库、管理员可登录、业务数据落在专用库而非共享库、共享库无该租户种子行 → 审核记录与审计完整 → 手工清理（DROP 库/账号 + 控制面行）。
5. 守卫脚本 `guard_no_raw_business_sql.php` 回归：ProvisionRepo 不新增触业务表的裸 SQL。

## 7. 部署与运维（写入 AGENTS.md 与发布清单）

一次性准备（dev 与 breeder 各执行一遍）：

```sql
-- 用管理员连接执行；provision 账号只管 qingguan_t_%
CREATE USER 'qg_provision'@'localhost'  IDENTIFIED BY '<强密码>';
CREATE USER 'qg_provision'@'127.0.0.1' IDENTIFIED BY '<强密码>';
GRANT CREATE ON *.* TO 'qg_provision'@'localhost';
GRANT CREATE ON *.* TO 'qg_provision'@'127.0.0.1';
GRANT CREATE USER ON *.* TO 'qg_provision'@'localhost';
GRANT CREATE USER ON *.* TO 'qg_provision'@'127.0.0.1';
GRANT ALL PRIVILEGES ON `qingguan_t_%`.* TO 'qg_provision'@'localhost' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON `qingguan_t_%`.* TO 'qg_provision'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

随后 config.php 补 `provision_db` 段（密码不入库、单独维护）。

运维口径（文档化，不做自动化）：

- **secret 轮换**：`app.secret` 变更会使全部 `qg_<code>` 账号密码失效 → 对每个专用库账号重跑 `SET PASSWORD = '<新派生值>'`（`DedicatedDb::pass()` 用新 secret 现算；提供一段可粘贴的生成 SQL 的说明，正式轮换工具归阶段 2/后续）。
- **租户库清理（手工）**：确定废弃某专用租户时，先平台侧 reject/suspend，再人工 `DROP USER` ×2 + `DROP DATABASE`。系统绝不自动执行 DROP。
- **迁移**：表结构变更时 = 共享库跑一遍 + `php83 scripts/migrate_dedicated.php <file>` 逐库跑；迁移文件仍需兼容 MySQL 8.0 与 MariaDB 10.5（现状铁律不变）。
- **监控点**：`saas_audit` 的 `provision_failed` 记录即开通失败告警源（当前靠人工看，接监控归后续）。

## 8. 风险与边界

- **`provision_db` 是新的高价值凭据**：权限已收敛（建库/建账号仅限 `qingguan_t_%` 与全局 CREATE/CREATE USER，不碰 `qingguan`/`qingguan_control`/`mysql` 系统库）；泄露影响 = 可创建/填充 `qingguan_t_*` 库，**读不到任何现有业务数据**。
- **MySQL 与 MariaDB 双兼容**：步骤 1–3 的 DDL 均为两者共同子集（`CREATE DATABASE IF NOT EXISTS` / `CREATE USER IF NOT EXISTS` / `SET PASSWORD` 语法两 side 兼容，实现时在 dev MariaDB 与 breeder MySQL 8.0 各实测一遍）。
- **半成品残留**：步骤失败留下空库/账号是预期行为（幂等重试会收编）；唯一的「脏状态」是库已建但控制面仍 pending——不影响运行时（运行时只认 active），属可接受。
- **并发开通同一租户**：步骤 5 的守卫 UPDATE 保证只有一个 pending→active 命中；重复点通过是幂等重放。
