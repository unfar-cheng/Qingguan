# 清关管理系统 · 自助开通与品牌 设计

- 日期：2026-10-15
- 状态：**已实现（2026-10-15）**——实现计划 `plans/2026-10-15-self-service-onboarding-branding.md`（13 个任务全部完成；基线 `60 passed, 0 failed` + `guard ok`）。残留二期项见 §10。
- 影响范围：单租户/手工开通 → 企业自助注册、平台审核、自动开通播种、租户 Logo 与附件命名空间
- 前置：`db/schema.sql` 已于 2026-10-15 升格为**数据面结构唯一权威**（结构 + 租户参数化默认数据，幂等），本设计的「开通播种」直接复用它。

## 1. 背景与目标

多租户 SaaS 一期地基（控制面 + 仓储层 + 租户代码登录 + 共享档隔离）已完成，控制面模型（`saas_plan` / `saas_tenant` / `saas_platform_user` / `saas_audit`）与 `Auth` 的 `scope=platform` 分支都已就位，但**平台侧还没有任何 HTTP 入口**，开通租户目前全靠手工执行 SQL。

本设计把「企业自助注册 → 平台审核 → 自动开通 → 登录可用」这条链路打通，并补上两块品牌能力：租户 Logo 与附件按租户分目录。

目标：

1. 企业访问公开注册页，填表提交 → 控制面落一条 `saas_tenant(status=pending)`。
2. 平台超管在独立后台看到待审列表，一键「通过 / 驳回」，操作留痕。
3. 「通过」时**幂等地**完成开通：播种该租户的默认数据、创建管理员账号、置 `active`，申请人即可用「租户代码 + 账号」登录。
4. 租户管理员可上传 Logo，登录页与系统外壳展示。
5. 附件路径按租户隔离：`uploads/t<tid>/order/Y/m/`。

### 非目标（YAGNI，本设计不做）

- **专用档（物理隔离）自动开通**：一期只做共享档端到端；专用档的「建库 / 建独立账号 / 灌库」留好挂点但归二期（它需要额外的 MySQL 建库权限与连接管理，单独做）。
- 在线计费 / 支付网关（套餐只做「展示 + 选择隔离档」）。
- 审核结果**邮件通知**：查过代码，目前**没有任何发邮件的实现**（`sys-config.html` 只是收集 SMTP 参数），谈不上「复用」。本次用「登录时按状态给提示」兜住申请人体验，邮件通知等真有发信能力时再加。
- 平台字典模板的 HTTP 后台（`publish_dict.php` 的 Web 入口）——属于已暂缓的《运维基础设施》阶段 2，不在本设计。
- 平台代维 / impersonation、租户自定义域名、用户名全局唯一登录。

## 2. 现状与前提（设计必须正视的事实）

- **控制面库已建好**：`qingguan_control`（dev 是 `qingguan_control_dev`），4 张表齐备，`saas_plan` 已播 `basic`(共享 600/月) 与 `isolated`(独享 1500/月) 两条套餐。
- **`saas_tenant` 字段够用**：`code/name/logo_url/mode/plan_id/db_name/status/contact_*/admin_username/admin_password_hash/reject_reason/applied_at/reviewed_at/reviewed_by`，注册信息直接落这张表、不需要新表。
- **`Auth` 支持 `scope=platform`**（读 `saas_platform_user`），但**没有 controller/route**——`api/index.php` 里根本没注册平台登录。这是本设计要补的最大一块。
- **`Router` 只有「登录与否」的粗粒度门禁**（`$auth=true` → `Auth::requireLogin()`）。平台接口需要**按 scope 隔离**：平台接口不能被租户令牌调通，反之亦然。要加 `Auth::requirePlatform()` / `requireTenant()`。
- **`db/schema.sql` 现在就是开租脚本**：幂等、`SET @tenant_id := <id>` 指定目标租户、内含菜单/角色/授权/配置/字典的产品默认数据，不预置 `sys_user` 与业务数据。开通播种 = 把它按目标 `tenant_id` 跑一遍。
- **附件是 Apache 静态直出**：`file_path` 存相对路径（如 `order/2026/10/xxx.pdf`），URL = `upload_url + '/' + file_path`，不走 PHP。这决定了「命名空间」只能把 tid 编进路径，**防不住知道完整 URL 的直接访问**（见 §10 风险）。
- **`tenant_id` 默认值隐患已消除**（2026-10-15，`db/migration_20261015_drop_tenant_default.sql`）：21 张表的 `tenant_id` 已去掉 `DEFAULT 1`，漏传会直接报错而不是静默落到租户 1。

## 3. 范围与分期

| 阶段 | 内容 | 本次 |
|---|---|---|
| A | 平台登录接线 + 平台后台骨架 | ✅ |
| B | 公开注册页 + 注册接口（限流/校验） | ✅ |
| C | 审核（通过/驳回）+ 共享档开通播种 | ✅ |
| D | 租户 Logo（上传 + 两处展示） | ✅ |
| E | 附件按租户命名空间（写入改造 + 存量搬迁） | ✅ |
| F | 专用档自动开通（建库/建账号/灌库） | ❌ 二期，仅留挂点 |
| G | 审核邮件通知 | ❌ 无发信实现，暂缓 |

## 4. 总体架构与数据流

```
申请人                    平台超管                    系统
  │                        │                          │
  │  register.html         │                          │
  ├──── POST /api/tenant/register ───────────────────▶│ 限流(IP)+校验 → 控制面 saas_tenant(status=pending)
  │                        │                          │
  │                        │ admin-login.html          │
  │                        ├── POST /api/platform/login ─▶│ 校验 saas_platform_user → 发 scope=platform 令牌
  │                        │                          │
  │                        │ platform.html            │
  │                        ├── GET /api/platform/tenants?status=pending ▶ 待审列表
  │                        ├── POST …/tenants/{id}/approve ──▶│ TenantProvisioner::provision()（幂等）
  │                        │                          │     · 共享档：schema.sql 按 tid 播种 + 建管理员
  │                        │                          │     · status=active，记 saas_audit
  │                        ├── POST …/tenants/{id}/reject ──▶│ status=rejected + reject_reason，记 saas_audit
  │                        │                          │
  │  login.html            │                          │
  ├──── 租户代码 + 账号 + 密码 ───────────────────────▶│ 控制面查 saas_tenant → status=active → 发租户令牌
  │   （输入租户代码后失焦调 GET /api/tenant/branding 拉回名称 + Logo 渲染品牌）
```

要点：

- **三条入口页面**：`register.html`（公开）、`admin-login.html`（公开）、`platform.html`（平台后台，独立顶层页、不进租户 iframe 外壳）。品牌展示挂在既有 `login.html` 与 `index.html` 上。
- **开通是幂等函数** `TenantProvisioner::provision(tenant)`：重复调用不产生重复数据（播种靠 `db/schema.sql` 的幂等性，建管理员靠 `sys_user` 的 `uk_tenant_username`），失败留 `pending` + 记错误，可重试。
- **平台与租户令牌互不通用**：平台接口一律 `Auth::requirePlatform()`，租户接口维持现状。

## 5. 模块设计

### 5.1 平台登录接线（补最大缺口）

- `admin-login.html`：仿 `login.html` 的单表单（用户名 + 密码，**不要租户代码**），提交到 `POST /api/platform/login`。
- 新增 `api/src/controllers/PlatformController.php`（与现有 7 个 controller 同构），在 `api/index.php` 里注册。**路由统一放 `/api/platform/...` 前缀下**，便于集中鉴权。
- `Auth` 增强：
  - `Auth::requirePlatform()`：登录态且 `scope==='platform'`，否则 401/403。
  - `Auth::platformUser()`：返回 `saas_platform_user` 行。（实现时收窄为 `requirePlatform()` 直接返回 `Auth::user()` 的 platform 分支结果，功能等价，未单设该方法。）
  - 平台登录沿用现有 HMAC 令牌方案，payload `{scope:'platform', puid, exp}`；登录成功更新 `last_login_at/ip`。
- 登录接口与注册接口都要**限流**（见 5.2），防爆破与防灌共用一个小组件。

### 5.2 注册页与注册接口

- `register.html`（公开，不进菜单）：企业名称、租户代码（前端 slug 建议 + 实时唯一校验）、联系人、手机、邮箱、管理员账号、密码（×2）、套餐选择（`basic`/`isolated`，展示价格与隔离档说明）。样式仿 `login.html`。
- 接口（公开，不登录）：
  - `GET /api/plans/public`：返回启用的套餐（名称/价格/模式/说明），供注册页渲染。
  - `GET /api/tenant/code-check?code=xx`：租户代码可用性（格式 + 唯一），供失焦实时校验。
  - `POST /api/tenant/register`：字段校验 → `password_hash()` → 落 `saas_tenant(status=pending, mode=套餐的 mode, applied_at=now)`。
- **校验规则**：`code` 用 `^[a-z0-9][a-z0-9-]{1,29}$`（小写字母数字短横线、非纯数字开头），唯一；密码 ≥8 位；`contact_phone`/`contact_email` 至少填一个（用于审核与将来通知）；`plan_id` 必须存在且 `status=1`。
- **限流**：文件型滑动窗口限流器，放 `api/runtime/`（可写、不入库），按 `IP + 端点` 计数（注册 ≤5 次/小时，平台登录 ≤10 次/10 分钟）。选文件型而**不加控制面列/表**：不改控制面结构、无迁移，且这两处低频、单机部署足够。

### 5.3 审核后台与审计

- `platform.html`（平台后台，登录后进入）：待审列表（`status=pending`）+ 全部租户列表（可按状态筛），行内操作「通过 / 驳回 / 停用 / 重新启用」。用 `QG.mount` + `QG.listPage` 同款列表骨架，风格跟租户后台一致但独立成页。
- 接口（`Auth::requirePlatform()`）：
  - `GET /api/platform/tenants?status=…`：列表/分页。
  - `POST /api/platform/tenants/{id}/approve` → 调 `TenantProvisioner::provision()`。
  - `POST /api/platform/tenants/{id}/reject {reason}`。
  - `POST /api/platform/tenants/{id}/suspend` / `{id}/enable`。
- **审计**：每次审核/状态变更写 `saas_audit(action, target_type='tenant', target_id, detail=json, ip, platform_user_id)`。

### 5.4 开通播种（核心，幂等）

新增 `api/src/TenantProvisioner.php`：

```php
class TenantProvisioner
{
    /** 幂等。重复调用安全；任何一步失败都抛异常并把租户留在 pending。 */
    public static function provision(array $tenant, ?int $platformUserId): void;
}
```

`provision()` 步骤（共享档）：

1. 计算目标库：共享档 = `App::config('db')['name']`；专用档 = `qingguan_t_<code>`（二期，本期直接抛「专用档开通未开放」）。
2. 在目标连接上 `SET @tenant_id := <tid>`，然后**读 `db/schema.sql`、按语句切分、逐条执行**——DDL 段在共享库上是空操作（`IF NOT EXISTS`），真正落地的是该 `tenant_id` 的产品默认数据。
   - 切分器读文件、去注释行、按「行尾 `;`」切段。注意别在含 `\n` 的字符串里断行。
   - 选「PHP 内读文件 + PDO 执行」而**不是 shell 调 mysql CLI**：web 进程拿不到 mysql 客户端、也不该把库口令带到 shell；凭据全在 `config.php` 里。
3. 建管理员：按 `admin_username` + `admin_password_hash`（注册时已 `password_hash`）写入 `sys_user`，角色挂 `code='tenant_admin'`（由 `sys_role` 播种行解析），`tenant_id=<tid>`。靠 `uk_tenant_username` 幂等。
4. 事务外、全成功后：控制面 `status='active'`、`reviewed_at/by`、清空 `admin_password_hash`（落库成功就不该再留明文对应物），记 `saas_audit`。
5. 失败路径：播种/建管理员抛异常 → 控制面状态保持 `pending`，`saas_audit` 记 `provision_failed` + 错误信息，审核页允许「重试开通」。

> 修正《运维基础设施》计划里的一处表述：开通**单个**租户不是「逐库 fanout」——它只对**一个**目标库播种，用不到 `Fanout::run()`。`Fanout` 服务于「平台改一处、所有库都要动」的场景（迁移、字典下发）。两者别混。

### 5.5 Logo

- 存储：`uploads/t<tid>/branding/logo.<ext>`。只收 png/jpg/jpeg（**不接 SVG**，避免 XSS），≤2MB，用 `fileinfo` 复核真实 MIME（不只信扩展名）。
- 上传：`POST /api/tenant/logo`（`Auth::requireLogin()` + `Auth::isTenantAdmin()`）。存盘后把 URL 写回控制面 `saas_tenant.logo_url = '/uploads/t<tid>/branding/logo.<ext>'`。专用档租户的 Logo 同样落这台机的磁盘（文件不在数据库里），路径规则不变。
- 展示两处：
  1. 外壳 `index.html` 侧栏品牌区：`AuthController::me()` 多返回 `tenant_name` + `logo_url`，有 Logo 就渲染、没有就退回文字品牌。
  2. 登录页 `login.html`：租户代码输入框失焦 → `GET /api/tenant/branding?code=xx`（公开）→ 返回 `{name, logo_url}`，渲染企业名 + Logo；未填/查无 → 平台通用品牌。
- `GET /api/tenant/branding` 公开返回租户名 + Logo：属于品牌信息的常规暴露（同「输入企业代码看它家 Logo」），接受这个信息面，不返回任何业务数据。

### 5.6 附件按租户命名空间

- 写入改造：`UploadController` 里 `$sub = 't' . Tenant::requireId() . '/order/' . date('Y') . '/' . date('m');`，`file_path` 自此带上 `t<tid>/` 前缀，URL 静态直出规则不变（Apache 直接伺服 `/uploads/t1/order/...`）。
- **存量搬迁**（租户 #1 的旧文件）：一次性脚本 `scripts/migrate_uploads_namespace.php`，逐条处理 `biz_order_file` 里 `file_path` 形如 `order/…`（不含 `t` 前缀）的行：物理移动 `uploads/order/…` → `uploads/t<tid>/order/…`，并 `UPDATE file_path`。幂等（搬过的不重复），先干跑 `--dry-run` 出清单。
- 兼容：先改代码再跑搬迁。改完代码后**新上传直接进 `t<tid>/` 新路径**，旧文件在搬迁前还停在 `order/` 旧路径——因此过渡期里旧附件按旧 URL 仍能访问、新附件走新路径，两边都能看；搬迁脚本把旧文件挪走、改完 `file_path` 后，旧 `order/` 目录清空即收口。

## 6. 关键决策与备选

| 决策点 | 备选 | 推荐 | 理由 |
|---|---|---|---|
| 平台后台入口 | A. 同域 `/admin-login.html` + `/api/platform/*` + 独立 `platform.html`；B. 独立子域 `platform.…` + 独立 vhost/证书 | **A** | 不加 DNS/证书/vhost 的运维面；scope 隔离由 `requirePlatform()` 保证，安全边界不靠域名。 |
| 开通时机 | A. 审核通过时**同步**播种；B. 入队异步 | **A** | 申请量小，同步即可；幂等 + 可重试已兜住失败，不引入队列。 |
| 专用档开通 | A. 本期做；B. 本期只留挂点 | **B** | 需要 MySQL 建库授权与连接管理，是二期的独立工程；挂点 = `provision()` 里 mode 分支。 |
| 限流存储 | A. 控制面加表/列；B. `api/runtime/` 文件 | **B** | 零迁移、够用；这两处是低频公开端点。 |
| 审核通知 | A. 邮件；B. 登录时按状态提示 | **B** | 全仓库没有发信实现，谈不上复用；登录提示已能闭环「审核中 / 未通过(原因)」。 |
| 播种执行方式 | A. PHP 读 `db/schema.sql` + PDO；B. shell 调 mysql CLI | **A** | 不把凭据带进 shell，不依赖 mysql 客户端在 web 机上。 |

## 7. 数据模型变更

- **控制面**：复用现有 4 张表，**不新加表**。注册信息落 `saas_tenant(status=pending)`；审核动作落 `saas_audit`。
- **数据面**：`sys_user` / `sys_role` / `sys_permission` / `sys_role_permission` / `sys_config` / `sys_dict` 的默认数据由 `db/schema.sql` 按 `@tenant_id` 播，不改结构。
- **文件**：`uploads/t<tid>/branding/` 与 `uploads/t<tid>/order/` 新目录约定，靠存量搬迁脚本过渡。
- 结论：**本设计原则上不需要表结构迁移**；如后续给注册加「申请人 IP」留痕，再单独加一条控制面 `ALTER`（幂等写法）。

## 8. 运维与权限前置

- **共享档**开通不需要额外数据库权限（播种走应用现有的 `qingguan*` 账号）。
- **专用档**（二期）需要应用账号具备 `qingguan_t_%` 的 `CREATE` / `GRANT OPTION`，届时单独授权，别给全局 `*.*`。
- 平台超管账号：`saas_platform_user` 需要手工播一条（bcrypt 密码），控制面 CLI 一次性完成，不提供注册入口。
- vhost 维持现状：`register.html` / `admin-login.html` / `platform.html` 是正常静态页，已在站点根可访问；`platform.html` 的接口全部要求 `scope=platform`。

## 9. 错误处理

- 注册：字段级中文报错（沿用 `Response::fail` 信封）；限流命中回 429 语义（`code` 区分）。
- 开通失败：抛异常 → 控制面留 `pending` + `saas_audit` 记 `provision_failed`，审核页给「重试」；严禁把状态写成 `active` 半截。
- Logo：非图片 / 超尺寸 / MIME 不符 → 明确报错，不落半张图。
- 平台/租户令牌互调：`requirePlatform()` 对租户令牌回 403，反之 401。

## 10. 风险与开放问题

- **附件静态直出**：命名空间只把 tid 编进路径，防得住「顺序遍历 URL」，防不住「拿到完整 URL 直接访问」。若要真隔离，得把附件改走 PHP 鉴权下载（`serve` 接口 + `Auth`），那是另一个量级的改动，本次不做，列为后续。（同扩展名覆盖导致的浏览器缓存旧图，已用 `logo_url` 带 `?v=<time>` 版本参数缓解。）
- ~~**`tenant_id DEFAULT 1` 隐患**~~：已消除（见 §2）。
- **`saas_platform_user` 的初始账号**靠手工播，注意密码强度与保管。
- 平台后台是个新攻击面，上线前在 breeder 上把 `admin-login.html`/`platform.html` 的访问也过一遍 Web 拒绝规则的核对（它们应可访问，但 `/api/platform/*` 不能漏鉴权；`api/runtime/` 的拒绝段也要复制）。
- **Backlog（实现评审记录的遗留小项）**：① 被驳回/搁置租户的 `code` 永久占用，无清理/释放机制；② `saas_audit.detail` 存在 JSON（register）与纯文本（平台动作）两种格式，将来做审计展示要兼容；③ `/tenant/code-check`、`/tenant/branding` 无限流（设计接受的品牌信息公开边界，若观察到枚举滥用再加宽松档）；④ 登录时序侧信道（未知用户不跑 bcrypt）——与租户侧同款基线，量小时不值得修。

## 11. 测试策略

- **注册**：HTTP 用例——合法注册落 `pending`；`code` 重复/非法被拒；限流生效。
- **审核 + 开通**：HTTP 用例——平台登录 → 待审列表 → approve → 该租户能用「租户代码 + 管理员」登录且各模块可用 → 重复 approve 幂等不重复播种。
- **Logo**：上传 png 成功、`svg` 被拒、`branding` 接口按 code 返回名称/Logo。
- **附件命名空间**：上传后 `file_path` 带 `t<tid>/` 前缀且 URL 可取；搬迁脚本能重复跑且不再改动。
- **回归**：`php83 tests/run.php` 全绿 + `guard` ok + `test_schema_parity.php` 全绿。

## 12. 建议的实施顺序（供 writing-plans）

1. `Auth::requirePlatform()` + `PlatformController` + `admin-login.html` + `platform.html` 骨架 + 平台超管种子。
2. 限流器（`api/runtime/` 文件型）+ 注册接口 + `register.html` + `plans/public` + `code-check`。
3. `TenantProvisioner` + approve/reject/suspend/enable + `saas_audit`。
4. Logo（上传接口 + `me()` 返回 + 外壳品牌 + 登录页 branding）。
5. 附件命名空间（写入改造 + 存量搬迁脚本）。
6. 端到端回归 + 文档（本文件状态、AGENTS.md）。
