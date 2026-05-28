<?php
declare(strict_types=1);

/**
 * POST /api/admin/rq-bulk-purge.php
 *
 * Admin-only handler: bulk-DELETE doc_review_queue rows by type.
 *
 * Replaces the retired scripts/purge-rq-type.js (BSF-era CLI tool).
 *
 * Payload (POST):
 *   csrf   — session token (required)
 *   type   — doc_review_queue.type enum value (required)
 *   status — comma-separated status values to filter (optional; default 'open,in_progress')
 *   confirm — must equal '1' (double-confirmation guard)
 *
 * Returns JSON:
 *   { ok: true,  deleted: N, type: '...' }
 *   { ok: false, error: '...' }
 *
 * Auditing: every deleted row's before-state is written to audit_row_revisions
 * with action='update' and after_json={"_deleted":true} (the action ENUM on that
 * table does not have a 'delete' variant; this is the convention used here).
 */

require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/csrf.php';
require __DIR__ . '/../../../app/db-write-helpers.php';

require_login();
require_admin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!csrf_verify($_POST['csrf'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_POST['confirm'] ?? '') !== '1') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Confirmation manquante (confirm=1 requis)'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Whitelist type against the ENUM ──────────────────────────────────────────
$VALID_TYPES = [
    'supplier-unknown', 'ingredient-unknown', 'gl-drift',
    'archive-candidate', 'inactive-candidate',
    'dynamic-vs-take-drift', 'rm-stale', 'rm-negative', 'rm-orphan-mi',
    'invoice-no-dn', 'dn-no-invoice', 'photonote-audit', 'sales-sku-unknown',
    'doc-classify-ambiguous', 'invoice-line-items-needed',
    'dn-invoice-duplicate', 'dn-low-confidence-line', 'sku-bom-unresolved',
];

$type = trim($_POST['type'] ?? '');
if ($type === '' || !in_array($type, $VALID_TYPES, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Type invalide: «{$type}»"], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Status filter ─────────────────────────────────────────────────────────────
$VALID_STATUSES = ['open', 'in_progress', 'resolved', 'rejected'];
$statusRaw = trim($_POST['status'] ?? 'open,in_progress');
$statuses  = array_filter(array_map('trim', explode(',', $statusRaw)));
foreach ($statuses as $s) {
    if (!in_array($s, $VALID_STATUSES, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Statut invalide: «{$s}»"], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (empty($statuses)) {
    $statuses = ['open', 'in_progress'];
}

$pdo = maltytask_pdo();
$me  = current_user();

try {
    $pdo->beginTransaction();

    // ── Fetch rows to delete (before-state for audit) ─────────────────────────
    $inStatus = implode(',', array_fill(0, count($statuses), '?'));
    $params   = array_merge([$type], array_values($statuses));
    $selStmt  = $pdo->prepare(
        "SELECT * FROM doc_review_queue
          WHERE type = ? AND status IN ({$inStatus})
          ORDER BY id"
    );
    $selStmt->execute($params);
    $rows = $selStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $pdo->rollBack();
        echo json_encode(['ok' => true, 'deleted' => 0, 'type' => $type], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Collect PKs ───────────────────────────────────────────────────────────
    $ids = array_map(static fn(array $r) => (int)$r['id'], $rows);
    $inIds = implode(',', array_fill(0, count($ids), '?'));

    // ── Write audit trail (one row per deleted RQ entry) ─────────────────────
    // Convention: action='update', after_json={'_deleted':true, 'deleted_by': user}.
    // Mirrors before-state for forensic rollback.
    $userId   = (int)$me['id'];
    $username = (string)$me['username'];
    $ip       = isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : null;
    $auditStmt = $pdo->prepare(
        "INSERT INTO audit_row_revisions
           (user_id, username, ip, target_table, target_pk, action, before_json, after_json, comment, qc_flag)
         VALUES (?, ?, ?, 'doc_review_queue', ?, 'update', ?, ?, ?, 'normal')"
    );
    $afterJson = json_encode(
        ['_deleted' => true, 'deleted_by' => $username, 'bulk_purge_type' => $type],
        JSON_UNESCAPED_UNICODE
    );
    $comment = "bulk-purge type={$type} via admin handler";
    foreach ($rows as $row) {
        $auditStmt->execute([
            $userId,
            $username,
            $ip,
            (int)$row['id'],
            json_encode($row, JSON_UNESCAPED_UNICODE),
            $afterJson,
            $comment,
        ]);
    }

    // ── DELETE ────────────────────────────────────────────────────────────────
    $delStmt = $pdo->prepare("DELETE FROM doc_review_queue WHERE id IN ({$inIds})");
    $delStmt->execute($ids);
    $deleted = $delStmt->rowCount();

    $pdo->commit();

    echo json_encode([
        'ok'      => true,
        'deleted' => $deleted,
        'type'    => $type,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
