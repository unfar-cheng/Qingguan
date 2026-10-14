<?php
/**
 * 初始化脚本：创建初始管理员并自检环境。
 * 部署后访问一次 http://你的域名/api/install.php ，完成后请删除本文件。
 */
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

$root = dirname(__DIR__);
$cfgFile = __DIR__ . '/config/config.php';

function line(string $s, string $color = '#333'): void
{
    echo '<div style="color:' . $color . '">' . $s . '</div>';
}

echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<div style="font:14px/1.9 -apple-system,"Microsoft YaHei",sans-serif;max-width:760px;margin:40px auto;padding:24px;border:1px solid #e3e6ea;border-radius:10px">';
echo '<h2 style="margin-top:0">清关系统 · 环境自检</h2>';

if (!is_file($cfgFile)) {
    line('✗ 未找到 api/config/config.php，请先复制 config.sample.php 并填写数据库信息。', '#b23b3b');
    echo '</div>';
    exit;
}
$cfg = require $cfgFile;

echo '<h3>1. PHP 环境</h3>';
line('PHP 版本：' . PHP_VERSION . (version_compare(PHP_VERSION, '8.0.0', '>=') ? ' ✓' : ' ✗ 需要 8.0+'), version_compare(PHP_VERSION, '8.0.0', '>=') ? '#2f7d4f' : '#b23b3b');
foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'json', 'openssl'] as $ext) {
    $ok = extension_loaded($ext);
    line(($ok ? '✓ ' : '✗ ') . '扩展 ' . $ext, $ok ? '#2f7d4f' : '#b23b3b');
}
$upDir = $cfg['app']['upload_dir'];
line((is_dir($upDir) && is_writable($upDir) ? '✓ ' : '✗ ') . '上传目录可写：' . $upDir, (is_dir($upDir) && is_writable($upDir)) ? '#2f7d4f' : '#b23b3b');
$rt = __DIR__ . '/runtime';
if (!is_dir($rt)) {
    @mkdir($rt, 0755, true);
}
line((is_dir($rt) && is_writable($rt) ? '✓ ' : '✗ ') . 'runtime 目录可写：' . $rt, (is_dir($rt) && is_writable($rt)) ? '#2f7d4f' : '#b23b3b');

echo '<h3>2. 数据库连接</h3>';
$db = $cfg['db'];
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']),
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    line('✓ 连接成功：' . $db['name'] . ' @ ' . $db['host'], '#2f7d4f');
} catch (Throwable $e) {
    line('✗ 连接失败：' . $e->getMessage(), '#b23b3b');
    line('请检查 api/config/config.php 中的数据库账号密码，以及宝塔数据库是否放行本机访问。', '#8b949e');
    echo '</div>';
    exit;
}

echo '<h3>3. 数据表</h3>';
$need = ['sys_user', 'sys_role', 'sys_permission', 'sys_role_permission', 'sys_config', 'sys_dict', 'sys_log',
    'org_company', 'biz_plan', 'biz_order', 'biz_order_container', 'biz_order_hscode', 'biz_order_address',
    'biz_isf', 'biz_inspect', 'biz_pickup', 'biz_order_file', 'biz_order_log',
    'fin_account', 'fin_recharge', 'fin_payable'];
$missing = [];
foreach ($need as $t) {
    try {
        $pdo->query("SELECT 1 FROM `{$t}` LIMIT 1");
    } catch (Throwable $e) {
        $missing[] = $t;
    }
}
if ($missing) {
    line('✗ 缺少 ' . count($missing) . ' 张表：' . implode(', ', $missing), '#b23b3b');
    line('请先到宝塔「数据库 → 导入」上传 db/schema.sql。', '#8b949e');
    echo '</div>';
    exit;
}
line('✓ 21 张表齐全', '#2f7d4f');

echo '<h3>4. 初始管理员</h3>';
$cnt = (int) $pdo->query('SELECT COUNT(*) FROM sys_user')->fetchColumn();
if ($cnt === 0) {
    $username = $_POST['username'] ?? 'admin';
    $password = $_POST['password'] ?? '';
    if ($password === '') {
        ?>
        <p>库中还没有任何账号，请设置初始管理员：</p>
        <form method="post" style="margin:12px 0">
          <label>账号 <input name="username" value="admin" style="padding:6px 10px;border:1px solid #d0d5dd;border-radius:6px"></label>
          <label style="margin-left:12px">密码 <input name="password" type="text" value="<?php echo htmlspecialchars('qg' . random_int(100000, 999999), ENT_QUOTES); ?>" style="padding:6px 10px;border:1px solid #d0d5dd;border-radius:6px"></label>
          <button style="margin-left:12px;padding:7px 18px;border:0;border-radius:6px;background:#2f6fb3;color:#fff;cursor:pointer">创建管理员</button>
        </form>
        <?php
    } else {
        if (strlen($password) < 6) {
            line('✗ 密码至少 6 位，请返回重试', '#b23b3b');
        } else {
            $st = $pdo->prepare('INSERT INTO sys_user (username, real_name, password_hash, role_id, status, created_at) VALUES (?,?,?,1,1,NOW())');
            $st->execute([$username, '超级管理员', password_hash($password, PASSWORD_DEFAULT)]);
            line('✓ 管理员已创建 —— 账号：<b>' . htmlspecialchars($username) . '</b>　密码：<b>' . htmlspecialchars($password) . '</b>', '#2f7d4f');
            line('请立即登录并到「系统设置 → 内部员工」修改密码。', '#8b949e');
        }
    }
} else {
    line('✓ 已存在 ' . $cnt . ' 个账号，跳过创建', '#2f7d4f');
}

echo '<h3>5. 完成</h3>';
line('自检结束。<b>请立刻删除 api/install.php</b>，避免暴露环境信息。', '#b23b3b');
line('<a href="/login.html">→ 前往登录</a>', '#2f6fb3');
echo '</div>';
