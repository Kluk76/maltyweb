<?php
declare(strict_types=1);

/**
 * POST /api/sf-evaluation-save.php
 *
 * Create a new supplier evaluation (draft or final).
 * Computes scores server-side — never trusts client-sent totals.
 * When status='final', supersedes any prior final evaluation for the supplier.
 *
 * Admin-only.
 *
 * Payload:
 *   csrf                              — session CSRF token
 *   supplier_id_fk                   — INT UNSIGNED
 *   evaluation_type                  — initial|annuel|biennal|evenementiel
 *   evaluated_at                     — YYYY-MM-DD
 *   comment                          — text (optional)
 *   status                           — draft|final
 *   explicit_ko                      — 0|1
 *   scores[<grid_criterion_id>]      — '' (sans objet) OR int string
 *   evidence_note[<grid_criterion_id>] — text (optional)
 *   score_source[<grid_criterion_id>]  — auto|manual (optional, default 'manual')
 *
 * Returns:
 *   { ok: true, id, result, total_pct, food_safety_ko, valid_until }
 *   { ok: false, error: "..." }
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/db-write-helpers.php';
require __DIR__ . '/../../app/services/rate_limit.php';
require __DIR__ . '/../../app/supplier-eval-helpers.php';

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
if (!rl_check_and_log((int) $me['id'], 'sf_evaluation_save', 50, 3600, $ip, $pdo)) {
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
$supplierId     = isset($_POST['supplier_id_fk']) ? (int) $_POST['supplier_id_fk'] : 0;
$evaluationType = trim($_POST['evaluation_type'] ?? '');
$evaluatedAtRaw = $_POST['evaluated_at'] ?? '';
$comment        = trim($_POST['comment'] ?? '') ?: null;
$status         = trim($_POST['status'] ?? '');
$explicitKo     = (int) ($_POST['explicit_ko'] ?? 0) === 1;

// ── Validate evaluation_type ──────────────────────────────────────────────────
$VALID_EVAL_TYPES = ['initial', 'annuel', 'biennal', 'evenementiel'];
if (!in_array($evaluationType, $VALID_EVAL_TYPES, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'evaluation_type invalide.']);
    exit;
}

// ── Validate status ───────────────────────────────────────────────────────────
$VALID_STATUSES = ['draft', 'final'];
if (!in_array($status, $VALID_STATUSES, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'status invalide.']);
    exit;
}

// ── Validate evaluated_at ─────────────────────────────────────────────────────
$evaluatedAt = parse_date_field($evaluatedAtRaw);
if ($evaluatedAt === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'evaluated_at invalide (YYYY-MM-DD requis).']);
    exit;
}

// ── Validate supplier_id_fk ───────────────────────────────────────────────────
if ($supplierId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'supplier_id_fk invalide.']);
    exit;
}

try {
    // 1. Verify supplier exists (SELECT FOR UPDATE for consistency)
    $supplierStmt = $pdo->prepare(
        'SELECT id, criticality FROM ref_suppliers WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    // FOR UPDATE requires an active transaction — start one before this check
    $pdo->beginTransaction();

    $supplierStmt->execute([$supplierId]);
    $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
    if (!$supplier) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Fournisseur introuvable.']);
        exit;
    }

    // 2. Load active grid
    $gridStmt = $pdo->prepare(
        'SELECT id FROM supplier_evaluation_grids WHERE is_active = 1 ORDER BY id LIMIT 1'
    );
    $gridStmt->execute();
    $grid = $gridStmt->fetch(PDO::FETCH_ASSOC);
    if (!$grid) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Aucune grille active trouvée.']);
        exit;
    }
    $gridId = (int) $grid['id'];

    // 3. Parse scores: '' → null, else intval
    $rawScores     = $_POST['scores'] ?? [];
    $rawEvidences  = $_POST['evidence_note'] ?? [];
    $rawSources    = $_POST['score_source'] ?? [];

    $scores = [];
    foreach ($rawScores as $critIdStr => $scoreVal) {
        $critId = (int) $critIdStr;
        if ($critId <= 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Identifiant critère invalide: {$critIdStr}."]);
            exit;
        }
        $scores[$critId] = (trim((string) $scoreVal) === '') ? null : (int) $scoreVal;
    }

    // 4. Compute scores server-side
    $computed = supplier_eval_compute($pdo, $gridId, $scores, $explicitKo);

    // 5. Compute valid_until (only when status='final')
    $validUntil = null;
    if ($status === 'final') {
        $criticality = $supplier['criticality'];
        // evenementiel always → +1 year
        // critique or NULL → +1 year
        // non_critique → +2 years
        if ($evaluationType === 'evenementiel' || $criticality === 'critique' || $criticality === null) {
            $validUntil = date('Y-m-d', strtotime($evaluatedAt . ' +1 year'));
        } else {
            // non_critique
            $validUntil = date('Y-m-d', strtotime($evaluatedAt . ' +2 years'));
        }
    }

    // 6a. INSERT supplier_evaluations header
    $insertEval = $pdo->prepare(
        'INSERT INTO supplier_evaluations
            (supplier_id_fk, grid_id_fk, evaluation_type, pillar_a_score, pillar_b_score,
             total_pct, food_safety_ko, result, evaluated_at, valid_until,
             evaluator_user_id, comment, status)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertEval->execute([
        $supplierId,
        $gridId,
        $evaluationType,
        $computed['pillar_a_score'],
        $computed['pillar_b_score'],
        $computed['total_pct'],
        $computed['food_safety_ko'] ? 1 : 0,
        $computed['result'],
        $evaluatedAt,
        $validUntil,
        (int) $me['id'],
        $comment,
        $status,
    ]);
    $newEvalId = (int) $pdo->lastInsertId();

    // 6b. INSERT supplier_evaluation_criteria rows (one per submitted score key)
    $insertCrit = $pdo->prepare(
        'INSERT INTO supplier_evaluation_criteria
            (evaluation_id_fk, grid_criterion_id_fk, score, score_source, evidence_note)
         VALUES (?, ?, ?, ?, ?)'
    );
    $VALID_SOURCES = ['auto', 'manual'];
    foreach ($scores as $critId => $score) {
        $evidenceNote = isset($rawEvidences[$critId]) ? trim((string) $rawEvidences[$critId]) : null;
        $evidenceNote = ($evidenceNote === '') ? null : $evidenceNote;

        $scoreSource = isset($rawSources[$critId]) ? trim((string) $rawSources[$critId]) : 'manual';
        if (!in_array($scoreSource, $VALID_SOURCES, true)) {
            $scoreSource = 'manual';
        }

        $insertCrit->execute([
            $newEvalId,
            $critId,
            $score, // null is fine (SMALLINT UNSIGNED NULL)
            $scoreSource,
            $evidenceNote,
        ]);
    }

    // 6c. If status='final': supersede prior final non-superseded evaluations for this supplier
    if ($status === 'final') {
        $supersede = $pdo->prepare(
            'UPDATE supplier_evaluations
                SET superseded_by_id = ?
              WHERE supplier_id_fk = ?
                AND status = \'final\'
                AND superseded_by_id IS NULL
                AND id != ?'
        );
        $supersede->execute([$newEvalId, $supplierId, $newEvalId]);
    }

    // 7. Snapshot the new evaluation row
    $snapStmt = $pdo->prepare(
        'SELECT * FROM supplier_evaluations WHERE id = ? LIMIT 1'
    );
    $snapStmt->execute([$newEvalId]);
    $newRow = $snapStmt->fetch(PDO::FETCH_ASSOC);
    if ($newRow) {
        $ts   = date('Ymd-His');
        $snap = json_encode($newRow, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        @file_put_contents(
            '/var/www/maltytask/data/snapshots/supplier_evaluations-' . $newEvalId . '-insert-' . $ts . '.json',
            $snap
        );
    }

    // 8. log_revision for the evaluation INSERT
    log_revision($pdo, $me, 'supplier_evaluations', $newEvalId, null, $newRow ?? [], 'normal', null);

    $pdo->commit();

    // 9. Return result
    echo json_encode([
        'ok'            => true,
        'id'            => $newEvalId,
        'result'        => $computed['result'],
        'total_pct'     => $computed['total_pct'],
        'food_safety_ko'=> $computed['food_safety_ko'],
        'valid_until'   => $validUntil,
    ]);

} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
}
