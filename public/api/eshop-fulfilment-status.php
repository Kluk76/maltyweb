<?php
declare(strict_types=1);
/**
 * POST /api/eshop-fulfilment-status.php — Workflow-advance endpoint for eshop orders.
 *
 * Accepts JSON POST: { csrf, eshop_order_id, action }
 *   action ∈ { advance, revert, cancel }
 *
 * Mode-aware lifecycle (keyed on inv_sales_orders.fulfilment_mode):
 *   delivery: new → picking → picked → fulfilled
 *   pickup:   new → picking → picked → ready_for_pickup → picked_up
 *   review:   REFUSED — return error "classer le mode d'abord"
 *   cancelled: terminal from any non-terminal status.
 *
 * ONE transaction:
 *   - lazy-create inv_sales_fulfilment row if absent
 *   - INSERT inv_sales_fulfilment_events
 *   - UPDATE inv_sales_fulfilment.status cache
 *   - On push-triggering status (fulfilled / ready_for_pickup / picked_up):
 *     set shopify_sync_state='pending' (worker Phase 2B drains; disarmed now)
 *   - log_revision audit on both tables
 *
 * Response: { ok:true, status:'…', label:'…', csrf:'…' }
 *         | { ok:false, error:'…' }
 *         | { ok:false, reason:'expired', csrf:'…' }  — CSRF retry hint
 *
 * HTTP: 200 success, 400 bad input/CSRF, 403 unauth, 405 wrong method, 500 error.
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

// ── Lifecycle constants ────────────────────────────────────────────────────────
// Mode-aware advance maps — 'review' mode is REFUSED (no entry).
const ESHOP_FULFIL_ADVANCE_DELIVERY = [
    'new'     => 'picking',
    'picking' => 'picked',
    'picked'  => 'fulfilled',
];
const ESHOP_FULFIL_ADVANCE_PICKUP = [
    'new'              => 'picking',
    'picking'          => 'picked',
    'picked'           => 'ready_for_pickup',
    'ready_for_pickup' => 'picked_up',
];
// Revert maps (shared: same intermediate steps for both modes)
const ESHOP_FULFIL_REVERT = [
    'picking'          => 'new',
    'picked'           => 'picking',
    'ready_for_pickup' => 'picked',
    'fulfilled'        => 'picked',
    'picked_up'        => 'ready_for_pickup',
];
const ESHOP_FULFIL_LABELS = [
    'new'              => 'Nouveau',
    'picking'          => 'En préparation',
    'picked'           => 'Préparé',
    'ready_for_pickup' => 'Prêt au retrait',
    'fulfilled'        => 'Expédié',
    'picked_up'        => 'Remis',
    'cancelled'        => 'Annulé',
];
// Statuses that trigger a Shopify push (Phase 2B worker drains; no-op now)
const ESHOP_FULFIL_PUSH_TRIGGER = ['fulfilled', 'ready_for_pickup', 'picked_up'];
// Terminal statuses — no further advance
const ESHOP_FULFIL_TERMINALS = ['fulfilled', 'picked_up', 'cancelled'];

// ── Input validation ──────────────────────────────────────────────────────────
$eshopOrderId = isset($data['eshop_order_id']) ? (int) $data['eshop_order_id'] : 0;
$action       = isset($data['action']) ? (string) $data['action'] : '';

if ($eshopOrderId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'eshop_order_id invalide.']);
    exit;
}
if (!in_array($action, ['advance', 'revert', 'cancel', 'classify'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Action invalide.']);
    exit;
}

// 'classify' param — read near other inputs; named $newMode to avoid collision with
// $mode (the order's current fulfilment_mode, assigned later inside the try block).
$newMode = isset($data['mode']) ? (string) $data['mode'] : '';

// ── DB ────────────────────────────────────────────────────────────────────────
$pdo = maltytask_pdo();

try {
    // ── Fetch eshop order (confirm it exists + get fulfilment_mode) ───────────
    $orderStmt = $pdo->prepare(
        'SELECT id, fulfilment_mode FROM inv_sales_orders WHERE id = ? LIMIT 1'
    );
    $orderStmt->execute([$eshopOrderId]);
    $eshopOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if ($eshopOrder === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Commande eshop introuvable.']);
        exit;
    }

    $mode = (string) ($eshopOrder['fulfilment_mode'] ?? 'review');

    // ── classify action — ALLOWED on review-mode orders; early-return branch ────
    // Must be placed BEFORE the review-refuse block: classify is the ONE action
    // intended for orders currently in 'review' mode.
    if ($action === 'classify') {
        // Server-side whitelist: only pickup|delivery are valid targets.
        // 'review' and anything else are rejected — operator can't classify to review.
        if (!in_array($newMode, ['pickup', 'delivery'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Mode invalide.']);
            exit;
        }
        // Review-only guard: can only classify an order currently in review mode.
        if ($mode !== 'review') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Commande déjà classée.']);
            exit;
        }
        // ONE transaction: update fulfilment_mode + source stamp + audit.
        // NOTE: inv_sales_orders has no updated_by column (confirmed via SHOW COLUMNS);
        // it is omitted from the SET clause deliberately.
        $pdo->beginTransaction();
        $updOrd = $pdo->prepare(
            'UPDATE inv_sales_orders
                SET fulfilment_mode        = ?,
                    fulfilment_mode_source = \'manual\'
              WHERE id = ? AND channel = \'eshop\''
        );
        $updOrd->execute([$newMode, $eshopOrderId]);
        log_revision(
            $pdo, $me, 'inv_sales_orders', $eshopOrderId,
            ['fulfilment_mode' => $mode,    'fulfilment_mode_source' => 'auto'],
            ['fulfilment_mode' => $newMode, 'fulfilment_mode_source' => 'manual'],
            'normal',
            'Classé manuellement: ' . $newMode
        );
        $pdo->commit();
        echo json_encode(['ok' => true, 'mode' => $newMode, 'csrf' => csrf_token()]);
        exit;
    }

    // REFUSE review-mode orders — operator must classify first
    if ($mode === 'review') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'classer le mode d\'abord']);
        exit;
    }

    // ── Fetch or lazy-create inv_sales_fulfilment row ─────────────────────────
    $fulfStmt = $pdo->prepare(
        'SELECT id, status, shopify_sync_state FROM inv_sales_fulfilment
          WHERE order_id_fk = ? LIMIT 1'
    );
    $fulfStmt->execute([$eshopOrderId]);
    $fulfRow = $fulfStmt->fetch(PDO::FETCH_ASSOC);

    // Lazy-create: resolve site on first encounter
    $isNew      = ($fulfRow === false);
    $fulfId     = 0;
    $curStatus  = 'new';

    if ($isNew) {
        // Resolve fulfilment site (eshop → warehouse)
        $siteId = resolve_fulfilment_site($pdo, ['channel' => 'eshop']);

        $insF = $pdo->prepare(
            'INSERT INTO inv_sales_fulfilment
               (order_id_fk, status, prepared_by_user_id, fulfilment_site_id_fk,
                shopify_sync_state, created_at, updated_at)
             VALUES (?, \'new\', ?, ?, \'idle\', NOW(), NOW())'
        );
        $insF->execute([
            $eshopOrderId,
            (int) $me['id'],
            $siteId > 0 ? $siteId : null,
        ]);
        $fulfId    = (int) $pdo->lastInsertId();
        $curStatus = 'new';
    } else {
        $fulfId    = (int) $fulfRow['id'];
        $curStatus = (string) $fulfRow['status'];
    }

    // ── Determine new status based on action + mode ───────────────────────────
    $newStatus = null;
    $comment   = null;

    if ($action === 'advance') {
        if (in_array($curStatus, ESHOP_FULFIL_TERMINALS, true)) {
            http_response_code(400);
            $termLabel = ESHOP_FULFIL_LABELS[$curStatus] ?? $curStatus;
            echo json_encode(['ok' => false, 'error' => 'Statut terminal (' . $termLabel . ') — impossible d\'avancer.']);
            exit;
        }
        $advMap    = ($mode === 'pickup') ? ESHOP_FULFIL_ADVANCE_PICKUP : ESHOP_FULFIL_ADVANCE_DELIVERY;
        $newStatus = $advMap[$curStatus] ?? null;
        if ($newStatus === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Avancement impossible depuis ce statut.']);
            exit;
        }
        $comment = 'Avancement depuis ' . (ESHOP_FULFIL_LABELS[$curStatus] ?? $curStatus);

    } elseif ($action === 'cancel') {
        if ($curStatus === 'cancelled') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Commande déjà annulée.']);
            exit;
        }
        if (in_array($curStatus, ['fulfilled', 'picked_up'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Commande déjà finalisée — annulation impossible.']);
            exit;
        }
        $newStatus = 'cancelled';
        $comment   = 'Annulé depuis ' . (ESHOP_FULFIL_LABELS[$curStatus] ?? $curStatus);

    } elseif ($action === 'revert') {
        if ($curStatus === 'new') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Impossible de revenir en arrière depuis Nouveau.']);
            exit;
        }
        if ($curStatus === 'cancelled') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Commande annulée — impossible de revenir en arrière.']);
            exit;
        }
        $newStatus = ESHOP_FULFIL_REVERT[$curStatus] ?? null;
        if ($newStatus === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Retour impossible depuis ce statut.']);
            exit;
        }
        $comment = 'Retour depuis ' . (ESHOP_FULFIL_LABELS[$curStatus] ?? $curStatus);
    }

    // ── Determine shopify_sync_state ──────────────────────────────────────────
    // Push-triggering statuses arm the pending queue for Phase 2B worker.
    // Revert from a push-triggered status returns to idle.
    $newSyncState = in_array($newStatus, ESHOP_FULFIL_PUSH_TRIGGER, true)
        ? 'pending'
        : 'idle';

    // ── ONE transaction ───────────────────────────────────────────────────────
    $pdo->beginTransaction();

    // 1. Log fulfilment event
    $insEv = $pdo->prepare(
        'INSERT INTO inv_sales_fulfilment_events
           (order_id_fk, status, occurred_at, user_id_fk, source, comment)
         VALUES (?, ?, NOW(), ?, \'operator\', ?)'
    );
    $insEv->execute([$eshopOrderId, $newStatus, (int) $me['id'], $comment]);
    $evId = (int) $pdo->lastInsertId();

    // 2. Update cache row
    $updF = $pdo->prepare(
        'UPDATE inv_sales_fulfilment
            SET status             = ?,
                shopify_sync_state = ?,
                updated_at         = NOW()
          WHERE id = ?'
    );
    $updF->execute([$newStatus, $newSyncState, $fulfId]);

    // 3. Audit
    log_revision(
        $pdo, $me, 'inv_sales_fulfilment', $fulfId,
        $isNew ? null : ['status' => $curStatus, 'shopify_sync_state' => (string)($fulfRow['shopify_sync_state'] ?? 'idle')],
        ['status' => $newStatus, 'shopify_sync_state' => $newSyncState],
        'normal',
        $comment
    );
    log_revision(
        $pdo, $me, 'inv_sales_fulfilment_events', $evId,
        null,
        ['order_id_fk' => $eshopOrderId, 'status' => $newStatus, 'comment' => $comment],
        'normal'
    );

    $pdo->commit();

    // Return fresh CSRF so client stays hot
    $freshCsrf = csrf_token();
    echo json_encode([
        'ok'              => true,
        'status'          => $newStatus,
        'label'           => ESHOP_FULFIL_LABELS[$newStatus] ?? $newStatus,
        'shopify_sync'    => $newSyncState,
        'csrf'            => $freshCsrf,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[eshop-fulfilment-status] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur interne.']);
}
