<?php
/**
 * KPI Handler Registry
 *
 * Hybrid registry: ~15–25 parameterized PHP handler functions grouped by
 * source_domain. One handler serves a whole domain; per-tracker variation
 * lives in the whitelisted params_json (period/groupby/filter/metric).
 *
 * CARDINAL RULE: every handler WRAPS an existing canonical source —
 * NEVER recomputes a fact the COGS/wort/packaging pipelines already own.
 *
 * Result shape contract (all handlers return this structure):
 * {
 *   "value":   mixed,         // primary scalar (number, string, or null)
 *   "unit":    string|null,   // display unit ("HL", "CHF", "%", "jours", …)
 *   "label":   string,        // human-readable label (from ref_kpi_trackers.label)
 *   "delta":   float|null,    // change vs prior period (positive = up); null if N/A
 *   "delta_label": string|null, // e.g. "vs mois dernier", "vs N-1"
 *   "tint":    "green"|"red"|"amber"|"neutral"|null, // semantic colouring hint
 *   "series":  array|null,    // [{period, value}] for sparkline/bar/waterfall
 *   "breakdown": array|null,  // [{key, label, value}] for donut/stacked_bar
 *   "meta":    array,         // free context for the UI (unit, period_label, …)
 * }
 *
 * On error: {"error": "message", "value": null, "unit": null, …}
 *
 * Usage:
 *   require_once __DIR__ . '/kpi-handlers.php';
 *   $result = kpi_dispatch($tracker, $pdo);
 *
 * where $tracker is a row from ref_kpi_trackers (source_domain, compute_handler,
 * params_json already decoded to array).
 */

declare(strict_types=1);

// ─── Param whitelist ──────────────────────────────────────────────────────────
// Allowed values per params_json key. Handlers validate against this before
// using any param. Unknown keys → handler refuses/returns error, never silently runs.

const KPI_ALLOWED_PERIODS = [
    'current_month', 'current_week', 'current_year',
    'latest_closed_month', 'rolling_3m', 'rolling_6m', 'rolling_12m',
    'ytd', 'today',
];
const KPI_ALLOWED_GROUPBY = [
    'classification', 'category', 'gl', 'sku', 'format', 'supplier',
];
const KPI_ALLOWED_METRICS = [
    'hl_brewed', 'hl_packaged', 'brew_count', 'unit_count',
    'total_chf', 'inbound_count', 'o2_ppm',
];
const KPI_ALLOWED_FILTERS = [];  // reserved for future per-recipe / per-site filtering
const KPI_ALLOWED_WINDOW_DAYS = [30, 60, 90, 180];  // trailing-day windows for rate-based KPIs

/**
 * Validate params_json against the whitelist. Returns a sanitized array
 * or throws on unknown keys/values.
 *
 * @throws RuntimeException on unknown param key or non-whitelisted value
 */
function kpi_validate_params(array $params): array
{
    $out = [];
    foreach ($params as $k => $v) {
        switch ($k) {
            case 'period':
                if (!in_array($v, KPI_ALLOWED_PERIODS, true)) {
                    throw new RuntimeException("kpi: unknown period '{$v}'");
                }
                $out['period'] = $v;
                break;
            case 'groupby':
                if (!in_array($v, KPI_ALLOWED_GROUPBY, true)) {
                    throw new RuntimeException("kpi: unknown groupby '{$v}'");
                }
                $out['groupby'] = $v;
                break;
            case 'metric':
                if (!in_array($v, KPI_ALLOWED_METRICS, true)) {
                    throw new RuntimeException("kpi: unknown metric '{$v}'");
                }
                $out['metric'] = $v;
                break;
            case 'limit':
                $out['limit'] = max(1, min(50, (int) $v));
                break;
            case 'window_days':
                $d = (int) $v;
                if (!in_array($d, KPI_ALLOWED_WINDOW_DAYS, true)) {
                    throw new RuntimeException(
                        "kpi: window_days must be one of " . implode(',', KPI_ALLOWED_WINDOW_DAYS) . ", got '{$v}'"
                    );
                }
                $out['window_days'] = $d;
                break;
            case 'filter':
                // reserved — no values allowed yet
                throw new RuntimeException("kpi: 'filter' param not yet supported");
            default:
                throw new RuntimeException("kpi: unknown param key '{$k}'");
        }
    }
    return $out;
}

/**
 * Per-request cache (static array — lives for the duration of one PHP request).
 * Prevents duplicate DB queries when mon-tableau.php loads 10+ trackers from
 * the same domain in a single page render.
 */
$_kpi_request_cache = [];

function kpi_cache_get(string $key): mixed
{
    global $_kpi_request_cache;
    return $_kpi_request_cache[$key] ?? null;
}

function kpi_cache_set(string $key, mixed $value): mixed
{
    global $_kpi_request_cache;
    $_kpi_request_cache[$key] = $value;
    return $value;
}

// ─── Empty result skeleton ────────────────────────────────────────────────────

function kpi_empty_result(string $label = '', string $unit = null): array
{
    return [
        'value'       => null,
        'unit'        => $unit,
        'label'       => $label,
        'delta'       => null,
        'delta_label' => null,
        'tint'        => 'neutral',
        'series'      => null,
        'breakdown'   => null,
        'meta'        => [],
    ];
}

function kpi_error_result(string $msg, string $label = ''): array
{
    return array_merge(kpi_empty_result($label), ['error' => $msg]);
}

// ─── Period helpers ───────────────────────────────────────────────────────────

/**
 * Resolve a period token to ['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD', 'label' => '…'].
 * 'latest_closed_month' → the most recent month where COGS pipeline has run (hardcoded
 * to previous month until a pipeline-last-run table exists).
 */
function kpi_resolve_period(string $period): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    switch ($period) {
        case 'current_month':
            $s = $now->modify('first day of this month')->format('Y-m-d');
            $e = $now->format('Y-m-d');
            return ['start' => $s, 'end' => $e, 'label' => $now->format('F Y')];
        case 'current_week':
            $s = $now->modify('monday this week')->format('Y-m-d');
            $e = $now->modify('sunday this week')->format('Y-m-d');
            return ['start' => $s, 'end' => $e, 'label' => 'semaine en cours'];
        case 'current_year':
            $s = $now->format('Y') . '-01-01';
            $e = $now->format('Y-m-d');
            return ['start' => $s, 'end' => $e, 'label' => 'YTD ' . $now->format('Y')];
        case 'ytd':
            $s = $now->format('Y') . '-01-01';
            $e = $now->format('Y-m-d');
            return ['start' => $s, 'end' => $e, 'label' => 'YTD ' . $now->format('Y')];
        case 'latest_closed_month':
            // Until a pipeline-last-run table exists, use previous calendar month.
            $lm = $now->modify('first day of last month');
            $s = $lm->format('Y-m-d');
            $e = $lm->modify('last day of this month')->format('Y-m-d');
            return ['start' => $s, 'end' => $e, 'label' => $lm->format('F Y')];
        case 'rolling_3m':
            $s = $now->modify('-3 months')->format('Y-m-d');
            $e = $now->format('Y-m-d');
            return ['start' => $s, 'end' => $e, 'label' => '3 derniers mois'];
        case 'rolling_6m':
            $s = $now->modify('-6 months')->format('Y-m-d');
            $e = $now->format('Y-m-d');
            return ['start' => $s, 'end' => $e, 'label' => '6 derniers mois'];
        case 'rolling_12m':
            $s = $now->modify('-12 months')->format('Y-m-d');
            $e = $now->format('Y-m-d');
            return ['start' => $s, 'end' => $e, 'label' => '12 derniers mois'];
        case 'today':
            $today = $now->format('Y-m-d');
            return ['start' => $today, 'end' => $today, 'label' => $now->format('d.m.Y')];
        default:
            throw new RuntimeException("kpi: unsupported period token '{$period}'");
    }
}

// ─── Main dispatch ────────────────────────────────────────────────────────────

/**
 * Dispatch a tracker to its handler.
 *
 * @param  array  $tracker  A row from ref_kpi_trackers with params_json already
 *                          decoded to array (or null).
 * @param  PDO    $pdo      An authenticated PDO connection to maltytask.
 * @return array            The normalized result shape.
 */
function kpi_dispatch(array $tracker, PDO $pdo): array
{
    $label   = $tracker['label']           ?? '';
    $domain  = $tracker['source_domain']   ?? '';
    $handler = $tracker['compute_handler'] ?? '';
    $params_raw = $tracker['params_json'] ?? [];
    if (is_string($params_raw)) {
        $params_raw = json_decode($params_raw, true) ?? [];
    }

    try {
        $params = kpi_validate_params($params_raw);
    } catch (RuntimeException $e) {
        return kpi_error_result($e->getMessage(), $label);
    }

    try {
        return match ($domain) {
            'ops_health'   => kpi_handler_ops_health($handler, $params, $label, $pdo),
            'cogs'         => kpi_handler_cogs($handler, $params, $label, $pdo),
            'wort'         => kpi_handler_wort($handler, $params, $label, $pdo),
            'rm_procurement' => kpi_handler_rm_procurement($handler, $params, $label, $pdo),
            'utilities'    => kpi_handler_utilities($handler, $params, $label, $pdo),
            'tanks'        => kpi_handler_tanks($handler, $params, $label, $pdo),
            'racking'      => kpi_handler_racking($handler, $params, $label, $pdo),
            'packaging'    => kpi_handler_packaging($handler, $params, $label, $pdo),
            'fg_stock'     => kpi_handler_fg_stock($handler, $params, $label, $pdo),
            'sales'        => kpi_stub_handler($domain, $handler, $label),
            'qa_qc'        => kpi_stub_handler($domain, $handler, $label),
            'equipment'    => kpi_stub_handler($domain, $handler, $label),
            'logistics'    => kpi_stub_handler($domain, $handler, $label),
            default        => kpi_error_result("kpi: unknown source_domain '{$domain}'", $label),
        };
    } catch (Throwable $e) {
        return kpi_error_result('kpi handler error: ' . $e->getMessage(), $label);
    }
}

// ─── Stub handler (not yet implemented domains) ───────────────────────────────

/**
 * Single source of truth for which source_domains still route to
 * kpi_stub_handler in the kpi_dispatch() match above. Consumed by
 * mon-tableau.php (picker exclusion + stub-mismatch watchdog).
 * KEEP IN SYNC with the match — when a domain gains a real handler,
 * remove it here in the same commit.
 */
function kpi_stub_domains(): array
{
    return ['sales', 'qa_qc', 'equipment', 'logistics'];
}

/**
 * Placeholder for domains whose v1 handlers ship in Phase 2b.
 * Returns a clearly-marked not-yet-available result so the UI can
 * render a "coming soon" tile rather than a blank error.
 */
function kpi_stub_handler(string $domain, string $handler, string $label): array
{
    $r = kpi_empty_result($label);
    $r['meta'] = [
        'stub'    => true,
        'domain'  => $domain,
        'handler' => $handler,
        'note'    => "Handler '{$domain}/{$handler}' arrives in Phase 2b.",
    ];
    return $r;
}


// ═════════════════════════════════════════════════════════════════════════════
// HANDLER: ops_health  (source_domain = 'ops_health')
// Reads: doc_review_queue, ingest_runs, doc_uploads, inv_deliveries, users
// All ✅ LIVE — the cheapest and most admin-valuable trackers.
// ═════════════════════════════════════════════════════════════════════════════

function kpi_handler_ops_health(
    string $handler,
    array  $params,
    string $label,
    PDO    $pdo
): array {
    return match ($handler) {
        'open_rq_by_type'           => kpi_ops_open_rq_by_type($label, $pdo),
        'rq_aging_oldest'           => kpi_ops_rq_aging_oldest($label, $pdo),
        'last_ingest_status'        => kpi_ops_last_ingest_status($label, $pdo),
        'docs_awaiting_triage'      => kpi_ops_docs_awaiting_triage($label, $pdo),
        'invoices_needing_line_items' => kpi_ops_invoices_needing_line_items($label, $pdo),
        'ingest_success_rate'       => kpi_ops_ingest_success_rate($params, $label, $pdo),
        'orphan_deliveries'         => kpi_ops_orphan_deliveries($label, $pdo),
        'pending_deliveries_aging'  => kpi_ops_pending_deliveries_aging($label, $pdo),
        'docs_processed_month'      => kpi_ops_docs_processed_month($params, $label, $pdo),
        'active_users_logins'       => kpi_ops_active_users_logins($label, $pdo),
        default                     => kpi_stub_handler('ops_health', $handler, $label),
    };
}

/** #213 — Open RQ items by type (bar breakdown) */
function kpi_ops_open_rq_by_type(string $label, PDO $pdo): array
{
    $cacheKey = 'ops_rq_open_by_type';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT type, COUNT(*) AS cnt
           FROM doc_review_queue
          WHERE status IN ('open','in_progress')
          GROUP BY type
          ORDER BY cnt DESC"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = array_sum(array_column($rows, 'cnt'));
    $breakdown = array_map(
        fn(array $r) => ['key' => $r['type'], 'label' => $r['type'], 'value' => (int) $r['cnt']],
        $rows
    );

    $result = array_merge(kpi_empty_result($label, 'items'), [
        'value'     => $total,
        'tint'      => $total === 0 ? 'green' : ($total > 10 ? 'red' : 'amber'),
        'breakdown' => $breakdown,
        'meta'      => ['period_label' => 'maintenant'],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #214 — RQ aging: oldest open item in days */
function kpi_ops_rq_aging_oldest(string $label, PDO $pdo): array
{
    $cacheKey = 'ops_rq_aging';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT DATEDIFF(CURDATE(), MIN(DATE(created_at))) AS age_days,
                MIN(DATE(created_at)) AS oldest_date
           FROM doc_review_queue
          WHERE status IN ('open','in_progress')"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $days = isset($row['age_days']) ? (int) $row['age_days'] : null;

    $result = array_merge(kpi_empty_result($label, 'jours'), [
        'value' => $days,
        'tint'  => $days === null ? 'green' : ($days > 30 ? 'red' : ($days > 7 ? 'amber' : 'green')),
        'meta'  => ['oldest_date' => $row['oldest_date'] ?? null],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #215 — Last ingest: status + age in hours */
function kpi_ops_last_ingest_status(string $label, PDO $pdo): array
{
    $cacheKey = 'ops_last_ingest';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT status,
                started_at,
                TIMESTAMPDIFF(HOUR, started_at, NOW()) AS hours_ago
           FROM ingest_runs
          ORDER BY started_at DESC
          LIMIT 1"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return array_merge(kpi_empty_result($label), [
            'tint' => 'amber', 'meta' => ['note' => 'Aucun ingest enregistré'],
        ]);
    }

    $hoursAgo = (int) $row['hours_ago'];
    $status   = $row['status'];

    $result = array_merge(kpi_empty_result($label, 'h'), [
        'value' => $hoursAgo,
        'tint'  => match($status) {
            'ok'      => ($hoursAgo <= 25 ? 'green' : 'amber'),
            'partial' => 'amber',
            'failed'  => 'red',
            default   => 'amber',
        },
        'meta' => [
            'status'     => $status,
            'started_at' => $row['started_at'],
            'hours_ago'  => $hoursAgo,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #216 — Documents awaiting triage (pipeline_status = uploaded|triggered) */
function kpi_ops_docs_awaiting_triage(string $label, PDO $pdo): array
{
    $cacheKey = 'ops_docs_awaiting';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT COUNT(*) AS cnt
           FROM doc_uploads
          WHERE pipeline_status IN ('uploaded','triggered')"
    );
    $cnt = (int) $stmt->fetchColumn();

    $result = array_merge(kpi_empty_result($label, 'docs'), [
        'value' => $cnt,
        'tint'  => $cnt === 0 ? 'green' : ($cnt > 5 ? 'amber' : 'neutral'),
        'meta'  => ['period_label' => 'maintenant'],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #217 — Invoices needing line items (RQ type = invoice-line-items-needed, open) */
function kpi_ops_invoices_needing_line_items(string $label, PDO $pdo): array
{
    $cacheKey = 'ops_invoices_line_items';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt
           FROM doc_review_queue
          WHERE type = 'invoice-line-items-needed'
            AND status IN ('open','in_progress')"
    );
    $stmt->execute();
    $cnt = (int) $stmt->fetchColumn();

    $result = array_merge(kpi_empty_result($label, 'factures'), [
        'value' => $cnt,
        'tint'  => $cnt === 0 ? 'green' : ($cnt > 20 ? 'red' : 'amber'),
        'meta'  => ['period_label' => 'maintenant'],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #218 — Ingest success rate this week */
function kpi_ops_ingest_success_rate(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_week';
    $p = kpi_resolve_period($period);

    $cacheKey = "ops_ingest_rate_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(status = 'ok') AS ok_cnt,
                SUM(status = 'partial') AS partial_cnt,
                SUM(status = 'failed') AS failed_cnt
           FROM ingest_runs
          WHERE DATE(started_at) BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $total   = (int) ($row['total']       ?? 0);
    $ok      = (int) ($row['ok_cnt']      ?? 0);
    $partial = (int) ($row['partial_cnt'] ?? 0);
    $failed  = (int) ($row['failed_cnt']  ?? 0);

    $rate = $total > 0 ? round(($ok / $total) * 100, 1) : null;

    $result = array_merge(kpi_empty_result($label, '%'), [
        'value' => $rate,
        'tint'  => $rate === null ? 'neutral'
                 : ($rate >= 90 ? 'green' : ($rate >= 70 ? 'amber' : 'red')),
        'meta'  => [
            'period_label' => $p['label'],
            'total'        => $total,
            'ok'           => $ok,
            'partial'      => $partial,
            'failed'       => $failed,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #219 — Orphan deliveries (open RQ: dn-no-invoice + invoice-no-dn) */
function kpi_ops_orphan_deliveries(string $label, PDO $pdo): array
{
    $cacheKey = 'ops_orphan_deliveries';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt
           FROM doc_review_queue
          WHERE type IN ('dn-no-invoice','invoice-no-dn')
            AND status IN ('open','in_progress')"
    );
    $stmt->execute();
    $cnt = (int) $stmt->fetchColumn();

    $result = array_merge(kpi_empty_result($label, 'items'), [
        'value' => $cnt,
        'tint'  => $cnt === 0 ? 'green' : 'amber',
        'meta'  => ['period_label' => 'maintenant'],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #223 — Pending deliveries aging (oldest Pending delivery in days) */
function kpi_ops_pending_deliveries_aging(string $label, PDO $pdo): array
{
    $cacheKey = 'ops_pending_delivery_aging';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT COUNT(*) AS cnt,
                DATEDIFF(CURDATE(), MIN(date_received)) AS oldest_days,
                MIN(date_received) AS oldest_date
           FROM inv_deliveries
          WHERE status = 'Pending'"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $cnt  = (int) ($row['cnt']         ?? 0);
    $days = (int) ($row['oldest_days'] ?? 0);

    $result = array_merge(kpi_empty_result($label, 'jours'), [
        'value' => $cnt > 0 ? $days : null,
        'tint'  => $cnt === 0 ? 'green' : ($days > 30 ? 'red' : ($days > 14 ? 'amber' : 'neutral')),
        'meta'  => [
            'pending_count' => $cnt,
            'oldest_date'   => $row['oldest_date'] ?? null,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #225 — Documents processed this month */
function kpi_ops_docs_processed_month(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "ops_docs_processed_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt,
                SUM(pipeline_status = 'processed') AS processed,
                SUM(pipeline_status = 'failed')    AS failed
           FROM doc_uploads
          WHERE DATE(uploaded_at) BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $total     = (int) ($row['cnt']       ?? 0);
    $processed = (int) ($row['processed'] ?? 0);
    $failed    = (int) ($row['failed']    ?? 0);

    $result = array_merge(kpi_empty_result($label, 'docs'), [
        'value' => $processed,
        'tint'  => $failed > 0 ? 'amber' : 'green',
        'meta'  => [
            'period_label' => $p['label'],
            'total'        => $total,
            'processed'    => $processed,
            'failed'       => $failed,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #243 — Active users / logins (users logged in the last 7 days) */
function kpi_ops_active_users_logins(string $label, PDO $pdo): array
{
    $cacheKey = 'ops_active_users';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Count users whose last_login_at is within 7 days (users.last_login_at column)
    $stmt = $pdo->query(
        "SELECT COUNT(*) AS total_users,
                SUM(CASE WHEN last_login_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS active_7d
           FROM users
          WHERE is_active = 1"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $result = array_merge(kpi_empty_result($label, 'utilisateurs'), [
        'value' => (int) ($row['active_7d']   ?? 0),
        'tint'  => 'neutral',
        'meta'  => [
            'total_users'   => (int) ($row['total_users'] ?? 0),
            'active_7d'     => (int) ($row['active_7d']   ?? 0),
            'period_label'  => '7 derniers jours',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}


// ═════════════════════════════════════════════════════════════════════════════
// HANDLER: cogs  (source_domain = 'cogs')
// Reads: the COGS pipeline output (cogs-report-data.json on the local maltytask
//         interfaces/ directory, or cogs_monthly / cop_monthly tables once populated).
//
// CARDINAL RULE: this handler CONSUMES the pipeline output — it NEVER
// re-derives COGS from bd_* + ref_mi prices. That would be a parallel store.
// ═════════════════════════════════════════════════════════════════════════════

function kpi_handler_cogs(
    string $handler,
    array  $params,
    string $label,
    PDO    $pdo
): array {
    return match ($handler) {
        'cogs_per_hl'          => kpi_cogs_per_hl($params, $label, $pdo),
        'cogs_total_month'     => kpi_cogs_total_month($params, $label, $pdo),
        'brewing_cost_chf_hl'  => kpi_cogs_brewing_cost_hl($params, $label, $pdo),
        'cop_total_breakdown'  => kpi_cogs_cop_breakdown($params, $label, $pdo),
        'maintenance_opex'       => kpi_cogs_maintenance_opex($params, $label, $pdo),
        'maintenance_opex_trend' => kpi_cogs_maintenance_opex_trend($label, $pdo),
        default                => kpi_stub_handler('cogs', $handler, $label),
    };
}

/**
 * Load and cache the COGS pipeline JSON. Returns the decoded array or null.
 * Path is the canonical maltytask interfaces directory on the local machine.
 * On the VPS the pipeline output lives in the same path under /var/www/maltytask.
 */
function kpi_load_cogs_json(): ?array
{
    $cacheKey = 'cogs_pipeline_json';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Try VPS path first, then local dev path.
    $candidates = [
        '/var/www/maltytask/interfaces/cogs-report-data.json',
        __DIR__ . '/../../interfaces/cogs-report-data.json',  // local dev
        __DIR__ . '/../../../maltytask/interfaces/cogs-report-data.json',
    ];
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            $json = file_get_contents($path);
            if ($json !== false) {
                $data = json_decode($json, true);
                if ($data !== null) {
                    return kpi_cache_set($cacheKey, $data);
                }
            }
        }
    }
    return kpi_cache_set($cacheKey, null);
}

/**
 * Load and cache the sales-COGS pipeline JSON (sales-cogs-data.json).
 * This file is ~5MB — callers that need only a slice should use
 * kpi_sales_cogs_month_slice() instead to avoid holding the full array.
 * Returns the decoded array (keys: generatedAt, months{}) or null.
 */
function kpi_load_sales_cogs_json(): ?array
{
    $cacheKey = 'sales_cogs_pipeline_json';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $candidates = [
        '/var/www/maltytask/interfaces/sales-cogs-data.json',
        __DIR__ . '/../../interfaces/sales-cogs-data.json',
        __DIR__ . '/../../../maltytask/interfaces/sales-cogs-data.json',
    ];
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            $json = file_get_contents($path);
            if ($json !== false) {
                $data = json_decode($json, true);
                if ($data !== null) {
                    return kpi_cache_set($cacheKey, $data);
                }
            }
        }
    }
    return kpi_cache_set($cacheKey, null);
}

/**
 * Return a single month's slice from the sales-COGS artifact.
 * Only reads the full file once per request (cached).
 * Returns null if the month is not found.
 */
function kpi_sales_cogs_month_slice(string $monthKey): ?array
{
    $data = kpi_load_sales_cogs_json();
    if ($data === null) return null;
    return $data['months'][$monthKey] ?? null;
}

/**
 * Get the most recent month's COP data from the pipeline JSON.
 * Returns an array with keys: monthKey, cop, production, cogsTopDown.
 */
function kpi_cogs_latest_month_data(): ?array
{
    $cacheKey = 'cogs_latest_month';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $data = kpi_load_cogs_json();
    if ($data === null || empty($data['months'])) {
        return kpi_cache_set($cacheKey, null);
    }

    // Return the last entry in the months array (most recent pipeline run).
    $month = end($data['months']);
    return kpi_cache_set($cacheKey, $month ?: null);
}

/**
 * Build a freshness meta block from the COGS pipeline JSON.
 * Called by each of the 4 COGS tile handlers.
 *
 * Returns an array with:
 *   computed_at    — raw ISO-8601 string from generatedAt (or null)
 *   computed_label — DD.MM.YYYY formatted (day-first, fr-CH) or null
 *   data_period    — monthKey formatted as MM/YYYY, or null
 *   is_stale       — true if (now − generatedAt) > 40 days
 *
 * Degrades gracefully: if generatedAt is absent (old JSON), all fields null
 * and is_stale = false so the JS chip simply doesn't render.
 */
function kpi_cogs_freshness_meta(?string $monthKey): array
{
    $data = kpi_load_cogs_json();
    $generatedAt = $data['generatedAt'] ?? null;

    if (!$generatedAt || !is_string($generatedAt)) {
        return [
            'computed_at'    => null,
            'computed_label' => null,
            'data_period'    => null,
            'is_stale'       => false,
        ];
    }

    try {
        $dt = new DateTimeImmutable($generatedAt, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return [
            'computed_at'    => null,
            'computed_label' => null,
            'data_period'    => null,
            'is_stale'       => false,
        ];
    }

    $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $ageDays = (int) $now->diff($dt)->days;
    $isStale = $ageDays > 40;

    // DD.MM.YYYY — day-first system-wide convention
    $computedLabel = $dt->setTimezone(new DateTimeZone('Europe/Zurich'))->format('d.m.Y');

    // MM/YYYY from monthKey (YYYY-MM)
    $dataPeriod = null;
    if ($monthKey && preg_match('/^(\d{4})-(\d{2})$/', $monthKey, $m)) {
        $dataPeriod = $m[2] . '/' . $m[1];
    }

    return [
        'computed_at'    => $generatedAt,
        'computed_label' => $computedLabel,
        'data_period'    => $dataPeriod,
        'is_stale'       => $isStale,
    ];
}

/** #170 (H2) — COGS / HL — reads COP section of pipeline output */
function kpi_cogs_per_hl(array $params, string $label, PDO $pdo): array
{
    $month = kpi_cogs_latest_month_data();

    if ($month === null) {
        return array_merge(kpi_empty_result($label, 'CHF/HL'), [
            'meta' => ['note' => 'Pipeline COGS non disponible'],
        ]);
    }

    $cop   = $month['cop']        ?? [];
    $hl    = (float) ($cop['hlBrewed'] ?? 0);
    $tvars = $cop['totalVariables'] ?? [];
    $perHL = is_array($tvars) ? (float) ($tvars['perHL'] ?? 0) : (float) $tvars;

    // Build a 6-month series from the months array for the sparkline.
    $data   = kpi_load_cogs_json();
    $months = $data['months'] ?? [];
    $series = [];
    foreach (array_slice($months, -6) as $m) {
        $c = $m['cop'] ?? [];
        $tv = $c['totalVariables'] ?? [];
        $v  = is_array($tv) ? (float) ($tv['perHL'] ?? 0) : (float) $tv;
        $series[] = ['period' => $m['monthKey'], 'value' => round($v, 2)];
    }

    // Delta vs prior month
    $delta      = null;
    $deltaLabel = null;
    if (count($series) >= 2) {
        $curr  = $series[count($series) - 1]['value'];
        $prev  = $series[count($series) - 2]['value'];
        $delta = round($curr - $prev, 2);
        $deltaLabel = 'vs mois précédent';
    }

    $freshness = kpi_cogs_freshness_meta($month['monthKey']);
    return array_merge(kpi_empty_result($label, 'CHF/HL'), [
        'value'       => round($perHL, 2),
        'delta'       => $delta,
        'delta_label' => $deltaLabel,
        'tint'        => 'neutral',  // directional: lower is better but not inherently bad
        'series'      => $series,
        'meta'        => array_merge([
            'period_label' => $month['monthKey'],
            'hl_brewed'    => round($hl, 1),
        ], $freshness),
    ]);
}

/** #169 — COGS total this month (total variable cost CHF from COP) */
function kpi_cogs_total_month(array $params, string $label, PDO $pdo): array
{
    $month = kpi_cogs_latest_month_data();

    if ($month === null) {
        return array_merge(kpi_empty_result($label, 'CHF'), [
            'meta' => ['note' => 'Pipeline COGS non disponible'],
        ]);
    }

    $cop   = $month['cop'] ?? [];
    $tvars = $cop['totalVariables'] ?? [];
    $total = is_array($tvars) ? (float) ($tvars['total'] ?? 0) : (float) $tvars;

    $freshness = kpi_cogs_freshness_meta($month['monthKey']);
    return array_merge(kpi_empty_result($label, 'CHF'), [
        'value' => round($total, 2),
        'tint'  => 'neutral',
        'meta'  => array_merge(['period_label' => $month['monthKey']], $freshness),
    ]);
}

/** #172 — Brewing cost CHF/HL (malt + hops + ingredients per HL) */
function kpi_cogs_brewing_cost_hl(array $params, string $label, PDO $pdo): array
{
    $month = kpi_cogs_latest_month_data();

    if ($month === null) {
        return array_merge(kpi_empty_result($label, 'CHF/HL'), [
            'meta' => ['note' => 'Pipeline COGS non disponible'],
        ]);
    }

    $cop  = $month['cop'] ?? [];
    $brew = $cop['brewing'] ?? [];

    // malts.currentYoY.perHL is the "current run rate" for the latest data.
    // Use current period (non-YoY) perHL, falling back to rolling6 if available.
    $maltsPerHL  = (float) (($brew['malts']['current']['perHL']  ?? null)
                        ?? ($brew['malts']['rolling6']['perHL']  ?? 0));
    $hopsPerHL   = (float) (($brew['hops']['current']['perHL']   ?? null)
                        ?? ($brew['hops']['rolling6']['perHL']   ?? 0));
    $ingPerHL    = (float) (($brew['ingredients']['current']['perHL'] ?? null)
                        ?? ($brew['ingredients']['rolling6']['perHL'] ?? 0));

    $total = round($maltsPerHL + $hopsPerHL + $ingPerHL, 2);

    $freshness = kpi_cogs_freshness_meta($month['monthKey']);
    return array_merge(kpi_empty_result($label, 'CHF/HL'), [
        'value'     => $total,
        'tint'      => 'neutral',
        'breakdown' => [
            ['key' => 'malt',        'label' => 'Malt',        'value' => round($maltsPerHL, 2)],
            ['key' => 'hops',        'label' => 'Houblon',     'value' => round($hopsPerHL, 2)],
            ['key' => 'ingredients', 'label' => 'Ingrédients', 'value' => round($ingPerHL, 2)],
        ],
        'meta' => array_merge(['period_label' => $month['monthKey']], $freshness),
    ]);
}

/** #173 — COP total + breakdown (5 sections) */
function kpi_cogs_cop_breakdown(array $params, string $label, PDO $pdo): array
{
    $month = kpi_cogs_latest_month_data();

    if ($month === null) {
        return array_merge(kpi_empty_result($label, 'CHF'), [
            'meta' => ['note' => 'Pipeline COGS non disponible'],
        ]);
    }

    $cop  = $month['cop'] ?? [];
    $tvars = $cop['totalVariables'] ?? [];
    $total = is_array($tvars) ? (float) ($tvars['total'] ?? 0) : (float) $tvars;

    $sections = ['brewing', 'packaging', 'indirect', 'utilities', 'rd'];
    $breakdown = [];
    foreach ($sections as $sec) {
        $s = $cop[$sec] ?? [];
        // Each section may have a nested structure; extract a total field.
        $v = (float) ($s['total'] ?? $s['cost'] ?? 0);
        $breakdown[] = ['key' => $sec, 'label' => ucfirst($sec), 'value' => round($v, 2)];
    }

    $freshness = kpi_cogs_freshness_meta($month['monthKey']);
    return array_merge(kpi_empty_result($label, 'CHF'), [
        'value'     => round($total, 2),
        'breakdown' => $breakdown,
        'tint'      => 'neutral',
        'meta'      => array_merge(['period_label' => $month['monthKey']], $freshness),
    ]);
}

/** #179 / #235 — Maintenance OPEX (reads from inv_charges_bc, GL 6100) */
function kpi_cogs_maintenance_opex(array $params, string $label, PDO $pdo): array
{
    // Reads inv_charges_bc for GL 6100 (Maintenance OPEX).
    // debit_amount = charges; credit_amount = reversals. Net = debit - credit.
    $period = $params['period'] ?? 'latest_closed_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "cogs_maintenance_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT SUM(COALESCE(debit_amount, 0) - COALESCE(credit_amount, 0)) AS net_chf,
                COUNT(*) AS cnt
           FROM inv_charges_bc
          WHERE gl_account_no LIKE '6100%'
            AND posting_date BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $total = $row && $row['net_chf'] !== null ? round((float) $row['net_chf'], 2) : null;

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value' => $total,
        'tint'  => 'neutral',
        'meta'  => ['period_label' => $p['label'], 'line_count' => (int) ($row['cnt'] ?? 0)],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #235 — Maintenance OPEX trend (sparkline: last 12 months, one point per month) */
function kpi_cogs_maintenance_opex_trend(string $label, PDO $pdo): array
{
    $cacheKey = 'cogs_maintenance_trend_12m';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Build a 12-month monthly series from inv_charges_bc GL 6100.
    $stmt = $pdo->query(
        "SELECT DATE_FORMAT(posting_date, '%Y-%m') AS mo,
                SUM(COALESCE(debit_amount, 0) - COALESCE(credit_amount, 0)) AS net_chf
           FROM inv_charges_bc
          WHERE gl_account_no LIKE '6100%'
            AND posting_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
          GROUP BY mo
          ORDER BY mo"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $series = array_map(
        fn(array $r) => ['period' => $r['mo'], 'value' => round((float) $r['net_chf'], 2)],
        $rows
    );

    $latest = !empty($rows) ? round((float) end($rows)['net_chf'], 2) : null;

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value'  => $latest,
        'series' => $series,
        'tint'   => 'neutral',
        'meta'   => ['period_label' => '12 derniers mois'],
    ]);

    return kpi_cache_set($cacheKey, $result);
}


// ═════════════════════════════════════════════════════════════════════════════
// HANDLER: wort  (source_domain = 'wort')
// Reads: bd_brewing_gravity_v2, bd_brewing_brewday_v2, ref_recipes
// Canonical formula: SUM(final_volume) WHERE event_type='Cooling' AND
//   is_tombstoned=0 JOIN ref_recipes ON recipe_id_fk.
// ═════════════════════════════════════════════════════════════════════════════

function kpi_handler_wort(
    string $handler,
    array  $params,
    string $label,
    PDO    $pdo
): array {
    return match ($handler) {
        'hl_brewed_period'          => kpi_wort_hl_brewed($params, $label, $pdo),
        'hl_brewed_ytd'             => kpi_wort_hl_brewed_ytd($label, $pdo),
        'brew_count_period'         => kpi_wort_brew_count($params, $label, $pdo),
        'avg_hl_per_brew'           => kpi_wort_avg_hl_per_brew($params, $label, $pdo),
        'production_by_beer_yoy'    => kpi_wort_production_by_beer_yoy($label, $pdo),
        // Phase 2b Batch 4 — compute handlers (mig-308):
        'brewhouse_yield'           => kpi_wort_brewhouse_yield($params, $label, $pdo),
        'days_since_last_brew'      => kpi_wort_days_since_last_brew($label, $pdo),
        'avg_brew_duration'         => kpi_wort_avg_brew_duration($params, $label, $pdo),
        'ingredient_consumption_period' => kpi_wort_ingredient_consumption($params, $label, $pdo),
        'beer_loss_cascade'         => kpi_wort_beer_loss_cascade($params, $label, $pdo),
        'daily_recap'               => kpi_wort_daily_recap($label, $pdo),
        // Confirmed stubs — source columns absent:
        'og_attainment'             => kpi_stub_handler('wort', $handler, $label),   // no target_og in ref_recipes
        'brewing_deviations'        => kpi_stub_handler('wort', $handler, $label),   // no deviations table/schema
        'extract_efficiency_lab'    => kpi_stub_handler('wort', $handler, $label),   // gap: no lab extract data
        'dryhop_absorption_loss'    => kpi_stub_handler('wort', $handler, $label),   // gap: no dryhop volume tracking
        'fv_trub_loss'              => kpi_stub_handler('wort', $handler, $label),   // gap: no knock-out→FV delta
        default                     => kpi_stub_handler('wort', $handler, $label),
    };
}

/**
 * Canonical wort base query fragment (see wort.php:187 for the authoritative version).
 * Joins bd_brewing_gravity_v2 → ref_recipes directly via recipe_id_fk
 * (NOT via bd_brewing_brewday_v2 date match — drops ~15% of HL).
 */
function kpi_wort_base_sql(): string
{
    return "
        FROM bd_brewing_gravity_v2 cl
        JOIN ref_recipes rr ON rr.id = cl.recipe_id_fk
       WHERE cl.event_type = 'Cooling'
         AND cl.is_tombstoned = 0
    ";
}

/** #1 / #9 — HL brewed for a period, with classification breakdown */
function kpi_wort_hl_brewed(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "wort_hl_brewed_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $base = kpi_wort_base_sql();
    $stmt = $pdo->prepare(
        "SELECT ROUND(SUM(cl.final_volume), 1) AS total_hl,
                SUM(CASE WHEN rr.classification='Contract' THEN cl.final_volume ELSE 0 END) AS contract_hl,
                SUM(CASE WHEN rr.classification='Neb'      THEN cl.final_volume ELSE 0 END) AS neb_hl,
                COUNT(*) AS brew_count
         {$base}
           AND DATE(cl.submitted_at) BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $total = (float) ($row['total_hl'] ?? 0);

    $result = array_merge(kpi_empty_result($label, 'HL'), [
        'value'     => $total,
        'tint'      => 'neutral',
        'breakdown' => [
            ['key' => 'neb',      'label' => 'Nébuleuse', 'value' => round((float)($row['neb_hl'] ?? 0), 1)],
            ['key' => 'contract', 'label' => 'Contract',  'value' => round((float)($row['contract_hl'] ?? 0), 1)],
        ],
        'meta' => [
            'period_label' => $p['label'],
            'brew_count'   => (int) ($row['brew_count'] ?? 0),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #2 — HL brewed YTD vs prior year YTD (sparkline) */
function kpi_wort_hl_brewed_ytd(string $label, PDO $pdo): array
{
    $cacheKey = 'wort_hl_ytd';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $year = (int) $now->format('Y');

    $base = kpi_wort_base_sql();
    $stmt = $pdo->prepare(
        "SELECT YEAR(DATE(cl.submitted_at)) AS yr,
                ROUND(SUM(cl.final_volume), 1) AS total_hl
         {$base}
           AND YEAR(DATE(cl.submitted_at)) IN (?, ?)
           AND DATE_FORMAT(DATE(cl.submitted_at), '%m-%d') <= DATE_FORMAT(CURDATE(), '%m-%d')
         GROUP BY yr
         ORDER BY yr"
    );
    $stmt->execute([$year - 1, $year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byYear = [];
    foreach ($rows as $r) {
        $byYear[(int)$r['yr']] = (float)$r['total_hl'];
    }

    $currYtd = $byYear[$year]       ?? null;
    $prevYtd = $byYear[$year - 1]   ?? null;
    $delta   = ($currYtd !== null && $prevYtd !== null && $prevYtd > 0)
               ? round((($currYtd - $prevYtd) / $prevYtd) * 100, 1)
               : null;

    $series = [];
    foreach ($byYear as $yr => $hl) {
        $series[] = ['period' => (string)$yr, 'value' => $hl];
    }

    $result = array_merge(kpi_empty_result($label, 'HL'), [
        'value'       => $currYtd,
        'delta'       => $delta,
        'delta_label' => 'vs N-1 YTD',
        'tint'        => $delta === null ? 'neutral' : ($delta >= 0 ? 'green' : 'amber'),
        'series'      => $series,
        'meta'        => ['period_label' => 'YTD ' . $year],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #3 / #9 — Brew count for a period */
function kpi_wort_brew_count(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "wort_brew_count_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $base = kpi_wort_base_sql();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS brew_count {$base} AND DATE(cl.submitted_at) BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $cnt = (int) $stmt->fetchColumn();

    $result = array_merge(kpi_empty_result($label, 'brassins'), [
        'value' => $cnt,
        'tint'  => 'neutral',
        'meta'  => ['period_label' => $p['label']],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #4 — Average HL per brew (rolling period) */
function kpi_wort_avg_hl_per_brew(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_3m';
    $p = kpi_resolve_period($period);

    $cacheKey = "wort_avg_hl_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $base = kpi_wort_base_sql();
    $stmt = $pdo->prepare(
        "SELECT ROUND(AVG(cl.final_volume), 2) AS avg_hl,
                COUNT(*) AS brew_count
         {$base}
           AND DATE(cl.submitted_at) BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $result = array_merge(kpi_empty_result($label, 'HL/brassin'), [
        'value' => $row ? (float) $row['avg_hl'] : null,
        'tint'  => 'neutral',
        'meta'  => [
            'period_label' => $p['label'],
            'brew_count'   => (int) ($row['brew_count'] ?? 0),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #8 — Days since last brew */
function kpi_wort_days_since_last_brew(string $label, PDO $pdo): array
{
    $cacheKey = 'wort_days_since_last';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $base = kpi_wort_base_sql();
    $stmt = $pdo->query(
        "SELECT DATEDIFF(CURDATE(), MAX(DATE(cl.submitted_at))) AS days_idle {$base}"
    );
    $days = $stmt->fetchColumn();

    $result = array_merge(kpi_empty_result($label, 'jours'), [
        'value' => $days !== false ? (int) $days : null,
        'tint'  => match(true) {
            $days === false || $days === null => 'neutral',
            (int)$days <= 3  => 'green',
            (int)$days <= 7  => 'neutral',
            (int)$days <= 14 => 'amber',
            default          => 'red',
        },
        'meta' => ['period_label' => 'maintenant'],
    ]);

    return kpi_cache_set($cacheKey, $result);
}


/** #5 — Production par bière, sparklines YoY (HL par bière, YTD N vs N-1) */
function kpi_wort_production_by_beer_yoy(string $label, PDO $pdo): array
{
    $cacheKey = 'wort_production_yoy';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $year = (int) $now->format('Y');

    // Per-recipe YTD HL for current year and prior year (same day of year cutoff).
    $stmt = $pdo->prepare(
        "SELECT rr.name AS beer_name,
                rr.classification,
                YEAR(DATE(cl.submitted_at)) AS yr,
                ROUND(SUM(cl.final_volume), 1) AS hl,
                COUNT(*) AS brew_count
           FROM bd_brewing_gravity_v2 cl
           JOIN ref_recipes rr ON rr.id = cl.recipe_id_fk
          WHERE cl.event_type = 'Cooling'
            AND cl.is_tombstoned = 0
            AND YEAR(DATE(cl.submitted_at)) IN (?, ?)
            AND DATE_FORMAT(DATE(cl.submitted_at), '%m-%d')
                <= DATE_FORMAT(CURDATE(), '%m-%d')
          GROUP BY rr.name, rr.classification, yr
          ORDER BY rr.name, yr"
    );
    $stmt->execute([$year - 1, $year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build per-beer map {name => {N-1 => hl, N => hl}}.
    $beers = [];
    foreach ($rows as $r) {
        $name = $r['beer_name'];
        if (!isset($beers[$name])) {
            $beers[$name] = ['classification' => $r['classification'], 'years' => []];
        }
        $beers[$name]['years'][(int) $r['yr']] = (float) $r['hl'];
    }

    // Total YTD this year.
    $totalCurr = 0.0;
    $totalPrev = 0.0;
    $breakdown = [];
    foreach ($beers as $name => $info) {
        $curr = $info['years'][$year]       ?? 0.0;
        $prev = $info['years'][$year - 1]   ?? 0.0;
        $totalCurr += $curr;
        $totalPrev += $prev;
        $breakdown[] = [
            'key'   => $name,
            'label' => $name,
            'value' => $curr,
            'meta'  => ['prior_year' => $prev, 'classification' => $info['classification']],
        ];
    }
    usort($breakdown, fn($a, $b) => $b['value'] <=> $a['value']);

    $delta = ($totalPrev > 0)
             ? round((($totalCurr - $totalPrev) / $totalPrev) * 100, 1)
             : null;

    $series = [
        ['period' => (string)($year - 1), 'value' => round($totalPrev, 1)],
        ['period' => (string)$year,        'value' => round($totalCurr, 1)],
    ];

    $result = array_merge(kpi_empty_result($label, 'HL'), [
        'value'       => round($totalCurr, 1),
        'delta'       => $delta,
        'delta_label' => 'vs N-1 YTD',
        'tint'        => $delta === null ? 'neutral' : ($delta >= 0 ? 'green' : 'amber'),
        'series'      => $series,
        'breakdown'   => $breakdown,
        'meta'        => ['period_label' => "YTD {$year}", 'beer_count' => count($beers)],
    ]);

    return kpi_cache_set($cacheKey, $result);
}


// ─── Phase 2b Batch 4 — wort compute handlers ────────────────────────────────

/**
 * #6 — Brewhouse yield %: actual cooling HL vs brewhouse nominal size (ref_brewhouse_size).
 * Denominator = the current nominal size in ref_brewhouse_size (most recent effective row).
 * Numerator  = average cooling final_volume per brew row.
 * Period is the submitted_at date window.
 * Multi-brew batches each contribute one Cooling row → each row counted independently.
 */
function kpi_wort_brewhouse_yield(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "wort_bhouse_yield_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Brewhouse nominal HL (most recent effective row, no future effective_from).
    $nomStmt = $pdo->query(
        "SELECT size_hl FROM ref_brewhouse_size
          WHERE effective_from <= CURDATE()
            AND (effective_until IS NULL OR effective_until >= CURDATE())
          ORDER BY effective_from DESC
          LIMIT 1"
    );
    $nomRow  = $nomStmt->fetch(PDO::FETCH_ASSOC);
    $nominalHl = $nomRow ? (float) $nomRow['size_hl'] : null;

    if ($nominalHl === null || $nominalHl <= 0) {
        $result = array_merge(kpi_empty_result($label, '%'), [
            'tint' => 'neutral',
            'meta' => ['note' => 'Capacité nominale brasserie non configurée'],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    $base = kpi_wort_base_sql();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS brew_rows,
                ROUND(AVG(cl.final_volume), 2) AS avg_hl,
                ROUND(SUM(cl.final_volume), 2) AS total_hl
         {$base}
           AND DATE(cl.submitted_at) BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $brewRows = (int) ($row['brew_rows'] ?? 0);
    $avgHl    = $row ? (float) $row['avg_hl'] : null;
    $yieldPct = ($avgHl !== null && $nominalHl > 0)
                ? round(($avgHl / $nominalHl) * 100, 1)
                : null;

    $tint = match (true) {
        $yieldPct === null  => 'neutral',
        $yieldPct >= 95.0   => 'green',
        $yieldPct >= 85.0   => 'amber',
        default             => 'red',
    };

    $result = array_merge(kpi_empty_result($label, '%'), [
        'value' => $yieldPct,
        'tint'  => $tint,
        'meta'  => [
            'period_label'  => $p['label'],
            'brew_rows'     => $brewRows,
            'avg_hl'        => $avgHl,
            'nominal_hl'    => $nominalHl,
            'note'          => "Rendement moyen = HL moyen refroidissement / {$nominalHl} HL nominal",
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #10 — Average brew duration in hours (rolling period).
 * Source: bd_brewing_timings_v2 brew_start / brew_end.
 * Multi-brew batches have one row per brew; each row contributes its own duration.
 * Filter: brew_end > brew_start, duration 1–24 h (excludes data-entry errors).
 */
function kpi_wort_avg_brew_duration(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_3m';
    $p = kpi_resolve_period($period);

    $cacheKey = "wort_brew_dur_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS brew_rows,
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, brew_start, brew_end) / 60.0), 2) AS avg_hours,
                ROUND(MIN(TIMESTAMPDIFF(MINUTE, brew_start, brew_end) / 60.0), 2) AS min_hours,
                ROUND(MAX(TIMESTAMPDIFF(MINUTE, brew_start, brew_end) / 60.0), 2) AS max_hours
           FROM bd_brewing_timings_v2
          WHERE is_tombstoned = 0
            AND brew_start IS NOT NULL
            AND brew_end   IS NOT NULL
            AND brew_end > brew_start
            AND TIMESTAMPDIFF(MINUTE, brew_start, brew_end) BETWEEN 60 AND 1440
            AND event_date BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $avgH   = $row && (int)$row['brew_rows'] > 0 ? (float) $row['avg_hours'] : null;

    $result = array_merge(kpi_empty_result($label, 'heures'), [
        'value' => $avgH,
        'tint'  => 'neutral',
        'meta'  => [
            'period_label' => $p['label'],
            'brew_rows'    => $row ? (int)   $row['brew_rows'] : 0,
            'min_hours'    => $row ? (float) $row['min_hours'] : null,
            'max_hours'    => $row ? (float) $row['max_hours'] : null,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #11 — Ingredient consumption by MI category for a period.
 * Source: inv_consumption (canonical) joined to ref_mi → ref_mi_categories.
 * Groups by category name; CHF conversion not applied (qty in mixed units).
 * Returns breakdown by category sorted by count descending.
 */
function kpi_wort_ingredient_consumption(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "wort_ingr_conso_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT rmc.name AS category,
                COUNT(*) AS line_count,
                ROUND(SUM(ic.qty), 2) AS total_qty,
                ic.unit
           FROM inv_consumption ic
           JOIN ref_mi rm ON rm.id = ic.mi_id_fk
           JOIN ref_mi_categories rmc ON rmc.id = rm.category_id
          WHERE ic.consumed_at BETWEEN ? AND ?
            AND ic.source_event IN ('brewing', 'fermenting')
          GROUP BY rmc.name, ic.unit
          ORDER BY line_count DESC"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aggregate by category (multiple units possible per category).
    $byCat = [];
    foreach ($rows as $r) {
        $cat = $r['category'];
        if (!isset($byCat[$cat])) {
            $byCat[$cat] = ['line_count' => 0, 'note' => []];
        }
        $byCat[$cat]['line_count'] += (int) $r['line_count'];
        $byCat[$cat]['note'][]      = round((float)$r['total_qty'], 1) . ' ' . ($r['unit'] ?? '?');
    }
    arsort($byCat);

    $totalLines = array_sum(array_column($byCat, 'line_count'));
    $breakdown  = [];
    foreach ($byCat as $cat => $data) {
        $breakdown[] = [
            'key'   => $cat,
            'label' => $cat,
            'value' => $data['line_count'],
            'meta'  => ['qty_note' => implode(', ', $data['note'])],
        ];
    }

    $result = array_merge(kpi_empty_result($label, 'lignes'), [
        'value'     => $totalLines,
        'tint'      => 'neutral',
        'breakdown' => $breakdown,
        'meta'      => [
            'period_label' => $p['label'],
            'category_count' => count($byCat),
            'note'         => 'Consommations brewing+fermenting issues de inv_consumption',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #252 — Beer loss cascade waterfall: brassé → transféré → packagé HL.
 * Chains bd_brewing_gravity_v2 (Cooling) → bd_racking_v2 → bd_packaging_v2
 * keyed on (recipe_id_fk, batch). Period filter on racking event_date.
 *
 * Waterfall shape consumed by renderKpiWaterfall (reads result.breakdown):
 *   [{key:'brewed',  label:'Brassé',      value: +total_brewed_hl},
 *    {key:'rack_loss',label:'→ Transféré',value: -rack_loss_hl},
 *    {key:'racked',  label:'Transféré',   value: +total_racked_hl},    // running step
 *    {key:'pkg_loss',label:'→ Packagé',   value: -pkg_loss_hl},
 *    {key:'packaged',label:'Packagé',     value: +total_packaged_hl}]
 *
 * Only matched pairs (brew+rack found) are included; unmatched orphans excluded.
 * Filter: loss fractions 0–40% (excludes impossible data-entry values).
 */
function kpi_wort_beer_loss_cascade(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_3m';
    $p = kpi_resolve_period($period);

    $cacheKey = "wort_loss_cascade_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Join: racking ↔ brewing (required), packaging (optional left join).
    // Key: (recipe_id_fk, batch) — brewing uses recipe_id_fk+batch,
    //      racking uses COALESCE(neb_recipe_id_fk,contract_recipe_id_fk) + COALESCE(neb_batch,contract_batch),
    //      packaging uses recipe_id_fk + COALESCE(neb_batch,contract_batch).
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS pairs,
                ROUND(SUM(bg.wort_hl), 2)  AS total_brewed_hl,
                ROUND(SUM(r.racked_vol_hl), 2) AS total_racked_hl,
                ROUND(SUM(COALESCE(pk.pkg_hl, 0)), 2) AS total_packaged_hl
           FROM bd_racking_v2 r
           JOIN (
               SELECT recipe_id_fk, batch, SUM(final_volume) AS wort_hl
                 FROM bd_brewing_gravity_v2
                WHERE event_type = 'Cooling' AND is_tombstoned = 0
                GROUP BY recipe_id_fk, batch
           ) bg ON bg.recipe_id_fk = COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)
               AND bg.batch = COALESCE(NULLIF(r.neb_batch, ''), r.contract_batch)
           LEFT JOIN (
               SELECT recipe_id_fk,
                      COALESCE(neb_batch, contract_batch) AS batch,
                      SUM(vendable_hl) AS pkg_hl
                 FROM bd_packaging_v2
                WHERE is_tombstoned = 0
                GROUP BY recipe_id_fk, batch
           ) pk ON pk.recipe_id_fk = COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)
               AND pk.batch = COALESCE(NULLIF(r.neb_batch, ''), r.contract_batch)
          WHERE r.is_tombstoned = 0
            AND r.racked_vol_hl > 0
            AND bg.wort_hl > 0
            AND r.event_date BETWEEN ? AND ?
            AND (bg.wort_hl - r.racked_vol_hl) / NULLIF(bg.wort_hl, 0) BETWEEN 0 AND 0.40"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $pairs    = (int)   ($row['pairs']             ?? 0);
    $brewed   = (float) ($row['total_brewed_hl']   ?? 0.0);
    $racked   = (float) ($row['total_racked_hl']   ?? 0.0);
    $packaged = (float) ($row['total_packaged_hl'] ?? 0.0);

    if ($pairs === 0 || $brewed <= 0) {
        $result = array_merge(kpi_empty_result($label, '%'), [
            'tint' => 'neutral',
            'meta' => ['period_label' => $p['label'], 'note' => 'Aucune paire brassin+transfert trouvée'],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    $rackLoss = round($brewed - $racked, 2);
    $pkgLoss  = $packaged > 0 ? round($racked - $packaged, 2) : null;

    // Overall loss % from brewed to final packaged (or to racked if no packaging matched).
    $finalOutput = $packaged > 0 ? $packaged : $racked;
    $totalLossPct = round((($brewed - $finalOutput) / $brewed) * 100, 1);

    // Waterfall breakdown: positive bars = volumes, negative bars = losses.
    $breakdown = [
        ['key' => 'brewed',    'label' => 'Brassé',      'value' => round($brewed, 2)],
        ['key' => 'rack_loss', 'label' => '- Pertes CCT', 'value' => -round($rackLoss, 2)],
        ['key' => 'racked',    'label' => 'Transféré',   'value' => round($racked, 2)],
    ];
    if ($pkgLoss !== null) {
        $breakdown[] = ['key' => 'pkg_loss',  'label' => '- Pertes BBT', 'value' => -$pkgLoss];
        $breakdown[] = ['key' => 'packaged',  'label' => 'Packagé',      'value' => round($packaged, 2)];
    }

    $tint = match (true) {
        $totalLossPct <= 8.0   => 'green',
        $totalLossPct <= 15.0  => 'amber',
        default                => 'red',
    };

    $result = array_merge(kpi_empty_result($label, '%'), [
        'value'     => $totalLossPct,
        'tint'      => $tint,
        'breakdown' => $breakdown,
        'meta'      => [
            'period_label'        => $p['label'],
            'pairs'               => $pairs,
            'total_brewed_hl'     => $brewed,
            'total_racked_hl'     => $racked,
            'total_packaged_hl'   => $packaged,
            'rack_loss_hl'        => $rackLoss,
            'pkg_loss_hl'         => $pkgLoss,
            'total_loss_pct'      => $totalLossPct,
            'note'                => 'Pertes CCT+BBT chaînées par (recipe_id_fk, batch)',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}


/** #270 — Brassage résumé du jour (recap)
 *
 * Combines three today-anchored sub-queries into one recap result:
 *   1. HL brassés + brew count: bd_brewing_gravity_v2 Cooling rows submitted today,
 *      split Neb/Contract via rr.classification. Keyed on (recipe_id_fk, batch).
 *   2. HL transférés aujourd'hui: bd_racking_v2 event_date=CURDATE(), is_tombstoned=0.
 *      Keyed on (neb_recipe_id_fk|contract_recipe_id_fk, batch).
 *   3. Dry-hop events aujourd'hui: bd_fermenting_v2 event_type='DryHop',
 *      event_date=CURDATE(), is_tombstoned=0. COUNT only.
 *   4. Cold-crash events aujourd'hui: same table, event_type='ColdCrash'.
 *
 * Returns recap shape:
 *   value     = HL brassés today (primary headline)
 *   meta.sections = [{label,value,unit,tint}] — headline metrics strip
 *   breakdown = [{key,label,value,unit,meta}] — per-recipe/run-type rows
 *
 * Anchor note: brewed HL uses submitted_at (form submission time) as the date
 * anchor, consistent with all other wort handlers (kpi_wort_base_sql).
 * Transferred HL uses event_date (the physical racking date).
 * The subtitle "(saisi aujourd'hui)" is rendered by renderKpiRecap() in the JS.
 */
function kpi_wort_daily_recap(string $label, PDO $pdo): array
{
    $today    = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    $cacheKey = "wort_daily_recap_{$today}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // 1. HL brassés today (Cooling rows, submitted_at anchor — matches kpi_wort_base_sql)
    $base = kpi_wort_base_sql();
    $stmtBrew = $pdo->prepare(
        "SELECT ROUND(SUM(cl.final_volume), 1)                                         AS total_hl,
                SUM(CASE WHEN rr.classification = 'Neb'      THEN cl.final_volume ELSE 0 END) AS neb_hl,
                SUM(CASE WHEN rr.classification = 'Contract' THEN cl.final_volume ELSE 0 END) AS contract_hl,
                COUNT(*)                                                                AS brew_count,
                COUNT(DISTINCT cl.recipe_id_fk, cl.batch)                              AS brassin_count
         {$base}
           AND DATE(cl.submitted_at) = ?"
    );
    $stmtBrew->execute([$today]);
    $brewRow = $stmtBrew->fetch(PDO::FETCH_ASSOC);

    $hlBrewed    = (float) ($brewRow['total_hl']      ?? 0.0);
    $hlNeb       = round((float) ($brewRow['neb_hl']      ?? 0.0), 1);
    $hlContract  = round((float) ($brewRow['contract_hl'] ?? 0.0), 1);
    $brewCount   = (int)   ($brewRow['brew_count']   ?? 0);
    $brassinCount = (int)  ($brewRow['brassin_count'] ?? 0);

    // 2. HL transférés today (bd_racking_v2 event_date)
    $stmtRack = $pdo->prepare(
        "SELECT ROUND(SUM(r.racked_vol_hl), 1)                                       AS total_hl,
                COUNT(*)                                                               AS rack_count,
                SUM(CASE WHEN rr1.classification = 'Neb'      THEN r.racked_vol_hl ELSE
                    CASE WHEN rr2.classification = 'Neb'      THEN r.racked_vol_hl ELSE 0 END
                    END)                                                               AS neb_hl,
                SUM(CASE WHEN rr1.classification = 'Contract' THEN r.racked_vol_hl ELSE
                    CASE WHEN rr2.classification = 'Contract' THEN r.racked_vol_hl ELSE 0 END
                    END)                                                               AS contract_hl
           FROM bd_racking_v2 r
           LEFT JOIN ref_recipes rr1 ON rr1.id = r.neb_recipe_id_fk
           LEFT JOIN ref_recipes rr2 ON rr2.id = r.contract_recipe_id_fk
          WHERE r.is_tombstoned = 0
            AND r.event_date = ?"
    );
    $stmtRack->execute([$today]);
    $rackRow = $stmtRack->fetch(PDO::FETCH_ASSOC);

    $hlRacked    = (float) ($rackRow['total_hl']   ?? 0.0);
    $rackCount   = (int)   ($rackRow['rack_count'] ?? 0);

    // 3. Dry-hop events today (bd_fermenting_v2)
    $stmtDh = $pdo->prepare(
        "SELECT COUNT(DISTINCT recipe_id_fk, batch) AS batch_count,
                COUNT(*)                             AS event_count
           FROM bd_fermenting_v2
          WHERE is_tombstoned = 0
            AND event_type    = 'DryHop'
            AND event_date    = ?"
    );
    $stmtDh->execute([$today]);
    $dhRow       = $stmtDh->fetch(PDO::FETCH_ASSOC);
    $dhBatches   = (int) ($dhRow['batch_count'] ?? 0);
    $dhEvents    = (int) ($dhRow['event_count'] ?? 0);

    // 4. Cold-crash events today
    $stmtCc = $pdo->prepare(
        "SELECT COUNT(DISTINCT recipe_id_fk, batch) AS batch_count
           FROM bd_fermenting_v2
          WHERE is_tombstoned = 0
            AND event_type    = 'ColdCrash'
            AND event_date    = ?"
    );
    $stmtCc->execute([$today]);
    $ccBatches = (int) ($stmtCc->fetchColumn() ?? 0);

    // Sections (headline metrics strip)
    $sections = [
        ['label' => 'HL brassés (saisi aujourd\'hui)', 'value' => $hlBrewed,    'unit' => 'HL',      'tint' => 'neutral'],
        ['label' => 'Brassins',                         'value' => $brassinCount, 'unit' => 'brassins','tint' => 'neutral'],
        ['label' => 'HL transférés',                    'value' => $hlRacked,    'unit' => 'HL',      'tint' => 'neutral'],
        ['label' => 'Transferts',                       'value' => $rackCount,   'unit' => 'mises',   'tint' => 'neutral'],
    ];
    if ($dhBatches > 0) {
        $sections[] = ['label' => 'Brassins dry-hoppés', 'value' => $dhBatches, 'unit' => 'lots', 'tint' => 'neutral'];
    }
    if ($ccBatches > 0) {
        $sections[] = ['label' => 'Cold crash démarrés', 'value' => $ccBatches, 'unit' => 'lots', 'tint' => 'neutral'];
    }

    // Breakdown (classification split for brewed + racked)
    $breakdown = [];
    if ($hlNeb > 0 || $hlContract > 0) {
        if ($hlNeb > 0) {
            $breakdown[] = ['key' => 'brew_neb',      'label' => 'Brassin Nébuleuse', 'value' => $hlNeb,     'unit' => 'HL'];
        }
        if ($hlContract > 0) {
            $breakdown[] = ['key' => 'brew_contract',  'label' => 'Brassin Contract',  'value' => $hlContract, 'unit' => 'HL'];
        }
    }
    if ($rackCount > 0) {
        $breakdown[] = ['key' => 'rack_total', 'label' => 'Transfert BBT', 'value' => round($hlRacked, 1), 'unit' => 'HL'];
    }
    if ($dhEvents > 0) {
        $breakdown[] = ['key' => 'dryhop', 'label' => 'Dry-hop (lots)', 'value' => $dhBatches, 'unit' => 'lots'];
    }

    $result = array_merge(kpi_empty_result($label, 'HL'), [
        'value'     => $hlBrewed > 0 ? $hlBrewed : ($hlRacked > 0 ? $hlRacked : null),
        'tint'      => 'neutral',
        'breakdown' => $breakdown ?: null,
        'meta'      => [
            'sections'      => $sections,
            'period_label'  => $today,
            'brew_count'    => $brewCount,
            'brassin_count' => $brassinCount,
            'rack_count'    => $rackCount,
            'dryhop_batches'=> $dhBatches,
            'coldcrash_batches' => $ccBatches,
            'today'         => $today,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}


// ═════════════════════════════════════════════════════════════════════════════
// HANDLER: rm_procurement  (source_domain = 'rm_procurement')
// Reads: inv_deliveries, doc_review_queue, v_rm_stock_dynamic
// ═════════════════════════════════════════════════════════════════════════════

function kpi_handler_rm_procurement(
    string $handler,
    array  $params,
    string $label,
    PDO    $pdo
): array {
    return match ($handler) {
        'deliveries_period'          => kpi_rm_deliveries_period($params, $label, $pdo),
        'pending_deliveries'         => kpi_rm_pending_deliveries($label, $pdo),
        'spend_by_gl_period'         => kpi_rm_spend_by_gl($params, $label, $pdo),
        'rm_negative_stock_alerts'   => kpi_rm_negative_stock_alerts($label, $pdo),
        'rm_stale_items'             => kpi_rm_stale_items($label, $pdo),
        'rm_drift_alert'             => kpi_rm_drift_alert($label, $pdo),
        'inventory_days_of_supply'   => kpi_rm_days_of_supply($params, $label, $pdo),
        // Phase 2b Batch 2 handlers (mig-306):
        'rm_stock_value'             => kpi_rm_stock_value($label, $pdo),
        'rm_stock_by_category'       => kpi_rm_stock_by_category($label, $pdo),
        'rm_days_cover'              => kpi_rm_days_cover($label, $pdo),
        'top_suppliers_spend'        => kpi_rm_top_suppliers_spend($params, $label, $pdo),
        'wac_trend_per_mi'           => kpi_rm_wac_trend_per_mi($label, $pdo),
        'price_anomalies'            => kpi_rm_price_anomalies($label, $pdo),
        'overpriced_purchase_flag'   => kpi_rm_price_anomalies($label, $pdo),   // alias
        'consumption_per_mi_period'  => kpi_rm_consumption_per_mi_period($params, $label, $pdo),
        'caution_deposit_balance'    => kpi_rm_caution_deposit_balance($label, $pdo),
        'import_vat_freight_trend'   => kpi_rm_import_vat_freight_trend($label, $pdo),
        'ingredient_cost_pct_cogs'   => kpi_rm_ingredient_cost_pct_cogs($label, $pdo),
        'supplier_lead_time'         => kpi_rm_supplier_lead_time($label, $pdo),
        'on_time_delivery_rate'      => kpi_rm_on_time_delivery_rate($label, $pdo),
        'single_source_risk'         => kpi_rm_single_source_risk($label, $pdo),
        'spend_yoy'                  => kpi_rm_spend_yoy($label, $pdo),
        'malt_hops_cost_split'       => kpi_rm_malt_hops_cost_split($label, $pdo),
        'fx_eur_chf_exposure'        => kpi_rm_fx_eur_chf_exposure($label, $pdo),
        default                      => kpi_stub_handler('rm_procurement', $handler, $label),
    };
}

/** #112 / #132 — Deliveries received in a period (count + CHF) */
function kpi_rm_deliveries_period(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $metric = $params['metric'] ?? 'total_chf';
    $p = kpi_resolve_period($period);

    $cacheKey = "rm_deliveries_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        // Re-use cached delivery data, just select the right metric.
        $d = $cached;
    } else {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt,
                    SUM(CASE WHEN status != 'Pending' THEN total_chf ELSE 0 END) AS total_chf
               FROM inv_deliveries
              WHERE date_received BETWEEN ? AND ?"
        );
        $stmt->execute([$p['start'], $p['end']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $d = [
            'cnt'       => (int)   ($row['cnt']        ?? 0),
            'total_chf' => round((float) ($row['total_chf'] ?? 0), 2),
        ];
        kpi_cache_set($cacheKey, $d);
    }

    $isCount = ($metric === 'inbound_count');
    $result = array_merge(kpi_empty_result($label, $isCount ? 'livraisons' : 'CHF'), [
        'value' => $isCount ? $d['cnt'] : $d['total_chf'],
        'tint'  => 'neutral',
        'meta'  => [
            'period_label' => $p['label'],
            'count'        => $d['cnt'],
            'total_chf'    => $d['total_chf'],
        ],
    ]);

    return $result;
}

/** #113 — Pending deliveries (truck arrived, invoice not yet) */
function kpi_rm_pending_deliveries(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_pending_deliveries';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT COUNT(*) AS cnt, MIN(date_received) AS oldest_date
           FROM inv_deliveries
          WHERE status = 'Pending'"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cnt = (int) ($row['cnt'] ?? 0);

    $result = array_merge(kpi_empty_result($label, 'livraisons'), [
        'value' => $cnt,
        'tint'  => $cnt === 0 ? 'neutral' : ($cnt > 10 ? 'amber' : 'neutral'),
        'meta'  => ['oldest_date' => $row['oldest_date'] ?? null],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #114 — Spend by GL / category this period */
function kpi_rm_spend_by_gl(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "rm_spend_gl_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Join inv_deliveries → ref_mi via mi_id_raw (VARCHAR → VARCHAR).
    // category_raw is NULL in canonical rows; resolve via ref_mi.gl_account.
    $stmt = $pdo->prepare(
        "SELECT rm.gl_account,
                ANY_VALUE(rmc.name) AS category_label,
                ROUND(SUM(d.total_chf), 2) AS total_chf
           FROM inv_deliveries d
           JOIN ref_mi rm ON rm.mi_id = d.mi_id_raw
           LEFT JOIN ref_mi_categories rmc ON rmc.id = rm.category_id
          WHERE d.date_received BETWEEN ? AND ?
            AND d.status != 'Pending'
          GROUP BY rm.gl_account
          ORDER BY total_chf DESC"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = array_sum(array_column($rows, 'total_chf'));
    $breakdown = array_map(fn($r) => [
        'key'   => $r['gl_account'],
        'label' => $r['category_label'] ?? $r['gl_account'],
        'value' => (float) $r['total_chf'],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value'     => round($total, 2),
        'breakdown' => $breakdown,
        'tint'      => 'neutral',
        'meta'      => ['period_label' => $p['label']],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #108 — RM negative stock alerts (reads open RQ of type rm-negative) */
function kpi_rm_negative_stock_alerts(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_negative_alerts';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt
           FROM doc_review_queue
          WHERE type = 'rm-negative' AND status IN ('open','in_progress')"
    );
    $stmt->execute();
    $cnt = (int) $stmt->fetchColumn();

    $result = array_merge(kpi_empty_result($label, 'alertes'), [
        'value' => $cnt,
        'tint'  => $cnt === 0 ? 'green' : 'red',
        'meta'  => ['period_label' => 'maintenant'],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #109 — RM stale items >180d (reads open RQ of type rm-stale) */
function kpi_rm_stale_items(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_stale_items';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt
           FROM doc_review_queue
          WHERE type = 'rm-stale' AND status IN ('open','in_progress')"
    );
    $stmt->execute();
    $cnt = (int) $stmt->fetchColumn();

    $result = array_merge(kpi_empty_result($label, 'articles'), [
        'value' => $cnt,
        'tint'  => $cnt === 0 ? 'green' : 'amber',
        'meta'  => ['period_label' => 'maintenant'],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #119 — RM drift alert (open RQ of type dynamic-vs-take-drift) */
function kpi_rm_drift_alert(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_drift_alert';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt
           FROM doc_review_queue
          WHERE type = 'dynamic-vs-take-drift' AND status IN ('open','in_progress')"
    );
    $stmt->execute();
    $cnt = (int) $stmt->fetchColumn();

    $result = array_merge(kpi_empty_result($label, 'alertes'), [
        'value' => $cnt,
        'tint'  => $cnt === 0 ? 'green' : 'amber',
        'meta'  => ['period_label' => 'maintenant'],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #267 — MP days of supply: current stock value ÷ daily consumption rate (MP only) */
function kpi_rm_days_of_supply(array $params, string $label, PDO $pdo): array
{
    $windowDays = $params['window_days'] ?? 90;
    $cacheKey   = "rm_days_of_supply_{$windowDays}d";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // ── Numerator: current MP stock value from v_rm_stock_dynamic ────────────
    // The view already applies EUR→CHF conversion (price_chf) and current_value_chf
    // = current_qty × price_chf. We sum only rows with positive qty (no negatives
    // inflate the total). This is the same source as the "Valeur stock MP" tracker.
    $stockStmt = $pdo->query(
        "SELECT
           SUM(current_value_chf) AS stock_value_chf,
           COUNT(*) AS mi_count
         FROM v_rm_stock_dynamic
         WHERE current_qty > 0"
    );
    $stockRow = $stockStmt->fetch(PDO::FETCH_ASSOC);
    $stockValue = ($stockRow && $stockRow['stock_value_chf'] !== null)
                  ? (float) $stockRow['stock_value_chf']
                  : null;
    $miCount = $stockRow ? (int) $stockRow['mi_count'] : 0;

    if ($stockValue === null) {
        $result = array_merge(kpi_empty_result($label, 'jours'), [
            'tint' => 'neutral',
            'meta' => ['note' => 'Stock MP non disponible (v_rm_stock_dynamic vide).'],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    // ── Denominator: trailing-N-day consumption in CHF ────────────────────────
    // inv_consumption.qty is stored in input_unit (e.g. grams for hops).
    // ref_mi.conversion_factor converts input_unit → pricing_unit (e.g. g → kg = 0.001).
    // ref_mi.price is per pricing_unit. EUR prices are converted to CHF at 0.945.
    // We restrict to is_inventoried=1 to match the stock-side scope.
    // Rows with NULL price are excluded (cannot value them); they contribute 0 to CHF
    // but do not bias the rate downward — any MI in stock but not consumed in window
    // simply doesn't reduce the stock numerator.
    $consStmt = $pdo->prepare(
        "SELECT
           SUM(
             c.qty
             * COALESCE(rm.conversion_factor, 1.0)
             * CASE WHEN rm.currency = 'EUR' THEN rm.price * 0.945 ELSE rm.price END
           ) AS consumption_chf
         FROM inv_consumption c
         JOIN ref_mi rm ON rm.id = c.mi_id_fk
         WHERE c.consumed_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
           AND rm.price   IS NOT NULL
           AND rm.is_inventoried = 1"
    );
    $consStmt->execute([$windowDays]);
    $consRow = $consStmt->fetch(PDO::FETCH_ASSOC);
    $consumptionChf = ($consRow && $consRow['consumption_chf'] !== null)
                      ? (float) $consRow['consumption_chf']
                      : null;

    // ── Divide ────────────────────────────────────────────────────────────────
    if ($consumptionChf === null || $consumptionChf <= 0.0) {
        $result = array_merge(kpi_empty_result($label, 'jours'), [
            'tint' => 'neutral',
            'meta' => [
                'stock_value_chf' => round($stockValue, 2),
                'mi_count'        => $miCount,
                'window_days'     => $windowDays,
                'note'            => "Aucune consommation MP sur {$windowDays}j — taux indisponible.",
            ],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    $dailyRateChf  = $consumptionChf / $windowDays;
    $daysOfSupply  = round($stockValue / $dailyRateChf, 1);

    $tint = match (true) {
        $daysOfSupply >= 90 => 'green',
        $daysOfSupply >= 30 => 'amber',
        default             => 'red',
    };

    $result = array_merge(kpi_empty_result($label, 'jours'), [
        'value'       => $daysOfSupply,
        'tint'        => $tint,
        'meta'        => [
            'stock_value_chf'   => round($stockValue, 2),
            'daily_rate_chf'    => round($dailyRateChf, 2),
            'consumption_chf'   => round($consumptionChf, 2),
            'window_days'       => $windowDays,
            'mi_count'          => $miCount,
            'period_label'      => "stock ÷ conso moy. {$windowDays}j",
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}


// ─────────────────────────────────────────────────────────────────────────────
// Phase 2b Batch 2 — rm_procurement compute handlers (mig-306)
// CANONICAL SOURCES (never re-derive):
//   v_rm_stock_dynamic  — current stock qty + value per MI (anchor + flows)
//   v_mi_cost           — WAC or catalog cost per MI in CHF (WAC resolver mig-178)
//   wac_snapshots       — historical WAC per (mi_id_fk, period) for trend
//   inv_consumption     — canonical consumption (source_event IN brewing/…/packaging)
//   cogs-report-data.json — COP pipeline output (via kpi_load_cogs_json())
//   inv_deliveries      — canonical deliveries, ingredient_fk FK join preferred
// ─────────────────────────────────────────────────────────────────────────────

/** #106 — Valeur stock MP (CHF now): sum current_value_chf from v_rm_stock_dynamic */
function kpi_rm_stock_value(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_stock_value';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT SUM(current_value_chf) AS total_chf,
                COUNT(*) AS mi_count
           FROM v_rm_stock_dynamic
          WHERE current_qty > 0"
    );
    $row       = $stmt->fetch(PDO::FETCH_ASSOC);
    $total     = $row && $row['total_chf'] !== null ? round((float) $row['total_chf'], 2) : null;
    $miCount   = $row ? (int) $row['mi_count'] : 0;

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value' => $total,
        'tint'  => 'neutral',
        'meta'  => [
            'mi_count'     => $miCount,
            'period_label' => 'maintenant',
            'source'       => 'v_rm_stock_dynamic',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #107 — Stock MP par catégorie (bar breakdown, CHF) */
function kpi_rm_stock_by_category(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_stock_by_category';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT category,
                ROUND(SUM(current_value_chf), 2) AS total_chf,
                COUNT(*) AS mi_count
           FROM v_rm_stock_dynamic
          WHERE current_qty > 0
            AND category IS NOT NULL
          GROUP BY category
          ORDER BY total_chf DESC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total     = array_sum(array_column($rows, 'total_chf'));
    $breakdown = array_map(fn($r) => [
        'key'   => $r['category'],
        'label' => $r['category'],
        'value' => (float) $r['total_chf'],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value'     => round($total, 2),
        'breakdown' => $breakdown,
        'tint'      => 'neutral',
        'meta'      => ['period_label' => 'maintenant', 'source' => 'v_rm_stock_dynamic'],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #110 — Jours de couverture par MP (table).
 * For each MI with stock, compute days_cover = current_qty / daily_consumption_rate.
 * Daily rate = consumption over trailing 90 days ÷ 90.
 * Units: inv_consumption.qty is in the MI's input unit (grams for hops etc.);
 * v_rm_stock_dynamic.current_qty is also in the pricing_unit (kg for hops after
 * conversion at ingest). We stay in stock-side units: compare directly since both
 * are sourced from the same MI's accumulated flows (stocktake used same unit).
 */
function kpi_rm_days_cover(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_days_cover';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT s.mi_id,
                s.item,
                s.category,
                s.unit,
                ROUND(s.current_qty, 3) AS stock_qty,
                ROUND(
                  SUM(c.qty) / 90.0,
                  4
                ) AS daily_rate,
                CASE
                  WHEN SUM(c.qty) > 0
                    THEN ROUND(s.current_qty / (SUM(c.qty) / 90.0), 1)
                  ELSE NULL
                END AS days_cover
           FROM v_rm_stock_dynamic s
           LEFT JOIN inv_consumption c
             ON c.mi_id_fk = s.mi_id_fk
            AND c.consumed_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
          WHERE s.current_qty > 0
          GROUP BY s.mi_id_fk, s.mi_id, s.item, s.category, s.unit, s.current_qty
          ORDER BY days_cover ASC, s.item ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $series = array_map(fn($r) => [
        'key'        => $r['mi_id'],
        'label'      => $r['item'],
        'category'   => $r['category'],
        'stock_qty'  => (float) $r['stock_qty'],
        'unit'       => $r['unit'],
        'daily_rate' => $r['daily_rate'] !== null ? (float) $r['daily_rate'] : null,
        'days_cover' => $r['days_cover'] !== null ? (float) $r['days_cover'] : null,
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'jours'), [
        'value'  => count($rows),
        'series' => $series,
        'tint'   => 'neutral',
        'meta'   => [
            'period_label' => 'trailing 90j',
            'mi_count'     => count($rows),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #115 — Top fournisseurs par dépense (bar) */
function kpi_rm_top_suppliers_spend(array $params, string $label, PDO $pdo): array
{
    $limit  = min(50, max(1, (int) ($params['limit'] ?? 10)));
    $period = $params['period'] ?? 'rolling_12m';
    $p      = kpi_resolve_period($period);

    $cacheKey = "rm_top_suppliers_{$p['start']}_{$p['end']}_l{$limit}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE(rs.name, d.supplier_raw, '(inconnu)') AS supplier,
                ROUND(SUM(d.total_chf), 2) AS total_chf,
                COUNT(*) AS delivery_rows
           FROM inv_deliveries d
           LEFT JOIN ref_suppliers rs ON rs.id = d.supplier_fk
          WHERE d.status IN ('Active', 'Consumed')
            AND d.exclusion_class IS NULL
            AND d.date_received BETWEEN ? AND ?
          GROUP BY d.supplier_fk, rs.name, d.supplier_raw
          ORDER BY total_chf DESC
          LIMIT ?"
    );
    $stmt->execute([$p['start'], $p['end'], $limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total     = array_sum(array_column($rows, 'total_chf'));
    $breakdown = array_map(fn($r) => [
        'key'   => $r['supplier'],
        'label' => $r['supplier'],
        'value' => (float) $r['total_chf'],
        'rows'  => (int) $r['delivery_rows'],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value'     => round($total, 2),
        'breakdown' => $breakdown,
        'tint'      => 'neutral',
        'meta'      => [
            'period_label' => $p['label'],
            'top_n'        => $limit,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #116 — Tendance WAC par MP (sparkline, last 6 months from wac_snapshots).
 * Returns a series of {period, mi_id, mi_name, wac_chf} rows — up to top-20
 * by latest WAC value so the chart stays legible. Live WAC from v_mi_cost
 * is appended as the "current" data point.
 */
function kpi_rm_wac_trend_per_mi(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_wac_trend_per_mi';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Historical snapshots (up to 6 periods), all MIs with at least 1 snapshot.
    $stmt = $pdo->query(
        "SELECT ws.period,
                rm.mi_id,
                rm.name AS mi_name,
                ROUND(ws.wac_chf, 4) AS wac_chf
           FROM wac_snapshots ws
           JOIN ref_mi rm ON rm.id = ws.mi_id_fk
          WHERE ws.period >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 6 MONTH), '%Y-%m')
          ORDER BY ws.period ASC, wac_chf DESC"
    );
    $historical = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Current live WAC from v_mi_cost.
    $liveStmt = $pdo->query(
        "SELECT vc.mi_id, vc.mi_name, ROUND(vc.cost_chf, 4) AS wac_chf
           FROM v_mi_cost vc
          WHERE vc.cost_chf IS NOT NULL
            AND vc.cost_basis IN ('wac', 'catalog')
          ORDER BY vc.cost_chf DESC
          LIMIT 20"
    );
    $live = $liveStmt->fetchAll(PDO::FETCH_ASSOC);

    $currentPeriod = date('Y-m');
    $liveSeries    = array_map(fn($r) => array_merge($r, ['period' => $currentPeriod]), $live);

    $series = array_merge($historical, $liveSeries);

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value'  => count($live),
        'series' => $series,
        'tint'   => 'neutral',
        'meta'   => [
            'period_label' => '6 derniers mois',
            'live_mi_count' => count($live),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #117 / #131 — Anomalies prix / Flag achat surpayé (flag + breakdown).
 * Identifies Active deliveries where the line unit_price_chf > v_mi_cost.cost_chf * 1.20.
 * Uses v_mi_cost (WAC or catalog) as the reference price.
 * Excludes: NULL cost_basis (no reference), exclusion_class set, qty_delivered=0 (credits).
 * The threshold 20% is intentionally conservative — flags real "urgent premium" buys.
 * Both #117 price_anomalies and #131 overpriced_purchase_flag share this handler.
 */
function kpi_rm_price_anomalies(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_price_anomalies';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT d.id,
                d.date_received,
                rm.mi_id,
                rm.name AS mi_name,
                d.unit_price,
                d.currency,
                ROUND(d.unit_price * CASE WHEN d.currency = 'EUR' THEN 0.945 ELSE 1.0 END, 4) AS unit_price_chf,
                ROUND(vc.cost_chf, 4) AS ref_cost_chf,
                vc.cost_basis,
                ROUND(
                  (d.unit_price * CASE WHEN d.currency = 'EUR' THEN 0.945 ELSE 1.0 END / NULLIF(vc.cost_chf, 0) - 1.0) * 100,
                  1
                ) AS pct_above_ref,
                COALESCE(rs.name, d.supplier_raw, '(inconnu)') AS supplier
           FROM inv_deliveries d
           JOIN ref_mi rm ON rm.id = d.ingredient_fk
           JOIN v_mi_cost vc ON vc.mi_id_fk = rm.id
           LEFT JOIN ref_suppliers rs ON rs.id = d.supplier_fk
          WHERE d.status = 'Active'
            AND d.exclusion_class IS NULL
            AND d.qty_delivered > 0
            AND d.unit_price > 0
            AND vc.cost_chf > 0
            AND d.unit_price * CASE WHEN d.currency = 'EUR' THEN 0.945 ELSE 1.0 END
                > vc.cost_chf * 1.20
          ORDER BY pct_above_ref DESC
          LIMIT 50"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cnt       = count($rows);
    $breakdown = array_map(fn($r) => [
        'key'            => (string) $r['id'],
        'label'          => $r['mi_name'],
        'mi_id'          => $r['mi_id'],
        'supplier'       => $r['supplier'],
        'date'           => $r['date_received'],
        'unit_price_chf' => (float) $r['unit_price_chf'],
        'ref_cost_chf'   => (float) $r['ref_cost_chf'],
        'cost_basis'     => $r['cost_basis'],
        'pct_above_ref'  => (float) $r['pct_above_ref'],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'alertes'), [
        'value'     => $cnt,
        'breakdown' => $breakdown,
        'tint'      => $cnt === 0 ? 'green' : ($cnt > 5 ? 'red' : 'amber'),
        'meta'      => [
            'threshold_pct' => 20,
            'period_label'  => 'stock actif',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #118 — Consommation par MP / mois (bar) */
function kpi_rm_consumption_per_mi_period(array $params, string $label, PDO $pdo): array
{
    $period   = $params['period'] ?? 'current_month';
    $p        = kpi_resolve_period($period);
    $cacheKey = "rm_consumption_mi_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT rm.mi_id,
                rm.name AS mi_name,
                ANY_VALUE(rmc.name) AS category,
                ANY_VALUE(c.unit) AS unit,
                ROUND(SUM(c.qty), 4) AS total_qty
           FROM inv_consumption c
           JOIN ref_mi rm ON rm.id = c.mi_id_fk
           LEFT JOIN ref_mi_categories rmc ON rmc.id = rm.category_id
          WHERE c.consumed_at BETWEEN ? AND ?
            AND rm.is_inventoried = 1
          GROUP BY rm.id, rm.mi_id, rm.name
          ORDER BY total_qty DESC
          LIMIT 30"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $breakdown = array_map(fn($r) => [
        'key'      => $r['mi_id'],
        'label'    => $r['mi_name'],
        'category' => $r['category'],
        'unit'     => $r['unit'],
        'value'    => (float) $r['total_qty'],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'qty'), [
        'value'     => count($rows),
        'breakdown' => $breakdown,
        'tint'      => 'neutral',
        'meta'      => [
            'period_label' => $p['label'],
            'mi_count'     => count($rows),
            'note'         => 'inv_consumption: brewing+fermenting+racking+packaging; no packaging rows pre-mig-pipeline.',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #120 — Solde cautions / dépôts par fournisseur (table).
 * Reads inv_deliveries rows where the MI belongs to category 'Cautions' (GL 1302).
 * Net balance per supplier = SUM(total_chf) — positive = net deposit paid,
 * negative = net credit received.
 */
function kpi_rm_caution_deposit_balance(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_caution_deposit_balance';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT COALESCE(rs.name, d.supplier_raw, '(inconnu)') AS supplier,
                ROUND(SUM(d.total_chf), 2) AS net_balance_chf,
                COUNT(*) AS line_count
           FROM inv_deliveries d
           JOIN ref_mi rm ON rm.id = d.ingredient_fk
           JOIN ref_mi_categories rmc ON rmc.id = rm.category_id
           LEFT JOIN ref_suppliers rs ON rs.id = d.supplier_fk
          WHERE rmc.name = 'Cautions'
            AND d.status IN ('Active', 'Consumed')
          GROUP BY d.supplier_fk, rs.name, d.supplier_raw
          ORDER BY net_balance_chf DESC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total     = array_sum(array_column($rows, 'net_balance_chf'));
    $breakdown = array_map(fn($r) => [
        'key'             => $r['supplier'],
        'label'           => $r['supplier'],
        'net_balance_chf' => (float) $r['net_balance_chf'],
        'value'           => (float) $r['net_balance_chf'],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value'     => round($total, 2),
        'breakdown' => $breakdown,
        'tint'      => 'neutral',
        'meta'      => [
            'period_label' => 'cumulé',
            'gl_account'   => '1302',
            'supplier_count' => count($rows),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #121 — Tendance TVA import / frais transport (sparkline by month).
 * Groups inv_deliveries for MI in category 'Transport' (12) by YYYY-MM.
 * Excludes exclusion_class='recoverable_vat' lines.
 */
function kpi_rm_import_vat_freight_trend(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_import_vat_freight_trend';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT DATE_FORMAT(d.date_received, '%Y-%m') AS period,
                ROUND(SUM(d.total_chf), 2) AS total_chf,
                COUNT(*) AS line_count
           FROM inv_deliveries d
           JOIN ref_mi rm ON rm.id = d.ingredient_fk
           JOIN ref_mi_categories rmc ON rmc.id = rm.category_id
          WHERE rmc.name = 'Transport'
            AND d.status IN ('Active', 'Consumed')
            AND d.exclusion_class IS NULL
          GROUP BY period
          ORDER BY period ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $series = array_map(fn($r) => [
        'period'     => $r['period'],
        'value'      => (float) $r['total_chf'],
        'line_count' => (int) $r['line_count'],
    ], $rows);

    $latest = !empty($series) ? end($series) : null;

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value'  => $latest ? $latest['value'] : null,
        'series' => $series,
        'tint'   => 'neutral',
        'meta'   => [
            'period_label' => 'dernier mois',
            'category'     => 'Transport',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #122 — Coût ingrédient en % du COGS (donut, per-section).
 * Numerator: brewing section total from COP pipeline JSON (malts + hops + ingredients).
 * Denominator: totalVariables.total from same month.
 * Uses kpi_load_cogs_json() — never re-derives COGS.
 */
function kpi_rm_ingredient_cost_pct_cogs(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_ingredient_cost_pct_cogs';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $month = kpi_cogs_latest_month_data();

    if ($month === null) {
        $result = array_merge(kpi_empty_result($label, '%'), [
            'meta' => ['note' => 'Pipeline COGS non disponible'],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    $cop         = $month['cop']          ?? [];
    $brewing     = $cop['brewing']        ?? [];
    $tvars       = $cop['totalVariables'] ?? [];
    $totalCop    = is_array($tvars) ? (float) ($tvars['total'] ?? 0) : (float) $tvars;

    $maltsTotal  = (float) ($brewing['malts']['current']['total']       ?? $brewing['malts']['rolling6']['total']       ?? 0);
    $hopsTotal   = (float) ($brewing['hops']['current']['total']        ?? $brewing['hops']['rolling6']['total']        ?? 0);
    $ingTotal    = (float) ($brewing['ingredients']['current']['total'] ?? $brewing['ingredients']['rolling6']['total'] ?? 0);
    $brewingTotal = $maltsTotal + $hopsTotal + $ingTotal;

    $packagingTotal = (float) ($cop['packaging']['total'] ?? 0);

    $ratio = $totalCop > 0 ? round($brewingTotal / $totalCop * 100, 2) : null;

    $breakdown = [
        ['key' => 'malt',      'label' => 'Malt',         'value' => round($maltsTotal, 2)],
        ['key' => 'hops',      'label' => 'Houblon',      'value' => round($hopsTotal, 2)],
        ['key' => 'brewing_adj', 'label' => 'Ingrédients brassage', 'value' => round($ingTotal, 2)],
        ['key' => 'packaging', 'label' => 'Packaging',   'value' => round($packagingTotal, 2)],
    ];

    $freshness = kpi_cogs_freshness_meta($month['monthKey']);

    $result = array_merge(kpi_empty_result($label, '%'), [
        'value'     => $ratio,
        'breakdown' => $breakdown,
        'tint'      => 'neutral',
        'meta'      => array_merge([
            'brewing_total_chf'  => round($brewingTotal, 2),
            'cop_total_chf'      => round($totalCop, 2),
            'period_label'       => $month['monthKey'],
        ], $freshness),
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #123 — Délai livraison fournisseur (table).
 * Lead time = DATEDIFF(date_received, invoice_date) via doc_invoices join.
 * Only rows where both dates exist and the gap is ≥ 0 (ignore data-entry noise).
 * Grouped by supplier, showing avg + min + max lead days.
 * Note: invoice_date is extracted from OCR — may equal date_received when the
 * parser defaulted to the delivery date. This is the best available proxy until
 * a PO date is recorded.
 */
function kpi_rm_supplier_lead_time(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_supplier_lead_time';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT COALESCE(rs.name, d.supplier_raw, '(inconnu)') AS supplier,
                ROUND(AVG(DATEDIFF(d.date_received, di.invoice_date)), 1) AS avg_lead_days,
                MIN(DATEDIFF(d.date_received, di.invoice_date))           AS min_lead_days,
                MAX(DATEDIFF(d.date_received, di.invoice_date))           AS max_lead_days,
                COUNT(*) AS sample_size
           FROM inv_deliveries d
           JOIN doc_files df ON df.id = d.file_id_fk
           JOIN doc_invoices di ON di.file_id = df.id
           LEFT JOIN ref_suppliers rs ON rs.id = d.supplier_fk
          WHERE d.status IN ('Active', 'Consumed')
            AND di.invoice_date IS NOT NULL
            AND d.date_received IS NOT NULL
            AND DATEDIFF(d.date_received, di.invoice_date) >= 0
          GROUP BY d.supplier_fk, rs.name, d.supplier_raw
         HAVING COUNT(*) >= 2
          ORDER BY avg_lead_days DESC
          LIMIT 20"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $breakdown = array_map(fn($r) => [
        'key'         => $r['supplier'],
        'label'       => $r['supplier'],
        'avg'         => (float) $r['avg_lead_days'],
        'min'         => (int) $r['min_lead_days'],
        'max'         => (int) $r['max_lead_days'],
        'sample_size' => (int) $r['sample_size'],
        'value'       => (float) $r['avg_lead_days'],
    ], $rows);

    $avgAll = count($rows) > 0
        ? round(array_sum(array_column($rows, 'avg')) / count($rows), 1)
        : null;

    $result = array_merge(kpi_empty_result($label, 'jours'), [
        'value'     => $avgAll,
        'breakdown' => $breakdown,
        'tint'      => 'neutral',
        'meta'      => [
            'period_label' => 'cumulé (≥2 livraisons)',
            'note'         => 'Proxy: invoice_date (OCR) vs date_received. Peut inclure biais si invoice_date=delivery_date.',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #124 — Taux livraison à temps.
 * Proxy: deliveries received within 14 days of invoice_date (positive gap ≤ 14).
 * Without a PO-expected-date we use invoice_date as the order anchor.
 * Returns pct on-time + sample size.
 */
function kpi_rm_on_time_delivery_rate(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_on_time_delivery_rate';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN DATEDIFF(d.date_received, di.invoice_date) <= 14 THEN 1 ELSE 0 END) AS on_time
           FROM inv_deliveries d
           JOIN doc_files df ON df.id = d.file_id_fk
           JOIN doc_invoices di ON di.file_id = df.id
          WHERE d.status IN ('Active', 'Consumed')
            AND di.invoice_date IS NOT NULL
            AND d.date_received IS NOT NULL
            AND DATEDIFF(d.date_received, di.invoice_date) BETWEEN 0 AND 60"
    );
    $row    = $stmt->fetch(PDO::FETCH_ASSOC);
    $total  = (int) ($row['total']   ?? 0);
    $onTime = (int) ($row['on_time'] ?? 0);
    $pct    = $total > 0 ? round($onTime / $total * 100, 1) : null;

    $result = array_merge(kpi_empty_result($label, '%'), [
        'value' => $pct,
        'tint'  => $pct === null ? 'neutral' : ($pct >= 90 ? 'green' : ($pct >= 70 ? 'amber' : 'red')),
        'meta'  => [
            'on_time'      => $onTime,
            'total'        => $total,
            'threshold'    => '≤14j après date facture',
            'period_label' => 'cumulé',
            'note'         => 'Proxy sans PO: compare date_received vs invoice_date (OCR). Écart 0–60j seulement.',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #127 — Flag risque fournisseur unique (flag + table).
 * An MI is flagged as single-source if it has exactly 1 distinct supplier_fk
 * across all Active + Consumed deliveries.
 */
function kpi_rm_single_source_risk(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_single_source_risk';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT rm.mi_id,
                rm.name AS mi_name,
                ANY_VALUE(rmc.name) AS category,
                COUNT(DISTINCT d.supplier_fk) AS supplier_count,
                ANY_VALUE(COALESCE(rs.name, d.supplier_raw, '(inconnu)')) AS sole_supplier
           FROM inv_deliveries d
           JOIN ref_mi rm ON rm.id = d.ingredient_fk
           LEFT JOIN ref_mi_categories rmc ON rmc.id = rm.category_id
           LEFT JOIN ref_suppliers rs ON rs.id = d.supplier_fk
          WHERE d.status IN ('Active', 'Consumed')
            AND d.ingredient_fk IS NOT NULL
            AND rm.is_inventoried = 1
          GROUP BY rm.id, rm.mi_id, rm.name
         HAVING COUNT(DISTINCT d.supplier_fk) = 1
          ORDER BY rmc.name ASC, rm.name ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cnt       = count($rows);
    $breakdown = array_map(fn($r) => [
        'key'          => $r['mi_id'],
        'label'        => $r['mi_name'],
        'category'     => $r['category'],
        'sole_supplier'=> $r['sole_supplier'],
        'value'        => 1,
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'MP'), [
        'value'     => $cnt,
        'breakdown' => $breakdown,
        'tint'      => $cnt === 0 ? 'green' : ($cnt > 10 ? 'red' : 'amber'),
        'meta'      => [
            'period_label' => 'historique cumulé',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #128 — Dépenses YoY (sparkline: month totals current year vs prior year).
 * Reads inv_deliveries, groups by YYYY-MM, shows last 24 months.
 */
function kpi_rm_spend_yoy(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_spend_yoy';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT DATE_FORMAT(d.date_received, '%Y-%m') AS period,
                YEAR(d.date_received) AS yr,
                ROUND(SUM(d.total_chf), 2) AS total_chf
           FROM inv_deliveries d
          WHERE d.status IN ('Active', 'Consumed')
            AND d.exclusion_class IS NULL
            AND d.date_received >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
          GROUP BY period, yr
          ORDER BY period ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Split into this year vs last year for delta.
    $thisYear = (int) date('Y');
    $byYear   = [];
    foreach ($rows as $r) {
        $byYear[$r['yr']][$r['period']] = (float) $r['total_chf'];
    }

    $totalThisYear = array_sum($byYear[$thisYear] ?? []);
    $totalLastYear = array_sum($byYear[$thisYear - 1] ?? []);
    $delta = $totalLastYear > 0 ? round($totalThisYear - $totalLastYear, 2) : null;

    $series = array_map(fn($r) => [
        'period' => $r['period'],
        'value'  => (float) $r['total_chf'],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value'       => round($totalThisYear, 2),
        'delta'       => $delta,
        'delta_label' => 'vs même période N-1',
        'series'      => $series,
        'tint'        => 'neutral',
        'meta'        => [
            'ytd_this_year' => round($totalThisYear, 2),
            'ytd_last_year' => round($totalLastYear, 2),
            'period_label'  => 'YTD ' . $thisYear,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #129 — Répartition coût malt vs houblon (donut).
 * Reads inv_deliveries grouped by MI category 'Malt' vs 'Hops',
 * restricted to current year Active + Consumed deliveries.
 */
function kpi_rm_malt_hops_cost_split(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_malt_hops_cost_split';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT rmc.name AS category,
                ROUND(SUM(d.total_chf), 2) AS total_chf
           FROM inv_deliveries d
           JOIN ref_mi rm ON rm.id = d.ingredient_fk
           JOIN ref_mi_categories rmc ON rmc.id = rm.category_id
          WHERE rmc.name IN ('Malt', 'Hops')
            AND d.status IN ('Active', 'Consumed')
            AND d.exclusion_class IS NULL
            AND YEAR(d.date_received) = YEAR(CURDATE())
          GROUP BY rmc.name"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byCategory = [];
    foreach ($rows as $r) {
        $byCategory[$r['category']] = (float) $r['total_chf'];
    }

    $malt  = $byCategory['Malt'] ?? 0.0;
    $hops  = $byCategory['Hops'] ?? 0.0;
    $total = $malt + $hops;

    $breakdown = [
        ['key' => 'malt', 'label' => 'Malt',    'value' => round($malt, 2)],
        ['key' => 'hops', 'label' => 'Houblon', 'value' => round($hops, 2)],
    ];

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value'     => round($total, 2),
        'breakdown' => $breakdown,
        'tint'      => 'neutral',
        'meta'      => [
            'period_label' => 'YTD ' . date('Y'),
            'malt_pct'     => $total > 0 ? round($malt / $total * 100, 1) : null,
            'hops_pct'     => $total > 0 ? round($hops / $total * 100, 1) : null,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #130 — Exposition FX (EUR/CHF) (kpi_number).
 * YTD EUR-denominated spend (total_original in EUR) and its CHF equivalent,
 * plus the implicit FX gain/loss vs 1:1 parity.
 */
function kpi_rm_fx_eur_chf_exposure(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_fx_eur_chf_exposure';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT ROUND(SUM(d.total_original), 2) AS total_eur,
                ROUND(SUM(d.total_chf), 2)      AS total_chf,
                COUNT(*) AS delivery_count
           FROM inv_deliveries d
          WHERE d.currency = 'EUR'
            AND d.status IN ('Active', 'Consumed')
            AND d.exclusion_class IS NULL
            AND YEAR(d.date_received) = YEAR(CURDATE())"
    );
    $row      = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalEur = $row && $row['total_eur'] !== null ? (float) $row['total_eur'] : 0.0;
    $totalChf = $row && $row['total_chf'] !== null ? (float) $row['total_chf'] : 0.0;
    $rows     = $row ? (int) $row['delivery_count'] : 0;
    // FX saving vs 1:1 parity
    $fxSaving = round($totalEur - $totalChf, 2);

    $result = array_merge(kpi_empty_result($label, 'EUR'), [
        'value' => round($totalEur, 2),
        'tint'  => 'neutral',
        'meta'  => [
            'total_eur'           => round($totalEur, 2),
            'total_chf'           => round($totalChf, 2),
            'fx_saving_vs_parity' => $fxSaving,
            'delivery_count'      => $rows,
            'period_label'        => 'YTD ' . date('Y'),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

// ═════════════════════════════════════════════════════════════════════════════
// HANDLER: racking  (source_domain = 'racking')
// Reads: bd_racking_v2 (is_tombstoned=0), joined to bd_brewing_gravity_v2 and
//        bd_packaging_v2 for cross-domain cycle metrics.
// Key: join on (recipe_id_fk, batch) — NEVER on beer name string.
// bd_racking_v2 uses neb_recipe_id_fk / contract_recipe_id_fk — use COALESCE.
// ═════════════════════════════════════════════════════════════════════════════

function kpi_handler_racking(
    string $handler,
    array  $params,
    string $label,
    PDO    $pdo
): array {
    return match ($handler) {
        'rackings_period'           => kpi_racking_period($params, $label, $pdo),
        'avg_racking_time_per_beer' => kpi_racking_avg_time_per_beer($label, $pdo),
        'avg_racking_time_per_hl'   => kpi_racking_avg_time_per_hl($label, $pdo),
        'racking_loss_pct'          => kpi_racking_loss_pct($params, $label, $pdo),
        'brew_to_rack_cycle'        => kpi_racking_brew_to_rack_cycle($params, $label, $pdo),
        'blend_rackings_count'      => kpi_racking_blend_count($params, $label, $pdo),
        'rack_to_packaging_lag'     => kpi_racking_to_packaging_lag($params, $label, $pdo),
        // Phase 2b Batch 4 — compute handlers (mig-308):
        'racking_yield_vs_target'   => kpi_racking_yield_vs_target($params, $label, $pdo),
        'do_pickup_racking'         => kpi_racking_do_pickup($params, $label, $pdo),
        'carbonation_achieved'      => kpi_racking_carbonation($params, $label, $pdo),
        // Confirmed stub — source columns absent:
        'tank_emptying_efficiency'  => kpi_stub_handler('racking', $handler, $label), // flowmeter_start/end: 2/406 rows; bbt_vide_scrapped_hl: 0/406
        default                     => kpi_stub_handler('racking', $handler, $label),
    };
}

/** #39 — Mises en fût ce mois (nbre + HL) */
function kpi_racking_period(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);
    $metric = $params['metric'] ?? 'hl_packaged';

    $cacheKey = "racking_period_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        $d = $cached;
    } else {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS rack_count,
                    ROUND(SUM(racked_vol_hl), 2) AS total_hl,
                    DATEDIFF(CURDATE(), MAX(event_date)) AS days_since_last
               FROM bd_racking_v2
              WHERE is_tombstoned = 0
                AND event_date BETWEEN ? AND ?"
        );
        $stmt->execute([$p['start'], $p['end']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $d = [
            'rack_count'      => (int)   ($row['rack_count']      ?? 0),
            'total_hl'        => (float) ($row['total_hl']        ?? 0.0),
            'days_since_last' => $row['days_since_last'] !== null ? (int) $row['days_since_last'] : null,
        ];
        kpi_cache_set($cacheKey, $d);
    }

    $isCount = ($metric === 'brew_count');
    $result = array_merge(kpi_empty_result($label, $isCount ? 'mises en fût' : 'HL'), [
        'value' => $isCount ? $d['rack_count'] : round($d['total_hl'], 1),
        'tint'  => 'neutral',
        'meta'  => [
            'period_label'    => $p['label'],
            'rack_count'      => $d['rack_count'],
            'total_hl'        => round($d['total_hl'], 1),
            'days_since_last' => $d['days_since_last'],
        ],
    ]);

    return $result;
}

/** #37 — Temps moyen mise en fût par bière (rolling 12m, hours/mise en fût) */
function kpi_racking_avg_time_per_beer(string $label, PDO $pdo): array
{
    $cacheKey = 'racking_avg_time_per_beer';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT COALESCE(rr1.name, rr2.name, 'Inconnu') AS beer_name,
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, r.start_time, r.end_time) / 60.0), 2) AS avg_hours,
                ROUND(AVG(r.racked_vol_hl), 2) AS avg_hl,
                COUNT(*) AS rack_count
           FROM bd_racking_v2 r
           LEFT JOIN ref_recipes rr1 ON rr1.id = r.neb_recipe_id_fk
           LEFT JOIN ref_recipes rr2 ON rr2.id = r.contract_recipe_id_fk
          WHERE r.is_tombstoned = 0
            AND r.start_time IS NOT NULL
            AND r.end_time IS NOT NULL
            AND r.end_time > r.start_time
            AND r.event_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
          GROUP BY beer_name
          ORDER BY avg_hours DESC
          LIMIT 20"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $overallAvg = null;
    if (!empty($rows)) {
        $weightedSum = 0.0;
        $totalCount  = 0;
        foreach ($rows as $r) {
            $weightedSum += (float)$r['avg_hours'] * (int)$r['rack_count'];
            $totalCount  += (int) $r['rack_count'];
        }
        $overallAvg = $totalCount > 0 ? round($weightedSum / $totalCount, 2) : null;
    }

    $breakdown = array_map(fn($r) => [
        'key'   => $r['beer_name'],
        'label' => $r['beer_name'],
        'value' => (float) $r['avg_hours'],
        'meta'  => ['avg_hl' => (float)$r['avg_hl'], 'rack_count' => (int)$r['rack_count']],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'h/mise en fût'), [
        'value'     => $overallAvg,
        'tint'      => 'neutral',
        'breakdown' => $breakdown,
        'meta'      => ['period_label' => '12 derniers mois'],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #38 — Temps moyen mise en fût / HL (min/HL, all time) */
function kpi_racking_avg_time_per_hl(string $label, PDO $pdo): array
{
    $cacheKey = 'racking_avg_time_per_hl';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, start_time, end_time) / NULLIF(racked_vol_hl, 0)), 2) AS avg_min_per_hl,
                ROUND(AVG(racked_vol_hl / NULLIF(TIMESTAMPDIFF(HOUR, start_time, end_time), 0)), 2) AS avg_hl_per_hour,
                COUNT(*) AS rack_count
           FROM bd_racking_v2
          WHERE is_tombstoned = 0
            AND start_time IS NOT NULL
            AND end_time IS NOT NULL
            AND end_time > start_time
            AND racked_vol_hl > 0"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $result = array_merge(kpi_empty_result($label, 'min/HL'), [
        'value' => $row ? (float) $row['avg_min_per_hl'] : null,
        'tint'  => 'neutral',
        'meta'  => [
            'avg_hl_per_hour' => $row ? (float) $row['avg_hl_per_hour'] : null,
            'rack_count'      => $row ? (int)   $row['rack_count']      : 0,
            'period_label'    => 'toutes données',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #40 — Pertes mise en fût % (brassé→soutiré, rolling 6m) */
function kpi_racking_loss_pct(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_6m';
    $p = kpi_resolve_period($period);

    $cacheKey = "racking_loss_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Join racking rows to wort (Cooling) totals by (recipe_id_fk, batch).
    // Average loss = (wort_hl - racked_hl) / wort_hl x 100.
    // Filter 0-40% to exclude data-entry errors.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS pairs,
                ROUND(AVG((bg.wort_hl - r.racked_vol_hl) / NULLIF(bg.wort_hl, 0) * 100), 2) AS avg_loss_pct,
                ROUND(SUM(r.racked_vol_hl), 2) AS total_racked_hl,
                ROUND(SUM(bg.wort_hl), 2) AS total_wort_hl
           FROM bd_racking_v2 r
           JOIN (
               SELECT recipe_id_fk, batch,
                      SUM(final_volume) AS wort_hl
                 FROM bd_brewing_gravity_v2
                WHERE event_type = 'Cooling' AND is_tombstoned = 0
                GROUP BY recipe_id_fk, batch
           ) bg ON bg.recipe_id_fk = COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)
               AND bg.batch = COALESCE(NULLIF(r.neb_batch, ''), r.contract_batch)
          WHERE r.is_tombstoned = 0
            AND r.racked_vol_hl > 0
            AND bg.wort_hl > 0
            AND r.event_date BETWEEN ? AND ?
            AND (bg.wort_hl - r.racked_vol_hl) / NULLIF(bg.wort_hl, 0) BETWEEN 0 AND 0.40"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $lossPct = $row && $row['pairs'] > 0 ? (float) $row['avg_loss_pct'] : null;

    $tint = match (true) {
        $lossPct === null  => 'neutral',
        $lossPct <= 8.0    => 'green',
        $lossPct <= 12.0   => 'amber',
        default            => 'red',
    };

    $result = array_merge(kpi_empty_result($label, '%'), [
        'value' => $lossPct,
        'tint'  => $tint,
        'meta'  => [
            'period_label'    => $p['label'],
            'pairs'           => $row ? (int)   $row['pairs']           : 0,
            'total_racked_hl' => $row ? (float) $row['total_racked_hl'] : 0.0,
            'total_wort_hl'   => $row ? (float) $row['total_wort_hl']   : 0.0,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #41 — Délai brassin→mise en fût (jours, rolling 6m) */
function kpi_racking_brew_to_rack_cycle(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_6m';
    $p = kpi_resolve_period($period);

    $cacheKey = "racking_brew_to_rack_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS pairs,
                ROUND(AVG(DATEDIFF(r.event_date, bg.brew_date)), 1) AS avg_days,
                MIN(DATEDIFF(r.event_date, bg.brew_date)) AS min_days,
                MAX(DATEDIFF(r.event_date, bg.brew_date)) AS max_days
           FROM bd_racking_v2 r
           JOIN (
               SELECT recipe_id_fk, batch,
                      MIN(DATE(submitted_at)) AS brew_date
                 FROM bd_brewing_gravity_v2
                WHERE event_type = 'Cooling' AND is_tombstoned = 0
                GROUP BY recipe_id_fk, batch
           ) bg ON bg.recipe_id_fk = COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)
               AND bg.batch = COALESCE(NULLIF(r.neb_batch, ''), r.contract_batch)
          WHERE r.is_tombstoned = 0
            AND r.event_date BETWEEN ? AND ?
            AND DATEDIFF(r.event_date, bg.brew_date) BETWEEN 0 AND 120"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $avgDays = $row && $row['pairs'] > 0 ? (float) $row['avg_days'] : null;

    $result = array_merge(kpi_empty_result($label, 'jours'), [
        'value' => $avgDays,
        'tint'  => 'neutral',
        'meta'  => [
            'period_label' => $p['label'],
            'pairs'        => $row ? (int) $row['pairs']    : 0,
            'min_days'     => $row ? (int) $row['min_days'] : null,
            'max_days'     => $row ? (int) $row['max_days'] : null,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #42 — Mélanges / mises en fût multi-cuves (blend_hl > 0) */
function kpi_racking_blend_count(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "racking_blends_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total_racks,
                SUM(CASE WHEN blend_hl IS NOT NULL AND blend_hl > 0 THEN 1 ELSE 0 END) AS blend_count,
                ROUND(SUM(CASE WHEN blend_hl IS NOT NULL AND blend_hl > 0 THEN blend_hl ELSE 0 END), 2) AS blend_hl_total
           FROM bd_racking_v2
          WHERE is_tombstoned = 0
            AND event_date BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $blendCount = (int) ($row['blend_count'] ?? 0);

    $result = array_merge(kpi_empty_result($label, 'mélanges'), [
        'value' => $blendCount,
        'tint'  => 'neutral',
        'meta'  => [
            'period_label'   => $p['label'],
            'total_racks'    => (int)   ($row['total_racks']    ?? 0),
            'blend_hl_total' => (float) ($row['blend_hl_total'] ?? 0.0),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #46 — Délai mise en fût → packaging (jours, rolling 6m) */
function kpi_racking_to_packaging_lag(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_6m';
    $p = kpi_resolve_period($period);

    $cacheKey = "racking_to_pkg_lag_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS pairs,
                ROUND(AVG(DATEDIFF(p.first_pkg_date, r.event_date)), 1) AS avg_days,
                MIN(DATEDIFF(p.first_pkg_date, r.event_date)) AS min_days,
                MAX(DATEDIFF(p.first_pkg_date, r.event_date)) AS max_days
           FROM bd_racking_v2 r
           JOIN (
               SELECT recipe_id_fk,
                      COALESCE(neb_batch, contract_batch) AS batch,
                      MIN(event_date) AS first_pkg_date
                 FROM bd_packaging_v2
                WHERE is_tombstoned = 0
                GROUP BY recipe_id_fk, batch
           ) p ON p.recipe_id_fk = COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)
              AND p.batch = COALESCE(NULLIF(r.neb_batch, ''), r.contract_batch)
          WHERE r.is_tombstoned = 0
            AND r.event_date BETWEEN ? AND ?
            AND DATEDIFF(p.first_pkg_date, r.event_date) BETWEEN 0 AND 60"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $avgDays = $row && $row['pairs'] > 0 ? (float) $row['avg_days'] : null;

    $result = array_merge(kpi_empty_result($label, 'jours'), [
        'value' => $avgDays,
        'tint'  => 'neutral',
        'meta'  => [
            'period_label' => $p['label'],
            'pairs'        => $row ? (int) $row['pairs']    : 0,
            'min_days'     => $row ? (int) $row['min_days'] : null,
            'max_days'     => $row ? (int) $row['max_days'] : null,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}


// ─── Phase 2b Batch 4 — racking compute handlers ─────────────────────────────

/**
 * #43 — Racking yield vs brewed HL (per-batch bar chart, rolling 3m).
 * Definition: racked HL / brewed HL per matched (recipe_id_fk, batch) pair.
 * Reuses same join as kpi_racking_loss_pct (#40) for consistency.
 * Returns: series = [{period: "RecipeName #batch", value: yield_pct}] for the bar renderer.
 */
function kpi_racking_yield_vs_target(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_3m';
    $p = kpi_resolve_period($period);

    $cacheKey = "racking_yield_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE(rr1.recipe_short_name, rr2.recipe_short_name,
                         rr1.name, rr2.name, 'Inconnu') AS beer_name,
                COALESCE(NULLIF(r.neb_batch,''), r.contract_batch) AS batch,
                r.racked_vol_hl,
                bg.wort_hl,
                ROUND(r.racked_vol_hl / NULLIF(bg.wort_hl, 0) * 100, 1) AS yield_pct
           FROM bd_racking_v2 r
           JOIN (
               SELECT recipe_id_fk, batch, SUM(final_volume) AS wort_hl
                 FROM bd_brewing_gravity_v2
                WHERE event_type = 'Cooling' AND is_tombstoned = 0
                GROUP BY recipe_id_fk, batch
           ) bg ON bg.recipe_id_fk = COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)
               AND bg.batch = COALESCE(NULLIF(r.neb_batch,''), r.contract_batch)
           LEFT JOIN ref_recipes rr1 ON rr1.id = r.neb_recipe_id_fk
           LEFT JOIN ref_recipes rr2 ON rr2.id = r.contract_recipe_id_fk
          WHERE r.is_tombstoned = 0
            AND r.racked_vol_hl > 0
            AND bg.wort_hl > 0
            AND r.event_date BETWEEN ? AND ?
            AND r.racked_vol_hl / NULLIF(bg.wort_hl, 0) BETWEEN 0.6 AND 1.05
          ORDER BY r.event_date DESC
          LIMIT 20"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $series = [];
    $totalYield = 0.0;
    foreach ($rows as $r) {
        $lbl = ($r['beer_name'] ?? 'Inconnu') . ' #' . ($r['batch'] ?? '?');
        $series[] = ['period' => $lbl, 'value' => (float) $r['yield_pct']];
        $totalYield += (float) $r['yield_pct'];
    }
    $avgYield = count($rows) > 0 ? round($totalYield / count($rows), 1) : null;

    $tint = match (true) {
        $avgYield === null  => 'neutral',
        $avgYield >= 92.0   => 'green',
        $avgYield >= 85.0   => 'amber',
        default             => 'red',
    };

    $result = array_merge(kpi_empty_result($label, '%'), [
        'value'  => $avgYield,
        'tint'   => $tint,
        'series' => $series,
        'meta'   => [
            'period_label' => $p['label'],
            'pair_count'   => count($rows),
            'note'         => 'Rendement = HL transféré / HL brassé par (recette, brassin)',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #44 — O₂ dissous pickup à la mise en fût (ppb, rolling 3m).
 * Source: bd_racking_v2.bbt_o2 (O₂ at BBT after racking).
 * Outlier filter: uses qc_o2_outlier_hi from commissioning_settings (default 200 ppb).
 */
function kpi_racking_do_pickup(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_3m';
    $p = kpi_resolve_period($period);

    $cacheKey = "racking_do_pickup_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Fetch O₂ outlier threshold from commissioning_settings; fall back to 200 ppb.
    $threshStmt = $pdo->query(
        "SELECT value_num FROM commissioning_settings
          WHERE section = 'qc_thresholds' AND key_name = 'qc_o2_outlier_hi'
          LIMIT 1"
    );
    $threshRow = $threshStmt->fetch(PDO::FETCH_ASSOC);
    $outlierHi = $threshRow ? (float) $threshRow['value_num'] : 200.0;

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS rack_count,
                ROUND(AVG(bbt_o2), 1) AS avg_o2,
                ROUND(MIN(bbt_o2), 1) AS min_o2,
                ROUND(MAX(bbt_o2), 1) AS max_o2,
                SUM(CASE WHEN bbt_o2 > ? THEN 1 ELSE 0 END) AS outlier_count
           FROM bd_racking_v2
          WHERE is_tombstoned = 0
            AND bbt_o2 IS NOT NULL
            AND bbt_o2 > 0
            AND bbt_o2 <= ?
            AND event_date BETWEEN ? AND ?"
    );
    $stmt->execute([$outlierHi, $outlierHi, $p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $avgO2     = $row && (int)$row['rack_count'] > 0 ? (float) $row['avg_o2'] : null;
    $warnHiStmt = $pdo->query(
        "SELECT value_num FROM commissioning_settings
          WHERE section = 'qc_thresholds' AND key_name = 'qc_o2_warn_hi'
          LIMIT 1"
    );
    $warnHiRow = $warnHiStmt->fetch(PDO::FETCH_ASSOC);
    $warnHi    = $warnHiRow ? (float) $warnHiRow['value_num'] : 50.0;

    $tint = match (true) {
        $avgO2 === null         => 'neutral',
        $avgO2 <= $warnHi * 0.6 => 'green',
        $avgO2 <= $warnHi       => 'amber',
        default                 => 'red',
    };

    $result = array_merge(kpi_empty_result($label, 'ppb'), [
        'value' => $avgO2,
        'tint'  => $tint,
        'meta'  => [
            'period_label'  => $p['label'],
            'rack_count'    => $row ? (int)   $row['rack_count']    : 0,
            'min_o2'        => $row ? (float) $row['min_o2']        : null,
            'max_o2'        => $row ? (float) $row['max_o2']        : null,
            'outlier_count' => $row ? (int)   $row['outlier_count'] : 0,
            'warn_hi_ppb'   => $warnHi,
            'outlier_hi_ppb' => $outlierHi,
            'note'          => 'O₂ mesuré BBT à la mise en fût; outliers exclus (> ' . $outlierHi . ' ppb)',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #45 — Carbonatation atteinte (g/L, rolling 3m).
 * Source: bd_racking_v2.bbt_co2 (CO₂ measured at BBT after racking).
 * Outlier filter: uses qc_co2_outlier_hi from commissioning_settings (default 6 g/L).
 */
function kpi_racking_carbonation(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_3m';
    $p = kpi_resolve_period($period);

    $cacheKey = "racking_co2_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Fetch CO₂ thresholds from commissioning_settings; fall back to 2.5/6 g/L.
    $threshStmt = $pdo->prepare(
        "SELECT key_name, value_num FROM commissioning_settings
          WHERE section = 'qc_thresholds'
            AND key_name IN ('qc_co2_warn_lo', 'qc_co2_warn_hi', 'qc_co2_outlier_lo', 'qc_co2_outlier_hi')"
    );
    $threshStmt->execute();
    $threshRows = $threshStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $warnLo    = (float) ($threshRows['qc_co2_warn_lo']    ?? 3.5);
    $warnHi    = (float) ($threshRows['qc_co2_warn_hi']    ?? 5.0);
    $outlierLo = (float) ($threshRows['qc_co2_outlier_lo'] ?? 2.5);
    $outlierHi = (float) ($threshRows['qc_co2_outlier_hi'] ?? 6.0);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS rack_count,
                ROUND(AVG(bbt_co2), 2) AS avg_co2,
                ROUND(MIN(bbt_co2), 2) AS min_co2,
                ROUND(MAX(bbt_co2), 2) AS max_co2,
                SUM(CASE WHEN bbt_co2 < ? OR bbt_co2 > ? THEN 1 ELSE 0 END) AS out_of_range_count
           FROM bd_racking_v2
          WHERE is_tombstoned = 0
            AND bbt_co2 IS NOT NULL
            AND bbt_co2 BETWEEN ? AND ?
            AND event_date BETWEEN ? AND ?"
    );
    $stmt->execute([$warnLo, $warnHi, $outlierLo, $outlierHi, $p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $avgCo2 = $row && (int)$row['rack_count'] > 0 ? (float) $row['avg_co2'] : null;

    $tint = match (true) {
        $avgCo2 === null                              => 'neutral',
        $avgCo2 >= $warnLo && $avgCo2 <= $warnHi     => 'green',
        $avgCo2 >= $outlierLo && $avgCo2 <= $outlierHi => 'amber',
        default                                        => 'red',
    };

    $result = array_merge(kpi_empty_result($label, 'g/L'), [
        'value' => $avgCo2,
        'tint'  => $tint,
        'meta'  => [
            'period_label'       => $p['label'],
            'rack_count'         => $row ? (int)   $row['rack_count']         : 0,
            'min_co2'            => $row ? (float) $row['min_co2']            : null,
            'max_co2'            => $row ? (float) $row['max_co2']            : null,
            'out_of_range_count' => $row ? (int)   $row['out_of_range_count'] : 0,
            'target_range'       => "{$warnLo}–{$warnHi} g/L",
            'note'               => 'CO₂ mesuré BBT à la mise en fût; plage outlier ' . $outlierLo . '–' . $outlierHi . ' g/L',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}


// ═════════════════════════════════════════════════════════════════════════════
// HANDLER: packaging  (source_domain = 'packaging')
// Reads: bd_packaging_v2 for vendable-output metrics.
//        inv_consumption (post-24x-fix) for packaging material consumption.
// NOTE: prod_total_units (main run) + special_qty_units (parallel run) = total
//       vendable units. vendable_hl column is pre-computed in bd_packaging_v2.
//       tombstoned rows excluded (is_tombstoned=0).
// ═════════════════════════════════════════════════════════════════════════════

function kpi_handler_packaging(
    string $handler,
    array  $params,
    string $label,
    PDO    $pdo
): array {
    return match ($handler) {
        'hl_packaged_period'         => kpi_pkg_hl_packaged($params, $label, $pdo),
        'units_packaged_period'      => kpi_pkg_units_packaged($params, $label, $pdo),
        'packaging_runs_period'      => kpi_pkg_runs_period($params, $label, $pdo),
        'top_skus_packaged'          => kpi_pkg_top_skus($params, $label, $pdo),
        'format_mix_pct'             => kpi_pkg_format_mix($params, $label, $pdo),
        'parallel_white_label_volume' => kpi_pkg_white_label_volume($params, $label, $pdo),
        'packaging_yield_pct'        => kpi_pkg_yield_pct($params, $label, $pdo),
        'avg_throughput_packaging'   => kpi_pkg_avg_throughput($params, $label, $pdo),
        'contract_packaging_volume'  => kpi_pkg_contract_volume($params, $label, $pdo),
        'volume_per_sku_period'      => kpi_pkg_volume_per_sku($params, $label, $pdo),
        'fg_added_inventory_period'  => kpi_pkg_fg_added($params, $label, $pdo),
        'fill_efficiency'            => kpi_pkg_fill_efficiency($params, $label, $pdo),
        'avg_losses_per_category'    => kpi_pkg_avg_losses_per_category($params, $label, $pdo),
        'avg_losses_per_sku'         => kpi_pkg_avg_losses_per_sku($params, $label, $pdo),
        'packaging_cost_per_unit'    => kpi_pkg_cost_per_unit($params, $label, $pdo),
        'packaging_material_consumption' => kpi_pkg_material_consumption($params, $label, $pdo),
        'daily_recap'                => kpi_pkg_daily_recap($label, $pdo),
        // no plan/schedule source yet — stub until tank-sim port ships
        'suggested_packaging_events' => kpi_stub_handler('packaging', $handler, $label),
        // no planned-vs-actual data source — stub until packaging schedule exists
        'packaging_deviations'       => kpi_stub_handler('packaging', $handler, $label),
        default                      => kpi_stub_handler('packaging', $handler, $label),
    };
}

/** #49 — HL packagés ce mois (vendable_hl from bd_packaging_v2) */
function kpi_pkg_hl_packaged(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_hl_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT ROUND(SUM(vendable_hl), 3) AS total_hl,
                COUNT(*) AS run_count,
                DATEDIFF(CURDATE(), MAX(event_date)) AS days_since_last
           FROM bd_packaging_v2
          WHERE is_tombstoned = 0
            AND event_date BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalHl = (float) ($row['total_hl'] ?? 0.0);

    $result = array_merge(kpi_empty_result($label, 'HL'), [
        'value' => round($totalHl, 1),
        'tint'  => 'neutral',
        'meta'  => [
            'period_label'    => $p['label'],
            'run_count'       => (int) ($row['run_count']       ?? 0),
            'days_since_last' => $row['days_since_last'] !== null ? (int) $row['days_since_last'] : null,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #48 — Unités packagées ce mois par format */
function kpi_pkg_units_packaged(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_units_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT run_type,
                SUM(COALESCE(prod_total_units, 0) + COALESCE(special_qty_units, 0)) AS units,
                ROUND(SUM(vendable_hl), 3) AS hl
           FROM bd_packaging_v2
          WHERE is_tombstoned = 0
            AND event_date BETWEEN ? AND ?
          GROUP BY run_type
          ORDER BY hl DESC"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalUnits = 0;
    $breakdown  = [];
    foreach ($rows as $r) {
        $u = (int) $r['units'];
        $totalUnits += $u;
        $breakdown[] = [
            'key'   => $r['run_type'],
            'label' => $r['run_type'],
            'value' => $u,
            'meta'  => ['hl' => (float) $r['hl']],
        ];
    }

    $result = array_merge(kpi_empty_result($label, 'unités'), [
        'value'     => $totalUnits,
        'tint'      => 'neutral',
        'breakdown' => $breakdown,
        'meta'      => ['period_label' => $p['label']],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #50 — Runs packaging ce mois + jours depuis dernier */
function kpi_pkg_runs_period(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_runs_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS run_count,
                DATEDIFF(CURDATE(), MAX(event_date)) AS days_since_last
           FROM bd_packaging_v2
          WHERE is_tombstoned = 0
            AND event_date BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $runCount      = (int) ($row['run_count']       ?? 0);
    $daysSinceLast = $row['days_since_last'] !== null ? (int) $row['days_since_last'] : null;

    $result = array_merge(kpi_empty_result($label, 'runs'), [
        'value' => $runCount,
        'tint'  => 'neutral',
        'meta'  => [
            'period_label'    => $p['label'],
            'days_since_last' => $daysSinceLast,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #51 — Top SKUs packagés (by HL, period) */
function kpi_pkg_top_skus(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $limit  = $params['limit']  ?? 5;
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_top_skus_{$p['start']}_{$p['end']}_{$limit}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE(rs.sku_code, p.run_type) AS sku_code,
                SUM(COALESCE(p.prod_total_units, 0) + COALESCE(p.special_qty_units, 0)) AS units,
                ROUND(SUM(p.vendable_hl), 3) AS hl
           FROM bd_packaging_v2 p
           LEFT JOIN ref_skus rs ON rs.id = p.sku_id_fk
          WHERE p.is_tombstoned = 0
            AND p.event_date BETWEEN ? AND ?
          GROUP BY COALESCE(rs.sku_code, p.run_type)
          ORDER BY hl DESC
          LIMIT ?"
    );
    $stmt->execute([$p['start'], $p['end'], $limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $topHl = !empty($rows) ? (float) $rows[0]['hl'] : null;

    $breakdown = array_map(fn($r) => [
        'key'   => $r['sku_code'],
        'label' => $r['sku_code'],
        'value' => (float) $r['hl'],
        'meta'  => ['units' => (int) $r['units']],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'HL'), [
        'value'     => $topHl,
        'tint'      => 'neutral',
        'breakdown' => $breakdown,
        'meta'      => ['period_label' => $p['label'], 'top_sku' => $rows[0]['sku_code'] ?? null],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #52 — Répartition formats % (keg/bouteille/canette) */
function kpi_pkg_format_mix(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_format_mix_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT run_type,
                ROUND(SUM(vendable_hl), 3) AS hl
           FROM bd_packaging_v2
          WHERE is_tombstoned = 0
            AND event_date BETWEEN ? AND ?
          GROUP BY run_type
          ORDER BY hl DESC"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalHl = array_sum(array_column($rows, 'hl'));
    $breakdown = array_map(fn($r) => [
        'key'   => $r['run_type'],
        'label' => $r['run_type'],
        'value' => $totalHl > 0 ? round((float)$r['hl'] / $totalHl * 100, 1) : 0.0,
        'meta'  => ['hl' => (float) $r['hl']],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, '%'), [
        'value'     => round($totalHl, 1),
        'tint'      => 'neutral',
        'breakdown' => $breakdown,
        'meta'      => ['period_label' => $p['label'], 'total_hl' => round($totalHl, 1)],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #53 — Volume parallel / marque blanche (white_label=1 vs main, rolling 3m) */
function kpi_pkg_white_label_volume(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_3m';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_wl_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT is_white_label,
                ROUND(SUM(vendable_hl), 3) AS hl,
                COUNT(*) AS run_count
           FROM bd_packaging_v2
          WHERE is_tombstoned = 0
            AND event_date BETWEEN ? AND ?
          GROUP BY is_white_label"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $wlHl   = 0.0;
    $nebHl  = 0.0;
    $wlRuns = 0;
    foreach ($rows as $r) {
        if ((int)$r['is_white_label'] === 1) {
            $wlHl   += (float) $r['hl'];
            $wlRuns += (int)   $r['run_count'];
        } else {
            $nebHl  += (float) $r['hl'];
        }
    }

    $result = array_merge(kpi_empty_result($label, 'HL'), [
        'value'     => round($wlHl, 1),
        'tint'      => 'neutral',
        'breakdown' => [
            ['key' => 'white_label', 'label' => 'Marque blanche', 'value' => round($wlHl, 1)],
            ['key' => 'nebuleuse',   'label' => 'Nébuleuse',      'value' => round($nebHl, 1)],
        ],
        'meta'      => [
            'period_label' => $p['label'],
            'wl_runs'      => $wlRuns,
            'wl_hl'        => round($wlHl, 1),
            'neb_hl'       => round($nebHl, 1),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #54 — Rendement packaging % (vendable / (vendable + pertes liquid)) */
function kpi_pkg_yield_pct(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_yield_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT ROUND(SUM(vendable_hl), 3) AS vendable_hl,
                ROUND(SUM(COALESCE(loss_kpi_hl, 0)), 3) AS loss_hl
           FROM bd_packaging_v2
          WHERE is_tombstoned = 0
            AND event_date BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $vendable = (float) ($row['vendable_hl'] ?? 0.0);
    $loss     = (float) ($row['loss_hl']     ?? 0.0);
    $gross    = $vendable + $loss;
    $yieldPct = $gross > 0 ? round(($vendable / $gross) * 100, 2) : null;

    $tint = match (true) {
        $yieldPct === null  => 'neutral',
        $yieldPct >= 97.0   => 'green',
        $yieldPct >= 94.0   => 'amber',
        default             => 'red',
    };

    $result = array_merge(kpi_empty_result($label, '%'), [
        'value' => $yieldPct,
        'tint'  => $tint,
        'meta'  => [
            'period_label' => $p['label'],
            'vendable_hl'  => round($vendable, 1),
            'loss_hl'      => round($loss, 3),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #58 — Débit moyen par run packaging (HL/run) */
function kpi_pkg_avg_throughput(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_3m';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_throughput_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS run_count,
                ROUND(AVG(vendable_hl), 3) AS avg_hl_per_run,
                ROUND(AVG(COALESCE(prod_total_units, 0) + COALESCE(special_qty_units, 0)), 0) AS avg_units_per_run
           FROM bd_packaging_v2
          WHERE is_tombstoned = 0
            AND event_date BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $result = array_merge(kpi_empty_result($label, 'HL/run'), [
        'value' => $row ? (float) $row['avg_hl_per_run'] : null,
        'tint'  => 'neutral',
        'meta'  => [
            'period_label'      => $p['label'],
            'run_count'         => $row ? (int)   $row['run_count']         : 0,
            'avg_units_per_run' => $row ? (float) $row['avg_units_per_run'] : null,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #56 — Volume packaging contractuel par client */
function kpi_pkg_contract_volume(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_contract_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE(white_label_name, 'Nébuleuse') AS client_label,
                is_white_label,
                ROUND(SUM(vendable_hl), 3) AS hl,
                COUNT(*) AS run_count
           FROM bd_packaging_v2
          WHERE is_tombstoned = 0
            AND event_date BETWEEN ? AND ?
          GROUP BY client_label, is_white_label
          ORDER BY hl DESC"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalHl    = array_sum(array_column($rows, 'hl'));
    $contractHl = 0.0;
    $breakdown  = [];
    foreach ($rows as $r) {
        if ((int)$r['is_white_label'] === 1) {
            $contractHl += (float) $r['hl'];
        }
        $breakdown[] = [
            'key'   => $r['client_label'],
            'label' => $r['client_label'],
            'value' => (float) $r['hl'],
            'meta'  => ['runs' => (int)$r['run_count'], 'is_white_label' => (int)$r['is_white_label']],
        ];
    }

    $result = array_merge(kpi_empty_result($label, 'HL'), [
        'value'     => round($contractHl, 1),
        'tint'      => 'neutral',
        'breakdown' => $breakdown,
        'meta'      => ['period_label' => $p['label'], 'total_hl' => round($totalHl, 1)],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #62 — Volume par SKU / mois */
function kpi_pkg_volume_per_sku(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_vol_sku_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE(rs.sku_code, CONCAT(p.run_type, '_unknown')) AS sku_code,
                p.run_type,
                SUM(COALESCE(p.prod_total_units, 0) + COALESCE(p.special_qty_units, 0)) AS units,
                ROUND(SUM(p.vendable_hl), 3) AS hl
           FROM bd_packaging_v2 p
           LEFT JOIN ref_skus rs ON rs.id = p.sku_id_fk
          WHERE p.is_tombstoned = 0
            AND p.event_date BETWEEN ? AND ?
          GROUP BY sku_code, p.run_type
          ORDER BY hl DESC"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalHl = array_sum(array_column($rows, 'hl'));
    $breakdown = array_map(fn($r) => [
        'key'   => $r['sku_code'],
        'label' => $r['sku_code'],
        'value' => (float) $r['hl'],
        'meta'  => ['units' => (int)$r['units'], 'run_type' => $r['run_type']],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'HL'), [
        'value'     => round($totalHl, 1),
        'tint'      => 'neutral',
        'breakdown' => $breakdown,
        'meta'      => ['period_label' => $p['label'], 'sku_count' => count($rows)],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #68 — PF ajoutés à l'inventaire / mois (vendable units + HL) */
function kpi_pkg_fg_added(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_fg_added_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT SUM(COALESCE(prod_total_units, 0) + COALESCE(special_qty_units, 0)) AS total_units,
                ROUND(SUM(vendable_hl), 3) AS total_hl
           FROM bd_packaging_v2
          WHERE is_tombstoned = 0
            AND event_date BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalUnits = (int)   ($row['total_units'] ?? 0);
    $totalHl    = (float) ($row['total_hl']    ?? 0.0);

    $result = array_merge(kpi_empty_result($label, 'unités'), [
        'value' => $totalUnits,
        'tint'  => 'neutral',
        'meta'  => [
            'period_label' => $p['label'],
            'total_hl'     => round($totalHl, 1),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #55 — Efficacité remplissage (réel vs théorique)
 *
 * Theoretical HL = SUM(units / units_per_pack × hl_per_unit).
 * prod_total_units stores individual bottles/cans; hl_per_unit is per sellable
 * pack unit (e.g. 24-bottle carton = 0.0792 HL). Dividing by units_per_pack
 * converts individual counts to pack units before multiplying by hl_per_unit.
 * Kegs and cuvs have units_per_pack=1, so the division is a no-op there.
 * Actual HL      = SUM(vendable_hl) from bd_packaging_v2.
 * Efficiency %   = actual / theoretical × 100.
 * Breakdown by run_type.
 */
function kpi_pkg_fill_efficiency(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_fill_eff_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // prod_total_units: individual unit count for this row's run (main OR parallel).
    // special_qty_units on parallel rows mirrors prod_total_units exactly (confirmed
    // against 70/70 parallel rows). Main rows have special_qty_units=0. Therefore
    // theoretical HL is derived from prod_total_units alone — not summed with special.
    $stmt = $pdo->prepare(
        "SELECT p.run_type,
                ROUND(SUM(p.vendable_hl), 3) AS actual_hl,
                ROUND(SUM(
                    COALESCE(p.prod_total_units, 0)
                    / NULLIF(COALESCE(s.units_per_pack, 1), 0)
                    * COALESCE(s.hl_per_unit, 0)
                ), 3) AS theoretical_hl
           FROM bd_packaging_v2 p
           LEFT JOIN ref_skus s ON s.id = p.sku_id_fk
          WHERE p.is_tombstoned = 0
            AND p.event_date BETWEEN ? AND ?
          GROUP BY p.run_type
          ORDER BY actual_hl DESC"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalActual      = 0.0;
    $totalTheoretical = 0.0;
    $breakdown        = [];
    foreach ($rows as $r) {
        $act  = (float) $r['actual_hl'];
        $theo = (float) $r['theoretical_hl'];
        $totalActual      += $act;
        $totalTheoretical += $theo;
        $pct = $theo > 0 ? round($act / $theo * 100, 2) : null;
        $breakdown[] = [
            'key'   => $r['run_type'],
            'label' => $r['run_type'],
            'value' => $pct,
            'meta'  => ['actual_hl' => $act, 'theoretical_hl' => $theo],
        ];
    }

    $overallPct = $totalTheoretical > 0
        ? round($totalActual / $totalTheoretical * 100, 2)
        : null;

    $tint = match (true) {
        $overallPct === null  => 'neutral',
        $overallPct >= 98.0   => 'green',
        $overallPct >= 95.0   => 'amber',
        default               => 'red',
    };

    $result = array_merge(kpi_empty_result($label, '%'), [
        'value'     => $overallPct,
        'tint'      => $tint,
        'breakdown' => $breakdown,
        'meta'      => [
            'period_label'    => $p['label'],
            'actual_hl'       => round($totalActual, 1),
            'theoretical_hl'  => round($totalTheoretical, 1),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #60 — Pertes moyennes par catégorie de perte (bd_packaging_v2 loss columns)
 *
 * Groups the discrete loss columns into descriptive categories:
 *   liquid   : loss_kpi_hl (liquid losses — fills, line, etc.)
 *   label    : loss_label_btl_units
 *   crown    : loss_crown_cork_units
 *   can_lid  : loss_can_lid_units
 *   container: loss_container_btl_units + loss_container_can_units
 *   4pack    : loss_4pack_btl_units + loss_4pack_can_units
 *   wrap     : loss_wrap_btl_units + loss_wrap_can_units
 *   keg_save : loss_keg_save_units
 * Values are totals (not averages) for the period, ordered by magnitude.
 * Liquid is in HL; packaging units are in units (mixed — noted in meta).
 */
function kpi_pkg_avg_losses_per_category(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_3m';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_loss_cat_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT
            ROUND(SUM(COALESCE(loss_kpi_hl, 0)), 3)                                          AS loss_liquid_hl,
            SUM(COALESCE(loss_label_btl_units, 0))                                            AS loss_label,
            SUM(COALESCE(loss_crown_cork_units, 0))                                           AS loss_crown,
            SUM(COALESCE(loss_can_lid_units, 0))                                              AS loss_can_lid,
            SUM(COALESCE(loss_container_btl_units, 0) + COALESCE(loss_container_can_units, 0)) AS loss_container,
            SUM(COALESCE(loss_4pack_btl_units, 0) + COALESCE(loss_4pack_can_units, 0))        AS loss_4pack,
            SUM(COALESCE(loss_wrap_btl_units, 0) + COALESCE(loss_wrap_can_units, 0))          AS loss_wrap,
            SUM(COALESCE(loss_keg_save_units, 0))                                              AS loss_keg_save,
            COUNT(*) AS run_cnt
           FROM bd_packaging_v2
          WHERE is_tombstoned = 0
            AND event_date BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int)$row['run_cnt'] === 0) {
        return array_merge(kpi_empty_result($label), [
            'meta' => ['period_label' => $p['label'], 'run_cnt' => 0],
        ]);
    }

    $runCnt = (int) $row['run_cnt'];

    $categories = [
        ['key' => 'liquid',    'label' => 'Liquide (HL)',    'value' => (float) $row['loss_liquid_hl'], 'unit' => 'HL'],
        ['key' => 'label',     'label' => 'Étiquettes',      'value' => (float) $row['loss_label'],     'unit' => 'u'],
        ['key' => 'crown',     'label' => 'Capsules',         'value' => (float) $row['loss_crown'],     'unit' => 'u'],
        ['key' => 'can_lid',   'label' => 'Couvercles canette','value' => (float) $row['loss_can_lid'],  'unit' => 'u'],
        ['key' => 'container', 'label' => 'Contenants',       'value' => (float) $row['loss_container'], 'unit' => 'u'],
        ['key' => '4pack',     'label' => '4-packs',          'value' => (float) $row['loss_4pack'],     'unit' => 'u'],
        ['key' => 'wrap',      'label' => 'Film étirable',    'value' => (float) $row['loss_wrap'],      'unit' => 'u'],
        ['key' => 'keg_save',  'label' => 'Sauv. kegs',       'value' => (float) $row['loss_keg_save'],  'unit' => 'u'],
    ];

    // Sort descending by value (mixed units — liquid HL intentionally first as largest signal)
    usort($categories, fn($a, $b) => $b['value'] <=> $a['value']);

    $topLoss = !empty($categories) ? $categories[0]['value'] : null;

    $result = array_merge(kpi_empty_result($label, 'u'), [
        'value'     => $topLoss,
        'tint'      => 'neutral',
        'breakdown' => $categories,
        'meta'      => [
            'period_label' => $p['label'],
            'run_cnt'      => $runCnt,
            'liquid_hl'    => (float) $row['loss_liquid_hl'],
            'note'         => 'Liquid en HL, packaging en unités',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #61 — Pertes moyennes par SKU (loss_kpi_hl grouped by sku_id_fk)
 *
 * Reports liquid loss in HL per SKU over the period, ordered by total loss desc.
 * loss_kpi_hl is the pre-computed liquid loss figure from bd_packaging_v2
 * (consistent with #54 packaging_yield_pct — same column).
 */
function kpi_pkg_avg_losses_per_sku(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_3m';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_loss_sku_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE(s.sku_code, CONCAT(p.run_type, '_unknown')) AS sku_code,
                p.run_type,
                COUNT(*) AS run_cnt,
                ROUND(SUM(COALESCE(p.loss_kpi_hl, 0)), 3) AS total_loss_hl,
                ROUND(AVG(COALESCE(p.loss_kpi_hl, 0)), 4) AS avg_loss_per_run_hl,
                ROUND(SUM(p.vendable_hl), 3) AS total_vendable_hl
           FROM bd_packaging_v2 p
           LEFT JOIN ref_skus s ON s.id = p.sku_id_fk
          WHERE p.is_tombstoned = 0
            AND p.event_date BETWEEN ? AND ?
          GROUP BY sku_code, p.run_type
          ORDER BY total_loss_hl DESC
          LIMIT 20"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalLoss = array_sum(array_column($rows, 'total_loss_hl'));

    $breakdown = array_map(fn($r) => [
        'key'   => $r['sku_code'],
        'label' => $r['sku_code'],
        'value' => (float) $r['total_loss_hl'],
        'meta'  => [
            'avg_per_run_hl'  => (float) $r['avg_loss_per_run_hl'],
            'run_cnt'         => (int)   $r['run_cnt'],
            'vendable_hl'     => (float) $r['total_vendable_hl'],
            'loss_pct'        => $r['total_vendable_hl'] > 0
                ? round((float)$r['total_loss_hl'] / ((float)$r['total_vendable_hl'] + (float)$r['total_loss_hl']) * 100, 2)
                : null,
        ],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'HL'), [
        'value'     => round($totalLoss, 2),
        'tint'      => 'neutral',
        'breakdown' => $breakdown,
        'meta'      => [
            'period_label' => $p['label'],
            'sku_count'    => count($rows),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #58 — Consommation matériaux packaging / mois (inv_consumption source_event='packaging')
 *
 * Reads inv_consumption rows with source_event='packaging'.
 * All rows are category='Packaging'; breakdown by MI name.
 * Returns total units consumed + breakdown by top 10 ingredients.
 */
function kpi_pkg_material_consumption(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'current_month';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_mat_cons_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Total consumed in period
    $stmt = $pdo->prepare(
        "SELECT SUM(c.qty) AS total_qty, COUNT(*) AS row_cnt
           FROM inv_consumption c
          WHERE c.source_event = 'packaging'
            AND c.consumed_at BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $agg = $stmt->fetch(PDO::FETCH_ASSOC);

    // Breakdown by MI name, top 10
    $stmt2 = $pdo->prepare(
        "SELECT m.mi_id, m.name AS mi_name,
                SUM(c.qty) AS total_qty,
                ANY_VALUE(c.unit) AS unit
           FROM inv_consumption c
           JOIN ref_mi m ON m.id = c.mi_id_fk
          WHERE c.source_event = 'packaging'
            AND c.consumed_at BETWEEN ? AND ?
          GROUP BY m.mi_id, m.name
          ORDER BY total_qty DESC
          LIMIT 10"
    );
    $stmt2->execute([$p['start'], $p['end']]);
    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $totalQty = (float) ($agg['total_qty'] ?? 0.0);

    $breakdown = array_map(fn($r) => [
        'key'   => $r['mi_id'],
        'label' => $r['mi_name'],
        'value' => (float) $r['total_qty'],
        'meta'  => ['unit' => $r['unit']],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'u'), [
        'value'     => round($totalQty, 0),
        'tint'      => 'neutral',
        'breakdown' => $breakdown,
        'meta'      => [
            'period_label' => $p['label'],
            'row_cnt'      => (int) ($agg['row_cnt'] ?? 0),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #66 — Coût packaging / unité → COGS (ref_sku_bom bom_source='packaging')
 *
 * Fiscal packaging cost per SKU from ref_sku_bom.cost (pre-computed WAC ×
 * qty_per_unit), bom_source='packaging'. This is the same basis used by the
 * COP/COGS tiles — SUM(cost) over all packaging-BOM lines for a SKU gives
 * the total packaging material cost per sellable unit.
 *
 * Period filtering: shows only SKUs that had at least one packaging run in the
 * requested period (inner join to bd_packaging_v2 for the period).
 * Breakdown by sku_code, ordered by packaging cost descending.
 */
function kpi_pkg_cost_per_unit(array $params, string $label, PDO $pdo): array
{
    $period = $params['period'] ?? 'rolling_3m';
    $p = kpi_resolve_period($period);

    $cacheKey = "pkg_cost_pu_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // SKUs active in the period
    $stmt = $pdo->prepare(
        "SELECT DISTINCT p.sku_id_fk
           FROM bd_packaging_v2 p
          WHERE p.is_tombstoned = 0
            AND p.sku_id_fk IS NOT NULL
            AND p.event_date BETWEEN ? AND ?"
    );
    $stmt->execute([$p['start'], $p['end']]);
    $activeSku = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($activeSku)) {
        return array_merge(kpi_empty_result($label, 'CHF/u'), [
            'meta' => ['period_label' => $p['label'], 'sku_count' => 0],
        ]);
    }

    // Placeholders for IN clause — all are integer IDs from the DB, safe
    $placeholders = implode(',', array_fill(0, count($activeSku), '?'));

    $stmt2 = $pdo->prepare(
        "SELECT s.sku_code,
                ROUND(SUM(b.cost), 4) AS pkg_cost_per_unit,
                COUNT(*) AS bom_line_cnt
           FROM ref_sku_bom b
           JOIN ref_skus s ON s.id = b.sku_id
          WHERE b.bom_source = 'packaging'
            AND b.sku_id IN ({$placeholders})
          GROUP BY s.sku_code
          ORDER BY pkg_cost_per_unit DESC"
    );
    $stmt2->execute($activeSku);
    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $topCost = !empty($rows) ? (float) $rows[0]['pkg_cost_per_unit'] : null;

    $breakdown = array_map(fn($r) => [
        'key'   => $r['sku_code'],
        'label' => $r['sku_code'],
        'value' => (float) $r['pkg_cost_per_unit'],
        'meta'  => ['bom_lines' => (int) $r['bom_line_cnt']],
    ], $rows);

    $result = array_merge(kpi_empty_result($label, 'CHF/u'), [
        'value'     => $topCost,
        'tint'      => 'neutral',
        'breakdown' => $breakdown,
        'meta'      => [
            'period_label' => $p['label'],
            'sku_count'    => count($rows),
            'note'         => 'Coût matériaux packaging par unité vendue (BOM packaging, base WAC)',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}


/** #271 — Packaging résumé du jour (recap)
 *
 * Today's packaging activity from bd_packaging_v2 (base table) + the vendable
 * view, for base rows only (reuses_packaging_id_fk IS NULL, is_tombstoned=0).
 *
 * beer_loss_hl formula (CANONICAL per §PERTES-DASHBOARD):
 *   beer_loss_hl = v.loss_kpi_hl + (b.loss_liquid_other_units / 100)
 *   beer_loss_pct = 100 * beer_loss_hl / v.vendable_hl
 * The loss_liquid_other_units/100 term is MANDATORY — omit and the % is ~10× low.
 *
 * Per-material loss rates (1:1 consumables only — honest denominator):
 *   consumed ≈ good units produced + wasted (valid for label/crown_cork/can_lid/container).
 *   For 4pack/* and wrap/* → raw count + pending_rate flag (never fabricate a denominator).
 *   loss_keg_collar/loss_keg_save → historically 0, omitted when 0.
 *
 * keg/cuv branch: litre-native → loss_keg_liquid_l + taproom_keg_l, not unit-side.
 *
 * Sections: run count, vendable HL, beer loss %, material-loss headline.
 * Breakdown: per-run (recipe/run_type) lines + per-material loss rows.
 */
function kpi_pkg_daily_recap(string $label, PDO $pdo): array
{
    $today    = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    $cacheKey = "pkg_daily_recap_{$today}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Per-run summary: join base table (has event_date, loss cols, recipe_id_fk, run_type)
    // with the vendable view (has vendable_hl, loss_kpi_hl). Filter on base table.
    $stmtRuns = $pdo->prepare(
        "SELECT b.id,
                b.run_type,
                b.recipe_id_fk,
                COALESCE(rr.name, 'Inconnu')              AS recipe_name,
                COALESCE(rr.classification, 'Neb')        AS classification,
                v.vendable_hl,
                v.loss_kpi_hl,
                b.loss_liquid_other_units,
                b.prod_total_units,
                /* per-material 1:1 losses (unit-side; bot/can/can33 only) */
                b.loss_label_btl_units,
                b.loss_crown_cork_units,
                b.loss_can_lid_units,
                b.loss_container_btl_units,
                b.loss_container_can_units,
                /* 4pack / wrap — non-1:1: raw counts + pending_rate */
                b.loss_4pack_btl_units,
                b.loss_4pack_can_units,
                b.loss_wrap_btl_units,
                b.loss_wrap_can_units,
                /* keg/cuv litre-side */
                b.loss_keg_liquid_l,
                b.taproom_keg_l,
                /* keg collars — historically 0 but kept for completeness */
                b.loss_keg_collar_units,
                b.loss_keg_save_units
           FROM bd_packaging_v2 b
           JOIN v_bd_packaging_v2_vendable v ON v.id = b.id
           LEFT JOIN ref_recipes rr ON rr.id = b.recipe_id_fk
          WHERE b.is_tombstoned = 0
            AND b.reuses_packaging_id_fk IS NULL
            AND b.event_date = ?
          ORDER BY b.id ASC"
    );
    $stmtRuns->execute([$today]);
    $runs = $stmtRuns->fetchAll(PDO::FETCH_ASSOC);

    if (empty($runs)) {
        $result = array_merge(kpi_empty_result($label, 'runs'), [
            'value'  => 0,
            'tint'   => 'neutral',
            'meta'   => [
                'sections'    => [['label' => 'Runs aujourd\'hui', 'value' => 0, 'unit' => 'runs', 'tint' => 'neutral']],
                'period_label' => $today,
                'today'        => $today,
            ],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    // Aggregate totals
    $totalVendableHl = 0.0;
    $totalBeerLossHl = 0.0;
    $runCount        = count($runs);

    // Material loss accumulators
    $matLabel     = 0;  // loss_label_btl_units
    $matCrown     = 0;  // loss_crown_cork_units  — 1:1 with bottle
    $matCanLid    = 0;  // loss_can_lid_units      — 1:1 with can
    $matContBtl   = 0;  // loss_container_btl_units
    $matContCan   = 0;  // loss_container_can_units
    $mat4packBtl  = 0;  // loss_4pack_btl_units   — non-1:1
    $mat4packCan  = 0;  // loss_4pack_can_units   — non-1:1
    $matWrapBtl   = 0;  // loss_wrap_btl_units    — non-1:1
    $matWrapCan   = 0;  // loss_wrap_can_units    — non-1:1
    $matKegLiqL   = 0.0; // loss_keg_liquid_l (litres)
    $matKegCollar = 0;  // loss_keg_collar_units (historically 0)

    // For 1:1 rate denominators
    $prodUnitsBtl = 0; // prod units for bottle runs
    $prodUnitsCan = 0; // prod units for can/can33 runs

    // Per-run breakdown rows
    $perRunRows = [];

    foreach ($runs as $r) {
        $vendHl    = (float) ($r['vendable_hl'] ?? 0.0);
        $lossKpiHl = (float) ($r['loss_kpi_hl'] ?? 0.0);
        $lossOther = (float) ($r['loss_liquid_other_units'] ?? 0.0);
        // Canonical beer loss formula from §PERTES-DASHBOARD:
        $beerLossHl = $lossKpiHl + ($lossOther / 100.0);
        $runType    = $r['run_type'] ?? 'bot';

        $totalVendableHl += $vendHl;
        $totalBeerLossHl += $beerLossHl;

        // Accumulate material losses
        $matLabel     += (int) ($r['loss_label_btl_units']    ?? 0);
        $matCrown     += (int) ($r['loss_crown_cork_units']    ?? 0);
        $matCanLid    += (int) ($r['loss_can_lid_units']       ?? 0);
        $matContBtl   += (int) ($r['loss_container_btl_units'] ?? 0);
        $matContCan   += (int) ($r['loss_container_can_units'] ?? 0);
        $mat4packBtl  += (int) ($r['loss_4pack_btl_units']     ?? 0);
        $mat4packCan  += (int) ($r['loss_4pack_can_units']     ?? 0);
        $matWrapBtl   += (int) ($r['loss_wrap_btl_units']      ?? 0);
        $matWrapCan   += (int) ($r['loss_wrap_can_units']      ?? 0);
        $matKegLiqL   += (float) ($r['loss_keg_liquid_l']      ?? 0.0);
        $matKegCollar += (int)   ($r['loss_keg_collar_units']  ?? 0);

        if (in_array($runType, ['bot'], true)) {
            $prodUnitsBtl += (int) ($r['prod_total_units'] ?? 0);
        } elseif (in_array($runType, ['can', 'can33'], true)) {
            $prodUnitsCan += (int) ($r['prod_total_units'] ?? 0);
        }

        // Per-run row for breakdown
        $runLossPct = ($vendHl > 0) ? round(100.0 * $beerLossHl / $vendHl, 1) : null;
        $perRunRows[] = [
            'key'   => 'run_' . $r['id'],
            'label' => $r['recipe_name'],
            'value' => round($vendHl, 1),
            'unit'  => 'HL',
            'meta'  => [
                'run_type'    => $runType,
                'loss_pct'    => $runLossPct,
                'beer_loss_hl'=> round($beerLossHl, 3),
            ],
        ];
    }

    $totalVendableHl = round($totalVendableHl, 1);
    $totalBeerLossHl = round($totalBeerLossHl, 3);
    $beerLossPct     = ($totalVendableHl > 0)
        ? round(100.0 * $totalBeerLossHl / $totalVendableHl, 1)
        : null;

    // Material loss breakdown rows (1:1 rates, non-1:1 raw, suppress 0-values)
    $matRows = [];

    // 1:1 consumables — honest rate: wasted / (good + wasted)
    // Étiquettes (label_btl)
    if ($matLabel > 0) {
        $denom = $prodUnitsBtl + $matLabel;
        $matRows[] = [
            'key'   => 'mat_label',
            'label' => 'Étiquettes',
            'value' => $matLabel,
            'unit'  => 'u',
            'meta'  => ['rate_pct' => $denom > 0 ? round(100.0 * $matLabel / $denom, 1) : null, 'rate_type' => '1:1'],
        ];
    }
    // Capsules (crown corks) — 1:1 with bottle
    if ($matCrown > 0) {
        $denom = $prodUnitsBtl + $matCrown;
        $matRows[] = [
            'key'   => 'mat_crown',
            'label' => 'Capsules',
            'value' => $matCrown,
            'unit'  => 'u',
            'meta'  => ['rate_pct' => $denom > 0 ? round(100.0 * $matCrown / $denom, 1) : null, 'rate_type' => '1:1'],
        ];
    }
    // Couvercles canette — 1:1 with can
    if ($matCanLid > 0) {
        $denom = $prodUnitsCan + $matCanLid;
        $matRows[] = [
            'key'   => 'mat_can_lid',
            'label' => 'Couvercles canette',
            'value' => $matCanLid,
            'unit'  => 'u',
            'meta'  => ['rate_pct' => $denom > 0 ? round(100.0 * $matCanLid / $denom, 1) : null, 'rate_type' => '1:1'],
        ];
    }
    // Bouteilles perdues (container_btl) — 1:1
    if ($matContBtl > 0) {
        $denom = $prodUnitsBtl + $matContBtl;
        $matRows[] = [
            'key'   => 'mat_cont_btl',
            'label' => 'Bouteilles',
            'value' => $matContBtl,
            'unit'  => 'u',
            'meta'  => ['rate_pct' => $denom > 0 ? round(100.0 * $matContBtl / $denom, 1) : null, 'rate_type' => '1:1'],
        ];
    }
    // Canettes perdues (container_can) — 1:1
    if ($matContCan > 0) {
        $denom = $prodUnitsCan + $matContCan;
        $matRows[] = [
            'key'   => 'mat_cont_can',
            'label' => 'Canettes',
            'value' => $matContCan,
            'unit'  => 'u',
            'meta'  => ['rate_pct' => $denom > 0 ? round(100.0 * $matContCan / $denom, 1) : null, 'rate_type' => '1:1'],
        ];
    }
    // 4-packs (non-1:1) — raw count + pending_rate flag
    if ($mat4packBtl > 0) {
        $matRows[] = [
            'key'   => 'mat_4pack_btl',
            'label' => 'Packs bouteille',
            'value' => $mat4packBtl,
            'unit'  => 'u',
            'meta'  => ['rate_type' => 'pending'],
        ];
    }
    if ($mat4packCan > 0) {
        $matRows[] = [
            'key'   => 'mat_4pack_can',
            'label' => 'Packs canette',
            'value' => $mat4packCan,
            'unit'  => 'u',
            'meta'  => ['rate_type' => 'pending'],
        ];
    }
    // Fardelage / wrap (non-1:1) — raw count + pending_rate flag
    if ($matWrapBtl > 0) {
        $matRows[] = [
            'key'   => 'mat_wrap_btl',
            'label' => 'Fardelage bouteille',
            'value' => $matWrapBtl,
            'unit'  => 'u',
            'meta'  => ['rate_type' => 'pending'],
        ];
    }
    if ($matWrapCan > 0) {
        $matRows[] = [
            'key'   => 'mat_wrap_can',
            'label' => 'Fardelage canette',
            'value' => $matWrapCan,
            'unit'  => 'u',
            'meta'  => ['rate_type' => 'pending'],
        ];
    }
    // Keg liquid loss (litres) — litre-native
    if ($matKegLiqL > 0.0) {
        $matRows[] = [
            'key'   => 'mat_keg_liq',
            'label' => 'Perte liquide fût (L)',
            'value' => round($matKegLiqL, 1),
            'unit'  => 'L',
            'meta'  => ['rate_type' => 'litre'],
        ];
    }
    // Keg collars — suppress when 0 (historically unused)
    if ($matKegCollar > 0) {
        $matRows[] = [
            'key'   => 'mat_keg_collar',
            'label' => 'Capuchons fût',
            'value' => $matKegCollar,
            'unit'  => 'u',
            'meta'  => ['rate_type' => '1:1'],
        ];
    }

    // Sections (headline metrics strip)
    $sections = [
        ['label' => 'Runs aujourd\'hui',  'value' => $runCount,         'unit' => 'runs', 'tint' => 'neutral'],
        ['label' => 'HL vendables',        'value' => $totalVendableHl,  'unit' => 'HL',   'tint' => 'neutral'],
        ['label' => 'Perte bière',         'value' => $beerLossPct,      'unit' => '%',    'tint' => ($beerLossPct !== null && $beerLossPct > 3.0) ? 'amber' : 'neutral'],
        ['label' => 'Perte bière (HL)',    'value' => $totalBeerLossHl,  'unit' => 'HL',   'tint' => 'neutral'],
    ];

    // Combined breakdown: per-run rows first, then material rows
    $breakdown = array_merge($perRunRows, $matRows);

    $result = array_merge(kpi_empty_result($label, 'runs'), [
        'value'     => $runCount,
        'tint'      => 'neutral',
        'breakdown' => $breakdown ?: null,
        'meta'      => [
            'sections'        => $sections,
            'period_label'    => $today,
            'run_count'       => $runCount,
            'vendable_hl'     => $totalVendableHl,
            'beer_loss_hl'    => $totalBeerLossHl,
            'beer_loss_pct'   => $beerLossPct,
            'today'           => $today,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}


// ═════════════════════════════════════════════════════════════════════════════
// HANDLER: tanks  (source_domain = 'tanks')
// SCOPE (this tranche):
//   - bd_tank_readings: direct reading aggregates (O2, deviations)
//   - Fermentation KPI mini-tranche (2026-06-07): avg_fermentation_time (#31),
//     cct_days_per_beer (#16), beers_fermenting_now (#18), tank_turns_period (#20).
//     Sources: bd_brewing_gravity_v2 (Cooling = pitch), bd_fermenting_v2
//     (ColdCrash = fermentation end per operator ruling), bd_racking_v2 (transfers),
//     ref_cct (active CCT count for turns-per-tank computation).
//     Join shape: (recipe_id_fk, batch) — NEVER on beer-name strings.
// ═════════════════════════════════════════════════════════════════════════════

function kpi_handler_tanks(
    string $handler,
    array  $params,
    string $label,
    PDO    $pdo
): array {
    return match ($handler) {
        'o2_in_bbt'               => kpi_tanks_o2_in_bbt($params, $label, $pdo),
        'o2_deviations'           => kpi_tanks_o2_deviations($params, $label, $pdo),
        // Fermentation KPI mini-tranche — live implementations:
        'avg_fermentation_time'   => kpi_tanks_avg_fermentation_time($label, $pdo),
        'cct_days_per_beer'       => kpi_tanks_cct_days_per_beer($label, $pdo),
        'beers_fermenting_now'    => kpi_tanks_beers_fermenting_now($label, $pdo),
        'tank_turns_period'       => kpi_tanks_tank_turns_period($params, $label, $pdo),
        // Phase 2b Batch 3 — readings/fermentation analytics:
        'garde_vs_target'         => kpi_tanks_garde_vs_target($label, $pdo),
        'cold_crash_in_progress'  => kpi_tanks_cold_crash_in_progress($label, $pdo),
        'fermentation_deviations' => kpi_tanks_fermentation_deviations($label, $pdo),
        'suggested_next_brew'     => kpi_tanks_suggested_next_brew($label, $pdo),
        'temp_pressure_excursions' => kpi_tanks_temp_pressure_excursions($label, $pdo),
        // Occupancy trackers: require Node tank-sim port — NOT built yet:
        'tank_occupancy'          => kpi_stub_handler('tanks', $handler, $label),
        'cct_utilization_pct'     => kpi_stub_handler('tanks', $handler, $label),
        'cct_idle_days'           => kpi_stub_handler('tanks', $handler, $label),
        'hl_in_tank_now'          => kpi_stub_handler('tanks', $handler, $label),
        default                   => kpi_stub_handler('tanks', $handler, $label),
    };
}

// ─── Fermentation KPI mini-tranche helpers ────────────────────────────────────

/**
 * Shared CTE fragment: first Cooling date per (recipe_id_fk, batch) from
 * bd_brewing_gravity_v2. Multi-brew batches fill a CCT over 1–3 days; the
 * FIRST Cooling event = first fill = effective pitch day.
 * Convention: submitted_at cast to DATE (the local calendar date the form
 * was submitted, which matches the brew day).
 */
function kpi_fermentation_cooling_cte(): string
{
    return "
        SELECT recipe_id_fk, batch,
               MIN(DATE(submitted_at)) AS first_cool_date
          FROM bd_brewing_gravity_v2
         WHERE event_type = 'Cooling'
           AND is_tombstoned = 0
         GROUP BY recipe_id_fk, batch
    ";
}

/**
 * Shared CTE fragment: first ColdCrash event_date per (recipe_id_fk, batch)
 * from bd_fermenting_v2. Operator ruling: fermentation END = start of cold crash.
 */
function kpi_fermentation_coldcrash_cte(): string
{
    return "
        SELECT recipe_id_fk, batch,
               MIN(event_date) AS cc_date
          FROM bd_fermenting_v2
         WHERE event_type = 'ColdCrash'
           AND is_tombstoned = 0
         GROUP BY recipe_id_fk, batch
    ";
}

/** #31 — Temps moyen de fermentation par bière (jours, rolling 12m of cold-crash events) */
function kpi_tanks_avg_fermentation_time(string $label, PDO $pdo): array
{
    $cacheKey = 'tanks_avg_ferm_time_12m';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Window: ColdCrash events in the rolling 12 months — these are the
    // "completed fermentations" we can measure. Batches still fermenting
    // (no ColdCrash yet) are excluded; coverage noted in meta.
    // Sanity filter: 0–120 days excludes obvious data-entry errors.
    $coolCte  = kpi_fermentation_cooling_cte();
    $crashCte = "
        SELECT recipe_id_fk, batch,
               MIN(event_date) AS cc_date
          FROM bd_fermenting_v2
         WHERE event_type = 'ColdCrash'
           AND is_tombstoned = 0
           AND event_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         GROUP BY recipe_id_fk, batch
    ";

    $stmt = $pdo->query("
        SELECT rr.name AS beer_name,
               ROUND(AVG(DATEDIFF(cc.cc_date, bw.first_cool_date)), 1) AS avg_days,
               COUNT(*) AS batch_count
          FROM ({$crashCte}) cc
          JOIN ({$coolCte}) bw
               ON bw.recipe_id_fk = cc.recipe_id_fk
              AND bw.batch = cc.batch
          JOIN ref_recipes rr ON rr.id = cc.recipe_id_fk
         WHERE DATEDIFF(cc.cc_date, bw.first_cool_date) BETWEEN 0 AND 120
         GROUP BY rr.name
         ORDER BY batch_count DESC, rr.name
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Weighted overall average (weight = batch_count per recipe).
    $totalBatches = 0;
    $weightedSum  = 0.0;
    $series       = [];
    foreach ($rows as $r) {
        $n             = (int)   $r['batch_count'];
        $d             = (float) $r['avg_days'];
        $weightedSum  += $d * $n;
        $totalBatches += $n;
        $series[]      = [
            'key'   => $r['beer_name'],
            'label' => $r['beer_name'],
            'value' => $d,
            'meta'  => ['batch_count' => $n],
        ];
    }
    $overallAvg = $totalBatches > 0 ? round($weightedSum / $totalBatches, 1) : null;

    $result = array_merge(kpi_empty_result($label, 'jours'), [
        'value'     => $overallAvg,
        'tint'      => 'neutral',
        'series'    => $series,
        'meta'      => [
            'period_label'    => '12 derniers mois (cold-crash enregistré)',
            'batch_count'     => $totalBatches,
            'recipe_count'    => count($rows),
            'convention'      => 'Début = 1er Cooling (1re introduction CCT); Fin = 1er ColdCrash',
            'coverage_note'   => 'Brassins sans ColdCrash enregistré (encore en fermentation ou données manquantes) exclus du calcul.',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #16 — Répartition jours CCT par bière (first Cooling → transfer date, rolling 12m of transfers) */
function kpi_tanks_cct_days_per_beer(string $label, PDO $pdo): array
{
    $cacheKey = 'tanks_cct_days_per_beer_12m';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // CCT residence = from first Cooling (pitch day) to transfer (racking event_date).
    // Window: racking events in rolling 12 months.
    // Join shape on bd_racking_v2: COALESCE(neb_recipe_id_fk, contract_recipe_id_fk)
    //   and COALESCE(NULLIF(neb_batch,''), contract_batch) — same pattern as racking handlers.
    // Sanity filter: 0–180 days.
    $coolCte = kpi_fermentation_cooling_cte();

    $stmt = $pdo->query("
        SELECT rr.name AS beer_name,
               SUM(DATEDIFF(r.event_date, bw.first_cool_date)) AS total_cct_days,
               COUNT(*)                                          AS batch_count,
               ROUND(AVG(DATEDIFF(r.event_date, bw.first_cool_date)), 1) AS avg_cct_days
          FROM bd_racking_v2 r
          JOIN ({$coolCte}) bw
               ON bw.recipe_id_fk = COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)
              AND bw.batch = COALESCE(NULLIF(r.neb_batch, ''), r.contract_batch)
          JOIN ref_recipes rr
               ON rr.id = COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)
         WHERE r.is_tombstoned = 0
           AND r.event_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
           AND DATEDIFF(r.event_date, bw.first_cool_date) BETWEEN 0 AND 180
         GROUP BY rr.name
         ORDER BY total_cct_days DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grandTotal = (int) array_sum(array_column($rows, 'total_cct_days'));
    $series     = [];
    foreach ($rows as $r) {
        $series[] = [
            'key'   => $r['beer_name'],
            'label' => $r['beer_name'],
            'value' => (int) $r['total_cct_days'],
            'meta'  => [
                'avg_cct_days' => (float) $r['avg_cct_days'],
                'batch_count'  => (int)   $r['batch_count'],
            ],
        ];
    }

    $result = array_merge(kpi_empty_result($label, 'jours-CCT'), [
        'value'  => $grandTotal,
        'tint'   => 'neutral',
        'series' => $series,
        'meta'   => [
            'period_label'  => '12 derniers mois (date de soutirage)',
            'recipe_count'  => count($rows),
            'value_meaning' => 'Total jours-CCT cumulés sur 12 mois (somme par bière)',
            'coverage_note' => 'Brassins encore en CCT (pas encore soutirés) non inclus dans ce total.',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #18 — Bières en fermentation + jours en cuve (batches with Cooling but no transfer yet) */
function kpi_tanks_beers_fermenting_now(string $label, PDO $pdo): array
{
    $cacheKey = 'tanks_beers_fermenting_now';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // A batch is "currently in CCT" if:
    //   1. It has a Cooling event in bd_brewing_gravity_v2 (is in / has entered a CCT)
    //   2. It has NO racking row in bd_racking_v2 (has not been transferred out)
    //   3. Its first Cooling date is within the last 120 days (guard against ancient
    //      data-entry gaps where a batch genuinely has no racking record).
    $coolCte = kpi_fermentation_cooling_cte();

    $stmt = $pdo->query("
        SELECT rr.name AS beer_name,
               bw.batch,
               DATEDIFF(CURDATE(), bw.first_cool_date) AS days_in_cct,
               bw.first_cool_date
          FROM ({$coolCte}) bw
          JOIN ref_recipes rr ON rr.id = bw.recipe_id_fk
         WHERE bw.first_cool_date >= DATE_SUB(CURDATE(), INTERVAL 120 DAY)
           AND NOT EXISTS (
               SELECT 1
                 FROM bd_racking_v2 r
                WHERE r.is_tombstoned = 0
                  AND COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk) = bw.recipe_id_fk
                  AND COALESCE(NULLIF(r.neb_batch, ''), r.contract_batch) = bw.batch
           )
         ORDER BY days_in_cct DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count  = count($rows);
    $series = [];
    foreach ($rows as $r) {
        $series[] = [
            'key'   => $r['beer_name'] . '-' . $r['batch'],
            'label' => $r['beer_name'] . ' #' . $r['batch'],
            'value' => (int) $r['days_in_cct'],
            'meta'  => [
                'batch'           => $r['batch'],
                'first_cool_date' => $r['first_cool_date'],
            ],
        ];
    }

    $tint = match (true) {
        $count === 0 => 'neutral',
        $count <= 5  => 'green',
        $count <= 12 => 'neutral',
        default      => 'amber',
    };

    $result = array_merge(kpi_empty_result($label, 'brassins'), [
        'value'  => $count,
        'tint'   => $tint,
        'series' => $series,
        'meta'   => [
            'period_label'  => 'maintenant',
            'window_note'   => 'Brassins dont le 1er Cooling date de moins de 120 jours et sans soutirage enregistré.',
            'coverage_note' => 'Brassins avec Cooling > 120 jours et sans soutirage sont exclus (données manquantes probables).',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #20 — Rotations cuves par mois (transfers to BBT = completed CCT cycles) */
function kpi_tanks_tank_turns_period(array $params, string $label, PDO $pdo): array
{
    $period   = $params['period'] ?? 'current_month';
    $p        = kpi_resolve_period($period);
    $cacheKey = "tanks_tank_turns_{$p['start']}_{$p['end']}";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Completed CCT cycle = transfer to BBT (racking_destination_type = 'BBT').
    // This is the dominant path (141/143 rackings in the last 12m go to BBT).
    // CCT-to-CCT transfers (1/143) are excluded — they represent a split/blend
    // operation, not a completed fermentation cycle.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS transfer_count
          FROM bd_racking_v2
         WHERE is_tombstoned = 0
           AND racking_destination_type = 'BBT'
           AND event_date BETWEEN ? AND ?
    ");
    $stmt->execute([$p['start'], $p['end']]);
    $transferCount = (int) $stmt->fetchColumn();

    // CCT count from ref_cct (active CCTs only).
    $cctCountStmt = $pdo->query("SELECT COUNT(*) FROM ref_cct WHERE status = 'active'");
    $activeCcts   = (int) $cctCountStmt->fetchColumn();

    // Turns per tank = transfers / active CCT count (meaningful only when > 0 CCTs).
    $turnsPerTank = ($activeCcts > 0 && $transferCount > 0)
                    ? round($transferCount / $activeCcts, 2)
                    : null;

    // Rolling 12m monthly series for sparkline.
    $seriesStmt = $pdo->query("
        SELECT DATE_FORMAT(event_date, '%Y-%m') AS mo,
               COUNT(*) AS cnt
          FROM bd_racking_v2
         WHERE is_tombstoned = 0
           AND racking_destination_type = 'BBT'
           AND event_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         GROUP BY mo
         ORDER BY mo
    ");
    $seriesRows = $seriesStmt->fetchAll(PDO::FETCH_ASSOC);
    $series     = array_map(
        fn($r) => ['period' => $r['mo'], 'value' => (int) $r['cnt']],
        $seriesRows
    );

    $result = array_merge(kpi_empty_result($label, 'transferts CCT→BBT'), [
        'value'  => $transferCount,
        'tint'   => 'neutral',
        'series' => $series,
        'meta'   => [
            'period_label'    => $p['label'],
            'active_cct_count' => $activeCcts,
            'turns_per_tank'  => $turnsPerTank,
            'value_meaning'   => 'Nombre de soutirages CCT→BBT (= cycles CCT terminés) sur la période',
            'turns_note'      => $activeCcts > 0
                                 ? "Rotations/cuve = {$transferCount} / {$activeCcts} CCT actives"
                                 : 'Nombre de CCT actives non disponible',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #34 — O₂ dissous en BBT (moyenne des N derniers jours, ppb) */
function kpi_tanks_o2_in_bbt(array $params, string $label, PDO $pdo): array
{
    $windowDays = $params['window_days'] ?? 30;

    $cacheKey = "tanks_o2_bbt_{$windowDays}d";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT ROUND(AVG(o2_ppb), 2) AS avg_o2_ppb,
                ROUND(MAX(o2_ppb), 2) AS max_o2_ppb,
                ROUND(MIN(o2_ppb), 2) AS min_o2_ppb,
                COUNT(*) AS reading_count,
                COUNT(DISTINCT DATE(read_date)) AS days_with_readings
           FROM bd_tank_readings
          WHERE o2_ppb IS NOT NULL
            AND read_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
    );
    $stmt->execute([$windowDays]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $avgO2 = $row && $row['reading_count'] > 0 ? (float) $row['avg_o2_ppb'] : null;

    // BBT finished-beer target: < 50 ppb. Red if > 100 ppb.
    $tint = match (true) {
        $avgO2 === null  => 'neutral',
        $avgO2 <= 25.0   => 'green',
        $avgO2 <= 60.0   => 'amber',
        default          => 'red',
    };

    $result = array_merge(kpi_empty_result($label, 'ppb O₂'), [
        'value' => $avgO2,
        'tint'  => $tint,
        'meta'  => [
            'period_label'       => "{$windowDays} derniers jours",
            'max_o2_ppb'         => $row ? (float) $row['max_o2_ppb']         : null,
            'min_o2_ppb'         => $row ? (float) $row['min_o2_ppb']         : null,
            'reading_count'      => $row ? (int)   $row['reading_count']      : 0,
            'days_with_readings' => $row ? (int)   $row['days_with_readings'] : 0,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/** #35 — Déviations O₂ (lectures > 50 ppb sur N jours) */
function kpi_tanks_o2_deviations(array $params, string $label, PDO $pdo): array
{
    $windowDays = $params['window_days'] ?? 30;
    $threshold  = 50.0; // ppb — above this is a deviation

    $cacheKey = "tanks_o2_dev_{$windowDays}d";
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total_readings,
                SUM(CASE WHEN o2_ppb > ? THEN 1 ELSE 0 END) AS deviation_count,
                ROUND(MAX(CASE WHEN o2_ppb > ? THEN o2_ppb ELSE NULL END), 2) AS max_deviation_ppb
           FROM bd_tank_readings
          WHERE o2_ppb IS NOT NULL
            AND read_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
    );
    $stmt->execute([$threshold, $threshold, $windowDays]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $devCount = (int) ($row['deviation_count'] ?? 0);
    $total    = (int) ($row['total_readings']  ?? 0);
    $devPct   = $total > 0 ? round(($devCount / $total) * 100, 1) : null;

    $tint = match (true) {
        $devCount === 0  => 'green',
        $devCount <= 3   => 'amber',
        default          => 'red',
    };

    $result = array_merge(kpi_empty_result($label, 'déviations'), [
        'value' => $devCount,
        'tint'  => $tint,
        'meta'  => [
            'period_label'      => "{$windowDays} derniers jours",
            'threshold_ppb'     => $threshold,
            'total_readings'    => $total,
            'deviation_pct'     => $devPct,
            'max_deviation_ppb' => $row['max_deviation_ppb'] !== null ? (float) $row['max_deviation_ppb'] : null,
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}


// ─── Phase 2b Batch 3 — readings/fermentation analytics ──────────────────────

/**
 * #19 — Garde / délai mise en fût vs cible
 *
 * Garde = days between first ColdCrash and BBT racking.
 * Target: ref_recipes.garde_days_min_override when set; otherwise the
 * default fallback of 14 days (sane minimum for most lager/clean ales;
 * no commissioning_settings key exists for this yet — stated in meta).
 * Window: BBT rackings in rolling 12 months that have a matching ColdCrash.
 */
function kpi_tanks_garde_vs_target(string $label, PDO $pdo): array
{
    $cacheKey = 'tanks_garde_vs_target_12m';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Default garde target when recipe has no override (no commissioning key):
    $defaultGardeDays = 14;

    // Pull per-recipe override so we can join it in PHP
    $recipeStmt = $pdo->query(
        "SELECT id, name, garde_days_min_override FROM ref_recipes WHERE is_active = 1"
    );
    $recipeRows   = $recipeStmt->fetchAll(PDO::FETCH_ASSOC);
    $gardeByRecipe = [];
    $nameByRecipe  = [];
    foreach ($recipeRows as $rr) {
        $rid = (int) $rr['id'];
        $gardeByRecipe[$rid] = $rr['garde_days_min_override'] !== null
            ? (int) $rr['garde_days_min_override']
            : $defaultGardeDays;
        $nameByRecipe[$rid]  = $rr['name'];
    }

    $stmt = $pdo->query("
        SELECT
            COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk) AS recipe_id,
            COALESCE(NULLIF(r.neb_batch, ''), r.contract_batch)    AS batch,
            r.event_date                                           AS rack_date,
            cc.cc_date
          FROM bd_racking_v2 r
          JOIN (
              SELECT recipe_id_fk, batch, MIN(event_date) AS cc_date
                FROM bd_fermenting_v2
               WHERE event_type = 'ColdCrash' AND is_tombstoned = 0
               GROUP BY recipe_id_fk, batch
          ) cc ON cc.recipe_id_fk = COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)
               AND cc.batch = COALESCE(NULLIF(r.neb_batch, ''), r.contract_batch)
         WHERE r.is_tombstoned = 0
           AND r.racking_destination_type = 'BBT'
           AND r.event_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
           AND DATEDIFF(r.event_date, cc.cc_date) >= 0
         ORDER BY r.event_date DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $series    = [];
    $shortCount = 0;
    $totalCount = 0;
    foreach ($rows as $r) {
        $rid    = (int) $r['recipe_id'];
        $target = $gardeByRecipe[$rid] ?? $defaultGardeDays;
        $actual = max(0, (int) round(
            (strtotime($r['rack_date']) - strtotime($r['cc_date'])) / 86400
        ));
        $delta  = $actual - $target;
        $beer   = $nameByRecipe[$rid] ?? "recipe_{$rid}";
        $totalCount++;
        if ($delta < 0) {
            $shortCount++;
        }
        $series[] = [
            'key'    => $beer . '-' . $r['batch'],
            'label'  => $beer . ' #' . $r['batch'],
            'value'  => $actual,
            'meta'   => [
                'target_days' => $target,
                'delta_days'  => $delta,
                'rack_date'   => $r['rack_date'],
                'cc_date'     => $r['cc_date'],
                'ok'          => $delta >= 0,
            ],
        ];
    }

    $shortPct = $totalCount > 0 ? round(($shortCount / $totalCount) * 100, 1) : null;
    $tint = match (true) {
        $shortCount === 0                   => 'green',
        $shortPct !== null && $shortPct < 10 => 'amber',
        default                             => 'red',
    };

    $result = array_merge(kpi_empty_result($label, 'brassins'), [
        'value'  => $shortCount,
        'tint'   => $tint,
        'series' => $series,
        'meta'   => [
            'period_label'    => '12 derniers mois (soutirages CCT→BBT)',
            'total_rackings'  => $totalCount,
            'short_garde_pct' => $shortPct,
            'default_target'  => $defaultGardeDays,
            'value_meaning'   => 'Nombre de soutirages avec garde inférieure à la cible (14j par défaut; override par recette possible via ref_recipes.garde_days_min_override)',
            'threshold_source' => 'ref_recipes.garde_days_min_override (NULL=0 recettes) → fallback 14j codé en dur (aucune clé commissioning_settings pour la garde à ce jour)',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #21 — Cold crash / dryhop en cours
 *
 * Counts batches that have entered ColdCrash (bd_fermenting_v2) in the last
 * 60 days but have NOT yet been racked to BBT. Also counts batches with a
 * recent DryHop event but no ColdCrash yet. Both are "attention" states that
 * the brewer needs to track.
 */
function kpi_tanks_cold_crash_in_progress(string $label, PDO $pdo): array
{
    $cacheKey = 'tanks_cold_crash_in_progress';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Batches in cold crash: have ColdCrash event (last 60d) but no BBT racking
    $ccStmt = $pdo->query("
        SELECT rr.name AS beer_name, cc.batch, cc.cc_date,
               DATEDIFF(CURDATE(), cc.cc_date) AS days_in_cc
          FROM (
              SELECT recipe_id_fk, batch, MIN(event_date) AS cc_date
                FROM bd_fermenting_v2
               WHERE event_type = 'ColdCrash' AND is_tombstoned = 0
                 AND event_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
               GROUP BY recipe_id_fk, batch
          ) cc
          JOIN ref_recipes rr ON rr.id = cc.recipe_id_fk
         WHERE NOT EXISTS (
             SELECT 1 FROM bd_racking_v2 r
              WHERE r.is_tombstoned = 0
                AND r.racking_destination_type = 'BBT'
                AND COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk) = cc.recipe_id_fk
                AND COALESCE(NULLIF(r.neb_batch, ''), r.contract_batch) = cc.batch
         )
         ORDER BY days_in_cc DESC
    ");
    $ccRows = $ccStmt->fetchAll(PDO::FETCH_ASSOC);

    // Batches in dry-hop: recent DryHop event (last 30d) but no ColdCrash yet
    $dhStmt = $pdo->query("
        SELECT rr.name AS beer_name, dh.batch, dh.last_dh_date,
               DATEDIFF(CURDATE(), dh.last_dh_date) AS days_since_dh
          FROM (
              SELECT recipe_id_fk, batch, MAX(event_date) AS last_dh_date
                FROM bd_fermenting_v2
               WHERE event_type = 'DryHop' AND is_tombstoned = 0
                 AND event_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
               GROUP BY recipe_id_fk, batch
          ) dh
          JOIN ref_recipes rr ON rr.id = dh.recipe_id_fk
         WHERE NOT EXISTS (
             SELECT 1 FROM bd_fermenting_v2 cc2
              WHERE cc2.is_tombstoned = 0
                AND cc2.event_type = 'ColdCrash'
                AND cc2.recipe_id_fk = dh.recipe_id_fk
                AND cc2.batch = dh.batch
         )
         ORDER BY days_since_dh ASC
    ");
    $dhRows = $dhStmt->fetchAll(PDO::FETCH_ASSOC);

    $ccCount = count($ccRows);
    $dhCount = count($dhRows);
    $total   = $ccCount + $dhCount;

    $seriesCC = array_map(fn($r) => [
        'key'    => $r['beer_name'] . '-' . $r['batch'] . '-cc',
        'label'  => $r['beer_name'] . ' #' . $r['batch'],
        'value'  => (int) $r['days_in_cc'],
        'meta'   => ['state' => 'cold_crash', 'since' => $r['cc_date']],
    ], $ccRows);

    $seriesDH = array_map(fn($r) => [
        'key'    => $r['beer_name'] . '-' . $r['batch'] . '-dh',
        'label'  => $r['beer_name'] . ' #' . $r['batch'],
        'value'  => (int) $r['days_since_dh'],
        'meta'   => ['state' => 'dry_hop', 'since' => $r['last_dh_date']],
    ], $dhRows);

    $tint = match (true) {
        $total === 0 => 'neutral',
        $total <= 3  => 'green',
        $total <= 8  => 'amber',
        default      => 'amber',
    };

    $result = array_merge(kpi_empty_result($label, 'brassins'), [
        'value'  => $total,
        'tint'   => $tint,
        'series' => array_merge($seriesCC, $seriesDH),
        'meta'   => [
            'period_label'    => 'maintenant',
            'cold_crash_count' => $ccCount,
            'dry_hop_count'    => $dhCount,
            'value_meaning'    => 'Cold crash en cours (ColdCrash sans soutirage BBT, ≤60j) + dry-hop en cours (DryHop sans ColdCrash, ≤30j)',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #22 — Déviations fermentation (DFE/atténuation/durée)
 *
 * Flags batches (last 12 months of completed fermentations) where at least
 * one of these deviations occurred:
 *
 *   A) Temperature excursion: any Reads row with temperature outside
 *      ref_recipes.ferm_temp_min_override / ferm_temp_max_override when set,
 *      else commissioning default: 0–25°C (wide; no global key yet — stated).
 *
 *   B) Gravity deviation: minimum observed FG (°Plato) > 20% of OG (°Plato),
 *      i.e. apparent attenuation < 80%. Threshold: hardcoded 80% apparent
 *      attenuation floor (industry standard for most ales/lagers).
 *      OG source: bd_brewing_gravity_v2 Cooling row final_gravity (per convention).
 *
 *   C) Fermentation duration outside expected range (< 5 days or > 45 days).
 *      No recipe-level overrides exist; defaults stated in meta.
 *
 * Source: bd_fermenting_v2 (Reads, ColdCrash), bd_brewing_gravity_v2 (Cooling).
 * Gravity is stored in °Plato (FW 14–22°P; FG 0–5°P typical).
 */
function kpi_tanks_fermentation_deviations(string $label, PDO $pdo): array
{
    $cacheKey = 'tanks_ferm_deviations_12m';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Threshold defaults (no commissioning_settings keys for these yet)
    $tempMinDefault  = 0.0;   // °C
    $tempMaxDefault  = 25.0;  // °C
    $attenFloor      = 0.80;  // 80% apparent attenuation minimum
    $fermMinDays     = 5;
    $fermMaxDays     = 45;

    // Per-recipe temperature overrides from ref_recipes
    $recipeStmt = $pdo->query(
        "SELECT id, name, ferm_temp_min_override, ferm_temp_max_override FROM ref_recipes"
    );
    $recipeRows = $recipeStmt->fetchAll(PDO::FETCH_ASSOC);
    $tempMin = [];
    $tempMax = [];
    $nameMap = [];
    foreach ($recipeRows as $rr) {
        $rid         = (int) $rr['id'];
        $nameMap[$rid] = $rr['name'];
        $tempMin[$rid] = $rr['ferm_temp_min_override'] !== null
            ? (float) $rr['ferm_temp_min_override'] : $tempMinDefault;
        $tempMax[$rid] = $rr['ferm_temp_max_override'] !== null
            ? (float) $rr['ferm_temp_max_override'] : $tempMaxDefault;
    }

    // A) Temperature excursions: batches with at least one out-of-range Reads row
    //    in the last 12 months of ColdCrash events (completed fermentations)
    $tempStmt = $pdo->query("
        SELECT f.recipe_id_fk,
               f.batch,
               MIN(f.temperature) AS min_temp,
               MAX(f.temperature) AS max_temp,
               COUNT(*) AS read_count
          FROM bd_fermenting_v2 f
         WHERE f.event_type = 'Reads'
           AND f.is_tombstoned = 0
           AND f.temperature IS NOT NULL
           AND f.recipe_id_fk IS NOT NULL
           AND EXISTS (
               SELECT 1 FROM bd_fermenting_v2 cc
                WHERE cc.recipe_id_fk = f.recipe_id_fk
                  AND cc.batch = f.batch
                  AND cc.event_type = 'ColdCrash'
                  AND cc.is_tombstoned = 0
                  AND cc.event_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
           )
         GROUP BY f.recipe_id_fk, f.batch
    ");
    $tempRows = $tempStmt->fetchAll(PDO::FETCH_ASSOC);

    // B) Attenuation: OG from bd_brewing_gravity_v2 Cooling, FG from min Reads gravity
    $attStmt = $pdo->query("
        SELECT og.recipe_id_fk,
               og.batch,
               og.og_plato,
               fg.min_fg_plato,
               fg.read_count
          FROM (
              SELECT recipe_id_fk, batch, MAX(final_gravity) AS og_plato
                FROM bd_brewing_gravity_v2
               WHERE event_type = 'Cooling' AND is_tombstoned = 0
                 AND final_gravity > 0
               GROUP BY recipe_id_fk, batch
          ) og
          JOIN (
              SELECT recipe_id_fk, batch,
                     MIN(gravity) AS min_fg_plato,
                     COUNT(*) AS read_count
                FROM bd_fermenting_v2
               WHERE event_type = 'Reads' AND is_tombstoned = 0
                 AND gravity IS NOT NULL AND gravity > 0
               GROUP BY recipe_id_fk, batch
          ) fg ON fg.recipe_id_fk = og.recipe_id_fk AND fg.batch = og.batch
         WHERE EXISTS (
             SELECT 1 FROM bd_fermenting_v2 cc
              WHERE cc.recipe_id_fk = og.recipe_id_fk
                AND cc.batch = og.batch
                AND cc.event_type = 'ColdCrash'
                AND cc.is_tombstoned = 0
                AND cc.event_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         )
           AND og.og_plato > 4
    ");
    $attRows = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    // C) Fermentation duration deviations (reuse shared CTEs logic inline)
    $durStmt = $pdo->query("
        SELECT cc.recipe_id_fk, cc.batch,
               DATEDIFF(cc.cc_date, bw.first_cool_date) AS ferm_days
          FROM (
              SELECT recipe_id_fk, batch, MIN(event_date) AS cc_date
                FROM bd_fermenting_v2
               WHERE event_type = 'ColdCrash' AND is_tombstoned = 0
                 AND event_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
               GROUP BY recipe_id_fk, batch
          ) cc
          JOIN (
              SELECT recipe_id_fk, batch, MIN(DATE(submitted_at)) AS first_cool_date
                FROM bd_brewing_gravity_v2
               WHERE event_type = 'Cooling' AND is_tombstoned = 0
               GROUP BY recipe_id_fk, batch
          ) bw ON bw.recipe_id_fk = cc.recipe_id_fk AND bw.batch = cc.batch
    ");
    $durRows = $durStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build deviation sets, keyed by (recipe_id, batch)
    $deviations = []; // key => ['temp'=>bool, 'atten'=>bool, 'dur'=>bool, ...]

    foreach ($tempRows as $r) {
        $rid    = (int) $r['recipe_id_fk'];
        $key    = "{$rid}:{$r['batch']}";
        $minT   = (float) $r['min_temp'];
        $maxT   = (float) $r['max_temp'];
        $tMin   = $tempMin[$rid] ?? $tempMinDefault;
        $tMax   = $tempMax[$rid] ?? $tempMaxDefault;
        if ($minT < $tMin || $maxT > $tMax) {
            $deviations[$key] = ($deviations[$key] ?? [
                'recipe_id' => $rid,
                'batch'     => $r['batch'],
                'beer'      => $nameMap[$rid] ?? "recipe_{$rid}",
                'flags'     => [],
            ]);
            $deviations[$key]['flags'][]      = 'temp';
            $deviations[$key]['temp_min_obs'] = $minT;
            $deviations[$key]['temp_max_obs'] = $maxT;
            $deviations[$key]['temp_target']  = "{$tMin}–{$tMax}°C";
        }
    }

    foreach ($attRows as $r) {
        $rid     = (int) $r['recipe_id_fk'];
        $key     = "{$rid}:{$r['batch']}";
        $og      = (float) $r['og_plato'];
        $fg      = (float) $r['min_fg_plato'];
        // Apparent attenuation in Plato: (OG - FG) / OG
        $atten   = $og > 0 ? ($og - $fg) / $og : null;
        if ($atten !== null && $atten < $attenFloor) {
            if (!isset($deviations[$key])) {
                $deviations[$key] = [
                    'recipe_id' => $rid,
                    'batch'     => $r['batch'],
                    'beer'      => $nameMap[$rid] ?? "recipe_{$rid}",
                    'flags'     => [],
                ];
            }
            $deviations[$key]['flags'][]   = 'attenuation';
            $deviations[$key]['og_plato']  = $og;
            $deviations[$key]['fg_plato']  = $fg;
            $deviations[$key]['atten_pct'] = round($atten * 100, 1);
        }
    }

    foreach ($durRows as $r) {
        $rid  = (int) $r['recipe_id_fk'];
        $key  = "{$rid}:{$r['batch']}";
        $days = (int) $r['ferm_days'];
        if ($days < $fermMinDays || $days > $fermMaxDays) {
            if (!isset($deviations[$key])) {
                $deviations[$key] = [
                    'recipe_id' => $rid,
                    'batch'     => $r['batch'],
                    'beer'      => $nameMap[$rid] ?? "recipe_{$rid}",
                    'flags'     => [],
                ];
            }
            $deviations[$key]['flags'][]    = 'duration';
            $deviations[$key]['ferm_days']  = $days;
            $deviations[$key]['dur_target'] = "{$fermMinDays}–{$fermMaxDays}j";
        }
    }

    $devCount = count($deviations);
    $series   = [];
    foreach ($deviations as $key => $d) {
        $flagStr = implode('+', array_unique($d['flags']));
        $series[] = [
            'key'   => $key,
            'label' => $d['beer'] . ' #' . $d['batch'],
            'value' => count(array_unique($d['flags'])),
            'meta'  => array_diff_key($d, array_flip(['recipe_id', 'batch', 'beer'])),
        ];
    }

    $tint = match (true) {
        $devCount === 0 => 'green',
        $devCount <= 3  => 'amber',
        default         => 'red',
    };

    $result = array_merge(kpi_empty_result($label, 'brassins'), [
        'value'  => $devCount,
        'tint'   => $tint,
        'series' => array_values($series),
        'meta'   => [
            'period_label'       => '12 derniers mois (brassins avec ColdCrash enregistré)',
            'value_meaning'      => 'Brassins avec au moins une déviation (temp / atténuation / durée)',
            'temp_threshold'     => "Défaut: {$tempMinDefault}–{$tempMaxDefault}°C (override: ref_recipes.ferm_temp_min/max_override; 0 recettes avec override actif)",
            'atten_threshold'    => "Atténuation apparente < " . ($attenFloor * 100) . "% (seuil codé en dur; °Plato source: bd_fermenting_v2 Reads + bd_brewing_gravity_v2 Cooling)",
            'duration_threshold' => "Durée fermentation < {$fermMinDays}j ou > {$fermMaxDays}j (seuil codé en dur; pas de clé commissioning_settings)",
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #23 — Prochaines bières à brasser (suggestion)
 *
 * "Brew next" suggestions based on recency gap: active recipes whose most
 * recent Cooling event (= last brew) is the oldest relative to the recipe's
 * active_window_months setting. Not fiscal. Not a firewall — purely informational.
 * Returns the top 10 candidates sorted by days since last brew (descending).
 */
function kpi_tanks_suggested_next_brew(string $label, PDO $pdo): array
{
    $cacheKey = 'tanks_suggested_next_brew';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // active_window_months from commissioning_settings — default 12
    $windowStmt = $pdo->query(
        "SELECT value_num FROM commissioning_settings
          WHERE section = 'recipe_lifecycle' AND key_name = 'active_window_months'
          LIMIT 1"
    );
    $windowRow    = $windowStmt->fetch(PDO::FETCH_ASSOC);
    $windowMonths = $windowRow ? (int) $windowRow['value_num'] : 12;

    $stmt = $pdo->prepare("
        SELECT rr.id AS recipe_id,
               rr.name AS beer_name,
               rr.subtype,
               MAX(DATE(bg.submitted_at)) AS last_brew_date,
               DATEDIFF(CURDATE(), MAX(DATE(bg.submitted_at))) AS days_since_last_brew,
               COUNT(DISTINCT bg.batch) AS total_batches
          FROM ref_recipes rr
          JOIN bd_brewing_gravity_v2 bg
               ON bg.recipe_id_fk = rr.id
              AND bg.event_type = 'Cooling'
              AND bg.is_tombstoned = 0
         WHERE rr.is_active = 1
           AND rr.lifecycle_hint = 'auto'
           AND rr.classification = 'Neb'
           AND rr.subtype IN ('Core', 'EPH', 'CollabIn')
           AND bg.submitted_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
         GROUP BY rr.id, rr.name, rr.subtype
         ORDER BY days_since_last_brew DESC
         LIMIT 10
    ");
    $stmt->execute([$windowMonths]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $series = array_map(fn($r) => [
        'key'   => 'recipe_' . $r['recipe_id'],
        'label' => $r['beer_name'],
        'value' => (int) $r['days_since_last_brew'],
        'meta'  => [
            'last_brew_date'      => $r['last_brew_date'],
            'total_batches'       => (int) $r['total_batches'],
            'subtype'             => $r['subtype'],
        ],
    ], $rows);

    $topCandidate = !empty($rows) ? $rows[0]['beer_name'] : null;

    $result = array_merge(kpi_empty_result($label, 'recettes'), [
        'value'  => count($rows),
        'tint'   => 'neutral',
        'series' => $series,
        'meta'   => [
            'period_label'   => "Recettes actives ({$windowMonths} derniers mois)",
            'top_candidate'  => $topCandidate,
            'value_meaning'  => 'Recettes Neb actives classées par ancienneté du dernier brassin (Core/EPH/CollabIn uniquement; informatif — ne remplace pas la planification du brasseur)',
            'window_source'  => "commissioning_settings recipe_lifecycle.active_window_months = {$windowMonths}",
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

/**
 * #24 — Excursions température/pression (bd_fermenting_v2 Reads + Purge)
 *
 * Temperature excursions: Reads rows outside recipe ferm_temp window
 * (ref_recipes.ferm_temp_min/max_override; fallback: 0–25°C — no
 * commissioning_settings key exists yet for a global default, stated in meta).
 *
 * Pressure excursions (Purge): purge_pressure_bar outside commissioning
 * qc_pressure_warn_lo / qc_pressure_warn_hi (0.8–2.5 bar, from migration_182).
 * NOTE: purge_pressure_bar has 0 non-NULL values in current data — handler
 * returns 0 for pressure until operators start filling this field.
 *
 * Window: rolling 30 days.
 */
function kpi_tanks_temp_pressure_excursions(string $label, PDO $pdo): array
{
    $cacheKey = 'tanks_temp_pressure_excursions_30d';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Load pressure thresholds from commissioning_settings
    $csStmt = $pdo->query(
        "SELECT key_name, value_num FROM commissioning_settings
          WHERE section = 'qc_thresholds'
            AND key_name IN ('qc_pressure_warn_lo', 'qc_pressure_warn_hi')
            AND is_active = 1"
    );
    $cs = [];
    foreach ($csStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cs[$row['key_name']] = (float) $row['value_num'];
    }
    $pressWarnLo = $cs['qc_pressure_warn_lo'] ?? 0.8;
    $pressWarnHi = $cs['qc_pressure_warn_hi'] ?? 2.5;

    // Per-recipe temperature bounds
    $recipeStmt = $pdo->query(
        "SELECT id, name, ferm_temp_min_override, ferm_temp_max_override FROM ref_recipes"
    );
    $recipeRows = $recipeStmt->fetchAll(PDO::FETCH_ASSOC);
    $tempMin = [];
    $tempMax = [];
    $nameMap = [];
    $tempMinDefault = 0.0;
    $tempMaxDefault = 25.0;
    foreach ($recipeRows as $rr) {
        $rid         = (int) $rr['id'];
        $nameMap[$rid] = $rr['name'];
        $tempMin[$rid] = $rr['ferm_temp_min_override'] !== null
            ? (float) $rr['ferm_temp_min_override'] : $tempMinDefault;
        $tempMax[$rid] = $rr['ferm_temp_max_override'] !== null
            ? (float) $rr['ferm_temp_max_override'] : $tempMaxDefault;
    }

    // Temperature excursion rows in last 30 days
    $tempStmt = $pdo->query("
        SELECT f.recipe_id_fk, f.batch, f.event_date,
               f.temperature
          FROM bd_fermenting_v2 f
         WHERE f.event_type = 'Reads'
           AND f.is_tombstoned = 0
           AND f.temperature IS NOT NULL
           AND f.recipe_id_fk IS NOT NULL
           AND f.event_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $tempRows = $tempStmt->fetchAll(PDO::FETCH_ASSOC);

    $tempExcursions = [];
    foreach ($tempRows as $r) {
        $rid   = (int) $r['recipe_id_fk'];
        $temp  = (float) $r['temperature'];
        $tMin  = $tempMin[$rid] ?? $tempMinDefault;
        $tMax  = $tempMax[$rid] ?? $tempMaxDefault;
        if ($temp < $tMin || $temp > $tMax) {
            $key = "{$rid}:{$r['batch']}:{$r['event_date']}";
            $tempExcursions[$key] = [
                'beer'       => $nameMap[$rid] ?? "recipe_{$rid}",
                'batch'      => $r['batch'],
                'date'       => $r['event_date'],
                'type'       => 'temperature',
                'value'      => $temp,
                'threshold'  => "{$tMin}–{$tMax}°C",
                'direction'  => $temp < $tMin ? 'low' : 'high',
            ];
        }
    }

    // Pressure excursions from Purge rows (purge_pressure_bar — currently 0 non-NULL)
    $pressStmt = $pdo->query("
        SELECT f.recipe_id_fk, f.batch, f.event_date,
               f.purge_pressure_bar
          FROM bd_fermenting_v2 f
         WHERE f.event_type = 'Purge'
           AND f.is_tombstoned = 0
           AND f.purge_pressure_bar IS NOT NULL
           AND f.event_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $pressRows = $pressStmt->fetchAll(PDO::FETCH_ASSOC);

    $pressExcursions = [];
    foreach ($pressRows as $r) {
        $rid  = (int) $r['recipe_id_fk'];
        $p    = (float) $r['purge_pressure_bar'];
        if ($p < $pressWarnLo || $p > $pressWarnHi) {
            $key = "{$rid}:{$r['batch']}:{$r['event_date']}:press";
            $pressExcursions[$key] = [
                'beer'      => $nameMap[$rid] ?? "recipe_{$rid}",
                'batch'     => $r['batch'],
                'date'      => $r['event_date'],
                'type'      => 'pressure',
                'value'     => $p,
                'threshold' => "{$pressWarnLo}–{$pressWarnHi} bar",
                'direction' => $p < $pressWarnLo ? 'low' : 'high',
            ];
        }
    }

    $allExcursions = array_merge(array_values($tempExcursions), array_values($pressExcursions));
    $totalCount    = count($allExcursions);

    // Sort by date desc for series
    usort($allExcursions, fn($a, $b) => strcmp($b['date'], $a['date']));

    $series = array_map(fn($e) => [
        'key'   => $e['type'] . ':' . $e['beer'] . ':' . $e['date'],
        'label' => $e['beer'] . ' #' . $e['batch'] . ' – ' . $e['type'],
        'value' => $e['value'],
        'meta'  => $e,
    ], $allExcursions);

    $tint = match (true) {
        $totalCount === 0 => 'green',
        $totalCount <= 5  => 'amber',
        default           => 'red',
    };

    $result = array_merge(kpi_empty_result($label, 'excursions'), [
        'value'  => $totalCount,
        'tint'   => $tint,
        'series' => $series,
        'meta'   => [
            'period_label'        => '30 derniers jours',
            'temp_count'          => count($tempExcursions),
            'pressure_count'      => count($pressExcursions),
            'value_meaning'       => 'Lectures Reads hors fenêtre de température + purges hors seuil de pression sur 30 jours',
            'temp_threshold_src'  => 'ref_recipes.ferm_temp_min/max_override (0 recettes avec override) → fallback 0–25°C (pas de clé commissioning_settings)',
            'press_threshold_src' => "commissioning_settings qc_thresholds: qc_pressure_warn_lo={$pressWarnLo} / qc_pressure_warn_hi={$pressWarnHi} bar (migration_182)",
            'pressure_note'       => 'purge_pressure_bar: 0 valeurs non-NULL dans les données actuelles — compteur pression toujours 0 jusqu\'à saisie opérateur',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

// ═════════════════════════════════════════════════════════════════════════════
// HANDLER: fg_stock  (source_domain = 'fg_stock')
// Reads: inv_fg_stocktake, bd_packaging_v2, ord_order_lines, inv_sales_bc,
//        inv_sales_orders, inv_sales_order_lines, inv_stock_movements,
//        ref_sku_bom, ref_skus, ref_sites, ref_packaging_formats.
//
// CARDINAL RULE: NEVER recompute FG physique independently.
// All stock figures derive from fg_stock_compute() (app/fg-stock.php).
// Fiscal values use ref_sku_bom.cost (sum over bom_source IN ('packaging','liquid')
// with effective_from/until date gate) — the SAME basis the COP build uses.
//
// #72  fg_units_in_stock    — table of physique per SKU/format
// #73  fg_inventory_value   — FISCAL: total inventory CHF (anchor × BOM cost)
// #74  fg_days_cover        — weeks cover per SKU (semaines_stock)
// #75  fg_stockouts         — GAP: no reorder threshold in DB → stub
// #76  fg_produced_vs_sold  — bar: prod_qty vs total depletion per display_family
// #77  fg_aging_best_before — GAP: no BBD tracking in DB → stub
// #78  fg_stock_turnover    — kpi_number: total depletion / avg anchor (8w)
// #79  warehouse_cage_fill  — GAP: no capacity data in DB → stub
// #80  slow_mover_flag      — flag: count of flag_dormant rows
// #81  fg_by_location       — bar: per-site units from fg_stock_location_snapshot()
// #82  consignment_keg_fleet — GAP → stub (passed via match default)
// #83  return_breakage_rate  — GAP → stub (passed via match default)
// #84  value_tied_per_beer  — bar: inventory CHF grouped by display_family/recipe
// #85  fg_stock_variation   — flag: anchor vs theoretical physique delta
// #133 warehouse_cage_capacity — same handler as #79 → stub
// ═════════════════════════════════════════════════════════════════════════════

function kpi_handler_fg_stock(
    string $handler,
    array  $params,
    string $label,
    PDO    $pdo
): array {
    return match ($handler) {
        'fg_units_in_stock'   => kpi_fg_units_in_stock($label, $pdo),
        'fg_inventory_value'  => kpi_fg_inventory_value($label, $pdo),
        'fg_days_cover'       => kpi_fg_days_cover($label, $pdo),
        'fg_produced_vs_sold' => kpi_fg_produced_vs_sold($label, $pdo),
        'fg_stock_turnover'   => kpi_fg_stock_turnover($label, $pdo),
        'slow_mover_flag'     => kpi_fg_slow_mover_flag($label, $pdo),
        'fg_by_location'      => kpi_fg_by_location($label, $pdo),
        'value_tied_per_beer' => kpi_fg_value_tied_per_beer($label, $pdo),
        'fg_stock_variation'  => kpi_fg_stock_variation($label, $pdo),
        // GAP trackers: no source data yet → stub with informative note
        'fg_stockouts'            => kpi_fg_stub_gap('fg_stockouts', $label, 'Aucun seuil de réapprovisionnement défini dans ref_skus'),
        'fg_aging_best_before'    => kpi_fg_stub_gap('fg_aging_best_before', $label, 'Données DDM par lot non encore enregistrées'),
        'warehouse_cage_fill'     => kpi_fg_stub_gap('warehouse_cage_fill', $label, 'Capacité entrepôt non configurée dans system_settings'),
        'warehouse_cage_capacity' => kpi_fg_stub_gap('warehouse_cage_capacity', $label, 'Capacité entrepôt non configurée dans system_settings'),
        default                   => kpi_stub_handler('fg_stock', $handler, $label),
    };
}

/**
 * Private: return a stub result for fg_stock GAP trackers with a specific note
 * rather than the generic Phase 2b message.
 */
function kpi_fg_stub_gap(string $handler, string $label, string $note): array
{
    $r = kpi_empty_result($label);
    $r['meta'] = [
        'stub'    => true,
        'domain'  => 'fg_stock',
        'handler' => $handler,
        'note'    => $note,
    ];
    return $r;
}

/**
 * Private: call fg_stock_compute() once per request (cached under 'fg_stock_compute_result').
 * All handlers that need the full per-SKU compute result call this wrapper.
 */
function kpi_fg_get_compute(PDO $pdo): array
{
    $cacheKey = 'fg_stock_compute_result';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }
    // fg-stock.php is required by the module pages that include kpi-handlers.php.
    // If it is not yet loaded (e.g. standalone kpi test), require it explicitly.
    if (!function_exists('fg_stock_compute')) {
        require_once __DIR__ . '/fg-stock.php';
    }
    $result = fg_stock_compute($pdo);
    return kpi_cache_set($cacheKey, $result);
}

// ─── BOM cost per SKU (fiscal basis) ─────────────────────────────────────────
// Built once per request; shared by fg_inventory_value, value_tied_per_beer.
// Uses ref_sku_bom.cost SUM with bom_source filter and effective date gate —
// the same basis the COP build uses.

function kpi_fg_get_sku_costs(PDO $pdo): array
{
    $cacheKey = 'fg_sku_bom_costs';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }
    $stmt = $pdo->query(
        "SELECT sku_id, SUM(cost) AS sku_cost
           FROM ref_sku_bom
          WHERE bom_source IN ('packaging', 'liquid')
            AND (effective_from  IS NULL OR effective_from  <= CURDATE())
            AND (effective_until IS NULL OR effective_until >= CURDATE())
          GROUP BY sku_id"
    );
    $costs = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $costs[(int) $row['sku_id']] = (float) $row['sku_cost'];
    }
    return kpi_cache_set($cacheKey, $costs);
}

// ─── #72 — fg_units_in_stock ─────────────────────────────────────────────────

/** #72 — Per-SKU current physique (table viz) */
function kpi_fg_units_in_stock(string $label, PDO $pdo): array
{
    $cacheKey = 'fg_units_in_stock';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $data = kpi_fg_get_compute($pdo);
    if (empty($data['rows'])) {
        $result = array_merge(kpi_empty_result($label, 'unités'), [
            'value' => 0,
            'tint'  => 'neutral',
            'meta'  => ['anchor_date' => null, 'note' => 'Aucun inventaire ancré'],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    // Total physique across all SKUs; breakdown per display_family for the table
    $totalUnits   = 0;
    $familyTotals = [];
    foreach ($data['rows'] as $row) {
        $phys = (int) $row['physique'];
        $totalUnits += $phys;
        $fam = (string) ($row['display_family'] ?? $row['format']);
        $familyTotals[$fam] = ($familyTotals[$fam] ?? 0) + $phys;
    }
    arsort($familyTotals);
    $breakdown = [];
    foreach ($familyTotals as $fam => $qty) {
        $breakdown[] = ['key' => $fam, 'label' => $fam, 'value' => $qty];
    }

    $result = array_merge(kpi_empty_result($label, 'unités'), [
        'value'     => $totalUnits,
        'tint'      => 'neutral',
        'breakdown' => $breakdown,
        'meta'      => [
            'anchor_date' => $data['anchor_date'],
            'sku_count'   => count($data['rows']),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

// ─── #73 — fg_inventory_value (FISCAL) ───────────────────────────────────────

/**
 * #73 — Total FG inventory value in CHF at BOM cost.
 *
 * Formula: Σ(anchor_qty[sku] × sku_cost[sku])
 * where:
 *   anchor_qty = SUM of latest per-(sku, location) counts from inv_fg_stocktake
 *   sku_cost   = SUM(ref_sku_bom.cost) WHERE bom_source IN ('packaging','liquid')
 *                AND effective date gate
 *
 * This is the ANCHOR value (not the live-computed physique) because:
 *   (a) The anchor IS the fiscal snapshot of physical stock at count time.
 *   (b) Movements since the anchor (prod/sales) are NOT yet reconciled to stock
 *       in the accounting system — they will be captured in the next close.
 *
 * Fiscal reconciliation verified 2026-06-10:
 *   ZEPF: 1256 units × CHF 1.9563/unit = CHF 2,457.07
 *   Total anchor inventory value = CHF 43,350.11 across 52 SKUs.
 */
function kpi_fg_inventory_value(string $label, PDO $pdo): array
{
    $cacheKey = 'fg_inventory_value';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // Anchor qty per SKU (same subquery as fg_stock_compute step 1)
    $anchorStmt = $pdo->query(
        "SELECT s.sku_id_fk, SUM(CAST(s.qty AS SIGNED)) AS anchor_qty
           FROM inv_fg_stocktake s
          WHERE s.id IN (
              SELECT MAX(t2.id)
                FROM inv_fg_stocktake t2
               WHERE t2.is_active = 1
               GROUP BY t2.sku_id_fk, t2.location_id_fk
          )
          GROUP BY s.sku_id_fk"
    );
    $anchors = [];
    foreach ($anchorStmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
        $anchors[(int) $ar['sku_id_fk']] = (int) $ar['anchor_qty'];
    }

    if (empty($anchors)) {
        $result = array_merge(kpi_empty_result($label, 'CHF'), [
            'value' => null,
            'tint'  => 'neutral',
            'meta'  => ['note' => 'Aucun inventaire ancré'],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    $skuCosts = kpi_fg_get_sku_costs($pdo);

    $totalCHF      = 0.0;
    $coveredSkus   = 0;
    $uncoveredSkus = 0;
    foreach ($anchors as $skuId => $qty) {
        if (isset($skuCosts[$skuId])) {
            $totalCHF += $qty * $skuCosts[$skuId];
            $coveredSkus++;
        } else {
            $uncoveredSkus++;
        }
    }

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value' => round($totalCHF, 2),
        'tint'  => 'neutral',
        'meta'  => [
            'sku_count'      => count($anchors),
            'covered_skus'   => $coveredSkus,
            'uncovered_skus' => $uncoveredSkus,
            'note'           => 'Valeur ancre (derniers comptages). Base: ref_sku_bom liquid+packaging.',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

// ─── #74 — fg_days_cover ─────────────────────────────────────────────────────

/**
 * #74 — Weeks of cover per SKU/format (table viz).
 * semaines_stock from fg_stock_compute() — already computed (physique / velocity_weekly).
 * SKUs with no velocity show null (UI renders '∞'). Total = weighted avg.
 */
function kpi_fg_days_cover(string $label, PDO $pdo): array
{
    $cacheKey = 'fg_days_cover';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $data = kpi_fg_get_compute($pdo);
    if (empty($data['rows'])) {
        $result = array_merge(kpi_empty_result($label, 'semaines'), [
            'value' => null,
            'tint'  => 'neutral',
            'meta'  => ['note' => 'Aucun inventaire ancré'],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    $totalVelWeighted = 0.0;
    $totalVelWeight   = 0.0;
    $lowCount         = 0;
    $infinityCount    = 0;
    $breakdown        = [];

    foreach ($data['rows'] as $row) {
        if ($row['flag_dormant']) continue;
        $sem = $row['semaines_stock'];

        if ($sem === null) {
            $infinityCount++;
        } else {
            $vel = (float) ($row['velocity_weekly'] ?? 0.0);
            if ($vel > 0.0) {
                $totalVelWeighted += (float) $sem * $vel;
                $totalVelWeight   += $vel;
            }
            if ($row['flag_low_stock']) {
                $lowCount++;
            }
        }
        $breakdown[] = [
            'key'   => $row['sku_code'],
            'label' => $row['sku_code'],
            'value' => $sem !== null ? round((float) $sem, 1) : null,
        ];
    }

    $avgWeeks = $totalVelWeight > 0.0 ? $totalVelWeighted / $totalVelWeight : null;

    $tint = match (true) {
        $lowCount > 3                                      => 'red',
        $lowCount > 0                                      => 'amber',
        $avgWeeks !== null && (float) $avgWeeks < 2.0      => 'amber',
        default                                            => 'green',
    };

    $result = array_merge(kpi_empty_result($label, 'semaines'), [
        'value'     => $avgWeeks !== null ? round((float) $avgWeeks, 1) : null,
        'tint'      => $tint,
        'breakdown' => $breakdown,
        'meta'      => [
            'anchor_date'     => $data['anchor_date'],
            'low_stock_count' => $lowCount,
            'infinity_count'  => $infinityCount,
            'note'            => 'Moyenne pondérée par vélocité hebdomadaire (8 semaines)',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

// ─── #76 — fg_produced_vs_sold ───────────────────────────────────────────────

/**
 * #76 — PF produits vs vendus: Δ stock net, grouped by display_family (bar).
 * Source: fg_stock_compute() rows — prod_qty vs (expedie + eshop + taproom).
 * Breakdown: [{key: family, label: family, value: net_delta}]
 * Series: interleaved prod/sold pairs for a grouped bar chart.
 */
function kpi_fg_produced_vs_sold(string $label, PDO $pdo): array
{
    $cacheKey = 'fg_produced_vs_sold';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $data = kpi_fg_get_compute($pdo);
    if (empty($data['rows'])) {
        $result = array_merge(kpi_empty_result($label, 'unités'), [
            'value' => null, 'tint' => 'neutral',
            'meta'  => ['note' => 'Aucun inventaire ancré'],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    $byFamily = [];
    foreach ($data['rows'] as $row) {
        $fam = (string) ($row['display_family'] ?? $row['format']);
        if (!isset($byFamily[$fam])) {
            $byFamily[$fam] = ['prod' => 0, 'sold' => 0];
        }
        $byFamily[$fam]['prod'] += (int) $row['prod_qty'];
        $byFamily[$fam]['sold'] += (int) $row['expedie_qty'] + (int) $row['eshop_qty'] + (int) $row['taproom_qty'];
    }

    $totalProd = 0;
    $totalSold = 0;
    $breakdown = [];
    $series    = [];
    foreach ($byFamily as $fam => $v) {
        $totalProd += $v['prod'];
        $totalSold += $v['sold'];
        $breakdown[] = ['key' => $fam, 'label' => $fam, 'value' => $v['prod'] - $v['sold']];
        $series[] = ['period' => $fam . ':prod', 'value' => $v['prod']];
        $series[] = ['period' => $fam . ':sold', 'value' => $v['sold']];
    }

    $netDelta = $totalProd - $totalSold;
    $tint = match (true) {
        $totalProd === 0 => 'neutral',
        $netDelta < 0    => 'amber',
        default          => 'green',
    };

    $result = array_merge(kpi_empty_result($label, 'unités'), [
        'value'     => $netDelta,
        'tint'      => $tint,
        'breakdown' => $breakdown,
        'series'    => $series,
        'meta'      => [
            'anchor_date' => $data['anchor_date'],
            'total_prod'  => $totalProd,
            'total_sold'  => $totalSold,
            'note'        => 'Depuis date de dernier inventaire ancré',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

// ─── #78 — fg_stock_turnover ─────────────────────────────────────────────────

/**
 * #78 — FG stock turnover ratio (annualised).
 * Formula: (velocity_weekly × 52) / anchor_total
 * velocity_weekly is the 8-week trailing avg already computed by fg_stock_compute().
 * Result unit: "rotations/an" (dimensionless).
 */
function kpi_fg_stock_turnover(string $label, PDO $pdo): array
{
    $cacheKey = 'fg_stock_turnover';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $data = kpi_fg_get_compute($pdo);
    if (empty($data['rows'])) {
        $result = array_merge(kpi_empty_result($label, 'rotations/an'), [
            'value' => null, 'tint' => 'neutral',
            'meta'  => ['note' => 'Aucun inventaire ancré'],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    $totalAnchor = 0;
    $weeklyRate  = 0.0;
    foreach ($data['rows'] as $row) {
        $totalAnchor += (int) $row['anchor_qty'];
        $weeklyRate  += (float) ($row['velocity_weekly'] ?? 0.0);
    }

    if ($totalAnchor === 0) {
        $result = array_merge(kpi_empty_result($label, 'rotations/an'), [
            'value' => null, 'tint' => 'neutral',
            'meta'  => ['note' => 'Inventaire ancré = 0'],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    $turnover = round(($weeklyRate * 52.0) / $totalAnchor, 2);

    $tint = match (true) {
        $turnover >= 8.0 => 'green',
        $turnover >= 4.0 => 'amber',
        default          => 'red',
    };

    $result = array_merge(kpi_empty_result($label, 'rotations/an'), [
        'value' => $turnover,
        'tint'  => $tint,
        'meta'  => [
            'anchor_date'  => $data['anchor_date'],
            'anchor_units' => $totalAnchor,
            'weekly_rate'  => round($weeklyRate, 1),
            'note'         => 'Taux annualisé = (ventes/semaine × 52) / stock ancré',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

// ─── #80 — slow_mover_flag ───────────────────────────────────────────────────

/**
 * #80 — Slow mover / dead stock flag.
 * Count of SKUs with flag_dormant=true AND physique > 0
 * (stock that exists but has had zero movement since the last count).
 */
function kpi_fg_slow_mover_flag(string $label, PDO $pdo): array
{
    $cacheKey = 'fg_slow_mover_flag';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $data = kpi_fg_get_compute($pdo);

    $dormantCount = 0;
    $dormantSkus  = [];
    foreach ($data['rows'] as $row) {
        if ($row['flag_dormant'] && (int) $row['physique'] > 0) {
            $dormantCount++;
            $dormantSkus[] = $row['sku_code'];
        }
    }

    $tint = match (true) {
        $dormantCount === 0 => 'green',
        $dormantCount <= 3  => 'amber',
        default             => 'red',
    };

    $result = array_merge(kpi_empty_result($label, 'SKUs'), [
        'value' => $dormantCount,
        'tint'  => $tint,
        'meta'  => [
            'anchor_date'  => $data['anchor_date'],
            'dormant_skus' => array_slice($dormantSkus, 0, 10),
            'note'         => 'SKUs avec physique > 0 et aucun mouvement depuis inventaire ancré',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

// ─── #81 — fg_by_location ────────────────────────────────────────────────────

/**
 * #81 — FG units by location (bar).
 * Source: fg_stock_location_snapshot() — per-site units after all flows applied.
 */
function kpi_fg_by_location(string $label, PDO $pdo): array
{
    $cacheKey = 'fg_by_location';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    if (!function_exists('fg_stock_location_snapshot')) {
        require_once __DIR__ . '/fg-stock.php';
    }
    $snap = fg_stock_location_snapshot($pdo);

    $breakdown  = [];
    $totalUnits = 0;
    foreach ($snap['locations'] as $loc) {
        $units = (int) ($loc['total_units'] ?? 0);
        $totalUnits += $units;
        $breakdown[] = [
            'key'   => (string) $loc['id'],
            'label' => $loc['name'],
            'value' => $units,
        ];
    }
    usort($breakdown, fn($a, $b) => $b['value'] <=> $a['value']);

    $result = array_merge(kpi_empty_result($label, 'unités'), [
        'value'     => $totalUnits,
        'tint'      => 'neutral',
        'breakdown' => $breakdown,
        'meta'      => [
            'anchor_date'    => $snap['anchor_date'] ?? null,
            'location_count' => count($snap['locations']),
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

// ─── #84 — value_tied_per_beer ───────────────────────────────────────────────

/**
 * #84 — Inventory value tied up per beer family (bar).
 * Same BOM cost basis as fg_inventory_value (#73).
 * Groups physique × cost by display_family.
 */
function kpi_fg_value_tied_per_beer(string $label, PDO $pdo): array
{
    $cacheKey = 'fg_value_tied_per_beer';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $data     = kpi_fg_get_compute($pdo);
    $skuCosts = kpi_fg_get_sku_costs($pdo);

    if (empty($data['rows'])) {
        $result = array_merge(kpi_empty_result($label, 'CHF'), [
            'value' => null, 'tint' => 'neutral',
            'meta'  => ['note' => 'Aucun inventaire ancré'],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    $byFamily = [];
    foreach ($data['rows'] as $row) {
        $skuId   = (int) $row['sku_id'];
        $cost    = $skuCosts[$skuId] ?? 0.0;
        $phys    = (int) $row['physique'];
        $value   = $phys * $cost;
        $fam     = (string) ($row['display_family'] ?? $row['format']);
        $byFamily[$fam] = ($byFamily[$fam] ?? 0.0) + $value;
    }
    arsort($byFamily);

    $totalValue = array_sum($byFamily);
    $breakdown  = [];
    foreach ($byFamily as $fam => $val) {
        if ($val < 0.01) continue;
        $breakdown[] = ['key' => $fam, 'label' => $fam, 'value' => round((float) $val, 2)];
    }

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value'     => round($totalValue, 2),
        'tint'      => 'neutral',
        'breakdown' => $breakdown,
        'meta'      => [
            'anchor_date' => $data['anchor_date'],
            'note'        => 'Physique courant × coût BOM (liquid+packaging). Regroupé par famille.',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}

// ─── #85 — fg_stock_variation ────────────────────────────────────────────────

/**
 * #85 — FG stock variation flag: count of SKUs where physique < 0.
 * A negative physique means more units have been sold/shipped than were produced
 * since the last physical count — signals a missing count, unrecorded shrinkage,
 * or data entry error.
 *
 * Primary value: count of over-depleted SKUs (alarm). Meta carries the net total.
 */
function kpi_fg_stock_variation(string $label, PDO $pdo): array
{
    $cacheKey = 'fg_stock_variation';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $data = kpi_fg_get_compute($pdo);
    if (empty($data['rows'])) {
        $result = array_merge(kpi_empty_result($label, 'unités'), [
            'value' => null, 'tint' => 'neutral',
            'meta'  => ['note' => 'Aucun inventaire ancré'],
        ]);
        return kpi_cache_set($cacheKey, $result);
    }

    $totalPhysique = 0;
    $totalAnchor   = 0;
    $negativeCount = 0;
    $breakdown     = [];

    foreach ($data['rows'] as $row) {
        $phys   = (int) $row['physique'];
        $anchor = (int) $row['anchor_qty'];
        $totalPhysique += $phys;
        $totalAnchor   += $anchor;
        if ($phys < 0) {
            $negativeCount++;
            $breakdown[] = [
                'key'   => $row['sku_code'],
                'label' => $row['sku_code'],
                'value' => $phys,
            ];
        }
    }

    $netVariation = $totalPhysique - $totalAnchor;

    $tint = match (true) {
        $negativeCount > 0  => 'red',
        default             => 'green',
    };

    $result = array_merge(kpi_empty_result($label, 'SKUs'), [
        'value'     => $negativeCount,
        'tint'      => $tint,
        'breakdown' => $breakdown,
        'meta'      => [
            'anchor_date'        => $data['anchor_date'],
            'total_physique'     => $totalPhysique,
            'total_anchor'       => $totalAnchor,
            'net_variation'      => $netVariation,
            'negative_sku_count' => $negativeCount,
            'note'               => 'Physique < 0 = stock sur-déplété (ventes > ancre + production)',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}


// ═════════════════════════════════════════════════════════════════════════════
// HANDLER: utilities  (source_domain = 'utilities')
//
// Consumption metrics (#192–#194, #199–#200, #202, #210, #212): read from
// inv_energydata (cumulative meter readings, monthly via LAG() window).
//
// Cost metrics (#195–#198, #201, #203, #211, #269): hybrid sources:
//   • Fiscal totals (#198, #211) → interfaces/cogs-report-data.json (COP feed),
//     which provides utilities.total + hlPackaged + totalVariables.total per month.
//     The COP feed uses a tariff-based predictive model for gas/elec/water and is
//     the same source shown on the COGS dashboard — reuse, don't reimpute.
//   • Per-type costs (#195 electricity, #196 water, #197 gas) →
//     inv_deliveries grouped by Utilities subcategory (actual booked HT CHF).
//     These may lag by a billing cycle; shown with a note.
//   • CO₂ purchased (#203) → inv_deliveries, cat='Process Chemical', subcat='Gas'.
//
// Sustainability stubs (#205–#209, #250): no canonical source yet — gap trackers.
//
// Data note: inv_energydata rows go to 2026-04; May/June show as "no data".
// ═════════════════════════════════════════════════════════════════════════════

function kpi_handler_utilities(
    string $handler,
    array  $params,
    string $label,
    PDO    $pdo
): array {
    return match ($handler) {
        'electricity_kwh_month'          => kpi_util_electricity_kwh($label, $pdo),
        'peak_demand_kw'                 => kpi_util_peak_demand_kw($label, $pdo),
        'reactive_power_kvarch'          => kpi_util_reactive_power($label, $pdo),
        'electricity_cost_month'         => kpi_util_electricity_cost($label, $pdo),
        'water_consumption_cost'         => kpi_util_water_cost($label, $pdo),
        'gas_consumption_cost'           => kpi_util_gas_cost($label, $pdo),
        'energy_cost_per_hl'             => kpi_util_energy_cost_per_hl($label, $pdo),
        'water_to_beer_ratio'            => kpi_util_water_to_beer_ratio($label, $pdo),
        'kwh_per_hl_trend'               => kpi_util_kwh_per_hl_trend($label, $pdo),
        'predictive_vs_actual_utilities' => kpi_util_cost_breakdown_bar($label, $pdo),
        'reactive_penalty_risk'          => kpi_util_reactive_penalty_risk($label, $pdo),
        'co2_purchased_cost'             => kpi_util_co2_purchased_cost($label, $pdo),
        'peak_shaving_opportunity'       => kpi_util_peak_shaving_opportunity($label, $pdo),
        'utility_cost_pct_cogs'          => kpi_util_cost_pct_cogs($label, $pdo),
        'seasonal_energy_curve'          => kpi_util_seasonal_energy_curve($label, $pdo),
        'mass_energy_water_balance'      => kpi_util_mass_energy_balance($label, $pdo),
        // GAP: no canonical source exists yet
        'co2_footprint_per_hl'           => kpi_util_stub_gap('co2_footprint_per_hl', $label,
            'Empreinte carbone non calculée — aucun facteur d\'émission lié aux MIs ou à la consommation énergie'),
        'spent_grain_volume'             => kpi_util_stub_gap('spent_grain_volume', $label,
            'Volume drêches non enregistré — aucune table de traçabilité drêches/trub'),
        'wastewater_load'                => kpi_util_stub_gap('wastewater_load', $label,
            'Charge eaux usées non mesurée — aucun capteur/registre COD/BOD'),
        'renewable_energy_pct'           => kpi_util_stub_gap('renewable_energy_pct', $label,
            'Part renouvelable inconnue — aucun suivi source énergie (certificat origine SIE)'),
        'heat_recovery'                  => kpi_util_stub_gap('heat_recovery', $label,
            'Récupération chaleur non instrumentée — aucun compteur calories'),
        'voc_tax_exposure'               => kpi_util_stub_gap('voc_tax_exposure', $label,
            'Taxe COV incorporée dans le prix unitaire des chimies (convention maltytask) — pas de MI séparé ni de ligne distincte délivrée'),
        'energy_per_equipment'           => kpi_util_stub_gap('energy_per_equipment', $label,
            'Sous-comptage par équipement non installé — un seul compteur global SIE'),
        default                          => kpi_stub_handler('utilities', $handler, $label),
    };
}

/** Private: stub for utilities GAP trackers with a specific gap-reason note. */
function kpi_util_stub_gap(string $handler, string $label, string $note): array
{
    $r = kpi_empty_result($label);
    $r['meta'] = [
        'stub'    => true,
        'domain'  => 'utilities',
        'handler' => $handler,
        'note'    => $note,
    ];
    return $r;
}

// ─── Shared loader: inv_energydata monthly consumption (LAG delta) ────────────
// Returns array keyed by period ('YYYY-MM'), each with:
//   eau_m3, gaz_kwh, elec_jour_kwh, elec_nuit_kwh, elec_total_kwh,
//   peak_kw (NULL when no SIE invoice), reactive_kvarh (NULL when no SIE invoice)

function kpi_util_load_energydata(PDO $pdo): array
{
    $cacheKey = 'util_energydata_monthly';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT
            period,
            eau_m3    - LAG(eau_m3)    OVER (ORDER BY period) AS eau_m3,
            gaz_kwh   - LAG(gaz_kwh)   OVER (ORDER BY period) AS gaz_kwh,
            elec_jour_kwh - LAG(elec_jour_kwh) OVER (ORDER BY period) AS elec_jour_kwh,
            elec_nuit_kwh - LAG(elec_nuit_kwh) OVER (ORDER BY period) AS elec_nuit_kwh,
            (elec_jour_kwh - LAG(elec_jour_kwh) OVER (ORDER BY period))
            + (elec_nuit_kwh - LAG(elec_nuit_kwh) OVER (ORDER BY period)) AS elec_total_kwh,
            peak_kw,
            reactive_kvarh,
            source
         FROM inv_energydata
         ORDER BY period"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $row) {
        if ($row['eau_m3'] === null && $row['gaz_kwh'] === null) {
            continue; // first row — no prior period for delta
        }
        $out[$row['period']] = [
            'eau_m3'         => $row['eau_m3']         !== null ? (float) $row['eau_m3']         : null,
            'gaz_kwh'        => $row['gaz_kwh']         !== null ? (float) $row['gaz_kwh']         : null,
            'elec_jour_kwh'  => $row['elec_jour_kwh']  !== null ? (float) $row['elec_jour_kwh']  : null,
            'elec_nuit_kwh'  => $row['elec_nuit_kwh']  !== null ? (float) $row['elec_nuit_kwh']  : null,
            'elec_total_kwh' => $row['elec_total_kwh'] !== null ? (float) $row['elec_total_kwh'] : null,
            'peak_kw'        => $row['peak_kw']         !== null ? (float) $row['peak_kw']         : null,
            'reactive_kvarh' => $row['reactive_kvarh'] !== null ? (float) $row['reactive_kvarh'] : null,
            'source'         => $row['source'],
        ];
    }
    return kpi_cache_set($cacheKey, $out);
}

// ─── Shared loader: COP feed (cogs-report-data.json) ─────────────────────────
// Returns array keyed by period ('YYYY-MM'), each with:
//   utilities_total (CHF), hl_packaged, total_variables (CHF)

function kpi_util_load_cop_feed(): array
{
    $cacheKey = 'util_cop_feed';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $path = __DIR__ . '/../interfaces/cogs-report-data.json';
    if (!file_exists($path)) {
        return kpi_cache_set($cacheKey, []);
    }
    $raw = json_decode(file_get_contents($path), true);
    if (!is_array($raw) || empty($raw['months'])) {
        return kpi_cache_set($cacheKey, []);
    }

    $out = [];
    foreach ($raw['months'] as $m) {
        $mk = $m['monthKey'] ?? null;
        if (!$mk) continue;
        $cop = $m['cop'] ?? [];
        // VPS JSON: utilities = {"total": float}
        // Local dev JSON: utilities = {gas:{...}, electricity:{...}, subtotal:{...}}
        $utilTotal = 0.0;
        $ut = $cop['utilities'] ?? null;
        if (is_array($ut)) {
            $utilTotal = isset($ut['subtotal']['current']['total'])
                ? (float) $ut['subtotal']['current']['total']
                : (float) ($ut['total'] ?? 0);
        }
        $out[$mk] = [
            'utilities_total' => $utilTotal,
            'hl_packaged'     => (float) ($cop['hlPackaged'] ?? 0),
            'total_variables' => (float) ($cop['totalVariables']['total'] ?? 0),
        ];
    }
    return kpi_cache_set($cacheKey, $out);
}

// ─── Shared loader: inv_deliveries Utilities costs by subcategory ─────────────
// Returns array keyed by period => subcat_name => total_chf

function kpi_util_load_delivery_costs(PDO $pdo): array
{
    $cacheKey = 'util_delivery_costs';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT
            DATE_FORMAT(d.date_received, '%Y-%m') AS period,
            sc.name                               AS subcat,
            SUM(d.total_chf)                      AS total_chf
         FROM inv_deliveries d
         JOIN ref_mi mi              ON mi.id = d.ingredient_fk
         JOIN ref_mi_categories c    ON c.id  = mi.category_id
         JOIN ref_mi_subcategories sc ON sc.id = mi.subcategory_id
         WHERE c.name  = 'Utilities'
           AND sc.name IN ('Electricity', 'Gas', 'Water & Sewage', 'Waste')
           AND d.status IN ('Active', 'Consumed')
         GROUP BY DATE_FORMAT(d.date_received, '%Y-%m'), sc.name
         ORDER BY period"
    );
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['period']][$row['subcat']] = (float) $row['total_chf'];
    }
    return kpi_cache_set($cacheKey, $out);
}

/** Private: latest period key from energydata array. */
function kpi_util_latest_energy_period(array $data): ?string
{
    return !empty($data) ? array_key_last($data) : null;
}

// ─── #192 — electricity_kwh_month ─────────────────────────────────────────────

function kpi_util_electricity_kwh(string $label, PDO $pdo): array
{
    $cacheKey = 'util_elec_kwh';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $data    = kpi_util_load_energydata($pdo);
    $periods = array_keys($data);
    $latest  = kpi_util_latest_energy_period($data);

    if (!$latest) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'kWh'), [
            'meta' => ['note' => 'Aucune donnée compteur dans inv_energydata'],
        ]));
    }

    $current  = $data[$latest]['elec_total_kwh'];
    $priorIdx = array_search($latest, $periods) - 1;
    $priorKey = $priorIdx >= 0 ? $periods[$priorIdx] : null;
    $prior    = $priorKey ? ($data[$priorKey]['elec_total_kwh'] ?? null) : null;
    $delta    = ($current !== null && $prior !== null && $prior > 0)
        ? round(($current - $prior) / $prior * 100, 1) : null;

    $tint = match (true) {
        $current === null => 'neutral',
        $delta === null   => 'neutral',
        $delta > 10       => 'red',
        $delta > 0        => 'amber',
        default           => 'green',
    };

    $result = array_merge(kpi_empty_result($label, 'kWh'), [
        'value'       => $current !== null ? (int) round($current) : null,
        'delta'       => $delta,
        'delta_label' => 'vs mois précédent (%)',
        'tint'        => $tint,
        'meta'        => [
            'period'    => $latest,
            'jour_kwh'  => $data[$latest]['elec_jour_kwh'] !== null ? (int) round($data[$latest]['elec_jour_kwh']) : null,
            'nuit_kwh'  => $data[$latest]['elec_nuit_kwh'] !== null ? (int) round($data[$latest]['elec_nuit_kwh']) : null,
            'source'    => 'inv_energydata (index compteur SIE)',
            'data_note' => 'Dernière donnée disponible : ' . $latest,
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #193 — peak_demand_kw ────────────────────────────────────────────────────

function kpi_util_peak_demand_kw(string $label, PDO $pdo): array
{
    $cacheKey = 'util_peak_kw';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $data = kpi_util_load_energydata($pdo);
    if (empty($data)) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'kW'), [
            'meta' => ['note' => 'Aucune donnée dans inv_energydata'],
        ]));
    }

    $peakPeriod = null;
    $peakVal    = null;
    foreach (array_reverse(array_keys($data)) as $p) {
        if ($data[$p]['peak_kw'] !== null) {
            $peakPeriod = $p;
            $peakVal    = $data[$p]['peak_kw'];
            break;
        }
    }

    if ($peakVal === null) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'kW'), [
            'meta' => ['note' => 'Aucun pic de puissance enregistré — requiert facture SIE ingérée'],
        ]));
    }

    $peakVals = array_filter(array_column($data, 'peak_kw'), fn($v) => $v !== null);
    $rolling6 = count($peakVals) >= 2
        ? array_sum(array_slice($peakVals, -6)) / min(6, count($peakVals))
        : null;

    $delta = ($rolling6 !== null && $rolling6 > 0)
        ? round(($peakVal - $rolling6) / $rolling6 * 100, 1) : null;

    $tint = match (true) {
        $delta === null => 'neutral',
        $delta > 15     => 'red',
        $delta > 5      => 'amber',
        default         => 'green',
    };

    $result = array_merge(kpi_empty_result($label, 'kW'), [
        'value'       => round($peakVal, 1),
        'delta'       => $delta,
        'delta_label' => 'vs moy. 6 mois (%)',
        'tint'        => $tint,
        'meta'        => [
            'period'           => $peakPeriod,
            'rolling6_mean_kw' => $rolling6 !== null ? round($rolling6, 1) : null,
            'peak_count'       => count($peakVals),
            'source'           => 'inv_energydata.peak_kw (facture SIE)',
            'data_note'        => 'Disponible uniquement sur mois avec facture SIE ingérée',
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #194 — reactive_power_kvarch ────────────────────────────────────────────

function kpi_util_reactive_power(string $label, PDO $pdo): array
{
    $cacheKey = 'util_reactive_kvarh';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $data      = kpi_util_load_energydata($pdo);
    $period    = null;
    $reactVal  = null;
    $peakVal   = null;
    foreach (array_reverse(array_keys($data)) as $p) {
        if ($data[$p]['reactive_kvarh'] !== null) {
            $period   = $p;
            $reactVal = $data[$p]['reactive_kvarh'];
            $peakVal  = $data[$p]['peak_kw'];
            break;
        }
    }

    if ($reactVal === null) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'kVArh'), [
            'meta' => ['note' => 'Aucune donnée réactive — requiert facture SIE ingérée'],
        ]));
    }

    $elecKwh     = $data[$period]['elec_total_kwh'] ?? null;
    $powerFactor = null;
    if ($elecKwh !== null && $elecKwh > 0) {
        $powerFactor = round($elecKwh / sqrt($elecKwh ** 2 + $reactVal ** 2), 3);
    }

    $tint = match (true) {
        $powerFactor === null => 'neutral',
        $powerFactor < 0.85   => 'red',
        $powerFactor < 0.90   => 'amber',
        default               => 'green',
    };

    $result = array_merge(kpi_empty_result($label, 'kVArh'), [
        'value' => (int) round($reactVal),
        'tint'  => $tint,
        'meta'  => [
            'period'       => $period,
            'peak_kw'      => $peakVal !== null ? round($peakVal, 1) : null,
            'power_factor' => $powerFactor,
            'elec_kwh'     => $elecKwh !== null ? (int) round($elecKwh) : null,
            'threshold'    => 'Pénalité probable si cos φ < 0.90 (SIE)',
            'source'       => 'inv_energydata.reactive_kvarh (facture SIE)',
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #195 — electricity_cost_month ────────────────────────────────────────────

function kpi_util_electricity_cost(string $label, PDO $pdo): array
{
    $cacheKey = 'util_elec_cost';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $costs  = kpi_util_load_delivery_costs($pdo);
    $latest = null;
    $value  = null;
    foreach (array_reverse(array_keys($costs)) as $p) {
        if (isset($costs[$p]['Electricity'])) {
            $latest = $p;
            $value  = $costs[$p]['Electricity'];
            break;
        }
    }

    if ($latest === null) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'CHF'), [
            'meta' => ['note' => 'Aucune facture électricité dans inv_deliveries'],
        ]));
    }

    $prior    = null;
    $priorKey = null;
    $found    = false;
    foreach (array_reverse(array_keys($costs)) as $p) {
        if ($found && isset($costs[$p]['Electricity'])) {
            $priorKey = $p;
            $prior    = $costs[$p]['Electricity'];
            break;
        }
        if ($p === $latest) $found = true;
    }

    $delta = ($prior !== null && $prior > 0) ? round(($value - $prior) / $prior * 100, 1) : null;

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value'       => round($value, 2),
        'delta'       => $delta,
        'delta_label' => 'vs période précédente (%)',
        'tint'        => 'neutral',
        'meta'        => [
            'period'    => $latest,
            'source'    => 'inv_deliveries (Utilities / Electricity, HT)',
            'data_note' => 'Coût comptabilisé à la date de réception facture — peut décaler d\'un mois vs consommation',
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #196 — water_consumption_cost ───────────────────────────────────────────

function kpi_util_water_cost(string $label, PDO $pdo): array
{
    $cacheKey = 'util_water_cost';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $energy     = kpi_util_load_energydata($pdo);
    $costs      = kpi_util_load_delivery_costs($pdo);
    $latest     = kpi_util_latest_energy_period($energy);
    $costPeriod = null;
    $costValue  = null;
    foreach (array_reverse(array_keys($costs)) as $p) {
        if (isset($costs[$p]['Water & Sewage'])) {
            $costPeriod = $p;
            $costValue  = $costs[$p]['Water & Sewage'];
            break;
        }
    }

    $eauM3 = ($latest && isset($energy[$latest])) ? $energy[$latest]['eau_m3'] : null;

    if ($eauM3 === null && $costValue === null) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'm³'), [
            'meta' => ['note' => 'Aucune donnée eau dans inv_energydata ou inv_deliveries'],
        ]));
    }

    $result = array_merge(kpi_empty_result($label, 'm³'), [
        'value' => $eauM3 !== null ? (int) round($eauM3) : null,
        'tint'  => 'neutral',
        'meta'  => [
            'meter_period' => $latest,
            'eau_m3'       => $eauM3 !== null ? round($eauM3, 1) : null,
            'cost_chf'     => $costValue !== null ? round($costValue, 2) : null,
            'cost_period'  => $costPeriod,
            'source_meter' => 'inv_energydata (compteur SIL)',
            'source_cost'  => 'inv_deliveries (Utilities / Water & Sewage, HT)',
            'data_note'    => 'Dernière donnée compteur : ' . ($latest ?? 'N/A'),
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #197 — gas_consumption_cost ─────────────────────────────────────────────

function kpi_util_gas_cost(string $label, PDO $pdo): array
{
    $cacheKey = 'util_gas_cost';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $energy     = kpi_util_load_energydata($pdo);
    $costs      = kpi_util_load_delivery_costs($pdo);
    $latest     = kpi_util_latest_energy_period($energy);
    $gazKwh     = ($latest && isset($energy[$latest])) ? $energy[$latest]['gaz_kwh'] : null;
    $costPeriod = null;
    $costValue  = null;
    foreach (array_reverse(array_keys($costs)) as $p) {
        if (isset($costs[$p]['Gas'])) {
            $costPeriod = $p;
            $costValue  = $costs[$p]['Gas'];
            break;
        }
    }

    if ($gazKwh === null && $costValue === null) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'kWh'), [
            'meta' => ['note' => 'Aucune donnée gaz dans inv_energydata ou inv_deliveries'],
        ]));
    }

    $result = array_merge(kpi_empty_result($label, 'kWh'), [
        'value' => $gazKwh !== null ? (int) round($gazKwh) : null,
        'tint'  => 'neutral',
        'meta'  => [
            'meter_period' => $latest,
            'gaz_kwh'      => $gazKwh !== null ? round($gazKwh, 1) : null,
            'cost_chf'     => $costValue !== null ? round($costValue, 2) : null,
            'cost_period'  => $costPeriod,
            'source_meter' => 'inv_energydata (compteur SIL)',
            'source_cost'  => 'inv_deliveries (Utilities / Gas, HT)',
            'data_note'    => 'Dernière donnée compteur : ' . ($latest ?? 'N/A'),
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #198 — energy_cost_per_hl ────────────────────────────────────────────────

function kpi_util_energy_cost_per_hl(string $label, PDO $pdo): array
{
    $cacheKey = 'util_energy_cost_hl';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $cop    = kpi_util_load_cop_feed();
    $latest = !empty($cop) ? array_key_last($cop) : null;

    if (!$latest || $cop[$latest]['hl_packaged'] <= 0) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'CHF/HL'), [
            'meta' => ['note' => 'COP feed non disponible ou HL packagé = 0'],
        ]));
    }

    $utilTotal = $cop[$latest]['utilities_total'];
    $hlPack    = $cop[$latest]['hl_packaged'];
    $perHL     = $hlPack > 0 ? round($utilTotal / $hlPack, 2) : null;

    $periods  = array_keys($cop);
    $priorIdx = array_search($latest, $periods) - 1;
    $delta    = null;
    if ($priorIdx >= 0) {
        $prior = $cop[$periods[$priorIdx]];
        if ($prior['hl_packaged'] > 0) {
            $priorPerHL = $prior['utilities_total'] / $prior['hl_packaged'];
            $delta = ($perHL !== null && $priorPerHL > 0)
                ? round(($perHL - $priorPerHL) / $priorPerHL * 100, 1) : null;
        }
    }

    $tint  = match (true) {
        $perHL === null => 'neutral',
        $delta === null => 'neutral',
        $delta > 10     => 'red',
        $delta > 3      => 'amber',
        default         => 'green',
    };
    $recon = isset($cop['2026-04'])
        ? '2026-04: ' . $cop['2026-04']['utilities_total'] . ' CHF ÷ ' . $cop['2026-04']['hl_packaged']
          . ' HL = ' . round($cop['2026-04']['utilities_total'] / max($cop['2026-04']['hl_packaged'], 0.01), 2) . ' CHF/HL'
        : '';

    $result = array_merge(kpi_empty_result($label, 'CHF/HL'), [
        'value'       => $perHL,
        'delta'       => $delta,
        'delta_label' => 'vs mois précédent (%)',
        'tint'        => $tint,
        'meta'        => [
            'period'         => $latest,
            'utilities_chf'  => round($utilTotal, 2),
            'hl_packaged'    => round($hlPack, 1),
            'source'         => 'COP feed (cogs-report-data.json, utilities.total ÷ cop.hlPackaged)',
            'reconciliation' => $recon,
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #199 — water_to_beer_ratio ───────────────────────────────────────────────

function kpi_util_water_to_beer_ratio(string $label, PDO $pdo): array
{
    $cacheKey = 'util_water_beer_ratio';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $energy = kpi_util_load_energydata($pdo);
    $latest = kpi_util_latest_energy_period($energy);

    if (!$latest || $energy[$latest]['eau_m3'] === null) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'm³/HL'), [
            'meta' => ['note' => 'Aucune donnée compteur eau pour le calcul du ratio'],
        ]));
    }

    $eauM3 = $energy[$latest]['eau_m3'];
    $stmt  = $pdo->prepare(
        "SELECT COALESCE(SUM(p.vendable_hl), 0) AS hl_packaged
           FROM bd_packaging_v2 p
          WHERE p.is_tombstoned = 0
            AND DATE_FORMAT(p.event_date, '%Y-%m') = ?"
    );
    $stmt->execute([$latest]);
    $hlPack = (float) ($stmt->fetchColumn() ?? 0);

    if ($hlPack <= 0) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'm³/HL'), [
            'meta' => [
                'period'      => $latest,
                'eau_m3'      => round($eauM3, 1),
                'hl_packaged' => 0,
                'note'        => 'HL packagé = 0 — ratio non calculable',
            ],
        ]));
    }

    $ratio   = round($eauM3 / $hlPack, 3);
    $periods = array_keys($energy);
    $idx     = array_search($latest, $periods);
    $delta   = null;
    if ($idx > 0) {
        $prevPeriod = $periods[$idx - 1];
        $prevEau    = $energy[$prevPeriod]['eau_m3'];
        if ($prevEau !== null) {
            $stmt2 = $pdo->prepare(
                "SELECT COALESCE(SUM(p.vendable_hl), 0) AS hl_packaged
                   FROM bd_packaging_v2 p
                  WHERE p.is_tombstoned = 0
                    AND DATE_FORMAT(p.event_date, '%Y-%m') = ?"
            );
            $stmt2->execute([$prevPeriod]);
            $prevHL = (float) ($stmt2->fetchColumn() ?? 0);
            if ($prevHL > 0) {
                $delta = round(($ratio - $prevEau / $prevHL) / ($prevEau / $prevHL) * 100, 1);
            }
        }
    }

    $tint = match (true) {
        $delta === null => 'neutral',
        $delta > 10     => 'red',
        $delta > 3      => 'amber',
        default         => 'green',
    };

    $result = array_merge(kpi_empty_result($label, 'm³/HL'), [
        'value'       => $ratio,
        'delta'       => $delta,
        'delta_label' => 'vs mois précédent (%)',
        'tint'        => $tint,
        'meta'        => [
            'period'       => $latest,
            'eau_m3'       => round($eauM3, 1),
            'hl_packaged'  => round($hlPack, 1),
            'source_meter' => 'inv_energydata (compteur SIL)',
            'source_hl'    => 'bd_packaging_v2 (vendable_hl)',
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #200 — kwh_per_hl_trend ──────────────────────────────────────────────────

function kpi_util_kwh_per_hl_trend(string $label, PDO $pdo): array
{
    $cacheKey = 'util_kwh_hl_trend';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $energy = kpi_util_load_energydata($pdo);
    if (empty($energy)) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'kWh/HL'), [
            'meta' => ['note' => 'Aucune donnée compteur'],
        ]));
    }

    $periods      = array_slice(array_keys($energy), -12);
    $placeholders = implode(',', array_fill(0, count($periods), '?'));
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(p.event_date, '%Y-%m') AS period,
                SUM(p.vendable_hl)                 AS hl_packaged
           FROM bd_packaging_v2 p
          WHERE p.is_tombstoned = 0
            AND DATE_FORMAT(p.event_date, '%Y-%m') IN ({$placeholders})
          GROUP BY DATE_FORMAT(p.event_date, '%Y-%m')"
    );
    $stmt->execute($periods);
    $hlByPeriod = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hlByPeriod[$row['period']] = (float) $row['hl_packaged'];
    }

    $series    = [];
    $latestVal = null;
    foreach ($periods as $p) {
        $kwh = $energy[$p]['elec_total_kwh'];
        $hl  = $hlByPeriod[$p] ?? 0;
        if ($kwh !== null && $hl > 0) {
            $ratio     = round($kwh / $hl, 2);
            $series[]  = ['period' => $p, 'value' => $ratio];
            $latestVal = $ratio;
        }
    }

    $latestPeriod = !empty($series) ? end($series)['period'] : null;
    $result = array_merge(kpi_empty_result($label, 'kWh/HL'), [
        'value'  => $latestVal,
        'series' => $series,
        'tint'   => 'neutral',
        'meta'   => [
            'latest_period' => $latestPeriod,
            'data_points'   => count($series),
            'source_kwh'    => 'inv_energydata (index SIE, delta mensuel)',
            'source_hl'     => 'bd_packaging_v2 (vendable_hl)',
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #201 — predictive_vs_actual_utilities ────────────────────────────────────

function kpi_util_cost_breakdown_bar(string $label, PDO $pdo): array
{
    $cacheKey = 'util_cost_bar';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $cop     = kpi_util_load_cop_feed();
    $periods = array_slice(array_keys($cop), -6);

    if (empty($periods)) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'CHF'), [
            'meta' => ['note' => 'COP feed non disponible'],
        ]));
    }

    $series = [];
    foreach ($periods as $p) {
        $series[] = ['period' => $p, 'value' => round((float) $cop[$p]['utilities_total'], 2)];
    }

    $latest     = end($periods);
    $latestVal  = $cop[$latest]['utilities_total'];
    $allPeriods = array_keys($cop);
    $priorPos   = array_search($latest, $allPeriods) - 1;
    $priorVal   = $priorPos >= 0 ? $cop[$allPeriods[$priorPos]]['utilities_total'] : null;
    $delta      = ($priorVal !== null && $priorVal > 0)
        ? round(($latestVal - $priorVal) / $priorVal * 100, 1) : null;

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value'       => round($latestVal, 2),
        'delta'       => $delta,
        'delta_label' => 'vs mois précédent (%)',
        'series'      => $series,
        'tint'        => 'neutral',
        'meta'        => [
            'latest_period' => $latest,
            'source'        => 'COP feed (cogs-report-data.json, utilities.total — modèle prédictif SIE)',
            'note'          => '6 derniers mois clôturés. Total coûts = gaz + électricité + eau/assainissement HT.',
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #202 — reactive_penalty_risk ────────────────────────────────────────────

function kpi_util_reactive_penalty_risk(string $label, PDO $pdo): array
{
    $cacheKey = 'util_reactive_penalty';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $data       = kpi_util_load_energydata($pdo);
    $period     = null;
    $reactKVArh = null;
    $elecKwh    = null;
    foreach (array_reverse(array_keys($data)) as $p) {
        if ($data[$p]['reactive_kvarh'] !== null) {
            $period     = $p;
            $reactKVArh = $data[$p]['reactive_kvarh'];
            $elecKwh    = $data[$p]['elec_total_kwh'];
            break;
        }
    }

    if ($reactKVArh === null || $elecKwh === null || $elecKwh <= 0) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label), [
            'value' => null, 'tint' => 'neutral',
            'meta'  => ['note' => 'Données réactives insuffisantes — requiert facture SIE ingérée'],
        ]));
    }

    $ratio    = $reactKVArh / $elecKwh;
    $atRisk   = $ratio > 0.60;
    $highRisk = $ratio > 0.75;

    $tint = match (true) {
        $highRisk => 'red',
        $atRisk   => 'amber',
        default   => 'green',
    };

    $result = array_merge(kpi_empty_result($label), [
        'value' => $atRisk ? 1 : 0,
        'unit'  => $atRisk ? 'RISQUE' : 'OK',
        'tint'  => $tint,
        'meta'  => [
            'period'         => $period,
            'reactive_kvarh' => (int) round($reactKVArh),
            'elec_kwh'       => (int) round($elecKwh),
            'ratio_pct'      => round($ratio * 100, 1),
            'threshold_pct'  => 60,
            'at_risk'        => $atRisk,
            'source'         => 'inv_energydata (facture SIE)',
            'note'           => 'Pénalité SIE si kVArh/kWh > 60% (cos φ < 0.857)',
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #203 — co2_purchased_cost ────────────────────────────────────────────────

function kpi_util_co2_purchased_cost(string $label, PDO $pdo): array
{
    $cacheKey = 'util_co2_cost';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $stmt = $pdo->query(
        "SELECT
            DATE_FORMAT(d.date_received, '%Y-%m') AS period,
            SUM(d.qty_delivered)                  AS total_qty,
            ANY_VALUE(d.pricing_unit)             AS pricing_unit,
            SUM(d.total_chf)                      AS total_chf
         FROM inv_deliveries d
         JOIN ref_mi mi               ON mi.id = d.ingredient_fk
         JOIN ref_mi_categories c     ON c.id  = mi.category_id
         JOIN ref_mi_subcategories sc ON sc.id = mi.subcategory_id
         WHERE c.name  = 'Process Chemical'
           AND sc.name = 'Gas'
           AND d.status IN ('Active', 'Consumed')
         GROUP BY DATE_FORMAT(d.date_received, '%Y-%m')
         ORDER BY period DESC
         LIMIT 1"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'CHF'), [
            'meta' => ['note' => 'Aucune livraison CO₂ dans inv_deliveries (cat=Process Chemical, subcat=Gas)'],
        ]));
    }

    $stmt2 = $pdo->query(
        "SELECT
            DATE_FORMAT(d.date_received, '%Y-%m') AS period,
            SUM(d.total_chf)                      AS total_chf
         FROM inv_deliveries d
         JOIN ref_mi mi               ON mi.id = d.ingredient_fk
         JOIN ref_mi_categories c     ON c.id  = mi.category_id
         JOIN ref_mi_subcategories sc ON sc.id = mi.subcategory_id
         WHERE c.name  = 'Process Chemical'
           AND sc.name = 'Gas'
           AND d.status IN ('Active', 'Consumed')
         GROUP BY DATE_FORMAT(d.date_received, '%Y-%m')
         ORDER BY period DESC
         LIMIT 6"
    );
    $seriesRows = array_reverse($stmt2->fetchAll(PDO::FETCH_ASSOC));
    $series = array_map(
        fn($r) => ['period' => $r['period'], 'value' => round((float) $r['total_chf'], 2)],
        $seriesRows
    );

    $result = array_merge(kpi_empty_result($label, 'CHF'), [
        'value'  => round((float) $row['total_chf'], 2),
        'series' => $series,
        'tint'   => 'neutral',
        'meta'   => [
            'period'       => $row['period'],
            'total_qty'    => $row['total_qty'] !== null ? round((float) $row['total_qty'], 2) : null,
            'pricing_unit' => $row['pricing_unit'],
            'source'       => 'inv_deliveries (Process Chemical / Gas — PROC_CO2_*, PROC_ALIGAL2_*)',
            'note'         => 'Inclut location réservoir + CO₂ liquide (Aligal 2). Hors CO₂ de fermentation endogène.',
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #210 — peak_shaving_opportunity ─────────────────────────────────────────

function kpi_util_peak_shaving_opportunity(string $label, PDO $pdo): array
{
    $cacheKey = 'util_peak_shaving';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $data        = kpi_util_load_energydata($pdo);
    $peakHistory = [];
    foreach ($data as $p => $row) {
        if ($row['peak_kw'] !== null) {
            $peakHistory[$p] = $row['peak_kw'];
        }
    }

    if (count($peakHistory) < 2) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label), [
            'value' => null, 'tint' => 'neutral',
            'meta'  => ['note' => 'Données pic insuffisantes (< 2 mois avec facture SIE)'],
        ]));
    }

    $latestPeriod = array_key_last($peakHistory);
    $latestPeak   = $peakHistory[$latestPeriod];
    $priorVals    = array_slice(array_values($peakHistory), 0, -1);
    $window       = array_slice($priorVals, -6);
    $mean         = count($window) > 0 ? array_sum($window) / count($window) : null;
    $opportunity  = ($mean !== null && $latestPeak > $mean * 1.10);
    $pctAbove     = ($mean !== null && $mean > 0) ? round(($latestPeak - $mean) / $mean * 100, 1) : null;

    $tint = match (true) {
        $pctAbove === null => 'neutral',
        $pctAbove > 20     => 'red',
        $opportunity       => 'amber',
        default            => 'green',
    };

    $result = array_merge(kpi_empty_result($label), [
        'value' => $opportunity ? 1 : 0,
        'unit'  => $opportunity ? 'OPPORTUNITÉ' : 'OK',
        'tint'  => $tint,
        'meta'  => [
            'period'           => $latestPeriod,
            'peak_kw'          => round($latestPeak, 1),
            'rolling6_mean_kw' => $mean !== null ? round($mean, 1) : null,
            'pct_above_mean'   => $pctAbove,
            'threshold_pct'    => 10,
            'source'           => 'inv_energydata.peak_kw',
            'note'             => 'Pic > moy.6mois +10% → opportunité d\'écrêtage tarifaire',
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #211 — utility_cost_pct_cogs ────────────────────────────────────────────

function kpi_util_cost_pct_cogs(string $label, PDO $pdo): array
{
    $cacheKey = 'util_cost_pct_cogs';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $cop    = kpi_util_load_cop_feed();
    $latest = !empty($cop) ? array_key_last($cop) : null;

    if (!$latest || $cop[$latest]['total_variables'] <= 0) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, '%'), [
            'meta' => ['note' => 'COP feed non disponible ou totalVariables = 0'],
        ]));
    }

    $utilTotal = $cop[$latest]['utilities_total'];
    $totalVar  = $cop[$latest]['total_variables'];
    $pct       = $totalVar > 0 ? round($utilTotal / $totalVar * 100, 2) : null;

    $periods  = array_keys($cop);
    $priorIdx = array_search($latest, $periods) - 1;
    $delta    = null;
    if ($priorIdx >= 0) {
        $prior    = $cop[$periods[$priorIdx]];
        $priorPct = $prior['total_variables'] > 0
            ? $prior['utilities_total'] / $prior['total_variables'] * 100 : null;
        $delta = ($pct !== null && $priorPct !== null) ? round($pct - $priorPct, 2) : null;
    }

    $tint  = match (true) {
        $pct === null => 'neutral',
        $pct > 25     => 'red',
        $pct > 20     => 'amber',
        default       => 'green',
    };
    $recon = isset($cop['2026-04'])
        ? '2026-04: ' . $cop['2026-04']['utilities_total'] . ' ÷ ' . $cop['2026-04']['total_variables']
          . ' = ' . round($cop['2026-04']['utilities_total'] / max($cop['2026-04']['total_variables'], 0.01) * 100, 2) . '%'
        : '';

    $result = array_merge(kpi_empty_result($label, '%'), [
        'value'       => $pct,
        'delta'       => $delta,
        'delta_label' => 'pts vs mois précédent',
        'tint'        => $tint,
        'meta'        => [
            'period'         => $latest,
            'utilities_chf'  => round($utilTotal, 2),
            'total_var_chf'  => round($totalVar, 2),
            'source'         => 'COP feed (utilities.total ÷ totalVariables.total)',
            'reconciliation' => $recon,
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #212 — seasonal_energy_curve ────────────────────────────────────────────

function kpi_util_seasonal_energy_curve(string $label, PDO $pdo): array
{
    $cacheKey = 'util_seasonal_energy';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $energy = kpi_util_load_energydata($pdo);
    if (empty($energy)) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label, 'kWh'), [
            'meta' => ['note' => 'Aucune donnée compteur'],
        ]));
    }

    $periods = array_slice(array_keys($energy), -12);
    $series  = [];
    foreach ($periods as $p) {
        $elec = $energy[$p]['elec_total_kwh'];
        $gaz  = $energy[$p]['gaz_kwh'];
        if ($elec !== null && $gaz !== null) {
            $series[] = ['period' => $p, 'value' => (int) round($elec + $gaz)];
        } elseif ($elec !== null) {
            $series[] = ['period' => $p, 'value' => (int) round($elec)];
        }
    }

    $latestEntry = !empty($series) ? end($series) : null;
    $result = array_merge(kpi_empty_result($label, 'kWh'), [
        'value'  => $latestEntry ? $latestEntry['value'] : null,
        'series' => $series,
        'tint'   => 'neutral',
        'meta'   => [
            'latest_period' => $latestEntry ? $latestEntry['period'] : null,
            'data_points'   => count($series),
            'source'        => 'inv_energydata (gaz_kwh + elec_jour_kwh + elec_nuit_kwh, delta mensuel)',
            'note'          => 'Courbe saisonnière 12 mois — gaz + électricité combinés',
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}

// ─── #269 — mass_energy_water_balance ────────────────────────────────────────

function kpi_util_mass_energy_balance(string $label, PDO $pdo): array
{
    $cacheKey = 'util_mass_energy_balance';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    $energy = kpi_util_load_energydata($pdo);
    $latest = kpi_util_latest_energy_period($energy);

    if (!$latest) {
        return kpi_cache_set($cacheKey, array_merge(kpi_empty_result($label), [
            'meta' => ['note' => 'Aucune donnée compteur disponible'],
        ]));
    }

    $row = $energy[$latest];

    // HL brewed from bd_brewing_gravity_v2 Cooling (submitted_at keyed, matches COP)
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(g.final_volume), 0) AS hl_brewed
           FROM bd_brewing_gravity_v2 g
          WHERE g.is_tombstoned = 0
            AND g.event_type    = 'Cooling'
            AND DATE_FORMAT(g.submitted_at, '%Y-%m') = ?"
    );
    $stmt->execute([$latest]);
    $hlBrewed = (float) ($stmt->fetchColumn() ?? 0);

    // HL packaged from bd_packaging_v2
    $stmt2 = $pdo->prepare(
        "SELECT COALESCE(SUM(p.vendable_hl), 0) AS hl_packaged
           FROM bd_packaging_v2 p
          WHERE p.is_tombstoned = 0
            AND DATE_FORMAT(p.event_date, '%Y-%m') = ?"
    );
    $stmt2->execute([$latest]);
    $hlPackaged = (float) ($stmt2->fetchColumn() ?? 0);

    $yieldLoss = $hlBrewed > 0 ? round($hlBrewed - $hlPackaged, 1) : null;
    $yieldPct  = ($hlBrewed > 0 && $hlPackaged > 0)
        ? round($hlPackaged / $hlBrewed * 100, 1) : null;

    $breakdown = [];
    if ($hlBrewed > 0) {
        $breakdown[] = ['key' => 'hl_brewed',   'label' => 'HL brassé',          'value' => round($hlBrewed, 1)];
    }
    if ($hlPackaged > 0) {
        $breakdown[] = ['key' => 'hl_packaged', 'label' => 'HL emballé (livré)', 'value' => round($hlPackaged, 1)];
    }
    if ($yieldLoss !== null) {
        $breakdown[] = ['key' => 'yield_loss',  'label' => 'Perte process (HL)', 'value' => $yieldLoss];
    }
    if ($row['eau_m3'] !== null) {
        $breakdown[] = ['key' => 'eau_m3',      'label' => 'Eau consommée (m³)', 'value' => round($row['eau_m3'], 1)];
    }
    if ($row['elec_total_kwh'] !== null) {
        $breakdown[] = ['key' => 'elec_kwh',    'label' => 'Électricité (kWh)',  'value' => (int) round($row['elec_total_kwh'])];
    }
    if ($row['gaz_kwh'] !== null) {
        $breakdown[] = ['key' => 'gaz_kwh',     'label' => 'Gaz (kWh)',          'value' => (int) round($row['gaz_kwh'])];
    }

    $result = array_merge(kpi_empty_result($label), [
        'value'     => $yieldPct,
        'unit'      => '% rendement',
        'breakdown' => $breakdown,
        'tint'      => match (true) {
            $yieldPct === null => 'neutral',
            $yieldPct >= 90.0  => 'green',
            $yieldPct >= 85.0  => 'amber',
            default            => 'red',
        },
        'meta'      => [
            'period'         => $latest,
            'hl_brewed'      => round($hlBrewed, 1),
            'hl_packaged'    => round($hlPackaged, 1),
            'yield_pct'      => $yieldPct,
            'eau_m3'         => $row['eau_m3'] !== null ? round($row['eau_m3'], 1) : null,
            'elec_total_kwh' => $row['elec_total_kwh'] !== null ? (int) round($row['elec_total_kwh']) : null,
            'gaz_kwh'        => $row['gaz_kwh'] !== null ? (int) round($row['gaz_kwh']) : null,
            'source_hl'      => 'bd_brewing_gravity_v2 (Cooling) + bd_packaging_v2',
            'source_energy'  => 'inv_energydata (delta mensuel)',
        ],
    ]);
    return kpi_cache_set($cacheKey, $result);
}
