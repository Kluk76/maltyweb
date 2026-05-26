<?php
declare(strict_types=1);

/**
 * recipe-ingredients-loader.php
 *
 * Reads ref_recipe_ingredients with GAP-FILL semantics — applies recipe rows
 * ONLY for MIs that are NOT already present in observed brewing data
 * (bd_brewing_ingredients_parsed). Observed always wins.
 *
 * Unions across multi-vintage recipes via `ref_recipes.name = beer_name` JOIN
 * (e.g. EPH1 has 5 ref_recipes rows; all contribute to the result).
 *
 * Rule (memory: feedback_observed_data_wins_over_ref_recipe.md):
 *   1. Observed in bd_brewing_ingredients_parsed → used directly by the existing
 *      brew-cost SQL. This loader does NOT touch those rows.
 *   2. For each (beer, batch), this loader returns ref_recipe rows whose
 *      mi_id_fk is NOT in the observed set — i.e. gap-fill only.
 *   3. Caller adds the resulting rows on top of the observed-cost calculation.
 *
 * All qty_per_hl values are per HL of wort. No basis distinction — operators
 * enter recipes per HL (or per brew, normalized via ref_brewhouse_size).
 *
 * Temporal-versioning placeholder:
 *   - v1 (now): effective_from / effective_until are NULL → "always current".
 *   - v2: each UPDATE produces a new row, closing the previous via effective_until.
 *   - v3: callers pass $asof_date so historical CHF calculations reflect the
 *         recipe definition valid at that date.
 */

/**
 * Convert a recipe-ingredient unit to the canonical (pricing) unit factor.
 *
 * Returns the multiplicative factor to apply to a qty in $fromUnit to obtain
 * the equivalent qty in $pricingUnit. Returns null (REFUSE) when the conversion
 * cannot be performed safely — the caller must skip the line and log it rather
 * than silently applying a wrong cost.
 *
 * Supported conversions:
 *   g  → kg : 0.001
 *   ml → l  : 0.001
 *   ml → kg : $densityGPerMl × 0.001   (requires a non-null $densityGPerMl)
 *   same unit → same unit : 1.0
 *   anything else : null (REFUSE)
 *
 * NEVER defaults ml→kg to 0.001 (water density) — doing so silently
 * underestimates dense liquids by up to ×1.685 (phosphoric acid).
 * Pass $densityGPerMl from ref_mi.density_g_per_ml; it is NULL for MIs
 * that have no confirmed SDS value, which correctly triggers REFUSE.
 *
 * The six SQL `IF(bip.unit='g', 0.001, 1)` occurrences in warehouse.php
 * (lines ~563/817/829/841/854/1071) and the one in warehouse-export.php
 * (~line 255) read bd_brewing_ingredients_parsed_v2 which stores observed
 * brewing data — currently g/kg only for those rows — and are intentionally
 * left unchanged. Do NOT extend them to handle ml without first routing
 * through this function.
 *
 * @param string     $fromUnit      Unit of the recipe line (e.g. 'ml', 'g', 'kg')
 * @param string     $pricingUnit   Unit of ref_mi.pricing_unit (e.g. 'kg', 'l')
 * @param float|null $densityGPerMl ref_mi.density_g_per_ml — null if not confirmed
 * @return float|null factor, or null if conversion is not safe
 */
function unit_to_canonical_factor(string $fromUnit, string $pricingUnit, ?float $densityGPerMl): ?float
{
    $from = strtolower(trim($fromUnit));
    $to   = strtolower(trim($pricingUnit));

    if ($from === $to) {
        return 1.0;
    }
    if ($from === 'g' && $to === 'kg') {
        return 0.001;
    }
    if ($from === 'ml' && $to === 'l') {
        return 0.001;
    }
    if ($from === 'ml' && $to === 'kg') {
        // Require a confirmed SDS density — refuse if absent to avoid silent under-costing
        if ($densityGPerMl === null) {
            return null;
        }
        return $densityGPerMl * 0.001;
    }
    return null;
}

/**
 * Map simulator-short beer names to canonical (ref_recipes / bd_brewing) names.
 * Callers may pass either form; both downstream joins need canonical.
 */
function canonical_beer_name_for_loader(string $raw): string {
    $map = [
        'Div.Blanche'   => 'Diversion Blanche',
        'Div. Blanche'  => 'Diversion Blanche',
        'Div.Gose'      => 'Diversion Gose',
        'Div. Gose'     => 'Diversion Gose',
        'Div.Panaché'   => 'Diversion Panaché',
    ];
    return $map[$raw] ?? $raw;
}

/**
 * Return gap-fill ingredient rows for (beer_name, batch). Rows already present
 * in bd_brewing_ingredients_parsed for this batch are excluded.
 *
 * @return array<array{
 *   mi_id_fk: int,
 *   mi_id: string,
 *   mi_name: string,
 *   qty: float,
 *   unit: string,
 *   qty_per_hl: float,
 *   hl_applied: float
 * }>
 */
function load_recipe_ingredients_for_batch(
    PDO $pdo,
    string $beer_name,
    string $batch,
    float $brew_hl,
    ?string $asof_date = null
): array {
    $beer_name = canonical_beer_name_for_loader($beer_name);
    $asof = $asof_date ?? date('Y-m-d');

    // Step 1: observed mi_id_fks for this batch (excluded from gap-fill).
    // bd_brewing_ingredients_parsed_v2 is normalized: beer/batch live on the header
    // (bd_brewing_ingredients_v2), joined via header_id.
    $observed = [];
    $stmt = $pdo->prepare("
        SELECT DISTINCT bip.mi_id_fk
          FROM bd_brewing_ingredients_parsed_v2 bip
          JOIN bd_brewing_ingredients_v2 bih ON bih.id = bip.header_id
         WHERE bih.beer  COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
           AND bih.batch COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
           AND bip.mi_id_fk IS NOT NULL
    ");
    $stmt->execute([$beer_name, $batch]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        $observed[(int) $mid] = true;
    }

    // Step 2: ref_recipe rows for any recipe matching this beer name (unions vintages)
    $stmt = $pdo->prepare("
        SELECT
          ri.mi_id_fk,
          m.mi_id,
          m.name AS mi_name,
          m.pricing_unit,
          ri.qty_per_hl,
          ri.unit
        FROM ref_recipe_ingredients ri
        JOIN ref_mi m       ON m.id  = ri.mi_id_fk
        JOIN ref_recipes r  ON r.id  = ri.recipe_id
        WHERE r.name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
          AND r.is_active  = 1
          AND ri.is_active = 1
          AND (ri.effective_from  IS NULL OR ri.effective_from  <= ?)
          AND (ri.effective_until IS NULL OR ri.effective_until >  ?)
        ORDER BY ri.mi_id_fk
    ");
    $stmt->execute([$beer_name, $asof, $asof]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Step 3: gap-fill — emit only rows whose mi_id_fk is NOT observed
    $applied = [];
    foreach ($rows as $r) {
        $miFk = (int) $r['mi_id_fk'];
        if (isset($observed[$miFk])) continue;
        $applied[] = [
            'mi_id_fk'   => $miFk,
            'mi_id'      => (string) $r['mi_id'],
            'mi_name'    => (string) $r['mi_name'],
            'qty'        => (float) $r['qty_per_hl'] * $brew_hl,
            'unit'       => (string) ($r['unit'] ?? 'kg'),
            'qty_per_hl' => (float) $r['qty_per_hl'],
            'hl_applied' => $brew_hl,
        ];
    }
    return $applied;
}

/**
 * Batched convenience wrapper. One round-trip per batch.
 *
 * @param array<array{beer_name: string, batch: string, brew_hl: float}> $batches
 * @return array<string, array<array{...}>>
 */
function load_recipe_ingredients_batched(
    PDO $pdo,
    array $batches,
    ?string $asof_date = null
): array {
    $out = [];
    foreach ($batches as $b) {
        $key = $b['beer_name'] . '|' . $b['batch'];
        $out[$key] = load_recipe_ingredients_for_batch(
            $pdo,
            (string) $b['beer_name'],
            (string) $b['batch'],
            (float)  $b['brew_hl'],
            $asof_date
        );
    }
    return $out;
}
