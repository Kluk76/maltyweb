<?php
declare(strict_types=1);

/**
 * scripts/seasonal-index-cli.php
 *
 * Weekly rebuild of the kpi_sku_seasonal_index cache table.
 *
 * Classical multiplicative seasonal decomposition over inv_sales_ledger
 * (2021→now, ~44k rows). Computes one curve per recipe family (recipe_id)
 * plus a global fallback curve (recipe_id=0). Families below
 * burn_min_family_years of history are skipped (they fall back to the global
 * curve at read time via sb_resolve_family_index()).
 *
 * Usage:
 *   php scripts/seasonal-index-cli.php [--dry-run] [--apply]
 *
 * Flags:
 *   --dry-run  (DEFAULT) Compute and print summary; no writes.
 *   --apply    Rebuild kpi_sku_seasonal_index atomically (DELETE + INSERT
 *              in a single transaction). Idempotent: re-running produces
 *              identical rows.
 *
 * Exit codes:
 *   0  Success (or dry-run completed)
 *   1  Fatal error (no global curve computable, DB error, etc.)
 *
 * Example:
 *   php scripts/seasonal-index-cli.php --dry-run
 *   php scripts/seasonal-index-cli.php --apply
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────

$repoRoot = dirname(__DIR__);
require $repoRoot . '/app/db.php';
require $repoRoot . '/app/settings.php';
require $repoRoot . '/app/seasonal-burn.php';

// ── Parse CLI args ───────────────────────────────────────────────────────────

$opts   = getopt('', ['dry-run', 'apply']);
$apply  = isset($opts['apply']);
$dryRun = !$apply;

$tStart = microtime(true);

echo ($dryRun ? "[DRY-RUN]" : "[APPLY]") . " seasonal-index-cli.php\n";
echo str_repeat('-', 72) . "\n";

// ── DB connection ─────────────────────────────────────────────────────────────

$pdo = maltytask_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Load params ───────────────────────────────────────────────────────────────

$params = sb_load_params();

printf("Params: ewma_lambda=%.2f  season_weeks=%d  horizon=%d  index_floor=%.2f  index_cap=%.1f\n",
    $params['burn_ewma_lambda'],
    $params['burn_season_weeks'],
    $params['burn_horizon_weeks'],
    $params['burn_index_floor'],
    $params['burn_index_cap']
);
printf("        min_family_years=%.1f  level_window=%d  prov_min_weeks=%d  eol_dormant=%d\n",
    $params['burn_min_family_years'],
    $params['burn_level_window_weeks'],
    $params['burn_provisional_min_weeks'],
    $params['burn_eol_dormant_weeks']
);
echo str_repeat('-', 72) . "\n";

// ── Find all distinct recipe_ids in the ledger ────────────────────────────────

$familyStmt = $pdo->query("
    SELECT DISTINCT s.recipe_id
      FROM inv_sales_ledger l
      JOIN ref_skus s ON s.id = l.sku_id_fk
     WHERE l.sku_id_fk IS NOT NULL
       AND s.recipe_id IS NOT NULL
     ORDER BY s.recipe_id
");
$familyIds  = array_map('intval', array_column($familyStmt->fetchAll(PDO::FETCH_ASSOC), 'recipe_id'));

// Count unresolved SKU rows for coverage caveat
$nullSkuCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM inv_sales_ledger WHERE sku_id_fk IS NULL"
)->fetchColumn();

printf("Distinct families: %d   Unresolved-SKU rows excluded: %d\n",
    count($familyIds), $nullSkuCount);
echo str_repeat('-', 72) . "\n";

// ── Compute global curve first (recipe_id=0) ──────────────────────────────────

echo "Computing GLOBAL curve (recipe_id=0) ...\n";
$globalSeries = sb_family_weekly_series($pdo, 0);
$globalResult = null;

if (!empty($globalSeries)) {
    $globalResult = sb_compute_seasonal_index($globalSeries, $params);
}

if ($globalResult === null) {
    fwrite(STDERR, "FATAL: Cannot compute global seasonal index (no usable series).\n");
    exit(1);
}

// Force global to be marked usable (it's the fallback; we always include it)
$globalPeakWeek = array_search(max($globalResult['index']), $globalResult['index']);
printf("  Global: sample_years=%.1f  usable=%s  peak_isoweek=%d  peak_idx=%.3f\n",
    $globalResult['sample_years'],
    $globalResult['usable'] ? 'yes' : 'no (but forced — global always stored)',
    $globalPeakWeek,
    $globalResult['index'][$globalPeakWeek]
);

// Sanity check: peak should be in summer (roughly isoweek 26-33)
if ($globalPeakWeek < 22 || $globalPeakWeek > 40) {
    printf("  ⚠ WARNING: global peak_isoweek=%d is outside expected summer range 22-40.\n",
        $globalPeakWeek);
    printf("    This may indicate an inverted sign or decomposition issue — verify.\n");
}

echo str_repeat('-', 72) . "\n";

// ── Compute per-family curves ─────────────────────────────────────────────────

$familyResults    = [];  // recipe_id => ['result'=>..., 'usable'=>bool]
$familiesProcessed  = 0;
$familiesFellback   = 0;

printf("%-12s  %-10s  %-8s  %-6s  %-6s  %-12s  %s\n",
    'recipe_id', 'sample_yr', 'usable', 'weeks', 'ratios', 'peak_wk(idx)', 'status'
);
printf("%-12s  %-10s  %-8s  %-6s  %-6s  %-12s  %s\n",
    str_repeat('-', 12), str_repeat('-', 10), str_repeat('-', 8),
    str_repeat('-', 6),  str_repeat('-', 6),  str_repeat('-', 12), str_repeat('-', 10)
);

foreach ($familyIds as $recipeId) {
    $series = sb_family_weekly_series($pdo, $recipeId);
    if (empty($series)) {
        printf("%-12d  %-10s  %-8s  %-6s  %-6s  %-12s  %s\n",
            $recipeId, '—', '—', '0', '0', '—', 'SKIPPED(empty)');
        $familiesFellback++;
        continue;
    }

    $res = sb_compute_seasonal_index($series, $params);
    if ($res === null || !$res['usable']) {
        printf("%-12d  %-10s  %-8s  %-6s  %-6s  %-12s  %s\n",
            $recipeId,
            $res !== null ? sprintf('%.1f', $res['sample_years']) : '—',
            'NO',
            count($series),
            '—',
            '—',
            'FALLBACK_TO_GLOBAL'
        );
        $familiesFellback++;
        continue;
    }

    $peakWk  = array_search(max($res['index']), $res['index']);
    $peakIdx = $res['index'][$peakWk];
    $nObs    = array_sum($res['n_obs']);

    printf("%-12d  %-10.1f  %-8s  %-6d  %-6d  %-12s  %s\n",
        $recipeId,
        $res['sample_years'],
        'YES',
        count($series),
        $nObs,
        sprintf('%d(%.3f)', $peakWk, $peakIdx),
        'OK'
    );

    $familyResults[$recipeId] = $res;
    $familiesProcessed++;
}

echo str_repeat('-', 72) . "\n";
printf("Families processed: %d   Fell back to global: %d\n",
    $familiesProcessed, $familiesFellback);

// ── Assemble rows to insert ───────────────────────────────────────────────────

$insertRows = []; // [recipe_id, iso_week, seasonal_index, n_obs, sample_years, is_global_fallback]
$computedAt = date('Y-m-d H:i:s');

// Global rows (recipe_id=0, is_global_fallback=1)
for ($w = 1; $w <= 53; $w++) {
    $insertRows[] = [
        'recipe_id'        => 0,
        'iso_week'         => $w,
        'seasonal_index'   => round($globalResult['index'][$w], 4),
        'n_obs'            => $globalResult['n_obs'][$w] ?? 0,
        'sample_years'     => $globalResult['sample_years'],
        'is_global_fallback' => 1,
        'computed_at'      => $computedAt,
    ];
}

// Family rows
foreach ($familyResults as $recipeId => $res) {
    for ($w = 1; $w <= 53; $w++) {
        $insertRows[] = [
            'recipe_id'        => $recipeId,
            'iso_week'         => $w,
            'seasonal_index'   => round($res['index'][$w], 4),
            'n_obs'            => $res['n_obs'][$w] ?? 0,
            'sample_years'     => $res['sample_years'],
            'is_global_fallback' => 0,
            'computed_at'      => $computedAt,
        ];
    }
}

$rowsToWrite = count($insertRows);
printf("Rows prepared: %d  (%d families × 53 + global × 53)\n",
    $rowsToWrite,
    $familiesProcessed
);

// ── Sample indices preview ────────────────────────────────────────────────────

echo "\nGlobal curve sample indices (weeks 1, 13, 26, 30, 39, 52):\n";
foreach ([1, 13, 26, 30, 39, 52] as $w) {
    printf("  week %-3d → %.4f\n", $w, $globalResult['index'][$w]);
}

// ── Dry-run: report and exit ──────────────────────────────────────────────────

if ($dryRun) {
    $elapsed = round(microtime(true) - $tStart, 2);
    echo "\n[DRY-RUN] No writes performed. Re-run with --apply to commit.\n";
    printf("Summary: families_processed=%d  families_fellback=%d  rows_would_write=%d  global_peak_week=%d  runtime=%.2fs\n",
        $familiesProcessed, $familiesFellback, $rowsToWrite, $globalPeakWeek, $elapsed);
    exit(0);
}

// ── Apply: atomic DELETE + INSERT in a transaction ────────────────────────────

echo "\n[APPLY] Writing to kpi_sku_seasonal_index ...\n";

try {
    $pdo->beginTransaction();

    $pdo->exec("DELETE FROM kpi_sku_seasonal_index");
    $deletedMsg = "DELETE done.";
    echo "  $deletedMsg\n";

    $insertSql = "INSERT INTO kpi_sku_seasonal_index
                  (recipe_id, iso_week, seasonal_index, n_obs, sample_years, is_global_fallback, computed_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
    $insertStmt = $pdo->prepare($insertSql);

    $inserted = 0;
    foreach ($insertRows as $r) {
        $insertStmt->execute([
            $r['recipe_id'],
            $r['iso_week'],
            $r['seasonal_index'],
            $r['n_obs'],
            $r['sample_years'],
            $r['is_global_fallback'],
            $r['computed_at'],
        ]);
        $inserted++;
    }

    $pdo->commit();
    echo "  Inserted: $inserted rows. Transaction committed.\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERROR during write: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Final summary ─────────────────────────────────────────────────────────────

$elapsed = round(microtime(true) - $tStart, 2);
printf("\nSummary: families_processed=%d  families_fellback=%d  rows_written=%d  global_peak_week=%d  runtime=%.2fs\n",
    $familiesProcessed, $familiesFellback, $inserted, $globalPeakWeek, $elapsed);

echo "[APPLY] Done.\n";
exit(0);
