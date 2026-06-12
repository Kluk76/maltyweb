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
