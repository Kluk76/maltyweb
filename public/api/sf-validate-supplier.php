<?php
declare(strict_types=1);
/**
 * POST /api/sf-validate-supplier.php
 *
 * Validate (curate) a supplier fiche:
 *   - If commissioning_state='draft': flip to 'active'.
 *   - Always: bulk-UPSERT pins for the confirmed_fields list.
 *
 * Admin-only. Uses SELECT … FOR UPDATE for idempotency guard.
 *
 * Payload:
 *   csrf             — session CSRF token
 *   supplier_fk      — INT UNSIGNED
 *   confirmed_fields — JSON array of field_name strings (from whitelist)
 *
 * Returns JSON:
 *   { ok: true, supplier: { id, name, commissioning_state }, already_active: bool }
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
if (!rl_check_and_log((int) $me['id'], 'sf_validate_supplier', 50, 3600, $ip, $pdo)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Limite de requêtes atteinte.']);
    exit;
}

// ── Input validation ──────────────────────────────────────────────────────────
$supplierFk      = isset($_POST['supplier_fk']) ? (int) $_POST['supplier_fk'] : 0;
$confirmedRaw    = $_POST['confirmed_fields'] ?? '[]';
$confirmedFields = json_decode((string) $confirmedRaw, true);
if (!is_array($confirmedFields)) $confirmedFields = [];

// Whitelist for pinnable fields
const VALIDATE_PINNABLE_FIELDS = [
    'gl_account', 'currency', 'country', 'vat_regime', 'vat_number',
    'parser_key', 'hors_perimetre_cogs', 'sporadique',
];

// Filter confirmed_fields to whitelist
$confirmedFields = array_values(array_filter(
    $confirmedFields,
    fn($f) => in_array($f, VALIDATE_PINNABLE_FIELDS, true)
));

if ($supplierFk <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'supplier_fk manquant ou invalide.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // ── SELECT FOR UPDATE — idempotency guard against concurrent validates ─────
    $lockStmt = $pdo->prepare(
        'SELECT id, name, commissioning_state, gl_account, currency, country,
                vat_number, vat_regime, parser_key, hors_perimetre_cogs, sporadique
           FROM ref_suppliers
          WHERE id = ?
          LIMIT 1
          FOR UPDATE'
    );
    $lockStmt->execute([$supplierFk]);
    $supp = $lockStmt->fetch();

    if (!$supp) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Fournisseur introuvable.']);
        exit;
    }

    $alreadyActive = $supp['commissioning_state'] !== 'draft';

    // Snapshot the row before any mutation
    $ts   = date('Ymd-His');
    $snap = json_encode($supp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    @file_put_contents(
        '/var/www/maltytask/data/snapshots/ref_suppliers-' . $supplierFk . '-validate-' . $ts . '.json',
        $snap
    );

    // ── Flip draft → active (only if currently draft) ─────────────────────────
    if (!$alreadyActive) {
        $pdo->prepare(
            "UPDATE ref_suppliers
                SET commissioning_state = 'active',
                    last_modified_by    = 'web'
              WHERE id = ?"
        )->execute([$supplierFk]);

        log_revision(
            $pdo, $me,
            'ref_suppliers', $supplierFk,
            ['commissioning_state' => 'draft'],
            ['commissioning_state' => 'active'],
            'normal',
            'validate-fiche:draft→active'
        );
    }

    // ── Bulk-UPSERT pins for confirmed fields ─────────────────────────────────
    $pinnedBy = (string) ($me['username'] ?? 'web');
    foreach ($confirmedFields as $fieldName) {
        // Get the current value from the supplier row
        $fieldValue = null;
        if (array_key_exists($fieldName, $supp)) {
            $fieldValue = $supp[$fieldName] !== null ? (string) $supp[$fieldName] : null;
        }

        // Read existing pin for before-state in audit
        $existPinStmt = $pdo->prepare(
            'SELECT pinned_value FROM ref_supplier_field_pins
              WHERE supplier_fk = ? AND field_name = ? LIMIT 1'
        );
        $existPinStmt->execute([$supplierFk, $fieldName]);
        $existPin = $existPinStmt->fetch();
        $pinBefore = $existPin ? ['pinned_value' => $existPin['pinned_value']] : null;

        $upsertPin = $pdo->prepare(
            'INSERT INTO ref_supplier_field_pins
               (supplier_fk, field_name, pinned_value, pinned_by, pinned_at, pin_reason)
             VALUES (?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
               pinned_value = VALUES(pinned_value),
               pinned_by    = VALUES(pinned_by),
               pinned_at    = NOW(),
               pin_reason   = VALUES(pin_reason)'
        );
        $upsertPin->execute([
            $supplierFk,
            $fieldName,
            $fieldValue,
            $pinnedBy,
            'Validé via fiche fournisseur le ' . date('Y-m-d'),
        ]);

        log_revision(
            $pdo, $me,
            'ref_supplier_field_pins', $supplierFk,
            $pinBefore,
            ['field_name' => $fieldName, 'pinned_value' => $fieldValue, 'pinned_by' => $pinnedBy],
            'normal',
            'validate-pin:' . $fieldName
        );
    }

    $pdo->commit();

    echo json_encode([
        'ok'           => true,
        'already_active'=> $alreadyActive,
        'supplier' => [
            'id'                  => (int) $supp['id'],
            'name'                => (string) $supp['name'],
            'commissioning_state' => 'active',
        ],
        'pins_created' => count($confirmedFields),
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
}
