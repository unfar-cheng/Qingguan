-- ============================================================================
-- 增量升级：把「始发港」从港口字典里拆出来，成为独立的一套数据
-- 日期：2026-09-17        适用：已经导过 schema.sql / 早期版本的库
--                        （全新安装不用跑这个，schema.sql 里已经是拆好的）
--
-- 【为什么改】
--   原来建单页的 始发港 / 卸货港 / 目的港 / 清关港 四个下拉**共用**同一个
--   字典类型 port，于是「中国出运港」和「美国目的港」混在同一个列表里，
--   选目的港时也会看到宁波港。
--   现在拆成两个类型：
--     · origin_port  始发港                    —— 只有「始发港」这一个下拉读它
--     · port         目的港 / 卸货港 / 清关港   —— 这三个下拉共用（保持原类型名不变，
--                                                 已有数据不用迁移、订单里存的文本不受影响）
--
-- 【本脚本做什么】
--   ① 补 origin_port 的起运港种子
--   ② 把你以前加在 port 里的常见中国起运港**复制**一份到 origin_port（不丢数据）
--   ③ 把 port 里**系统预置的这 3 条**中国起运港停用（不删除，随时可恢复）
--   ④ 打印结果供核对
--
-- 【可重复执行】每条语句都是幂等的，跑几次结果都一样。
-- 【跑完请 Ctrl+F5 强刷后台页面】下拉数据在浏览器里缓存。
-- ============================================================================


-- ① 始发港种子（已存在则原样保留 —— 不覆盖你手工改过的显示名/排序）
INSERT INTO `sys_dict` (`dict_type`,`dict_key`,`dict_value`,`sort`,`status`) VALUES
  ('origin_port','宁波港','宁波港',1,1),
  ('origin_port','上海港','上海港',2,1),
  ('origin_port','深圳盐田港','深圳盐田港',3,1),
  ('origin_port','深圳蛇口港','深圳蛇口港',4,1),
  ('origin_port','广州南沙港','广州南沙港',5,1),
  ('origin_port','青岛港','青岛港',6,1),
  ('origin_port','天津港','天津港',7,1),
  ('origin_port','厦门港','厦门港',8,1)
ON DUPLICATE KEY UPDATE `id` = `id`;


-- ② 把 port 里已有的中国起运港复制到 origin_port（你以前手工加过的不会丢）
--    说明：港口名没法自动判断国别，所以只认下面这份常见起运港白名单；
--    如果你还加过别的中国起运港（如「大连港」），跑完在后台照抄一条即可。
--    实现要点：目标表 sys_dict 只出现在 FROM/JOIN 里，**不用子查询** ——
--    MySQL 不允许 INSERT ... SELECT 时在子查询里引用目标表（会报 1093）。
INSERT INTO `sys_dict` (`dict_type`,`dict_key`,`dict_value`,`sort`,`status`)
SELECT 'origin_port', d.dict_key, d.dict_value, 100 + d.sort, d.status
  FROM `sys_dict` AS d
  LEFT JOIN `sys_dict` AS x
         ON x.dict_type = 'origin_port' AND x.dict_key = d.dict_key
 WHERE d.dict_type = 'port'
   AND d.dict_key IN ('宁波港','上海港','深圳盐田港','深圳蛇口港','广州南沙港','青岛港','天津港','厦门港')
   AND x.id IS NULL;


-- ③ 把 port 里「系统预置的 3 条中国起运港」停用（不删除）
--    只动这 3 个明确的预置名，你自己加的一律不动。
--    ⚠️ 想恢复：把下面的 status 0 改成 1 再跑一次这一条即可。
UPDATE `sys_dict`
   SET `status` = 0
 WHERE `dict_type` = 'port'
   AND `dict_key` IN ('宁波港','上海港','深圳盐田港');


-- ④ 核对：两个类型各有几条、启用/停用各几条
SELECT `dict_type`, `status`, COUNT(*) AS `条数`
  FROM `sys_dict`
 WHERE `dict_type` IN ('port','origin_port')
 GROUP BY `dict_type`, `status`
 ORDER BY `dict_type`, `status` DESC;


-- ⑤ 核对：始发港明细（建单页「始发港」下拉里会出现的项）
SELECT `sort`, `dict_key`, `dict_value`, `status`
  FROM `sys_dict`
 WHERE `dict_type` = 'origin_port'
 ORDER BY `sort`, `id`;


-- ⑥ 核对：目的港 / 卸货港 / 清关港下拉里会出现的启用项（不应再有中国起运港）
SELECT `sort`, `dict_key`, `dict_value`
  FROM `sys_dict`
 WHERE `dict_type` = 'port' AND `status` = 1
 ORDER BY `sort`, `id`;
