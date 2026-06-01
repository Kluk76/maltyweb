<?php
declare(strict_types=1);
/**
 * rm-stocktake-rollup.php — Shared rollup helper for RM per-pallet stocktake.
 *
 * Requires: app/db-write-helpers.php (bd_upsert, bd_fetch_before, bd_lookup_pk_by_nk, log_revision)
 *
 * NULL-vs-0 invariant (load-bearing for COGS):
 *   - 0 active lines for (mi_id, period)  → counted_qty = NULL
 *     (final_qty falls back to expected_qty)
 *   - ≥1 active line                      → counted_qty = SUM(qty)
 *     (explicit 0 line from operator = genuine stock-out)
 *
 * Do not call this from BSF-syncing paths. MySQL only.
 */

require_once __DIR__ . '/db-write-helpers.php';

/**
 * Recompute inv_rm_stocktake.counted_qty for (mi_id, period) from active lines.
 *
 * @param PDO    $pdo      Live PDO connection
 * @param array  $me       current_user() result
 * @param string $miId     VARCHAR mi_id string (e.g. "MALT_PILSNER")
 * @param int    $miFk     INT UNSIGNED ref_mi.id
 * @param string $period   YYYY-MM
 */
function rm_recompute_rollup(PDO $pdo, array $me, string $miId, int $miFk, string $period): void
{
    // Sum active lines — COALESCE(SUM, NULL) returns NULL when there are no rows,
    // which is what we want: 0 rows → NULL counted_qty (not 0).
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(qty), NULL) AS s, COUNT(*) AS n
           FROM inv_rm_stocktake_lines
          WHERE mi_id = ? AND period = ? AND is_active = 1'
    );
    $stmt->execute([$miId, $period]);
    $agg = $stmt->fetch(PDO::FETCH_ASSOC);

    $lineCount  = (int) $agg['n'];
    // When n=0, countedQty stays null (PHP null → PDO null → DB NULL).
    // When n>0, use the decimal string to avoid float rounding on DECIMAL column.
    $countedQty = ($lineCount === 0) ? null : $agg['s'];

    $countedBy = $me['username'] ?? 'unknown';
    $countedAt = date('Y-m-d H:i:s');

    // Deterministic hash for inv_rm_stocktake (same shape as legacy form)
    $countedQtyStr = ($countedQty !== null) ? (string) $countedQty : '';
    $rowHash = hash('sha256', implode('|', [
        (string) $miFk,
        $miId,
        $period,
        $countedQtyStr,
        'web-form',
        $countedBy,
    ]));

    // Snapshot before-state for audit
    $existingPk = bd_lookup_pk_by_nk($pdo, 'inv_rm_stocktake', ['mi_id', 'period'], [
        'mi_id'  => $miId,
        'period' => $period,
    ]);
    $before = ($existingPk !== null) ? bd_fetch_before($pdo, 'inv_rm_stocktake', $existingPk) : null;

    $row = [
        'mi_id'      => $miId,
        'mi_id_fk'   => $miFk,
        'period'     => $period,
        'counted_qty' => $countedQty,
        'source'     => 'web-form',
        'counted_by' => $countedBy,
        'counted_at' => $countedAt,
        'is_active'  => 1,
        'notes'      => null,
        'row_hash'   => $rowHash,
    ];

    $result = bd_upsert($pdo, 'inv_rm_stocktake', $row, ['mi_id', 'period']);
    $pk     = $result['id'];

    log_revision(
        $pdo, $me,
        'inv_rm_stocktake', $pk, $before, $row, 'normal',
        'Rollup RM période ' . $period . ' — ' . $lineCount . ' ligne(s) active(s)'
    );
}
