<?php
declare(strict_types=1);
/**
 * api/financier-data.php — Lazy per-month data endpoint for Pôle Financier
 *
 * Modules:
 *   cogs        — single month COGS/Ventes slice from sales-cogs-data.json
 *   cop-grid    — GL-account P&L tree for COP tab  (÷ HL brewed)
 *   cogs-grid   — GL-account P&L tree for COGS tab (÷ HL sold)
 *
 * Manager+ only. JSON, never redirects on auth failure.
 *
 * GET ?module=cogs&month=YYYY-MM
 * GET ?module=cop-grid&month=YYYY-MM
 * GET ?module=cogs-grid&month=YYYY-MM
 * GET ?module=cogs-grid&month=YYYY-MM&ytd=1   — includes ytd sums
 * GET ?module=cop-grid&month=YYYY-MM&ytd=1    — includes 6M rolling sums
 * GET ?module=gl-months                        — list of booked GL months
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/kpi-handlers.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/settings.php';   // date_display_format() for BOM freshness
require_once __DIR__ . '/../../app/utilities-estimate.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* ── Auth gate — manager+ only, JSON error on failure ─────────────────────── */
$u = current_user();
if ($u === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'unauthenticated']);
    exit;
}
if (_role_rank($u['role'] ?? '') < _role_rank('manager')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'reason' => 'insufficient_role']);
    exit;
}

/* ── Input validation ─────────────────────────────────────────────────────── */
$module = $_GET['module'] ?? '';
$month  = $_GET['month']  ?? '';

$allowedModules = ['cogs', 'cop-grid', 'cogs-grid', 'gl-months', 'sku-detail'];
if (!in_array($module, $allowedModules, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'reason' => 'unknown_module']);
    exit;
}

/* ── gl-months: list of YYYY-MM keys present in inv_charges_bc ────────────── */
if ($module === 'gl-months') {
    try {
        $pdo   = maltytask_pdo();
        $rows  = $pdo->query(
            "SELECT DISTINCT period_text FROM inv_charges_bc WHERE is_summary = 0 ORDER BY period_text"
        )->fetchAll(PDO::FETCH_COLUMN);
        $months = [];
        foreach ($rows as $pt) {
            $mk = fin_period_text_to_month_key($pt);
            if ($mk !== null && !in_array($mk, $months, true)) {
                $months[] = $mk;
            }
        }
        sort($months);
        echo json_encode(['ok' => true, 'months' => $months], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'reason' => 'db_error']);
    }
    exit;
}

/* ── Module: sku-detail — BOM decomposition for one SKU ──────────────────── */
if ($module === 'sku-detail') {
    $skuCode = $_GET['sku'] ?? '';
    // Sanitise: alphanumeric + hyphen/underscore, max 32 chars (matches all real SKU codes)
    if (!preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $skuCode)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'reason' => 'invalid_sku']);
        exit;
    }
    try {
        $pdo = maltytask_pdo();

        /* Resolve sku_code → sku_id (active SKUs only) */
        $skuStmt = $pdo->prepare(
            "SELECT s.id, s.sku_code, s.format, s.unit_label, s.hl_per_unit,
                    rr.recipe_short_name, rr.classification,
                    ROUND(SUM(CASE WHEN b.source = 'Brewing'   THEN b.cost ELSE 0 END), 3) AS brewing_subtotal,
                    ROUND(SUM(CASE WHEN b.source = 'Packaging' THEN b.cost ELSE 0 END), 3) AS packaging_subtotal,
                    ROUND(SUM(b.cost), 3) AS total,
                    ROUND(SUM(b.cost) / NULLIF(s.hl_per_unit, 0), 2) AS chf_per_hl
             FROM ref_skus s
             LEFT JOIN ref_sku_bom  b  ON b.sku_id  = s.id
             LEFT JOIN ref_recipes  rr ON rr.id     = s.recipe_id
             WHERE s.sku_code = :sku AND s.is_active = 1
             GROUP BY s.id"
        );
        $skuStmt->execute([':sku' => $skuCode]);
        $skuRow = $skuStmt->fetch(PDO::FETCH_ASSOC);

        if (!$skuRow) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'reason' => 'sku_not_found']);
            exit;
        }

        /* BOM lines — VERBATIM from sku-cost-detail.php */
        $bomStmt = $pdo->prepare(
            "SELECT b.source, b.category_raw, b.ingredient_raw,
                    b.qty_per_unit, b.ing_unit,
                    b.pricing_unit, b.price, b.currency, b.cost,
                    b.mi_id, b.resolution,
                    m.mi_id      AS mi_canonical,
                    c.name       AS category_canonical
             FROM ref_sku_bom b
             LEFT JOIN ref_mi m            ON m.id = b.mi_id
             LEFT JOIN ref_mi_categories c ON c.id = m.category_id
             WHERE b.sku_id = :sku_id
             ORDER BY
                 CASE b.source WHEN 'Brewing' THEN 1 WHEN 'Packaging' THEN 2 ELSE 3 END,
                 b.cost DESC"
        );
        $bomStmt->execute([':sku_id' => $skuRow['id']]);
        $bomLines = $bomStmt->fetchAll(PDO::FETCH_ASSOC);

        /* BOM freshness: MAX(compiled_at) for this sku_id */
        $freshStmt = $pdo->prepare(
            "SELECT MAX(compiled_at) AS compiled_at FROM ref_sku_bom WHERE sku_id = :sku_id"
        );
        $freshStmt->execute([':sku_id' => $skuRow['id']]);
        $freshRow   = $freshStmt->fetch(PDO::FETCH_ASSOC);
        $freshness  = (!empty($freshRow['compiled_at']))
            ? (new DateTimeImmutable($freshRow['compiled_at']))->format(date_display_format())
            : null;

        /* Normalise numeric fields so JS receives proper types */
        foreach ($bomLines as &$line) {
            foreach (['qty_per_unit','price','cost'] as $f) {
                if (isset($line[$f]) && is_string($line[$f])) {
                    $line[$f] = $line[$f] !== '' ? (float) $line[$f] : null;
                }
            }
            if (isset($line['mi_id'])) {
                $line['mi_id'] = $line['mi_id'] !== null ? (int) $line['mi_id'] : null;
            }
        }
        unset($line);

        $payload = [
            'ok'                 => true,
            'sku_code'           => $skuRow['sku_code'],
            'recipe_short_name'  => $skuRow['recipe_short_name'],
            'format'             => $skuRow['format'],
            'unit_label'         => $skuRow['unit_label'],
            'hl_per_unit'        => $skuRow['hl_per_unit'] !== null ? (float) $skuRow['hl_per_unit'] : null,
            'brewing_subtotal'   => $skuRow['brewing_subtotal'] !== null ? (float) $skuRow['brewing_subtotal'] : 0.0,
            'packaging_subtotal' => $skuRow['packaging_subtotal'] !== null ? (float) $skuRow['packaging_subtotal'] : 0.0,
            'total'              => $skuRow['total'] !== null ? (float) $skuRow['total'] : 0.0,
            'chf_per_hl'         => $skuRow['chf_per_hl'] !== null ? (float) $skuRow['chf_per_hl'] : null,
            'freshness'          => $freshness,
            'lines'              => $bomLines,
        ];
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'reason' => 'db_error']);
    }
    exit;
}

/* ── Validate YYYY-MM for remaining modules ───────────────────────────────── */
if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $month)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'reason' => 'invalid_month']);
    exit;
}

/* ── Module: cogs (existing) ──────────────────────────────────────────────── */
if ($module === 'cogs') {
    $raw = kpi_sales_cogs_month_slice($month);
    if ($raw === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'reason' => 'month_not_found', 'month' => $month]);
        exit;
    }
    $bySku = [];
    foreach ($raw['bySKU'] ?? [] as $sku => $sd) {
        $bySku[$sku] = [
            'units'         => $sd['units']         ?? 0,
            'HL'            => $sd['HL']             ?? 0,
            'material_CHF'  => $sd['material_CHF']   ?? 0,
            'beerTax_CHF'   => $sd['beerTax_CHF']    ?? 0,
            'salesCOGS_CHF' => $sd['salesCOGS_CHF']  ?? 0,
            'revenueCHF'    => $sd['revenueCHF']     ?? 0,
            'unitCost'      => $sd['unitCost']       ?? 0,
            'hlPerUnit'     => $sd['hlPerUnit']      ?? 0,
            'beerTaxCat'    => $sd['beerTaxCat']     ?? null,
        ];
    }
    $payload = [
        'ok'          => true,
        'month'       => $month,
        'totals'      => $raw['totals']      ?? [],
        'bySKU'       => $bySku,
        'beerTax'     => $raw['beerTax']     ?? [],
        'unknownSKUs' => $raw['unknownSKUs'] ?? [],
        'nonBeerSKUs' => $raw['nonBeerSKUs'] ?? [],
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

/* ── Module: cop-grid — Operational feed (primary) + booked GL (secondary) ── */
if ($module === 'cop-grid') {
    try {
        $pdo = maltytask_pdo();

        /* ── A) OPERATIONAL: load feed, build per-line tree ──────────────────── */
        $feedData = kpi_load_cogs_json();
        if ($feedData === null) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'reason' => 'feed_unavailable']);
            exit;
        }

        // Index feed by monthKey for O(1) lookups
        $feedByMonth = [];
        foreach ($feedData['months'] ?? [] as $entry) {
            $mk = $entry['monthKey'] ?? null;
            if ($mk !== null) $feedByMonth[$mk] = $entry;
        }

        // Build operational month data
        $opMonth = fin_cop_operational_month($feedByMonth, $month, $pdo);

        // YTD: sum all months Jan..selected that are present in the feed
        [$selYear] = explode('-', $month, 2);
        $ytdMonthsOp = array_filter(
            array_keys($feedByMonth),
            fn($mk) => str_starts_with($mk, $selYear . '-') && $mk <= $month
        );
        sort($ytdMonthsOp);
        $opYtd = fin_cop_operational_ytd($feedByMonth, array_values($ytdMonthsOp), $pdo);

        /* ── B) BOOKED GL: compact section-level totals only ─────────────────── */
        // Month
        [$year, $mo] = explode('-', $month, 2);
        $yy = substr($year, 2);
        $likePattern = '%01.' . $mo . '.' . $yy . '%';

        $stmtBk = $pdo->prepare(
            "SELECT gl_account_no,
                    SUM(debit_amount) - SUM(credit_amount) AS net
             FROM inv_charges_bc
             WHERE is_summary = 0
               AND period_text LIKE ?
             GROUP BY gl_account_no"
        );
        $stmtBk->execute([$likePattern]);
        $glNetsBooked = [];
        while ($row = $stmtBk->fetch(PDO::FETCH_ASSOC)) {
            $glNetsBooked[$row['gl_account_no']] = (float) $row['net'];
        }

        // YTD booked: intersect with GL-booked months
        $glBookedMonths = fin_gl_booked_months($pdo);
        $glBookedSet    = array_flip($glBookedMonths);
        $ytdMonthsBk    = array_filter(
            array_values($ytdMonthsOp),
            fn($mk) => isset($glBookedSet[$mk])
        );
        $ytdMonthsBk = array_values($ytdMonthsBk);

        $glNetsBookedYtd = [];
        if (!empty($ytdMonthsBk)) {
            $ytdLikes = [];
            foreach ($ytdMonthsBk as $mk) {
                [$y2, $m2] = explode('-', $mk, 2);
                $ytdLikes[] = '01.' . $m2 . '.' . substr($y2, 2);
            }
            $orClauses  = implode(' OR ', array_fill(0, count($ytdLikes), 'period_text LIKE ?'));
            $stmtBkYtd  = $pdo->prepare(
                "SELECT gl_account_no, SUM(debit_amount) - SUM(credit_amount) AS net
                 FROM inv_charges_bc
                 WHERE is_summary = 0 AND ($orClauses)
                 GROUP BY gl_account_no"
            );
            $stmtBkYtd->execute(array_map(fn($p) => '%' . $p . '%', $ytdLikes));
            while ($row = $stmtBkYtd->fetch(PDO::FETCH_ASSOC)) {
                $glNetsBookedYtd[$row['gl_account_no']] = (float) $row['net'];
            }
        }

        $booked    = fin_cop_booked_totals($glNetsBooked,    $opMonth['hlPackaged']);
        $bookedYtd = fin_cop_booked_totals($glNetsBookedYtd, $opYtd['hlPackaged']);

        /* ── C) CHECK: operational total CHF − booked total CHF ─────────────── */
        $check = [
            'month' => [
                'opTotalChf'     => $opMonth['totalVariableChf'],
                'bookedTotalChf' => $booked['totalChf'],
                'diffChf'        => $opMonth['totalVariableChf'] - $booked['totalChf'],
            ],
            'ytd' => [
                'opTotalChf'     => $opYtd['totalVariableChf'],
                'bookedTotalChf' => $bookedYtd['totalChf'],
                'diffChf'        => $opYtd['totalVariableChf'] - $bookedYtd['totalChf'],
            ],
        ];

        /* ── D) N-1: same month/YTD window but in prior year ───────────────────── */
        $n1MonthKey = fin_prior_year_month($month);
        $n1YtdMonths = array_map('fin_prior_year_month', array_values($ytdMonthsOp));

        $n1Month = fin_cop_operational_month($feedByMonth, $n1MonthKey, $pdo);
        $n1Ytd   = fin_cop_operational_ytd($feedByMonth, $n1YtdMonths, $pdo);

        $payload = [
            'ok'           => true,
            'month'        => $month,
            'ytdLabel'     => 'YTD',
            'ytdMonths'    => array_values($ytdMonthsOp),
            'operational'  => [
                'month' => $opMonth,
                'ytd'   => $opYtd,
            ],
            'n1'           => [
                'month' => $n1Month,
                'ytd'   => $n1Ytd,
            ],
            'booked'       => [
                'month' => $booked,
                'ytd'   => $bookedYtd,
            ],
            'check'        => $check,
        ];
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'reason' => 'db_error', 'detail' => $e->getMessage()]);
    }
    exit;
}

/* ── Module: cogs-grid — Operational feed (primary) + booked GL (secondary) ── */
try {
    $pdo = maltytask_pdo();

    /* ── A) OPERATIONAL: load sales-cogs feed, build per-line tree ───────────── */
    $salesFeedData = fin_load_sales_cogs_json();
    if ($salesFeedData === null) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'reason' => 'feed_unavailable']);
        exit;
    }

    // Index by monthKey for O(1) lookups
    $salesByMonth = $salesFeedData['months'] ?? [];

    // Load budget CHF/HL map keyed by line_key (fiscal_year derived from $month)
    [$selYear] = explode('-', $month, 2);
    $budgetMap = fin_load_budget_cogs($pdo, (int) $selYear);

    // Build operational month data
    $opMonth = fin_cogs_operational_month($salesByMonth, $month, $budgetMap, $pdo);

    // YTD: sum all months Jan..selected present in the feed
    $ytdMonthsOp = array_filter(
        array_keys($salesByMonth),
        fn($mk) => str_starts_with($mk, $selYear . '-') && $mk <= $month
    );
    sort($ytdMonthsOp);
    $opYtd = fin_cogs_operational_ytd($salesByMonth, array_values($ytdMonthsOp), $budgetMap, $pdo);

    /* ── B) BOOKED GL: compact section-level totals (secondary / Vue comptable) */
    [$year, $mo] = explode('-', $month, 2);
    $yy = substr($year, 2);
    $likePattern = '%01.' . $mo . '.' . $yy . '%';

    $stmtBk = $pdo->prepare(
        "SELECT gl_account_no,
                SUM(debit_amount) - SUM(credit_amount) AS net
         FROM inv_charges_bc
         WHERE is_summary = 0
           AND period_text LIKE ?
         GROUP BY gl_account_no"
    );
    $stmtBk->execute([$likePattern]);
    $glNetsBooked = [];
    while ($row = $stmtBk->fetch(PDO::FETCH_ASSOC)) {
        $glNetsBooked[$row['gl_account_no']] = (float) $row['net'];
    }

    // YTD booked: intersect operational YTD months with GL-booked months
    $glBookedMonths = fin_gl_booked_months($pdo);
    $glBookedSet    = array_flip($glBookedMonths);
    $ytdMonthsBk    = array_values(array_filter(
        array_values($ytdMonthsOp),
        fn($mk) => isset($glBookedSet[$mk])
    ));

    $glNetsBookedYtd = [];
    if (!empty($ytdMonthsBk)) {
        $ytdLikes = [];
        foreach ($ytdMonthsBk as $mk) {
            [$y2, $m2] = explode('-', $mk, 2);
            $ytdLikes[] = '01.' . $m2 . '.' . substr($y2, 2);
        }
        $orClauses  = implode(' OR ', array_fill(0, count($ytdLikes), 'period_text LIKE ?'));
        $stmtBkYtd  = $pdo->prepare(
            "SELECT gl_account_no, SUM(debit_amount) - SUM(credit_amount) AS net
             FROM inv_charges_bc
             WHERE is_summary = 0 AND ($orClauses)
             GROUP BY gl_account_no"
        );
        $stmtBkYtd->execute(array_map(fn($p) => '%' . $p . '%', $ytdLikes));
        while ($row = $stmtBkYtd->fetch(PDO::FETCH_ASSOC)) {
            $glNetsBookedYtd[$row['gl_account_no']] = (float) $row['net'];
        }
    }

    $booked    = fin_cogs_booked_totals($glNetsBooked,    $opMonth['hlSold']);
    $bookedYtd = fin_cogs_booked_totals($glNetsBookedYtd, $opYtd['hlSold']);

    /* ── C) CHECK: operational total CHF − booked total CHF ──────────────────── */
    $check = [
        'month' => [
            'opTotalChf'     => $opMonth['totalVariableChf'],
            'bookedTotalChf' => $booked['totalChf'],
            'diffChf'        => $opMonth['totalVariableChf'] - $booked['totalChf'],
        ],
        'ytd' => [
            'opTotalChf'     => $opYtd['totalVariableChf'],
            'bookedTotalChf' => $bookedYtd['totalChf'],
            'diffChf'        => $opYtd['totalVariableChf'] - $bookedYtd['totalChf'],
        ],
    ];

    /* ── D) N-1: same month/YTD window but in prior year ───────────────────── */
    $n1MonthKey  = fin_prior_year_month($month);
    $n1YtdMonths = array_map('fin_prior_year_month', array_values($ytdMonthsOp));

    $n1Month = fin_cogs_operational_month($salesByMonth, $n1MonthKey, [], $pdo);
    $n1Ytd   = fin_cogs_operational_ytd($salesByMonth, $n1YtdMonths, [], $pdo);

    $payload = [
        'ok'           => true,
        'month'        => $month,
        'ytdLabel'     => 'YTD',
        'ytdMonths'    => array_values($ytdMonthsOp),
        'operational'  => [
            'month' => $opMonth,
            'ytd'   => $opYtd,
        ],
        'n1'           => [
            'month' => $n1Month,
            'ytd'   => $n1Ytd,
        ],
        'booked'       => [
            'month' => $booked,
            'ytd'   => $bookedYtd,
        ],
        'check'        => $check,
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'reason' => 'db_error', 'detail' => $e->getMessage()]);
}
exit;

/* ══════════════════════════════════════════════════════════════════════════════
   HELPER FUNCTIONS — all top-level (never nested)
══════════════════════════════════════════════════════════════════════════════ */

/**
 * Return the prior-year equivalent of a YYYY-MM month key.
 * e.g. '2026-04' → '2025-04'. Pure string arithmetic, no DateTime needed.
 */
function fin_prior_year_month(string $monthKey): string
{
    [$year, $mo] = explode('-', $monthKey, 2);
    return ((int)$year - 1) . '-' . $mo;
}

/**
 * Convert 'Période : 01.MM.YY..DD.MM.YY' to 'YYYY-MM' or null if unparseable.
 */
function fin_period_text_to_month_key(string $pt): ?string
{
    // Match the opening date: 01.MM.YY
    if (!preg_match('/01\.(\d{2})\.(\d{2})/', $pt, $m)) return null;
    $mo   = $m[1];
    $year = '20' . $m[2];
    return $year . '-' . $mo;
}

/**
 * Load hlBrewed for a monthKey from COP JSON (same source as COP tiles).
 */
function fin_cop_hl_brewed(string $monthKey): float
{
    static $copCache = null;
    if ($copCache === null) {
        $path = '/var/www/maltytask/interfaces/cogs-report-data.json';
        if (!is_readable($path)) { $copCache = []; return 0.0; }
        $data = json_decode(file_get_contents($path), true);
        $copCache = [];
        foreach ($data['months'] ?? [] as $mo) {
            $mk = $mo['monthKey'] ?? null;
            if ($mk === null) continue;
            $copCache[$mk] = (float) ($mo['cop']['hlBrewed'] ?? 0);
        }
    }
    return $copCache[$monthKey] ?? 0.0;
}

/**
 * Board line definitions for the operational COP tree.
 * Each entry: label, gl (array of account strings), key (for subtotal refs).
 * Subtotals reference their constituent line keys.
 */
function fin_cop_board_lines(): array
{
    return [
        // Brewing
        ['type' => 'line',     'key' => 'malts',         'label' => 'Malts (4101)',                       'gl' => ['4101']],
        ['type' => 'line',     'key' => 'hops',          'label' => 'Hops (4102)',                        'gl' => ['4102']],
        ['type' => 'line',     'key' => 'yeast',         'label' => 'Yeast (4103)',                       'gl' => ['4103']],
        ['type' => 'line',     'key' => 'other_ing',     'label' => 'Other Ingredients (4104)',           'gl' => ['4104']],
        ['type' => 'subtotal', 'key' => 'sub_brewing',   'label' => 'Total Brewing',                     'lines' => ['malts','hops','yeast','other_ing']],
        // Packaging
        ['type' => 'line',     'key' => 'bottles',       'label' => 'Bottles inc. TEA (4200+4201)',       'gl' => ['4200','4201']],
        ['type' => 'line',     'key' => 'cans',          'label' => 'Cans (4202)',                        'gl' => ['4202']],
        ['type' => 'line',     'key' => 'reg_cardboard', 'label' => 'Regular cardboard (4203+4207)',      'gl' => ['4203','4207']],
        ['type' => 'line',     'key' => 'spec_cardboard','label' => 'Special cardboard (4204)',           'gl' => ['4204']],
        ['type' => 'line',     'key' => 'plastic',       'label' => 'Plastic wrap (4205)',                'gl' => ['4205']],
        ['type' => 'line',     'key' => 'labels',        'label' => 'Labels (4206)',                      'gl' => ['4206']],
        ['type' => 'line',     'key' => 'keg',           'label' => 'Keg material (4209)',                'gl' => ['4209']],
        ['type' => 'line',     'key' => 'external',      'label' => 'External packing (4208)',            'gl' => ['4208']],
        ['type' => 'subtotal', 'key' => 'sub_packaging', 'label' => 'Total Packaging',
         'lines' => ['bottles','cans','reg_cardboard','spec_cardboard','plastic','labels','keg','external']],
        // Indirect
        ['type' => 'line',     'key' => 'co2',           'label' => 'CO2 (4300)',                         'gl' => ['4300']],
        ['type' => 'line',     'key' => 'chemical',      'label' => 'Chemical (4301)',                    'gl' => ['4301']],
        ['type' => 'line',     'key' => 'small_equip',   'label' => 'Small equipment (4302)',             'gl' => ['4302']],
        ['type' => 'line',     'key' => 'transport',     'label' => 'Transport (4600)',                   'gl' => ['4600']],
        ['type' => 'subtotal', 'key' => 'sub_indirect',  'label' => 'Total Indirect',                    'lines' => ['co2','chemical','small_equip','transport']],
        // Utilities
        ['type' => 'line',     'key' => 'gas_water',     'label' => 'Gaz & Water (4700)',                 'gl' => ['4700']],
        ['type' => 'line',     'key' => 'electricity',   'label' => 'Electricity (4702)',                 'gl' => ['4702']],
        ['type' => 'line',     'key' => 'waste',         'label' => 'Waste evacuation (4701)',            'gl' => ['4701']],
        ['type' => 'subtotal', 'key' => 'sub_utilities', 'label' => 'Total Utilities',                   'lines' => ['gas_water','electricity','waste']],
        // R&D
        ['type' => 'line',     'key' => 'qa_qc',         'label' => 'QA/QC (4500)',                       'gl' => ['4500']],
        ['type' => 'line',     'key' => 'rd_purchases',  'label' => 'Purchases (4510)',                   'gl' => ['4510']],
        ['type' => 'subtotal', 'key' => 'sub_rd',        'label' => 'Total R&D',                         'lines' => ['qa_qc','rd_purchases']],
        // Grand total
        ['type' => 'grand_subtotal', 'key' => 'grand_variable', 'label' => 'TOTAL COGS VARIABLE',
         'subtotals' => ['sub_brewing','sub_packaging','sub_indirect','sub_utilities','sub_rd']],
    ];
}

/**
 * Resolve CHF values for one period from glLines and return the tree rows.
 * Returns ['lines' => [...], 'hlPackaged' => float, 'totalVariableChf' => float].
 * When $pdo is supplied, overrides 4700/4702 from the live PHP estimator.
 */
function fin_cop_operational_month(array $feedByMonth, string $monthKey, ?PDO $pdo = null): array
{
    $entry    = $feedByMonth[$monthKey] ?? null;
    $glLines  = (array) ($entry['cop']['glLines'] ?? []);
    $hlPkg    = (float) ($entry['cop']['hlPackaged'] ?? 0.0);

    if ($pdo !== null) {
        $utilEst = _fin_cop_try_estimate($pdo, $monthKey);
        if ($utilEst !== null) {
            $glLines['4700'] = $utilEst['gas_water'];
            $glLines['4702'] = $utilEst['electricity'];
        }
    }

    return fin_cop_build_op_rows($glLines, $hlPkg);
}

/**
 * Aggregate multiple months from the feed into a YTD slice.
 * When $pdo is supplied, overrides 4700/4702 per month from the live PHP estimator.
 */
function fin_cop_operational_ytd(array $feedByMonth, array $monthKeys, ?PDO $pdo = null): array
{
    $glSums = [];
    $hlPkg  = 0.0;
    foreach ($monthKeys as $mk) {
        $entry = $feedByMonth[$mk] ?? null;
        if ($entry === null) continue;
        $hlPkg += (float) ($entry['cop']['hlPackaged'] ?? 0.0);
        $glLines = (array) ($entry['cop']['glLines'] ?? []);
        if ($pdo !== null) {
            $utilEst = _fin_cop_try_estimate($pdo, $mk);
            if ($utilEst !== null) {
                $glLines['4700'] = $utilEst['gas_water'];
                $glLines['4702'] = $utilEst['electricity'];
            }
        }
        foreach ($glLines as $acc => $val) {
            $glSums[(string)$acc] = ($glSums[(string)$acc] ?? 0.0) + (float)$val;
        }
    }
    return fin_cop_build_op_rows($glSums, $hlPkg);
}

/**
 * Build the operational board rows from a glLines map + hlPackaged.
 * Returns ['lines' => [...], 'hlPackaged' => float, 'totalVariableChf' => float].
 */
function fin_cop_build_op_rows(array $glLines, float $hlPkg): array
{
    $boardDef = fin_cop_board_lines();

    // First pass: resolve CHF per key
    $vals = [];
    foreach ($boardDef as $node) {
        $key  = $node['key'];
        $type = $node['type'];
        if ($type === 'line') {
            $sum = 0.0;
            foreach ($node['gl'] as $acc) {
                $sum += (float)($glLines[$acc] ?? 0.0);
            }
            $vals[$key] = $sum;
        } elseif ($type === 'subtotal') {
            $sum = 0.0;
            foreach ($node['lines'] as $lk) {
                $sum += $vals[$lk] ?? 0.0;
            }
            $vals[$key] = $sum;
        } elseif ($type === 'grand_subtotal') {
            $sum = 0.0;
            foreach ($node['subtotals'] as $sk) {
                $sum += $vals[$sk] ?? 0.0;
            }
            $vals[$key] = $sum;
        }
    }

    // Second pass: build output rows
    $rows = [];
    foreach ($boardDef as $node) {
        $key  = $node['key'];
        $chf  = $vals[$key] ?? 0.0;
        $phl  = $hlPkg > 0 ? $chf / $hlPkg : null;
        $rows[] = [
            'type'        => $node['type'],
            'key'         => $key,
            'label'       => $node['label'],
            'chf'         => round($chf, 2),
            'perHl'       => $phl !== null ? round($phl, 4) : null,
        ];
    }

    $totalChf = $vals['grand_variable'] ?? 0.0;
    return [
        'lines'            => $rows,
        'hlPackaged'       => round($hlPkg, 2),
        'totalVariableChf' => round($totalChf, 2),
    ];
}

/**
 * Compute section-level booked GL totals (compact, for Vue comptable block).
 * GL ranges:
 *   Brewing:   4101–4104
 *   Packaging: 4200–4299
 *   Indirect:  4300–4302, 4600
 *   Utilities: 4700–4702
 *   R&D:       4500–4510
 */
function fin_cop_booked_totals(array $glNets, float $hlPkg): array
{
    $brewing   = 0.0;
    $packaging = 0.0;
    $indirect  = 0.0;
    $utilities = 0.0;
    $rd        = 0.0;

    foreach ($glNets as $acc => $net) {
        $a = (int) $acc;
        if ($a >= 4101 && $a <= 4104)          $brewing   += $net;
        elseif ($a >= 4200 && $a <= 4299)      $packaging += $net;
        elseif (($a >= 4300 && $a <= 4302) || ($a >= 4600 && $a <= 4602)) $indirect  += $net;
        elseif ($a >= 4700 && $a <= 4702)      $utilities += $net;
        elseif ($a >= 4500 && $a <= 4510)      $rd        += $net;
    }

    $total = $brewing + $packaging + $indirect + $utilities + $rd;

    $phl = fn(float $v): ?float => $hlPkg > 0 ? round($v / $hlPkg, 4) : null;

    return [
        'brewing'   => ['chf' => round($brewing,   2), 'perHl' => $phl($brewing)],
        'packaging' => ['chf' => round($packaging, 2), 'perHl' => $phl($packaging)],
        'indirect'  => ['chf' => round($indirect,  2), 'perHl' => $phl($indirect)],
        'utilities' => ['chf' => round($utilities, 2), 'perHl' => $phl($utilities)],
        'rd'        => ['chf' => round($rd,        2), 'perHl' => $phl($rd)],
        'totalChf'  => round($total, 2),
        'totalPerHl'=> $phl($total),
        'hlPackaged'=> round($hlPkg, 2),
    ];
}

/* ── COGS-grid helpers (operational, sold-driven) ─────────────────────────── */

/**
 * Load budget CHF/HL rows from ref_budget_cogs for a given fiscal year.
 * Returns ['line_key' => float budget_chf_per_hl, …].
 * Falls back to [] on DB error so the grid degrades gracefully (shows '—' for budget cols).
 */
function fin_load_budget_cogs(PDO $pdo, int $fiscalYear): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT line_key, budget_chf_per_hl
               FROM ref_budget_cogs
              WHERE fiscal_year = ?'
        );
        $stmt->execute([$fiscalYear]);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[$row['line_key']] = (float) $row['budget_chf_per_hl'];
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Load the sales-cogs-data.json feed.
 * Returns the full decoded array, or null if the file is missing/unreadable.
 */
function fin_load_sales_cogs_json(): ?array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $path = '/var/www/maltytask/interfaces/sales-cogs-data.json';
    if (!is_readable($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) return null;
    $cache = $data;
    return $cache;
}

/**
 * Board line definitions for the operational COGS tree.
 * Mirrors fin_cop_board_lines() but uses ÷ HL sold as denominator.
 * Includes a beer-tax line below TOTAL COGS VARIABLE.
 */
function fin_cogs_board_lines(): array
{
    return [
        // Brewing
        ['type' => 'line',     'key' => 'malts',         'label' => 'Malts (4101)',                       'gl' => ['4101']],
        ['type' => 'line',     'key' => 'hops',          'label' => 'Hops (4102)',                        'gl' => ['4102']],
        ['type' => 'line',     'key' => 'yeast',         'label' => 'Yeast (4103)',                       'gl' => ['4103']],
        ['type' => 'line',     'key' => 'other_ing',     'label' => 'Other Ingredients (4104)',           'gl' => ['4104']],
        ['type' => 'subtotal', 'key' => 'sub_brewing',   'label' => 'Total Brewing',                     'lines' => ['malts','hops','yeast','other_ing']],
        // Packaging
        ['type' => 'line',     'key' => 'bottles',       'label' => 'Bottles inc. TEA (4200+4201)',       'gl' => ['4200','4201']],
        ['type' => 'line',     'key' => 'cans',          'label' => 'Cans (4202)',                        'gl' => ['4202']],
        ['type' => 'line',     'key' => 'reg_cardboard', 'label' => 'Regular cardboard (4203+4207)',      'gl' => ['4203','4207']],
        ['type' => 'line',     'key' => 'spec_cardboard','label' => 'Special cardboard (4204)',           'gl' => ['4204']],
        ['type' => 'line',     'key' => 'plastic',       'label' => 'Plastic wrap (4205)',                'gl' => ['4205']],
        ['type' => 'line',     'key' => 'labels',        'label' => 'Labels (4206)',                      'gl' => ['4206']],
        ['type' => 'line',     'key' => 'keg',           'label' => 'Keg material (4209)',                'gl' => ['4209']],
        ['type' => 'subtotal', 'key' => 'sub_packaging', 'label' => 'Total Packaging',
         'lines' => ['bottles','cans','reg_cardboard','spec_cardboard','plastic','labels','keg']],
        // Indirect
        ['type' => 'line',     'key' => 'co2',           'label' => 'CO2 (4300)',                         'gl' => ['4300']],
        ['type' => 'line',     'key' => 'chemical',      'label' => 'Chemical (4301)',                    'gl' => ['4301']],
        ['type' => 'line',     'key' => 'small_equip',   'label' => 'Small equipment (4302)',             'gl' => ['4302']],
        ['type' => 'line',     'key' => 'transport',     'label' => 'Transport (4600)',                   'gl' => ['4600']],
        ['type' => 'subtotal', 'key' => 'sub_indirect',  'label' => 'Total Indirect',                    'lines' => ['co2','chemical','small_equip','transport']],
        // Utilities
        ['type' => 'line',     'key' => 'gas_water',     'label' => 'Gaz & Water (4700)',                 'gl' => ['4700']],
        ['type' => 'line',     'key' => 'electricity',   'label' => 'Electricity (4702)',                 'gl' => ['4702']],
        ['type' => 'line',     'key' => 'waste',         'label' => 'Waste evacuation (4701)',            'gl' => ['4701']],
        ['type' => 'subtotal', 'key' => 'sub_utilities', 'label' => 'Total Utilities',                   'lines' => ['gas_water','electricity','waste']],
        // R&D
        ['type' => 'line',     'key' => 'qa_qc',         'label' => 'QA/QC (4500)',                       'gl' => ['4500']],
        ['type' => 'line',     'key' => 'rd_purchases',  'label' => 'Purchases (4510)',                   'gl' => ['4510']],
        ['type' => 'subtotal', 'key' => 'sub_rd',        'label' => 'Total R&D',                         'lines' => ['qa_qc','rd_purchases']],
        // Grand total variable
        ['type' => 'grand_subtotal', 'key' => 'grand_variable', 'label' => 'TOTAL COGS VARIABLE',
         'subtotals' => ['sub_brewing','sub_packaging','sub_indirect','sub_utilities','sub_rd']],
        // Beer tax (own line below variable total — not inside any subtotal)
        ['type' => 'line',     'key' => 'beer_tax',      'label' => 'Taxe bière',                        'gl' => []],
    ];
}

/**
 * Build operational COGS rows for one month from the sales-cogs feed.
 * Returns ['lines' => [...], 'hlSold' => float, 'totalVariableChf' => float].
 * glLines in the feed already contains both material and overhead lines.
 *
 * $budgetMap: ['line_key' => float budget_chf_per_hl, …] — attached to each row.
 * Subtotal/grand_subtotal budget = sum of child line budgets.
 * beer_tax has no budget (null).
 */
function fin_cogs_build_op_rows(array $glLines, float $hlSold, float $beerTaxChf, array $budgetMap = []): array
{
    $boardDef = fin_cogs_board_lines();

    // First pass: resolve CHF per key
    $vals       = [];
    $budgetVals = [];
    foreach ($boardDef as $node) {
        $key  = $node['key'];
        $type = $node['type'];
        if ($type === 'line') {
            if ($key === 'beer_tax') {
                $vals[$key]       = $beerTaxChf;
                $budgetVals[$key] = null; // no budget for beer_tax
            } else {
                $sum = 0.0;
                foreach ($node['gl'] as $acc) {
                    $sum += (float) ($glLines[$acc] ?? 0.0);
                }
                $vals[$key]       = $sum;
                $budgetVals[$key] = isset($budgetMap[$key]) ? (float) $budgetMap[$key] : null;
            }
        } elseif ($type === 'subtotal') {
            $sum = 0.0;
            $bgt = 0.0;
            $bgtNull = false;
            foreach ($node['lines'] as $lk) {
                $sum += $vals[$lk] ?? 0.0;
                if ($budgetVals[$lk] === null) {
                    $bgtNull = true;
                } else {
                    $bgt += (float) ($budgetVals[$lk] ?? 0.0);
                }
            }
            $vals[$key]       = $sum;
            $budgetVals[$key] = $bgtNull ? null : $bgt;
        } elseif ($type === 'grand_subtotal') {
            $sum = 0.0;
            $bgt = 0.0;
            $bgtNull = false;
            foreach ($node['subtotals'] as $sk) {
                $sum += $vals[$sk] ?? 0.0;
                if ($budgetVals[$sk] === null) {
                    $bgtNull = true;
                } else {
                    $bgt += (float) ($budgetVals[$sk] ?? 0.0);
                }
            }
            $vals[$key]       = $sum;
            $budgetVals[$key] = $bgtNull ? null : $bgt;
        }
    }

    // Second pass: build output rows
    $rows = [];
    foreach ($boardDef as $node) {
        $key  = $node['key'];
        $chf  = $vals[$key] ?? 0.0;
        $phl  = $hlSold > 0 ? $chf / $hlSold : null;
        $rows[] = [
            'type'          => $node['type'],
            'key'           => $key,
            'label'         => $node['label'],
            'chf'           => round($chf, 2),
            'perHl'         => $phl !== null ? round($phl, 4) : null,
            'budgetPerHl'   => $budgetVals[$key] !== null ? round((float) $budgetVals[$key], 4) : null,
        ];
    }

    $totalChf = $vals['grand_variable'] ?? 0.0;
    return [
        'lines'            => $rows,
        'hlSold'           => round($hlSold, 2),
        'totalVariableChf' => round($totalChf, 2),
    ];
}

/**
 * Build operational COGS data for a single month from the sales-cogs feed.
 * $salesByMonth = $salesFeedData['months'] (keyed by YYYY-MM).
 * $budgetMap: ['line_key' => float] from fin_load_budget_cogs().
 */
function fin_cogs_operational_month(array $salesByMonth, string $monthKey, array $budgetMap = [], ?PDO $pdo = null): array
{
    $entry     = $salesByMonth[$monthKey] ?? null;
    $glLines   = (array) ($entry['glLines'] ?? []);
    $hlSold    = (float) ($entry['hlSold']  ?? 0.0);
    $beerTax   = (float) ($entry['beerTax']['total'] ?? 0.0);

    if ($pdo !== null) {
        $utilEst = _fin_cop_try_estimate($pdo, $monthKey);
        if ($utilEst !== null) {
            $glLines['4700'] = $utilEst['gas_water'];
            $glLines['4702'] = $utilEst['electricity'];
        }
    }

    return fin_cogs_build_op_rows($glLines, $hlSold, $beerTax, $budgetMap);
}

/**
 * Aggregate multiple months into a YTD COGS slice.
 * Budget CHF/HL is the annual flat rate — same value for both month and YTD.
 */
function fin_cogs_operational_ytd(array $salesByMonth, array $monthKeys, array $budgetMap = [], ?PDO $pdo = null): array
{
    $glSums  = [];
    $hlSold  = 0.0;
    $beerTax = 0.0;
    foreach ($monthKeys as $mk) {
        $entry = $salesByMonth[$mk] ?? null;
        if ($entry === null) continue;
        $hlSold  += (float) ($entry['hlSold']  ?? 0.0);
        $beerTax += (float) ($entry['beerTax']['total'] ?? 0.0);
        $glLines  = (array) ($entry['glLines'] ?? []);
        if ($pdo !== null) {
            $utilEst = _fin_cop_try_estimate($pdo, $mk);
            if ($utilEst !== null) {
                $glLines['4700'] = $utilEst['gas_water'];
                $glLines['4702'] = $utilEst['electricity'];
            }
        }
        foreach ($glLines as $acc => $val) {
            $glSums[(string)$acc] = ($glSums[(string)$acc] ?? 0.0) + (float)$val;
        }
    }
    return fin_cogs_build_op_rows($glSums, $hlSold, $beerTax, $budgetMap);
}

/**
 * Compute section-level booked GL totals for COGS secondary block.
 * Uses hlSold as denominator (not hlPackaged) to match the operational tree.
 */
function fin_cogs_booked_totals(array $glNets, float $hlSold): array
{
    $brewing   = 0.0;
    $packaging = 0.0;
    $indirect  = 0.0;
    $utilities = 0.0;
    $rd        = 0.0;

    foreach ($glNets as $acc => $net) {
        $a = (int) $acc;
        if ($a >= 4101 && $a <= 4104)          $brewing   += $net;
        elseif ($a >= 4200 && $a <= 4299)      $packaging += $net;
        elseif (($a >= 4300 && $a <= 4302) || ($a >= 4600 && $a <= 4602)) $indirect  += $net;
        elseif ($a >= 4700 && $a <= 4702)      $utilities += $net;
        elseif ($a >= 4500 && $a <= 4510)      $rd        += $net;
    }

    $total = $brewing + $packaging + $indirect + $utilities + $rd;
    $phl   = fn(float $v): ?float => $hlSold > 0 ? round($v / $hlSold, 4) : null;

    return [
        'brewing'   => ['chf' => round($brewing,   2), 'perHl' => $phl($brewing)],
        'packaging' => ['chf' => round($packaging, 2), 'perHl' => $phl($packaging)],
        'indirect'  => ['chf' => round($indirect,  2), 'perHl' => $phl($indirect)],
        'utilities' => ['chf' => round($utilities, 2), 'perHl' => $phl($utilities)],
        'rd'        => ['chf' => round($rd,        2), 'perHl' => $phl($rd)],
        'totalChf'  => round($total, 2),
        'totalPerHl'=> $phl($total),
        'hlSold'    => round($hlSold, 2),
        // Alias for JS compatibility with cop-grid renderCopPLGrid (which reads hlPackaged)
        'hlPackaged'=> round($hlSold, 2),
    ];
}

/**
 * Return the list of months for YTD (COGS: same year up to $month)
 * or 6M rolling (COP: last ≤6 booked months including $month).
 *
 * Both the calendar window (COP JSON source) AND the GL-booked set
 * (inv_charges_bc) must contain the month — this keeps the HL denominator
 * aligned with the cost numerator (unbooked months contribute 0 cost but
 * real HL, producing a meaninglessly low CHF/HL figure).
 *
 * @param string   $month         Selected YYYY-MM
 * @param bool     $rolling6m     true = COP 6M-rolling, false = COGS YTD
 * @param string[] $glBookedMonths YYYY-MM months that have inv_charges_bc rows
 */
function fin_ytd_months(string $month, bool $rolling6m, array $glBookedMonths = []): array
{
    static $bookedMonths = null;
    if ($bookedMonths === null) {
        // Load from COP JSON (hlBrewed source for COP denominator)
        $path = '/var/www/maltytask/interfaces/cogs-report-data.json';
        $bookedMonths = [];
        if (is_readable($path)) {
            $data = json_decode(file_get_contents($path), true);
            foreach ($data['months'] ?? [] as $mo) {
                $mk = $mo['monthKey'] ?? null;
                if ($mk !== null) $bookedMonths[] = $mk;
            }
        }
    }

    // Build a lookup set for GL-booked months so array_filter is O(1) per element
    $glBookedSet = array_flip($glBookedMonths);

    if ($rolling6m) {
        // Last ≤6 COP-JSON months that are ≤ $month AND have GL rows
        $eligible = array_filter(
            $bookedMonths,
            fn($mk) => $mk <= $month && isset($glBookedSet[$mk])
        );
        sort($eligible);
        return array_values(array_slice($eligible, -6));
    } else {
        // Same-year months ≤ $month from COP JSON AND with GL rows
        [$year] = explode('-', $month, 2);
        $eligible = array_filter(
            $bookedMonths,
            fn($mk) => str_starts_with($mk, $year . '-') && $mk <= $month && isset($glBookedSet[$mk])
        );
        sort($eligible);
        return array_values($eligible);
    }
}

/**
 * Return YYYY-MM keys for every month that has at least one non-summary row
 * in inv_charges_bc.  Used to align the YTD/rolling HL denominator with the
 * cost numerator (months without GL rows contribute 0 cost but real sold HL,
 * which would produce a meaninglessly low CHF/HL figure).
 */
function fin_gl_booked_months(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT DISTINCT period_text FROM inv_charges_bc WHERE is_summary = 0"
    )->fetchAll(PDO::FETCH_COLUMN);
    $months = [];
    foreach ($rows as $pt) {
        $mk = fin_period_text_to_month_key($pt);
        if ($mk !== null && !in_array($mk, $months, true)) {
            $months[] = $mk;
        }
    }
    sort($months);
    return $months;
}

/**
 * Try to get live utility estimate for a month; returns null on failure (future months, no readings).
 * Returns ['gas_water' => float, 'electricity' => float] or null.
 * 4701 (waste) is intentionally excluded — waste remains booked-only.
 */
function _fin_cop_try_estimate(PDO $pdo, string $monthKey): ?array
{
    return utilities_cop_ht_for_month($pdo, $monthKey);
}

/**
 * The canonical GL line tree definition.
 * Each leaf: ['type'=>'line', 'label'=>…, 'gl'=>[…], 'placeholder'=>bool]
 * Each subtotal: ['type'=>'subtotal', 'label'=>…, 'keys'=>[…]]
 * Each section header: ['type'=>'section', 'label'=>…]
 * Each grand subtotal: ['type'=>'grand_subtotal', 'label'=>…, 'keys'=>[…]]
 * Each total: ['type'=>'total', 'label'=>…, 'placeholder'=>bool]
 *
 * keys refer to the 'key' field on sibling nodes for summation.
 */
function fin_gl_tree_definition(): array
{
    return [
        // ── SECTION: COGS VARIABLES ──────────────────────────────────────────
        ['type' => 'section', 'label' => 'COGS VARIABLES'],

        // Sub: Brewing
        ['type' => 'sub_header', 'label' => 'Brewing'],
        ['type' => 'line', 'key' => 'gl_4100',  'label' => 'Ready Beer (4100)',                       'gl' => ['4100']],
        ['type' => 'line', 'key' => 'gl_4101',  'label' => 'Malts (4101)',                            'gl' => ['4101']],
        ['type' => 'line', 'key' => 'gl_4102',  'label' => 'Hops (4102)',                             'gl' => ['4102']],
        ['type' => 'line', 'key' => 'gl_4103',  'label' => 'Yeast (4103)',                            'gl' => ['4103']],
        ['type' => 'line', 'key' => 'gl_4104',  'label' => 'Other Ingredients (4104)',                'gl' => ['4104']],
        ['type' => 'subtotal', 'key' => 'sub_brewing', 'label' => 'Total Brewing (419)',
         'lines' => ['gl_4100','gl_4101','gl_4102','gl_4103','gl_4104']],

        // Sub: Packaging
        ['type' => 'sub_header', 'label' => 'Packaging'],
        ['type' => 'line', 'key' => 'gl_4200_4201', 'label' => 'Bottles (inc TEA) (4200 + 4201)',    'gl' => ['4200','4201']],
        ['type' => 'line', 'key' => 'gl_4202',      'label' => 'Cans inc. lids (4202)',              'gl' => ['4202']],
        ['type' => 'line', 'key' => 'gl_4203_4207', 'label' => 'Regular packaging cardboard (4203 + 4207)', 'gl' => ['4203','4207']],
        ['type' => 'line', 'key' => 'gl_4204',      'label' => 'Special order cardboard (4204)',     'gl' => ['4204']],
        ['type' => 'line', 'key' => 'gl_4205',      'label' => 'Plastic wrap (4205)',                'gl' => ['4205']],
        ['type' => 'line', 'key' => 'gl_4206',      'label' => 'Labels (4206)',                      'gl' => ['4206']],
        ['type' => 'line', 'key' => 'gl_4209',      'label' => 'Keg packaging material (4209)',      'gl' => ['4209']],
        ['type' => 'line', 'key' => 'gl_4208',      'label' => 'External packing services (4208)',   'gl' => ['4208']],
        // COP-only Scotch line: no GL source
        ['type' => 'line', 'key' => 'gl_scotch',    'label' => 'Scotch',                             'gl' => [], 'placeholder' => true, 'cop_only' => true],
        ['type' => 'subtotal', 'key' => 'sub_packaging', 'label' => 'Total Packaging',
         'lines' => ['gl_4200_4201','gl_4202','gl_4203_4207','gl_4204','gl_4205','gl_4206','gl_4209','gl_4208']],
        // Note: scotch excluded from packaging subtotal (no GL, no value)

        // Sub: Indirect
        ['type' => 'sub_header', 'label' => 'Indirect'],
        ['type' => 'line', 'key' => 'gl_4300',      'label' => 'CO2 (4300)',                         'gl' => ['4300']],
        ['type' => 'line', 'key' => 'gl_4301',      'label' => 'Chemical (4301)',                    'gl' => ['4301']],
        ['type' => 'line', 'key' => 'gl_4302',      'label' => 'Small production equipment (4302)',  'gl' => ['4302']],
        ['type' => 'line', 'key' => 'gl_4600_4602', 'label' => 'Transport costs (4600+4602)',        'gl' => ['4600','4602']],
        ['type' => 'subtotal', 'key' => 'sub_indirect', 'label' => 'Total Indirect',
         'lines' => ['gl_4300','gl_4301','gl_4302','gl_4600_4602']],

        // Sub: Utilities
        ['type' => 'sub_header', 'label' => 'Utilities'],
        ['type' => 'line', 'key' => 'gl_4700',      'label' => 'Gaz & Water (4700)',                 'gl' => ['4700']],
        ['type' => 'line', 'key' => 'gl_4702',      'label' => 'Electricity (4702)',                 'gl' => ['4702']],
        ['type' => 'line', 'key' => 'gl_4701',      'label' => 'Waste evacuation (4701)',            'gl' => ['4701']],
        ['type' => 'subtotal', 'key' => 'sub_utilities', 'label' => 'Total Utilities',
         'lines' => ['gl_4700','gl_4702','gl_4701']],

        // Sub: R&D
        ['type' => 'sub_header', 'label' => 'R&D'],
        ['type' => 'line', 'key' => 'gl_4500',      'label' => 'QA / QC (4500)',                    'gl' => ['4500']],
        ['type' => 'line', 'key' => 'gl_4510',      'label' => 'Purchases (4510)',                   'gl' => ['4510']],
        ['type' => 'subtotal', 'key' => 'sub_rd', 'label' => 'Total R&D',
         'lines' => ['gl_4500','gl_4510']],

        // Grand subtotal: TOTAL COGS VARIABLE
        ['type' => 'grand_subtotal', 'key' => 'grand_variable',
         'label' => 'TOTAL COGS VARIABLE',
         'subtotals' => ['sub_brewing','sub_packaging','sub_indirect','sub_utilities','sub_rd']],

        // ── SECTION: COGS SEMI VARIABLE ─────────────────────────────────────
        ['type' => 'section', 'label' => 'COGS SEMI VARIABLE'],

        ['type' => 'sub_header', 'label' => 'WORK / Fixed Staff'],
        ['type' => 'line', 'key' => 'semi_brew_staff',   'label' => 'Brewing',          'gl' => [], 'placeholder' => true],
        ['type' => 'line', 'key' => 'semi_pkg_staff',    'label' => 'Packaging',         'gl' => [], 'placeholder' => true],
        ['type' => 'line', 'key' => 'semi_vt_n1',        'label' => 'Valeur Travail N-1','gl' => [], 'placeholder' => true],

        ['type' => 'sub_header', 'label' => 'Temporary Staff'],
        ['type' => 'line', 'key' => 'semi_temp_pkg',     'label' => 'Packaging',         'gl' => [], 'placeholder' => true],

        ['type' => 'grand_subtotal', 'key' => 'grand_semi',
         'label' => 'TOTAL COGS SEMI-VARIABLE',
         'subtotals' => [], 'placeholder' => true],

        // ── TOTAL OVERALL ────────────────────────────────────────────────────
        ['type' => 'total', 'key' => 'total_overall',
         'label' => 'COGS OVERALL',
         'placeholder' => true],

        // ── COP extra: COST PER PACKAGING TYPE ──────────────────────────────
        ['type' => 'section', 'label' => 'COST PER PACKAGING TYPE', 'cop_only' => true],
        ['type' => 'line', 'key' => 'cpp_kegs',     'label' => 'Kegs',   'gl' => [], 'placeholder' => true, 'cop_only' => true],
        ['type' => 'line', 'key' => 'cpp_bottles',  'label' => 'Bottles','gl' => [], 'placeholder' => true, 'cop_only' => true],
        ['type' => 'line', 'key' => 'cpp_cans',     'label' => 'Cans',   'gl' => [], 'placeholder' => true, 'cop_only' => true],
        ['type' => 'line', 'key' => 'cpp_bulk',     'label' => 'Bulk',   'gl' => [], 'placeholder' => true, 'cop_only' => true],
    ];
}

/**
 * Compute tree rows: resolve GL nets, calculate subtotals, divide by HL denominator.
 * Returns array of row objects for JSON.
 */
function fin_compute_tree(
    array  $treeDef,
    array  $glNets,
    float  $hlMonth,
    array  $glNetsYtd,
    float  $hlYtd,
    string $module
): array {
    $isCop = ($module === 'cop-grid');

    // First pass: compute per-key CHF values (month + ytd)
    $valMonth = [];  // key → CHF
    $valYtd   = [];  // key → CHF

    foreach ($treeDef as $node) {
        $key  = $node['key']  ?? null;
        $type = $node['type'] ?? '';
        if ($key === null) continue;

        if ($type === 'line') {
            if (!empty($node['placeholder'])) {
                $valMonth[$key] = null;
                $valYtd[$key]   = null;
            } else {
                $sum = 0.0;
                foreach ($node['gl'] as $acc) {
                    $sum += $glNets[$acc] ?? 0.0;
                }
                $valMonth[$key] = $sum;
                $sumYtd = 0.0;
                foreach ($node['gl'] as $acc) {
                    $sumYtd += $glNetsYtd[$acc] ?? 0.0;
                }
                $valYtd[$key] = $sumYtd;
            }
        } elseif ($type === 'subtotal') {
            if (!empty($node['placeholder'])) {
                $valMonth[$key] = null;
                $valYtd[$key]   = null;
            } else {
                $sum = 0.0;
                foreach ($node['lines'] as $lk) {
                    $v = $valMonth[$lk] ?? null;
                    if ($v !== null) $sum += $v;
                }
                $valMonth[$key] = $sum;
                $sumY = 0.0;
                foreach ($node['lines'] as $lk) {
                    $v = $valYtd[$lk] ?? null;
                    if ($v !== null) $sumY += $v;
                }
                $valYtd[$key] = $sumY;
            }
        } elseif ($type === 'grand_subtotal') {
            if (!empty($node['placeholder'])) {
                $valMonth[$key] = null;
                $valYtd[$key]   = null;
            } else {
                $sum = 0.0;
                foreach ($node['subtotals'] as $sk) {
                    $v = $valMonth[$sk] ?? null;
                    if ($v !== null) $sum += $v;
                }
                $valMonth[$key] = $sum;
                $sumY = 0.0;
                foreach ($node['subtotals'] as $sk) {
                    $v = $valYtd[$sk] ?? null;
                    if ($v !== null) $sumY += $v;
                }
                $valYtd[$key] = $sumY;
            }
        } elseif ($type === 'total') {
            // COGS OVERALL is placeholder — semi-variable unsourced
            $valMonth[$key] = null;
            $valYtd[$key]   = null;
        }
    }

    // Second pass: build output rows
    $rows = [];
    foreach ($treeDef as $node) {
        $type = $node['type'] ?? '';
        $key  = $node['key']  ?? null;
        $isCopOnly = !empty($node['cop_only']);

        // Skip COP-only rows when rendering cogs-grid
        if ($isCopOnly && !$isCop) continue;

        if ($type === 'section') {
            $rows[] = [
                'rowType' => 'section',
                'label'   => $node['label'],
            ];
            continue;
        }
        if ($type === 'sub_header') {
            $rows[] = [
                'rowType' => 'sub_header',
                'label'   => $node['label'],
            ];
            continue;
        }

        // For all value rows
        $chfMonth = ($key !== null) ? ($valMonth[$key] ?? null) : null;
        $chfYtd   = ($key !== null) ? ($valYtd[$key]   ?? null) : null;

        $phlMonth = $hlMonth > 0 ? ($chfMonth !== null ? $chfMonth / $hlMonth : null) : null;
        $phlYtd   = $hlYtd   > 0 ? ($chfYtd   !== null ? $chfYtd   / $hlYtd   : null) : null;

        $rows[] = [
            'rowType'   => $type,
            'key'       => $key,
            'label'     => $node['label'] ?? '',
            'placeholder' => !empty($node['placeholder']),
            // CHF actuals
            'chfMonth'  => $chfMonth,
            'chfYtd'    => $chfYtd,
            // CHF/HL actuals
            'phlMonth'  => $phlMonth,
            'phlYtd'    => $phlYtd,
        ];
    }

    return $rows;
}
