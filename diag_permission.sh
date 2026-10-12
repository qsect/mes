#!/usr/bin/env bash
# ==================================================================
# 权限修复 —— 一键诊断脚本（v2，只读，不改任何文件）
#
# 用法（在服务器上执行）：
#   bash diag_permission.sh /www/wwwroot/vite
#
# 目的：把「接口 500 / 菜单不过滤」的真实原因一次性钉死。
# 输出请整段复制回来（第 1、2、3、4、5、7、8 节最关键）。
# ==================================================================
set -u

BASE="${1:-/www/wwwroot/vite}"
PHP="${PHP_BIN:-/www/server/php/82/bin/php}"
[ -x "$PHP" ] || PHP="php"

cd "$BASE" 2>/dev/null || { echo "★ 目录不存在: $BASE"; exit 1; }

echo "=================================================="
echo " 权限修复诊断  $(date '+%F %T')"
echo " 项目根: $BASE"
echo " PHP   : $PHP"
echo "=================================================="

# ----------------------------------------------------------------
echo
echo "── 1. 关键文件：是否存在 / 大小 / 修改时间 ──"
echo "   （★ 大小与本地不一致 = 文件没传对）"
for f in \
  backend/public/index.php \
  backend/app/Core/Router.php \
  backend/app/Controllers/AuthController.php \
  backend/app/Middlewares/ModuleViewMiddleware.php \
  backend/app/Middlewares/TraceViewMiddleware.php \
  frontend/dist/index.html
do
  if [ -f "$f" ]; then
    printf "  [有] %-52s %8s 字节  %s\n" "$f" "$(stat -c%s "$f")" "$(stat -c%y "$f" | cut -d. -f1)"
  else
    printf "  [缺] ★ %-50s 文件不存在！\n" "$f"
  fi
done
echo "  参考（本地构建）: index.php 55657 / ModuleViewMiddleware 4450 / TraceViewMiddleware 2259"

# ----------------------------------------------------------------
echo
echo "── 2. 关键特征串核对（判断服务器文件是新版还是旧版）──"
IDX=backend/public/index.php
n_ltrim=$(grep -cF 'ltrim($class, "\\")' "$IDX" 2>/dev/null); n_ltrim=${n_ltrim:-0}
n_mv=$(grep -c 'ModuleViewMiddleware' "$IDX" 2>/dev/null); n_mv=${n_mv:-0}
n_tv=$(grep -c 'TraceViewMiddleware'  "$IDX" 2>/dev/null); n_tv=${n_tv:-0}
# 前导反斜杠写法：路由数组元素形如 \\App\\... （即类名首字符是反斜杠）
n_lead=$(grep -cE "\[\\\\\\\\App" "$IDX" 2>/dev/null); n_lead=${n_lead:-0}

printf "  %-46s 命中 %s 处  %s\n" "自动加载器容错 ltrim(\$class, \"\\\\\\\\\")" "$n_ltrim" \
  "$([ "$n_ltrim" -gt 0 ] && echo '[OK]' || echo '[×] 应为 1 → 旧版')"
printf "  %-46s 命中 %s 处  %s\n" "ModuleViewMiddleware 挂载" "$n_mv" \
  "$([ "$n_mv" -ge 200 ] && echo '[OK]' || echo '[×] 期望 209 → 旧版')"
printf "  %-46s 命中 %s 处  %s\n" "TraceViewMiddleware 挂载" "$n_tv" \
  "$([ "$n_tv" -ge 10 ] && echo '[OK]' || echo '[×] 期望 14 → 旧版')"
printf "  %-46s 命中 %s 处  %s\n" "类名带前导反斜杠（不应有）" "$n_lead" \
  "$([ "$n_lead" -eq 0 ] && echo '[OK] 无前导反斜杠' || echo '[×] ★ 有前导反斜杠 → 旧版，必 500')"

echo
echo "  ▶ 公告四条路由当前写法（新版本应「没有第三个参数」）:"
grep -nE 'list_notices|get_notice_detail|list_notice_reads|mark_notice_read' \
  backend/public/index.php 2>/dev/null | sed 's/^/      /'
echo "      期望长相（行尾直接分号，无 ', [...]'）："
echo "      ...list_notices\",      [\\App\\Controllers\\DiscussController::class, \"listNotices\"]);"

# ----------------------------------------------------------------
echo
echo "── 3. AuthController 是否仍是旧版（SQL 里出现 is_visible）──"
# 旧版特征：is_visible 出现在 SELECT/WHERE 的 SQL 模板里。
# 新版只会在 information_schema 探测与 in_array 中出现，因此按「SQL 上下文」判定。
hit=$(grep -nE "FROM modules[^\"]*is_visible|WHERE +[a-zA-Z_.]*is_visible *=|\{?\\\$(visibleCol|moduleVisibleColumn)\}? *= *'is_visible'|'is_visible'.*;( *//)?" \
        backend/app/Controllers/AuthController.php 2>/dev/null \
      | grep -viE 'COLUMN_NAME|in_array|moduleVisibleColumn =' )
if [ -n "$hit" ]; then
  echo "  ★ 命中旧版 SQL 特征（= 500 元凶）:"
  printf '%s\n' "$hit" | sed 's/^/      /'
else
  echo "  [OK] 未发现旧版 is_visible SQL 特征"
fi
echo "  resolveModuleVisibleColumn 探测方法出现次数: $(grep -c 'resolveModuleVisibleColumn' backend/app/Controllers/AuthController.php) （期望 ≥2）"
echo "  （以下为文件中所有 is_visible 出现位置，供人工确认，新版应仅在探测逻辑里）:"
grep -n "is_visible" backend/app/Controllers/AuthController.php 2>/dev/null | sed 's/^/      /'

# ----------------------------------------------------------------
echo
echo "── 4. PHP 语法检查（应全部 No syntax errors）──"
for f in \
  backend/public/index.php \
  backend/app/Core/Router.php \
  backend/app/Controllers/AuthController.php \
  backend/app/Middlewares/ModuleViewMiddleware.php \
  backend/app/Middlewares/TraceViewMiddleware.php
do
  if [ -f "$f" ]; then
    printf "  %-52s " "$f"
    "$PHP" -l "$f" 2>&1 | tail -1
  else
    printf "  %-52s ★ 文件不存在\n" "$f"
  fi
done

# ----------------------------------------------------------------
echo
echo "── 5. .env 是否可读（DB_PASS 缺失会让所有接口 500）──"
if [ -f backend/.env ]; then
  echo "  [有] backend/.env  $(stat -c%s backend/.env) 字节  权限 $(stat -c%a backend/.env)  属主 $(stat -c%U:%G backend/.env)"
  for k in DB_HOST DB_PORT DB_NAME DB_USER DB_PASS; do
    if grep -qE "^\s*${k}\s*=\s*[^[:space:]]" backend/.env; then
      echo "      $k : 已配置"
    else
      echo "      $k : ★ 缺失或为空"
    fi
  done
  # 提示 DB_NAME 指向的库（不回显口令）
  grep -n '^DB_NAME' backend/.env 2>/dev/null | sed 's/=.*/=<已隐藏>/' | sed 's/^/      /'
else
  echo "  ★ backend/.env 不存在 → Database 会抛异常 → 全站 500"
fi

# ----------------------------------------------------------------
echo
echo "── 5.1 模块 500 的元凶排查（你反馈 equipment/quality/... 全部 500）──"
echo "  「系统内部错误」只有 index.php 全局异常处理器能产出，"
echo "   模块能 500 = 代码/依赖不完整。逐项排查："
echo
echo "  [A] 缺失的中间件 / 服务文件："
for f in \
  backend/app/Middlewares/ModuleViewMiddleware.php \
  backend/app/Middlewares/TraceViewMiddleware.php \
  backend/app/Services/AuditService.php \
  backend/app/Core/Env.php \
  backend/app/Core/Schema.php \
  backend/app/Core/RateLimiter.php \
  backend/app/Core/ModulePerm.php
do
  [ -f "$f" ] && printf "    [有] %-50s %8s 字节\n" "$f" "$(stat -c%s "$f")" \
              || printf "    [缺] ★ %-48s ← 可能直接 500\n" "$f"
done
echo
echo "  [B] RateLimiter 存储目录可写性（写不进会 500）："
for d in backend/storage backend/storage/rate_limit backend/storage/schema backend/logs; do
  if [ -d "$d" ]; then
    [ -w "$d" ] && printf "    [可写] %s\n" "$d" || printf "    [不可写] ★ %s（属主 %s:%s 权限 %s）\n" "$d" "$(stat -c%U "$d")" "$(stat -c%G "$d")" "$(stat -c%a "$d")"
  else
    printf "    [不存在] %s\n" "$d"
  fi
done
echo
echo "  [C] PHP 必需扩展（缺 pdo_mysql 会让所有接口 500）："
"$PHP" -r 'foreach(["pdo_mysql","session","json","mbstring"] as $e){ printf("    %-14s %s\n", $e, extension_loaded($e)?"[OK]":"[★缺失]"); }' 2>/dev/null \
  || echo "    （无法执行 php -r）"
echo
echo "  [D] 从外部访问 .env / 敏感文件（必须返回 403/404，返回 200 属高危）："
for p in backend/.env .env backend/.env.example; do
  c=$(curl -s -o /dev/null -w '%{http_code}' -m 6 "http://127.0.0.1:8666/$p" 2>/dev/null)
  printf "    HTTP %-4s /%s\n" "${c:-ERR}" "$p"
done

# ----------------------------------------------------------------
echo
echo "── 6. 前端产物 + 浏览器缓存判定 ──"
if [ -f frontend/dist/index.html ]; then
  MAIN=$(grep -oE 'assets/index-[^"]+\.js' frontend/dist/index.html | head -1)
  echo "  index.html 引用主包 : ${MAIN:-未识别}"
  echo "  期望主包            : assets/index-DBx7Y5Gl.js"
  [ "$MAIN" = "assets/index-DBx7Y5Gl.js" ] \
    && echo "  [OK] 磁盘上 dist 已是新包" \
    || echo "  [× ] ★ 磁盘主包哈希不一致 → dist 未替换"
  echo "  assets 文件数       : $(find frontend/dist/assets -maxdepth 1 -type f 2>/dev/null | wc -l)  （期望 69）"
  echo
  echo "  经 nginx 实际下发的主包（对比磁盘，判断是否被 nginx/缓存卡住旧包）:"
  CURL_MAIN=$(curl -s -m 6 "http://127.0.0.1/index.html" 2>/dev/null | grep -oE 'assets/index-[^"]+\.js' | head -1)
  echo "    nginx 返回 index.html 引用: ${CURL_MAIN:-（取不到，可能站点根不是 dist）}"
  if [ -n "${CURL_MAIN:-}" ] && [ "$CURL_MAIN" != "$MAIN" ]; then
    echo "    ★ nginx 返回的主包 ≠ 磁盘主包 → 浏览器/缓存/路径指向了旧产物"
  fi
else
  echo "  ★ frontend/dist/index.html 不存在"
fi

# ----------------------------------------------------------------
echo
echo "── 7. 实测接口（本机直连，看真实响应码与响应体）──"
test_api() {
  local path="$1" note="${2:-}"
  local out code body
  out=$(curl -s -m 8 -w '\n__CODE__%{http_code}' "http://127.0.0.1:8666${path}" 2>/dev/null)
  code="${out##*__CODE__}"
  body="${out%__CODE__*}"
  printf "  HTTP %-4s %-40s %s\n" "${code:-ERR}" "$path" "$note"
  [ "${code:-000}" != "000" ] && printf "        %s\n" "$(printf '%s' "$body" | tr -d '\r\n' | head -c 240)"
}
test_api "/api/discuss/list_notices"  "首页公告（应已摘中间件）"
test_api "/api/auth/me"               "身份接口"
test_api "/api/equipment/list_matrix" "你反馈 500 的模块之一"
test_api "/api/discuss/init"          "讨论区初始化（报错来源）"
echo "  判读：不带 Cookie 时"
echo "    401 + '未登录'  ⇒ 代码链路是新版，500 多半在依赖/目录权限"
echo "    500 + '系统内部错误' ⇒ 代码层未捕获异常，看第 8 节的 request_id"

# ----------------------------------------------------------------
echo
echo "── 8. 真实错误日志（最关键）──"
found=0
for p in backend/logs backend/storage/logs /www/wwwlogs; do
  [ -d "$p" ] || continue
  while read -r lf; do
    [ -n "$lf" ] || continue
    found=1
    echo "  >>> $lf   (末 30 行)"
    tail -30 "$lf" | sed 's/^/      /'
    echo
  done < <(find "$p" -type f -name '*.log' -mmin -360 2>/dev/null | head -3)
done
for p in /www/server/php/82/var/log/php-fpm.log /www/server/php/82/var/log/*error*.log; do
  [ -f "$p" ] || continue
  found=1
  echo "  >>> $p   (末 20 行)"
  tail -20 "$p" | sed 's/^/      /'
  echo
done
[ "$found" = "0" ] && echo "  ★ 未找到最近 6 小时的日志文件，请确认日志目录"

echo
echo "  提示：页面报的「系统内部错误」响应体里带 request_id，可直接定位："
echo "    grep -r '<request_id>' backend/logs /www/wwwlogs /www/server/php/82/var/log 2>/dev/null"

# ----------------------------------------------------------------
echo
echo "── 9. OPcache 状态与重启提醒 ──"
if "$PHP" -i 2>/dev/null | grep -q 'validate_timestamps.*=> *Off\|validate_timestamps => Off'; then
  echo "  ★ OPcache validate_timestamps=Off —— 改完 PHP 必须重启才生效"
fi
echo "  重启命令: systemctl restart php-fpm-82"

echo
echo "=================================================="
echo " 诊断完成。请把第 1/2/3/5/5.1/6/7/8 节整体复制回来。"
echo "=================================================="
echo
echo "【快速对照：你这次的三个现象】"
echo "  ① 登录成功 + 菜单仍全显示     → 多半是浏览器/缓存还在跑旧 JS，"
echo "                                  或 dist 没替换（看第 6 节主包哈希）"
echo "  ② 模块访问 500               → 看第 7 节响应码 + 第 8 节 request_id"
echo "  ③ 讨论区初始化失败 500        → 同 ②，看 /api/discuss/init 那行"
