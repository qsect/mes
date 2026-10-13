<?php
declare(strict_types=1);

/**
 * 老化车「有效维修时长」与「统计月度口径」单元测试
 *
 * 覆盖 App\Core\WorkDuration：
 *   - 时段规则（周一至周四 8:30-18:00、周五周六至 17:00、周日不计、午休不计）
 *   - 跨午休、跨天、跨周末的切分累加
 *   - 每月 26 日起算的统计月度归属与区间反推
 *
 * 固定日期说明：2026-10-12 是星期一，故
 *   10-12 周一 / 10-13 周二 / 10-14 周三 / 10-15 周四 / 10-16 周五 / 10-17 周六 / 10-18 周日
 *
 * 这些是最容易算错又最难靠肉眼核对的规则，改动 WORK_WINDOWS / LUNCH_* / periodLabel
 * 时本测试会立刻报警。
 */

require_once __DIR__ . '/../app/Core/WorkDuration.php';

use App\Core\WorkDuration;

// ---------- 时段规则：周一至周四 ----------

test('维修时长：周一至周四 8:30 之前不计入', function () {
    // 周三 08:00-09:00，只应计入 8:30 之后的 30 分钟
    assert_eq(30, WorkDuration::minutes('2026-10-14 08:00:00', '2026-10-14 09:00:00'));
});

test('维修时长：周一至周四 18:00 之后不计入', function () {
    // 周三 17:30-19:00，只应计入到 18:00 的 30 分钟
    assert_eq(30, WorkDuration::minutes('2026-10-14 17:30:00', '2026-10-14 19:00:00'));
});

test('维修时长：完整工作日上午 3.5 小时', function () {
    // 周三 08:30-12:00 = 210 分钟
    assert_eq(210, WorkDuration::minutes('2026-10-14 08:30:00', '2026-10-14 12:00:00'));
});

test('维修时长：午休 12:00-13:00 不计入（跨越午休）', function () {
    // 周三 11:30-13:30：11:30-12:00 计 30，13:00-13:30 计 30，合计 60
    assert_eq(60, WorkDuration::minutes('2026-10-14 11:30:00', '2026-10-14 13:30:00'));
});

test('维修时长：完全落在午休内计 0', function () {
    assert_eq(0, WorkDuration::minutes('2026-10-14 12:10:00', '2026-10-14 12:50:00'));
});

// ---------- 时段规则：周五、周六、周日 ----------

test('维修时长：周五 17:00 之后不计入', function () {
    // 周五 16:00-18:00，只应计入到 17:00 的 60 分钟
    assert_eq(60, WorkDuration::minutes('2026-10-16 16:00:00', '2026-10-16 18:00:00'));
});

test('维修时长：周六 17:00 之后不计入', function () {
    assert_eq(60, WorkDuration::minutes('2026-10-17 16:00:00', '2026-10-17 18:00:00'));
});

test('维修时长：周五、周六 8:30 之前按需求原文计入', function () {
    // 需求只排除了「周五周六 17:00 之后」，未排除 8:30 之前，故整段计入
    assert_eq(60, WorkDuration::minutes('2026-10-17 08:00:00', '2026-10-17 09:00:00'));
});

test('维修时长：周日全天不计入', function () {
    assert_eq(0, WorkDuration::minutes('2026-10-18 08:00:00', '2026-10-18 20:00:00'));
});

// ---------- 跨天 / 跨周末 ----------

test('维修时长：跨天应逐日累加', function () {
    // 周三 08:00 → 周四 19:00
    // 周三：8:30-12:00(210) + 13:00-18:00(300) = 510
    // 周四：同为 510
    assert_eq(1020, WorkDuration::minutes('2026-10-14 08:00:00', '2026-10-15 19:00:00'));
});

test('维修时长：跨周末应跳过周日', function () {
    // 周六 16:00 → 周一 09:00
    // 周六：16:00-17:00 = 60
    // 周日：0
    // 周一：8:30-09:00 = 30
    assert_eq(90, WorkDuration::minutes('2026-10-17 16:00:00', '2026-10-19 09:00:00'));
});

test('维修时长：整周累计（周一 00:00 → 周日 23:59）', function () {
    // 周一至周四各 510，周五周六各 960（0:00-17:00 扣午休 60），周日 0
    // 510*4 + 960*2 = 3960
    assert_eq(3960, WorkDuration::minutes('2026-10-12 00:00:00', '2026-10-18 23:59:59'));
});

// ---------- 边界与异常入参 ----------

test('维修时长：结束早于开始计 0', function () {
    assert_eq(0, WorkDuration::minutes('2026-10-14 10:00:00', '2026-10-14 09:00:00'));
});

test('维修时长：起止相同计 0', function () {
    assert_eq(0, WorkDuration::minutes('2026-10-14 10:00:00', '2026-10-14 10:00:00'));
});

test('维修时长：非法时间字符串不抛异常，计 0', function () {
    assert_eq(0, WorkDuration::minutes('not-a-date', '2026-10-14 10:00:00'));
    assert_eq(0, WorkDuration::minutes('2026-10-14 10:00:00', ''));
});

// ---------- 格式化 ----------

test('时长格式化：分钟与小时', function () {
    assert_eq('0 分钟', WorkDuration::format(0));
    assert_eq('45 分钟', WorkDuration::format(45));
    assert_eq('1 小时 0 分钟', WorkDuration::format(60));
    assert_eq('1 小时 35 分钟', WorkDuration::format(95));
});

// ---------- 统计月度口径：每月 26 日起算 ----------

test('统计月份：25 日仍归属当月', function () {
    assert_eq('2026-12', WorkDuration::periodLabel('2026-12-25 23:59:59'));
});

test('统计月份：26 日起归属次月', function () {
    assert_eq('2027-01', WorkDuration::periodLabel('2026-12-26 00:00:00'));
    assert_eq('2027-01', WorkDuration::periodLabel('2026-12-31 12:00:00'));
    assert_eq('2027-01', WorkDuration::periodLabel('2027-01-25 23:59:59'));
});

test('统计月份：次月 26 日再翻页', function () {
    assert_eq('2027-02', WorkDuration::periodLabel('2027-01-26 00:00:00'));
});

test('统计月份：12 月 26 日跨年应归到次年 1 月', function () {
    assert_eq('2027-01', WorkDuration::periodLabel('2026-12-26 08:00:00'));
});

test('统计区间：2027-01 应为 2026-12-26 至 2027-01-25', function () {
    $r = WorkDuration::periodRange('2027-01');
    assert_eq('2027-01', $r['label']);
    assert_eq('2026-12-26 00:00:00', $r['start']);
    assert_eq('2027-01-25 23:59:59', $r['end']);
    assert_eq('2026-12-26', $r['start_date']);
    assert_eq('2027-01-25', $r['end_date']);
});

test('统计区间：非法标签回退为当前月份且不抛异常', function () {
    $r = WorkDuration::periodRange('不是月份');
    assert_true(preg_match('/^\d{4}-\d{2}$/', $r['label']) === 1);
});

test('统计月份：区间首尾闭合（25 日与 26 日不重叠）', function () {
    // 2026-12-25 属于 2026-12，2026-12-26 属于 2027-01，两者不应落到同一区间
    $a = WorkDuration::periodLabel('2026-12-25 10:00:00');
    $b = WorkDuration::periodLabel('2026-12-26 10:00:00');
    assert_true($a !== $b);
});
