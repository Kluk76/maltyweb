<?php
declare(strict_types=1);
/**
 * POST /api/sb-merge.php  — merge one or more source mothers into a survivor mother.
 *
 * Operator-level (no admin required — merges are a normal workflow op).
 *
 * POST params:
 *   csrf              (string, required)
 *   survivor_id       (int, must be an open mother)
 *   source_ids[]      (array of int mother ids — must all be open, distinct from survivor)
 *   blend_share_pct[] (optional array, same indexing as source_ids, floats 0–100)
 *
 * POST response (200):
 *   { "ok": true, "result": { "merged_count": N, "survivor_id": M,
 *                              "sources_merged_into_survivor": [id, …],
 *                              "errors": [optionally, per-source strings] } }
 *
 * Error responses:
 *   302  (require_login redirect — not logged in)
 *   400  { "ok": false, "error": "csrf-invalid" | "missing-param" | "invalid-survivor-id"
 *                                | "invalid-source-ids" | "survivor-cannot-be-source"
 *                                | "survivor-not-open" | "invalid-blend-share-pct"
 *                                | "no-sources" }
 *   405  { "ok": false, "error": "method-not-allowed" }
 *   500  { "ok": false, "error": "internal" }
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/mother-shell.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method-not-allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF first — before any other param parsing.
if (!csrf_verify($_POST['csrf'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf-invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = maltytask_pdo();

// ── survivor_id ────────────────────────────────────────────────────────────────
$rawSurvivor = $_POST['survivor_id'] ?? null;
if ($rawSurvivor === null || $rawSurvivor === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing-param',
                      'detail' => 'survivor_id requis'], JSON_UNESCAPED_UNICODE);
    exit;
}
$survivorId = filter_var($rawSurvivor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($survivorId === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid-survivor-id',
                      'detail' => 'survivor_id doit être un entier > 0'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── source_ids[] ───────────────────────────────────────────────────────────────
$rawSources = $_POST['source_ids'] ?? null;
if (!is_array($rawSources) || count($rawSources) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no-sources',
                      'detail' => 'source_ids[] doit contenir au moins un ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sourceIds = [];
foreach ($rawSources as $raw) {
    $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid-source-ids',
                          'detail' => "source_id '{$raw}' invalide — doit être un entier > 0"],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sourceIds[] = $id;
}

// ── survivor cannot be one of the sources ──────────────────────────────────────
if (in_array($survivorId, $sourceIds, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'survivor-cannot-be-source',
                      'detail' => 'survivor_id ne peut pas figurer dans source_ids[]'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

// ── blend_share_pct[] (optional) ───────────────────────────────────────────────
$rawPcts  = $_POST['blend_share_pct'] ?? [];
$sharePcts = [];  // indexed same as $sourceIds; null = not provided
if (!is_array($rawPcts)) {
    $rawPcts = [];
}
for ($i = 0; $i < count($sourceIds); $i++) {
    $rawPct = $rawPcts[$i] ?? null;
    if ($rawPct === null || $rawPct === '') {
        $sharePcts[] = null;
    } else {
        $pct = filter_var($rawPct, FILTER_VALIDATE_FLOAT);
        if ($pct === false || $pct < 0 || $pct > 100) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid-blend-share-pct',
                              'detail' => "blend_share_pct[{$i}] = '{$rawPct}' invalide — doit être un décimal dans [0, 100]"],
                             JSON_UNESCAPED_UNICODE);
            exit;
        }
        $sharePcts[] = $pct;
    }
}

// ── RULE-2 BLOCK 2 fix: validation NOW inside the transaction with FOR UPDATE ──
// Previously survivor + source validation happened BEFORE beginTransaction(),
// opening a TOCTOU window where merge_mothers (which does NOT re-validate
// status='open') could write to a row that had just been closed by a concurrent
// admin op. FOR UPDATE takes the InnoDB row lock as part of the transaction.
$mergedIds = [];
$errors    = [];

$pdo->beginTransaction();
try {
    $stmtCheck = $pdo->prepare(
        "SELECT id, batch, status, merged_into_session_id_fk, form_type, is_tombstoned
           FROM op_sessions
          WHERE id = ?
          LIMIT 1
          FOR UPDATE"
    );

    // Survivor must be an open, non-tombstoned, non-merged mother (locked).
    $stmtCheck->execute([$survivorId]);
    $survivorRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if ($survivorRow === false
        || $survivorRow['form_type'] !== 'batch'
        || $survivorRow['status'] !== 'open'
        || $survivorRow['merged_into_session_id_fk'] !== null
        || (int)$survivorRow['is_tombstoned'] !== 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'survivor-not-open',
                          'detail' => "survivor_id={$survivorId} n'est pas une mother shell ouverte"],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Each source must be an open mother (locked).
    foreach ($sourceIds as $sid) {
        $stmtCheck->execute([$sid]);
        $srcRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if ($srcRow === false
            || $srcRow['form_type'] !== 'batch'
            || $srcRow['status'] !== 'open'
            || $srcRow['merged_into_session_id_fk'] !== null
            || (int)$srcRow['is_tombstoned'] !== 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'source-not-open',
                              'detail' => "source_id={$sid} n'est pas une mother shell ouverte"],
                             JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    for ($i = 0; $i < count($sourceIds); $i++) {
        $sid      = $sourceIds[$i];
        $sharePct = $sharePcts[$i] ?? null;
        try {
            merge_mothers($pdo, $sid, $survivorId, $sharePct);
            $mergedIds[] = $sid;
        } catch (\Throwable $e) {
            $errors[] = "source_id={$sid}: " . $e->getMessage();
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode([
                'ok'    => false,
                'error' => 'merge-failed',
                'detail' => "Échec lors de la fusion de source_id={$sid} — tout annulé.",
                'source_error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('[sb-merge POST] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'     => true,
    'result' => [
        'merged_count'                  => count($mergedIds),
        'survivor_id'                   => $survivorId,
        'sources_merged_into_survivor'  => $mergedIds,
        'errors'                        => $errors,
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
