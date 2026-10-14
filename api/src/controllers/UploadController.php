<?php
namespace QG\controllers;

use QG\App;
use QG\Auth;
use QG\Db;
use QG\Req;
use QG\Response;
use QG\Router;

class UploadController
{
    public static function routes(Router $r): void
    {
        $r->post('/upload', [self::class, 'upload']);
        $r->get('/files', [self::class, 'index']);
        $r->post('/files', [self::class, 'attach']);
        $r->del('/files/{id}', [self::class, 'destroy']);
        $r->get('/dict/options', [self::class, 'options']);
    }

    public static function upload(): void
    {
        Auth::requireLogin();
        if (empty($_FILES['file'])) {
            Response::fail('未收到文件');
        }
        $f    = $_FILES['file'];
        $cfg  = App::config('app');
        if ($f['error'] !== UPLOAD_ERR_OK) {
            Response::fail('上传失败，错误码：' . $f['error']);
        }
        if ($f['size'] > (int) $cfg['max_upload']) {
            Response::fail('文件超过大小限制：' . round($cfg['max_upload'] / 1048576, 1) . 'MB');
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $cfg['allow_ext'], true)) {
            Response::fail('不支持的文件类型：.' . $ext);
        }

        $sub = 'order/' . date('Y') . '/' . date('m');
        $dir = rtrim($cfg['upload_dir'], '/\\') . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            Response::fail('上传目录不可写：' . $dir);
        }
        $base = date('YmdHis') . '_' . App::rand(6) . '.' . $ext;
        $dest = $dir . '/' . $base;
        if (!@move_uploaded_file($f['tmp_name'], $dest)) {
            Response::fail('文件保存失败，请检查目录权限');
        }

        $rel = $sub . '/' . $base;
        Response::ok([
            'file_name' => $f['name'],
            'file_path' => $rel,
            'url'       => rtrim($cfg['upload_url'], '/') . '/' . $rel,
            'file_size' => (int) $f['size'],
        ], '上传成功');
    }

    public static function index(): void
    {
        Auth::requireLogin();
        $orderId = Req::int('order_id');
        $rows = Db::all('SELECT * FROM biz_order_file WHERE order_id = ? ORDER BY id DESC', [$orderId]);
        foreach ($rows as &$r) {
            $r['url'] = rtrim(App::config('app')['upload_url'], '/') . '/' . ltrim($r['file_path'], '/');
        }
        unset($r);
        Response::ok($rows);
    }

    public static function attach(): void
    {
        Auth::requireLogin();
        $orderId = Req::int('order_id');
        if (!Db::val('SELECT id FROM biz_order WHERE id = ? AND is_deleted = 0', [$orderId])) {
            Response::fail('订单不存在');
        }
        $path = Req::require_('file_path', '文件路径');
        $id = Db::insert('biz_order_file', [
            'order_id'    => $orderId,
            'file_type'   => Req::str('file_type'),
            'file_name'   => Req::str('file_name') ?: basename($path),
            'file_path'   => $path,
            'file_size'   => Req::int('file_size'),
            'uploaded_by' => Auth::id(),
            'created_at'  => App::now(),
        ]);
        Db::insert('biz_order_log', [
            'order_id' => $orderId, 'action' => '上传附件',
            'new_value' => Req::str('file_name'),
            'operator_id' => Auth::id(), 'created_at' => App::now(),
        ]);
        Response::ok(['id' => $id], '已关联到订单');
    }

    public static function destroy(array $p): void
    {
        Auth::requireLogin();
        $id  = (int) $p['id'];
        $row = Db::one('SELECT * FROM biz_order_file WHERE id = ?', [$id]);
        if (!$row) {
            Response::fail('文件不存在');
        }
        $full = rtrim(App::config('app')['upload_dir'], '/\\') . '/' . ltrim($row['file_path'], '/');
        if (is_file($full)) {
            @unlink($full);
        }
        Db::exec('DELETE FROM biz_order_file WHERE id = ?', [$id]);
        Response::ok(null, '已删除');
    }

    /**
     * 公共下拉数据：字典 + 进口商 + 发货人 + 方案，供各页面复用。
     *
     * 注意：本接口**只校验登录，不校验角色** —— 任何登录账号拿到的数据完全一样。
     * 「某个角色看不到某条数据」不可能是这里造成的（全表就 4 条固定 SQL，
     * 没有任何用户 / 角色的过滤条件），见 部署说明.md「数据可见性与权限的关系」。
     * 2026-09-17 修正：注册时漏写默认 auth=true，等于把发货人 / 进口商名册
     * 和整份字典对匿名访问者公开，已收回登录校验。
     */
    public static function options(): void
    {
        Auth::requireLogin();
        $dicts = Db::all('SELECT dict_type, dict_key, dict_value FROM sys_dict WHERE status = 1 ORDER BY dict_type, sort, id');
        $grouped = [];
        foreach ($dicts as $d) {
            $grouped[$d['dict_type']][] = ['key' => $d['dict_key'], 'value' => $d['dict_value']];
        }
        Response::ok([
            'dict'     => $grouped,
            'importers' => Db::all('SELECT id, company_name, country, city, bond_no FROM org_company WHERE is_deleted = 0 AND status = 1 AND company_type = 2 ORDER BY company_name LIMIT 500'),
            'shippers'  => Db::all('SELECT id, company_name, country, state_province, city, zip_code, address, phone FROM org_company WHERE is_deleted = 0 AND status = 1 AND company_type = 1 ORDER BY company_name LIMIT 500'),
            'plans'     => Db::all('SELECT id, plan_code, plan_name, amount, applicable_port, customs_port, intro, notice FROM biz_plan WHERE status = 1 ORDER BY sort, id'),
        ]);
    }
}
