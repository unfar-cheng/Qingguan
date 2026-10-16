-- 数据面：把「账单中心」从顶级菜单挪到「系统设置」下（幂等；执行库 = 每个租户的数据面库）
-- 背景：账单中心是低频功能，占顶级菜单位置不合适；搁在系统设置里（与报价方案/内部员工等同级）。
-- 口径：只改 parent_id 与 sort，不动 status（管理员手工停用过就保持停用）、不动授权
--       （sys_role_permission 认的是节点 id，节点没换行，授权天然保留）。
-- 幂等：WHERE 里带 parent_id <> p.id，重跑时不再匹配任何行。
-- 注意：db/schema.sql 的菜单段与 §3 自愈 UPDATE 里有同一处改动，两处必须保持一致。
SET NAMES utf8mb4;

UPDATE `sys_permission` AS c
  JOIN `sys_permission` AS p
    ON p.`tenant_id` = c.`tenant_id` AND p.`code` = 'system' AND p.`parent_id` = 0
   SET c.`parent_id` = p.`id`, c.`sort` = 5
 WHERE c.`code` = 'billing' AND c.`type` = 1 AND c.`parent_id` <> p.`id`;
