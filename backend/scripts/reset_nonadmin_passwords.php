<?php declare(strict_types=1);

/**
 * 一次性运维脚本：把所有「非管理员」账号的密码统一重置为初始密码，
 * 并标记为「首次登录必须修改密码」。管理员账号不做任何调整。
 *
 * 用法（在项目根目录或 backend 目录下执行均可）：
 *   /www/server/php/82/bin/php /www/wwwroot/vite/backend/scripts/reset_nonadmin_passwords.php --dry-run
 *   /www/server/php/82/bin/php /www/wwwroot/vite/backend/scripts/reset_nonadmin_passwords.php
 *
 * 参数：
 *   --dry-run  只列出将被影响的账号，不写入数据库
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

$backendDir = dirname(__DIR__);

// ---------- 1. 读取 .env ----------
$envFile = $backendDir . '/.env';
$env = [];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$name = $env['DB_DATABASE'] ?? ($env['DB_NAME'] ?? '');
$user = $env['DB_USERNAME'] ?? ($env['DB_USER'] ?? '');
$pass = $env['DB_PASSWORD'] ?? ($env['DB_PASS'] ?? '');

if ($name === '') {
    fwrite(STDERR, "[错误] 未能在 {$envFile} 中读取到数据库名（DB_DATABASE）\n");
    exit(1);
}

// ---------- 2. 连接数据库 ----------
try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "[错误] 数据库连接失败: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------- 3. 确保必须的列存在 ----------
function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $column]);
    if ((int)$st->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        echo "  · 已为 {$table} 新增列 {$column}\n";
    }
}

if (!$dryRun) {
    ensureColumn($pdo, 'users', 'must_change_password', "`must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '首次登录或被重置后需强制修改密码'");
    ensureColumn($pdo, 'users', 'password_updated_at', "`password_updated_at` DATETIME NULL COMMENT '密码最后修改时间'");
}

// ---------- 4. 统计受影响的账号 ----------
$where = "(u.is_admin = 0 OR u.is_admin IS NULL) AND (r.code IS NULL OR r.code <> 'admin')";

$listStmt = $pdo->query("SELECT u.id, u.username, u.full_name, u.team, u.is_active, u.is_admin, r.name AS role_name
                         FROM users u
                         LEFT JOIN roles r ON u.role_id = r.id
                         WHERE {$where}
                         ORDER BY u.id ASC");
$targets = $listStmt->fetchAll();

echo "=== 非管理员账号（共 " . count($targets) . " 个）===\n";
foreach ($targets as $t) {
    printf("  #%-4d %-16s %-10s %-12s %s\n",
        $t['id'],
        (string)$t['username'],
        (string)($t['full_name'] ?? ''),
        (string)($t['role_name'] ?? ''),
        ((int)$t['is_active'] === 1 ? '启用' : '禁用')
    );
}

if (empty($targets)) {
    echo "没有需要处理的账号。\n";
    exit(0);
}

if ($dryRun) {
    echo "\n[演练模式] 未修改任何数据。去掉 --dry-run 即真正执行。\n";
    exit(0);
}

// ---------- 5. 执行重置 ----------
$initialPassword = '123456';
$hash = password_hash($initialPassword, PASSWORD_DEFAULT);

$sql = "UPDATE users u
        LEFT JOIN roles r ON u.role_id = r.id
        SET u.password = :p,
            u.must_change_password = 1,
            u.password_updated_at = NOW()
        WHERE {$where}";

$stmt = $pdo->prepare($sql);
$stmt->execute([':p' => $hash]);
$count = $stmt->rowCount();

echo "\n=== 执行完成 ===\n";
echo "已将 {$count} 个非管理员账号的密码重置为 {$initialPassword}\n";
echo "这些账号下次登录时会强制要求修改为「8 位以上 + 大小写字母 + 数字 + 特殊字符」的合规密码。\n";
echo "管理员账号保持不变。\n";
