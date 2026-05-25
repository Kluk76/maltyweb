<?php
declare(strict_types=1);
/**
 * POST /api/sf-pin-field.php
 *
 * Pin or unpin a supplier field into ref_supplier_field_pins.
 * Admin-only.
 *
 * Payload:
 *   csrf         — session CSRF token
 *   supplier_fk  — INT UNSIGNED  (ref_suppliers.id)
 *   field_name   — VARCHAR(64)   (from whitelist below)
 *   pinned_value — TEXT          (value to pin; ignored for unpin)
 *   pin_reason   — TEXT          (optional)
 *   action       — 'pin' | 'unpin'
 *
 * Returns JSON:
 *   { ok: true,  pin: { field_name, pinned_value, pinned_by, pinned_at } }
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
if (!rl_check_and_log((int) $me['id'], 'sf_pin_field', 200, 3600, $ip, $pdo)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Limite de requêtes atteinte (200/h).']);
    exit;
}

// ── Input validation — two-step: read with default, then validate ──────────────
$supplierFk  = isset($_POST['supplier_fk'])  ? (int) $_POST['supplier_fk']  : 0;
$fieldName   = trim($_POST['field_name']   ?? '');
$pinnedValue = $_POST['pinned_value'] ?? null;
$pinnedValue = ($pinnedValue !== null) ? trim($pinnedValue) : null;
$pinReason   = trim($_POST['pin_reason']  ?? '');
$action      = trim($_POST['action']      ?? 'pin');

// Whitelist: fields that may be pinned
const PINNABLE_FIELDS = [
    'gl_account', 'currency', 'country', 'vat_regime', 'vat_number',
    'parser_key', 'hors_perimetre_cogs', 'sporadique',
];

if ($supplierFk <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'supplier_fk manquant ou invalide.']);
    exit;
}
if (!in_array($fieldName, PINNABLE_FIELDS, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Champ non autorisé : ' . $fieldName]);
    exit;
}
if (!in_array($action, ['pin', 'unpin'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Action invalide.']);
    exit;
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

    $pdo->beginTransaction();

    // ── Read before-state for audit ───────────────────────────────────────────
    $pinStmt = $pdo->prepare(
        'SELECT supplier_fk, field_name, pinned_value, pinned_by, pinned_at, pin_reason
           FROM ref_supplier_field_pins
          WHERE supplier_fk = ? AND field_name = ?
          LIMIT 1'
    );
    $pinStmt->execute([$supplierFk, $fieldName]);
    $existingPin = $pinStmt->fetch() ?: null;
    $before = $existingPin ? [
        'pinned_value' => $existingPin['pinned_value'],
        'pinned_by'    => $existingPin['pinned_by'],
    ] : null;

    if ($action === 'pin') {
        // UPSERT pin
        $upsert = $pdo->prepare(
            'INSERT INTO ref_supplier_field_pins
               (supplier_fk, field_name, pinned_value, pinned_by, pinned_at, pin_reason)
             VALUES (?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
               pinned_value = VALUES(pinned_value),
               pinned_by    = VALUES(pinned_by),
               pinned_at    = NOW(),
               pin_reason   = VALUES(pin_reason)'
        );
        $pinnedBy = (string) ($me['username'] ?? 'web');
        $upsert->execute([
            $supplierFk,
            $fieldName,
            $pinnedValue !== '' ? $pinnedValue : null,
            $pinnedBy,
            $pinReason !== '' ? $pinReason : null,
        ]);

        // Update supplier last_modified_by
        $pdo->prepare('UPDATE ref_suppliers SET last_modified_by = ? WHERE id = ?')
            ->execute(['web', $supplierFk]);

        $after = ['pinned_value' => $pinnedValue, 'pinned_by' => $pinnedBy];

        log_revision(
            $pdo, $me,
            'ref_supplier_field_pins', $supplierFk,
            $before, $after,
            'normal',
            'field-pin:' . $fieldName
        );

        $pdo->commit();

        // Fetch the saved pin for response
        $pinStmt->execute([$supplierFk, $fieldName]);
        $saved = $pinStmt->fetch();

        echo json_encode([
            'ok'  => true,
            'pin' => [
                'field_name'   => $fieldName,
                'pinned_value' => $saved['pinned_value'] ?? null,
                'pinned_by'    => $saved['pinned_by']    ?? $pinnedBy,
                'pinned_at'    => $saved['pinned_at']    ?? date('Y-m-d H:i:s'),
                'pin_reason'   => $saved['pin_reason']   ?? null,
            ],
        ]);

    } else {
        // unpin
        if ($existingPin === null) {
            $pdo->rollBack();
            echo json_encode(['ok' => true, 'pin' => null, 'was_absent' => true]);
            exit;
        }

        // Snapshot before delete
        $ts   = date('Ymd-His');
        $snap = json_encode($existingPin, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        @file_put_contents(
            '/var/www/maltytask/data/snapshots/ref_supplier_field_pins-' . $supplierFk . '-' . $ts . '.json',
            $snap
        );

        $pdo->prepare(
            'DELETE FROM ref_supplier_field_pins WHERE supplier_fk = ? AND field_name = ?'
        )->execute([$supplierFk, $fieldName]);

        $pdo->prepare('UPDATE ref_suppliers SET last_modified_by = ? WHERE id = ?')
            ->execute(['web', $supplierFk]);

        log_revision(
            $pdo, $me,
            'ref_supplier_field_pins', $supplierFk,
            $before, ['pinned_value' => null, 'deleted' => true],
            'normal',
            'field-unpin:' . $fieldName
        );

        $pdo->commit();

        echo json_encode(['ok' => true, 'pin' => null]);
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
}
