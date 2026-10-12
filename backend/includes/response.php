<?php
// includes/response.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!function_exists("sendJson")) {
    function sendJson($success, $message = '', $data = array()) {
    echo json_encode(array(
        'success'   => (bool)$success,
        'message'   => $message,
        'data'      => $data,
        'timestamp' => time()
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

function checkUser($requireAdmin = false) {
    if (!isset($_SESSION['user_id'])) {
        sendJson(false, '未登录或登录已超时', array('need_login' => true));
    }
    if ($requireAdmin && empty($_SESSION['is_admin'])) {
        sendJson(false, '权限不足，仅管理员可访问');
    }
    return array(
        'id'        => (int)$_SESSION['user_id'],
        'username'  => isset($_SESSION['username']) ? $_SESSION['username'] : '',
        'full_name' => isset($_SESSION['full_name']) ? $_SESSION['full_name'] : '',
        'is_admin'  => isset($_SESSION['is_admin']) ? (int)$_SESSION['is_admin'] : 0
    );
}

function writeLog($userId, $action, $description, $moduleCode = 'SYSTEM') {
    try {
        $db = getDB();
        if ($db) {
            $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, user_agent, module_code, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
            $stmt->execute(array($userId, $action, $description, $ip, $ua, $moduleCode));
        }
    } catch (Exception $e) {}
}
}
