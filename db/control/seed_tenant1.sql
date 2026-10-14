-- 登记租户 #1：把原有单机数据整体视为第一个租户。幂等，可重复执行。
SET NAMES utf8mb4;

INSERT INTO `saas_tenant`
  (`code`,`name`,`mode`,`plan_id`,`status`,`contact_name`,`admin_username`,`applied_at`,`reviewed_at`)
VALUES
  ('demo','示例企业（原单机数据）','shared',
   (SELECT `id` FROM `saas_plan` WHERE `code`='basic'),'active','系统管理员','admin',NOW(),NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `status` = VALUES(`status`);
