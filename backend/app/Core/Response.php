<?php
namespace App\Core;

class Response
{
    public static function json(bool $success, string $message = '', array $data = [], int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'   => $success,
            'message'   => $message,
            'data'      => $data,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(string $message = '操作成功', array $data = []): void
    {
        self::json(true, $message, $data, 200);
    }

    public static function error(string $message = '操作失败', array $data = [], int $httpCode = 400): void
    {
        self::json(false, $message, $data, $httpCode);
    }

    /**
     * ★ 安全加固（M5）：捕获异常后的统一脱敏出口。
     *
     * 此前大量控制器直接 Response::error('XX失败: ' . $e->getMessage())，
     * 会把原始异常信息（SQL 片段、表名、文件路径、外部服务地址、调用栈线索等）
     * 回显给客户端，形成信息泄露面。
     *
     * 本方法统一行为：
     *   - 详细原因（异常类 / message / 文件 / 行号）只写入 error_log，并带上随机错误码；
     *   - 返回给客户端的只有调用方给出的友好文案（附带错误码，便于用户报错时定位）；
     *   - 保持默认 HTTP 400，与改造前 Response::error 的响应码一致，不改变前端行为。
     *
     * @param \Throwable $e           捕获到的异常
     * @param string     $userMessage 面向用户的友好文案，如「导出失败」
     * @param array      $data        附加返回数据（可选）
     * @param int        $httpCode    默认 400（与历史行为一致）
     */
    public static function exception(\Throwable $e, string $userMessage = '操作失败', array $data = [], int $httpCode = 400): void
    {
        $rid = bin2hex(random_bytes(6));
        error_log("[{$rid}] " . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());

        self::json(false, $userMessage . '（错误码：' . $rid . '）', $data, $httpCode);
    }
}