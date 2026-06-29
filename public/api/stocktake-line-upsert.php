<?php
declare(strict_types=1);
/**
 * POST /api/stocktake-line-upsert.php — Per-SKU upsert for guided FG census.
 *
 * Accepts JSON or form POST:
 *   csrf         string
 *   loc_id       int
 *   counted_at   string YYYY-MM-DD
 *   count_type   'operational' | 'month_end'
 *   sku_id       int
 *   qty          float >= 0
 *   do_snapshot  '1' | '' — if truthy, take a location snapshot first
 *   motif        string (optional)
 *   motif_note   string (optional, max 200 chars)
 *
 * Response: { ok:true, pk:N }
 *         | { ok:false, error:'...' }
 *         | { ok:false, reason:'expired', csrf:'...' }  (CSRF retry hint)
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/stocktake-helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$me = current_user();
if (!$me) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non authentifié']);
    exit;
}

require_page_access('expeditions');

if (!can_write_expeditions($me)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès en lecture seule']);
    exit;
}

// Parse body: supports JSON or form POST
$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody, true);
if (!is_array($body)) {
    $body = $_POST;
}

// CSRF
if (!csrf_verify($body['csrf'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Session expirée', 'reason' => 'expired', 'csrf' => csrf_token()]);
    exit;
}

// ── Inputs ──────────────────────────────────────────────────────────────────
$locId      = isset($body['loc_id'])     ? (int)   $body['loc_id']              : 0;
$countedAt  = isset($body['counted_at']) ? trim((string) $body['counted_at'])   : '';
$rawCt      = isset($body['count_type']) ? (string) $body['count_type']         : 'operational';
$countType  = in_array($rawCt, ['operational', 'month_end'], true) ? $rawCt : 'operational';
$skuId      = isset($body['sku_id'])     ? (int)   $body['sku_id']              : 0;
$qty        = isset($body['qty'])        ? (float)  $body['qty']                : -1.0;
$doSnapshot = !empty($body['do_snapshot']);
$motif      = isset($body['motif'])      ? trim((string) $body['motif'])        : '';
$motifNote  = isset($body['motif_note']) ? trim((string) $body['motif_note'])   : '';

// ── Role gate for backdating ──────────────────────────────────────────────
$canBackdate   = is_manager() || is_admin();
$today         = date('Y-m-d');
$thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));

if (!$canBackdate) {
    // Operators: coerce dates beyond 30-day window to today
    if ($countedAt < $thirtyDaysAgo) {
        $countedAt = $today;
    }
    // Operators cannot set month_end
    $countType = 'operational';
}

// ── Validation ────────────────────────────────────────────────────────────
$errors = [];
if ($locId <= 0)   $errors[] = 'loc_id invalide';
if ($skuId <= 0)   $errors[] = 'sku_id invalide';
if ($qty < 0)      $errors[] = 'qty invalide (< 0)';
if ($countedAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $countedAt)) {
    $errors[] = 'counted_at invalide';
} elseif ($countedAt > $today) {
    $errors[] = 'counted_at dans le futur';
} elseif ($countedAt < '2020-01-01') {
    $errors[] = 'counted_at trop ancienne';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => implode('; ', $errors)]);
    exit;
}

$pdo = maltytask_pdo();

// ── Load and validate location ────────────────────────────────────────────
try {
    $locStmt = $pdo->prepare(
        'SELECT id, site_type FROM ref_sites WHERE id = ? AND holds_fg_stock = 1 AND is_active = 1'
    );
    $locStmt->execute([$locId]);
    $locRow = $locStmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur DB (loc)']);
    exit;
}
if (!$locRow) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Site invalide']);
    exit;
}
$siteType = (string) $locRow['site_type'];

// ── Load and validate SKU ─────────────────────────────────────────────────
try {
    $skuStmt = $pdo->prepare(
        'SELECT id, sku_code, stocktake_scope FROM ref_skus WHERE id = ? AND is_active = 1'
    );
    $skuStmt->execute([$skuId]);
    $skuRow = $skuStmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur DB (sku)']);
    exit;
}
if (!$skuRow) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'SKU invalide ou inactif']);
    exit;
}
$skuCode = (string) $skuRow['sku_code'];
$scope   = (string) $skuRow['stocktake_scope'];

if (!exp_st_scope_allowed($scope, $siteType)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'SKU non autorisé pour ce site']);
    exit;
}

// ── COGS/seal guard for operators on past dates ───────────────────────────
if (!$canBackdate && $countedAt !== $today) {
    $monthClosed = substr($countedAt, 0, 7);
    if (exp_st_month_is_sealed($pdo, $monthClosed)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Mois clôturé — contactez la finance']);
        exit;
    }
    if (exp_st_has_month_end_row($pdo, $locId, $countedAt)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Inventaire de clôture — modification réservée à la gestion']);
        exit;
    }
}

// ── COGS/seal acknowledge gate for managers on month_end ─────────────────
// Managers may correct sealed months but must explicitly acknowledge the
// COGS impact. Re-validates seal state on every request — ack_seal=1
// suppresses the warning, it does not bypass the check.
if ($canBackdate && $countType === 'month_end') {
    $monthForSeal = substr($countedAt, 0, 7);
    if (exp_st_month_is_sealed($pdo, $monthForSeal)) {
        if (empty($body['ack_seal'])) {
            echo json_encode([
                'ok'      => false,
                'reason'  => 'seal_ack_required',
                'warning' => 'Ce mois est scellé. La correction NE modifiera PAS la fiche COGS scellée tant que le responsable financier ne la re-scelle pas.',
            ]);
            exit;
        }
        // ack_seal=1 received — seal state confirmed above, proceed to upsert
    }
}

// ── Audit note ────────────────────────────────────────────────────────────
$validMotifs  = ['erreur-saisie', 'casse', 'perte', 'retrouve', 'autre'];
$auditNote    = 'Saisie guidée FG';
if ($motif !== '' && in_array($motif, $validMotifs, true)) {
    $auditNote = 'Correction guidée (' . $motif . ')'
        . ($motifNote !== '' ? ': ' . mb_substr($motifNote, 0, 200) : '');
}

// ── Snapshot (once per session, caller signals with do_snapshot=1) ────────
if ($doSnapshot) {
    exp_st_snapshot($pdo, $locId);
}

// ── Write ─────────────────────────────────────────────────────────────────
$pdo->beginTransaction();
try {
    $result = exp_st_do_upsert($pdo, $me, $locId, $countedAt, $countType, $skuId, $qty, $skuCode, $auditNote);
    $pdo->commit();
    if (!$result['ok']) {
        http_response_code(500);
    }
    echo json_encode($result);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[stocktake-line-upsert] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur lors de l\'enregistrement']);
}
