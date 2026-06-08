<?php
declare(strict_types=1);
/**
 * POST /api/invoice-validate.php — operator-initiated invoice commit trigger (B1b).
 *
 * Validates a staged invoice by firing the background commit worker:
 *   sudo -u maltytask ingest-one-local-commit.sh <upload_id> <user_id>
 *
 * The commit worker replays the staged delivery_write_plan → writes inv_deliveries
 * (idempotent via dedup_key), applies energy_extract → inv_energydata, promotes any
 * DN Pending→Active, and stamps doc_invoices.validated_at = NOW() + validated_by.
 *
 * Completion is polled via GET /api/upload-status.php (extended to report validated_at).
 *
 * Input (POST body):
 *   upload_id  — doc_uploads.id (integer)
 *   csrf       — CSRF token
 *
 * Responses (JSON):
 *   { ok: true,  upload_id: N }                 — worker fired (200)
 *   { ok: false, error: 'invalid_input' }        — bad upload_id (400)
 *   { ok: false, error: 'not_found' }            — row not found / not owner (200)
 *   { ok: false, error: 'not_validatable' }      — wrong state / no plan (409)
 *   { ok: false, error: 'csrf_invalid' }         — CSRF check failed (400)
 *
 * Auth: require_login() + csrf_verify() — mirrors upload-document.php pattern.
 *
 * Anti-pattern: PHP query-param: read with ?? default, THEN validate (two-step).
 * Anti-pattern: no inline <style>/<script> — JS/CSS are external.
 * Anti-pattern: log_revision audit on every operator write.
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
$me     = current_user();
$userId = (int) $me['id'];

// ── CSRF (first — mirrors upload-document.php) ────────────────────────────────
if (!csrf_verify($_POST['csrf'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
    exit;
}

// ── Input: read with default THEN validate (two-step; PHP 8 NULL trap) ────────
$uploadIdRaw = $_POST['upload_id'] ?? '';
$uploadId    = (int) $uploadIdRaw;
if ($uploadId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_input']);
    exit;
}

$pdo = maltytask_pdo();

// ── Load the doc_uploads + doc_invoices row ───────────────────────────────────
// Owner check on doc_uploads enforced (user_id = current user).
// Dual-key join: doc_uploads.drive_file_id (UUID) = doc_files.file_id (UUID)
//                doc_files.id (BIGINT)            = doc_invoices.file_id (BIGINT)
$rowStmt = $pdo->prepare(
    "SELECT
         du.id                      AS upload_id,
         du.user_id,
         du.pipeline_status,
         du.drive_file_id,
         di.id                      AS invoice_id,
         di.validated_at,
         di.skipped_at,
         di.delivery_write_plan,
         di.invoice_ref,
         di.supplier_name
       FROM doc_uploads du
       JOIN doc_files   df ON df.file_id = du.drive_file_id
       JOIN doc_invoices di ON di.file_id = df.id
      WHERE du.id = ?
      LIMIT 1"
);
$rowStmt->execute([$uploadId]);
$row = $rowStmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    // 200 JSON — avoid leaking row existence
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

// ── Guard: must be in a validatable state ─────────────────────────────────────
// validated_at IS NULL       → not yet committed
// skipped_at IS NULL         → not rejected
// delivery_write_plan != NULL → plan was staged (excludes pre-B1a invoices)
if (
    $row['validated_at'] !== null ||
    $row['skipped_at']   !== null ||
    $row['delivery_write_plan'] === null
) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'not_validatable']);
    exit;
}

// ── Reset error_text so poller reads a clean slate ────────────────────────────
try {
    $clearErrStmt = $pdo->prepare(
        "UPDATE doc_uploads SET error_text = NULL WHERE id = ?"
    );
    $clearErrStmt->execute([$uploadId]);
} catch (Throwable) { /* non-fatal */ }

// ── Audit: operator-initiated validate ────────────────────────────────────────
log_revision(
    $pdo,
    $me,
    'doc_invoices',
    (int) $row['invoice_id'],
    [
        'validated_at' => null,
        'validated_by' => null,
    ],
    [
        'validated_at' => 'PENDING — commit worker firing',
        'validated_by' => $userId,
    ],
    'normal',
    'Validate déclenchée par opérateur (invoice-validate.php)'
);

// ── Fire the commit worker (async, like upload-document.php fires the stage worker) ──
// UPLOAD_COMMIT_CMD is the sudoers-wired commit wrapper.
// Pattern mirrors upload_ingest_trigger() in app/upload-ingest.php.
upload_commit_trigger($uploadId, $userId);

// ── Respond ───────────────────────────────────────────────────────────────────
echo json_encode(['ok' => true, 'upload_id' => $uploadId]);
