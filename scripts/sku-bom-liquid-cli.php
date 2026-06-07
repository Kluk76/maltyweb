<?php
declare(strict_types=1);

/**
 * scripts/sku-bom-liquid-cli.php
 *
 * CLI entry point for the liquid-BOM compiler (compile_sku_bom_liquid).
 * Produces a dry-run preview + diff of proposed liquid BOM lines vs current.
 * WRITES NOTHING by default — only produces a JSON preview artifact.
 *
 * Usage:
 *   sudo -u www-data php scripts/sku-bom-liquid-cli.php [--dry-run] [--sku <id,...>] [--out <path>]
 *
 * Flags:
 *   --dry-run        (default) Compute and report without DB writes.
 *   --apply          Reserved: would write to ref_sku_bom. NOT IMPLEMENTED YET.
 *                    The apply path requires operator review of this dry-run first.
 *   --sku <ids>      Comma-separated ref_skus.id list. Omit = all in-scope solo active SKUs.
 *   --out <path>     Path for the JSON preview artifact.
 *                    Default: /var/www/maltytask/data/sku-bom-liquid-preview.json
 *
 * Exit codes:
 *   0  Success (or dry-run completed with 0 errors)
 *   1  Error (unresolved MIs, exception, or --apply attempted)
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$repoRoot = dirname(__DIR__);
require $repoRoot . '/app/db.php';
require $repoRoot . '/app/sku-bom-compile.php';

// ── Parse CLI args ─────────────────────────────────────────────────────────────

$opts   = getopt('', ['dry-run', 'apply', 'sku:', 'out:']);
$apply  = isset($opts['apply']);
$dryRun = !$apply;

if ($apply) {
    fwrite(STDERR, "ERROR: --apply is not yet implemented for the liquid compiler.\n");
    fwrite(STDERR, "Review the dry-run preview first, then apply manually with the predicate.\n");
    exit(1);
}

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

$outPath = $opts['out'] ?? ($repoRoot . '/data/sku-bom-liquid-preview.json');

// ── Run compiler ───────────────────────────────────────────────────────────────

echo "[DRY-RUN] sku-bom-liquid-cli.php\n";
echo "SKU scope: " . ($skuIds !== null ? implode(',', $skuIds) : "all in-scope solo active SKUs") . "\n";
echo "Output: {$outPath}\n";
echo str_repeat('-', 80) . "\n";

$pdo    = maltytask_pdo();
$result = compile_sku_bom_liquid($pdo, $skuIds, true);

// ── Write JSON artifact ─────────────────────────────────────────────────────────

$outDir = dirname($outPath);
if (!is_dir($outDir)) {
    fwrite(STDERR, "ERROR: Output directory does not exist: {$outDir}\n");
    exit(1);
}

file_put_contents($outPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "JSON preview written to: {$outPath}\n\n";

// ── Console report ─────────────────────────────────────────────────────────────

$s = $result['summary'];

echo "SCOPE\n";
echo "  Total active SKUs (incl. composite): {$result['scope']['total_active_skus']}\n";
echo "  Composite excluded:                  {$result['scope']['composite_excluded']}\n";
echo "  Recipe-missing excluded:             {$result['scope']['recipe_excluded']}\n";
echo "  In scope:                            {$result['scope']['in_scope']}\n\n";

echo "SUMMARY\n";
echo "  SKUs gaining liquid lines:  {$s['skus_gaining_liquid']}\n";
echo "  SKUs losing  liquid lines:  {$s['skus_losing_liquid']}\n";
echo "  SKUs no-change (same count):{$s['skus_no_change']}\n";
echo "  Lines ADDED:                {$s['lines_added_total']}\n";
echo "  Lines REMOVED:              {$s['lines_removed_total']}\n";
echo "  Lines CHANGED (qty):        {$s['lines_changed_total']}\n";
echo "  Unresolved MI flags:        " . count($s['unresolved_mi_flags']) . "\n";
echo "  Errors:                     {$s['errors_total']}\n\n";

// Gaining SKU list
if (!empty($s['skus_gaining_codes'])) {
    echo "SKUs gaining liquid lines (" . count($s['skus_gaining_codes']) . "):\n";
    foreach ($s['skus_gaining_codes'] as $code) {
        echo "  + {$code}\n";
    }
    echo "\n";
}

// Unresolved MI flags
if (!empty($s['unresolved_mi_flags'])) {
    echo "UNRESOLVED MI FLAGS (target=0):\n";
    foreach ($s['unresolved_mi_flags'] as $flag) {
        echo "  ! {$flag}\n";
    }
    echo "\n";
}

// Errors
if ($s['errors_total'] > 0) {
    echo "ERRORS:\n";
    foreach ($result['skus'] as $sid => $skuRow) {
        if ($skuRow['error'] !== null) {
            echo "  [{$skuRow['sku_code']}] {$skuRow['error']}\n";
        }
    }
    echo "\n";
}

// ── Per-SKU diff table ─────────────────────────────────────────────────────────

echo str_repeat('-', 80) . "\n";
echo "PER-SKU LIQUID BOM DIFF\n";
echo str_repeat('-', 80) . "\n";
printf("%-14s %3s  %5s %5s  %5s %5s %5s  %s\n",
    'SKU', 'R', 'CUR', 'PROP', 'ADD', 'DEL', 'CHG', 'STATUS'
);
printf("%-14s %3s  %5s %5s  %5s %5s %5s  %s\n",
    str_repeat('-', 14), '---', '-----', '-----', '-----', '-----', '-----', '------'
);

foreach ($result['skus'] as $skuId => $sr) {
    $status = $sr['error'] !== null ? 'ERROR'
        : (empty($sr['diff']['added']) && empty($sr['diff']['removed']) && empty($sr['diff']['changed'])
            ? 'NO_CHANGE'
            : 'DIFF');
    printf("%-14s r%-2d  %5d %5d  %5d %5d %5d  %s\n",
        $sr['sku_code'],
        $sr['recipe_id'],
        $sr['current_liquid_lines'],
        $sr['proposed_liquid_lines'],
        count($sr['diff']['added']),
        count($sr['diff']['removed']),
        count($sr['diff']['changed']),
        $status
    );
    if ($sr['error'] !== null) {
        echo "  ↳ {$sr['error']}\n";
    }
}

echo str_repeat('-', 80) . "\n";

// ── Alternative validation ─────────────────────────────────────────────────────

$av = $result['alternative_validation'];
echo "\nALTERNATIVE (recipe 6) VALIDATION: {$av['status']}\n";
if (!empty($av['checks'])) {
    $c = $av['checks'];
    echo "  (a) PROC_PHOSPHORIQUE present: " . ($c['phosphoric_present'] ? 'YES' : 'NO')
        . " via source=" . ($c['phosphoric_source'] ?? 'n/a') . "\n";
    echo "  (a) Check: {$c['phosphoric_check']}\n";
    echo "  (b) Hops all from observed: " . ($c['hops_all_from_observed'] ? 'YES' : 'NO') . "\n";
    echo "  (b) Observed hops: " . (empty($c['hops_observed_list']) ? '(none)' : implode(', ', $c['hops_observed_list'])) . "\n";
    echo "  (b) Recipe-only hops (should be empty): " . (empty($c['hops_recipe_only_list']) ? '(none)' : implode(', ', $c['hops_recipe_only_list'])) . "\n";
    echo "  (b) Check: {$c['hops_check']}\n";
    echo "  (c) Exclusion check: {$c['exclusion_check']}\n";
    if (!empty($c['recipe_hop_malt_exclusions'])) {
        foreach ($c['recipe_hop_malt_exclusions'] as $item) {
            echo "      - {$item}\n";
        }
    }
}

// ── Apply predicate note ────────────────────────────────────────────────────────

echo "\nAPPLY PREDICATE (when operator approves):\n";
echo "  For each in-scope SKU:\n";
echo "    DELETE FROM ref_sku_bom\n";
echo "     WHERE sku_id = :sku_id\n";
echo "       AND source = 'Brewing'\n";
echo "       AND (bom_source IS NULL OR bom_source = 'liquid')\n";
echo "       AND mi_id IS NOT NULL;\n";
echo "    Then INSERT proposed lines with bom_source='liquid', source='Brewing'.\n";
echo "    Safe-zone: NEVER touches Packaging, composite_liquid, or composite_packaging rows.\n";

// ── Apply-gate 15: oldest-invoice transparency table ─────────────────────────
// Operator ruling 2026-06-07. Emitted for every (recipe, MI) pair where the
// oldest-invoice costing rule fired. Substitution is explicit, never silent.

$gate15 = $result['gate15_oldest_invoice'] ?? [];
echo "\n" . str_repeat('=', 80) . "\n";
echo "APPLY-GATE 15 — OLDEST-INVOICE COSTING TRANSPARENCY\n";
echo "  Rule: basis-window END < MI's earliest date_received → cost from oldest delivery.\n";
echo "  Operator ruling: 2026-06-07 | Dispatch: DRY-RUN ONLY (zero ref_sku_bom writes)\n";
echo str_repeat('=', 80) . "\n";

if (empty($gate15)) {
    echo "  (no oldest-invoice substitutions triggered)\n";
} else {
    printf("  Total substitutions: %d (recipe/MI pairs)\n\n", count($gate15));
    printf("%-5s %-10s %-22s %-12s %-12s %-10s %-10s %-10s\n",
        'R_ID', 'MI_CODE', 'BASIS_END', 'MI_FIRST_DEL', 'OLDEST_CHF', 'WAC_CHF', 'DELTA_PHL', 'DEL_ID'
    );
    printf("%-5s %-10s %-22s %-12s %-12s %-10s %-10s %-10s\n",
        str_repeat('-', 5), str_repeat('-', 10), str_repeat('-', 22),
        str_repeat('-', 12), str_repeat('-', 12),
        str_repeat('-', 10), str_repeat('-', 10), str_repeat('-', 10)
    );
    foreach ($gate15 as $row) {
        $wac   = $row['current_wac_chf'] !== null ? sprintf('%.6f', $row['current_wac_chf']) : 'n/a';
        $delta = ($row['current_wac_chf'] !== null)
            ? sprintf('%+.6f', ($row['oldest_chf_unit'] - $row['current_wac_chf']) * $row['per_hl'])
            : 'n/a';
        printf("%-5d %-10s %-22s %-12s %-12.6f %-10s %-10s %-10d\n",
            $row['recipe_id'],
            $row['mi_code'],
            $row['basis_window_end'] ?? 'NULL',
            $row['mi_earliest_delivery'],
            $row['oldest_chf_unit'],
            $wac,
            $delta,
            $row['oldest_delivery_id']
        );
    }
    echo "\nColumns: R_ID=recipe_id, BASIS_END=latest basis-batch date, MI_FIRST_DEL=MI's earliest delivery,\n";
    echo "  OLDEST_CHF=total_chf/qty_delivered of oldest delivery, WAC_CHF=current v_mi_cost.cost_chf,\n";
    echo "  DELTA_PHL=(oldest_chf_unit - wac) × per_hl [CHF delta per HL], DEL_ID=inv_deliveries.id.\n";
}
echo str_repeat('=', 80) . "\n";

echo "\nAGGREGATOR LOCATION: {$result['aggregator_location']}\n";
echo "\n[DRY-RUN] No DB writes performed. Review JSON preview then apply with explicit --apply.\n";

exit($s['errors_total'] > 0 ? 1 : 0);
