<?php declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * 外部数据源连接工厂（SQL Server 工单库 / MES 生产库）
 *
 * ★ 安全约定：本文件不包含任何硬编码主机名、账号或口令。
 *   全部取自 .env（见 .env.example 的 SQLSRV_* / MES_DB_*）。
 *   配置不完整时不尝试用「猜测的默认凭据」连接，直接拒绝并写错误日志，
 *   使配置缺失能被快速发现，而不是静默连到错误的数据源。
 *
 * 用法（控制器内）：
 *   $cfg = ExternalDb::sqlsrvConfig();      // 校验配置，缺失返回 null
 *   if ($cfg === null) return null;
 *   [$host, $port, $db, $user, $pass] = $cfg;
 *
 * 或直接取连接：
 *   $pdo = ExternalDb::sqlsrv(8);           // ?PDO
 *   $pdo = ExternalDb::mes(5);              // ?PDO
 */
class ExternalDb
{
    /**
     * 读取并校验 SQL Server 工单库配置
     *
     * @return array{0:string,1:string,2:string,3:string,4:string}|null [host, port, db, user, pass]
     */
    public static function sqlsrvConfig(): ?array
    {
        [$v, $missing] = Env::required(['SQLSRV_HOST', 'SQLSRV_DB', 'SQLSRV_USER', 'SQLSRV_PASS']);
        if (!empty($missing)) {
            error_log('[ExternalDb] SQL Server 配置缺失（' . implode(', ', $missing) . '），已跳过连接。请在 .env 中补齐 SQLSRV_* 配置。');
            return null;
        }
        return [
            $v['SQLSRV_HOST'],
            Env::get('SQLSRV_PORT', '1433'),
            $v['SQLSRV_DB'],
            $v['SQLSRV_USER'],
            $v['SQLSRV_PASS'],
        ];
    }

    /**
     * 读取并校验 MES 生产库配置
     *
     * @return array{0:string,1:string,2:string,3:string,4:string}|null [host, port, db, user, pass]
     */
    public static function mesConfig(): ?array
    {
        [$v, $missing] = Env::required(['MES_DB_HOST', 'MES_DB_NAME', 'MES_DB_USER', 'MES_DB_PASS']);
        if (!empty($missing)) {
            error_log('[ExternalDb] MES 配置缺失（' . implode(', ', $missing) . '），已跳过连接。请在 .env 中补齐 MES_DB_* 配置。');
            return null;
        }
        return [
            $v['MES_DB_HOST'],
            Env::get('MES_DB_PORT', '3306'),
            $v['MES_DB_NAME'],
            $v['MES_DB_USER'],
            $v['MES_DB_PASS'],
        ];
    }

    /**
     * 建立 SQL Server（ERP 工单库）连接，失败返回 null
     */
    public static function sqlsrv(int $timeout = 5): ?PDO
    {
        $cfg = self::sqlsrvConfig();
        if ($cfg === null) {
            return null;
        }
        [$host, $port, $db, $user, $pass] = $cfg;

        try {
            $dsn = "dblib:host={$host}:{$port};dbname={$db};charset=UTF-8";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => $timeout,
            ]);
            $pdo->exec("SET ANSI_NULLS ON; SET ANSI_WARNINGS ON; SET ANSI_PADDING ON; SET CONCAT_NULL_YIELDS_NULL ON; SET QUOTED_IDENTIFIER ON;");
            return $pdo;
        } catch (Throwable $e) {
            error_log('[ExternalDb] SQL Server 连接失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 建立 MES（生产库）连接，失败返回 null
     */
    public static function mes(int $timeout = 5): ?PDO
    {
        $cfg = self::mesConfig();
        if ($cfg === null) {
            return null;
        }
        [$host, $port, $db, $user, $pass] = $cfg;

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => true,
                PDO::ATTR_TIMEOUT            => $timeout,
            ]);
        } catch (Throwable $e) {
            error_log('[ExternalDb] MES 连接失败: ' . $e->getMessage());
            return null;
        }
    }
}
