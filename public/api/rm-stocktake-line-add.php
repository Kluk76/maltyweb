<?php
declare(strict_types=1);
/**
 * POST /api/rm-stocktake-line-add.php
 *
 * Adds one per-pallet line to inv_rm_stocktake_lines, then recomputes
 * the rollup into inv_rm_stocktake.counted_qty for that (mi_id, period).
 *
 * Request (POST body):
 *   csrf        — session CSRF token
 *   period      — YYYY-MM
 *   mi_id_fk    — INT > 0, must be is_inventoried + is_active in ref_mi
 *   qty         — numeric ≥ 0 (comma→dot normalised)
 *
 * Response 200 OK:
 *   { ok: true, line: {id, qty, mi_id_fk, mi_name},
 *     mi_subtotal, grand_total,
 *     lines_for_mi: [{id, qty, counted_at}],
 *     csrf: <fresh token> }
 *
 * CSRF expired (session rotated):
 *   { ok: false, reason: 'expired', csrf: <fresh token> }
 *   HTTP 401
 *
 * Validation error:
 *   { ok: false, error: '...' }
 *   HTTP 400
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

// ── CSRF (must be first validation — return fresh token on fail so JS can retry) ─
$postedCsrf = $_POST['csrf'] ?? null;
if (!csrf_verify($postedCsrf)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'expired', 'csrf' => csrf_token()]);
    exit;
}

// ── Input: read with default THEN validate (two-step pattern) ─────────────────
$periodRaw = $_POST['period'] ?? '';
$miFkRaw   = $_POST['mi_id_fk'] ?? '';
$qtyRaw    = $_POST['qty'] ?? '';

// Period
$period = trim((string) $periodRaw);
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Période invalide (format: AAAA-MM).']);
    exit;
}

// mi_id_fk — must be a positive integer
$miFk = (int) $miFkRaw;
if ($miFk <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'mi_id_fk manquant ou invalide.']);
    exit;
}

// qty — numeric, comma→dot, ≥ 0
$qtyStr = str_replace(',', '.', trim((string) $qtyRaw));
if (!is_numeric($qtyStr) || (float) $qtyStr < 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Quantité invalide (nombre ≥ 0 attendu).']);
    exit;
}

try {
    $pdo = maltytask_pdo();

    // ── Verify MI exists, is inventoried + active ─────────────────────────────
    $miStmt = $pdo->prepare(
        'SELECT id, mi_id, name, pricing_unit
           FROM ref_mi
          WHERE id = ? AND is_inventoried = 1 AND is_active = 1
          LIMIT 1'
    );
    $miStmt->execute([$miFk]);
    $mi = $miStmt->fetch(PDO::FETCH_ASSOC);
    if (!$mi) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ingrédient introuvable ou non inventoriable.']);
        exit;
    }
    $miId   = $mi['mi_id'];
    $miName = $mi['name'];

    // ── Build row_hash — unique per insert ────────────────────────────────────
    // Two pallets of identical qty are TWO real lines. We include microtime so
    // two simultaneous identical submissions produce distinct hashes and both
    // survive the uq_row_hash UNIQUE constraint.
    $countedAt = date('Y-m-d H:i:s');
    $nonce     = substr(hash('sha256', uniqid('', true) . microtime(true)), 0, 16);
    $rowHash   = hash('sha256', implode('|', [
        (string) $miFk,
        $miId,
        $period,
        $qtyStr,
        $me['username'] ?? 'unknown',
        $countedAt,
        $nonce,
    ]));

    // ── Insert the line ───────────────────────────────────────────────────────
    $insStmt = $pdo->prepare(
        'INSERT INTO inv_rm_stocktake_lines
            (mi_id_fk, mi_id, period, qty, source, counted_by, counted_at, is_active, row_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $insStmt->execute([
        $miFk,
        $miId,
        $period,
        $qtyStr,
        'web-form-line',
        $me['username'] ?? null,
        $countedAt,
        $rowHash,
    ]);
    $lineId = (int) $pdo->lastInsertId();

    // Audit the line insert
    log_revision(
        $pdo, $me,
        'inv_rm_stocktake_lines', $lineId,
        null,
        [
            'mi_id_fk'   => $miFk,
            'mi_id'      => $miId,
            'period'     => $period,
            'qty'        => $qtyStr,
            'source'     => 'web-form-line',
            'counted_by' => $me['username'] ?? null,
            'counted_at' => $countedAt,
            'is_active'  => 1,
        ],
        'normal',
        'Ajout ligne pallet RM période ' . $period
    );

    // ── Recompute rollup into inv_rm_stocktake ────────────────────────────────
    rm_recompute_rollup($pdo, $me, $miId, $miFk, $period);

    // ── Build response: active lines for this MI, subtotal, grand total ───────
    $linesStmt = $pdo->prepare(
        'SELECT id, qty, counted_at
           FROM inv_rm_stocktake_lines
          WHERE mi_id = ? AND period = ? AND is_active = 1
          ORDER BY id ASC'
    );
    $linesStmt->execute([$miId, $period]);
    $linesForMi = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

    // mi_subtotal = sum of active lines for this MI
    $miSubtotal = array_sum(array_column($linesForMi, 'qty'));

    // grand_total = sum of ALL active lines for this period
    $gtStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(qty), 0) AS grand_total
           FROM inv_rm_stocktake_lines
          WHERE period = ? AND is_active = 1'
    );
    $gtStmt->execute([$period]);
    $grandTotal = (float) $gtStmt->fetchColumn();

    echo json_encode([
        'ok'          => true,
        'line'        => [
            'id'       => $lineId,
            'qty'      => $qtyStr,
            'mi_id_fk' => $miFk,
            'mi_name'  => $miName,
        ],
        'mi_subtotal'  => $miSubtotal,
        'grand_total'  => $grandTotal,
        'lines_for_mi' => $linesForMi,
        'csrf'         => csrf_token(),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . pdo_friendly_error($e, 'rm-line-add')]);
}
