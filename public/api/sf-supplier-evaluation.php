<?php
declare(strict_types=1);
/**
 * GET /api/sf-supplier-evaluation.php?supplier_id=<id>
 *
 * Returns the full evaluation context for one supplier as JSON.
 * Read-only. Manager + admin access (matches salle-fournisseurs.php gate).
 *
 * Response shape (Wave-3 UI contract):
 * {
 *   ok: true,
 *   supplier:           { id, name, criticality },
 *   grid:               { id, code, version, criteria: [{id,pillar,code,label,max_score,is_ko_flag,display_order}] },
 *   latest_evaluation:  <header row + its criteria scores> | null,
 *   history:            [ evaluation headers ordered evaluated_at DESC ],
 *   ncs:                [ supplier_nc rows DESC ],
 *   certs:              [ supplier_cert_documents rows, joined to doc_files for file_name ],
 *   autofeed:           { delivery_count, last_delivery_date, distinct_mi_categories,
 *                          open_nc_count, total_nc_count, is_critical_derived }
 * }
 *
 * Error shape:
 * { ok: false, error: "..." }
 */

require __DIR__ . '/../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

// ── Method guard ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// ── Auth + role gate (manager or admin — mirrors salle-fournisseurs.php) ───────
require_login();
$me = current_user();
if (!is_admin($me) && !is_manager($me)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès réservé aux managers et admins.']);
    exit;
}

// ── Input validation: supplier_id — read with ?? default, THEN validate (anti-pattern #9) ──
$rawId      = $_GET['supplier_id'] ?? null;
$supplierId = ($rawId !== null && ctype_digit((string) $rawId)) ? (int) $rawId : 0;

if ($supplierId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'supplier_id manquant ou invalide (entier positif requis).']);
    exit;
}

// ── DB queries ──────────────────────────────────────────────────────────────────
$pdo = maltytask_pdo();

try {

    // ── Q1: Supplier row ────────────────────────────────────────────────────────
    $suppStmt = $pdo->prepare(
        'SELECT id, name, criticality
           FROM ref_suppliers
          WHERE id = ?
          LIMIT 1'
    );
    $suppStmt->execute([$supplierId]);
    $suppRow = $suppStmt->fetch(PDO::FETCH_ASSOC);

    if (!$suppRow) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Fournisseur introuvable.']);
        exit;
    }

    $supplier = [
        'id'          => (int) $suppRow['id'],
        'name'        => (string) $suppRow['name'],
        'criticality' => $suppRow['criticality'] !== null ? (string) $suppRow['criticality'] : null,
    ];

    // ── Q2: Active evaluation grid + criteria ───────────────────────────────────
    // There may be multiple grids eventually; take the single active one.
    $gridStmt = $pdo->query(
        'SELECT g.id, g.code, g.version,
                gc.id           AS crit_id,
                gc.pillar,
                gc.code         AS crit_code,
                gc.label,
                gc.max_score,
                gc.is_ko_flag,
                gc.display_order
           FROM supplier_evaluation_grids g
           JOIN supplier_evaluation_grid_criteria gc ON gc.grid_id_fk = g.id
          WHERE g.is_active = 1
          ORDER BY gc.display_order, gc.id'
    );
    $gridRows = $gridStmt->fetchAll(PDO::FETCH_ASSOC);

    $grid = null;
    if (!empty($gridRows)) {
        $firstRow = $gridRows[0];
        $criteria = [];
        foreach ($gridRows as $row) {
            $criteria[] = [
                'id'           => (int) $row['crit_id'],
                'pillar'       => (string) $row['pillar'],
                'code'         => (string) $row['crit_code'],
                'label'        => (string) $row['label'],
                'max_score'    => (int) $row['max_score'],
                'is_ko_flag'   => (int) $row['is_ko_flag'] === 1,
                'display_order'=> (int) $row['display_order'],
            ];
        }
        $grid = [
            'id'       => (int) $firstRow['id'],
            'code'     => (string) $firstRow['code'],
            'version'  => (string) $firstRow['version'],
            'criteria' => $criteria,
        ];
    }

    // ── Q3: Evaluation history for this supplier (headers, DESC) ────────────────
    $histStmt = $pdo->prepare(
        'SELECT id, supplier_id_fk, grid_id_fk, evaluation_type,
                pillar_a_score, pillar_b_score, total_pct,
                food_safety_ko, result, evaluated_at, valid_until,
                evaluator_user_id, comment, status,
                superseded_by_id, created_at, updated_at
           FROM supplier_evaluations
          WHERE supplier_id_fk = ?
          ORDER BY evaluated_at DESC, created_at DESC'
    );
    $histStmt->execute([$supplierId]);
    $histRows = $histStmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast types for history rows
    $history = array_map(function (array $row): array {
        return [
            'id'                => (int) $row['id'],
            'supplier_id_fk'    => (int) $row['supplier_id_fk'],
            'grid_id_fk'        => (int) $row['grid_id_fk'],
            'evaluation_type'   => (string) $row['evaluation_type'],
            'pillar_a_score'    => $row['pillar_a_score'] !== null ? (int) $row['pillar_a_score'] : null,
            'pillar_b_score'    => $row['pillar_b_score'] !== null ? (int) $row['pillar_b_score'] : null,
            'total_pct'         => $row['total_pct'] !== null ? (float) $row['total_pct'] : null,
            'food_safety_ko'    => (int) $row['food_safety_ko'] === 1,
            'result'            => (string) $row['result'],
            'evaluated_at'      => $row['evaluated_at'] !== null ? (string) $row['evaluated_at'] : null,
            'valid_until'       => $row['valid_until']  !== null ? (string) $row['valid_until']  : null,
            'evaluator_user_id' => $row['evaluator_user_id'] !== null ? (int) $row['evaluator_user_id'] : null,
            'comment'           => $row['comment'] !== null ? (string) $row['comment'] : null,
            'status'            => (string) $row['status'],
            'superseded_by_id'  => $row['superseded_by_id'] !== null ? (int) $row['superseded_by_id'] : null,
            'created_at'        => (string) $row['created_at'],
            'updated_at'        => (string) $row['updated_at'],
        ];
    }, $histRows);

    // ── Q4: Latest evaluation + its per-criterion scores ───────────────────────
    $latestEval = null;
    if (!empty($history)) {
        // Latest = first in DESC order
        $latest = $history[0];

        // Fetch criteria scores for this evaluation
        $scoreStmt = $pdo->prepare(
            'SELECT ecs.id, ecs.evaluation_id_fk, ecs.grid_criterion_id_fk,
                    ecs.score, ecs.score_source, ecs.evidence_note
               FROM supplier_evaluation_criteria ecs
              WHERE ecs.evaluation_id_fk = ?
              ORDER BY ecs.id'
        );
        $scoreStmt->execute([$latest['id']]);
        $scores = $scoreStmt->fetchAll(PDO::FETCH_ASSOC);

        $criteriaScores = array_map(function (array $row): array {
            return [
                'id'                      => (int) $row['id'],
                'evaluation_id_fk'        => (int) $row['evaluation_id_fk'],
                'grid_criterion_id_fk'    => (int) $row['grid_criterion_id_fk'],
                'score'                   => $row['score'] !== null ? (int) $row['score'] : null,
                'score_source'            => (string) $row['score_source'],
                'evidence_note'           => $row['evidence_note'] !== null ? (string) $row['evidence_note'] : null,
            ];
        }, $scores);

        $latestEval = array_merge($latest, ['criteria_scores' => $criteriaScores]);
    }

    // ── Q5: Non-conformances (DESC) ─────────────────────────────────────────────
    $ncStmt = $pdo->prepare(
        'SELECT id, supplier_id_fk, detected_on, nc_type, severity,
                description, delivery_id_fk, capa_register, capa_ref,
                status, closed_on, resolution, triggered_evaluation,
                created_at, created_by
           FROM supplier_nc
          WHERE supplier_id_fk = ?
          ORDER BY detected_on DESC, created_at DESC'
    );
    $ncStmt->execute([$supplierId]);
    $ncRows = $ncStmt->fetchAll(PDO::FETCH_ASSOC);

    $ncs = array_map(function (array $row): array {
        return [
            'id'                   => (int) $row['id'],
            'supplier_id_fk'       => (int) $row['supplier_id_fk'],
            'detected_on'          => (string) $row['detected_on'],
            'nc_type'              => (string) $row['nc_type'],
            'severity'             => (string) $row['severity'],
            'description'          => (string) $row['description'],
            'delivery_id_fk'       => $row['delivery_id_fk']  !== null ? (int) $row['delivery_id_fk']  : null,
            'capa_register'        => $row['capa_register']   !== null ? (string) $row['capa_register']   : null,
            'capa_ref'             => $row['capa_ref']        !== null ? (string) $row['capa_ref']        : null,
            'status'               => (string) $row['status'],
            'closed_on'            => $row['closed_on']   !== null ? (string) $row['closed_on']   : null,
            'resolution'           => $row['resolution']  !== null ? (string) $row['resolution']  : null,
            'triggered_evaluation' => (int) $row['triggered_evaluation'] === 1,
            'created_at'           => (string) $row['created_at'],
            'created_by'           => $row['created_by'] !== null ? (int) $row['created_by'] : null,
        ];
    }, $ncRows);

    // ── Q6: Certificates (joined to doc_files for file_name) ───────────────────
    // doc_files has no filename col; file_name is the column name. Confirm live above.
    $certStmt = $pdo->prepare(
        'SELECT scd.id, scd.supplier_id_fk, scd.doc_file_id_fk,
                scd.doc_type, scd.reference_label,
                scd.issued_on, scd.expires_on,
                scd.linked_evaluation_id_fk,
                scd.is_active, scd.created_at, scd.created_by,
                df.file_name
           FROM supplier_cert_documents scd
           LEFT JOIN doc_files df ON df.id = scd.doc_file_id_fk
          WHERE scd.supplier_id_fk = ?
          ORDER BY scd.expires_on DESC, scd.created_at DESC'
    );
    $certStmt->execute([$supplierId]);
    $certRows = $certStmt->fetchAll(PDO::FETCH_ASSOC);

    $certs = array_map(function (array $row): array {
        return [
            'id'                        => (int) $row['id'],
            'supplier_id_fk'            => (int) $row['supplier_id_fk'],
            'doc_file_id_fk'            => $row['doc_file_id_fk']            !== null ? (int) $row['doc_file_id_fk']    : null,
            'doc_type'                  => (string) $row['doc_type'],
            'reference_label'           => $row['reference_label']           !== null ? (string) $row['reference_label']   : null,
            'issued_on'                 => $row['issued_on']                 !== null ? (string) $row['issued_on']         : null,
            'expires_on'                => $row['expires_on']                !== null ? (string) $row['expires_on']        : null,
            'linked_evaluation_id_fk'   => $row['linked_evaluation_id_fk']  !== null ? (int) $row['linked_evaluation_id_fk'] : null,
            'is_active'                 => (int) $row['is_active'] === 1,
            'created_at'                => (string) $row['created_at'],
            'created_by'                => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'file_name'                 => $row['file_name']  !== null ? (string) $row['file_name']  : null,
        ];
    }, $certRows);

    // ── Q7: Autofeed — evidence computed from real data only ────────────────────
    //
    // Categories considered food-safety / quality critical for is_critical_derived.
    // Ids: 1=Malt, 2=Hops, 3=Yeast, 4=Brewing Adjunct, 7=Cleaning Chemical,
    //      8=Packaging, 11=Brewing Mineral.
    // Non-food operational categories (logistics, utilities, maintenance, etc.) = non-critical.
    $criticalCategoryIds = [1, 2, 3, 4, 7, 8, 11];

    $autofeed = [];

    // delivery_count + last_delivery_date + distinct_mi_categories (one query)
    $feedStmt = $pdo->prepare(
        'SELECT COUNT(*)                                     AS delivery_count,
                MAX(d.date_received)                         AS last_delivery_date,
                GROUP_CONCAT(DISTINCT cat.name ORDER BY cat.name SEPARATOR \'|\') AS categories_raw,
                SUM(CASE WHEN m.category_id IN ('
        . implode(',', array_fill(0, count($criticalCategoryIds), '?'))
        . ') THEN 1 ELSE 0 END) AS critical_line_count
           FROM inv_deliveries d
           JOIN ref_mi m             ON m.id = d.ingredient_fk
           LEFT JOIN ref_mi_categories cat ON cat.id = m.category_id
          WHERE d.supplier_fk = ?
            AND d.ingredient_fk IS NOT NULL
            AND d.status IN (\'Active\', \'Consumed\')'
    );
    $feedParams = array_merge($criticalCategoryIds, [$supplierId]);
    $feedStmt->execute($feedParams);
    $feedRow = $feedStmt->fetch(PDO::FETCH_ASSOC);

    if ($feedRow && (int) $feedRow['delivery_count'] > 0) {
        $autofeed['delivery_count']    = (int) $feedRow['delivery_count'];
        $autofeed['last_delivery_date'] = (string) $feedRow['last_delivery_date'];

        // Distinct MI categories as an array (non-empty)
        $catRaw = $feedRow['categories_raw'];
        $autofeed['distinct_mi_categories'] = $catRaw
            ? array_values(array_filter(explode('|', $catRaw)))
            : [];

        // is_critical_derived: true if any delivered MI is in a critical category
        $autofeed['is_critical_derived'] = (int) $feedRow['critical_line_count'] > 0;
    } else {
        // Supplier has no resolved deliveries — omit delivery metrics, include derived flag
        $autofeed['is_critical_derived'] = false;
        // Note: delivery_count / last_delivery_date / distinct_mi_categories OMITTED
        // (no real source data — do not fabricate)
    }

    // open_nc_count / total_nc_count
    $ncCountStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = \'open\' THEN 1 ELSE 0 END)       AS open_count
           FROM supplier_nc
          WHERE supplier_id_fk = ?'
    );
    $ncCountStmt->execute([$supplierId]);
    $ncCountRow = $ncCountStmt->fetch(PDO::FETCH_ASSOC);

    if ($ncCountRow) {
        $autofeed['open_nc_count']  = (int) $ncCountRow['open_count'];
        $autofeed['total_nc_count'] = (int) $ncCountRow['total'];
    }

    // ── Assemble response ───────────────────────────────────────────────────────
    echo json_encode([
        'ok'                 => true,
        'supplier'           => $supplier,
        'grid'               => $grid,
        'latest_evaluation'  => $latestEval,
        'history'            => $history,
        'ncs'                => $ncs,
        'certs'              => $certs,
        'autofeed'           => $autofeed,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
}
