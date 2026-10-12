<?php declare(strict_types=1);

/**
 * db_migrate/20_结构对比.php
 * ------------------------------------------------------------------
 * 对比「老库(源)」与「新库(目标)」的表结构，回答三个问题：
 *   1) 哪些表两边都有？（→ 只需搬数据，结构以新库为准）
 *   2) 哪些表只有老库有？（→ 新系统仍在使用，必须整表搬过来）
 *   3) 哪些表只有新库有？（→ 新系统新建的，老库没有，看是否需要从别的老表映射）
 *
 * 并对「两边都有」的表逐列比对：仅老有 / 仅新有 / 类型不一致 / 字符集不一致 / 可空性不一致。
 *
 * 本脚本【完全只读】，不写任何数据库。
 *
 * 用法：
 *   php db_migrate/20_结构对比.php
 *   php db_migrate/20_结构对比.php --out=db_migrate/结构差异报告.md
 */

require __DIR__ . '/_common.php';

$opt  = dbm_args();
$src  = dbm_src();
$dst  = dbm_dst();

$srcDb = dbm_dbname($src);
$dstDb = dbm_dbname($dst);

$srcCols = dbm_columns($src);
$dstCols = dbm_columns($dst);
$srcTbl  = dbm_tables($src);
$dstTbl  = dbm_tables($dst);

$onlySrc = array_values(array_diff(array_keys($srcTbl), array_keys($dstTbl)));
$onlyDst = array_values(array_diff(array_keys($dstTbl), array_keys($srcTbl)));
$both    = array_values(array_intersect(array_keys($srcTbl), array_keys($dstTbl)));
sort($onlySrc); sort($onlyDst); sort($both);

dbm_hr('结构对比');
dbm_info("源库(老站) = {$srcDb}    表 " . count($srcTbl) . " 张");
dbm_info("目标库(新站) = {$dstDb}  表 " . count($dstTbl) . " 张");
dbm_info("两边都有 " . count($both) . " / 仅老库 " . count($onlySrc) . " / 仅新库 " . count($onlyDst));

$L = [];
$L[] = '# 数据库结构差异报告（老站 → 新站）';
$L[] = '';
$L[] = '> 由 `db_migrate/20_结构对比.php` 自动生成，纯只读探测，未修改任何数据。';
$L[] = '';
$L[] = "- 源库（老站）：`{$srcDb}`，共 " . count($srcTbl) . ' 张表';
$L[] = "- 目标库（新站）：`{$dstDb}`，共 " . count($dstTbl) . ' 张表';
$L[] = '';
$L[] = '## 一、总览';
$L[] = '';
$L[] = '| 分类 | 张数 | 含义与处理原则 |';
$L[] = '| --- | --- | --- |';
$L[] = '| 两边都有 | ' . count($both) . ' | 结构以**新库**为准，只需把老库数据按列映射搬进来 |';
$L[] = '| 仅老库有 | ' . count($onlySrc) . ' | 新系统可能仍在使用（遗留表）→ 需**整表搬入**新库 |';
$L[] = '| 仅新库有 | ' . count($onlyDst) . ' | 新系统新建的表，老库无对应 → 判断是否需从老库别的表映射 |';
$L[] = '';

// -------------------------------------------------- 仅老库有
$L[] = '## 二、仅老库有的表（重点：新系统可能依赖）';
$L[] = '';
if (!$onlySrc) {
    $L[] = '（无）';
    $L[] = '';
} else {
    $L[] = '| 表名 | 近似行数 | 引擎 | 字符集 | 说明 |';
    $L[] = '| --- | --- | --- | --- | --- |';
    foreach ($onlySrc as $t) {
        $r = $srcTbl[$t];
        // 判定是否被新系统源码引用（决定它是"必须搬"还是"可忽略"）
        $used = dbm_is_referenced($t) ? '**新系统代码仍在引用 → 必须搬**' : '新系统未引用，可忽略';
        $L[] = sprintf('| `%s` | %s | %s | %s | %s |',
            $t, $r['TABLE_ROWS'] ?? '?', $r['ENGINE'] ?? '?', $r['TABLE_COLLATION'] ?? '?', $used);
    }
    $L[] = '';
    dbm_hr('仅老库有（新系统可能依赖）');
    foreach ($onlySrc as $t) {
        $used = dbm_is_referenced($t);
        ($used ? 'dbm_warn' : 'dbm_info')(sprintf('%-32s 行数约 %-10s %s', $t, $srcTbl[$t]['TABLE_ROWS'] ?? '?',
            $used ? '← 新系统代码在引用，必须搬' : '（新系统未引用）'));
    }
}

// -------------------------------------------------- 仅新库有
$L[] = '## 三、仅新库有的表（老库无对应）';
$L[] = '';
if (!$onlyDst) {
    $L[] = '（无）';
    $L[] = '';
} else {
    $L[] = '| 表名 | 近似行数 | 说明 |';
    $L[] = '| --- | --- | --- |';
    foreach ($onlyDst as $t) {
        $L[] = sprintf('| `%s` | %s | 新系统新建，需判断老库有哪个表可映射进来 |', $t, $dstTbl[$t]['TABLE_ROWS'] ?? '?');
    }
    $L[] = '';
}

// -------------------------------------------------- 两边都有：逐列比对
$L[] = '## 四、两边都有的表 —— 逐列差异';
$L[] = '';
$colIssues = [];
foreach ($both as $t) {
    $sc = $srcCols[$t] ?? [];
    $dc = $dstCols[$t] ?? [];
    $onlyInSrc = array_diff(array_keys($sc), array_keys($dc));
    $onlyInDst = array_diff(array_keys($dc), array_keys($sc));
    $diffType  = [];
    $diffCset  = [];
    foreach (array_intersect(array_keys($sc), array_keys($dc)) as $c) {
        if (dbm_norm_type($sc[$c]['COLUMN_TYPE']) !== dbm_norm_type($dc[$c]['COLUMN_TYPE'])) {
            $diffType[$c] = [$sc[$c]['COLUMN_TYPE'], $dc[$c]['COLUMN_TYPE']];
        }
        if (($sc[$c]['CHARACTER_SET_NAME'] ?? null) !== ($dc[$c]['CHARACTER_SET_NAME'] ?? null)
            && ($sc[$c]['CHARACTER_SET_NAME'] ?? null) !== null) {
            $diffCset[$c] = [$sc[$c]['CHARACTER_SET_NAME'], $dc[$c]['CHARACTER_SET_NAME']];
        }
    }
    if (!$onlyInSrc && !$onlyInDst && !$diffType && !$diffCset) {
        continue;   // 完全一致，不进报告，避免噪音
    }
    $colIssues[$t] = compact('onlyInSrc', 'onlyInDst', 'diffType', 'diffCset');

    $L[] = "### `{$t}`";
    $L[] = '';
    if ($diffType) {
        $L[] = '**类型不一致**（注意：以新库类型为准，写入时可能被截断/转换）';
        $L[] = '';
        $L[] = '| 列 | 老库 | 新库 |';
        $L[] = '| --- | --- | --- |';
        foreach ($diffType as $c => [$a, $b]) {
            $L[] = "| `{$c}` | `{$a}` | `{$b}` |";
        }
        $L[] = '';
    }
    if ($diffCset) {
        $L[] = '**字符集不一致**（老库若是 gbk/latin1，需转码否则中文乱码）';
        $L[] = '';
        $L[] = '| 列 | 老库 | 新库 |';
        $L[] = '| --- | --- | --- |';
        foreach ($diffCset as $c => [$a, $b]) {
            $L[] = "| `{$c}` | {$a} | {$b} |";
        }
        $L[] = '';
    }
    if ($onlyInSrc) {
        $L[] = '- **仅老库有的列**（新库接收时需要丢弃或映射到别的列）：`' . implode('`, `', $onlyInSrc) . '`';
    }
    if ($onlyInDst) {
        $L[] = '- **仅新库有的列**（老库无来源 → 需给默认值/常量，或允许为 NULL）';
        $L[] = '';
        $flags = [];
        foreach ($onlyInDst as $c) {
            $x = $dc[$c];
            $flags[] = sprintf('`%s` %s%s',
                $c, $x['COLUMN_TYPE'],
                $x['IS_NULLABLE'] === 'NO' ? ' **NOT NULL 无默认 → 必须提供值**' : '');
        }
        $L[] = '  - ' . implode(PHP_EOL . '  - ', $flags);
    }
    $L[] = '';
}
if (!$colIssues) {
    $L[] = '所有同名表结构完全一致，可直接搬数据。';
    $L[] = '';
}

// -------------------------------------------------- 索引差异概览
$L[] = '## 五、下一步';
$L[] = '';
$L[] = '1. 按「四」的差异，为每张表在 `db_migrate/mapping.json` 里写一条映射（可参照 `mapping.example.json`）。';
$L[] = '2. `php db_migrate/30_迁移.php`（dry-run，不写库）→ 看试跑结果。';
$L[] = '3. 在**影子库**上 `--execute` 跑一遍 → `php db_migrate/40_校验.php` 核对行数与抽样。';
$L[] = '4. 全部通过后，才对正式新库执行同一份 mapping。';
$L[] = '';

$report = implode(PHP_EOL, $L);
$out = $opt['out'] ?: dbm_path('db_migrate/结构差异报告.md');
@file_put_contents($out, $report);
dbm_hr();
dbm_ok("报告已写入: {$out}");

if ($colIssues) {
    dbm_warn('存在列差异的表: ' . implode(', ', array_keys($colIssues)));
} else {
    dbm_ok('两边同名表结构完全一致。');
}

// -------------------------------------------------- 辅助

/** 判断某表名是否出现在新系统源码中（FROM/JOIN/INTO/UPDATE ...） */
function dbm_is_referenced(string $table): bool
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $dirs = [dbm_path('backend/app'), dbm_path('backend/public'), dbm_path('backend/cron')];
        foreach ($dirs as $d) {
            if (!is_dir($d)) { continue; }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') { continue; }
                $t = (string)@file_get_contents($f->getPathname());
                if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([A-Za-z0-9_]+)`?/i', $t, $m)) {
                    foreach ($m[1] as $n) { $cache[strtolower($n)] = true; }
                }
            }
        }
    }
    return !empty($cache[strtolower($table)]);
}

/** 归一化类型，忽略显示宽度等无关差异（int(11) vs int） */
function dbm_norm_type(string $t): string
{
    $t = strtolower($t);
    $t = preg_replace('/\(\d+\)/', '', $t);            // 去掉 (11)
    $t = preg_replace('/\s+unsigned/', ' unsigned', $t);
    return trim((string)$t);
}
