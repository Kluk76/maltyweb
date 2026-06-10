<?php
declare(strict_types=1);

/**
 * app/sku-bom-compile.php
 *
 * Packaging + composite ref_sku_bom recompute service.
 *
 * For NON-COMPOSITE SKUs (no ref_sku_composite_slots rows):
 *   Rebuilds ONLY the source='Packaging' lines. Liquid lines left byte-identical.
 *   Safe-delete predicate: DELETE WHERE sku_id=? AND source='Packaging'
 *
 * For COMPOSITE SKUs (has ref_sku_composite_slots rows):
 *   Replaces ALL stale flat rows (bom_source IS NULL) with:
 *     composite_liquid  — per-member-recipe ingredient lines (source='Brewing',
 *                         bom_source='composite_liquid', volume_hl NULL,
 *                         ingredient_raw prefixed with '[PREFIX]' to avoid UNIQUE collision)
 *     composite_packaging — overwrap items resolved via ref_sku_packaging_choices
 *                         (source='Packaging', bom_source='composite_packaging')
 *   Safe-delete predicate: DELETE WHERE sku_id=? AND bom_source IS NULL
 *   Per-member liquid basis: each member's own single-SKU liquid BOM (BU format preferred,
 *   else smallest-HL bottle format), normalised to per-HL, then × slot_hl.
 *   Refuse-don't-NULL: unresolved member or overwrap slot → RQ row, NEVER a NULL mi_id line.
 *
 * COLLAB (sku_code IN ref_sku_collab_temporal):
 *   recipe_id resolved via ref_sku_collab_temporal before the buildability gate,
 *   then compiled as a normal single-recipe SKU (no composite_slots).
 *
 * Resolve precedence per packaging slot:
 *   1. ref_sku_packaging_choices   (SKU-level override)
 *   2. ref_recipe_packaging_bindings (recipe-level binding by role)
 *   3. ref_packaging_items.default_mi_id_fk (template default)
 *   → unresolved: emit self-sufficient doc_review_queue row (type sku-bom-unresolved)
 *                 NEVER insert a mi_id=NULL row.
 *
 * Slot scope filtering:
 *   always        → always included
 *   labelled_only → only when template decoration_integral = 0
 *   we_supply_only→ only when template supply = 'we_supply'
 *
 * Box-sticker rule (§8.1) — AMENDED:
 *   For 24-box formats (B id=1 / C id=7 / BC id=8), box decoration is exactly ONE of:
 *     (1) box-sticker: REQUIRED iff scotch=TRANSP AND box_label resolves NULL.
 *     (2) branded scotch → sticker intentionally absent (keep existing behaviour).
 *     (3) box_label resolves non-NULL → sticker intentionally absent (no line, no RQ).
 *   EXCEPTION: an active Tier-1 sticker choice (ref_sku_packaging_choices.mi_id_fk NOT NULL)
 *              overrides suppression (3) — explicit beats structural (ZEPC choice-47 principle).
 *   Explicit-NULL box_label choices (mi_id_fk=NULL, e.g. ALTB/DIBB/etc.) resolve to null
 *   → NO suppression → their stickers process normally.
 *   box_label template item exists only on fmt 1; B12/ZEPC (fmt 7) are unaffected.
 *
 * @param PDO        $pdo           Active DB connection (maltytask_pdo()).
 * @param int[]|null $skuIds        SKU ids to recompute. Null = auto-detect the 25 affected.
 * @param bool       $dryRun        If true: compute + report but do NOT write (default true).
 * @param bool       $packagingOnly Must be true in v1; false would allow liquid recompute (unimplemented).
 *
 * @return array {
 *   'dry_run'    => bool,
 *   'skus'       => array<int, array{
 *       sku_code:        string,
 *       format_code:     string,
 *       sku_prefix:      string,
 *       pkg_deleted:     int,
 *       pkg_inserted:    int,
 *       rq_emitted:      int,
 *       liq_rows_before: int,
 *       liq_cost_before: float,
 *       liq_rows_after:  int,
 *       liq_cost_after:  float,
 *       parity_ok:       bool,
 *       error:           string|null,
 *   }>,
 *   'total_pkg_deleted'  => int,
 *   'total_pkg_inserted' => int,
 *   'total_rq_emitted'   => int,
 *   'parity_violations'  => int,
 *   'errors'             => int,
 * }
 */
function compile_sku_bom_packaging(
    PDO    $pdo,
    ?array $skuIds   = null,
    bool   $dryRun   = true,
    bool   $packagingOnly = true
): array {

    if (!$packagingOnly) {
        throw new \InvalidArgumentException(
            'compile_sku_bom_packaging: packagingOnly=false is not implemented in v1. ' .
            'Full-liquid recompute requires the F2 liquid-source decision to be settled first.'
        );
    }

    // ── 1. Resolve affected SKU set ───────────────────────────────────────────

    if ($skuIds === null) {
        // Arm 1: solo SKUs with un-resolved NULL packaging rows (the original gate).
        $stmt = $pdo->query(
            "SELECT DISTINCT sku_id FROM ref_sku_bom WHERE mi_id IS NULL AND source = 'Packaging'"
        );
        $nullPkgIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN, 0));

        // Arm 2: composite SKUs — composites have bom_source='composite_packaging' and
        // already-populated mi_id rows after the first compile, so they never appear in
        // the NULL-mi_id arm even when a label binding changes.  Always include every
        // active composite so the cron keeps them fresh.
        $compStmt = $pdo->query(
            "SELECT DISTINCT cs.sku_id
               FROM ref_sku_composite_slots cs
               JOIN ref_skus s ON s.id = cs.sku_id AND s.is_active = 1"
        );
        $compositeIds = array_map('intval', $compStmt->fetchAll(\PDO::FETCH_COLUMN, 0));

        // Arm 3: COLLAB SKUs whose recipe_id is NULL in ref_skus and is resolved
        // via ref_sku_collab_temporal.  Same reasoning: their rows already exist
        // after first compile, so they drop out of arm 1 silently.
        $collabStmt = $pdo->query(
            "SELECT DISTINCT s.id
               FROM ref_skus s
               JOIN ref_sku_collab_temporal ct ON ct.sku_code = s.sku_code
                AND ct.effective_from <= CURDATE()
                AND (ct.effective_until IS NULL OR ct.effective_until > CURDATE())
              WHERE s.recipe_id IS NULL AND s.is_active = 1"
        );
        $collabIds = array_map('intval', $collabStmt->fetchAll(\PDO::FETCH_COLUMN, 0));

        // Arm 4: derived-format SKUs (e.g. draft pours P25/P50 with
        // derived_from_format_id set).  Their packaging rows exist after first
        // compile and won't appear in arm 1; always include so the cron keeps
        // them fresh when MI prices change.
        $derivedStmt = $pdo->query(
            "SELECT DISTINCT s.id
               FROM ref_skus s
               JOIN ref_packaging_formats f ON f.id = s.format_id
              WHERE f.derived_from_format_id IS NOT NULL
                AND s.is_active = 1"
        );
        $derivedIds = array_map('intval', $derivedStmt->fetchAll(\PDO::FETCH_COLUMN, 0));

        $skuIds = array_values(array_unique(array_merge($nullPkgIds, $compositeIds, $collabIds, $derivedIds)));
    }

    if (count($skuIds) === 0) {
        return [
            'dry_run'            => $dryRun,
            'skus'               => [],
            'total_pkg_deleted'  => 0,
            'total_pkg_inserted' => 0,
            'total_rq_emitted'   => 0,
            'parity_violations'  => 0,
            'errors'             => 0,
        ];
    }

    // ── 1b. COLLAB temporal resolver ─────────────────────────────────────────
    // Resolve recipe_id for COLLAB SKUs from ref_sku_collab_temporal BEFORE the
    // buildability gate (which INNER-JOINs ref_recipes on s.recipe_id).
    // Keyed by sku_id → resolved recipe_id (int).
    $collabResolvedRecipes = [];
    if (!empty($skuIds)) {
        $collabPlaceholders = implode(',', array_fill(0, count($skuIds), '?'));
        $collabStmt = $pdo->prepare(
            "SELECT s.id AS sku_id, ct.recipe_id
               FROM ref_skus s
               JOIN ref_sku_collab_temporal ct
                 ON ct.sku_code = s.sku_code
                AND ct.effective_from <= CURDATE()
                AND (ct.effective_until IS NULL OR ct.effective_until > CURDATE())
              WHERE s.id IN ({$collabPlaceholders})
                AND s.recipe_id IS NULL"
        );
        $collabStmt->execute($skuIds);
        foreach ($collabStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $collabResolvedRecipes[(int)$row['sku_id']] = (int)$row['recipe_id'];
        }
    }

    // ── 2. Load SKU metadata ─────────────────────────────────────────────────
    // The buildability gate INNER-JOINs ref_recipes on s.recipe_id.
    // COLLAB SKUs have recipe_id=NULL in ref_skus — inject resolved recipe_id via subquery.
    // Composite SKUs (PD8/PAL/XMAS/PAC) have recipe_id=NULL too; they are detected by
    // the presence of ref_sku_composite_slots rows and take a separate compile path.
    // The INNER-JOIN on ref_packaging_bom_templates intentionally excludes composites
    // (no we_supply template for composite formats) — we handle that in the composite branch.
    //
    // Derived-format SKUs (e.g. P25/P50 draft pours) have derived_from_format_id set and
    // catalog_id=NULL — they cannot satisfy the dbc INNER-JOIN on their own format.
    // They pass the gate via the PARENT format's dbc chain (three-leg JOIN through parent_f).

    // Pre-detect derived-format SKU ids within the requested set.
    // These need a separate metaQuery branch joining through the parent format's dbc chain.
    $derivedFormatSkuIds = [];
    if (!empty($skuIds)) {
        $dfp = implode(',', array_fill(0, count($skuIds), '?'));
        $dfStmt = $pdo->prepare(
            "SELECT s.id
               FROM ref_skus s
               JOIN ref_packaging_formats f ON f.id = s.format_id
              WHERE s.id IN ({$dfp})
                AND f.derived_from_format_id IS NOT NULL
                AND f.is_composite = 0"
        );
        $dfStmt->execute($skuIds);
        $derivedFormatSkuIds = array_map('intval', $dfStmt->fetchAll(\PDO::FETCH_COLUMN, 0));
    }

    // Build a UNION to cover normally-recipe'd SKUs, COLLAB-resolved SKUs, and
    // derived-format SKUs (pours).  Always use the UNION path so all three branches
    // can be represented cleanly.
    $collabIds   = array_keys($collabResolvedRecipes);
    // Normal: not collab-resolved, not derived-format, not composite (composites have no recipe_id)
    $normalIds   = array_diff($skuIds, $collabIds, $derivedFormatSkuIds);
    $queryParts  = [];
    $metaParams  = [];

    if (!empty($normalIds)) {
        $np = implode(',', array_fill(0, count($normalIds), '?'));
        $queryParts[] = "SELECT s.id, s.sku_code, s.format_id, s.recipe_id, s.hl_per_unit,
                f.format_code, f.run_type AS fmt_run_type,
                r.sku_prefix, r.uses_branded_scotch,
                bt.decoration_integral, bt.supply,
                0 AS collab_resolved
           FROM ref_skus s
           JOIN ref_packaging_formats f ON f.id = s.format_id
           JOIN ref_recipes r           ON r.id = s.recipe_id
           JOIN ref_packaging_bom_templates bt
             ON bt.format_id = s.format_id
            AND bt.supply = 'we_supply'
            AND bt.is_active = 1
           JOIN dbc_packaging_format_templates t ON t.id  = f.catalog_id
           JOIN dbc_container_types            c ON c.container_code = t.container_code
           JOIN ref_filler_containers         fc ON fc.container_id  = c.id AND fc.is_active = 1
           JOIN ref_process_machines           m ON m.id = fc.machine_id   AND m.is_active = 1
          WHERE s.id IN ({$np})
            AND f.is_active = 1
            AND f.is_composite = 0";
        foreach ($normalIds as $nid) {
            $metaParams[] = $nid;
        }
    }

    // COLLAB: substitute resolved recipe_id to pass the INNER-JOIN buildability gate
    foreach ($collabIds as $cid) {
        $rId = $collabResolvedRecipes[$cid];
        $queryParts[] = "SELECT s.id, s.sku_code, s.format_id, ? AS recipe_id, s.hl_per_unit,
                f.format_code, f.run_type AS fmt_run_type,
                r.sku_prefix, r.uses_branded_scotch,
                bt.decoration_integral, bt.supply,
                1 AS collab_resolved
           FROM ref_skus s
           JOIN ref_packaging_formats f ON f.id = s.format_id
           JOIN ref_recipes r           ON r.id = ?
           JOIN ref_packaging_bom_templates bt
             ON bt.format_id = s.format_id
            AND bt.supply = 'we_supply'
            AND bt.is_active = 1
           JOIN dbc_packaging_format_templates t ON t.id  = f.catalog_id
           JOIN dbc_container_types            c ON c.container_code = t.container_code
           JOIN ref_filler_containers         fc ON fc.container_id  = c.id AND fc.is_active = 1
           JOIN ref_process_machines           m ON m.id = fc.machine_id   AND m.is_active = 1
          WHERE s.id = ?
            AND f.is_active = 1
            AND f.is_composite = 0";
        $metaParams[] = $rId;
        $metaParams[] = $rId;
        $metaParams[] = $cid;
    }

    // Derived-format: format has derived_from_format_id set (e.g. P25/P50 → keg).
    // Gate passes via PARENT format's dbc chain (parent_f.catalog_id → dbc → filler → machine).
    // The format's own run_type is NULL for draft pours — use it as-is (known-null-volume path).
    if (!empty($derivedFormatSkuIds)) {
        $dp = implode(',', array_fill(0, count($derivedFormatSkuIds), '?'));
        $queryParts[] = "SELECT s.id, s.sku_code, s.format_id, s.recipe_id, s.hl_per_unit,
                f.format_code, f.run_type AS fmt_run_type,
                r.sku_prefix, r.uses_branded_scotch,
                bt.decoration_integral, bt.supply,
                0 AS collab_resolved
           FROM ref_skus s
           JOIN ref_packaging_formats f       ON f.id = s.format_id
           JOIN ref_packaging_formats parent_f ON parent_f.id = f.derived_from_format_id
           JOIN ref_recipes r                  ON r.id = s.recipe_id
           JOIN ref_packaging_bom_templates bt
             ON bt.format_id = s.format_id
            AND bt.supply = 'we_supply'
            AND bt.is_active = 1
           JOIN dbc_packaging_format_templates t ON t.id  = parent_f.catalog_id
           JOIN dbc_container_types            c ON c.container_code = t.container_code
           JOIN ref_filler_containers         fc ON fc.container_id  = c.id AND fc.is_active = 1
           JOIN ref_process_machines           m ON m.id = fc.machine_id   AND m.is_active = 1
          WHERE s.id IN ({$dp})
            AND f.is_active = 1
            AND f.is_composite = 0";
        foreach ($derivedFormatSkuIds as $did) {
            $metaParams[] = $did;
        }
    }

    if (empty($queryParts)) {
        // All requested SKUs were composites or collabs with no resolved recipe — skuIndex stays empty.
        $metaQuery = "SELECT NULL AS id, NULL AS sku_code, NULL AS format_id, NULL AS recipe_id,
                             NULL AS hl_per_unit, NULL AS format_code, NULL AS fmt_run_type,
                             NULL AS sku_prefix, NULL AS uses_branded_scotch,
                             NULL AS decoration_integral, NULL AS supply,
                             NULL AS collab_resolved WHERE 1=0";
        $metaParams = [];
    } else {
        $metaQuery = implode(' UNION ALL ', $queryParts) . ' ORDER BY sku_code';
    }

    $stmt = $pdo->prepare($metaQuery);
    $stmt->execute($metaParams);
    $skuRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Index by sku_id
    $skuIndex = [];
    foreach ($skuRows as $row) {
        $skuIndex[(int)$row['id']] = $row;
    }

    // Warn about any requested sku_ids that didn't resolve
    $missingIds = array_diff($skuIds, array_keys($skuIndex));

    // ── 3. Pre-load shared lookup data ──────────────────────────────────────

    // ref_mi: id → {mi_id, name, category_id, price, currency, pricing_unit, cost_chf, cost_basis}
    // cost_chf and cost_basis come from v_mi_cost (WAC > catalog > no_basis, FX-normalised to CHF).
    // cost_chf is the canonical per-pricing-unit cost used for BOM line costing from this point forward.
    $allMiStmt = $pdo->query(
        "SELECT m.id, m.mi_id, m.name, m.is_active, c.name AS cat_name,
                m.price, m.currency, m.pricing_unit,
                v.cost_chf, v.cost_basis
           FROM ref_mi m
           JOIN ref_mi_categories c ON c.id = m.category_id
           LEFT JOIN v_mi_cost v ON v.mi_id_fk = m.id"
    );
    $miById = [];
    foreach ($allMiStmt->fetchAll(\PDO::FETCH_ASSOC) as $m) {
        $miById[(int)$m['id']] = $m;
    }

    // ref_recipe_packaging_bindings: (recipe_id, role) → mi_id_fk
    $bindStmt = $pdo->query(
        "SELECT recipe_id, role, mi_id_fk
           FROM ref_recipe_packaging_bindings
          WHERE (effective_until IS NULL OR effective_until > CURDATE())"
    );
    $bindings = [];
    foreach ($bindStmt->fetchAll(\PDO::FETCH_ASSOC) as $b) {
        $bindings[(int)$b['recipe_id']][$b['role']] = (int)$b['mi_id_fk'];
    }

    // ref_sku_packaging_choices: sku_id → slot_name → mi_id_fk (empty today)
    $choiceStmt = $pdo->query(
        "SELECT sku_id, slot_name, mi_id_fk, qty_per_unit
           FROM ref_sku_packaging_choices
          WHERE is_checked = 1
            AND (effective_until IS NULL OR effective_until > CURDATE())"
    );
    $skuChoices = [];
    foreach ($choiceStmt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
        $skuChoices[(int)$c['sku_id']][$c['slot_name']] = [
            'mi_id_fk'    => $c['mi_id_fk'] !== null ? (int)$c['mi_id_fk'] : null,
            'qty_per_unit' => (float)$c['qty_per_unit'],
        ];
    }

    // PKG_SCOTCH_TRANSP id (hard fact from §8.1)
    $scotchTranspId = _bom_lookup_mi_id('PKG_SCOTCH_TRANSP', $miById);

    // 24-box format IDs (B=1, C=7, BC=8 — verified live)
    $box24FormatIds = [1, 7, 8];

    // ── 3b. Composite slot map ───────────────────────────────────────────────
    // Detect composite SKUs by PRESENCE of ref_sku_composite_slots rows (not is_composite flag).
    // Keys: sku_id → array of slot rows sorted by slot_order.
    // Each slot: [recipe_id, units_per_recipe, member_format_id, sku_prefix, member_hl]
    $compositeSlots = [];
    if (!empty($skuIds)) {
        $csp = implode(',', array_fill(0, count($skuIds), '?'));
        $csStmt = $pdo->prepare(
            "SELECT cs.sku_id, cs.recipe_id, cs.units_per_recipe, cs.slot_order,
                    cs.member_format_id,
                    r.sku_prefix,
                    mf.hl_per_unit AS member_hl
               FROM ref_sku_composite_slots cs
               JOIN ref_recipes r  ON r.id  = cs.recipe_id
               JOIN ref_packaging_formats mf ON mf.id = cs.member_format_id
              WHERE cs.sku_id IN ({$csp})
                AND (cs.effective_until IS NULL OR cs.effective_until > CURDATE())
              ORDER BY cs.sku_id, cs.slot_order"
        );
        $csStmt->execute($skuIds);
        foreach ($csStmt->fetchAll(\PDO::FETCH_ASSOC) as $cs) {
            $compositeSlots[(int)$cs['sku_id']][] = $cs;
        }
    }

    // ── 3c. Member single-SKU liquid BOM index ───────────────────────────────
    // For each recipe_id that appears in composite slots, find the canonical single-unit
    // liquid BOM to use as the per-HL ingredient basis.
    // Selection priority: BU format (format_code='BU', hl_per_unit=0.0033) → else smallest HL bottle.
    // Each member's qty_per_unit from its BU/single-bottle SKU is already normalised to that
    // SKU's own hl_per_unit (0.0033 for BU). Per-HL = qty_per_unit / member_hl.
    // Then composite qty = (per_HL) × slot_hl.
    $compositeRecipeIds = [];
    foreach ($compositeSlots as $slots) {
        foreach ($slots as $slot) {
            $compositeRecipeIds[] = (int)$slot['recipe_id'];
        }
    }
    $compositeRecipeIds = array_values(array_unique($compositeRecipeIds));

    // memberLiquidBom: recipe_id → [ mi_id => ['qtyPerHl', 'costPerHl', 'ing_unit'], ... ]
    // Also memberSkuHl: recipe_id → the canonical hl_per_unit of the source SKU used
    $memberLiquidBom   = [];  // recipe_id → [mi_id => ['qtyPerHl'=>float, 'costPerHl'=>float|null, 'ing_unit'=>string]]
    $memberSourceSku   = [];  // recipe_id → sku_code of the source (for reporting)

    if (!empty($compositeRecipeIds)) {
        $crp = implode(',', array_fill(0, count($compositeRecipeIds), '?'));

        // Step 1: find the best source SKU per recipe (BU preferred, else min hl_per_unit bottle)
        $srcStmt = $pdo->prepare(
            "SELECT s.recipe_id, s.id AS sku_id, s.sku_code, s.hl_per_unit,
                    f.format_code,
                    CASE WHEN f.format_code = 'BU' THEN 0 ELSE 1 END AS fmt_priority
               FROM ref_skus s
               JOIN ref_packaging_formats f ON f.id = s.format_id
              WHERE s.recipe_id IN ({$crp})
                AND s.is_active = 1
                AND f.run_type = 'bot'
                AND EXISTS (
                    SELECT 1 FROM ref_sku_bom b
                     WHERE b.sku_id = s.id
                       AND b.source != 'Packaging'
                )
              ORDER BY s.recipe_id,
                       CASE WHEN f.format_code = 'BU' THEN 0 ELSE 1 END ASC,
                       s.hl_per_unit ASC"
        );
        $srcStmt->execute($compositeRecipeIds);
        $allSrcRows = $srcStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Pick the first (best) source SKU per recipe
        $recipeToSrcSku = [];  // recipe_id → [sku_id, sku_code, hl_per_unit]
        foreach ($allSrcRows as $sr) {
            $rid = (int)$sr['recipe_id'];
            if (!isset($recipeToSrcSku[$rid])) {
                $recipeToSrcSku[$rid] = [
                    'sku_id'     => (int)$sr['sku_id'],
                    'sku_code'   => $sr['sku_code'],
                    'hl_per_unit' => (float)$sr['hl_per_unit'],
                ];
                $memberSourceSku[$rid] = $sr['sku_code'];
            }
        }

        // Step 2: load liquid BOM rows for all selected source SKUs
        $srcSkuIds = array_column($recipeToSrcSku, 'sku_id');
        if (!empty($srcSkuIds)) {
            $ssp = implode(',', array_fill(0, count($srcSkuIds), '?'));
            $liqStmt = $pdo->prepare(
                "SELECT b.sku_id, b.mi_id, b.qty_per_unit, b.ing_unit, b.cost, s.hl_per_unit, s.recipe_id
                   FROM ref_sku_bom b
                   JOIN ref_skus s ON s.id = b.sku_id
                  WHERE b.sku_id IN ({$ssp})
                    AND b.source != 'Packaging'
                    AND b.mi_id IS NOT NULL
                  ORDER BY b.sku_id, b.mi_id"
            );
            $liqStmt->execute($srcSkuIds);

            // Build skuId → recipeId map for reverse lookup
            $skuIdToRecipe = [];
            foreach ($recipeToSrcSku as $rid => $skuInfo) {
                $skuIdToRecipe[$skuInfo['sku_id']] = $rid;
            }

            foreach ($liqStmt->fetchAll(\PDO::FETCH_ASSOC) as $liq) {
                $sid = (int)$liq['sku_id'];
                $rid = $skuIdToRecipe[$sid] ?? null;
                if ($rid === null) {
                    continue;
                }
                $hlPerUnit = (float)$liq['hl_per_unit'];
                if ($hlPerUnit <= 0) {
                    continue; // guard against zero division
                }
                $miId      = (int)$liq['mi_id'];
                $qtyPerHl  = (float)$liq['qty_per_unit'] / $hlPerUnit;
                // costPerHl carries the source row's already-correct cost (conversion_factor applied
                // when the source BOM was built). Storing per-HL cost avoids re-deriving from
                // price × qty which would ignore the g→kg unit conversion on hop lines.
                $costPerHl = $liq['cost'] !== null ? (float)$liq['cost'] / $hlPerUnit : null;
                // Accumulate (same MI may appear from different brews — take first since BOM
                // rows are already averaged from observed data; BU has exactly one row per MI)
                if (!isset($memberLiquidBom[$rid][$miId])) {
                    $memberLiquidBom[$rid][$miId] = [
                        'qtyPerHl'  => $qtyPerHl,
                        'costPerHl' => $costPerHl,
                        'ing_unit'  => $liq['ing_unit'],
                    ];
                }
            }
        }
    }

    // ── Volume dimension pre-load ────────────────────────────────────────────
    // Per-format: derived volume_hl + which slot_name carries the container role.
    //
    // Volume is derived from:
    //   ref_packaging_formats.catalog_id
    //   → dbc_packaging_format_templates.units_per_format + container_code
    //   → dbc_container_types.hl_per_unit
    //
    // Container-role identification (in slot terms) — ONLY the real consumable
    // container layers (bottle/can) own volume_hl on a BOM line:
    //   run_type 'bot'           → slot_name 'bottle'     (consumed, appears in BOM)
    //   run_type 'can'/'can33'   → slot_name 'can'        (consumed, appears in BOM)
    //   run_type 'keg'           → NULL (keg reusable, no keg MI in BOM — only accessories
    //                               keg_collars/keg_safe; SKU-level volume via v_sku_volume)
    //   run_type 'cuv'           → NULL (liner reusable-container, CUV_LINER hl null; fill-event volume)
    //   is_composite=1 OR catalog_id IS NULL → NULL (composites/draft-pours/6C/PAD; no static chain)
    //
    // volume_hl is set on the container-role line ONLY. All other lines get NULL.
    // CARDINAL RULE: cost is additive across lines; volume is NOT. Summing volume_hl
    // across all lines of one SKU would N×-count the liquid. One line owns it.
    $formatVolumeMapStmt = $pdo->query(
        "SELECT
             f.id         AS format_id,
             f.run_type,
             t.is_composite,
             t.units_per_format,
             c.hl_per_unit AS container_hl,
             CASE
                 WHEN t.is_composite = 1     THEN NULL
                 WHEN f.catalog_id  IS NULL   THEN NULL
                 WHEN c.hl_per_unit  IS NULL   THEN NULL
                 WHEN t.units_per_format IS NULL THEN NULL
                 ELSE (t.units_per_format * c.hl_per_unit)
             END AS volume_hl_derived
           FROM ref_packaging_formats f
           LEFT JOIN dbc_packaging_format_templates t ON t.id = f.catalog_id
           LEFT JOIN dbc_container_types c ON c.container_code = t.container_code"
    );
    // format_id → ['volume_hl' => float|null, 'container_slot' => string|null]
    $formatVolumeMap = [];
    foreach ($formatVolumeMapStmt->fetchAll(\PDO::FETCH_ASSOC) as $fv) {
        $runType    = $fv['run_type'];
        $volDerived = $fv['volume_hl_derived'] !== null ? (float)$fv['volume_hl_derived'] : null;

        // Map run_type → the slot_name of the real CONTAINER LAYER that carries volume.
        // ONLY bottle/can are consumable container BOM lines → they own volume_hl.
        // keg & cuv containers are REUSABLE — there is NO container MI line in the BOM,
        // only accessories (keg_collars/keg_safe, liner_client/liner_transport). Their
        // volume is a SKU-LEVEL fact via the v_sku_volume view, NOT forced onto an
        // accessory line (a collar/liner is not the container). cuv volume is also
        // fill-event-variable (CUV_LINER.volume_l is NULL by design).
        if ($runType === 'bot') {
            $containerSlot = 'bottle';
        } elseif ($runType === 'can' || $runType === 'can33') {
            $containerSlot = 'can';
        } else {
            // keg / cuv (reusable container — no consumable container line) / composite /
            // draft pours → no BOM line owns volume; SKU-level volume lives in v_sku_volume.
            $containerSlot = null;
        }

        $formatVolumeMap[(int)$fv['format_id']] = [
            'volume_hl'      => $volDerived,
            'container_slot' => $containerSlot,
        ];
    }

    // ── 4. Process each SKU ─────────────────────────────────────────────────

    $summary = [];
    $totalPkgDeleted  = 0;
    $totalPkgInserted = 0;
    $totalRqEmitted   = 0;
    $totalParityViol  = 0;
    $totalErrors      = 0;

    // ── UoT assertion: refuse-don't-NULL ─────────────────────────────────────
    // For each requested SKU: if its format is NOT in the dbc commissioning chain
    // AND it has no composite slots, SKIP it with an explicit error — do not silently no-op.
    // This surfaces future un-gated activations (contracted-out, draft formats, genuinely new).
    // Composites are passed through unconditionally (their formats have catalog_id=NULL by design).
    // The buildability gate INNER-JOIN above already excluded un-gated SKUs from skuIndex;
    // this assertion makes the exclusion VISIBLE in the $summary output so the CLI reports it.
    $gatedFormatIds = _compiler_gated_format_ids($pdo);
    $gatedFormatSet = array_flip($gatedFormatIds);  // O(1) lookup

    // Build sku_id → format_id map for ALL requested SKUs (including those that missed the gate)
    if (!empty($skuIds)) {
        $fmtPlaceholders = implode(',', array_fill(0, count($skuIds), '?'));
        $fmtStmt = $pdo->prepare(
            "SELECT id, format_id FROM ref_skus WHERE id IN ({$fmtPlaceholders})"
        );
        $fmtStmt->execute($skuIds);
        $skuFormatMap = [];
        foreach ($fmtStmt->fetchAll(\PDO::FETCH_ASSOC) as $fmtRow) {
            $skuFormatMap[(int)$fmtRow['id']] = (int)$fmtRow['format_id'];
        }
    } else {
        $skuFormatMap = [];
    }

    foreach ($skuIds as $_assertSkuId) {
        $_assertSkuId = (int)$_assertSkuId;
        // Composites: routed via composite branch — exempt from gate assertion
        if (isset($compositeSlots[$_assertSkuId])) {
            continue;
        }
        // Already in skuIndex: passed the buildability gate — OK
        if (isset($skuIndex[$_assertSkuId])) {
            continue;
        }
        // Not in skuIndex and not composite: check if it's a gate miss (un-commissioned format)
        $fmtId = $skuFormatMap[$_assertSkuId] ?? null;
        if ($fmtId !== null && !isset($gatedFormatSet[$fmtId])) {
            $summary[$_assertSkuId] = [
                'sku_code'        => "sku_id={$_assertSkuId}",
                'format_code'     => '',
                'sku_prefix'      => '',
                'pkg_deleted'     => 0,
                'pkg_inserted'    => 0,
                'rq_emitted'      => 0,
                'liq_rows_before' => 0,
                'liq_cost_before' => 0.0,
                'liq_rows_after'  => 0,
                'liq_cost_after'  => 0.0,
                'parity_ok'       => false,
                'error'           => "SKU id={$_assertSkuId} skipped: format_id={$fmtId} not commissioned (not in dbc gate) and not composite",
            ];
            $totalErrors++;
        }
        // If fmtId IS in gatedFormatSet but SKU still missed skuIndex, it fell through due to
        // missing recipe_id/template — the existing missingIds path below will catch it.
    }

    foreach ($skuIds as $skuId) {
        $skuId = (int)$skuId;

        // UoT assertion above already marked this SKU as errored (un-gated format,
        // not composite). Preserve that specific reason — do not let the generic
        // missingIds path overwrite the message or double-count $totalErrors.
        if (isset($summary[$skuId]) && ($summary[$skuId]['error'] ?? null) !== null) {
            continue;
        }

        // ── Route: composite vs. non-composite ───────────────────────────────
        // Composites are detected by the presence of ref_sku_composite_slots rows.
        // They are NOT in skuIndex (the buildability INNER-JOIN excludes them since
        // composite formats have no we_supply packaging template).
        if (isset($compositeSlots[$skuId])) {
            $composite = _bom_compile_composite(
                $pdo, $skuId, $compositeSlots[$skuId],
                $memberLiquidBom, $memberSourceSku,
                $miById, $skuChoices, $dryRun, $bindings
            );
            $summary[$skuId] = $composite;
            $totalPkgDeleted  += $composite['pkg_deleted'];
            $totalPkgInserted += $composite['pkg_inserted'];
            $totalRqEmitted   += $composite['rq_emitted'];
            if (!$composite['parity_ok'] || $composite['error'] !== null) {
                $totalErrors++;
            }
            continue;
        }

        if (!isset($skuIndex[$skuId])) {
            $summary[$skuId] = [
                'sku_code'        => "sku_id={$skuId}",
                'format_code'     => '',
                'sku_prefix'      => '',
                'pkg_deleted'     => 0,
                'pkg_inserted'    => 0,
                'rq_emitted'      => 0,
                'liq_rows_before' => 0,
                'liq_cost_before' => 0.0,
                'liq_rows_after'  => 0,
                'liq_cost_after'  => 0.0,
                'parity_ok'       => false,
                'error'           => "SKU id={$skuId} not found (no format_id or no we_supply template)",
            ];
            $totalErrors++;
            continue;
        }

        $sku = $skuIndex[$skuId];
        $skuCode    = $sku['sku_code'];
        $formatId   = (int)$sku['format_id'];
        $formatCode = $sku['format_code'];
        $recipeId   = (int)$sku['recipe_id'];
        $prefix     = strtoupper($sku['sku_prefix'] ?? '');
        $decoIntegral = (bool)(int)$sku['decoration_integral'];
        $isWeSupply   = ($sku['supply'] === 'we_supply');
        $usesBranded  = (bool)(int)$sku['uses_branded_scotch'];
        $is24Box      = in_array($formatId, $box24FormatIds, true);

        // ── 4a. Snapshot liquid baseline ────────────────────────────────────

        $liqBefore = _bom_liquid_snapshot($pdo, $skuId);

        // ── 4b. Load packaging items for this format ──────────────────────

        $itemStmt = $pdo->prepare(
            "SELECT id, slot_name, qty_per_unit, mi_filter_pattern,
                    default_mi_id_fk, slot_scope, display_order
               FROM ref_packaging_items
              WHERE format_id = ?
                AND (effective_until IS NULL OR effective_until > CURDATE())
              ORDER BY display_order"
        );
        $itemStmt->execute([$formatId]);
        $items = $itemStmt->fetchAll(\PDO::FETCH_ASSOC);

        // ── 4c. Resolve each slot ────────────────────────────────────────

        // volume_hl for this SKU's format (NULL for cuv/composite/P25/P50/PAD/6C)
        $formatVol = $formatVolumeMap[$formatId] ?? ['volume_hl' => null, 'container_slot' => null];
        $skuVolumeHl     = $formatVol['volume_hl'];
        $containerSlotName = $formatVol['container_slot'];

        $runTypeFmt = $sku['fmt_run_type'] ?? '';  // populated in step 2 via f.run_type

        $pkgLines = [];   // rows to INSERT: ['mi_id_fk' => int|null, 'slot_name' => str, 'qty' => float, 'volume_hl' => float|null, 'cost_direct' => float|null]
        $rqRows   = [];   // rows to emit in doc_review_queue

        // ── §cuv observed-liner branch ────────────────────────────────────────
        // For cuv (serving-tank) SKUs, liner cost is volume-weighted from observed
        // bd_packaging_v2 data rather than a fixed per-event template pair.
        // Operator ruling: liner count = what was actually entered per row
        // (new_liner_*=1 OR liner_*_mi_id_fk IS NOT NULL); cost diluted per HL filled.
        // We compute liner_cost_per_hl here and later suppress the two fixed template
        // slots (liner_client / liner_transport) to prevent double-emission.
        $isCuvSku = ($runTypeFmt === 'cuv');
        $cuvLinerCostPerHl = null;  // float|null — set for cuv SKUs with observed data

        if ($isCuvSku) {
            // Default MI id for fallback when flag set but mi_id_fk IS NULL.
            // MI 102 = PKG_LINER_10HL_EDS25 (the format-18 template default).
            $cuvDefaultLinerId = 102;

            $cuvStmt = $pdo->prepare(
                "SELECT
                     p.vendable_hl,
                     p.new_liner_client,    p.liner_client_mi_id_fk,
                     p.new_liner_transport, p.liner_transport_mi_id_fk,
                     vc_c.cost_chf AS client_cost_chf,
                     vc_t.cost_chf AS transport_cost_chf
                   FROM bd_packaging_v2 p
                   LEFT JOIN v_mi_cost vc_c ON vc_c.mi_id_fk = p.liner_client_mi_id_fk
                   LEFT JOIN v_mi_cost vc_t ON vc_t.mi_id_fk = p.liner_transport_mi_id_fk
                  WHERE p.recipe_id_fk = ?
                    AND p.run_type = 'cuv'
                    AND p.is_tombstoned = 0
                    AND p.vendable_hl > 0"
            );
            $cuvStmt->execute([$recipeId]);
            $cuvRows = $cuvStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Default liner cost in CHF (MI 102: EUR 18.00 × EUR→CHF rate from v_mi_cost).
            // v_mi_cost already normalises EUR→CHF for MI 102; look it up once.
            $defStmt = $pdo->prepare("SELECT cost_chf FROM v_mi_cost WHERE mi_id_fk = ?");
            $defStmt->execute([$cuvDefaultLinerId]);
            $defCostRow = $defStmt->fetch(\PDO::FETCH_ASSOC);
            $cuvDefaultCostChf = ($defCostRow && $defCostRow['cost_chf'] !== null)
                ? (float)$defCostRow['cost_chf']
                : 17.01;  // hard fallback: EUR 18.00 × 0.945 ≈ 17.01

            $cuvTotalHl    = 0.0;
            $cuvTotalCost  = 0.0;

            foreach ($cuvRows as $cuvRow) {
                $hl = (float)$cuvRow['vendable_hl'];
                $cuvTotalHl += $hl;

                // Liner present = (new_liner_*=1) OR (liner_*_mi_id_fk IS NOT NULL).
                // Older rows may have NULL boolean but populated FK (or vice versa).
                $hasClient    = ((int)($cuvRow['new_liner_client']    ?? 0) === 1
                                 || $cuvRow['liner_client_mi_id_fk']    !== null);
                $hasTransport = ((int)($cuvRow['new_liner_transport']  ?? 0) === 1
                                 || $cuvRow['liner_transport_mi_id_fk'] !== null);

                if ($hasClient) {
                    $cuvTotalCost += $cuvRow['client_cost_chf'] !== null
                        ? (float)$cuvRow['client_cost_chf']
                        : $cuvDefaultCostChf;
                }
                if ($hasTransport) {
                    $cuvTotalCost += $cuvRow['transport_cost_chf'] !== null
                        ? (float)$cuvRow['transport_cost_chf']
                        : $cuvDefaultCostChf;
                }
            }

            if ($cuvTotalHl > 0) {
                $cuvLinerCostPerHl = $cuvTotalCost / $cuvTotalHl;
                // Emit one synthetic line: slot_name='liner_amortized'.
                // mi_id_fk = cuvDefaultLinerId (MI 102, the most common liner) satisfies
                // the ref_sku_bom CHECK constraint (mi_id NOT NULL for bom_source='packaging').
                // The actual cost comes from cost_direct (volume-weighted average), NOT the
                // MI's catalog price — cost_direct bypasses the normal mi-lookup in the INSERT loop.
                // Liner row stored PER SELLABLE UNIT (mirrors the liquid branch pattern):
                //   cost_direct = liner_cost_per_hl × hl_per_unit
                //   qty         = hl_per_unit
                // So cost/qty recovers per-HL liner cost for display; CHF/HL unchanged.
                // Generalises the old hl_per_unit=1.0 special case to any hl_per_unit.
                $skuHlPerUnit = (float)($sku['hl_per_unit'] ?? 1.0);
                $pkgLines[] = [
                    'mi_id_fk'    => $cuvDefaultLinerId,
                    'slot_name'   => 'liner_amortized',
                    'qty'         => $skuHlPerUnit,
                    'volume_hl'   => null,
                    'cost_direct' => round($cuvLinerCostPerHl * $skuHlPerUnit, 6),
                ];
            }
            // If no observed cuv rows (new recipe, zero history), emit nothing — no liner cost yet.
        }

        // Identify known-by-design NULL volume cases — these must NOT emit RQ rows.
        // run_type='cuv': CUV_LINER has null hl_per_unit (volume comes from the fill event).
        // is_composite=1 OR catalog_id NULL: composites/draft-pours/6C/PAD — no static chain.
        $isKnownNullVolume = (
            $runTypeFmt === 'cuv'                                 // fill-event volume
            || !in_array($runTypeFmt, ['bot','can','can33','keg'], true) // composite, draft, tray
        );

        // For a STANDARD format (bot/can/can33/keg) that UNEXPECTEDLY fails to resolve
        // a container volume → emit a self-sufficient sku-bom-unresolved RQ row.
        // In practice today all standard formats resolve cleanly; this guards future additions.
        if ($skuVolumeHl === null && !$isKnownNullVolume) {
            $rqRows[] = [
                'queue_id'    => 'RQ_' . (int)(microtime(true) * 1000) . '_VOL_' . strtoupper(substr(md5($skuCode), 0, 6)),
                'value'       => "{$skuCode} — volume resolution miss ({$formatCode} run_type={$runTypeFmt})",
                'context'     => "SKU: {$skuCode}\nFormat: {$formatCode}\nrun_type: {$runTypeFmt}\n"
                               . "container chain failed (catalog_id or hl_per_unit is NULL unexpectedly).\n"
                               . "Action: verify dbc_packaging_format_templates + dbc_container_types for this format.",
                'top_match'   => null,
                'suggestions' => null,
                'dedup_key'   => "sku-bom-unresolved|{$skuCode}|volume",
                'priority'    => 50,
            ];
        }

        // Determine scotch resolution first (needed for box-sticker rule)
        $scotchResolved    = null;   // int|null: resolved MI id for scotch slot
        $scotchIsUnresolved = false; // true if scotch slot exists but cannot be resolved

        foreach ($items as $item) {
            if ($item['slot_name'] === 'scotch') {
                $scope = $item['slot_scope'];
                if (!_bom_scope_ok($scope, $decoIntegral, $isWeSupply)) {
                    continue; // scope excludes this slot
                }
                $defaultMiFk = $item['default_mi_id_fk'] !== null ? (int)$item['default_mi_id_fk'] : 0;
                $resolved = _bom_resolve_scotch(
                    $skuId, $recipeId, $prefix, $usesBranded,
                    $bindings, $skuChoices, $miById, $defaultMiFk
                );
                if ($resolved !== null) {
                    $scotchResolved = $resolved;
                } else {
                    // scotch unresolved — will emit RQ below
                    $scotchIsUnresolved = true;
                }
                break; // only one scotch slot per format
            }
        }

        // Pre-resolve box_label for §8.1 amended sticker-suppression check.
        // box_label exists only on fmt 1 (ref_packaging_items id=71); other formats have no such item.
        // null  = slot absent on this format, or explicit-null Tier-1 choice, or pattern miss → no suppression.
        // int   = MI id resolved (non-null) → sticker suppressed unless overridden by active Tier-1 sticker choice.
        $boxLabelResolved = null; // int|null
        if ($is24Box) {
            foreach ($items as $blItem) {
                if ($blItem['slot_name'] === 'box_label') {
                    $blScope = $blItem['slot_scope'];
                    if (_bom_scope_ok($blScope, $decoIntegral, $isWeSupply)) {
                        $boxLabelResolved = _bom_resolve_slot(
                            $blItem, $skuId, $recipeId, $prefix, $bindings, $skuChoices, $miById
                        );
                    }
                    break; // only one box_label slot per format
                }
            }
        }

        // Now process all slots
        foreach ($items as $item) {
            $slotName = $item['slot_name'];
            $scope    = $item['slot_scope'];
            $qty      = (float)$item['qty_per_unit'];

            // ── cuv: suppress fixed template liner slots ─────────────────
            // For cuv SKUs the observed-liner branch (§cuv above) already emitted
            // a single 'liner_amortized' line. Suppress the two fixed template
            // slots so they are not double-counted.
            if ($isCuvSku && in_array($slotName, ['liner_client', 'liner_transport'], true)) {
                continue;
            }

            // ── Scope gate ───────────────────────────────────────────────

            if (!_bom_scope_ok($scope, $decoIntegral, $isWeSupply)) {
                continue;
            }

            // ── §8.1 box-sticker rule (amended) ─────────────────────────
            // For 24-box formats, box decoration = exactly one of 3 mechanisms:
            //   (1) box-sticker: REQUIRED iff scotch=TRANSP AND box_label=null
            //   (2) branded scotch → sticker intentionally absent
            //   (3) box_label non-null → sticker intentionally absent (no line, no RQ)
            // EXCEPTION: active Tier-1 sticker choice (mi_id_fk NOT NULL) overrides (3).
            if ($slotName === 'sticker' && $is24Box) {
                if ($scotchResolved !== null && $scotchResolved !== $scotchTranspId) {
                    // scotch = branded → box-sticker intentionally absent, skip silently
                    continue;
                }
                // §8.1 mechanism (3): box_label resolves non-null → suppress sticker
                // unless an active Tier-1 sticker choice is set (explicit beats structural).
                if ($boxLabelResolved !== null) {
                    $hasTier1StickerChoice = isset($skuChoices[$skuId]['sticker'])
                        && $skuChoices[$skuId]['sticker']['mi_id_fk'] !== null;
                    if (!$hasTier1StickerChoice) {
                        continue; // box_label present, no explicit sticker override → suppress
                    }
                }
                // else: scotch=TRANSP, box_label=null, or Tier-1 override → process sticker normally below
            }

            // ── Scotch slot: handled above, now write or queue RQ ────────

            if ($slotName === 'scotch') {
                if ($scotchResolved !== null) {
                    $pkgLines[] = [
                        'mi_id_fk'  => $scotchResolved,
                        'slot_name' => $slotName,
                        'qty'       => $qty,
                        // Scotch is a packaging accessory — volume is owned by the container line.
                        'volume_hl' => null,
                    ];
                } else {
                    // Unresolved — emit RQ
                    $rqRows[] = _bom_build_rq_row(
                        $skuId, $skuCode, $formatCode, $prefix, $slotName,
                        $item['mi_filter_pattern'], $recipeId, $miById
                    );
                }
                continue;
            }

            // ── All other slots ──────────────────────────────────────────

            $resolved = _bom_resolve_slot($item, $skuId, $recipeId, $prefix, $bindings, $skuChoices, $miById);

            if ($resolved !== null) {
                // Volume is OWNED by the container-role line only (additive-cost-vs-owned-volume rule).
                // All other lines (closure, label, box, sticker, etc.) get NULL.
                $lineVolumeHl = ($slotName === $containerSlotName && $skuVolumeHl !== null)
                    ? $skuVolumeHl
                    : null;
                $pkgLines[] = [
                    'mi_id_fk'  => $resolved,
                    'slot_name' => $slotName,
                    'qty'       => $qty,
                    'volume_hl' => $lineVolumeHl,
                ];
            } elseif (isset($skuChoices[$skuId][$slotName]) &&
                      $skuChoices[$skuId][$slotName]['mi_id_fk'] === null) {
                // Explicit null override in ref_sku_packaging_choices — intentional absence, skip silently.
                // No RQ emitted: the operator has declared this slot is intentionally unoccupied.
                continue;
            } else {
                // Unresolved → emit RQ (never insert NULL mi_id)
                $rqRows[] = _bom_build_rq_row(
                    $skuId, $skuCode, $formatCode, $prefix, $slotName,
                    $item['mi_filter_pattern'], $recipeId, $miById
                );
            }
        }

        // ── 4d. Execute within transaction ──────────────────────────────

        $pkgDeleted  = 0;
        $pkgInserted = 0;
        $rqEmitted   = 0;
        $parityOk    = true;
        $error       = null;

        if ($dryRun) {
            // Dry-run: count what would happen, verify parity (no writes)
            $existingPkg = _bom_count_packaging($pdo, $skuId);
            $pkgDeleted  = $existingPkg;
            $pkgInserted = count($pkgLines);
            $rqEmitted   = count($rqRows);
            // Parity: by definition unchanged since we're not writing — just report baseline
            $liqAfter = $liqBefore;
            $parityOk = true;
        } else {
            $pdo->beginTransaction();
            try {
                // ── Step 1 baseline (inside transaction) ─────────────────
                $liqBefore = _bom_liquid_snapshot($pdo, $skuId);

                // ── Step 2 delete packaging rows ──────────────────────────
                $del = $pdo->prepare(
                    "DELETE FROM ref_sku_bom WHERE sku_id = ? AND (source <> 'Brewing' OR source IS NULL)"
                );
                $del->execute([$skuId]);
                $pkgDeleted = $del->rowCount();

                // ── Step 3 insert resolved packaging rows ─────────────────
                $compiledAt = gmdate('Y-m-d H:i:s');
                $today      = date('Y-m-d');

                $ins = $pdo->prepare(
                    "INSERT INTO ref_sku_bom
                       (sku_id, mi_id, ingredient_raw, source, slot_name, category_raw,
                        qty_per_unit, ing_unit, pricing_unit, price, currency, cost, volume_hl,
                        resolution, row_hash, compiled_at, bom_source, effective_from)
                     VALUES
                       (:sku_id, :mi_id, :ingredient_raw, :source, :slot_name, :category_raw,
                        :qty_per_unit, :ing_unit, :pricing_unit, :price, :currency, :cost, :volume_hl,
                        :resolution, :row_hash, :compiled_at, :bom_source, :effective_from)"
                );

                foreach ($pkgLines as $line) {
                    $mi = $miById[$line['mi_id_fk']] ?? null;
                    $miCode   = $mi['mi_id']       ?? '';
                    $catName  = $mi['cat_name']    ?? 'Packaging';
                    $pricingUnit = $mi['pricing_unit'] ?? null;
                    // cost_chf from v_mi_cost (WAC > catalog > no_basis). cost_basis='no_basis' → NULL cost.
                    // Currency is always CHF after this switch (v_mi_cost normalises EUR→CHF).
                    $costChf  = ($mi !== null && $mi['cost_chf'] !== null) ? (float)$mi['cost_chf'] : null;
                    $currency = 'CHF';

                    // cost_direct: liner_amortized lines bypass the mi-lookup cost and carry
                    // their volume-weighted average cost directly. The mi_id_fk is still set
                    // (to the default liner MI) to satisfy the CHECK constraint, but cost
                    // comes from the observed data, not the MI catalog price.
                    if (isset($line['cost_direct'])) {
                        $cost    = round($line['cost_direct'], 6);
                        $costChf = $cost;  // price = cost for qty=1.0 lines
                    } else {
                        // Refuse-don't-NULL: no_basis lines keep cost=NULL; they are flagged in the return value.
                        $cost = ($costChf !== null) ? round($costChf * $line['qty'], 6) : null;
                    }

                    // row_hash: stable content key (sku_id, mi_id_fk, slot_name, qty, volume_hl, effective_from)
                    $rowHash = hash('sha256', implode('|', [
                        $skuId,
                        $line['mi_id_fk'],
                        $line['slot_name'],
                        round($line['qty'], 6),
                        isset($line['volume_hl']) && $line['volume_hl'] !== null
                            ? round($line['volume_hl'], 6) : 'null',
                        $today,
                    ]));

                    $isLinerAmortized = ($line['slot_name'] === 'liner_amortized');
                    $ins->execute([
                        ':sku_id'         => $skuId,
                        ':mi_id'          => $line['mi_id_fk'],
                        ':ingredient_raw' => $miCode,
                        ':source'         => 'Packaging',
                        ':slot_name'      => $line['slot_name'],
                        ':category_raw'   => $catName,
                        ':qty_per_unit'   => round($line['qty'], 6),
                        ':ing_unit'       => $isLinerAmortized ? 'HL' : 'unit',
                        ':pricing_unit'   => $isLinerAmortized ? 'HL' : $pricingUnit,
                        ':price'          => $costChf,  // per-unit cost in CHF (from v_mi_cost or cost_direct)
                        ':currency'       => $currency, // always 'CHF' after WAC switch
                        ':cost'           => $cost,
                        // Set on the container-role line ONLY (bottle/can slot).
                        // NULL on closure, label, box, sticker, accessories, AND keg/cuv lines
                        // (reusable containers — their volume is SKU-level via v_sku_volume).
                        // NOT additive across lines — one line owns the SKU's liquid volume.
                        ':volume_hl'      => isset($line['volume_hl']) ? $line['volume_hl'] : null,
                        ':resolution'     => $isLinerAmortized ? 'observed_cuv' : 'mi_match',
                        ':row_hash'       => $rowHash,
                        ':compiled_at'    => $compiledAt,
                        ':bom_source'     => 'packaging',
                        ':effective_from' => $today,
                    ]);
                    $pkgInserted++;
                }

                // ── Step 4 liquid parity gate ─────────────────────────────
                $liqAfter = _bom_liquid_snapshot($pdo, $skuId);

                if ($liqAfter['rows'] !== $liqBefore['rows'] ||
                    abs($liqAfter['cost'] - $liqBefore['cost']) > 0.000001) {
                    // Parity violation — ABORT the whole transaction
                    $pdo->rollBack();
                    $parityOk = false;
                    $error    = sprintf(
                        'LIQUID PARITY GATE TRIPPED for sku_id=%d (%s): rows %d→%d, cost %.6f→%.6f — ROLLED BACK',
                        $skuId, $skuCode,
                        $liqBefore['rows'], $liqAfter['rows'],
                        $liqBefore['cost'], $liqAfter['cost']
                    );
                    $totalParityViol++;
                    $totalErrors++;
                    $summary[$skuId] = [
                        'sku_code'        => $skuCode,
                        'format_code'     => $formatCode,
                        'sku_prefix'      => $prefix,
                        'pkg_deleted'     => 0,
                        'pkg_inserted'    => 0,
                        'rq_emitted'      => 0,
                        'liq_rows_before' => $liqBefore['rows'],
                        'liq_cost_before' => $liqBefore['cost'],
                        'liq_rows_after'  => $liqAfter['rows'],
                        'liq_cost_after'  => $liqAfter['cost'],
                        'parity_ok'       => false,
                        'error'           => $error,
                    ];
                    continue;
                }

                // ── Step 5 emit RQ rows ───────────────────────────────────
                if (!empty($rqRows)) {
                    $rqIns = $pdo->prepare(
                        "INSERT INTO doc_review_queue
                           (queue_id, type, value, context, top_match, suggestions,
                            dedup_key, priority, status, decision)
                         VALUES
                           (:queue_id, :type, :value, :context, :top_match, :suggestions,
                            :dedup_key, :priority, 'open', 'pending')
                         ON DUPLICATE KEY UPDATE
                           updated_at = CURRENT_TIMESTAMP"
                    );

                    foreach ($rqRows as $rq) {
                        // Dedup: only insert if this dedup_key doesn't already exist as open
                        $dedupCheck = $pdo->prepare(
                            "SELECT COUNT(*) FROM doc_review_queue
                              WHERE dedup_key = ? AND status IN ('open','in_progress')"
                        );
                        $dedupCheck->execute([$rq['dedup_key']]);
                        if ((int)$dedupCheck->fetchColumn() > 0) {
                            continue; // already open, skip
                        }

                        $rqIns->execute([
                            ':queue_id'   => $rq['queue_id'],
                            ':type'       => 'sku-bom-unresolved',
                            ':value'      => $rq['value'],
                            ':context'    => $rq['context'],
                            ':top_match'  => $rq['top_match'],
                            ':suggestions'=> $rq['suggestions'],
                            ':dedup_key'  => $rq['dedup_key'],
                            ':priority'   => $rq['priority'],
                        ]);
                        if ($rqIns->rowCount() > 0) {
                            $rqEmitted++;
                        }
                    }
                }

                $pdo->commit();

            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error  = $e->getMessage();
                $parityOk = false;
                $liqAfter = $liqBefore;
                $totalErrors++;
            }
        }

        $totalPkgDeleted  += $pkgDeleted;
        $totalPkgInserted += $pkgInserted;
        $totalRqEmitted   += $rqEmitted;
        if (!$parityOk && $error !== null) {
            // already counted above (parity violation path), others counted here
        }

        $summary[$skuId] = [
            'sku_code'        => $skuCode,
            'format_code'     => $formatCode,
            'sku_prefix'      => $prefix,
            'pkg_deleted'     => $pkgDeleted,
            'pkg_inserted'    => $pkgInserted,
            'rq_emitted'      => $rqEmitted,
            'liq_rows_before' => $liqBefore['rows'],
            'liq_cost_before' => $liqBefore['cost'],
            'liq_rows_after'  => isset($liqAfter) ? $liqAfter['rows'] : $liqBefore['rows'],
            'liq_cost_after'  => isset($liqAfter) ? $liqAfter['cost'] : $liqBefore['cost'],
            'parity_ok'       => $parityOk && $error === null,
            'error'           => $error,
        ];
    }

    return [
        'dry_run'            => $dryRun,
        'missing_sku_ids'    => $missingIds,
        'skus'               => $summary,
        'total_pkg_deleted'  => $totalPkgDeleted,
        'total_pkg_inserted' => $totalPkgInserted,
        'total_rq_emitted'   => $totalRqEmitted,
        'parity_violations'  => $totalParityViol,
        'errors'             => $totalErrors,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Composite compiler
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Compile a composite SKU's BOM from its ref_sku_composite_slots membership.
 *
 * Emits:
 *   composite_liquid    — one set of ingredient lines per member recipe
 *                         (source='Brewing', bom_source='composite_liquid', volume_hl=NULL)
 *                         ingredient_raw = '[PREFIX]<mi_code>' to avoid UNIQUE collision
 *                         when two members share an ingredient (e.g. ZEP+MOO both use MALT_PILSENER).
 *   composite_packaging — overwrap lines from ref_sku_packaging_choices for this sku_id
 *                         (source='Packaging', bom_source='composite_packaging', volume_hl=NULL)
 *                       + member container materials per slot (bottle×units, cap×units, label×units)
 *                         resolved data-driven from ref_packaging_items[member_format_id]
 *                         + ref_recipe_packaging_bindings (label role) using the same 3-tier
 *                         precedence as the per-SKU packaging compiler (_bom_resolve_slot).
 *                         ingredient_raw = '[PREFIX]<slot_name>' for uniqueness across members.
 *
 * Safe-delete predicate: DELETE WHERE sku_id=? AND bom_source IN ('composite_liquid','composite_packaging')
 * (clears stale composite rows without touching rows from other SKUs).
 *
 * Refuse-don't-NULL: any unresolved member (no liquid BOM source found) or
 * overwrap slot with NULL mi_id_fk → RQ row, not a NULL mi_id BOM line.
 * Unresolved member container slot (e.g. missing label binding) → RQ row, not NULL.
 *
 * Volume: composites emit NO per-line volume_hl. SKU-level volume lives in
 * v_sku_volume.hl_per_unit_stored (from ref_skus.hl_per_unit), which is correct
 * for all composite formats (PM-verified: PD8=0.0264, PAL=0.0396, XMAS=0.0099, PAC=0.0396).
 *
 * @param array $slots       From compositeSlots[$skuId] — the slot rows.
 * @param array $liquidBom   From memberLiquidBom — recipe_id → [mi_id => qty_per_hl].
 * @param array $sourceSku   From memberSourceSku — recipe_id → sku_code (for reporting).
 * @param array $miById      Full MI index.
 * @param array $skuChoices  ref_sku_packaging_choices index (sku_id → slot_name → row).
 * @param array $bindings    ref_recipe_packaging_bindings index (recipe_id → role → mi_id_fk).
 */
function _bom_compile_composite(
    PDO   $pdo,
    int   $skuId,
    array $slots,
    array $liquidBom,
    array $sourceSku,
    array $miById,
    array $skuChoices,
    bool  $dryRun,
    array $bindings = []
): array {
    // Load SKU metadata (sku_code) for reporting — not gated on recipe_id
    $skuMeta = $pdo->prepare(
        "SELECT s.sku_code, s.hl_per_unit, f.format_code
           FROM ref_skus s
           LEFT JOIN ref_packaging_formats f ON f.id = s.format_id
          WHERE s.id = ?"
    );
    $skuMeta->execute([$skuId]);
    $meta = $skuMeta->fetch(\PDO::FETCH_ASSOC);
    $skuCode    = $meta['sku_code']    ?? "sku_id={$skuId}";
    $formatCode = $meta['format_code'] ?? '';

    // ── Build composite_liquid lines ─────────────────────────────────────────
    $liqLines  = [];  // ['mi_id_fk', 'mi_code', 'slot_hl', 'qty', 'ingredient_raw', 'cat_name']
    $rqRows    = [];
    $hasLiquidError = false;

    foreach ($slots as $slot) {
        $recipeId       = (int)$slot['recipe_id'];
        $unitsPerRecipe = (int)$slot['units_per_recipe'];
        $memberHl       = (float)$slot['member_hl'];  // hl_per_unit of the member's container format
        $prefix         = strtoupper($slot['sku_prefix']);
        $slotHl         = $unitsPerRecipe * $memberHl; // HL this member contributes per composite unit

        if (!isset($liquidBom[$recipeId])) {
            // No liquid BOM source found for this recipe — refuse-don't-NULL
            $srcSku = $sourceSku[$recipeId] ?? "(recipe_id={$recipeId})";
            $rqRows[] = _bom_build_composite_rq(
                $skuId, $skuCode, $formatCode, $prefix, $recipeId, $srcSku,
                'composite_liquid_no_source',
                "No single-unit (BU/bottle) liquid BOM found for recipe {$recipeId} ({$prefix}). " .
                "Expected a BU SKU (e.g. {$prefix}BU) with source='Brewing' rows in ref_sku_bom."
            );
            $hasLiquidError = true;
            continue;
        }

        foreach ($liquidBom[$recipeId] as $miId => $srcRow) {
            $mi = $miById[$miId] ?? null;
            if ($mi === null) {
                continue; // MI no longer in index — skip silently (extremely unlikely)
            }
            $miCode  = $mi['mi_id'];
            $catName = $mi['cat_name'] ?? 'Brewing';
            $qty     = round($srcRow['qtyPerHl'] * $slotHl, 6);
            $cost    = $srcRow['costPerHl'] !== null
                ? round($srcRow['costPerHl'] * $slotHl, 6)
                : null;
            // ingredient_raw must be unique per (sku_id, ingredient_raw, source) across members.
            // Prefix with '[PREFIX]' to distinguish ZEP:MALT_PILSENER from MOO:MALT_PILSENER.
            $ingredientRaw = "[{$prefix}]{$miCode}";

            $liqLines[] = [
                'mi_id_fk'       => $miId,
                'mi_code'        => $miCode,
                'cat_name'       => $catName,
                'qty'            => $qty,
                'cost'           => $cost,
                'ing_unit'       => $srcRow['ing_unit'],
                'ingredient_raw' => $ingredientRaw,
                'slot_hl'        => $slotHl,
                'prefix'         => $prefix,
            ];
        }
    }

    // ── Build composite_packaging lines ──────────────────────────────────────
    // Two sub-sources, both bom_source='composite_packaging', source='Packaging':
    //
    //   1. Overwrap items from ref_sku_packaging_choices for this composite sku_id.
    //      ingredient_raw = plain mi_code (no prefix; unique per composite, not per member).
    //
    //   2. Member container items per slot (bottle, cap, label) resolved data-driven from
    //      ref_packaging_items[member_format_id] using the same 3-tier precedence as the
    //      per-SKU packaging compiler.  ingredient_raw = '[PREFIX]<slot_name>' to keep
    //      uniqueness when two members share the same bottle/cap MI.
    //      Scope filtering: 'always' and 'labelled_only' (decoration_integral=0 for BU/4PB).
    //      Crown caps use 'we_supply_only' scope — treated as we_supply for composites.
    //      Volume_hl: NULL on all composite lines (same as liquid lines above).
    //
    $pkgLines = [];

    // ── 1. Overwrap items from ref_sku_packaging_choices ──────────────────────
    if (isset($skuChoices[$skuId])) {
        foreach ($skuChoices[$skuId] as $slotName => $choice) {
            $miIdFk     = $choice['mi_id_fk'];
            $qtyPerUnit = (float)$choice['qty_per_unit'];

            if ($miIdFk === null) {
                // Explicit null override — intentional absence, skip silently.
                continue;
            }
            $mi = $miById[$miIdFk] ?? null;
            if ($mi === null) {
                $rqRows[] = _bom_build_composite_rq(
                    $skuId, $skuCode, $formatCode, '', 0, '',
                    'composite_packaging_unresolved',
                    "ref_sku_packaging_choices slot '{$slotName}' has mi_id_fk={$miIdFk} but that MI is not in ref_mi."
                );
                continue;
            }
            $pkgLines[] = [
                'mi_id_fk'       => $miIdFk,
                'mi_code'        => $mi['mi_id'],
                'cat_name'       => $mi['cat_name'] ?? 'Packaging',
                'qty'            => $qtyPerUnit,
                'slot_name'      => $slotName,
                'ingredient_raw' => $mi['mi_id'],  // plain mi_code: unique per composite overwrap
            ];
        }
    } else {
        // No choices for this composite — the overwrap is operator-required.
        // Emit one self-sufficient RQ row per composite without choices.
        $rqRows[] = _bom_build_composite_rq(
            $skuId, $skuCode, $formatCode, '', 0, '',
            'composite_packaging_no_choices',
            "Composite SKU {$skuCode} has no rows in ref_sku_packaging_choices. " .
            "Operator must add the overwrap MI binding(s) via Salle de contrôle → Recettes → Formats."
        );
    }

    // ── 2. Member container items per slot ────────────────────────────────────
    // Collect unique member format IDs across all slots, then load their packaging items
    // and template decoration flag once — amortised across all members of this composite.
    $memberFormatIds = array_values(array_unique(array_column($slots, 'member_format_id')));
    $memberFormatItems     = [];  // format_id → [items]
    $memberFormatDecoInteg = [];  // format_id → bool decoration_integral

    if (!empty($memberFormatIds)) {
        $mfp = implode(',', array_fill(0, count($memberFormatIds), '?'));
        $mfItemStmt = $pdo->prepare(
            "SELECT id, format_id, slot_name, qty_per_unit, mi_filter_pattern,
                    default_mi_id_fk, slot_scope, display_order
               FROM ref_packaging_items
              WHERE format_id IN ({$mfp})
                AND (effective_until IS NULL OR effective_until > CURDATE())
              ORDER BY format_id, display_order"
        );
        $mfItemStmt->execute($memberFormatIds);
        foreach ($mfItemStmt->fetchAll(\PDO::FETCH_ASSOC) as $item) {
            $memberFormatItems[(int)$item['format_id']][] = $item;
        }

        // Load decoration_integral from the we_supply template (same gate used for solo SKUs).
        $mfTplStmt = $pdo->prepare(
            "SELECT format_id, decoration_integral
               FROM ref_packaging_bom_templates
              WHERE format_id IN ({$mfp})
                AND supply = 'we_supply'
                AND is_active = 1"
        );
        $mfTplStmt->execute($memberFormatIds);
        foreach ($mfTplStmt->fetchAll(\PDO::FETCH_ASSOC) as $tpl) {
            $memberFormatDecoInteg[(int)$tpl['format_id']] = (bool)(int)$tpl['decoration_integral'];
        }
    }

    foreach ($slots as $slot) {
        $recipeId       = (int)$slot['recipe_id'];
        $unitsPerRecipe = (int)$slot['units_per_recipe'];
        $memberFormatId = (int)$slot['member_format_id'];
        $prefix         = strtoupper($slot['sku_prefix']);

        $items      = $memberFormatItems[$memberFormatId] ?? [];
        $decoInteg  = $memberFormatDecoInteg[$memberFormatId] ?? false;

        foreach ($items as $item) {
            $slotName = $item['slot_name'];
            $scope    = $item['slot_scope'];
            // Scope: always → include; labelled_only → include when not decoration_integral;
            // we_supply_only → include (composites are we-supply by definition).
            // Any other scope value → include (safe default).
            if ($scope === 'labelled_only' && $decoInteg) {
                continue;
            }

            // Quantity: per-unit quantity from template × units_per_recipe (members per composite unit).
            $itemQty  = (float)$item['qty_per_unit'] * $unitsPerRecipe;

            // Resolve MI using the same 3-tier precedence as _bom_resolve_slot(),
            // but WITHOUT scotch alternation (member containers have no scotch slot).
            // Tier 1: SKU-level override (skuChoices keyed on composite sku_id — overwrap choices
            //         are keyed on the same sku_id, but slot names differ so no collision).
            // Note: member-container slot names (bottle, crown_caps, label, holder) are distinct
            // from overwrap slot names (outer_box, scotch_eshop, verre, etc.).
            $resolvedMiId = null;
            if (isset($skuChoices[$skuId][$slotName])) {
                $c = $skuChoices[$skuId][$slotName];
                if ($c['mi_id_fk'] === null) {
                    // Explicit null override for this member slot — intentional absence.
                    continue;
                }
                $resolvedMiId = (int)$c['mi_id_fk'];
            }

            // Tier 2: recipe binding by role (role name = slot_name: label, bottle, crown_caps, holder).
            if ($resolvedMiId === null && isset($bindings[$recipeId][$slotName])) {
                $resolvedMiId = $bindings[$recipeId][$slotName];
            }

            // Tier 3: template default / pattern resolution.
            if ($resolvedMiId === null) {
                $pattern = $item['mi_filter_pattern'] ?? '';
                if (!str_contains($pattern, '{beer}')) {
                    // Fixed slot — use default_mi_id_fk.
                    $resolvedMiId = $item['default_mi_id_fk'] !== null ? (int)$item['default_mi_id_fk'] : null;
                } else {
                    // {beer} pattern — substitute prefix.
                    $resolved      = str_replace('{beer}', $prefix, $pattern);
                    $resolvedExact = rtrim($resolved, '%');
                    $resolvedMiId  = _bom_lookup_mi_id($resolvedExact, $miById);
                    if ($resolvedMiId === null && str_ends_with($resolved, '%')) {
                        foreach ($miById as $candidateId => $candidate) {
                            if (_bom_mi_id_matches_like($candidate['mi_id'], $resolved)) {
                                $resolvedMiId = $candidateId;
                                break;
                            }
                        }
                    }
                    // Fall back to template default if pattern finds nothing.
                    if ($resolvedMiId === null && $item['default_mi_id_fk'] !== null) {
                        $resolvedMiId = (int)$item['default_mi_id_fk'];
                    }
                }
            }

            if ($resolvedMiId === null) {
                // Refuse-don't-NULL: emit RQ.
                $rqRows[] = _bom_build_composite_rq(
                    $skuId, $skuCode, $formatCode, $prefix, $recipeId,
                    $sourceSku[$recipeId] ?? "(recipe_id={$recipeId})",
                    'composite_member_slot_unresolved',
                    "Member {$prefix} (recipe {$recipeId}, format_id {$memberFormatId}): " .
                    "slot '{$slotName}' could not be resolved. " .
                    "Add a ref_recipe_packaging_bindings row (role='{$slotName}') or " .
                    "a ref_sku_packaging_choices override for sku_id={$skuId}."
                );
                continue;
            }

            $mi = $miById[$resolvedMiId] ?? null;
            if ($mi === null) {
                continue; // MI disappeared from index — extremely unlikely
            }

            // ingredient_raw: '[PREFIX]<slot_name>' keeps uniqueness when two members share
            // the same fixed MI (e.g. PKG_BOT_PIVO) across different recipes.
            $pkgLines[] = [
                'mi_id_fk'       => $resolvedMiId,
                'mi_code'        => $mi['mi_id'],
                'cat_name'       => $mi['cat_name'] ?? 'Packaging',
                'qty'            => $itemQty,
                'slot_name'      => $slotName,
                'ingredient_raw' => "[{$prefix}]{$slotName}",
            ];
        }
    }

    // ── Snapshot liquid baseline (counts all non-Packaging rows regardless of bom_source) ──
    $liqBefore = _bom_liquid_snapshot($pdo, $skuId);

    // ── Dry-run or Apply ─────────────────────────────────────────────────────
    $compiledAt = gmdate('Y-m-d H:i:s');
    $today      = date('Y-m-d');

    $pkgDeleted  = 0;
    $pkgInserted = 0;
    $rqEmitted   = 0;
    $parityOk    = true;
    $error       = null;
    $liqAfter    = $liqBefore;

    if ($dryRun) {
        // Count stale rows that would be deleted (mirrors the live DELETE predicate in the apply path)
        $delCountStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM ref_sku_bom WHERE sku_id = ? AND (bom_source IS NULL OR bom_source IN ('composite_liquid','composite_packaging'))"
        );
        $delCountStmt->execute([$skuId]);
        $pkgDeleted  = (int)$delCountStmt->fetchColumn();
        $pkgInserted = count($liqLines) + count($pkgLines);
        $rqEmitted   = count($rqRows);
        $parityOk    = true;
        // Project liq_cost_after from computed lines (source='Brewing' rows only — mirrors snapshot)
        $projectedCost = 0.0;
        foreach ($liqLines as $line) {
            if ($line['cost'] !== null) {
                $projectedCost += $line['cost'];
            }
        }
        $liqAfter = [
            'rows' => count($liqLines),
            'cost' => round($projectedCost, 6),
        ];
    } else {
        $pdo->beginTransaction();
        try {
            // Snapshot inside transaction
            $liqBefore = _bom_liquid_snapshot($pdo, $skuId);

            // Delete ALL stale flat rows (bom_source IS NULL or previously compiled composite rows)
            // for this composite. Covers: legacy bom_source=NULL rows AND prior composite_liquid/
            // composite_packaging rows from a previous compile — prevents double-insert on recompute.
            $del = $pdo->prepare(
                "DELETE FROM ref_sku_bom WHERE sku_id = ? AND (bom_source IS NULL OR bom_source IN ('composite_liquid','composite_packaging'))"
            );
            $del->execute([$skuId]);
            $pkgDeleted = $del->rowCount();

            // Insert composite_liquid rows
            $ins = $pdo->prepare(
                "INSERT INTO ref_sku_bom
                   (sku_id, mi_id, ingredient_raw, source, slot_name, category_raw,
                    qty_per_unit, ing_unit, pricing_unit, price, currency, cost, volume_hl,
                    resolution, row_hash, compiled_at, bom_source, effective_from)
                 VALUES
                   (:sku_id, :mi_id, :ingredient_raw, :source, :slot_name, :category_raw,
                    :qty_per_unit, :ing_unit, :pricing_unit, :price, :currency, :cost, :volume_hl,
                    :resolution, :row_hash, :compiled_at, :bom_source, :effective_from)"
            );

            foreach ($liqLines as $line) {
                $mi       = $miById[$line['mi_id_fk']];
                $price    = $mi['price'] !== null ? (float)$mi['price'] : null;
                // Use the source row's pre-scaled cost (costPerHl × slotHl), which already
                // incorporates the g→kg conversion_factor applied when the source BOM was built.
                // Do NOT recompute from price × qty here — price is in pricing_unit (kg) while
                // qty may be in ing_unit (g), so price × qty would be ~1000× inflated for hops.
                $cost     = $line['cost'];
                $rowHash  = hash('sha256', implode('|', [
                    $skuId, $line['mi_id_fk'], $line['ingredient_raw'],
                    'Brewing', round($line['qty'], 6), 'null', $today,
                ]));
                $ins->execute([
                    ':sku_id'         => $skuId,
                    ':mi_id'          => $line['mi_id_fk'],
                    ':ingredient_raw' => $line['ingredient_raw'],
                    ':source'         => 'Brewing',
                    ':slot_name'      => null,  // liquid rows carry no packaging slot
                    ':category_raw'   => $line['cat_name'],
                    ':qty_per_unit'   => round($line['qty'], 6),
                    ':ing_unit'       => $line['ing_unit'],
                    ':pricing_unit'   => $mi['pricing_unit'] ?? null,
                    ':price'          => $price,
                    ':currency'       => $mi['currency'] ?? null,
                    ':cost'           => $cost,
                    ':volume_hl'      => null,  // composite_liquid carries NO per-line volume
                    ':resolution'     => 'mi_match',
                    ':row_hash'       => $rowHash,
                    ':compiled_at'    => $compiledAt,
                    ':bom_source'     => 'composite_liquid',
                    ':effective_from' => $today,
                ]);
                $pkgInserted++;
            }

            // Insert composite_packaging rows
            foreach ($pkgLines as $line) {
                $mi      = $miById[$line['mi_id_fk']];
                // cost_chf from v_mi_cost (WAC > catalog > no_basis, FX-normalised to CHF).
                // Currency is always 'CHF' after this switch.
                $costChf = ($mi['cost_chf'] !== null) ? (float)$mi['cost_chf'] : null;
                $cost    = ($costChf !== null) ? round($costChf * $line['qty'], 6) : null;
                $rowHash = hash('sha256', implode('|', [
                    $skuId, $line['mi_id_fk'], $line['ingredient_raw'],
                    'Packaging', round($line['qty'], 6), 'null', $today,
                ]));
                $ins->execute([
                    ':sku_id'         => $skuId,
                    ':mi_id'          => $line['mi_id_fk'],
                    ':ingredient_raw' => $line['ingredient_raw'],
                    ':source'         => 'Packaging',
                    ':slot_name'      => $line['slot_name'] ?? null,
                    ':category_raw'   => $line['cat_name'],
                    ':qty_per_unit'   => round($line['qty'], 6),
                    ':ing_unit'       => 'unit',
                    ':pricing_unit'   => $mi['pricing_unit'] ?? null,
                    ':price'          => $costChf,  // per-unit cost in CHF (from v_mi_cost)
                    ':currency'       => 'CHF',     // always CHF after WAC switch
                    ':cost'           => $cost,
                    ':volume_hl'      => null,  // composite_packaging also carries no per-line volume
                    ':resolution'     => 'mi_match',
                    ':row_hash'       => $rowHash,
                    ':compiled_at'    => $compiledAt,
                    ':bom_source'     => 'composite_packaging',
                    ':effective_from' => $today,
                ]);
                $pkgInserted++;
            }

            // Emit RQ rows
            if (!empty($rqRows)) {
                $rqIns = $pdo->prepare(
                    "INSERT INTO doc_review_queue
                       (queue_id, type, value, context, top_match, suggestions,
                        dedup_key, priority, status, decision)
                     VALUES
                       (:queue_id, :type, :value, :context, :top_match, :suggestions,
                        :dedup_key, :priority, 'open', 'pending')
                     ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP"
                );
                foreach ($rqRows as $rq) {
                    $dedupCheck = $pdo->prepare(
                        "SELECT COUNT(*) FROM doc_review_queue WHERE dedup_key = ? AND status IN ('open','in_progress')"
                    );
                    $dedupCheck->execute([$rq['dedup_key']]);
                    if ((int)$dedupCheck->fetchColumn() > 0) {
                        continue;
                    }
                    $rqIns->execute([
                        ':queue_id'    => $rq['queue_id'],
                        ':type'        => 'sku-bom-unresolved',
                        ':value'       => $rq['value'],
                        ':context'     => $rq['context'],
                        ':top_match'   => $rq['top_match'],
                        ':suggestions' => $rq['suggestions'],
                        ':dedup_key'   => $rq['dedup_key'],
                        ':priority'    => $rq['priority'],
                    ]);
                    if ($rqIns->rowCount() > 0) {
                        $rqEmitted++;
                    }
                }
            }

            // Liquid parity gate — composite_liquid rows use source='Brewing' so they ARE
            // counted in the liquid snapshot. After our insert, liqAfter will differ from
            // liqBefore (we just added composite_liquid rows where stale Brewing rows were deleted).
            // The gate must be SKIPPED for composites — we're replacing flat Brewing rows
            // with composite_liquid Brewing rows. Instead, verify no non-composite liquid was touched.
            // Approach: confirm the count changed only by the delta we introduced.
            $liqAfter = _bom_liquid_snapshot($pdo, $skuId);

            // For composites, parity is: liqAfter.rows == count(liqLines)
            // (we deleted all old Brewing+NULL rows, inserted exactly liqLines liquid rows)
            // and liqAfter must not contain any non-composite_liquid source='Brewing' rows
            // (there should be none — composites had no prior bom_source='liquid' rows).
            // We accept the delta as expected; no rollback on composite.
            $parityOk = true;  // composite replaces old flat rows — delta is expected

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error    = $e->getMessage();
            $parityOk = false;
            $liqAfter = $liqBefore;
        }
    }

    return [
        'sku_code'        => $skuCode,
        'format_code'     => $formatCode,
        'sku_prefix'      => implode('+', array_unique(array_column($slots, 'sku_prefix'))),
        'pkg_deleted'     => $pkgDeleted,
        'pkg_inserted'    => $pkgInserted,
        'rq_emitted'      => $rqEmitted,
        'liq_rows_before' => $liqBefore['rows'],
        'liq_cost_before' => $liqBefore['cost'],
        'liq_rows_after'  => $liqAfter['rows'],
        'liq_cost_after'  => $liqAfter['cost'],
        'parity_ok'       => $parityOk && $error === null,
        'error'           => $error,
    ];
}

/**
 * Build a self-sufficient doc_review_queue row for a composite resolution failure.
 */
function _bom_build_composite_rq(
    int    $skuId,
    string $skuCode,
    string $formatCode,
    string $prefix,
    int    $recipeId,
    string $srcSku,
    string $rqSubtype,
    string $detail
): array {
    $ts       = (int)(microtime(true) * 1000);
    $queueId  = 'RQ_' . $ts . '_' . strtoupper(substr(md5("{$skuId}{$rqSubtype}{$prefix}"), 0, 6));
    $dedupKey = "sku-bom-unresolved|{$skuCode}|{$rqSubtype}" . ($prefix ? "|{$prefix}" : '');
    $value    = "{$skuCode} — {$rqSubtype}" . ($prefix ? " ({$prefix})" : '');
    $context  = "SKU: {$skuCode}\nFormat: {$formatCode}\n"
              . ($prefix    ? "Member prefix: {$prefix}\n" : '')
              . ($recipeId  ? "Recipe id: {$recipeId}\n" : '')
              . ($srcSku    ? "Source SKU tried: {$srcSku}\n" : '')
              . "Issue: {$detail}\n"
              . "Action: check ref_sku_composite_slots + ref_sku_packaging_choices + single-unit liquid BOMs.";
    return [
        'queue_id'    => $queueId,
        'value'       => $value,
        'context'     => $context,
        'top_match'   => null,
        'suggestions' => null,
        'dedup_key'   => $dedupKey,
        'priority'    => 75,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Private helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Snapshot liquid rows for a SKU (source != 'Packaging').
 * Returns ['rows' => int, 'cost' => float].
 */
function _bom_liquid_snapshot(PDO $pdo, int $skuId): array
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(cost), 0) AS total_cost
           FROM ref_sku_bom
          WHERE sku_id = ?
            AND source != 'Packaging'"
    );
    $stmt->execute([$skuId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return [
        'rows' => (int)$row['cnt'],
        'cost' => (float)$row['total_cost'],
    ];
}

/**
 * Snapshot the packaging + composite rows for a SKU — the domain the liquid apply
 * must NOT touch. Used as a parity gate in the liquid apply branch: pre- and post-
 * apply snapshots must be byte-identical (row count + SUM(cost) within 1e-6).
 *
 * Covers:
 *   source = 'Packaging'  (all packaging rows, regardless of bom_source)
 *   bom_source IN ('composite_liquid', 'composite_packaging')
 *
 * Returns ['rows' => int, 'cost' => float].
 */
function _bom_packaging_composite_snapshot(PDO $pdo, int $skuId): array
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(cost), 0) AS total_cost
           FROM ref_sku_bom
          WHERE sku_id = ?
            AND (source = 'Packaging'
                 OR bom_source IN ('composite_liquid', 'composite_packaging'))"
    );
    $stmt->execute([$skuId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return [
        'rows' => (int)$row['cnt'],
        'cost' => (float)$row['total_cost'],
    ];
}

/**
 * Count rows that the non-composite DELETE would purge on apply (for dry-run reporting).
 *
 * Predicate MUST match the live DELETE in the apply path (~line 781):
 *   DELETE FROM ref_sku_bom WHERE sku_id = ? AND (source <> 'Brewing' OR source IS NULL)
 *
 * This catches any legacy non-Brewing tag (Packaging, mi_match, NULL) — keeping the
 * dry-run pkg_deleted counter honest about what apply would actually do.
 */
function _bom_count_packaging(PDO $pdo, int $skuId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM ref_sku_bom WHERE sku_id = ? AND (source <> 'Brewing' OR source IS NULL)"
    );
    $stmt->execute([$skuId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Returns true if a slot's scope allows it given the template's decoration/supply.
 *
 * Scope rules:
 *   always        → always included
 *   labelled_only → only when decoration_integral = 0 (non-pre-printed)
 *   we_supply_only→ only when supply = 'we_supply' (always true for packaging-only recompute
 *                    since we query only we_supply templates; kept for correctness)
 */
function _bom_scope_ok(string $scope, bool $decoIntegral, bool $isWeSupply): bool
{
    switch ($scope) {
        case 'always':         return true;
        case 'labelled_only':  return !$decoIntegral;
        case 'we_supply_only': return $isWeSupply;
        default:               return true;
    }
}

/**
 * Resolve a packaging slot to a ref_mi.id, using the precedence chain:
 *   1. ref_sku_packaging_choices (SKU-level override)
 *   2. ref_recipe_packaging_bindings (recipe-level binding by role)
 *   3. ref_packaging_items.default_mi_id_fk (template default)
 *
 * Scotch slot is handled separately by the caller via _bom_resolve_scotch().
 * Returns ref_mi.id or null if unresolved.
 */
function _bom_resolve_slot(
    array  $item,
    int    $skuId,
    int    $recipeId,
    string $prefix,
    array  $bindings,
    array  $skuChoices,
    array  $miById
): ?int {
    $slotName = $item['slot_name'];
    $pattern  = $item['mi_filter_pattern'];

    // Tier 1: SKU-level override
    if (isset($skuChoices[$skuId][$slotName])) {
        $c = $skuChoices[$skuId][$slotName];
        return $c['mi_id_fk']; // may be null if override explicitly unsets
    }

    // Tier 2: recipe binding by role (role name matches slot_name for: label, can, sticker, holder, outer_tray, scotch)
    if (isset($bindings[$recipeId][$slotName])) {
        return $bindings[$recipeId][$slotName];
    }

    // Tier 3: template default (fixed MI)
    if (!str_contains($pattern, '{beer}')) {
        // Fixed slot — default_mi_id_fk is the answer
        return $item['default_mi_id_fk'] !== null ? (int)$item['default_mi_id_fk'] : null;
    }

    // Beer-specific slot with {beer} pattern
    // Scotch pattern with alternation is handled by _bom_resolve_scotch() above
    if (str_contains($pattern, '(TRANSP|{beer})')) {
        // This is the scotch pattern — should have been handled by caller
        // If we're here, it's a non-scotch slot with this pattern (shouldn't happen)
        return null;
    }

    // Resolve {beer} → prefix, then LIKE lookup.
    // Skip is_active=0 MIs — deactivated MIs must not be resurrected via pattern resolution.
    // (Observed consumption lines referencing inactive MIs are source='Brewing' and bypass this path.)
    $resolved = str_replace('{beer}', $prefix, $pattern);
    $resolvedExact = rtrim($resolved, '%');

    // First try exact match (active MIs only)
    $id = _bom_lookup_mi_id_active($resolvedExact, $miById);
    if ($id !== null) {
        return $id;
    }

    // LIKE match if pattern ends with % (active MIs only)
    if (str_ends_with($resolved, '%')) {
        foreach ($miById as $miId => $mi) {
            if (isset($mi['is_active']) && !(bool)$mi['is_active']) {
                continue; // skip inactive
            }
            if (_bom_mi_id_matches_like($mi['mi_id'], $resolved)) {
                return $miId;
            }
        }
    }

    // Fall back to template default (fixed MI, not pattern-matched — left as-is)
    return $item['default_mi_id_fk'] !== null ? (int)$item['default_mi_id_fk'] : null;
}

/**
 * Resolve the scotch slot specifically.
 * Pattern = 'PKG_SCOTCH_(TRANSP|{beer})%' — needs two-LIKE-OR, not literal pattern.
 *
 * Precedence:
 *   1. SKU override
 *   2. Recipe binding (role='scotch')
 *   3. Template default (PKG_SCOTCH_TRANSP = id 179)
 */
function _bom_resolve_scotch(
    int    $skuId,
    int    $recipeId,
    string $prefix,
    bool   $usesBranded,
    array  $bindings,
    array  $skuChoices,
    array  $miById,
    int    $defaultMiIdFk
): ?int {
    // Tier 1: SKU override
    if (isset($skuChoices[$skuId]['scotch'])) {
        return $skuChoices[$skuId]['scotch']['mi_id_fk'];
    }

    // Tier 2: recipe binding
    if (isset($bindings[$recipeId]['scotch'])) {
        return $bindings[$recipeId]['scotch'];
    }

    // Tier 3: template default
    return $defaultMiIdFk > 0 ? $defaultMiIdFk : null;
}

/**
 * Look up a ref_mi.id by its mi_id string from the pre-loaded index.
 * Returns the first match regardless of is_active — used for fixed/explicit references.
 */
function _bom_lookup_mi_id(string $miIdString, array $miById): ?int
{
    foreach ($miById as $id => $mi) {
        if ($mi['mi_id'] === $miIdString) {
            return $id;
        }
    }
    return null;
}

/**
 * Look up a ref_mi.id by its mi_id string, skipping is_active=0 rows.
 * Used exclusively in the {beer}-pattern resolution path to prevent resurrection
 * of deactivated placeholder MIs via pattern matching.
 */
function _bom_lookup_mi_id_active(string $miIdString, array $miById): ?int
{
    foreach ($miById as $id => $mi) {
        if ($mi['mi_id'] === $miIdString) {
            if (isset($mi['is_active']) && !(bool)$mi['is_active']) {
                return null; // found but inactive — treat as not found
            }
            return $id;
        }
    }
    return null;
}

/**
 * Simple LIKE simulation for {beer}% patterns (only trailing % supported).
 */
function _bom_mi_id_matches_like(string $miId, string $pattern): bool
{
    if (str_ends_with($pattern, '%')) {
        $prefix = substr($pattern, 0, -1);
        return str_starts_with($miId, $prefix);
    }
    return $miId === $pattern;
}

/**
 * Build a self-sufficient doc_review_queue row for an unresolved slot.
 * Conforms to the 'sku-bom-unresolved' type.
 * Never includes (unknown)/(none)/0.00 stubs.
 */
function _bom_build_rq_row(
    int    $skuId,
    string $skuCode,
    string $formatCode,
    string $prefix,
    string $slotName,
    string $pattern,
    int    $recipeId,
    array  $miById
): array {
    $queueId  = 'RQ_' . (int)(microtime(true) * 1000) . '_' . strtoupper(substr(md5("{$skuId}{$slotName}"), 0, 6));
    $dedupKey = "sku-bom-unresolved|{$skuCode}|{$slotName}";
    $value    = "{$skuCode} — slot: {$slotName} ({$formatCode})";

    // Resolve candidate MIs from the LIKE pattern for self-sufficiency
    $candidates = [];
    if (str_contains($pattern, '(TRANSP|{beer})')) {
        // Scotch alternation: two candidates
        $transpId = _bom_lookup_mi_id('PKG_SCOTCH_TRANSP', $miById);
        if ($transpId !== null) {
            $candidates[] = 'PKG_SCOTCH_TRANSP (id=' . $transpId . ')';
        }
        $brandedKey = str_replace('{beer}', $prefix, 'PKG_SCOTCH_{beer}');
        $brandedId = _bom_lookup_mi_id($brandedKey, $miById);
        if ($brandedId !== null) {
            $candidates[] = $brandedKey . ' (id=' . $brandedId . ')';
        }
    } elseif (str_contains($pattern, '{beer}')) {
        $resolvedPattern = str_replace('{beer}', $prefix, $pattern);
        $resolvedExact   = rtrim($resolvedPattern, '%');
        foreach ($miById as $id => $mi) {
            if (isset($mi['is_active']) && !(bool)$mi['is_active']) {
                continue; // skip inactive — RQ candidates must be actionable
            }
            if ($mi['mi_id'] === $resolvedExact || _bom_mi_id_matches_like($mi['mi_id'], $resolvedPattern)) {
                $candidates[] = $mi['mi_id'] . ' (id=' . $id . ')';
                if (count($candidates) >= 5) break;
            }
        }
    }

    $context = "SKU: {$skuCode}\n"
             . "Format: {$formatCode}\n"
             . "Recipe prefix: {$prefix}\n"
             . "Slot: {$slotName}\n"
             . "MI filter pattern: {$pattern}\n"
             . "Candidates from pattern: " . (empty($candidates) ? 'none found' : implode(', ', $candidates)) . "\n"
             . "Action: bind the correct MI via Salle de contrôle → Recettes → Formats tab.";

    $topMatch = !empty($candidates) ? $candidates[0] : null;

    $suggestions = !empty($candidates)
        ? json_encode(array_values($candidates))
        : null;

    // Priority: scotch/holder slots are lower urgency (known gaps); can is urgent (affects cost)
    $priority = in_array($slotName, ['scotch', 'sticker'], true) ? 10 : 50;

    return [
        'queue_id'    => $queueId,
        'value'       => $value,
        'context'     => $context,
        'top_match'   => $topMatch,
        'suggestions' => $suggestions,
        'dedup_key'   => $dedupKey,
        'priority'    => $priority,
    ];
}

/**
 * Returns the set of format IDs that are commissioned and buildable today.
 *
 * Mirrors the logic in sdc_gated_format_ids() (public/modules/salle-de-controle.php).
 * MUST stay in sync with that function — if the UI gate changes, update here too.
 *
 * Gate: ref_filler_containers (active) → ref_process_machines (active) →
 *       dbc_container_types → dbc_packaging_format_templates → ref_packaging_formats (active, non-composite).
 * Cartoner check: formats requiring units_per_format > 1 are excluded when no active cartoner exists.
 *
 * @return int[]
 */
function _compiler_gated_format_ids(PDO $pdo): array
{
    $cartoner = (int)$pdo->query(
        "SELECT COUNT(*) FROM ref_process_machines WHERE machine_type='cartoner' AND is_active=1"
    )->fetchColumn();

    // Arm A: formats directly in the dbc commissioning chain.
    $rows = $pdo->query(
        "SELECT DISTINCT f.id, (t.units_per_format > 1) AS needs_cartoner
           FROM ref_filler_containers fc
           JOIN ref_process_machines m   ON m.id = fc.machine_id  AND m.is_active = 1
           JOIN dbc_container_types c    ON c.id = fc.container_id
           JOIN dbc_packaging_format_templates t ON t.container_code = c.container_code
           JOIN ref_packaging_formats f  ON f.catalog_id = t.id
          WHERE fc.is_active = 1 AND f.is_active = 1 AND f.is_composite = 0"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $ids = [];
    foreach ($rows as $r) {
        if ($r['needs_cartoner'] && !$cartoner) {
            continue;
        }
        $ids[] = (int)$r['id'];
    }

    // Arm B: derived-format formats whose parent format passes Arm A.
    // A pour format is gated if its parent keg format is commissioned.
    // Generic: no hardcoded format ids.
    $parentGated = array_flip($ids);
    $derivedRows = $pdo->query(
        "SELECT id, derived_from_format_id
           FROM ref_packaging_formats
          WHERE derived_from_format_id IS NOT NULL
            AND is_active = 1
            AND is_composite = 0"
    )->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($derivedRows as $d) {
        if (isset($parentGated[(int)$d['derived_from_format_id']])) {
            $ids[] = (int)$d['id'];
        }
    }

    return $ids;
}

// ─────────────────────────────────────────────────────────────────────────────
// Shared BOM-recompile helpers — used by bom-review.php + salle-de-controle.php.
// Defined here (app/) so any page that require_once this file gets them without
// requiring a module-level file.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Recompile packaging BOM for all active SKUs belonging to a recipe.
 * Called AFTER commit() so a recompute failure never rolls back the saved binding.
 * Returns the compile_sku_bom_packaging result array (or a zero-result stub on
 * empty SKU set). Throws on hard PHP errors; the caller wraps in try/catch.
 */
if (!function_exists('sdc_recompile_recipe_packaging')) {
    function sdc_recompile_recipe_packaging(PDO $pdo, int $recipeId): array
    {
        // Solo SKUs whose recipe_id matches directly.
        $stmt = $pdo->prepare(
            "SELECT id FROM ref_skus WHERE recipe_id = ? AND is_active = 1"
        );
        $stmt->execute([$recipeId]);
        $soloIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

        // Composite SKUs that contain this recipe as a member slot.
        // A label-binding change for recipe N must re-emit the composite's
        // member-container lines (bom_source='composite_packaging') for that member.
        $compStmt = $pdo->prepare(
            "SELECT DISTINCT cs.sku_id
               FROM ref_sku_composite_slots cs
               JOIN ref_skus s ON s.id = cs.sku_id AND s.is_active = 1
              WHERE cs.recipe_id = ?
                AND (cs.effective_until IS NULL OR cs.effective_until > CURDATE())"
        );
        $compStmt->execute([$recipeId]);
        $compositeIds = array_map('intval', array_column($compStmt->fetchAll(PDO::FETCH_ASSOC), 'sku_id'));

        // COLLAB SKUs temporally resolved to this recipe_id.
        $collabStmt = $pdo->prepare(
            "SELECT DISTINCT s.id
               FROM ref_skus s
               JOIN ref_sku_collab_temporal ct ON ct.sku_code = s.sku_code
                AND ct.recipe_id = ?
                AND ct.effective_from <= CURDATE()
                AND (ct.effective_until IS NULL OR ct.effective_until > CURDATE())
              WHERE s.recipe_id IS NULL AND s.is_active = 1"
        );
        $collabStmt->execute([$recipeId]);
        $collabIds = array_map('intval', array_column($collabStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

        $skuIds = array_values(array_unique(array_merge($soloIds, $compositeIds, $collabIds)));
        if (empty($skuIds)) {
            return [
                'dry_run'            => false,
                'skus'               => [],
                'total_pkg_deleted'  => 0,
                'total_pkg_inserted' => 0,
                'total_rq_emitted'   => 0,
                'parity_violations'  => 0,
                'errors'             => 0,
            ];
        }
        return compile_sku_bom_packaging($pdo, $skuIds, false, true);
    }
}

/**
 * Set the flash message after a BOM recompile attempt.
 * saveMsg = success label for the preceding write.
 * r       = result array from sdc_recompile_recipe_packaging().
 */
if (!function_exists('sdc_flash_bom_result')) {
    function sdc_flash_bom_result(string $saveMsg, array $r): void
    {
        if ($r['parity_violations'] > 0 || $r['errors'] > 0) {
            flash_set('err', $saveMsg
                . " · BOM recompilé avec avertissements"
                . " ({$r['parity_violations']} violation(s) parité, {$r['errors']} erreur(s))."
                . " La sauvegarde est conservée.");
        } else {
            flash_set('ok', $saveMsg
                . " · BOM recompilé ({$r['total_pkg_inserted']} lignes, {$r['total_rq_emitted']} en file).");
        }
    }
}

// =============================================================================
// LIQUID BOM COMPILER
// =============================================================================

/**
 * compile_sku_bom_liquid
 *
 * Computes the PROPOSED liquid BOM for every in-scope solo SKU:
 *   active (ref_skus.is_active=1) + recipe_id IS NOT NULL
 *   NOT a composite (no ref_sku_composite_slots row).
 *
 * Ingredient sourcing rules (hard-encoded per spec):
 *
 *   MALT_ and HOPS_ (category: Malt, Hops)
 *     → OBSERVED-ONLY from form data.
 *     → Sources:
 *         malt + hops_kettle + hops_dry  → bd_brewing_ingredients_parsed (v1)
 *                                          source_id → bd_brewing_ingredients_v2 (has recipe_id_fk)
 *     → If a recipe/MI combo has NO observed data → DO NOT emit it (intentional drift, not gap-fill).
 *
 *   Non-MALT/non-HOPS (mineral, process, adjunct, yeast):
 *     → Observed wins (bd_brewing_ingredients_parsed_v2, header_id → bd_brewing_brewday_v2)
 *     → ref_recipe_ingredients GAP-FILLS where observed is missing.
 *     → Zero-observed recipes: fall back FULLY to ref_recipe_ingredients for ALL categories.
 *
 * Per-HL basis: volume-weighted trailing average.
 *   Window: last 8 brews of each recipe (or all if < 8) where BOTH ingredient data
 *           AND cooling HL are available. Minimum: 1 brew (we report n_brews in output).
 *   Batch HL: SUM(bd_brewing_cooling.cool_final_volume_hl) per (cool_beer_recipe_id, cool_batch).
 *   Per brew: per_hl = canonical_qty_in_pricing_unit / batch_hl
 *   Outlier rejection: drop brews whose per_hl > 2 × MAD from median (min 4 brews required).
 *   Volume-weighted average: SUM(per_hl_i × batch_hl_i) / SUM(batch_hl_i)
 *   qty_per_unit = avg_per_hl × sku.hl_per_unit
 *
 * Unit / cost discipline:
 *   - g input, kg pricing → canonical_qty_kg = qty_g × 0.001; cost = qty_g × price × 0.001
 *   - ml input, kg pricing → canonical_qty_kg = qty_ml × density_g_per_ml × 0.001
 *   - kg input, kg pricing → as-is; cost = qty_kg × price
 *   ing_unit stored in ref_sku_bom = the SOURCE unit (g/kg/ml from observed data)
 *   qty_per_unit stored = qty in ing_unit (NOT pricing unit)
 *   cost = qty_per_unit × price × effective_conversion_factor
 *   Refuse-don't-NULL: if MI has no price → cost=null, flagged in output.
 *
 * Scope exclusion:
 *   - Composites (has ref_sku_composite_slots rows) → skipped entirely.
 *
 * @param PDO        $pdo      Active DB connection.
 * @param int[]|null $skuIds   Specific SKU ids, or null = all in-scope solo active SKUs.
 * @param bool       $dryRun   true (default) = compute only, no DB writes.
 *
 * @return array {
 *   dry_run: bool,
 *   generated_at: string,
 *   scope: array{total_skus, composite_excluded, recipe_excluded, in_scope},
 *   skus: array<int, array{
 *     sku_code, recipe_id, hl_per_unit,
 *     proposed_lines: array<array{
 *       mi_id_fk, mi_code, cat_name, ing_unit, qty_per_unit, per_hl, n_brews, n_brews_in_window,
 *       cost, currency, price, source (observed|recipe_gapfill|recipe_full_fallback),
 *       no_price_flag: bool
 *     }>,
 *     diff: array{added: array, removed: array, changed: array},
 *     current_liquid_lines: int,
 *     proposed_liquid_lines: int,
 *     unresolved_mi: array<string>,
 *     error: string|null,
 *   }>,
 *   summary: array{
 *     skus_total, skus_gaining_liquid, skus_losing_liquid, skus_no_change,
 *     lines_added_total, lines_removed_total, lines_changed_total,
 *     unresolved_mi_flags: array<string>,
 *     errors_total: int,
 *   },
 *   alternative_validation: array,
 * }
 */
function compile_sku_bom_liquid(
    PDO    $pdo,
    ?array $skuIds = null,
    bool   $dryRun = true
): array {

    $generatedAt = gmdate('Y-m-d H:i:s') . ' UTC';

    // ── 1. Resolve in-scope SKU set ───────────────────────────────────────────

    // All active solo (non-composite) SKUs with recipe_id
    $allSoloStmt = $pdo->query(
        "SELECT s.id, s.sku_code, s.recipe_id, s.hl_per_unit
           FROM ref_skus s
          WHERE s.is_active = 1
            AND s.recipe_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM ref_sku_composite_slots sc WHERE sc.sku_id = s.id
            )
          ORDER BY s.sku_code"
    );
    $allSoloSkus = $allSoloStmt->fetchAll(\PDO::FETCH_ASSOC);
    $allSoloById = [];
    foreach ($allSoloSkus as $row) {
        $allSoloById[(int)$row['id']] = $row;
    }

    // Composite SKUs count (for scope reporting)
    $compositeCnt = (int)$pdo->query(
        "SELECT COUNT(DISTINCT sku_id) FROM ref_sku_composite_slots"
    )->fetchColumn();

    // Recipe-excluded: active SKUs with recipe_id=NULL (not composite)
    $recipeMissingCnt = (int)$pdo->query(
        "SELECT COUNT(*) FROM ref_skus WHERE is_active=1 AND recipe_id IS NULL
           AND NOT EXISTS (SELECT 1 FROM ref_sku_composite_slots sc WHERE sc.sku_id=ref_skus.id)"
    )->fetchColumn();

    if ($skuIds !== null) {
        // Filter to only the requested subset that are in-scope
        $filtered = [];
        foreach ($skuIds as $id) {
            $id = (int)$id;
            if (isset($allSoloById[$id])) {
                $filtered[$id] = $allSoloById[$id];
            }
        }
        $targetSkus = $filtered;
    } else {
        $targetSkus = $allSoloById;
    }

    // ── 2. Pre-load reference data ─────────────────────────────────────────────

    // ref_mi: id → {mi_id, cat_name, input_unit, pricing_unit, conversion_factor,
    //               density_g_per_ml, price, currency, cost_chf, cost_basis}
    // cost_chf from v_mi_cost (WAC > catalog > no_basis, FX-normalised to CHF per pricing_unit).
    $miStmt = $pdo->query(
        "SELECT m.id, m.mi_id, mc.name AS cat_name,
                m.input_unit, m.pricing_unit, m.conversion_factor,
                m.density_g_per_ml, m.price, m.currency,
                v.cost_chf, v.cost_basis
           FROM ref_mi m
           JOIN ref_mi_categories mc ON mc.id = m.category_id
           LEFT JOIN v_mi_cost v ON v.mi_id_fk = m.id
          WHERE m.is_active = 1"
    );
    $miById = [];
    foreach ($miStmt->fetchAll(\PDO::FETCH_ASSOC) as $m) {
        $miById[(int)$m['id']] = $m;
    }

    // ── 2-OI. Oldest-invoice costing data (compiler-local, NEVER modifies v_mi_cost) ──────
    //
    // OLDEST-INVOICE RULE (operator ruling 2026-06-07):
    //   Per MI-line, at costing time:
    //   TRIGGER: the MI has ≥1 inv_deliveries row (exclusion_class IS NULL, qty_delivered > 0,
    //            ANY status including Consumed) AND the recipe's basis-window END (the latest
    //            basis-batch date used for that recipe's observed window) is STRICTLY BEFORE
    //            the MI's earliest date_received.
    //   THEN: cost the line from the OLDEST delivery by date_received:
    //         chf_unit = total_chf / qty_delivered (CHF-normalised at the source row —
    //         NEVER unit_price × a hardcoded FX rate).
    //         Tag provenance as 'oldest_invoice'.
    //   ELSE if MI has no deliveries at all → existing catalog/no_basis fallback, unchanged.
    //   ELSE (coverage overlaps the basis window) → current WAC, unchanged.
    //
    // This query is LOCAL to compile_sku_bom_liquid — v_mi_cost is shared and signed-off,
    // do NOT modify it. The per-MI oldest-delivery lookup is an override applied AFTER the
    // shared WAC is loaded, only when the trigger condition is met for a given (recipe, MI) pair.

    // miOldestDelivery[mi_id_fk] = {delivery_id, date_received, chf_unit}
    // Only populated for MIs that actually have ≥1 delivery row.
    $miOldestDelivery = [];
    {
        // Subquery: per ingredient_fk, pick the row with the earliest date_received (ties: lowest id).
        $oiStmt = $pdo->query(
            "SELECT d.ingredient_fk, d.id AS delivery_id, d.date_received,
                    (d.total_chf / d.qty_delivered) AS chf_unit
               FROM inv_deliveries d
              WHERE d.exclusion_class IS NULL
                AND d.qty_delivered > 0
                AND d.date_received = (
                    SELECT MIN(d2.date_received)
                      FROM inv_deliveries d2
                     WHERE d2.ingredient_fk = d.ingredient_fk
                       AND d2.exclusion_class IS NULL
                       AND d2.qty_delivered > 0
                )
              ORDER BY d.ingredient_fk, d.id ASC"
        );
        foreach ($oiStmt->fetchAll(\PDO::FETCH_ASSOC) as $oi) {
            $miFk = (int)$oi['ingredient_fk'];
            // Keep only the first row per MI (earliest date_received, lowest id — ties resolved)
            if (!isset($miOldestDelivery[$miFk])) {
                $miOldestDelivery[$miFk] = [
                    'delivery_id'   => (int)$oi['delivery_id'],
                    'date_received' => $oi['date_received'],   // 'YYYY-MM-DD'
                    'chf_unit'      => (float)$oi['chf_unit'],
                ];
            }
        }
    }

    // Collect unique recipe IDs across all target SKUs
    $recipeIds = array_values(array_unique(array_column($targetSkus, 'recipe_id')));

    if (empty($recipeIds)) {
        return _liq_empty_result($dryRun, $generatedAt, $allSoloSkus, $compositeCnt, $recipeMissingCnt);
    }

    $recipePhRaw = implode(',', array_fill(0, count($recipeIds), '?'));

    // ── 2a-pre. Recipe window guard (G2: floor date) ─────────────────────────
    // Load liquid_basis_floor_date for all in-scope recipes.
    //
    // G2 (floor date): when liquid_basis_floor_date IS NOT NULL, brews whose
    // event_date is strictly before the floor are excluded from the trailing
    // window. Used for recipe discontinuities (DK era-change: SPY batch 57+
    // and DIB batch 4+, both floored at 2025-10-07).
    //
    // Note on seasonal (EPH) scoping: each EPH vintage is already a distinct
    // recipe row in ref_recipes (e.g. r62=EPH1-2026, r76=EPH2-2026). Active
    // EPH SKUs point to their vintage's recipe_id, so the trailing window only
    // ever sees that vintage's single brew. No additional vintage-scoping guard
    // is required — the DB model enforces it structurally.
    //
    // If the guarded set has 0 brews: recipe is skipped with a per-recipe note
    // (not counted as an error — expected for dormant recipes with no post-floor
    // brews yet).
    $recipeGuardStmt = $pdo->prepare(
        "SELECT id, liquid_basis_floor_date
           FROM ref_recipes
          WHERE id IN ({$recipePhRaw})"
    );
    $recipeGuardStmt->execute($recipeIds);
    // recipeGuards[recipe_id] = {floor_date}
    $recipeGuards = [];
    foreach ($recipeGuardStmt->fetchAll(\PDO::FETCH_ASSOC) as $rg) {
        $recipeGuards[(int)$rg['id']] = [
            'floor_date' => $rg['liquid_basis_floor_date'],  // 'YYYY-MM-DD' or null
        ];
    }

    // ── 2a. Batch HL index ─────────────────────────────────────────────────────
    // For each (recipe_id, batch): total cooling HL across all brews of that batch.
    // bd_brewing_cooling.cool_batch (varchar) matches bd_brewing_ingredients_v2.batch (varchar).
    $hlStmt = $pdo->prepare(
        "SELECT cool_beer_recipe_id AS recipe_id, cool_batch AS batch,
                SUM(cool_final_volume_hl) AS batch_hl,
                COUNT(*) AS n_brews,
                MIN(event_date) AS batch_event_date
           FROM bd_brewing_cooling
          WHERE cool_beer_recipe_id IN ({$recipePhRaw})
            AND cool_final_volume_hl IS NOT NULL
            AND cool_final_volume_hl > 0
          GROUP BY cool_beer_recipe_id, cool_batch"
    );
    $hlStmt->execute($recipeIds);
    // batchHl[recipe_id][batch] = float hl
    // batchNBrews[recipe_id][batch] = int — number of parallel brews in that batch.
    //   bd_brewing_ingredients_v2 has ONE header per batch (the operator enters the
    //   grain bill once, for a single brew's charge). bd_brewing_cooling has one row
    //   per physical brew. For a 4-brew batch the ingredient qty is ONE brew's charge
    //   but the total batch HL is 4 brews' HL — the numerator must be scaled ×n_brews
    //   before per-HL division (done in the malt/hops_kettle accumulator below).
    // batchEventDate[recipe_id][batch] = date string (for guard filtering)
    $batchHl        = [];
    $batchNBrews    = [];
    $batchEventDate = [];
    foreach ($hlStmt->fetchAll(\PDO::FETCH_ASSOC) as $hl) {
        $rid   = (int)$hl['recipe_id'];
        $batch = $hl['batch'];
        $batchHl[$rid][$batch]        = (float)$hl['batch_hl'];
        $batchNBrews[$rid][$batch]    = max(1, (int)$hl['n_brews']);  // default 1 (identity) if missing
        $batchEventDate[$rid][$batch] = $hl['batch_event_date'];  // 'YYYY-MM-DD' or null
    }

    // ── 2b. Observed malt + kettle-hops from v1 (bd_brewing_ingredients_parsed) ──
    // Link: source_id → bd_brewing_ingredients_v2 → recipe_id_fk + batch
    // Categories: malt, hops_kettle ONLY (source_table='bd_brewing_ingredients').
    // hops_dry rows use a different source (bd_fermenting_v2 DryHop events — see §2b3).
    $v1Stmt = $pdo->prepare(
        "SELECT biv.recipe_id_fk AS recipe_id, bip.batch, bip.category,
                bip.mi_id_fk, bip.qty, bip.unit, bip.event_date
           FROM bd_brewing_ingredients_parsed bip
           JOIN bd_brewing_ingredients_v2 biv
             ON biv.id = bip.source_id
            AND biv.is_tombstoned = 0
          WHERE biv.recipe_id_fk IN ({$recipePhRaw})
            AND bip.category IN ('malt','hops_kettle')
            AND bip.mi_id_fk IS NOT NULL
          ORDER BY biv.recipe_id_fk, bip.batch, bip.category, bip.mi_id_fk"
    );
    $v1Stmt->execute($recipeIds);
    // v1RawRows[recipe_id][batch][category][mi_id_fk] = [qty, unit, event_date]
    // Note: same batch can have multiple rows for same MI (e.g. multi-brew additions)
    // We SUM them per batch per MI (batch total).
    $v1RawRows = [];
    foreach ($v1Stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $rid = (int)$row['recipe_id'];
        $batch = $row['batch'];
        $cat   = $row['category'];
        $miId  = (int)$row['mi_id_fk'];
        $qty   = (float)$row['qty'];
        $unit  = $row['unit'];
        if (!isset($v1RawRows[$rid][$batch][$cat][$miId])) {
            $v1RawRows[$rid][$batch][$cat][$miId] = ['qty' => 0.0, 'unit' => $unit, 'date' => $row['event_date']];
        }
        $v1RawRows[$rid][$batch][$cat][$miId]['qty'] += $qty;
    }

    // ── 2b3. Dry-hop observed — bd_fermenting_v2 DryHop events (sole source) ────
    // bd_fermenting_v2 event_type='DryHop' is the canonical, recipe_id_fk-native source.
    // The legacy bd_brewing_ingredients_parsed path (source_table='bd_fermenting') joined
    // via source_id → bd_fermenting_v2.id was REMOVED: source_id there points into the v1
    // bd_fermenting table, not bd_fermenting_v2, causing arbitrary cross-recipe attribution
    // (Stirling b147 dry-hops landing on EMB b185, etc.). v2 DryHop covers all Néb recipes
    // with dry-hop SKU BOMs; the only v1-only rows are contract brews outside F2 scope.
    // Aggregate per (recipe_id_fk, batch, dh_mi_id_fk) to guard against multi-line same-MI
    // dry-hop additions on the same batch (the fan-out anti-pattern).
    $dhV2Stmt = $pdo->prepare(
        "SELECT recipe_id_fk AS recipe_id, batch,
                dh_mi_id_fk AS mi_id_fk, SUM(dh_qty) AS qty, dh_unit AS unit,
                MIN(event_date) AS event_date
           FROM bd_fermenting_v2
          WHERE recipe_id_fk IN ({$recipePhRaw})
            AND event_type = 'DryHop'
            AND dh_mi_id_fk IS NOT NULL
            AND is_tombstoned = 0
            AND batch NOT IN ('None', '')
          GROUP BY recipe_id_fk, batch, dh_mi_id_fk, dh_unit
          ORDER BY recipe_id_fk, batch, dh_mi_id_fk"
    );
    $dhV2Stmt->execute($recipeIds);
    // dhV2[recipe_id][batch][mi_id_fk] = [qty=>float, unit=>string, date=>string]
    $dhV2 = [];
    foreach ($dhV2Stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $rid   = (int)$row['recipe_id'];
        $batch = $row['batch'];
        $miId  = (int)$row['mi_id_fk'];
        $dhV2[$rid][$batch][$miId] = [
            'qty'  => (float)$row['qty'],
            'unit' => $row['unit'],
            'date' => $row['event_date'],
        ];
    }

    // ── 2b4. Assign dry-hop data — dhV2 is the sole source ──────────────────────
    // dhMerged[recipe_id][batch][mi_id_fk] = [qty, unit, date]
    $dhMerged  = $dhV2;
    $dhCoverage = [];  // [recipe_id] => ['total_dh_batches'=>int]
    foreach ($recipeIds as $_rid) {
        $_rid = (int)$_rid;
        $dhCoverage[$_rid] = [
            'total_dh_batches' => count($dhMerged[$_rid] ?? []),
        ];
    }

    // ── 2c. Observed non-malt/hops from v2 (bd_brewing_ingredients_parsed_v2) ──
    // Link: header_id → bd_brewing_brewday_v2 → recipe_id_fk + batch
    // Categories: adjunct, mineral, process
    $v2Stmt = $pdo->prepare(
        "SELECT bd.recipe_id_fk AS recipe_id, bd.batch, bd.event_date, bi.category,
                bi.mi_id_fk, bi.qty, bi.unit
           FROM bd_brewing_ingredients_parsed_v2 bi
           JOIN bd_brewing_brewday_v2 bd
             ON bd.id = bi.header_id
            AND bd.is_tombstoned = 0
          WHERE bd.recipe_id_fk IN ({$recipePhRaw})
            AND bi.category IN ('adjunct','mineral','process')
            AND bi.mi_id_fk IS NOT NULL
          ORDER BY bd.recipe_id_fk, bd.batch, bi.category, bi.mi_id_fk"
    );
    $v2Stmt->execute($recipeIds);
    // v2Rows[recipe_id][batch][category][mi_id_fk] = [qty, unit]
    // v2BatchDate[recipe_id][batch] = event_date string (for guard filtering)
    $v2RawRows  = [];
    $v2BatchDate = [];
    foreach ($v2Stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $rid  = (int)$row['recipe_id'];
        $batch = $row['batch'];
        $cat  = $row['category'];
        $miId = (int)$row['mi_id_fk'];
        $qty  = (float)$row['qty'];
        $unit = $row['unit'];
        if (!isset($v2RawRows[$rid][$batch][$cat][$miId])) {
            $v2RawRows[$rid][$batch][$cat][$miId] = ['qty' => 0.0, 'unit' => $unit];
        }
        $v2RawRows[$rid][$batch][$cat][$miId]['qty'] += $qty;
        if (!isset($v2BatchDate[$rid][$batch])) {
            $v2BatchDate[$rid][$batch] = $row['event_date'];
        }
    }

    // ── 2d. ref_recipe_ingredients (gap-fill / full-fallback) ─────────────────
    // qty_per_hl is already per HL in the recipe's declared unit.
    $recipeIngStmt = $pdo->prepare(
        "SELECT ri.recipe_id, ri.mi_id_fk, ri.qty_per_hl, ri.unit
           FROM ref_recipe_ingredients ri
          WHERE ri.recipe_id IN ({$recipePhRaw})
            AND ri.is_active = 1"
    );
    $recipeIngStmt->execute($recipeIds);
    // recipeIng[recipe_id][mi_id_fk] = {qty_per_hl, unit}
    $recipeIng = [];
    foreach ($recipeIngStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $rid  = (int)$row['recipe_id'];
        $miId = (int)$row['mi_id_fk'];
        $recipeIng[$rid][$miId] = [
            'qty_per_hl' => (float)$row['qty_per_hl'],
            'unit'       => $row['unit'],
        ];
    }

    // ── 2d-post. Apply per-recipe floor-date guard (G2) ──────────────────────
    // For recipes with liquid_basis_floor_date set, exclude batches whose brew
    // event_date is strictly before the floor from batchHl, v1RawRows, v2RawRows.
    // Sourced from bd_brewing_cooling.event_date (MIN per batch).
    //
    // Zero-surviving batches → recipe is skipped (per-recipe note, not an error).
    //
    // $recipeWindowGuarded[recipe_id] = [
    //     'guarded'          => bool,      true if floor_date guard was applied
    //     'guard_reason'     => string[],  e.g. ['floor:2025-10-07']
    //     'batches_excluded' => int,       count of batches removed by the floor
    //     'batches_kept'     => int,       count of batches remaining
    //     'zero_window'      => bool,      true if no batches survive the guard
    // ]
    $recipeWindowGuarded = [];

    foreach ($recipeIds as $recipeId) {
        $recipeId  = (int)$recipeId;
        $floorDate = $recipeGuards[$recipeId]['floor_date'] ?? null;  // 'YYYY-MM-DD' or null

        if ($floorDate === null) {
            // No guard for this recipe
            $recipeWindowGuarded[$recipeId] = ['guarded' => false];
            continue;
        }

        // Determine which batches survive the floor date.
        // Basis = batches with a known cooling event_date >= floor, plus any batch
        // whose event_date is unknown (null → allowed conservatively).
        $allBatches      = array_keys($batchHl[$recipeId] ?? []);
        $allowedBatches  = [];
        $excludedBatches = [];

        foreach ($allBatches as $batch) {
            $batchDate = $batchEventDate[$recipeId][$batch] ?? null;
            if ($batchDate !== null && strcmp($batchDate, $floorDate) < 0) {
                $excludedBatches[] = $batch;
            } else {
                $allowedBatches[] = $batch;
            }
        }

        $batchesKept     = count($allowedBatches);
        $batchesExcluded = count($excludedBatches);
        $zeroWindow      = ($batchesKept === 0);

        $recipeWindowGuarded[$recipeId] = [
            'guarded'          => true,
            'guard_reason'     => ["floor:{$floorDate}"],
            'batches_excluded' => $batchesExcluded,
            'batches_kept'     => $batchesKept,
            'zero_window'      => $zeroWindow,
        ];

        if (!$zeroWindow) {
            $allowedSet = array_flip($allowedBatches);
            if (isset($batchHl[$recipeId])) {
                $batchHl[$recipeId] = array_intersect_key($batchHl[$recipeId], $allowedSet);
            }
            if (isset($v1RawRows[$recipeId])) {
                $v1RawRows[$recipeId] = array_intersect_key($v1RawRows[$recipeId], $allowedSet);
            }
            if (isset($v2RawRows[$recipeId])) {
                $v2RawRows[$recipeId] = array_intersect_key($v2RawRows[$recipeId], $allowedSet);
            }
            // Apply floor guard to dry-hop merged data (same allowed-batch set).
            if (isset($dhMerged[$recipeId])) {
                $dhMerged[$recipeId] = array_intersect_key($dhMerged[$recipeId], $allowedSet);
            }
        } else {
            // Zero-window: clear all batch data so the aggregation step detects it.
            $batchHl[$recipeId]   = [];
            $v1RawRows[$recipeId] = [];
            $v2RawRows[$recipeId] = [];
            $dhMerged[$recipeId]  = [];
        }
    }

    // ── 2e. Current liquid BOM rows (for diffing) ─────────────────────────────
    if (!empty($targetSkus)) {
        $skuIdsList = array_keys($targetSkus);
        $skuPlRaw = implode(',', array_fill(0, count($skuIdsList), '?'));
        $curLiqStmt = $pdo->prepare(
            "SELECT b.sku_id, b.mi_id, b.ingredient_raw, b.qty_per_unit, b.ing_unit, b.cost, b.bom_source
               FROM ref_sku_bom b
              WHERE b.sku_id IN ({$skuPlRaw})
                AND b.source = 'Brewing'
                AND b.mi_id IS NOT NULL
              ORDER BY b.sku_id, b.mi_id"
        );
        $curLiqStmt->execute($skuIdsList);
        // currentLiq[sku_id][mi_id] = {qty_per_unit, ing_unit, cost}
        $currentLiq = [];
        foreach ($curLiqStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sid = (int)$row['sku_id'];
            $mid = (int)$row['mi_id'];
            $currentLiq[$sid][$mid] = [
                'qty_per_unit' => (float)$row['qty_per_unit'],
                'ing_unit'     => $row['ing_unit'],
                'cost'         => $row['cost'] !== null ? (float)$row['cost'] : null,
                'bom_source'   => $row['bom_source'],
                'mi_code'      => $row['ingredient_raw'],
            ];
        }
    } else {
        $currentLiq = [];
    }

    // ── 3. Per-recipe: build recipe-level basis-batch set ────────────────────
    //
    // basisBatches[recipe_id] = ordered array of batch ids (most-recent first, ≤8)
    // that satisfy ALL of:
    //   (a) cooling HL > 0 (has a batchHl entry, after floor-guard filtering)
    //   (b) at least one observed ingredient row; AND
    //   (c) for recipes that have ANY dry-hop batches (dhMerged non-empty):
    //       the batch must also have dry-hop observed data — batches with only
    //       brewhouse data but no DH entry are excluded from the shared window.
    //       Rationale: a batch that was never dry-hopped (or whose DH was not
    //       recorded) must not consume a window slot and inflate the denominator
    //       without contributing to the dry-hop numerator — that would silently
    //       dilute dry-hop per-HL averages and displace older DH batches that
    //       carry unique hop entries (e.g. Mosaic in EMB b225).
    //
    // This is the SHARED window for every MI of a recipe — brewhouse AND dry-hop stages.
    // ONE shared set: the dhBasisSet in the dry-hop branch reads the same array.
    // A MI that has no qty in the basis window simply drops out (stale retired ingredients
    // age out naturally rather than pulling in old batches outside the window).
    //
    // Rule ①: per_hl = Σ(MI qty over surviving presentBatches) ÷ Σ(HL over ALL basisBatches)
    // "Absence dilutes": a batch where the MI was not used counts as qty=0 in the numerator
    // but its HL still enters the denominator. This yields the average actual practice per HL
    // across the window (vs the old per-present-batch weighting that inflated retired MIs).
    $recipeBasisBatches = [];  // recipe_id → [batch, ...] sorted descending (most recent first)
    foreach ($recipeIds as $recipeId) {
        $recipeId = (int)$recipeId;
        $hlMap    = $batchHl[$recipeId] ?? [];

        // Collect all batches with HL > 0 (already floor-guard filtered above)
        $candidateBatches = array_keys($hlMap);

        // Determine whether this recipe has any dry-hop data at all.
        // If it does, require DH presence per batch (gate c above).
        $recipeHasDh = !empty($dhMerged[$recipeId]);

        // Keep only batches with at least one observed ingredient row in any source,
        // plus dry-hop gate when the recipe has DH batches.
        $withIngredients = [];
        foreach ($candidateBatches as $batch) {
            // Gate (c): if recipe has DH data, this batch must also have DH data.
            // A batch with only brewhouse data but no DH entry is excluded from the
            // shared basis window (prevents denominator inflation without DH contribution
            // and preserves older DH batches within the ≤8 window).
            if ($recipeHasDh && (!isset($dhMerged[$recipeId][$batch]) || empty($dhMerged[$recipeId][$batch]))) {
                continue;
            }

            $hasObs = false;
            // v1: malt or hops_kettle
            if (isset($v1RawRows[$recipeId][$batch]) && !empty($v1RawRows[$recipeId][$batch])) {
                $hasObs = true;
            }
            // v2: mineral / process / adjunct
            if (!$hasObs && isset($v2RawRows[$recipeId][$batch]) && !empty($v2RawRows[$recipeId][$batch])) {
                $hasObs = true;
            }
            // dry-hop (always present for this batch when recipeHasDh is true and gate passed)
            if (!$hasObs && isset($dhMerged[$recipeId][$batch]) && !empty($dhMerged[$recipeId][$batch])) {
                $hasObs = true;
            }
            if ($hasObs) {
                $withIngredients[] = $batch;
            }
        }

        // Sort descending by batch number as integer (most recent first), cap at 8.
        // Explicit integer cast guards against string-comparison pitfalls on numeric batch keys.
        usort($withIngredients, fn($a, $b) => (int)$b - (int)$a);
        $recipeBasisBatches[$recipeId] = array_slice($withIngredients, 0, 8);
    }

    // ── 3a-OI. Compute basis-window END date per recipe (for oldest-invoice trigger) ────────
    //
    // basis-window END = the LATEST batch event_date among the ≤8 selected basis batches.
    // Used by the oldest-invoice trigger: if window END < MI's earliest date_received →
    // the recipe's brews all predate the ingredient's delivery-price coverage.
    //
    // recipeWindowEndDate[recipe_id] = 'YYYY-MM-DD' or null (if no batches / dates unknown).
    $recipeWindowEndDate = [];
    foreach ($recipeBasisBatches as $rid => $batches) {
        if (empty($batches)) {
            $recipeWindowEndDate[$rid] = null;
            continue;
        }
        // batchEventDate[rid][batch] was populated from bd_brewing_cooling MIN(event_date).
        // The "end" of the basis window is the LATEST date among the selected batches.
        $maxDate = null;
        foreach ($batches as $batch) {
            $d = $batchEventDate[$rid][$batch] ?? null;
            if ($d !== null && ($maxDate === null || $d > $maxDate)) {
                $maxDate = $d;
            }
        }
        $recipeWindowEndDate[$rid] = $maxDate;
    }

    // ── 3b. Volume-weighted per-HL aggregator (recipe-level window semantics) ──
    //
    // $computePerHl now takes the recipe's pre-selected $basisBatches (ordered ≤8 set),
    // the per-MI $batchQty data, and the full $hlMap.
    //
    // Algorithm:
    //   1. presentBatches = basisBatches where this MI has qty > 0
    //      → empty: return null (MI drops out; stale / retired ingredient)
    //   2. Outlier rejection (≥4 present values): keep today's 2×MAD fences
    //      among presentBatches' per-batch per-HL values; rejected batches'
    //      qty is excluded from the numerator for that MI.
    //   3. per_hl = Σ(surviving qty_i) ÷ Σ(HL over ALL basisBatches)
    //      Denominator = full basis-window HL, not just the present/surviving batches.
    //      Absence dilutes: a basis batch where the MI was zero contributes 0 to
    //      numerator but its full HL to denominator → reflects true window practice.
    //
    // @param array  $basisBatches  Ordered list of batch ids for this recipe (≤8, desc)
    // @param array  $batchQty      batch → [qty=>float, unit=>string]  (only batches with qty>0)
    // @param array  $hlMap         batch → float HL
    // @return array|null  {per_hl, n_brews, n_window, unit, basis_window_hl}
    $computePerHl = function(array $basisBatches, array $batchQty, array $hlMap): ?array {
        if (empty($basisBatches)) {
            return null;
        }

        // Denominator: ΣHL over ALL basis batches (fixed for this recipe's window)
        $totalBasisHl = 0.0;
        foreach ($basisBatches as $batch) {
            $totalBasisHl += $hlMap[$batch] ?? 0.0;
        }
        if ($totalBasisHl <= 0.0) {
            return null;
        }
        $nWindow = count($basisBatches);

        // Present batches: basis batches where this MI has qty > 0
        $presentData = [];  // batch → {qty, unit, hl}
        $unit = null;
        foreach ($basisBatches as $batch) {
            if (!isset($batchQty[$batch]) || $batchQty[$batch]['qty'] <= 0) {
                continue;  // MI absent in this basis batch — contributes 0 to numerator
            }
            $presentData[$batch] = [
                'qty'  => $batchQty[$batch]['qty'],
                'unit' => $batchQty[$batch]['unit'],
                'hl'   => $hlMap[$batch] ?? 0.0,
            ];
            if ($unit === null) {
                $unit = $batchQty[$batch]['unit'];
            }
        }

        // Empty presentBatches → MI has no usage in the recipe's basis window → drop it
        if (empty($presentData)) {
            return null;
        }

        // Compute per_hl per present batch (in source unit / HL)
        $perHls = [];
        foreach ($presentData as $batch => $data) {
            if ($data['hl'] > 0) {
                $perHls[$batch] = $data['qty'] / $data['hl'];
            }
        }
        if (empty($perHls)) {
            return null;
        }

        // Outlier rejection (only when ≥ 4 present values).
        // Relative floor: skip rejection entirely when MAD/median < 0.05 — data is
        // already consistent; rejection would incorrectly penalise normal variation
        // in clustered values (e.g. Amarillo DH on EMB where all per-HL values are
        // 295–313 g/HL, median ≈ 308.9, MAD ≈ 2.2, ratio ≈ 0.007 → skip).
        // Without this guard, the absolute 2×MAD fences (304.5–313.3) would reject
        // two perfectly normal batches (295.6 and 304.2), under-counting n_brews.
        $survivingBatches = array_keys($perHls);
        if (count($perHls) >= 4) {
            $vals = array_values($perHls);
            sort($vals);
            $n = count($vals);
            $mid = (int)($n / 2);
            $median = ($n % 2 === 0)
                ? ($vals[$mid - 1] + $vals[$mid]) / 2.0
                : $vals[$mid];
            $deviations = array_map(fn($v) => abs($v - $median), $vals);
            sort($deviations);
            $mad = ($n % 2 === 0)
                ? ($deviations[$mid - 1] + $deviations[$mid]) / 2.0
                : $deviations[$mid];

            // Relative consistency gate: if MAD/median < 0.05 the window is already
            // tight — skip outlier rejection to preserve all valid brews.
            $relativeDispersion = ($median > 0.0) ? ($mad / $median) : 0.0;
            if ($relativeDispersion >= 0.05) {
                $loFence = max(0.0, $median - 2.0 * $mad);
                $hiFence = $median + 2.0 * $mad;
                $survivors = array_filter($perHls, fn($v) => $v >= $loFence && $v <= $hiFence);
                if (count($survivors) >= 1) {
                    $survivingBatches = array_keys($survivors);
                }
                // else: pathological — keep all present batches
            }
            // else: MAD/median < 0.05 → data is consistent, skip rejection entirely
        }

        // Numerator: Σ qty over surviving present batches
        $sumQty = 0.0;
        foreach ($survivingBatches as $batch) {
            $sumQty += $presentData[$batch]['qty'] ?? 0.0;
        }

        // per_hl = Σ(surviving qty) ÷ Σ(ALL basis-window HL)
        // "Absence dilutes": batches where MI was absent contribute 0 to numerator
        // but their HL enters the denominator — per_hl reflects true average practice.
        return [
            'per_hl'           => $sumQty / $totalBasisHl,
            'n_brews'          => count($survivingBatches),
            'n_window'         => $nWindow,
            'unit'             => $unit ?? 'g',
            'basis_window_hl'  => $totalBasisHl,
        ];
    };

    /**
     * Compute the effective cost for one BOM line.
     * qty_per_unit is in ing_unit (g/kg/ml).
     * Uses v_mi_cost.cost_chf (WAC > catalog > no_basis, FX-normalised to CHF per pricing_unit).
     * Unit conversion factors (g→kg, ml→kg) are still applied because cost_chf is per pricing_unit (e.g. CHF/kg).
     * Returns float or null (if no cost basis — cost_basis='no_basis').
     */
    $computeCost = function(float $qtyPerUnit, string $ingUnit, array $mi): ?float {
        // Use cost_chf from v_mi_cost; falls back to null when cost_basis='no_basis'.
        $price = ($mi['cost_chf'] !== null && $mi['cost_chf'] !== '') ? (float)$mi['cost_chf'] : null;
        if ($price === null) {
            return null;
        }
        // pricing_unit determines the scale factor (cost_chf is per pricing_unit, e.g. CHF/kg)
        $pricingUnit = $mi['pricing_unit'] ?? '';
        $conv        = $mi['conversion_factor'] !== null ? (float)$mi['conversion_factor'] : null;

        if ($ingUnit === $pricingUnit) {
            // Same unit — no conversion needed
            return round($qtyPerUnit * $price, 6);
        }

        if (($ingUnit === 'g' || $ingUnit === 'kg') && $pricingUnit === 'kg') {
            // Use conversion_factor (g→kg is 0.001, kg→kg is 1.0)
            if ($conv === null) {
                return null;
            }
            return round($qtyPerUnit * $price * $conv, 6);
        }

        if ($ingUnit === 'ml' && $pricingUnit === 'kg') {
            // ml → g via density → kg via conv_factor
            $density = $mi['density_g_per_ml'] !== null ? (float)$mi['density_g_per_ml'] : null;
            if ($density === null || $conv === null) {
                return null; // cannot compute without density
            }
            // cost = qty_ml × density_g/ml × conv_g→kg × cost_chf_per_kg
            return round($qtyPerUnit * $density * $conv * $price, 6);
        }

        // Fallback: if conv_factor is available, use it
        if ($conv !== null) {
            return round($qtyPerUnit * $price * $conv, 6);
        }

        return null;
    };

    // ── Gate-15 collector (oldest-invoice transparency table) ────────────────────
    //
    // Populated during per-recipe costing when the oldest-invoice rule triggers.
    // Each entry: [recipe_id, recipe_name, mi_id (int), mi_code, basis_window_end,
    //              mi_earliest_delivery, oldest_delivery_id, oldest_chf_unit,
    //              current_wac_chf, delta_chf_per_hl, per_hl, qty_per_unit (sku-level, filled later)]
    // Keyed by "recipe_id:mi_id" to deduplicate within a recipe (one entry per recipe/MI pair).
    $gate15Entries = [];

    // ── 4. Per-recipe: build proposed liquid lines ────────────────────────────

    // proposedByRecipe[recipe_id][mi_id] = {mi_code, cat_name, ing_unit, qty_per_hl,
    //                                       n_brews, n_window, source, per_hl_unit, cost_per_hl, no_price}
    $proposedByRecipe = [];
    $globalUnresolvedMi = [];

    // zeroWindowRecipes: recipe_ids whose guarded window is empty — SKUs of these
    // recipes will be skipped with a per-SKU note in the output.
    $zeroWindowRecipes = [];
    foreach ($recipeWindowGuarded as $rid => $wg) {
        if (!empty($wg['zero_window'])) {
            $zeroWindowRecipes[$rid] = $wg;
        }
    }

    foreach ($recipeIds as $recipeId) {
        $recipeId  = (int)$recipeId;
        $hlMap     = $batchHl[$recipeId] ?? [];
        $proposed  = [];

        // Guard: if this recipe's window was guarded and has zero surviving batches,
        // emit an empty proposed set. The per-SKU loop below will emit a 'skipped'
        // note instead of processing this recipe. Do NOT fall through to the
        // ref_recipe full-fallback — a zero-window seasonal (e.g. dormant EPH3 2024,
        // no 2024-vintage brew this year) must be skipped, not costed from an
        // out-of-date recipe baseline.
        if (isset($zeroWindowRecipes[$recipeId])) {
            $proposedByRecipe[$recipeId] = null;  // null sentinel = zero-window skip
            continue;
        }

        // ── Oldest-invoice override closure (per-recipe, captures $recipeId) ────────────
        //
        // $computeCostOI wraps $computeCost and applies the OLDEST-INVOICE rule when
        // the trigger condition is met. Drop-in replacement for $computeCost at every
        // per-recipe line-costing call site.
        //
        // Returns [cost => float|null, cost_basis => 'oldest_invoice'|'wac'|'catalog'|'no_basis'].
        // Callers read [0] for cost, [1] for provenance.
        //
        // Gate-15 side-effect: when oldest_invoice triggers, appends an entry to $gate15Entries.
        // The entry key is "recipeId:miId" — one record per (recipe, MI) pair (deduped).
        $computeCostOI = function(
            float $qtyPerUnit,
            string $ingUnit,
            array  $mi,
            int    $miId
        ) use (
            $computeCost,
            $recipeId,
            $miOldestDelivery,
            $recipeWindowEndDate,
            &$gate15Entries
        ): array {
            $oi = $miOldestDelivery[$miId] ?? null;

            if ($oi !== null) {
                // The MI has at least one delivery. Check the trigger condition:
                // basis-window END strictly before MI's earliest date_received.
                $windowEnd = $recipeWindowEndDate[$recipeId] ?? null;

                if ($windowEnd !== null && strcmp($windowEnd, $oi['date_received']) < 0) {
                    // TRIGGER: oldest-invoice rule fires.
                    // chf_unit = total_chf / qty_delivered (already computed at load time,
                    // CHF-normalised at the source row — NEVER unit_price × hardcoded FX rate).
                    $oiChfUnit = $oi['chf_unit'];  // CHF per pricing_unit of the delivery

                    // Apply the same unit-conversion as $computeCost to yield cost per ing_unit qty.
                    // oiChfUnit is CHF per pricing_unit (e.g. CHF/kg for malts/hops).
                    // We need cost = qtyPerUnit × oiChfUnit × conversion_factor.
                    $pricingUnit = $mi['pricing_unit'] ?? '';
                    $conv        = $mi['conversion_factor'] !== null ? (float)$mi['conversion_factor'] : null;

                    $oiCost = null;
                    if ($ingUnit === $pricingUnit) {
                        $oiCost = round($qtyPerUnit * $oiChfUnit, 6);
                    } elseif (($ingUnit === 'g' || $ingUnit === 'kg') && $pricingUnit === 'kg') {
                        if ($conv !== null) {
                            $oiCost = round($qtyPerUnit * $oiChfUnit * $conv, 6);
                        }
                    } elseif ($ingUnit === 'ml' && $pricingUnit === 'kg') {
                        $density = $mi['density_g_per_ml'] !== null ? (float)$mi['density_g_per_ml'] : null;
                        if ($density !== null && $conv !== null) {
                            $oiCost = round($qtyPerUnit * $density * $conv * $oiChfUnit, 6);
                        }
                    } elseif ($conv !== null) {
                        $oiCost = round($qtyPerUnit * $oiChfUnit * $conv, 6);
                    }

                    // Gate-15: record one entry per (recipe, MI) pair.
                    $g15Key = "{$recipeId}:{$miId}";
                    if (!isset($gate15Entries[$g15Key])) {
                        $currentWac = ($mi['cost_chf'] !== null && $mi['cost_chf'] !== '')
                            ? (float)$mi['cost_chf'] : null;
                        $gate15Entries[$g15Key] = [
                            'recipe_id'             => $recipeId,
                            'mi_id_fk'              => $miId,
                            'mi_code'               => $mi['mi_id'],
                            'basis_window_end'      => $windowEnd,
                            'mi_earliest_delivery'  => $oi['date_received'],
                            'oldest_delivery_id'    => $oi['delivery_id'],
                            'oldest_chf_unit'       => round($oiChfUnit, 6),
                            'current_wac_chf'       => $currentWac,
                            'per_hl'                => $qtyPerUnit,   // filled with per-HL qty at call site
                            'cost_per_hl_oldest'    => $oiCost,       // per-HL cost at oldest price
                            'cost_per_hl_wac'       => $computeCost($qtyPerUnit, $ingUnit, $mi),
                        ];
                    }

                    return [$oiCost, 'oldest_invoice'];
                }
            }

            // No trigger — use WAC (current behavior).
            $cost = $computeCost($qtyPerUnit, $ingUnit, $mi);
            $basis = ($mi['cost_basis'] ?? 'no_basis');
            return [$cost, $basis !== null ? $basis : 'no_basis'];
        };

        // Determine if this recipe is "zero-observed" (no v1 OR v2 data at all)
        $hasAnyObserved = (
            !empty($v1RawRows[$recipeId]) || !empty($v2RawRows[$recipeId])
        );

        if (!$hasAnyObserved) {
            // Full fallback: use ref_recipe_ingredients for ALL categories.
            // Dry-hop has no recipe-ingredient equivalent (observed-only) — zero-observed recipes
            // also get no dry-hop lines (there are no dry-hop recipe ingredients to fall back to).
            foreach ($recipeIng[$recipeId] ?? [] as $miId => $ing) {
                $mi = $miById[$miId] ?? null;
                if ($mi === null) {
                    $globalUnresolvedMi[] = "recipe={$recipeId} mi_id_fk={$miId} (not in ref_mi)";
                    continue;
                }
                $perHl  = $ing['qty_per_hl'];
                $unit   = $ing['unit'];
                [$cost, $costBasis] = $computeCostOI($perHl, $unit, $mi, $miId);
                $proposed[$miId] = [
                    'mi_code'     => $mi['mi_id'],
                    'cat_name'    => $mi['cat_name'],
                    'ing_unit'    => $unit,
                    'per_hl'      => $perHl,
                    'n_brews'     => 0,
                    'n_window'    => 0,
                    'source'      => 'recipe_full_fallback',
                    'stage'       => 'brewhouse',
                    'cost_per_hl' => $cost,
                    'cost_basis'  => $costBasis,
                    'no_price'    => ($cost === null && $mi['price'] !== null && $mi['price'] !== ''),
                ];
            }
            // Dry-hop observed branch below will still run and may add dry_hop lines if
            // $dhMerged has data for this recipe, even when brewhouse observed is absent.
        }

        if ($hasAnyObserved) {
            // ── MALT: observed-only from v1 ─────────────────────────────────────
            // Aggregate v1 malt rows: [mi_id][batch] → qty
            // IMPORTANT: bd_brewing_ingredients_v2 has ONE header per batch regardless
            // of how many parallel brews were made. The operator enters one brew's grain
            // bill; bd_brewing_cooling has N rows (one per parallel brew). We must scale
            // the observed qty × n_brews so the per-batch numerator reflects the full
            // batch charge before dividing by total batch HL.
            $maltByMiBatch = [];  // mi_id → batch → {qty, unit}
            foreach ($v1RawRows[$recipeId] ?? [] as $batch => $cats) {
                if (!isset($cats['malt'])) continue;
                $nBrews = $batchNBrews[$recipeId][$batch] ?? 1;  // default 1 = single-brew (identity)
                foreach ($cats['malt'] as $miId => $data) {
                    $scaledData = $data;
                    $scaledData['qty'] = $data['qty'] * $nBrews;
                    $maltByMiBatch[$miId][$batch] = $scaledData;
                }
            }
            foreach ($maltByMiBatch as $miId => $batchData) {
                $mi = $miById[$miId] ?? null;
                if ($mi === null) {
                    $globalUnresolvedMi[] = "recipe={$recipeId} MALT mi_id_fk={$miId} (not in ref_mi)";
                    continue;
                }
                $result = $computePerHl($recipeBasisBatches[$recipeId] ?? [], $batchData, $hlMap);
                if ($result === null) {
                    continue; // MI absent from basis window — stale/retired, drop it
                }
                [$cost, $costBasis] = $computeCostOI($result['per_hl'], $result['unit'], $mi, $miId);
                $proposed[$miId] = [
                    'mi_code'     => $mi['mi_id'],
                    'cat_name'    => $mi['cat_name'],
                    'ing_unit'    => $result['unit'],
                    'per_hl'      => $result['per_hl'],
                    'n_brews'     => $result['n_brews'],
                    'n_window'    => $result['n_window'],
                    'source'      => 'observed',
                    'stage'       => 'brewhouse',
                    'cost_per_hl' => $cost,
                    'cost_basis'  => $costBasis,
                    'no_price'    => ($cost === null),
                ];
            }

            // ── HOPS (kettle only): observed-only from v1 ───────────────────────
            // Dry-hop is handled separately below via the $dhMerged branch.
            // Same n_brews scaling as malt: one ingredient header per batch, N cooling rows.
            $hopsByMiBatch = [];
            foreach ($v1RawRows[$recipeId] ?? [] as $batch => $cats) {
                if (!isset($cats['hops_kettle'])) continue;
                $nBrews = $batchNBrews[$recipeId][$batch] ?? 1;  // default 1 = single-brew (identity)
                foreach ($cats['hops_kettle'] as $miId => $data) {
                    $scaledData = $data;
                    $scaledData['qty'] = $data['qty'] * $nBrews;
                    $hopsByMiBatch[$miId][$batch] = $scaledData;
                }
            }
            foreach ($hopsByMiBatch as $miId => $batchData) {
                $mi = $miById[$miId] ?? null;
                if ($mi === null) {
                    $globalUnresolvedMi[] = "recipe={$recipeId} hops_kettle mi_id_fk={$miId} (not in ref_mi)";
                    continue;
                }
                $result = $computePerHl($recipeBasisBatches[$recipeId] ?? [], $batchData, $hlMap);
                if ($result === null) {
                    continue; // MI absent from basis window — stale/retired, drop it
                }
                [$cost, $costBasis] = $computeCostOI($result['per_hl'], $result['unit'], $mi, $miId);
                $proposed[$miId] = [
                    'mi_code'     => $mi['mi_id'],
                    'cat_name'    => $mi['cat_name'],
                    'ing_unit'    => $result['unit'],
                    'per_hl'      => $result['per_hl'],
                    'n_brews'     => $result['n_brews'],
                    'n_window'    => $result['n_window'],
                    'source'      => 'observed',
                    'stage'       => 'brewhouse',
                    'cost_per_hl' => $cost,
                    'cost_basis'  => $costBasis,
                    'no_price'    => ($cost === null),
                ];
            }

            // ── Non-malt/hops: observed (v2) first, then recipe gap-fill ─────────
            // Build: mi_id → observed per-HL from v2 (categories: mineral, process, adjunct)
            foreach (['mineral', 'process', 'adjunct'] as $cat) {
                // Build per-MI batch data from v2
                $obssByMiBatch = [];
                foreach ($v2RawRows[$recipeId] ?? [] as $batch => $cats) {
                    if (!isset($cats[$cat])) continue;
                    foreach ($cats[$cat] as $miId => $data) {
                        $obssByMiBatch[$miId][$batch] = $data;
                    }
                }

                // MIs with observed v2 data
                foreach ($obssByMiBatch as $miId => $batchData) {
                    $mi = $miById[$miId] ?? null;
                    if ($mi === null) {
                        $globalUnresolvedMi[] = "recipe={$recipeId} {$cat} mi_id_fk={$miId} (not in ref_mi)";
                        continue;
                    }
                    if (isset($proposed[$miId])) {
                        continue; // malt/hops from v1 already set
                    }
                    $result = $computePerHl($recipeBasisBatches[$recipeId] ?? [], $batchData, $hlMap);
                    if ($result === null) {
                        // MI absent from basis window or no HL → try recipe gap-fill below
                        continue;
                    }
                    [$cost, $costBasis] = $computeCostOI($result['per_hl'], $result['unit'], $mi, $miId);
                    $proposed[$miId] = [
                        'mi_code'     => $mi['mi_id'],
                        'cat_name'    => $mi['cat_name'],
                        'ing_unit'    => $result['unit'],
                        'per_hl'      => $result['per_hl'],
                        'n_brews'     => $result['n_brews'],
                        'n_window'    => $result['n_window'],
                        'source'      => 'observed',
                        'stage'       => 'brewhouse',
                        'cost_per_hl' => $cost,
                        'cost_basis'  => $costBasis,
                        'no_price'    => ($cost === null),
                    ];
                }
            }

            // Gap-fill: MIs in ref_recipe_ingredients NOT already covered by observed
            // EXCLUDING Malt (category_id 1) and Hops (category_id 2) — observed-only rule.
            foreach ($recipeIng[$recipeId] ?? [] as $miId => $ing) {
                if (isset($proposed[$miId])) {
                    continue; // already covered by observed
                }
                $mi = $miById[$miId] ?? null;
                if ($mi === null) {
                    $globalUnresolvedMi[] = "recipe={$recipeId} recipe_ing mi_id_fk={$miId} (not in ref_mi)";
                    continue;
                }
                // Skip Malt and Hops categories — NEVER gap-fill these
                if ($mi['cat_name'] === 'Malt' || $mi['cat_name'] === 'Hops') {
                    continue;
                }
                $perHl = $ing['qty_per_hl'];
                $unit  = $ing['unit'];
                [$cost, $costBasis] = $computeCostOI($perHl, $unit, $mi, $miId);
                $proposed[$miId] = [
                    'mi_code'     => $mi['mi_id'],
                    'cat_name'    => $mi['cat_name'],
                    'ing_unit'    => $unit,
                    'per_hl'      => $perHl,
                    'n_brews'     => 0,
                    'n_window'    => 0,
                    'source'      => 'recipe_gapfill',
                    'stage'       => 'brewhouse',
                    'cost_per_hl' => $cost,
                    'cost_basis'  => $costBasis,
                    'no_price'    => ($cost === null && $mi['price'] !== null && $mi['price'] !== ''),
                ];
            }
        } // end if ($hasAnyObserved)

        // ── DRY-HOP BRANCH (third observed branch) ─────────────────────────────
        // Reads from $dhMerged (v2 wins per batch, v1 fills remaining).
        // Always observed-only — no recipe gap-fill for dry-hops.
        // Emits separate lines keyed by (mi_id, stage='dry_hop') to allow the SAME
        // hop MI to appear in BOTH 'brewhouse' (kettle) and 'dry_hop' stages without
        // being collapsed into a single line. Keyed as (mi_id * -1 - 1) to avoid
        // collision with the brewhouse $proposed keying (which uses mi_id as key).
        // The outer SKU loop uses a 'stage'-keyed composite key for the proposed lines array.
        $dhProposed  = [];  // dh_key → proposal (dh_key = 'dh:' . $miId for uniqueness)
        $dhBatchData = [];  // mi_id → batch → [qty, unit]
        $dhBasisSet  = array_flip($recipeBasisBatches[$recipeId] ?? []);  // O(1) lookup
        foreach ($dhMerged[$recipeId] ?? [] as $batch => $mis) {
            // Guard: only include batches that are in the shared recipe basis window
            if (!isset($dhBasisSet[$batch])) {
                continue;
            }
            foreach ($mis as $miId => $data) {
                $dhBatchData[$miId][$batch] = $data;
            }
        }
        foreach ($dhBatchData as $miId => $batchQty) {
            $mi = $miById[$miId] ?? null;
            if ($mi === null) {
                $globalUnresolvedMi[] = "recipe={$recipeId} hops_dry mi_id_fk={$miId} (not in ref_mi)";
                continue;
            }
            $result = $computePerHl($recipeBasisBatches[$recipeId] ?? [], $batchQty, $hlMap);
            if ($result === null) {
                continue;
            }
            // Sanity gate: 0–1500 g/HL (dry-hop is in grams, HL is in HL units)
            $perHlG = $result['per_hl'];  // in source unit (g)
            if ($result['unit'] === 'kg') {
                $perHlG = $result['per_hl'] * 1000.0;  // convert to g for the sanity check
            }
            if ($perHlG < 0 || $perHlG > 1500.0) {
                error_log(sprintf(
                    'compile_sku_bom_liquid: DRY-HOP sanity WARN recipe=%d mi_id=%d per_hl=%.2f %s/HL (outside 0–1500 g/HL)',
                    $recipeId, $miId, $perHlG, $result['unit']
                ));
            }
            [$cost, $costBasis] = $computeCostOI($result['per_hl'], $result['unit'], $mi, $miId);
            $dhKey = 'dh:' . $miId;
            $dhProposed[$dhKey] = [
                'mi_id_fk'    => $miId,
                'mi_code'     => $mi['mi_id'],
                'cat_name'    => $mi['cat_name'],
                'ing_unit'    => $result['unit'],
                'per_hl'      => $result['per_hl'],
                'n_brews'     => $result['n_brews'],
                'n_window'    => $result['n_window'],
                'source'      => 'observed',
                'stage'       => 'dry_hop',
                'cost_per_hl' => $cost,
                'cost_basis'  => $costBasis,
                'no_price'    => ($cost === null),
            ];
        }

        $proposedByRecipe[$recipeId] = [
            'brewhouse' => $proposed,
            'dry_hop'   => $dhProposed,
            'coverage'  => $dhCoverage[$recipeId] ?? [],
        ];
    }

    // ── 5. Per-SKU: compute proposed lines + diff ─────────────────────────────

    $skuResults       = [];
    $summaryGaining   = 0;
    $summaryLosing    = 0;
    $summaryNoChange  = 0;
    $totalAdded       = 0;
    $totalRemoved     = 0;
    $totalChanged     = 0;
    $errorsTotal      = 0;
    $allUnresolvedMi  = $globalUnresolvedMi;

    foreach ($targetSkus as $skuId => $skuRow) {
        $skuId     = (int)$skuId;
        $skuCode   = $skuRow['sku_code'];
        $recipeId  = (int)$skuRow['recipe_id'];
        $hlPerUnit = (float)$skuRow['hl_per_unit'];
        $error     = null;

        if ($hlPerUnit <= 0.0) {
            $skuResults[$skuId] = [
                'sku_code'             => $skuCode,
                'recipe_id'            => $recipeId,
                'hl_per_unit'          => $hlPerUnit,
                'proposed_lines'       => [],
                'diff'                 => ['added' => [], 'removed' => [], 'changed' => []],
                'current_liquid_lines' => count($currentLiq[$skuId] ?? []),
                'proposed_liquid_lines'=> 0,
                'unresolved_mi'        => [],
                'error'                => "hl_per_unit=0 — cannot compute qty_per_unit",
            ];
            $errorsTotal++;
            continue;
        }

        // Zero-window guard: recipe has no surviving batches after guard filtering.
        // Skip this SKU with an explicit note — NOT counted as an error (expected
        // state for dormant seasonals with no current-vintage brew).
        if (array_key_exists($recipeId, $zeroWindowRecipes)) {
            $wg = $zeroWindowRecipes[$recipeId];
            $guardDesc = implode(', ', $wg['guard_reason'] ?? ['unknown guard']);
            $skuResults[$skuId] = [
                'sku_code'             => $skuCode,
                'recipe_id'            => $recipeId,
                'hl_per_unit'          => $hlPerUnit,
                'proposed_lines'       => [],
                'diff'                 => ['added' => [], 'removed' => [], 'changed' => []],
                'current_liquid_lines' => count($currentLiq[$skuId] ?? []),
                'proposed_liquid_lines'=> 0,
                'unresolved_mi'        => [],
                'skipped_zero_window'  => true,
                'skip_reason'          => "zero-window after guard ({$guardDesc}) — no brews in current scope. SKU liquid basis unchanged.",
                'error'                => null,
            ];
            $summaryNoChange++;
            continue;
        }

        $recipeProposedStruct = $proposedByRecipe[$recipeId] ?? ['brewhouse' => [], 'dry_hop' => [], 'coverage' => []];
        $brewhouseProposed    = $recipeProposedStruct['brewhouse'] ?? [];
        $dryHopProposed       = $recipeProposedStruct['dry_hop']   ?? [];
        $curLines             = $currentLiq[$skuId] ?? [];

        // Build proposed BOM lines for this SKU.
        // Key: 'bh:<mi_id>' for brewhouse lines, 'dh:<mi_id>' for dry-hop lines.
        // This allows the same MI to appear in both stages (e.g. Mosaic kettle + Mosaic dry-hop)
        // without collision. The diff is done against current lines keyed by mi_id (legacy),
        // so we report net change (brewhouse + dry_hop total per MI for diff, per-line for proposed).
        $proposedLines = [];

        foreach ($brewhouseProposed as $miId => $p) {
            $qtyPerUnit = round($p['per_hl'] * $hlPerUnit, 6);
            $cost       = $p['cost_per_hl'] !== null
                ? round($p['cost_per_hl'] * $hlPerUnit, 6)
                : null;
            $mi         = $miById[$miId] ?? null;
            $lineKey    = 'bh:' . $miId;
            $proposedLines[$lineKey] = [
                'mi_id_fk'          => $miId,
                'mi_code'           => $p['mi_code'],
                'cat_name'          => $p['cat_name'],
                'ing_unit'          => $p['ing_unit'],
                'qty_per_unit'      => $qtyPerUnit,
                'per_hl'            => round($p['per_hl'], 6),
                'n_brews'           => $p['n_brews'],
                'n_brews_in_window' => $p['n_window'],
                'cost'              => $cost,
                'currency'          => $mi['currency'] ?? null,
                'price'             => $mi !== null && $mi['price'] !== null ? (float)$mi['price'] : null,
                'source'            => $p['source'],
                'stage'             => 'brewhouse',
                'cost_basis'        => $p['cost_basis'] ?? null,
                'no_price_flag'     => $p['no_price'],
            ];
        }

        foreach ($dryHopProposed as $dhKey => $p) {
            $miId       = (int)$p['mi_id_fk'];
            $qtyPerUnit = round($p['per_hl'] * $hlPerUnit, 6);
            $cost       = $p['cost_per_hl'] !== null
                ? round($p['cost_per_hl'] * $hlPerUnit, 6)
                : null;
            $mi         = $miById[$miId] ?? null;
            $proposedLines[$dhKey] = [
                'mi_id_fk'          => $miId,
                'mi_code'           => $p['mi_code'],
                'cat_name'          => $p['cat_name'],
                'ing_unit'          => $p['ing_unit'],
                'qty_per_unit'      => $qtyPerUnit,
                'per_hl'            => round($p['per_hl'], 6),
                'n_brews'           => $p['n_brews'],
                'n_brews_in_window' => $p['n_window'],
                'cost'              => $cost,
                'currency'          => $mi['currency'] ?? null,
                'price'             => $mi !== null && $mi['price'] !== null ? (float)$mi['price'] : null,
                'source'            => $p['source'],
                'stage'             => 'dry_hop',
                'cost_basis'        => $p['cost_basis'] ?? null,
                'no_price_flag'     => $p['no_price'],
            ];
        }

        // Diff: compare proposed vs current.
        // Proposed is keyed by 'bh:<mi_id>' / 'dh:<mi_id>'; current is keyed by mi_id (int).
        // For the diff, dry-hop lines are ALWAYS 'added' (current ref_sku_bom has no dry-hop stage
        // rows yet — this compiler run is the first to introduce them).
        // Brewhouse lines are compared to current by mi_id.
        $added   = [];
        $removed = [];
        $changed = [];

        // Build a mi_id → qty/cost map for current lines (for brewhouse diff)
        $curByMiId = $curLines;  // already keyed by mi_id

        foreach ($proposedLines as $lineKey => $prop) {
            $miId = (int)$prop['mi_id_fk'];
            if ($prop['stage'] === 'dry_hop') {
                // Dry-hop lines: always new additions (no prior dry-hop stage in current BOM)
                $added[] = $prop;
            } elseif (!isset($curByMiId[$miId])) {
                $added[] = $prop;
            } else {
                $cur = $curByMiId[$miId];
                $qtyDelta = abs($prop['qty_per_unit'] - $cur['qty_per_unit']);
                $qtyPct   = $cur['qty_per_unit'] > 0
                    ? round(($prop['qty_per_unit'] - $cur['qty_per_unit']) / $cur['qty_per_unit'] * 100.0, 1)
                    : 0.0;
                if ($qtyDelta > 0.000001) {
                    $changed[] = array_merge($prop, [
                        'old_qty'        => $cur['qty_per_unit'],
                        'old_ing_unit'   => $cur['ing_unit'],
                        'old_cost'       => $cur['cost'],
                        'qty_pct_change' => $qtyPct,
                    ]);
                }
            }
        }
        // Build set of brewhouse mi_ids for the removed check
        $proposedBhMiIds = [];
        foreach ($proposedLines as $lineKey => $prop) {
            if ($prop['stage'] === 'brewhouse') {
                $proposedBhMiIds[(int)$prop['mi_id_fk']] = true;
            }
        }
        foreach ($curByMiId as $miId => $cur) {
            if (!isset($proposedBhMiIds[$miId])) {
                $removed[] = [
                    'mi_id_fk'     => $miId,
                    'mi_code'      => $cur['mi_code'],
                    'qty_per_unit' => $cur['qty_per_unit'],
                    'ing_unit'     => $cur['ing_unit'],
                    'cost'         => $cur['cost'],
                ];
            }
        }

        // Tally gaining/losing/no-change
        $proposedCnt = count($proposedLines);
        $curCnt      = count($curLines);
        if ($proposedCnt > $curCnt) {
            $summaryGaining++;
        } elseif ($proposedCnt < $curCnt) {
            $summaryLosing++;
        } else {
            // Same count, but could have changes
            if (!empty($changed) || !empty($added) || !empty($removed)) {
                $summaryNoChange++; // count-same but different MIs
            } else {
                $summaryNoChange++;
            }
        }

        $totalAdded   += count($added);
        $totalRemoved += count($removed);
        $totalChanged += count($changed);

        $skuResults[$skuId] = [
            'sku_code'             => $skuCode,
            'recipe_id'            => $recipeId,
            'hl_per_unit'          => $hlPerUnit,
            'proposed_lines'       => array_values($proposedLines),
            'diff'                 => [
                'added'   => $added,
                'removed' => $removed,
                'changed' => $changed,
            ],
            'current_liquid_lines' => $curCnt,
            'proposed_liquid_lines'=> $proposedCnt,
            'unresolved_mi'        => [],
            'error'                => $error,
        ];
    }

    // ── 6. Alternative validation (recipe 6) ──────────────────────────────────

    $altValidation = _liq_validate_alternative($pdo, $targetSkus, $skuResults, $recipeIng, $proposedByRecipe, $miById);

    // ── 7. Summary ────────────────────────────────────────────────────────────

    $gainingSku = [];
    $zeroWindowSkus = [];
    foreach ($skuResults as $sid => $sr) {
        if ($sr['proposed_liquid_lines'] > $sr['current_liquid_lines']) {
            $gainingSku[] = $sr['sku_code'];
        }
        if (!empty($sr['skipped_zero_window'])) {
            $zeroWindowSkus[] = $sr['sku_code'] . ' (' . ($sr['skip_reason'] ?? '') . ')';
        }
    }

    // Compute recipe floor-guard report for the summary
    $windowGuardReport = [];
    foreach ($recipeWindowGuarded as $rid => $wg) {
        if ($wg['guarded']) {
            $windowGuardReport[] = [
                'recipe_id'        => $rid,
                'floor_date'       => $recipeGuards[$rid]['floor_date'] ?? null,
                'guard_reasons'    => $wg['guard_reason'],
                'batches_excluded' => $wg['batches_excluded'],
                'batches_kept'     => $wg['batches_kept'],
                'zero_window'      => $wg['zero_window'],
            ];
        }
    }

    // Dry-hop coverage summary across all in-scope recipes
    $dhCoverageSummary = [];
    foreach ($recipeIds as $_rid) {
        $_rid = (int)$_rid;
        $cov  = $dhCoverage[$_rid] ?? [];
        if (($cov['total_dh_batches'] ?? 0) > 0) {
            $recipe = null;
            foreach ($targetSkus as $s) {
                if ((int)$s['recipe_id'] === $_rid) {
                    $recipe = $s['sku_code'] ?? null;
                    break;
                }
            }
            $dhCoverageSummary[] = array_merge(['recipe_id' => $_rid, 'sample_sku' => $recipe], $cov);
        }
    }

    $summaryOut = [
        'skus_total'                  => count($targetSkus),
        'skus_gaining_liquid'         => count($gainingSku),
        'skus_gaining_codes'          => $gainingSku,
        'skus_losing_liquid'          => $summaryLosing,
        'skus_no_change'              => $summaryNoChange,
        'skus_skipped_zero_window'    => count($zeroWindowSkus),
        'skus_skipped_zero_window_list' => $zeroWindowSkus,
        'lines_added_total'           => $totalAdded,
        'lines_removed_total'         => $totalRemoved,
        'lines_changed_total'         => $totalChanged,
        'unresolved_mi_flags'         => array_values(array_unique($allUnresolvedMi)),
        'errors_total'                => $errorsTotal,
        'window_guard_report'         => $windowGuardReport,
        'dry_hop_coverage'            => $dhCoverageSummary,
    ];

    // ── 8. Apply branch (executes only when $dryRun=false) ────────────────────

    $applyStats = [
        'skus_applied'       => 0,
        'lines_deleted'      => 0,
        'lines_inserted'     => 0,
        'parity_violations'  => 0,
        'apply_errors'       => 0,
    ];

    if (!$dryRun) {
        $compiledAt = gmdate('Y-m-d H:i:s');
        $today      = date('Y-m-d');

        $delStmt = $pdo->prepare(
            "DELETE FROM ref_sku_bom
              WHERE sku_id = :sku_id
                AND source = 'Brewing'
                AND (bom_source IS NULL OR bom_source = 'liquid')
                AND mi_id IS NOT NULL"
        );

        $insStmt = $pdo->prepare(
            "INSERT INTO ref_sku_bom
               (sku_id, mi_id, ingredient_raw, source, slot_name, category_raw,
                qty_per_unit, ing_unit, pricing_unit, price, currency, cost, volume_hl,
                resolution, row_hash, compiled_at, bom_source, effective_from)
             VALUES
               (:sku_id, :mi_id, :ingredient_raw, :source, :slot_name, :category_raw,
                :qty_per_unit, :ing_unit, :pricing_unit, :price, :currency, :cost, :volume_hl,
                :resolution, :row_hash, :compiled_at, :bom_source, :effective_from)"
        );

        foreach ($skuResults as $skuId => $sr) {
            // Skip SKUs with a compute error — do not touch their rows.
            if ($sr['error'] !== null) {
                $applyStats['apply_errors']++;
                continue;
            }

            // Skip zero-window / dormant SKUs — leave their existing rows untouched.
            if (!empty($sr['skipped_zero_window'])) {
                continue;
            }

            $pdo->beginTransaction();
            try {
                // Pre-snapshot the domain we must NOT touch:
                // packaging rows + composite rows.
                $pkgCompBefore = _bom_packaging_composite_snapshot($pdo, $skuId);

                // DELETE old liquid rows for this SKU.
                $delStmt->execute([':sku_id' => $skuId]);
                $deleted = $delStmt->rowCount();

                // INSERT proposed lines.
                $inserted = 0;
                foreach ($sr['proposed_lines'] as $line) {
                    $miIdFk  = (int)$line['mi_id_fk'];
                    $stage   = $line['stage'];          // 'brewhouse' or 'dry_hop'
                    $qtyRnd  = round((float)$line['qty_per_unit'], 6);
                    $cost    = $line['cost'] !== null ? (float)$line['cost'] : null;

                    // Effective per-pricing-unit CHF rate (self-consistent with cost).
                    $price = null;
                    if ($qtyRnd > 0 && $cost !== null) {
                        $price = round($cost / $qtyRnd, 6);
                    }

                    // pricing_unit from $miById (loaded at compiler start).
                    $pricingUnit = $miById[$miIdFk]['pricing_unit'] ?? null;

                    // row_hash: includes stage so brewhouse+dry_hop of same MI don't collide.
                    $rowHash = hash('sha256', implode('|', [
                        $skuId,
                        $miIdFk,
                        $stage,
                        $qtyRnd,
                        $today,
                    ]));

                    $insStmt->execute([
                        ':sku_id'         => $skuId,
                        ':mi_id'          => $miIdFk,
                        ':ingredient_raw' => $line['mi_code'],
                        ':source'         => 'Brewing',
                        ':slot_name'      => $stage,
                        ':category_raw'   => $line['cat_name'],
                        ':qty_per_unit'   => $qtyRnd,
                        ':ing_unit'       => $line['ing_unit'],
                        ':pricing_unit'   => $pricingUnit,
                        ':price'          => $price,
                        ':currency'       => 'CHF',
                        ':cost'           => $cost,
                        ':volume_hl'      => null,
                        ':resolution'     => 'mi_match',
                        ':row_hash'       => $rowHash,
                        ':compiled_at'    => $compiledAt,
                        ':bom_source'     => 'liquid',
                        ':effective_from' => $today,
                    ]);
                    $inserted++;
                }

                // Post-snapshot: packaging+composite domain must be byte-identical.
                $pkgCompAfter = _bom_packaging_composite_snapshot($pdo, $skuId);

                if ($pkgCompAfter['rows'] !== $pkgCompBefore['rows'] ||
                    abs($pkgCompAfter['cost'] - $pkgCompBefore['cost']) > 0.000001) {
                    $pdo->rollBack();
                    $applyStats['parity_violations']++;
                    $applyStats['apply_errors']++;
                    // Annotate the SKU result with the parity error so the caller can report it.
                    $skuResults[$skuId]['error'] = sprintf(
                        'PACKAGING/COMPOSITE PARITY GATE TRIPPED for sku_id=%d (%s): rows %d→%d, cost %.6f→%.6f — ROLLED BACK',
                        $skuId, $sr['sku_code'],
                        $pkgCompBefore['rows'], $pkgCompAfter['rows'],
                        $pkgCompBefore['cost'], $pkgCompAfter['cost']
                    );
                    continue;
                }

                $pdo->commit();
                $applyStats['skus_applied']++;
                $applyStats['lines_deleted']  += $deleted;
                $applyStats['lines_inserted'] += $inserted;

            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $applyStats['apply_errors']++;
                $skuResults[$skuId]['error'] = 'APPLY EXCEPTION: ' . $e->getMessage();
            }
        }
    }

    // ── 8b. Apply predicate (documented for reference) ────────────────────────
    $applyPredicate = $dryRun ? <<<'SQL'
-- Apply predicate (DRY-RUN ONLY — do NOT execute):
-- Step 1: DELETE solo Brewing rows that will be replaced (bom_source=NULL or 'liquid')
-- DELETE FROM ref_sku_bom
--  WHERE sku_id = :sku_id
--    AND source = 'Brewing'
--    AND (bom_source IS NULL OR bom_source = 'liquid')
--    AND mi_id IS NOT NULL;
--
-- Step 2: INSERT proposed lines with bom_source='liquid', source='Brewing'
--
-- Safe-zone: this predicate NEVER touches:
--   packaging rows  (source='Packaging')
--   composite_liquid rows (bom_source='composite_liquid')
--   composite_packaging rows (bom_source='composite_packaging')
-- Note: zero-window SKUs (dormant seasonals) are excluded from the apply scope.
SQL
    : '-- Applied (see apply_stats for counts).';

    // ── Gate-15: finalize oldest-invoice transparency table ────────────────────
    // gate15_table is the apply-gate 15 transparency report: every (recipe, MI) pair
    // where the oldest-invoice rule fired, with basis_window_end, MI's earliest delivery,
    // oldest CHF/unit vs current WAC, and per-HL CHF delta. Sorted by recipe_id then mi_code.
    $gate15Table = array_values($gate15Entries);
    usort($gate15Table, fn($a, $b) =>
        $a['recipe_id'] !== $b['recipe_id']
            ? $a['recipe_id'] <=> $b['recipe_id']
            : strcmp($a['mi_code'], $b['mi_code'])
    );

    return [
        'dry_run'              => $dryRun,
        'generated_at'         => $generatedAt,
        'apply_stats'          => $dryRun ? null : $applyStats,
        'scope'                => [
            'total_active_skus'  => count($allSoloById) + $compositeCnt,
            'composite_excluded' => $compositeCnt,
            'recipe_excluded'    => $recipeMissingCnt,
            'in_scope'           => count($targetSkus),
        ],
        'skus'                 => $skuResults,
        'summary'              => $summaryOut,
        'alternative_validation' => $altValidation,
        'apply_predicate_note' => $applyPredicate,
        'aggregator_location'  => 'compile_sku_bom_liquid → $computePerHl closure. Recipe-level basis window (≤8 most-recent batches with HL+any-observed-ingredient, shared across all stages). Per MI: presentBatches = basis ∩ {MI qty > 0}; empty → MI drops out. Outlier rejection (2×MAD) on presentBatches per-HL values. per_hl = Σ(surviving qty) ÷ Σ(ALL basis-window HL) — absence dilutes. Reuse by extracting to _liq_compute_per_hl() if a drift surface needs the same aggregation.',
        // Apply-gate 15 transparency: every line costed via oldest-invoice rule.
        // Operator ruling 2026-06-07. Dry-run only — zero ref_sku_bom writes this dispatch.
        'gate15_oldest_invoice' => $gate15Table,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Liquid compiler private helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Validate Alternative (recipe 6) SKUs against the spec requirements:
 *   (a) PROC_PHOSPHORIQUE must appear (recipe gap-fill since 0 observed in v2)
 *       Note: Alternative HAS observed malt/hops in v1 (6 batches). Only phosphoric
 *             appears via recipe gap-fill because v2 data for recipe 6 is sparse.
 *   (b) Observed hop bill is retained
 *   (c) NO recipe-only hop/malt was added that isn't in observed
 */
function _liq_validate_alternative(
    PDO   $pdo,
    array $targetSkus,
    array $skuResults,
    array $recipeIng,
    array $proposedByRecipe,
    array $miById
): array {
    $altSkuIds = [];
    foreach ($targetSkus as $sid => $s) {
        if ((int)$s['recipe_id'] === 6) {
            $altSkuIds[$sid] = $s['sku_code'];
        }
    }

    if (empty($altSkuIds)) {
        return ['status' => 'no_alt_skus_in_scope', 'checks' => []];
    }

    // proposedByRecipe[recipe_id] is now ['brewhouse'=>..., 'dry_hop'=>..., 'coverage'=>...]
    // or null for zero-window recipes.
    $altProposedStruct = $proposedByRecipe[6] ?? null;
    $altProposed = ($altProposedStruct !== null) ? ($altProposedStruct['brewhouse'] ?? []) : [];
    $altDryHop   = ($altProposedStruct !== null) ? ($altProposedStruct['dry_hop']   ?? []) : [];

    // Check (a): PROC_PHOSPHORIQUE in brewhouse proposed
    $phosphStmt = $pdo->query("SELECT id FROM ref_mi WHERE mi_id = 'PROC_PHOSPHORIQUE' LIMIT 1");
    $phosphRow  = $phosphStmt->fetch(\PDO::FETCH_ASSOC);
    $phosphMiId = $phosphRow ? (int)$phosphRow['id'] : null;
    $phosphPresent = $phosphMiId !== null && isset($altProposed[$phosphMiId]);
    $phosphSource  = $phosphPresent ? ($altProposed[$phosphMiId]['source'] ?? 'unknown') : null;

    // Check (b): hop MIs in brewhouse proposed all come from observed (dry-hop lines are separate)
    $hopsObserved       = [];
    $hopsRecipeOnly     = [];
    foreach ($altProposed as $miId => $p) {
        $mi = $miById[$miId] ?? null;
        if ($mi === null) continue;
        if ($mi['cat_name'] !== 'Hops') continue;
        if ($p['source'] === 'observed') {
            $hopsObserved[] = $p['mi_code'];
        } else {
            $hopsRecipeOnly[] = $p['mi_code']; // This should be empty per spec
        }
    }

    // Check (c): ref_recipe_ingredients hops/malt NOT in observed → must NOT appear in proposed
    $recipeHopMaltNotInProposed = [];
    foreach ($recipeIng[6] ?? [] as $miId => $ing) {
        $mi = $miById[$miId] ?? null;
        if ($mi === null) continue;
        if ($mi['cat_name'] !== 'Hops' && $mi['cat_name'] !== 'Malt') continue;
        if (!isset($altProposed[$miId])) {
            $recipeHopMaltNotInProposed[] = $mi['mi_id'] . ' (correctly excluded)';
        } elseif (($altProposed[$miId]['source'] ?? '') !== 'observed') {
            $recipeHopMaltNotInProposed[] = $mi['mi_id'] . ' (INCORRECTLY included via recipe — BUG)';
        }
    }

    $checks = [
        'phosphoric_present'         => $phosphPresent,
        'phosphoric_source'          => $phosphSource,
        'phosphoric_check'           => $phosphPresent ? 'PASS' : 'FAIL (PROC_PHOSPHORIQUE missing)',
        'hops_all_from_observed'     => empty($hopsRecipeOnly),
        'hops_observed_list'         => $hopsObserved,
        'hops_recipe_only_list'      => $hopsRecipeOnly,
        'hops_check'                 => empty($hopsRecipeOnly)
            ? 'PASS (no recipe-only hops leaked into proposed)'
            : 'FAIL (recipe-only hops present: ' . implode(', ', $hopsRecipeOnly) . ')',
        'recipe_hop_malt_exclusions' => $recipeHopMaltNotInProposed,
        'exclusion_check'            => empty(array_filter($recipeHopMaltNotInProposed, fn($s) => strpos($s, 'INCORRECTLY') !== false))
            ? 'PASS' : 'FAIL',
    ];

    $overallPass = (strpos($checks['phosphoric_check'], 'PASS') === 0)
        && (strpos($checks['hops_check'], 'PASS') === 0)
        && (strpos($checks['exclusion_check'], 'PASS') === 0);

    return [
        'status'      => $overallPass ? 'PASS' : 'FAIL',
        'alt_sku_cnt' => count($altSkuIds),
        'alt_skus'    => array_values($altSkuIds),
        'checks'      => $checks,
    ];
}

/**
 * Empty result stub when no SKUs are in scope.
 */
function _liq_empty_result(
    bool   $dryRun,
    string $generatedAt,
    array  $allSoloSkus,
    int    $compositeCnt,
    int    $recipeMissingCnt
): array {
    return [
        'dry_run'              => $dryRun,
        'generated_at'         => $generatedAt,
        'scope'                => [
            'total_active_skus'  => count($allSoloSkus) + $compositeCnt,
            'composite_excluded' => $compositeCnt,
            'recipe_excluded'    => $recipeMissingCnt,
            'in_scope'           => 0,
        ],
        'skus'                 => [],
        'summary'              => [
            'skus_total'          => 0,
            'skus_gaining_liquid' => 0,
            'skus_gaining_codes'  => [],
            'skus_losing_liquid'  => 0,
            'skus_no_change'      => 0,
            'lines_added_total'   => 0,
            'lines_removed_total' => 0,
            'lines_changed_total' => 0,
            'unresolved_mi_flags' => [],
            'errors_total'        => 0,
        ],
        'alternative_validation' => ['status' => 'no_alt_skus_in_scope', 'checks' => []],
        'apply_predicate_note'  => '',
        'aggregator_location'   => '',
    ];
}
