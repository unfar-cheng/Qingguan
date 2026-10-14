<?php
namespace QG\repos;

use QG\Db;
use QG\Tenant;

class FileRepo
{
    /** 某订单的附件列表（对齐 UploadController::index） */
    public static function listByOrder(int $orderId): array
    {
        return Db::all('SELECT * FROM biz_order_file WHERE tenant_id = ? AND order_id = ? ORDER BY id DESC',
            [Tenant::requireId(), $orderId]);
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM biz_order_file WHERE tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    public static function create(array $data): int
    {
        $data['tenant_id'] = Tenant::requireId();
        return Db::insert('biz_order_file', $data);
    }

    public static function delete(int $id): int
    {
        return Db::exec('DELETE FROM biz_order_file WHERE tenant_id = ? AND id = ?', [Tenant::requireId(), $id]);
    }

    /** 订单是否属于本租户且未删除（对齐 UploadController::attach 的存在性校验） */
    public static function orderExists(int $orderId): bool
    {
        return (bool) Db::val('SELECT id FROM biz_order WHERE tenant_id = ? AND id = ? AND is_deleted = 0',
            [Tenant::requireId(), $orderId]);
    }
}
