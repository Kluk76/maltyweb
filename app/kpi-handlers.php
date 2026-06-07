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
    'ytd',
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
            'utilities'    => kpi_stub_handler($domain, $handler, $label),
            'tanks'        => kpi_handler_tanks($handler, $params, $label, $pdo),
            'racking'      => kpi_handler_racking($handler, $params, $label, $pdo),
            'packaging'    => kpi_handler_packaging($handler, $params, $label, $pdo),
            'fg_stock'     => kpi_stub_handler($domain, $handler, $label),
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
    return ['utilities', 'fg_stock', 'sales', 'qa_qc', 'equipment', 'logistics'];
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

    return array_merge(kpi_empty_result($label, 'CHF/HL'), [
        'value'       => round($perHL, 2),
        'delta'       => $delta,
        'delta_label' => $deltaLabel,
        'tint'        => 'neutral',  // directional: lower is better but not inherently bad
        'series'      => $series,
        'meta'        => [
            'period_label' => $month['monthKey'],
            'hl_brewed'    => round($hl, 1),
        ],
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

    return array_merge(kpi_empty_result($label, 'CHF'), [
        'value' => round($total, 2),
        'tint'  => 'neutral',
        'meta'  => ['period_label' => $month['monthKey']],
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

    return array_merge(kpi_empty_result($label, 'CHF/HL'), [
        'value'     => $total,
        'tint'      => 'neutral',
        'breakdown' => [
            ['key' => 'malt',        'label' => 'Malt',        'value' => round($maltsPerHL, 2)],
            ['key' => 'hops',        'label' => 'Houblon',     'value' => round($hopsPerHL, 2)],
            ['key' => 'ingredients', 'label' => 'Ingrédients', 'value' => round($ingPerHL, 2)],
        ],
        'meta' => ['period_label' => $month['monthKey']],
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

    return array_merge(kpi_empty_result($label, 'CHF'), [
        'value'     => round($total, 2),
        'breakdown' => $breakdown,
        'tint'      => 'neutral',
        'meta'      => ['period_label' => $month['monthKey']],
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
        'hl_brewed_period'     => kpi_wort_hl_brewed($params, $label, $pdo),
        'hl_brewed_ytd'        => kpi_wort_hl_brewed_ytd($label, $pdo),
        'brew_count_period'    => kpi_wort_brew_count($params, $label, $pdo),
        'avg_hl_per_brew'      => kpi_wort_avg_hl_per_brew($params, $label, $pdo),
        'production_by_beer_yoy' => kpi_wort_production_by_beer_yoy($label, $pdo),
        'brewhouse_yield'      => kpi_stub_handler('wort', $handler, $label), // no target_hl in ref_recipes; stays stub
        'days_since_last_brew' => kpi_wort_days_since_last_brew($label, $pdo),
        'beer_loss_cascade'    => kpi_stub_handler('wort', $handler, $label), // needs racking+packaging delta data
        default                => kpi_stub_handler('wort', $handler, $label),
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
        'racking_yield_vs_target'   => kpi_stub_handler('racking', $handler, $label), // no target HL in ref_recipes
        'tank_emptying_efficiency'  => kpi_stub_handler('racking', $handler, $label), // no flowmeter data in bd_racking_v2
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
        'fill_efficiency'            => kpi_stub_handler('packaging', $handler, $label), // needs target fill volume per run
        'avg_losses_per_category'    => kpi_stub_handler('packaging', $handler, $label), // semantics unclear: loss by MI category vs by run_type
        'avg_losses_per_sku'         => kpi_stub_handler('packaging', $handler, $label), // loss_kpi_hl is per-run, not per-SKU
        'suggested_packaging_events' => kpi_stub_handler('packaging', $handler, $label), // needs tank-sim port
        'packaging_deviations'       => kpi_stub_handler('packaging', $handler, $label), // needs planned-vs-actual data source
        'packaging_cost_per_unit'    => kpi_stub_handler('packaging', $handler, $label), // needs COGS pipeline integration
        'packaging_material_consumption' => kpi_stub_handler('packaging', $handler, $label), // gap: no PKG material rows in inv_consumption
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
        // Remaining tank occupancy trackers still need tank-sim port:
        'tank_occupancy'          => kpi_stub_handler('tanks', $handler, $label),
        'cct_utilization_pct'     => kpi_stub_handler('tanks', $handler, $label),
        'cct_idle_days'           => kpi_stub_handler('tanks', $handler, $label),
        'hl_in_tank_now'          => kpi_stub_handler('tanks', $handler, $label),
        'garde_vs_target'         => kpi_stub_handler('tanks', $handler, $label),
        'cold_crash_in_progress'  => kpi_stub_handler('tanks', $handler, $label),
        'fermentation_deviations' => kpi_stub_handler('tanks', $handler, $label),
        'suggested_next_brew'     => kpi_stub_handler('tanks', $handler, $label),
        'temp_pressure_excursions' => kpi_stub_handler('tanks', $handler, $label),
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
