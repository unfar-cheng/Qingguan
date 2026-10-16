# P0-A · 账单与有效期 设计

- 日期：2026-10-16
- 状态：设计已确认（宽限期模式、账单实体、线下收款平台确认、条幅+正式账单页均经选择拍板）；待写成实现计划
- 范围拆分：P0 三件套拆为 A 账单与有效期（本规格）、B 租户管理员密码代重置、C 平台账号管理，各自独立规格与计划
- 前置：《多租户 SaaS 化设计》《专用档自动开通设计》已落地；平台审计日志页已上线（`/api/platform/audits`）

## 1. 背景与目标

`saas_tenant` 无有效期概念：审核通过即永久有效，`saas_plan` 的价格只用于注册页展示，收没收费、服务到哪天系统一无所知。目标：引入**账单实体**把「钱」与「服务期」解耦，形成「注册选周期 → 生成账单 → 审核开通即 30 天试用 → 线下付款、平台确认 → 到期日生效/顺延 → 临期/宽限条幅提醒 → 超宽限拦截」的完整闭环。一期为**线下收款、平台人工确认**，在线支付（微信/支付宝）留二期挂点。

### 已拍板的决策

| 决策点 | 选择 |
|---|---|
| 到期后行为 | **宽限期模式**：宽限内可登录（红条幅），超宽限硬拦截；宽限 30 天，写死常量 |
| 时长来源 | **注册时申请人选月付/年付**并生成第一张账单；付款与否决定服务期是否生效（未付款 = 审核日起 30 天试用） |
| 支付通道 | 一期**线下转账 + 平台确认收款**；租户不可自标已付；表结构预留 `paid_channel/paid_ref` |
| 预付与审核 | **待审核期间账单锁定**（平台不可确认收款）；驳回时该租户 unpaid 账单自动作废 |
| 到期日口径 | 见 §2 三分支规则（试用转正 = 审核日 + 周期） |
| 租户侧呈现 | **顶部条幅（状态摘要）+ 正式「账单中心」页面**（进左侧菜单） |
| 菜单与权限 | 一级菜单「账单中心」；所有存量租户补插权限行 + `schema.sql` 默认权限；非管理员角色暂不授权（租户管理员 `['*']` 短路可见） |

### 非目标（YAGNI）

- 在线支付网关、自动对账（二期，字段已预留）。
- 发票/开票管理、优惠折扣、按用量计费、预充值。
- 平台手动调整 `expires_at`（赠送延期等边缘场景将来用「0 元账单」承接，本期不做）。
- 邮件/短信通知（P2 触达项目；当前靠条幅与登录页文案）。
- 平台仪表盘、租户详情页（P1）。
- demo 租户补账单/设有效期（保持 NULL = 永不参与到期逻辑）。

## 2. 数据模型（控制面一次迁移，MySQL 8.0 + MariaDB 10.5 双兼容、幂等）

### 2.1 `saas_tenant` 加一列

```sql
bill_cycle ENUM('month','year') NOT NULL DEFAULT 'year'  -- 注册申请的付费周期意愿（平台侧可见）
expires_at DATETIME NULL  -- NULL = 不参与到期逻辑（存量租户默认 NULL，永不拦截、无条幅）
```

### 2.2 新表 `saas_bill`

```sql
CREATE TABLE IF NOT EXISTS saas_bill (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  cycle ENUM('month','year') NOT NULL,            -- 账单对应的服务周期
  amount DECIMAL(10,2) NOT NULL,                  -- 生成时快照套餐价，改价不影响旧账
  status ENUM('unpaid','paid','void') NOT NULL DEFAULT 'unpaid',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  paid_channel VARCHAR(30) NOT NULL DEFAULT '',   -- 二期在线支付前恒 'offline'
  paid_ref VARCHAR(100) NOT NULL DEFAULT '',      -- 二期支付流水号；一期可填转账备注
  confirmed_by INT UNSIGNED NULL,                 -- 确认收款的平台账号 id
  void_reason VARCHAR(200) NOT NULL DEFAULT '',
  remark VARCHAR(200) NOT NULL DEFAULT '',
  KEY idx_tenant (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

与既有控制面表同库（`qingguan_control`），迁移按仓库惯例：`information_schema` 探查 + 幂等执行；同步更新 `db/control/schema.sql`（新装机一次到位）。

### 2.3 「账单中心」菜单权限

- `sys_permission` 新增一级节点（`type=1`，`code='billing'`，名称「账单中心」，路径 `/pages/billing.html`，sort 靠前）；
- 迁移里 `INSERT ... SELECT` 给**全部存量租户**各插一份（幂等：唯一键冲突跳过）；`db/schema.sql` 的默认权限段同步加行（未来新租户自动有）；
- 不动 `sys_role_permission`：租户管理员凭 `['*']` 短路可见，普通角色本期不授权。

## 3. 状态机与到期规则

租户服务期状态由 `expires_at` **实时派生**（不落库、不写状态列）：

| 状态 | 条件（`now` 为服务器时间，按自然日计剩余天数） | 行为 |
|---|---|---|
| 正常 | `expires_at` 为 NULL，或剩余 > 30 天 | 无条幅，登录正常 |
| 临期 | 0 < 剩余 ≤ 30 天 | 琥珀条幅「会员剩余 N 天，请及时续费」（本次会话可关闭） |
| 宽限/试用 | 已到期但 `now ≤ expires_at + 30 天` | 红条幅不可关闭；从无 paid 账单 → 「试用期剩余 N 天，付款后服务期自审核日起算」；有 paid 账单 → 「服务已到期，宽限剩余 N 天」；登录正常 |
| 已过期 | `now > expires_at + 30 天` | 登录拦截「服务已到期，请联系平台续费」；已登录会话在下次鉴权时失效踢回登录页 |

### 3.1 到期日重算（确认收款时，事务内，三分支）

设周期项 `term` = 1 个月（month）/ 12 个月（year）：

1. `expires_at > now`（含临期续费）→ 新到期日 = `expires_at + term`（顺延，不吃亏）；
2. `expires_at ≤ now` 且仍在宽限期内（含**试用转正**，此时 `expires_at` = 审核日）→ 新到期日 = `expires_at + term`（宽限期视为服务延续；试用转正即用户口径「审核日 + 周期」，试用期天数白送）；
3. 已过期（超宽限期）→ 新到期日 = `now + term`（拖欠期不补、不白送）。

### 3.2 账单状态机

- `unpaid → paid`：仅平台「确认收款」，`UPDATE ... WHERE status='unpaid'` 守卫（0 行 = 已被并发处理，报错回滚）；租户 `status='pending'` 时确认收款被拒（「租户未开通」）。
- `unpaid → void`：仅平台「作废」，必填原因；驳回租户时其全部 unpaid 账单自动作废（`void_reason='注册申请被驳回'`）。
- `paid` / `void` 为终态，不可再变更。
- 账单生成点：① 注册（cycle 取申请表单，amount 快照套餐价，remark「注册账单」）；② 租户自助续费（账单中心选周期生成）。两处都只产 `unpaid`。

## 4. 各端变更

### 4.1 注册（TenantController）

- 注册表单加必选「付费周期」单选（月付/年付，默认年付），随申请落库（`saas_tenant` 加 `bill_cycle` 一列存申请意愿，平台侧可见）；
- 注册成功即插入第一张 `unpaid` 账单（amount = 套餐对应周期价格快照）；
- 驳回流程（PlatformController::reject）加自动作废 unpaid 账单。

### 4.2 审核通过（TenantProvisioner::provision）

- 状态守卫 UPDATE 增加 `expires_at = <审核时刻>`（试用开始）；approve 弹窗文案补「未付款前为 30 天试用期」；审计 detail 不变（审计日志页自动收录后续 bill_paid/bill_void）。

### 4.3 租户侧

- **条幅**：`index.html` 外壳最顶部全宽条幅（外壳一层展示，iframe 子页面不重复）；数据来自 `/auth/me` 扩展（`billing: {state, days_left, expires_at}`）；临期琥珀可关（会话内），宽限红不可关；NULL 到期不渲染。
- **账单中心页** `pages/billing.html`：账单列表（生成时间/周期/金额/状态徽标/支付时间/备注）、待付账单展示对公账户与汇款说明（文案放 `config.php` 的 `app.billing_notice`——部署级配置，单一来源，不进租户级 `sys_config` 以免撞多租户下发问题）；「续费」按钮选周期生成 unpaid 账单（提示「转账后请联系平台确认」）。
- 新路由：`GET /api/bills`（本租户账单，仅 `Auth::isTenantAdmin`）、`POST /api/bills`（自助续费生成 unpaid，仅租户管理员；cycle 白名单校验）。仓储层新 `BillRepo`（查询一律带 `tenant_id = ?`，遵守守卫约定）。

### 4.4 平台侧

- `platform.html` 加第三个视图「账单」：全局账单列表（租户代码筛选 + 状态筛选），行内操作 = 确认收款 / 作废（填原因）；
- 新路由：`GET /api/platform/bills`、`POST /api/platform/bills/{id}/confirm`、`POST /api/platform/bills/{id}/void`（全部 `requirePlatform`，确认/作废走 `ControlDb` 事务 + 状态守卫，审计 `bill_paid` / `bill_void`）；
- 租户列表加「有效期」列（到期日 + 派生状态色：正常/临期/宽限/试用/过期）。

### 4.5 鉴权链

- `AuthController::login`：pending/rejected 分支之后加「已过期」分支（返回「服务已到期，请联系平台续费」）；
- `Auth::user()` 租户分支：`expires_at` 非 NULL 且超宽限 → 视同未登录（401 → 前端跳登录页 → 登录拦截文案）；
- `/auth/me` 扩展 `billing` 字段（§4.3 条幅数据源）；`AuthController::me` 对 platform scope 同样返回（值为空对象，前端不用）。

## 5. 测试策略

- **单测（纯函数，零 DB 副作用）**：服务期四态计算（NULL/临期/试用/宽限/过期，含 30 天边界）；到期日三分支重算（试用转正/临期续/过期续）。
- **HTTP 测试（真实 dev 库，合成高号段租户，finally 清理）**：注册带 cycle → 第一张 unpaid 账单生成且金额快照正确；pending 租户确认收款被拒；approve 后 expires_at = 审核时刻；平台确认收款 → 账单 paid + 到期日按分支重算 + 重复确认幂等拒绝；自助续费生成新账单；过期租户登录被拦（把合成租户 expires_at 改到 31 天前）；租户 token 只能读本租户账单。
- **守卫**：`guard_no_raw_business_sql`（BillRepo 遵守仓储纪律）+ 全量回归。
- **手工验收**：注册→不付款→approve→外壳红条幅（试用期文案）→平台确认收款→刷新后条幅消失、到期日正确→临期条幅（改 expires_at 到 15 天后）→超宽限登录拦截文案→清理合成数据。

## 6. 风险与边界

- **控制面无 parity 测试**（schema parity 只管数据面 21 表）：迁移的正确性靠「information_schema 探查 + 幂等重跑」与 dev/breeder 双跑验证，发布清单里作为硬步骤。
- **菜单补插迁移是首个「平台向存量租户下发权限」的实例**：幂等键依赖 `sys_permission` 的租户内唯一键（迁移前先查实际键名，按 AGENTS.md 规矩不假设键名）。
- **时区**：`expires_at` 与 `now` 都用 MySQL 服务器时间（`App::now()`/SQL NOW()），剩余天数按自然日计算，避免应用/DB 时区漂移。
- **试用即宽限的实现耦合**：试用 = 「未付款 + expires_at=审核日」的宽限态，不新增状态列；若将来要独立试用营销（如 14 天试用），只改 approve 时的初始 expires_at 赋值，状态机不动。
- **demo 租户 NULL**：条幅、拦截、账单页均对 NULL 直通，存量租户零感知。
