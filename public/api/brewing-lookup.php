<?php
declare(strict_types=1);
/**
 * GET /api/brewing-lookup.php
 *
 * Read-only brewing session lookup. Two modes:
 *   mode=day   &date=YYYY-MM-DD        — all brewday sessions for a calendar day
 *   mode=batch &recipe_id=N&batch=X    — all sessions for a recipe + lot
 *
 * For mode=batch, also returns gravity and ingredient sub-tables.
 *
 * Auth: require_page_access('saisies')  — GET, no CSRF needed.
 */

require __DIR__ . '/../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Auth ──────────────────────────────────────────────────────────────────────
require_page_access('wort');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// ── Whitelisted modes ─────────────────────────────────────────────────────────
$allowedModes = ['day', 'batch'];
$mode = $_GET['mode'] ?? '';
if (!in_array($mode, $allowedModes, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Paramètre mode invalide (day|batch requis).']);
    exit;
}

try {
    $pdo = maltytask_pdo();

    // ── Build WHERE clause depending on mode ──────────────────────────────────
    if ($mode === 'day') {
        $dateRaw = trim($_GET['date'] ?? '');
        $dateParts = explode('-', $dateRaw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)
            || !checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Format date invalide (YYYY-MM-DD requis).']);
            exit;
        }
        $whereSql  = 'WHERE b.is_tombstoned = 0 AND b.event_date = ?';
        $whereArgs = [$dateRaw];

    } else {
        // mode=batch
        $recipeIdRaw = $_GET['recipe_id'] ?? '';
        $batchRaw    = trim($_GET['batch'] ?? '');

        $recipeId = (int) $recipeIdRaw;
        if ($recipeId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'recipe_id invalide (entier positif requis).']);
            exit;
        }
        if ($batchRaw === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Paramètre batch manquant.']);
            exit;
        }
        $whereSql  = 'WHERE b.is_tombstoned = 0 AND b.recipe_id_fk = ? AND b.batch = ?';
        $whereArgs = [$recipeId, $batchRaw];
    }

    // ── Main brewday query ────────────────────────────────────────────────────
    $brewSql = "SELECT
                  b.id,
                  b.beer,
                  b.batch,
                  b.recipe_id_fk,
                  r.name AS recipe_name,
                  COALESCE(r.classification, 'Neb') AS classification,
                  b.cct,
                  b.event_date,
                  b.submitted_at,
                  b.comments
                FROM bd_brewing_brewday_v2 b
                LEFT JOIN ref_recipes r ON b.recipe_id_fk = r.id
                {$whereSql}
                ORDER BY b.submitted_at, b.id";

    $brewStmt = $pdo->prepare($brewSql);
    $brewStmt->execute($whereArgs);
    $brews = $brewStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Timings — single batched query for all brew sessions ──────────────────
    $timingsByBrew = [];
    if (!empty($brews)) {
        $conditions   = [];
        $timingParams = [];
        foreach ($brews as $b) {
            $conditions[]   = '(beer = ? AND batch = ?)';
            $timingParams[] = $b['beer'];
            $timingParams[] = $b['batch'];
        }
        $timingsWhere = implode(' OR ', $conditions);
        $timingsStmt  = $pdo->prepare(
            "SELECT beer, batch, brew, brew_start, brew_end, event_date
               FROM bd_brewing_timings_v2
              WHERE is_tombstoned = 0 AND ({$timingsWhere})
              ORDER BY beer, batch, brew"
        );
        $timingsStmt->execute($timingParams);
        foreach ($timingsStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $key = $t['beer'] . '|||' . $t['batch'];
            $timingsByBrew[$key][] = [
                'brew'       => $t['brew'],
                'brew_start' => $t['brew_start'],
                'brew_end'   => $t['brew_end'],
                'event_date' => $t['event_date'],
            ];
        }
    }
    foreach ($brews as &$brew) {
        $key           = $brew['beer'] . '|||' . $brew['batch'];
        $brew['timings'] = $timingsByBrew[$key] ?? [];
    }
    unset($brew);

    // ── Gravity and ingredients (batch mode only) ──────────────────────────────
    $gravity     = [];
    $ingredients = [];

    if ($mode === 'batch' && !empty($brews)) {
        // Use the beer + batch from the first row (all rows share the same NK)
        $beerNk  = $brews[0]['beer'];
        $batchNk = $brews[0]['batch'];

        // Gravity
        $gravSql = "SELECT beer, batch, brew, event_type,
                           firstwort_gravity, firstwort_ph,
                           pfannevoll_gravity, kochwurze_gravity,
                           final_gravity, final_volume, final_ph, batch_dilution
                      FROM bd_brewing_gravity_v2
                     WHERE is_tombstoned = 0 AND beer = ? AND batch = ?
                     ORDER BY brew, FIELD(event_type, 'FirstWort','Pfannevoll','Kochwurze','Cooling')";
        $gravStmt = $pdo->prepare($gravSql);
        $gravStmt->execute([$beerNk, $batchNk]);
        $gravity = $gravStmt->fetchAll(PDO::FETCH_ASSOC);

        // Ingredients
        $ingSql = "SELECT bip.line_idx, bip.category, bip.raw_name, bip.qty, bip.unit, bip.lot, bip.confidence,
                          rm.name AS mi_name
                     FROM bd_brewing_ingredients_v2 bi
                     JOIN bd_brewing_ingredients_parsed_v2 bip ON bip.header_id = bi.id
                     LEFT JOIN ref_mi rm ON rm.id = bip.mi_id_fk
                    WHERE bi.is_tombstoned = 0 AND bi.beer = ? AND bi.batch = ?
                    ORDER BY bip.line_idx";
        $ingStmt = $pdo->prepare($ingSql);
        $ingStmt->execute([$beerNk, $batchNk]);
        $ingredients = $ingStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'ok'          => true,
        'brews'       => $brews,
        'gravity'     => $gravity,
        'ingredients' => $ingredients,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . pdo_friendly_error($e, 'brewing-lookup')]);
}
