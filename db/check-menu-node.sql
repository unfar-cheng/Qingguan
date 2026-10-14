-- ============================================================================
--  清关管理系统 · 菜单不显示「自查/修复」脚本
--  用途：文件覆盖了、菜单也插了，但左侧导航还是看不到那个页面时，跑这个。
--
--  【背景：一个菜单节点要显示出来，必须同时满足 4 个条件】
--    1. sys_permission.status = 1          （节点启用）
--    2. sys_permission.type   = 1          （类型是「菜单」，不是按钮/接口）
--    3. parent_id = 0，或者父节点自己也满足 1/2（否则成孤儿，被静默丢弃）
--       —— 见 api/src/controllers/AuthController.php:95  `isset($tree[$pid])`
--       ★ 最常见的踩坑：脚本里把 parent_id 写成了固定数字（例如 40），而不同库里
--         「系统设置」的 id 可能是 15 → 父节点压根不存在 → 孤儿 → 菜单消失。
--         所以第 1 步要先看 parent_name 是不是 NULL。
--    4. 当前登录账号的角色在 sys_role_permission 里有这个节点的授权
--       —— 超管（sys_role.code = 'super'）走 '*' 通配，天生全可见，不需要第 4 条
--
--  还有第 5 条（不在数据库里）：左侧菜单是外壳页面 index.html 启动时
--  调一次 GET /api/menu 拉的，之后一直用内存里那份。所以改完数据库，
--  必须 Ctrl+F5 强刷整个后台；只按 F5 刷 iframe 里的子页面是没用的。
--
--  【执行方式】宝塔 → 数据库 → 选中本系统的库 → 管理 → SQL 执行，整段粘贴。
--  全是 SELECT 和幂等的 INSERT/UPDATE，不 DROP、不 DELETE、不 ALTER。
--  把下面所有 'system.dict' 换成你要排查的那个权限标识即可。
-- ============================================================================


-- ---------- 第 1 步：看这条记录真实长什么样 --------------------------------
-- 关注 parent_name：如果显示 NULL 而 parent_id 又不是 0 → 悬空父节点（孤儿）
SELECT c.`id`, c.`parent_id`, p.`name` AS parent_name, c.`name`, c.`code`,
       c.`type`, c.`path`, c.`status`
  FROM `sys_permission` c
  LEFT JOIN `sys_permission` p ON p.`id` = c.`parent_id`
 WHERE c.`code` = 'system.dict';
-- 期望：1 行，parent_name = 系统设置，type = 1，status = 1，path = /pages/sys-dict.html


-- ---------- 第 2 步：确认父节点本身是「可见的顶级菜单」-----------------------
SELECT `id`, `name`, `code`, `type`, `status`, `path`
  FROM `sys_permission`
 WHERE `code` = 'system';
-- 期望：1 行，parent_id = 0，type = 1，status = 1


-- ---------- 第 3 步：确认当前账号的角色 ------------------------------------
SELECT u.`username`, u.`real_name`, r.`code` AS role_code, r.`name` AS role_name
  FROM `sys_user` u
  LEFT JOIN `sys_role` r ON r.`id` = u.`role_id`
 WHERE u.`is_deleted` = 0;
-- role_code = super → 第 4 条自动满足，直接跳到第 5 步（强刷浏览器）


-- ---------- 第 4 步：看各个角色有没有被授权 --------------------------------
SELECT r.`code` AS role_code, COUNT(*) AS granted
  FROM `sys_role_permission` rp
  JOIN `sys_role` r       ON r.`id` = rp.`role_id`
  JOIN `sys_permission` p ON p.`id` = rp.`permission_id`
 WHERE p.`code` = 'system.dict'
 GROUP BY r.`code`;
-- 你用的那个 role_code 没出现在结果里 → 就是它，跑下面第 6 步补授权


-- ---------- 第 5 步：一键体检「所有菜单节点」--------------------------------
-- 把全库菜单节点连同父节点名字列出来，异常项一眼可见：
--   problem 列不是 'OK' 的，就是左侧看不到的原因
SELECT c.`id`, c.`parent_id`, c.`name`, c.`code`, c.`type`, c.`path`, c.`status`,
       CASE
         WHEN c.`status` <> 1                       THEN '节点被停用 (status != 1)'
         WHEN c.`type`   <> 1                       THEN '类型不是菜单 (type != 1)'
         WHEN c.`parent_id` = 0                     THEN 'OK (顶级菜单)'
         WHEN p.`id` IS NULL                        THEN '孤儿：父节点不存在'
         WHEN p.`status` <> 1                       THEN '孤儿：父节点被停用'
         WHEN p.`type`   <> 1                       THEN '孤儿：父节点不是菜单类型'
         ELSE 'OK'
       END AS problem
  FROM `sys_permission` c
  LEFT JOIN `sys_permission` p ON p.`id` = c.`parent_id`
 ORDER BY c.`parent_id`, c.`sort`, c.`id`;


-- ============================================================================
--  修复动作（按第 1~5 步的结果，只跑需要的那条）
-- ============================================================================

-- ---------- 第 6 步 A：节点属性不对 / 父节点挂错 → 重新挂回「系统设置」-------
-- 幂等，跑几次都一样。
UPDATE `sys_permission` c
  JOIN `sys_permission` p ON p.`code` = 'system' AND p.`parent_id` = 0
   SET c.`parent_id` = p.`id`,
       c.`type`      = 1,
       c.`status`    = 1
 WHERE c.`code` = 'system.dict';


-- ---------- 第 6 步 B：非超管角色没授权 → 补上 ------------------------------
-- 幂等（NOT EXISTS 去重）。只给超管用账号的话，这条不用跑。
INSERT INTO `sys_role_permission` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
  FROM `sys_role` r
  JOIN `sys_permission` p ON p.`code` = 'system.dict'
 WHERE r.`code` <> 'super'
   AND NOT EXISTS (
         SELECT 1 FROM `sys_role_permission` x
          WHERE x.`role_id` = r.`id` AND x.`permission_id` = p.`id`
       );


-- ---------- 第 6 步 C：一键把所有二级菜单校正回真正的父节点 -----------------
-- 第 6A 只修「字典维护」一条。如果体检（第 5 步）里还有别的节点也是「孤儿」，
-- 跑下面这四条 —— 它们按父节点的 **code** 现取 id，不认任何写死的数字，
-- 所以不管你的库是 1/10/11/20… 编号还是 1/2/3/4… 顺序编号都能用。
UPDATE `sys_permission` AS c JOIN `sys_permission` AS p
   ON p.`code` = 'order' AND p.`parent_id` = 0
   SET c.`parent_id` = p.`id`
 WHERE c.`code` IN ('order.create', 'order.isf', 'order.customs', 'order.pickup', 'order.inspect');

UPDATE `sys_permission` AS c JOIN `sys_permission` AS p
   ON p.`code` = 'org' AND p.`parent_id` = 0
   SET c.`parent_id` = p.`id`
 WHERE c.`code` IN ('org.shipper', 'org.importer');

UPDATE `sys_permission` AS c JOIN `sys_permission` AS p
   ON p.`code` = 'finance' AND p.`parent_id` = 0
   SET c.`parent_id` = p.`id`
 WHERE c.`code` IN ('finance.account', 'finance.recharge', 'finance.payable');

UPDATE `sys_permission` AS c JOIN `sys_permission` AS p
   ON p.`code` = 'system' AND p.`parent_id` = 0
   SET c.`parent_id` = p.`id`
 WHERE c.`code` IN ('system.plan', 'system.staff', 'system.role', 'system.config', 'system.dict');


-- ---------- 第 7 步：复查 --------------------------------------------------
-- 用「接口视角」模拟一次菜单下发：只看超管能看到的树（不含角色授权过滤）
SELECT `id`, `parent_id`, `name`, `code`, `path`
  FROM `sys_permission`
 WHERE `status` = 1 AND `type` = 1
 ORDER BY `sort`, `id`;
-- 在结果里能同时看到 code='system' 和 code='system.dict'，且后者 parent_id
-- 等于前者的 id → 数据库侧已经没有问题了，剩下的只需要 Ctrl+F5 强刷后台。


-- ============================================================================
--  第 8 步：角色授权体检 ——「权限明明给了，账号却看不到」的根因就在这
--
--  菜单下发时，子菜单的**父节点也必须在授权里**（AuthController::menu() 里
--  isset($tree[$pid]) 不成立就把整枝丢掉、不报错）。
--  前端勾选时 el-tree 半选的父节点会被一起保存，所以界面上操作不容易出问题；
--  但**用 SQL / 直接调接口写授权**很容易只写子节点 → 悬空授权 → 账号左侧空白。
--
--  ⚠️ 注意：从 2026-09-16 版起后端已容错（menu() 会自动补齐祖先、保存授权时
--     也会自动补父节点），所以标 ★ 的行现在其实**也能显示**。下面这两条用于
--     ①体检历史数据 ②想把库里数据改到自洽时执行。
-- ============================================================================

-- 8.1 找出所有悬空授权（expect: 零行；有行 = 这些角色会「授权了却看不到」）
SELECT r.`id` AS role_id, r.`code` AS role_code, r.`name` AS role_name,
       c.`id` AS child_id, c.`name` AS child_name, c.`code` AS child_code,
       c.`parent_id` AS missing_parent_id
  FROM `sys_role_permission` rp
  JOIN `sys_role` r       ON r.`id` = rp.`role_id`
  JOIN `sys_permission` c ON c.`id` = rp.`permission_id`
 WHERE c.`parent_id` > 0
   AND (
         NOT EXISTS (SELECT 1 FROM `sys_permission` p WHERE p.`id` = c.`parent_id`)
      OR NOT EXISTS (SELECT 1 FROM `sys_role_permission` x
                      WHERE x.`role_id` = r.`id` AND x.`permission_id` = c.`parent_id`)
       );

-- 8.2 一键补齐：把每个角色「已授权节点的父节点」也补进授权（幂等，不会重复插）
INSERT IGNORE INTO `sys_role_permission` (`role_id`,`permission_id`)
SELECT DISTINCT rp.`role_id`, c.`parent_id`
  FROM `sys_role_permission` rp
  JOIN `sys_permission` c ON c.`id` = rp.`permission_id`
 WHERE c.`parent_id` > 0
   AND EXISTS (SELECT 1 FROM `sys_permission` p WHERE p.`id` = c.`parent_id`);

-- 8.3 某个角色最终能看到什么（把 'kehu' 换成要查的角色 code：
--     super / operator / finance / kehu …）
--     等价于用「接口视角」跑一次 GET /api/menu 的过滤逻辑。
SELECT c.`id`, c.`parent_id`, c.`name`, c.`code`, c.`path`,
       IF(rp.`id` IS NULL, '未授权', '已授权') AS granted,
       CASE WHEN c.`parent_id` = 0 THEN '-'
            WHEN p_rp.`id` IS NULL THEN '父未授权'
            ELSE '父已授权' END AS parent_granted,
       CASE WHEN c.`parent_id` = 0 AND rp.`id` IS NULL THEN '不显示（顶级菜单未授权）'
            WHEN c.`parent_id` = 0                     THEN '显示'
            WHEN rp.`id` IS NULL                       THEN '不显示（子菜单未授权）'
            WHEN p_rp.`id` IS NULL                     THEN '★父节点没授权 → 看不到'
            ELSE '显示' END AS will_show
  FROM `sys_permission` c
  JOIN `sys_role` r ON r.`code` = 'kehu'
  LEFT JOIN `sys_role_permission` rp   ON rp.`role_id` = r.`id` AND rp.`permission_id` = c.`id`
  LEFT JOIN `sys_permission` p         ON p.`id` = c.`parent_id`
  LEFT JOIN `sys_role_permission` p_rp ON p_rp.`role_id` = r.`id` AND p_rp.`permission_id` = p.`id`
 WHERE c.`status` = 1 AND c.`type` = 1
 ORDER BY c.`parent_id`, c.`sort`, c.`id`;


-- ============================================================================
--  第 9 步：按钮级权限体检 ——「创建订单」按钮为什么是灰的 / 为什么谁都能建单
--
--  背景：创建订单 / ISF / 清关 / 提拆派 / 查验 这 5 个功能的「创建」按钮，
--        各自对应一个 type = 2 的按钮权限节点（见 db/upgrade-2026-09-21b.sql）：
--
--          order.create.new    新增海运提单     （挂在 order.create  创建订单 下）
--          order.isf.new       创建 ISF 订单    （挂在 order.isf     ISF订单 下）
--          order.customs.new   创建清关订单     （挂在 order.customs 清关订单 下）
--          order.pickup.new    创建提拆派订单   （挂在 order.pickup  提拆派订单 下）
--          order.inspect.new   创建查验订单     （挂在 order.inspect 查验订单 下）
--
--  ⚠️ 三个容易搞错的地方：
--   1. 按钮节点是**第三层**（顶级分组 → 二级菜单 → 按钮），左侧导航里不会出现它，
--      只有「权限管理 → 角色权限」的权限树上能看到，带一个「按钮」小标签。
--      所以「菜单里找不到」是正常的，不是没生效。
--   2. 按钮节点不参与菜单显示（/api/menu 只挑 type = 1），只决定
--      「创建」按钮能不能点 + 后端 POST /api/orders 放不放行（无权限返回 403）。
--   3. 超管（sys_role.code = 'super'）走 '*' 通配，怎么配都能建单；
--      要验权限必须有**非超管**账号。
-- ============================================================================

-- 9.1 五个按钮节点是否都在、挂的位置对不对（parent_name 必须是上面那张表里的菜单）
SELECT c.`id`, c.`parent_id`, p.`name` AS parent_menu, p.`type` AS parent_type,
       c.`name`, c.`code`, c.`type`, c.`status`,
       CASE
         WHEN p.`id` IS NULL        THEN '异常：父节点不存在（会被权限树丢掉）'
         WHEN p.`type` <> 1         THEN '异常：父节点不是菜单'
         WHEN c.`type` <> 2         THEN '异常：自己不是按钮类型'
         WHEN c.`status` <> 1       THEN '异常：节点被停用'
         ELSE 'OK'
       END AS problem
  FROM `sys_permission` c
  LEFT JOIN `sys_permission` p ON p.`id` = c.`parent_id`
 WHERE c.`code` IN ('order.create.new','order.isf.new','order.customs.new','order.pickup.new','order.inspect.new')
 ORDER BY c.`id`;
-- 期望：5 行，problem 全是 OK。少于 5 行 → 跑 9.3 补回来。


-- 9.2 谁有建单权限（按角色逐项列出；超管不在这里，它天生全有）
SELECT r.`code` AS role_code, r.`name` AS role_name,
       SUM(IF(p.`code` = 'order.create.new',  1, 0)) AS 创建订单,
       SUM(IF(p.`code` = 'order.isf.new',     1, 0)) AS ISF,
       SUM(IF(p.`code` = 'order.customs.new', 1, 0)) AS 清关,
       SUM(IF(p.`code` = 'order.pickup.new',  1, 0)) AS 提拆派,
       SUM(IF(p.`code` = 'order.inspect.new', 1, 0)) AS 查验
  FROM `sys_role` r
  LEFT JOIN `sys_role_permission` rp ON rp.`role_id` = r.`id`
  LEFT JOIN `sys_permission` p       ON p.`id` = rp.`permission_id`
 WHERE r.`code` <> 'super'
 GROUP BY r.`id`, r.`code`, r.`name`
 ORDER BY r.`id`;
-- 1 = 有这个按钮权限（能建单），0 = 没有（按钮是灰的）。
-- 注意：SUM(...) 里必须写成 IF(cond,1,0)。直接 SUM(p.code='x') 在 LEFT JOIN
-- 出来的 NULL 行上会变 NULL，一个权限都没有的角色会显示 NULL 而不是 0，容易误判。


-- 9.3 一键把 5 个按钮节点补/挂回正确的菜单（幂等，全程按 code 现取 id，不认写死的数字）
--     与 db/schema.sql、db/upgrade-2026-09-21b.sql 里的同名语句完全一致，重复跑没问题。
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
-- 注意：这里用派生表别名 s.n 而不是 VALUES()（MySQL 8.0.20+ 起 VALUES() 已弃用）。

-- 9.3b 父节点自愈：`xxx.new` 的父节点就是去掉 `.new` 的那个菜单（同样是幂等的）
UPDATE `sys_permission` AS c
  JOIN `sys_permission` AS p
    ON p.`code` = SUBSTRING_INDEX(c.`code`, '.new', 1) AND p.`type` = 1
   SET c.`parent_id` = p.`id`
 WHERE c.`code` IN ('order.create.new', 'order.isf.new', 'order.customs.new',
                    'order.pickup.new', 'order.inspect.new');


-- 9.4 给某个角色开 / 关某个「创建」按钮权限（把 'operator' 换成你的角色 code）
-- 开：改成 INSERT IGNORE（重复跑只插一次）
INSERT IGNORE INTO `sys_role_permission` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
  FROM `sys_role` r
  JOIN `sys_permission` p ON p.`code` = 'order.customs.new'
 WHERE r.`code` = 'operator';

-- 关：把上面的 INSERT 换成下面这句删掉即可（只删按钮权限，不动菜单权限）
-- DELETE rp FROM `sys_role_permission` rp
--   JOIN `sys_role` r       ON r.`id` = rp.`role_id`
--   JOIN `sys_permission` p ON p.`id` = rp.`permission_id`
--  WHERE r.`code` = 'operator' AND p.`code` = 'order.customs.new';


-- 9.5 权限树看不到按钮节点？（2026-09-21b 之前的版本会出现）
-- 旧版 SystemController::permissionTree() 只组装两层，第三层的按钮节点会被整批静默丢掉。
-- 新版已改成按任意层级递归组装，覆盖 api/src/controllers/SystemController.php 即可。
-- 数据库侧可以先用下面这句确认按钮节点本身没问题（能查到 5 行 + parent_menu 有名字）：
SELECT c.`code`, c.`name`, p.`code` AS parent_code, p.`name` AS parent_name
  FROM `sys_permission` c
  LEFT JOIN `sys_permission` p ON p.`id` = c.`parent_id`
 WHERE c.`type` = 2
 ORDER BY c.`sort`, c.`id`;
