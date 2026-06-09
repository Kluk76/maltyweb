<?php
declare(strict_types=1);
/**
 * api/journal-feed.php — Journal des saisies live feed endpoint.
 *
 * GET params (mutually exclusive modes):
 *   ?since=<YYYY-MM-DD HH:MM:SS>   → return events strictly newer (live append)
 *   ?before=<YYYY-MM-DD HH:MM:SS>  → return older events (load-more pagination)
 *   ?limit=<int>                    → optional, default 40, max 100
 *
 * All params default then validated. No dynamic SQL from raw input.
 *
 * Returns JSON array: [{source_table,row_pk,form_type,event_date,submitted_at,
 *                       operator_display,label}]
 * or {error:'...'} with appropriate HTTP status.
 *
 * Auth: require_page_access via auth.php.
 */

require_once __DIR__ . '/../../app/auth.php';

require_page_access('journal-saisies');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ─── Read + validate params ───────────────────────────────────────────────────

$since  = $_GET['since']  ?? null;
$before = $_GET['before'] ?? null;
$limit  = (int)($_GET['limit'] ?? 40);

// Validate limit
if ($limit < 1 || $limit > 100) {
    $limit = 40;
}

// Validate datetime strings — must be "YYYY-MM-DD HH:MM:SS" or similar ISO-like
function js_is_valid_datetime(?string $s): bool
{
    if ($s === null) return false;
    // Allow YYYY-MM-DD HH:MM:SS[.ffffff]
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $s);
}

if ($since !== null && !js_is_valid_datetime($since)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid since parameter'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($before !== null && !js_is_valid_datetime($before)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid before parameter'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Query ───────────────────────────────────────────────────────────────────

try {
    $pdo = maltytask_pdo();

    if ($since !== null) {
        // Live append: events strictly newer than cursor
        $stmt = $pdo->prepare(
            "SELECT
                v.source_table,
                v.row_pk,
                v.form_type,
                DATE_FORMAT(v.event_date, '%Y-%m-%d') AS event_date,
                DATE_FORMAT(v.submitted_at, '%Y-%m-%d %H:%i:%s') AS submitted_at,
                v.operator_email,
                COALESCE(NULLIF(u.display_name,''), v.operator_email, 'Opérateur') AS operator_display,
                v.label
             FROM v_saisie_events v
             LEFT JOIN users u ON u.id = v.submitted_by_user_id_fk
             WHERE v.submitted_at IS NOT NULL
               AND v.submitted_at > ?
             ORDER BY v.submitted_at DESC
             LIMIT ?"
        );
        $stmt->execute([$since, $limit]);
    } else {
        // Pagination (or initial load with no cursor): events older than cursor
        if ($before !== null) {
            $stmt = $pdo->prepare(
                "SELECT
                    v.source_table,
                    v.row_pk,
                    v.form_type,
                    DATE_FORMAT(v.event_date, '%Y-%m-%d') AS event_date,
                    DATE_FORMAT(v.submitted_at, '%Y-%m-%d %H:%i:%s') AS submitted_at,
                    v.operator_email,
                    COALESCE(NULLIF(u.display_name,''), v.operator_email, 'Opérateur') AS operator_display,
                    v.label
                 FROM v_saisie_events v
                 LEFT JOIN users u ON u.id = v.submitted_by_user_id_fk
                 WHERE v.submitted_at IS NOT NULL
                   AND v.submitted_at < ?
                 ORDER BY v.submitted_at DESC
                 LIMIT ?"
            );
            $stmt->execute([$before, $limit]);
        } else {
            // No cursor at all — return latest N
            $stmt = $pdo->prepare(
                "SELECT
                    v.source_table,
                    v.row_pk,
                    v.form_type,
                    DATE_FORMAT(v.event_date, '%Y-%m-%d') AS event_date,
                    DATE_FORMAT(v.submitted_at, '%Y-%m-%d %H:%i:%s') AS submitted_at,
                    v.operator_email,
                    COALESCE(NULLIF(u.display_name,''), v.operator_email, 'Opérateur') AS operator_display,
                    v.label
                 FROM v_saisie_events v
                 LEFT JOIN users u ON u.id = v.submitted_by_user_id_fk
                 WHERE v.submitted_at IS NOT NULL
                 ORDER BY v.submitted_at DESC
                 LIMIT ?"
            );
            $stmt->execute([$limit]);
        }
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast row_pk to int for JS (bigint may arrive as string from PDO)
    foreach ($rows as &$row) {
        $row['row_pk'] = (int) $row['row_pk'];
    }
    unset($row);

    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

} catch (Throwable $e) {
    error_log('journal-feed.php: query failed — ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server error'], JSON_UNESCAPED_UNICODE);
}
