<?php
declare(strict_types=1);
/**
 * api/financier-data.php — Lazy per-month COGS slice endpoint
 *
 * Returns a single month's COGS/Ventes slice from sales-cogs-data.json.
 * Manager+ only. JSON response, never redirects on auth failure.
 *
 * GET ?module=cogs&month=YYYY-MM
 *
 * Response shape:
 *   { ok: true,  month: "YYYY-MM", totals:{…}, bySKU:{…}, beerTax:{…},
 *     unknownSKUs:[…], nonBeerSKUs:[…] }
 * or
 *   { ok: false, reason: "…" }
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/kpi-handlers.php';

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

if ($module !== 'cogs') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'reason' => 'unknown_module']);
    exit;
}

// Validate YYYY-MM format
if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $month)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'reason' => 'invalid_month']);
    exit;
}

/* ── Load slice ────────────────────────────────────────────────────────────── */
$raw = kpi_sales_cogs_month_slice($month);
if ($raw === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'reason' => 'month_not_found', 'month' => $month]);
    exit;
}

/* ── Trim bySKU: drop byCustomer (UI doesn't need it, saves payload) ─────── */
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
