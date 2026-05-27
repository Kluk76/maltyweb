<?php
declare(strict_types=1);

/**
 * app/qc-thresholds.php — Per-recipe QC threshold resolver.
 *
 * PRECEDENCE per metric (operator-confirmed three-tier chain):
 *
 *   racked_vol_hl
 *     1. ref_recipes operator override cols (racked_vol_warn_lo / _hi / outlier_lo / _hi)
 *     2. v_recipe_vol_band (history-derived σ-bands, n >= 3)
 *     3. commissioning_settings global fallback (qc_vol_warn_lo/hi, qc_vol_outlier_lo/hi)
 *
 *   co2 (g/L)
 *     1. ref_recipes.co2_target + co2_tolerance → [target±tol] / [target±2×tol]
 *     2. commissioning_settings global fallback (qc_co2_warn_lo/hi, qc_co2_outlier_lo/hi)
 *
 *   o2 (ppb)
 *     — always commissioning_settings global (qc_o2_warn_lo/hi, qc_o2_outlier_lo/hi)
 *
 *   pressure (bar)
 *     — always commissioning_settings global (qc_pressure_warn_lo/hi, qc_pressure_outlier_lo/hi)
 *
 * NULL semantics: when a recipe has no history (v_recipe_vol_band absent) and no operator
 * override, the global band is used — never NULL. Every metric always resolves.
 *
 * Consumers (Phase 2):
 *   - form-racking.php (hydrates JS form-framework with per-recipe thresholds)
 *   - salle-de-controle.php (admin editor for per-recipe overrides)
 *
 * Public API:
 *   qc_thresholds_for_recipes(PDO $pdo, array $recipeIds): array
 *     → map[recipe_id => metric_map]
 *   qc_global_bands(PDO $pdo): array
 *     → map[metric => ['warn' => [lo, hi], 'outlier' => [lo, hi]]]
 *
 * Metric map shape (each recipe_id value):
 *   [
 *     'racked_vol_hl' => ['label'=>'Volume transféré', 'unit'=>' HL',   'warn'=>[lo,hi], 'outlier'=>[lo,hi]],
 *     'co2'           => ['label'=>'CO₂ destination',  'unit'=>' g/L',  'warn'=>[lo,hi], 'outlier'=>[lo,hi]],
 *     'o2'            => ['label'=>'O₂ destination',   'unit'=>' ppb',  'warn'=>[lo,hi], 'outlier'=>[lo,hi]],
 *     'pressure'      => ['label'=>'Pression destination','unit'=>' bar','warn'=>[lo,hi], 'outlier'=>[lo,hi]],
 *   ]
 *
 * Dependencies: app/db.php (for maltytask_pdo() — caller passes $pdo; no direct DB open here).
 */

require_once __DIR__ . '/db.php';

/* ═══════════════════════════════════════════════════════════════════════════
   1. GLOBAL BANDS (commissioning_settings)
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Read the four global QC bands from commissioning_settings.
 * Returns a map keyed by metric ('vol', 'co2', 'o2', 'pressure'), each with
 * 'warn' => [lo, hi] and 'outlier' => [lo, hi].
 *
 * Result is memoized per request (static cache) since it is read-only reference data
 * and may be called multiple times within one HTTP request (once per recipe batch).
 *
 * @param PDO $pdo  Active DB connection.
 * @return array<string, array{warn: array{float, float}, outlier: array{float, float}}>
 */
function qc_global_bands(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $keys = [
        'qc_o2_warn_lo', 'qc_o2_warn_hi', 'qc_o2_outlier_lo', 'qc_o2_outlier_hi',
        'qc_pressure_warn_lo', 'qc_pressure_warn_hi', 'qc_pressure_outlier_lo', 'qc_pressure_outlier_hi',
        'qc_co2_warn_lo', 'qc_co2_warn_hi', 'qc_co2_outlier_lo', 'qc_co2_outlier_hi',
        'qc_vol_warn_lo', 'qc_vol_warn_hi', 'qc_vol_outlier_lo', 'qc_vol_outlier_hi',
    ];

    // Build parameterised IN clause — $keys count is fixed (16), no SQL injection risk.
    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare(
        "SELECT key_name, value_num
           FROM commissioning_settings
          WHERE section = 'qc_thresholds'
            AND key_name IN ({$placeholders})
            AND is_active = 1"
    );
    $stmt->execute($keys);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // key_name => value_num

    // Fallback values (match hardcoded defaults in bd_qc_flag) if a key is somehow missing.
    $defaults = [
        'qc_o2_warn_lo'         => 0.0,   'qc_o2_warn_hi'         => 50.0,
        'qc_o2_outlier_lo'      => 0.0,   'qc_o2_outlier_hi'      => 200.0,
        'qc_pressure_warn_lo'   => 0.8,   'qc_pressure_warn_hi'   => 2.5,
        'qc_pressure_outlier_lo'=> 0.0,   'qc_pressure_outlier_hi'=> 3.5,
        'qc_co2_warn_lo'        => 3.5,   'qc_co2_warn_hi'        => 5.0,
        'qc_co2_outlier_lo'     => 2.5,   'qc_co2_outlier_hi'     => 6.0,
        'qc_vol_warn_lo'        => 25.0,  'qc_vol_warn_hi'        => 240.0,
        'qc_vol_outlier_lo'     => 25.0,  'qc_vol_outlier_hi'     => 240.0,
    ];

    $get = static function (string $key) use ($rows, $defaults): float {
        $v = $rows[$key] ?? $defaults[$key];
        return (float)$v;
    };

    $cache = [
        'o2'       => ['warn' => [$get('qc_o2_warn_lo'),          $get('qc_o2_warn_hi')],
                        'outlier' => [$get('qc_o2_outlier_lo'),   $get('qc_o2_outlier_hi')]],
        'pressure' => ['warn' => [$get('qc_pressure_warn_lo'),    $get('qc_pressure_warn_hi')],
                        'outlier' => [$get('qc_pressure_outlier_lo'), $get('qc_pressure_outlier_hi')]],
        'co2'      => ['warn' => [$get('qc_co2_warn_lo'),         $get('qc_co2_warn_hi')],
                        'outlier' => [$get('qc_co2_outlier_lo'),  $get('qc_co2_outlier_hi')]],
        'vol'      => ['warn' => [$get('qc_vol_warn_lo'),         $get('qc_vol_warn_hi')],
                        'outlier' => [$get('qc_vol_outlier_lo'),  $get('qc_vol_outlier_hi')]],
    ];

    return $cache;
}

/* ═══════════════════════════════════════════════════════════════════════════
   2. PER-RECIPE RESOLVER
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Resolve QC thresholds for a batch of recipes.
 *
 * All four DB reads (ref_recipes, v_recipe_vol_band, commissioning_settings,
 * plus nothing for o2/pressure which always use global) are batched — at most
 * one query per source for the whole $recipeIds list.
 *
 * @param PDO   $pdo       Active DB connection.
 * @param int[] $recipeIds List of ref_recipes.id values.
 *
 * @return array<int, array{
 *   racked_vol_hl: array{label:string,unit:string,warn:array{float,float},outlier:array{float,float}},
 *   co2:           array{label:string,unit:string,warn:array{float,float},outlier:array{float,float}},
 *   o2:            array{label:string,unit:string,warn:array{float,float},outlier:array{float,float}},
 *   pressure:      array{label:string,unit:string,warn:array{float,float},outlier:array{float,float}},
 * }>
 *
 * @throws InvalidArgumentException if $recipeIds is empty.
 */
function qc_thresholds_for_recipes(PDO $pdo, array $recipeIds): array
{
    if (empty($recipeIds)) {
        throw new InvalidArgumentException('qc_thresholds_for_recipes: $recipeIds must be non-empty');
    }

    // Validate: only positive integers — prevent SQL injection via IN clause.
    $safeIds = [];
    foreach ($recipeIds as $id) {
        $intId = (int)$id;
        if ($intId <= 0) {
            throw new InvalidArgumentException("qc_thresholds_for_recipes: invalid recipe id: {$id}");
        }
        $safeIds[] = $intId;
    }
    $safeIds = array_unique($safeIds);

    $placeholders = implode(', ', array_fill(0, count($safeIds), '?'));

    // ── 2a. Load global bands (memoized) ─────────────────────────────────────
    $global = qc_global_bands($pdo);

    // ── 2b. Load per-recipe ref_recipes cols (co2_target, co2_tolerance, vol overrides) ─
    $stmtR = $pdo->prepare(
        "SELECT id,
                co2_target,
                co2_tolerance,
                racked_vol_warn_lo,
                racked_vol_warn_hi,
                racked_vol_outlier_lo,
                racked_vol_outlier_hi
           FROM ref_recipes
          WHERE id IN ({$placeholders})"
    );
    $stmtR->execute($safeIds);
    $recipeRows = [];
    foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recipeRows[(int)$row['id']] = $row;
    }

    // ── 2c. Load v_recipe_vol_band (history-derived σ-bands) ─────────────────
    $stmtV = $pdo->prepare(
        "SELECT recipe_id, warn_lo, warn_hi, outlier_lo, outlier_hi
           FROM v_recipe_vol_band
          WHERE recipe_id IN ({$placeholders})"
    );
    $stmtV->execute($safeIds);
    $volBands = [];
    foreach ($stmtV->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $volBands[(int)$row['recipe_id']] = $row;
    }

    // ── 2d. Build per-recipe result map ──────────────────────────────────────
    $result = [];
    foreach ($safeIds as $recipeId) {
        $recipe = $recipeRows[$recipeId] ?? null;

        // ── Volume: operator override → view band → global fallback ─────────
        $volOverrideComplete = $recipe !== null
            && $recipe['racked_vol_warn_lo'] !== null
            && $recipe['racked_vol_warn_hi'] !== null
            && $recipe['racked_vol_outlier_lo'] !== null
            && $recipe['racked_vol_outlier_hi'] !== null;

        if ($volOverrideComplete) {
            $volWarn    = [(float)$recipe['racked_vol_warn_lo'],    (float)$recipe['racked_vol_warn_hi']];
            $volOutlier = [(float)$recipe['racked_vol_outlier_lo'], (float)$recipe['racked_vol_outlier_hi']];
        } elseif (isset($volBands[$recipeId])) {
            $vb = $volBands[$recipeId];
            $volWarn    = [(float)$vb['warn_lo'],    (float)$vb['warn_hi']];
            $volOutlier = [(float)$vb['outlier_lo'], (float)$vb['outlier_hi']];
        } else {
            $volWarn    = $global['vol']['warn'];
            $volOutlier = $global['vol']['outlier'];
        }

        // ── CO₂: per-recipe target+tolerance → global fallback ───────────────
        $co2Target    = ($recipe !== null && $recipe['co2_target'] !== null)
            ? (float)$recipe['co2_target']
            : null;
        $co2Tolerance = ($recipe !== null && $recipe['co2_tolerance'] !== null)
            ? (float)$recipe['co2_tolerance']
            : null;

        if ($co2Target !== null && $co2Tolerance !== null) {
            $co2Warn    = [$co2Target - $co2Tolerance,       $co2Target + $co2Tolerance];
            $co2Outlier = [$co2Target - 2 * $co2Tolerance,  $co2Target + 2 * $co2Tolerance];
        } else {
            $co2Warn    = $global['co2']['warn'];
            $co2Outlier = $global['co2']['outlier'];
        }

        // ── O₂ and pressure: always global ───────────────────────────────────
        $result[$recipeId] = [
            'racked_vol_hl' => [
                'label'   => 'Volume transféré',
                'unit'    => ' HL',
                'warn'    => $volWarn,
                'outlier' => $volOutlier,
            ],
            'co2' => [
                'label'   => 'CO₂ destination',
                'unit'    => ' g/L',
                'warn'    => $co2Warn,
                'outlier' => $co2Outlier,
            ],
            'o2' => [
                'label'   => 'O₂ destination',
                'unit'    => ' ppb',
                'warn'    => $global['o2']['warn'],
                'outlier' => $global['o2']['outlier'],
            ],
            'pressure' => [
                'label'   => 'Pression destination',
                'unit'    => ' bar',
                'warn'    => $global['pressure']['warn'],
                'outlier' => $global['pressure']['outlier'],
            ],
        ];
    }

    return $result;
}
