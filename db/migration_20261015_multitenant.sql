-- =============================================================
-- 多租户·一期地基 —— 数据面迁移
-- 内容：21 张业务表全部加 tenant_id、唯一键改造成带租户的复合键、补列表索引。
-- 现有数据全部归租户 #1（tenant_id 默认 1）。
-- 幂等：每步先查 information_schema，已存在则跳过；可重复执行。
-- 兼容 MySQL 8.0 与 MariaDB 10.5+，不使用 MySQL 8 专有语法。
-- =============================================================
SET NAMES utf8mb4;

-- ---------- 1) 全表加 tenant_id ----------

-- sys_user
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_user' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `sys_user` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_role
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_role' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `sys_role` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_permission
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_permission' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `sys_permission` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_role_permission
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_role_permission' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `sys_role_permission` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_config
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_config' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `sys_config` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_dict
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_dict' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `sys_dict` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_log
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_log' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `sys_log` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- org_company
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='org_company' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `org_company` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_plan
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_plan' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `biz_plan` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `biz_order` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order_container
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order_container' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `biz_order_container` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order_hscode
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order_hscode' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `biz_order_hscode` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order_address
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order_address' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `biz_order_address` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_isf
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_isf' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `biz_isf` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_inspect
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_inspect' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `biz_inspect` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_pickup
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_pickup' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `biz_pickup` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order_file
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order_file' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `biz_order_file` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order_log
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order_log' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `biz_order_log` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- fin_account
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fin_account' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `fin_account` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- fin_recharge
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fin_recharge' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `fin_recharge` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- fin_payable
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fin_payable' AND COLUMN_NAME='tenant_id') = 0,
  'ALTER TABLE `fin_payable` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- 2) 唯一键改造（旧键 → 带 tenant_id 的复合键）----------

-- sys_user: uk_username -> uk_tenant_username
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_user' AND INDEX_NAME='uk_username') > 0,
  'ALTER TABLE `sys_user` DROP INDEX `uk_username`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_user' AND INDEX_NAME='uk_tenant_username') = 0,
  'ALTER TABLE `sys_user` ADD UNIQUE KEY `uk_tenant_username` (`tenant_id`,`username`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_role: uk_code -> uk_tenant_code
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_role' AND INDEX_NAME='uk_code') > 0,
  'ALTER TABLE `sys_role` DROP INDEX `uk_code`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_role' AND INDEX_NAME='uk_tenant_code') = 0,
  'ALTER TABLE `sys_role` ADD UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_permission: uk_code -> uk_tenant_code
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_permission' AND INDEX_NAME='uk_code') > 0,
  'ALTER TABLE `sys_permission` DROP INDEX `uk_code`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_permission' AND INDEX_NAME='uk_tenant_code') = 0,
  'ALTER TABLE `sys_permission` ADD UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_config: uk_cfg_key -> uk_tenant_cfg_key
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_config' AND INDEX_NAME='uk_cfg_key') > 0,
  'ALTER TABLE `sys_config` DROP INDEX `uk_cfg_key`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_config' AND INDEX_NAME='uk_tenant_cfg_key') = 0,
  'ALTER TABLE `sys_config` ADD UNIQUE KEY `uk_tenant_cfg_key` (`tenant_id`,`cfg_key`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_dict: uk_type_key -> uk_tenant_type_key
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_dict' AND INDEX_NAME='uk_type_key') > 0,
  'ALTER TABLE `sys_dict` DROP INDEX `uk_type_key`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_dict' AND INDEX_NAME='uk_tenant_type_key') = 0,
  'ALTER TABLE `sys_dict` ADD UNIQUE KEY `uk_tenant_type_key` (`tenant_id`,`dict_type`,`dict_key`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_plan: uk_plan_code -> uk_tenant_plan
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_plan' AND INDEX_NAME='uk_plan_code') > 0,
  'ALTER TABLE `biz_plan` DROP INDEX `uk_plan_code`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_plan' AND INDEX_NAME='uk_tenant_plan') = 0,
  'ALTER TABLE `biz_plan` ADD UNIQUE KEY `uk_tenant_plan` (`tenant_id`,`plan_code`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order: uk_order_no -> uk_tenant_order_no
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order' AND INDEX_NAME='uk_order_no') > 0,
  'ALTER TABLE `biz_order` DROP INDEX `uk_order_no`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order' AND INDEX_NAME='uk_tenant_order_no') = 0,
  'ALTER TABLE `biz_order` ADD UNIQUE KEY `uk_tenant_order_no` (`tenant_id`,`order_no`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- fin_account: uk_company -> uk_tenant_company
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fin_account' AND INDEX_NAME='uk_company') > 0,
  'ALTER TABLE `fin_account` DROP INDEX `uk_company`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fin_account' AND INDEX_NAME='uk_tenant_company') = 0,
  'ALTER TABLE `fin_account` ADD UNIQUE KEY `uk_tenant_company` (`tenant_id`,`company_id`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 说明：sys_role_permission 的 uk_rp(role_id,permission_id) 不改——role_id/permission_id
--       在共享库里仍全局唯一，复合键无意义。

-- ---------- 3) 回填兜底（DEFAULT 1 已保证，此处显式修正异常值）----------

UPDATE `sys_user` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `sys_role` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `sys_permission` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `sys_role_permission` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `sys_config` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `sys_dict` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `sys_log` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `org_company` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `biz_plan` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `biz_order` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `biz_order_container` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `biz_order_hscode` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `biz_order_address` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `biz_isf` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `biz_inspect` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `biz_pickup` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `biz_order_file` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `biz_order_log` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `fin_account` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `fin_recharge` SET `tenant_id` = 1 WHERE `tenant_id` = 0;
UPDATE `fin_payable` SET `tenant_id` = 1 WHERE `tenant_id` = 0;

-- ---------- 4) 列表类复合索引 ----------

-- biz_order.idx_tenant_list
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order' AND INDEX_NAME='idx_tenant_list') = 0,
  'ALTER TABLE `biz_order` ADD KEY `idx_tenant_list` (`tenant_id`,`is_deleted`,`id`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- org_company.idx_tenant_list
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='org_company' AND INDEX_NAME='idx_tenant_list') = 0,
  'ALTER TABLE `org_company` ADD KEY `idx_tenant_list` (`tenant_id`,`is_deleted`,`id`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order_file.idx_tenant_order
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order_file' AND INDEX_NAME='idx_tenant_order') = 0,
  'ALTER TABLE `biz_order_file` ADD KEY `idx_tenant_order` (`tenant_id`,`order_id`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

