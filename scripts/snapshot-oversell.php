<?php
/**
 * snapshot-oversell.php — Daily CLI snapshot writer for ATP-breach history.
 *
 * Usage:
 *   php scripts/snapshot-oversell.php
 *
 * Idempotent: re-running for the same date updates existing rows via
 * ON DUPLICATE KEY UPDATE (UNIQUE KEY on snapshot_date, sku_id_fk).
 *
 * Safe to run from cron as maltytask user or www-data.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/db.php';
require dirname(__DIR__) . '/app/oversell.php';

// ── Concurrency guard ────────────────────────────────────────────────────────
$lockDir  = '/var/www/maltytask/data/locks';
$lockFile = $lockDir . '/oversell-snapshot.lock';

if (!is_dir($lockDir)) {
    if (!mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
        fwrite(STDERR, "[oversell-snapshot] ERROR: cannot create lock dir {$lockDir}\n");
        exit(1);
    }
}

$fh = fopen($lockFile, 'w');
if ($fh === false) {
    fwrite(STDERR, "[oversell-snapshot] ERROR: cannot open lock file {$lockFile}\n");
    exit(1);
}

if (!flock($fh, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[oversell-snapshot] Already running (lock held). Exiting.\n");
    fclose($fh);
    exit(0);
}

// ── Main logic ───────────────────────────────────────────────────────────────
$pdo    = maltytask_pdo();
$result = oversell_current($pdo);

$basisDate   = $result['basis_date'];
$oversoldSkus = $result['skus'];
$oversoldCount = $result['oversold_count'];
$totalShort    = $result['total_units_short'];

if ($oversoldCount === 0) {
    echo "No ATP breaches today — nothing written.\n";
    flock($fh, LOCK_UN);
    fclose($fh);
    exit(0);
}

// ── Upsert into ops_oversell_snapshot ───────────────────────────────────────
$upsertStmt = $pdo->prepare(
    "INSERT INTO ops_oversell_snapshot
         (snapshot_date, sku_id_fk, live_futur, units_short,
          on_trade_short, off_trade_short, unclassified_short)
     VALUES (CURDATE(), ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
         live_futur          = VALUES(live_futur),
         units_short         = VALUES(units_short),
         on_trade_short      = VALUES(on_trade_short),
         off_trade_short     = VALUES(off_trade_short),
         unclassified_short  = VALUES(unclassified_short)"
);

$rowsWritten = 0;
foreach ($oversoldSkus as $sku) {
    $upsertStmt->execute([
        $sku['sku_id'],
        $sku['live_futur'],
        $sku['units_short'],
        $sku['on_trade_short'],
        $sku['off_trade_short'],
        $sku['unclassified_short'],
    ]);
    // COUNT unique SKUs processed, not MySQL affected rows
    // (MySQL returns 1 for INSERT, 2 for UPDATE via ON DUPLICATE KEY).
    $rowsWritten++;
}

// ── Summary ──────────────────────────────────────────────────────────────────
echo "Date: {$basisDate}\n";
echo "Oversold SKUs: {$oversoldCount}\n";
echo sprintf("Total units short: %.2f\n", $totalShort);
echo "Rows written/updated: {$rowsWritten}\n";

flock($fh, LOCK_UN);
fclose($fh);
exit(0);
