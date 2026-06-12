<?php
declare(strict_types=1);
/**
 * POST /api/expeditions-repack.php — Log eshop repack events.
 *
 * Accepts JSON POST:
 *   {
 *     csrf:     string,
 *     order_id: int,            inv_sales_orders.id (source order; may be 0 for ad-hoc)
 *     mode:     string,         'pickup'|'delivery' (for site attribution, informational)
 *     moved_on: string,         ISO date YYYY-MM-DD
 *     rows: [
 *       {
 *         from_sku_id:       int,   ref_skus.id (base box opened)
 *         from_qty:          int,   boxes opened (> 0)
 *         to_sku_id:         int,   ref_skus.id of bundle/result (0 = loose)
 *         to_qty:            int,   bundles/PD8 produced (≥ 0)
 *         component_bottles: int,   bottles consumed = to_qty × bundle.units_per_pack
 *         loose_units:       int,   remainder bottles = from_qty×base.units_per_pack − component_bottles
 *         to_kind:           string, 'bundle'|'pd8'|'loose'|'adjustment'
 *         site_id:           int,   ref_sites.id
 *       }, …
 *     ]
 *   }
 *
 * Server-side balance check (per row):
 *   from_qty × base.units_per_pack == component_bottles + loose_units
 *   (Conservation: refuse-don't-NULL if imbalanced.)
 *
 * Idempotency:
 *   Auto-proposed rows (order_id > 0) carry
 *   repack_key = "{order_id}:{from_sku_id}".
 *   The UNIQUE KEY uq_repack_key on inv_repack_events is honoured via
 *   INSERT IGNORE — duplicate auto-rows are silently skipped.
 *   Ad-hoc rows (order_id == 0) leave repack_key NULL (no idempotency needed).
 *
 * ONE transaction per request:
 *   - Validate all rows first (no partial writes)
 *   - INSERT each inv_repack_events row
 *   - log_revision per row
 *
 * Response: { ok:true, inserted:int, csrf:string }
 *         | { ok:false, error:string }
 *         | { ok:false, reason:'expired', csrf:string }
 *
 * HTTP: 200 success, 400 bad input, 403 unauth, 405 wrong method, 500 error.
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/settings-helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
$me = current_user();
if ($me === null) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Authentification requise.']);
    exit;
}

// ── Decode JSON body ──────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Corps JSON invalide.']);
    exit;
}

// ── CSRF — first gate; on fail return fresh token for one retry ───────────────
$postedCsrf = $data['csrf'] ?? null;
if (!csrf_verify(is_string($postedCsrf) ? $postedCsrf : null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'reason' => 'expired', 'csrf' => csrf_token()]);
    exit;
}

// ── Input validation ──────────────────────────────────────────────────────────
$orderId  = isset($data['order_id'])  ? (int) $data['order_id']  : 0;   // 0 = ad-hoc
$movedOn  = isset($data['moved_on'])  ? (string) $data['moved_on'] : '';
$rows     = isset($data['rows'])      ? (array)  $data['rows']    : [];

if ($movedOn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $movedOn)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'moved_on doit être une date YYYY-MM-DD.']);
    exit;
}
if (empty($rows)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'rows est vide.']);
    exit;
}

// Allowed to_kind values
const RKP_TO_KINDS = ['bundle', 'pd8', 'loose', 'adjustment'];

// ── DB ────────────────────────────────────────────────────────────────────────
$pdo = maltytask_pdo();

try {
    // ── Pre-validate ALL rows before opening a transaction ────────────────────
    // Also load base-box units_per_pack for the balance check.
    $fromSkuIds = array_unique(array_map(fn($r) => (int) ($r['from_sku_id'] ?? 0), $rows));
    $fromSkuIds = array_filter($fromSkuIds, fn($id) => $id > 0);
    if (empty($fromSkuIds)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Aucun from_sku_id valide.']);
        exit;
    }

    $inPlaceholders = implode(',', array_fill(0, count($fromSkuIds), '?'));
    $skuStmt = $pdo->prepare(
        "SELECT id, sku_code, units_per_pack, stocktake_scope
           FROM ref_skus
          WHERE id IN ($inPlaceholders) AND is_active = 1"
    );
    $skuStmt->execute(array_values($fromSkuIds));
    $skuMap = []; // id => {sku_code, units_per_pack, stocktake_scope}
    foreach ($skuStmt->fetchAll(PDO::FETCH_ASSOC) as $sk) {
        $skuMap[(int) $sk['id']] = $sk;
    }

    // Similarly load to_sku metadata (for FK existence check)
    $toSkuIds = array_unique(array_filter(
        array_map(fn($r) => (int) ($r['to_sku_id'] ?? 0), $rows),
        fn($id) => $id > 0
    ));
    $toSkuMap = [];
    if (!empty($toSkuIds)) {
        $toInPlaceholders = implode(',', array_fill(0, count($toSkuIds), '?'));
        $toSkuStmt = $pdo->prepare(
            "SELECT id FROM ref_skus WHERE id IN ($toInPlaceholders) AND is_active = 1"
        );
        $toSkuStmt->execute(array_values($toSkuIds));
        foreach ($toSkuStmt->fetchAll(PDO::FETCH_ASSOC) as $ts) {
            $toSkuMap[(int) $ts['id']] = true;
        }
    }

    // Validate each row
    $validatedRows = [];
    foreach ($rows as $i => $row) {
        $fromSkuId       = isset($row['from_sku_id'])       ? (int)    $row['from_sku_id']       : 0;
        $fromQty         = isset($row['from_qty'])          ? (int)    $row['from_qty']           : 0;
        $toSkuId         = isset($row['to_sku_id'])         ? (int)    $row['to_sku_id']          : 0;
        $toQty           = isset($row['to_qty'])            ? (int)    $row['to_qty']             : 0;
        $componentBottles= isset($row['component_bottles']) ? (int)    $row['component_bottles']  : 0;
        $looseUnits      = isset($row['loose_units'])       ? (int)    $row['loose_units']        : 0;
        $toKind          = isset($row['to_kind'])           ? (string) $row['to_kind']            : '';
        $siteId          = isset($row['site_id'])           ? (int)    $row['site_id']            : 0;

        if ($fromSkuId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Ligne $i : from_sku_id invalide."]);
            exit;
        }
        if (!isset($skuMap[$fromSkuId])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Ligne $i : SKU source $fromSkuId introuvable ou inactif."]);
            exit;
        }
        if ($fromQty <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Ligne $i : from_qty doit être > 0."]);
            exit;
        }
        if (!in_array($toKind, RKP_TO_KINDS, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Ligne $i : to_kind invalide '$toKind'."]);
            exit;
        }
        if ($toSkuId > 0 && !isset($toSkuMap[$toSkuId])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Ligne $i : SKU résultat $toSkuId introuvable ou inactif."]);
            exit;
        }
        if ($siteId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Ligne $i : site_id invalide."]);
            exit;
        }

        // ── Balance check: from_qty × base.units_per_pack == component_bottles + loose_units
        $baseUnits = (int) round((float) $skuMap[$fromSkuId]['units_per_pack']);
        if ($baseUnits <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Ligne $i : units_per_pack=0 sur le SKU source."]);
            exit;
        }
        $expectedTotal = $fromQty * $baseUnits;
        $actualTotal   = $componentBottles + $looseUnits;
        if ($expectedTotal !== $actualTotal) {
            http_response_code(400);
            echo json_encode([
                'ok'    => false,
                'error' => sprintf(
                    "Ligne $i : déséquilibre — from_qty(%d)×units_per_pack(%d)=%d ≠ component_bottles(%d)+loose_units(%d)=%d.",
                    $fromQty, $baseUnits, $expectedTotal,
                    $componentBottles, $looseUnits, $actualTotal
                ),
            ]);
            exit;
        }

        $validatedRows[] = [
            'from_sku_id'       => $fromSkuId,
            'from_qty'          => $fromQty,
            'to_sku_id'         => $toSkuId > 0 ? $toSkuId : null,
            'to_qty'            => $toQty,
            'loose_units'       => $looseUnits,
            'to_kind'           => $toKind,
            'site_id'           => $siteId,
            'base_units'        => $baseUnits,       // for reference only
        ];
    }

    // ── ONE transaction: INSERT all rows + audit ──────────────────────────────
    $pdo->beginTransaction();

    $insertStmt = $pdo->prepare(
        "INSERT INTO inv_repack_events
           (site_id_fk, from_sku_id_fk, from_qty,
            to_sku_id_fk, to_qty, loose_units, to_kind,
            source_order_id_fk, moved_on,
            submitted_by_user_fk, repack_key, created_at)
         VALUES
           (:site_id, :from_sku_id, :from_qty,
            :to_sku_id, :to_qty, :loose_units, :to_kind,
            :order_id, :moved_on,
            :user_id, :repack_key, NOW())
         ON DUPLICATE KEY UPDATE id = id"
    );
    // Note: ON DUPLICATE KEY UPDATE id=id is a no-op — effectively INSERT IGNORE
    // for the UNIQUE KEY uq_repack_key. For ad-hoc rows (repack_key=NULL), the
    // UNIQUE constraint on NULL is MySQL-safe (NULLs don't collide).

    $inserted = 0;
    foreach ($validatedRows as $vr) {
        // repack_key for auto-proposed rows: "{order_id}:{from_sku_id}"
        $repackKey = ($orderId > 0)
            ? ($orderId . ':' . $vr['from_sku_id'])
            : null;

        $insertStmt->execute([
            ':site_id'    => $vr['site_id'],
            ':from_sku_id'=> $vr['from_sku_id'],
            ':from_qty'   => $vr['from_qty'],
            ':to_sku_id'  => $vr['to_sku_id'],
            ':to_qty'     => $vr['to_qty'],
            ':loose_units'=> $vr['loose_units'],
            ':to_kind'    => $vr['to_kind'],
            ':order_id'   => $orderId > 0 ? $orderId : null,
            ':moved_on'   => $movedOn,
            ':user_id'    => (int) $me['id'],
            ':repack_key' => $repackKey,
        ]);

        // lastInsertId() returns 0 on a duplicate-key no-op — only audit real inserts.
        $newId = (int) $pdo->lastInsertId();
        if ($newId > 0) {
            $inserted++;
            log_revision(
                $pdo, $me, 'inv_repack_events', $newId,
                null,
                [
                    'site_id_fk'         => $vr['site_id'],
                    'from_sku_id_fk'     => $vr['from_sku_id'],
                    'from_qty'           => $vr['from_qty'],
                    'to_sku_id_fk'       => $vr['to_sku_id'],
                    'to_qty'             => $vr['to_qty'],
                    'loose_units'        => $vr['loose_units'],
                    'to_kind'            => $vr['to_kind'],
                    'source_order_id_fk' => $orderId > 0 ? $orderId : null,
                    'moved_on'           => $movedOn,
                    'repack_key'         => $repackKey,
                ],
                'normal',
                'Reconditionnement eshop enregistré'
            );
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok'       => true,
        'inserted' => $inserted,
        'csrf'     => csrf_token(),
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[expeditions-repack] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur interne.']);
}
