<?php
namespace App\Core;

class Router
{
    private array $routes = [];

    /**
     * 注册路由
     *
     * @param array $middlewares 中间件列表，支持两种写法：
     *   - '完整类名'                   例如 \App\Middlewares\AuthMiddleware::class
     *   - ['类名', 构造参数, ...]      例如 ['\App\Middlewares\ModuleViewMiddleware', 'equipment']
     */
    public function add(string $method, string $path, $handler, array $middlewares = []): void
    {
        $this->routes[] = [
            'method'      => strtoupper($method),
            'path'        => '/' . trim($path, '/'),
            'handler'     => $handler,
            'middlewares' => $middlewares
        ];
    }

    public function dispatch(): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        
        // 直接从 REQUEST_URI 提取真实请求路径并去除参数
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
        $requestUri = parse_url($rawUri, PHP_URL_PATH);
        $requestUri = '/' . trim($requestUri, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] === $requestMethod && $route['path'] === $requestUri) {
                // 执行绑定的中间件
                foreach ($route['middlewares'] as $middleware) {
                    // 支持两种写法：
                    //   1) '完整类名'                    例如 \App\Middlewares\AuthMiddleware::class
                    //   2) ['类名', $arg1, $arg2, ...]   例如 ['\App\Middlewares\ModuleViewMiddleware', 'equipment']
                    if (is_array($middleware)) {
                        $class = array_shift($middleware);
                        if (!is_string($class) || $class === '') {
                            throw new \RuntimeException(
                                "路由配置错误: [{$route['method']} {$route['path']}] 的中间件数组首项必须是类名字符串"
                            );
                        }
                        $mw = new $class(...$middleware);
                    } elseif (is_string($middleware)) {
                        // ★ 显式校验：类名必须包含命名空间分隔符。
                        //   否则形如 ['mac'] / 'mac' 的裸模块名会变成 new mac()，
                        //   PHP 抛出 "Class \"mac\" not found"，该提示极易被误读为
                        //   "控制器缺失"，实际是路由表配错了（2026-10-11 踩坑）。
                        if (strpos($middleware, '\\') === false) {
                            throw new \RuntimeException(
                                "路由配置错误: [{$route['method']} {$route['path']}] 的中间件 '{$middleware}' "
                                . "不是合法的类名（缺少命名空间）。正确写法示例："
                                . "['App\\\\Middlewares\\\\ModuleViewMiddleware', '{$middleware}']"
                            );
                        }
                        $mw = new $middleware();
                    } else {
                        throw new \RuntimeException(
                            "路由配置错误: [{$route['method']} {$route['path']}] 的中间件类型非法（"
                            . get_debug_type($middleware) . "）"
                        );
                    }
                    $mw->handle();
                }

                // 执行控制器
                [$controllerClass, $action] = $route['handler'];
                $controller = new $controllerClass();
                $controller->$action();
                return;
            }
        }

        Response::error("接口不存在: [{$requestMethod}] {$requestUri}", [], 404);
    }
}
