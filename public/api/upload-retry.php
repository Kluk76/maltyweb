<?php
declare(strict_types=1);

/**
 * POST /api/upload-retry.php — operator self-service retry for a failed/timeout upload.
 *
 * Input (POST body):
 *   id    — doc_uploads.id (integer)
 *   csrf  — CSRF token
 *
 * Responses:
 *   { ok: true,  upload_id: <id> }                  — retry triggered (200)
 *   { ok: false, error: 'not_found' }               — no such row / not owner (200)
 *   { ok: false, error: 'file_gone' }               — inbox file missing on disk (200)
 *   { ok: false, error: 'not_retryable' }           — row not in failed/timeout state (409)
 *
 * Auth: require_login() + csrf_verify() — same pattern as upload-document.php.
 *
 * Race-safety: the UPDATE WHERE clause is the concurrency guard (double-click safe).
 * pipeline_started_at=NOW() reset is mandatory so upload-status.php does not
 * instantly re-flip the row back to 'timeout' (which keys on started_at > 600 s).
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/db-write-helpers.php';
require __DIR__ . '/../../app/upload-ingest.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST uniquement.']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
require_login();
$me = current_user();

// ── CSRF (first validation — mirrors upload-document.php pattern) ─────────────
if (!csrf_verify($_POST['csrf'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide.']);
    exit;
}

// ── Input: read with default THEN validate ────────────────────────────────────
$idRaw = $_POST['id'] ?? '';
$uploadId = (int) $idRaw;
if ($uploadId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'id invalide.']);
    exit;
}

$pdo    = maltytask_pdo();
$userId = (int) $me['id'];

// ── Step 1: Load the doc_uploads row (owner check enforced) ───────────────────
$rowStmt = $pdo->prepare(
    "SELECT id, user_id, storage_filename, pipeline_status
       FROM doc_uploads
      WHERE id = ? AND user_id = ?
      LIMIT 1"
);
$rowStmt->execute([$uploadId, $userId]);
$row = $rowStmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    // 200 JSON (not a 404) — avoids leaking row existence to other users
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

// ── Step 2: Rebuild and validate the inbox path ───────────────────────────────
$storageFilename = (string) ($row['storage_filename'] ?? '');
$inboxPath = '/var/www/maltytask/storage/documents/inbox/' . $storageFilename;

// Strict inbox path validation — same regex used in upload-document.php
if (!preg_match(
    '#^/var/www/maltytask/storage/documents/inbox/\d{4}-\d{2}-\d{2}_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[a-z]{2,5}$#',
    $inboxPath
)) {
    // Malformed filename stored in DB — cannot safely retry
    echo json_encode(['ok' => false, 'error' => 'file_gone']);
    exit;
}

if (!file_exists($inboxPath)) {
    // File no longer in inbox (already processed and moved, or deleted)
    echo json_encode(['ok' => false, 'error' => 'file_gone']);
    exit;
}

// ── Step 3: Race-guard UPDATE — only succeeds if row is in failed/timeout ─────
// pipeline_started_at=NOW() reset is mandatory: without it upload-status.php
// would instantly re-flip to 'timeout' (it checks started_at > 600 s).
$updStmt = $pdo->prepare(
    "UPDATE doc_uploads
        SET pipeline_status     = 'triggered',
            pipeline_started_at = NOW(),
            pipeline_finished_at = NULL,
            error_text          = NULL
      WHERE id = ?
        AND pipeline_status IN ('failed','timeout')"
);
$updStmt->execute([$uploadId]);

if ($updStmt->rowCount() === 0) {
    // Row not in a retryable state (already triggered/processed by another request)
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'not_retryable']);
    exit;
}

// ── Audit trail for operator-initiated state flip ─────────────────────────────
// action='update' is derived by log_revision when $before is non-null.
// The audit_row_revisions.action ENUM has only 'insert'/'update' — no 'delete'.
log_revision(
    $pdo,
    $me,
    'doc_uploads',
    $uploadId,
    ['pipeline_status' => $row['pipeline_status']],
    [
        'pipeline_status'     => 'triggered',
        'pipeline_started_at' => 'NOW() — reset by retry',
        'pipeline_finished_at'=> null,
        'error_text'          => null,
    ],
    'normal',
    'Réessai opérateur (upload-retry.php)'
);

// ── Step 4: Fire the worker ───────────────────────────────────────────────────
// inbox_path already validated against the strict regex above.
upload_ingest_trigger($inboxPath);

// ── Step 5: Respond ───────────────────────────────────────────────────────────
echo json_encode(['ok' => true, 'upload_id' => $uploadId]);
