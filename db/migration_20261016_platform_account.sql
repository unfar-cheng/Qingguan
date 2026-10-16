-- 控制面：平台账号强改标记 + 平台设置表（幂等；执行库 = qingguan_control[_dev]）
SET NAMES utf8mb4;
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saas_platform_user' AND COLUMN_NAME = 'must_change_password') = 0,
  'ALTER TABLE `saas_platform_user` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''首登强制改密'' AFTER `status`',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saas_setting') = 0,
  'CREATE TABLE `saas_setting` (
     `setting_key` VARCHAR(50) NOT NULL,
     `setting_value` TEXT NOT NULL,
     `updated_by` INT UNSIGNED NULL,
     `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     PRIMARY KEY (`setting_key`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''平台设置（键值）''',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
