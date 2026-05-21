<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";
require_login();

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
                     (SELECT SUM(bc.cool_final_volume_hl)
                        FROM bd_brewing_cooling bc
                       WHERE bc.cool_beer  COLLATE utf8mb4_unicode_ci = t.beer_name COLLATE utf8mb4_unicode_ci
                         AND bc.cool_batch COLLATE utf8mb4_unicode_ci = t.batch     COLLATE utf8mb4_unicode_ci
                         AND bc.cool_final_volume_hl > 0) AS total_brewed_hl,
                     (SELECT COUNT(*)
                        FROM bd_brewing_cooling bc
                       WHERE bc.cool_beer  COLLATE utf8mb4_unicode_ci = t.beer_name COLLATE utf8mb4_unicode_ci
                         AND bc.cool_batch COLLATE utf8mb4_unicode_ci = t.batch     COLLATE utf8mb4_unicode_ci
                         AND bc.cool_final_volume_hl > 0) AS n_brews
                FROM tanks t
            )
            SELECT COALESCE(
                     ANY_VALUE(m.gl_account),
                     ANY_VALUE(c.default_gl_account)
                   ) AS gl,
                   SUM(
                     bip.qty * IF(bip.unit='g', 0.001, 1)
                     * COALESCE(tb.n_brews, 1)
                     * tb.volume_hl / NULLIF(tb.total_brewed_hl, 0)
                     * COALESCE(w.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1))
                   ) AS wip_value
              FROM tank_batches tb
              JOIN bd_brewing_ingredients_parsed bip
                ON bip.beer  COLLATE utf8mb4_unicode_ci = tb.beer_name COLLATE utf8mb4_unicode_ci
               AND bip.batch COLLATE utf8mb4_unicode_ci = tb.batch     COLLATE utf8mb4_unicode_ci
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
            $gl = (string) ($r['gl'] ?? '');
            if ($gl !== '') $wipByGl[$gl] = (float) ($r['wip_value'] ?? 0);
        }

        /* TEMPORARY HARDCODE — remove when maltyweb-native ingredient form ships */
        // Mirrors the hardcoded 4104 ingredient logic from warehouse.php WIP block.
        // Covers: per-brew recipe adjuncts (Moonshine/Stirling/DIB), PROC_YEASTVIT
        // (all Neb except Diversion/Diversion Blanche/Alternative), and PROC_NAGARDO
        // (Diversion + Diversion Blanche, BBT per-HL only). Result added to $wipByGl['4104'].
        $HC_RULES_EXPORT = [
            'Moonshine'        => [
                ['mi_id' => 'ADJ_CORIANDER',   'qty_per_brew_kg' => 2.2],
                ['mi_id' => 'ADJ_ORANGE_PEEL',  'qty_per_brew_kg' => 3.3],
            ],
            'Stirling'         => [
                ['mi_id' => 'PROC_DEHAZE', 'qty_per_brew_kg' => 0.150, 'skip_if_in_parsed' => true],
            ],
            'Diversion Blanche' => [
                ['mi_id' => 'ADJ_PEACH_TEA', 'qty_per_brew_kg' => 4.0],
            ],
        ];
        $HC_YV_EXCLUDE_EXPORT = ['Diversion', 'Diversion Blanche', 'Alternative'];
        $HC_YV_RULE_EXPORT    = ['mi_id' => 'PROC_YEASTVIT', 'qty_per_brew_kg' => 0.240];
        $HC_NG_BEERS_EXPORT   = ['Diversion', 'Diversion Blanche'];
        $HC_NG_RULE_EXPORT    = ['mi_id' => 'PROC_NAGARDO', 'qty_per_hl_kg' => 0.002];

        $HC_ALL_MI_EXPORT = array_unique(array_merge(
            ['PROC_YEASTVIT', 'PROC_NAGARDO'],
            ...array_values(array_map(fn(array $rules) => array_column($rules, 'mi_id'), $HC_RULES_EXPORT))
        ));

        // Fetch Neb beer names for PROC_YEASTVIT scope
        $HC_NEB_EXPORT = [];
        {
            $nebSt = $pdo->query("SELECT name FROM ref_recipes WHERE classification = 'Neb'");
            foreach ($nebSt->fetchAll(PDO::FETCH_COLUMN) as $n) { $HC_NEB_EXPORT[] = (string) $n; }
        }
        $HC_YV_BEERS_EXPORT = array_values(array_filter(
            $HC_NEB_EXPORT,
            fn(string $n) => !in_array($n, $HC_YV_EXCLUDE_EXPORT, true)
        ));

        // Fetch unit prices for all hardcoded MIs (WAC for period, fallback ref_mi.price)
        $hcPricesExport = [];
        {
            $ph   = implode(',', array_fill(0, count($HC_ALL_MI_EXPORT), '?'));
            $prms = [$period];
            foreach ($HC_ALL_MI_EXPORT as $mid) { $prms[] = $mid; }
            $pSt  = $pdo->prepare("
                SELECT m.mi_id,
                       ANY_VALUE(c.default_gl_account) AS gl,
                       COALESCE(ws.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1)) AS unit_price_chf
                  FROM ref_mi m
                  LEFT JOIN ref_mi_categories c  ON c.id = m.category_id
                  LEFT JOIN wac_snapshots ws ON ws.mi_id_fk = m.id AND ws.period = ?
                 WHERE m.mi_id IN ($ph)
                 GROUP BY m.id, m.mi_id, ws.wac_chf, m.price, m.currency
            ");
            $pSt->execute($prms);
            foreach ($pSt->fetchAll() as $pr) {
                $hcPricesExport[(string) $pr['mi_id']] = [
                    'unit_price_chf' => (float) ($pr['unit_price_chf'] ?? 0),
                    'gl'             => (string) ($pr['gl'] ?? '4104'),
                ];
            }
        }

        // Fetch n_brews + total_hl for batches that need per-brew rules
        $batchMetaExport = [];   // "beer|batch" => ['n'=>int,'hl'=>float]
        {
            $bmTuples = [];
            $bmParams = [];
            foreach ($tankTuples as $t) {
                if ($t['batch'] === '') continue;
                $beerName = $t['beer'];
                $needsMeta = isset($HC_RULES_EXPORT[$beerName])
                    || in_array($beerName, $HC_YV_BEERS_EXPORT, true);
                if (!$needsMeta) continue;
                $bk = $beerName . '|' . $t['batch'];
                if (!isset($batchMetaExport[$bk])) {
                    $bmTuples[] = 'ROW(?, ?)';
                    $bmParams[] = $beerName;
                    $bmParams[] = $t['batch'];
                    $batchMetaExport[$bk] = null;
                }
            }
            if (!empty($bmTuples)) {
                $bmSt = $pdo->prepare("
                    WITH wanted AS (
                      SELECT * FROM (VALUES " . implode(',', $bmTuples) . ") AS v(beer, batch)
                    )
                    SELECT w.beer  COLLATE utf8mb4_unicode_ci AS beer,
                           w.batch COLLATE utf8mb4_unicode_ci AS batch,
                           (SELECT COUNT(*) FROM bd_brewing_cooling bc
                             WHERE bc.cool_beer  COLLATE utf8mb4_unicode_ci = w.beer  COLLATE utf8mb4_unicode_ci
                               AND bc.cool_batch COLLATE utf8mb4_unicode_ci = w.batch COLLATE utf8mb4_unicode_ci
                               AND bc.cool_final_volume_hl > 0) AS n_brews,
                           (SELECT SUM(bc.cool_final_volume_hl) FROM bd_brewing_cooling bc
                             WHERE bc.cool_beer  COLLATE utf8mb4_unicode_ci = w.beer  COLLATE utf8mb4_unicode_ci
                               AND bc.cool_batch COLLATE utf8mb4_unicode_ci = w.batch COLLATE utf8mb4_unicode_ci
                               AND bc.cool_final_volume_hl > 0) AS total_hl
                      FROM wanted w
                ");
                $bmSt->execute($bmParams);
                foreach ($bmSt->fetchAll() as $bm) {
                    $batchMetaExport[$bm['beer'] . '|' . $bm['batch']] = [
                        'n'  => (int)   ($bm['n_brews']  ?? 0),
                        'hl' => (float) ($bm['total_hl'] ?? 0),
                    ];
                }
            }

            // Pre-fetch skip_if_in_parsed for PROC_DEHAZE / Stirling batches
            $HC_DEHAZE_BATCHES_EXPORT = [];
            $stirBks = array_values(array_filter(
                array_keys($batchMetaExport),
                fn(string $k) => strncmp($k, 'Stirling|', 9) === 0
            ));
            if (!empty($stirBks)) {
                $orClauses = implode(' OR ', array_fill(0, count($stirBks),
                    '(bip.beer COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci '
                    . 'AND bip.batch COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci)'));
                $dhParams = [];
                foreach ($stirBks as $sbk) {
                    [$sb, $sbt] = explode('|', $sbk, 2);
                    $dhParams[] = $sb;
                    $dhParams[] = $sbt;
                }
                $dhSt = $pdo->prepare("
                    SELECT DISTINCT bip.beer  COLLATE utf8mb4_unicode_ci AS beer,
                                    bip.batch COLLATE utf8mb4_unicode_ci AS batch
                      FROM bd_brewing_ingredients_parsed bip
                      JOIN ref_mi m ON m.id = bip.mi_id_fk
                     WHERE m.mi_id = 'PROC_DEHAZE'
                       AND ($orClauses)
                ");
                $dhSt->execute($dhParams);
                foreach ($dhSt->fetchAll() as $dh) {
                    $HC_DEHAZE_BATCHES_EXPORT[$dh['beer'] . '|' . $dh['batch']] = true;
                }
            }
        }

        // Sum up all hardcoded GL 4104 deltas
        $hc4104Delta  = 0.0;
        $hcNagarDelta = 0.0;

        foreach ($tankTuples as $t) {
            if ($t['batch'] === '') continue;
            $beerName = $t['beer'];
            $bk       = $beerName . '|' . $t['batch'];

            // Per-brew rules from $HC_RULES_EXPORT
            foreach (($HC_RULES_EXPORT[$beerName] ?? []) as $rule) {
                if (!isset($hcPricesExport[$rule['mi_id']])) continue;
                if (!empty($rule['skip_if_in_parsed']) && isset($HC_DEHAZE_BATCHES_EXPORT[$bk])) continue;
                $meta = $batchMetaExport[$bk] ?? null;
                if ($meta === null || $meta['n'] <= 0 || $meta['hl'] <= 0) continue;
                $tankQty     = $rule['qty_per_brew_kg'] * $meta['n'] * ($t['volume_hl'] / $meta['hl']);
                $hc4104Delta += $tankQty * $hcPricesExport[$rule['mi_id']]['unit_price_chf'];
            }

            // PROC_YEASTVIT: all qualifying Neb beers, per-brew
            if (in_array($beerName, $HC_YV_BEERS_EXPORT, true)
                && isset($hcPricesExport[$HC_YV_RULE_EXPORT['mi_id']])) {
                $meta = $batchMetaExport[$bk] ?? null;
                if ($meta !== null && $meta['n'] > 0 && $meta['hl'] > 0) {
                    $tankQty     = $HC_YV_RULE_EXPORT['qty_per_brew_kg'] * $meta['n'] * ($t['volume_hl'] / $meta['hl']);
                    $hc4104Delta += $tankQty * $hcPricesExport[$HC_YV_RULE_EXPORT['mi_id']]['unit_price_chf'];
                }
            }

            // PROC_NAGARDO: BBT per-HL, Diversion + Diversion Blanche only
            if ($t['type'] === 'BBT'
                && in_array($beerName, $HC_NG_BEERS_EXPORT, true)
                && isset($hcPricesExport[$HC_NG_RULE_EXPORT['mi_id']])) {
                $tankQty      = $HC_NG_RULE_EXPORT['qty_per_hl_kg'] * $t['volume_hl'];
                $hcNagarDelta += $tankQty * $hcPricesExport[$HC_NG_RULE_EXPORT['mi_id']]['unit_price_chf'];
            }
        }

        $totalHcDelta = $hc4104Delta + $hcNagarDelta;
        if ($totalHcDelta > 0.0) {
            $wipByGl['4104'] = ($wipByGl['4104'] ?? 0.0) + $totalHcDelta;
        }
        /* END TEMPORARY HARDCODE */
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

// ── Query 3b: FG composite-pack supplement ───────────────────────────────────
// TEMPORARY: composite packs (PD8, PAL) have no ref_sku_bom rows yet for liquid cost.
// Compute their liquid value using constituent beer walk-back costs.
// Remove this block once ref_sku_bom / a dedicated composites table holds this.
if (!$fgUnavailable) {
    try {
        $COMPOSITE_PACK_LIQUID = [
            // PD8 = Pack Découverte: 8 × 33cl, 1 bottle of each of 8 core beers (0.0264 HL)
            // Source: BSF CompositePacks tab. PAD was the old BSF header; PD8 is canonical (inv_fg_stocktake renamed 2026-05-21).
            'PD8' => ['Zepp' => 1, 'Diversion' => 1, 'Double Oat' => 1, 'Embuscade' => 1,
                      'Moonshine' => 1, 'Speakeasy' => 1, 'Stirling' => 1, 'Alternative' => 1],
            // PAL = Pack Louis: 12 × 33cl, 2 bottles each of 6 beers (0.396 HL)
            'PAL' => ['Speakeasy' => 2, 'Alternative' => 2, 'Diversion Blanche' => 2,
                      'Double Oat' => 2, 'Embuscade' => 2, 'Moonshine' => 2],
        ];
        $BOTTLE_HL = 0.0033;  // 33cl = 0.33 L = 0.0033 HL (1 HL = 100 L)

        // Collect all unique constituent beers
        $constituentBeers = [];
        foreach ($COMPOSITE_PACK_LIQUID as $constituents) {
            foreach (array_keys($constituents) as $beerName) {
                $constituentBeers[] = $beerName;
            }
        }
        $constituentBeers = array_values(array_unique($constituentBeers));

        // Compute prevPeriod relative to the requested period
        $prevPeriod = (new DateTime($period . '-01'))->modify('-1 month')->format('Y-m');

        // Walk-back cost per constituent beer (mirrors warehouse.php Step 3b)
        $constituentCostMap = [];
        foreach ($constituentBeers as $cBeer) {
            $wcSt = $pdo->prepare("
                SELECT SUM(volume_hl * brew_cost_per_hl) / NULLIF(SUM(volume_hl), 0) AS cost_per_hl,
                       ANY_VALUE(month_key) AS month_key
                  FROM (
                    SELECT month_key, volume_hl, brew_cost_per_hl,
                           ROW_NUMBER() OVER (ORDER BY month_key DESC) AS rn
                      FROM inv_tank_balances
                     WHERE beer_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                       AND month_key <= ?
                       AND brew_cost_per_hl IS NOT NULL
                  ) ranked
                 WHERE rn = 1
            ");
            $wcSt->execute([$cBeer, $prevPeriod]);
            $wcRow = $wcSt->fetch();
            if ($wcRow && $wcRow['cost_per_hl'] !== null) {
                // GL split for this constituent
                $glSt = $pdo->prepare("
                    SELECT c.default_gl_account AS gl,
                           SUM(bip.qty * IF(bip.unit='g', 0.001, 1)
                               * COALESCE(ws.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1))) AS gl_cost
                      FROM bd_brewing_ingredients_parsed bip
                      JOIN ref_mi m ON m.id = bip.mi_id_fk
                      LEFT JOIN ref_mi_categories c ON c.id = m.category_id
                      LEFT JOIN wac_snapshots ws ON ws.mi_id_fk = m.id AND ws.period = ?
                     WHERE bip.beer COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                       AND bip.mi_id_fk IS NOT NULL
                       AND EXISTS (
                         SELECT 1 FROM bd_brewing_cooling bc
                          WHERE bc.cool_beer  COLLATE utf8mb4_unicode_ci = bip.beer  COLLATE utf8mb4_unicode_ci
                            AND bc.cool_batch COLLATE utf8mb4_unicode_ci = bip.batch COLLATE utf8mb4_unicode_ci
                            AND DATE_FORMAT(bc.event_date, '%Y-%m') = ?
                       )
                     GROUP BY c.default_gl_account
                ");
                $glSt->execute([$wcRow['month_key'], $cBeer, $wcRow['month_key']]);
                $glRows    = $glSt->fetchAll();
                $totalGlCost = (float) array_sum(array_column($glRows, 'gl_cost'));
                $glSplit = [];
                foreach ($glRows as $glr) {
                    $gl = (string) ($glr['gl'] ?? '');
                    if ($totalGlCost > 0 && $gl !== '') {
                        $glSplit[$gl] = (float) $wcRow['cost_per_hl'] * ((float) $glr['gl_cost'] / $totalGlCost);
                    }
                }
                $constituentCostMap[$cBeer] = [
                    'cost_per_hl' => (float) $wcRow['cost_per_hl'],
                    'gl_split'    => $glSplit,
                ];
            }
        }

        // For each composite SKU present in the period, compute GL contribution
        $phComp = implode(',', array_map(fn($c) => '?', array_keys($COMPOSITE_PACK_LIQUID)));
        $compSt = $pdo->prepare(
            "SELECT fgs.sku, fgs.qty
               FROM inv_fg_stocktake fgs
              WHERE fgs.month_closed = ? AND fgs.sku IN ($phComp) AND fgs.qty > 0"
        );
        $compSt->execute(array_merge([$period], array_keys($COMPOSITE_PACK_LIQUID)));
        foreach ($compSt->fetchAll() as $cr) {
            $compSku  = (string) $cr['sku'];
            $compQty  = (int)    $cr['qty'];
            $constituents = $COMPOSITE_PACK_LIQUID[$compSku] ?? [];
            foreach ($constituents as $cBeer => $bottles) {
                $cbc = $constituentCostMap[$cBeer] ?? null;
                if (!$cbc) continue;
                $constituentHl = $bottles * $BOTTLE_HL;
                foreach ($cbc['gl_split'] as $gl => $costPerHlGl) {
                    $fgByGl[$gl] = ($fgByGl[$gl] ?? 0.0) + ($compQty * $constituentHl * $costPerHlGl);
                }
            }
        }
    } catch (Throwable $e) {
        // Non-fatal — composite packs will show 0 in the export
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
