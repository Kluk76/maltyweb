<?php
declare(strict_types=1);

/**
 * scripts/sku-bom-compile-cli.php
 *
 * CLI entry point for the packaging-only ref_sku_bom recompute service.
 *
 * Usage:
 *   php scripts/sku-bom-compile-cli.php [--dry-run] [--apply] [--sku <id,...>]
 *
 * Flags:
 *   --dry-run   (default) Compute and report without writing.
 *   --apply     Write changes. Requires explicit flag.
 *   --sku <ids> Comma-separated ref_skus.id list to recompute.
 *               Omit to auto-detect the 25 affected SKUs (those with NULL mi_id + source='Packaging').
 *
 * Exit codes:
 *   0  Success (or dry-run completed)
 *   1  Parity gate tripped or other error
 *
 * Example:
 *   php scripts/sku-bom-compile-cli.php --dry-run
 *   php scripts/sku-bom-compile-cli.php --apply
 *   php scripts/sku-bom-compile-cli.php --apply --sku 56,94,95
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────

$repoRoot = dirname(__DIR__);
require $repoRoot . '/app/db.php';
require $repoRoot . '/app/sku-bom-compile.php';

// ── Parse CLI args ───────────────────────────────────────────────────────────

$opts   = getopt('', ['dry-run', 'apply', 'sku:']);
$apply  = isset($opts['apply']);
$dryRun = !$apply;                        // dry-run is the default

$skuIds = null;
if (isset($opts['sku'])) {
    $skuIds = array_map('intval', explode(',', (string)$opts['sku']));
    $skuIds = array_filter($skuIds, fn($id) => $id > 0);
    $skuIds = array_values($skuIds);
    if (empty($skuIds)) {
        fwrite(STDERR, "ERROR: --sku requires a comma-separated list of positive integers.\n");
        exit(1);
    }
}

// ── Run ──────────────────────────────────────────────────────────────────────

echo ($dryRun ? "[DRY-RUN]" : "[APPLY]") . " sku-bom-compile-cli.php\n";
echo "SKU scope: " . ($skuIds !== null ? implode(',', $skuIds) : "auto-detect NULL packaging rows") . "\n";
echo str_repeat('-', 72) . "\n";

$pdo = maltytask_pdo();

$result = compile_sku_bom_packaging($pdo, $skuIds, $dryRun, true);

// ── Report ───────────────────────────────────────────────────────────────────

$exitCode = 0;

echo "\nPer-SKU preview:\n";
printf("%-14s %-6s %-7s  %6s %6s %5s  %8s %10s  %8s %10s  %s\n",
    'SKU', 'FMT', 'PREFIX',
    'DEL', 'INS', 'RQ',
    'LIQ_ROWS', 'LIQ_COST',
    'LIQ_ROWS_', 'LIQ_COST_',
    'STATUS'
);
printf("%-14s %-6s %-7s  %6s %6s %5s  %8s %10s  %8s %10s  %s\n",
    str_repeat('-',14), str_repeat('-',6), str_repeat('-',7),
    str_repeat('-',6), str_repeat('-',6), str_repeat('-',5),
    str_repeat('-',8), str_repeat('-',10),
    str_repeat('-',8), str_repeat('-',10),
    str_repeat('-',10)
);

foreach ($result['skus'] as $skuId => $s) {
    $status = $s['error'] !== null ? 'ERROR' : ($s['parity_ok'] ? 'OK' : 'PARITY_FAIL');
    printf("%-14s %-6s %-7s  %6d %6d %5d  %8d %10.4f  %8d %10.4f  %s\n",
        $s['sku_code'],
        $s['format_code'],
        $s['sku_prefix'],
        $s['pkg_deleted'],
        $s['pkg_inserted'],
        $s['rq_emitted'],
        $s['liq_rows_before'],
        $s['liq_cost_before'],
        $s['liq_rows_after'],
        $s['liq_cost_after'],
        $status
    );
    if ($s['error'] !== null) {
        echo "  ↳ ERROR: {$s['error']}\n";
        $exitCode = 1;
    }
}

echo str_repeat('-', 72) . "\n";
printf("TOTALS:  pkg_deleted=%d  pkg_inserted=%d  rq_emitted=%d  parity_violations=%d  errors=%d\n",
    $result['total_pkg_deleted'],
    $result['total_pkg_inserted'],
    $result['total_rq_emitted'],
    $result['parity_violations'],
    $result['errors']
);

if ($result['errors'] > 0 || $result['parity_violations'] > 0) {
    $exitCode = 1;
}

if ($dryRun) {
    echo "\n[DRY-RUN] No writes performed. Re-run with --apply to commit.\n";
} else {
    echo "\n[APPLY] Done.\n";
}

// ── NULL-count verification (after apply) ────────────────────────────────────

if (!$dryRun) {
    $nullCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM ref_sku_bom WHERE mi_id IS NULL"
    )->fetchColumn();
    echo "\nPost-apply NULL mi_id count: {$nullCount}";
    if ($nullCount === 0) {
        echo " ✓ (DONE CRITERION MET — migration 139 may now be applied by orchestrator)\n";
    } else {
        echo " ✗ (STILL HAS NULL ROWS — investigate before applying migration 139)\n";
        $exitCode = 1;
    }
}

exit($exitCode);
