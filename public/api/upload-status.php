<?php
declare(strict_types=1);

/**
 * GET /api/upload-status.php — polling endpoint for upload pipeline status.
 *
 * Query parameters (one required):
 *   upload_id=N    — preferred; doc_uploads.id
 *   drive_id=X     — fallback; doc_uploads.drive_file_id
 *
 * Auth: session required. Owner check enforced (user_id = current user).
 *
 * Returns JSON:
 * {
 *   "upload_id":           123,
 *   "drive_file_id":       "1ABC...",
 *   "pipeline_status":     "triggered"|"processed"|"failed"|"timeout"|"uploaded",
 *   "elapsed_seconds":     42,
 *   "review_queue_rq_id":  456,           // set when a doc_review_queue row exists
 *   "doc_invoice_id":      789,           // set when doc_invoices row exists
 *   "redirect_url":        "/modules/triage.php?rq_id=456"  // set when RQ row exists
 * }
 *
 * State derivation (idempotent):
 *   - 'processed' when a doc_files row exists for drive_file_id AND
 *     at least one of doc_invoices / doc_delivery_notes / doc_ambiguous links to it.
 *   - 'timeout'   when pipeline_started_at > 600 s ago AND no doc_files row yet.
 *   - Otherwise returns current doc_uploads.pipeline_status unchanged.
 *
 * Idempotent: purely read + state-derived UPDATE. Safe to poll every 2 s.
 */

require __DIR__ . '/../../app/auth.php';

require_login();
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'GET only.']);
    exit;
}

$pdo    = maltytask_pdo();
$userId = (int) $me['id'];

$fail = static function (int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
};

// ── Resolve the doc_uploads row ───────────────────────────────────────────────
$upload_id = isset($_GET['upload_id']) ? (int)$_GET['upload_id'] : 0;
$drive_id  = trim($_GET['drive_id'] ?? '');

if ($upload_id <= 0 && $drive_id === '') {
    $fail(400, 'upload_id ou drive_id requis.');
}

if ($upload_id > 0) {
    $stmt = $pdo->prepare(
        "SELECT id, user_id, drive_file_id, pipeline_status,
                pipeline_started_at, pipeline_finished_at, error_text,
                uploaded_at
           FROM doc_uploads
          WHERE id = ? AND user_id = ?
          LIMIT 1"
    );
    $stmt->execute([$upload_id, $userId]);
} else {
    // drive_id path — validate format first
    if (!preg_match('/^[A-Za-z0-9_\-]{10,200}$/', $drive_id)) {
        $fail(400, 'drive_id invalide.');
    }
    $stmt = $pdo->prepare(
        "SELECT id, user_id, drive_file_id, pipeline_status,
                pipeline_started_at, pipeline_finished_at, error_text,
                uploaded_at
           FROM doc_uploads
          WHERE drive_file_id = ? AND user_id = ?
          LIMIT 1"
    );
    $stmt->execute([$drive_id, $userId]);
}

$row = $stmt->fetch();
if (!$row) {
    $fail(404, 'Upload introuvable ou accès refusé.');
}

$upload_id      = (int) $row['id'];
$drive_file_id  = (string)($row['drive_file_id'] ?? '');
$pipeline_status = (string) $row['pipeline_status'];
$started_at     = $row['pipeline_started_at']
                  ? strtotime((string)$row['pipeline_started_at'])
                  : null;
$elapsed        = $started_at !== null ? (time() - $started_at) : null;

// ── Derive pipeline_status from downstream tables ─────────────────────────────
$review_queue_rq_id = null;
$doc_invoice_id     = null;
$redirect_url       = null;
$new_status         = $pipeline_status;

if ($drive_file_id !== '' && !in_array($pipeline_status, ['failed', 'processed'], true)) {
    // Look up doc_files row for this Drive file ID
    $fs = $pdo->prepare(
        "SELECT id FROM doc_files WHERE file_id = ? LIMIT 1"
    );
    $fs->execute([$drive_file_id]);
    $doc_file_row = $fs->fetch();

    if ($doc_file_row) {
        $doc_file_pk = (int) $doc_file_row['id'];

        // Check doc_invoices
        $inv_stmt = $pdo->prepare(
            "SELECT id FROM doc_invoices WHERE file_id = ? LIMIT 1"
        );
        $inv_stmt->execute([$doc_file_pk]);
        $inv_row = $inv_stmt->fetch();
        if ($inv_row) {
            $doc_invoice_id = (int) $inv_row['id'];
            $new_status = 'processed';
        }

        // Check doc_delivery_notes
        if ($new_status !== 'processed') {
            $dn_stmt = $pdo->prepare(
                "SELECT id FROM doc_delivery_notes WHERE file_id = ? LIMIT 1"
            );
            $dn_stmt->execute([$doc_file_pk]);
            if ($dn_stmt->fetch()) {
                $new_status = 'processed';
            }
        }

        // Check doc_ambiguous
        if ($new_status !== 'processed') {
            $amb_stmt = $pdo->prepare(
                "SELECT id FROM doc_ambiguous WHERE file_id = ? LIMIT 1"
            );
            $amb_stmt->execute([$doc_file_pk]);
            if ($amb_stmt->fetch()) {
                $new_status = 'processed';
            }
        }
    } elseif ($pipeline_status === 'triggered'
              && $started_at !== null
              && (time() - $started_at) > 600) {
        // No doc_files row and pipeline has been running > 10 min — timeout
        $new_status = 'timeout';
    }
}

// ── Look up doc_review_queue row if processed ─────────────────────────────────
if ($new_status === 'processed' && $drive_file_id !== '') {
    // Prefer file_id_fk join (reliable). Fall back to value=driveFileId
    // for ambiguous rows where file_id_fk may not be set.
    $rq_stmt = $pdo->prepare(
        "SELECT rq.id
           FROM doc_review_queue rq
           LEFT JOIN doc_files df ON df.id = rq.file_id_fk
          WHERE (df.file_id = ? OR rq.value = ?)
            AND rq.status = 'open'
          ORDER BY rq.created_at DESC
          LIMIT 1"
    );
    $rq_stmt->execute([$drive_file_id, $drive_file_id]);
    $rq_row = $rq_stmt->fetch();
    if ($rq_row) {
        $review_queue_rq_id = (int) $rq_row['id'];
        $redirect_url = '/modules/triage.php?rq_id=' . $review_queue_rq_id;
    }
}

// ── Persist derived status changes ────────────────────────────────────────────
if ($new_status !== $pipeline_status) {
    try {
        $upd = $pdo->prepare(
            "UPDATE doc_uploads
                SET pipeline_status      = ?,
                    pipeline_finished_at = CASE WHEN ? IN ('processed','failed','timeout')
                                               THEN NOW() ELSE pipeline_finished_at END
              WHERE id = ?"
        );
        $upd->execute([$new_status, $new_status, $upload_id]);
    } catch (Throwable) { /* non-fatal — response still valid */ }
    $pipeline_status = $new_status;
}

// ── Respond ───────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
$payload = [
    'ok'             => true,
    'upload_id'      => $upload_id,
    'drive_file_id'  => $drive_file_id !== '' ? $drive_file_id : null,
    'pipeline_status'=> $pipeline_status,
    'elapsed_seconds'=> $elapsed,
];
if ($review_queue_rq_id !== null) {
    $payload['review_queue_rq_id'] = $review_queue_rq_id;
}
if ($doc_invoice_id !== null) {
    $payload['doc_invoice_id'] = $doc_invoice_id;
}
if ($redirect_url !== null) {
    $payload['redirect_url'] = $redirect_url;
}

echo json_encode($payload);
