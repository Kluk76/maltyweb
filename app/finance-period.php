<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";

/**
 * Returns the last closed COGS month from the DB.
 *
 * Canonical source: MAX(month_key) over cogs_fiche_seed ∪ cogs_fiche_monthly.
 * This union is the fiscal-close signing surface: a row in either table means
 * a month has been formally closed by the pipeline.
 *
 * Returns null when no closes exist (new install guard).
 * Never throws — caller treats null as "no closed month yet".
 */
function fin_last_closed_month(PDO $pdo): ?string
{
    try {
        $stmt = $pdo->query("
            SELECT MAX(month_key) AS last_closed FROM (
                SELECT month_key FROM cogs_fiche_seed
                UNION
                SELECT month_key FROM cogs_fiche_monthly
            ) AS all_months
        ");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        $v = $row['last_closed'] ?? null;
        return ($v !== null && $v !== '') ? (string) $v : null;
    } catch (\Throwable $e) {
        error_log('fin_last_closed_month: DB read failed — ' . $e->getMessage());
        return null;
    }
}

/**
 * Returns month_keys that have the FULL triplet required for COGS fiche computation:
 *   - at least one active inv_rm_stocktake row (RM census)
 *   - at least one inv_fg_stocktake row with count_type='month_end' (FG census)
 *   - at least one inv_tank_balances row (WIP census)
 *
 * Excludes months that are already in cogs_fiche_seed (immutable/seeded months
 * cannot be recomputed — the TS engine enforces this; PHP mirrors the same rule).
 *
 * Returns months sorted ascending. Returns empty array on DB error (never throws).
 *
 * Today (2026-06): returns ['2026-05'] (2026-04 is seeded; 2026-06 lacks FG month_end).
 * Will grow as each month's FG census lands.
 *
 * @return string[]  YYYY-MM strings, sorted ascending
 */
function fin_closeable_months(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT rm.period AS month_key
            FROM (
                SELECT DISTINCT period
                FROM inv_rm_stocktake
                WHERE is_active = 1 AND final_qty > 0
            ) rm
            INNER JOIN (
                SELECT DISTINCT month_closed
                FROM inv_fg_stocktake
                WHERE count_type = 'month_end'
            ) fg ON fg.month_closed = rm.period
            INNER JOIN (
                SELECT DISTINCT month_key
                FROM inv_tank_balances
            ) wip ON wip.month_key = rm.period
            WHERE rm.period NOT IN (
                SELECT DISTINCT month_key FROM cogs_fiche_seed
            )
            ORDER BY month_key ASC
        ");
        if ($stmt === false) return [];
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) {
        error_log('fin_closeable_months: DB read failed — ' . $e->getMessage());
        return [];
    }
}
