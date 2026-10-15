# 清关管理系统 · 多租户 SaaS 化设计

- 日期：2026-10-14
- 状态：设计已评审通过（骨架 + 流程两轮确认）；待写成实现计划
- 影响范围：现有单租户单机系统 → 支持多企业租户的 SaaS

## 1. 背景与目标

现有系统是**单租户单机版**：一套代码、一个库、全局共享数据，权限只决定「左侧菜单可见性」，不隔离数据。目标是改造成可对外经营的多租户 SaaS。

目标：

1. 企业**自助注册**租户，平台超级管理员**审核**后开通。
2. 数据按租户隔离，支持两种服务等级：
   - **逻辑隔离档**：共享一套库，每条业务数据带 `tenant_id`。
   - **物理隔离档**：每个租户一个独立数据库。
   - 注册时由客户选择，对应不同价格。
3. 租户内的「超级管理员」降级为「**租户系统管理员**」（只在本租户内全权）。
4. 支持上传**租户 Logo**，在登录页与系统外壳展示。

### 非目标（YAGNI，本期不做）

- 在线计费 / 支付网关（只做「套餐 + 价格展示 + 选择隔离档」）。
- 租户自定义域名（统一域名 + 租户代码登录）。
- 平台客服代维 / impersonation。
- 用户名全局唯一、免填租户代码的登录方式。

## 2. 现状事实（设计前提）

来自代码测绘，均为设计必须正视的约束：

- 后端为 **PHP 8 原生**，无框架、无 ORM；数据访问是极薄的静态 PDO 包装 `api/src/Db.php`（`all/one/val/exec` 收手写 SQL，`insert/update` 收表名+数组）。
- 全后端共 **163 处 `Db::` 调用**，分布在 7 个 controller（OrderController 51、SystemController 48、FinanceController 26、OrgController 11、UploadController 10、PlanController 9、AuthController 5）+ 辅助类 3 处。
- **没有任何全局中间件/钩子**可挂；唯一横切是路由级 `Auth::requireLogin()`。
- 表共 **21 张**：7 张 `sys_*`（元数据）+ 14 张业务表（`org_company`、`biz_plan`、`biz_order` + 8 张订单子表、3 张 `fin_*`）。**没有真正的 FOREIGN KEY**（只有索引 + 应用层维护）。
- `sys_user` 是**单角色**（一个用户挂一个 `role_id`）。
- `sys_role.code='super'` 是一个 `['*']` **短路开关**，出现在 3 处：`Auth.php:127`、`Auth.php:142`、`AuthController.php:83`。
- **当前无任何按用户/租户的数据过滤**：所有业务数据全局共享，任何登录账号直接调接口都能看到全部数据。
- 附件存 `uploads/order/Y/m/`（**全局共享，未按租户分目录**）。
- 前端是无构建的静态 SPA：外壳 `index.html`（侧栏品牌「清关管理系统」写死、菜单来自 `/menu`、内容区是 `v-show` 保活的 iframe 标签）、`login.html`（品牌写死）、`assets/js/common.js`（`QG.get/post`、`QG_USER` 来自 `/auth/me`、`QG.upload`）。

## 3. 总体架构：控制面 / 数据面分离

- **控制面库**（新建，`qingguan_control`）：只放跨租户对象——租户、套餐、平台管理员、平台审计。不含业务数据。
- **数据面**：
  - 逻辑隔离档 → **共享库**（现有 `qingguan`，所有表加 `tenant_id`）。
  - 物理隔离档 → 每租户一个库 `qingguan_t_<code>`，**表结构与共享库完全一致**。
- **两档共用同一套 21 表 schema，业务表一律带 `tenant_id`**。物理隔离档的库里所有行本来就是同一租户，过滤恒等但无害；隔离效果由「独立库」本身提供。
- 代码中唯一的变量：当前请求的**租户上下文**决定连哪个 PDO。除此之外无分支。

判定：这是本方案的核心主张——用一个统一的「总是按 `tenant_id` 过滤」的代码路径，同时支撑两种服务等级。

## 4. 控制面数据模型

```sql
-- 套餐
saas_plan(
  id, code UNIQUE, name, mode ENUM('shared','dedicated'),
  price_month DECIMAL(10,2), price_year DECIMAL(10,2),
  max_users INT, max_orders INT, remark, status TINYINT, sort INT, created_at
)

-- 租户（注册信息也落这里，status=pending）
saas_tenant(
  id, code UNIQUE,            -- 租户代码，登录用
  name, logo_url,
  mode ENUM('shared','dedicated'),
  plan_id, db_name,           -- db_name 仅物理隔离档有
  status ENUM('pending','active','rejected','suspended'),
  contact_name, contact_phone, contact_email,
  admin_username, admin_password_hash,   -- 待审期间暂存，开通时落到数据面
  reject_reason,
  applied_at, reviewed_at, reviewed_by,
  created_at, updated_at
)

-- 平台管理员（与租户 sys_user 完全分开）
saas_platform_user(
  id, username UNIQUE, real_name, password_hash, email,
  status TINYINT, last_login_at, last_login_ip, created_at
)

-- 平台侧操作留痕（审核/开通/停用/改套餐）
saas_audit(
  id, platform_user_id, action, target_type, target_id, detail, ip, created_at
)
```

- 注册信息直接落 `saas_tenant`（`status=pending`），不单开注册表。
- `admin_password_hash` 用 `password_hash()`（bcrypt）；开通时写入数据面 `sys_user` 后即可清空。

## 5. 登录与租户解析

**租户用户**：登录框三项 `租户代码 + 用户名 + 密码`。

```
POST /api/auth/login { tenant, username, password }
  1. 控制面查 saas_tenant WHERE code=?
  2. 校验存在且 status=active（pending/rejected/suspended 给明确提示）
  3. 解析连接：mode=shared → 共享库；mode=dedicated → 该租户库
  4. 在该库的 sys_user 里校验 username/password（tenant_id 过滤）
  5. 签发令牌 cookie：{ scope:'tenant', tid, uid, uname, exp }
```

**平台管理员**：独立入口 `/admin-login.html`，校验 `saas_platform_user`，令牌 `{ scope:'platform', puid, exp }`，无 `tid`。

**每请求**：`Auth` 解析令牌 → 建「租户上下文」（含已解析 PDO）→ 全局注入（进程内单例）。

- 选用「租户代码」而非「全局唯一用户名」：两种档一条路径即可解析到库，实现简单，不依赖子域/泛域名证书；代价是登录多填一项。
- 升级路径（后置）：控制面加 `username → tenant` 索引表即可支持「只填用户名」。

## 6. 数据隔离落地（收口点）

- 新增 `api/src/Tenant.php`：进程内单例的租户上下文（`id / mode / db_name / pdo`）。
- 改 `api/src/Db.php`：`Db::pdo()` 返回**当前租户上下文**的连接（共享档→共享库，专用档→各自库），不再连死在 `config.php`。
- 新增 `api/src/repos/*`：按表拆仓储（`OrderRepo / CompanyRepo / PlanRepo / FinanceRepo / UserRepo / DictRepo / ConfigRepo / PermissionRepo / LogRepo / FileRepo`）。**业务 SQL 只允许写在仓储里**，每个方法强制带 `tenant_id`（共享档靠它过滤，专用档恒等）；controller 只调仓储，不再直接 `Db::`。**这 163 处 SQL 是这次改造的主要工作量。**
- **守卫**（因为无 ORM，必须有自动化兜底）：
  1. 扫 `src/repos` 之外是否有 SQL 碰业务表 → 有就 fail；
  2. 扫仓储内每条业务 SQL 是否带 `tenant_id` → 缺就 fail。
  作为测试/上线前硬闸。
- 7 张 `sys_*` 表**也随租户走**（每个租户一份自己的角色/权限/字典/配置/日志），保证两档行为一致；平台级账号/套餐/审计只在控制面。
- 跨租户统计（平台看用量）走控制面或专用只读接口，不混进业务仓储。

## 7. 注册 / 审核 / 开通

**注册**：公开页 `register.html`（仿 `login.html`）。字段：企业名称、租户代码（自动 slug + 唯一校验）、联系人/手机/邮箱、管理员账号+密码、选择套餐（决定 `mode`，显示价格）。`POST /api/tenant/register`（无需登录）→ 落 `saas_tenant`，`status=pending`。接口需**限流（同 IP 频次）+ 基础校验**防灌。

**审核**：平台超管页 `/admin.html`。待审列表 → 「通过 / 驳回（填原因）/ 停用 / 重新启用」，全部记 `saas_audit`。

**通过时开通（幂等）**：

1. 共享档：不建库，跳到第 3 步。
2. 专用档：`CREATE DATABASE qingguan_t_<code>`（utf8mb4）+ 灌 schema + 建专用库账号 `qg_<code>` 并只授权本库。
3. 播种租户：写入该租户的 `sys_role`（租户系统管理员 / 操作员 / 财务）、`sys_permission` 菜单树、`sys_dict`、`sys_config` 默认值，以及**第一个 `sys_user`（管理员，角色=租户系统管理员）**。
4. `status=active`，发通知邮件（复用现有邮件配置），申请人即可用「租户代码 + 账号」登录。

**驳回**：`status=rejected` + 原因；申请人重试登录时提示「申请未通过」。

## 8. 角色模型改造

- `sys_role.code='super'` 的 `['*']` 短路语义**保留**，但明确：它只在本租户内全权——因为权限数据随租户走，天然成立。
- 显示名「超级管理员」→「**租户系统管理员**」；`code` 由 `super` 改为 `tenant_admin`。涉及 3 处代码：`Auth.php:127`、`Auth.php:142`、`AuthController.php:83`，外加种子数据迁移。
- **平台超管**独立于任何租户的 `sys_user`（存 `saas_platform_user`）。
- **边界**：平台超管默认**看不到**租户业务数据（无租户上下文进不了业务接口）。代维能力后续再加，且必须带审计。

## 9. Logo 与附件隔离

- **附件按租户分目录**：`uploads/t<tid>/order/Y/m/`（原为全局 `uploads/order/Y/m/`）。这属于数据隔离的一部分——否则租户 A 可猜到 B 的附件 URL。涉及 `UploadController` 与前端硬编码的 `/uploads/` 前缀。
- **Logo**：`POST /api/tenant/logo`（仅租户系统管理员），存 `uploads/t<tid>/branding/logo.<ext>`，路径写回 `saas_tenant.logo_url`。只收 png/jpg（**不接 SVG**，避免 XSS），限大小。
- **展示两处**：
  1. 外壳 `index.html` 侧栏 `.brand`：`/auth/me` 多返回租户名 + logo 后渲染。
  2. 登录页 `login.html`：因先填租户代码，输入框失焦后调 `GET /api/tenant/branding?code=xx` 拉名称+logo 渲染；未填时显示平台通用品牌。

## 10. 数据库迁移（唯一键与索引）

把现有 `qingguan` 库当「租户 #1（共享档）」：

- 21 张表全部 `ADD COLUMN tenant_id`（业务表 `NOT NULL`），现有行回填 `tenant_id=1`。
- **唯一键必须一起改**（最易漏，否则第二租户建同名记录会撞键）：
  - `sys_user.username` → `(tenant_id, username)`
  - `sys_role.code` → `(tenant_id, code)`
  - `sys_permission.code` → `(tenant_id, code)`
  - `sys_config.cfg_key` → `(tenant_id, cfg_key)`
  - `sys_dict(dict_type, dict_key)` → `(tenant_id, dict_type, dict_key)`
  - `biz_plan.plan_code` → `(tenant_id, plan_code)`
  - `biz_order.order_no` → `(tenant_id, order_no)`
  - `fin_account.company_id` → `(tenant_id, company_id)`
- 高频查询补 `(tenant_id, ...)` 复合索引（列表类：`biz_order(tenant_id, is_deleted, id)` 等）。
- 建控制面库，写 `saas_tenant` 第 1 条（`mode=shared`），把 `users.id=1` 的角色显示名改为「租户系统管理员」。
- 迁移脚本与 `db/` 下现有 `upgrade-*.sql` 同风格、幂等。

## 11. 分阶段交付

- **一期（核心可用）**：控制面；租户代码登录；仓储层重构；共享档隔离；注册/审核/开通（共享档端到端）；角色改名；Logo；附件目录命名空间；存量数据迁移。
- **二期**：专用档自动开通（建库/建账号/灌 schema/播种）+ 套餐与价格展示 + 平台审计面板完善。
- **三期（可选）**：计费/在线支付、租户自定义域名、平台代维（impersonation）、用户名全局唯一登录。

## 12. 测试策略（硬要求）

- **隔离用例**：A 租户登录后，其订单 / 抬头 / 财务 / 附件对 B 不可见，反之亦然；直接构造跨租户 id 调接口应 404/403。
- **守卫脚本**：见第 6 节的两条静态检查，纳入测试。
- **端到端**：注册 → 审核 → 开通 → 登录 → 各模块可用。
- **回归**：现有单租户（=租户 #1）功能不退化。

## 13. 风险与开放问题

- **改造面大**：163 处 SQL 收拢进仓储是主要风险点；靠守卫脚本 + 隔离测试兜。建议小步提交、逐 controller 迁移。
- **唯一键迁移**：需在生产数据上验证无冲突（现有数据量小，风险低）。
- **物理隔离档的运维**：建库/迁移/连接数管理是二期重点，需明确「租户库迁移怎么跑」（逐库执行 migration）。
- **平台超管是否可读业务数据**：本设计默认「不可」，如需支持再看是否引入带审计的代维通道。
- **现有 `部署说明.md`** 是厂商的宝塔版说明，与本机 Apache 部署不一致；本仓库部署事实以运行环境为准（另行整理）。

## 14. 平台字典（与菜单）的统一增改

**问题**：第 6 节决定「7 张 `sys_*` 随租户走」，于是每个租户各有一份 `sys_dict`（还有一份 `sys_permission` 菜单树）。平台想统一新增一个字典项（例如贸易方式多一种）、或调整一个菜单的路径，就要改 N 个库——这就是多租户化换来的新运维负担，必须事先设计，不能靠手工。

**模型**：

- 控制面新增 `saas_dict_template`（平台字典模板）：列与 `sys_dict` 相同，去掉 `tenant_id`，加 `version INT`、`status TINYINT`。
- 租户 `sys_dict` 加两列：`source ENUM('platform','tenant') NOT NULL DEFAULT 'platform'`、`template_version INT NOT NULL DEFAULT 0`。存量行回填 `source='platform'`。
- 菜单同理，第二期做：`saas_menu_template` ↔ `sys_permission`，加同一对列。

**发布（平台 → 全部租户）**：

1. 平台改模板 → 该 `saas_dict_template` 的 `version` +1。
2. 点「发布」前先出**差异预览**：新增 N 项 / 改动 M 项 / 停用 K 项 / 冲突 C 项，要二次确认。
3. 按 控制面 → 共享库 → 各专用库 的顺序，逐库 upsert，**只动 `source='platform'` 的行**：
   - 模板里有、租户没有 → 插入（`source='platform'`，带上当前 `template_version`）；
   - 两边都有且都是 `platform` → 覆盖成模板值；
   - **两边都有、但租户那行是 `source='tenant'`（租户自己加的）→ 判为冲突，跳过并列出来**，由人工决定谁赢，不静默覆盖；
   - 模板里删掉的项 → 不物理删，改 `status=0`（停用）后同步下来，避免租户历史数据引用到不存在的字典项。
4. 每库发布完写一行结果（成功/失败/冲突数），失败的库单独可重试（发布也要幂等）。

**租户自建**：租户自己加的字典项 `source='tenant'`，平台发布永远不碰；租户改不了平台项（界面置灰），只能停用（本地 `status=0`）。

**与迁移的关系**：第 15 节的「多库执行器」和这里「多库发布器」是同一件事的两种载荷——都是「控制面定义 → 逐个数据面库幂等执行」。两者共用一个执行器（`scripts/fanout.php`）：它负责「列出所有数据面库 → 按序执行 → 逐库记录结果 → 失败即停并报告」，具体 SQL 由字典发布或 schema 迁移提供。

## 15. 多库统一的表结构升级

**问题**：物理隔离档（二期）会带来 N 个结构完全相同的库。以后任何一次加字段/改索引，都要在 共享库 + N 个专用库上一致地跑完，不能漏、不能重复、不能跑一半。

**约定**：

- 迁移脚本集中到 `db/migrations/`，文件名 `NNNN_描述.sql`（四位序号 + 说明），**每个文件必须幂等**（先查 `information_schema`，已存在则跳过）——这一点一期已经这么做了，沿用 `migration_20261015_multitenant.sql` 的 `PREPARE` + `IF` 写法。
- 每个数据面库（含共享库）与控制面库各有一张 `schema_version(version INT PRIMARY KEY, note VARCHAR(200), applied_at DATETIME)`。
- `scripts/migrate.php`：
  - 目标顺序固定：**控制面库 → 共享库 → 各专用库**（专用库按 `db_name` 排序，保证每次顺序一致）；
  - 对每个目标：读 `schema_version` 的最大 `version`，按序号执行所有更大的迁移文件，**每个文件执行成功后立刻写入版本行**（MySQL 的 DDL 是隐式提交，事务包不住 DDL，所以靠「文件幂等 + 成功才记账」来保证可断点续跑）；
  - 任一库失败**立即停止**并打印「哪个库、哪个文件、什么错误」，已成功的库保持已升级状态（它们本来就是幂等的），修完重跑即可；
  - 支持 `--dry-run`（只列出「哪个库还差哪几个文件」）、`--only=<db_name>`、`--to=<NNNN>`；
  - 执行前自动 `mysqldump` 备份到 `db/backup/<库名>_<时间>.sql.gz`。
- ~~**建新库 = 按 migrations 从头跑一遍**。所以 `db/schema.sql` 的历史定位要改口……~~
  > **【2026-10-15 改口】** 改为：**建新库 = 灌 `db/schema.sql`**。`db/schema.sql` 已从「单租户基线」升格为**数据面结构的唯一权威**（含结构 + 租户参数化的产品默认数据，幂等，`SET @tenant_id := <id>` 指定目标租户）；它由「生产克隆库」导出归一而来、并用 `tests/test_schema_parity.php` 与开发库逐列逐键比对来兜底。`db/migrations/` 只负责**升级既有库**；每次迁移落库后必须同步把 DDL 改动写回 `db/schema.sql`，否则 parity 测试会红。建库与升级是两条正交的路：新库灌 schema.sql，旧库跑 migrations，二者由 parity 测试保证汇合。
- **发布流程固化成三步**：备份 → 迁移（在切代码**之前**跑，因为新代码依赖新结构）→ 切代码/重启 → 验证。回滚只有代码层是可靠的（`git revert` + 重启）；数据库回滚靠执行前的备份，迁移脚本不写 down（写了也没人敢在生产上跑）。
- **门禁**：`tests/run.php` 里加一条断言——开发库的 `schema_version` 等于 `db/migrations/` 的最大序号；`scripts/migrate.php --dry-run` 必须报「无待执行」。上线前这两条不过就不发。
- 一期的两份迁移（`migration_20261015_multitenant.sql`、`migration_20261015_role_rename.sql`）在「运维基础设施」这一步归一到 `db/migrations/0001_*.sql` / `0002_*.sql`，并给已经跑过它们的库（本机 dev、生产）回填版本行。
