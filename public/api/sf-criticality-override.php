<?php
declare(strict_types=1);

/**
 * POST /api/sf-criticality-override.php
 *
 * Override the criticality field on a supplier.
 * Updates ONLY ref_suppliers.criticality — no other columns touched.
 * Admin-only.
 *
 * Payload:
 *   csrf            — session CSRF token
 *   supplier_id_fk  — INT UNSIGNED (required, must exist)
 *   criticality     — critique|non_critique (required)
 *
 * Returns:
 *   { ok: true, criticality }
 *   { ok: false, error: "..." }
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/db-write-helpers.php';
require __DIR__ . '/../../app/services/rate_limit.php';

header('Content-Type: application/json; charset=utf-8');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// ── Auth + role gate ──────────────────────────────────────────────────────────
require_login();
$me = current_user();
if (!is_admin($me)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin uniquement.']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!csrf_verify($_POST['csrf'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide. Rechargez la page.']);
    exit;
}

$pdo = maltytask_pdo();

// ── Rate limit ────────────────────────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
if (!rl_check_and_log((int) $me['id'], 'sf_criticality_override', 50, 3600, $ip, $pdo)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Limite de requêtes atteinte.']);
    exit;
}

// ── Input parsing ─────────────────────────────────────────────────────────────
$supplierId  = isset($_POST['supplier_id_fk']) ? (int) $_POST['supplier_id_fk'] : 0;
$criticality = trim($_POST['criticality'] ?? '');

// ── Validate supplier_id_fk ───────────────────────────────────────────────────
if ($supplierId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'supplier_id_fk invalide.']);
    exit;
}

// ── Validate criticality ──────────────────────────────────────────────────────
$VALID_CRITICALITIES = ['critique', 'non_critique'];
if (!in_array($criticality, $VALID_CRITICALITIES, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'criticality invalide.']);
    exit;
}

try {
    // 1. Verify supplier exists
    $supplierStmt = $pdo->prepare('SELECT id, criticality FROM ref_suppliers WHERE id = ? LIMIT 1');
    $supplierStmt->execute([$supplierId]);
    $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
    if (!$supplier) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Fournisseur introuvable.']);
        exit;
    }

    // 2. Snapshot BEFORE (only id + criticality fields)
    $before = [
        'id'          => $supplier['id'],
        'criticality' => $supplier['criticality'],
    ];
    $ts   = date('Ymd-His');
    $snap = json_encode($before, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    @file_put_contents(
        '/var/www/maltytask/data/snapshots/ref_suppliers-' . $supplierId . '-criticality-' . $ts . '.json',
        $snap
    );

    // Capture before state for revision log
    $beforeFull = bd_fetch_before($pdo, 'ref_suppliers', $supplierId);

    // 3. UPDATE only the criticality column
    $updateStmt = $pdo->prepare(
        'UPDATE ref_suppliers SET criticality = ? WHERE id = ?'
    );
    $updateStmt->execute([$criticality, $supplierId]);

    // Fetch after state
    $afterStmt = $pdo->prepare('SELECT * FROM ref_suppliers WHERE id = ? LIMIT 1');
    $afterStmt->execute([$supplierId]);
    $afterRow = $afterStmt->fetch(PDO::FETCH_ASSOC);

    // 4. log_revision
    log_revision($pdo, $me, 'ref_suppliers', $supplierId, $beforeFull, $afterRow ?? [], 'normal', null);

    // 5. Return result
    echo json_encode(['ok' => true, 'criticality' => $criticality]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
}
