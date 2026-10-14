#!/bin/bash
# ─────────────────────────────────────────────────────────────
# 部署校验：确认「系统安全加固」批次文件已正确就位（按内容特征，而非文件日期）
#
# 用法（服务器上）：
#   bash /www/wwwroot/vite/backend/scripts/verify_deploy.sh
#   或指定项目根：
#   bash verify_deploy.sh /www/wwwroot/vite
# ─────────────────────────────────────────────────────────────

BASE="${1:-/www/wwwroot/vite}"
B="$BASE/backend"
F="$BASE/frontend"
pass=0
fail=0
warn=0

chk() {  # chk <文件> [应含特征]
    if [ ! -f "$1" ]; then echo "  [缺失]   $1"; fail=$((fail+1)); return; fi
    if [ -n "$2" ] && ! grep -q "$2" "$1" 2>/dev/null; then
        echo "  [未更新] $1   (缺特征: $2)"; fail=$((fail+1)); return
    fi
    echo "  [OK]     $1"; pass=$((pass+1))
}

neg() {  # neg <文件> <不应再有的特征>
    if [ -f "$1" ] && grep -q "$2" "$1" 2>/dev/null; then
        echo "  [警告]   $1   仍含旧特征: $2"; fail=$((fail+1)); return
    fi
    echo "  [OK]     $1"; pass=$((pass+1))
}

rmf() {  # rmf <应已删除的文件>
    if [ -e "$1" ]; then echo "  [残留]   $1"; fail=$((fail+1)); else echo "  [已删除] $1"; pass=$((pass+1)); fi
}

neg_r() {  # neg_r <目录> <不应再共现的特征A> <特征B>
    if grep -rn "$2" "$1" 2>/dev/null | grep -q "$3"; then
        echo "  [警告]   $1 仍存在 \"$2 ... $3\" 回显残留"; fail=$((fail+1)); return
    fi
    echo "  [OK]     $1 无 \"$2 ... $3\" 残留"; pass=$((pass+1))
}

echo "=================================================="
echo " 项目根: $BASE"
echo "=================================================="

echo ""
echo "── 一、新增文件（6）──"
chk "$B/app/Core/CemConfig.php"                        "class CemConfig"
chk "$B/config/cem.local.php"                          "CEM_HOST"
chk "$B/app/Core/PasswordPolicy.php"                   "needsRehash"
chk "$B/app/Core/Schema.php"                           "ensureLoginAttempts"
chk "$F/src/utils/sanitizeHtml.js"                     "DOMParser"
chk "$BASE/.gitignore"                                 "node_modules"

echo ""
echo "── 二、后端核心修改（9）──"
chk "$B/public/index.php"                              "excelSafe"
chk "$B/public/index.php"                              "users/delete"
chk "$B/public/index.php"                              "request_id"
chk "$B/public/index.php"                              "CORS_ORIGINS"
chk "$B/public/index.php"                              "samesite"
chk "$B/app/Controllers/DispatchKittingController.php" "__construct"
chk "$B/app/Controllers/DiscussController.php"         "sanitizeHtml"
chk "$B/app/Controllers/SystemController.php"          "deleteUser"
chk "$B/app/Controllers/AuthController.php"            "session_regenerate_id"
chk "$B/app/Controllers/AuthController.php"            "needsRehash"
chk "$B/includes/CemClient.php"                        "function cemUser"
chk "$B/app/cem/index.php"                             "CemConfig"
chk "$B/app/legacy_trace.php"                          "CemConfig::host()"

echo ""
echo "── 三、导出控制器 excelSafe（15）──"
for x in Assets Attendance BarcodeValidator Beibian Diary DocError FirstPiece Impedance Mac MacQuery Oee Osm Quality Troubleshoot Weekly; do
    chk "$B/app/Controllers/${x}Controller.php" "excelSafe"
done

echo ""
echo "── 四、前端（源码，5）──"
chk "$F/src/utils/sanitizeHtml.js"                     "DOMParser"
chk "$F/src/main.js"                                   "v-safe-html"
chk "$F/src/views/Dashboard.vue"                       "v-safe-html"
chk "$F/src/views/discuz/Index.vue"                    "v-safe-html"
chk "$F/src/views/quality_trace/Index.vue"             "v-safe-html"

echo ""
echo "── 五、M5 异常回显脱敏 ──"
chk "$B/app/Core/Response.php"                          "function exception"
chk "$B/app/Controllers/AssetsController.php"           "Response::exception"
chk "$B/app/Controllers/DispatchKittingController.php"  "Response::exception"
chk "$B/app/Controllers/SystemController.php"           "Response::exception"
neg_r "$B/app/Controllers" "Response::error" "getMessage"
neg_r "$B/app/Controllers" "sendJson(false" "getMessage"

echo ""
echo "── 六、旧特征应已消失 ──"
neg "$B/includes/CemClient.php"                        "const CEM_USER"

echo ""
echo "── 七、残留文件应已删除（4）──"
rmf "$B/test.php"
rmf "$B/diagnose_admin.php"
rmf "$B/app/Services/AuthService.php"
rmf "$B/includes/CemClient-副本.php"

echo ""
echo "── 八、技术债 L5/L6/L7 ──"
chk "$B/app/Core/RateLimiter.php"                      "function guard"
chk "$B/app/Core/Schema.php"                           "function ensureOnce"
chk "$B/public/index.php"                              "RateLimiter::guard"
chk "$B/app/Controllers/ImpedanceController.php"       "Schema::ensureOnce"
chk "$B/app/Controllers/SystemController.php"          "isReady('perm_user')"
chk "$F/src/stores/auth.js"                            "isLoggedIn"
neg "$F/src/api/request.js"                            "X-Session-Id"
neg "$F/src/stores/auth.js"                            "setItem('token'"

echo ""
echo "── 九、PHP 语法检查（php -l，拦截残缺/损坏文件）──"
PHP_BIN="${PHP_BIN:-/www/server/php/82/bin/php}"
if [ -x "$PHP_BIN" ]; then
    synerr=0
    while IFS= read -r f; do
        if ! "$PHP_BIN" -l "$f" >/dev/null 2>&1; then
            echo "  [语法错误] $f"
            "$PHP_BIN" -l "$f" 2>&1 | head -1 | sed 's/^/             /'
            synerr=$((synerr+1)); fail=$((fail+1))
        fi
    done < <(find "$B/app" "$B/public" "$B/includes" -name "*.php" 2>/dev/null)
    if [ "$synerr" -eq 0 ]; then
        echo "  [OK]     全部 PHP 文件语法正确"; pass=$((pass+1))
    else
        echo "  → 共 $synerr 个文件存在语法错误（如 legacy_trace.php 残缺导致 trace 全模块 500）"
    fi
else
    echo "  [跳过]   未找到 php CLI（$PHP_BIN），可用 PHP_BIN=/path/to/php 指定后重跑"
fi

echo ""
echo "── 十、legacy_trace.php 完整性（防止再次被残缺副本覆盖）──"
LT="$B/app/legacy_trace.php"
if [ -f "$LT" ]; then
    ltlines=$(wc -l < "$LT" | tr -d ' ')
    if [ "$ltlines" -lt 1000 ]; then
        echo "  [残缺]   legacy_trace.php 仅 $ltlines 行（完整版应为 ~1245 行），追溯写入接口会 ParseError→500"
        fail=$((fail+1))
    else
        echo "  [OK]     行数 $ltlines"; pass=$((pass+1))
    fi
    ltmiss=0
    for a in check_or_fetch_erp save_config start_trace scan_barcode update_scan_record delete_scan_record manual_push_cem export_excel; do
        if ! grep -q "action === '$a'" "$LT"; then
            echo "  [缺失]   分支 $a"; ltmiss=$((ltmiss+1)); fail=$((fail+1))
        fi
    done
    if [ "$ltmiss" -eq 0 ]; then
        echo "  [OK]     8 个 action 分支齐全"; pass=$((pass+1))
    fi
    for fn in trBoxPointScanned trCountCompletedBoxes trIsBoxFullyCompleted trRefreshBoxStatus jsonBody; do
        grep -q "$fn" "$LT" || { echo "  [警告]   缺少辅助定义 $fn"; fail=$((fail+1)); }
    done
else
    echo "  [缺失]   $LT"; fail=$((fail+1))
fi

echo ""
echo "── 十一、凭据安全（源码内不得残留真实口令）──"
# 口令值现场取自 .env 与 config/cem.local.php（不读 .env.example）——
# 本脚本自身不持有任何口令字面量，口令轮换后无需改脚本。
SECRET_VALS=$( {
    [ -f "$B/.env" ] && grep -hE '^[A-Za-z_]*(PASS|PASSWORD|SECRET)=' "$B/.env" 2>/dev/null | cut -d= -f2-
    [ -f "$B/config/cem.local.php" ] && grep -oE "'[A-Za-z_]*PASS[A-Za-z_]*'[[:space:]]*=>[[:space:]]*'[^']*'" "$B/config/cem.local.php" 2>/dev/null | sed "s/.*=>[[:space:]]*'//; s/'[[:space:]]*$//"
} 2>/dev/null | sed 's/^[[:space:]]*//; s/[[:space:]]*$//; s/^"//; s/"$//' | sed '/^$/d' )
PAT=""
while IFS= read -r v; do
    [ ${#v} -lt 6 ] && continue                                  # 太短的值不参与回扫
    case "$v" in your_*|change_this*|placeholder*|example*|xxx*|'<'*) continue ;; esac   # 占位符跳过
    esc=$(printf '%s' "$v" | sed 's/[.^$*+?()\[\]{}\\|]/\\&/g')
    PAT="${PAT:+$PAT|}$esc"
done <<< "$SECRET_VALS"

if [ -z "$PAT" ]; then
    echo "  [跳过]   未从 .env / cem.local.php 读到口令，跳过源码回扫"; warn=$((warn+1))
else
    CRED_HITS=$(grep -rEn "$PAT" "$B/app" "$B/public" "$B/includes" "$B/cron" 2>/dev/null | wc -l | tr -d ' ')
    if [ "$CRED_HITS" -eq 0 ]; then
        echo "  [OK]     源码内无真实口令（已回扫 app/public/includes/cron）"; pass=$((pass+1))
    else
        echo "  [残留]   发现 $CRED_HITS 处真实口令（源码或备份泄露即凭据泄露）："
        # 只列文件，不回显匹配行，避免把口令又打印到终端
        grep -rEln "$PAT" "$B/app" "$B/public" "$B/includes" "$B/cron" 2>/dev/null | sed 's/^/           /'
        fail=$((fail+1))
    fi
    # 示例文件绝不允许出现真实口令（常被误提交到版本库/随源码发放）
    if [ -f "$B/.env.example" ]; then
        EX_N=$(grep -Ec "$PAT" "$B/.env.example" 2>/dev/null | tr -d ' ')
        case "$EX_N" in ''|*[!0-9]*) EX_N=0 ;; esac
        if [ "$EX_N" -gt 0 ]; then
            echo "  [残留]   .env.example 含真实口令 $EX_N 处，必须改为占位符（行号：$(grep -En "$PAT" "$B/.env.example" 2>/dev/null | cut -d: -f1 | tr '\n' ' '))"
            fail=$((fail+1))
        else
            echo "  [OK]     .env.example 仅含占位符"; pass=$((pass+1))
        fi
    fi
fi

# 备份残留：*.bak、*.bak.<时间戳>、*.orig、*~、*副本* 等
# （注意：*.bak 匹配不到 legacy_trace.php.bak.20261010142843 这类带时间戳的名字，故用 *.bak.*）
BAK_LIST=$(find "$BASE" \( -name node_modules -o -name vendor -o -name .git -o -name dist \) -prune -o \
    -type f \( -name "*.bak" -o -name "*.bak.*" -o -name "*.orig" -o -name "*.orig.*" \
                -o -name "*.save" -o -name "*.save.*" -o -name "*.old" -o -name "*.copy*" \
                -o -name "*~" -o -name "*.swp" -o -name "*副本*" -o -name "*备份*" \) -print 2>/dev/null)
BAK_HITS=$(printf '%s\n' "$BAK_LIST" | grep -c '[^[:space:]]')
if [ "$BAK_HITS" -eq 0 ]; then
    echo "  [OK]     项目内无 .bak/.orig/副本 备份残留"; pass=$((pass+1))
else
    BAK_CRED=0
    if [ -n "$PAT" ]; then
        while IFS= read -r bf; do
            if [ -n "$bf" ] && grep -Eq "$PAT" "$bf" 2>/dev/null; then BAK_CRED=$((BAK_CRED+1)); fi
        done <<< "$BAK_LIST"
    fi
    if [ "$BAK_CRED" -gt 0 ]; then
        echo "  [残留]   发现 $BAK_HITS 个备份文件，其中 $BAK_CRED 个含真实口令，必须删除："
        fail=$((fail+1))
    else
        echo "  [警告]   发现 $BAK_HITS 个备份文件（虽未检出真实口令，但会随目录同步一起部署，建议移出项目）："
        warn=$((warn+1))
    fi
    printf '%s\n' "$BAK_LIST" | sed 's/^/           /'
fi
for nf in app/Core/Env.php app/Core/ExternalDb.php; do
    if [ -f "$B/$nf" ]; then
        echo "  [OK]     $nf"; pass=$((pass+1))
    else
        echo "  [缺失]   $nf"; fail=$((fail+1))
    fi
done
if [ -f "$B/.env" ]; then
    for k in DB_PASS SQLSRV_PASS MES_DB_PASS; do
        if grep -q "^$k=.\+" "$B/.env"; then
            echo "  [OK]     .env 已配置 $k"; pass=$((pass+1))
        else
            echo "  [缺失]   .env 未配置 $k（源码已无兜底口令，缺失将导致对应数据源不可用）"; fail=$((fail+1))
        fi
    done
else
    echo "  [警告]   未找到 $B/.env"; fail=$((fail+1))
fi

echo ""
echo "── 十二、模块维护权限标记（前端按钮级隐藏）──"
for pair in "EquipmentController:equipment" "WeeklyController:weekly" "AttendanceController:attendance" "FirstPieceController:first_piece"; do
    ctl="${pair%%:*}"; mod="${pair##*:}"
    f="$B/app/Controllers/$ctl.php"
    if [ -f "$f" ] && grep -q "can_manage" "$f" && grep -q "ModulePerm::canEdit" "$f"; then
        echo "  [OK]     $ctl 已返回 can_manage（模块 $mod）"; pass=$((pass+1))
    else
        echo "  [未更新] $ctl 缺少 can_manage / ModulePerm 判定"; fail=$((fail+1))
    fi
done

echo ""
echo "── 十三、审计日志覆盖（写操作留痕）──"
AUDIT_MISS=0
for ctl in TraceController ImpedanceController QualityController MacController AssetsController BarcodeValidatorController DiaryController DiscussController ManhourController OeeController SkillController LabelPrintController BeibianController DispatchKittingController; do
    f="$B/app/Controllers/$ctl.php"
    if [ -f "$f" ] && grep -q "AuditService::log" "$f"; then
        :
    else
        echo "  [未接入] $ctl 无 AuditService::log"; AUDIT_MISS=$((AUDIT_MISS+1)); fail=$((fail+1))
    fi
done
if [ "$AUDIT_MISS" -eq 0 ]; then
    echo "  [OK]     重点模块写操作均已接入审计日志"; pass=$((pass+1))
fi
grep -q "AuditService::log" "$B/app/legacy_trace.php" 2>/dev/null \
    && { echo "  [OK]     legacy_trace.php 已接入审计日志"; pass=$((pass+1)); } \
    || { echo "  [未接入] legacy_trace.php 无 AuditService::log"; fail=$((fail+1)); }

# 十四、前端产物
echo ""
echo "── 十四、前端构建产物（按钮级隐藏已生效）──"
DIST="$BASE/frontend/dist"
if [ -d "$DIST" ]; then
    if grep -rq "can_manage" "$DIST/assets" 2>/dev/null; then
        echo "  [OK]     dist 产物含 can_manage 权限标记"; pass=$((pass+1))
    else
        echo "  [未重建] dist 产物未见 can_manage，请 rm -rf dist/assets && npm run build"; fail=$((fail+1))
    fi
    MAIN=$(grep -o 'assets/index-[A-Za-z0-9_-]*\.js' "$DIST/index.html" 2>/dev/null | head -1)
    if [ -n "$MAIN" ] && grep -q 'localStorage.getItem("token")' "$DIST/$MAIN" 2>/dev/null; then
        echo "  [警告]   主包仍含 localStorage token 逻辑（L7 未生效）"; fail=$((fail+1))
    else
        echo "  [OK]     主包无 localStorage token 逻辑"; pass=$((pass+1))
    fi
else
    echo "  [跳过]   未找到 $DIST（服务器上通常只部署产物，可忽略）"
fi

# 十五、代码清理（2026-10-12 批次）
echo ""
echo "── 十五、代码清理（死代码 / 垃圾 / 可移植性）──"
rmf "$B/app/Middlewares/CorsMiddleware.php"
rmf "$B/config/app.php"
for d in "$B/database" "$B/datebase"; do
    if [ -d "$d" ]; then
        echo "  [残留]   $d 应已删除（空目录，datebase 还是拼写错误）"; fail=$((fail+1))
    else
        echo "  [已删除] $d"; pass=$((pass+1))
    fi
done

# 可移植性：源码里不应再出现「非注释的 /www/wwwroot」绝对路径
ABS=$(grep -rn "/www/wwwroot" "$B/app" "$B/includes" --include="*.php" 2>/dev/null \
      | grep -vE ":[0-9]+:[[:space:]]*//" | grep -vE ":[0-9]+:[[:space:]]*#")
if [ -n "$ABS" ]; then
    echo "  [警告]   源码仍有硬编码绝对路径（非注释），换服务器路径即失效："
    printf '%s\n' "$ABS" | head -5 | sed 's/^/           /'; fail=$((fail+1))
else
    echo "  [OK]     源码无硬编码绝对路径（/www/wwwroot 仅存于注释）"; pass=$((pass+1))
fi
chk "$B/app/legacy_trace.php" "SCRIPT_FILENAME"

# 十六、单元测试（零依赖，直接跑源码）
echo ""
echo "── 十六、单元测试 ──"
PHP_BIN="${PHP_BIN:-/www/server/php/82/bin/php}"
if [ -f "$B/tests/run.php" ] && [ -x "$PHP_BIN" ]; then
    TOUT=$("$PHP_BIN" "$B/tests/run.php" 2>&1)
    if [ $? -eq 0 ]; then
        echo "  [OK]     后端单元测试全通过（$(printf '%s' "$TOUT" | grep -o '通过 [0-9]* 项' | head -1)）"; pass=$((pass+1))
    else
        echo "  [失败]   后端单元测试未通过："
        printf '%s\n' "$TOUT" | tail -12 | sed 's/^/           /'; fail=$((fail+1))
    fi
else
    echo "  [跳过]   tests/run.php 缺失或 PHP 不可用（PHP_BIN=$PHP_BIN）"
fi

if [ -f "$F/tests/richText.test.mjs" ] && command -v node >/dev/null 2>&1; then
    NOUT=$(node "$F/tests/richText.test.mjs" 2>&1)
    if [ $? -eq 0 ]; then
        echo "  [OK]     前端工具测试全通过（$(printf '%s' "$NOUT" | grep -o '通过 [0-9]* 项' | head -1)）"; pass=$((pass+1))
    else
        echo "  [失败]   前端测试未通过："
        printf '%s\n' "$NOUT" | tail -12 | sed 's/^/           /'; fail=$((fail+1))
    fi
else
    echo "  [跳过]   node 不在 PATH 或 frontend/tests 缺失（前端测试可忽略）"
fi

echo ""
echo "── 十七、客户端免登录下载（更新包）──"
# 客户端在「登录前」用系统浏览器打开 download_url，必须走免鉴权端点，
# 落到 /api/files/download 或 /backend/ 静态路径都会表现为「提示需要登录」。
if grep -q '"/api/client/download"' "$B/public/index.php"; then
    echo "  [OK]     /api/client/download 已注册为公开路由"; pass=$((pass+1))
else
    echo "  [缺失]   /api/client/download 未注册 → 客户端登录前下载会提示需要登录"; fail=$((fail+1))
fi
if grep -q "public function download" "$B/app/Controllers/ClientVersionController.php"; then
    echo "  [OK]     ClientVersionController::download() 已就位"; pass=$((pass+1))
else
    echo "  [缺失]   ClientVersionController::download() 不存在"; fail=$((fail+1))
fi
if grep -q "/api/client/download" "$B/public/index.php" && grep -q '"/api/client/check_version",' "$B/public/index.php"; then
    echo "  [OK]     强制改密白名单已覆盖公开下载端点"; pass=$((pass+1))
    if ! grep -A4 '"/api/client/check_version",' "$B/public/index.php" | grep -q '"/api/client/download"'; then
        echo "  [警告]   /api/client/download 未加入强制改密白名单"; warn=$((warn+1))
    fi
fi
if [ -d "$B/public/shared_files" ]; then
    CNT=$(find "$B/public/shared_files" -type f 2>/dev/null | wc -l | tr -d ' ')
    echo "  [OK]     shared_files 存在（共 $CNT 个文件，相对路径下载地址的根）"; pass=$((pass+1))
else
    echo "  [警告]   shared_files 目录不存在，填写相对路径将无法下载"; warn=$((warn+1))
fi

echo "── 十八、老化车维修模块 ──"
# ★ 该模块跨「后端控制器 + 路由 + 前端视图 + 前端路由 + 侧边栏菜单 + modules 表登记」六处，
#   任一处遗漏都会表现为「页面打不开」或「普通用户看不到菜单」，且都不报错，只能靠自检发现。
if [ -f "$B/app/Controllers/AgingCartController.php" ]; then
    echo "  [OK]     AgingCartController.php 已就位"; pass=$((pass+1))
else
    echo "  [缺失]   AgingCartController.php 不存在"; fail=$((fail+1))
fi
if [ -f "$B/app/Core/WorkDuration.php" ]; then
    echo "  [OK]     App\\Core\\WorkDuration 已就位（有效维修时长算法）"; pass=$((pass+1))
else
    echo "  [缺失]   WorkDuration.php 不存在 → 维修时长无法计算"; fail=$((fail+1))
fi
AC_ROUTES=$(grep -c "/api/aging_cart/" "$B/public/index.php" 2>/dev/null | tr -d ' ')
AC_MW=$(grep -c "aging_cart'\]\]" "$B/public/index.php" 2>/dev/null | tr -d ' ')
if [ "${AC_ROUTES:-0}" -ge 14 ] && [ "${AC_MW:-0}" -ge 14 ]; then
    echo "  [OK]     /api/aging_cart/* 路由 ${AC_ROUTES} 条，中间件均为双层方括号"; pass=$((pass+1))
else
    echo "  [缺失]   aging_cart 路由不全（路由 ${AC_ROUTES:-0} 条 / 双层方括号 ${AC_MW:-0} 处，期望均 ≥14，含多人维修的 join/leave 两条）"; fail=$((fail+1))
fi
# 车辆总数配置：调总数接口 + 配置表 + 台账按配置同步
if grep -q "/api/aging_cart/set_cart_total" "$B/public/index.php" 2>/dev/null \
   && grep -q "function setCartTotal" "$B/app/Controllers/AgingCartController.php" 2>/dev/null \
   && grep -q "aging_settings" "$B/app/Controllers/AgingCartController.php" 2>/dev/null; then
    echo "  [OK]     车辆总数可配置（aging_settings + set_cart_total）"; pass=$((pass+1))
else
    echo "  [缺失]   车辆总数仍为硬编码，无法调整（期望 aging_settings + set_cart_total）"; fail=$((fail+1))
fi
# 车辆档案（层数/使用位置/状态/备注）维护接口
if grep -q "/api/aging_cart/save_cart" "$B/public/index.php" 2>/dev/null \
   && grep -q "function saveCart" "$B/app/Controllers/AgingCartController.php" 2>/dev/null; then
    echo "  [OK]     save_cart 接口与控制器方法均已就位（车辆档案维护）"; pass=$((pass+1))
else
    echo "  [缺失]   save_cart 缺失 → 列表页无法保存层数/使用位置/状态/备注"; fail=$((fail+1))
fi
# 车辆档案字段：老库缺列时靠 ensureColumn 自动补齐，漏了会导致 carts 接口整段报错
if grep -q "'layers'" "$B/app/Controllers/AgingCartController.php" 2>/dev/null \
   && grep -q "'usage_type'" "$B/app/Controllers/AgingCartController.php" 2>/dev/null \
   && grep -q "Schema::ensureColumn" "$B/app/Controllers/AgingCartController.php" 2>/dev/null; then
    echo "  [OK]     车辆档案字段（层数/使用位置）含自动补列"; pass=$((pass+1))
else
    echo "  [缺失]   layers/usage_type 或 ensureColumn 补列逻辑缺失"; fail=$((fail+1))
fi
# 故障类型按需求固定 4 项，且仅「其他」强制填写故障现象
FT_OK=0
for t in 车辆变形 车辆掉螺丝 轮子异常; do
    grep -q "$t" "$B/app/Controllers/AgingCartController.php" 2>/dev/null && FT_OK=$((FT_OK+1))
done
if [ "$FT_OK" -eq 3 ] && grep -q "FAULT_DESC_REQUIRED = \['其他'\]" "$B/app/Controllers/AgingCartController.php" 2>/dev/null; then
    echo "  [OK]     故障类型预设 4 项，且仅「其他」必填故障现象"; pass=$((pass+1))
else
    echo "  [缺失]   故障类型预设或条件必填规则不符（期望：车辆变形/车辆掉螺丝/轮子异常/其他）"; fail=$((fail+1))
fi
# ★ 档案维护权限必须锁定为「超级管理员 / 班长」，
#   绝不能退化成 ModulePerm::canEdit（那会让任何被勾选 can_edit 的人都能改档案）。
#
# ⚠️ 断言必须限定在 saveCart() 方法体内：save()/delete()（维修记录改删）用 canManage
#    是有意的，两者口径不同。若写成全文件的 `! grep canManage` 会恒为假、永远报缺失
#    （2026-10-13 实测踩坑，这里保留注释以免后人再犯）。
AC_SAVE=$(awk '/public function saveCart/,/^    }$/' "$B/app/Controllers/AgingCartController.php" 2>/dev/null)
if [ -f "$B/app/Core/UserRole.php" ] \
   && grep -q "UserRole::canMaintain" "$B/app/Controllers/AgingCartController.php" 2>/dev/null \
   && printf '%s\n' "$AC_SAVE" | grep -q "canEditCart" \
   && ! printf '%s\n' "$AC_SAVE" | grep -q "canManage"; then
    echo "  [OK]     档案维护权限已限定为超级管理员/班长（saveCart 独立鉴权）"; pass=$((pass+1))
else
    echo "  [缺失]   档案维护权限未正确限定（saveCart 应为 canEditCart，而非 canManage）"; fail=$((fail+1))
fi
# 权限字段名前后端必须一致（防漂移）：后端下发 can_edit_cart，前端按同名接收
if grep -q "can_edit_cart" "$B/app/Controllers/AgingCartController.php" 2>/dev/null \
   && grep -q "can_edit_cart" "$F/src/views/aging_cart/Index.vue" 2>/dev/null; then
    echo "  [OK]     can_edit_cart 前后端字段一致"; pass=$((pass+1))
else
    echo "  [缺失]   can_edit_cart 下发或接收不一致"; fail=$((fail+1))
fi
# 列表默认只读：单元格只在「编辑态」渲染输入控件；操作列提供 编辑 / 保存 / 取消
# ⚠️ 同样只用正向断言 —— 视图里 records 页签仍保留 canManage 用于维修记录改删，是预期行为。
if grep -q "isEditing(row)" "$F/src/views/aging_cart/Index.vue" 2>/dev/null \
   && grep -q "function beginEditCart" "$F/src/views/aging_cart/Index.vue" 2>/dev/null \
   && grep -q "function saveEditCart" "$F/src/views/aging_cart/Index.vue" 2>/dev/null \
   && grep -q "function cancelEditCart" "$F/src/views/aging_cart/Index.vue" 2>/dev/null; then
    echo "  [OK]     列表默认只读（编辑态才渲染输入控件，含 编辑/保存/取消）"; pass=$((pass+1))
else
    echo "  [缺失]   列表未实现「默认只读 + 点击编辑」"; fail=$((fail+1))
fi
# 看板与列表联动：两者必须共用 carts 数据源
if grep -q "v-for=\"c in carts\"" "$F/src/views/aging_cart/Index.vue" 2>/dev/null \
   && grep -q "filteredCarts" "$F/src/views/aging_cart/Index.vue" 2>/dev/null; then
    echo "  [OK]     格子看板与列表共用 carts 数据源（天然联动）"; pass=$((pass+1))
else
    echo "  [缺失]   看板与列表未共享数据，改动不会联动"; fail=$((fail+1))
fi
# 统计规则说明已按需求从页面取消
if ! grep -q "v-for=\"r in rules\"" "$F/src/views/aging_cart/Index.vue" 2>/dev/null \
   && ! grep -q "每月 26 日至次月 25 日为一个统计月" "$F/src/views/aging_cart/Index.vue" 2>/dev/null; then
    echo "  [OK]     统计规则说明已从页面取消"; pass=$((pass+1))
else
    echo "  [缺失]   统计规则说明仍在页面展示（需求要求取消）"; fail=$((fail+1))
fi
if [ -f "$F/src/views/aging_cart/Index.vue" ]; then
    echo "  [OK]     前端视图 views/aging_cart/Index.vue 存在"; pass=$((pass+1))
else
    echo "  [缺失]   前端视图缺失（注意 Linux 区分大小写，应为大写 Index.vue）"; fail=$((fail+1))
fi
if grep -q "views/aging_cart/Index.vue" "$F/src/router/index.js" 2>/dev/null; then
    echo "  [OK]     前端路由已注册"; pass=$((pass+1))
else
    echo "  [缺失]   router/index.js 未注册 aging-cart"; fail=$((fail+1))
fi
if grep -q "'/aging-cart'" "$F/src/layout/Layout.vue" 2>/dev/null; then
    echo "  [OK]     侧边栏菜单已加入"; pass=$((pass+1))
else
    echo "  [缺失]   Layout.vue 菜单未加入 /aging-cart"; fail=$((fail+1))
fi
# ★ 菜单图标必须在 Layout.vue 顶部 import 并写进 iconMap，否则 <component :is> 取不到组件，
#   菜单项会「静默没有图标」——不报错、不告警，只能靠这里扫出来。
if grep -q "Bicycle" "$F/src/layout/Layout.vue" 2>/dev/null && grep -q "iconMap = {" "$F/src/layout/Layout.vue" 2>/dev/null; then
    echo "  [OK]     菜单图标 Bicycle 已导入"; pass=$((pass+1))
else
    echo "  [缺失]   Layout.vue 未导入 Bicycle 图标 → 老化车菜单项会显示成没有图标"; fail=$((fail+1))
fi
if grep -q "iconMap\[item.icon\] || Menu" "$F/src/layout/Layout.vue" 2>/dev/null; then
    echo "  [OK]     图标渲染已带兜底（未登记图标显示默认图标而非空白）"; pass=$((pass+1))
else
    echo "  [警告]   图标渲染缺兜底，新增图标漏登记时会显示空白"; warn=$((warn+1))
fi
if grep -q "joinRepair" "$B/app/Controllers/AgingCartController.php" 2>/dev/null \
   && grep -q "closeWorkerRows" "$B/app/Controllers/AgingCartController.php" 2>/dev/null \
   && grep -q "loadWorkers" "$B/app/Controllers/AgingCartController.php" 2>/dev/null; then
    echo "  [OK]     多人维修核心方法齐备（参与/结算/明细）"; pass=$((pass+1))
else
    echo "  [缺失]   缺少多人维修实现（joinRepair / closeWorkerRows / loadWorkers）"; fail=$((fail+1))
fi
if grep -q "me_joined" "$B/app/Controllers/AgingCartController.php" 2>/dev/null \
   && grep -q "me_joined" "$F/src/views/aging_cart/Index.vue" 2>/dev/null; then
    echo "  [OK]     「我是否在修」由后端下发，前后端字段一致"; pass=$((pass+1))
else
    echo "  [缺失]   me_joined 缺失或前后端不一致 → 参与/收工按钮会错乱"; fail=$((fail+1))
fi
# ★ 收工必须把仍在修的人一并结账，否则出现「工单已完、某人还在计时」的脏数据
AC_FINISH=$(awk '/public function finish/,/^    }$/' "$B/app/Controllers/AgingCartController.php" 2>/dev/null)
if printf '%s\n' "$AC_FINISH" | grep -q "closeWorkerRows"; then
    echo "  [OK]     收工会一并结束所有在修人员的计时"; pass=$((pass+1))
else
    echo "  [缺失]   finish() 未调用 closeWorkerRows → 多人协作时他人工时会漏结算"; fail=$((fail+1))
fi
if [ -f "$BASE/tools/老化车多人维修补表.sql" ]; then
    echo "  [OK]     手工兜底 SQL 存在：tools/老化车多人维修补表.sql"; pass=$((pass+1))
else
    echo "  [警告]   缺少手工兜底 SQL：tools/老化车多人维修补表.sql（自动补表失效时用）"; warn=$((warn+1))
fi
# ★ 自愈闭环：初始化失败时必须撤销就绪标记，否则 ensureOnce 会认为"跑过了"
#   而永久不再重试 —— 表就会一直缺失且页面无任何报错（2026-10-14 实测）。
#   断言限定在 initTables() 方法体内：clearReady 出现在别处不算数。
AC_INIT=$(awk '/private function initTables/,/^    }$/' "$B/app/Controllers/AgingCartController.php" 2>/dev/null)
# ⚠️ 只判定「代码自愈」，不要把 SQL 文件是否存在也塞进来 ——
#    2026-10-14 实测：代码全对，只因 tools/*.sql 没上传就被判 [缺失]，误导排查方向。
#    SQL 文件是可选兜底，其存在性由上面的独立 [警告] 承担。
if printf '%s\n' "$AC_INIT" | grep -q "missingTables" \
   && printf '%s\n' "$AC_INIT" | grep -q "clearReady" \
   && grep -q "public static function clearReady" "$B/app/Core/Schema.php" 2>/dev/null; then
    echo "  [OK]     建表失败会撤销就绪标记并重试（不会永久缺表）"; pass=$((pass+1))
else
    echo "  [缺失]   缺少自愈闭环：initialized 失败后未 clearReady → 表可能永久缺失"; fail=$((fail+1))
fi
echo "  [提示]   模块登记需执行一次 SQL：tools/老化车模块初始化.sql（否则普通用户看不到菜单）"

echo ""
echo "── 十九、岗位角色预设（实习学生）──"
# roles 表没有后台增删界面，新增角色靠「代码内预置 + 幂等补齐」，
# 前端「岗位角色」下拉直接读 /api/system/roles，因此后端到位即生效。
if grep -q "ensurePresetRoles" "$B/app/Core/Schema.php" 2>/dev/null \
   && grep -q "实习学生" "$B/app/Core/Schema.php" 2>/dev/null \
   && grep -q "PRESET_ROLES" "$B/app/Core/Schema.php" 2>/dev/null; then
    echo "  [OK]     Schema 已预置岗位角色（含实习学生）"; pass=$((pass+1))
else
    echo "  [缺失]   Schema 未预置「实习学生」角色（前端岗位角色下拉将选不到）"; fail=$((fail+1))
fi
if grep -q "Schema::ensurePresetRoles" "$B/app/Controllers/SystemController.php" 2>/dev/null; then
    echo "  [OK]     角色列表接口已接入自动补齐（访问即生效，无需手工 SQL）"; pass=$((pass+1))
else
    echo "  [缺失]   SystemController::roles() 未调用 ensurePresetRoles → 需手工执行 tools/新增实习学生角色.sql"; fail=$((fail+1))
fi
if [ -f "$BASE/tools/新增实习学生角色.sql" ]; then
    echo "  [OK]     手工兜底 SQL 存在：tools/新增实习学生角色.sql"; pass=$((pass+1))
else
    echo "  [警告]   缺少手工兜底 SQL：tools/新增实习学生角色.sql（无权限自动补列时用）"; warn=$((warn+1))
fi
# 前端角色徽章需对「学生」有独立配色，否则和普通员工混在一起不易辨认
if grep -q "学生" "$F/src/views/system/Users.vue" 2>/dev/null; then
    echo "  [OK]     用户管理页角色徽章已区分「学生」配色"; pass=$((pass+1))
else
    echo "  [警告]   Users.vue 角色徽章未区分学生角色，显示上不易识别"; warn=$((warn+1))
fi

echo ""
echo "=================================================="
echo " 通过 $pass 项 / 失败 $fail 项"
if [ "$warn" -gt 0 ]; then
    echo " 提示 $warn 项（不阻断，但建议顺手处理）"
fi
if [ "$fail" -eq 0 ]; then
    echo " ✔ 全部就绪"
else
    echo " ✘ 有 $fail 项待处理，见上面 [缺失]/[未更新]/[残留]/[警告]"
fi
echo "=================================================="
echo ""
echo "提醒：若上方后端项全通过但接口仍异常，请重启 PHP：systemctl restart php-fpm-82"
echo "      前端只看源码通过不够，还需重建：cd $F && rm -rf dist/assets && npm run build"
