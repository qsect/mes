<?php declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * 有效工时 / 维修时长计算器
 *
 * 背景：老化车维修模块要求「维修时长只统计规定时段」，规则与日历强相关且容易算错
 * （跨天、跨午休、跨周末、跨月）。把这段逻辑从控制器里抽出来，好处有三：
 *   1. 可被单元测试直接覆盖（backend/tests/AgingCartDurationTest.php），不必连数据库；
 *   2. 其它模块（工时统计、设备停机统计等）可直接复用，不必各写一套；
 *   3. 规则集中在一处常量，调整时段时只改这里。
 *
 * ★ 时区：一律显式使用 Asia/Shanghai，不依赖服务器 date.timezone。
 *   否则「8:30 之前不算」这类规则会因时区偏差整体错位（服务器若为 UTC 将偏移 8 小时）。
 */
class WorkDuration
{
    public const TZ = 'Asia/Shanghai';

    /**
     * 有效时段表：下标为星期（0=周日 … 6=周六），
     * 值为 [开始小时, 开始分钟, 结束小时, 结束分钟]；null 表示整天不统计。
     *
     * 规则来源（老化车维修需求原文）：
     *   - 周一至周四：8:30 之前不统计，18:00 之后不统计；
     *   - 周五、周六：17:00 之后不统计；
     *   - 周日：整天不统计；
     *   - 每天 12:00-13:00 不统计。
     *
     * 注：周五/周六 8:30 之前按需求原文未列入排除项，故按计入处理。
     *     如需与周一至周四一致，把 5、6 改为 [8, 30, 17, 0] 即可。
     */
    public const WORK_WINDOWS = [
        0 => null,
        1 => [8, 30, 18, 0],
        2 => [8, 30, 18, 0],
        3 => [8, 30, 18, 0],
        4 => [8, 30, 18, 0],
        5 => [0, 0, 17, 0],
        6 => [0, 0, 17, 0],
    ];

    public const LUNCH_START = [12, 0];
    public const LUNCH_END   = [13, 0];

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::TZ);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::zone());
    }

    /** 容忍 string / DateTimeImmutable 两种入参，解析失败返回 null */
    public static function parse($value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return new DateTimeImmutable(trim($value), self::zone());
            } catch (Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * 计算 [start, end] 区间内的有效分钟数
     *
     * 算法：按自然日切分成若干段，逐段裁剪到当日有效时段并扣除午休，最后累加。
     * 复杂度与跨越天数线性相关，对维修场景（通常几分钟到几天）完全够用。
     *
     * @param string|DateTimeImmutable $start
     * @param string|DateTimeImmutable $end
     */
    public static function minutes($start, $end): int
    {
        $s = self::parse($start);
        $e = self::parse($end);
        if ($s === null || $e === null || $e <= $s) {
            return 0;
        }

        $total = 0;
        $cursor = $s;
        $guard = 0;
        while ($cursor < $e && $guard < 1000) {
            $dayStart = $cursor->setTime(0, 0, 0);
            $nextDay = $dayStart->modify('+1 day');
            $segEnd = ($e < $nextDay) ? $e : $nextDay;
            $total += self::dayMinutes($dayStart, $cursor, $segEnd);
            $cursor = $nextDay;
            $guard++;
        }

        return $total > 0 ? $total : 0;
    }

    /**
     * 单日内的有效分钟数：先裁剪到当日工作时段，再扣除与午休的重叠部分
     */
    public static function dayMinutes(
        DateTimeImmutable $dayStart,
        DateTimeImmutable $segStart,
        DateTimeImmutable $segEnd
    ): int {
        $dow = (int)$dayStart->format('w');
        $win = self::WORK_WINDOWS[$dow] ?? null;
        if ($win === null) {
            return 0; // 周日：整天不统计
        }

        $winStart = $dayStart->setTime((int)$win[0], (int)$win[1]);
        $winEnd   = $dayStart->setTime((int)$win[2], (int)$win[3]);

        $a = ($segStart > $winStart) ? $segStart : $winStart;
        $b = ($segEnd < $winEnd) ? $segEnd : $winEnd;
        if ($b <= $a) {
            return 0;
        }

        $lunchStart = $dayStart->setTime((int)self::LUNCH_START[0], (int)self::LUNCH_START[1]);
        $lunchEnd   = $dayStart->setTime((int)self::LUNCH_END[0], (int)self::LUNCH_END[1]);
        $la = ($a > $lunchStart) ? $a : $lunchStart;
        $lb = ($b < $lunchEnd) ? $b : $lunchEnd;
        $lunch = ($lb > $la) ? (int)(($lb->getTimestamp() - $la->getTimestamp()) / 60) : 0;

        $raw = (int)(($b->getTimestamp() - $a->getTimestamp()) / 60);
        $minutes = $raw - $lunch;

        return $minutes > 0 ? $minutes : 0;
    }

    /** 分钟数格式化为「X 小时 Y 分钟」 */
    public static function format(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0 分钟';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h > 0) {
            return $h . ' 小时 ' . $m . ' 分钟';
        }
        return $m . ' 分钟';
    }

    /**
     * 统计月份标签：每月 26 日起算次月
     * 例：2026-12-26 ~ 2027-01-25 均归属 2027-01
     *
     * @param string|DateTimeImmutable|null $date 为空取当前时间
     */
    public static function periodLabel($date = null): string
    {
        $d = self::parse($date ?? 'now');
        if ($d === null) {
            $d = self::now();
        }

        $y = (int)$d->format('Y');
        $m = (int)$d->format('n');
        if ((int)$d->format('j') >= 26) {
            $m++;
            if ($m > 12) {
                $m = 1;
                $y++;
            }
        }

        return sprintf('%04d-%02d', $y, $m);
    }

    /**
     * 由月份标签（YYYY-MM）反推统计区间：上月 26 日 00:00:00 ~ 本月 25 日 23:59:59
     *
     * @return array{label:string,start:string,end:string,start_date:string,end_date:string}
     */
    public static function periodRange(string $label): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $label)) {
            $label = self::periodLabel('now');
        }

        [$y, $m] = array_map('intval', explode('-', $label));
        $end   = new DateTimeImmutable(sprintf('%04d-%02d-25 23:59:59', $y, $m), self::zone());
        $start = (new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $y, $m), self::zone()))
            ->modify('-1 month')
            ->modify('+25 days');

        return [
            'label'      => $label,
            'start'      => $start->format('Y-m-d H:i:s'),
            'end'        => $end->format('Y-m-d H:i:s'),
            'start_date' => $start->format('Y-m-d'),
            'end_date'   => $end->format('Y-m-d'),
        ];
    }

    /** 取当前统计区间 */
    public static function currentPeriod(): array
    {
        return self::periodRange(self::periodLabel('now'));
    }

    /** 规则说明（供页面展示，与算法同源） */
    public static function rules(): array
    {
        return [
            '周一至周四：08:30 之前、18:00 之后不计入',
            '周五、周六：17:00 之后不计入',
            '周日：全天不计入',
            '每天 12:00-13:00 午休时段不计入',
            '月份口径：每月 26 日至次月 25 日为一个统计月',
        ];
    }
}
