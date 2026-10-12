<?php
declare(strict_types=1);

namespace App\Middlewares;

use App\Core\Response;

class AuthMiddleware
{
    public function handle(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['user_id'])) {
            Response::error('未登录或会话已过期', ['need_login' => true], 401);
        }
    }
}