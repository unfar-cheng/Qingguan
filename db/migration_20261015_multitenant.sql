-- =============================================================
-- 多租户·一期地基 —— 数据面迁移
-- 内容：21 张业务表全部加 tenant_id、唯一键改造成带租户的复合键、补列表索引。
-- 现有数据全部归租户 #1（tenant_id 默认 1）。
--
-- 幂等：每步先查 information_schema，已存在/不存在都跳过；可重复执行。
-- 不假设旧唯一键的**名字**（生产库与本机库键名不同，例如 biz_plan 是 uk_code 而不是
-- uk_plan_code），按「唯一键恰好覆盖这些列」来定位，避免了「旧键没删掉、新键加上去」
-- 导致租户内仍然全局唯一这种隐性错误。
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
  'ALTER TABLE `sys_role_permission` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1',
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

-- ---------- 2) 唯一键改造（旧的单列/双列唯一键 → 带 tenant_id 的复合键）----------

-- sys_user: 去掉覆盖 (username) 的唯一键，换成 uk_tenant_username
SET @old := (SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_user' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY' GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='username' LIMIT 1);
SET @s := IF(@old IS NULL, 'SELECT 1', CONCAT('ALTER TABLE `sys_user` DROP INDEX `', @old, '`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_user' AND INDEX_NAME='uk_tenant_username') = 0,
  'ALTER TABLE `sys_user` ADD UNIQUE KEY `uk_tenant_username` (`tenant_id`,`username`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_role: 去掉覆盖 (code) 的唯一键，换成 uk_tenant_code
SET @old := (SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_role' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY' GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='code' LIMIT 1);
SET @s := IF(@old IS NULL, 'SELECT 1', CONCAT('ALTER TABLE `sys_role` DROP INDEX `', @old, '`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_role' AND INDEX_NAME='uk_tenant_code') = 0,
  'ALTER TABLE `sys_role` ADD UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_permission: 去掉覆盖 (code) 的唯一键，换成 uk_tenant_code
SET @old := (SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_permission' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY' GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='code' LIMIT 1);
SET @s := IF(@old IS NULL, 'SELECT 1', CONCAT('ALTER TABLE `sys_permission` DROP INDEX `', @old, '`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_permission' AND INDEX_NAME='uk_tenant_code') = 0,
  'ALTER TABLE `sys_permission` ADD UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_config: 去掉覆盖 (cfg_key) 的唯一键，换成 uk_tenant_cfg_key
SET @old := (SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_config' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY' GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='cfg_key' LIMIT 1);
SET @s := IF(@old IS NULL, 'SELECT 1', CONCAT('ALTER TABLE `sys_config` DROP INDEX `', @old, '`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_config' AND INDEX_NAME='uk_tenant_cfg_key') = 0,
  'ALTER TABLE `sys_config` ADD UNIQUE KEY `uk_tenant_cfg_key` (`tenant_id`,`cfg_key`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- sys_dict: 去掉覆盖 (dict_type,dict_key) 的唯一键，换成 uk_tenant_type_key
SET @old := (SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_dict' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY' GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='dict_type,dict_key' LIMIT 1);
SET @s := IF(@old IS NULL, 'SELECT 1', CONCAT('ALTER TABLE `sys_dict` DROP INDEX `', @old, '`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sys_dict' AND INDEX_NAME='uk_tenant_type_key') = 0,
  'ALTER TABLE `sys_dict` ADD UNIQUE KEY `uk_tenant_type_key` (`tenant_id`,`dict_type`,`dict_key`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_plan: 去掉覆盖 (plan_code) 的唯一键，换成 uk_tenant_plan
SET @old := (SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_plan' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY' GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='plan_code' LIMIT 1);
SET @s := IF(@old IS NULL, 'SELECT 1', CONCAT('ALTER TABLE `biz_plan` DROP INDEX `', @old, '`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_plan' AND INDEX_NAME='uk_tenant_plan') = 0,
  'ALTER TABLE `biz_plan` ADD UNIQUE KEY `uk_tenant_plan` (`tenant_id`,`plan_code`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- biz_order: 去掉覆盖 (order_no) 的唯一键，换成 uk_tenant_order_no
SET @old := (SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY' GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='order_no' LIMIT 1);
SET @s := IF(@old IS NULL, 'SELECT 1', CONCAT('ALTER TABLE `biz_order` DROP INDEX `', @old, '`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biz_order' AND INDEX_NAME='uk_tenant_order_no') = 0,
  'ALTER TABLE `biz_order` ADD UNIQUE KEY `uk_tenant_order_no` (`tenant_id`,`order_no`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- fin_account: 去掉覆盖 (company_id) 的唯一键，换成 uk_tenant_company
SET @old := (SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fin_account' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY' GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)='company_id' LIMIT 1);
SET @s := IF(@old IS NULL, 'SELECT 1', CONCAT('ALTER TABLE `fin_account` DROP INDEX `', @old, '`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fin_account' AND INDEX_NAME='uk_tenant_company') = 0,
  'ALTER TABLE `fin_account` ADD UNIQUE KEY `uk_tenant_company` (`tenant_id`,`company_id`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 说明：
--   · sys_role_permission 的 PRIMARY(role_id,permission_id) 不改——role_id/permission_id
--     在共享库里仍全局唯一，复合键无意义。
--   · sys_permission 在生产库上本来就没有 code 唯一键（只有 PRIMARY id），上面那句
--     「找到旧键再删」会安全跳过，只新增 uk_tenant_code。
--   · biz_order_address 的 uk_order_type(order_id,addr_type) 不在改造范围，保持不动。

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

