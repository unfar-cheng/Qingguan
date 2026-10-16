-- 数据面：sys_user 首登强制改密标记（幂等；执行面 = 共享库手工 + 各专用库经 migrate_dedicated.php 扇出）
SET NAMES utf8mb4;
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sys_user' AND COLUMN_NAME = 'must_change_password') = 0,
  'ALTER TABLE `sys_user` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''首登强制改密'' AFTER `status`',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
