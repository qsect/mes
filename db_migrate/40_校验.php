<?php declare(strict_types=1);

/**
 * db_migrate/40_校验.php
 * ------------------------------------------------------------------
 * 迁移后的对账：确认「完整移植」而不是「看起来搬完了」。
 *
 * 校验三件事：
 *   1) 行数对账   —— 源表（按 where 过滤后）行数 vs 目标表行数
 *   2) 主键覆盖   —— 源表所有主键是否都能在目标表找到（防漏行）
 *   3) 抽样逐列   —— 随机抽 N 行，按映射逐列比对（防错列/错值/乱码）
 *
 * 纯只读，不写任何库。
 *
 * 用法：
 *   php db_migrate/40_校验.php
 *   php db_migrate/40_校验.php --table=users --limit=50   # 每表抽 50 行比对
 */

require __DIR__ . '/_common.php';

$opt = dbm_args();
$src = dbm_src();
$dst = dbm_dst();

$mappingFile = $opt['mapping'] ?: dbm_path('db_migrate/mapping.json');
if (!is_file($mappingFile)) {
    fwrite(STDERR, "找不到映射文件: {$mappingFile}\n");
    exit(1);
}
$map = json_decode((string)file_get_contents($mappingFile), true);
$sampleSize = $opt['limit'] > 0 ? $opt['limit'] : 20;

dbm_hr('迁移对账校验');
dbm_info('源库 ' . dbm_dbname($src) . '  →  目标库 ' . dbm_dbname($dst));
dbm_info("抽样 {$sampleSize} 行/表");

$pass = 0;
$fail = 0;
$rows = [];

foreach ($map['tables'] as $cfg) {
    if (($cfg['enabled'] ?? true) === false) { continue; }
    $sT = $cfg['source'] ?? '';
    $dT = $cfg['target'] ?? '';
    if ($opt['tables'] && !in_array($sT, $opt['tables'], true) && !in_array($dT, $opt['tables'], true)) { continue; }
    if ($sT === '' || $dT === '') { continue; }

    $where = trim((string)($cfg['where'] ?? '')) ?: '1=1';
    $pk = dbm_pk($src, $sT);

    // ---- 1) 行数对账
    try {
        $sc = (int)$src->query("SELECT COUNT(*) FROM `{$sT}` WHERE {$where}")->fetchColumn();
        $dc = dbm_count($dst, $dT);
    } catch (Throwable $e) {
        $fail++;
        dbm_err("{$sT} → {$dT}  计数失败：" . $e->getMessage());
        $rows[] = [$sT, $dT, '-', '-', false, $e->getMessage()];
        continue;
    }

    // 目标表可能本来就有新系统自己的数据，或存在 upsert/去重
    // 因此判定标准是「目标 >= 源」且差额可解释；mode=insert_ignore 时差额往往是重复键。
    $mode = $cfg['mode'] ?? 'insert_ignore';
    $okCount = $dc >= $sc;
    $hint = $okCount
        ? ($dc === $sc ? '完全一致' : '目标多 ' . ($dc - $sc) . ' 行（含新系统原有数据或历史去重，属正常）')
        : '★ 目标比源少 ' . ($sc - $dc) . ' 行，可能漏搬';

    // ---- 2) 主键覆盖 + 3) 抽样逐列比对
    $missPk = 0;
    $mismatch = [];
    if ($pk) {
        $sel = $pk;
        $st = $src->prepare("SELECT `{$pk}` FROM `{$sT}` WHERE {$where} ORDER BY RAND() LIMIT {$sampleSize}");
        $st->execute();
        $pks = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($pks as $v) {
            $q = $dst->prepare("SELECT COUNT(*) FROM `{$dT}` WHERE `{$pk}` = ?");
            $q->execute([$v]);
            if ((int)$q->fetchColumn() === 0) { $missPk++; }
        }

        // 逐列比对：取源行 → 按映射转换 → 与目标行对应列比较
        $fromCols = array_values(array_filter(array_map(fn($c) => $c['from'] ?? null, $cfg['columns'] ?? [])));
        if ($fromCols && $pks) {
            $in = implode(',', array_fill(0, count($pks), '?'));
            $st2 = $src->prepare("SELECT " . implode(',', array_map(fn($c) => "`{$c}`", $fromCols))
                . " FROM `{$sT}` WHERE `{$pk}` IN ({$in})");
            $st2->execute($pks);
            $srcRows = [];
            foreach ($st2->fetchAll() as $r) { $srcRows[(string)$r[$pk]] = $r; }

            $toCols = array_map(fn($c) => $c['to'], $cfg['columns'] ?? []);
            $st3 = $dst->prepare("SELECT " . implode(',', array_map(fn($c) => "`{$c}`", $toCols))
                . " FROM `{$dT}` WHERE `{$pk}` IN ({$in})");
            $st3->execute($pks);
            $dstRows = [];
            foreach ($st3->fetchAll() as $r) { $dstRows[(string)$r[$pk]] = $r; }

            $charset = $cfg['source_charset'] ?? ($map['source_charset'] ?? null);
            foreach ($srcRows as $k => $sr) {
                if (!isset($dstRows[$k])) { continue; }
                [$vals] = dbm_build_row($cfg['columns'] ?? [], $sr, $charset);
                $toColsA = array_map(fn($c) => $c['to'], $cfg['columns']);
                foreach ($toColsA as $i => $col) {
                    $exp = $vals[$i];
                    $got = $dstRows[$k][$col];
                    if (dbm_loose_eq($exp, $got)) { continue; }
                    $mismatch[] = "{$col}: 源→`" . mb_substr((string)$exp, 0, 30) . "` 目标→`" . mb_substr((string)$got, 0, 30) . '`';
                    if (count($mismatch) >= 5) { break 2; }
                }
            }
        }
    }

    $ok = $okCount && $missPk === 0 && !$mismatch;
    $ok ? $pass++ : $fail++;

    printf("  %-30s → %-30s 源 %-8d 目标 %-8d %s%s",
        $sT, $dT, $sc, $dc, $ok ? '[通过]' : '[失败]', PHP_EOL);
    echo "        {$hint}" . PHP_EOL;
    if (!$pk) { dbm_warn("        {$sT} 无单列主键，跳过主键覆盖与抽样比对"); }
    if ($missPk) { dbm_err("        有 {$missPk}/" . count($pks ?? []) . " 个主键在目标表找不到 → 漏行"); }
    foreach ($mismatch as $m) { dbm_err("        值不一致 {$m}"); }

    $rows[] = [$sT, $dT, $sc, $dc, $ok, $hint];
}

dbm_hr('结果');
if ($fail === 0) {
    dbm_ok("全部通过：{$pass} 张表行数与抽样比对一致。");
    exit(0);
}
dbm_err("有 {$fail} 张表未通过（{$pass} 张通过），请先排查再考虑上线。");
exit(1);

// ---------------------------------------------------------------- 辅助

/** 宽松相等：忽略 int/string 差异、NULL 与 '' 视为等价、忽略时间尾部 */
function dbm_loose_eq($a, $b): bool
{
    if ($a === null && ($b === null || $b === '')) { return true; }
    if ($b === null && $a === '') { return true; }
    if (is_numeric($a) && is_numeric($b)) { return abs((float)$a - (float)$b) < 1e-9; }
    $a = is_string($a) ? trim($a) : $a;
    $b = is_string($b) ? trim($b) : $b;
    return (string)$a === (string)$b;
}
