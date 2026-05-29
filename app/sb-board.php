<?php
declare(strict_types=1);
/**
 * sb-board.php — Mother-shell board read API.
 *
 * Single source of truth for every board surface (atoms 3+). Board atoms MUST
 * consume these functions; they may NOT issue their own queries to op_sessions,
 * bd_* tables, or commissioning_settings for board purposes.
 *
 * Public surface:
 *   sb_open_mothers(PDO): array         — grouped by zone, ordered by severity
 *   sb_mother_drill_in(PDO, int): ?array — full drill-in payload for one mother
 *   sb_eta_default(PDO, int): ?string   — ETA close date for a recipe (YYYY-MM-DD or null)
 *   sb_heartbeat_severity(PDO, int): string — 'green'|'amber'|'red'
 *
 * Schema dependency: mig 215 (op_sessions + op_session_steps).
 * No writes. No LIMIT traps. PHP 8.1 strict_types.
 */

require_once __DIR__ . '/db.php';

// ─── Heartbeat defaults (mig 216 seeds commissioning_settings; until then use these) ───

const SB_HEARTBEAT_GREEN_DEFAULT_HOURS = 24;
const SB_HEARTBEAT_AMBER_DEFAULT_HOURS = 72;

// ─── Zone mapping: latest child form_type → board zone ────────────────────────

const SB_ZONE_MAP = [
    'brewing'    => 'brasserie',
    'fermenting' => 'fermentation',
    'racking'    => 'bbt',
    'packaging'  => 'conditionnement',
    'batch'      => 'brasserie',  // mother with no children yet → brasserie
];

const SB_ZONES = ['brasserie', 'fermentation', 'bbt', 'conditionnement', 'expedition'];

// ─── Internal helpers ─────────────────────────────────────────────────────────

/**
 * Load heartbeat thresholds from commissioning_settings (section='heartbeat').
 * Returns [green_max_hours, amber_max_hours] with hardcoded defaults when rows absent.
 */
function _sb_heartbeat_thresholds(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT key_name, COALESCE(value_num, value_text)
           FROM commissioning_settings
          WHERE section  = 'heartbeat'
            AND key_name IN ('green_max_hours', 'amber_max_hours')
            AND is_active = 1"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $green = isset($rows['green_max_hours']) ? (int) $rows['green_max_hours'] : SB_HEARTBEAT_GREEN_DEFAULT_HOURS;
    $amber = isset($rows['amber_max_hours']) ? (int) $rows['amber_max_hours'] : SB_HEARTBEAT_AMBER_DEFAULT_HOURS;

    return [$green, $amber];
}

/**
 * Compute MAX(updated_at) across a mother's children (op_sessions) and their
 * step events (op_session_steps.acted_at). Returns a DateTime or null.
 */
function _sb_last_activity(PDO $pdo, int $mother_id): ?\DateTime
{
    $stmt = $pdo->prepare(
        "SELECT GREATEST(
                    COALESCE(MAX(s.updated_at), '1970-01-01'),
                    COALESCE(MAX(st.acted_at),  '1970-01-01')
                ) AS last_act
           FROM op_sessions s
           LEFT JOIN op_session_steps st ON st.session_id_fk = s.id
          WHERE s.parent_session_id_fk = :mother_id
            AND s.is_tombstoned = 0"
    );
    $stmt->execute([':mother_id' => $mother_id]);
    $raw = $stmt->fetchColumn();

    if ($raw === null || $raw === false || $raw === '1970-01-01 00:00:00') {
        return null;
    }
    // RULE-2 BLOCK fix: op_session_steps.acted_at is datetime(6) (microseconds).
    // When GREATEST returns the step's timestamp, the string carries ".uuuuuu" suffix
    // and the basic 'Y-m-d H:i:s' format silently returns false → null → permanent red.
    // Try microsecond format first, fall back to second-precision (covers updated_at win).
    $dt = \DateTime::createFromFormat('Y-m-d H:i:s.u', $raw)
       ?: \DateTime::createFromFormat('Y-m-d H:i:s', $raw);
    return $dt ?: null;
}

/**
 * Determine which zone a mother belongs to based on its latest child's form_type.
 */
function _sb_mother_zone(PDO $pdo, int $mother_id): string
{
    $stmt = $pdo->prepare(
        "SELECT form_type
           FROM op_sessions
          WHERE parent_session_id_fk = :mother_id
            AND is_tombstoned = 0
          ORDER BY opened_at DESC
          LIMIT 1"
    );
    $stmt->execute([':mother_id' => $mother_id]);
    $form_type = $stmt->fetchColumn();

    if ($form_type === false || $form_type === null) {
        return 'brasserie'; // no children yet
    }
    return SB_ZONE_MAP[$form_type] ?? 'brasserie';
}

/**
 * Count children by form_type for a mother. Returns array with keys:
 *   brewing, fermenting, racking, packaging — all int.
 */
function _sb_children_summary(PDO $pdo, int $mother_id): array
{
    $stmt = $pdo->prepare(
        "SELECT form_type, COUNT(*) AS cnt
           FROM op_sessions
          WHERE parent_session_id_fk = :mother_id
            AND is_tombstoned = 0
          GROUP BY form_type"
    );
    $stmt->execute([':mother_id' => $mother_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'brewing'    => (int) ($rows['brewing']    ?? 0),
        'fermenting' => (int) ($rows['fermenting'] ?? 0),
        'racking'    => (int) ($rows['racking']    ?? 0),
        'packaging'  => (int) ($rows['packaging']  ?? 0),
    ];
}

/**
 * Get the current vessel (kind + number) from the mother's latest non-tombstoned child.
 * Returns [vessel_kind, vessel_number] or [null, null].
 */
function _sb_current_vessel(PDO $pdo, int $mother_id): array
{
    $stmt = $pdo->prepare(
        "SELECT vessel_kind, vessel_number
           FROM op_sessions
          WHERE parent_session_id_fk = :mother_id
            AND is_tombstoned = 0
            AND vessel_kind IS NOT NULL
          ORDER BY opened_at DESC
          LIMIT 1"
    );
    $stmt->execute([':mother_id' => $mother_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return [null, null];
    }
    return [$row['vessel_kind'], $row['vessel_number'] !== null ? (int) $row['vessel_number'] : null];
}

/**
 * Compute pct_packaged for a mother.
 *
 * Formula: SUM(bd_packaging_v2.vendable_hl) for packaging children
 *        / SUM(bd_racking_v2.racked_vol_hl) for racking children × 100
 *
 * Returns null if no racking children exist yet.
 */
function _sb_pct_packaged(PDO $pdo, int $mother_id): ?float
{
    // Racking children → racked_vol_hl
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(r.racked_vol_hl), 0) AS total_racked
           FROM op_sessions s
           JOIN bd_racking_v2 r ON r.session_id_fk = s.id
          WHERE s.parent_session_id_fk = :mother_id
            AND s.form_type = 'racking'
            AND s.is_tombstoned = 0
            AND r.is_tombstoned = 0"
    );
    $stmt->execute([':mother_id' => $mother_id]);
    $total_racked = (float) $stmt->fetchColumn();

    if ($total_racked <= 0.0) {
        return null; // No racking yet — refuse to return 0
    }

    // Packaging children → vendable_hl
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(p.vendable_hl), 0) AS total_packaged
           FROM op_sessions s
           JOIN bd_packaging_v2 p ON p.session_id_fk = s.id
          WHERE s.parent_session_id_fk = :mother_id
            AND s.form_type = 'packaging'
            AND s.is_tombstoned = 0
            AND p.is_tombstoned = 0"
    );
    $stmt->execute([':mother_id' => $mother_id]);
    $total_packaged = (float) $stmt->fetchColumn();

    return round(($total_packaged / $total_racked) * 100.0, 1);
}

/**
 * Check whether ref_recipes.process_type column exists in the DB.
 * Graceful default: false if absent (do NOT introduce the column).
 */
function _sb_has_process_type_column(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'ref_recipes'
            AND COLUMN_NAME  = 'process_type'"
    );
    $stmt->execute();
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Build a single mother summary array.
 * Used by sb_open_mothers() for each row.
 */
function _sb_build_mother_summary(PDO $pdo, array $mother_row, array $thresholds): array
{
    [$green_hours, $amber_hours] = $thresholds;

    $mother_id  = (int) $mother_row['id'];
    $recipe_id  = $mother_row['recipe_id_fk'] !== null ? (int) $mother_row['recipe_id_fk'] : null;

    $last_activity_dt = _sb_last_activity($pdo, $mother_id);
    $last_activity_at = $last_activity_dt?->format('Y-m-d H:i:s');

    // Heartbeat
    $heartbeat_severity = _sb_compute_heartbeat($last_activity_dt, $green_hours, $amber_hours);

    // ETA
    $eta_close_date = ($recipe_id !== null) ? sb_eta_default($pdo, $recipe_id) : null;

    // Children summary
    $children_summary = _sb_children_summary($pdo, $mother_id);

    // Current vessel
    [$vessel_kind, $vessel_number] = _sb_current_vessel($pdo, $mother_id);

    // pct_packaged
    $pct_packaged = _sb_pct_packaged($pdo, $mother_id);

    return [
        'id'                  => $mother_id,
        'recipe_id_fk'        => $recipe_id,
        'recipe_name'         => $mother_row['recipe_name'] ?? null,
        'batch'               => $mother_row['batch'],
        'opened_at'           => $mother_row['opened_at'],
        'status'              => $mother_row['status'],
        'children_summary'    => $children_summary,
        'current_vessel_kind'   => $vessel_kind,
        'current_vessel_number' => $vessel_number,
        'eta_close_date'      => $eta_close_date,
        'heartbeat_severity'  => $heartbeat_severity,
        'last_activity_at'    => $last_activity_at,
        'pct_packaged'        => $pct_packaged,
        'blend_share_pct'     => $mother_row['blend_share_pct'] !== null
                                    ? (float) $mother_row['blend_share_pct']
                                    : null,
    ];
}

/**
 * Pure heartbeat computation from a last-activity DateTime and thresholds.
 */
function _sb_compute_heartbeat(?\DateTime $last_activity_dt, int $green_hours, int $amber_hours): string
{
    if ($last_activity_dt === null) {
        return 'red'; // no activity ever recorded
    }

    $now       = new \DateTime();
    $elapsed_h = ($now->getTimestamp() - $last_activity_dt->getTimestamp()) / 3600.0;

    if ($elapsed_h <= $green_hours) {
        return 'green';
    }
    if ($elapsed_h <= $amber_hours) {
        return 'amber';
    }
    return 'red';
}

// ─── Public API ───────────────────────────────────────────────────────────────

/**
 * Open mothers grouped by display zone, ordered by heartbeat severity then opened_at DESC.
 *
 * Returns array keyed by zone:
 *   'brasserie'|'fermentation'|'bbt'|'conditionnement'|'expedition'
 * Each value is a list of mother summary arrays.
 *
 * 'expedition' is a placeholder for v1 — always returns empty list.
 */
function sb_open_mothers(PDO $pdo): array
{
    // Initialise all zones to empty lists (ensures 5 keys always present)
    $by_zone = array_fill_keys(SB_ZONES, []);

    // Fetch all open non-tombstoned mothers with recipe name
    $stmt = $pdo->prepare(
        "SELECT m.id,
                m.recipe_id_fk,
                r.name AS recipe_name,
                m.batch,
                m.opened_at,
                m.status,
                m.blend_share_pct
           FROM op_sessions m
           LEFT JOIN ref_recipes r ON r.id = m.recipe_id_fk
          WHERE m.form_type   = 'batch'
            AND m.status      = 'open'
            AND m.is_tombstoned = 0
          ORDER BY m.opened_at DESC"
    );
    $stmt->execute();
    $mothers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($mothers)) {
        return $by_zone;
    }

    // Load thresholds once for the whole batch
    $thresholds = _sb_heartbeat_thresholds($pdo);

    // Severity ordering: red < amber < green (red = most urgent = first)
    $severity_order = ['red' => 0, 'amber' => 1, 'green' => 2];

    foreach ($mothers as $row) {
        $summary = _sb_build_mother_summary($pdo, $row, $thresholds);
        $zone    = _sb_mother_zone($pdo, $summary['id']);

        // expedition is PM-locked (phase v1 placeholder) — skip populating
        if ($zone === 'expedition') {
            continue;
        }

        $by_zone[$zone][] = $summary;
    }

    // Sort each zone by severity asc (red first), then opened_at desc
    foreach (SB_ZONES as $zone) {
        if ($zone === 'expedition' || count($by_zone[$zone]) < 2) {
            continue;
        }
        usort($by_zone[$zone], static function (array $a, array $b) use ($severity_order): int {
            $sev_cmp = $severity_order[$a['heartbeat_severity']] <=> $severity_order[$b['heartbeat_severity']];
            if ($sev_cmp !== 0) {
                return $sev_cmp;
            }
            // opened_at desc (more recent first within same severity)
            return strcmp($b['opened_at'], $a['opened_at']);
        });
    }

    return $by_zone;
}

/**
 * Full drill-in payload for one mother session.
 *
 * Returns null if mother_id doesn't exist or is tombstoned.
 * Returns:
 *   mother  => {row + recipe_name + heartbeat + ETA + wort_contract},
 *   children => [{session row + form_type + events_count + last_activity}],
 *   merge   => {survivor_id, sources:[mother_id,...], blend_share_pct} | null,
 *   archived => bool
 */
function sb_mother_drill_in(PDO $pdo, int $mother_id): ?array
{
    // Fetch the mother row
    $stmt = $pdo->prepare(
        "SELECT m.id,
                m.form_type,
                m.recipe_id_fk,
                r.name AS recipe_name,
                m.batch,
                m.opened_at,
                m.closed_at,
                m.status,
                m.phase,
                m.blend_share_pct,
                m.merged_into_session_id_fk,
                m.audit_flags,
                m.is_tombstoned
           FROM op_sessions m
           LEFT JOIN ref_recipes r ON r.id = m.recipe_id_fk
          WHERE m.id        = :mother_id
            AND m.form_type = 'batch'
            AND m.is_tombstoned = 0"
    );
    $stmt->execute([':mother_id' => $mother_id]);
    $mother_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($mother_row === false) {
        return null;
    }

    // Heartbeat thresholds + severity
    $thresholds         = _sb_heartbeat_thresholds($pdo);
    [$green, $amber]    = $thresholds;
    $last_activity_dt   = _sb_last_activity($pdo, $mother_id);
    $last_activity_at   = $last_activity_dt?->format('Y-m-d H:i:s');
    $heartbeat_severity = _sb_compute_heartbeat($last_activity_dt, $green, $amber);

    // ETA
    $recipe_id      = $mother_row['recipe_id_fk'] !== null ? (int) $mother_row['recipe_id_fk'] : null;
    $eta_close_date = ($recipe_id !== null) ? sb_eta_default($pdo, $recipe_id) : null;

    // wort_contract — graceful default false if column absent
    $wort_contract = false;
    if ($recipe_id !== null && _sb_has_process_type_column($pdo)) {
        $stmt = $pdo->prepare("SELECT process_type FROM ref_recipes WHERE id = :id");
        $stmt->execute([':id' => $recipe_id]);
        $pt = $stmt->fetchColumn();
        $wort_contract = ($pt === 'wort_contract');
    }

    // Children
    $stmt = $pdo->prepare(
        "SELECT s.id,
                s.form_type,
                s.vessel_kind,
                s.vessel_number,
                s.phase,
                s.status,
                s.opened_at,
                s.closed_at,
                s.updated_at,
                COUNT(st.id) AS events_count
           FROM op_sessions s
           LEFT JOIN op_session_steps st ON st.session_id_fk = s.id
          WHERE s.parent_session_id_fk = :mother_id
            AND s.is_tombstoned = 0
          GROUP BY s.id,
                   s.form_type, s.vessel_kind, s.vessel_number,
                   s.phase, s.status, s.opened_at, s.closed_at, s.updated_at
          ORDER BY s.opened_at ASC"
    );
    $stmt->execute([':mother_id' => $mother_id]);
    $children_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // RULE-2 medium fix: hoist PDO::prepare out of foreach (prepare once, execute N times).
    // Plus DATE_FORMAT to normalize microsecond precision so string compare stays consistent.
    $step_stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(MAX(acted_at), '%Y-%m-%d %H:%i:%s')
           FROM op_session_steps WHERE session_id_fk = :sid"
    );

    $children = [];
    foreach ($children_raw as $child) {
        // last_activity = max(child.updated_at, max step acted_at), both at second precision
        $step_stmt->execute([':sid' => (int) $child['id']]);
        $max_step_at = $step_stmt->fetchColumn();

        $child_last = $child['updated_at'];
        if ($max_step_at && $max_step_at > $child_last) {
            $child_last = $max_step_at;
        }

        $children[] = [
            'id'            => (int) $child['id'],
            'form_type'     => $child['form_type'],
            'vessel_kind'   => $child['vessel_kind'],
            'vessel_number' => $child['vessel_number'] !== null ? (int) $child['vessel_number'] : null,
            'phase'         => $child['phase'],
            'status'        => $child['status'],
            'opened_at'     => $child['opened_at'],
            'closed_at'     => $child['closed_at'],
            'events_count'  => (int) $child['events_count'],
            'last_activity' => $child_last,
        ];
    }

    // Merge block — populated if this mother absorbed others (it is the survivor)
    $merge = null;
    $stmt  = $pdo->prepare(
        "SELECT id, blend_share_pct
           FROM op_sessions
          WHERE merged_into_session_id_fk = :mother_id
            AND form_type = 'batch'
            AND is_tombstoned = 0"
    );
    $stmt->execute([':mother_id' => $mother_id]);
    $source_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($source_rows)) {
        $merge = [
            'survivor_id'    => $mother_id,
            'sources'        => array_map(fn($r) => (int) $r['id'], $source_rows),
            'blend_share_pct'=> $mother_row['blend_share_pct'] !== null
                                    ? (float) $mother_row['blend_share_pct']
                                    : null,
        ];
    }

    return [
        'mother' => [
            'id'                  => (int) $mother_row['id'],
            'recipe_id_fk'        => $recipe_id,
            'recipe_name'         => $mother_row['recipe_name'] ?? null,
            'batch'               => $mother_row['batch'],
            'opened_at'           => $mother_row['opened_at'],
            'closed_at'           => $mother_row['closed_at'],
            'status'              => $mother_row['status'],
            'phase'               => $mother_row['phase'],
            'blend_share_pct'     => $mother_row['blend_share_pct'] !== null
                                        ? (float) $mother_row['blend_share_pct']
                                        : null,
            'eta_close_date'      => $eta_close_date,
            'heartbeat_severity'  => $heartbeat_severity,
            'last_activity_at'    => $last_activity_at,
            'wort_contract'       => $wort_contract,
            'audit_flags'         => $mother_row['audit_flags'] !== null
                                        ? json_decode($mother_row['audit_flags'], true)
                                        : null,
        ],
        'children' => $children,
        'merge'    => $merge,
        'archived' => ($mother_row['status'] === 'closed' || $mother_row['status'] === 'abandoned'),
    ];
}

/**
 * ETA close date for a recipe.
 *
 * Resolution order:
 *   1. Median (close_at - open_at) across ≥3 closed mothers with same recipe_id_fk
 *      → today + median days (YYYY-MM-DD)
 *   2. commissioning_settings.section='eta_defaults', key=recipe_id or recipe_name
 *      → today + value (days) (YYYY-MM-DD)
 *   3. null — caller renders "ETA non défini"
 */
function sb_eta_default(PDO $pdo, int $recipe_id_fk): ?string
{
    // Step 1: median from historical closed mothers
    $stmt = $pdo->prepare(
        "SELECT TIMESTAMPDIFF(DAY, opened_at, closed_at) AS days_open
           FROM op_sessions
          WHERE recipe_id_fk   = :recipe_id
            AND form_type      = 'batch'
            AND status         = 'closed'
            AND closed_at      IS NOT NULL
            AND opened_at      IS NOT NULL
            AND is_tombstoned  = 0
          ORDER BY days_open"
    );
    $stmt->execute([':recipe_id' => $recipe_id_fk]);
    $days_list = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($days_list) >= 3) {
        $count  = count($days_list);
        $median = (int) round((float) $days_list[(int) floor(($count - 1) / 2)]);
        $eta_dt = (new \DateTime())->modify("+{$median} days");
        return $eta_dt->format('Y-m-d');
    }

    // Step 2: commissioning_settings lookup — try recipe_id first, then recipe_name
    // Fetch recipe_name for the key=recipe_name fallback
    $name_stmt = $pdo->prepare("SELECT name FROM ref_recipes WHERE id = :id");
    $name_stmt->execute([':id' => $recipe_id_fk]);
    $recipe_name = $name_stmt->fetchColumn();

    $keys = [(string) $recipe_id_fk];
    if ($recipe_name !== false && $recipe_name !== null) {
        $keys[] = $recipe_name;
    }

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $cs_stmt = $pdo->prepare(
        "SELECT COALESCE(value_num, value_text)
           FROM commissioning_settings
          WHERE section  = 'eta_defaults'
            AND key_name IN ({$placeholders})
            AND is_active = 1
          LIMIT 1"
    );
    $cs_stmt->execute($keys);
    $days_val = $cs_stmt->fetchColumn();

    if ($days_val !== false && is_numeric($days_val)) {
        $days   = (int) $days_val;
        $eta_dt = (new \DateTime())->modify("+{$days} days");
        return $eta_dt->format('Y-m-d');
    }

    // Step 3: refuse-don't-null → return null (caller renders "ETA non défini")
    return null;
}

/**
 * Heartbeat severity for a mother based on last_activity timing.
 *
 * Reads commissioning_settings.section='heartbeat' thresholds (green_max_hours,
 * amber_max_hours). Defaults to 24h / 72h when rows absent (mig 216 seeds them).
 *
 * Returns 'green' | 'amber' | 'red'
 */
function sb_heartbeat_severity(PDO $pdo, int $mother_id): string
{
    [$green_hours, $amber_hours] = _sb_heartbeat_thresholds($pdo);
    $last_activity_dt = _sb_last_activity($pdo, $mother_id);
    return _sb_compute_heartbeat($last_activity_dt, $green_hours, $amber_hours);
}

/**
 * Recently-closed mothers for the board's ghost strip surface.
 * Atom 3 BLOCK fix: surfaces consume this API instead of querying op_sessions
 * directly (single-source-of-truth contract for all board reads).
 *
 * Returns list of arrays with: id, batch, opened_at, closed_at, recipe_name, days_open.
 * Empty list if no closed mothers (graceful — caller renders "Aucun lot clos récemment").
 */
function sb_recent_closed_mothers(PDO $pdo, int $limit = 3): array
{
    $limit = max(1, min(50, $limit));
    try {
        $stmt = $pdo->prepare(
            "SELECT m.id, m.batch, m.opened_at, m.closed_at, r.name AS recipe_name,
                    TIMESTAMPDIFF(DAY, m.opened_at, m.closed_at) AS days_open
               FROM op_sessions m
               LEFT JOIN ref_recipes r ON r.id = m.recipe_id_fk
              WHERE m.form_type     = 'batch'
                AND m.status        = 'closed'
                AND m.is_tombstoned = 0
              ORDER BY m.closed_at DESC
              LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        // Graceful degrade — table may not exist in test environments.
        return [];
    }
}
