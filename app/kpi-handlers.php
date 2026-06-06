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
            'tanks'        => kpi_stub_handler($domain, $handler, $label),
            'racking'      => kpi_stub_handler($domain, $handler, $label),
            'packaging'    => kpi_stub_handler($domain, $handler, $label),
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
        'maintenance_opex'     => kpi_cogs_maintenance_opex($params, $label, $pdo),
        'maintenance_opex_trend' => kpi_cogs_maintenance_opex($params, $label, $pdo),
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
        'production_by_beer_yoy' => kpi_stub_handler('wort', $handler, $label),
        'brewhouse_yield'      => kpi_stub_handler('wort', $handler, $label), // needs efficiency pipeline output
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
        'inventory_days_of_supply'   => kpi_rm_days_of_supply($label, $pdo),
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

/** #267 — Inventory days of supply (RM stock value via v_rm_stock_dynamic) */
function kpi_rm_days_of_supply(string $label, PDO $pdo): array
{
    $cacheKey = 'rm_days_of_supply';
    if (($cached = kpi_cache_get($cacheKey)) !== null) {
        return $cached;
    }

    // v_rm_stock_dynamic.current_value_chf = current_qty × price_chf (already computed).
    // Full days-of-supply (÷ consumption rate) is blocked by the packaging
    // pipeline gap (#58) — consumption_out is zero for PKG_* rows.
    // We return total RM stock value as a partial result.
    $stmt = $pdo->query(
        "SELECT
           SUM(current_value_chf) AS stock_value_chf,
           COUNT(*) AS mi_count
         FROM v_rm_stock_dynamic
         WHERE current_qty > 0"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $stockValue = $row && $row['stock_value_chf'] !== null
                  ? round((float) $row['stock_value_chf'], 2)
                  : null;
    $miCount = $row ? (int) $row['mi_count'] : 0;

    $result = array_merge(kpi_empty_result($label, 'CHF stock'), [
        'value' => $stockValue,
        'tint'  => 'neutral',
        'meta'  => [
            'mi_count' => $miCount,
            'note'     => 'Valeur stock MP. Calcul jours-de-couverture bloqué par gap pipeline packaging (#58).',
        ],
    ]);

    return kpi_cache_set($cacheKey, $result);
}
