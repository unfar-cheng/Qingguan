# P0-B+C · 账号与密码管理 设计

- 日期：2026-10-16
- 状态：设计已确认（临时密码+首登强改、等权账号+三条保护规则均经选择拍板）；待写成实现计划
- 范围拆分：P0-B 租户管理员密码代重置 与 P0-C 平台账号管理 合并为一份规格（同属「账号与密码」域，共享临时密码与强制改密机制）
- 前置：账单与有效期已上线（`docs/superpowers/specs/2026-10-16-billing-expiry-design.md`）；平台审计日志页已上线

## 1. 背景与目标

两处账号管理的空缺：

1. **租户管理员忘记密码没有任何恢复路径**：开通后 `admin_password_hash` 即清空为不可逆哈希，平台无法代重置，客户锁死只能等死。
2. **平台账号只有 CLI 播种一条路**：无法新增运营人员、无法停用离职账号、平台管理员自己改密码也没有接口。

目标：平台可一键代重置租户管理员密码（系统生成临时密码、一次性展示、首登强制改密）；平台账号获得完整的增/停/启/重置/自改能力。等权模型，本期不做角色区分。

### 已拍板的决策

| 决策点 | 选择 |
|---|---|
| 临时密码形态 | **系统生成 12 位随机**（含大小写+数字+符号，剔除易混淆字符 0O1lI），响应一次性返回，不落明文 |
| 首登强改 | **是**：`must_change_password` 标记，登录后强制改密弹窗（不可关闭），改密成功才放行 |
| 平台账号权限 | **等权**（无角色列）；保护规则三条：不能停用自己、不能停用最后一个启用中的账号、只停用不物理删除 |
| 重置与停用的关系 | 重置顺带启用（status=1），语义为「平台主动恢复该账号访问」 |

### 非目标（YAGNI）

- 平台角色区分（超管/只读运营）——将来加 `role` 列即可，本期接口设计不预设。
- 平台账号物理删除（审计与登录历史保留，停用足够）。
- 邮件/短信投递临时密码（P2 触达项目；当前线下交付）。
- 租户普通用户的密码管理（租户管理员在「系统设置 → 用户管理」自助，现有功能）。
- 「忘记密码」自助找回（无邮件设施；当前路径 = 联系平台代重置）。

## 2. 数据模型（两份迁移 + 两份 schema 同步）

### 2.1 控制面（`qingguan_control[_dev]`）

```sql
ALTER TABLE `saas_platform_user`
  ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '首登强制改密' AFTER `status`;
```

迁移文件 `db/migration_20261016_platform_account.sql`（幂等惯例：information_schema 探查 + PREPARE；`SET NAMES utf8mb4`）；`db/control/schema.sql` 的 `saas_platform_user` DDL 同步。

### 2.2 数据面（`qingguan[_dev]` 与各专用库）

```sql
ALTER TABLE `sys_user`
  ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '首登强制改密' AFTER `status`;
```

迁移文件 `db/migration_20261016_user_force_change.sql`。**执行面 = 共享库手工跑 + 专用库 `php83 scripts/migrate_dedicated.php <file>` 扇出**（dev 有 `qingguan_t_woniu`；生产专用库随首个专用租户开通出现，发布时扇出 dry-run 确认清单）。**数据面改表会触发 `tests/test_schema_parity.php` 红灯** → `db/schema.sql` 的 `sys_user` DDL 同步是硬步骤（新增列放在 status 之后，与迁移一致）。

## 3. 临时密码生成器（`api/src/TempPassword.php`）

纯静态：

```php
TempPassword::generate(int $length = 12): string
```

- 字符集：大小写字母 + 数字 + `!@#$%^&*`，剔除易混淆字符 `0O1lI`；
- 用 `random_int`（密码学安全）；长度默认 12（满足平台账号 ≥10 与租户 ≥8 的两边策略）；
- 单测锁：长度、字符集闭合、连续多次生成不重复。

## 4. B · 租户管理员密码代重置

### 4.1 接口

`POST /api/platform/tenants/{id}/reset-admin-password`（PlatformController，requirePlatform）：

1. 租户须存在且 `status='active'`（否则 fail）；
2. `TempPassword::generate()` 生成临时密码，`password_hash` 落库；
3. **建立租户上下文**（`Tenant::set(['id','mode','db_name','code'])`，取自 saas_tenant 行——专用档自动走派生凭据连接），执行数据面 `UPDATE sys_user SET password_hash = ?, must_change_password = 1, status = 1 WHERE tenant_id = ? AND username = ?`（username 取租户 `admin_username`；0 行 = 管理员不存在，fail「租户管理员账号不存在」），finally `Tenant::set(null)`；
4. 审计 `admin_reset`（detail：「重置管理员账号 xxx 的密码（临时密码已线下交付）」——**不含明文**）；
5. 响应 `data: { temp_password }` ——**一次性**：服务端不存明文、审计与日志零明文。

### 4.2 强制改密链路

- `AuthController::login` 成功后查数据面 `must_change_password`，响应 data 加该布尔；`/auth/me` 同样带出（`UserRepo::findWithRole` 查询为 `sys_user` 全列，新列自动带出——实现时验证）。
- `AuthController::password`（现有改密接口）成功分支清零：`UPDATE sys_user SET must_change_password = 0 WHERE id = ?`。
- 前端 `index.html`：`created` 里 `/auth/me` 返回 `must_change_password === true` → 弹**不可关闭**的强改对话框（复用现有「修改密码」表单加 force 模式：隐藏取消、遮罩不可点掉、改密成功才关闭放行；旧密码 = 临时密码）。强改完成前不渲染外壳菜单数据接口调用拦截无必要——接口层本就有鉴权，强改是 UX 约束。

### 4.3 前端入口

- `platform.html` 租户列表 active 行操作区加「重置密码」按钮：`QG.confirm` 提示「将生成临时密码，请通过线下渠道告知客户，客户首登须改密」→ 调接口 → 弹窗展示临时密码（大字号等宽 + 复制按钮 + 「我已线下交付」关闭确认）。

## 5. C · 平台账号管理

### 5.1 接口（PlatformController 扩展，全部 requirePlatform）

| 路由 | 行为 |
|---|---|
| `GET /api/platform/accounts` | 列表：id/username/real_name/email/status/must_change_password/last_login_at/created_at（不含 hash），按 id 正序 |
| `POST /api/platform/accounts` | 新增：username（3-30 位 `[a-zA-Z0-9_]`，唯一校验，撞 uk 友好报错）、real_name 必填、email 可选、生成临时密码（`must_change_password=1`）→ 审计 `account_create` → 响应一次性返回 `temp_password` |
| `POST /api/platform/accounts/{id}/disable` | 停用（status=0）：**不能停用自己**；事务内 `COUNT(status=1)` 校验**至少保留一个启用账号**（不满足 fail「至少保留一个启用中的平台账号」）；审计 `account_disable` |
| `POST /api/platform/accounts/{id}/enable` | 启用（status=1）；审计 `account_enable` |
| `POST /api/platform/accounts/{id}/reset-password` | 生成临时密码 + `must_change_password=1` + `status=1`（重置即启用）；审计 `account_reset`；响应一次性 `temp_password` |
| `POST /api/platform/password` | 自己改自己：old_password 验证、new ≥10 位；成功清零 `must_change_password`；审计 `account_password`（不写新旧密码） |

### 5.2 强制改密链路（平台侧）

- `PlatformController::login` 响应与 `/platform/me` 带 `must_change_password`；
- `POST /api/platform/password` 成功清零；
- 前端 `platform.html`：`created` 的 `/platform/me` 探测处检测标记 → 弹不可关闭强改对话框（同 §4.2 force 模式，旧密码 = 临时密码）。

### 5.3 前端

- `platform.html` 第四视图「账号」：账号表（用户名/姓名/邮箱/状态徽标/最近登录/创建时间）+ 「新增账号」按钮（表单：用户名/姓名/邮箱 → 成功弹窗一次性展示临时密码 + 复制）+ 行操作（启用中：停用/重置密码；已停用：启用/重置密码）；
- 页头加「修改密码」按钮（对话框：旧密码/新密码/确认，走 `POST /api/platform/password`）。

## 6. 测试策略

- **单测**：`TempPassword::generate`（长度默认 12、字符集闭合无 0O1lI、500 次生成无重复）。
- **HTTP**（合成租户 920xxx 段 + 播种管理员，惯例照 test_billing_http）：
  1. 租户代重置全链路：平台重置 → 旧密码登录失败 → 临时密码登录成功且响应/me 带 `must_change_password=true` → 改密成功 → flag 清零、临时密码失效、新密码可用；
  2. 平台账号：新增（临时密码 + flag）→ 停用后登录被拒「账号或密码错误」语义（status=0 不匹配）→ 启用 → 重置 → 强改链路；
  3. 保护规则：停自己 → fail；只剩一个启用账号时停它 → fail「至少保留一个启用中的平台账号」；
  4. 平台改密：旧密码错 → fail；成功 → flag 清零。
- **守卫**：全量回归 + guard；schema parity（sys_user 新列）转绿即证明 schema.sql 同步正确。
- **手工验收**：浏览器过两处强改对话框（失败可重试、成功才放行、刷新不掉）与临时密码一次性展示/复制。

## 7. 风险与边界

- **强改对话框是「锁死风险」单点**：实现缺陷会把用户挡在系统外——测试重点 = 改密成功即解除、失败不关闭可重试、接口失败不吞错误；数据兜底 = 平台可对同一账号再次代重置（生成新临时密码重走流程）。
- **数据面迁移扇出**：生产专用库随 pengcheng 开通出现后，发布时扇出 dry-run 清单必须为空（遗漏 = 专用库管理员重置时报「列不存在」）；发布顺序 = 迁移先行。
- **临时密码交付是线下信任链**：系统只保证「一次性生成 + 强制首改」，泄露窗口在线下（与行业惯例一致）。
- **审计零明文**：临时密码不进 `saas_audit`/日志/响应缓存；响应标注一次性展示。
- **兼容**：`must_change_password` 默认 0，存量账号（platform 与全部租户用户）零感知。
