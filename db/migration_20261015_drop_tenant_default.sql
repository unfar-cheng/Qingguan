-- =============================================================
-- 去掉 tenant_id 的 DEFAULT 1 —— 多租户·运维加固
--
-- 背景：一期给 21 张表加 tenant_id 时带了 DEFAULT 1，好让单机存量写入继续工作。
-- 现在所有写入路径（仓储 create() 注入、日志、订单子表、财务）都已显式传 tenant_id，
-- 这个默认值只剩一个副作用：**把「忘了传 tenant_id」的写入静默落到租户 1**——
-- 共享库里是串租户，专用库里是写出「谁都看不见」的行。所以把它去掉，让漏传直接报错。
--
-- 幂等：只在该列「存在且仍带默认值」时才 MODIFY；可重复执行。
-- 兼容 MySQL 8.0 与 MariaDB 10.5+。
-- 与 db/schema.sql 同步：本迁移把 tenant_id 改成「无默认」，schema.sql 的 DDL 段已同步。
-- =============================================================
SET NAMES utf8mb4;

-- 通用写法：列存在且仍带默认值 → MODIFY 去掉默认值
-- （COLUMN_DEFAULT IS NOT NULL 就是「还带默认值」的判据，MODIFY 后变 NULL，天然幂等）

-- sys_user
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_user' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `sys_user` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_role
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_role' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `sys_role` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_permission
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_permission' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `sys_permission` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_role_permission
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_role_permission' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `sys_role_permission` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_config
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_config' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `sys_config` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_dict
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_dict' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `sys_dict` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_log
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_log' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `sys_log` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- org_company
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='org_company' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `org_company` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_plan
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_plan' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `biz_plan` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `biz_order` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order_container
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order_container' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `biz_order_container` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order_hscode
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order_hscode' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `biz_order_hscode` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order_address
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order_address' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `biz_order_address` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order_file
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order_file' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `biz_order_file` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order_log
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order_log' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `biz_order_log` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_isf
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_isf' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `biz_isf` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_inspect
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_inspect' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `biz_inspect` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_pickup
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_pickup' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `biz_pickup` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- fin_account
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fin_account' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `fin_account` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- fin_recharge
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fin_recharge' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `fin_recharge` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- fin_payable
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fin_payable' AND COLUMN_NAME='tenant_id' AND COLUMN_DEFAULT IS NOT NULL) = 1,
  'ALTER TABLE `fin_payable` MODIFY `tenant_id` INT UNSIGNED NOT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
