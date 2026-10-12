#!/usr/bin/env bash
# ==================================================================
# db_migrate/10_导出老库快照.sh
# ------------------------------------------------------------------
# 在【老站所在服务器】(或任何能连到老库的机器) 上执行。
# 目的：把老库导出成一个 .sql 快照文件，之后所有操作都在快照上做，
#       老站生产库从此不再被触碰 —— 这是「不破坏原网站」最彻底的保证。
#
# 关键点：使用 --single-transaction，全程【不加表锁】，
#        导出期间老站照常读写，业务无感知。
#
# 用法：
#   1) 改下面 5 个变量
#   2) bash db_migrate/10_导出老库快照.sh
#   3) 把生成的 old_snapshot.sql.gz 传到新站服务器
# ==================================================================
set -euo pipefail

# ---------------- 需要你填写 ----------------
OLD_HOST="127.0.0.1"          # 老库地址（本机就写 127.0.0.1）
OLD_PORT="3306"
OLD_DB="old_db_name"          # ★ 老站数据库名
OLD_USER="dump_ro"            # ★ 建议用只读账号，见 00_老库只读账号.sql
OLD_PASS="在这里填只读账号口令"
OLD_CHARSET="utf8mb4"         # ★ 老库编码：utf8mb4 / utf8 / gbk / latin1
                              #   不确定就执行： SELECT DEFAULT_CHARACTER_SET_NAME
                              #   FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='老库名';
# -------------------------------------------

OUT_DIR="${OUT_DIR:-./old_snapshot_$(date +%Y%m%d_%H%M)}"
mkdir -p "$OUT_DIR"

echo "=================================================="
echo " 导出老库快照"
echo " 库: $OLD_DB @ $OLD_HOST:$OLD_PORT   编码: $OLD_CHARSET"
echo " 输出目录: $OUT_DIR"
echo "=================================================="

MYSQL_ARGS=(-h "$OLD_HOST" -P "$OLD_PORT" -u "$OLD_USER" "-p${OLD_PASS}")
DUMP_ARGS=(
  --single-transaction        # InnoDB 一致性快照，不加锁，老站无感
  --skip-lock-tables          # 不执行 LOCK TABLES
  --skip-add-locks            # dump 文件里也不写 LOCK 语句
  --no-tablespaces            # 避免需要 PROCESS 权限
  --default-character-set="$OLD_CHARSET"
  --hex-blob                  # 二进制列安全导出
  --routines --triggers       # 存储过程/触发器（若老站有）
  --set-gtid-purged=OFF       # 避免导入到新库时 GTID 冲突
  --column-statistics=0       # 兼容旧版 mysqldump 客户端
)

# ---- 1) 数据 + 结构（主文件）
echo "[1/3] 导出结构与数据（可能较慢，请耐心）..."
if ! mysqldump "${DUMP_ARGS[@]}" "${MYSQL_ARGS[@]}" "$OLD_DB" > "$OUT_DIR/old_snapshot.sql" 2>"$OUT_DIR/dump.err"; then
  if grep -q "Unknown variable.*column-statistics" "$OUT_DIR/dump.err"; then
    echo "      mysqldump 不支持 --column-statistics，去掉后重试..."
    DUMP_ARGS=("${DUMP_ARGS[@]/--column-statistics=0/}")
    mysqldump "${DUMP_ARGS[@]}" "${MYSQL_ARGS[@]}" "$OLD_DB" > "$OUT_DIR/old_snapshot.sql"
  else
    echo "★ 导出失败："; cat "$OUT_DIR/dump.err"; exit 1
  fi
fi
echo "      完成: $(du -h "$OUT_DIR/old_snapshot.sql" | cut -f1)"

# ---- 2) 只要结构（给「结构对比」用，体积小、传得快）
echo "[2/3] 导出纯结构..."
mysqldump "${DUMP_ARGS[@]}" --no-data "${MYSQL_ARGS[@]}" "$OLD_DB" > "$OUT_DIR/old_schema_only.sql"
echo "      完成: $(du -h "$OUT_DIR/old_schema_only.sql" | cut -f1)"

# ---- 3) 导出每张表的行数清单（对账基准，很重要！）
echo "[3/3] 导出各表行数清单..."
mysql "${MYSQL_ARGS[@]}" -N -B -e "
  SELECT TABLE_NAME, TABLE_ROWS, ENGINE, TABLE_COLLATION
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA='$OLD_DB' AND TABLE_TYPE='BASE TABLE'
  ORDER BY TABLE_NAME;" > "$OUT_DIR/old_table_rows.tsv"
echo "      完成: $(wc -l < "$OUT_DIR/old_table_rows.tsv") 张表"

gzip -f "$OUT_DIR/old_snapshot.sql"

cat <<EOF

==================================================
 ✔ 导出完成
==================================================
 输出文件：
   $OUT_DIR/old_snapshot.sql.gz   ← 数据+结构（传到新站服务器）
   $OUT_DIR/old_schema_only.sql   ← 纯结构（本地做对比用）
   $OUT_DIR/old_table_rows.tsv    ← 行数基准（对账用，务必保留）

 下一步（在新站服务器上）：
   1. 上传 old_snapshot.sql.gz
   2. 建快照库并导入：
        mysql -u root -p -e "CREATE DATABASE old_snapshot \\
          CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
        gunzip -c old_snapshot.sql.gz | mysql -u root -p old_snapshot
   3. 把 db_migrate/source.env 指向 old_snapshot（本机，不是老站！）
   4. php db_migrate/20_结构对比.php
EOF
