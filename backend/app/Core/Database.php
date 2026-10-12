<?php
namespace App\Core;

use PDO;
use Exception;

class Database
{
    private static array $connections = [];

    public static function getConnection(string $name = 'default'): PDO
    {
        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        switch ($name) {
            case 'default':
                $host = Env::get('DB_HOST', '127.0.0.1');
                $port = Env::get('DB_PORT', '3306');
                $dbname = Env::get('DB_NAME', 'vite');
                $user = Env::get('DB_USER', 'vite');

                // ★ 安全加固：口令只从 .env 读取，源码内不再保留任何兜底口令。
                //   缺失时直接抛错（而不是用「猜测的口令」连库），避免源码泄露即凭据泄露。
                $pass = Env::get('DB_PASS');
                if ($pass === '') {
                    throw new Exception('数据库口令未配置：请在 backend/.env 中设置 DB_PASS（出于安全考虑，源码不再提供兜底凭据）');
                }

                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                break;

            default:
                throw new Exception("未定义的数据源: {$name}");
        }

        self::$connections[$name] = $pdo;
        return $pdo;
    }
}