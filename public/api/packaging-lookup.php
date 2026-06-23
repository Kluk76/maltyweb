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
$allowedModes = ['day', 'batch'];
$mode = $_GET['mode'] ?? '';
if (!in_array($mode, $allowedModes, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Paramètre mode invalide (day|batch requis).']);
    exit;
}

try {
    $pdo = maltytask_pdo();

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
              p.cip_tank_done,
              p.cip_tank_type,
              p.cip_tank_date,
              p.cip_machines_done,
              p.cip_machines_type,
              p.cip_machines_date,
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
        'ok'      => true,
        'events'  => $events,
        'co2o2'   => $co2o2,
        'summary' => [
            'n_events'         => count($events),
            'total_units'      => $totalUnits,
            'total_vendable_hl'  => round($totalVendableHl, 4),
            'total_loss_kpi_hl'  => round($totalLossKpiHl,  4),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . pdo_friendly_error($e, 'packaging-lookup')]);
}
