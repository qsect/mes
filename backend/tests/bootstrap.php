<?php
declare(strict_types=1);

/**
 * 极简测试框架（零依赖，不需要 PHPUnit / composer）
 *
 * 用法：
 *   require __DIR__ . '/bootstrap.php';
 *   test('用例描述', function () {
 *       assert_true(...);
 *       assert_eq(...);
 *   });
 *
 * 运行：
 *   /www/server/php/82/bin/php backend/tests/run.php      （服务器）
 *   php backend/tests/run.php                             （本地）
 *
 * 说明：本项目此前无任何自动化测试，回归全靠人肉。这里只覆盖「纯逻辑 + 静态配置」
 *       这类不需要数据库/会话的检查，成本极低但能拦住最典型的一类事故
 *       （改路由/改口令规则时把配置写坏，只在用户点击时才炸）。
 */

final class TestRunner
{
    public static int $passed = 0;
    public static int $failed = 0;

    /** @var array<int, array{0:string,1:string}> */
    public static array $failures = [];
}

/**
 * 注册并立即执行一个用例
 */
function test(string $name, callable $fn): void
{
    try {
        $fn();
        TestRunner::$passed++;
        echo "  [PASS] {$name}\n";
    } catch (Throwable $e) {
        TestRunner::$failed++;
        TestRunner::$failures[] = [$name, $e->getMessage()];
        echo "  [FAIL] {$name}\n         -> {$e->getMessage()}\n";
    }
}

function fail_test(string $msg): void
{
    throw new RuntimeException($msg);
}

/** 恒等断言（===） */
function assert_eq($expected, $actual, string $hint = ''): void
{
    if ($expected !== $actual) {
        fail_test('期望 ' . var_export($expected, true)
            . '，实际 ' . var_export($actual, true)
            . ($hint !== '' ? "（{$hint}）" : ''));
    }
}

function assert_true($actual, string $hint = ''): void
{
    assert_eq(true, $actual, $hint);
}

function assert_false($actual, string $hint = ''): void
{
    assert_eq(false, $actual, $hint);
}

/** 断言字符串包含某片段 */
function assert_contains(string $needle, string $haystack, string $hint = ''): void
{
    if (strpos($haystack, $needle) === false) {
        fail_test("期望包含 \"{$needle}\"，实际内容不含该片段"
            . ($hint !== '' ? "（{$hint}）" : ''));
    }
}
