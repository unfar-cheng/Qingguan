-- 数据面：对库内全部已开通租户补插「账单中心」一级菜单（幂等；uk_tenant_code 去重；
-- 租户集合从 sys_permission 现有行派生，不跨库引用控制面——dev/prod 库名不同）
SET NAMES utf8mb4;
INSERT INTO `sys_permission` (`tenant_id`,`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT DISTINCT p.tenant_id, 0, '账单中心', 'billing', 1, '/pages/billing.html', 'Wallet', 2, 1
  FROM `sys_permission` p
  LEFT JOIN `sys_permission` x ON x.tenant_id = p.tenant_id AND x.code = 'billing'
 WHERE x.id IS NULL;
