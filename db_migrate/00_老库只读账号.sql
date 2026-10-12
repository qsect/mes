-- ==================================================================
-- db_migrate/00_老库只读账号.sql
-- ------------------------------------------------------------------
-- 在【老站数据库】上执行一次，创建一个只能读、不能改的迁移专用账号。
--
-- 为什么必须做：这是「不破坏原网站数据」的第一道、也是最硬的一道防线。
--   即使迁移脚本写错了，MySQL 也会直接拒绝 —— 从权限层面杜绝误写。
--
-- 执行者：老站 DBA 或运维（需要 CREATE USER 权限）
-- ==================================================================

-- ------------------------------------------------------------------
-- 方式 A（推荐）：只读账号 + 只授予 SELECT
-- ------------------------------------------------------------------
CREATE USER 'dump_ro'@'%' IDENTIFIED BY '请换成一个独立的强口令';
GRANT SELECT, SHOW VIEW, PROCESS, RELOAD, REPLICATION CLIENT ON *.* TO 'dump_ro'@'%';
-- 说明：
--   SELECT            → 读数据
--   SHOW VIEW         → 导出视图定义
--   PROCESS           → mysqldump 需要（老版本）
--   RELOAD / REPLICATION CLIENT → 支持 --single-transaction 的一致性快照
-- 注意：这里【没有】INSERT/UPDATE/DELETE/DROP/ALTER —— 它物理上改不了数据。
FLUSH PRIVILEGES;

-- 更保守的写法：把 '%' 换成新站服务器的具体 IP，例如 'dump_ro'@'10.0.0.25'
-- CREATE USER 'dump_ro'@'10.0.0.25' IDENTIFIED BY '...';


-- ------------------------------------------------------------------
-- 方式 B（MySQL 8.0+ 更严格）：显式声明只读角色
-- ------------------------------------------------------------------
-- CREATE ROLE 'migration_readonly';
-- GRANT SELECT, SHOW VIEW, PROCESS ON *.* TO 'migration_readonly';
-- CREATE USER 'dump_ro'@'%' IDENTIFIED BY '请换成强口令';
-- GRANT 'migration_readonly' TO 'dump_ro'@'%';
-- SET DEFAULT ROLE 'migration_readonly' TO 'dump_ro'@'%';
-- FLUSH PRIVILEGES;


-- ------------------------------------------------------------------
-- 验证：用只读账号登录后，故意试一次写操作，应当被拒绝
-- ------------------------------------------------------------------
-- mysql -h 老库地址 -u dump_ro -p 老库名
--   mysql> CREATE TABLE _write_test(id int);
--   ERROR 1142 (42000): CREATE command denied to user 'dump_ro'@'...'
--   ↑ 出现这个报错，说明防线生效了。


-- ------------------------------------------------------------------
-- 附：查看老库编码（决定 source.env 里的 SRC_CHARSET 填什么）
-- ------------------------------------------------------------------
-- SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
-- FROM information_schema.SCHEMATA
-- WHERE SCHEMA_NAME = '你的老库名';


-- ------------------------------------------------------------------
-- 附：如果采用「导出快照」的方案（推荐），导出完成后
--      可以随时回收这个账号，进一步收紧：
-- ------------------------------------------------------------------
-- DROP USER 'dump_ro'@'%';
