<?php
declare(strict_types=1);
require_once __DIR__ . '/settings.php';
/**
 * kpi_recap_next_due — canonical next-due-at derivation for KPI recap subs.
 * Anchors send_hour_local to the app display timezone (app_timezone()),
 * advances past today's slot if it
 * has passed, applies the cadence, and returns a UTC 'Y-m-d H:i:s' string for
 * storage (so MySQL next_due_at <= NOW() comparisons are timezone-safe).
 *
 * @param int    $sendHour 0–23, local (app_timezone()) hour of day
 * @param string $cadence  'daily' | 'weekly' | 'monthly' (anything else => daily-style next slot)
 * @param int|null $dow    ISO day-of-week 1=Mon…7=Sun (weekly only; null = legacy +6 days)
 * @param DateTimeImmutable|null $from  Anchor "now"; defaults to current time. Pass for testability.
 */
function kpi_recap_next_due(int $sendHour, string $cadence, ?int $dow = null, ?DateTimeImmutable $from = null): string
{
    $tz  = new DateTimeZone(app_timezone());
    $utc = new DateTimeZone('UTC');
    $now = $from ? $from->setTimezone($tz) : new DateTimeImmutable('now', $tz);
    $cand = $now->setTime($sendHour, 0, 0);
    if ($cand <= $now) {
        $cand = $cand->modify('+1 day');
    }
    if ($cadence === 'weekly') {
        if ($dow !== null && $dow >= 1 && $dow <= 7) {
            /* Walk forward day-by-day until we hit the target ISO weekday */
            $limit = 0;
            while ((int) $cand->format('N') !== $dow && $limit++ < 7) {
                $cand = $cand->modify('+1 day');
            }
        } else {
            /* Legacy: +6 days from the next daily slot */
            $cand = $cand->modify('+6 days');
        }
        return $cand->setTimezone($utc)->format('Y-m-d H:i:s');
    }
    return match ($cadence) {
        'monthly' => $cand->modify('+1 month')->setTimezone($utc)->format('Y-m-d H:i:s'),
        default   => $cand->setTimezone($utc)->format('Y-m-d H:i:s'),
    };
}
