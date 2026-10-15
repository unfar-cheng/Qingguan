-- 清理 sys_config 租户 1 的旧命名（flat 键）死数据。
-- 背景：早期平铺命名（company_name/page_size 等）已废弃，代码全部改用带点键
-- （company.name、ui.page_size 等，见 ConfigRepo / 系统设置页）。下列 14 个键
-- 没有任何代码读取，值全为空或仅是默认值（50/50/0/QG），直接删除。
-- 幂等：DELETE 天生幂等，重复执行无副作用。
-- 适用库：数据面业务库（dev: qingguan_dev；prod: qingguan，随下次部署执行）。

DELETE FROM sys_config
WHERE tenant_id = 1
  AND cfg_key IN (
    'company_name',
    'company_en',
    'company_tel',
    'company_addr',
    'company_email',
    'company_web',
    'mail_from_addr',
    'mail_from_name',
    'mail_reply_to',
    'mail_signature',
    'page_size',
    'upload_max_mb',
    'auto_isf',
    'order_no_prefix'
  );
