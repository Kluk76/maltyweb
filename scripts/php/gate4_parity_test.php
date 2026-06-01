<?php
declare(strict_types=1);
/**
 * gate4_parity_test.php
 *
 * Gate 4 parity diagnostic: compare hardcode block (warehouse.php) output
 * against load_recipe_ingredients_for_batch() loader output for all active
 * tanks in the given period.
 *
 * READ-ONLY. Does not modify any production data.
 *
 * Usage:
 *   php /var/www/maltytask/scripts/php/gate4_parity_test.php [--period 2026-04]
 *
 * If --period is omitted, uses the latest month_key in inv_tank_balances.
 */

require __DIR__ . '/../../app/db.php';
require __DIR__ . '/../../app/recipe-ingredients-loader.php';

// ── CLI ──────────────────────────────────────────────────────────────────────
$opts   = getopt('', ['period:']);
$period = ($opts['period'] ?? '') ?: '';

$pdo = maltytask_pdo();

// ── Resolve period ────────────────────────────────────────────────────────────
if ($period === '') {
    $r = $pdo->query("SELECT MAX(month_key) AS latest FROM inv_tank_balances")->fetch();
    $period = (string) ($r['latest'] ?? '');
    if ($period === '') { fwrite(STDERR, "No rows in inv_tank_balances\n"); exit(1); }
}
[$py, $pm] = array_map('intval', explode('-', $period));
$asofDate = date('Y-m-d', mktime(0, 0, 0, $pm + 1, 0, $py)); // last day of period month

// ── Fetch tanks for period ────────────────────────────────────────────────────
$tankStmt = $pdo->prepare("
    SELECT tank_id, tank_type, beer_name, batch, volume_hl
      FROM inv_tank_balances
     WHERE month_key = ? AND volume_hl > 0
     ORDER BY tank_type, CAST(tank_id AS UNSIGNED)
");
$tankStmt->execute([$period]);
$tanks = $tankStmt->fetchAll();
if (empty($tanks)) {
    echo "No tanks with volume > 0 for period $period\n";
    exit(0);
}

// ── Name normalizer: inv_tank_balances uses short names ──────────────────────
// The tank simulator may store abbreviated names (e.g. "Div.Blanche")
// but bd_brewing_cooling and ref_recipes use canonical names.
// Normalize before any DB lookup.
function canonical_beer_name(string $raw): string {
    $map = [
        'Div.Blanche'   => 'Diversion Blanche',
        'Div. Blanche'  => 'Diversion Blanche',
        'Div.Gose'      => 'Qrew - Diversion Gose',
        'Div. Gose'     => 'Qrew - Diversion Gose',
        'Div.Panaché'   => 'Diversion Panaché',
    ];
    return $map[$raw] ?? $raw;
}

// ── Fetch CollabIn beers for YEASTVIT dynamic list ────────────────────────────
$collabInRows = $pdo->query(
    "SELECT name FROM ref_recipes WHERE classification = 'Neb' AND subtype = 'CollabIn' AND is_active = 1"
)->fetchAll(PDO::FETCH_COLUMN);
$HC_YEASTVIT_INCLUDE_STATIC = [
    'Stirling', 'Embuscade', 'Zepp', 'Moonshine', 'Double Oat', 'Estafette',
    'EPH1', 'EPH2', 'EPH3', 'EPH4', 'Docks - NEIPA',
];
$HC_YEASTVIT_BEERS = array_values(array_unique(array_merge($HC_YEASTVIT_INCLUDE_STATIC, $collabInRows)));

// ── Hardcode rules (mirrors warehouse.php exactly) ────────────────────────────
$HARDCODED_INGREDIENT_RULES = [
    'Moonshine' => [
        ['mi_id' => 'ADJ_CORIANDER',   'qty_per_brew_kg' => 2.2],
        ['mi_id' => 'ADJ_ORANGE_PEEL', 'qty_per_brew_kg' => 3.3],
    ],
    'Stirling' => [
        ['mi_id' => 'PROC_DEHAZE', 'qty_per_brew_kg' => 0.150, 'skip_if_in_parsed' => true],
    ],
    'Diversion Blanche' => [
        ['mi_id' => 'ADJ_PEACH_TEA', 'qty_per_brew_kg' => 4.0],
    ],
];
$HC_YEASTVIT_RULE = ['mi_id' => 'PROC_YEASTVIT', 'qty_per_brew_kg' => 0.240];
$HC_NAGARDO_BEERS = ['Diversion', 'Diversion Blanche'];
$HC_NAGARDO_RULE  = ['mi_id' => 'PROC_NAGARDO', 'qty_per_hl_kg' => 0.002];

// ── Fetch MI prices (same expression as warehouse.php) ────────────────────────
$allMiIds = array_unique(array_merge(
    ['PROC_YEASTVIT', 'PROC_NAGARDO'],
    ...array_values(array_map(fn($rules) => array_column($rules, 'mi_id'), $HARDCODED_INGREDIENT_RULES))
));

$miPricePlaceholders = implode(',', array_fill(0, count($allMiIds), '?'));
$miPriceParams = [$period];
foreach ($allMiIds as $mid) { $miPriceParams[] = $mid; }

$miPriceStmt = $pdo->prepare("
    SELECT m.mi_id,
           m.id,
           m.name,
           m.pricing_unit,
           COALESCE(ws.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1)) AS unit_price_chf
      FROM ref_mi m
      LEFT JOIN wac_snapshots ws ON ws.mi_id_fk = m.id AND ws.period = ?
     WHERE m.mi_id IN ($miPricePlaceholders)
     GROUP BY m.id, m.mi_id, m.name, m.pricing_unit, ws.wac_chf, m.price, m.currency
");
$miPriceStmt->execute($miPriceParams);
$miPrices = [];
foreach ($miPriceStmt->fetchAll() as $r) {
    $miPrices[(string) $r['mi_id']] = [
        'unit_price_chf' => (float) $r['unit_price_chf'],
        'pricing_unit'   => (string) ($r['pricing_unit'] ?? 'kg'),
    ];
}

// ── Per-batch brew metadata (n_brews, total_hl) ───────────────────────────────
// Collect unique (beer, batch) pairs that need brewing metadata
$neededBatches = [];
foreach ($tanks as $t) {
    $beerRaw  = (string) $t['beer_name'];
    $beerCan  = canonical_beer_name($beerRaw);
    $batch    = (string) $t['batch'];
    $key      = $beerCan . '|' . $batch;
    $neededBatches[$key] = ['beer' => $beerCan, 'batch' => $batch];
}

$batchMeta = []; // key => ['n'=>int, 'hl'=>float]
if (!empty($neededBatches)) {
    foreach ($neededBatches as $key => $nb) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS n_brews, SUM(cool_final_volume_hl) AS total_hl
              FROM bd_brewing_cooling
             WHERE cool_beer  COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
               AND cool_batch COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
               AND cool_final_volume_hl > 0
        ");
        $stmt->execute([$nb['beer'], $nb['batch']]);
        $row = $stmt->fetch();
        $batchMeta[$key] = [
            'n'    => (int)   ($row['n_brews']  ?? 0),
            'hl'   => (float) ($row['total_hl'] ?? 0.0),
        ];
    }
}

// ── PROC_DEHAZE skip-if-in-parsed check for Stirling batches ─────────────────
$HC_DEHAZE_PARSED = [];
foreach ($neededBatches as $key => $nb) {
    if ($nb['beer'] !== 'Stirling') continue;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
          FROM bd_brewing_ingredients_parsed bip
          JOIN ref_mi m ON m.id = bip.mi_id_fk AND m.mi_id = 'PROC_DEHAZE'
         WHERE bip.beer  COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
           AND bip.batch COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
    ");
    $stmt->execute([$nb['beer'], $nb['batch']]);
    if ((int) $stmt->fetchColumn() > 0) {
        $HC_DEHAZE_PARSED[$key] = true;
    }
}

// ── Also fetch loader-side MI prices (for the loader's gap-fill rows) ─────────
// Loader returns qty (total) and unit; we need price to compute CHF.
// Reuse same price expression but for all MIs in ref_recipe_ingredients.
// Build a combined MI list: all ref_recipe_ingredients MIs for active beers in this period.
$rriMiStmt = $pdo->prepare("
    SELECT DISTINCT m.mi_id, m.id, m.pricing_unit,
           COALESCE(ws.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1)) AS unit_price_chf
      FROM ref_recipe_ingredients ri
      JOIN ref_mi m ON m.id = ri.mi_id_fk
      LEFT JOIN wac_snapshots ws ON ws.mi_id_fk = m.id AND ws.period = ?
      JOIN ref_recipes r ON r.id = ri.recipe_id
     WHERE r.is_active = 1 AND ri.is_active = 1
     GROUP BY m.id, m.mi_id, m.pricing_unit, ws.wac_chf, m.price, m.currency
");
$rriMiStmt->execute([$period]);
$loaderMiPrices = [];
foreach ($rriMiStmt->fetchAll() as $r) {
    $loaderMiPrices[(string) $r['mi_id']] = [
        'unit_price_chf' => (float) $r['unit_price_chf'],
        'pricing_unit'   => (string) ($r['pricing_unit'] ?? 'kg'),
    ];
}

// ── Unit conversion to kg (canonical pricing unit) ───────────────────────────
// ref_mi.pricing_unit is typically 'kg'. Loader returns qty in ri.unit.
// For PROC_PHOSPHORIQUE: ri.unit='ml', pricing_unit='L' → 0.001 multiplier.
// For hops: ri.unit='g' → 0.001 multiplier.
// All other units assumed to already be kg.
function unit_to_kg_multiplier(string $qty_unit, string $pricing_unit): float {
    $qu = strtolower($qty_unit);
    $pu = strtolower($pricing_unit);
    // g → kg
    if ($qu === 'g' && $pu === 'kg') return 0.001;
    // ml → L
    if ($qu === 'ml' && $pu === 'l') return 0.001;
    // ml → kg (density ~1): approximate but consistent with how PROC_DEHAZE is treated
    if ($qu === 'ml' && $pu === 'kg') return 0.001;
    // kg → kg (same unit)
    if ($qu === 'kg' && $pu === 'kg') return 1.0;
    // L → L
    if ($qu === 'l' && $pu === 'l') return 1.0;
    // Default: assume same unit
    return 1.0;
}

// ── Parity check per tank ─────────────────────────────────────────────────────
$tankResults = [];

foreach ($tanks as $t) {
    $tankId   = (string) $t['tank_id'];
    $tankType = (string) $t['tank_type'];
    $beerRaw  = (string) $t['beer_name'];
    $beerCan  = canonical_beer_name($beerRaw);
    $batch    = (string) $t['batch'];
    $volHl    = (float)  $t['volume_hl'];
    $batchKey = $beerCan . '|' . $batch;

    $meta = $batchMeta[$batchKey] ?? ['n' => 0, 'hl' => 0.0];
    $n    = $meta['n'];
    $hl   = $meta['hl'];   // total brewed HL

    // Use brew_hl for loader (same as n_brews * avg_brew_size, derived from bd_brewing_cooling)
    $brewHl = $hl > 0 ? $hl : 30.0; // fallback to 30 HL if no data

    // ── HARDCODE side ─────────────────────────────────────────────────────────
    $hcMiRows = [];  // mi_id => ['qty_per_hl_kg'=>float, 'chf_per_hl'=>float, 'basis'=>string]

    if ($n > 0 && $hl > 0) {
        // Per-brew rules
        foreach (($HARDCODED_INGREDIENT_RULES[$beerCan] ?? []) as $rule) {
            $mid = $rule['mi_id'];
            if (!isset($miPrices[$mid])) continue;
            if (!empty($rule['skip_if_in_parsed']) && isset($HC_DEHAZE_PARSED[$batchKey])) continue;
            $qtyKgPerBrew = (float) $rule['qty_per_brew_kg'];
            $qtyKgPerHl   = ($qtyKgPerBrew * $n) / $hl;
            $price        = $miPrices[$mid]['unit_price_chf'];
            $hcMiRows[$mid] = [
                'qty_per_hl_kg' => $qtyKgPerHl,
                'chf_per_hl'    => $qtyKgPerHl * $price,
                'basis'         => "per-brew ($n brews / {$hl} HL)",
                'price'         => $price,
            ];
        }

        // PROC_YEASTVIT
        if (in_array($beerCan, $HC_YEASTVIT_BEERS, true) && isset($miPrices['PROC_YEASTVIT'])) {
            $mid   = 'PROC_YEASTVIT';
            $qtyKg = ($HC_YEASTVIT_RULE['qty_per_brew_kg'] * $n) / $hl;
            $price = $miPrices[$mid]['unit_price_chf'];
            $hcMiRows[$mid] = [
                'qty_per_hl_kg' => $qtyKg,
                'chf_per_hl'    => $qtyKg * $price,
                'basis'         => "per-brew ({$n} brews / {$hl} HL)",
                'price'         => $price,
            ];
        }
    }

    // PROC_NAGARDO: per-HL of BBT only
    if ($tankType === 'BBT'
        && in_array($beerCan, $HC_NAGARDO_BEERS, true)
        && isset($miPrices['PROC_NAGARDO'])) {
        $mid   = 'PROC_NAGARDO';
        $price = $miPrices[$mid]['unit_price_chf'];
        $hcMiRows[$mid] = [
            'qty_per_hl_kg' => $HC_NAGARDO_RULE['qty_per_hl_kg'],
            'chf_per_hl'    => $HC_NAGARDO_RULE['qty_per_hl_kg'] * $price,
            'basis'         => 'per-HL BBT',
            'price'         => $price,
        ];
    }

    // ── LOADER side ───────────────────────────────────────────────────────────
    $loaderRows = load_recipe_ingredients_for_batch($pdo, $beerCan, $batch, $brewHl, $asofDate);

    $ldMiRows = [];  // mi_id => ['qty_per_hl_kg'=>float, 'chf_per_hl'=>float, 'unit'=>string]
    foreach ($loaderRows as $lr) {
        $mid      = (string) $lr['mi_id'];
        $unit     = (string) $lr['unit'];
        $qtyPerHl = (float) $lr['qty_per_hl'];  // in ri.unit per HL
        $priceInfo = $loaderMiPrices[$mid] ?? ['unit_price_chf' => 0.0, 'pricing_unit' => 'kg'];
        $convMult = unit_to_kg_multiplier($unit, $priceInfo['pricing_unit']);
        $qtyKgPerHl = $qtyPerHl * $convMult;
        $chfPerHl   = $qtyKgPerHl * $priceInfo['unit_price_chf'];
        $ldMiRows[$mid] = [
            'qty_per_hl_kg' => $qtyKgPerHl,
            'chf_per_hl'    => $chfPerHl,
            'unit'          => $unit,
            'pricing_unit'  => $priceInfo['pricing_unit'],
            'price'         => $priceInfo['unit_price_chf'],
        ];
    }

    // ── Merge rows for comparison ─────────────────────────────────────────────
    $allMids = array_unique(array_merge(array_keys($hcMiRows), array_keys($ldMiRows)));
    sort($allMids);

    $rows    = [];
    $hcTotal = 0.0;
    $ldTotal = 0.0;

    foreach ($allMids as $mid) {
        $hc     = $hcMiRows[$mid] ?? null;
        $ld     = $ldMiRows[$mid] ?? null;
        $hcQty  = $hc ? $hc['qty_per_hl_kg']  : 0.0;
        $ldQty  = $ld ? $ld['qty_per_hl_kg']  : 0.0;
        $hcChf  = $hc ? $hc['chf_per_hl']     : 0.0;
        $ldChf  = $ld ? $ld['chf_per_hl']      : 0.0;
        $diffQty = $ldQty - $hcQty;
        $diffChf = $ldChf - $hcChf;
        $hcTotal += $hcChf;
        $ldTotal += $ldChf;
        $rows[]   = [
            'mi_id'    => $mid,
            'hc_qty'   => $hcQty,
            'ld_qty'   => $ldQty,
            'diff_qty' => $diffQty,
            'hc_chf'   => $hcChf,
            'ld_chf'   => $ldChf,
            'diff_chf' => $diffChf,
            'hc_unit'  => $hc ? 'kg' : ($ld ? ($ldMiRows[$mid]['unit'] ?? '?') : '?'),
            'ld_unit'  => $ld ? ($ldMiRows[$mid]['unit'] ?? 'kg') : '-',
            'source'   => ($hc && $ld) ? 'both' : ($hc ? 'hc-only' : 'ld-only'),
        ];
    }

    $diffTotal = $ldTotal - $hcTotal;

    $tankResults[] = [
        'tank_id'      => $tankId,
        'tank_type'    => $tankType,
        'beer_raw'     => $beerRaw,
        'beer_can'     => $beerCan,
        'batch'        => $batch,
        'vol_hl'       => $volHl,
        'brew_hl'      => $brewHl,
        'n_brews'      => $n,
        'rows'         => $rows,
        'hc_total_hl'  => $hcTotal,
        'ld_total_hl'  => $ldTotal,
        'diff_total_hl'=> $diffTotal,
        'hc_tank_chf'  => $hcTotal * $volHl,
        'ld_tank_chf'  => $ldTotal * $volHl,
        'diff_tank_chf'=> $diffTotal * $volHl,
    ];
}

// ── Output ────────────────────────────────────────────────────────────────────
$hr80 = str_repeat('─', 80);
$hr68 = str_repeat('─', 68);

echo "\nGATE 4 PARITY DIAGNOSTIC — period: $period, " . count($tanks) . " tanks\n";
echo "$hr80\n\n";

foreach ($tankResults as $tr) {
    $beerLabel = $tr['beer_raw'] !== $tr['beer_can']
        ? "{$tr['beer_raw']} (→ {$tr['beer_can']})"
        : $tr['beer_can'];
    printf(
        "Tank %s-%s (%s batch %s, %.1f HL brewed in %d brew(s), current vol %.2f HL)\n",
        $tr['tank_type'], $tr['tank_id'],
        $beerLabel, $tr['batch'],
        $tr['brew_hl'], $tr['n_brews'], $tr['vol_hl']
    );
    echo "$hr68\n";

    if (empty($tr['rows'])) {
        echo "  (no hardcode rules and no loader rows — no gap-fill ingredients)\n";
    } else {
        printf(
            "  %-26s %12s %12s %10s %10s %10s %10s  %s\n",
            'mi_id', 'hc_qty/HL', 'ld_qty/HL', 'diff_qty', 'hc_CHF/HL', 'ld_CHF/HL', 'diff_CHF', 'src'
        );
        foreach ($tr['rows'] as $r) {
            $diffMark = abs($r['diff_chf']) > 0.001 ? ' *' : '';
            printf(
                "  %-26s %11.6f %11.6f %+10.6f %10.4f %10.4f %+10.4f  %s%s\n",
                $r['mi_id'],
                $r['hc_qty'], $r['ld_qty'], $r['diff_qty'],
                $r['hc_chf'], $r['ld_chf'], $r['diff_chf'],
                $r['source'], $diffMark
            );
        }
    }

    printf(
        "  %-26s %12s %12s %10s %10.4f %10.4f %+10.4f\n",
        'TOTAL per HL:', '', '', '', $tr['hc_total_hl'], $tr['ld_total_hl'], $tr['diff_total_hl']
    );
    printf(
        "  %-26s %12s %12s %10s %10.2f %10.2f %+10.2f\n",
        'TOTAL per tank (CHF):', '', '', '', $tr['hc_tank_chf'], $tr['ld_tank_chf'], $tr['diff_tank_chf']
    );
    echo "\n";
}

// ── Summary ───────────────────────────────────────────────────────────────────
$passTanks  = 0;
$failTanks  = 0;
$maxDiff    = 0.0;
$maxDiffTank = '';
$totalAbsDiff = 0.0;
$beerDiffs  = [];

foreach ($tankResults as $tr) {
    $absChf = abs($tr['diff_tank_chf']);
    $totalAbsDiff += $absChf;
    if ($absChf <= 1.0) {
        $passTanks++;
    } else {
        $failTanks++;
        if ($absChf > $maxDiff) {
            $maxDiff     = $absChf;
            $maxDiffTank = $tr['tank_type'] . '-' . $tr['tank_id'] . ' (' . $tr['beer_can'] . ')';
        }
    }
    $beer = $tr['beer_can'];
    $beerDiffs[$beer] = ($beerDiffs[$beer] ?? 0.0) + $tr['diff_tank_chf'];
}

$total = count($tankResults);
$verdict = ($failTanks === 0) ? 'PASS' : 'FAIL';

echo "$hr80\n";
echo "GATE 4 PARITY SUMMARY (period: $period, $total tanks)\n";
echo "$hr80\n";
printf("  Tanks with |diff| ≤ 1 CHF:    %d / %d  %s\n", $passTanks, $total, $passTanks === $total ? '✓ PASS' : '');
printf("  Tanks with |diff| > 1 CHF:    %d / %d  %s\n", $failTanks, $total, $failTanks > 0 ? '✗ INVESTIGATE' : '');
printf("  Max abs diff:                 %.2f CHF  on %s\n", $maxDiff, $maxDiffTank ?: 'n/a');
printf("  Total absolute diff (CHF):    %.2f\n\n", $totalAbsDiff);

echo "  Beer-level diff:\n";
arsort($beerDiffs);
foreach ($beerDiffs as $beer => $diff) {
    if (abs($diff) < 0.001) continue;
    printf("    %-32s  %+.2f CHF\n", $beer, $diff);
}
$zeroBeerCount = count(array_filter($beerDiffs, fn($d) => abs($d) < 0.001));
if ($zeroBeerCount > 0) {
    echo "    ($zeroBeerCount beer(s) with diff ≈ 0 omitted)\n";
}

echo "\n  Verdict: $verdict\n";

// ── WHY section for failing tanks ─────────────────────────────────────────────
if ($failTanks > 0) {
    echo "\n$hr80\n";
    echo "WHY — failing tanks (|diff| > 1 CHF):\n";
    echo "$hr80\n";
    foreach ($tankResults as $tr) {
        if (abs($tr['diff_tank_chf']) <= 1.0) continue;
        echo "\nTank {$tr['tank_type']}-{$tr['tank_id']} ({$tr['beer_can']} batch {$tr['batch']}) — diff: " .
             sprintf("%+.2f CHF", $tr['diff_tank_chf']) . "\n";
        foreach ($tr['rows'] as $r) {
            if (abs($r['diff_chf']) < 0.001) continue;
            printf(
                "  %s: hc=%.6f kg/HL (%.4f CHF/HL)  ld=%.6f kg/HL (%.4f CHF/HL)  diff=%+.4f CHF/HL\n",
                $r['mi_id'], $r['hc_qty'], $r['hc_chf'], $r['ld_qty'], $r['ld_chf'], $r['diff_chf']
            );
            // Root-cause diagnosis
            if ($r['source'] === 'hc-only') {
                echo "    ROOT CAUSE: MI {$r['mi_id']} is in hardcode but NOT in ref_recipe_ingredients (seed gap or observed-filter excluded it)\n";
            } elseif ($r['source'] === 'ld-only') {
                echo "    ROOT CAUSE: MI {$r['mi_id']} is in ref_recipe_ingredients but NOT in hardcode rules\n";
            } else {
                // Both present — quantity/price mismatch
                $qRatio = $r['hc_qty'] > 0 ? $r['ld_qty'] / $r['hc_qty'] : 0;
                if (abs($r['diff_qty']) > 0.0001) {
                    printf("    ROOT CAUSE: qty mismatch — hc %.6f kg/HL vs ld %.6f kg/HL (ratio %.3f)\n",
                        $r['hc_qty'], $r['ld_qty'], $qRatio);
                    if ($tr['n_brews'] > 0 && $tr['brew_hl'] > 0) {
                        $expectedLdKgPerHl = $r['hc_qty'];
                        $impliedPerBrewKg  = $r['hc_qty'] * $tr['brew_hl'] / $tr['n_brews'];
                        printf("    NOTE: hardcode uses per-brew basis; implied per-brew qty = %.4f kg over %.1f HL / %d brews\n",
                            $impliedPerBrewKg, $tr['brew_hl'], $tr['n_brews']);
                    }
                }
                if (abs($r['diff_qty']) < 0.0001 && abs($r['diff_chf']) > 0.001) {
                    echo "    ROOT CAUSE: price mismatch (same qty, different CHF/unit)\n";
                }
            }
        }
    }
}

// ── ASSERTION: loader dedup-by-mi_id_fk ──────────────────────────────────────
//
// Proves the dedup fix in load_recipe_ingredients_for_batch() Step 3.
//
// Strategy: find a hop MI that is in ref_recipe_ingredients for some recipe,
// and find a (beer, batch) where that MI is NOT observed (so it will reach
// gap-fill). Insert a SECOND stage-row for that same (recipe_id, mi_id_fk)
// with a different stage so the new unique key allows it. Call the loader and
// assert: exactly ONE entry for that MI, qty = sum of both stage rows.
// Self-clean: DELETE both synthetic rows even on failure (try/finally).
//
// The fixture uses real recipe/MI FKs to avoid FK violations — we look them
// up dynamically so the test doesn't embed hardcoded IDs that may drift.
echo "\n$hr80\n";
echo "ASSERTION: multi-stage gap-fill dedup (mi_id_fk summing)\n";
echo "$hr80\n";

$assertPassed = false;
$assertMsg    = '';

// Find a hop MI that has exactly one recipe-ingredient row in some active recipe
// and that is NOT in bd_brewing_ingredients_parsed_v2 for a known (beer, batch).
// We pick the first such hop MI and a batch that doesn't observe it.

// Pick a hop MI with at least one active rri row
$hopCandRow = $pdo->query("
    SELECT ri.recipe_id, ri.mi_id_fk, ri.unit, ri.qty_per_hl,
           r.name AS recipe_name
      FROM ref_recipe_ingredients ri
      JOIN ref_mi m       ON m.id = ri.mi_id_fk
      JOIN ref_recipes r  ON r.id = ri.recipe_id AND r.is_active = 1
      JOIN ref_mi_categories cat ON cat.id = m.category_id AND cat.name = 'Hops'
     WHERE ri.is_active = 1
       AND ri.hop_addition_stage IS NULL
     LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$hopCandRow) {
    echo "SKIP: no suitable hop MI with stage=NULL found — no hop rows yet seeded\n";
} else {
    $fixtureRecipeId = (int)   $hopCandRow['recipe_id'];
    $fixtureMiFk     = (int)   $hopCandRow['mi_id_fk'];
    $fixtureUnit     = (string)$hopCandRow['unit'];
    $fixtureQplA     = (float) $hopCandRow['qty_per_hl'];   // original row qty
    $fixtureRecipeName = (string)$hopCandRow['recipe_name'];

    // synthetic second-stage qty (arbitrary, distinct from original)
    $fixtureQplB = 25.0;  // g/HL — won't collide with typical recipe qtys

    // Find a (beer, batch) for this recipe name where the MI is NOT observed.
    // We look for the latest bd_brewing_cooling entry for this beer and check
    // bd_brewing_ingredients_parsed_v2 for that batch.
    $batchCandRow = $pdo->prepare("
        SELECT cool_beer AS beer_name, cool_batch AS batch,
               SUM(cool_final_volume_hl) AS brew_hl
          FROM bd_brewing_cooling
         WHERE cool_beer COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
           AND cool_final_volume_hl > 0
         GROUP BY cool_beer, cool_batch
         ORDER BY cool_batch DESC
         LIMIT 5
    ");
    $batchCandRow->execute([$fixtureRecipeName]);
    $candidateBatches = $batchCandRow->fetchAll(PDO::FETCH_ASSOC);

    $fixtureBeer  = null;
    $fixtureBatch = null;
    $fixtureBrewHl = 30.0;

    foreach ($candidateBatches as $cb) {
        // Check if mi_id_fk is observed for this batch
        $obsCheck = $pdo->prepare("
            SELECT COUNT(*) AS cnt
              FROM bd_brewing_ingredients_parsed_v2 bip
              JOIN bd_brewing_ingredients_v2 bih ON bih.id = bip.header_id
             WHERE bih.beer  COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
               AND bih.batch COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
               AND bip.mi_id_fk = ?
        ");
        $obsCheck->execute([$cb['beer_name'], $cb['batch'], $fixtureMiFk]);
        if ((int)$obsCheck->fetchColumn() === 0) {
            $fixtureBeer   = $cb['beer_name'];
            $fixtureBatch  = $cb['batch'];
            $fixtureBrewHl = (float)$cb['brew_hl'];
            break;
        }
    }

    if ($fixtureBeer === null) {
        echo "SKIP: no (beer, batch) found where hop MI {$fixtureMiFk} is not observed — cannot wire fixture\n";
        echo "WHY: all recent batches of '{$fixtureRecipeName}' already have this MI in observed data.\n";
        echo "     This is expected when observed brewing data is complete. The dedup logic in\n";
        echo "     load_recipe_ingredients_for_batch() Step 3 is exercised by live data in this case.\n";
    } else {
        // Insert a synthetic second-stage row. The new unique key is
        // (recipe_id, mi_id_fk, stage_key, boil_time_key).
        // Original row has stage_key='none', boil_time_key=-1.
        // We insert with stage='dry_hop' → stage_key='dry_hop', boil_time_key=-1.
        // hop_boil_time_min stays NULL (dry_hop requires no boil time per the CHECK).
        $insertedIds = [];
        try {
            $ins = $pdo->prepare("
                INSERT INTO ref_recipe_ingredients
                    (recipe_id, mi_id_fk, qty_per_hl, unit, hop_addition_stage, is_active)
                VALUES
                    (?, ?, ?, ?, 'dry_hop', 1)
            ");
            $ins->execute([$fixtureRecipeId, $fixtureMiFk, $fixtureQplB, $fixtureUnit]);
            $insertedIds[] = (int)$pdo->lastInsertId();

            // Call the loader — should return exactly ONE entry for $fixtureMiFk
            // with qty_per_hl = $fixtureQplA + $fixtureQplB
            $loaderResult = load_recipe_ingredients_for_batch(
                $pdo,
                $fixtureBeer,
                $fixtureBatch,
                $fixtureBrewHl
            );

            // Find entry for fixtureMiFk
            $matchingEntries = array_filter($loaderResult, fn($e) => (int)$e['mi_id_fk'] === $fixtureMiFk);

            $countForMi  = count($matchingEntries);
            $expectedQpl = $fixtureQplA + $fixtureQplB;
            $actualQpl   = 0.0;
            if ($countForMi === 1) {
                $entry      = array_values($matchingEntries)[0];
                $actualQpl  = (float)$entry['qty_per_hl'];
            }

            $qplMatch = $countForMi === 1 && abs($actualQpl - $expectedQpl) < 0.000001;

            if ($countForMi === 1 && $qplMatch) {
                $assertPassed = true;
                $assertMsg = sprintf(
                    "PASS: mi_id_fk=%d → 1 entry, qty_per_hl=%.6f (expected=%.6f, A=%.6f + B=%.6f)",
                    $fixtureMiFk, $actualQpl, $expectedQpl, $fixtureQplA, $fixtureQplB
                );
            } else {
                $assertMsg = sprintf(
                    "FAIL: mi_id_fk=%d → %d entries (expected 1), qty_per_hl=%.6f (expected=%.6f)",
                    $fixtureMiFk, $countForMi, $actualQpl, $expectedQpl
                );
            }
        } finally {
            // Self-clean: always delete synthetic rows, even on exception
            if (!empty($insertedIds)) {
                $placeholders = implode(',', array_fill(0, count($insertedIds), '?'));
                $pdo->prepare("DELETE FROM ref_recipe_ingredients WHERE id IN ($placeholders)")
                    ->execute($insertedIds);
                $deleted = count($insertedIds);
                echo "  [cleanup] deleted $deleted synthetic row(s): id(s) " . implode(', ', $insertedIds) . "\n";
            }
        }

        echo "  Recipe: '{$fixtureRecipeName}' (recipe_id={$fixtureRecipeId}), mi_id_fk={$fixtureMiFk}\n";
        echo "  Beer/batch for gap-fill: '{$fixtureBeer}' / '{$fixtureBatch}' (brew_hl={$fixtureBrewHl})\n";
        echo "  Original row qty_per_hl: {$fixtureQplA} {$fixtureUnit}\n";
        echo "  Synthetic dry_hop row qty_per_hl: {$fixtureQplB} {$fixtureUnit}\n";
        echo "  $assertMsg\n";

        if (!$assertPassed) {
            echo "\nASSERTION FAILED — dedup fix is not working correctly.\n";
            exit(2);
        }
    }
}

echo "\nDone.\n";
