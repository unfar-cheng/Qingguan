<?php
namespace QG\controllers;

use QG\App;
use QG\Auth;
use QG\Billing;
use QG\ControlDb;
use QG\RateLimit;
use QG\Req;
use QG\Response;
use QG\Router;
use QG\TempPassword;
use QG\Tenant;
use QG\TenantProvisioner;
use QG\repos\BillRepo;
use QG\repos\SettingRepo;
use QG\repos\UserRepo;

/** 平台侧：超管登录 + 租户审核。全部路由在 /api/platform/* 下，令牌 scope=platform。 */
class PlatformController
{
    public static function routes(Router $r): void
    {
        $r->post('/platform/login', [self::class, 'login'], false);
        $r->post('/platform/logout', [self::class, 'logout']);
        $r->get('/platform/me', [self::class, 'me']);
        $r->get('/platform/tenants', [self::class, 'tenants']);
        $r->get('/platform/summary', [self::class, 'summary']);
        $r->get('/platform/audits', [self::class, 'audits']);
        $r->get('/platform/bills', [self::class, 'bills']);
        $r->post('/platform/bills/{id}/confirm', [self::class, 'confirmBill']);
        $r->post('/platform/bills/{id}/void', [self::class, 'voidBill']);
        $r->post('/platform/tenants/{id}/approve', [self::class, 'approve']);
        $r->post('/platform/tenants/{id}/reject', [self::class, 'reject']);
        $r->post('/platform/tenants/{id}/suspend', [self::class, 'suspend']);
        $r->post('/platform/tenants/{id}/enable', [self::class, 'enable']);
        $r->get('/platform/settings', [self::class, 'settingsGet']);
        $r->put('/platform/settings', [self::class, 'settingsPut']);
        $r->post('/platform/tenants/{id}/reset-admin-password', [self::class, 'resetAdminPassword']);
        $r->get('/platform/accounts', [self::class, 'accounts']);
        $r->post('/platform/accounts', [self::class, 'accountCreate']);
        $r->post('/platform/accounts/{id}/disable', [self::class, 'accountDisable']);
        $r->post('/platform/accounts/{id}/enable', [self::class, 'accountEnable']);
        $r->post('/platform/accounts/{id}/reset-password', [self::class, 'accountReset']);
        $r->post('/platform/password', [self::class, 'platformPassword']);
    }

    public static function login(): void
    {
        if (!RateLimit::allow('platform-login|' . App::remoteAddr(), 10, 600)) {
            Response::fail('尝试过于频繁，请 10 分钟后再试', 429, 429);
        }
        $username = Req::require_('username', '账号');
        $password = Req::str('password');
        if ($password === '') {
            Response::fail('密码不能为空');
        }
        $u = ControlDb::one('SELECT * FROM saas_platform_user WHERE username = ? AND status = 1 LIMIT 1', [$username]);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            Response::fail('账号或密码错误');
        }
        ControlDb::exec('UPDATE saas_platform_user SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
            [App::ip(), (int) $u['id']]);
        Auth::platformLogin($u);
        self::audit((int) $u['id'], 'login', null, '');
        unset($u['password_hash']);
        $u['scope'] = 'platform';
        Response::ok($u, '登录成功');
    }

    public static function logout(): void
    {
        Auth::requirePlatform();
        Auth::logout();
        Response::ok(null, '已退出');
    }

    public static function me(): void
    {
        Response::ok(Auth::requirePlatform());
    }

    public static function tenants(): void
    {
        Auth::requirePlatform();
        [$page, $size, $offset] = Req::page();
        $status = Req::str('status');
        $kw = trim(Req::str('keyword'));
        $where = '1=1';
        $bind  = [];
        if (in_array($status, ['pending', 'active', 'rejected', 'suspended'], true)) {
            $where .= ' AND t.status = ?';
            $bind[] = $status;
        }
        if ($kw !== '') {
            // 关键词：企业名 / 租户代码 / 联系人 / 手机 / 管理员账号（LIKE 走全表小扫描，租户表量级无压力）
            $where .= ' AND (t.name LIKE ? OR t.code LIKE ? OR t.contact_name LIKE ? OR t.contact_phone LIKE ? OR t.admin_username LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%';
            array_push($bind, $like, $like, $like, $like, $like);
        }
        $total = (int) ControlDb::val("SELECT COUNT(*) FROM saas_tenant t WHERE {$where}", $bind);
        $rows  = ControlDb::all(
            "SELECT t.id, t.code, t.name, t.mode, t.status, t.contact_name, t.contact_phone, t.contact_email,
                    t.admin_username, t.reject_reason, t.applied_at, t.reviewed_at, t.logo_url, t.db_name, t.expires_at,
                    p.name AS plan_name
               FROM saas_tenant t LEFT JOIN saas_plan p ON p.id = t.plan_id
              WHERE {$where} ORDER BY (t.status = 'pending') DESC, t.id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        Response::page($rows, $total, $page, $size);
    }

    /** 概览看板：一次拿齐首页数字卡与待办计数（口径与租户列表的有效期标签一致：30 天内到期 / 已过期待宽限） */
    public static function summary(): void
    {
        Auth::requirePlatform();
        Response::ok([
            'pending'       => (int) ControlDb::val("SELECT COUNT(*) FROM saas_tenant WHERE status = 'pending'"),
            'active'        => (int) ControlDb::val("SELECT COUNT(*) FROM saas_tenant WHERE status = 'active'"),
            'dedicated'     => (int) ControlDb::val("SELECT COUNT(*) FROM saas_tenant WHERE status = 'active' AND mode = 'dedicated'"),
            'expiring'      => (int) ControlDb::val(
                "SELECT COUNT(*) FROM saas_tenant WHERE status = 'active' AND expires_at IS NOT NULL
                   AND expires_at > NOW() AND expires_at <= DATE_ADD(NOW(), INTERVAL 30 DAY)"
            ),
            'expired'       => (int) ControlDb::val(
                "SELECT COUNT(*) FROM saas_tenant WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= NOW()"
            ),
            // 已过服务期但仍在宽限期（Billing::GRACE_DAYS = 30）内的家数；超宽限的 = expired - in_grace
            'in_grace'      => (int) ControlDb::val(
                "SELECT COUNT(*) FROM saas_tenant WHERE status = 'active' AND expires_at IS NOT NULL
                   AND expires_at <= NOW() AND expires_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"
            ),
            'unpaid_count'  => (int) ControlDb::val("SELECT COUNT(*) FROM saas_bill WHERE status = 'unpaid'"),
            'unpaid_amount' => (string) (ControlDb::val("SELECT COALESCE(SUM(amount), 0) FROM saas_bill WHERE status = 'unpaid'") ?? '0'),
            'paid_month'    => (string) (ControlDb::val(
                "SELECT COALESCE(SUM(amount), 0) FROM saas_bill
                  WHERE status = 'paid' AND paid_at IS NOT NULL AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
            ) ?? '0'),
            'accounts_on'   => (int) ControlDb::val('SELECT COUNT(*) FROM saas_platform_user WHERE status = 1'),
        ]);
    }

    /** 审计动作白名单：筛选参数只接受这些值，其余静默忽略（不 500、不注入） */
    private const AUDIT_ACTIONS = ['register', 'approve', 'reject', 'suspend', 'enable', 'login', 'bill_paid', 'bill_void',
        'provision_failed', 'admin_reset', 'account_create', 'account_disable', 'account_enable', 'account_reset',
        'account_password', 'setting_update'];

    /** 审计日志（只读）：时间倒序，可按动作与目标租户（代码）筛选。platform_user_id 为空 = 申请人自注册。 */
    public static function audits(): void
    {
        Auth::requirePlatform();
        [$page, $size, $offset] = Req::page();
        $where = '1=1';
        $bind  = [];
        $action = Req::str('action');
        if ($action !== '' && in_array($action, self::AUDIT_ACTIONS, true)) {
            $where .= ' AND a.action = ?';
            $bind[] = $action;
        }
        $code = strtolower(trim(Req::str('tenant')));
        if ($code !== '') {
            $tid = ControlDb::val('SELECT id FROM saas_tenant WHERE code = ? LIMIT 1', [$code]);
            // 不存在的租户代码返回恒空集而不是忽略条件——运营不会把「查无此租户」误读成「该租户没有审计记录」
            if (!$tid) {
                Response::page([], 0, $page, $size);
            }
            $where .= ' AND a.target_type = ? AND a.target_id = ?';
            $bind[] = 'tenant';
            $bind[] = (int) $tid;
        }
        $total = (int) ControlDb::val("SELECT COUNT(*) FROM saas_audit a WHERE {$where}", $bind);
        $rows  = ControlDb::all(
            "SELECT a.id, a.action, a.target_id, t.code AS tenant_code, t.name AS tenant_name,
                    p.username AS operator, a.detail, a.ip, a.created_at
               FROM saas_audit a
               LEFT JOIN saas_tenant t ON t.id = a.target_id
               LEFT JOIN saas_platform_user p ON p.id = a.platform_user_id
              WHERE {$where} ORDER BY a.id DESC LIMIT {$size} OFFSET {$offset}",
            $bind
        );
        Response::page($rows, $total, $page, $size);
    }

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
        if (mb_strlen($ref) > 100) {
            Response::fail('支付参考号不能超过 100 字');
        }
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
            $n = UserRepo::forceResetByUsername($t['admin_username'], password_hash($temp, PASSWORD_DEFAULT));
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

    public static function approve(array $p): void
    {
        $admin = Auth::requirePlatform();
        $t = self::findTenant($p);
        if ($t['status'] === 'active') {
            Response::ok(null, '该租户已是开通状态（幂等）');
        }
        if ($t['status'] !== 'pending') {
            Response::fail('当前状态不可审核通过：' . $t['status']);
        }
        try {
            TenantProvisioner::provision($t, (int) $admin['id']);
        } catch (\Throwable $e) {
            // 并发 approve 兜底：两个管理员同时点「通过」时，loser 会撞 uk_tenant_username 的 1062。
            // 此时先重读控制面状态，若已是 active 则按幂等成功处理（任务 3 质量审查的结论）。
            $now = ControlDb::one('SELECT status FROM saas_tenant WHERE id = ? LIMIT 1', [(int) $t['id']]);
            if (($now['status'] ?? '') === 'active') {
                Response::ok(null, '该租户已在其他请求中开通（幂等）');
            }
            self::audit((int) $admin['id'], 'provision_failed', (int) $t['id'], mb_substr($e->getMessage(), 0, 500));
            Response::fail('开通失败：' . $e->getMessage());
        }
        Response::ok(null, '已开通');
    }

    public static function reject(array $p): void
    {
        $admin = Auth::requirePlatform();
        $t = self::findTenant($p);
        if ($t['status'] !== 'pending') {
            Response::fail('仅待审核的租户可驳回');
        }
        $reason = Req::str('reason');
        if ($reason === '') {
            Response::fail('驳回原因不能为空');
        }
        if (mb_strlen($reason) > 200) {
            Response::fail('驳回原因不能超过 200 字');
        }
        // 状态守卫：只在仍为 pending 时落驳回（approve 的最终 UPDATE 同样带 AND status='pending'，
        // 两边都不会把对方的结果无条件覆盖）。
        $n = ControlDb::exec(
            "UPDATE saas_tenant SET status = 'rejected', reject_reason = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ? AND status = 'pending'",
            [$reason, (int) $admin['id'], (int) $t['id']]
        );
        if ($n === 0) {
            Response::fail('租户状态已变化，请刷新后重试');
        }
        ControlDb::exec(
            "UPDATE saas_bill SET status = 'void', void_reason = '注册申请被驳回' WHERE tenant_id = ? AND status = 'unpaid'",
            [(int) $t['id']]
        );
        self::audit((int) $admin['id'], 'reject', (int) $t['id'], $reason);
        Response::ok(null, '已驳回');
    }

    public static function suspend(array $p): void
    {
        $admin = Auth::requirePlatform();
        $t = self::findTenant($p);
        if ($t['status'] !== 'active') {
            Response::fail('仅已开通的租户可停用');
        }
        ControlDb::exec("UPDATE saas_tenant SET status = 'suspended' WHERE id = ?", [(int) $t['id']]);
        self::audit((int) $admin['id'], 'suspend', (int) $t['id'], '');
        Response::ok(null, '已停用');
    }

    public static function enable(array $p): void
    {
        $admin = Auth::requirePlatform();
        $t = self::findTenant($p);
        if ($t['status'] !== 'suspended') {
            Response::fail('仅已停用的租户可重新启用');
        }
        ControlDb::exec("UPDATE saas_tenant SET status = 'active' WHERE id = ?", [(int) $t['id']]);
        self::audit((int) $admin['id'], 'enable', (int) $t['id'], '');
        Response::ok(null, '已启用');
    }

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
        $body  = Req::all();
        // 先全量校验再写入：多键白名单未来扩展时不会出现“前键已写、后键报错”的半更新
        $plan = [];
        foreach (self::SETTING_KEYS as $key) {
            if (!array_key_exists($key, $body)) { continue; }   // 未提交的键保持不动
            $v = Req::str($key);
            if ($v !== '' && mb_strlen($v) > 2000) {
                Response::fail($key . ' 不能超过 2000 字');
            }
            $plan[$key] = $v;
        }
        if (!$plan) {
            Response::fail('没有需要更新的设置项');
        }
        $updated = [];
        $cleared = [];
        foreach ($plan as $key => $v) {
            if ($v === '') {                                     // 显式空串 = 清空，回落代码侧兜底
                SettingRepo::delete($key);
                $cleared[] = $key;
                continue;
            }
            SettingRepo::put($key, $v, (int) $admin['id']);
            $updated[] = $key;
        }
        $parts = [];
        if ($updated) { $parts[] = '更新 ' . implode(',', $updated); }
        if ($cleared) { $parts[] = '清空 ' . implode(',', $cleared); }
        self::audit((int) $admin['id'], 'setting_update', null, '平台设置：' . implode('；', $parts));
        Response::ok(SettingRepo::all(self::SETTING_KEYS), '设置已保存');
    }

    private static function findTenant(array $p): array
    {
        $t = ControlDb::one('SELECT * FROM saas_tenant WHERE id = ? LIMIT 1', [(int) ($p['id'] ?? 0)]);
        if (!$t) {
            Response::fail('租户不存在');
        }
        return $t;
    }

    private static function audit(int $platformUserId, string $action, ?int $targetId, string $detail): void
    {
        ControlDb::insert('saas_audit', [
            'platform_user_id' => $platformUserId,
            'action'           => $action,
            'target_type'      => $targetId ? 'tenant' : '',
            'target_id'        => $targetId,
            'detail'           => $detail,
            'ip'               => App::ip(),
        ]);
    }
}
