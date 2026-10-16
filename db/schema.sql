-- =============================================================
--  清关管理系统 · 数据面库结构 + 产品默认数据
--
--  【定位】本文件是数据面库的**唯一权威**：
--    · 新建共享档租户（在共享库里建该租户的数据）用它；
--    · 为专用档租户创建独立库（CREATE DATABASE + 灌本文件）用它；
--    · 任何一次表结构变更，改完 db/migration_*.sql 之后**必须同步改本文件的 DDL 段**。
--      一致性由 tests/test_schema_parity.php 把住：它会拿本文件声明的结构与
--      当前开发库（= 生产克隆 + 全部迁移）逐列逐键比对，不一致就红。
--
--  【幂等】本文件必须能重复执行、结果不变：
--    · DDL 一律 CREATE TABLE IF NOT EXISTS；
--    · 数据一律 INSERT ... ON DUPLICATE KEY UPDATE（靠租户复合唯一键去重）；
--    · 自愈用的 UPDATE ... JOIN 也必须带 tenant_id 条件，别跨租户乱改。
--
--  【用法】
--    新装 / 租户 #1（不 SET 时默认 1）：
--        mysql -u<user> -p <库名> < db/schema.sql
--    为租户 <id> 建库/播种：
--        SET @tenant_id := <id>;  SOURCE db/schema.sql;
--    共享档与专用档用的是同一个文件：共享库上 DDL 段是空操作（表都在），
--    真正落地的是该 tenant_id 的默认数据。
--
--  【写入边界】只放两类东西：
--    1) 21 张表的结构；
--    2) **产品级默认数据**——菜单与权限节点、产品角色、角色默认授权、
--       系统配置默认项、功能字典。
--    不放租户的业务数据（报价方案、抬头、订单、附件、日志），也不放管理员账号：
--    管理员由开通流程按控制面 saas_tenant.admin_username / admin_password_hash 落到 sys_user。
--    理由：业务数据跟租户走、账号跟申请走，把它们预置进「所有新租户的模板」只会串味。
--
--  【数据的两处口径，别照抄现网租户 #1】
--    · sys_dict / sys_config 用**功能完整的产品默认值**（下面注释里逐条说明），
--      因为租户 #1 的这两张表已经被客户改过：sys_config 里那 14 行是旧命名
--      （company_name / page_size …），**没有任何代码读**，是死数据；sys_dict 缺
--      inspect_type / pay_type，照抄会让新租户的下拉框直接是空的。
--    · sys_permission / sys_role / sys_role_permission 则与现网租户 #1 **逐行对齐**
--      （菜单树、图标、排序、默认授权），因为它们描述的是「这个产品有哪些页面」，
--      属于结构而非业务数据。
-- =============================================================
SET NAMES utf8mb4;

-- 目标租户。不设则视为租户 #1（历史单机数据整体 = 租户 1）。
SET @tenant_id := IFNULL(@tenant_id, 1);

-- -------------------------------------------------------------
--  系统与权限
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_user` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `username` VARCHAR(50) NOT NULL COMMENT '登录账号',
  `real_name` VARCHAR(50) NOT NULL COMMENT '姓名',
  `password_hash` VARCHAR(255) NOT NULL COMMENT '密码哈希',
  `phone` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `dept` VARCHAR(50) DEFAULT NULL COMMENT '所属部门',
  `role_id` INT UNSIGNED DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '首登强制改密',
  `last_login_at` DATETIME DEFAULT NULL,
  `last_login_ip` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_username` (`tenant_id`,`username`),
  KEY `idx_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='内部员工';

CREATE TABLE IF NOT EXISTS `sys_role` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(50) NOT NULL COMMENT '角色名称',
  `code` VARCHAR(50) NOT NULL COMMENT '角色标识',
  `remark` VARCHAR(255) DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色';

CREATE TABLE IF NOT EXISTS `sys_permission` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `name` VARCHAR(50) NOT NULL COMMENT '权限名称',
  `code` VARCHAR(80) NOT NULL COMMENT '权限标识，如 order.isp.list',
  `type` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1菜单 2按钮',
  `path` VARCHAR(255) DEFAULT NULL COMMENT '前端路由',
  `icon` VARCHAR(50) DEFAULT NULL COMMENT '菜单图标',
  `sort` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='权限/菜单';

CREATE TABLE IF NOT EXISTS `sys_role_permission` (
  `role_id` INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色-权限';

CREATE TABLE IF NOT EXISTS `sys_config` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `group_key` VARCHAR(50) NOT NULL DEFAULT 'basic' COMMENT '分组',
  `cfg_key` VARCHAR(80) NOT NULL,
  `cfg_value` TEXT DEFAULT NULL,
  `remark` VARCHAR(255) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_cfg_key` (`tenant_id`,`cfg_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置（含「收发信息」）';

CREATE TABLE IF NOT EXISTS `sys_dict` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `dict_type` VARCHAR(50) NOT NULL COMMENT '如 carrier/port/container_type',
  `dict_key` VARCHAR(50) NOT NULL,
  `dict_value` VARCHAR(100) NOT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_type_key` (`tenant_id`,`dict_type`,`dict_key`),
  KEY `idx_type` (`dict_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据字典';

CREATE TABLE IF NOT EXISTS `sys_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `module` VARCHAR(50) DEFAULT NULL COMMENT '模块',
  `action` VARCHAR(50) DEFAULT NULL COMMENT '动作',
  `target_id` BIGINT DEFAULT NULL COMMENT '目标记录ID',
  `detail` TEXT DEFAULT NULL COMMENT '详情/变更前后',
  `ip` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`,`created_at`),
  KEY `idx_target` (`module`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作日志';

-- -------------------------------------------------------------
--  基础资料
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `org_company` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `company_type` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1发货人 2进口商(收货人) 3通知方',
  `company_name` VARCHAR(200) NOT NULL COMMENT '公司',
  `country` VARCHAR(10) DEFAULT NULL COMMENT '国家（两位代码，如 CN/US）',
  `state_province` VARCHAR(50) DEFAULT NULL COMMENT '州/省',
  `city` VARCHAR(50) DEFAULT NULL COMMENT '城市',
  `zip_code` VARCHAR(20) DEFAULT NULL COMMENT '邮编',
  `address` VARCHAR(255) DEFAULT NULL COMMENT '地址',
  `phone` VARCHAR(50) DEFAULT NULL COMMENT '电话',
  `contact` VARCHAR(50) DEFAULT NULL COMMENT '联系人',
  `email` VARCHAR(100) DEFAULT NULL,
  `bond_no` VARCHAR(50) DEFAULT NULL COMMENT 'BOND 号（进口商专用）',
  `remark` VARCHAR(255) DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态：1已启用 0不可用',
  `manage_status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '管理状态：1已启用 0不可用',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_type_status` (`company_type`,`status`),
  KEY `idx_name` (`company_name`),
  KEY `idx_tenant_list` (`tenant_id`,`is_deleted`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抬头（发货人/进口商/通知方）';

CREATE TABLE IF NOT EXISTS `biz_plan` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `plan_code` VARCHAR(20) NOT NULL COMMENT '方案编码：A/B/C',
  `plan_name` VARCHAR(100) NOT NULL COMMENT '方案名称，如 方案A--美森',
  `amount` DECIMAL(12,2) DEFAULT NULL COMMENT '方案金额',
  `applicable_port` VARCHAR(255) DEFAULT NULL COMMENT '适用港口',
  `customs_port` VARCHAR(255) DEFAULT NULL COMMENT '清关口岸',
  `intro` TEXT DEFAULT NULL COMMENT '方案介绍',
  `notice` TEXT DEFAULT NULL COMMENT '注意事项',
  `sort` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_plan` (`tenant_id`,`plan_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='报价方案';

-- -------------------------------------------------------------
--  订单
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `biz_order` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `order_no` VARCHAR(30) NOT NULL COMMENT '系统单号',
  `customer_no` VARCHAR(50) DEFAULT NULL COMMENT '客户单号',
  `customer_order_no` VARCHAR(50) DEFAULT NULL COMMENT '客户订单号',
  `mbl_no` VARCHAR(50) NOT NULL COMMENT '主提单号/MBL',
  `carrier` VARCHAR(50) DEFAULT NULL COMMENT '船司',
  `importer_id` INT UNSIGNED DEFAULT NULL COMMENT '进口商/BOND（关联 org_company）',
  `vessel` VARCHAR(100) DEFAULT NULL COMMENT '船名/Ocean Vessel',
  `origin_country` VARCHAR(10) DEFAULT NULL COMMENT '原产国，默认 CN',
  `port_of_loading` VARCHAR(100) DEFAULT NULL COMMENT '始发港/Port of Loading',
  `port_of_delivery` VARCHAR(100) DEFAULT NULL COMMENT '目的港/Place of Delivery',
  `etd` DATE DEFAULT NULL,
  `hbl_no` VARCHAR(50) DEFAULT NULL COMMENT '分单号/HBL',
  `isf_flag` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '申报ISF：1是 0否',
  `pickup_flag` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '提拆派：1是 0否',
  `voyage` VARCHAR(50) DEFAULT NULL COMMENT '船次/Voy.No.',
  `dest_country` VARCHAR(10) DEFAULT NULL COMMENT '目的国家，默认 US',
  `port_of_discharge` VARCHAR(100) DEFAULT NULL COMMENT '卸货港/Port of Discharge',
  `customs_port` VARCHAR(100) DEFAULT NULL COMMENT '清关港',
  `eta` DATE DEFAULT NULL,
  `plan_id` INT UNSIGNED DEFAULT NULL COMMENT '所选报价方案',
  `plan_amount` DECIMAL(12,2) DEFAULT NULL COMMENT '报价',
  `actual_fee` DECIMAL(12,2) DEFAULT NULL COMMENT '实收费用',
  `container_type` VARCHAR(20) DEFAULT NULL COMMENT '柜型，如 40HQ/40HC',
  `container_weight` DECIMAL(12,2) DEFAULT NULL COMMENT '柜重',
  `match_status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '匹配状态：0未匹配 1ISP匹配成功',
  `customs_status` VARCHAR(20) DEFAULT NULL COMMENT '清关状态：forecast申报中/inspecting查验中/released已放行',
  `release_date` DATE DEFAULT NULL COMMENT '放行日期',
  `inspect_type` VARCHAR(50) DEFAULT NULL COMMENT '查验类型',
  `inspect_abnormal` VARCHAR(255) DEFAULT NULL COMMENT '查验异常',
  `route_type` VARCHAR(50) DEFAULT NULL COMMENT '航线类型',
  `biz_type` VARCHAR(50) DEFAULT NULL COMMENT '类型',
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否核销：0未核销 1已核销',
  `customer_remark` VARCHAR(500) DEFAULT NULL COMMENT '客户备注',
  `remark` VARCHAR(500) DEFAULT NULL COMMENT '备注',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_order_no` (`tenant_id`,`order_no`),
  KEY `idx_mbl` (`mbl_no`),
  KEY `idx_hbl` (`hbl_no`),
  KEY `idx_importer` (`importer_id`),
  KEY `idx_etd` (`etd`),
  KEY `idx_eta` (`eta`),
  KEY `idx_status` (`customs_status`,`match_status`),
  KEY `idx_tenant_list` (`tenant_id`,`is_deleted`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='海运提单/清关订单主表';

CREATE TABLE IF NOT EXISTS `biz_order_container` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `container_no` VARCHAR(30) DEFAULT NULL COMMENT '柜号/Container No.',
  `container_type` VARCHAR(20) DEFAULT NULL COMMENT '柜型',
  `gross_weight` DECIMAL(12,2) DEFAULT NULL COMMENT '单重/Gross Weight',
  `packages` INT DEFAULT NULL COMMENT '箱件/No. of Pkgs.',
  `cbm` DECIMAL(12,3) DEFAULT NULL COMMENT '方数/CBM',
  `sort` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单柜子明细';

CREATE TABLE IF NOT EXISTS `biz_order_hscode` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `hs_code` VARCHAR(30) NOT NULL COMMENT 'HS CODE',
  `origin_country` VARCHAR(10) DEFAULT NULL COMMENT '原产国',
  `sort` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单 HS CODE 明细';

CREATE TABLE IF NOT EXISTS `biz_order_address` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `addr_type` TINYINT(1) NOT NULL COMMENT '1 Shipper发货人 2 Consignee收货人 3 Notify party通知方',
  `source_id` INT UNSIGNED DEFAULT NULL COMMENT '引用的 org_company.id，手填为 NULL',
  `company` VARCHAR(200) DEFAULT NULL COMMENT '公司',
  `country` VARCHAR(10) DEFAULT NULL COMMENT '国家',
  `state_province` VARCHAR(50) DEFAULT NULL COMMENT '州/省',
  `city` VARCHAR(50) DEFAULT NULL COMMENT '城市',
  `zip_code` VARCHAR(20) DEFAULT NULL COMMENT '邮编',
  `address` VARCHAR(255) DEFAULT NULL COMMENT '地址',
  `phone` VARCHAR(50) DEFAULT NULL COMMENT '电话',
  `remark` VARCHAR(255) DEFAULT NULL COMMENT '备注',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_type` (`order_id`,`addr_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单抬头信息（发货人/收货人/通知方）';

CREATE TABLE IF NOT EXISTS `biz_order_file` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `file_type` VARCHAR(50) DEFAULT NULL COMMENT '文件类型',
  `file_name` VARCHAR(255) NOT NULL COMMENT '原始文件名',
  `file_path` VARCHAR(500) NOT NULL COMMENT '存储路径',
  `file_size` BIGINT DEFAULT NULL,
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_tenant_order` (`tenant_id`,`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单附件';

CREATE TABLE IF NOT EXISTS `biz_order_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(50) DEFAULT NULL COMMENT '动作：创建/编辑/状态变更/上传',
  `field` VARCHAR(50) DEFAULT NULL,
  `old_value` VARCHAR(500) DEFAULT NULL,
  `new_value` VARCHAR(500) DEFAULT NULL,
  `detail` TEXT DEFAULT NULL COMMENT '变更详情',
  `remark` VARCHAR(500) DEFAULT NULL,
  `operator_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_time` (`order_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单操作日志（对应列表页「日志」入口）';

-- -------------------------------------------------------------
--  报关/查验/提拆派
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `biz_isf` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `declare_date` DATETIME DEFAULT NULL COMMENT '申报日期',
  `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0未申报 1已匹配成功 2失败',
  `match_msg` VARCHAR(255) DEFAULT NULL COMMENT '匹配结果说明',
  `response` TEXT DEFAULT NULL COMMENT '接口原始返回',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_declare` (`declare_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ISF 申报记录';

CREATE TABLE IF NOT EXISTS `biz_inspect` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `inspect_type` VARCHAR(50) DEFAULT NULL COMMENT '查验类型',
  `inspect_date` DATETIME DEFAULT NULL COMMENT '查验日期',
  `abnormal` VARCHAR(255) DEFAULT NULL COMMENT '查验异常',
  `result` VARCHAR(50) DEFAULT NULL COMMENT '查验结果',
  `fee` DECIMAL(12,2) DEFAULT NULL COMMENT '查验费用',
  `remark` VARCHAR(500) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='查验订单';

CREATE TABLE IF NOT EXISTS `biz_pickup` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `pickup_addr` VARCHAR(255) DEFAULT NULL COMMENT '提货地址',
  `delivery_addr` VARCHAR(255) DEFAULT NULL COMMENT '送货地址',
  `plan_date` DATETIME DEFAULT NULL COMMENT '预约提货时间',
  `status` VARCHAR(20) DEFAULT NULL COMMENT '预报状态',
  `remark` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='提拆派预报';

-- -------------------------------------------------------------
--  财务
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fin_account` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL COMMENT '客户/公司',
  `balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '账户余额',
  `frozen` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '冻结金额',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_company` (`tenant_id`,`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账户余额';

CREATE TABLE IF NOT EXISTS `fin_recharge` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL COMMENT '充值金额',
  `pay_type` VARCHAR(50) DEFAULT NULL COMMENT '付款方式',
  `voucher` VARCHAR(500) DEFAULT NULL COMMENT '凭证文件',
  `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0待确认 1已入账 2已驳回',
  `remark` VARCHAR(500) DEFAULT NULL,
  `operator_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='充值记录';

CREATE TABLE IF NOT EXISTS `fin_payable` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED DEFAULT NULL,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `fee_name` VARCHAR(100) DEFAULT NULL COMMENT '费用项',
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'CNY',
  `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0未付 1已付 2部分付',
  `due_date` DATE DEFAULT NULL COMMENT '应付日期',
  `paid_at` DATETIME DEFAULT NULL,
  `remark` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='应付账单';
-- =============================================================
--  产品默认数据
-- =============================================================
--  下面每一条 INSERT 都显式带上 tenant_id = @tenant_id。
--  去重靠表上的**租户复合唯一键**：sys_role(uk_tenant_code)、
--  sys_permission(uk_tenant_code)、sys_config(uk_tenant_cfg_key)、
--  sys_dict(uk_tenant_type_key)，所以同一个文件在同一个库上跑多少次，
--  结果都一样（新增租户时也只在它自己的 tenant_id 下写入）。
-- =============================================================

-- 角色 --------------------------------------------------------
-- 三个产品角色。「租户系统管理员」的 code 是 tenant_admin，它在 Auth 里
-- 短路成权限 `*`（本租户内全权），所以不需要 sys_role_permission 授权行。
-- 【不写 id】id 交给 AUTO_INCREMENT：写死 id 会在共享库里跟别的租户撞主键。
INSERT INTO `sys_role` (`tenant_id`,`name`,`code`,`remark`,`status`,`created_at`) VALUES
  (@tenant_id,'租户系统管理员','tenant_admin','本租户内全部权限',1,NOW()),
  (@tenant_id,'操作员','operator','订单与抬头日常操作',1,NOW()),
  (@tenant_id,'财务','finance','财务模块权限',1,NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `remark` = VALUES(`remark`);

-- 菜单与权限节点（20 个菜单 + 5 个按钮 = 25 个）-----------------
-- 【为什么这一段一个 id 都不写】
--   · id 交给 AUTO_INCREMENT，幂等只认 `code`（表上有 UNIQUE KEY `uk_tenant_code`）；
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
--      后者在 MySQL 8.0.20+ 的 INSERT ... SELECT 中会抛弃用警告；
--      而它又**不能**引用 JOIN 进来的 p（父节点）的列，所以 parent_id 的
--      校正统一放在下面第 3) 段的自愈 UPDATE 里做。
--
--   菜单位次与图标以现网租户 #1 为准：「创建订单」是**顶级菜单**（不是订单管理
--   的子项），二级菜单不设图标，仅「字典维护」保留了 Collection 图标。
--   2026-10-16：「账单中心」从顶级菜单挪到「系统设置」下（业务面不常用），
--   本文件与 db/migration_20261016_menu_billing_system.sql 必须同步改。

-- 1) 顶级菜单（parent_id 固定 0，不涉及自引用）
INSERT INTO `sys_permission` (`tenant_id`,`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT @tenant_id, 0, s.n, s.c, 1, s.pa, s.i, s.so, 1
  FROM (
              SELECT '首页'     AS n, 'home'         AS c, '/pages/home.html'         AS pa, 'HomeFilled'    AS i, 1 AS so FROM DUAL
    UNION ALL SELECT '创建订单',   'order.create',      '/pages/order-create.html', 'CirclePlus',       2
    UNION ALL SELECT '订单管理',   'order',             NULL,                       'Tickets',          3
    UNION ALL SELECT '抬头管理',   'org',               NULL,                       'OfficeBuilding',   4
    UNION ALL SELECT '财务管理',   'finance',           NULL,                       'Money',            5
    UNION ALL SELECT '系统设置',   'system',            NULL,                       'Setting',          6
  ) AS s
ON DUPLICATE KEY UPDATE
  `name` = s.n, `parent_id` = 0, `path` = s.pa, `icon` = s.i, `sort` = s.so;

-- 2) 二级菜单：parent_id 由父节点 code 现取（JOIN 到的 p 就是父节点那一行）
INSERT INTO `sys_permission` (`tenant_id`,`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT @tenant_id, p.`id`, s.n, s.c, 1, s.pa, s.i, s.so, 1
  FROM (
              SELECT 'ISF订单'    AS n, 'order.isf'     AS c, '/pages/order-isf.html'     AS pa, NULL          AS i, 1 AS so FROM DUAL
    UNION ALL SELECT '清关订单',   'order.customs',  '/pages/order-customs.html',  NULL,            2
    UNION ALL SELECT '提拆派订单', 'order.pickup',   '/pages/order-pickup.html',   NULL,            3
    UNION ALL SELECT '查验订单',   'order.inspect',  '/pages/order-inspect.html',  NULL,            4
  ) AS s
  JOIN `sys_permission` p ON p.`tenant_id` = @tenant_id AND p.`code` = 'order' AND p.`parent_id` = 0
ON DUPLICATE KEY UPDATE
  `name` = s.n, `path` = s.pa, `icon` = s.i, `sort` = s.so;

INSERT INTO `sys_permission` (`tenant_id`,`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT @tenant_id, p.`id`, s.n, s.c, 1, s.pa, s.i, s.so, 1
  FROM (
              SELECT '发货人管理' AS n, 'org.shipper'  AS c, '/pages/org-shipper.html'  AS pa, NULL AS i, 1 AS so FROM DUAL
    UNION ALL SELECT '进口商管理', 'org.importer',  '/pages/org-importer.html', NULL,       2
  ) AS s
  JOIN `sys_permission` p ON p.`tenant_id` = @tenant_id AND p.`code` = 'org' AND p.`parent_id` = 0
ON DUPLICATE KEY UPDATE
  `name` = s.n, `path` = s.pa, `icon` = s.i, `sort` = s.so;

INSERT INTO `sys_permission` (`tenant_id`,`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT @tenant_id, p.`id`, s.n, s.c, 1, s.pa, s.i, s.so, 1
  FROM (
              SELECT '账户余额' AS n, 'finance.account'  AS c, '/pages/fin-account.html'  AS pa, NULL AS i, 1 AS so FROM DUAL
    UNION ALL SELECT '充值记录', 'finance.recharge', '/pages/fin-recharge.html', NULL,        2
    UNION ALL SELECT '应付账单', 'finance.payable',  '/pages/fin-payable.html',  NULL,        3
  ) AS s
  JOIN `sys_permission` p ON p.`tenant_id` = @tenant_id AND p.`code` = 'finance' AND p.`parent_id` = 0
ON DUPLICATE KEY UPDATE
  `name` = s.n, `path` = s.pa, `icon` = s.i, `sort` = s.so;

-- 系统设置下 5 项。「字典维护」的 sort 是 45（不是 5），这是现网租户 #1 的实际值，
-- 历史上一直如此，保持不动免得每次重导都产生一次「位次被改」的假差异。
INSERT INTO `sys_permission` (`tenant_id`,`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT @tenant_id, p.`id`, s.n, s.c, 1, s.pa, s.i, s.so, 1
  FROM (
              SELECT '报价方案' AS n, 'system.plan'   AS c, '/pages/sys-plan.html'       AS pa, NULL         AS i,  1 AS so FROM DUAL
    UNION ALL SELECT '内部员工', 'system.staff',  '/pages/sys-staff.html',      NULL,          2
    UNION ALL SELECT '权限管理', 'system.role',   '/pages/sys-permission.html', NULL,          3
    UNION ALL SELECT '收发信息', 'system.config', '/pages/sys-config.html',     NULL,          4
    UNION ALL SELECT '账单中心', 'billing',       '/pages/billing.html',        NULL,          5
    UNION ALL SELECT '字典维护', 'system.dict',   '/pages/sys-dict.html',       'Collection', 45
  ) AS s
  JOIN `sys_permission` p ON p.`tenant_id` = @tenant_id AND p.`code` = 'system' AND p.`parent_id` = 0
ON DUPLICATE KEY UPDATE
  `name` = s.n, `path` = s.pa, `icon` = s.i, `sort` = s.so;

-- 3) 兜底校正 parent_id（自愈）
--    上面 INSERT 只在「新插一行」时才带上正确的 parent_id；已存在但父节点挂错的
--    节点（= 左侧菜单看不到的主因）靠这几条 UPDATE 修回来，幂等，跑几次都一样。
--    ★ JOIN 里必须带 p.tenant_id = c.tenant_id：共享库里多个租户的菜单 code 相同，
--      漏掉这个条件会让租户 A 的父节点 id 被写到租户 B 的子节点上。
--    刻意不写 status：某个菜单被管理员手工停用了，重导不该把它偷偷打开。
UPDATE `sys_permission` AS c JOIN `sys_permission` AS p
   ON p.`tenant_id` = c.`tenant_id` AND p.`code` = 'order' AND p.`parent_id` = 0
   SET c.`parent_id` = p.`id`
 WHERE c.`tenant_id` = @tenant_id
   AND c.`code` IN ('order.isf', 'order.customs', 'order.pickup', 'order.inspect');

UPDATE `sys_permission` AS c JOIN `sys_permission` AS p
   ON p.`tenant_id` = c.`tenant_id` AND p.`code` = 'org' AND p.`parent_id` = 0
   SET c.`parent_id` = p.`id`
 WHERE c.`tenant_id` = @tenant_id
   AND c.`code` IN ('org.shipper', 'org.importer');

UPDATE `sys_permission` AS c JOIN `sys_permission` AS p
   ON p.`tenant_id` = c.`tenant_id` AND p.`code` = 'finance' AND p.`parent_id` = 0
   SET c.`parent_id` = p.`id`
 WHERE c.`tenant_id` = @tenant_id
   AND c.`code` IN ('finance.account', 'finance.recharge', 'finance.payable');

UPDATE `sys_permission` AS c JOIN `sys_permission` AS p
   ON p.`tenant_id` = c.`tenant_id` AND p.`code` = 'system' AND p.`parent_id` = 0
   SET c.`parent_id` = p.`id`
 WHERE c.`tenant_id` = @tenant_id
   AND c.`code` IN ('system.plan', 'system.staff', 'system.role', 'system.config', 'system.dict', 'billing');

-- 4) 按钮级权限：各订单功能的「创建新订单」（type=2）
--    只决定「能不能点新增按钮 / 能不能调 POST /orders」，**不进左侧菜单**
--    （AuthController::menu() 只查 type=1，所以 type=2 天然不会出现在菜单里）。
--    code 用 `xxx.new` 而不是 `xxx`：菜单的 code 已经占了 order.create / order.isf … ，
--    租户内 code 唯一，重名会直接插失败。
INSERT INTO `sys_permission` (`tenant_id`,`parent_id`,`name`,`code`,`type`,`path`,`icon`,`sort`,`status`)
SELECT @tenant_id, p.`id`, s.n, s.c, 2, '', '', s.so, 1
  FROM (
              SELECT '新增海运提单'   AS n, 'order.create.new'  AS c, 'order.create'  AS pc, 911 AS so FROM DUAL
    UNION ALL SELECT '创建 ISF 订单',    'order.isf.new',      'order.isf',      912
    UNION ALL SELECT '创建清关订单',     'order.customs.new',  'order.customs',  913
    UNION ALL SELECT '创建提拆派订单',   'order.pickup.new',   'order.pickup',   914
    UNION ALL SELECT '创建查验订单',     'order.inspect.new',  'order.inspect',  915
  ) AS s
  JOIN `sys_permission` p ON p.`tenant_id` = @tenant_id AND p.`code` = s.pc
ON DUPLICATE KEY UPDATE
  `name` = s.n, `type` = 2, `path` = '', `icon` = '', `sort` = s.so;

-- 4.1) 按钮权限的 parent_id 自愈：`xxx.new` 的父节点就是去掉 `.new` 的那个菜单
UPDATE `sys_permission` AS c
  JOIN `sys_permission` AS p
    ON p.`tenant_id` = c.`tenant_id`
   AND p.`code` = SUBSTRING_INDEX(c.`code`, '.new', 1) AND p.`type` = 1
   SET c.`parent_id` = p.`id`
 WHERE c.`tenant_id` = @tenant_id
   AND c.`code` IN ('order.create.new', 'order.isf.new', 'order.customs.new',
                    'order.pickup.new', 'order.inspect.new');

-- 角色权限：操作员 / 财务 --------------------------------------
-- 授权集合与现网租户 #1 一致（含「字典维护」——操作员与财务都要能维护字典项）。
-- 「租户系统管理员」不需要授权行：Auth::permissionCodes() 见到 role_code=tenant_admin
-- 直接返回 `*`。新加的角色默认没有任何授权，要到「系统设置 → 权限管理」勾选。
INSERT IGNORE INTO `sys_role_permission` (`tenant_id`,`role_id`,`permission_id`)
SELECT @tenant_id, r.`id`, p.`id`
  FROM `sys_role` r
  JOIN `sys_permission` p ON p.`tenant_id` = @tenant_id AND p.`code` IN (
        'home', 'order', 'order.create', 'order.isf', 'order.customs',
        'order.pickup', 'order.inspect', 'org', 'org.shipper', 'org.importer',
        'system.plan', 'system.dict',
        'order.create.new', 'order.isf.new', 'order.customs.new',
        'order.pickup.new', 'order.inspect.new')
 WHERE r.`tenant_id` = @tenant_id AND r.`code` = 'operator';

INSERT IGNORE INTO `sys_role_permission` (`tenant_id`,`role_id`,`permission_id`)
SELECT @tenant_id, r.`id`, p.`id`
  FROM `sys_role` r
  JOIN `sys_permission` p ON p.`tenant_id` = @tenant_id AND p.`code` IN (
        'home', 'finance', 'finance.account', 'finance.recharge', 'finance.payable',
        'system.dict',
        'order.create.new', 'order.isf.new', 'order.customs.new',
        'order.pickup.new', 'order.inspect.new')
 WHERE r.`tenant_id` = @tenant_id AND r.`code` = 'finance';

-- 系统配置 ---------------------------------------------------
-- 键名以页面为准：pages/sys-config.html 读的是**带点的** company.name / mail.host /
-- system.page_size 这一套，SystemController::configList() 只是把 sys_config 的
-- cfg_key 原样透出、不做映射。所以下面这套键名才是「代码真正会读的」。
--   ⚠ 现网租户 #1 的 sys_config 里躺着的 14 行是另一套旧命名（company_name /
--     company_tel / mail_from_addr / page_size / auto_isf …），全仓库没有任何代码
--     读它们 —— 那是升级过程中的死数据，别照抄（照抄的结果是新租户打开
--     「收发信息」一片空白，而旧库里多出一倍永远不显示的配置行）。
-- 值与租户无关的才给默认值；公司名/电话/邮箱/短信签名这些**留空**，
-- 由租户自己在「收发信息」里填 —— 否则新租户会顶着上一家的公司名上线。
INSERT INTO `sys_config` (`tenant_id`,`group_key`,`cfg_key`,`cfg_value`,`remark`) VALUES
  (@tenant_id,'company','company.name'          ,'' ,'公司名称'),
  (@tenant_id,'company','company.tel'           ,'' ,'联系电话'),
  (@tenant_id,'company','company.address'       ,'' ,'公司地址'),
  (@tenant_id,'mail'   ,'mail.host'             ,'' ,'邮件服务器'),
  (@tenant_id,'mail'   ,'mail.port'             ,'465','邮件端口'),
  (@tenant_id,'mail'   ,'mail.user'             ,'' ,'发信账号'),
  (@tenant_id,'mail'   ,'mail.pass'             ,'' ,'发信密码/授权码'),
  (@tenant_id,'mail'   ,'mail.from_name'        ,'' ,'发件人名称'),
  (@tenant_id,'mail'   ,'mail.to'               ,'' ,'默认收件人（多个用逗号分隔）'),
  (@tenant_id,'sms'    ,'sms.enabled'           ,'0','短信通知开关 1开 0关'),
  (@tenant_id,'sms'    ,'sms.sign'              ,'' ,'短信签名'),
  (@tenant_id,'system' ,'system.page_size'      ,'50','列表默认每页条数'),
  (@tenant_id,'system' ,'system.upload_max_mb'  ,'50','附件大小上限(MB)'),
  (@tenant_id,'system' ,'system.auto_isf'       ,'1','新建订单自动创建ISF记录'),
  (@tenant_id,'system' ,'system.order_no_prefix','QG','订单号前缀'),
  (@tenant_id,'system' ,'notify.on_release'     ,'1','放行时通知客户'),
  (@tenant_id,'system' ,'notify.on_inspect'     ,'1','查验时通知客户')
ON DUPLICATE KEY UPDATE `remark` = VALUES(`remark`);   -- 只补备注，不覆盖租户改过的值

-- 数据字典 ---------------------------------------------------
-- 【只放「有界面在读」的 8 种】——即 pages/sys-dict.html 顶部 DICT_USAGE 列出的：
--   carrier / container_type / origin_port / port / file_type / origin_country /
--   inspect_type / pay_type。
--   那 8 种之外的（customs_status / inspect_abnormal / route_type / biz_type /
--   currency）页面上是写死常量、没有下拉读它们，而且 sys-dict.html 明确禁止新增
--   无用途类型（「显示不了的内容，不能进行添加」），所以新租户不再预置 ——
--   预置了它们只会在「字典维护」里显示成「无下拉」的垃圾项。
--   ⚠ 现网租户 #1 的字典只剩 6 种、且 `port` 只有一条无意义记录
--     （key='2' value='2'）、carrier 少了前 4 家 —— 那是客户改过的样子，
--     照抄会让新租户的「收款方式 / 查验类型 / 目的港」下拉直接是空的。
--     所以这里给的是**功能完整的产品默认值**。
INSERT INTO `sys_dict` (`tenant_id`,`dict_type`,`dict_key`,`dict_value`,`sort`,`status`) VALUES
  (@tenant_id,'container_type','20GP','20GP',1,1),
  (@tenant_id,'container_type','40GP','40GP',2,1),
  (@tenant_id,'container_type','40HQ','40HQ',3,1),
  (@tenant_id,'container_type','45HQ','45HQ',4,1),
  (@tenant_id,'container_type','40HC','40HC',5,1),
  (@tenant_id,'container_type','53HQ','53HQ',6,1),
  (@tenant_id,'container_type','40NOR','40NOR',7,1),
  (@tenant_id,'container_type','40RH','40RH',8,1),
  -- 始发港（origin_port）：**只给建单页的「始发港 / Port of Loading」用**，
  -- 内容是「中国出运港」；与下面 port（目的国港口）是两套完全独立的数据，别混。
  (@tenant_id,'origin_port','宁波港','宁波港',1,1),
  (@tenant_id,'origin_port','上海港','上海港',2,1),
  (@tenant_id,'origin_port','深圳盐田港','深圳盐田港',3,1),
  (@tenant_id,'origin_port','深圳蛇口港','深圳蛇口港',4,1),
  (@tenant_id,'origin_port','广州南沙港','广州南沙港',5,1),
  (@tenant_id,'origin_port','青岛港','青岛港',6,1),
  (@tenant_id,'origin_port','天津港','天津港',7,1),
  (@tenant_id,'origin_port','厦门港','厦门港',8,1),
  -- 港口（port）：**目的港 / 卸货港 / 清关港 三个下拉共用这一套**，
  -- 内容是「目的国港口」（中美线主要填美国港口）。别往里加中国起运港。
  (@tenant_id,'port','洛杉矶港','洛杉矶港',1,1),
  (@tenant_id,'port','长滩港','长滩港',2,1),
  (@tenant_id,'port','纽约港','纽约港',3,1),
  (@tenant_id,'port','萨凡纳港','萨凡纳港',4,1),
  (@tenant_id,'port','休斯顿港','休斯顿港',5,1),
  (@tenant_id,'port','芝加哥港','芝加哥港',6,1),
  (@tenant_id,'port','西雅图港','西雅图港',7,1),
  (@tenant_id,'port','奥克兰港','奥克兰港',8,1),
  (@tenant_id,'carrier','美森轮船 MATSON','美森轮船 MATSON',1,1),
  (@tenant_id,'carrier','以星航运 ZIM','以星航运 ZIM',2,1),
  (@tenant_id,'carrier','中远海运 COSCO','中远海运 COSCO',3,1),
  (@tenant_id,'carrier','长荣海运 EVERGREEN','长荣海运 EVERGREEN',4,1),
  (@tenant_id,'carrier','达飞 CMA','达飞 CMA',5,1),
  (@tenant_id,'carrier','马士基 MSK','马士基 MSK',6,1),
  (@tenant_id,'carrier','阳明 YML','阳明 YML',7,1),
  (@tenant_id,'carrier','东方海外 OOCL','东方海外 OOCL',8,1),
  (@tenant_id,'carrier','海洋网联 ONE','海洋网联 ONE',9,1),
  (@tenant_id,'file_type','提单 BL','提单 BL',1,1),
  (@tenant_id,'file_type','报关单','报关单',2,1),
  (@tenant_id,'file_type','商业发票','商业发票',3,1),
  (@tenant_id,'file_type','装箱单','装箱单',4,1),
  (@tenant_id,'file_type','ISF 申报资料','ISF 申报资料',5,1),
  (@tenant_id,'file_type','其他','其他',6,1),
  (@tenant_id,'inspect_type','人工查验','人工查验',1,1),
  (@tenant_id,'inspect_type','机检查验','机检查验',2,1),
  (@tenant_id,'inspect_type','X光查验','X光查验',3,1),
  (@tenant_id,'inspect_type','CET查验','CET查验',4,1),
  (@tenant_id,'pay_type','银行转账','银行转账',1,1),
  (@tenant_id,'pay_type','支付宝','支付宝',2,1),
  (@tenant_id,'pay_type','微信','微信',3,1),
  (@tenant_id,'pay_type','现金','现金',4,1),
  (@tenant_id,'origin_country','CN','中国',1,1),
  (@tenant_id,'origin_country','US','美国',2,1),
  (@tenant_id,'origin_country','VN','越南',3,1),
  (@tenant_id,'origin_country','IN','印度',4,1),
  (@tenant_id,'origin_country','ID','印度尼西亚',5,1),
  (@tenant_id,'origin_country','TH','泰国',6,1),
  (@tenant_id,'origin_country','MY','马来西亚',7,1),
  (@tenant_id,'origin_country','KR','韩国',8,1),
  (@tenant_id,'origin_country','JP','日本',9,1),
  (@tenant_id,'origin_country','DE','德国',10,1),
  (@tenant_id,'origin_country','IT','意大利',11,1),
  (@tenant_id,'origin_country','TR','土耳其',12,1)
ON DUPLICATE KEY UPDATE `id` = `id`;   -- 已存在则原样保留，不覆盖租户手工改过的显示名

-- =============================================================
--  本文件到此为止，不预置：
--    · sys_user —— 租户管理员由开通流程创建：读控制面 saas_tenant 的
--      admin_username / admin_password_hash（bcrypt）写进 sys_user，
--      角色挂到 code='tenant_admin'，tenant_id = 本文件的 @tenant_id。
--      历史上这里让人手工访问 api/install.php 建账号，那条路已经废弃：
--      install.php 无鉴权、且写 sys_user 时根本不带 tenant_id。
--    · biz_plan —— 报价方案是租户的业务数据（含各自的金额与条款），
--      不预置示例，由租户在「系统设置 → 报价方案」里自己录。
--    · 其余业务表（抬头/订单/附件/日志）同理。
-- =============================================================
