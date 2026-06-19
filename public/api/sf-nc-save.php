<?php
declare(strict_types=1);

/**
 * POST /api/sf-nc-save.php
 *
 * Record a new supplier non-conformity (NC).
 * Single INSERT — no transaction needed.
 * Admin-only.
 *
 * Payload:
 *   csrf                — session CSRF token
 *   supplier_id_fk      — INT UNSIGNED (required, must exist)
 *   detected_on         — YYYY-MM-DD (required)
 *   nc_type             — food_safety|quality|delivery|documentation|esg|other (required)
 *   severity            — mineure|majeure|critique (required)
 *   description         — text (required, non-empty)
 *   delivery_id_fk      — BIGINT UNSIGNED (optional; must exist in inv_deliveries if provided)
 *   capa_register       — text (optional, default 'MA-01')
 *   capa_ref            — text (optional)
 *   status              — open|in_progress|closed (optional, default 'open')
 *   triggered_evaluation — 0|1 (optional, default 0)
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
if (!rl_check_and_log((int) $me['id'], 'sf_nc_save', 50, 3600, $ip, $pdo)) {
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
$detectedOnRaw       = $_POST['detected_on'] ?? '';
$ncType              = trim($_POST['nc_type'] ?? '');
$severity            = trim($_POST['severity'] ?? '');
$description         = trim($_POST['description'] ?? '');
$deliveryIdRaw       = trim($_POST['delivery_id_fk'] ?? '');
$capaRegister        = trim($_POST['capa_register'] ?? '') ?: 'MA-01';
$capaRef             = trim($_POST['capa_ref'] ?? '') ?: null;
$status              = trim($_POST['status'] ?? '') ?: 'open';
$triggeredEvaluation = (int) ($_POST['triggered_evaluation'] ?? 0) === 1 ? 1 : 0;

// ── Validate supplier_id_fk ───────────────────────────────────────────────────
if ($supplierId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'supplier_id_fk invalide.']);
    exit;
}

// ── Validate detected_on ──────────────────────────────────────────────────────
$detectedOn = parse_date_field($detectedOnRaw);
if ($detectedOn === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'detected_on invalide (YYYY-MM-DD requis).']);
    exit;
}

// ── Validate nc_type ──────────────────────────────────────────────────────────
$VALID_NC_TYPES = ['food_safety', 'quality', 'delivery', 'documentation', 'esg', 'other'];
if (!in_array($ncType, $VALID_NC_TYPES, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'nc_type invalide.']);
    exit;
}

// ── Validate severity ─────────────────────────────────────────────────────────
$VALID_SEVERITIES = ['mineure', 'majeure', 'critique'];
if (!in_array($severity, $VALID_SEVERITIES, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'severity invalide.']);
    exit;
}

// ── Validate description ──────────────────────────────────────────────────────
if ($description === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'description requise.']);
    exit;
}

// ── Validate status ───────────────────────────────────────────────────────────
$VALID_STATUSES = ['open', 'in_progress', 'closed'];
if (!in_array($status, $VALID_STATUSES, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'status invalide.']);
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

    // Validate delivery_id_fk if provided
    $deliveryId = null;
    if ($deliveryIdRaw !== '') {
        $deliveryId = (int) $deliveryIdRaw;
        if ($deliveryId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'delivery_id_fk invalide.']);
            exit;
        }
        $delivStmt = $pdo->prepare('SELECT id FROM inv_deliveries WHERE id = ? LIMIT 1');
        $delivStmt->execute([$deliveryId]);
        if (!$delivStmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Livraison (delivery_id_fk) introuvable.']);
            exit;
        }
    }

    // INSERT supplier_nc
    $insertStmt = $pdo->prepare(
        'INSERT INTO supplier_nc
            (supplier_id_fk, detected_on, nc_type, severity, description,
             delivery_id_fk, capa_register, capa_ref, status, triggered_evaluation, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertStmt->execute([
        $supplierId,
        $detectedOn,
        $ncType,
        $severity,
        $description,
        $deliveryId,
        $capaRegister,
        $capaRef,
        $status,
        $triggeredEvaluation,
        (int) $me['id'],
    ]);
    $newId = (int) $pdo->lastInsertId();

    // Fetch inserted row for revision log
    $newRow = bd_fetch_before($pdo, 'supplier_nc', $newId);

    // log_revision
    log_revision($pdo, $me, 'supplier_nc', $newId, null, $newRow ?? [], 'normal', null);

    echo json_encode(['ok' => true, 'id' => $newId]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
}
