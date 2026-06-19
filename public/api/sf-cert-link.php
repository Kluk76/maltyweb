<?php
declare(strict_types=1);

/**
 * POST /api/sf-cert-link.php
 *
 * Link a certification document to a supplier.
 * Single INSERT — no transaction needed.
 * Admin-only.
 *
 * Payload:
 *   csrf                       — session CSRF token
 *   supplier_id_fk             — INT UNSIGNED (required, must exist)
 *   doc_file_id_fk             — BIGINT UNSIGNED (optional; must exist in doc_files.id if provided)
 *   doc_type                   — cert_iso22000|cert_fssc|cert_ifs|cert_bio|cert_brc|coa|spec_sheet|
 *                                code_conduite|esg_report|other (required)
 *   reference_label            — text (optional)
 *   issued_on                  — YYYY-MM-DD (optional)
 *   expires_on                 — YYYY-MM-DD (optional)
 *   linked_evaluation_id_fk    — INT UNSIGNED (optional; must exist in supplier_evaluations.id)
 *
 * Returns:
 *   { ok: true, id }
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
if (!rl_check_and_log((int) $me['id'], 'sf_cert_link', 50, 3600, $ip, $pdo)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Limite de requêtes atteinte.']);
    exit;
}

// ── Date helper (local) ───────────────────────────────────────────────────────
function parse_date_field(string $raw): ?string
{
    $d = trim($raw);
    if ($d === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return null;
    }
    return $d;
}

// ── Input parsing ─────────────────────────────────────────────────────────────
$supplierId          = isset($_POST['supplier_id_fk']) ? (int) $_POST['supplier_id_fk'] : 0;
$docFileIdRaw        = trim($_POST['doc_file_id_fk'] ?? '');
$docType             = trim($_POST['doc_type'] ?? '');
$referenceLabel      = trim($_POST['reference_label'] ?? '') ?: null;
$issuedOnRaw         = $_POST['issued_on'] ?? '';
$expiresOnRaw        = $_POST['expires_on'] ?? '';
$linkedEvalIdRaw     = trim($_POST['linked_evaluation_id_fk'] ?? '');

// ── Validate supplier_id_fk ───────────────────────────────────────────────────
if ($supplierId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'supplier_id_fk invalide.']);
    exit;
}

// ── Validate doc_type ─────────────────────────────────────────────────────────
$VALID_DOC_TYPES = [
    'cert_iso22000', 'cert_fssc', 'cert_ifs', 'cert_bio', 'cert_brc',
    'coa', 'spec_sheet', 'code_conduite', 'esg_report', 'other',
];
if (!in_array($docType, $VALID_DOC_TYPES, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'doc_type invalide.']);
    exit;
}

// ── Parse optional dates ──────────────────────────────────────────────────────
$issuedOn  = parse_date_field($issuedOnRaw);
$expiresOn = parse_date_field($expiresOnRaw);

// Reject malformed (non-empty but not matching pattern)
if ($issuedOnRaw !== '' && trim($issuedOnRaw) !== '' && $issuedOn === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'issued_on invalide (YYYY-MM-DD requis).']);
    exit;
}
if ($expiresOnRaw !== '' && trim($expiresOnRaw) !== '' && $expiresOn === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'expires_on invalide (YYYY-MM-DD requis).']);
    exit;
}

try {
    // Verify supplier exists
    $supplierStmt = $pdo->prepare('SELECT id FROM ref_suppliers WHERE id = ? LIMIT 1');
    $supplierStmt->execute([$supplierId]);
    if (!$supplierStmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Fournisseur introuvable.']);
        exit;
    }

    // Validate doc_file_id_fk if provided (BIGINT UNSIGNED)
    $docFileId = null;
    if ($docFileIdRaw !== '') {
        $docFileId = (int) $docFileIdRaw;
        if ($docFileId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'doc_file_id_fk invalide.']);
            exit;
        }
        $docFileStmt = $pdo->prepare('SELECT id FROM doc_files WHERE id = ? LIMIT 1');
        $docFileStmt->execute([$docFileId]);
        if (!$docFileStmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Fichier document (doc_file_id_fk) introuvable.']);
            exit;
        }
    }

    // Validate linked_evaluation_id_fk if provided
    $linkedEvalId = null;
    if ($linkedEvalIdRaw !== '') {
        $linkedEvalId = (int) $linkedEvalIdRaw;
        if ($linkedEvalId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'linked_evaluation_id_fk invalide.']);
            exit;
        }
        $evalStmt = $pdo->prepare('SELECT id FROM supplier_evaluations WHERE id = ? LIMIT 1');
        $evalStmt->execute([$linkedEvalId]);
        if (!$evalStmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Évaluation (linked_evaluation_id_fk) introuvable.']);
            exit;
        }
    }

    // INSERT supplier_cert_documents
    $insertStmt = $pdo->prepare(
        'INSERT INTO supplier_cert_documents
            (supplier_id_fk, doc_file_id_fk, doc_type, reference_label,
             issued_on, expires_on, linked_evaluation_id_fk, is_active, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $insertStmt->execute([
        $supplierId,
        $docFileId,
        $docType,
        $referenceLabel,
        $issuedOn,
        $expiresOn,
        $linkedEvalId,
        (int) $me['id'],
    ]);
    $newId = (int) $pdo->lastInsertId();

    // Fetch inserted row for revision log
    $newRow = bd_fetch_before($pdo, 'supplier_cert_documents', $newId);

    // log_revision
    log_revision($pdo, $me, 'supplier_cert_documents', $newId, null, $newRow ?? [], 'normal', null);

    echo json_encode(['ok' => true, 'id' => $newId]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
}
