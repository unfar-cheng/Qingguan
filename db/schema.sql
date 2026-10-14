-- =============================================================
--  清关管理系统 · 数据库结构 + 初始化数据
--  MySQL 8.0 / utf8mb4 / InnoDB
--  用法：宝塔面板 → 数据库 → 先建库 qingguan(utf8mb4) → 导入本文件
--
--  【可以重复导入】建表全是 CREATE TABLE IF NOT EXISTS，种子数据全部幂等：
--    · 菜单（sys_permission）按 `code` 匹配，**不写死 id**（见文件末尾注释）
--    · 角色授权（sys_role_permission）按 code 现取 role_id / permission_id
--    · 字典（sys_dict）按 (dict_type,dict_key) 匹配
--  在已经有数据的库上重跑，只会补齐缺失项、把属性修正回设计值，
--  不会因为 id 撞号把别的菜单改名/改路径。
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- 1. 系统：用户 / 角色 / 权限
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_user` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL COMMENT '登录账号',
  `real_name`     VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '姓名',
  `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
  `phone`         VARCHAR(30)  NOT NULL DEFAULT '',
  `email`         VARCHAR(100) NOT NULL DEFAULT '',
  `dept`          VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '部门',
  `role_id`       INT UNSIGNED NULL DEFAULT NULL,
  `status`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `last_login_at` DATETIME     NULL DEFAULT NULL,
  `last_login_ip` VARCHAR(50)  NOT NULL DEFAULT '',
  `is_deleted`    TINYINT      NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NULL DEFAULT NULL,
  `updated_at`    DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='内部员工';

CREATE TABLE IF NOT EXISTS `sys_role` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(50)  NOT NULL COMMENT '角色名称',
  `code`       VARCHAR(50)  NOT NULL COMMENT '角色标识',
  `remark`     VARCHAR(255) NOT NULL DEFAULT '',
  `status`     TINYINT      NOT NULL DEFAULT 1,
  `created_at` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色';

CREATE TABLE IF NOT EXISTS `sys_permission` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `name`      VARCHAR(50)  NOT NULL COMMENT '菜单名称',
  `code`      VARCHAR(60)  NOT NULL COMMENT '权限标识',
  `type`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1菜单 2按钮',
  `path`      VARCHAR(120) NOT NULL DEFAULT '' COMMENT '页面路径',
  `icon`      VARCHAR(50)  NOT NULL DEFAULT '',
  `sort`      INT          NOT NULL DEFAULT 0,
  `status`    TINYINT      NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='菜单权限';

CREATE TABLE IF NOT EXISTS `sys_role_permission` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rp` (`role_id`,`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色权限';

CREATE TABLE IF NOT EXISTS `sys_config` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_key`  VARCHAR(50)  NOT NULL DEFAULT 'basic' COMMENT '分组',
  `cfg_key`    VARCHAR(80)  NOT NULL COMMENT '配置键',
  `cfg_value`  TEXT         NULL COMMENT '配置值',
  `remark`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT '说明',
  `updated_at` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cfg_key` (`cfg_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置';

CREATE TABLE IF NOT EXISTS `sys_dict` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dict_type`  VARCHAR(50)  NOT NULL COMMENT '字典类型',
  `dict_key`   VARCHAR(50)  NOT NULL COMMENT '键',
  `dict_value` VARCHAR(100) NOT NULL COMMENT '值',
  `sort`       INT          NOT NULL DEFAULT 0,
  `status`     TINYINT      NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`dict_type`),
  UNIQUE KEY `uk_type_key` (`dict_type`,`dict_key`)   -- 同一类型下键不重复，升级脚本靠它做幂等
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据字典';

CREATE TABLE IF NOT EXISTS `sys_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NULL DEFAULT NULL,
  `module`     VARCHAR(50)  NOT NULL DEFAULT '',
  `action`     VARCHAR(50)  NOT NULL DEFAULT '',
  `target_id`  VARCHAR(50)  NOT NULL DEFAULT '',
  `detail`     TEXT         NULL,
  `ip`         VARCHAR(50)  NOT NULL DEFAULT '',
  `created_at` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作日志';

-- -------------------------------------------------------------
-- 2. 抬头（公司）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `org_company` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_type`   TINYINT      NOT NULL DEFAULT 1 COMMENT '1发货人 2进口商 3通知方',
  `company_name`   VARCHAR(200) NOT NULL COMMENT '公司名称',
  `country`        VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '国家',
  `state_province` VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '州/省',
  `city`           VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '城市',
  `zip_code`       VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '邮编',
  `address`        VARCHAR(255) NOT NULL DEFAULT '' COMMENT '地址',
  `phone`          VARCHAR(50)  NOT NULL DEFAULT '',
  `contact`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '联系人',
  `email`          VARCHAR(100) NOT NULL DEFAULT '',
  `bond_no`        VARCHAR(60)  NOT NULL DEFAULT '' COMMENT 'BOND 号（进口商专用）',
  `remark`         VARCHAR(255) NOT NULL DEFAULT '',
  `status`         TINYINT      NOT NULL DEFAULT 1 COMMENT '状态 1启用 0停用',
  `manage_status`  TINYINT      NOT NULL DEFAULT 1 COMMENT '管理状态 1启用 0停用',
  `created_by`     INT UNSIGNED NULL DEFAULT NULL,
  `is_deleted`     TINYINT      NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NULL DEFAULT NULL,
  `updated_at`     DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`company_type`,`is_deleted`),
  KEY `idx_name` (`company_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抬头管理';

-- -------------------------------------------------------------
-- 3. 报价方案
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `biz_plan` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_code`       VARCHAR(40)  NOT NULL COMMENT '方案编码',
  `plan_name`       VARCHAR(100) NOT NULL COMMENT '方案名称',
  `amount`          DECIMAL(12,2) NULL DEFAULT NULL COMMENT '方案金额',
  `applicable_port` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '适用港口',
  `customs_port`    VARCHAR(120) NOT NULL DEFAULT '' COMMENT '清关港',
  `intro`           VARCHAR(255) NOT NULL DEFAULT '' COMMENT '方案介绍',
  `notice`          TEXT         NULL COMMENT '注意事项',
  `sort`            INT          NOT NULL DEFAULT 0,
  `status`          TINYINT      NOT NULL DEFAULT 1,
  `created_at`      DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plan_code` (`plan_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='报价方案';

-- -------------------------------------------------------------
-- 4. 订单（ISF / 清关 / 提拆派 / 查验 共用一张主表）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `biz_order` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no`          VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '系统单号',
  `customer_no`       VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '客户单号',
  `customer_order_no` VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '客户订单号',
  `mbl_no`            VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '主提单号/MBL',
  `hbl_no`            VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '分单号/HBL',
  `carrier`           VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '船公司/承运人',
  `importer_id`       INT UNSIGNED NULL DEFAULT NULL COMMENT '进口商/BOND',
  `vessel`            VARCHAR(80)  NOT NULL DEFAULT '' COMMENT '船名/Ocean Vessel',
  `voyage`            VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '航次/Voy.No.',
  `origin_country`    VARCHAR(10)  NOT NULL DEFAULT 'CN' COMMENT '原产国',
  `dest_country`      VARCHAR(10)  NOT NULL DEFAULT 'US' COMMENT '目的国家',
  `port_of_loading`   VARCHAR(80)  NOT NULL DEFAULT '' COMMENT '始发港/Port of Loading',
  `port_of_delivery`  VARCHAR(80)  NOT NULL DEFAULT '' COMMENT '目的港/Place of Delivery',
  `port_of_discharge` VARCHAR(80)  NOT NULL DEFAULT '' COMMENT '卸货港/Port of Discharge',
  `customs_port`      VARCHAR(80)  NOT NULL DEFAULT '' COMMENT '清关港',
  `etd`               DATE         NULL DEFAULT NULL,
  `eta`               DATE         NULL DEFAULT NULL,
  `isf_flag`          TINYINT      NOT NULL DEFAULT 1 COMMENT '是否申报ISF 1是 0否',
  `pickup_flag`       TINYINT      NOT NULL DEFAULT 0 COMMENT '是否提拆派 1是 0否',
  `plan_id`           INT UNSIGNED NULL DEFAULT NULL COMMENT '报价方案',
  `plan_amount`       DECIMAL(12,2) NULL DEFAULT NULL COMMENT '报价',
  `actual_fee`        DECIMAL(12,2) NULL DEFAULT NULL COMMENT '实收费用',
  `container_type`    VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '柜型',
  `container_weight`  DECIMAL(12,2) NULL DEFAULT NULL COMMENT '柜重',
  `match_status`      TINYINT      NOT NULL DEFAULT 0 COMMENT '匹配状态 1 ISP匹配成功 0未匹配',
  `customs_status`    VARCHAR(20)  NOT NULL DEFAULT 'forecast' COMMENT '清关状态 forecast/declaring/inspecting/released',
  `release_date`      DATE         NULL DEFAULT NULL COMMENT '放行日期',
  `inspect_type`      VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '查验类型',
  `inspect_abnormal`  VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '查验异常',
  `route_type`        VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '航线类型',
  `biz_type`          VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '业务类型',
  `is_verified`       TINYINT      NOT NULL DEFAULT 0 COMMENT '是否核销 1已核销 0未核销',
  `customer_remark`   VARCHAR(500) NOT NULL DEFAULT '' COMMENT '客户备注',
  `remark`            VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
  `created_by`        INT UNSIGNED NULL DEFAULT NULL,
  `is_deleted`        TINYINT      NOT NULL DEFAULT 0,
  `created_at`        DATETIME     NULL DEFAULT NULL,
  `updated_at`        DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_mbl` (`mbl_no`),
  KEY `idx_hbl` (`hbl_no`),
  KEY `idx_importer` (`importer_id`),
  KEY `idx_plan` (`plan_id`),
  KEY `idx_status` (`customs_status`,`is_deleted`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单主表';

CREATE TABLE IF NOT EXISTS `biz_order_container` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`       INT UNSIGNED NOT NULL,
  `container_no`   VARCHAR(30)  NOT NULL DEFAULT '' COMMENT '柜号',
  `container_type` VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '柜型',
  `gross_weight`   DECIMAL(12,2) NULL DEFAULT NULL COMMENT '柜重',
  `packages`       INT          NULL DEFAULT NULL COMMENT '箱数',
  `cbm`            DECIMAL(12,3) NULL DEFAULT NULL COMMENT '方数',
  `sort`           INT          NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单柜子信息';

CREATE TABLE IF NOT EXISTS `biz_order_hscode` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`       INT UNSIGNED NOT NULL,
  `hs_code`        VARCHAR(40)  NOT NULL DEFAULT '' COMMENT 'HS CODE',
  `origin_country` VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '原产国',
  `sort`           INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单HS CODE';

CREATE TABLE IF NOT EXISTS `biz_order_address` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`       INT UNSIGNED NOT NULL,
  `addr_type`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1发货人 2收货人 3通知方',
  `source_id`      INT UNSIGNED NULL DEFAULT NULL COMMENT '引用的抬头ID',
  `company`        VARCHAR(200) NOT NULL DEFAULT '' COMMENT '公司',
  `country`        VARCHAR(60)  NOT NULL DEFAULT '',
  `state_province` VARCHAR(60)  NOT NULL DEFAULT '',
  `city`           VARCHAR(60)  NOT NULL DEFAULT '',
  `zip_code`       VARCHAR(20)  NOT NULL DEFAULT '',
  `address`        VARCHAR(255) NOT NULL DEFAULT '',
  `phone`          VARCHAR(50)  NOT NULL DEFAULT '',
  `remark`         VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`,`addr_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单抬头信息';

-- -------------------------------------------------------------
-- 5. 业务子表
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `biz_isf` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NOT NULL,
  `declare_date` DATETIME     NULL DEFAULT NULL COMMENT '申报日期',
  `status`       TINYINT      NOT NULL DEFAULT 1 COMMENT '1匹配成功 0未匹配',
  `match_msg`    VARCHAR(255) NOT NULL DEFAULT '' COMMENT '匹配结果',
  `remark`       VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`   DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ISF申报记录';

CREATE TABLE IF NOT EXISTS `biz_inspect` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NOT NULL,
  `inspect_type` VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '查验类型',
  `inspect_date` DATETIME     NULL DEFAULT NULL COMMENT '查验日期',
  `abnormal`     VARCHAR(40)  NOT NULL DEFAULT '无异常' COMMENT '是否异常',
  `result`       TEXT         NULL COMMENT '查验结果',
  `fee`          DECIMAL(12,2) NULL DEFAULT NULL COMMENT '查验费用',
  `remark`       VARCHAR(500) NOT NULL DEFAULT '',
  `created_by`   INT UNSIGNED NULL DEFAULT NULL,
  `created_at`   DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='查验记录';

CREATE TABLE IF NOT EXISTS `biz_pickup` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NOT NULL,
  `pickup_type`  VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '提柜方式',
  `pickup_date`  DATETIME     NULL DEFAULT NULL COMMENT '提柜日期',
  `warehouse`    VARCHAR(150) NOT NULL DEFAULT '' COMMENT '提货仓库',
  `delivery_addr` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '派送地址',
  `contact`      VARCHAR(50)  NOT NULL DEFAULT '',
  `phone`        VARCHAR(50)  NOT NULL DEFAULT '',
  `fee`          DECIMAL(12,2) NULL DEFAULT NULL,
  `remark`       VARCHAR(500) NOT NULL DEFAULT '',
  `created_by`   INT UNSIGNED NULL DEFAULT NULL,
  `created_at`   DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='提拆派记录';

CREATE TABLE IF NOT EXISTS `biz_order_file` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`    INT UNSIGNED NOT NULL,
  `file_type`   VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '文件类型',
  `file_name`   VARCHAR(200) NOT NULL DEFAULT '',
  `file_path`   VARCHAR(255) NOT NULL DEFAULT '',
  `file_size`   BIGINT       NOT NULL DEFAULT 0,
  `uploaded_by` INT UNSIGNED NULL DEFAULT NULL,
  `created_at`  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单附件';

CREATE TABLE IF NOT EXISTS `biz_order_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`    INT UNSIGNED NOT NULL,
  `action`      VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '操作动作',
  `field`       VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '变更字段',
  `old_value`   VARCHAR(255) NOT NULL DEFAULT '',
  `new_value`   VARCHAR(255) NOT NULL DEFAULT '',
  `detail`      TEXT         NULL,
  `remark`      VARCHAR(500) NOT NULL DEFAULT '',
  `operator_id` INT UNSIGNED NULL DEFAULT NULL,
  `created_at`  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单操作日志';

-- -------------------------------------------------------------
-- 6. 财务
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fin_account` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL COMMENT '公司',
  `balance`    DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '账户余额',
  `frozen`     DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '冻结金额',
  `created_at` DATETIME     NULL DEFAULT NULL,
  `updated_at` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账户余额';

CREATE TABLE IF NOT EXISTS `fin_recharge` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id`  INT UNSIGNED NOT NULL,
  `amount`      DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '充值金额',
  `pay_type`    VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '支付方式',
  `voucher`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT '凭证号/流水号',
  `status`      TINYINT      NOT NULL DEFAULT 0 COMMENT '0待确认 1已入账 2已驳回',
  `remark`      VARCHAR(500) NOT NULL DEFAULT '',
  `operator_id` INT UNSIGNED NULL DEFAULT NULL,
  `created_at`  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='充值记录';

CREATE TABLE IF NOT EXISTS `fin_payable` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NULL DEFAULT NULL COMMENT '关联订单',
  `company_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '应收/应付对象',
  `fee_name`   VARCHAR(120) NOT NULL DEFAULT '' COMMENT '费用名称',
  `amount`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `currency`   VARCHAR(10)  NOT NULL DEFAULT 'CNY',
  `status`     TINYINT      NOT NULL DEFAULT 0 COMMENT '0未付 1已付 2部分付',
  `due_date`   DATE         NULL DEFAULT NULL COMMENT '账期',
  `paid_at`    DATETIME     NULL DEFAULT NULL,
  `remark`     VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='应付账单';

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
--  初始化数据
-- =============================================================

-- 角色 --------------------------------------------------------
INSERT INTO `sys_role` (`id`,`name`,`code`,`remark`,`status`,`created_at`) VALUES
  (1,'超级管理员','super','拥有全部权限',1,NOW()),
  (2,'操作员','operator','订单与抬头日常操作',1,NOW()),
  (3,'财务','finance','财务模块权限',1,NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 菜单权限（与左侧导航一一对应，20 项）----------------------
-- 【为什么这一段一个 id 都不写】
--   · id 交给 AUTO_INCREMENT，幂等只认 `code`（表上有 UNIQUE KEY `uk_code`）；
--   · 二级菜单的 parent_id 一律 JOIN 父节点的 code 现取；
--   · 末尾再用 UPDATE ... JOIN 把 parent_id 校正一遍，让「父节点挂错」的库自愈。
--   这样本段在「全新的空库」和「已经用了一阵子的库」上跑出来结果一致：同名 code
--   只更新属性，绝不会因为 id 撞号把别的菜单改名/改路径。
--   早期版本曾在脚本里硬编码过 id / parent_id，后果就是
--   「字典维护 parent_id=40，而库里 系统设置 的 id 是 15」→ 父节点不存在 →
--   孤儿节点被 AuthController::menu() 静默丢弃 → 左侧菜单永远不显示、且不报错。
--   以后再也不要往这张表里写死 id。
--
--   两条 MySQL 细节（都按官方文档来，别改成想当然的写法）：
--   a) INSERT ... SELECT 允许目标表出现在 SELECT 的 FROM 里（文档明确允许），
--      但不允许出现在子查询里 —— 所以父节点 id 用 JOIN 取、不用标量子查询；
--      UNION 必须包成派生表，否则 ON DUPLICATE KEY UPDATE 引用不到它的列。
--   b) ON DUPLICATE KEY UPDATE 里引用派生表别名 s 的列，而不是 VALUES() ——
--      后者在 MySQL 8.0.20+ 的 INSERT ... SELECT 中会抛弃用警告。

-- 1) 顶级菜单（parent_id 固定 0，不涉及自引用）
INSERT INTO `sys_permission` (`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT 0, s.n, s.c, 1, s.pa, s.i, s.so, 1
  FROM (
              SELECT '首页'     AS n, 'home'    AS c, '/pages/home.html' AS pa, 'HomeFilled'     AS i,  1 AS so FROM DUAL
    UNION ALL SELECT '订单管理', 'order',   '',                   'Tickets',        10
    UNION ALL SELECT '抬头管理', 'org',     '',                   'OfficeBuilding', 20
    UNION ALL SELECT '财务管理', 'finance', '',                   'Money',          30
    UNION ALL SELECT '系统设置', 'system',  '',                   'Setting',        40
  ) AS s
ON DUPLICATE KEY UPDATE
  `name` = s.n, `path` = s.pa, `icon` = s.i, `sort` = s.so;

-- 2) 二级菜单：parent_id 由父节点 code 现取（JOIN 到的 p 就是父节点那一行）
INSERT INTO `sys_permission` (`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT p.`id`, s.n, s.c, 1, s.pa, s.i, s.so, 1
  FROM (
              SELECT '创建订单'   AS n, 'order.create'  AS c, '/pages/order-create.html'  AS pa, 'Plus'     AS i, 11 AS so FROM DUAL
    UNION ALL SELECT 'ISF订单',    'order.isf',      '/pages/order-isf.html',     'Document', 12
    UNION ALL SELECT '清关订单',   'order.customs',  '/pages/order-customs.html', 'Files',    13
    UNION ALL SELECT '提拆派订单', 'order.pickup',   '/pages/order-pickup.html',  'Van',      14
    UNION ALL SELECT '查验订单',   'order.inspect',  '/pages/order-inspect.html', 'Search',   15
  ) AS s
  JOIN `sys_permission` p ON p.`code` = 'order' AND p.`parent_id` = 0
ON DUPLICATE KEY UPDATE
  `name` = s.n, `path` = s.pa, `icon` = s.i, `sort` = s.so;

INSERT INTO `sys_permission` (`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT p.`id`, s.n, s.c, 1, s.pa, s.i, s.so, 1
  FROM (
              SELECT '发货人管理' AS n, 'org.shipper'  AS c, '/pages/org-shipper.html'  AS pa, 'Avatar'   AS i, 21 AS so FROM DUAL
    UNION ALL SELECT '进口商管理', 'org.importer',  '/pages/org-importer.html', 'Postcard', 22
  ) AS s
  JOIN `sys_permission` p ON p.`code` = 'org' AND p.`parent_id` = 0
ON DUPLICATE KEY UPDATE
  `name` = s.n, `path` = s.pa, `icon` = s.i, `sort` = s.so;

INSERT INTO `sys_permission` (`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT p.`id`, s.n, s.c, 1, s.pa, s.i, s.so, 1
  FROM (
              SELECT '账户余额' AS n, 'finance.account'  AS c, '/pages/fin-account.html'  AS pa, 'Wallet'     AS i, 31 AS so FROM DUAL
    UNION ALL SELECT '充值记录', 'finance.recharge', '/pages/fin-recharge.html', 'CreditCard', 32
    UNION ALL SELECT '应付账单', 'finance.payable',  '/pages/fin-payable.html',  'Bill',       33
  ) AS s
  JOIN `sys_permission` p ON p.`code` = 'finance' AND p.`parent_id` = 0
ON DUPLICATE KEY UPDATE
  `name` = s.n, `path` = s.pa, `icon` = s.i, `sort` = s.so;

INSERT INTO `sys_permission` (`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT p.`id`, s.n, s.c, 1, s.pa, s.i, s.so, 1
  FROM (
              SELECT '报价方案' AS n, 'system.plan'   AS c, '/pages/sys-plan.html'       AS pa, 'PriceTag'   AS i, 41 AS so FROM DUAL
    UNION ALL SELECT '内部员工', 'system.staff',  '/pages/sys-staff.html',      'UserFilled', 42
    UNION ALL SELECT '权限管理', 'system.role',   '/pages/sys-permission.html', 'Lock',       43
    UNION ALL SELECT '收发信息', 'system.config', '/pages/sys-config.html',     'Message',    44
    UNION ALL SELECT '字典维护', 'system.dict',   '/pages/sys-dict.html',       'Collection', 45
  ) AS s
  JOIN `sys_permission` p ON p.`code` = 'system' AND p.`parent_id` = 0
ON DUPLICATE KEY UPDATE
  `name` = s.n, `path` = s.pa, `icon` = s.i, `sort` = s.so;

-- 3) 兜底校正 parent_id（自愈）
--    上面 INSERT 只在「新插一行」时才带上正确的 parent_id；已存在但父节点挂错的
--    节点（= 左侧菜单看不到的主因）靠这几条 UPDATE 修回来，幂等，跑几次都一样。
--    刻意不写 status：某个菜单被管理员手工停用了，重导不该把它偷偷打开。
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

-- 4) 按钮级权限：各订单功能的「创建新订单」（type=2）
--    只决定「能不能点新增按钮 / 能不能调 POST /orders」，**不进左侧菜单**
--    （AuthController::menu() 只查 type=1，所以 type=2 天然不会出现在菜单里）。
--    code 用 `xxx.new` 而不是 `xxx`：menu 的 code 已经占了 order.create / order.isf … ，
--    uk_code 是全局唯一，重名会直接插失败。
--    默认策略：下面「角色权限」段会给所有**非超管角色**都授权（= 升级前后行为一致），
--    要限制谁不能建单，去「系统设置 → 权限管理 → 角色权限」把对应按钮取消勾选即可。
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

-- 4.1) 按钮权限的 parent_id 自愈：`xxx.new` 的父节点就是去掉 `.new` 的那个菜单
UPDATE `sys_permission` AS c
  JOIN `sys_permission` AS p
    ON p.`code` = SUBSTRING_INDEX(c.`code`, '.new', 1) AND p.`type` = 1
   SET c.`parent_id` = p.`id`
 WHERE c.`code` IN ('order.create.new', 'order.isf.new', 'order.customs.new',
                    'order.pickup.new', 'order.inspect.new');

-- 角色权限：操作员 / 财务（同样不写死 id，按 role.code + permission.code 现取）--
INSERT IGNORE INTO `sys_role_permission` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
  FROM `sys_role` r
  JOIN `sys_permission` p ON p.`code` IN (
        'home', 'order', 'order.create', 'order.isf', 'order.customs',
        'order.pickup', 'order.inspect', 'org', 'org.shipper', 'org.importer', 'system.plan')
 WHERE r.`code` = 'operator';
-- 角色权限：财务 ---------------------------------------------
INSERT IGNORE INTO `sys_role_permission` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
  FROM `sys_role` r
  JOIN `sys_permission` p ON p.`code` IN (
        'home', 'finance', 'finance.account', 'finance.recharge', 'finance.payable')
 WHERE r.`code` = 'finance';

-- 角色权限：创建订单按钮（type=2）-----------------------------
-- 默认**授予所有非超管角色**，保证升级前后行为一致（升级前谁都能建单，升级后照旧）；
-- 要限制某个角色不能建单 → 「系统设置 → 权限管理 → 角色权限」取消对应按钮的勾选。
-- （超管走 role_code='super' 的 ['*'] 通配，不需要授权记录）
INSERT IGNORE INTO `sys_role_permission` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
  FROM `sys_role` r
  JOIN `sys_permission` p ON p.`code` IN (
        'order.create.new', 'order.isf.new', 'order.customs.new',
        'order.pickup.new', 'order.inspect.new')
 WHERE r.`code` <> 'super';

-- 系统配置 ---------------------------------------------------
INSERT INTO `sys_config` (`group_key`,`cfg_key`,`cfg_value`,`remark`) VALUES
  ('company','company.name'       ,'深圳市鹏成运通国际物流有限公司','公司名称'),
  ('company','company.tel'        ,''                        ,'联系电话'),
  ('company','company.address'    ,''                        ,'公司地址'),
  ('mail'   ,'mail.host'          ,''                        ,'邮件服务器'),
  ('mail'   ,'mail.port'          ,'465'                     ,'邮件端口'),
  ('mail'   ,'mail.user'          ,''                        ,'发信账号'),
  ('mail'   ,'mail.pass'          ,''                        ,'发信密码/授权码'),
  ('mail'   ,'mail.from_name'     ,'清关系统'                 ,'发件人名称'),
  ('mail'   ,'mail.to'            ,''                        ,'默认收件人（多个用逗号分隔）'),
  ('sms'    ,'sms.enabled'        ,'0'                       ,'短信通知开关 1开 0关'),
  ('sms'    ,'sms.sign'           ,'【鹏成运通】'              ,'短信签名'),
  ('system' ,'system.page_size'   ,'50'                      ,'列表默认每页条数'),
  ('system' ,'system.upload_max_mb','50'                     ,'附件大小上限(MB)'),
  ('system' ,'system.auto_isf'    ,'1'                       ,'新建订单自动创建ISF记录'),
  ('system' ,'system.order_no_prefix','QG'                   ,'订单号前缀'),
  ('system' ,'notify.on_release'  ,'1'                       ,'放行时通知客户'),
  ('system' ,'notify.on_inspect'  ,'1'                       ,'查验时通知客户')
ON DUPLICATE KEY UPDATE `remark` = VALUES(`remark`);

-- 数据字典 ---------------------------------------------------
INSERT INTO `sys_dict` (`dict_type`,`dict_key`,`dict_value`,`sort`,`status`) VALUES
  ('container_type','20GP','20GP',1,1),
  ('container_type','40GP','40GP',2,1),
  ('container_type','40HQ','40HQ',3,1),
  ('container_type','45HQ','45HQ',4,1),
  ('container_type','40HC','40HC',5,1),
  ('container_type','53HQ','53HQ',6,1),
  ('container_type','40NOR','40NOR',7,1),
  ('container_type','40RH','40RH',8,1),
  ('customs_status','forecast','已预报',1,1),
  ('customs_status','declaring','申报中',2,1),
  ('customs_status','inspecting','查验中',3,1),
  ('customs_status','released','已放行',4,1),
  ('inspect_type','人工查验','人工查验',1,1),
  ('inspect_type','机检查验','机检查验',2,1),
  ('inspect_type','X光查验','X光查验',3,1),
  ('inspect_type','CET查验','CET查验',4,1),
  ('inspect_abnormal','无异常','无异常',1,1),
  ('inspect_abnormal','有异常','有异常',2,1),
  ('route_type','OA普船','OA普船',1,1),
  ('route_type','美森快船','美森快船',2,1),
  ('route_type','以星快船','以星快船',3,1),
  ('biz_type','整柜清关','整柜清关',1,1),
  ('biz_type','提拆派','提拆派',2,1),
  ('pay_type','银行转账','银行转账',1,1),
  ('pay_type','支付宝','支付宝',2,1),
  ('pay_type','微信','微信',3,1),
  ('pay_type','现金','现金',4,1),
  ('currency','CNY','CNY 人民币',1,1),
  ('currency','USD','USD 美元',2,1),
  -- 始发港（origin_port）：**只给建单页的「始发港 / Port of Loading」用**，
  -- 内容是「中国出运港」；与下面 port（目的国港口）是两套完全独立的数据，别混。
  ('origin_port','宁波港','宁波港',1,1),
  ('origin_port','上海港','上海港',2,1),
  ('origin_port','深圳盐田港','深圳盐田港',3,1),
  ('origin_port','深圳蛇口港','深圳蛇口港',4,1),
  ('origin_port','广州南沙港','广州南沙港',5,1),
  ('origin_port','青岛港','青岛港',6,1),
  ('origin_port','天津港','天津港',7,1),
  ('origin_port','厦门港','厦门港',8,1),
  -- 港口（port）：**目的港 / 卸货港 / 清关港 三个下拉共用这一套**，
  -- 内容是「目的国港口」（中美线主要填美国港口）。
  ('port','洛杉矶港','洛杉矶港',1,1),
  ('port','长滩港','长滩港',2,1),
  ('port','纽约港','纽约港',3,1),
  ('port','萨凡纳港','萨凡纳港',4,1),
  ('port','休斯顿港','休斯顿港',5,1),
  ('port','芝加哥港','芝加哥港',6,1),
  ('port','西雅图港','西雅图港',7,1),
  ('port','奥克兰港','奥克兰港',8,1),
  ('carrier','美森轮船 MATSON','美森轮船 MATSON',1,1),
  ('carrier','以星航运 ZIM','以星航运 ZIM',2,1),
  ('carrier','中远海运 COSCO','中远海运 COSCO',3,1),
  ('carrier','长荣海运 EVERGREEN','长荣海运 EVERGREEN',4,1),
  ('carrier','达飞 CMA','达飞 CMA',5,1),
  ('carrier','马士基 MSK','马士基 MSK',6,1),
  ('carrier','阳明 YML','阳明 YML',7,1),
  ('carrier','东方海外 OOCL','东方海外 OOCL',8,1),
  ('carrier','海洋网联 ONE','海洋网联 ONE',9,1),
  ('file_type','提单 BL','提单 BL',1,1),
  ('file_type','报关单','报关单',2,1),
  ('file_type','商业发票','商业发票',3,1),
  ('file_type','装箱单','装箱单',4,1),
  ('file_type','ISF 申报资料','ISF 申报资料',5,1),
  ('file_type','其他','其他',6,1),
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
ON DUPLICATE KEY UPDATE `id` = `id`;   -- 已存在则原样保留，不覆盖你手工改过的显示名

-- 报价方案（三条示例，金额与条款请按实际业务在后台手动录入）---
INSERT INTO `biz_plan` (`id`,`plan_code`,`plan_name`,`amount`,`applicable_port`,`customs_port`,`intro`,`notice`,`sort`,`status`) VALUES
  (1,'A','方案A--美森',NULL,'','','查验费补贴上限 2000 USD','1、限重 16T 以内，纺织品 + 鞋帽 + 箱包 仅限 15 方以内/柜，不能含反倾销品名；\n2、订舱申报品名必须填写普货品名；\n3、柜尾需装 3 排以上普货收尾；\n4、一个 BOND 每月只能使用 8 个柜子以内，清关必须提供完整资料。',1,1),
  (2,'B','方案B--以星',NULL,'','','查验费补贴上限 2000 USD','1、限重 16T 以内，纺织品 + 鞋帽 + 箱包 仅限 15 方以内/柜，不能含反倾销品名；\n2、订舱申报品名必须填写普货品名；\n3、柜尾需装 3 排以上普货收尾；\n4、一个 BOND 每月只能使用 8 个柜子以内，清关必须提供完整资料。',2,1),
  (3,'C','方案C--OA普船',NULL,'','','不包查验补贴','1、限重 16T 以内，纺织品 + 鞋帽 + 箱包 仅限 15 方以内/柜，不能含反倾销品名；\n2、订舱申报品名必须填写普货品名；\n3、柜尾需装 3 排以上普货收尾；\n4、一个 BOND 每月只能使用 8 个柜子以内，清关必须提供完整资料。',3,1)
ON DUPLICATE KEY UPDATE `plan_name` = VALUES(`plan_name`), `intro` = VALUES(`intro`), `notice` = VALUES(`notice`);

-- 说明：不预置管理员账号。
-- 导入完成后访问一次 http://你的域名/api/install.php ，按提示设置初始管理员账号与密码
-- （密码由 PHP password_hash 现场生成，避免写死哈希）。
