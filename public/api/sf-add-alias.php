<?php
declare(strict_types=1);
/**
 * POST /api/sf-add-alias.php
 *
 * Add an OCR alias for a supplier into ref_supplier_aliases.
 * Admin-only.
 *
 * Payload:
 *   csrf        — session CSRF token
 *   supplier_fk — INT UNSIGNED  (ref_suppliers.id)
 *   alias       — VARCHAR(255)  (the alias string to register)
 *   source      — 'manual' | 'observed'  (default 'manual')
 *
 * Returns JSON:
 *   { ok: true,  alias: { id, alias, source } }
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
if (!rl_check_and_log((int) $me['id'], 'sf_add_alias', 200, 3600, $ip, $pdo)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Limite de requêtes atteinte (200/h).']);
    exit;
}

// ── Input validation ──────────────────────────────────────────────────────────
$supplierFk = isset($_POST['supplier_fk']) ? (int) $_POST['supplier_fk'] : 0;
$alias      = trim($_POST['alias']  ?? '');
$source     = trim($_POST['source'] ?? 'manual');

if ($supplierFk <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'supplier_fk manquant ou invalide.']);
    exit;
}
if ($alias === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Alias vide.']);
    exit;
}
if (mb_strlen($alias) > 255) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Alias trop long (max 255 caractères).']);
    exit;
}
if (!in_array($source, ['manual', 'observed'], true)) {
    $source = 'manual';
}

try {
    // ── Verify supplier exists ────────────────────────────────────────────────
    $suppStmt = $pdo->prepare(
        'SELECT id, name FROM ref_suppliers WHERE id = ? LIMIT 1'
    );
    $suppStmt->execute([$supplierFk]);
    $supp = $suppStmt->fetch();
    if (!$supp) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Fournisseur introuvable.']);
        exit;
    }

    // ── Check for collision (who already has this alias?) ─────────────────────
    $checkStmt = $pdo->prepare(
        'SELECT a.supplier_id_fk, s.name AS owner_name
           FROM ref_supplier_aliases a
           JOIN ref_suppliers s ON s.id = a.supplier_id_fk
          WHERE a.alias = ?
          LIMIT 1'
    );
    $checkStmt->execute([$alias]);
    $existing = $checkStmt->fetch();
    if ($existing) {
        $ownerName = (string) $existing['owner_name'];
        $msg = ((int) $existing['supplier_id_fk'] === $supplierFk)
            ? "Cet alias est déjà attribué à ce fournisseur."
            : "Cet alias est déjà attribué à «{$ownerName}».";
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }

    $pdo->beginTransaction();

    // INSERT (UNIQUE on alias — INSERT IGNORE as safety net after the explicit check)
    $insertStmt = $pdo->prepare(
        'INSERT IGNORE INTO ref_supplier_aliases (supplier_id_fk, alias, source)
         VALUES (?, ?, ?)'
    );
    $insertStmt->execute([$supplierFk, $alias, $source]);
    $newId = (int) $pdo->lastInsertId();

    if ($newId === 0) {
        // Concurrent insert won the UNIQUE race
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Cet alias est déjà attribué (conflit concurrent).']);
        exit;
    }

    log_revision(
        $pdo, $me,
        'ref_supplier_aliases', $supplierFk,
        null,
        ['alias' => $alias, 'source' => $source, 'supplier_fk' => $supplierFk],
        'normal',
        'alias-add:' . $alias
    );

    $pdo->commit();

    echo json_encode([
        'ok'    => true,
        'alias' => [
            'id'     => $newId,
            'alias'  => $alias,
            'source' => $source,
        ],
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
}
