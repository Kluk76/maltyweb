<?php
declare(strict_types=1);
/**
 * POST /api/sf-update-field.php
 *
 * Edit a ref_suppliers field (admin → direct UPDATE; manager → proposal).
 *
 * Payload:
 *   csrf        — session CSRF token
 *   supplier_fk — INT UNSIGNED
 *   field_name  — VARCHAR(64)  (from whitelist)
 *   new_value   — TEXT
 *   confirmed   — '1' (for COGS-impacting fields: second-step confirm)
 *
 * Whitelist (direct edit):
 *   country, vat_number, vat_regime, parser_key,
 *   hors_perimetre_cogs, sporadique, notes
 *
 * COGS-impacting (require confirmed=1):
 *   gl_account, currency
 *
 * Returns JSON:
 *   { ok: true, field_name, new_value, pending: bool }
 *   { ok: false, error: "...", needs_confirm: bool }
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

// ── Auth ──────────────────────────────────────────────────────────────────────
require_login();
$me = current_user();
if (!is_admin($me) && !is_manager($me)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès refusé.']);
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
if (!rl_check_and_log((int) $me['id'], 'sf_update_field', 200, 3600, $ip, $pdo)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Limite de requêtes atteinte (200/h).']);
    exit;
}

// ── Input validation ──────────────────────────────────────────────────────────
$supplierFk = isset($_POST['supplier_fk']) ? (int) $_POST['supplier_fk'] : 0;
$fieldName  = trim($_POST['field_name'] ?? '');
$newValue   = $_POST['new_value'] ?? '';
$confirmed  = !empty($_POST['confirmed']) && $_POST['confirmed'] !== '0';

// Fields editable by admin directly (or by manager → proposal)
const EDITABLE_FIELDS = [
    'country', 'vat_number', 'vat_regime', 'parser_key',
    'hors_perimetre_cogs', 'sporadique', 'notes',
    'gl_account', 'currency',
];

// COGS-impacting fields require two-step confirm
const COGS_IMPACTING_FIELDS = ['gl_account', 'currency'];

// Valid vat_regime ENUM values (matches DB)
const VALID_VAT_REGIMES = [
    'ch_vat', 'intra_eu_vat', 'third_country_0vat', 'ch_reduced_vat', 'non_taxable',
];

if ($supplierFk <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'supplier_fk manquant ou invalide.']);
    exit;
}
if (!in_array($fieldName, EDITABLE_FIELDS, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Champ non autorisé : ' . $fieldName]);
    exit;
}

// ── Per-field value validation ────────────────────────────────────────────────
$newValue = trim((string) $newValue);

if ($fieldName === 'country') {
    if ($newValue === '') {
        $newValue = null; // empty → NULL (clears the field)
    } elseif (!preg_match('/^[A-Z]{2}$/', $newValue)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Pays : code ISO-3166-1 alpha-2 requis (ex: CH, DE, FR).']);
        exit;
    }
}

if ($fieldName === 'vat_regime') {
    if ($newValue === '') {
        $newValue = null;
    } elseif (!in_array($newValue, VALID_VAT_REGIMES, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Régime TVA invalide.']);
        exit;
    }
}

if ($fieldName === 'hors_perimetre_cogs' || $fieldName === 'sporadique') {
    // Accept '0'/'1'/true/false
    $newValue = in_array($newValue, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
}

if ($newValue === '') $newValue = null;

try {
    // ── Verify supplier exists ────────────────────────────────────────────────
    $suppStmt = $pdo->prepare(
        'SELECT id, name, gl_account, currency, country, vat_number, vat_regime,
                parser_key, hors_perimetre_cogs, sporadique, notes
           FROM ref_suppliers WHERE id = ? LIMIT 1'
    );
    $suppStmt->execute([$supplierFk]);
    $supp = $suppStmt->fetch();
    if (!$supp) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Fournisseur introuvable.']);
        exit;
    }

    $oldValue = $supp[$fieldName] ?? null;

    // ── Manager → proposal path ───────────────────────────────────────────────
    if (!is_admin($me)) {
        // Supplier proposals require logistics (or broader) scope.
        // Forward-proofing: a future production-only manager must not reach this path.
        if (!manager_can('logistics', $me)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Portée insuffisante pour proposer des modifications fournisseur.']);
            exit;
        }
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO ref_supplier_proposals
               (supplier_fk, field_name, current_value, proposed_value, proposed_by, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierFk,
            $fieldName,
            $oldValue !== null ? (string) $oldValue : null,
            $newValue,
            (int) $me['id'],
            'pending',
        ]);

        log_revision(
            $pdo, $me,
            'ref_supplier_proposals', $supplierFk,
            null,
            ['field_name' => $fieldName, 'proposed_value' => $newValue, 'status' => 'pending'],
            'normal',
            'field-proposal:' . $fieldName
        );
        $pdo->commit();

        echo json_encode([
            'ok'        => true,
            'pending'   => true,
            'field_name'=> $fieldName,
            'new_value' => $newValue,
            'message'   => 'Proposition enregistrée, en attente de validation admin.',
        ]);
        exit;
    }

    // ── Admin path ────────────────────────────────────────────────────────────

    // COGS-impacting: require explicit confirmation
    if (in_array($fieldName, COGS_IMPACTING_FIELDS, true) && !$confirmed) {
        // Return diff for the UI confirm step — no write yet
        echo json_encode([
            'ok'           => false,
            'needs_confirm'=> true,
            'field_name'   => $fieldName,
            'old_value'    => $oldValue,
            'new_value'    => $newValue,
            'message'      => 'Ce champ est COGS-impacting. Confirmez pour appliquer.',
        ]);
        exit;
    }

    // Snapshot before write (always for admin updates)
    $ts   = date('Ymd-His');
    $snap = json_encode($supp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    @file_put_contents(
        '/var/www/maltytask/data/snapshots/ref_suppliers-' . $supplierFk . '-' . $ts . '.json',
        $snap
    );

    $pdo->beginTransaction();

    // Parameterized UPDATE — field is whitelisted above, safe to use as identifier
    // Use a match on the whitelisted field names
    $fieldSql = match($fieldName) {
        'country'            => 'country = ?',
        'vat_number'         => 'vat_number = ?',
        'vat_regime'         => 'vat_regime = ?',
        'parser_key'         => 'parser_key = ?',
        'hors_perimetre_cogs'=> 'hors_perimetre_cogs = ?',
        'sporadique'         => 'sporadique = ?',
        'notes'              => 'notes = ?',
        'gl_account'         => 'gl_account = ?',
        'currency'           => 'currency = ?',
    };

    $pdo->prepare(
        "UPDATE ref_suppliers SET {$fieldSql}, last_modified_by = 'web' WHERE id = ?"
    )->execute([$newValue, $supplierFk]);

    log_revision(
        $pdo, $me,
        'ref_suppliers', $supplierFk,
        [$fieldName => $oldValue !== null ? (string) $oldValue : null],
        [$fieldName => $newValue],
        'normal',
        'field-update:' . $fieldName
    );

    $pdo->commit();

    echo json_encode([
        'ok'        => true,
        'pending'   => false,
        'field_name'=> $fieldName,
        'new_value' => $newValue,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
}
