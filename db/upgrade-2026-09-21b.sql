-- ============================================================================
-- 增量升级 2026-09-21b：按钮级权限 —— 各订单功能的「创建新订单」
--
-- 背景：本系统的权限原本只管「左侧菜单能不能看见」，不管操作。
--       本次给 5 个订单功能的「创建新订单」按钮加上独立开关：
--         order.create.new    新增海运提单（创建订单页）
--         order.isf.new       创建 ISF 订单
--         order.customs.new   创建清关订单
--         order.pickup.new    创建提拆派订单
--         order.inspect.new   创建查验订单
--       type=2（按钮）节点只用于 `Auth::can()` / 前端 `QG.can()` 判断按钮能不能点，
--       **不会出现在左侧菜单里**（菜单只读 type=1）。
--
-- 默认策略：把 5 个按钮权限授予**所有非超管角色** —— 保证升级前后行为一致，
--       不会有人的「新增」按钮突然消失。要限制谁不能建单，执行完去
--       「系统设置 → 权限管理 → 角色权限」把对应按钮取消勾选即可。
--
-- 本脚本**幂等**，可重复执行。
-- ⚠️ 执行后：受影响的账号需要重新登录（或 Ctrl+F5 强刷）才会拿到最新权限。
-- ============================================================================

SET NAMES utf8mb4;

-- 1) 加 5 个按钮权限节点（parent_id 由父菜单的 code 现取，不写死 id）---------
INSERT INTO `sys_permission` (`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT p.`id`, s.n, s.c, 2, '', '', s.so, 1
  FROM (
              SELECT '新增海运提单'   AS n, 'order.create.new'  AS c, 'order.create'  AS pc, 911 AS so FROM DUAL
    UNION ALL SELECT '创建 ISF 订单',    'order.isf.new',      'order.isf',      912
    UNION ALL SELECT '创建清关订单',     'order.customs.new',  'order.customs',  913
    UNION ALL SELECT '创建提拆派订单',   'order.pickup.new',   'order.pickup',   914
    UNION ALL SELECT '创建查验订单',     'order.inspect.new',  'order.inspect',  915
  ) AS s
  JOIN `sys_permission` p ON p.`code` = s.pc
ON DUPLICATE KEY UPDATE
  `name` = s.n, `type` = 2, `sort` = s.so;

-- 2) parent_id 自愈：`xxx.new` 的父节点 = 去掉 `.new` 的那个菜单 --------------
UPDATE `sys_permission` AS c
  JOIN `sys_permission` AS p
    ON p.`code` = SUBSTRING_INDEX(c.`code`, '.new', 1) AND p.`type` = 1
   SET c.`parent_id` = p.`id`
 WHERE c.`code` IN ('order.create.new', 'order.isf.new', 'order.customs.new',
                    'order.pickup.new', 'order.inspect.new');

-- 3) 默认授权给所有非超管角色（超管走 role_code='super' 的 ['*'] 通配）-------
INSERT IGNORE INTO `sys_role_permission` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
  FROM `sys_role` r
  JOIN `sys_permission` p ON p.`code` IN (
        'order.create.new', 'order.isf.new', 'order.customs.new',
        'order.pickup.new', 'order.inspect.new')
 WHERE r.`code` <> 'super';

-- ============================================================================
-- 核对（依次执行，看结果对不对）
-- ============================================================================

-- ① 5 个按钮节点都在、type=2、父节点挂对了（parent_name 应是 5 个订单菜单）
SELECT c.`id`, c.`name`, c.`code`, c.`type`, p.`name` AS parent_name, c.`status`
  FROM `sys_permission` c
  LEFT JOIN `sys_permission` p ON p.`id` = c.`parent_id`
 WHERE c.`code` IN ('order.create.new', 'order.isf.new', 'order.customs.new',
                    'order.pickup.new', 'order.inspect.new')
 ORDER BY c.`id`;

-- ② 各角色拿到了几个建单权限（超管不列，它免检）
SELECT r.`code` AS role_code, r.`name` AS role_name, COUNT(*) AS btn_count
  FROM `sys_role` r
  JOIN `sys_role_permission` rp ON rp.`role_id` = r.`id`
  JOIN `sys_permission` p ON p.`id` = rp.`permission_id`
 WHERE p.`code` IN ('order.create.new', 'order.isf.new', 'order.customs.new',
                    'order.pickup.new', 'order.inspect.new')
   AND r.`code` <> 'super'
 GROUP BY r.`id`
 ORDER BY r.`id`;
-- 正常应看到每个非超管角色 5 条；某个角色 0 条 = 它不能建单（这是可配置的正常结果）

-- ③ 有没有孤儿节点（parent_id 指向不存在的父级 → 权限树里会看不到）
SELECT c.`id`, c.`name`, c.`code`, c.`parent_id`
  FROM `sys_permission` c
  LEFT JOIN `sys_permission` p ON p.`id` = c.`parent_id`
 WHERE c.`code` IN ('order.create.new', 'order.isf.new', 'order.customs.new',
                    'order.pickup.new', 'order.inspect.new')
   AND (c.`parent_id` = 0 OR p.`id` IS NULL);
-- 期望：0 行
