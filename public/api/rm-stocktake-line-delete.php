<?php
declare(strict_types=1);
/**
 * POST /api/rm-stocktake-line-delete.php
 *
 * Soft-deletes (is_active = 0) one line from inv_rm_stocktake_lines, then
 * recomputes the rollup into inv_rm_stocktake.counted_qty.
 *
 * If the last active line for an MI is deleted, rollup resets counted_qty = NULL
 * (final_qty falls back to expected_qty — NULL-vs-0 invariant preserved).
 *
 * Request (POST body):
 *   csrf    — session CSRF token
 *   line_id — BIGINT UNSIGNED > 0
 *   period  — YYYY-MM (for grand_total response)
 *
 * Response 200 OK:
 *   { ok: true, mi_subtotal, grand_total, csrf: <fresh token> }
 *
 * CSRF expired:
 *   { ok: false, reason: 'expired', csrf: <fresh token> }
 *   HTTP 401
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/db-write-helpers.php';
require __DIR__ . '/../../app/rm-stocktake-rollup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
require_login();
$me = current_user();
if ($me === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'expired']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
$postedCsrf = $_POST['csrf'] ?? null;
if (!csrf_verify($postedCsrf)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'expired', 'csrf' => csrf_token()]);
    exit;
}

// ── Input validation ──────────────────────────────────────────────────────────
$lineIdRaw = $_POST['line_id'] ?? '';
$periodRaw = $_POST['period']  ?? '';

$lineId = (int) $lineIdRaw;
if ($lineId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'line_id manquant ou invalide.']);
    exit;
}

$period = trim((string) $periodRaw);
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Période invalide.']);
    exit;
}

try {
    $pdo = maltytask_pdo();

    // ── Fetch the line to soft-delete ─────────────────────────────────────────
    $lineStmt = $pdo->prepare(
        'SELECT id, mi_id_fk, mi_id, period, qty, is_active
           FROM inv_rm_stocktake_lines
          WHERE id = ? LIMIT 1'
    );
    $lineStmt->execute([$lineId]);
    $line = $lineStmt->fetch(PDO::FETCH_ASSOC);

    if (!$line) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Ligne introuvable.']);
        exit;
    }
    if (!(int) $line['is_active']) {
        // Already deleted — idempotent: re-run the rollup and return ok
        $miId = $line['mi_id'];
        $miFk = (int) $line['mi_id_fk'];
    } else {
        $miId = $line['mi_id'];
        $miFk = (int) $line['mi_id_fk'];

        // Before snapshot
        $before = $line;

        // Soft-delete: is_active = 0
        // audit_row_revisions.action has no 'delete' value — tombstone via action='update'
        // with is_active=0, per house convention.
        $delStmt = $pdo->prepare(
            'UPDATE inv_rm_stocktake_lines SET is_active = 0 WHERE id = ?'
        );
        $delStmt->execute([$lineId]);

        $after = array_merge($before, [
            'is_active'   => 0,
            '_tombstone'  => 'deleted_by_rm_pallet_form',
        ]);

        log_revision(
            $pdo, $me,
            'inv_rm_stocktake_lines', $lineId,
            $before,
            $after,
            'normal',
            'Suppression ligne pallet RM période ' . $period
        );
    }

    // ── Recompute rollup (NULL reset if last line deleted) ────────────────────
    rm_recompute_rollup($pdo, $me, $miId, $miFk, $period);

    // ── Build response ────────────────────────────────────────────────────────
    $subStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(qty), 0) AS sub
           FROM inv_rm_stocktake_lines
          WHERE mi_id = ? AND period = ? AND is_active = 1'
    );
    $subStmt->execute([$miId, $period]);
    $miSubtotal = (float) $subStmt->fetchColumn();

    $gtStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(qty), 0) AS grand_total
           FROM inv_rm_stocktake_lines
          WHERE period = ? AND is_active = 1'
    );
    $gtStmt->execute([$period]);
    $grandTotal = (float) $gtStmt->fetchColumn();

    echo json_encode([
        'ok'          => true,
        'mi_subtotal'  => $miSubtotal,
        'grand_total'  => $grandTotal,
        'csrf'         => csrf_token(),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . pdo_friendly_error($e, 'rm-line-delete')]);
}
