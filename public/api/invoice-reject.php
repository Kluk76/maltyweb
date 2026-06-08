<?php
declare(strict_types=1);
/**
 * POST /api/invoice-reject.php — operator-initiated invoice rejection (B1b).
 *
 * Marks an invoice as rejected (skipped) and emits a self-sufficient
 * doc_review_queue row as a parser-improvement signal.
 *
 * Input (POST body):
 *   upload_id  — doc_uploads.id (integer)
 *   reason     — optional free-text reason (max 90 chars after trim, to fit varchar(128))
 *   csrf       — CSRF token
 *
 * Responses (JSON):
 *   { ok: true }                                  — rejected (200)
 *   { ok: false, error: 'invalid_input' }          — bad upload_id (400)
 *   { ok: false, error: 'not_found' }              — row not found (200)
 *   { ok: false, error: 'not_rejectable' }         — already validated/rejected (409)
 *   { ok: false, error: 'csrf_invalid' }           — CSRF check failed (400)
 *
 * Auth: require_login() + csrf_verify().
 *
 * RQ row shape (self-sufficient — operator never opens the PDF):
 *   type       = 'invoice-line-items-needed' (existing ENUM value for parser signals)
 *   value      = supplier_name | invoice_ref (human-readable identity)
 *   context    = JSON: {uploadId, invoiceId, filename, reason, lineCount, parserName}
 *   sources    = original_filename
 *   dedup_key  = 'reject:upload:<upload_id>'  (one signal per upload)
 *   invoice_ref = invoice_ref (for triage linking)
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/db-write-helpers.php';

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

// ── CSRF ──────────────────────────────────────────────────────────────────────
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

// Reason: optional, truncate to 90 chars (col is varchar(128);
// "operator-rejected: " prefix takes ~20 chars → 128 - 20 = 108, trim to 90 for safety).
$reasonRaw = trim($_POST['reason'] ?? '');
$reason    = $reasonRaw !== '' ? substr($reasonRaw, 0, 90) : null;

$pdo = maltytask_pdo();

// ── Load doc_uploads + doc_invoices ──────────────────────────────────────────
$rowStmt = $pdo->prepare(
    "SELECT
         du.id                      AS upload_id,
         du.user_id,
         du.original_filename,
         di.id                      AS invoice_id,
         di.validated_at,
         di.skipped_at,
         di.skipped_reason,
         di.invoice_ref,
         di.supplier_name,
         di.total_ht,
         di.currency,
         di.parser_name,
         di.file_id                 AS doc_file_pk,
         (SELECT COUNT(*) FROM doc_invoice_lines WHERE invoice_id = di.id) AS line_count
       FROM doc_uploads du
       JOIN doc_files   df ON df.file_id = du.drive_file_id
       JOIN doc_invoices di ON di.file_id = df.id
      WHERE du.id = ?
      LIMIT 1"
);
$rowStmt->execute([$uploadId]);
$row = $rowStmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

// ── Guard: not already validated or rejected ─────────────────────────────────
if ($row['validated_at'] !== null || $row['skipped_at'] !== null) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'not_rejectable']);
    exit;
}

$invoiceId  = (int) $row['invoice_id'];
$docFilePk  = (int) $row['doc_file_pk'];
$lineCount  = (int) $row['line_count'];
$parserName = (string) ($row['parser_name'] ?? '');
$filename   = (string) ($row['original_filename'] ?? '');
$suppName   = (string) ($row['supplier_name'] ?? '');
$invRef     = (string) ($row['invoice_ref'] ?? '');

// ── Set skipped_at + skipped_reason on doc_invoices ──────────────────────────
// skipped_reason format: "operator-rejected" + optional ": <reason>"
$skipReason = 'operator-rejected';
if ($reason !== null && $reason !== '') {
    $skipReason .= ': ' . $reason;
}
// varchar(128) — safe: "operator-rejected" = 18 chars + ": " + 90 chars = 110 max
$skipReason = substr($skipReason, 0, 128);

$skipStmt = $pdo->prepare(
    "UPDATE doc_invoices
        SET skipped_at     = NOW(),
            skipped_reason = ?
      WHERE id = ?
        AND validated_at IS NULL
        AND skipped_at   IS NULL"
);
$skipStmt->execute([$skipReason, $invoiceId]);

if ($skipStmt->rowCount() === 0) {
    // Race condition — already rejected/validated by another request
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'not_rejectable']);
    exit;
}

// ── Audit ─────────────────────────────────────────────────────────────────────
log_revision(
    $pdo,
    $me,
    'doc_invoices',
    $invoiceId,
    [
        'skipped_at'     => null,
        'skipped_reason' => $row['skipped_reason'],
    ],
    [
        'skipped_at'     => 'NOW()',
        'skipped_reason' => $skipReason,
    ],
    'normal',
    'Refus opérateur (invoice-reject.php)'
);

// ── Emit self-sufficient RQ row (parser-improvement signal) ──────────────────
// Type: 'invoice-line-items-needed' — existing ENUM value, used for parser signals.
// The RQ row must be self-sufficient: supplier, invoiceRef, filename, lineCount
// so the operator/dev never needs to open the PDF to understand what was rejected.
//
// dedup_key: 'reject:upload:<upload_id>' — one signal per upload;
//            INSERT IGNORE prevents duplicate rows on double-click.
$rqValue  = $suppName !== '' ? $suppName : ($invRef !== '' ? $invRef : $filename);
$rqValue  = substr($rqValue, 0, 512);

$rqContext = json_encode([
    'uploadId'    => $uploadId,
    'invoiceId'   => $invoiceId,
    'filename'    => $filename,
    'supplierName'=> $suppName,
    'invoiceRef'  => $invRef !== '' ? $invRef : null,
    'lineCount'   => $lineCount,
    'parserName'  => $parserName !== '' ? $parserName : null,
    'reason'      => $reason,
    'rejectedBy'  => $me['username'] ?? null,
], JSON_UNESCAPED_UNICODE);

$rqDedupKey  = 'reject:upload:' . $uploadId;
$rqQueueId   = 'rej-' . $uploadId . '-' . time();
$rqSources   = $filename !== '' ? substr($filename, 0, 512) : null;
$rqInvRef    = $invRef !== '' ? substr($invRef, 0, 128) : null;

try {
    $rqStmt = $pdo->prepare(
        "INSERT IGNORE INTO doc_review_queue
            (queue_id, type, value, context, sources, dedup_key,
             status, decision, priority, invoice_ref, file_id_fk, last_seen_at)
         VALUES
            (?, 'invoice-line-items-needed', ?, ?, ?, ?,
             'open', 'pending', 10, ?, ?, CURDATE())"
    );
    $rqStmt->execute([
        $rqQueueId,
        $rqValue,
        $rqContext,
        $rqSources,
        $rqDedupKey,
        $rqInvRef,
        $docFilePk > 0 ? $docFilePk : null,
    ]);
} catch (Throwable $e) {
    // RQ write failure is non-fatal — rejection itself already succeeded.
    error_log('invoice-reject.php: RQ insert failed for upload_id=' . $uploadId . ': ' . $e->getMessage());
}

// ── Respond ───────────────────────────────────────────────────────────────────
echo json_encode(['ok' => true]);
