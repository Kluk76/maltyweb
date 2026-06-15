<?php
declare(strict_types=1);

/**
 * db-write-helpers.php — Shared form-write infrastructure for operator input forms.
 *
 * Public surface (clone these in fan-out forms):
 *   bd_upsert($pdo, $table, $row, $nkCols)   → ['action'=>'insert'|'update', 'id'=>BIGINT]
 *   bd_row_hash(array $canonical)             → CHAR(64) sha256
 *   bd_qc_flag(array $measurements, string $beerType) → 'normal'|'elevated'|'outlier'
 *   log_revision($pdo, $me, $table, $pk, $before, $after, $qcFlag, $comment) → void
 *
 * Dependencies: app/auth.php (current_user), app/db.php (maltytask_pdo).
 * No Google Sheets, no BSF writes. MySQL only.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// ──────────────────────────────────────────────────────────────────────────────
// UPSERT
// ──────────────────────────────────────────────────────────────────────────────

/**
 * UPSERT a single row into a bd_*_v2 table.
 *
 * Behaviour mirrors the Python ingest upsert_rows():
 *   - INSERT … ON DUPLICATE KEY UPDATE skipping NK cols, row_hash, imported_at, id.
 *   - Returns ['action' => 'insert'|'update', 'id' => BIGINT].
 *
 * $nkCols: list of column names that form the natural key (must already be in $row).
 * $row: all writable columns including row_hash; must NOT include id or imported_at.
 *
 * The form layer computes row_hash before calling this; updated_at is handled by the
 * DB ON UPDATE CURRENT_TIMESTAMP default.
 *
 * @throws RuntimeException on SQL error (caller should catch + pdo_friendly_error).
 */
function bd_upsert(PDO $pdo, string $table, array $row, array $nkCols): array
{
    // Columns that must never appear in ON DUPLICATE KEY UPDATE
    static $IMMUTABLE = ['id', 'imported_at'];

    $skipOnUpdate = array_merge($nkCols, $IMMUTABLE);

    $cols       = array_keys($row);
    $colList    = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $updateParts = [];
    foreach ($cols as $c) {
        if (!in_array($c, $skipOnUpdate, true)) {
            $updateParts[] = "`$c` = VALUES(`$c`)";
        }
    }
    $updateClause = implode(', ', $updateParts);

    $sql = "INSERT INTO `$table` ($colList) VALUES ($placeholders)"
         . " ON DUPLICATE KEY UPDATE $updateClause, `updated_at` = CURRENT_TIMESTAMP";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($row));

    $affectedRows = $stmt->rowCount();
    // MySQL: 1 = insert, 2 = update (row changed), 0 = update (no change)
    $action = ($affectedRows === 1) ? 'insert' : 'update';

    // Fetch the PK — LAST_INSERT_ID() is reliable for both insert and update
    // because ON DUPLICATE KEY UPDATE sets it to the existing row's PK on update.
    $pk = (int) $pdo->lastInsertId();
    if ($pk === 0) {
        // Fallback: look up PK from NK for the no-change update case
        $nkWhere = implode(' AND ', array_map(fn($c) => "`$c` = ?", $nkCols));
        $nkVals  = array_map(fn($c) => $row[$c], $nkCols);
        $r = $pdo->prepare("SELECT id FROM `$table` WHERE $nkWhere LIMIT 1");
        $r->execute($nkVals);
        $pk = (int) ($r->fetchColumn() ?: 0);
    }

    return ['action' => $action, 'id' => $pk];
}

// ──────────────────────────────────────────────────────────────────────────────
// ROW HASH
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Computes a deterministic SHA-256 row_hash from an array of canonical values.
 * Mirrors compute_row_hash() in settings-helpers.php but is distinct: this hash
 * is keyed on canonical-column values only (exclude id, imported_at, updated_at,
 * audit_flags — pass only the "stable" columns).
 *
 * The next Python ingest recomputes uq_row_hash on its own canonical set — that's
 * fine because the form marks rows with audit_flags 'web_entry' so ingest can
 * detect and preserve them without collision.
 */
function bd_row_hash(array $canonical): string
{
    $parts = array_map(fn($v) => $v === null ? '' : (string) $v, $canonical);
    return hash('sha256', implode('|', $parts));
}

// ──────────────────────────────────────────────────────────────────────────────
// QC FLAG
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Compute a QC flag from measurement values and beer type.
 *
 * Rules (all soft — NEVER block submission):
 *   bbt_co2:       normal 3.5–5.0 g/L / elevated 2.5–6.0 / outlier otherwise
 *   bbt_o2:        normal <50 ppb / elevated 50–200 / outlier >200
 *   racked_vol_hl: normal 10–100 HL / elevated 1–10 or 100–150 / outlier otherwise
 *   bbt_pressure:  normal 0.8–2.5 bar / elevated 0.5–3.5 / outlier otherwise
 *
 * $beerType: 'seltzer' | 'beer' (default) — NYL hard seltzer has wider pH range.
 * Returns the worst flag across all checked measurements.
 */
function bd_qc_flag(array $measurements, string $beerType = 'beer'): string
{
    $flag = 'normal';

    $escalate = static function (string &$current, string $candidate): void {
        $order = ['normal' => 0, 'elevated' => 1, 'outlier' => 2];
        if (($order[$candidate] ?? 0) > ($order[$current] ?? 0)) {
            $current = $candidate;
        }
    };

    // CO2 (g/L)
    if (isset($measurements['bbt_co2'])) {
        $v = (float) $measurements['bbt_co2'];
        if ($v < 2.5 || $v > 6.0) {
            $escalate($flag, 'outlier');
        } elseif ($v < 3.5 || $v > 5.0) {
            $escalate($flag, 'elevated');
        }
    }

    // O2 (ppb)
    if (isset($measurements['bbt_o2'])) {
        $v = (float) $measurements['bbt_o2'];
        if ($v > 200) {
            $escalate($flag, 'outlier');
        } elseif ($v >= 50) {
            $escalate($flag, 'elevated');
        }
    }

    // Racked volume (HL)
    if (isset($measurements['racked_vol_hl'])) {
        $v = (float) $measurements['racked_vol_hl'];
        if ($v <= 0 || $v > 150) {
            $escalate($flag, 'outlier');
        } elseif ($v < 10 || $v > 100) {
            $escalate($flag, 'elevated');
        }
    }

    // BBT pressure (bar)
    if (isset($measurements['bbt_pressure'])) {
        $v = (float) $measurements['bbt_pressure'];
        if ($v < 0 || $v > 3.5) {
            $escalate($flag, 'outlier');
        } elseif ($v < 0.5 || $v > 2.5) {
            $escalate($flag, 'elevated');
        }
    }

    return $flag;
}

/**
 * Compute soft-validation warnings for a measurement set.
 * Returns an array of human-readable warning strings (may be empty).
 * Never throws — used client-side mirror via the JS form-framework.
 */
function bd_soft_warnings(array $measurements, string $beerType = 'beer'): array
{
    $warnings = [];

    if (isset($measurements['bbt_co2'])) {
        $v = (float) $measurements['bbt_co2'];
        if ($v < 2.5 || $v > 6.0) {
            $warnings[] = "CO₂ {$v} g/L est hors de la plage typique (2.5–6.0 g/L).";
        } elseif ($v < 3.5 || $v > 5.0) {
            $warnings[] = "CO₂ {$v} g/L est légèrement hors plage (normal: 3.5–5.0 g/L).";
        }
    }
    if (isset($measurements['bbt_o2'])) {
        $v = (float) $measurements['bbt_o2'];
        if ($v > 200) {
            $warnings[] = "O₂ {$v} ppb est très élevé (seuil critique: 200 ppb).";
        } elseif ($v >= 50) {
            $warnings[] = "O₂ {$v} ppb est élevé (normal: <50 ppb).";
        }
    }
    if (isset($measurements['racked_vol_hl'])) {
        $v = (float) $measurements['racked_vol_hl'];
        if ($v <= 0 || $v > 150) {
            $warnings[] = "Volume soutiré {$v} HL semble incorrect (plage: 1–150 HL).";
        } elseif ($v < 10 || $v > 100) {
            $warnings[] = "Volume soutiré {$v} HL est inhabituel (typique: 10–100 HL).";
        }
    }
    if (isset($measurements['bbt_pressure'])) {
        $v = (float) $measurements['bbt_pressure'];
        if ($v < 0 || $v > 3.5) {
            $warnings[] = "Pression BBT {$v} bar semble incorrecte (plage: 0–3.5 bar).";
        } elseif ($v < 0.5 || $v > 2.5) {
            $warnings[] = "Pression BBT {$v} bar est inhabituelle (normale: 0.8–2.5 bar).";
        }
    }

    // Swap detection: if values look transposed (e.g. gravity in CO2 field)
    // Not applicable to racking (no gravity), but kept for future forms.

    return $warnings;
}

// ──────────────────────────────────────────────────────────────────────────────
// AUDIT REVISION LOG
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Writes one row to audit_row_revisions.
 *
 * @param PDO    $pdo      Live connection (share the same transaction when possible)
 * @param array  $me       current_user() result: must have keys id, username
 * @param string $table    Target table (e.g. 'bd_racking_v2')
 * @param int    $pk       PK of the row that was inserted/updated
 * @param ?array $before   Previous row values (null on insert)
 * @param array  $after    Submitted values (post-write)
 * @param string $qcFlag   'normal'|'elevated'|'outlier'
 * @param ?string $comment Operator comment (required when outlier; nullable for normal/elevated)
 */
function log_revision(
    PDO     $pdo,
    array   $me,
    string  $table,
    int     $pk,
    ?array  $before,
    array   $after,
    string  $qcFlag,
    ?string $comment = null
): void {
    $userId   = (int) $me['id'];
    $username = (string) $me['username'];
    $action   = ($before === null) ? 'insert' : 'update';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? null;
    $ipClean  = $ip !== null ? substr($ip, 0, 45) : null;

    $stmt = $pdo->prepare(
        "INSERT INTO audit_row_revisions
          (user_id, username, ip, target_table, target_pk, action, before_json, after_json, comment, qc_flag)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $userId,
        $username,
        $ipClean,
        $table,
        $pk,
        $action,
        $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
        json_encode($after, JSON_UNESCAPED_UNICODE),
        $comment,
        $qcFlag,
    ]);
}

// ──────────────────────────────────────────────────────────────────────────────
// VALIDATION HELPERS
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Fetches a row by PK from a table (for before-snapshot on UPDATE).
 * Returns null if the row doesn't exist.
 */
function bd_fetch_before(PDO $pdo, string $table, int $pk): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? LIMIT 1");
    $stmt->execute([$pk]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/**
 * Looks up an existing row's PK by NK columns (for before-snapshot when PK is unknown).
 * Returns null if no matching row exists.
 */
function bd_lookup_pk_by_nk(PDO $pdo, string $table, array $nkCols, array $row): ?int
{
    $where  = implode(' AND ', array_map(fn($c) => "`$c` = ?", $nkCols));
    $vals   = array_map(fn($c) => $row[$c] ?? null, $nkCols);
    $stmt   = $pdo->prepare("SELECT id FROM `$table` WHERE $where LIMIT 1");
    $stmt->execute($vals);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : null;
}

/**
 * Parses a POST decimal field: normalises comma→dot, returns null when empty
 * or non-numeric. Used by QA write-handlers (qa-net-content, qa-cleaning-efficacy,
 * qa-bottle-reception) and any other form that accepts nullable decimal inputs.
 */
function parse_nullable_decimal(string $raw): ?string
{
    $clean = str_replace(',', '.', trim($raw));
    if ($clean === '' || !is_numeric($clean)) {
        return null;
    }
    return $clean;
}
