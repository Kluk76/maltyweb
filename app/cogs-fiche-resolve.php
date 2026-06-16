<?php
declare(strict_types=1);

/**
 * app/cogs-fiche-resolve.php
 *
 * COGS fiche precedence resolver + seal primitive.
 *
 * Public surface:
 *   cogs_fiche_resolve_month(PDO $pdo, string $month): array
 *   cogs_fiche_seal_month(PDO $pdo, string $month, string $sealedBy, ?string $note): int
 *   cogs_fiche_source_fingerprint(PDO $pdo, string $month): string
 *
 * Precedence (resolve):
 *   1. Active cogs_fiche_sealed row for $month  → provenance='sealed'  (NEVER live-recomputed)
 *   2. $month in cogs_fiche_seed                 → provenance='seed'
 *   3. $month in fin_closeable_months()           → provenance='live'   (cache-backed)
 *   4. Otherwise                                  → provenance='unavailable'
 *
 * Return shape for 'sealed' / 'seed' / 'live':
 *   [
 *     'provenance'  => 'sealed'|'seed'|'live'|'unavailable',
 *     'sealed_at'   => string|null,   // ISO datetime, sealed only
 *     'sealed_by'   => string|null,   // sealed only
 *     'categories'  => array<string, array{rm_chf,wip_chf,fg_chf,total_chf,opening_chf,variation_chf,basis_adjustment_chf}>,
 *     'totals'      => same shape as categories value,
 *   ]
 *
 * Cache (live months only):
 *   Computes source_fingerprint → if cogs_fiche_monthly rows exist with matching fingerprint,
 *   returns cached rows. Otherwise recomputes via cogs_fiche_compute_month() and UPSERTs cache.
 *   Sealed months bypass the cache entirely.
 */

require_once __DIR__ . '/cogs-fiche-compute.php';
require_once __DIR__ . '/finance-period.php';

// ── Source fingerprint ─────────────────────────────────────────────────────────

/**
 * Compute a cheap fingerprint over the month's source state.
 *
 * Hashes: row counts + MAX(updated_at) for each of the three source tables
 * (inv_rm_stocktake by period, inv_fg_stocktake by month_closed, inv_tank_balances by month_key).
 *
 * A change in any row count or any updated_at → different fingerprint → cache miss → recompute.
 */
function cogs_fiche_source_fingerprint(PDO $pdo, string $month): string
{
    $stmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*)       FROM inv_rm_stocktake  WHERE period       = ? AND is_active = 1) AS rm_count,
            (SELECT MAX(updated_at) FROM inv_rm_stocktake WHERE period       = ? AND is_active = 1) AS rm_max_upd,
            (SELECT COUNT(*)       FROM inv_fg_stocktake  WHERE month_closed = ?)                   AS fg_count,
            (SELECT MAX(updated_at) FROM inv_fg_stocktake WHERE month_closed = ?)                   AS fg_max_upd,
            (SELECT COUNT(*)       FROM inv_tank_balances WHERE month_key    = ?)                   AS wip_count,
            (SELECT MAX(updated_at) FROM inv_tank_balances WHERE month_key   = ?)                   AS wip_max_upd
    ");
    $stmt->execute([$month, $month, $month, $month, $month, $month]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $raw = implode('|', [
        $row['rm_count']  ?? '0',
        $row['rm_max_upd'] ?? 'null',
        $row['fg_count']  ?? '0',
        $row['fg_max_upd'] ?? 'null',
        $row['wip_count'] ?? '0',
        $row['wip_max_upd'] ?? 'null',
        $month,
    ]);

    return sha1($raw);
}

// ── Internal helpers ───────────────────────────────────────────────────────────

/**
 * Build the standard categories + totals structure from a flat array keyed by category_key.
 *
 * @param array<string, array{rm_chf:float,wip_chf:float,fg_chf:float,total_chf:float,opening_chf:float,variation_chf:float,basis_adjustment_chf:float}> $catMap
 */
function _cfs_build_result(string $provenance, array $catMap, ?string $sealedAt, ?string $sealedBy): array
{
    $totals = [
        'rm_chf'               => 0.0,
        'wip_chf'              => 0.0,
        'fg_chf'               => 0.0,
        'total_chf'            => 0.0,
        'opening_chf'          => 0.0,
        'variation_chf'        => 0.0,
        'basis_adjustment_chf' => 0.0,
    ];

    foreach ($catMap as $vals) {
        $totals['rm_chf']               += (float)$vals['rm_chf'];
        $totals['wip_chf']              += (float)$vals['wip_chf'];
        $totals['fg_chf']               += (float)$vals['fg_chf'];
        $totals['total_chf']            += (float)$vals['total_chf'];
        $totals['opening_chf']          += (float)$vals['opening_chf'];
        $totals['variation_chf']        += (float)$vals['variation_chf'];
        $totals['basis_adjustment_chf'] += (float)$vals['basis_adjustment_chf'];
    }

    $result = [
        'provenance'  => $provenance,
        'sealed_at'   => $sealedAt,
        'sealed_by'   => $sealedBy,
        'categories'  => $catMap,
        'totals'      => $totals,
    ];

    return $result;
}

/**
 * Read frozen rows from cogs_fiche_sealed for the active seal of $month.
 * Returns null if no sealed rows exist.
 *
 * Active seal = the seal event with the latest sealed_at for the month.
 * Identified by the MAX(sealed_at) sub-query so we pick the most recent seal event.
 */
function _cfs_read_sealed(PDO $pdo, string $month): ?array
{
    // Active seal = the event with the highest id (last inserted wins; each event inserts
    // 12 rows with shared sealed_at+sealed_by+supersedes_seal_id). We identify the active
    // event by finding MAX(id) for the month, then reading all rows that share the same
    // (sealed_at, sealed_by, supersedes_seal_id) as that max-id row. This correctly
    // disambiguates two seal events that share the same second.
    $stmt = $pdo->prepare("
        SELECT s.*
        FROM cogs_fiche_sealed s
        INNER JOIN (
            SELECT sealed_at, sealed_by, supersedes_seal_id
            FROM cogs_fiche_sealed
            WHERE month_key = ?
            ORDER BY id DESC
            LIMIT 1
        ) latest
            ON  s.sealed_at           = latest.sealed_at
            AND s.sealed_by           <=> latest.sealed_by
            AND s.supersedes_seal_id  <=> latest.supersedes_seal_id
        WHERE s.month_key = ?
        ORDER BY s.id ASC
    ");
    $stmt->execute([$month, $month]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return null;
    }

    $sealedAt = (string)$rows[0]['sealed_at'];
    $sealedBy = $rows[0]['sealed_by'] !== null ? (string)$rows[0]['sealed_by'] : null;

    $catMap = [];
    foreach ($rows as $r) {
        $ck = (string)$r['category_key'];
        $catMap[$ck] = [
            'rm_chf'               => (float)$r['rm_chf'],
            'wip_chf'              => (float)$r['wip_chf'],
            'fg_chf'               => (float)$r['fg_chf'],
            'total_chf'            => (float)$r['total_chf'],
            'opening_chf'          => (float)$r['opening_chf'],
            'variation_chf'        => (float)$r['variation_chf'],
            'basis_adjustment_chf' => (float)$r['basis_adjustment_chf'],
        ];
    }

    return _cfs_build_result('sealed', $catMap, $sealedAt, $sealedBy);
}

/**
 * Read rows from cogs_fiche_seed for $month.
 * Returns null if not in seed.
 */
function _cfs_read_seed(PDO $pdo, string $month): ?array
{
    $stmt = $pdo->prepare("
        SELECT category_key, rm_chf, wip_chf, fg_chf, total_chf,
               opening_chf, variation_chf,
               0 AS basis_adjustment_chf
        FROM cogs_fiche_seed
        WHERE month_key = ?
        ORDER BY category_key
    ");
    $stmt->execute([$month]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return null;
    }

    $catMap = [];
    foreach ($rows as $r) {
        $catMap[(string)$r['category_key']] = [
            'rm_chf'               => (float)$r['rm_chf'],
            'wip_chf'              => (float)$r['wip_chf'],
            'fg_chf'               => (float)$r['fg_chf'],
            'total_chf'            => (float)$r['total_chf'],
            'opening_chf'          => (float)$r['opening_chf'],
            'variation_chf'        => (float)$r['variation_chf'],
            'basis_adjustment_chf' => (float)$r['basis_adjustment_chf'],
        ];
    }

    return _cfs_build_result('seed', $catMap, null, null);
}

/**
 * Read cached rows from cogs_fiche_monthly for $month.
 * Returns null if no rows or fingerprint missing/mismatched.
 */
function _cfs_read_cache(PDO $pdo, string $month, string $fingerprint): ?array
{
    $stmt = $pdo->prepare("
        SELECT category_key, rm_chf, wip_chf, fg_chf, total_chf,
               opening_chf, variation_chf, basis_adjustment_chf,
               source_fingerprint
        FROM cogs_fiche_monthly
        WHERE month_key = ?
        ORDER BY category_key
    ");
    $stmt->execute([$month]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return null;
    }

    // All rows must have matching fingerprint
    foreach ($rows as $r) {
        if ((string)($r['source_fingerprint'] ?? '') !== $fingerprint) {
            return null;
        }
    }

    $catMap = [];
    foreach ($rows as $r) {
        $catMap[(string)$r['category_key']] = [
            'rm_chf'               => (float)$r['rm_chf'],
            'wip_chf'              => (float)$r['wip_chf'],
            'fg_chf'               => (float)$r['fg_chf'],
            'total_chf'            => (float)$r['total_chf'],
            'opening_chf'          => (float)$r['opening_chf'],
            'variation_chf'        => (float)$r['variation_chf'],
            'basis_adjustment_chf' => (float)$r['basis_adjustment_chf'],
        ];
    }

    return _cfs_build_result('live', $catMap, null, null);
}

/**
 * Recompute via cogs_fiche_compute_month() and UPSERT the 12 rows into cogs_fiche_monthly.
 * Returns the live result array (provenance='live').
 */
function _cfs_recompute_and_cache(PDO $pdo, string $month, string $fingerprint): array
{
    $computed  = cogs_fiche_compute_month($pdo, $month);
    $computedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $catMap    = [];

    // Prepare once outside the loop — 12 executes, 1 prepare.
    $stmt = $pdo->prepare("
        INSERT INTO cogs_fiche_monthly
            (month_key, category_key, rm_chf, wip_chf, fg_chf, total_chf,
             opening_chf, variation_chf, basis_adjustment_chf, computed_at,
             source_fingerprint, row_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            rm_chf               = VALUES(rm_chf),
            wip_chf              = VALUES(wip_chf),
            fg_chf               = VALUES(fg_chf),
            total_chf            = VALUES(total_chf),
            opening_chf          = VALUES(opening_chf),
            variation_chf        = VALUES(variation_chf),
            basis_adjustment_chf = VALUES(basis_adjustment_chf),
            computed_at          = VALUES(computed_at),
            source_fingerprint   = VALUES(source_fingerprint),
            row_hash             = VALUES(row_hash)
    ");

    foreach (COGS_FICHE_CATEGORIES as $cat) {
        $vals = $computed['categories'][$cat];

        // Build row_hash over stable fields for idempotency
        $hashInput = implode('|', [
            $month,
            $cat,
            number_format((float)$vals['rm_chf'], 4, '.', ''),
            number_format((float)$vals['wip_chf'], 4, '.', ''),
            number_format((float)$vals['fg_chf'], 4, '.', ''),
            number_format((float)$vals['total_chf'], 4, '.', ''),
            number_format((float)$vals['opening_chf'], 4, '.', ''),
            number_format((float)$vals['variation_chf'], 4, '.', ''),
            number_format((float)$vals['basis_adjustment_chf'], 4, '.', ''),
        ]);
        $rowHash = hash('sha256', $hashInput);

        $stmt->execute([
            $month,
            $cat,
            (string)$vals['rm_chf'],
            (string)$vals['wip_chf'],
            (string)$vals['fg_chf'],
            (string)$vals['total_chf'],
            (string)$vals['opening_chf'],
            (string)$vals['variation_chf'],
            (string)$vals['basis_adjustment_chf'],
            $computedAt,
            $fingerprint,
            $rowHash,
        ]);

        $catMap[$cat] = [
            'rm_chf'               => (float)$vals['rm_chf'],
            'wip_chf'              => (float)$vals['wip_chf'],
            'fg_chf'               => (float)$vals['fg_chf'],
            'total_chf'            => (float)$vals['total_chf'],
            'opening_chf'          => (float)$vals['opening_chf'],
            'variation_chf'        => (float)$vals['variation_chf'],
            'basis_adjustment_chf' => (float)$vals['basis_adjustment_chf'],
        ];
    }

    return _cfs_build_result('live', $catMap, null, null);
}

// ── Public API: resolver ───────────────────────────────────────────────────────

/**
 * Resolve the COGS fiche for $month by precedence:
 *   1. Active cogs_fiche_sealed  → provenance='sealed'
 *   2. cogs_fiche_seed           → provenance='seed'
 *   3. fin_closeable_months()    → provenance='live'  (fingerprint-cached)
 *   4. Otherwise                 → provenance='unavailable'
 *
 * Sealed months are NEVER live-recomputed — frozen values are returned as-is.
 * Live months use a fingerprint cache backed by cogs_fiche_monthly.
 */
function cogs_fiche_resolve_month(PDO $pdo, string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        return [
            'provenance'  => 'unavailable',
            'sealed_at'   => null,
            'sealed_by'   => null,
            'categories'  => [],
            'totals'      => [],
        ];
    }

    // 1. Active seal?
    $sealed = _cfs_read_sealed($pdo, $month);
    if ($sealed !== null) {
        return $sealed;
    }

    // 2. Seeded month?
    $seed = _cfs_read_seed($pdo, $month);
    if ($seed !== null) {
        return $seed;
    }

    // 3. Closeable (live, cache-backed)?
    $closeable = fin_closeable_months($pdo);
    if (in_array($month, $closeable, true)) {
        $fingerprint = cogs_fiche_source_fingerprint($pdo, $month);
        $cached = _cfs_read_cache($pdo, $month, $fingerprint);
        if ($cached !== null) {
            return $cached;
        }
        return _cfs_recompute_and_cache($pdo, $month, $fingerprint);
    }

    // 4. Unavailable
    return [
        'provenance'  => 'unavailable',
        'sealed_at'   => null,
        'sealed_by'   => null,
        'categories'  => [],
        'totals'      => [],
    ];
}

// ── Public API: seal primitive ─────────────────────────────────────────────────

/**
 * Freeze the current resolved-live values of $month into cogs_fiche_sealed.
 *
 * If an active seal already exists for $month, this is a RESTATEMENT:
 *   - new rows are inserted with supersedes_seal_id = prior active seal's id
 *   - the prior seal rows are retained as history
 *
 * Returns the id of the first newly inserted row (representative seal event id).
 *
 * Guards:
 *   - Refuses to seal April (cogs_fiche_seed anchor — immutable).
 *   - Refuses to seal a month not in fin_closeable_months().
 *   - Runs in a transaction (all 12 rows or none).
 *
 * @throws \RuntimeException on guard violations or DB errors
 */
function cogs_fiche_seal_month(PDO $pdo, string $month, string $sealedBy, ?string $note): int
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        throw new \InvalidArgumentException(sprintf('Invalid month "%s"', $month));
    }

    // Guard 1: refuse April seed anchor
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cogs_fiche_seed WHERE month_key = ?");
    $stmt->execute([$month]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new \RuntimeException(sprintf(
            'cogs_fiche_seal_month: cannot seal month "%s" — it is a seeded anchor (immutable). '
            . 'Sealing the seed would break the opening chain.',
            $month
        ));
    }

    // Guard 2: month must be closeable
    $closeable = fin_closeable_months($pdo);
    if (!in_array($month, $closeable, true)) {
        throw new \RuntimeException(sprintf(
            'cogs_fiche_seal_month: cannot seal month "%s" — not in fin_closeable_months() '
            . '(missing RM, FG or WIP census). Available: %s',
            $month,
            implode(', ', $closeable) ?: 'none'
        ));
    }

    // Resolve current live values directly (bypass sealed branch even for restatements).
    // On a restatement the month is already sealed, so cogs_fiche_resolve_month would return
    // provenance='sealed' — we need the live compute instead.
    $fingerprint = cogs_fiche_source_fingerprint($pdo, $month);
    $cached = _cfs_read_cache($pdo, $month, $fingerprint);
    $live   = $cached ?? _cfs_recompute_and_cache($pdo, $month, $fingerprint);

    // Find prior active seal's HEAD row id (= MIN(id) of the most-recent seal event).
    // "Active event" = the group of 12 rows sharing the latest sealed_at.
    // We store the HEAD (first-inserted = MIN id) so chain-navigation always lands
    // on the representative row — consistent with _cfs_read_sealed's identity query.
    $priorStmt = $pdo->prepare("
        SELECT MIN(s.id) AS head_id
        FROM cogs_fiche_sealed s
        INNER JOIN (
            SELECT sealed_at, sealed_by, supersedes_seal_id
            FROM cogs_fiche_sealed
            WHERE month_key = ?
            ORDER BY sealed_at DESC, id DESC
            LIMIT 1
        ) latest
            ON  s.sealed_at           = latest.sealed_at
            AND s.sealed_by           <=> latest.sealed_by
            AND s.supersedes_seal_id  <=> latest.supersedes_seal_id
        WHERE s.month_key = ?
    ");
    $priorStmt->execute([$month, $month]);
    $priorRow   = $priorStmt->fetch(PDO::FETCH_ASSOC);
    $supersedes = ($priorRow && $priorRow['head_id'] !== null) ? (int)$priorRow['head_id'] : null;

    // Guard VARCHAR(128) column — truncate silently rather than let MySQL reject.
    $sealedBy   = mb_substr($sealedBy, 0, 128);
    $sealedAt   = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $firstNewId = null;

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("
            INSERT INTO cogs_fiche_sealed
                (month_key, category_key, rm_chf, wip_chf, fg_chf, total_chf,
                 opening_chf, variation_chf, basis_adjustment_chf,
                 sealed_at, sealed_by, supersedes_seal_id, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach (COGS_FICHE_CATEGORIES as $cat) {
            $vals = $live['categories'][$cat] ?? [
                'rm_chf'               => 0.0,
                'wip_chf'              => 0.0,
                'fg_chf'               => 0.0,
                'total_chf'            => 0.0,
                'opening_chf'          => 0.0,
                'variation_chf'        => 0.0,
                'basis_adjustment_chf' => 0.0,
            ];

            $ins->execute([
                $month,
                $cat,
                (string)$vals['rm_chf'],
                (string)$vals['wip_chf'],
                (string)$vals['fg_chf'],
                (string)$vals['total_chf'],
                (string)$vals['opening_chf'],
                (string)$vals['variation_chf'],
                (string)$vals['basis_adjustment_chf'],
                $sealedAt,
                $sealedBy !== '' ? $sealedBy : null,
                $supersedes,
                $note,
            ]);

            if ($firstNewId === null) {
                $firstNewId = (int)$pdo->lastInsertId();
            }
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($firstNewId === null) {
        throw new \RuntimeException('cogs_fiche_seal_month: no rows inserted (COGS_FICHE_CATEGORIES is empty?)');
    }

    return $firstNewId;
}
