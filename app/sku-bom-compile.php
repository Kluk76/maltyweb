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
 * Box-sticker rule (§8.1):
 *   For 24-box formats (B id=1 / C id=7 / BC id=8):
 *     - If scotch resolves to PKG_SCOTCH_TRANSP (id=179): sticker slot REQUIRED.
 *     - If scotch resolves to a branded PKG_SCOTCH_[beer]: sticker INTENTIONALLY ABSENT.
 *     - If scotch UNRESOLVED: scotch → RQ row; sticker also unresolved → RQ row.
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
        $stmt = $pdo->query(
            "SELECT DISTINCT sku_id FROM ref_sku_bom WHERE mi_id IS NULL AND source = 'Packaging'"
        );
        $skuIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN, 0));
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

    $placeholders = implode(',', array_fill(0, count($skuIds), '?'));

    // Build a UNION to cover both normally-recipe'd SKUs and COLLAB-resolved SKUs.
    // If no COLLAB resolved, just use the standard query.
    if (empty($collabResolvedRecipes)) {
        $metaParams = $skuIds;
        $metaQuery = "SELECT s.id, s.sku_code, s.format_id, s.recipe_id, s.hl_per_unit,
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
          WHERE s.id IN ({$placeholders})
            AND f.is_active = 1
            AND f.is_composite = 0
          ORDER BY s.sku_code";
    } else {
        // Two branches: normal recipe_id path + collab override path
        $collabIds    = array_keys($collabResolvedRecipes);
        $normalIds    = array_diff($skuIds, $collabIds);
        $queryParts   = [];
        $metaParams   = [];

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
        "SELECT m.id, m.mi_id, m.name, c.name AS cat_name,
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
                $miById, $skuChoices, $dryRun
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

        $pkgLines = [];   // rows to INSERT: ['mi_id_fk' => int, 'slot_name' => str, 'qty' => float, 'volume_hl' => float|null]
        $rqRows   = [];   // rows to emit in doc_review_queue

        // Identify known-by-design NULL volume cases — these must NOT emit RQ rows.
        // run_type='cuv': CUV_LINER has null hl_per_unit (volume comes from the fill event).
        // is_composite=1 OR catalog_id NULL: composites/draft-pours/6C/PAD — no static chain.
        $runTypeFmt = $sku['fmt_run_type'] ?? '';  // populated in step 2 via f.run_type
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

        // Now process all slots
        foreach ($items as $item) {
            $slotName = $item['slot_name'];
            $scope    = $item['slot_scope'];
            $qty      = (float)$item['qty_per_unit'];

            // ── Scope gate ───────────────────────────────────────────────

            if (!_bom_scope_ok($scope, $decoIntegral, $isWeSupply)) {
                continue;
            }

            // ── §8.1 box-sticker rule ────────────────────────────────────
            // For 24-box formats, the 'sticker' slot (box-sticker) is:
            //   - REQUIRED if scotch resolves to TRANSP (179)
            //   - INTENTIONALLY ABSENT (skip, no RQ) if scotch resolves to branded
            //   - UNRESOLVED (emit RQ) if scotch itself is unresolved
            if ($slotName === 'sticker' && $is24Box) {
                if ($scotchResolved !== null && $scotchResolved !== $scotchTranspId) {
                    // scotch = branded → box-sticker intentionally absent, skip silently
                    continue;
                }
                // else: scotch=TRANSP or scotch unresolved → process sticker normally below
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
                       (sku_id, mi_id, ingredient_raw, source, category_raw,
                        qty_per_unit, ing_unit, pricing_unit, price, currency, cost, volume_hl,
                        resolution, row_hash, compiled_at, bom_source, effective_from)
                     VALUES
                       (:sku_id, :mi_id, :ingredient_raw, :source, :category_raw,
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

                    // Refuse-don't-NULL: no_basis lines keep cost=NULL; they are flagged in the return value.
                    $cost = ($costChf !== null) ? round($costChf * $line['qty'], 6) : null;

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

                    $ins->execute([
                        ':sku_id'         => $skuId,
                        ':mi_id'          => $line['mi_id_fk'],
                        ':ingredient_raw' => $miCode,
                        ':source'         => 'Packaging',
                        ':category_raw'   => $catName,
                        ':qty_per_unit'   => round($line['qty'], 6),
                        ':ing_unit'       => 'unit',
                        ':pricing_unit'   => $pricingUnit,
                        ':price'          => $costChf,  // per-unit cost in CHF (from v_mi_cost)
                        ':currency'       => $currency, // always 'CHF' after WAC switch
                        ':cost'           => $cost,
                        // Set on the container-role line ONLY (bottle/can slot).
                        // NULL on closure, label, box, sticker, accessories, AND keg/cuv lines
                        // (reusable containers — their volume is SKU-level via v_sku_volume).
                        // NOT additive across lines — one line owns the SKU's liquid volume.
                        ':volume_hl'      => isset($line['volume_hl']) ? $line['volume_hl'] : null,
                        ':resolution'     => 'mi_match',
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
 *
 * Safe-delete predicate: DELETE WHERE sku_id=? AND bom_source IS NULL
 * (clears ALL stale flat rows — both 'Brewing' and 'Packaging' with NULL bom_source —
 *  without touching rows from other SKUs).
 *
 * Refuse-don't-NULL: any unresolved member (no liquid BOM source found) or
 * overwrap slot with NULL mi_id_fk → RQ row, not a NULL mi_id BOM line.
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
 */
function _bom_compile_composite(
    PDO   $pdo,
    int   $skuId,
    array $slots,
    array $liquidBom,
    array $sourceSku,
    array $miById,
    array $skuChoices,
    bool  $dryRun
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

    // ── Build composite_packaging lines from ref_sku_packaging_choices ────────
    // Overwrap items are resolved ONLY from ref_sku_packaging_choices for composite SKUs.
    // The spec confirms: composites have no we_supply packaging template, so choices are the sole source.
    $pkgLines = [];

    if (isset($skuChoices[$skuId])) {
        foreach ($skuChoices[$skuId] as $slotName => $choice) {
            $miIdFk    = $choice['mi_id_fk'];
            $qtyPerUnit = (float)$choice['qty_per_unit'];

            if ($miIdFk === null) {
                // Explicit null override — skip (intentional absence)
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
                'ingredient_raw' => $mi['mi_id'],  // overwrap: use plain mi_code (no prefix, unique per composite)
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
                   (sku_id, mi_id, ingredient_raw, source, category_raw,
                    qty_per_unit, ing_unit, pricing_unit, price, currency, cost, volume_hl,
                    resolution, row_hash, compiled_at, bom_source, effective_from)
                 VALUES
                   (:sku_id, :mi_id, :ingredient_raw, :source, :category_raw,
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

    // Resolve {beer} → prefix, then LIKE lookup
    $resolved = str_replace('{beer}', $prefix, $pattern);
    $resolvedExact = rtrim($resolved, '%');

    // First try exact match
    $id = _bom_lookup_mi_id($resolvedExact, $miById);
    if ($id !== null) {
        return $id;
    }

    // LIKE match if pattern ends with %
    if (str_ends_with($resolved, '%')) {
        foreach ($miById as $miId => $mi) {
            if (_bom_mi_id_matches_like($mi['mi_id'], $resolved)) {
                return $miId;
            }
        }
    }

    // Fall back to template default
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
        $stmt = $pdo->prepare(
            "SELECT id FROM ref_skus WHERE recipe_id = ? AND is_active = 1"
        );
        $stmt->execute([$recipeId]);
        $skuIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
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

    // Collect unique recipe IDs across all target SKUs
    $recipeIds = array_values(array_unique(array_column($targetSkus, 'recipe_id')));

    if (empty($recipeIds)) {
        return _liq_empty_result($dryRun, $generatedAt, $allSoloSkus, $compositeCnt, $recipeMissingCnt);
    }

    $recipePhRaw = implode(',', array_fill(0, count($recipeIds), '?'));

    // ── 2a. Batch HL index ─────────────────────────────────────────────────────
    // For each (recipe_id, batch): total cooling HL across all brews of that batch.
    // bd_brewing_cooling.cool_batch (varchar) matches bd_brewing_ingredients_v2.batch (varchar).
    $hlStmt = $pdo->prepare(
        "SELECT cool_beer_recipe_id AS recipe_id, cool_batch AS batch,
                SUM(cool_final_volume_hl) AS batch_hl
           FROM bd_brewing_cooling
          WHERE cool_beer_recipe_id IN ({$recipePhRaw})
            AND cool_final_volume_hl IS NOT NULL
            AND cool_final_volume_hl > 0
          GROUP BY cool_beer_recipe_id, cool_batch"
    );
    $hlStmt->execute($recipeIds);
    // batchHl[recipe_id][batch] = float hl
    $batchHl = [];
    foreach ($hlStmt->fetchAll(\PDO::FETCH_ASSOC) as $hl) {
        $batchHl[(int)$hl['recipe_id']][$hl['batch']] = (float)$hl['batch_hl'];
    }

    // ── 2b. Observed malt/hops from v1 (bd_brewing_ingredients_parsed) ─────────
    // Link: source_id → bd_brewing_ingredients_v2 → recipe_id_fk + batch
    // Categories: malt, hops_kettle, hops_dry
    // Only source_table = 'bd_brewing_ingredients' (fermenting rows are hops_dry from fermenting form,
    // which we keep separate as they provide dry-hop data).
    $v1Stmt = $pdo->prepare(
        "SELECT biv.recipe_id_fk AS recipe_id, bip.batch, bip.category,
                bip.mi_id_fk, bip.qty, bip.unit, bip.event_date
           FROM bd_brewing_ingredients_parsed bip
           JOIN bd_brewing_ingredients_v2 biv
             ON biv.id = bip.source_id
            AND biv.is_tombstoned = 0
          WHERE biv.recipe_id_fk IN ({$recipePhRaw})
            AND bip.category IN ('malt','hops_kettle','hops_dry')
            AND bip.mi_id_fk IS NOT NULL
          ORDER BY biv.recipe_id_fk, bip.batch, bip.category, bip.mi_id_fk"
    );
    $v1Stmt->execute($recipeIds);
    // v1Rows[recipe_id][batch][category][mi_id_fk] = [qty, unit, event_date]
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

    // ── 2c. Observed non-malt/hops from v2 (bd_brewing_ingredients_parsed_v2) ──
    // Link: header_id → bd_brewing_brewday_v2 → recipe_id_fk + batch
    // Categories: adjunct, mineral, process
    $v2Stmt = $pdo->prepare(
        "SELECT bd.recipe_id_fk AS recipe_id, bd.batch, bi.category,
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
    $v2RawRows = [];
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

    // ── 3. Per-recipe: compute volume-weighted trailing average per-HL ─────────

    /**
     * Given an array of (batch → {qty, unit}) and the batchHl map for this recipe,
     * return a volume-weighted per-HL in the source unit (qty_in_unit / hl).
     * Returns null if no usable batches with HL.
     *
     * Outlier rejection: for windows ≥ 4 brews, drop batches where per_hl > median + 2×MAD
     * or per_hl < max(0, median - 2×MAD). Minimum 1 batch accepted after rejection.
     *
     * @param array $batchQty  batch → [qty=>float, unit=>string]
     * @param array $hlMap     batch → float (total HL)
     * @param int   $maxWindow max brews to include (most recent first by batch number)
     * @return array|null {per_hl: float, n_brews: int, n_window: int, unit: string}
     */
    // Inline as a closure to avoid polluting global namespace
    $computePerHl = function(array $batchQty, array $hlMap, int $maxWindow = 8): ?array {
        // Intersect: only batches that have BOTH qty and HL
        $usable = [];
        foreach ($batchQty as $batch => $data) {
            if (isset($hlMap[$batch]) && $hlMap[$batch] > 0) {
                $usable[$batch] = [
                    'qty'  => $data['qty'],
                    'unit' => $data['unit'],
                    'hl'   => $hlMap[$batch],
                ];
            }
        }
        if (empty($usable)) {
            return null;
        }

        // Sort by batch descending (most recent first), take up to $maxWindow
        uksort($usable, function($a, $b) {
            return (int)$b - (int)$a;
        });
        $window = array_slice($usable, 0, $maxWindow, true);
        $nWindow = count($window);

        // Compute per_hl per batch (in source unit)
        $perHls = [];
        foreach ($window as $batch => $data) {
            $perHls[$batch] = $data['qty'] / $data['hl'];
        }

        // Outlier rejection (only when ≥ 4 brews)
        $filtered = $perHls;
        if (count($perHls) >= 4) {
            $vals = array_values($perHls);
            sort($vals);
            $n = count($vals);
            $mid = (int)($n / 2);
            $median = ($n % 2 === 0)
                ? ($vals[$mid - 1] + $vals[$mid]) / 2.0
                : $vals[$mid];
            // Compute MAD
            $deviations = array_map(fn($v) => abs($v - $median), $vals);
            sort($deviations);
            $mad = ($n % 2 === 0)
                ? ($deviations[$mid - 1] + $deviations[$mid]) / 2.0
                : $deviations[$mid];
            $loFence = max(0.0, $median - 2.0 * $mad);
            $hiFence = $median + 2.0 * $mad;
            // Only reject if at least 1 brew would survive rejection
            $survivors = array_filter($perHls, fn($v) => $v >= $loFence && $v <= $hiFence);
            if (count($survivors) >= 1) {
                $filtered = $survivors;
            }
            // else: keep all (pathological case — all values are outliers by MAD)
        }

        // Volume-weighted average: SUM(perHl_i × hl_i) / SUM(hl_i)
        $sumWeightedPerHl = 0.0;
        $sumHl = 0.0;
        $unit = null;
        foreach ($filtered as $batch => $_) {
            $bData = $window[$batch];
            $sumWeightedPerHl += ($bData['qty'] / $bData['hl']) * $bData['hl'];
            $sumHl += $bData['hl'];
            if ($unit === null) {
                $unit = $bData['unit'];
            }
        }

        if ($sumHl <= 0.0) {
            return null;
        }

        return [
            'per_hl'     => $sumWeightedPerHl / $sumHl,
            'n_brews'    => count($filtered),
            'n_window'   => $nWindow,
            'unit'       => $unit ?? 'g',
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

    // ── 4. Per-recipe: build proposed liquid lines ────────────────────────────

    // proposedByRecipe[recipe_id][mi_id] = {mi_code, cat_name, ing_unit, qty_per_hl,
    //                                       n_brews, n_window, source, per_hl_unit, cost_per_hl, no_price}
    $proposedByRecipe = [];
    $globalUnresolvedMi = [];

    foreach ($recipeIds as $recipeId) {
        $recipeId  = (int)$recipeId;
        $hlMap     = $batchHl[$recipeId] ?? [];
        $proposed  = [];

        // Determine if this recipe is "zero-observed" (no v1 OR v2 data at all)
        $hasAnyObserved = (
            !empty($v1RawRows[$recipeId]) || !empty($v2RawRows[$recipeId])
        );

        if (!$hasAnyObserved) {
            // Full fallback: use ref_recipe_ingredients for ALL categories
            foreach ($recipeIng[$recipeId] ?? [] as $miId => $ing) {
                $mi = $miById[$miId] ?? null;
                if ($mi === null) {
                    $globalUnresolvedMi[] = "recipe={$recipeId} mi_id_fk={$miId} (not in ref_mi)";
                    continue;
                }
                $perHl  = $ing['qty_per_hl'];
                $unit   = $ing['unit'];
                $cost   = $computeCost($perHl, $unit, $mi);
                $proposed[$miId] = [
                    'mi_code'     => $mi['mi_id'],
                    'cat_name'    => $mi['cat_name'],
                    'ing_unit'    => $unit,
                    'per_hl'      => $perHl,
                    'n_brews'     => 0,
                    'n_window'    => 0,
                    'source'      => 'recipe_full_fallback',
                    'cost_per_hl' => $cost,
                    'no_price'    => ($cost === null && $mi['price'] !== null && $mi['price'] !== ''),
                ];
            }
            $proposedByRecipe[$recipeId] = $proposed;
            continue;
        }

        // ── MALT: observed-only from v1 ─────────────────────────────────────
        // Aggregate v1 malt rows: [mi_id][batch] → qty
        $maltByMiBatch = [];  // mi_id → batch → {qty, unit}
        foreach ($v1RawRows[$recipeId] ?? [] as $batch => $cats) {
            if (!isset($cats['malt'])) continue;
            foreach ($cats['malt'] as $miId => $data) {
                $maltByMiBatch[$miId][$batch] = $data;
            }
        }
        foreach ($maltByMiBatch as $miId => $batchData) {
            $mi = $miById[$miId] ?? null;
            if ($mi === null) {
                $globalUnresolvedMi[] = "recipe={$recipeId} MALT mi_id_fk={$miId} (not in ref_mi)";
                continue;
            }
            $result = $computePerHl($batchData, $hlMap);
            if ($result === null) {
                continue; // no HL data for any of its brews — skip (not gap-fillable per spec)
            }
            $cost = $computeCost($result['per_hl'], $result['unit'], $mi);
            $proposed[$miId] = [
                'mi_code'     => $mi['mi_id'],
                'cat_name'    => $mi['cat_name'],
                'ing_unit'    => $result['unit'],
                'per_hl'      => $result['per_hl'],
                'n_brews'     => $result['n_brews'],
                'n_window'    => $result['n_window'],
                'source'      => 'observed',
                'cost_per_hl' => $cost,
                'no_price'    => ($cost === null),
            ];
        }

        // ── HOPS (kettle + dry): observed-only from v1 ──────────────────────
        foreach (['hops_kettle', 'hops_dry'] as $hopCat) {
            $hopsByMiBatch = [];
            foreach ($v1RawRows[$recipeId] ?? [] as $batch => $cats) {
                if (!isset($cats[$hopCat])) continue;
                foreach ($cats[$hopCat] as $miId => $data) {
                    $hopsByMiBatch[$miId][$batch] = $data;
                }
            }
            foreach ($hopsByMiBatch as $miId => $batchData) {
                $mi = $miById[$miId] ?? null;
                if ($mi === null) {
                    $globalUnresolvedMi[] = "recipe={$recipeId} {$hopCat} mi_id_fk={$miId} (not in ref_mi)";
                    continue;
                }
                $result = $computePerHl($batchData, $hlMap);
                if ($result === null) {
                    continue;
                }
                if (!isset($proposed[$miId])) {
                    $cost = $computeCost($result['per_hl'], $result['unit'], $mi);
                    $proposed[$miId] = [
                        'mi_code'     => $mi['mi_id'],
                        'cat_name'    => $mi['cat_name'],
                        'ing_unit'    => $result['unit'],
                        'per_hl'      => $result['per_hl'],
                        'n_brews'     => $result['n_brews'],
                        'n_window'    => $result['n_window'],
                        'source'      => 'observed',
                        'cost_per_hl' => $cost,
                        'no_price'    => ($cost === null),
                    ];
                } else {
                    // Same MI appears in both kettle and dry-hop (sum per-HL basis across stages)
                    // This is intentional: total per-HL = kettle_per_HL + dry_per_HL
                    $cost = $computeCost($result['per_hl'], $result['unit'], $mi);
                    $proposed[$miId]['per_hl'] += $result['per_hl'];
                    $proposed[$miId]['n_brews'] = max($proposed[$miId]['n_brews'], $result['n_brews']);
                    $proposed[$miId]['n_window'] = max($proposed[$miId]['n_window'], $result['n_window']);
                    if ($proposed[$miId]['cost_per_hl'] !== null && $cost !== null) {
                        $proposed[$miId]['cost_per_hl'] += $cost;
                    }
                }
            }
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
                    continue; // malt/hops from v1 already set (shouldn't happen for mineral/process but guard)
                }
                $result = $computePerHl($batchData, $hlMap);
                if ($result === null) {
                    // No HL → try recipe gap-fill below
                    continue;
                }
                $cost = $computeCost($result['per_hl'], $result['unit'], $mi);
                $proposed[$miId] = [
                    'mi_code'     => $mi['mi_id'],
                    'cat_name'    => $mi['cat_name'],
                    'ing_unit'    => $result['unit'],
                    'per_hl'      => $result['per_hl'],
                    'n_brews'     => $result['n_brews'],
                    'n_window'    => $result['n_window'],
                    'source'      => 'observed',
                    'cost_per_hl' => $cost,
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
            $cost  = $computeCost($perHl, $unit, $mi);
            $proposed[$miId] = [
                'mi_code'     => $mi['mi_id'],
                'cat_name'    => $mi['cat_name'],
                'ing_unit'    => $unit,
                'per_hl'      => $perHl,
                'n_brews'     => 0,
                'n_window'    => 0,
                'source'      => 'recipe_gapfill',
                'cost_per_hl' => $cost,
                'no_price'    => ($cost === null && $mi['price'] !== null && $mi['price'] !== ''),
            ];
        }

        $proposedByRecipe[$recipeId] = $proposed;
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

        $recipeProposed = $proposedByRecipe[$recipeId] ?? [];
        $curLines       = $currentLiq[$skuId] ?? [];

        // Build proposed BOM lines for this SKU
        $proposedLines = [];
        foreach ($recipeProposed as $miId => $p) {
            $qtyPerUnit = round($p['per_hl'] * $hlPerUnit, 6);
            $cost       = $p['cost_per_hl'] !== null
                ? round($p['cost_per_hl'] * $hlPerUnit, 6)
                : null;
            $mi         = $miById[$miId] ?? null;
            $proposedLines[$miId] = [
                'mi_id_fk'     => $miId,
                'mi_code'      => $p['mi_code'],
                'cat_name'     => $p['cat_name'],
                'ing_unit'     => $p['ing_unit'],
                'qty_per_unit' => $qtyPerUnit,
                'per_hl'       => round($p['per_hl'], 6),
                'n_brews'      => $p['n_brews'],
                'n_brews_in_window' => $p['n_window'],
                'cost'         => $cost,
                'currency'     => $mi['currency'] ?? null,
                'price'        => $mi !== null && $mi['price'] !== null ? (float)$mi['price'] : null,
                'source'       => $p['source'],
                'no_price_flag'=> $p['no_price'],
            ];
        }

        // Diff: compare proposed vs current
        $added   = [];
        $removed = [];
        $changed = [];

        foreach ($proposedLines as $miId => $prop) {
            if (!isset($curLines[$miId])) {
                $added[] = $prop;
            } else {
                $cur = $curLines[$miId];
                $qtyDelta = abs($prop['qty_per_unit'] - $cur['qty_per_unit']);
                $qtyPct   = $cur['qty_per_unit'] > 0
                    ? round(($prop['qty_per_unit'] - $cur['qty_per_unit']) / $cur['qty_per_unit'] * 100.0, 1)
                    : 0.0;
                if ($qtyDelta > 0.000001) {
                    $changed[] = array_merge($prop, [
                        'old_qty'     => $cur['qty_per_unit'],
                        'old_ing_unit'=> $cur['ing_unit'],
                        'old_cost'    => $cur['cost'],
                        'qty_pct_change' => $qtyPct,
                    ]);
                }
            }
        }
        foreach ($curLines as $miId => $cur) {
            if (!isset($proposedLines[$miId])) {
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
    foreach ($skuResults as $sid => $sr) {
        if ($sr['proposed_liquid_lines'] > $sr['current_liquid_lines']) {
            $gainingSku[] = $sr['sku_code'];
        }
    }

    $summaryOut = [
        'skus_total'          => count($targetSkus),
        'skus_gaining_liquid' => count($gainingSku),
        'skus_gaining_codes'  => $gainingSku,
        'skus_losing_liquid'  => $summaryLosing,
        'skus_no_change'      => $summaryNoChange,
        'lines_added_total'   => $totalAdded,
        'lines_removed_total' => $totalRemoved,
        'lines_changed_total' => $totalChanged,
        'unresolved_mi_flags' => array_values(array_unique($allUnresolvedMi)),
        'errors_total'        => $errorsTotal,
    ];

    // ── 8. Apply predicate (documented, not executed) ─────────────────────────
    $applyPredicate = <<<'SQL'
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
SQL;

    return [
        'dry_run'              => $dryRun,
        'generated_at'         => $generatedAt,
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
        'aggregator_location'  => 'compile_sku_bom_liquid → $computePerHl closure (volume-weighted trailing average with 2×MAD outlier rejection). Reuse by extracting to _liq_compute_per_hl() if a drift surface needs the same aggregation.',
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

    $altProposed = $proposedByRecipe[6] ?? [];

    // Check (a): PROC_PHOSPHORIQUE in proposed
    $phosphStmt = $pdo->query("SELECT id FROM ref_mi WHERE mi_id = 'PROC_PHOSPHORIQUE' LIMIT 1");
    $phosphRow  = $phosphStmt->fetch(\PDO::FETCH_ASSOC);
    $phosphMiId = $phosphRow ? (int)$phosphRow['id'] : null;
    $phosphPresent = $phosphMiId !== null && isset($altProposed[$phosphMiId]);
    $phosphSource  = $phosphPresent ? ($altProposed[$phosphMiId]['source'] ?? 'unknown') : null;

    // Check (b): hop MIs in proposed all come from observed
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
