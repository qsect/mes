<?php
declare(strict_types=1);

/**
 * 单元测试运行入口
 *
 *   php backend/tests/run.php
 *
 * 零依赖：不使用 PHPUnit，直接 require tests/ 下的 *Test.php 并汇总结果。
 * 退出码：0 = 全部通过；1 = 存在失败用例（便于接入 CI / 部署自检）。
 */

require __DIR__ . '/bootstrap.php';

$tests = glob(__DIR__ . '/*Test.php') ?: [];
sort($tests);

if ($tests === []) {
    fwrite(STDERR, "未找到任何 *Test.php 文件\n");
    exit(1);
}

echo "==== 单元测试 ====\n";

foreach ($tests as $file) {
    echo "\n[" . basename($file) . "]\n";
    require $file;
}

echo "\n==== 结果 ====\n";
printf("通过 %d 项，失败 %d 项\n", TestRunner::$passed, TestRunner::$failed);

if (TestRunner::$failed > 0) {
    echo "\n失败明细：\n";
    foreach (TestRunner::$failures as $f) {
        echo "  - {$f[0]}\n    {$f[1]}\n";
    }
    exit(1);
}

echo "全部通过\n";
exit(0);
