-- 补齐 biz_order_log.detail
--
-- 背景：本仓库自带的 db/schema.sql（厂商随包附带）里 biz_order_log 有 detail 列，代码里也确实在写它
-- （编辑订单时 `'detail' => null`，见历史版本 OrderController::update）。但生产库是从一份更早的
-- 数据导出恢复的，biz_order_log 没有这一列 —— 结果「编辑订单」在生产上会直接报
-- 「Unknown column 'detail'」。这条迁移把缺的列补上，让库结构与随包 schema 一致。
--
-- 幂等：已存在则跳过；可重复执行。
SET NAMES utf8mb4;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order_log' AND COLUMN_NAME='detail') = 0,
  'ALTER TABLE `biz_order_log` ADD COLUMN `detail` TEXT NULL COMMENT ''变更详情'' AFTER `new_value`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
