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
$summary = null; // invoice summary; populated below when processed

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

    // ── Invoice summary (lines + deliveries counts, totals) ──────────────
    // Join: doc_files → doc_invoices → doc_invoice_lines + inv_deliveries
    // Only populated when a doc_invoices row exists for this file.
    if ($doc_invoice_id !== null) {
        try {
            // Lines summary: count total, count by resolved status
            // active = inv_deliveries row with status='Active' for this invoice
            // pending = inv_deliveries row with status='Pending'
            // excluded = inv_deliveries rows with exclusion_class IS NOT NULL
            // unresolved = doc_invoice_lines with mi_id_fk IS NULL AND accounting IS NULL
            $sum_stmt = $pdo->prepare(
                "SELECT
                     COUNT(il.id)                                                AS lines_total,
                     SUM(CASE WHEN d.status = 'Active'
                                   AND (d.exclusion_class IS NULL)              THEN 1 ELSE 0 END) AS lines_active,
                     SUM(CASE WHEN d.status = 'Pending'                         THEN 1 ELSE 0 END) AS lines_pending,
                     SUM(CASE WHEN d.exclusion_class IS NOT NULL                THEN 1 ELSE 0 END) AS lines_excluded,
                     COALESCE(inv.total_ht, 0)                                  AS total_ht,
                     COALESCE(inv.currency, 'CHF')                              AS currency,
                     inv.supplier_name,
                     inv.invoice_ref
                   FROM doc_invoices inv
                   LEFT JOIN doc_invoice_lines il ON il.invoice_id = inv.id
                   LEFT JOIN inv_deliveries d
                          ON d.invoice_ref   = inv.invoice_ref
                         AND d.supplier_raw  = inv.supplier_name
                  WHERE inv.id = ?
                  GROUP BY inv.id"
            );
            $sum_stmt->execute([$doc_invoice_id]);
            $sum_row = $sum_stmt->fetch();

            if ($sum_row) {
                $summary = [
                    'lines_total'    => (int)   ($sum_row['lines_total']    ?? 0),
                    'lines_active'   => (int)   ($sum_row['lines_active']   ?? 0),
                    'lines_pending'  => (int)   ($sum_row['lines_pending']  ?? 0),
                    'lines_excluded' => (int)   ($sum_row['lines_excluded'] ?? 0),
                    'total_ht'       => (float) ($sum_row['total_ht']       ?? 0),
                    'currency'       => (string)($sum_row['currency']       ?? 'CHF'),
                    'supplier_name'  => $sum_row['supplier_name'] !== '' ? $sum_row['supplier_name'] : null,
                    'invoice_ref'    => $sum_row['invoice_ref']   !== '' ? $sum_row['invoice_ref']   : null,
                ];
            }
        } catch (Throwable) { /* non-fatal — summary simply omitted */ }
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

// ── B1b: Commit completion check ─────────────────────────────────────────────
// Polls doc_invoices.validated_at to detect commit success/failure.
// Only relevant when pipeline_status='processed' and doc_invoice_id is known.
// commit_status: 'pending' | 'done' | 'failed'
$commit_status    = null;
$commit_error     = null;
$commit_validated_at = null;

if ($doc_invoice_id !== null && $new_status === 'processed') {
    try {
        $commitStmt = $pdo->prepare(
            "SELECT validated_at, skipped_at FROM doc_invoices WHERE id = ? LIMIT 1"
        );
        $commitStmt->execute([$doc_invoice_id]);
        $commitRow = $commitStmt->fetch();
        if ($commitRow) {
            if ($commitRow['validated_at'] !== null) {
                $commit_status = 'done';
                $commit_validated_at = $commitRow['validated_at'];
            } elseif ($commitRow['skipped_at'] !== null) {
                $commit_status = 'rejected';
            } else {
                // Not yet committed — check if commit worker left an error.
                // error_text was already loaded in the initial SELECT ($row).
                if (!empty($row['error_text'])) {
                    $commit_status = 'failed';
                    $commit_error  = $row['error_text'];
                } else {
                    $commit_status = 'pending';
                }
            }
        }
    } catch (Throwable) { /* non-fatal */ }
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
if ($summary !== null) {
    $payload['summary'] = $summary;
}
// Expose error_text for failed status so the UI can display a meaningful message
if ($pipeline_status === 'failed') {
    $payload['error_text'] = $row['error_text'] ?? null;
}
// B1b: commit completion fields (only when a commit has been / is being attempted)
if ($commit_status !== null) {
    $payload['commit_status']       = $commit_status;
    $payload['commit_validated_at'] = $commit_validated_at;
    if ($commit_error !== null) {
        $payload['commit_error'] = $commit_error;
    }
}

echo json_encode($payload);
