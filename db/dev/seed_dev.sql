-- 本机开发库用的初始账号（仅 dev 使用，生产不要导入）。
-- 账号 admin / 密码 admin123，与单机版部署时的初始管理员保持一致。
-- 幂等：已存在同名账号则不改动。
SET NAMES utf8mb4;

INSERT INTO `sys_user`
  (`tenant_id`,`username`,`real_name`,`password_hash`,`role_id`,`status`,`created_at`)
SELECT 1,'admin','系统管理员',
       '$2y$10$GvjEYINZ1E2cBUYpJAnbNOFW9MlJ5RktNwncEU8JfRPSh6hRKOAPe',
       (SELECT `id` FROM `sys_role` WHERE `code`='tenant_admin' AND `tenant_id`=1 LIMIT 1),
       1, NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `sys_user` WHERE `tenant_id`=1 AND `username`='admin');
