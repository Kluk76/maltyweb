<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";
require_page_access('warehouse');

// ── Input validation (two-step per feedback_php_query_param_validate_after_default) ──
$period = $_GET['period'] ?? '';
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
    // Default: last closed month (today's month - 1)
    $period = (new DateTimeImmutable('first day of last month'))->format('Y-m');
}

$format = $_GET['format'] ?? 'csv';
if ($format !== 'csv') $format = 'csv';

$today     = date('Y-m-d');
$filename  = "warehouse-gl-recap-{$period}-{$today}.csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

// ── GL labels ────────────────────────────────────────────────────────────────
$glLabels = [
    '4101' => 'Malt',
    '4102' => 'Houblon',
    '4103' => 'Levure',
    '4104' => 'Ingrédients',
    '4200' => 'Emballage (divers)',
    '4201' => 'Bouteilles',
    '4202' => 'Cannettes',
    '4203' => 'Étiquettes',
    '4204' => 'Bouchons',
    '4205' => 'Cartons',
    '4206' => 'Emballage (autre)',
    '4207' => 'Cartons réutilisables',
    '4209' => 'Accessoires fûts',
    '4301' => 'Chimie de nettoyage',
    '4500' => 'R&D',
    '4510' => 'R&D Achats',
    '6285' => 'Logistique',
];

// ── DB connection ────────────────────────────────────────────────────────────
$pdo = maltytask_pdo();

// ── Collect all GL accounts from ref_mi to handle unknown ones dynamically ──
// Store as flat string array (never as array keys, because purely-numeric string
// keys get silently cast to int by PHP — see feedback_php_strict_after_column_type_migration)
$knownGls = [];
try {
    $glStmt = $pdo->query(
        "SELECT DISTINCT COALESCE(m.gl_account, c.default_gl_account) AS gl
           FROM ref_mi m
           JOIN ref_mi_categories c ON c.id = m.category_id
          WHERE m.is_inventoried = 1
            AND COALESCE(m.gl_account, c.default_gl_account) IS NOT NULL
            AND COALESCE(m.gl_account, c.default_gl_account) LIKE '4%'"
    );
    foreach ($glStmt->fetchAll(PDO::FETCH_COLUMN) as $gl) {
        $knownGls[] = (string) $gl;
    }
} catch (Throwable $e) {
    // Non-fatal — unknown GLs will get 'GL xxx' label
}

// ── Helper: label for a GL ────────────────────────────────────────────────────
function gl_label(string $gl, array $staticLabels): string {
    return $staticLabels[$gl] ?? ('GL ' . $gl);
}

// ── FG table availability check ──────────────────────────────────────────────
$fgUnavailable = false;
$fgNotice      = '';
try {
    $fgCheckStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'inv_fg_stocktake'"
    );
    $fgCheckStmt->execute();
    if ((int) $fgCheckStmt->fetchColumn() === 0) {
        $fgUnavailable = true;
        $fgNotice = '# NOTE: inv_fg_stocktake unavailable — FG column shows 0';
    }
} catch (Throwable $e) {
    $fgUnavailable = true;
    $fgNotice = '# NOTE: inv_fg_stocktake check failed — FG column shows 0';
}

// ── Compute as-of date (last day of period) ───────────────────────────────────
[$py, $pm] = array_map('intval', explode('-', $period));
$lastDayNum  = (int) date('t', mktime(0, 0, 0, $pm, 1, $py));
$asOf        = sprintf('%04d-%02d-%02d', $py, $pm, $lastDayNum);

// ── Query 1: RM Inventory by GL ───────────────────────────────────────────────
// Live qty = anchor (latest counted_qty from inv_rm_stocktake)
//           + deliveries since anchor
//           - consumption since anchor
// WAC from wac_snapshots for the period; fallback ref_mi.price × EUR rate
$rmByGl = [];
try {
    $rmSql = "
        WITH
          anchor AS (
            SELECT rm1.mi_id_fk,
                   MAX(rm1.counted_at) AS anchor_at,
                   (SELECT rm2.counted_qty FROM inv_rm_stocktake rm2
                     WHERE rm2.mi_id_fk = rm1.mi_id_fk
                       AND rm2.counted_at = MAX(rm1.counted_at)
                     LIMIT 1) AS anchor_qty
              FROM inv_rm_stocktake rm1
             GROUP BY rm1.mi_id_fk
          ),
          deliveries_since AS (
            SELECT d.ingredient_fk AS mi_id_fk,
                   SUM(d.qty_delivered) AS qty_in
              FROM inv_deliveries d
              JOIN anchor a ON a.mi_id_fk = d.ingredient_fk
             WHERE d.date_received > a.anchor_at
               AND d.date_received <= :asof1
               AND d.status IN ('Active','Pending')
               AND d.exclusion_class IS NULL
             GROUP BY d.ingredient_fk
          ),
          consumption_since AS (
            SELECT c.mi_id_fk,
                   SUM(
                     c.qty * COALESCE(
                       CASE WHEN cu.dimension = su.dimension AND su.to_base_factor > 0
                            THEN cu.to_base_factor / su.to_base_factor
                            ELSE 1
                       END, 1)
                   ) AS qty_out
              FROM inv_consumption c
              JOIN anchor a ON a.mi_id_fk = c.mi_id_fk
              JOIN ref_mi m ON m.id = c.mi_id_fk
              LEFT JOIN ref_units cu ON cu.code = c.unit
              LEFT JOIN ref_units su ON su.code = m.pricing_unit
             WHERE c.consumed_at > a.anchor_at
               AND c.consumed_at <= :asof2
             GROUP BY c.mi_id_fk
          )
        SELECT COALESCE(m.gl_account, c.default_gl_account) AS gl,
               SUM(
                 GREATEST(
                   COALESCE(a.anchor_qty, 0)
                   + COALESCE(ds.qty_in, 0)
                   - COALESCE(cs.qty_out, 0),
                   0
                 )
                 * COALESCE(w.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1), 0)
               ) AS rm_value
          FROM ref_mi m
          JOIN ref_mi_categories c ON c.id = m.category_id
          LEFT JOIN anchor            a  ON a.mi_id_fk  = m.id
          LEFT JOIN deliveries_since  ds ON ds.mi_id_fk = m.id
          LEFT JOIN consumption_since cs ON cs.mi_id_fk = m.id
          LEFT JOIN wac_snapshots     w  ON w.mi_id_fk  = m.id
            AND w.period = :period1
         WHERE m.is_inventoried = 1
           AND COALESCE(m.gl_account, c.default_gl_account) IS NOT NULL
         GROUP BY gl
         ORDER BY gl
    ";
    $rmStmt = $pdo->prepare($rmSql);
    $rmStmt->execute([
        ':asof1'   => $asOf,
        ':asof2'   => $asOf,
        ':period1' => $period,
    ]);
    foreach ($rmStmt->fetchAll() as $r) {
        $gl = (string) ($r['gl'] ?? '');
        if ($gl !== '') $rmByGl[$gl] = (float) ($r['rm_value'] ?? 0);
    }
} catch (Throwable $e) {
    // Leave $rmByGl empty — zeros will show in CSV
    $rmQueryError = $e->getMessage();
}

// ── Query 2: WIP by GL (mirror warehouse.php WIP block) ──────────────────────
// Uses TankSimulator + bd_brewing_ingredients_parsed × WAC, grouped by GL.
$wipByGl = [];
try {
    require_once __DIR__ . '/../../app/tank-simulator.php';
    require_once __DIR__ . '/../../app/recipe-ingredients-loader.php';

    $asOfDT = (new DateTimeImmutable())->setDate($py, $pm, 1)
                ->modify('last day of this month')
                ->setTime(23, 59, 59);

    $sim    = new TankSimulator($pdo);
    $simRes = $sim->run($asOfDT);

    // Collect tankTuples with tank type so BBT-only NAGARDO rule can discriminate.
    $tankTuples = [];
    foreach (($simRes['cct'] ?? []) as $num => $st) {
        if ($st === null) continue;
        $tankTuples[] = [
            'type'      => 'CCT',
            'beer'      => $st['raw_beer'] ?? $st['beer'] ?? '',
            'batch'     => (string) ($st['batch'] ?? ''),
            'volume_hl' => (float)  ($st['volume_hl'] ?? 0),
        ];
    }
    foreach (($simRes['bbt'] ?? []) as $num => $st) {
        if ($st === null) continue;
        $tankTuples[] = [
            'type'      => 'BBT',
            'beer'      => $st['raw_beer'] ?? $st['beer'] ?? '',
            'batch'     => (string) ($st['batch'] ?? ''),
            'volume_hl' => (float)  ($st['volume_hl'] ?? 0),
        ];
    }

    if (!empty($tankTuples)) {
        $miTuples  = [];
        $miParams  = [];
        foreach ($tankTuples as $t) {
            $miTuples[] = 'ROW(?, ?, ?)';
            $miParams[] = $t['beer'];
            $miParams[] = $t['batch'];
            $miParams[] = $t['volume_hl'];
        }
        $miParams[] = $period;  // ws.period

        $wipMiSql = "
            WITH tanks AS (
              SELECT * FROM (VALUES " . implode(',', $miTuples) . ") AS v(beer_name, batch, volume_hl)
            ),
            tank_batches AS (
              SELECT t.beer_name COLLATE utf8mb4_unicode_ci AS beer_name,
                     t.batch     COLLATE utf8mb4_unicode_ci AS batch,
                     t.volume_hl,
                     (SELECT SUM(bc.final_volume)
                        FROM bd_brewing_gravity_v2 bc
                       WHERE bc.event_type = 'Cooling'
                         AND bc.beer  COLLATE utf8mb4_unicode_ci = t.beer_name COLLATE utf8mb4_unicode_ci
                         AND bc.batch COLLATE utf8mb4_unicode_ci = t.batch     COLLATE utf8mb4_unicode_ci
                         AND bc.final_volume > 0) AS total_brewed_hl,
                     (SELECT COUNT(*)
                        FROM bd_brewing_gravity_v2 bc
                       WHERE bc.event_type = 'Cooling'
                         AND bc.beer  COLLATE utf8mb4_unicode_ci = t.beer_name COLLATE utf8mb4_unicode_ci
                         AND bc.batch COLLATE utf8mb4_unicode_ci = t.batch     COLLATE utf8mb4_unicode_ci
                         AND bc.final_volume > 0) AS n_brews
                FROM tanks t
            )
            -- Unit conversion via v_bip_canonical (migration 161): qty_priced is
            -- density-aware (ml→kg uses ref_mi.density_g_per_ml). factor_unresolved
            -- rows yield NULL qty_priced (surfaced via unresolved_count, not dropped).
            SELECT COALESCE(
                     ANY_VALUE(m.gl_account),
                     ANY_VALUE(c.default_gl_account)
                   ) AS gl,
                   SUM(
                     bip.qty_priced
                     * COALESCE(tb.n_brews, 1)
                     * tb.volume_hl / NULLIF(tb.total_brewed_hl, 0)
                     * COALESCE(w.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1))
                   ) AS wip_value,
                   SUM(bip.factor_unresolved) AS unresolved_count
              FROM tank_batches tb
              JOIN bd_brewing_ingredients_v2 bih
                ON bih.beer  COLLATE utf8mb4_unicode_ci = tb.beer_name COLLATE utf8mb4_unicode_ci
               AND bih.batch COLLATE utf8mb4_unicode_ci = tb.batch     COLLATE utf8mb4_unicode_ci
              JOIN v_bip_canonical bip
                ON bip.header_id = bih.id
               AND bip.mi_id_fk IS NOT NULL
              JOIN ref_mi m ON m.id = bip.mi_id_fk
              LEFT JOIN ref_mi_categories c ON c.id = m.category_id
              LEFT JOIN wac_snapshots w ON w.mi_id_fk = m.id AND w.period = ?
             WHERE COALESCE(m.gl_account, c.default_gl_account) IS NOT NULL
             GROUP BY COALESCE(m.gl_account, c.default_gl_account)
             ORDER BY gl
        ";
        $wipStmt = $pdo->prepare($wipMiSql);
        $wipStmt->execute($miParams);
        foreach ($wipStmt->fetchAll() as $r) {
            if ((int) ($r['unresolved_count'] ?? 0) > 0) {
                error_log('[warehouse-export] WIP GL-split: unresolved unit conversion(s) for gl=' . ($r['gl'] ?? '?') . ' — check v_bip_canonical.factor_unresolved');
            }
            $gl = (string) ($r['gl'] ?? '');
            if ($gl !== '') $wipByGl[$gl] = (float) ($r['wip_value'] ?? 0);
        }

        // ── Recipe-ingredients gap-fill (loader leg) ────────────────────────────
        // Collect total brewed HL per batch (needed by loader to compute qty = qty_per_hl × brew_hl)
        $exportBatchBrewHl = [];   // "beer|batch" => float
        $expBatchTuples    = [];
        $expBatchParams    = [];
        foreach ($tankTuples as $t) {
            if ($t['batch'] === '') continue;
            $bk = $t['beer'] . '|' . $t['batch'];
            if (!isset($exportBatchBrewHl[$bk])) {
                $exportBatchBrewHl[$bk] = 0.0;   // placeholder; filled below
                $expBatchTuples[] = 'ROW(?, ?)';
                $expBatchParams[] = $t['beer'];
                $expBatchParams[] = $t['batch'];
            }
        }
        if (!empty($expBatchTuples)) {
            $ebSt = $pdo->prepare("
                WITH wanted AS (
                  SELECT * FROM (VALUES " . implode(',', $expBatchTuples) . ") AS v(beer, batch)
                )
                SELECT w.beer  COLLATE utf8mb4_unicode_ci AS beer,
                       w.batch COLLATE utf8mb4_unicode_ci AS batch,
                       (SELECT SUM(bc.final_volume) FROM bd_brewing_gravity_v2 bc
                         WHERE bc.event_type = 'Cooling'
                           AND bc.beer  COLLATE utf8mb4_unicode_ci = w.beer  COLLATE utf8mb4_unicode_ci
                           AND bc.batch COLLATE utf8mb4_unicode_ci = w.batch COLLATE utf8mb4_unicode_ci
                           AND bc.final_volume > 0) AS total_hl
                  FROM wanted w
            ");
            $ebSt->execute($expBatchParams);
            foreach ($ebSt->fetchAll() as $ebRow) {
                $hl = (float) ($ebRow['total_hl'] ?? 0);
                if ($hl > 0) {
                    $exportBatchBrewHl[(string) $ebRow['beer'] . '|' . (string) $ebRow['batch']] = $hl;
                }
            }
        }

        $asofStr      = (new DateTimeImmutable())->setDate($py, $pm, 1)
                            ->modify('last day of this month')
                            ->format('Y-m-d');
        $expLoaderBatches = [];
        foreach ($exportBatchBrewHl as $bk => $brewHl) {
            if ($brewHl <= 0.0) continue;
            [$beerN, $batchN] = explode('|', $bk, 2);
            $expLoaderBatches[] = [
                'beer_name' => $beerN,
                'batch'     => $batchN,
                'brew_hl'   => $brewHl,
            ];
        }

        if (!empty($expLoaderBatches)) {
            $expLoaderResults = load_recipe_ingredients_batched($pdo, $expLoaderBatches, $asofStr);

            // Collect MI IDs for price lookup
            $expMiFkSet = [];
            foreach ($expLoaderResults as $rows) {
                foreach ($rows as $lr) { $expMiFkSet[(int) $lr['mi_id_fk']] = true; }
            }

            $expMiPrices = [];   // mi_id_fk(int) => ['gl'=>str,'pricing_unit'=>str,'unit_price_chf'=>float,'density_g_per_ml'=>float|null]
            if (!empty($expMiFkSet)) {
                $expFkList = array_keys($expMiFkSet);
                $expFkPh   = implode(',', array_fill(0, count($expFkList), '?'));
                $expPrPs   = [$period];
                foreach ($expFkList as $fk) { $expPrPs[] = $fk; }
                $epSt = $pdo->prepare("
                    SELECT m.id,
                           ANY_VALUE(c.default_gl_account) AS gl,
                           m.pricing_unit,
                           m.density_g_per_ml,
                           COALESCE(ws.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1)) AS unit_price_chf
                      FROM ref_mi m
                      LEFT JOIN ref_mi_categories c  ON c.id = m.category_id
                      LEFT JOIN wac_snapshots ws     ON ws.mi_id_fk = m.id AND ws.period = ?
                     WHERE m.id IN ($expFkPh)
                     GROUP BY m.id, m.pricing_unit, m.density_g_per_ml, ws.wac_chf, m.price, m.currency
                ");
                $epSt->execute($expPrPs);
                foreach ($epSt->fetchAll() as $ep) {
                    $expMiPrices[(int) $ep['id']] = [
                        'gl'              => (string) ($ep['gl'] ?? ''),
                        'pricing_unit'    => (string) ($ep['pricing_unit'] ?? 'kg'),
                        'density_g_per_ml'=> $ep['density_g_per_ml'] !== null ? (float) $ep['density_g_per_ml'] : null,
                        'unit_price_chf'  => (float)  ($ep['unit_price_chf'] ?? 0),
                    ];
                }
            }

            foreach ($expLoaderResults as $bk => $rows) {
                $brewHl = $exportBatchBrewHl[$bk] ?? 0.0;
                if ($brewHl <= 0.0) continue;
                foreach ($rows as $lr) {
                    $miFk      = (int) $lr['mi_id_fk'];
                    $priceInfo = $expMiPrices[$miFk] ?? null;
                    if ($priceInfo === null) continue;

                    $riUnit   = strtolower((string) $lr['unit']);
                    $pricingU = strtolower($priceInfo['pricing_unit']);
                    // Unit conversion via centralized helper — handles g→kg, ml→l, ml→kg (density-aware).
                    // ml→kg requires ref_mi.density_g_per_ml; returns null (REFUSE) when absent.
                    $unitFactor = unit_to_canonical_factor($riUnit, $pricingU, $priceInfo['density_g_per_ml']);
                    if ($unitFactor === null) {
                        error_log("recipe-loader export unit mismatch or missing density: ri.unit=$riUnit pricing_unit=$pricingU mi_id_fk=$miFk — row skipped (check ref_mi.density_g_per_ml)");
                        continue;
                    }

                    $chf = (float) $lr['qty'] * $unitFactor * $priceInfo['unit_price_chf'];
                    $gl  = $priceInfo['gl'] !== '' ? $priceInfo['gl'] : '4104';
                    $wipByGl[$gl] = ($wipByGl[$gl] ?? 0.0) + $chf;
                }
            }
        }
    }
} catch (Throwable $e) {
    // Leave $wipByGl empty
    $wipQueryError = $e->getMessage();
}

// ── Query 3: FG by GL ─────────────────────────────────────────────────────────
// Reads inv_fg_stocktake WHERE month_closed = period.
// Liquid value = qty × brew_cost_per_hl × hl_per_unit (via ref_sku_bom MI ratios).
// Packaging GL split = ref_sku_bom WHERE mi LIKE 'PKG_%' × qty.
// If table unavailable or zero rows → FG = 0.
$fgByGl = [];
if (!$fgUnavailable) {
    try {
        // Check for rows in this period
        $fgCountStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM inv_fg_stocktake WHERE month_closed = ? AND is_active = 1"
        );
        $fgCountStmt->execute([$period]);
        $fgRowCount = (int) $fgCountStmt->fetchColumn();

        if ($fgRowCount === 0) {
            $fgNotice = '# NOTE: inv_fg_stocktake has 0 rows for period ' . $period . ' — FG column shows 0';
        } else {
            // Liquid GL split via SKU BOM ingredient ratios
            // For each SKU in the period, get total qty, then look up BOM to find
            // ingredient cost fractions per GL (4101/4102/4104/etc.)
            // Approach: join inv_fg_stocktake → ref_skus → ref_sku_bom → ref_mi → GL
            // For packaging lines: group by COALESCE(mi.gl_account, cat.default_gl_account)
            $fgSql = "
                SELECT COALESCE(m.gl_account, c.default_gl_account) AS gl,
                       SUM(
                         fgs.qty
                         * COALESCE(b.cost, 0)
                       ) AS fg_value  -- b.cost is already qty_per_unit × MI_price (per-pack line cost)
                  FROM inv_fg_stocktake fgs
                  JOIN ref_skus s       ON s.sku_code = fgs.sku
                  JOIN ref_sku_bom b    ON b.sku_id   = s.id
                                       AND b.resolution = 'mi_match'
                  JOIN ref_mi m         ON m.id = b.mi_id
                  JOIN ref_mi_categories c ON c.id = m.category_id
                 WHERE fgs.month_closed = ?
                   AND fgs.is_active = 1
                   AND COALESCE(m.gl_account, c.default_gl_account) IS NOT NULL
                 GROUP BY COALESCE(m.gl_account, c.default_gl_account)
                 ORDER BY gl
            ";
            $fgStmt = $pdo->prepare($fgSql);
            $fgStmt->execute([$period]);
            foreach ($fgStmt->fetchAll() as $r) {
                $gl = (string) ($r['gl'] ?? '');
                if ($gl !== '') $fgByGl[$gl] = (float) ($r['fg_value'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        $fgUnavailable = true;
        $fgNotice = '# NOTE: FG query failed (' . $e->getMessage() . ') — FG column shows 0';
    }
}

// ── Collect all GLs present across RM + WIP + FG ────────────────────────────
// array_keys on arrays keyed by purely-numeric strings returns ints in PHP —
// cast everything explicitly to string before merging.
$allGls = array_unique(array_merge(
    array_map('strval', array_keys($rmByGl)),
    array_map('strval', array_keys($wipByGl)),
    array_map('strval', array_keys($fgByGl)),
    $knownGls
));
// Keep only 4xxx GLs (COGS-relevant) — filter out balance-sheet or non-COGS
$allGls = array_values(array_filter($allGls, fn(string $gl) => preg_match('/^4\d{3}$/', $gl)));
sort($allGls, SORT_NATURAL);

// ── Accumulate totals ────────────────────────────────────────────────────────
$grandRm  = 0.0;
$grandWip = 0.0;
$grandFg  = 0.0;

// ── Write CSV ────────────────────────────────────────────────────────────────
$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel opens French accents correctly
fwrite($out, "\xEF\xBB\xBF");

// Optional notice line (comments before the header)
if ($fgNotice !== '') {
    fputcsv($out, [$fgNotice], ',', '"');
}
if (isset($rmQueryError)) {
    fputcsv($out, ['# WARN: RM query error — ' . $rmQueryError], ',', '"');
}
if (isset($wipQueryError)) {
    fputcsv($out, ['# WARN: WIP query error — ' . $wipQueryError], ',', '"');
}

// Header row
fputcsv($out, ['GL', 'Catégorie', 'RM Inventory (CHF)', 'WIP in Tank (CHF)', 'FG (CHF)', 'Total (CHF)'], ',', '"');

foreach ($allGls as $gl) {
    $rm    = $rmByGl[$gl]  ?? 0.0;
    $wip   = $wipByGl[$gl] ?? 0.0;
    $fg    = $fgByGl[$gl]  ?? 0.0;
    $total = $rm + $wip + $fg;

    // Skip GLs with zero value across all three unless they appear in static label map
    // (zero-value known GLs are still useful for completeness)
    if ($total == 0.0 && !isset($glLabels[$gl])) continue;

    $grandRm  += $rm;
    $grandWip += $wip;
    $grandFg  += $fg;

    fputcsv($out, [
        $gl,
        gl_label($gl, $glLabels),
        number_format($rm,    2, '.', ''),
        number_format($wip,   2, '.', ''),
        number_format($fg,    2, '.', ''),
        number_format($total, 2, '.', ''),
    ], ',', '"');
}

// TOTAL row
$grandTotal = $grandRm + $grandWip + $grandFg;
fputcsv($out, [
    'TOTAL',
    '',
    number_format($grandRm,    2, '.', ''),
    number_format($grandWip,   2, '.', ''),
    number_format($grandFg,    2, '.', ''),
    number_format($grandTotal, 2, '.', ''),
], ',', '"');

fclose($out);
