<?php
declare(strict_types=1);
/**
 * app/cip-cadence.php — BBT CIP cadence resolver (C6b).
 *
 * Reads commissioning_settings WHERE section='cip_cadence' for thresholds +
 * step-type id sets, walks bd_cip_events × bd_racking_v2 back from now, and
 * computes per-BBT: last CIP timestamps, rack counts since each CIP class, and
 * a recommended_action / severity signal.
 *
 * DESIGN CHOICE (P-B): this file is a pure-read resolver. In P-B its output is
 * surfaced as window.BBT_CIP_CADENCE (a JS data injection) by session-body-
 * racking.php. Consumption in the BBT picker (IN_PROGRESS phase, S5) is deferred
 * to P-C. This is the simpler P-B-scoped approach: build the resolver + data
 * surface now; render badges in the BBT picker in P-C.
 *
 * Pattern after app/qc-thresholds.php:
 *   – Pure read, no writes, no static caches (compute-on-read).
 *   – Returns structured arrays with explicit keys; caller never parses raw SQL.
 *   – Memoised per-request via static $cache.
 *
 * Query strategy: ONE batched query for all BBTs (≤ 6 in prod), not N per-BBT
 * round-trips. The per-BBT rack count since last CIP uses a subquery correlated
 * on bbt_number — acceptable on small data sets (≤ 400 racking rows).
 *
 * Public API:
 *   cadence_for_bbt(PDO $pdo, int $bbtNumber): array
 *   cadence_for_all_bbts(PDO $pdo): array<int, array>   keyed by bbt_number
 *
 * Return shape for each bbt_number:
 *   'bbt_number'         => int
 *   'last_full_cip_at'   => string|null  (cip_date string from bd_cip_events, latest full reset)
 *   'last_acid_cip_at'   => string|null  (latest acid-or-fuller reset)
 *   'racks_since_full'   => int          (racking rows in bd_racking_v2 after last full CIP date)
 *   'racks_since_acid'   => int          (racking rows after last acid-or-fuller CIP date)
 *   'threshold_full'     => int          (from commissioning_settings)
 *   'threshold_acid'     => int          (from commissioning_settings)
 *   'recommended_action' => 'none' | 'acid_recommended' | 'full_recommended'
 *   'severity'           => 'ok' | 'warn' | 'critical'
 *
 * Dependencies: none beyond PDO (caller passes connection).
 */

// ─── Internal helpers ──────────────────────────────────────────────────────────

/**
 * Load and parse commissioning_settings WHERE section='cip_cadence'.
 * Returns:
 *   [
 *     'threshold_acid'      => int,
 *     'threshold_full'      => int,
 *     'acid_reset_type_ids' => int[],   // ref_cip_types ids counting as acid reset
 *     'full_reset_type_ids' => int[],   // ref_cip_types ids counting as full reset
 *   ]
 * Memoised per request.
 *
 * @internal
 */
function _cip_cadence_config(PDO $pdo): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $stmt = $pdo->prepare(
        "SELECT key_name, value_num, value_text
           FROM commissioning_settings
          WHERE section = 'cip_cadence'
            AND is_active = 1"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $map[$row['key_name']] = $row;
    }

    // Integer thresholds — default to 6 if missing (matches migration 190 defaults).
    $thresholdAcid = isset($map['cip_cadence_acid_after'])
        ? max(1, (int)$map['cip_cadence_acid_after']['value_num'])
        : 6;
    $thresholdFull = isset($map['cip_cadence_full_after'])
        ? max(1, (int)$map['cip_cadence_full_after']['value_num'])
        : 6;

    // CSV of ref_cip_types ids — parse to int[].
    $parseCsv = static function (?string $raw): array {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int)trim($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    };

    $acidResetTypeIds = $parseCsv($map['cip_cadence_acid_reset_types']['value_text'] ?? null);
    $fullResetTypeIds = $parseCsv($map['cip_cadence_full_reset_types']['value_text'] ?? null);

    // Fallbacks matching migration 190 defaults.
    if (empty($acidResetTypeIds)) {
        $acidResetTypeIds = [2]; // Acide
    }
    if (empty($fullResetTypeIds)) {
        $fullResetTypeIds = [3, 4]; // Full CIP + Full CIP + rinser
    }

    $cfg = [
        'threshold_acid'      => $thresholdAcid,
        'threshold_full'      => $thresholdFull,
        'acid_reset_type_ids' => $acidResetTypeIds,
        'full_reset_type_ids' => $fullResetTypeIds,
    ];
    return $cfg;
}

/**
 * Compute cadence data for all active BBTs in one batched pass.
 *
 * Strategy:
 *   1. Load config (memoised).
 *   2. Fetch active BBT numbers from ref_bbt.
 *   3. For each class (full, acid): find the most-recent qualifying bd_cip_events
 *      row per BBT using a batched query (GROUP BY target_number + MAX(cip_date)
 *      within the relevant cip_type_id_fk set).
 *   4. Count bd_racking_v2 rows per BBT whose event_date > last relevant CIP date.
 *      NULL last-CIP → count all racking rows for that BBT (worst-case assumption).
 *
 * @internal
 * @return array<int, array>  keyed by bbt_number
 */
function _cip_cadence_compute(PDO $pdo): array
{
    static $computed = null;
    if ($computed !== null) {
        return $computed;
    }

    $cfg = _cip_cadence_config($pdo);

    // ── 1. Active BBT numbers ─────────────────────────────────────────────────
    $bbtStmt = $pdo->query(
        "SELECT number FROM ref_bbt WHERE status = 'active' ORDER BY number ASC"
    );
    $bbtNumbers = array_map('intval', array_column($bbtStmt->fetchAll(PDO::FETCH_ASSOC), 'number'));

    if (empty($bbtNumbers)) {
        $computed = [];
        return $computed;
    }

    // ── 2. Helpers: placeholders + batched CIP date query ─────────────────────

    // Build placeholders for a set of ids.
    $ph = static function (array $ids): string {
        return implode(', ', array_fill(0, count($ids), '?'));
    };

    // Fetch MAX(cip_date) per target_number for a given set of cip_type_id_fk ids.
    // cip_date is a varchar — MySQL MAX() on varchars does lexicographic comparison,
    // which works correctly for ISO dates (YYYY-MM-DD) or DD/MM/YYYY dates.
    // To be safe we also filter on a real date interpretation via GREATEST / STR_TO_DATE
    // fallback — but since the house convention is varchar free-text, we trust MAX()
    // for approximate cadence (within the same year, lexicographic order is reliable
    // for YYYY-MM-DD format; pre-2026 backfill may use other formats but those rows
    // have NULL cip_started_at and won't affect recent-cadence accuracy).
    $fetchLastCip = static function (array $typeIds) use ($pdo, $bbtNumbers, $ph): array {
        if (empty($typeIds)) {
            return [];
        }
        $bbtPh  = $ph($bbtNumbers);
        $typePh = $ph($typeIds);

        // bd_cip_events.cip_date is VARCHAR with MIXED formats in production:
        //   - ISO: '2025-05-05 00:00:00' or '2025-05-05'
        //   - US:  '8/29/2024' (Google Form artifact, %c/%e/%Y)
        // MAX() over varchar is lexicographic and silently wrong for mixed formats.
        // Coalesce parsing in SQL (cheaper than PHP-side normalization, single round-trip).
        $stmt = $pdo->prepare(
            "SELECT target_number,
                    DATE_FORMAT(MAX(COALESCE(
                      STR_TO_DATE(cip_date, '%Y-%m-%d %H:%i:%s'),
                      STR_TO_DATE(cip_date, '%Y-%m-%d'),
                      STR_TO_DATE(cip_date, '%c/%e/%Y %H:%i:%s'),
                      STR_TO_DATE(cip_date, '%c/%e/%Y')
                    )), '%Y-%m-%d') AS last_cip_date
               FROM bd_cip_events
              WHERE target_kind         = 'vessel'
                AND target_code         = 'bbt'
                AND is_tombstoned       = 0
                AND target_number       IN ({$bbtPh})
                AND cip_type_id_fk      IN ({$typePh})
              GROUP BY target_number"
        );
        $params = array_merge($bbtNumbers, $typeIds);
        $stmt->execute($params);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['target_number']] = $row['last_cip_date'];
        }
        return $result;
    };

    // ── 3. CIP reset class IDs ───────────────────────────────────────────────
    //    "last full CIP" = most recent BBT CIP of any FULL class only.
    //    "last acid CIP" = most recent BBT CIP of ACID OR FULL class
    //                       (a full CIP also resets the acid counter).
    $allAcidResetIds  = array_values(array_unique(array_merge(
        $cfg['acid_reset_type_ids'],
        $cfg['full_reset_type_ids']
    )));

    $lastFullCipByBbt = $fetchLastCip($cfg['full_reset_type_ids']);
    $lastAcidCipByBbt = $fetchLastCip($allAcidResetIds);

    // ── 4. Rack counts per BBT since last CIP date ────────────────────────────
    // For each BBT we need COUNT(bd_racking_v2 rows WHERE bbt_number=N AND
    // racking_destination_type='BBT' AND event_date > lastCipDate AND is_tombstoned=0).
    // We do this with ONE query using conditional aggregation over all BBTs.
    //
    // Build a UNION ALL of per-BBT date thresholds to join against, or use a
    // simpler per-BBT correlated approach since N≤6.
    //
    // APPROACH: one query that fetches ALL racking rows for the active BBTs,
    // then aggregate in PHP (avoids a dynamic pivot).

    $bbtPh = $ph($bbtNumbers);
    $rackStmt = $pdo->prepare(
        "SELECT bbt_number, event_date
           FROM bd_racking_v2
          WHERE bbt_number          IN ({$bbtPh})
            AND racking_destination_type = 'BBT'
            AND is_tombstoned        = 0
            AND interrupted_flag     = 0
          ORDER BY bbt_number ASC, event_date ASC"
    );
    $rackStmt->execute($bbtNumbers);

    // Group racking event_dates by bbt_number.
    $rackDatesByBbt = [];
    foreach ($bbtNumbers as $n) {
        $rackDatesByBbt[$n] = [];
    }
    foreach ($rackStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $bbtNum = (int)$row['bbt_number'];
        if (isset($rackDatesByBbt[$bbtNum])) {
            $rackDatesByBbt[$bbtNum][] = (string)$row['event_date'];
        }
    }

    // ── 5. Build per-BBT result ───────────────────────────────────────────────
    $result = [];
    foreach ($bbtNumbers as $bbtNum) {
        $lastFullAt = $lastFullCipByBbt[$bbtNum] ?? null;
        $lastAcidAt = $lastAcidCipByBbt[$bbtNum] ?? null;
        $rackDates  = $rackDatesByBbt[$bbtNum] ?? [];

        // Count racking events after the last full CIP date.
        $racksSinceFull = 0;
        if ($lastFullAt !== null) {
            foreach ($rackDates as $rd) {
                if ($rd > $lastFullAt) {
                    $racksSinceFull++;
                }
            }
        } else {
            // No full CIP on record: count ALL racking rows (conservative).
            $racksSinceFull = count($rackDates);
        }

        // Count racking events after the last acid-or-fuller CIP date.
        $racksSinceAcid = 0;
        if ($lastAcidAt !== null) {
            foreach ($rackDates as $rd) {
                if ($rd > $lastAcidAt) {
                    $racksSinceAcid++;
                }
            }
        } else {
            $racksSinceAcid = count($rackDates);
        }

        // recommended_action: full_recommended takes precedence.
        $threshFull = $cfg['threshold_full'];
        $threshAcid = $cfg['threshold_acid'];

        if ($racksSinceFull >= $threshFull) {
            $action   = 'full_recommended';
            $severity = 'critical';
        } elseif ($racksSinceAcid >= $threshAcid) {
            $action   = 'acid_recommended';
            $severity = 'warn';
        } else {
            $action   = 'none';
            $severity = 'ok';
        }

        $result[$bbtNum] = [
            'bbt_number'         => $bbtNum,
            'last_full_cip_at'   => $lastFullAt,
            'last_acid_cip_at'   => $lastAcidAt,
            'racks_since_full'   => $racksSinceFull,
            'racks_since_acid'   => $racksSinceAcid,
            'threshold_full'     => $threshFull,
            'threshold_acid'     => $threshAcid,
            'recommended_action' => $action,
            'severity'           => $severity,
        ];
    }

    $computed = $result;
    return $computed;
}

// ─── Public API ────────────────────────────────────────────────────────────────

/**
 * cadence_for_all_bbts — Compute CIP cadence for ALL active BBTs.
 *
 * Pure read. No writes. Idempotent. Memoised per request.
 *
 * @param PDO $pdo  Active DB connection (maltytask_pdo() or caller-provided).
 * @return array<int, array>  Keyed by bbt_number. See file-level docblock for shape.
 */
function cadence_for_all_bbts(PDO $pdo): array
{
    return _cip_cadence_compute($pdo);
}

/**
 * cadence_for_bbt — Compute CIP cadence for ONE specific BBT.
 *
 * Delegates to cadence_for_all_bbts (single batch) and returns the slice.
 * If the BBT is not active or has no history, returns a safe default with
 * recommended_action='none', severity='ok'.
 *
 * @param PDO $pdo
 * @param int $bbtNumber  The BBT number (ref_bbt.number).
 * @return array  See file-level docblock for shape.
 */
function cadence_for_bbt(PDO $pdo, int $bbtNumber): array
{
    $all = _cip_cadence_compute($pdo);

    if (isset($all[$bbtNumber])) {
        return $all[$bbtNumber];
    }

    // Safe default: BBT not in active list.
    $cfg = _cip_cadence_config($pdo);
    return [
        'bbt_number'         => $bbtNumber,
        'last_full_cip_at'   => null,
        'last_acid_cip_at'   => null,
        'racks_since_full'   => 0,
        'racks_since_acid'   => 0,
        'threshold_full'     => $cfg['threshold_full'],
        'threshold_acid'     => $cfg['threshold_acid'],
        'recommended_action' => 'none',
        'severity'           => 'ok',
    ];
}
