-- ============================================================================
--  清关管理系统 · 增量升级脚本
--  版本：2026-09-16b
--  适用：已经导入过 schema.sql 的库（全新安装直接用 schema.sql 即可，不用跑这个）
--
--  本脚本做三件事：
--    1) 左侧菜单「系统设置」下新增「字典维护」节点（id = 45）
--    2) 补全柜型字典（53HQ / 40NOR / 40RH）
--    3) 补全船司字典（达飞 / 马士基 / 阳明 / 东方海外 / 海洋网联）
--       并新增 file_type（文件类型）、origin_country（原产国）两组字典
--
--  用法（宝塔 → 数据库 → 管理 → SQL 执行 / 或 mysql 客户端）：
--    mysql -uroot -p 库名 < upgrade-2026-09-16b.sql
--  执行前建议先 mysqldump 备份。
--  脚本可重复执行（幂等）：下面的写入都靠 sys_dict 上 (dict_type, dict_key) 唯一键
--  做 upsert，不会产生重复行。老库若没有这个唯一键，第 0 步会先清重复再补上。
-- ============================================================================

-- ---------- 0. 前置：清重复 + 补 (dict_type, dict_key) 唯一键 -------------
-- 先删掉同一 (type,key) 下 id 较大的重复行，只留最早那条
DELETE d FROM `sys_dict` d
  JOIN `sys_dict` k
    ON k.`dict_type` = d.`dict_type`
   AND k.`dict_key`  = d.`dict_key`
   AND k.`id` < d.`id`;

-- 再按需补唯一键（已存在则跳过，保证脚本可重复执行）
SET @has_uk := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sys_dict' AND INDEX_NAME = 'uk_type_key'
);
SET @ddl := IF(@has_uk = 0,
  'ALTER TABLE `sys_dict` ADD UNIQUE KEY `uk_type_key` (`dict_type`,`dict_key`)',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- 1. 菜单：系统设置 → 字典维护 --------------------------------
INSERT INTO `sys_permission` (`id`,`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`) VALUES
  (45,40,'字典维护','system.dict',1,'/pages/sys-dict.html','Collection',45,1)
ON DUPLICATE KEY UPDATE
  `parent_id` = VALUES(`parent_id`),
  `name`      = VALUES(`name`),
  `code`      = VALUES(`code`),
  `path`      = VALUES(`path`),
  `icon`      = VALUES(`icon`),
  `sort`      = VALUES(`sort`),
  `status`    = VALUES(`status`);

-- 管理员角色（role_id = 1）默认拥有全部权限，走 '*' 通配，不必插 sys_role_permission。
-- 若要给「操作员」也开放字典维护，取消下面一行的注释：
-- INSERT IGNORE INTO `sys_role_permission` (`role_id`,`permission_id`) VALUES (2,45);

-- ---------- 2. 柜型补全 -------------------------------------------------
INSERT INTO `sys_dict` (`dict_type`,`dict_key`,`dict_value`,`sort`,`status`) VALUES
  ('container_type','53HQ','53HQ',6,1),
  ('container_type','40NOR','40NOR',7,1),
  ('container_type','40RH','40RH',8,1)
ON DUPLICATE KEY UPDATE `dict_value` = VALUES(`dict_value`), `sort` = VALUES(`sort`), `status` = VALUES(`status`);

-- ---------- 3. 船司补全 -------------------------------------------------
INSERT INTO `sys_dict` (`dict_type`,`dict_key`,`dict_value`,`sort`,`status`) VALUES
  ('carrier','达飞 CMA','达飞 CMA',5,1),
  ('carrier','马士基 MSK','马士基 MSK',6,1),
  ('carrier','阳明 YML','阳明 YML',7,1),
  ('carrier','东方海外 OOCL','东方海外 OOCL',8,1),
  ('carrier','海洋网联 ONE','海洋网联 ONE',9,1)
ON DUPLICATE KEY UPDATE `dict_value` = VALUES(`dict_value`), `sort` = VALUES(`sort`), `status` = VALUES(`status`);

-- ---------- 4. 文件类型（建单页「文件上传」用）--------------------------
INSERT INTO `sys_dict` (`dict_type`,`dict_key`,`dict_value`,`sort`,`status`) VALUES
  ('file_type','提单 BL','提单 BL',1,1),
  ('file_type','报关单','报关单',2,1),
  ('file_type','商业发票','商业发票',3,1),
  ('file_type','装箱单','装箱单',4,1),
  ('file_type','ISF 申报资料','ISF 申报资料',5,1),
  ('file_type','其他','其他',6,1)
ON DUPLICATE KEY UPDATE `dict_value` = VALUES(`dict_value`), `sort` = VALUES(`sort`), `status` = VALUES(`status`);

-- ---------- 5. 原产国（HS CODE 原产国下拉用）----------------------------
-- 键=两位国家代码（存库），值=中文名（界面显示）
INSERT INTO `sys_dict` (`dict_type`,`dict_key`,`dict_value`,`sort`,`status`) VALUES
  ('origin_country','CN','中国',1,1),
  ('origin_country','US','美国',2,1),
  ('origin_country','VN','越南',3,1),
  ('origin_country','IN','印度',4,1),
  ('origin_country','ID','印度尼西亚',5,1),
  ('origin_country','TH','泰国',6,1),
  ('origin_country','MY','马来西亚',7,1),
  ('origin_country','KR','韩国',8,1),
  ('origin_country','JP','日本',9,1),
  ('origin_country','DE','德国',10,1),
  ('origin_country','IT','意大利',11,1),
  ('origin_country','TR','土耳其',12,1)
ON DUPLICATE KEY UPDATE `dict_value` = VALUES(`dict_value`), `sort` = VALUES(`sort`), `status` = VALUES(`status`);

-- ---------- 校验 --------------------------------------------------------
-- SELECT dict_type, COUNT(*) AS n FROM sys_dict GROUP BY dict_type ORDER BY dict_type;
-- 期望：carrier 9 / container_type 8 / file_type 6 / origin_country 12
-- SELECT id, parent_id, name, path FROM sys_permission WHERE parent_id = 40 ORDER BY sort;
-- SHOW INDEX FROM sys_dict WHERE Key_name = 'uk_type_key';
