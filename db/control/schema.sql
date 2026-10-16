-- 控制面库：qingguan_control（跨租户对象）。MySQL 8.0 / utf8mb4。
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `saas_plan` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(50)  NOT NULL,
  `name`        VARCHAR(100) NOT NULL,
  `mode`        ENUM('shared','dedicated') NOT NULL DEFAULT 'shared',
  `price_month` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `price_year`  DECIMAL(10,2) NOT NULL DEFAULT 0,
  `max_users`   INT NOT NULL DEFAULT 0,
  `max_orders`  INT NOT NULL DEFAULT 0,
  `remark`      VARCHAR(255) NOT NULL DEFAULT '',
  `status`      TINYINT NOT NULL DEFAULT 1,
  `sort`        INT NOT NULL DEFAULT 0,
  `created_at`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SaaS 套餐';

CREATE TABLE IF NOT EXISTS `saas_tenant` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                VARCHAR(50)  NOT NULL COMMENT '租户代码，登录用',
  `name`                VARCHAR(100) NOT NULL,
  `logo_url`            VARCHAR(255) NOT NULL DEFAULT '',
  `mode`                ENUM('shared','dedicated') NOT NULL DEFAULT 'shared',
  `plan_id`             INT UNSIGNED NULL,
  `db_name`             VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '专用档库名',
  `status`              ENUM('pending','active','rejected','suspended') NOT NULL DEFAULT 'pending',
  `contact_name`        VARCHAR(50)  NOT NULL DEFAULT '',
  `contact_phone`       VARCHAR(30)  NOT NULL DEFAULT '',
  `contact_email`       VARCHAR(100) NOT NULL DEFAULT '',
  `admin_username`      VARCHAR(50)  NOT NULL DEFAULT '',
  `admin_password_hash` VARCHAR(255) NOT NULL DEFAULT '',
  `reject_reason`       VARCHAR(255) NOT NULL DEFAULT '',
  `bill_cycle`          ENUM('month','year') NOT NULL DEFAULT 'year' COMMENT '注册申请的付费周期意愿',
  `expires_at`          DATETIME NULL COMMENT '服务期到期日；NULL=不参与到期逻辑',
  `applied_at`          DATETIME NULL,
  `reviewed_at`         DATETIME NULL,
  `reviewed_by`         INT UNSIGNED NULL,
  `created_at`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户';

CREATE TABLE IF NOT EXISTS `saas_platform_user` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `real_name`     VARCHAR(50)  NOT NULL DEFAULT '',
  `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
  `email`         VARCHAR(100) NOT NULL DEFAULT '',
  `status`        TINYINT NOT NULL DEFAULT 1,
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(50) NOT NULL DEFAULT '',
  `created_at`    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台管理员';

CREATE TABLE IF NOT EXISTS `saas_audit` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_user_id` INT UNSIGNED NULL,
  `action`           VARCHAR(50) NOT NULL,
  `target_type`      VARCHAR(50) NOT NULL DEFAULT '',
  `target_id`        INT UNSIGNED NULL,
  `detail`           VARCHAR(1000) NOT NULL DEFAULT '',
  `ip`               VARCHAR(50) NOT NULL DEFAULT '',
  `created_at`       DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target_type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台侧操作审计';

-- 两个套餐（示例价格，上线前按实际改）
INSERT INTO `saas_plan` (`code`,`name`,`mode`,`price_month`,`price_year`,`max_users`,`max_orders`,`remark`,`status`,`sort`) VALUES
  ('basic',    '标准版（共享）', 'shared',    600.00,  6000.00,  20,  5000, '数据与其他租户共享一套库，逻辑隔离', 1, 1),
  ('isolated', '隔离版（独享）', 'dedicated', 1500.00, 15000.00, 50, 50000, '独立数据库，物理隔离（二期开通）', 1, 2);

CREATE TABLE IF NOT EXISTS `saas_bill` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL,
  `cycle`        ENUM('month','year') NOT NULL COMMENT '服务周期',
  `amount`       DECIMAL(10,2) NOT NULL COMMENT '生成时快照套餐价',
  `status`       ENUM('unpaid','paid','void') NOT NULL DEFAULT 'unpaid',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at`      DATETIME NULL,
  `paid_channel` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '二期在线支付前恒 offline',
  `paid_ref`     VARCHAR(100) NOT NULL DEFAULT '' COMMENT '支付流水号/转账备注',
  `confirmed_by` INT UNSIGNED NULL COMMENT '确认收款的平台账号',
  `void_reason`  VARCHAR(200) NOT NULL DEFAULT '',
  `remark`       VARCHAR(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='账单';
