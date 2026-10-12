<?php declare(strict_types=1);

/**
 * cron/bootstrap.php
 * CLI / Cron 专用引导文件
 * 只加载 autoload、.env 和数据库连接函数，不走 index.php 的 HTTP 逻辑
 */

// 只在 CLI 下启用（防止被 web 直接访问）
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// ---------- 1. PSR-4 自动加载 ----------
spl_autoload_register(function ($class) {
    $prefix  = "App\\";
    $baseDir = __DIR__ . "/../app/";
    if (strncmp($prefix, $class, strlen($prefix)) === 0) {
        $file = $baseDir . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// ---------- 2. 加载 .env ----------
$envFile = __DIR__ . "/../.env";
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === "" || str_starts_with($line, "#")) continue;
        if (strpos($line, "=") !== false) {
            [$name, $value] = explode("=", $line, 2);
            $name  = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }
    }
}

// ---------- 3. 业务主库 (vite) ----------
if (!function_exists("getDB")) {
    function getDB() {
        static $pdo = null;
        if ($pdo instanceof \PDO) return $pdo;

        try {
            $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $name = $_ENV['DB_NAME'] ?? '';
            $user = $_ENV['DB_USER'] ?? '';
            $pass = $_ENV['DB_PASS'] ?? '';

            if ($name === '') return null;

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_TIMEOUT            => 5,
            ]);
            return $pdo;
        } catch (\Throwable $e) {
            fwrite(STDERR, "[getDB] " . $e->getMessage() . PHP_EOL);
            return null;
        }
    }
}

// ---------- 4. MES 生产库 ----------
if (!function_exists("getMesDB")) {
    function getMesDB() {
        static $pdo = null;
        if ($pdo instanceof \PDO) return $pdo;

        try {
            $host = $_ENV['MES_DB_HOST'] ?? '';
            $port = $_ENV['MES_DB_PORT'] ?? '3306';
            $name = $_ENV['MES_DB_NAME'] ?? '';
            $user = $_ENV['MES_DB_USER'] ?? '';
            $pass = $_ENV['MES_DB_PASS'] ?? '';

            if ($host === '' || $name === '') return null;

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_TIMEOUT            => 5,
            ]);
            return $pdo;
        } catch (\Throwable $e) {
            fwrite(STDERR, "[getMesDB] " . $e->getMessage() . PHP_EOL);
            return null;
        }
    }
}

// ---------- 5. SQL Server ERP 库（cron 暂时用不到，保留兼容） ----------
if (!function_exists("getSqlSrvDB")) {
    function getSqlSrvDB() {
        static $pdo = null;
        if ($pdo instanceof \PDO) return $pdo;

        try {
            $host = $_ENV['SQLSRV_HOST'] ?? '';
            $port = $_ENV['SQLSRV_PORT'] ?? '1433';
            $name = $_ENV['SQLSRV_DB']   ?? '';
            $user = $_ENV['SQLSRV_USER'] ?? '';
            $pass = $_ENV['SQLSRV_PASS'] ?? '';

            if ($host === '') return null;

            $dsn = "sqlsrv:Server={$host},{$port};Database={$name};TrustServerCertificate=1";
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            return $pdo;
        } catch (\Throwable $e) {
            fwrite(STDERR, "[getSqlSrvDB] " . $e->getMessage() . PHP_EOL);
            return null;
        }
    }
}