<?php
declare(strict_types=1);
/**
 * POST /api/expeditions-line-status.php — Line-status writer for ord_order_lines.
 *
 * Accepts JSON POST: { csrf, line_id, status }
 *   status ∈ { to_fulfil, non_livre, rupture }
 *
 * ONE transaction: UPDATE ord_order_lines.line_status + log_revision on the line.
 *
 * Response: { ok:true, line_id:N, status:'…', label:'…', csrf:'…' }
 *         | { ok:false, error:'…' }
 *         | { ok:false, reason:'expired', csrf:'…' }  — CSRF retry hint
 *
 * HTTP: 200 success, 400 bad input/CSRF, 403 unauth, 405 wrong method, 500 error.
 *
 * Auth: same gate as expeditions-status.php — current_user() (any logged-in user).
 * Role note: the Expéditions page is gated at page-access level; any user who can
 * reach that page may update line statuses.
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

// ── Label map — operator labels; NO DB enum literals in responses ─────────────
$lineStatusLabels = [
    'to_fulfil'  => 'À livrer',
    'non_livre'  => 'Non livré',
    'rupture'    => 'Rupture',
];
$allowedStatuses = array_keys($lineStatusLabels);

// ── Read + validate input ─────────────────────────────────────────────────────
$lineId    = isset($data['line_id']) ? (int) $data['line_id'] : 0;
$newStatus = isset($data['status'])  ? (string) $data['status'] : '';

if ($lineId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'line_id invalide.']);
    exit;
}
if (!in_array($newStatus, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Statut invalide. Valeurs acceptées : ' . implode(', ', $allowedStatuses) . '.']);
    exit;
}

// ── DB ────────────────────────────────────────────────────────────────────────
$pdo = maltytask_pdo();

try {
    // Fetch current line — verify it exists
    $lineStmt = $pdo->prepare(
        'SELECT id, order_id_fk, line_status FROM ord_order_lines WHERE id = ? LIMIT 1'
    );
    $lineStmt->execute([$lineId]);
    $line = $lineStmt->fetch(PDO::FETCH_ASSOC);

    if ($line === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ligne introuvable.']);
        exit;
    }

    $currentStatus = (string) $line['line_status'];
    $orderId       = (int)    $line['order_id_fk'];

    if ($currentStatus === $newStatus) {
        // Idempotent — no write needed, return success with fresh CSRF
        $freshCsrf = csrf_token();
        echo json_encode([
            'ok'      => true,
            'line_id' => $lineId,
            'status'  => $newStatus,
            'label'   => $lineStatusLabels[$newStatus],
            'csrf'    => $freshCsrf,
        ]);
        exit;
    }

    $comment = 'Statut ligne : ' . ($lineStatusLabels[$currentStatus] ?? $currentStatus)
             . ' → ' . ($lineStatusLabels[$newStatus] ?? $newStatus)
             . ' (commande #' . $orderId . ')';

    // ── ONE transaction: update + audit ──────────────────────────────────────
    $pdo->beginTransaction();

    $updLine = $pdo->prepare(
        'UPDATE ord_order_lines SET line_status = ? WHERE id = ?'
    );
    $updLine->execute([$newStatus, $lineId]);

    log_revision($pdo, $me, 'ord_order_lines', $lineId,
        ['line_status' => $currentStatus],
        ['line_status' => $newStatus],
        'normal',
        $comment
    );

    $pdo->commit();

    // Return fresh CSRF token so client stays hot
    $freshCsrf = csrf_token();
    echo json_encode([
        'ok'      => true,
        'line_id' => $lineId,
        'status'  => $newStatus,
        'label'   => $lineStatusLabels[$newStatus],
        'csrf'    => $freshCsrf,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[expeditions-line-status] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur interne.']);
}
