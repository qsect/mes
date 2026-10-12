<?php
namespace App\Services;

use App\Core\Database;
use PDO;

class AuditService
{
    public static function log(string $module, string $action, bool $status = true, string $errorMsg = '', int $executionTime = 0): void
    {
        try {
            $pdo = Database::getConnection();

            $user = $_SESSION['user'] ?? null;
            $userId   = $user['id'] ?? 0;
            $username = $user['username'] ?? 'GUEST';
            $fullName = $user['full_name'] ?? '访客';

            $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
            $url    = $_SERVER['REQUEST_URI'] ?? '';
            $ip     = self::getClientIp();
            $ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 250);

            // 获取请求载荷并清洗敏感密码字段
            $params = $_REQUEST;
            if (empty($params)) {
                $rawInput = file_get_contents('php://input');
                $params = json_decode($rawInput, true) ?? [];
            }
            if (isset($params['password'])) $params['password'] = '******';
            if (isset($params['old_password'])) $params['old_password'] = '******';
            $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE);

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs 
                (user_id, username, full_name, module, action, method, url, ip, user_agent, params, status, error_msg, execution_time, created_at)
                VALUES 
                (:user_id, :username, :full_name, :module, :action, :method, :url, :ip, :ua, :params, :status, :error_msg, :exec_time, NOW())
            ");

            $stmt->execute([
                ':user_id'   => $userId,
                ':username'  => $username,
                ':full_name' => $fullName,
                ':module'    => $module,
                ':action'    => $action,
                ':method'    => $method,
                ':url'       => $url,
                ':ip'        => $ip,
                ':ua'        => $ua,
                ':params'    => $paramsJson,
                ':status'    => $status ? 1 : 0,
                ':error_msg' => $errorMsg,
                ':exec_time' => $executionTime
            ]);
        } catch (\Throwable $e) {
            // 审计日志绝不能阻断业务流程，异常写入错误日志即可
            error_log("AuditLog Error: " . $e->getMessage());
        }
    }

    private static function getClientIp(): string
    {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '127.0.0.1';
    }
}