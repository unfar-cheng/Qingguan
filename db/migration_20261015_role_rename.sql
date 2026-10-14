-- 租户内的「超级管理员」更名为「租户系统管理员」，角色标识 super → tenant_admin。
-- 幂等：只改 code='super' 的行，重复执行无副作用。
SET NAMES utf8mb4;

UPDATE `sys_role`
   SET `code` = 'tenant_admin', `name` = '租户系统管理员'
 WHERE `code` = 'super';
