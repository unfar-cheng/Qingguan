<?php
namespace QG\controllers;

use QG\App;
use QG\Auth;
use QG\ControlDb;
use QG\RateLimit;
use QG\Req;
use QG\Response;
use QG\Router;
use QG\Tenant;
use PDOException;

/** 租户侧公开接口（注册/品牌）+ 租户管理员 Logo 上传。 */
class TenantController
{
    public static function routes(Router $r): void
    {
        $r->get('/plans/public', [self::class, 'plans'], false);
        $r->get('/tenant/code-check', [self::class, 'codeCheck'], false);
        $r->post('/tenant/register', [self::class, 'register'], false);
        $r->get('/tenant/branding', [self::class, 'branding'], false);
        $r->post('/tenant/logo', [self::class, 'logo']);
    }

    /** 注册页渲染用：启用的套餐（不含敏感字段） */
    public static function plans(): void
    {
        $rows = ControlDb::all(
            'SELECT id, code, name, mode, price_month, price_year, max_users, max_orders, remark
               FROM saas_plan WHERE status = 1 ORDER BY sort, id'
        );
        Response::ok($rows);
    }

    public static function codeCheck(): void
    {
        $code = strtolower(trim(Req::str('code')));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,29}$/', $code)) {
            Response::ok(['available' => false, 'msg' => '格式：2-30 位小写字母、数字或短横线']);
        }
        $exists = (bool) ControlDb::val('SELECT COUNT(*) FROM saas_tenant WHERE code = ?', [$code]);
        Response::ok(['available' => !$exists, 'msg' => $exists ? '该代码已被占用' : '']);
    }

    public static function register(): void
    {
        if (!RateLimit::allow('register|' . App::remoteAddr(), 5, 3600)) {
            Response::fail('提交过于频繁，请 1 小时后再试', 429, 429);
        }
        $name = Req::require_('name', '企业名称');
        if (mb_strlen($name) > 100) {
            Response::fail('企业名称不能超过 100 字');
        }
        $code = strtolower(trim(Req::require_('code', '租户代码')));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,29}$/', $code)) {
            Response::fail('租户代码需为 2-30 位小写字母、数字或短横线');
        }
        $contactName = Req::require_('contact_name', '联系人');
        if (mb_strlen($contactName) > 50) {
            Response::fail('联系人不能超过 50 字');
        }
        $phone = trim(Req::str('contact_phone'));
        $email = trim(Req::str('contact_email'));
        if ($phone === '' && $email === '') {
            Response::fail('手机号与邮箱至少填一个');
        }
        if (mb_strlen($phone) > 30) {
            Response::fail('手机号不能超过 30 位');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::fail('邮箱格式不正确');
        }
        if (mb_strlen($email) > 100) {
            Response::fail('邮箱不能超过 100 字');
        }
        $adminUsername = Req::require_('admin_username', '管理员账号');
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $adminUsername)) {
            Response::fail('管理员账号需为 3-30 位字母、数字或下划线');
        }
        $password = Req::str('password');
        if (strlen($password) < 8) {
            Response::fail('密码至少 8 位');
        }
        if (strlen($password) > 72) {
            Response::fail('密码最长 72 位');
        }
        $planId = Req::int('plan_id');
        $plan = ControlDb::one('SELECT * FROM saas_plan WHERE id = ? AND status = 1 LIMIT 1', [$planId]);
        if (!$plan) {
            Response::fail('套餐不存在或已停用');
        }
        if (ControlDb::val('SELECT COUNT(*) FROM saas_tenant WHERE code = ?', [$code])) {
            Response::fail('该租户代码已被占用');
        }
        try {
            $id = ControlDb::insert('saas_tenant', [
                'code'                => $code,
                'name'                => $name,
                'mode'                => $plan['mode'],
                'plan_id'             => $planId,
                'status'              => 'pending',
                'contact_name'        => $contactName,
                'contact_phone'       => $phone,
                'contact_email'       => $email,
                'admin_username'      => $adminUsername,
                'admin_password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'applied_at'          => App::now(),
            ]);
        } catch (PDOException $e) {
            // 并发注册同 code：预检查与插入之间撞 saas_tenant.uk_code（23000），转友好报错（参照 PlatformController::approve 的并发兜底先例）。
            if ($e->getCode() === '23000') {
                Response::fail('该租户代码已被占用，请换一个');
            }
            throw $e;
        }
        ControlDb::insert('saas_audit', [
            'platform_user_id' => null,
            'action'           => 'register',
            'target_type'      => 'tenant',
            'target_id'        => $id,
            'detail'           => json_encode(['code' => $code, 'name' => $name, 'plan' => $plan['code']], JSON_UNESCAPED_UNICODE),
            'ip'               => App::ip(),
        ]);
        Response::ok(['id' => $id], '申请已提交，审核通过后即可登录');
    }

    /** 登录页失焦拉品牌：只暴露名称与 Logo，不返回任何业务数据 */
    public static function branding(): void
    {
        $code = strtolower(trim(Req::str('code')));
        $t = $code === '' ? null
            : ControlDb::one('SELECT name, logo_url FROM saas_tenant WHERE code = ? LIMIT 1', [$code]);
        Response::ok([
            'found'    => (bool) $t,
            'name'     => $t['name'] ?? '',
            'logo_url' => $t['logo_url'] ?? '',
        ]);
    }

    /** 租户管理员上传 Logo：uploads/t<tid>/branding/logo.<ext>，URL 写回控制面 */
    public static function logo(): void
    {
        Auth::requireLogin();
        if (!Auth::isTenantAdmin()) {
            Response::fail('仅租户管理员可上传 Logo', 403, 403);
        }
        $tid = Tenant::requireId();
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::fail('请选择要上传的图片');
        }
        if ((int) $f['size'] > 2 * 1024 * 1024) {
            Response::fail('Logo 不能超过 2MB');
        }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            Response::fail('仅支持 PNG/JPG 图片');
        }
        $mime = mime_content_type($f['tmp_name']);
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            Response::fail('文件内容不是有效的图片');
        }
        $cfg = App::config('app');
        $sub = 't' . $tid . '/branding';
        $dir = rtrim($cfg['upload_dir'], '/\\') . '/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            Response::fail('无法创建存储目录');
        }
        if (!move_uploaded_file($f['tmp_name'], $dir . '/logo.' . $ext)) {
            Response::fail('保存文件失败');
        }
        // 先落盘成功再清旧图：只删与本次扩展名不同的文件，move 失败时旧 Logo 不会被误删。
        foreach (glob($dir . '/logo.*') ?: [] as $old) {
            if (basename($old) !== 'logo.' . $ext) {
                @unlink($old);
            }
        }
        $url = rtrim($cfg['upload_url'], '/') . '/' . $sub . '/logo.' . $ext;
        ControlDb::exec('UPDATE saas_tenant SET logo_url = ? WHERE id = ?', [$url, $tid]);
        Response::ok(['logo_url' => $url], 'Logo 已更新');
    }
}
