<?php
declare(strict_types=1);

/**
 * repack.php — Pure auto-decomposition engine for eshop bundle sales.
 *
 * Public entry point:
 *   repack_decompose_orders(PDO $pdo, string $date): array
 *
 * Given a date, queries all eshop orders for that date (by DATE(created_at))
 * and decomposes each bundle line into proposed base-box-open + loose-remainder
 * consumption rows. Returns an array of proposal rows; makes NO writes to
 * canonical tables except the refuse-don't-NULL ReviewQueue path (one RQ row
 * per unresolvable bundle, idempotent via dedup_key).
 *
 * Output row shape:
 *   {
 *     source_order_id int,
 *     site_id         int,
 *     from_sku_id     int,
 *     from_sku_code   string,
 *     from_qty        int,       // boxes opened (CEIL of component_bottles / base.units_per_pack)
 *     to_kind         string,    // 'bundle'|'pd8'|'loose'|'adjustment'
 *     to_sku_id       int,       // the bundle or composite SKU id
 *     to_sku_code     string,
 *     to_qty          int,       // qty of the bundle/composite sold (order line qty)
 *     component_bottles int,     // bottles consumed by this line = units_per_pack × order_qty
 *     loose_units     int,       // from_qty × base.units_per_pack − component_bottles (≥ 0)
 *     balanced        bool,      // loose_units === 0
 *   }
 *
 * Consumed by:
 *   - public/api/expeditions-repack.php (operator confirm endpoint, Phase A)
 *   - public/modules/expeditions.php (preview panel, future)
 *
 * NOTE: doc_review_queue.type ENUM must include 'repack-unresolved-bundle'.
 * Migration db/migrations/337_rq_repack_unresolved_bundle.sql must be applied
 * before the refuse-don't-NULL path can emit rows. If the ENUM is not yet
 * extended, the INSERT will be silently skipped (PDO::ERRMODE_EXCEPTION catches
 * the HY000 but we log it without crashing the proposal loop).
 *
 * Base-box disambiguation rule (format prefix):
 *   For single-recipe bundles with multiple scope=base candidates sharing the
 *   same (recipe_id, run_type), the function tries to pick the unique base box
 *   whose format_code is a prefix of the bundle's format_code (e.g. B12→B, 4PB→4,
 *   4C→4C). If the prefix rule yields exactly one match it is used; if it yields
 *   0 or >1 matches the bundle is treated as unresolvable → RQ row.
 *
 * COGS note: this function produces UNIT proposals only. Phase B (cage/COGS costing)
 * is gated on 2026-06-15 cage anchor and handled elsewhere.
 */

require_once __DIR__ . '/fulfilment-site.php';

/**
 * Decompose a day's eshop bundle sales into proposed base-box-open rows.
 *
 * @param  PDO    $pdo  Live maltytask PDO connection.
 * @param  string $date ISO date 'YYYY-MM-DD'.
 * @return array        Array of proposal rows (see module docblock for shape).
 */
function repack_decompose_orders(PDO $pdo, string $date): array
{
    // ── 0. Validate input ────────────────────────────────────────────────────
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new \InvalidArgumentException("repack_decompose_orders: date must be YYYY-MM-DD, got '$date'");
    }

    // ── 1. Load all eshop orders + lines for the day ─────────────────────────
    $orderRows = _repack_load_orders($pdo, $date);
    if (empty($orderRows)) {
        return [];
    }

    // ── 2. Load ref data: SKUs, formats, composite slots, aliases ───────────
    $skuById       = _repack_load_skus($pdo);
    $formatByFmtId = _repack_load_formats($pdo);
    $slotsById     = _repack_load_composite_slots($pdo, $date);
    $aliasMap      = _repack_load_alias_map($pdo);    // alias → canonical_sku_id

    // ── 3. Decompose each order line ─────────────────────────────────────────
    $proposals = [];

    foreach ($orderRows as $line) {
        $orderId  = (int)$line['order_id'];
        $lineQty  = (int)round((float)$line['qty']);
        if ($lineQty <= 0) {
            continue;
        }

        $bundleSkuId   = $line['sku_id_fk'] !== null ? (int)$line['sku_id_fk'] : null;
        $bundleSkuCode = (string)$line['sku_code'];
        $fulfilMode    = (string)$line['fulfilment_mode'];

        // ── Composite alias resolution ────────────────────────────────────────
        // Resolve sku_code through ref_sku_aliases BEFORE the null-id guard.
        // This handles two overlapping cases:
        //   (a) sku_id_fk is NULL — legacy/discontinued codes (PACKDEC, PACKDECX8,
        //       FRPACKDEC, PAD …) that the order-import pipeline never linked to a
        //       ref_skus row.
        //   (b) sku_id_fk points to a legacy ref_skus row (recipe_id=NULL, no slots)
        //       whose sku_code is itself an alias for a canonical composite.
        // Both cases fold to the canonical sku_id via the alias varchar key.
        // $bundleSkuCode is intentionally kept as the original sold code for
        // traceability in proposals and RQ emissions.
        if (isset($aliasMap[$bundleSkuCode])) {
            $canonicalId = $aliasMap[$bundleSkuCode];
            if (isset($skuById[$canonicalId])) {
                $bundleSkuId = $canonicalId;
            }
        }

        // Resolve fulfilment site via canonical helper (once, correct channel).
        $siteId = resolve_fulfilment_site($pdo, [
            'channel' => $fulfilMode === 'pickup' ? 'taproom' : 'eshop',
        ]);

        // Must have a resolved sku_id_fk (after alias resolution above)
        if ($bundleSkuId === null || !isset($skuById[$bundleSkuId])) {
            _repack_emit_rq($pdo, $orderId, $bundleSkuCode, 'sku_id_fk missing or not in ref_skus');
            continue;
        }

        $bundleSku = $skuById[$bundleSkuId];

        // Detect composite vs single-recipe
        if (isset($slotsById[$bundleSkuId]) && count($slotsById[$bundleSkuId]) > 0) {
            // ── PD8 / composite path ──────────────────────────────────────
            $slots = $slotsById[$bundleSkuId];
            $fanRows = _repack_decompose_composite(
                $pdo, $orderId, $siteId,
                $bundleSkuId, $bundleSkuCode, $lineQty,
                $slots, $skuById, $formatByFmtId
            );
            if ($fanRows === null) {
                // _repack_decompose_composite already emitted RQ rows for failures
                continue;
            }
            foreach ($fanRows as $r) {
                $proposals[] = $r;
            }
        } else {
            // ── Single-recipe bundle path ─────────────────────────────────
            $row = _repack_decompose_single(
                $pdo, $orderId, $siteId,
                $bundleSkuId, $bundleSkuCode, $lineQty,
                $bundleSku, $skuById, $formatByFmtId
            );
            if ($row === null) {
                // _repack_decompose_single emitted RQ row for failures
                continue;
            }
            $proposals[] = $row;
        }
    }

    return $proposals;
}

// ────────────────────────────────────────────────────────────────────────────
// Internal helpers
// ────────────────────────────────────────────────────────────────────────────

/**
 * Load all eshop order lines for the given date.
 * Returns array of rows with order metadata joined.
 *
 * @internal
 * @return array<int, array{order_id:string, fulfilment_mode:string, sku_id_fk:string|null, sku_code:string, qty:string}>
 */
function _repack_load_orders(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare(
        "SELECT so.id          AS order_id,
                so.fulfilment_mode,
                sol.sku_id_fk,
                sol.sku_code,
                sol.qty
           FROM inv_sales_orders so
           JOIN inv_sales_order_lines sol ON sol.order_id_fk = so.id
          WHERE so.channel = 'eshop'
            AND DATE(so.created_at) = ?
          ORDER BY so.id, sol.line_index"
    );
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Load all active ref_skus with their packaging format run_type.
 * Keyed by sku id (int).
 *
 * @internal
 * @return array<int, array{id:int, sku_code:string, recipe_id:int|null, format_id:int|null, format_code:string, run_type:string, units_per_pack:float, stocktake_scope:string}>
 */
function _repack_load_skus(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT rs.id, rs.sku_code, rs.recipe_id, rs.format_id,
                COALESCE(rpf.format_code, '') AS format_code,
                COALESCE(rpf.run_type, '')    AS run_type,
                rs.units_per_pack,
                rs.stocktake_scope
           FROM ref_skus rs
           LEFT JOIN ref_packaging_formats rpf ON rpf.id = rs.format_id
          WHERE rs.is_active = 1"
    );
    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$r['id'];
        $rows[$id] = [
            'id'              => $id,
            'sku_code'        => $r['sku_code'],
            'recipe_id'       => $r['recipe_id'] !== null ? (int)$r['recipe_id'] : null,
            'format_id'       => $r['format_id'] !== null ? (int)$r['format_id'] : null,
            'format_code'     => $r['format_code'],
            'run_type'        => $r['run_type'],
            'units_per_pack'  => (float)$r['units_per_pack'],
            'stocktake_scope' => $r['stocktake_scope'],
        ];
    }
    return $rows;
}

/**
 * Load all ref_packaging_formats keyed by id.
 *
 * @internal
 * @return array<int, array{id:int, format_code:string, run_type:string, is_composite:bool}>
 */
function _repack_load_formats(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id, format_code, COALESCE(run_type,'') AS run_type, is_composite
           FROM ref_packaging_formats"
    );
    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$r['id'];
        $rows[$id] = [
            'id'           => $id,
            'format_code'  => $r['format_code'],
            'run_type'     => $r['run_type'],
            'is_composite' => (bool)(int)$r['is_composite'],
        ];
    }
    return $rows;
}

/**
 * Load all composite slots active on $date, keyed by sku_id.
 * Each value is an array of slot rows.
 *
 * @internal
 * @return array<int, list<array{recipe_id:int, units_per_recipe:int, member_format_id:int|null}>>
 */
function _repack_load_composite_slots(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare(
        "SELECT sku_id, recipe_id, units_per_recipe, member_format_id, slot_order
           FROM ref_sku_composite_slots
          WHERE (effective_until IS NULL OR effective_until >= ?)
          ORDER BY sku_id, slot_order"
    );
    $stmt->execute([$date]);
    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $skuId = (int)$r['sku_id'];
        $rows[$skuId][] = [
            'recipe_id'       => (int)$r['recipe_id'],
            'units_per_recipe' => (int)$r['units_per_recipe'],
            'member_format_id' => $r['member_format_id'] !== null ? (int)$r['member_format_id'] : null,
        ];
    }
    return $rows;
}

/**
 * Load a map of alias-sku-code → canonical-sku-id from ref_sku_aliases.
 *
 * Key: alias varchar (e.g. 'PACKDEC', 'PACKDECX8', 'FRPACKDEC').
 * Value: canonical_sku_id (int, FK to ref_skus.id).
 *
 * Keying on the varchar code (not the alias row's ref_skus.id) lets us resolve
 * order lines where sku_id_fk is NULL but sku_code is populated — the common
 * case for legacy/discontinued eshop codes that were never linked to a ref_skus
 * row in the order-import pipeline.
 *
 * @internal
 * @return array<string, int>  alias_code => canonical_sku_id
 */
function _repack_load_alias_map(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT alias, canonical_sku_id FROM ref_sku_aliases"
    );
    $map = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[$r['alias']] = (int)$r['canonical_sku_id'];
    }
    return $map;
}

/**
 * Decompose a single-recipe bundle line into one proposal row.
 *
 * Returns the proposal row on success, or null if unresolvable (RQ emitted).
 *
 * @internal
 */
function _repack_decompose_single(
    PDO    $pdo,
    int    $orderId,
    int    $siteId,
    int    $bundleSkuId,
    string $bundleSkuCode,
    int    $lineQty,
    array  $bundleSku,   // from _repack_load_skus
    array  $skuById,
    array  $formatByFmtId
): ?array {
    $recipeId    = $bundleSku['recipe_id'];
    $unitsPerPack = $bundleSku['units_per_pack'];
    $formatCode  = $bundleSku['format_code'];
    $runType     = $bundleSku['run_type'];

    // Must have recipe_id and units_per_pack > 0
    if ($recipeId === null) {
        _repack_emit_rq($pdo, $orderId, $bundleSkuCode, 'no recipe_id on bundle SKU');
        return null;
    }
    if ($unitsPerPack <= 0) {
        _repack_emit_rq($pdo, $orderId, $bundleSkuCode, 'units_per_pack is zero');
        return null;
    }
    if ($runType === '') {
        _repack_emit_rq($pdo, $orderId, $bundleSkuCode, 'format has no run_type');
        return null;
    }

    // Find base box = scope=base + same recipe_id + same run_type, with prefix tie-break
    $baseBox = _repack_find_base_box($recipeId, $runType, $formatCode, $skuById);
    if ($baseBox === null) {
        _repack_emit_rq(
            $pdo, $orderId, $bundleSkuCode,
            "base box ambiguous or missing for recipe=$recipeId run_type=$runType bundle_fmt=$formatCode"
        );
        return null;
    }

    $componentBottles = (int)round($unitsPerPack * $lineQty);
    $baseUnits        = (int)round($baseBox['units_per_pack']);
    if ($baseUnits <= 0) {
        _repack_emit_rq($pdo, $orderId, $bundleSkuCode, 'base box units_per_pack is zero');
        return null;
    }

    $fromQty    = (int)ceil($componentBottles / $baseUnits);
    $looseUnits = $fromQty * $baseUnits - $componentBottles;

    return [
        'source_order_id'   => $orderId,
        'site_id'           => $siteId,
        'from_sku_id'       => $baseBox['id'],
        'from_sku_code'     => $baseBox['sku_code'],
        'from_qty'          => $fromQty,
        'to_kind'           => 'bundle',
        'to_sku_id'         => $bundleSkuId,
        'to_sku_code'       => $bundleSkuCode,
        'to_qty'            => $lineQty,
        'component_bottles' => $componentBottles,
        'loose_units'       => $looseUnits,
        'balanced'          => ($looseUnits === 0),
    ];
}

/**
 * Decompose a composite (PD8-style) bundle line into N fan-out proposal rows,
 * one per distinct base box. Returns array of rows on success, or null if any
 * slot fails (RQ emitted per failing slot).
 *
 * @internal
 */
function _repack_decompose_composite(
    PDO    $pdo,
    int    $orderId,
    int    $siteId,
    int    $compositeSkuId,
    string $compositeSkuCode,
    int    $lineQty,
    array  $slots,     // from _repack_load_composite_slots
    array  $skuById,
    array  $formatByFmtId
): ?array {
    $fanRows   = [];
    $anyFail   = false;

    // Aggregate: multiple slots may share the same base box.
    // Key: base_sku_id → accumulated component_bottles for that base box.
    $baseAccumulator = []; // base_sku_id => ['base' => ..., 'component_bottles' => int]

    foreach ($slots as $slot) {
        $slotRecipeId   = $slot['recipe_id'];
        $slotMultiple   = $slot['units_per_recipe']; // bottles per composite sold
        $memberFmtId    = $slot['member_format_id'];

        // Determine run_type for this slot from member_format_id
        $slotRunType = '';
        if ($memberFmtId !== null && isset($formatByFmtId[$memberFmtId])) {
            $fmt = $formatByFmtId[$memberFmtId];
            // Composite format (e.g. PD8 format_id=19 run_type='') → infer from slot's recipe
            // We need the actual base box run_type — use 'bot' as default for bottle composites,
            // but derive it properly by looking at what base boxes exist for this recipe.
            $slotRunType = $fmt['run_type'];
        }

        // For composites like PD8 whose member_format_id is itself a composite format
        // (run_type=''), we must find the run_type by inspecting what scope=base SKUs
        // the recipe has. We take the unique run_type if there is one; if there are multiple,
        // we cannot proceed.
        if ($slotRunType === '') {
            $slotRunType = _repack_infer_run_type_for_recipe($slotRecipeId, $skuById);
            if ($slotRunType === null) {
                _repack_emit_rq(
                    $pdo, $orderId, $compositeSkuCode,
                    "slot recipe=$slotRecipeId has ambiguous or no run_type for base box lookup"
                );
                $anyFail = true;
                continue;
            }
        }

        // We don't have a bundle format_code for the slot (it's inside a composite).
        // Pass empty string → prefix tie-break will still work if only one base box exists;
        // if ambiguous, we must refuse.
        $baseBox = _repack_find_base_box($slotRecipeId, $slotRunType, '', $skuById);
        if ($baseBox === null) {
            _repack_emit_rq(
                $pdo, $orderId, $compositeSkuCode,
                "slot recipe=$slotRecipeId run_type=$slotRunType: base box ambiguous or missing"
            );
            $anyFail = true;
            continue;
        }

        $slotBottles = $slotMultiple * $lineQty;
        $baseSkuId   = $baseBox['id'];

        if (!isset($baseAccumulator[$baseSkuId])) {
            $baseAccumulator[$baseSkuId] = [
                'base'              => $baseBox,
                'component_bottles' => 0,
            ];
        }
        $baseAccumulator[$baseSkuId]['component_bottles'] += $slotBottles;
    }

    if ($anyFail) {
        return null;
    }

    // Build fan-out rows
    foreach ($baseAccumulator as $baseSkuId => $acc) {
        $baseBox          = $acc['base'];
        $componentBottles = $acc['component_bottles'];
        $baseUnits        = (int)round($baseBox['units_per_pack']);

        if ($baseUnits <= 0) {
            _repack_emit_rq($pdo, $orderId, $compositeSkuCode, 'base box units_per_pack is zero');
            $anyFail = true;
            continue;
        }

        $fromQty    = (int)ceil($componentBottles / $baseUnits);
        $looseUnits = $fromQty * $baseUnits - $componentBottles;

        $fanRows[] = [
            'source_order_id'   => $orderId,
            'site_id'           => $siteId,
            'from_sku_id'       => $baseBox['id'],
            'from_sku_code'     => $baseBox['sku_code'],
            'from_qty'          => $fromQty,
            'to_kind'           => 'pd8',
            'to_sku_id'         => $compositeSkuId,
            'to_sku_code'       => $compositeSkuCode,
            'to_qty'            => $lineQty,
            'component_bottles' => $componentBottles,
            'loose_units'       => $looseUnits,
            'balanced'          => ($looseUnits === 0),
        ];
    }

    if ($anyFail) {
        return null;
    }

    return $fanRows;
}

/**
 * Find the unique scope=base SKU for a given (recipe_id, run_type) pair.
 *
 * If multiple candidates exist, applies the format prefix tie-break:
 *   Choose the base box whose format_code is a prefix of $bundleFormatCode.
 *   If exactly one prefix match → use it.
 *   If zero or multiple prefix matches → return null (ambiguous).
 *
 * @internal
 * @param  string $bundleFormatCode  Format code of the bundle (e.g. 'B12', '4PB', '12C').
 *                                   May be '' for composite slot paths where no bundle code exists.
 * @return array|null  sku row from _repack_load_skus, or null if unresolvable.
 */
function _repack_find_base_box(
    int    $recipeId,
    string $runType,
    string $bundleFormatCode,
    array  $skuById
): ?array {
    $candidates = [];
    foreach ($skuById as $sku) {
        if ($sku['recipe_id'] === $recipeId
            && $sku['stocktake_scope'] === 'base'
            && $sku['run_type'] === $runType) {
            $candidates[] = $sku;
        }
    }

    if (count($candidates) === 0) {
        return null;
    }
    if (count($candidates) === 1) {
        return $candidates[0];
    }

    // Multiple candidates — apply format prefix tie-break if bundleFormatCode is given
    if ($bundleFormatCode !== '') {
        $prefixMatches = array_filter(
            $candidates,
            fn($c) => $c['format_code'] !== '' && str_starts_with($bundleFormatCode, $c['format_code'])
        );
        $prefixMatches = array_values($prefixMatches);
        if (count($prefixMatches) === 1) {
            return $prefixMatches[0];
        }
        // 0 or >1 prefix matches → ambiguous
        return null;
    }

    // No bundle format code (composite slot path) — only safe if unique after run_type filter
    // (already checked above: count > 1 → ambiguous)
    return null;
}

/**
 * Infer run_type from the base boxes of a recipe (used for composite slot paths
 * where the member_format_id is a composite format with no run_type).
 *
 * Returns the run_type if all base boxes for this recipe share the same one,
 * or null if ambiguous / no base boxes.
 *
 * @internal
 */
function _repack_infer_run_type_for_recipe(int $recipeId, array $skuById): ?string
{
    $runTypes = [];
    foreach ($skuById as $sku) {
        if ($sku['recipe_id'] === $recipeId && $sku['stocktake_scope'] === 'base' && $sku['run_type'] !== '') {
            $runTypes[$sku['run_type']] = true;
        }
    }

    if (count($runTypes) === 0) {
        return null;
    }
    if (count($runTypes) === 1) {
        return array_key_first($runTypes);
    }

    // Multiple run types (e.g. a recipe with both bot and can base boxes) → ambiguous
    return null;
}

/**
 * Emit a 'repack-unresolved-bundle' RQ row for an unresolvable bundle.
 * Idempotent via dedup_key = "repack:{order_id}:{sku_code}".
 * Skips silently if the RQ row already exists as open/in_progress.
 *
 * NOTE: Requires doc_review_queue.type ENUM to include 'repack-unresolved-bundle'
 * (migration 337_rq_repack_unresolved_bundle.sql). If the ENUM is not yet extended,
 * the INSERT will throw and be caught here — the proposal loop continues.
 *
 * @internal
 */
function _repack_emit_rq(PDO $pdo, int $orderId, string $skuCode, string $reason): void
{
    $dedupKey = 'repack:' . $orderId . ':' . $skuCode;

    try {
        // Check for existing open/in_progress row
        $check = $pdo->prepare(
            "SELECT COUNT(*) FROM doc_review_queue
              WHERE dedup_key = ? AND status IN ('open','in_progress')"
        );
        $check->execute([$dedupKey]);
        if ((int)$check->fetchColumn() > 0) {
            return; // already open
        }

        $queueId = 'repack-' . $orderId . '-' . preg_replace('/[^A-Za-z0-9]/', '-', $skuCode);
        $context = json_encode([
            'order_id' => $orderId,
            'sku_code' => $skuCode,
            'reason'   => $reason,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ins = $pdo->prepare(
            "INSERT INTO doc_review_queue
               (queue_id, type, value, context, dedup_key, priority, status, decision)
             VALUES
               (:queue_id, 'repack-unresolved-bundle', :value, :context,
                :dedup_key, 30, 'open', 'pending')
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP"
        );
        $ins->execute([
            ':queue_id'  => $queueId,
            ':value'     => $skuCode,
            ':context'   => $context,
            ':dedup_key' => $dedupKey,
        ]);
    } catch (\Throwable $e) {
        // Do not crash the decomposition loop; log and continue.
        error_log('[repack] _repack_emit_rq failed for order=' . $orderId . ' sku=' . $skuCode . ': ' . $e->getMessage());
    }
}
