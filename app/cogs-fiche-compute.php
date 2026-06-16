<?php
declare(strict_types=1);

/**
 * app/cogs-fiche-compute.php
 *
 * COGS fiche monthly computation — PHP port of scripts/cogs-monthly-compile.ts.
 *
 * Public surface:
 *   cogs_fiche_compute_month(PDO $pdo, string $month): array
 *
 * Returns an array with keys:
 *   'categories'  => array<string, array{
 *       rm_chf, wip_chf, fg_chf, total_chf,
 *       opening_chf, variation_chf, basis_adjustment_chf
 *   }>
 *   'totals'      => same shape, summed across 12 categories
 *   'diagnostics' => informational array (no writes, no side-effects)
 *   'notes'       => string[]
 *
 * PORTING CONTRACT — every rule from the TS engine applies verbatim:
 *   RM  = final_qty × cost_chf  (NO conversionFactor — finalQty is already in pricing unit)
 *   WIP = volume_hl × ref_beer_types.brew_cost_per_hl × liquid-BOM category proportion
 *         (inv_tank_balances.brew_cost_per_hl is NULL for all rows — must NOT use it)
 *   FG  = inv_fg_stocktake (month_end, latest per sku×loc) × ref_sku_bom per-category cost
 *   Opening = prior-month total_chf from cogs_fiche_seed ∪ cogs_fiche_monthly
 *             (monthly overrides seed; chains back to the April seed anchor)
 *   Variation = total_chf − opening_chf
 *   basis_adjustment_chf = revalue prior-month FG at current BOM − seeded prior fg_chf
 *                          (FG/F2 portion only; 0 for pure-RM categories)
 *
 * Pure compute — no writes, no cache, no side-effects.
 * Never throws for data-quality issues; surfaces them in 'diagnostics'/'notes'.
 * Throws only for structural failures (DB errors, missing seeded opening row).
 */

// ── Category constants ─────────────────────────────────────────────────────────

/** Ordered list of 12 fiche categories — order must match TS FICHE_CATEGORIES. */
const COGS_FICHE_CATEGORIES = [
    'Malt', 'Hops', 'Ingredients', 'Yeast',
    'Cartons', 'Inliner', 'Capsules', 'Verres',
    'Bouteilles', 'Etiquettes', 'Canettes', 'CapsFuts',
];

/** Categories that receive WIP value (liquid beer, brewing-cost only). */
const COGS_BREWING_CATEGORIES = ['Malt', 'Hops', 'Ingredients', 'Yeast'];

// ── Category mapper ────────────────────────────────────────────────────────────

/**
 * Map a ref_mi category + subcategory + name/id to a fiche category string.
 * Returns null for excluded categories (cleaning, logistics, keg, etc.).
 *
 * Ported verbatim from mapToFicheCategory() in cogs-monthly-compile.ts.
 * Changes here MUST be mirrored in the TS source.
 */
function cogs_fiche_map_category(
    string $category,
    ?string $subcategory,
    string $mi_name,
    string $mi_id = ''
): ?string {
    $cat = strtolower($category);
    $sub = strtolower($subcategory ?? '');
    $name = strtolower($mi_name);
    $id   = strtolower($mi_id);

    if ($cat === 'malt')             return 'Malt';
    if ($cat === 'hops')             return 'Hops';
    if ($cat === 'yeast')            return 'Yeast';
    if ($cat === 'brewing adjunct'
     || $cat === 'process chemical'
     || $cat === 'brewing mineral')  return 'Ingredients';

    // Excluded categories
    if (in_array($cat, [
        'cleaning chemical', 'logistics', 'keg', 'transport',
        'utilities', 'r&d', 'sales', 'nonbeer', 'maintenance', 'cautions',
    ], true)) {
        return null;
    }

    if ($cat === 'packaging') {
        // Crown Caps: sub='Bottle' but these are closures → Capsules
        if (str_contains($id, 'crown_cap') || str_contains($name, 'crown cap')) return 'Capsules';

        if ($sub === 'pack' || $sub === 'box')  return 'Cartons';
        if ($sub === 'tea')                      return 'Cartons';
        if ($sub === 'bottle')                   return 'Bouteilles';
        if ($sub === 'can')                      return 'Canettes';
        if ($sub === 'closure')                  return 'Capsules';
        if ($sub === 'label')                    return 'Etiquettes';
        if ($sub === 'liner')                    return 'Inliner';
        if ($sub === 'keg')                      return 'CapsFuts';
        if ($sub === 'misc') {
            if (str_contains($name, 'scotch') || str_contains($name, 'film')) return 'Cartons';
            if (str_contains($name, 'verre')  || str_contains($name, 'glass')) return 'Verres';
            if (str_contains($name, 'sticker'))  return 'Etiquettes';
            if (str_contains($name, 'bottle'))   return 'Bouteilles';
            return 'Cartons'; // default misc → Cartons
        }
        return 'Cartons'; // other subs → Cartons
    }

    // Fallback: unknown category → Ingredients
    return 'Ingredients';
}

// ── RM computation ─────────────────────────────────────────────────────────────

/**
 * Compute RM closing values by fiche category.
 *
 * Formula: final_qty × cost_chf  (NO conversionFactor).
 * inv_rm_stocktake.final_qty is stored in the PRICING unit (already kg for
 * hops/yeast/malt despite ref_mi.input_unit='g'). cost_chf is per pricing unit.
 * Applying conversionFactor would produce wildly wrong values.
 *
 * Filter: is_active=1 AND final_qty > 0 AND mi_id_fk IS NOT NULL.
 * SANITY GUARD: Yeast >= 50 000 CHF means conversionFactor crept in → throws.
 *
 * @return array{by_category: array<string,float>, no_basis: string[], excluded: string[],
 *               unit_mismatch_items: array<int,array>, notes: string[]}
 */
function _cogs_rm_compute(PDO $pdo, string $month): array
{
    $byCategory = array_fill_keys(COGS_FICHE_CATEGORIES, 0.0);
    $noBasis    = [];
    $excluded   = [];
    $unitMismatch = [];
    $notes      = [];

    $stmt = $pdo->prepare("
        SELECT
            m.mi_id                                       AS mi_id,
            m.name                                        AS mi_name,
            c.name                                        AS category,
            sc.name                                       AS subcategory,
            CAST(m.conversion_factor AS DECIMAL(14,6))   AS conversion_factor,
            m.pricing_unit                                AS pricing_unit,
            m.input_unit                                  AS input_unit,
            v.cost_basis                                  AS cost_basis,
            v.cost_chf                                    AS cost_chf,
            s.final_qty                                   AS final_qty
        FROM inv_rm_stocktake s
        JOIN ref_mi m              ON m.id = s.mi_id_fk
        JOIN ref_mi_categories c   ON c.id = m.category_id
        LEFT JOIN ref_mi_subcategories sc ON sc.id = m.subcategory_id
        LEFT JOIN v_mi_cost v      ON v.mi_id_fk = m.id
        WHERE s.is_active = 1
          AND s.final_qty > 0
          AND s.mi_id_fk IS NOT NULL
          AND s.period = ?
    ");
    $stmt->execute([$month]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $notes[] = sprintf('RM rows loaded: %d', count($rows));

    foreach ($rows as $r) {
        $miId    = (string)$r['mi_id'];
        $costChf = $r['cost_chf'] !== null ? (float)$r['cost_chf'] : null;

        if ($costChf === null) {
            $noBasis[] = $miId;
            continue;
        }

        $fcat = cogs_fiche_map_category(
            (string)$r['category'],
            $r['subcategory'] !== null ? (string)$r['subcategory'] : null,
            (string)$r['mi_name'],
            $miId
        );

        if ($fcat === null) {
            $excluded[] = $miId;
            continue;
        }

        // KEY: final_qty × cost_chf — NO conversionFactor
        $finalQty   = (float)$r['final_qty'];
        $naiveValue = $finalQty * $costChf;
        $byCategory[$fcat] += $naiveValue;

        // Track unit-mismatch items for diagnostics
        $convFactor = (float)$r['conversion_factor'];
        if ($convFactor !== 1.0 && $r['input_unit'] !== $r['pricing_unit']) {
            $unitMismatch[] = [
                'mi_id'       => $miId,
                'input_unit'  => (string)$r['input_unit'],
                'pricing_unit'=> (string)$r['pricing_unit'],
                'conv_factor' => $convFactor,
                'final_qty'   => $finalQty,
                'naive_value' => $naiveValue,
                'conv_value'  => $finalQty * $convFactor * $costChf,
            ];
        }
    }

    // SANITY GUARD: Yeast >= 50 000 CHF means conversionFactor was applied
    if (($byCategory['Yeast'] ?? 0.0) >= 50_000.0) {
        throw new \RuntimeException(sprintf(
            'SANITY FAIL: Yeast RM = %.2f CHF (>= 50000 threshold). '
            . 'conversionFactor crept into formula — check _cogs_rm_compute.',
            $byCategory['Yeast']
        ));
    }

    return [
        'by_category'        => $byCategory,
        'no_basis'           => $noBasis,
        'excluded'           => $excluded,
        'unit_mismatch_items'=> $unitMismatch,
        'notes'              => $notes,
    ];
}

// ── FG computation ─────────────────────────────────────────────────────────────

/**
 * Compute FG closing values by fiche category.
 *
 * Source: inv_fg_stocktake where count_type='month_end', latest per (sku_id_fk, location_id_fk).
 * No is_active filter (seeded rows are is_active=0 but must be included).
 * BOM cost per category via ref_sku_bom joined to ref_mi, ref_mi_categories, ref_mi_subcategories.
 *
 * For liquid/composite_liquid BOM lines: category determined by MI category.
 * For packaging/composite_packaging BOM lines: default by SKU format, override by MI category.
 *
 * @param PDO $pdo
 * @param string $month YYYY-MM
 * @return array{by_category: array<string,float>, missing_bom_skus: string[], zero_cost_skus: string[],
 *               fg_missing: bool, notes: string[]}
 */
function _cogs_fg_compute(PDO $pdo, string $month): array
{
    $byCategory    = array_fill_keys(COGS_FICHE_CATEGORIES, 0.0);
    $missingBomSkus = [];
    $zeroCostSkus  = [];
    $notes         = [];
    $fgMissing     = false;

    // Latest-per-(sku_id_fk, location_id_fk) for month_end census
    $stmt = $pdo->prepare("
        SELECT sku, sku_id_fk, qty
        FROM (
            SELECT sku, sku_id_fk, qty,
                   ROW_NUMBER() OVER (
                       PARTITION BY sku_id_fk, location_id_fk
                       ORDER BY counted_at DESC, id DESC
                   ) AS rn
            FROM inv_fg_stocktake
            WHERE month_closed = ?
              AND count_type = 'month_end'
        ) z
        WHERE z.rn = 1
        ORDER BY sku
    ");
    $stmt->execute([$month]);
    $fgRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notes[] = sprintf('FG rows loaded: %d', count($fgRows));

    if (empty($fgRows)) {
        $fgMissing = true;
        $notes[] = sprintf('FG MISSING for %s — fg_chf set to 0', $month);
        return [
            'by_category'    => $byCategory,
            'missing_bom_skus'=> $missingBomSkus,
            'zero_cost_skus' => $zeroCostSkus,
            'fg_missing'     => $fgMissing,
            'notes'          => $notes,
        ];
    }

    // Fetch all BOM lines for the unique SKU IDs in one query
    $skuIds = array_unique(array_column($fgRows, 'sku_id_fk'));
    $notes[] = sprintf('Unique SKU IDs: %d', count($skuIds));

    // Build per-SKU BOM cost map
    $skuBomCost = [];

    foreach ($skuIds as $skuId) {
        $stmt2 = $pdo->prepare("
            SELECT
                b.bom_source,
                cat.name    AS category,
                sub.name    AS subcategory,
                m.mi_id     AS mi_id_str,
                b.cost,
                rs.format
            FROM ref_sku_bom b
            JOIN ref_mi m              ON m.id = b.mi_id
            JOIN ref_mi_categories cat ON cat.id = m.category_id
            LEFT JOIN ref_mi_subcategories sub ON sub.id = m.subcategory_id
            JOIN ref_skus rs           ON rs.id = b.sku_id
            WHERE b.sku_id = ?
              AND b.mi_id IS NOT NULL
        ");
        $stmt2->execute([(int)$skuId]);
        $bomLines = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        if (empty($bomLines)) {
            $missingBomSkus[] = (string)$skuId;
            continue;
        }

        $catCost = array_fill_keys(COGS_FICHE_CATEGORIES, 0.0);

        foreach ($bomLines as $line) {
            $cost = (float)($line['cost'] ?? 0);
            if ($cost === 0.0) continue;

            $bomSource = (string)$line['bom_source'];
            $fcat      = null;

            if ($bomSource === 'liquid' || $bomSource === 'composite_liquid') {
                $fcat = cogs_fiche_map_category(
                    (string)$line['category'],
                    $line['subcategory'] !== null ? (string)$line['subcategory'] : null,
                    (string)$line['mi_id_str'],
                    (string)$line['mi_id_str']
                );
            } else {
                // Packaging BOM: default by SKU format, override by MI category
                // Mirrors TS: fmt==='can'→Canettes, 'keg'→CapsFuts, 'bot'→Bouteilles, else→Cartons
                $fmt = strtolower((string)($line['format'] ?? ''));
                if ($fmt === 'can') {
                    $defaultCat = 'Canettes';
                } elseif ($fmt === 'keg') {
                    $defaultCat = 'CapsFuts';
                } elseif ($fmt === 'bot') {
                    $defaultCat = 'Bouteilles';
                } else {
                    $defaultCat = 'Cartons';
                }

                $overrideCat = cogs_fiche_map_category(
                    (string)$line['category'],
                    $line['subcategory'] !== null ? (string)$line['subcategory'] : null,
                    (string)$line['mi_id_str'],
                    (string)$line['mi_id_str']
                );
                $fcat = $overrideCat ?? $defaultCat;
            }

            if ($fcat !== null && in_array($fcat, COGS_FICHE_CATEGORIES, true)) {
                $catCost[$fcat] += $cost;
            }
        }

        $totalBomCost = array_sum($catCost);
        if ($totalBomCost === 0.0) {
            $zeroCostSkus[] = (string)$skuId;
        }

        $skuBomCost[(int)$skuId] = $catCost;
    }

    // Accumulate FG values: qty × per-unit BOM cost by category
    foreach ($fgRows as $fgRow) {
        $qty   = (float)$fgRow['qty'];
        $skuId = (int)$fgRow['sku_id_fk'];
        if ($qty === 0.0) continue;
        if (!isset($skuBomCost[$skuId])) continue;

        $bomCat = $skuBomCost[$skuId];
        foreach (COGS_FICHE_CATEGORIES as $cat) {
            $byCategory[$cat] += $qty * ($bomCat[$cat] ?? 0.0);
        }
    }

    return [
        'by_category'     => $byCategory,
        'missing_bom_skus'=> $missingBomSkus,
        'zero_cost_skus'  => $zeroCostSkus,
        'fg_missing'      => $fgMissing,
        'notes'           => $notes,
    ];
}

// ── WIP computation ────────────────────────────────────────────────────────────

/**
 * Compute WIP closing values by fiche category.
 *
 * Source: inv_tank_balances for the month_key.
 * brew_cost_per_hl: from ref_beer_types.brew_cost_per_hl (case-insensitive match).
 *   inv_tank_balances.brew_cost_per_hl is NULL for all rows — must NOT be used.
 * Representative SKU: Keg-format SKU via recipe name (case-insensitive); fallback
 *   to any SKU with liquid BOM lines (handles bottle-only beers like Diversion Blanche).
 * WIP proportion: liquid BOM category cost / total liquid BOM cost.
 * Only BREWING categories (Malt/Hops/Ingredients/Yeast) receive WIP values.
 *
 * @return array{by_category: array<string,float>, beers_found: string[],
 *               beers_missing_cost: string[], beers_no_bom: string[], notes: string[]}
 */
function _cogs_wip_compute(PDO $pdo, string $month): array
{
    $byCategory       = array_fill_keys(COGS_FICHE_CATEGORIES, 0.0);
    $beersFound       = [];
    $beersMissingCost = [];
    $beersNoBom       = [];
    $notes            = [];

    $stmt = $pdo->prepare("
        SELECT tank_id, beer_name, volume_hl, brew_cost_per_hl
        FROM inv_tank_balances
        WHERE month_key = ?
    ");
    $stmt->execute([$month]);
    $tankRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notes[] = sprintf('WIP tank rows: %d', count($tankRows));

    // Group by beer_name
    $beerNames = array_unique(array_column($tankRows, 'beer_name'));

    foreach ($beerNames as $beerName) {
        // Step 1: brew_cost_per_hl from ref_beer_types (case-insensitive)
        $stmt2 = $pdo->prepare("
            SELECT brew_cost_per_hl
            FROM ref_beer_types
            WHERE LOWER(beer_name) = LOWER(?)
            LIMIT 1
        ");
        $stmt2->execute([$beerName]);
        $beerTypeRow = $stmt2->fetch(PDO::FETCH_ASSOC);

        if ($beerTypeRow === false || $beerTypeRow['brew_cost_per_hl'] === null) {
            $beersMissingCost[] = $beerName;
            $notes[] = sprintf('WIP: no brew_cost_per_hl in ref_beer_types for beer "%s"', $beerName);
            continue;
        }

        $brewCostPerHl = (float)$beerTypeRow['brew_cost_per_hl'];
        if ($brewCostPerHl === 0.0) {
            $notes[] = sprintf('WIP: brew_cost_per_hl=0 for beer "%s" — skipping', $beerName);
            continue;
        }

        // Step 2: representative Keg SKU via recipe name (case-insensitive)
        $stmt3 = $pdo->prepare("
            SELECT rs.id AS sku_id, rs.sku_code
            FROM ref_skus rs
            JOIN ref_recipes r   ON r.id = rs.recipe_id
            JOIN ref_packaging_formats pf ON pf.id = rs.format_id
            WHERE rs.format = 'Keg'
              AND LOWER(r.name) = LOWER(?)
            ORDER BY rs.sku_code
            LIMIT 1
        ");
        $stmt3->execute([$beerName]);
        $kegRow = $stmt3->fetch(PDO::FETCH_ASSOC);

        $repSkuId   = null;
        $repSkuCode = null;

        if ($kegRow !== false) {
            $repSkuId   = (int)$kegRow['sku_id'];
            $repSkuCode = (string)$kegRow['sku_code'];
        } else {
            // Fallback: any SKU with liquid BOM lines
            $stmt4 = $pdo->prepare("
                SELECT rs.id AS sku_id, rs.sku_code
                FROM ref_skus rs
                JOIN ref_recipes r ON r.id = rs.recipe_id
                WHERE LOWER(r.name) = LOWER(?)
                  AND rs.id IN (
                      SELECT DISTINCT sku_id FROM ref_sku_bom
                      WHERE bom_source IN ('liquid', 'composite_liquid')
                        AND mi_id IS NOT NULL
                  )
                ORDER BY rs.sku_code
                LIMIT 1
            ");
            $stmt4->execute([$beerName]);
            $anyRow = $stmt4->fetch(PDO::FETCH_ASSOC);

            if ($anyRow !== false) {
                $repSkuId   = (int)$anyRow['sku_id'];
                $repSkuCode = (string)$anyRow['sku_code'];
                $notes[] = sprintf('WIP: beer "%s" has no Keg SKU — using fallback SKU %s', $beerName, $repSkuCode);
            }
        }

        if ($repSkuId === null) {
            $beersNoBom[] = $beerName;
            $notes[] = sprintf('WIP: no representative SKU found for beer "%s"', $beerName);
            continue;
        }

        // Step 3: liquid BOM lines for the representative SKU
        $stmt5 = $pdo->prepare("
            SELECT
                b.bom_source,
                cat.name    AS category,
                sub.name    AS subcategory,
                m.mi_id     AS mi_id_str,
                b.cost
            FROM ref_sku_bom b
            JOIN ref_mi m              ON m.id = b.mi_id
            JOIN ref_mi_categories cat ON cat.id = m.category_id
            LEFT JOIN ref_mi_subcategories sub ON sub.id = m.subcategory_id
            WHERE b.sku_id = ?
              AND b.bom_source IN ('liquid', 'composite_liquid')
              AND b.mi_id IS NOT NULL
        ");
        $stmt5->execute([$repSkuId]);
        $liquidBom = $stmt5->fetchAll(PDO::FETCH_ASSOC);

        if (empty($liquidBom)) {
            $beersNoBom[] = $beerName;
            $notes[] = sprintf('WIP: no liquid BOM lines for SKU %s (id=%d, beer="%s")', $repSkuCode, $repSkuId, $beerName);
            continue;
        }

        // Step 4: per-category proportions from liquid BOM cost
        $catCost        = array_fill_keys(COGS_FICHE_CATEGORIES, 0.0);
        $totalLiquidCost = 0.0;

        foreach ($liquidBom as $line) {
            $cost = (float)($line['cost'] ?? 0);
            if ($cost === 0.0) continue;
            $fcat = cogs_fiche_map_category(
                (string)$line['category'],
                $line['subcategory'] !== null ? (string)$line['subcategory'] : null,
                (string)$line['mi_id_str'],
                (string)$line['mi_id_str']
            );
            if ($fcat !== null) {
                $catCost[$fcat] += $cost;
            }
            $totalLiquidCost += $cost;
        }

        if ($totalLiquidCost === 0.0) {
            $notes[] = sprintf('WIP: total liquid BOM cost=0 for beer "%s" (SKU %s) — skipping', $beerName, $repSkuCode);
            continue;
        }

        $beersFound[] = $beerName;

        // Step 5: accumulate WIP value (brewing categories only)
        $beerVolumeHl = 0.0;
        foreach ($tankRows as $t) {
            if ($t['beer_name'] === $beerName) {
                $beerVolumeHl += (float)$t['volume_hl'];
            }
        }

        foreach (COGS_BREWING_CATEGORIES as $cat) {
            if (!isset($catCost[$cat]) || $catCost[$cat] === 0.0) continue;
            $proportion = $catCost[$cat] / $totalLiquidCost;
            $wipValue   = $beerVolumeHl * $brewCostPerHl * $proportion;
            $byCategory[$cat] += $wipValue;
        }
    }

    return [
        'by_category'       => $byCategory,
        'beers_found'       => $beersFound,
        'beers_missing_cost'=> $beersMissingCost,
        'beers_no_bom'      => $beersNoBom,
        'notes'             => $notes,
    ];
}

// ── Opening anchor ─────────────────────────────────────────────────────────────

/**
 * Load prior-month opening total_chf per category.
 *
 * Precedence: sealed > monthly(live cache) > seed.
 *   1. If the prior month has an active seal in cogs_fiche_sealed, use those values
 *      (frozen by operator — must never be overridden by a recompute).
 *   2. Otherwise, fall back to cogs_fiche_monthly (live cache).
 *   3. Otherwise, fall back to cogs_fiche_seed (immutable April anchor).
 *
 * This ensures that once a month is sealed, its closing value propagates correctly
 * as the opening of the following month, even if the live cache is later invalidated.
 *
 * @return array{by_category: array<string,float>, notes: string[]}
 */
function _cogs_opening_load(PDO $pdo, string $month): array
{
    $byCategory = array_fill_keys(COGS_FICHE_CATEGORIES, 0.0);
    $notes      = [];

    $priorMonth = cogs_fiche_prev_month($month);

    // 1. Try active seal for prior month (highest precedence).
    // Active seal = event with the latest sealed_at; reads all 12 rows in that event.
    $sealedStmt = $pdo->prepare("
        SELECT s.category_key, s.total_chf
        FROM cogs_fiche_sealed s
        INNER JOIN (
            SELECT sealed_at, sealed_by, supersedes_seal_id
            FROM cogs_fiche_sealed
            WHERE month_key = ?
            ORDER BY id DESC
            LIMIT 1
        ) latest
            ON  s.sealed_at           = latest.sealed_at
            AND s.sealed_by           <=> latest.sealed_by
            AND s.supersedes_seal_id  <=> latest.supersedes_seal_id
        WHERE s.month_key = ?
    ");
    $sealedStmt->execute([$priorMonth, $priorMonth]);
    $sealedRows = $sealedStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($sealedRows)) {
        foreach ($sealedRows as $r) {
            $ck = (string)$r['category_key'];
            if (in_array($ck, COGS_FICHE_CATEGORIES, true)) {
                $byCategory[$ck] = (float)$r['total_chf'];
            }
        }
        $notes[] = sprintf(
            'Opening (prior month %s): sealed=%d rows (sealed takes precedence)',
            $priorMonth,
            count($sealedRows)
        );
        return ['by_category' => $byCategory, 'notes' => $notes];
    }

    // 2. Seed rows (immutable operator-seeded opening)
    $stmt = $pdo->prepare("
        SELECT category_key, total_chf
        FROM cogs_fiche_seed
        WHERE month_key = ?
    ");
    $stmt->execute([$priorMonth]);
    $seedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Monthly rows (computed closes — override seed if present)
    $stmt2 = $pdo->prepare("
        SELECT category_key, total_chf
        FROM cogs_fiche_monthly
        WHERE month_key = ?
    ");
    $stmt2->execute([$priorMonth]);
    $monthlyRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    foreach ($seedRows as $r) {
        $ck = (string)$r['category_key'];
        if (in_array($ck, COGS_FICHE_CATEGORIES, true)) {
            $byCategory[$ck] = (float)$r['total_chf'];
        }
    }
    foreach ($monthlyRows as $r) {
        $ck = (string)$r['category_key'];
        if (in_array($ck, COGS_FICHE_CATEGORIES, true)) {
            $byCategory[$ck] = (float)$r['total_chf']; // override seed
        }
    }

    $notes[] = sprintf(
        'Opening (prior month %s): no seal — seed=%d rows, monthly=%d rows',
        $priorMonth,
        count($seedRows),
        count($monthlyRows)
    );

    $missingCats = [];
    foreach (COGS_FICHE_CATEGORIES as $c) {
        $inSeed    = count(array_filter($seedRows, fn($r) => $r['category_key'] === $c)) > 0;
        $inMonthly = count(array_filter($monthlyRows, fn($r) => $r['category_key'] === $c)) > 0;
        if (!$inSeed && !$inMonthly) {
            $missingCats[] = $c;
        }
    }
    if (!empty($missingCats)) {
        $notes[] = 'Opening: WARNING — no prior-month data for categories: ' . implode(', ', $missingCats) . ' (using 0)';
    }

    return ['by_category' => $byCategory, 'notes' => $notes];
}

// ── Basis adjustment ───────────────────────────────────────────────────────────

/**
 * Compute basis adjustment per category.
 *
 * Revalues prior-month FG at CURRENT ref_sku_bom costs, then subtracts
 * the seeded prior fg_chf. FG/F2 portion only.
 *
 * Only meaningful for the first computed month after a seed (April→May seam).
 * For months whose prior month has no seed rows, returns all-zero.
 *
 * basis_adjustment_chf[cat] = priorFgRevalued[cat] − seedPriorFgChf[cat]
 *
 * @return array{by_category: array<string,float>, total_adj: float, notes: string[]}
 */
function _cogs_basis_adjustment_compute(PDO $pdo, string $month): array
{
    $byCategory = array_fill_keys(COGS_FICHE_CATEGORIES, 0.0);
    $notes      = [];

    $priorMonth = cogs_fiche_prev_month($month);

    // Only compute when prior month is seeded (seed = immutable anchor)
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM cogs_fiche_seed WHERE month_key = ?");
    $stmt->execute([$priorMonth]);
    $seedCnt = (int)$stmt->fetchColumn();

    if ($seedCnt === 0) {
        $notes[] = sprintf(
            'basis_adjustment: prior month %s not in cogs_fiche_seed — no adjustment needed (computed close chains directly)',
            $priorMonth
        );
        return ['by_category' => $byCategory, 'total_adj' => 0.0, 'notes' => $notes];
    }

    // Step 1: Load prior-month FG rows (same query as _cogs_fg_compute)
    $stmt2 = $pdo->prepare("
        SELECT sku, sku_id_fk, qty
        FROM (
            SELECT sku, sku_id_fk, qty,
                   ROW_NUMBER() OVER (
                       PARTITION BY sku_id_fk, location_id_fk
                       ORDER BY counted_at DESC, id DESC
                   ) AS rn
            FROM inv_fg_stocktake
            WHERE month_closed = ?
              AND count_type = 'month_end'
        ) z
        WHERE z.rn = 1
        ORDER BY sku
    ");
    $stmt2->execute([$priorMonth]);
    $fgRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $notes[] = sprintf('basis_adjustment: prior-month (%s) FG rows loaded: %d', $priorMonth, count($fgRows));

    if (empty($fgRows)) {
        $notes[] = sprintf('basis_adjustment: zero FG rows for %s — adjustment is zero', $priorMonth);
        return ['by_category' => $byCategory, 'total_adj' => 0.0, 'notes' => $notes];
    }

    // Step 2: Fetch CURRENT BOM for each unique prior-month SKU
    $skuIds     = array_unique(array_column($fgRows, 'sku_id_fk'));
    $skuBomCost = [];

    foreach ($skuIds as $skuId) {
        $stmt3 = $pdo->prepare("
            SELECT
                b.bom_source,
                cat.name    AS category,
                sub.name    AS subcategory,
                m.mi_id     AS mi_id_str,
                b.cost,
                rs.format
            FROM ref_sku_bom b
            JOIN ref_mi m              ON m.id = b.mi_id
            JOIN ref_mi_categories cat ON cat.id = m.category_id
            LEFT JOIN ref_mi_subcategories sub ON sub.id = m.subcategory_id
            JOIN ref_skus rs           ON rs.id = b.sku_id
            WHERE b.sku_id = ?
              AND b.mi_id IS NOT NULL
        ");
        $stmt3->execute([(int)$skuId]);
        $bomLines = $stmt3->fetchAll(PDO::FETCH_ASSOC);

        if (empty($bomLines)) continue;

        $catCost = array_fill_keys(COGS_FICHE_CATEGORIES, 0.0);

        foreach ($bomLines as $line) {
            $cost = (float)($line['cost'] ?? 0);
            if ($cost === 0.0) continue;

            $bomSource = (string)$line['bom_source'];
            $fcat      = null;

            if ($bomSource === 'liquid' || $bomSource === 'composite_liquid') {
                $fcat = cogs_fiche_map_category(
                    (string)$line['category'],
                    $line['subcategory'] !== null ? (string)$line['subcategory'] : null,
                    (string)$line['mi_id_str'],
                    (string)$line['mi_id_str']
                );
            } else {
                $fmt = strtolower((string)($line['format'] ?? ''));
                if ($fmt === 'can') {
                    $defaultCat = 'Canettes';
                } elseif ($fmt === 'keg') {
                    $defaultCat = 'CapsFuts';
                } elseif ($fmt === 'bot') {
                    $defaultCat = 'Bouteilles';
                } else {
                    $defaultCat = 'Cartons';
                }
                $overrideCat = cogs_fiche_map_category(
                    (string)$line['category'],
                    $line['subcategory'] !== null ? (string)$line['subcategory'] : null,
                    (string)$line['mi_id_str'],
                    (string)$line['mi_id_str']
                );
                $fcat = $overrideCat ?? $defaultCat;
            }

            if ($fcat !== null && in_array($fcat, COGS_FICHE_CATEGORIES, true)) {
                $catCost[$fcat] += $cost;
            }
        }

        $skuBomCost[(int)$skuId] = $catCost;
    }

    // Step 3: Revalue prior-month FG at current BOM costs
    $revalued = array_fill_keys(COGS_FICHE_CATEGORIES, 0.0);
    foreach ($fgRows as $fgRow) {
        $qty   = (float)$fgRow['qty'];
        $skuId = (int)$fgRow['sku_id_fk'];
        if ($qty === 0.0) continue;
        if (!isset($skuBomCost[$skuId])) continue;

        $bomCat = $skuBomCost[$skuId];
        foreach (COGS_FICHE_CATEGORIES as $cat) {
            $revalued[$cat] += $qty * ($bomCat[$cat] ?? 0.0);
        }
    }

    // Step 4: Load seeded prior-month fg_chf
    $stmt4 = $pdo->prepare("SELECT category_key, fg_chf FROM cogs_fiche_seed WHERE month_key = ?");
    $stmt4->execute([$priorMonth]);
    $seedFgRows = $stmt4->fetchAll(PDO::FETCH_ASSOC);

    $seedFgByCat = [];
    foreach ($seedFgRows as $r) {
        $seedFgByCat[(string)$r['category_key']] = (float)$r['fg_chf'];
    }

    $notes[] = sprintf('basis_adjustment: seed fg_chf loaded for %d categories', count($seedFgRows));

    // Step 5: delta per category
    foreach (COGS_FICHE_CATEGORIES as $cat) {
        $revaluedFg  = $revalued[$cat] ?? 0.0;
        $seedFg      = $seedFgByCat[$cat] ?? 0.0;
        $byCategory[$cat] = $revaluedFg - $seedFg;
    }

    $totalAdj = (float)array_sum($byCategory);
    $notes[] = sprintf(
        'basis_adjustment: total = %+.2f CHF (FG/F2 restatement; RM portion non-isolable — base héritée)',
        $totalAdj
    );

    return ['by_category' => $byCategory, 'total_adj' => $totalAdj, 'notes' => $notes];
}

// ── Utility ────────────────────────────────────────────────────────────────────

/** Returns the YYYY-MM of the month preceding $month. */
function cogs_fiche_prev_month(string $month): string
{
    [$y, $m] = array_map('intval', explode('-', $month));
    if ($m === 1) {
        return sprintf('%04d-12', $y - 1);
    }
    return sprintf('%04d-%02d', $y, $m - 1);
}

// ── Public API ─────────────────────────────────────────────────────────────────

/**
 * Compute the COGS fiche for a single month.
 *
 * Pure compute — no writes, no cache, no side-effects.
 *
 * @param PDO    $pdo   maltytask PDO connection
 * @param string $month YYYY-MM
 * @return array{
 *   categories: array<string, array{
 *     rm_chf: float, wip_chf: float, fg_chf: float, total_chf: float,
 *     opening_chf: float, variation_chf: float, basis_adjustment_chf: float
 *   }>,
 *   totals: array{
 *     rm_chf: float, wip_chf: float, fg_chf: float, total_chf: float,
 *     opening_chf: float, variation_chf: float, basis_adjustment_chf: float
 *   },
 *   diagnostics: array<string, mixed>,
 *   notes: string[]
 * }
 */
function cogs_fiche_compute_month(PDO $pdo, string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        throw new \InvalidArgumentException(sprintf(
            'cogs_fiche_compute_month: invalid month "%s" — expected YYYY-MM',
            $month
        ));
    }

    // Run all four legs
    $rmResult      = _cogs_rm_compute($pdo, $month);
    $fgResult      = _cogs_fg_compute($pdo, $month);
    $wipResult     = _cogs_wip_compute($pdo, $month);
    $openingResult = _cogs_opening_load($pdo, $month);
    $basisResult   = _cogs_basis_adjustment_compute($pdo, $month);

    // Assemble per-category rows
    $categories = [];
    $totals = [
        'rm_chf'               => 0.0,
        'wip_chf'              => 0.0,
        'fg_chf'               => 0.0,
        'total_chf'            => 0.0,
        'opening_chf'          => 0.0,
        'variation_chf'        => 0.0,
        'basis_adjustment_chf' => 0.0,
    ];

    foreach (COGS_FICHE_CATEGORIES as $cat) {
        $rm       = $rmResult['by_category'][$cat]      ?? 0.0;
        $wip      = $wipResult['by_category'][$cat]     ?? 0.0;
        $fg       = $fgResult['by_category'][$cat]      ?? 0.0;
        $total    = $rm + $wip + $fg;
        $opening  = $openingResult['by_category'][$cat] ?? 0.0;
        $variation = $total - $opening;
        $basisAdj = $basisResult['by_category'][$cat]   ?? 0.0;

        $categories[$cat] = [
            'rm_chf'               => $rm,
            'wip_chf'              => $wip,
            'fg_chf'               => $fg,
            'total_chf'            => $total,
            'opening_chf'          => $opening,
            'variation_chf'        => $variation,
            'basis_adjustment_chf' => $basisAdj,
        ];

        $totals['rm_chf']               += $rm;
        $totals['wip_chf']              += $wip;
        $totals['fg_chf']               += $fg;
        $totals['total_chf']            += $total;
        $totals['opening_chf']          += $opening;
        $totals['variation_chf']        += $variation;
        $totals['basis_adjustment_chf'] += $basisAdj;
    }

    // Collect all notes
    $allNotes = array_merge(
        $rmResult['notes'],
        $fgResult['notes'],
        $wipResult['notes'],
        $openingResult['notes'],
        $basisResult['notes']
    );

    $diagnostics = [
        'month'                   => $month,
        'rm_formula'              => 'valueCHF = final_qty × cost_chf (NO conversionFactor — final_qty is in pricing unit)',
        'wip_cost_source'         => 'ref_beer_types.brew_cost_per_hl (inv_tank_balances.brew_cost_per_hl is NULL)',
        'wip_method'              => 'totalVolumeHl × brewCostPerHl × (liquid BOM category proportion)',
        'rm_no_basis_mi_ids'      => $rmResult['no_basis'],
        'rm_excluded_count'       => count($rmResult['excluded']),
        'rm_unit_mismatch_items'  => $rmResult['unit_mismatch_items'],
        'fg_missing'              => $fgResult['fg_missing'],
        'fg_missing_bom_skus'     => $fgResult['missing_bom_skus'],
        'fg_zero_cost_skus'       => $fgResult['zero_cost_skus'],
        'wip_beers_found'         => $wipResult['beers_found'],
        'wip_beers_missing_cost'  => $wipResult['beers_missing_cost'],
        'wip_beers_no_bom'        => $wipResult['beers_no_bom'],
        'opening_prior_month'     => cogs_fiche_prev_month($month),
        'opening_resolver'        => 'sealed > cogs_fiche_monthly > cogs_fiche_seed — sealed prior month takes precedence',
        'basis_adjustment_total_chf' => $basisResult['total_adj'],
        'basis_adjustment_scope'  => 'FG/F2 restatement only — RM portion non-isolable (base héritée)',
        'basis_adjustment_notes'  => $basisResult['notes'],
    ];

    return [
        'categories'  => $categories,
        'totals'      => $totals,
        'diagnostics' => $diagnostics,
        'notes'       => $allNotes,
    ];
}
