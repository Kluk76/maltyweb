<?php
declare(strict_types=1);

/**
 * app/sku-compiler.php
 *
 * Compiles the static BOM for a given SKU from its recipe and format templates.
 * Called by the SKU builder UI after operator confirms the SKU configuration.
 *
 * Temporal versioning note (project_recipe_versioning_temporal.md):
 *   v1 (current): effective_from/effective_until columns exist but are NULL=always-current.
 *                 This function reads rows with effective_until IS NULL (or > today),
 *                 but does NOT write SCD2 history. The DELETE+INSERT pattern replaces
 *                 the entire BOM atomically, which is safe for v1.
 *   v2 (after SKU builder UI commissioning): activate SCD2 — instead of DELETE, close
 *                 existing rows with effective_until = NOW() and INSERT new rows with
 *                 effective_from = NOW(). Downstream queries add:
 *                   WHERE :as_of >= COALESCE(effective_from, '1970-01-01')
 *                     AND :as_of <  COALESCE(effective_until, '2999-12-31')
 *   v3 (retrofit): downstream warehouse.php / build-sales-cogs.js add as-of filter.
 *
 * DO NOT modify warehouse.php, warehouse-export.php, or sku-builder*.php directly —
 * those files consume ref_sku_bom but do not call this function.
 *
 * Usage:
 *   $result = compile_sku_bom($skuId, $pdo);
 *   $result = compile_all_skus($pdo);               // all active SKUs
 *   $result = compile_all_skus($pdo, [1, 2, 3]);    // specific SKU IDs
 */

/**
 * bbt_factor: fraction of wort volume that reaches BBT.
 * Approximates the wort→BBT transfer loss (fermentation + trub + dry-hop absorption).
 * TODO: refine with operator — actual measured yield per recipe may vary ±2%.
 * Current default: 0.97 (3% loss wort→BBT).
 */
const BBT_FACTOR = 0.97;

// ─────────────────────────────────────────────────────────────────────────────
// compile_sku_bom()
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Compiles and writes the full BOM for a single SKU.
 *
 * @param int $skuId  The ref_skus.id for the SKU to compile.
 * @param PDO $pdo    Active PDO connection (use maltytask_pdo()).
 *
 * @return array {
 *   'inserted'        => int,   // total rows inserted into ref_sku_bom
 *   'liquid_lines'    => int,
 *   'packaging_lines' => int,
 *   'compiled_at'     => string, // ISO 8601 UTC
 *   'warnings'        => string[], // non-fatal issues (e.g. missing PKG_STICKER_* MI)
 * }
 *
 * @throws RuntimeException if SKU, recipe, or format are not found.
 */
function compile_sku_bom(int $skuId, PDO $pdo): array
{
    $warnings     = [];
    $liquidLines  = [];
    $pkgLines     = [];

    // ── 1. Load SKU ───────────────────────────────────────────────────────────

    $stmt = $pdo->prepare(
        'SELECT s.id, s.sku_code, s.recipe_id_fk, s.format_id,
                f.hl_per_unit, f.is_composite, f.format_code,
                r.sku_prefix, r.recipe_short_name, r.uses_branded_scotch
           FROM ref_skus s
           LEFT JOIN ref_packaging_formats f ON f.id = s.format_id
           LEFT JOIN ref_recipes r           ON r.id = s.recipe_id_fk
          WHERE s.id = ?'
    );
    $stmt->execute([$skuId]);
    $sku = $stmt->fetch();

    if ($sku === false) {
        throw new RuntimeException("compile_sku_bom: ref_skus.id={$skuId} not found");
    }
    if ($sku['format_id'] === null) {
        throw new RuntimeException(
            "compile_sku_bom: sku_code={$sku['sku_code']} has no format_id (run _backfill-skus-format-recipe.ts --apply first)"
        );
    }

    $hlPerUnit       = (float) $sku['hl_per_unit'];
    $isComposite     = (bool)  $sku['is_composite'];
    $recipeCode      = $sku['sku_prefix'] ?? $sku['recipe_short_name'] ?? $sku['sku_code'];
    $usesBrandedScotch = (bool) ($sku['uses_branded_scotch'] ?? false);

    // ── 2. Liquid lines ───────────────────────────────────────────────────────

    if (!$isComposite) {
        // Non-composite: query ref_recipe_ingredients for the recipe
        if ($sku['recipe_id_fk'] === null) {
            throw new RuntimeException(
                "compile_sku_bom: sku_code={$sku['sku_code']} is not composite but has no recipe_id_fk"
            );
        }

        $stmt = $pdo->prepare(
            'SELECT rri.id, rri.mi_id_fk, rri.qty_per_hl, rri.unit, rri.basis, rri.skip_if_observed,
                    m.mi_id AS mi_code
               FROM ref_recipe_ingredients rri
               JOIN ref_mi m ON m.id = rri.mi_id_fk
              WHERE rri.recipe_id = ?
                AND rri.is_active = 1
                AND (rri.effective_until IS NULL OR rri.effective_until > CURDATE())'
        );
        $stmt->execute([$sku['recipe_id_fk']]);
        $ingredients = $stmt->fetchAll();

        foreach ($ingredients as $ing) {
            // skip_if_observed rows are per-batch dynamic — not included in static BOM
            if ((bool) $ing['skip_if_observed']) {
                $warnings[] = "Skipped skip_if_observed ingredient {$ing['mi_code']} for sku_code={$sku['sku_code']}";
                continue;
            }

            $qtyPerUnit = _liquid_qty_per_unit(
                (float) $ing['qty_per_hl'],
                $ing['basis'],
                $hlPerUnit
            );

            $liquidLines[] = [
                'mi_id_fk'   => (int) $ing['mi_id_fk'],
                'qty_per_unit' => $qtyPerUnit,
                'unit'       => $ing['unit'],
                'bom_source' => 'liquid',
            ];
        }
    } else {
        // Composite: aggregate ingredients from constituent recipe slots
        $stmt = $pdo->prepare(
            'SELECT cs.recipe_id, cs.multiple, cs.slot_order
               FROM ref_sku_composite_slots cs
              WHERE cs.sku_id = ?
                AND (cs.effective_until IS NULL OR cs.effective_until > CURDATE())
              ORDER BY cs.slot_order'
        );
        $stmt->execute([$skuId]);
        $slots = $stmt->fetchAll();

        if (empty($slots)) {
            $warnings[] = "Composite sku_code={$sku['sku_code']} has no entries in ref_sku_composite_slots — liquid lines skipped";
        }

        // Aggregate per mi_id_fk across all slots
        $aggregated = []; // mi_id_fk → ['qty' => float, 'unit' => string]

        foreach ($slots as $slot) {
            $stmt2 = $pdo->prepare(
                'SELECT rri.mi_id_fk, rri.qty_per_hl, rri.unit, rri.basis, rri.skip_if_observed,
                         m.mi_id AS mi_code
                    FROM ref_recipe_ingredients rri
                    JOIN ref_mi m ON m.id = rri.mi_id_fk
                   WHERE rri.recipe_id = ?
                     AND rri.is_active = 1
                     AND (rri.effective_until IS NULL OR rri.effective_until > CURDATE())'
            );
            $stmt2->execute([$slot['recipe_id']]);
            $ings = $stmt2->fetchAll();

            // hl contributed by this slot: multiple × hl_per_unit / total_bottles_in_composite
            // For composites, hl_per_unit on the format is total; each slot contributes proportionally.
            // multiple = number of bottles of this beer in the composite unit.
            // We compute hl per slot bottle = format.hl_per_unit / total_bottle_count.
            // But total_bottle_count requires summing multiples across all slots — defer to post-loop.
            // For now, store raw qty_per_hl × multiple; normalise after.
            foreach ($ings as $ing) {
                if ((bool) $ing['skip_if_observed']) continue;

                $key = (int) $ing['mi_id_fk'];
                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = ['qty' => 0.0, 'unit' => $ing['unit']];
                }
                // scale: qty_per_hl (per wort HL) × (multiple × hl per slot-bottle)
                // hl per slot-bottle = hlPerUnit / total_multiples (computed after loop)
                // For now accumulate: qty_per_hl × multiple (divide by total_multiples later)
                $aggregated[$key]['qty'] += (float) $ing['qty_per_hl'] * (int) $slot['multiple'];
            }
        }

        // Compute total multiples for normalisation
        $totalMultiples = (int) array_sum(array_column($slots, 'multiple'));
        if ($totalMultiples > 0) {
            $hlPerSlotBottle = $hlPerUnit / $totalMultiples;
            foreach ($aggregated as $miIdFk => $data) {
                $liquidLines[] = [
                    'mi_id_fk'    => $miIdFk,
                    'qty_per_unit' => $data['qty'] * $hlPerSlotBottle,
                    'unit'        => $data['unit'],
                    'bom_source'  => 'composite_liquid',
                ];
            }
        }
    }

    // ── 3. Packaging lines ────────────────────────────────────────────────────

    $stmt = $pdo->prepare(
        'SELECT rpi.slot_name, rpi.qty_per_unit, rpi.mi_filter_pattern,
                rpi.default_mi_id_fk, rpi.is_default_checked, rpi.display_order
           FROM ref_packaging_items rpi
          WHERE rpi.format_id = ?
            AND rpi.is_default_checked = 1
            AND (rpi.effective_until IS NULL OR rpi.effective_until > CURDATE())
          ORDER BY rpi.display_order'
    );
    $stmt->execute([$sku['format_id']]);
    $pkgItems = $stmt->fetchAll();

    foreach ($pkgItems as $item) {
        // Check for per-SKU override
        $override = _load_sku_packaging_override($skuId, $item['slot_name'], $pdo);

        if ($override !== null) {
            // Explicit override: use override's mi_id_fk and qty
            if ($override['mi_id_fk'] !== null) {
                $pkgLines[] = [
                    'mi_id_fk'    => (int) $override['mi_id_fk'],
                    'qty_per_unit' => (float) $override['qty_per_unit'],
                    'unit'        => 'unit',
                    'bom_source'  => $isComposite ? 'composite_packaging' : 'packaging',
                ];
            }
            continue;
        }

        // No override — resolve via template
        $pattern = $item['mi_filter_pattern'];

        if ($item['slot_name'] === 'scotch') {
            // Branded scotch logic (migration 074)
            $miId = _resolve_scotch_mi($recipeCode, $usesBrandedScotch, $pdo, $warnings);
        } elseif (str_contains($pattern, '{beer}')) {
            // Per-beer templated pattern
            $miId = _resolve_beer_template_mi($pattern, $recipeCode, $pdo);
            if ($miId === null && $isComposite) {
                // For composites, skip beer-specific slots that have no global default
                $warnings[] = "Composite slot '{$item['slot_name']}' pattern '{$pattern}' skipped — use per-SKU override";
                continue;
            }
        } else {
            // Static default MI
            $miId = $item['default_mi_id_fk'] !== null ? (int) $item['default_mi_id_fk'] : null;
        }

        if ($miId === null) {
            $warnings[] = "Slot '{$item['slot_name']}' (pattern: {$pattern}) resolved to no MI for sku_code={$sku['sku_code']} — skipped";
            continue;
        }

        $pkgLines[] = [
            'mi_id_fk'    => $miId,
            'qty_per_unit' => (float) $item['qty_per_unit'],
            'unit'        => 'unit',
            'bom_source'  => $isComposite ? 'composite_packaging' : 'packaging',
        ];
    }

    // ── 4. Write BOM in transaction ───────────────────────────────────────────

    $allLines   = array_merge($liquidLines, $pkgLines);
    $compiledAt = gmdate('Y-m-d H:i:s'); // UTC

    $pdo->beginTransaction();
    try {
        // Clear existing BOM for this SKU
        $del = $pdo->prepare('DELETE FROM ref_sku_bom WHERE sku_id = ?');
        $del->execute([$skuId]);

        // Insert new lines
        $ins = $pdo->prepare(
            'INSERT INTO ref_sku_bom
               (sku_id, mi_id, ingredient_raw, source, qty_per_unit, ing_unit, compiled_at, bom_source, row_hash)
             VALUES
               (:sku_id, :mi_id, :ingredient_raw, :source, :qty_per_unit, :ing_unit, :compiled_at, :bom_source, :row_hash)'
        );

        foreach ($allLines as $line) {
            $rowHash = hash('sha256', implode('|', [
                $skuId,
                $line['mi_id_fk'],
                $line['qty_per_unit'],
                $compiledAt,
            ]));
            $ins->execute([
                ':sku_id'         => $skuId,
                ':mi_id'          => $line['mi_id_fk'],
                ':ingredient_raw' => '',            // compiled rows have no raw text
                ':source'         => 'sku-compiler',
                ':qty_per_unit'   => round($line['qty_per_unit'], 6),
                ':ing_unit'       => $line['unit'],
                ':compiled_at'    => $compiledAt,
                ':bom_source'     => $line['bom_source'],
                ':row_hash'       => $rowHash,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'inserted'        => count($allLines),
        'liquid_lines'    => count($liquidLines),
        'packaging_lines' => count($pkgLines),
        'compiled_at'     => $compiledAt,
        'warnings'        => $warnings,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// compile_all_skus()
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Convenience wrapper — compiles BOM for all active SKUs (or a specific subset).
 *
 * @param PDO        $pdo     Active PDO connection.
 * @param array|null $skuIds  If non-null, only compile these SKU IDs.
 *
 * @return array {
 *   'total'    => int,
 *   'ok'       => int,
 *   'errors'   => array<int, string>,   // sku_id → error message
 *   'warnings' => array<int, string[]>, // sku_id → warning list
 * }
 */
function compile_all_skus(PDO $pdo, ?array $skuIds = null): array
{
    if ($skuIds !== null && count($skuIds) === 0) {
        return ['total' => 0, 'ok' => 0, 'errors' => [], 'warnings' => []];
    }

    if ($skuIds !== null) {
        $placeholders = implode(',', array_fill(0, count($skuIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id FROM ref_skus WHERE format_id IS NOT NULL AND id IN ({$placeholders}) ORDER BY sku_code"
        );
        $stmt->execute($skuIds);
    } else {
        $stmt = $pdo->query(
            'SELECT id FROM ref_skus WHERE format_id IS NOT NULL ORDER BY sku_code'
        );
    }

    $ids    = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $ok     = 0;
    $errors = [];
    $warns  = [];

    foreach ($ids as $id) {
        $skuId = (int) $id;
        try {
            $result = compile_sku_bom($skuId, $pdo);
            $ok++;
            if (!empty($result['warnings'])) {
                $warns[$skuId] = $result['warnings'];
            }
        } catch (Throwable $e) {
            $errors[$skuId] = $e->getMessage();
        }
    }

    return [
        'total'    => count($ids),
        'ok'       => $ok,
        'errors'   => $errors,
        'warnings' => $warns,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Private helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Computes qty_per_unit for a liquid ingredient given its basis.
 *
 * basis='hl_wort': qty_per_unit = qty_per_hl × hl_per_unit
 * basis='hl_bbt':  qty_per_unit = qty_per_hl × hl_per_unit × BBT_FACTOR
 *                  (accounts for wort→BBT volume loss before BBT dosing)
 *
 * TODO (v2 refinement): BBT_FACTOR could become per-recipe once measured yields
 * are collected. For now the global 0.97 constant is used.
 */
function _liquid_qty_per_unit(float $qtyPerHl, string $basis, float $hlPerUnit): float
{
    if ($basis === 'hl_bbt') {
        return $qtyPerHl * $hlPerUnit * BBT_FACTOR;
    }
    // default: hl_wort
    return $qtyPerHl * $hlPerUnit;
}

/**
 * Loads a per-SKU packaging override from ref_sku_packaging_choices.
 * Returns null if no override exists.
 */
function _load_sku_packaging_override(int $skuId, string $slotName, PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        'SELECT mi_id_fk, qty_per_unit, is_checked
           FROM ref_sku_packaging_choices
          WHERE sku_id = ?
            AND slot_name = ?
            AND is_checked = 1
            AND (effective_until IS NULL OR effective_until > CURDATE())
          LIMIT 1'
    );
    $stmt->execute([$skuId, $slotName]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/**
 * Resolves the scotch MI for a given beer, applying the branded-scotch rule.
 *
 * When uses_branded_scotch=1: try PKG_SCOTCH_{BEER} first; fallback to PKG_SCOTCH_TRANSP.
 * When uses_branded_scotch=0: always use PKG_SCOTCH_TRANSP.
 */
function _resolve_scotch_mi(string $recipeCode, bool $usesBrandedScotch, PDO $pdo, array &$warnings): ?int
{
    if ($usesBrandedScotch) {
        $brandedId = _lookup_mi_by_id_string("PKG_SCOTCH_{$recipeCode}", $pdo);
        if ($brandedId !== null) return $brandedId;
        // Fallback: branded MI not found — use transparent, emit warning
        $warnings[] = "PKG_SCOTCH_{$recipeCode} not found in ref_mi — falling back to PKG_SCOTCH_TRANSP";
    }

    return _lookup_mi_by_id_string('PKG_SCOTCH_TRANSP', $pdo);
}

/**
 * Resolves a templated MI pattern (containing {beer}) by substituting the recipe code.
 * Pattern may contain a LIKE wildcard % at the end.
 *
 * Returns ref_mi.id or null if not found.
 */
function _resolve_beer_template_mi(string $pattern, string $recipeCode, PDO $pdo): ?int
{
    // Substitute {beer} with recipe code (upper case)
    $resolved = str_replace('{beer}', strtoupper($recipeCode), $pattern);

    // Strip regex alternation patterns (e.g. PKG_SCOTCH_(TRANSP|{beer})% → handled by _resolve_scotch_mi)
    // If the resolved string still contains ( or ), skip — handled by calling context
    if (str_contains($resolved, '(') || str_contains($resolved, ')')) {
        return null;
    }

    // Exact lookup first
    $id = _lookup_mi_by_id_string(rtrim($resolved, '%'), $pdo);
    if ($id !== null) return $id;

    // LIKE lookup if pattern ends with %
    if (str_ends_with($resolved, '%')) {
        $stmt = $pdo->prepare('SELECT id FROM ref_mi WHERE mi_id LIKE ? LIMIT 1');
        $stmt->execute([$resolved]);
        $row = $stmt->fetch();
        return $row !== false ? (int) $row['id'] : null;
    }

    return null;
}

/**
 * Returns ref_mi.id for the given mi_id string, or null if not found.
 */
function _lookup_mi_by_id_string(string $miId, PDO $pdo): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM ref_mi WHERE mi_id = ? LIMIT 1');
    $stmt->execute([$miId]);
    $row = $stmt->fetch();
    return $row !== false ? (int) $row['id'] : null;
}
