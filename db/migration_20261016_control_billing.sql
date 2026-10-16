-- 控制面：账单与有效期（幂等；MySQL 8.0 与 MariaDB 10.5 通用；执行库 = qingguan_control[_dev]）
SET NAMES utf8mb4;
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saas_tenant' AND COLUMN_NAME = 'expires_at') = 0,
  'ALTER TABLE `saas_tenant` ADD COLUMN `bill_cycle` ENUM(''month'',''year'') NOT NULL DEFAULT ''year'' COMMENT ''注册申请的付费周期意愿'' AFTER `reject_reason`, ADD COLUMN `expires_at` DATETIME NULL COMMENT ''服务期到期日；NULL=不参与到期逻辑'' AFTER `bill_cycle`',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saas_bill') = 0,
  'CREATE TABLE `saas_bill` (
     `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
     `tenant_id` INT UNSIGNED NOT NULL,
     `cycle` ENUM(''month'',''year'') NOT NULL,
     `amount` DECIMAL(10,2) NOT NULL,
     `status` ENUM(''unpaid'',''paid'',''void'') NOT NULL DEFAULT ''unpaid'',
     `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     `paid_at` DATETIME NULL,
     `paid_channel` VARCHAR(30) NOT NULL DEFAULT '''',
     `paid_ref` VARCHAR(100) NOT NULL DEFAULT '''',
     `confirmed_by` INT UNSIGNED NULL,
     `void_reason` VARCHAR(200) NOT NULL DEFAULT '''',
     `remark` VARCHAR(200) NOT NULL DEFAULT '''',
     PRIMARY KEY (`id`),
     KEY `idx_tenant` (`tenant_id`,`status`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''账单''',
  'SELECT 1'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
