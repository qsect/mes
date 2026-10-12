-- ==================================================================
-- 客户端版本验证与推送 · 建表 SQL
-- ------------------------------------------------------------------
-- 说明：
--   服务端 ClientVersionController 会自动建表（幂等），本 SQL 仅作
--   手动兜底 / 首次初始化用。如果你希望预置一条与当前客户端一致的
--   版本记录（v2.1，非强制），执行文末的 INSERT。
--
-- 生效规则：
--   client_versions 中 is_published = 1 且版本号最大的一条即为
--   「当前最新版本」，客户端 check_version 会拿它与自身版本比对。
--
-- 【多客户端支持】
--   client_versions.client_key 用于区分不同客户端的版本线：
--     · impedance_recorder → 阻抗测试客户端
--     · barcode_validator  → 条码校验客户端
--     · trace_scanner      → 追溯扫描客户端
--     · label_print        → 标签打印客户端
--   每个 client_key 各自独立比较版本，互不影响。
--   客户端侧在 Config.h 的 CLIENT_KEY 定义，新客户端只改这一处即可；
--   服务端侧在 ClientVersionController::KNOWN_CLIENTS 中补登记（用于
--   管理页下拉提示）。未预置的自定义 key 也能正常使用。
-- ==================================================================

CREATE TABLE IF NOT EXISTS `client_versions` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `client_key`   VARCHAR(64)  NOT NULL DEFAULT 'impedance_recorder' COMMENT '客户端标识',
  `version`      VARCHAR(32)  NOT NULL COMMENT '版本号，如 2.2',
  `min_version`  VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '低于此版本的客户端强制更新',
  `force_update` TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '1=强制更新',
  `download_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '新版下载地址',
  `update_log`   TEXT         NULL COMMENT '更新说明',
  `is_published` TINYINT(1)   NOT NULL DEFAULT 1  COMMENT '1=已发布生效',
  `created_by`   VARCHAR(100) NOT NULL DEFAULT '',
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_client_version` (`client_key`, `is_published`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------
-- 预置：当前线上版本 v2.1（与现有客户端一致，非强制）
-- 若表中已有记录可跳过。
-- ------------------------------------------------------------------
INSERT INTO `client_versions`
  (`client_key`, `version`, `min_version`, `force_update`, `download_url`, `update_log`, `is_published`, `created_by`)
SELECT 'impedance_recorder', '2.1', '', 0, '', '初始版本', 1, 'system'
WHERE NOT EXISTS (
  SELECT 1 FROM `client_versions` WHERE `client_key` = 'impedance_recorder'
);

-- ==================================================================
-- 【发布新版本时怎么用】
-- ------------------------------------------------------------------
-- 场景 1：发布 v2.2，不强制（用户可跳过）
--   INSERT INTO client_versions (client_key, version, force_update, download_url, update_log)
--   VALUES ('impedance_recorder', '2.2', 0, 'https://你的域名/download/imp-v2.2.zip', '修复XX问题');
--
-- 场景 2：发布 v2.3，强制更新（所有低于此版本的客户端必须更新）
--   INSERT INTO client_versions (client_key, version, force_update, download_url, update_log)
--   VALUES ('impedance_recorder', '2.3', 1, 'https://你的域名/download/imp-v2.3.zip', '重要安全修复，必须更新');
--
-- 场景 3：不做强制字段，只抬「最低支持版本」（所有 < 2.5 的客户端强制）
--   UPDATE client_versions SET min_version = '2.5'
--   WHERE client_key = 'impedance_recorder' AND version = '2.5';
--
-- 也可直接通过管理接口发布（推荐，会写审计日志）：
--   POST /api/client/save_version
--   { "version":"2.3", "force_update":1, "download_url":"https://...", "update_log":"..." }
--   （需管理员登录，见 frontend 版本管理页）
-- ==================================================================


-- ==================================================================
-- 【重要】登记「客户端版本」菜单模块
-- ------------------------------------------------------------------
-- 为什么必须做：
--   前端侧边栏菜单严格以 /api/auth/me 返回的 menus 为准（fail-closed）。
--   而 menus 是从 modules 表查出来的。若不在 modules 表登记本页面，
--   即使路由和页面文件都存在，菜单也**不会显示**，直接访问 URL 也会被
--   路由守卫拦回首页。
--
-- 执行方式二选一：
--   A. 执行下面这段 SQL（推荐，幂等）
--   B. 登录 web → 系统管理 → 模块管理 → 新增模块，填写：
--        模块名称：客户端版本
--        模块代码：client_version
--        路由地址：system/client-version
--        所属分类：系统管理
--        图标：Upload      排序：99
--      保存后，再让管理员到「权限分配」给需要的人勾上"查看"。
--
-- 注意：管理员（is_admin=1）无需授权即可看到全部模块；
--       普通用户必须额外在「权限分配」里勾选该模块的"查看"。
-- ==================================================================

INSERT INTO `modules` (`name`, `module_code`, `category`, `icon`, `route`, `sort_order`, `description`, `status`)
SELECT '客户端版本', 'client_version', '系统管理', 'Upload', 'system/client-version', 99, '阻抗测试客户端版本发布与强制更新管控', 1
WHERE NOT EXISTS (
  SELECT 1 FROM `modules` WHERE `module_code` = 'client_version'
);

-- 若你的 modules 表可见性字段不是 status 而是 is_visible，
-- 请把上面 INSERT 的最后两个值改为：..., 1, 1)；并把字段名 status 换成 is_visible。
-- （AuthController 会自动探测可见性字段，两种结构都兼容。）

