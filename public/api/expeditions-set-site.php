<?php
declare(strict_types=1);
/**
 * POST /api/expeditions-set-site.php — Two-column fulfilment-site writer.
 *
 * Accepts JSON POST: { csrf, order_id, fulfilment_site_id_fk }
 *   fulfilment_site_id_fk: int|empty string → NULL (clear to Automatique)
 *   fulfilment_site_id_fk: int > 0 → MUST be in holds_fg_stock=1 whitelist or REJECTED.
 *
 * Two-column branching (NEVER writes both in one request):
 *   Branch 1 — customer has no default → write ref_customers.default_delivery_site_id_fk
 *   Branch 2 — customer has default AND pick != default → write ord_orders.fulfilment_site_id_fk (exception)
 *   Branch 3 — customer has default AND pick == default → NULL ord_orders.fulfilment_site_id_fk (clear spurious exception)
 *   Branch 4 — clear to Automatique → NULL ord_orders.fulfilment_site_id_fk; never touches ref_customers
 *
 * Response: {
 *   ok: true,
 *   order_id: N,
 *   fulfilment_site_id_fk: null|int,
 *   resolved_site_id: int,
 *   resolved_site_name: '…',
 *   is_override: bool,
 *   wrote_table: 'ref_customers'|'ord_orders'|null,
 *   ui_state: 'override'|'auto'|'unassigned',
 *   csrf: '…'
 * }
 * | { ok:false, error:'…' }
 * | { ok:false, reason:'expired', csrf:'…' }
 *
 * HTTP: 200 success, 400 bad input, 403 unauth, 405 wrong method, 500 error.
 * Auth: current_user() + require_page_access('expeditions') + can_write_expeditions($me).
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/fulfilment-site.php';

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

if (!user_can_access('expeditions', $me)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès non autorisé.']);
    exit;
}

if (!can_write_expeditions($me)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès en lecture seule.']);
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
    $freshCsrf = csrf_token();
    echo json_encode(['ok' => false, 'reason' => 'expired', 'csrf' => $freshCsrf]);
    exit;
}

// ── Read + validate input ─────────────────────────────────────────────────────
$orderId   = isset($data['order_id']) ? (int) $data['order_id'] : 0;
$siteRaw   = $data['fulfilment_site_id_fk'] ?? '';
$newSiteId = null; // null = Automatique

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'order_id invalide.']);
    exit;
}

// Interpret site value: empty/0/null → NULL; otherwise validate whitelist
if ($siteRaw !== '' && $siteRaw !== null && (int) $siteRaw > 0) {
    $siteInt = (int) $siteRaw;

    // Build holds_fg_stock whitelist
    $pdo    = maltytask_pdo();
    $wlStmt = $pdo->query(
        'SELECT id FROM ref_sites WHERE holds_fg_stock = 1 AND is_active = 1'
    );
    $whitelist = [];
    foreach ($wlStmt->fetchAll(PDO::FETCH_COLUMN) as $wid) {
        $whitelist[(int)$wid] = true;
    }

    if (!isset($whitelist[$siteInt])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Site d\'expédition invalide (hors whitelist holds_fg_stock).']);
        exit;
    }
    $newSiteId = $siteInt;
} else {
    // Clear to Automatique
    $pdo = maltytask_pdo();
}

// ── DB ────────────────────────────────────────────────────────────────────────
try {
    // Fetch current order — verify it exists + get customer for re-resolve
    $ordStmt = $pdo->prepare(
        'SELECT o.id, o.fulfilment_site_id_fk, o.customer_id_fk, o.internal_channel,
                c.default_delivery_site_id_fk AS customer_default_site_id
           FROM ord_orders o
           LEFT JOIN ref_customers c ON c.id = o.customer_id_fk
          WHERE o.id = ? LIMIT 1'
    );
    $ordStmt->execute([$orderId]);
    $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

    if ($order === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Commande introuvable.']);
        exit;
    }

    $currentSiteId     = $order['fulfilment_site_id_fk'] !== null ? (int) $order['fulfilment_site_id_fk'] : null;
    $customerDefaultId = ($order['customer_default_site_id'] !== null && $order['customer_default_site_id'] !== '')
        ? (int) $order['customer_default_site_id'] : null;
    $customerId        = isset($order['customer_id_fk']) ? (int) $order['customer_id_fk'] : 0;

    // ── Determine branch ─────────────────────────────────────────────────────
    // Branch 1: establish customer default (no existing default, picking a site)
    // Branch 2: per-order exception (customer has default, pick differs)
    // Branch 3: pick == customer default (clear any spurious order override)
    // Branch 4: clear to Automatique (empty pick)

    $wroteTable             = null;
    $finalOrderOverride     = $currentSiteId; // will be updated per branch
    $updatedCustomerDefault = $customerDefaultId;

    if ($newSiteId === null) {
        // ── Branch 4: clear to Automatique ───────────────────────────────────
        if ($currentSiteId !== null) {
            $comment = 'Site d\'expedition : #' . $currentSiteId . ' -> Automatique (commande #' . $orderId . ')';
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE ord_orders SET fulfilment_site_id_fk = NULL WHERE id = ?')
                ->execute([$orderId]);
            log_revision($pdo, $me, 'ord_orders', $orderId,
                ['fulfilment_site_id_fk' => $currentSiteId],
                ['fulfilment_site_id_fk' => null],
                'normal', $comment
            );
            $pdo->commit();
            $wroteTable = 'ord_orders';
        }
        $finalOrderOverride = null;
        // $updatedCustomerDefault unchanged

    } elseif ($customerDefaultId === null) {
        // ── Branch 1: establish customer default ──────────────────────────────
        if ($customerId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Impossible d\'etablir le defaut : customer_id_fk absent.']);
            exit;
        }
        $comment = 'Defaut livraison client #' . $customerId . ' etabli : site #' . $newSiteId . ' (commande #' . $orderId . ')';
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE ref_customers SET default_delivery_site_id_fk = ? WHERE id = ?')
            ->execute([$newSiteId, $customerId]);
        log_revision($pdo, $me, 'ref_customers', $customerId,
            ['default_delivery_site_id_fk' => null],
            ['default_delivery_site_id_fk' => $newSiteId],
            'normal', $comment
        );
        $pdo->commit();
        $wroteTable             = 'ref_customers';
        $finalOrderOverride     = null; // ord_orders untouched
        $updatedCustomerDefault = $newSiteId;

    } elseif ($newSiteId !== $customerDefaultId) {
        // ── Branch 2: per-order exception ────────────────────────────────────
        $comment = 'Site expedition force : #' . ($currentSiteId ?? 'auto') . ' -> #' . $newSiteId . ' (commande #' . $orderId . ')';
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE ord_orders SET fulfilment_site_id_fk = ? WHERE id = ?')
            ->execute([$newSiteId, $orderId]);
        log_revision($pdo, $me, 'ord_orders', $orderId,
            ['fulfilment_site_id_fk' => $currentSiteId],
            ['fulfilment_site_id_fk' => $newSiteId],
            'normal', $comment
        );
        $pdo->commit();
        $wroteTable         = 'ord_orders';
        $finalOrderOverride = $newSiteId;

    } else {
        // ── Branch 3: pick == customer default — clear any spurious exception ─
        if ($currentSiteId !== null) {
            $comment = 'Site expedition remis sur defaut client #' . $customerId . ' (commande #' . $orderId . ')';
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE ord_orders SET fulfilment_site_id_fk = NULL WHERE id = ?')
                ->execute([$orderId]);
            log_revision($pdo, $me, 'ord_orders', $orderId,
                ['fulfilment_site_id_fk' => $currentSiteId],
                ['fulfilment_site_id_fk' => null],
                'normal', $comment
            );
            $pdo->commit();
            $wroteTable = 'ord_orders';
        }
        $finalOrderOverride = null;
        // $updatedCustomerDefault unchanged ($customerDefaultId)
    }

    // ── Compute ui_state ─────────────────────────────────────────────────────
    if ($finalOrderOverride !== null) {
        $uiState = 'override';
    } elseif ($updatedCustomerDefault !== null && $updatedCustomerDefault > 0) {
        $uiState = 'auto';
    } else {
        $uiState = 'unassigned';
    }

    // Recompute resolved site after write
    $resolvedId = resolve_fulfilment_site($pdo, [
        'fulfilment_site_id_fk'     => $finalOrderOverride,
        '_customer_default_site_id' => $updatedCustomerDefault,
        'channel'                   => $order['internal_channel'] ?? null,
    ]);

    $nameStmt = $pdo->prepare('SELECT name FROM ref_sites WHERE id = ? LIMIT 1');
    $nameStmt->execute([$resolvedId]);
    $nameRow = $nameStmt->fetch(PDO::FETCH_ASSOC);

    $freshCsrf = csrf_token();
    echo json_encode([
        'ok'                    => true,
        'order_id'              => $orderId,
        'fulfilment_site_id_fk' => $finalOrderOverride,
        'resolved_site_id'      => $resolvedId,
        'resolved_site_name'    => $nameRow ? $nameRow['name'] : '—',
        'is_override'           => ($finalOrderOverride !== null),
        'wrote_table'           => $wroteTable,
        'ui_state'              => $uiState,
        'csrf'                  => $freshCsrf,
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[expeditions-set-site] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur interne.']);
}
