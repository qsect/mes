<?php declare(strict_types=1);

/**
 * db_migrate/_common.php
 * ------------------------------------------------------------------
 * 老库(源) → 新库(目标) 数据库移植工具集的公共部分。
 *
 * ★ 安全红线（贯穿全部脚本）：
 *   1) 源库(老站) 全程【只读】：连接后立即 SET SESSION TRANSACTION READ ONLY；
 *      全部代码只出现 SELECT，不存在任何写语句。
 *   2) 目标库(新站) 只做数据写入（INSERT/REPLACE/UPDATE），
 *      绝不做 ALTER/CREATE/DROP —— 保证「不修改新网站的结构」。
 *   3) 所有迁移默认 dry-run，只有显式加 --execute 才真正落库。
 *
 * 连接来源：
 *   源库  → db_migrate/source.env （独立文件，绝不复用 backend/.env）
 *   目标库 → backend/.env 的 DB_* （新站自己的配置，天然一致）
 */

// ---------------------------------------------------------------- 基础

function dbm_usage(string $script, string $extra = ''): void
{
    fwrite(STDERR, "用法: php {$script} [选项]{$extra}\n");
    fwrite(STDERR, "  --execute            真正执行写入（默认仅 dry-run 试跑）\n");
    fwrite(STDERR, "  --table=名称[,...]   只处理指定表（逗号分隔）\n");
    fwrite(STDERR, "  --limit=N            每张表最多处理 N 行（试跑用）\n");
    fwrite(STDERR, "  --mapping=路径       映射文件，默认 db_migrate/mapping.json\n");
    fwrite(STDERR, "  --yes                跳过二次确认（危险，谨慎）\n");
}

/** 解析命令行参数 */
function dbm_args(): array
{
    $opt = ['execute' => false, 'yes' => false, 'limit' => 0, 'tables' => [], 'mapping' => null, 'out' => null];
    foreach (array_slice($GLOBALS['argv'], 1) as $a) {
        if ($a === '--execute') { $opt['execute'] = true; }
        elseif ($a === '--yes') { $opt['yes'] = true; }
        elseif (str_starts_with($a, '--limit=')) { $opt['limit'] = (int)substr($a, 8); }
        elseif (str_starts_with($a, '--mapping=')) { $opt['mapping'] = substr($a, 10); }
        elseif (str_starts_with($a, '--out=')) { $opt['out'] = substr($a, 6); }
        elseif (str_starts_with($a, '--table=')) {
            $opt['tables'] = array_values(array_filter(array_map('trim', explode(',', substr($a, 8)))));
        } elseif ($a === '--help' || $a === '-h') {
            dbm_usage($GLOBALS['argv'][0]);
            exit(0);
        } else {
            // 兼容 `--table x`（空格写法）
            if ($a === '--table') { $opt['_want_table'] = true; continue; }
            if (!empty($opt['_want_table'])) { $opt['tables'] = array_map('trim', explode(',', $a)); unset($opt['_want_table']); continue; }
            fwrite(STDERR, "未知参数: {$a}\n");
            dbm_usage($GLOBALS['argv'][0]);
            exit(1);
        }
    }
    return $opt;
}

function dbm_root(): string
{
    return dirname(__DIR__);                 // → 项目根
}

function dbm_path(string $rel): string
{
    return rtrim(dbm_root(), '/\\') . '/' . ltrim($rel, '/\\');
}

/** 读取 KEY=VALUE 形式的配置文件（不写回环境，避免污染） */
function dbm_parse_env(string $file): array
{
    if (!is_file($file)) {
        fwrite(STDERR, "找不到配置文件: {$file}\n");
        return [];
    }
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
    return $out;
}

// ---------------------------------------------------------------- 连接

/**
 * 目标库（新站）连接。
 *
 * 优先读 db_migrate/target.env（存在时用它），否则回退到 backend/.env。
 * 这样设计是为了「影子库试跑」：把 target.env 指向 shadow 库，
 * 就能在不碰正式库的情况下完整跑一遍迁移，跑通后删掉 target.env 即可切回正式库。
 */
function dbm_dst(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $override = dbm_path('db_migrate/target.env');
    $env = is_file($override) ? dbm_parse_env($override) : dbm_parse_env(dbm_path('backend/.env'));
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $k) {
        if (($env[$k] ?? '') === '') {
            fwrite(STDERR, "目标库配置缺少 {$k}（检查 backend/.env" . (is_file($override) ? ' 或 db_migrate/target.env' : '') . "）\n");
            exit(1);
        }
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'], $env['DB_PORT'], $env['DB_NAME']);
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // 目标库也要求严格模式，避免静默截断导致「看似成功实则丢数据」
    $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

/**
 * 源库（老站）连接 —— 强制只读。
 *
 * 推荐做法是先把老库 dump 成一个同实例的快照库（见 10_导出老库快照.sh），
 * 此时 source.env 指向 localhost 的快照库，老站生产库完全不接触。
 */
function dbm_src(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $env = dbm_parse_env(dbm_path('db_migrate/source.env'));
    if (empty($env)) {
        fwrite(STDERR, "缺少 db_migrate/source.env，请先复制 source.env.example 并填写老库信息。\n");
        exit(1);
    }
    $charset = $env['SRC_CHARSET'] ?? 'utf8mb4';
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $env['SRC_DB_HOST'] ?? '127.0.0.1',
        $env['SRC_DB_PORT'] ?? '3306',
        $env['SRC_DB_NAME'] ?? '',
        $charset
    );
    $pdo = new PDO($dsn, $env['SRC_DB_USER'] ?? '', $env['SRC_DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // ★ 双重保险：会话级只读。任何误写的 DML 都会被 MySQL 直接拒绝。
    try {
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    } catch (Throwable $e) {
        fwrite(STDERR, "警告：源库未能开启只读/一致性快照（{$e->getMessage()}）。\n");
        fwrite(STDERR, "      该 MySQL 版本可能过低，请自行确认老库账号本身是只读的。\n");
    }
    return $pdo;
}

// ---------------------------------------------------------------- 结构探测

/** 当前连接所在库名 */
function dbm_dbname(PDO $pdo): string
{
    return (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
}

/** 拉取某库的全部列定义，返回 [表名 => [列名 => 列信息]] */
function dbm_columns(PDO $pdo, ?string $db = null): array
{
    $db ??= dbm_dbname($pdo);
    $sql = 'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                   COLUMN_KEY, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, ORDINAL_POSITION
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :db
            ORDER BY TABLE_NAME, ORDINAL_POSITION';
    $st = $pdo->prepare($sql);
    $st->execute([':db' => $db]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['TABLE_NAME']][$r['COLUMN_NAME']] = $r;
    }
    return $out;
}

/** 拉取某库的表级信息，返回 [表名 => [ENGINE, TABLE_ROWS, TABLE_COLLATION, TABLE_COMMENT]] */
function dbm_tables(PDO $pdo, ?string $db = null): array
{
    $db ??= dbm_dbname($pdo);
    $sql = 'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, TABLE_COLLATION, TABLE_COMMENT
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = \'BASE TABLE\'';
    $st = $pdo->prepare($sql);
    $st->execute([':db' => $db]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['TABLE_NAME']] = $r;
    }
    return $out;
}

/** 取某表的主键列（单列时返回列名，复合/无主键返回 null） */
function dbm_pk(PDO $pdo, string $table, ?string $db = null): ?string
{
    $db ??= dbm_dbname($pdo);
    $sql = 'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND CONSTRAINT_NAME = \'PRIMARY\'
            ORDER BY ORDINAL_POSITION';
    $st = $pdo->prepare($sql);
    $st->execute([':db' => $db, ':t' => $table]);
    $cols = $st->fetchAll(PDO::FETCH_COLUMN);
    return count($cols) === 1 ? (string)$cols[0] : null;
}

/** 精确行数 */
function dbm_count(PDO $pdo, string $table): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
}

// ---------------------------------------------------------------- 输出

function dbm_hr(string $title = ''): void
{
    echo $title === '' ? str_repeat('-', 72) . PHP_EOL
        : PHP_EOL . '=== ' . $title . ' ' . str_repeat('=', max(0, 66 - mb_strlen($title))) . PHP_EOL;
}

function dbm_ok(string $m): void   { echo "  [OK]   {$m}" . PHP_EOL; }
function dbm_info(string $m): void { echo "  [--]   {$m}" . PHP_EOL; }
function dbm_warn(string $m): void { echo "  [警告] {$m}" . PHP_EOL; }
function dbm_err(string $m): void  { echo "  [错误] {$m}" . PHP_EOL; }

/** 二次确认，防止手滑在正式库上执行 */
function dbm_confirm(string $prompt): bool
{
    echo PHP_EOL . $prompt . ' 输入 yes 确认: ';
    $line = fgets(STDIN);
    return $line !== false && trim($line) === 'yes';
}
