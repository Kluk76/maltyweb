<?php
declare(strict_types=1);

/**
 * app/sku-bom-compile.php
 *
 * PACKAGING-ONLY ref_sku_bom recompute service.
 *
 * Rebuilds ONLY the source='Packaging' lines for each affected SKU.
 * Liquid lines (source='Brewing' / 'Fermenting' / etc.) are left byte-identical.
 * A hard liquid-parity gate aborts the transaction if any liquid row is changed.
 *
 * Resolve precedence per slot:
 *   1. ref_sku_packaging_choices   (SKU-level override — empty today)
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
 *     - If scotch binding resolves to PKG_SCOTCH_TRANSP (id=179): sticker slot REQUIRED.
 *     - If scotch binding resolves to a branded PKG_SCOTCH_[beer]: sticker INTENTIONALLY ABSENT (no line, no RQ).
 *     - If scotch UNRESOLVED: scotch → RQ row; sticker also unresolved → RQ row.
 *
 * Safe-delete predicate: DELETE WHERE sku_id IN (:affected) AND source='Packaging'
 * (never touches source='Brewing' or source='mi_match' rows).
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

    // ── 2. Load SKU metadata ─────────────────────────────────────────────────

    $placeholders = implode(',', array_fill(0, count($skuIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT s.id, s.sku_code, s.format_id, s.recipe_id, s.hl_per_unit,
                f.format_code,
                r.sku_prefix, r.uses_branded_scotch,
                bt.decoration_integral, bt.supply
           FROM ref_skus s
           JOIN ref_packaging_formats f ON f.id = s.format_id
           JOIN ref_recipes r           ON r.id = s.recipe_id
           JOIN ref_packaging_bom_templates bt
             ON bt.format_id = s.format_id
            AND bt.supply = 'we_supply'
            AND bt.is_active = 1
          WHERE s.id IN ({$placeholders})
          ORDER BY s.sku_code"
    );
    $stmt->execute($skuIds);
    $skuRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Index by sku_id
    $skuIndex = [];
    foreach ($skuRows as $row) {
        $skuIndex[(int)$row['id']] = $row;
    }

    // Warn about any requested sku_ids that didn't resolve
    $missingIds = array_diff($skuIds, array_keys($skuIndex));

    // ── 3. Pre-load shared lookup data ──────────────────────────────────────

    // ref_mi: id → {mi_id, name, category_id, price, currency, pricing_unit}
    $allMiStmt = $pdo->query(
        "SELECT m.id, m.mi_id, m.name, c.name AS cat_name,
                m.price, m.currency, m.pricing_unit
           FROM ref_mi m
           JOIN ref_mi_categories c ON c.id = m.category_id"
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

    // ── 4. Process each SKU ─────────────────────────────────────────────────

    $summary = [];
    $totalPkgDeleted  = 0;
    $totalPkgInserted = 0;
    $totalRqEmitted   = 0;
    $totalParityViol  = 0;
    $totalErrors      = 0;

    foreach ($skuIds as $skuId) {
        $skuId = (int)$skuId;

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

        $pkgLines = [];   // rows to INSERT: ['mi_id_fk' => int, 'slot_name' => str, 'qty' => float]
        $rqRows   = [];   // rows to emit in doc_review_queue

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
                $pkgLines[] = [
                    'mi_id_fk'  => $resolved,
                    'slot_name' => $slotName,
                    'qty'       => $qty,
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
                    "DELETE FROM ref_sku_bom WHERE sku_id = ? AND source = 'Packaging'"
                );
                $del->execute([$skuId]);
                $pkgDeleted = $del->rowCount();

                // ── Step 3 insert resolved packaging rows ─────────────────
                $compiledAt = gmdate('Y-m-d H:i:s');
                $today      = date('Y-m-d');

                $ins = $pdo->prepare(
                    "INSERT INTO ref_sku_bom
                       (sku_id, mi_id, ingredient_raw, source, category_raw,
                        qty_per_unit, ing_unit, pricing_unit, price, currency, cost,
                        resolution, row_hash, compiled_at, bom_source, effective_from)
                     VALUES
                       (:sku_id, :mi_id, :ingredient_raw, :source, :category_raw,
                        :qty_per_unit, :ing_unit, :pricing_unit, :price, :currency, :cost,
                        :resolution, :row_hash, :compiled_at, :bom_source, :effective_from)"
                );

                foreach ($pkgLines as $line) {
                    $mi = $miById[$line['mi_id_fk']] ?? null;
                    $miCode   = $mi['mi_id']       ?? '';
                    $catName  = $mi['cat_name']    ?? 'Packaging';
                    $price    = $mi !== null && $mi['price'] !== null ? (float)$mi['price'] : null;
                    $currency = $mi['currency']    ?? null;
                    $pricingUnit = $mi['pricing_unit'] ?? null;

                    // cost = price × qty (NULL if price unknown)
                    $cost = ($price !== null) ? round($price * $line['qty'], 6) : null;

                    // row_hash: stable content key (sku_id, mi_id_fk, slot_name, qty, effective_from)
                    $rowHash = hash('sha256', implode('|', [
                        $skuId,
                        $line['mi_id_fk'],
                        $line['slot_name'],
                        round($line['qty'], 6),
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
                        ':price'          => $price,
                        ':currency'       => $currency,
                        ':cost'           => $cost,
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
 * Count existing packaging rows for a SKU (for dry-run reporting).
 */
function _bom_count_packaging(PDO $pdo, int $skuId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM ref_sku_bom WHERE sku_id = ? AND source = 'Packaging'"
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
