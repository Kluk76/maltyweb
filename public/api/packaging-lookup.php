<?php
declare(strict_types=1);
/**
 * GET /api/packaging-lookup.php
 *
 * Read-only packaging event lookup. Two modes:
 *   mode=day   &date=YYYY-MM-DD        — all events for a calendar day
 *   mode=batch &sku_id=N&batch=X       — all events for a SKU + lot
 *
 * Auth: require_page_access('packaging')  — GET, no CSRF needed.
 */

require __DIR__ . '/../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Auth ──────────────────────────────────────────────────────────────────────
require_page_access('packaging');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// ── Whitelisted modes ─────────────────────────────────────────────────────────
$allowedModes = ['day', 'batch', 'recent'];
$mode = $_GET['mode'] ?? '';
if (!in_array($mode, $allowedModes, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Paramètre mode invalide (day|batch requis).']);
    exit;
}

try {
    $pdo = maltytask_pdo();

    // ── B2: mode=recent — early return ────────────────────────────────────────
    if ($mode === 'recent') {
        $skuIdRaw = $_GET['sku_id'] ?? '';
        $skuId    = (int) $skuIdRaw;
        if ($skuId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'sku_id invalide (entier positif requis).']);
            exit;
        }

        // Human format labels resolved server-side — no DB nomenclature in UI.
        $runTypeLabels = [
            'bot'   => 'Bouteille',
            'can'   => 'Canette',
            'can33' => 'Canette',
            'keg'   => 'Fût / Keg',
            'cuv'   => 'Cuve de service',
        ];

        $recentStmt = $pdo->prepare(
            "SELECT p.id,
                    p.event_date,
                    COALESCE(NULLIF(p.neb_batch,''), NULLIF(p.contract_batch,'')) AS batch,
                    p.run_type,
                    rs.sku_code,
                    COALESCE(r.name, '') AS recipe_name,
                    p.prod_total_units,
                    COALESCE(rg.n_readings, 0) AS n_readings,
                    rg.avg_co2,
                    rg.avg_o2
               FROM bd_packaging_v2 p
               LEFT JOIN ref_skus    rs ON rs.id = p.sku_id_fk
               LEFT JOIN ref_recipes r  ON r.id  = p.recipe_id_fk
               LEFT JOIN (
                   SELECT packaging_v2_id,
                          COUNT(*)         AS n_readings,
                          ROUND(AVG(co2),3) AS avg_co2,
                          ROUND(AVG(o2), 2) AS avg_o2
                     FROM bd_packaging_readings
                    GROUP BY packaging_v2_id
               ) rg ON rg.packaging_v2_id = p.id
              WHERE p.sku_id_fk   = ?
                AND p.is_tombstoned = 0
              ORDER BY p.event_date DESC, p.id DESC
              LIMIT 10"
        );
        $recentStmt->execute([$skuId]);
        $runs = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($runs as &$run) {
            $rt = (string)($run['run_type'] ?? '');
            $run['format_human']     = $runTypeLabels[$rt] ?? strtoupper($rt);
            $run['n_readings']       = (int) $run['n_readings'];
            $run['prod_total_units'] = $run['prod_total_units'] !== null ? (int)$run['prod_total_units'] : null;
            $run['avg_co2']          = $run['avg_co2'] !== null ? (float)$run['avg_co2'] : null;
            $run['avg_o2']           = $run['avg_o2']  !== null ? (float)$run['avg_o2']  : null;
        }
        unset($run);

        echo json_encode([
            'ok'     => true,
            'sku_id' => $skuId,
            'runs'   => $runs,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }

    // ── $skuId initialised here so it is in scope for the batch response ─────
    $skuId = 0;

    // ── Build WHERE clause depending on mode ──────────────────────────────────
    if ($mode === 'day') {
        $dateRaw = trim($_GET['date'] ?? '');
        $dateParts = explode('-', $dateRaw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)
            || !checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Format date invalide (YYYY-MM-DD requis).']);
            exit;
        }
        $whereSql  = 'WHERE p.is_tombstoned = 0 AND p.event_date = ?';
        $whereArgs = [$dateRaw];

    } else {
        // mode=batch
        $skuIdRaw = $_GET['sku_id'] ?? '';
        $batchRaw = trim($_GET['batch'] ?? '');

        $skuId = (int) $skuIdRaw;
        if ($skuId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'sku_id invalide (entier positif requis).']);
            exit;
        }
        if ($batchRaw === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Paramètre batch manquant.']);
            exit;
        }
        $whereSql  = 'WHERE p.is_tombstoned = 0
                        AND p.sku_id_fk = ?
                        AND COALESCE(NULLIF(p.neb_batch,\'\'), NULLIF(p.contract_batch,\'\')) = ?';
        $whereArgs = [$skuId, $batchRaw];
    }

    // ── Main query ────────────────────────────────────────────────────────────
    $sql = "SELECT
              p.id,
              p.submitted_at,
              p.event_date,
              p.sku_id_fk,
              COALESCE(rs.sku_code, '(SKU manquant)') AS sku_code,
              COALESCE(rs.unit_label, '') AS unit_label,
              COALESCE(NULLIF(p.neb_batch,''), NULLIF(p.contract_batch,'')) AS batch,
              p.run_type,
              p.prod_total_units,
              p.recipe_id_fk,
              p.reuses_packaging_id_fk,
              r.name AS recipe_name,
              COALESCE(r.classification, 'Neb') AS classification,
              v.vendable_units,
              v.vendable_hl,
              v.beer_tax_base_hl,
              v.loss_kpi_hl,
              rs.stocktake_scope,
              p.loss_liquid_other_units,
              p.loss_4pack_btl_units,
              p.loss_4pack_can_units,
              p.loss_wrap_btl_units,
              p.loss_wrap_can_units,
              p.loss_label_btl_units,
              p.loss_keg_collar_units,
              p.loss_crown_cork_units,
              p.loss_uncapped_units,
              p.loss_half_filled_units,
              p.loss_untaxed_full_units,
              p.loss_can_lid_units,
              p.loss_keg_save_units,
              p.loss_keg_liquid_l,
              p.loss_container_btl_units,
              p.loss_container_can_units,
              p.unsaleable_units,
              p.taproom_keg_l,
              p.qa_analyses_units,
              p.qa_library_units,
              p.is_white_label,
              p.white_label_name,
              p.audit_flags,
              p.hors_process_flag,
              p.hors_process_reason,
              p.email,
              p.comments,
              p.source_tank_type,
              p.bbt_source_fk,
              p.cct_source_fk,
              p.neb_beer,
              p.contract_beer
            FROM bd_packaging_v2 p
            JOIN v_bd_packaging_v2_vendable v ON v.id = p.id
            LEFT JOIN ref_skus rs ON p.sku_id_fk = rs.id
            LEFT JOIN ref_recipes r ON p.recipe_id_fk = r.id
            {$whereSql}
            ORDER BY p.event_date, p.id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($whereArgs);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── CO2/O2 aggregates ─────────────────────────────────────────────────────
    $co2o2 = [];
    $bbtCarbonation = null;
    if (!empty($events)) {
        $ids       = array_column($events, 'id');
        $inMarks   = implode(',', array_fill(0, count($ids), '?'));
        $co2Stmt   = $pdo->prepare(
            "SELECT packaging_v2_id,
                    COUNT(*) AS n_readings,
                    ROUND(AVG(co2), 3) AS avg_co2,
                    ROUND(AVG(o2), 2)  AS avg_o2
               FROM bd_packaging_readings
              WHERE packaging_v2_id IN ({$inMarks})
              GROUP BY packaging_v2_id"
        );
        $co2Stmt->execute($ids);
        foreach ($co2Stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $co2o2[(string) $row['packaging_v2_id']] = [
                'n_readings' => (int) $row['n_readings'],
                'avg_co2'    => $row['avg_co2'] !== null ? (float) $row['avg_co2'] : null,
                'avg_o2'     => $row['avg_o2']  !== null ? (float) $row['avg_o2']  : null,
            ];
        }
    }

    // ── B1a: BBT carbonation fallback (mode=batch only) ──────────────────────
    // Fetch upstream soutirage carbonation when the run has zero in-package gas
    // readings. Keyed on packaging.recipe_id_fk → racking.COALESCE(neb_recipe_id_fk,
    // contract_recipe_id_fk) and the shared batch key. Returned as a SEPARATE field
    // — never folded into $co2o2 — because BBT O₂ ≠ in-package O₂.
    if ($mode === 'batch' && empty($co2o2) && !empty($events)) {
        $firstEv   = $events[0];
        $fRecipeId = (int)($firstEv['recipe_id_fk'] ?? 0);
        $fBatch    = (string)($firstEv['batch'] ?? '');
        if ($fRecipeId > 0 && $fBatch !== '') {
            $bbtStmt = $pdo->prepare(
                "SELECT bbt_co2, bbt_o2, event_date
                   FROM bd_racking_v2
                  WHERE COALESCE(neb_recipe_id_fk, contract_recipe_id_fk) = ?
                    AND COALESCE(NULLIF(neb_batch,''), NULLIF(contract_batch,'')) = ?
                    AND is_tombstoned = 0
                    AND racking_destination_type = 'BBT'
                    AND bbt_co2 IS NOT NULL
                  ORDER BY event_date DESC
                  LIMIT 1"
            );
            $bbtStmt->execute([$fRecipeId, $fBatch]);
            $bbtRow = $bbtStmt->fetch(PDO::FETCH_ASSOC);
            if ($bbtRow) {
                $bbtCarbonation = [
                    'co2'                => $bbtRow['bbt_co2'] !== null ? (float)$bbtRow['bbt_co2'] : null,
                    'o2'                 => $bbtRow['bbt_o2']  !== null ? (float)$bbtRow['bbt_o2']  : null,
                    'racking_event_date' => $bbtRow['event_date'],
                ];
            }
        }
    }

    // ── BOM (inv_consumption lines for these packaging events) ───────────────
    $eventIds = array_column($events, 'id');
    $bomByEvent = [];
    if (!empty($eventIds)) {
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $bomStmt = $pdo->prepare(
            "SELECT ic.source_row_id, rm.name AS mi_name, ic.qty, ic.unit
               FROM inv_consumption ic
               JOIN ref_mi rm ON rm.id = ic.mi_id_fk
              WHERE ic.source_event = 'packaging'
                AND ic.source_row_id IN ({$placeholders})
              ORDER BY ic.source_row_id, rm.name"
        );
        $bomStmt->execute($eventIds);
        foreach ($bomStmt->fetchAll(PDO::FETCH_ASSOC) as $bomRow) {
            $bomByEvent[(int)$bomRow['source_row_id']][] = [
                'mi_name' => $bomRow['mi_name'],
                'qty'     => $bomRow['qty'],
                'unit'    => $bomRow['unit'],
            ];
        }
    }
    // Attach bom array to each event
    foreach ($events as &$ev) {
        $ev['bom'] = $bomByEvent[(int)$ev['id']] ?? [];
    }
    unset($ev);

    // ── Sibling resolution (beer-level QA/gas repatriation) ──────────────────
    // For each event, find sibling runs: same recipe_id_fk + batch + event_date, different id.
    // NOTE: In mode=batch the query filters by sku_id_fk, so sibling runs of OTHER formats
    // (different SKU, same beer+batch+date) are NOT in $events — we must query them from the DB.
    // Pick the richest sibling (most gas readings, tie-break lowest id).
    // Only populate sibling_qa when the viewed run is "light" (no own gas/qa) AND a richer sibling exists.
    if (!empty($events)) {
        // Collect "light" events that might benefit from a sibling
        // and their identity keys (recipe_id_fk:batch:event_date)
        $lightEvents = [];
        foreach ($events as $ev) {
            $batch  = $ev['batch'] ?? null;
            $recId  = $ev['recipe_id_fk'] ?? null;
            $evDate = $ev['event_date'] ?? null;
            if (!$recId || !$batch || !$evDate) continue;
            $ownGas = $co2o2[(string)(int)$ev['id']] ?? null;
            $ownQaZero   = ((int)($ev['qa_analyses_units'] ?? 0) === 0);
            $ownGasAbsent = ($ownGas === null || (int)$ownGas['n_readings'] === 0);
            if ($ownQaZero && $ownGasAbsent) {
                $lightEvents[] = [
                    'id'             => (int)$ev['id'],
                    'recipe_id_fk'   => (int)$recId,
                    'batch'          => $batch,
                    'event_date'     => $evDate,
                ];
            }
        }

        if (!empty($lightEvents)) {
            // Build one query to fetch all cross-SKU siblings for all light events.
            // Use OR-groups: (recipe_id_fk=? AND batch=? AND event_date=? AND id<>?)
            $sibWhereClauses = [];
            $sibWhereArgs    = [];
            $viewedIdSet     = [];
            foreach ($lightEvents as $le) {
                $viewedIdSet[$le['id']] = true;
                $sibWhereClauses[] = '(p.recipe_id_fk = ? AND COALESCE(NULLIF(p.neb_batch,\'\'),NULLIF(p.contract_batch,\'\')) = ? AND p.event_date = ? AND p.id <> ?)';
                $sibWhereArgs[]    = $le['recipe_id_fk'];
                $sibWhereArgs[]    = $le['batch'];
                $sibWhereArgs[]    = $le['event_date'];
                $sibWhereArgs[]    = $le['id'];
            }

            $sibSql = "SELECT p.id, p.recipe_id_fk,
                              COALESCE(NULLIF(p.neb_batch,''), NULLIF(p.contract_batch,'')) AS batch,
                              p.event_date,
                              p.qa_analyses_units, p.qa_library_units,
                              rs.sku_code
                         FROM bd_packaging_v2 p
                         LEFT JOIN ref_skus rs ON rs.id = p.sku_id_fk
                        WHERE p.is_tombstoned = 0
                          AND (" . implode(' OR ', $sibWhereClauses) . ")";
            $sibStmt = $pdo->prepare($sibSql);
            $sibStmt->execute($sibWhereArgs);
            $allSibRows = $sibStmt->fetchAll(PDO::FETCH_ASSOC);

            // Build map: {recipe_id_fk}:{batch}:{event_date} => [sibling rows]
            $sibByKey = [];
            $allSiblingIds = [];
            foreach ($allSibRows as $sibRow) {
                $key = $sibRow['recipe_id_fk'] . ':' . $sibRow['batch'] . ':' . $sibRow['event_date'];
                if (!isset($sibByKey[$key])) $sibByKey[$key] = [];
                $sibByKey[$key][] = $sibRow;
                $allSiblingIds[(int)$sibRow['id']] = true;
            }
            $allSiblingIds = array_keys($allSiblingIds);

            // Fetch gas readings for sibling ids
            $sibGasMap = [];
            if (!empty($allSiblingIds)) {
                $sibInMarks = implode(',', array_fill(0, count($allSiblingIds), '?'));
                $sibGasStmt = $pdo->prepare(
                    "SELECT packaging_v2_id,
                            COUNT(*) AS n_readings,
                            ROUND(AVG(co2), 3) AS avg_co2,
                            ROUND(AVG(o2), 2)  AS avg_o2
                       FROM bd_packaging_readings
                      WHERE packaging_v2_id IN ({$sibInMarks})
                      GROUP BY packaging_v2_id"
                );
                $sibGasStmt->execute($allSiblingIds);
                foreach ($sibGasStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $sibGasMap[(int)$row['packaging_v2_id']] = [
                        'n_readings' => (int)$row['n_readings'],
                        'avg_co2'    => $row['avg_co2'] !== null ? (float)$row['avg_co2'] : null,
                        'avg_o2'     => $row['avg_o2']  !== null ? (float)$row['avg_o2']  : null,
                    ];
                }
            }

            // For each light event, find richest sibling
            foreach ($events as &$ev) {
                $evId  = (int)$ev['id'];
                $recId = (int)($ev['recipe_id_fk'] ?? 0);
                $batch = $ev['batch'] ?? '';
                $evDate= $ev['event_date'] ?? '';
                if (!$recId || !$batch || !$evDate) continue;

                // Re-check lightness (already filtered above but guard here)
                $ownGas      = $co2o2[(string)$evId] ?? null;
                $ownQaZero   = ((int)($ev['qa_analyses_units'] ?? 0) === 0);
                $ownGasAbsent = ($ownGas === null || (int)($ownGas['n_readings'] ?? 0) === 0);
                if (!$ownQaZero || !$ownGasAbsent) continue;

                $key      = $recId . ':' . $batch . ':' . $evDate;
                $siblings = $sibByKey[$key] ?? [];
                if (empty($siblings)) continue;

                // Find richest sibling: most gas readings, tie-break lowest id
                $bestSib    = null;
                $bestReads  = -1;
                foreach ($siblings as $sibRow) {
                    $sid    = (int)$sibRow['id'];
                    $gas    = $sibGasMap[$sid] ?? null;
                    $nReads = $gas ? (int)$gas['n_readings'] : 0;
                    $sibQa  = (int)($sibRow['qa_analyses_units'] ?? 0);
                    $isRicher = ($nReads > 0 || $sibQa > 0);
                    if (!$isRicher) continue;
                    if ($nReads > $bestReads
                        || ($nReads === $bestReads && $bestSib !== null && $sid < (int)$bestSib['id'])
                    ) {
                        $bestReads = $nReads;
                        $bestSib   = $sibRow;
                    }
                }

                if ($bestSib === null) continue;

                $bestSibId = (int)$bestSib['id'];
                $bestGas   = $sibGasMap[$bestSibId] ?? null;
                $ev['sibling_qa'] = [
                    'source_run_id'    => $bestSibId,
                    'source_sku_code'  => $bestSib['sku_code'] ?? null,
                    'qa_analyses_units'=> $bestSib['qa_analyses_units'] !== null ? (int)$bestSib['qa_analyses_units'] : null,
                    'qa_library_units' => $bestSib['qa_library_units']  !== null ? (int)$bestSib['qa_library_units']  : null,
                    'avg_co2'          => $bestGas ? $bestGas['avg_co2']    : null,
                    'avg_o2'           => $bestGas ? $bestGas['avg_o2']     : null,
                    'n_readings'       => $bestGas ? $bestGas['n_readings'] : 0,
                ];
            }
            unset($ev);
        }
    }

    // ── CIP from bd_cip_events ────────────────────────────────────────────────
    // Collect all relevant packaging ids: viewed + siblings
    $allPkgIds = array_map('intval', array_column($events, 'id'));
    foreach ($events as $ev) {
        if (!empty($ev['sibling_qa']['source_run_id'])) {
            $allPkgIds[] = (int)$ev['sibling_qa']['source_run_id'];
        }
    }
    $allPkgIds = array_values(array_unique($allPkgIds));

    $cipByPkgId = [];
    if (!empty($allPkgIds)) {
        $cipMarks = implode(',', array_fill(0, count($allPkgIds), '?'));
        $cipStmt  = $pdo->prepare(
            "SELECT ce.packaging_id,
                    ce.target_kind,
                    ce.target_code,
                    ce.target_number,
                    ct.name AS type_name,
                    ce.cip_date,
                    ce.notes
               FROM bd_cip_events ce
               JOIN ref_cip_types ct ON ct.id = ce.cip_type_id_fk
              WHERE ce.source_form = 'packaging'
                AND ce.is_tombstoned = 0
                AND ce.packaging_id IN ({$cipMarks})
              ORDER BY ce.cip_date, ce.id"
        );
        $cipStmt->execute($allPkgIds);
        foreach ($cipStmt->fetchAll(PDO::FETCH_ASSOC) as $cipRow) {
            $cipByPkgId[(int)$cipRow['packaging_id']][] = [
                'target_kind'   => $cipRow['target_kind'],
                'target_code'   => $cipRow['target_code'],
                'target_number' => $cipRow['target_number'] !== null ? (int)$cipRow['target_number'] : null,
                'type_name'     => $cipRow['type_name'],
                'cip_date'      => $cipRow['cip_date'],
                'notes'         => $cipRow['notes'],
            ];
        }
    }

    // Attach cip and cip_sibling to each event
    foreach ($events as &$ev) {
        $evId = (int)$ev['id'];
        $ownCip = $cipByPkgId[$evId] ?? [];
        $ev['cip'] = $ownCip;

        // If no own CIP but sibling has one
        if (empty($ownCip) && !empty($ev['sibling_qa']['source_run_id'])) {
            $sibId = (int)$ev['sibling_qa']['source_run_id'];
            $sibCip = $cipByPkgId[$sibId] ?? [];
            if (!empty($sibCip)) {
                $ev['cip_sibling'] = [
                    'source_run_id'   => $sibId,
                    'source_sku_code' => $ev['sibling_qa']['source_sku_code'] ?? null,
                    'events'          => $sibCip,
                ];
            }
        }
    }
    unset($ev);

    // ── PHP-side summary ──────────────────────────────────────────────────────
    $totalUnits      = 0;
    $totalVendableHl = 0.0;
    $totalLossKpiHl  = 0.0;
    foreach ($events as $ev) {
        $totalUnits      += (int)   ($ev['prod_total_units'] ?? 0);
        $totalVendableHl += (float) ($ev['vendable_hl']      ?? 0);
        $totalLossKpiHl  += (float) ($ev['loss_kpi_hl']      ?? 0);
    }

    echo json_encode([
        'ok'             => true,
        'sku_id'         => $skuId ?: null,
        'events'         => $events,
        'co2o2'          => $co2o2,
        'bbt_carbonation'=> $bbtCarbonation,
        'summary' => [
            'n_events'          => count($events),
            'total_units'       => $totalUnits,
            'total_vendable_hl' => round($totalVendableHl, 4),
            'total_loss_kpi_hl' => round($totalLossKpiHl,  4),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . pdo_friendly_error($e, 'packaging-lookup')]);
}
