<?php
namespace QG\repos;

use QG\App;
use QG\Auth;
use QG\Db;
use QG\Tenant;

class LogRepo
{
    /** 操作日志分页（对齐 SystemController::logList，补 tenant_id） */
    public static function page(int $size, int $offset): array
    {
        $tid   = Tenant::requireId();
        $total = (int) Db::val('SELECT COUNT(*) FROM sys_log WHERE tenant_id = ?', [$tid]);
        $rows  = Db::all(
            "SELECT l.*, u.real_name AS operator_name FROM sys_log l
               LEFT JOIN sys_user u ON u.id = l.user_id AND u.tenant_id = l.tenant_id
              WHERE l.tenant_id = ? ORDER BY l.id DESC LIMIT {$size} OFFSET {$offset}",
            [$tid]
        );
        return [$rows, $total];
    }

    /** 写一条操作日志（对齐 App::log） */
    public static function write(string $module, string $action, $targetId = null, $detail = null): int
    {
        return Db::insert('sys_log', [
            'tenant_id'  => Tenant::requireId(),
            'user_id'    => Auth::id(),
            'module'     => $module,
            'action'     => $action,
            // sys_log.target_id 是整型列：空值必须写 NULL，写 '' 在严格模式下会报
            // 「Incorrect integer value」（系统设置保存那条日志的 target 就是空的）
            'target_id'  => ($targetId === null || $targetId === '') ? null : (int) $targetId,
            'detail'     => is_scalar($detail) ? (string) $detail : json_encode($detail, JSON_UNESCAPED_UNICODE),
            'ip'         => App::ip(),
            'created_at' => App::now(),
        ]);
    }

    /**
     * 写一条订单日志。$data 可取 order_id / action / field / old_value / new_value /
     * detail / remark / operator_id / created_at，缺省项按库默认值补齐。
     */
    public static function writeOrderLog(array $data): int
    {
        if (empty($data['order_id'])) {
            throw new \RuntimeException('订单日志缺少 order_id');
        }
        return Db::insert('biz_order_log', [
            'tenant_id'   => Tenant::requireId(),
            'order_id'    => (int) $data['order_id'],
            'action'      => (string) ($data['action'] ?? ''),
            'field'       => (string) ($data['field'] ?? ''),
            'old_value'   => (string) ($data['old_value'] ?? ''),
            'new_value'   => (string) ($data['new_value'] ?? ''),
            'detail'      => $data['detail'] ?? null,
            'remark'      => (string) ($data['remark'] ?? ''),
            'operator_id' => $data['operator_id'] ?? Auth::id(),
            'created_at'  => $data['created_at'] ?? App::now(),
        ]);
    }

    /** 某订单的操作日志（对齐 OrderController::logsOf） */
    public static function orderLogs(int $orderId): array
    {
        return Db::all(
            'SELECT l.*, u.real_name AS operator_name FROM biz_order_log l
               LEFT JOIN sys_user u ON u.id = l.operator_id AND u.tenant_id = l.tenant_id
              WHERE l.tenant_id = ? AND l.order_id = ? ORDER BY l.id DESC LIMIT 200',
            [Tenant::requireId(), $orderId]
        );
    }
}
