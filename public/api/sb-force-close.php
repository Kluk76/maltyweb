<?php
declare(strict_types=1);
/**
 * POST /api/sb-force-close.php  — force-close an open mother shell (ADMIN ONLY).
 *
 * Destructive: closes a mother shell without the normal cuve-vide workflow.
 * Admin-only because it bypasses all phase-gate checks.
 *
 * POST params:
 *   csrf       (string, required)
 *   mother_id  (int)
 *   reason     (string, free-form, >= 5 chars, required)
 *
 * POST response (200):
 *   { "ok": true, "mother_id": N, "closed_at": "YYYY-MM-DD HH:MM:SS" }
 *
 * Error responses:
 *   302  (require_login redirect — not logged in)
 *   400  { "ok": false, "error": "csrf-invalid" | "missing-param"
 *                                | "invalid-mother-id" | "reason-too-short"
 *                                | "mother-not-open" }
 *   403  { "ok": false, "error": "admin-required" }
 *   405  { "ok": false, "error": "method-not-allowed" }
 *   500  { "ok": false, "error": "internal" }
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/mother-shell.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method-not-allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF first — before role check or any other param parsing.
if (!csrf_verify($_POST['csrf'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf-invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Admin gate.
$me = current_user();
if (($me['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'admin-required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = maltytask_pdo();

// ── mother_id ──────────────────────────────────────────────────────────────────
$rawMother = $_POST['mother_id'] ?? null;
if ($rawMother === null || $rawMother === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing-param',
                      'detail' => 'mother_id requis'], JSON_UNESCAPED_UNICODE);
    exit;
}
$motherId = filter_var($rawMother, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($motherId === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid-mother-id',
                      'detail' => 'mother_id doit être un entier > 0'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── reason ────────────────────────────────────────────────────────────────────
$reason = trim($_POST['reason'] ?? '');
if (strlen($reason) < 5) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'reason-too-short',
                      'detail' => 'reason doit faire au moins 5 caractères'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Verify mother is open ─────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT id, status, form_type, merged_into_session_id_fk, is_tombstoned, closed_at
       FROM op_sessions
      WHERE id = ?
      LIMIT 1"
);
$stmt->execute([$motherId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Note: a "merged survivor" (status=open, has absorbed children with their
// merged_into_session_id_fk = $motherId) IS force-closeable here — the survivor's
// own merged_into_session_id_fk is NULL (it's the survivor, not the departing).
// close_mother() does NOT cascade; absorbed sources are already status=closed
// from the merge operation, so no dangling refs. Future-proof: if create_mother
// ever allows un-merge / re-open, this gap reopens — re-evaluate then.
if ($row === false
    || $row['form_type'] !== 'batch'
    || $row['status'] !== 'open'
    || $row['merged_into_session_id_fk'] !== null
    || (int)$row['is_tombstoned'] !== 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'mother-not-open',
                      'detail' => "mother_id={$motherId} n'est pas une mother shell ouverte"],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Apply ─────────────────────────────────────────────────────────────────────
try {
    close_mother($pdo, $motherId, 'force-close: ' . $reason);

    // Fetch closed_at from the just-updated row.
    $stmtCa = $pdo->prepare("SELECT closed_at FROM op_sessions WHERE id = ? LIMIT 1");
    $stmtCa->execute([$motherId]);
    $closedAt = $stmtCa->fetchColumn();

    echo json_encode([
        'ok'        => true,
        'mother_id' => $motherId,
        'closed_at' => $closedAt,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

} catch (\Throwable $e) {
    error_log('[sb-force-close POST] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal'], JSON_UNESCAPED_UNICODE);
    exit;
}
