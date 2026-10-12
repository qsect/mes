# db_migrate —— 老库 → 新库 移植工具集

一套**只读老库、只写数据不改结构**的迁移工具。所有脚本默认 `dry-run`，看不到问题不会落库。

> 完整操作流程与风险说明见项目根目录的 **`数据库移植方案.md`**。
> 本文档只讲工具怎么用。

---

## 一、文件清单

| 文件 | 在哪跑 | 作用 |
| --- | --- | --- |
| `00_老库只读账号.sql` | 老库 | 建只读账号（第一道防线，强烈建议） |
| `10_导出老库快照.sh` | 老站服务器 | 无锁导出老库 → `.sql` 快照 + 行数基准 |
| `source.env.example` | — | 源库连接配置模板 |
| `mapping.example.json` | — | 字段映射配置模板 |
| `_common.php` | — | 公共库（连接、结构探测），被下面三个引用 |
| `20_结构对比.php` | 新站服务器 | 对比两端结构 → 生成差异报告 Markdown |
| `30_迁移.php` | 新站服务器 | 按 mapping 搬数据（默认试跑） |
| `40_校验.php` | 新站服务器 | 行数对账 + 主键覆盖 + 抽样逐列比对 |

---

## 二、命令速查

```bash
# 0) 准备配置（含口令，权限收紧）
cp db_migrate/source.env.example db_migrate/source.env
chmod 600 db_migrate/source.env
cp db_migrate/mapping.example.json db_migrate/mapping.json

# 1) 结构对比 —— 纯只读，先跑这个
php db_migrate/20_结构对比.php
# → 生成 db_migrate/结构差异报告.md

# 2) 试跑（不写库），指定表更聚焦
php db_migrate/30_迁移.php
php db_migrate/30_迁移.php --table=users,tab_job
php db_migrate/30_迁移.php --limit=100        # 每表只试 100 行

# 3) 真正写入（有二次确认；先在影子库上做！）
php db_migrate/30_迁移.php --execute

# 4) 对账
php db_migrate/40_校验.php
php db_migrate/40_校验.php --table=users --limit=50
```

`30_迁移.php` 常用参数：

| 参数 | 说明 |
| --- | --- |
| `--execute` | 真正写库；不加则只试跑 |
| `--table=a,b` | 只处理指定源表名或目标表名 |
| `--limit=N` | 每表最多 N 行（试跑用） |
| `--mapping=路径` | 指定映射文件 |
| `--yes` | 跳过二次确认（谨慎） |

---

## 三、安全设计（为什么不会破坏老站 / 不会改新站结构）

1. **源库强制只读**：`_common.php` 连上后立即执行 `SET SESSION TRANSACTION READ ONLY`，
   并开启一致性快照事务。脚本里只有 `SELECT`，不存在任何写语句。
2. **建议再叠加账号级只读**：用 `00_老库只读账号.sql` 建的账号连老库，即便脚本有 bug 也写不进去。
3. **推荐走快照，彻底隔离**：用 `10_导出老库快照.sh` 把老库 dump 成一个库，
   之后工具连的是快照库而不是老站生产库 —— 老站从物理上不可能被影响。
4. **目标库只做 DML**：整个工具集里没有任何 `CREATE TABLE` / `ALTER TABLE` / `DROP`。
   `target` 表必须已存在，否则直接报错跳过（不会替你建表）。
5. **默认 dry-run**：不加 `--execute` 绝不写入；加了还要手动输入 `yes` 确认。

---

## 四、mapping.json 速查

```jsonc
{
  "source_charset": "utf8mb4",     // 全局老库编码
  "tables": [
    {
      "source": "老表名",
      "target": "新表名",
      "mode": "insert_ignore",     // insert_ignore | upsert | replace | insert
      "where": "is_deleted=0",     // 可选，只搬部分数据
      "source_charset": "gbk",     // 可选，本表单独指定编码
      "batch_size": 200,           // 可选
      "columns": [
        { "from": "老列", "to": "新列" },
        { "from": "状态列", "to": "status",
          "map": {"1":"active","0":"disabled"}, "map_default":"disabled" },
        { "from": "int时间戳", "to": "created_at", "transform": "ts_to_datetime" },
        { "from": "", "to": "补的列", "const": "{{NOW}}" }
      ],
      "after": { "reset_auto_increment": true }
    }
  ]
}
```

**可选 `transform`**：
`trim` `int` `float` `string` `upper` `lower` `strip_tags` `bool01`
`null_if_zero` `null_if_empty` `ts_to_datetime` `ts_to_date` `ms_to_datetime`
`date_normalize` `zero_date_to_null`

**常量占位符**：`{{NOW}}` `{{DATE}}` `{{YEAR}}` `{{UUID}}` `{{NULL}}`

**`mode` 怎么选**：

| mode | 重复键时 | 适用场景 |
| --- | --- | --- |
| `insert_ignore` | 跳过 | **首选**，幂等，可反复跑 |
| `upsert` | 更新该行所有非主键列 | 老库数据还会变、需要同步 |
| `replace` | 整行替换（未提供的列会变默认值/NULL） | 谨慎使用 |
| `insert` | 报错中断 | 确认目标表为空时 |

---

## 五、依赖

- PHP 8.0+（服务器已满足）与 `pdo_mysql`、`mbstring` 扩展
- 运行 `20/30/40` 的机器需要能同时连到源库与目标库；
  若走快照方案，两者通常都在本机，最省事
- `10_导出老库快照.sh` 需要老站服务器上的 `mysqldump` / `mysql` 客户端

---

## 六、收尾（务必执行）

```bash
# 迁移确认无误后，删除含口令的配置文件
shred -u db_migrate/source.env 2>/dev/null || rm -f db_migrate/source.env

# 快照库确认不再需要后删除
mysql -u root -p -e "DROP DATABASE old_snapshot"

# 回收老库的只读账号
# DROP USER 'dump_ro'@'%';
```
