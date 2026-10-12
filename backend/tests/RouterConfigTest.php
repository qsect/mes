<?php
declare(strict_types=1);

/**
 * 路由表静态健康检查
 *
 * 背景（2026-10-11 实际踩坑）：
 *   路由表是纯文本配置，写法错误编译期不报错，只有请求命中时才炸，
 *   且报错信息极具误导性 —— 例如中间件少写一层方括号时，PHP 会把
 *   模块名当成类名去 new，抛出 `Class "mac" not found`，极易被误读为
 *   "控制器缺失"。更隐蔽的是：少一层括号会连带丢掉中间件的构造参数，
 *   导致权限中间件空转（安静的越权）。
 *
 *   这里把这类问题全部提前到测试阶段，用静态解析的方式体检整个路由表。
 */

$INDEX_PATH = __DIR__ . '/../public/index.php';
$SRC = (string) file_get_contents($INDEX_PATH);

// 提取所有路由注册行（本项目约定 add() 单行书写）
preg_match_all('/^[ \t]*\$router->add\([^\n]*$/m', $SRC, $m);
$ROUTE_LINES = $m[0];

test('路由表：入口文件可读且存在路由注册', function () use ($SRC, $ROUTE_LINES) {
    assert_true($SRC !== '', 'public/index.php 读取为空');
    assert_true(count($ROUTE_LINES) > 0, '未解析到任何 $router->add() 注册行');
});

test('路由表：不存在「单层方括号 + 字符串首项」的中间件写法', function () use ($SRC) {
    // 错误写法：$router->add("GET","/x",[C::class,"m"], ['App\\Middlewares\\X','mod']);
    // 正确写法：                                              [['App\\Middlewares\\X','mod']]
    preg_match_all('/,\s*\[\s*\'/', $SRC, $hit);
    assert_eq(
        0,
        count($hit[0]),
        '发现单层方括号的中间件写法（少一层 []，会丢掉构造参数导致权限校验空转）'
    );
});

test('路由表：路由参数中不存在裸模块名数组（如 [\'mac\']）', function () use ($ROUTE_LINES) {
    // ★ 注意：只在路由注册行内扫描。若对全文扫描，会把 $_SESSION['user']、
    //   $row['last_active'] 这类合法的下标访问一并误报（本条测试初版就踩了这个坑）。
    $hits = [];
    foreach ($ROUTE_LINES as $line) {
        if (preg_match_all('/\[\s*\'[a-z_][a-z0-9_]*\'\s*\]/', $line, $h)) {
            $hits = array_merge($hits, $h[0]);
        }
    }
    assert_eq(
        0,
        count($hits),
        '发现裸模块名数组：' . implode(' / ', $hits) . '（会把模块名当类名 new，报 Class not found）'
    );
});

test('路由表：引用的中间件与控制器类在磁盘上真实存在', function () use ($ROUTE_LINES) {
    $missing = [];
    foreach ($ROUTE_LINES as $line) {
        // 统一去掉反斜杠后再匹配，兼容 ::class（单 \) 与字符串（双 \\）两种写法
        $flat = str_replace('\\', '', $line);

        if (preg_match_all('/AppMiddlewares([A-Za-z_][A-Za-z0-9_]*)/', $flat, $mw)) {
            foreach ($mw[1] as $name) {
                if (!is_file(__DIR__ . '/../app/Middlewares/' . $name . '.php')) {
                    $missing[] = "Middlewares/{$name}.php";
                }
            }
        }
        if (preg_match_all('/AppControllers([A-Za-z_][A-Za-z0-9_]*)/', $flat, $ct)) {
            foreach ($ct[1] as $name) {
                if (!is_file(__DIR__ . '/../app/Controllers/' . $name . '.php')) {
                    $missing[] = "Controllers/{$name}.php";
                }
            }
        }
    }
    assert_eq([], array_values(array_unique($missing)), '路由引用了不存在的类文件');
});

test('路由表：中间件引用数量与带中间件的路由量级一致', function () use ($SRC) {
    $withMw = substr_count($SRC, ', [[');
    assert_true($withMw > 0, '未发现任何带中间件的路由（双层方括号）');
    assert_true(
        $withMw <= substr_count($SRC, '$router->add('),
        '带中间件的路由数不应超过路由总数'
    );
});

test('路由表：路由总数不低于基线（防止误删整段配置）', function () use ($SRC) {
    $n = substr_count($SRC, '$router->add(');
    assert_true($n >= 200, "当前路由数 {$n}，低于基线 200，疑似配置被破坏");
});
