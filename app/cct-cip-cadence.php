<?php
declare(strict_types=1);
/**
 * app/cct-cip-cadence.php — CCT CIP cadence resolver for fermenting start firewall.
 *
 * DESIGN CHOICE (P-B, fermenting): this is a thin delegating wrapper around
 * app/cip-cadence.php (the BBT resolver). Rather than forking the resolver,
 * we keep ONE algorithm (days-since-last-CIP vs threshold) and parameterise
 * the two differences between CCT and BBT contexts:
 *
 *   1. `target_code` — 'cct' instead of 'bbt' in bd_cip_events
 *   2. `commissioning_settings key_name` prefix — 'cct_max_days_since_cip'
 *      and 'cct_warn_days_since_cip' instead of the BBT rack-count keys
 *
 * The BBT resolver uses rack-count since last CIP as the cadence metric.
 * For CCTs we use DAYS since last CIP (simpler, no bd_fermenting_v2 joins
 * required). This is a deliberate per-vessel-kind difference, not a fork.
 *
 * Public API:
 *   cct_cip_status(PDO $pdo, int $cctNumber): array
 *
 * Return shape:
 *   'cct_number'        => int
 *   'last_cip_at'       => string|null   ISO date or null if never recorded
 *   'days_since_cip'    => int|null      null when last_cip_at is null
 *   'max_days'          => int           from commissioning_settings (default 14)
 *   'warn_days'         => int           from commissioning_settings (default 10)
 *   'severity'          => 'ok'|'warn'|'red'
 *   'verdict_label'     => string        human-readable FR label
 *
 * Dependencies: none beyond PDO (caller passes connection).
 */

/**
 * Load CCT CIP cadence thresholds from commissioning_settings.
 *
 * Keys read: cct_max_days_since_cip, cct_warn_days_since_cip (section='cip_cadence').
 * Memoised per request via static.
 *
 * @internal
 */
function _cct_cip_config(PDO $pdo): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $stmt = $pdo->prepare(
        "SELECT key_name, value_num
           FROM commissioning_settings
          WHERE section  = 'cip_cadence'
            AND key_name IN ('cct_max_days_since_cip', 'cct_warn_days_since_cip')
            AND is_active = 1"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $map[$row['key_name']] = (int)$row['value_num'];
    }

    $cfg = [
        'max_days'  => $map['cct_max_days_since_cip']  ?? 14,
        'warn_days' => $map['cct_warn_days_since_cip'] ?? 10,
    ];
    return $cfg;
}

/**
 * Resolve the most-recent CIP date for a specific CCT from bd_cip_events.
 *
 * bd_cip_events.cip_date is VARCHAR with MIXED formats in production
 * (ISO '2025-05-05', ISO+time '2025-05-05 00:00:00', US '%c/%e/%Y').
 * Mirrors the STR_TO_DATE coalesce chain from cip-cadence.php exactly.
 *
 * @internal
 */
function _cct_last_cip_date(PDO $pdo, int $cctNumber): ?string
{
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(MAX(COALESCE(
                  STR_TO_DATE(cip_date, '%Y-%m-%d %H:%i:%s'),
                  STR_TO_DATE(cip_date, '%Y-%m-%d'),
                  STR_TO_DATE(cip_date, '%c/%e/%Y %H:%i:%s'),
                  STR_TO_DATE(cip_date, '%c/%e/%Y')
                )), '%Y-%m-%d') AS last_cip_date
           FROM bd_cip_events
          WHERE target_kind   = 'vessel'
            AND target_code   = 'cct'
            AND target_number = ?
            AND is_tombstoned = 0"
    );
    $stmt->execute([$cctNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return ($row !== false && $row['last_cip_date'] !== null)
        ? (string)$row['last_cip_date']
        : null;
}

// ─── Public API ────────────────────────────────────────────────────────────────

/**
 * cct_cip_status — Compute CIP cadence status for ONE specific CCT.
 *
 * Pure read. No writes. Idempotent. Memoised config per request.
 *
 * @param PDO $pdo         Active DB connection.
 * @param int $cctNumber   The CCT number (bd_brewing_brewday_v2.cct / ref_cct.number).
 * @return array  See file-level docblock for shape.
 */
function cct_cip_status(PDO $pdo, int $cctNumber): array
{
    $cfg       = _cct_cip_config($pdo);
    $lastCipAt = _cct_last_cip_date($pdo, $cctNumber);

    if ($lastCipAt !== null) {
        $lastTs      = strtotime($lastCipAt);
        $daysSince   = (int)floor((time() - $lastTs) / 86400);
    } else {
        $daysSince = null;
    }

    // Severity: red when never CIP'd or over max; warn when over warn; ok otherwise.
    if ($daysSince === null || $daysSince >= $cfg['max_days']) {
        $severity     = 'red';
        $verdictLabel = $daysSince === null
            ? 'Aucun CIP enregistré pour cette CCT'
            : "Dernier CIP il y a {$daysSince}j — dépasse la limite de {$cfg['max_days']}j";
    } elseif ($daysSince >= $cfg['warn_days']) {
        $severity     = 'warn';
        $verdictLabel = "Dernier CIP il y a {$daysSince}j (avertissement ≥ {$cfg['warn_days']}j)";
    } else {
        $severity     = 'ok';
        $verdictLabel = "CIP CCT {$cctNumber} : il y a {$daysSince}j";
    }

    return [
        'cct_number'     => $cctNumber,
        'last_cip_at'    => $lastCipAt,
        'days_since_cip' => $daysSince,
        'max_days'       => $cfg['max_days'],
        'warn_days'      => $cfg['warn_days'],
        'severity'       => $severity,
        'verdict_label'  => $verdictLabel,
    ];
}
