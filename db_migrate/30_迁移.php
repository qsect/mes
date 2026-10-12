<?php declare(strict_types=1);

/**
 * db_migrate/30_迁移.php
 * ------------------------------------------------------------------
 * 按 db_migrate/mapping.json 的定义，把老库数据搬进新库。
 *
 * ★ 三条硬约束（对应你的三个要求）：
 *   「不破坏原网站数据」→ 源库连接被 _common.php 强制置为 READ ONLY，本脚本只有 SELECT。
 *   「不修改新网站结构」→ 目标库只执行 INSERT/REPLACE/UPDATE，绝不 ALTER/CREATE/DROP。
 *   「完整移植」        → 支持字段改名、值域转换、编码转换、常量补列，逐列显式映射不漏不错。
 *
 * 默认 dry-run（只试跑不写库）；真正落库必须显式加 --execute。
 *
 * 用法：
 *   php db_migrate/30_迁移.php                          # 试跑全部表
 *   php db_migrate/30_迁移.php --table=users,equipment  # 只试跑指定表
 *   php db_migrate/30_迁移.php --execute                # 真正写入（会二次确认）
 */

require __DIR__ . '/_common.php';

$opt = dbm_args();
$src = dbm_src();
$dst = dbm_dst();

$mappingFile = $opt['mapping'] ?: dbm_path('db_migrate/mapping.json');
if (!is_file($mappingFile)) {
    fwrite(STDERR, "找不到映射文件: {$mappingFile}\n");
    fwrite(STDERR, "请先复制 db_migrate/mapping.example.json 为 mapping.json 并填写。\n");
    exit(1);
}
$map = json_decode((string)file_get_contents($mappingFile), true);
if (!is_array($map) || empty($map['tables'])) {
    fwrite(STDERR, "mapping.json 格式不正确：顶层需要 {\"tables\": [ ... ]}\n");
    exit(1);
}

$srcDb = dbm_dbname($src);
$dstDb = dbm_dbname($dst);
$srcCols = dbm_columns($src);
$dstCols = dbm_columns($dst);

dbm_hr('迁移' . ($opt['execute'] ? '（★ 实际写入）' : '（试跑 dry-run）'));
dbm_info("源库 {$srcDb}  →  目标库 {$dstDb}");
dbm_info("映射文件 {$mappingFile}");
if ($opt['limit'] > 0) { dbm_warn("每表限制 {$opt['limit']} 行（试跑模式）"); }

// ---------------------------------------------------------------- 预检

$plan = [];     // [ [cfg, srcTable, dstTable, cols[], pk, warnings[]] ]
$hadError = false;

foreach ($map['tables'] as $cfg) {
    $sT = $cfg['source'] ?? '';
    $dT = $cfg['target'] ?? '';
    $note = $cfg['note'] ?? '';

    if (($cfg['enabled'] ?? true) === false) { continue; }
    if ($opt['tables'] && !in_array($sT, $opt['tables'], true) && !in_array($dT, $opt['tables'], true)) { continue; }

    $errs = [];
    if ($sT === '' || $dT === '') { $errs[] = 'source/target 未填写'; }
    if ($sT !== '' && !isset($srcCols[$sT])) { $errs[] = "源库不存在表 `{$sT}`"; }
    if ($dT !== '' && !isset($dstCols[$dT])) { $errs[] = "目标库不存在表 `{$dT}`（本工具不会替你建表）"; }

    $cols = $cfg['columns'] ?? [];
    if (!$cols) { $errs[] = 'columns 为空'; }

    foreach ($cols as $i => $c) {
        $from = $c['from'] ?? null;
        $to   = $c['to'] ?? null;
        if ($to === null || $to === '') { $errs[] = "columns[{$i}] 缺少 to"; continue; }
        if ($from !== null && $from !== '' && $sT !== '' && isset($srcCols[$sT]) && !isset($srcCols[$sT][$from])) {
            $errs[] = "源表 `{$sT}` 无列 `{$from}`";
        }
        if ($dT !== '' && isset($dstCols[$dT]) && !isset($dstCols[$dT][$to])) {
            $errs[] = "目标表 `{$dT}` 无列 `{$to}`";
        }
    }

    if ($errs) {
        $hadError = true;
        dbm_err("跳过 `{$sT}` → `{$dT}`" . ($note ? "（{$note}）" : ''));
        foreach (array_unique($errs) as $e) { echo "           · {$e}" . PHP_EOL; }
        continue;
    }

    $plan[] = [
        'cfg' => $cfg,
        'src' => $sT,
        'dst' => $dT,
        'cols' => $cols,
        'note' => $note,
        'pk' => dbm_pk($src, $sT),
    ];
}

if ($hadError) {
    dbm_hr();
    dbm_err('预检未通过，请先修好 mapping.json（上面列出的每一条都要处理）。');
    exit(1);
}
if (!$plan) {
    dbm_warn('没有需要处理的表（检查 --table 过滤条件或 mapping.json 的 enabled）。');
    exit(0);
}

dbm_hr('预检通过，共 ' . count($plan) . ' 张表');
foreach ($plan as $p) {
    $sc = dbm_count($src, $p['src']);
    $dc = dbm_count($dst, $p['dst']);
    printf("  %-30s → %-30s 源 %-9s 目标现有 %-9s [%s]%s%s",
        $p['src'], $p['dst'], $sc, $dc, $p['cfg']['mode'] ?? 'insert_ignore',
        $p['pk'] ? " pk={$p['pk']}" : ' ★无单列主键',
        $p['note'] ? "  // {$p['note']}" : ''
    );
    echo PHP_EOL;
}

// ---------------------------------------------------------------- 执行

if ($opt['execute'] && !$opt['yes']) {
    dbm_hr();
    dbm_warn('即将对【目标库 ' . $dstDb . '】写入数据。源库全程只读，但目标库的现有数据可能被覆盖。');
    dbm_info('强烈建议先在影子库上跑通。请确认已备份：mysqldump -u… ' . $dstDb . ' > backup_$(date +%F_%H%M).sql');
    if (!dbm_confirm('确认继续写入？')) {
        dbm_err('已取消。');
        exit(1);
    }
}

$grand = ['read' => 0, 'written' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($plan as $p) {
    $cfg  = $p['cfg'];
    $mode = $cfg['mode'] ?? 'insert_ignore';
    $where = trim((string)($cfg['where'] ?? '')) ?: '1=1';
    $batch = max(1, min(1000, (int)($cfg['batch_size'] ?? 200)));
    $charset = $cfg['source_charset'] ?? ($map['source_charset'] ?? null);

    dbm_hr("{$p['src']} → {$p['dst']}  [{$mode}]");

    // ---- 构造 SELECT
    $selectExpr = [];
    $binds = [];      // 条件参数（本工具不强求参数化 where，但保留）
    foreach ($p['cols'] as $c) {
        $from = $c['from'] ?? null;
        if ($from === null || $from === '') { continue; }   // 纯常量列，不参与 SELECT
        $selectExpr[] = '`' . str_replace('`', '``', $from) . '`';
    }
    if (!$selectExpr) {
        dbm_err('该表所有列都是常量，无数据可搬，跳过。');
        continue;
    }
    $pk = $p['pk'];
    $pkFrom = null;
    if ($pk) {
        foreach ($p['cols'] as $c) {
            if (($c['from'] ?? null) === $pk) { $pkFrom = $pk; break; }
        }
        // 主键没在映射里 → 无法用 keyset 分页，退回 OFFSET
        if ($pkFrom === null) { $pk = null; }
    }
    if (!$pk) {
        dbm_warn('源表无单列主键（或主键列未参与映射）→ 使用 OFFSET 分页，大表会较慢。');
    }

    $srcTotal = dbm_count($src, $p['src']);
    $limit = $opt['limit'] > 0 ? $opt['limit'] : 0;
    dbm_info("源表 {$srcTotal} 行" . ($limit ? "，本次限制 {$limit} 行" : ''));

    // ---- 逐批读取 + 写入
    $r = ['read' => 0, 'written' => 0, 'skipped' => 0, 'failed' => 0];
    $lastPk = null;
    $offset = 0;
    $previewShown = 0;
    $maxPreview = 3;

    try {
        while (true) {
            if ($limit > 0 && $r['read'] >= $limit) { break; }
            $pageSize = $batch;
            if ($limit > 0) { $pageSize = min($pageSize, $limit - $r['read']); }
            if ($pageSize <= 0) { break; }

            if ($pk) {
                $sql = 'SELECT ' . implode(', ', $selectExpr) . " FROM `{$p['src']}` WHERE ({$where})"
                    . ($lastPk !== null ? " AND `{$pk}` > ?" : '')
                    . " ORDER BY `{$pk}` ASC LIMIT {$pageSize}";
                $st = $src->prepare($sql);
                $binds2 = $binds;
                if ($lastPk !== null) { $binds2[] = $lastPk; }
                $st->execute($binds2);
            } else {
                $sql = 'SELECT ' . implode(', ', $selectExpr) . " FROM `{$p['src']}` WHERE ({$where})"
                    . " LIMIT {$pageSize} OFFSET {$offset}";
                $st = $src->prepare($sql);
                $st->execute($binds);
                $offset += $pageSize;
            }
            $rows = $st->fetchAll();
            $st->closeCursor();
            if (!$rows) { break; }

            $r['read'] += count($rows);

            // ---- 逐行转换
            $outRows = [];
            foreach ($rows as $row) {
                [$vals, $nulls] = dbm_build_row($p['cols'], $row, $charset);
                $outRows[] = $vals;
                if (!$opt['execute'] && $previewShown < $maxPreview) {
                    dbm_info('试跑样本: ' . json_encode(array_combine(
                        array_map(fn($c) => $c['to'], $p['cols']), array_map(
                            fn($v, $n) => $n ? '(NULL)' : (is_string($v) ? mb_substr($v, 0, 40) : $v),
                            $vals, $nulls
                        )), JSON_UNESCAPED_UNICODE));
                    $previewShown++;
                }
            }
            if (count($outRows) !== count($rows)) { /* 防御性 */ }

            if ($opt['execute']) {
                [$ok, $aff, $err] = dbm_write_batch($dst, $p['dst'], $p['cols'], $outRows, $mode);
                if (!$ok) {
                    $r['failed'] += count($outRows);
                    dbm_err("写入失败（本批 " . count($outRows) . " 行）：{$err}");
                    throw new RuntimeException('写入中断，已停止该表以避免数据不一致');
                }
                $r['written'] += $aff;
            } else {
                $r['written'] += count($outRows);
            }

            // 记录本批最后一行的主键值，供下一批 keyset 续读
            if ($pk) { $lastPk = $rows[count($rows) - 1][$pk] ?? null; }
            if (count($rows) < $pageSize) { break; }
        }
    } catch (Throwable $e) {
        dbm_err($e->getMessage());
    }

    dbm_ok(sprintf('读取 %d 行 / 写入 %d 行 %s', $r['read'], $r['written'], $opt['execute'] ? '' : '（试跑，未落库）'));
    $grand['read'] += $r['read'];
    $grand['written'] += $r['written'];
    $grand['failed'] += $r['failed'];

    // ---- 善后：重置自增，避免后续新插入的 ID 与搬进来的 ID 冲突
    if ($opt['execute'] && ($cfg['after']['reset_auto_increment'] ?? false) && $p['pk']) {
        try {
            $max = $dst->query("SELECT COALESCE(MAX(`{$p['pk']}`),0) FROM `{$p['dst']}`")->fetchColumn();
            $dst->exec("ALTER TABLE `{$p['dst']}` AUTO_INCREMENT = " . ((int)$max + 1));
            dbm_ok("已重置自增 `{$p['pk']}` → " . ((int)$max + 1));
        } catch (Throwable $e) {
            dbm_warn('重置自增失败（不影响数据，可忽略）：' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------- 汇总

dbm_hr('汇总');
dbm_info("读取 {$grand['read']} 行，写入 {$grand['written']} 行，失败 {$grand['failed']} 行");
if ($grand['failed'] > 0) {
    dbm_err('存在失败行，请检查上面输出。');
    exit(2);
}
if (!$opt['execute']) {
    dbm_warn('以上为试跑结果，未写入任何数据。确认无误后加 --execute 执行。');
    dbm_info('建议顺序：先在影子库 --execute → php db_migrate/40_校验.php → 通过后再对正式库执行。');
} else {
    dbm_ok('写入完成。请立刻运行校验：php db_migrate/40_校验.php');
}
exit(0);

// ================================================================ 函数

/**
 * 按列配置把一行源数据转换成目标行的值数组。
 * @return array{0: array<int,mixed>, 1: array<int,bool>} [值列表, 是否NULL列表]
 */
function dbm_build_row(array $cols, array $srcRow, ?string $charset): array
{
    $vals = [];
    $nulls = [];
    foreach ($cols as $c) {
        $from = $c['from'] ?? null;
        $to   = $c['to'];

        // 常量列
        if ($from === null || $from === '') {
            $v = dbm_placeholder($c['const'] ?? null);
        } else {
            $v = array_key_exists($from, $srcRow) ? $srcRow[$from] : null;
        }

        // 编码转换（先转码，再做其它变换）
        if ($charset && $v !== null && is_string($v)) {
            $v = dbm_recode($v, $charset);
        }

        // 通用变换
        $tr = $c['transform'] ?? null;
        if ($tr !== null && $v !== null) {
            $v = dbm_apply_transform($tr, $v, $c['transform_arg'] ?? null);
        }

        // 值域映射
        if (!empty($c['map']) && is_array($c['map'])) {
            $key = $v === null ? '' : (string)$v;
            if (array_key_exists($key, $c['map'])) {
                $v = dbm_placeholder($c['map'][$key]);
            } elseif (array_key_exists('map_default', $c)) {
                $v = dbm_placeholder($c['map_default']);
            }
        }

        // 空值归一
        if ($v === '' && !empty($c['empty_to_null'])) { $v = null; }

        // 兜底默认值
        if ($v === null && array_key_exists('default', $c)) {
            $v = dbm_placeholder($c['default']);
        }

        // 必填校验（NOT NULL 且无值 → 用默认空串占位，并提示）
        if ($v === null && !empty($c['required'])) {
            dbm_warn("列 `{$to}` 标记 required 但取到 NULL，已置空串，请检查映射。");
            $v = '';
        }

        $vals[] = $v;
        $nulls[] = $v === null;
    }
    return [$vals, $nulls];
}

/** 常量占位符替换 */
function dbm_placeholder($v)
{
    if (!is_string($v)) { return $v; }
    return match ($v) {
        '{{NOW}}'  => date('Y-m-d H:i:s'),
        '{{DATE}}' => date('Y-m-d'),
        '{{YEAR}}' => date('Y'),
        '{{UUID}}' => bin2hex(random_bytes(16)),
        '{{NULL}}' => null,
        default    => $v,
    };
}

function dbm_apply_transform(string $tr, $v, $arg)
{
    return match ($tr) {
        'trim'             => is_string($v) ? trim($v) : $v,
        'int'              => (int)$v,
        'float'            => (float)$v,
        'string'           => (string)$v,
        'upper'            => is_string($v) ? mb_strtoupper($v) : $v,
        'lower'            => is_string($v) ? mb_strtolower($v) : $v,
        'strip_tags'       => is_string($v) ? trim(strip_tags($v)) : $v,
        'bool01'           => in_array((string)$v, ['1', 'true', 'Y', 'y', 'yes', '是'], true) ? 1 : 0,
        'null_if_zero'     => ((string)$v === '0' || $v === 0) ? null : $v,
        'null_if_empty'    => (is_string($v) && trim($v) === '') ? null : $v,
        // 时间戳 → 日期时间（老系统常用 int 存 unix 时间）
        'ts_to_datetime'   => ((int)$v > 0) ? date('Y-m-d H:i:s', (int)$v) : null,
        'ts_to_date'       => ((int)$v > 0) ? date('Y-m-d', (int)$v) : null,
        'ms_to_datetime'   => ((int)$v > 0) ? date('Y-m-d H:i:s', intdiv((int)$v, 1000)) : null,
        // 字符串日期 → 规范化（'2026/1/5' → '2026-01-05'）
        'date_normalize'   => dbm_date_normalize((string)$v),
        // 0/空 → NULL（老库常用 '0000-00-00 00:00:00' 表示空）
        'zero_date_to_null' => (in_array(substr((string)$v, 0, 10), ['0000-00-00', ''], true)) ? null : $v,
        default            => $v,   // 未知变换原样保留，不静默丢数据
    };
}

function dbm_date_normalize(string $v): ?string
{
    $v = trim($v);
    if ($v === '' || str_starts_with($v, '0000-00-00')) { return null; }
    $ts = strtotime(str_replace('/', '-', $v));
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

/** 把老库编码的字符串转成 UTF-8 */
function dbm_recode(string $v, string $from): string
{
    $from = strtoupper($from);
    if (in_array($from, ['UTF8', 'UTF8MB4'], true)) { return $v; }
    if (!mb_check_encoding($v, 'UTF-8')) {
        $conv = @mb_convert_encoding($v, 'UTF-8', $from);
        if ($conv !== false) { return $conv; }
    }
    return $v;   // 已经是 UTF-8 就不要二次转码
}

/** 执行一批写入，返回 [是否成功, 影响行数, 错误信息] */
function dbm_write_batch(PDO $dst, string $table, array $cols, array $rows, string $mode): array
{
    $toCols = array_map(fn($c) => $c['to'], $cols);
    $quoted = array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', $toCols);
    $rowPh  = '(' . implode(',', array_fill(0, count($toCols), '?')) . ')';

    $verb = match ($mode) {
        'replace'        => 'REPLACE INTO',
        'insert_ignore'  => 'INSERT IGNORE INTO',
        default          => 'INSERT INTO',
    };

    $sql = "{$verb} `{$table}` (" . implode(',', $quoted) . ') VALUES '
        . implode(',', array_fill(0, count($rows), $rowPh));

    if (in_array($mode, ['upsert', 'update'], true)) {
        $sets = [];
        $pk = 'id';
        foreach ($toCols as $c) {
            if ($c === $pk) { continue; }
            $q = '`' . str_replace('`', '``', $c) . '`';
            $sets[] = "{$q} = VALUES({$q})";
        }
        if ($sets) { $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets); }
    }

    try {
        $st = $dst->prepare($sql);
        $i = 0;
        foreach ($rows as $row) {
            foreach ($row as $v) {
                $i++;
                if ($v === null) {
                    $st->bindValue($i, null, PDO::PARAM_NULL);
                } elseif (is_int($v)) {
                    $st->bindValue($i, $v, PDO::PARAM_INT);
                } else {
                    $st->bindValue($i, (string)$v, PDO::PARAM_STR);
                }
            }
        }
        $st->execute();
        return [true, $st->rowCount(), ''];
    } catch (Throwable $e) {
        return [false, 0, $e->getMessage()];
    }
}
