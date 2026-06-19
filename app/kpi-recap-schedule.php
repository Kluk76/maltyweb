<?php
declare(strict_types=1);
/**
 * kpi_recap_next_due — canonical next-due-at derivation for KPI recap subs.
 * Anchors send_hour_local to Europe/Zurich, advances past today's slot if it
 * has passed, applies the cadence, and returns a UTC 'Y-m-d H:i:s' string for
 * storage (so MySQL next_due_at <= NOW() comparisons are timezone-safe).
 *
 * @param int    $sendHour 0–23, local (Europe/Zurich) hour of day
 * @param string $cadence  'daily' | 'weekly' | 'monthly' (anything else => daily-style next slot)
 * @param DateTimeImmutable|null $from  Anchor "now"; defaults to current time. Pass for testability.
 */
function kpi_recap_next_due(int $sendHour, string $cadence, ?DateTimeImmutable $from = null): string
{
    $tz  = new DateTimeZone('Europe/Zurich');
    $utc = new DateTimeZone('UTC');
    $now = $from ? $from->setTimezone($tz) : new DateTimeImmutable('now', $tz);
    $cand = $now->setTime($sendHour, 0, 0);
    if ($cand <= $now) {
        $cand = $cand->modify('+1 day');
    }
    return match ($cadence) {
        'weekly'  => $cand->modify('+6 days')->setTimezone($utc)->format('Y-m-d H:i:s'),
        'monthly' => $cand->modify('+1 month')->setTimezone($utc)->format('Y-m-d H:i:s'),
        default   => $cand->setTimezone($utc)->format('Y-m-d H:i:s'),
    };
}
