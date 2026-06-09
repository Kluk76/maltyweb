<?php
declare(strict_types=1);
/**
 * api/journal-detail.php — Drill-down detail for a single saisie event.
 *
 * GET params:
 *   ?table=<source_table>  — WHITELISTED against 7 known table names
 *   ?pk=<int>              — positive integer PK
 *
 * Returns JSON:
 * {
 *   source_table: string,
 *   row_pk: int,
 *   form_type: string,
 *   operator_display: string,
 *   submitted_at: string,
 *   label: string,
 *   fields: [{key, value}],          // current row key→value (human-ish)
 *   audit: [{                        // ASC timeline
 *     action: 'insert'|'update',
 *     actor: string,
 *     created_at: string,
 *     comment: string|null,
 *     qc_flag: string,
 *     diff: [{field, old, new}]|null  // null for insert (full snapshot in after_json)
 *     after_snapshot: object|null    // for insert rows
 *   }],
 *   has_audit: bool
 * }
 *
 * Auth: require_page_access('journal-saisies').
 */

require_once __DIR__ . '/../../app/auth.php';

require_page_access('journal-saisies');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ─── Whitelist ────────────────────────────────────────────────────────────────
const ALLOWED_TABLES = [
    'bd_racking_v2'            => 'Transfert',
    'bd_fermenting_v2'         => 'Fermentation',
    'bd_packaging_v2'          => 'Conditionnement',
    'bd_brewing_brewday_v2'    => 'Brassage · brassin',
    'bd_brewing_gravity_v2'    => 'Brassage · densité',
    'bd_brewing_ingredients_v2' => 'Brassage · ingrédients',
    'bd_brewing_timings_v2'    => 'Brassage · timing',
];

// Hidden/internal columns to exclude from the operator-facing field list
const HIDDEN_FIELDS = [
    'row_hash', 'is_tombstoned', 'audit_flags', 'imported_at', 'updated_at',
    'session_id_fk', 'source_sheet_row_index',
];

// ─── Read + validate params ───────────────────────────────────────────────────

$table = $_GET['table'] ?? '';
$pk    = (int)($_GET['pk'] ?? 0);

if (!isset(ALLOWED_TABLES[$table])) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid table'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($pk <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid pk'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Compute field-level diff between two JSON-decoded objects.
 * Returns list of {field, old, new} for changed fields only.
 */
function jd_field_diff(?array $before, ?array $after): array
{
    if ($after === null) return [];
    $diff = [];
    // Fields present in after (the truth)
    foreach ($after as $k => $v) {
        $oldVal = $before[$k] ?? null;
        // Skip if identical
        if ($oldVal === $v) continue;
        // Skip internal fields
        if (in_array($k, HIDDEN_FIELDS, true)) continue;
        $diff[] = [
            'field' => $k,
            'old'   => $oldVal,
            'new'   => $v,
        ];
    }
    return $diff;
}

/**
 * Strip hidden fields from a snapshot array.
 */
function jd_clean_snapshot(?array $snap): ?array
{
    if ($snap === null) return null;
    return array_filter(
        $snap,
        fn($k) => !in_array($k, HIDDEN_FIELDS, true),
        ARRAY_FILTER_USE_KEY
    );
}

// ─── Query ───────────────────────────────────────────────────────────────────

try {
    $pdo = maltytask_pdo();

    // ── Fetch the current row from the source table ──
    // Table name comes from the whitelist above — safe to interpolate.
    $rowStmt = $pdo->prepare(
        "SELECT t.*, COALESCE(NULLIF(u.display_name,''), t.email, 'Opérateur') AS _operator_display
         FROM `{$table}` t
         LEFT JOIN users u ON u.id = t.submitted_by_user_id_fk
         WHERE t.id = ?
         LIMIT 1"
    );
    $rowStmt->execute([$pk]);
    $rawRow = $rowStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rawRow) {
        http_response_code(404);
        echo json_encode(['error' => 'row not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $operatorDisplay = $rawRow['_operator_display'] ?? 'Opérateur';
    $submittedAt     = $rawRow['submitted_at'] ?? null;

    // Build user-facing field list (exclude hidden + synthetic _operator_display)
    $fields = [];
    foreach ($rawRow as $k => $v) {
        if ($k === '_operator_display') continue;
        if (in_array($k, HIDDEN_FIELDS, true)) continue;
        $fields[] = ['key' => $k, 'value' => $v];
    }

    // ── Get the form_type label from v_saisie_events ──
    $labelStmt = $pdo->prepare(
        "SELECT form_type, label
         FROM v_saisie_events
         WHERE source_table = ? AND row_pk = ?
         LIMIT 1"
    );
    $labelStmt->execute([$table, $pk]);
    $labelRow = $labelStmt->fetch(PDO::FETCH_ASSOC);
    $formType = $labelRow['form_type'] ?? ALLOWED_TABLES[$table];
    $label    = $labelRow['label']     ?? "#{$pk}";

    // ── Fetch audit timeline ──
    $auditStmt = $pdo->prepare(
        "SELECT action, username, created_at, comment, qc_flag,
                before_json, after_json
         FROM audit_row_revisions
         WHERE target_table = ? AND target_pk = ?
         ORDER BY created_at ASC"
    );
    $auditStmt->execute([$table, $pk]);
    $auditRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

    $hasAudit  = count($auditRows) > 0;
    $auditLine = [];

    foreach ($auditRows as $ar) {
        $before = $ar['before_json'] !== null
            ? json_decode($ar['before_json'], true)
            : null;
        $after  = $ar['after_json'] !== null
            ? json_decode($ar['after_json'], true)
            : null;

        if ($ar['action'] === 'insert') {
            $entry = [
                'action'          => 'insert',
                'actor'           => $ar['username'],
                'created_at'      => $ar['created_at'],
                'comment'         => $ar['comment'],
                'qc_flag'         => $ar['qc_flag'],
                'diff'            => null,
                'after_snapshot'  => jd_clean_snapshot($after),
            ];
        } else {
            $entry = [
                'action'          => 'update',
                'actor'           => $ar['username'],
                'created_at'      => $ar['created_at'],
                'comment'         => $ar['comment'],
                'qc_flag'         => $ar['qc_flag'],
                'diff'            => jd_field_diff($before, $after),
                'after_snapshot'  => null,
            ];
        }
        $auditLine[] = $entry;
    }

    echo json_encode([
        'source_table'     => $table,
        'row_pk'           => $pk,
        'form_type'        => $formType,
        'operator_display' => $operatorDisplay,
        'submitted_at'     => $submittedAt,
        'label'            => $label,
        'fields'           => $fields,
        'audit'            => $auditLine,
        'has_audit'        => $hasAudit,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

} catch (Throwable $e) {
    error_log('journal-detail.php: query failed — ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server error'], JSON_UNESCAPED_UNICODE);
}
