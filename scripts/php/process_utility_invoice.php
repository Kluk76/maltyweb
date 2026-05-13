<?php
declare(strict_types=1);

/**
 * process_utility_invoice.php — Auto-process SIL (gas/water/sewage) and SIE (electricity)
 * utility invoices into inv_deliveries rows.
 *
 * ARCHITECTURE
 * ============
 * Tariff catalog: /var/www/maltytask/data/utility-tariffs.json (on VPS)
 *   = data/utility-tariffs.json in this repo — must be kept in sync manually.
 *   Source of truth is maltytask/data/utility-tariffs.json.
 *   Long-term: migrate to ref_utility_tariffs MySQL table (Phase D or later).
 *
 * The script does NOT recompute component costs from catalog rates. Instead it:
 *   - Extracts OCR sub-totals from the invoice text (more robust, handles tariff drift)
 *   - Uses catalog rates for reference and drift notes only
 *   - Validates computed total vs OCR-extracted TTC (SIL) or doc_invoices.total_ht (SIE)
 *   - Emits 2 inv_deliveries rows (SIL: gas + water/sewage) or 1 row (SIE: electricity)
 *
 * KNOWN BUG in invoice extractor: SIL invoices with mixed VAT rates (2.6% water,
 * 8.1% gas, 0% solidarity) have their doc_invoices.total_ht computed as TTC/1.081,
 * which is WRONG. The script detects this and validates against OCR TTC instead.
 *
 * CLI
 * ===
 * php scripts/php/process_utility_invoice.php \
 *     --invoice-ref=<ref>        (required)
 *     [--service=sil|sie]        (override auto-detect)
 *     [--peak-kw=<float>]        (SIE only; overrides OCR-extracted value)
 *     [--tariff-version=<date>]  (override; defaults to latest effectiveFrom <= periodEnd)
 *     [--apply]                  (default = dry-run)
 *     [--force]                  (bypass validation drift halt)
 *
 * AFTER RUNNING
 * =============
 * Run from maltytask repo to write meter indices to BSF EnergyData:
 *   node scripts/update-energy-actuals.js --month YYYY-MM
 */

// ─── Bootstrap ───────────────────────────────────────────────────────────────

$scriptDir = __DIR__;
$appDir    = realpath($scriptDir . '/../../app');
$dataDir   = realpath($scriptDir . '/../../data');

require $appDir . '/db.php';
require $appDir . '/services/triage_actions.php';

// ─── CLI argument parsing ─────────────────────────────────────────────────────

$opts = getopt('', [
    'invoice-ref:',
    'service:',
    'peak-kw:',
    'tariff-version:',
    'apply',
    'force',
]);

$invoiceRef     = $opts['invoice-ref'] ?? null;
$serviceForcedRaw = $opts['service']  ?? null;
$peakKwOverride = isset($opts['peak-kw']) ? (float)$opts['peak-kw'] : null;
$tariffOverride = $opts['tariff-version'] ?? null;
$dryRun         = !isset($opts['apply']);
$force          = isset($opts['force']);

if ($invoiceRef === null) {
    fwrite(STDERR, "Usage: php process_utility_invoice.php --invoice-ref=<ref> [--service=sil|sie] [--peak-kw=<float>] [--tariff-version=<date>] [--apply] [--force]\n");
    exit(1);
}

$serviceForced = null;
if ($serviceForcedRaw !== null) {
    $s = strtolower((string)$serviceForcedRaw);
    if (!in_array($s, ['sil', 'sie'], true)) {
        fwrite(STDERR, "Error: --service must be 'sil' or 'sie'\n");
        exit(1);
    }
    $serviceForced = $s;
}

echo ($dryRun ? "[DRY-RUN] " : "[APPLY]   ") . "Processing invoice ref: {$invoiceRef}\n";
echo str_repeat('─', 60) . "\n";

// ─── Load tariff catalog ──────────────────────────────────────────────────────

$tariffPath = realpath($dataDir . '/utility-tariffs.json');
if ($tariffPath === false || !file_exists($tariffPath)) {
    fwrite(STDERR, "Error: tariff catalog not found at {$dataDir}/utility-tariffs.json\n");
    fwrite(STDERR, "Copy from maltytask repo: cp <maltytask>/data/utility-tariffs.json {$dataDir}/utility-tariffs.json\n");
    exit(1);
}

$tariffCatalog = json_decode(file_get_contents($tariffPath), true);
if (!is_array($tariffCatalog) || empty($tariffCatalog['versions'])) {
    fwrite(STDERR, "Error: invalid tariff catalog format\n");
    exit(1);
}

// ─── Load invoice from DB ─────────────────────────────────────────────────────

$pdo = maltytask_pdo();

$stmt = $pdo->prepare(
    "SELECT id, invoice_ref, supplier_name, supplier_fk, total_ht, invoice_date, ocr_text
       FROM doc_invoices
      WHERE invoice_ref = ?
      LIMIT 1"
);
$stmt->execute([$invoiceRef]);
$invoice = $stmt->fetch();

if (!$invoice) {
    fwrite(STDERR, "Error: invoice ref '{$invoiceRef}' not found in doc_invoices\n");
    exit(1);
}

$invoiceId   = (int)$invoice['id'];
$supplierRaw = (string)$invoice['supplier_name'];
$supplierFk  = $invoice['supplier_fk'] !== null ? (int)$invoice['supplier_fk'] : null;
$dbTotalHt   = (float)$invoice['total_ht'];
$invoiceDate = (string)$invoice['invoice_date'];
$ocrText     = (string)$invoice['ocr_text'];

echo "Invoice ID:    {$invoiceId}\n";
echo "Supplier:      {$supplierRaw}\n";
echo "Invoice date:  {$invoiceDate}\n";
echo "DB total_ht:   " . number_format($dbTotalHt, 2) . " CHF\n";
echo "OCR length:    " . strlen($ocrText) . " chars\n";
echo str_repeat('─', 60) . "\n";

if (strlen($ocrText) < 200) {
    fwrite(STDERR, "Error: OCR text too short (" . strlen($ocrText) . " chars) — invoice may not have been OCR'd\n");
    exit(1);
}

// ─── Auto-detect service ──────────────────────────────────────────────────────

if ($serviceForced !== null) {
    $service = $serviceForced;
    echo "Service:       {$service} (forced via --service)\n";
} else {
    $sil = (
        stripos($supplierRaw, 'SIL') !== false ||
        stripos($supplierRaw, 'Lausanne') !== false ||
        stripos($supplierRaw, 'Industriels') !== false ||
        stripos($ocrText,    'Services Industriels') !== false ||
        stripos($ocrText,    'lausanne.ch/sil') !== false
    );
    $sie = (
        stripos($supplierRaw, 'SIE') !== false ||
        stripos($supplierRaw, 'Intercommunal') !== false ||
        stripos($supplierRaw, 'Énergies') !== false ||
        stripos($supplierRaw, 'Energies') !== false ||
        stripos($ocrText,    'Intercommunal des') !== false ||
        stripos($ocrText,    'SIE SA') !== false
    );

    if ($sil && !$sie) {
        $service = 'sil';
    } elseif ($sie && !$sil) {
        $service = 'sie';
    } elseif ($sil && $sie) {
        fwrite(STDERR, "Error: ambiguous service detection (both SIL and SIE markers found). Use --service=sil|sie\n");
        exit(1);
    } else {
        fwrite(STDERR, "Error: cannot auto-detect service (no SIL or SIE markers found in supplier name or OCR text). Use --service=sil|sie\n");
        exit(1);
    }
    echo "Service:       {$service} (auto-detected)\n";
}
echo str_repeat('─', 60) . "\n";

// ─── Helper: extract a float from OCR via pattern ────────────────────────────

/**
 * Extract a single float from OCR text using a regex.
 * Returns null if not found.
 * Swiss number format: 1'234.56 — strip apostrophes before parsing.
 */
function ocr_extract_float(string $text, string $pattern): ?float
{
    if (!preg_match($pattern, $text, $m)) {
        return null;
    }
    $raw = $m[1];
    // Remove Swiss thousands separator (apostrophe or space before digits)
    $raw = str_replace(["'", "\u{2019}", "\u{00A0}", ' '], '', $raw);
    // Normalize comma decimal (some OCR outputs commas)
    $raw = str_replace(',', '.', $raw);
    return (float)$raw;
}

/**
 * Extract a period from OCR text.
 * Looks for "Période du DD.MM.YYYY au DD.MM.YYYY" or "Période du D mois YYYY au D mois YYYY"
 * Returns ['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD'] or null.
 */
function ocr_extract_period(string $text): ?array
{
    // Numeric format: 01.11.2025 au 30.11.2025
    if (preg_match('/[Pp][ée]riode\s+du\s+(\d{1,2})\.(\d{2})\.(\d{4})\s+au\s+(\d{1,2})\.(\d{2})\.(\d{4})/u', $text, $m)) {
        $start = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        $end   = sprintf('%04d-%02d-%02d', (int)$m[6], (int)$m[5], (int)$m[4]);
        return ['start' => $start, 'end' => $end];
    }
    // Text month format: "1 décembre 2025 au 31 décembre 2025"
    $months = ['janvier'=>1,'février'=>2,'fevrier'=>2,'mars'=>3,'avril'=>4,
                'mai'=>5,'juin'=>6,'juillet'=>7,'août'=>8,'aout'=>8,
                'septembre'=>9,'octobre'=>10,'novembre'=>11,'décembre'=>12,'decembre'=>12];
    $monthPat = implode('|', array_keys($months));
    if (preg_match("/[Pp][ée]riode\s+du\s+(\d{1,2})\s+({$monthPat})\s+(\d{4})\s+au\s+(\d{1,2})\s+({$monthPat})\s+(\d{4})/ui", $text, $m)) {
        $startMon = $months[strtolower($m[2])] ?? 0;
        $endMon   = $months[strtolower($m[5])] ?? 0;
        if ($startMon && $endMon) {
            $start = sprintf('%04d-%02d-%02d', (int)$m[3], $startMon, (int)$m[1]);
            $end   = sprintf('%04d-%02d-%02d', (int)$m[6], $endMon,   (int)$m[4]);
            return ['start' => $start, 'end' => $end];
        }
    }
    return null;
}

/**
 * Compute fractional months between two dates (days / 30.4).
 */
function period_months(string $start, string $end): float
{
    $d1 = new DateTime($start);
    $d2 = new DateTime($end);
    // Add 1 to include the end day in the count
    $days = (int)$d1->diff($d2)->days + 1;
    return round($days / 30.4, 4);
}

/**
 * Select tariff version: latest effectiveFrom <= periodEnd.
 * If $override is provided, find the version with that exact effectiveFrom date.
 */
function select_tariff(array $catalog, string $periodEnd, ?string $override): ?array
{
    $versions = $catalog['versions'];
    if ($override !== null) {
        foreach ($versions as $v) {
            if ($v['effectiveFrom'] === $override) return $v;
        }
        return null;
    }
    // Sort descending by effectiveFrom
    usort($versions, fn($a, $b) => strcmp($b['effectiveFrom'], $a['effectiveFrom']));
    foreach ($versions as $v) {
        if ($v['effectiveFrom'] <= $periodEnd) return $v;
    }
    return $versions[0]; // fallback to oldest
}

// ─── Format helpers ───────────────────────────────────────────────────────────

function fmt(float $v, int $dec = 2): string
{
    return number_format($v, $dec, '.', "'");
}

function check_mark(bool $ok): string
{
    return $ok ? 'OK' : 'DRIFT';
}

// ─── SIL PIPELINE ────────────────────────────────────────────────────────────

if ($service === 'sil') {

    // Validate SIL markers in OCR
    if (stripos($ocrText, 'Services Industriels') === false &&
        stripos($ocrText, 'lausanne.ch/sil') === false &&
        stripos($ocrText, 'Période du décompte') === false) {
        fwrite(STDERR, "Error: OCR text does not contain SIL markers (Services Industriels / lausanne.ch/sil / Période du décompte). Wrong invoice?\n");
        if (!$force) exit(1);
        echo "WARNING: SIL markers absent — proceeding with --force\n";
    }

    // ── Extract billing period ─────────────────────────────────────────────

    $period = ocr_extract_period($ocrText);
    if (!$period) {
        // SIL-specific: "Période du décompte : DD.MM.YYYY au DD.MM.YYYY"
        if (preg_match('/[Pp][ée]riode\s+du\s+d[ée]compte\s*:\s*(\d{2})\.(\d{2})\.(\d{4})\s+au\s+(\d{2})\.(\d{2})\.(\d{4})/u', $ocrText, $m)) {
            $period = [
                'start' => sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]),
                'end'   => sprintf('%04d-%02d-%02d', (int)$m[6], (int)$m[5], (int)$m[4]),
            ];
        }
    }
    if (!$period) {
        fwrite(STDERR, "Error: cannot extract billing period from OCR text\n");
        if (!$force) exit(1);
        $period = ['start' => substr($invoiceDate, 0, 7) . '-01', 'end' => $invoiceDate];
        echo "WARNING: using fallback period {$period['start']} to {$period['end']}\n";
    }

    $periodStart  = $period['start'];
    $periodEnd    = $period['end'];
    $months       = period_months($periodStart, $periodEnd);

    echo "Period:        {$periodStart} to {$periodEnd} ({$months} months)\n";

    // ── Select tariff ──────────────────────────────────────────────────────

    $tariff = select_tariff($tariffCatalog, $periodEnd, $tariffOverride);
    if (!$tariff) {
        fwrite(STDERR, "Error: tariff version not found" . ($tariffOverride ? " for '{$tariffOverride}'" : "") . "\n");
        exit(1);
    }
    echo "Tariff version: {$tariff['effectiveFrom']}\n";
    echo str_repeat('─', 60) . "\n";

    $gasCat    = $tariff['gas'];
    $waterCat  = $tariff['water'];
    $sewageCat = $tariff['sewage'];

    // Normalize curly apostrophes (U+2019) to straight quotes for all regex work
    $ocrN = str_replace("\xE2\x80\x99", "'", $ocrText);

    // ── Extract GAS components from OCR ────────────────────────────────────

    // Try extracting index ancien/nouveau and kWh from "Consommation gaz A B C kWh"
    if (preg_match("/Consommation\s+gaz\s+([\d']+\.?\d*)\s+([\d']+\.?\d*)\s+([\d']+\.?\d*)\s+kWh/", $ocrN, $mIdx)) {
        $gasIndexAncienV  = (float)str_replace("'", '', $mIdx[1]);
        $gasIndexNouveauV = (float)str_replace("'", '', $mIdx[2]);
        $gasVolumeM3      = $gasIndexNouveauV - $gasIndexAncienV;
        $gasKwh           = (float)str_replace("'", '', $mIdx[3]);
    } else {
        $gasVolumeM3      = null;
        $gasIndexAncienV  = null;
        $gasIndexNouveauV = null;
        $gasKwh           = null;
    }

    // Extract sub-totals from OCR for HT computation
    // "Sous-total vente de gaz (TVA non comprise) 5044.24"
    $gasVenteSousTotal = ocr_extract_float($ocrN, "/Sous-total\s+vente\s+de\s+gaz\s+\(TVA\s+non\s+comprise\)\s+([\d']+\.?\d*)/i");
    // "Sous-total redevances et taxes (TVA non comprise) 874.02"
    $gasTaxesSousTotal = ocr_extract_float($ocrN, "/Sous-total\s+redevances\s+et\s+taxes\s+\(TVA\s+non\s+comprise\)\s+([\d']+\.?\d*)/i");

    // Extract individual line items for breakdown
    $gasSubscription = ocr_extract_float($ocrN, '/Abonnement\s+[\d.,]+\s+[àa]\s+CHF\s+([\d.,]+)\/mois/i');
    if ($gasSubscription === null) {
        $gasSubscription = ocr_extract_float($ocrN, "/Abonnement\s+[\d.,]+\s+[àa]\s+CHF\s+[\d.,]+\/mois\s+([\d'.,]+)/i");
    }

    // Power clause: "Puissance 400 kW à CHF 1.50/mois/kW 600.00" — capture the kW value
    $gasPeakKw     = ocr_extract_float($ocrN, '/Puissance\s+(\d+)\s+[kK][wW]\s+[àa]\s+CHF/');
    $gasPowerCharge = ocr_extract_float($ocrN, "/Puissance\s+\d+\s+[kK][wW]?\s+[àa]\s+CHF\s+[\d.,]+\/mois\/kW\s+([\d'.,]+)/i");

    // "Consommation 40'444.99 x 0.10840 = 4'384.24" — total only
    $gasConsumptionCharge = ocr_extract_float($ocrN, "/Consommation\s+[\d'.]+\s+x\s+[\d.]+\s+=\s+([\d']+\.?\d*)/");

    // CO2 tax: "Taxe CO, 40444.99 x 0.02161 = 874.02" (OCR may corrupt CO2 subscript)
    $gasCo2Rate   = ocr_extract_float($ocrN, '/Taxe\s+CO[,2]?\s+[\d\'.]+\s+x\s+([\d.]+)/');
    $gasCo2Charge = ocr_extract_float($ocrN, "/Taxe\s+CO[,2]?\s+[\d'.]+\s+x\s+[\d.]+\s+=\s+([\d']+\.?\d*)/");

    // Total gaz TTC: "Total gaz 6'397.64"
    $gasTtc = ocr_extract_float($ocrN, "/Total\s+gaz\s+([\d']+\.?\d*)/i");

    // Gas HT = vente sub-total + taxes sub-total
    if ($gasVenteSousTotal !== null && $gasTaxesSousTotal !== null) {
        $gasHt = round($gasVenteSousTotal + $gasTaxesSousTotal, 2);
    } elseif ($gasTtc !== null) {
        // Fallback: gas HT = TTC / (1 + gasVAT) — less precise
        $gasHt = round($gasTtc / (1.0 + $gasCat['tvaRate']), 2);
        echo "WARNING: using gas TTC/{1+VAT} fallback for gas HT (missing sous-totaux)\n";
    } else {
        fwrite(STDERR, "Error: cannot compute gas HT — sous-total de gaz not found in OCR\n");
        if (!$force) exit(1);
        $gasHt = 0.0;
    }

    // Verify peak kW
    $gasPeakKwFinal = $gasPeakKw ?? (float)$gasCat['subscribedCapacityKW'];
    if ($gasPeakKw === null) {
        echo "NOTE: Gas peak kW not extracted from OCR — using catalog default {$gasCat['subscribedCapacityKW']} kW\n";
    }

    // ── Extract WATER / SEWAGE components from OCR (use $ocrN — already normalized) ─

    // Water meter indices and volume: "Taxe de consommation A B C m3"
    if (preg_match("/Taxe\s+de\s+consommation\s+([\d']+\.?\d*)\s+([\d']+\.?\d*)\s+([\d']+\.?\d*)\s+m/u", $ocrN, $mWat)) {
        $waterIndexAncien  = (float)str_replace("'", '', $mWat[1]);
        $waterIndexNouveau = (float)str_replace("'", '', $mWat[2]);
        $waterVolumeM3     = (float)str_replace("'", '', $mWat[3]);
    } else {
        $waterVolumeM3     = null;
        $waterIndexAncien  = null;
        $waterIndexNouveau = null;
    }

    // Water sub-totals from OCR
    // "Sous-total vente d'eau (TVA non comprise, au taux réduit) 1'101.60"
    $waterConsumptionSt = ocr_extract_float($ocrN, "/Sous-total\s+vente\s+d'eau\s+\(TVA\s+non\s+comprise[^)]*\)\s+([\d']+\.?\d*)/i");
    // "Sous-total taxes de base, locations et débits (TVA non comprise, au taux réduit) 249.00"
    $waterBaseSt = ocr_extract_float($ocrN, "/Sous-total\s+taxes\s+de\s+base[^)]*\)\s+([\d']+\.?\d*)/i");
    // "Sous-total contributions diverses (non soumises à la TVA) 6.80"
    $waterSolidaritySt = ocr_extract_float($ocrN, "/Sous-total\s+contributions\s+diverses[^)]*\)\s+([\d']+\.?\d*)/i");

    // Water total TTC: "Total eau et assainissement 2'417.96"
    $waterSeweageTtc = ocr_extract_float($ocrN, "/Total\s+eau\s+et\s+assainissement\s+([\d']+\.?\d*)/i");

    // Sewage: "Sous-total assainissement (TVA non comprise, au taux normal) 948.60"
    $sewageHt = ocr_extract_float($ocrN, "/Sous-total\s+assainissement\s+\(TVA\s+non\s+comprise[^)]*\)\s+([\d']+\.?\d*)/i");

    // Sewage gross and rebate for details
    $sewageGross  = ocr_extract_float($ocrN, "/Taxe\s+[ée]puration\s+des\s+eaux\s+us[ée]es\s+[\d'.]+\s+x\s+[\d.]+\s+=\s+([\d']+\.?\d*)/iu");
    $sewageRebate = ocr_extract_float($ocrN, "/Rabais\s+contractuel\s+[ée]puration[^=\n]*-\s*([\d']+\.?\d*)/iu");

    // Water HT = consumption + base + solidarity
    if ($waterConsumptionSt !== null && $waterBaseSt !== null) {
        $waterHt = round($waterConsumptionSt + $waterBaseSt + (float)($waterSolidaritySt ?? 0), 2);
    } elseif ($waterSeweageTtc !== null && $sewageHt !== null) {
        // Fallback: derive from TTC
        $waterHt = round($waterSeweageTtc / 1.026 - $sewageHt, 2);
        echo "WARNING: using water TTC fallback for water HT\n";
    } else {
        fwrite(STDERR, "Error: cannot compute water HT — water sous-totaux not found in OCR\n");
        if (!$force) exit(1);
        $waterHt = 0.0;
    }

    if ($sewageHt === null) {
        fwrite(STDERR, "Error: cannot extract sewage HT from OCR\n");
        if (!$force) exit(1);
        $sewageHt = 0.0;
    }

    // ── Look up MI IDs ─────────────────────────────────────────────────────

    $miGas = $pdo->prepare("SELECT id FROM ref_mi WHERE mi_id = ? LIMIT 1");
    $miGas->execute(['UTIL_GAS_SIL']);
    $miGasRow = $miGas->fetch();

    $miWater = $pdo->prepare("SELECT id FROM ref_mi WHERE mi_id = ? LIMIT 1");
    $miWater->execute(['UTIL_WATER_SEWAGE_SIL']);
    $miWaterRow = $miWater->fetch();

    if (!$miGasRow || !$miWaterRow) {
        fwrite(STDERR, "Error: utility MI not found in ref_mi (UTIL_GAS_SIL / UTIL_WATER_SEWAGE_SIL)\n");
        exit(1);
    }

    $miGasId  = (int)$miGasRow['id'];
    $miWaterId = (int)$miWaterRow['id'];

    // ── Compute totals and validate ────────────────────────────────────────

    $grandTotalHt = round($gasHt + $waterHt + $sewageHt, 2);

    // Compute TTC for each section for validation
    // Gas: taxable base = gasHt, rate 8.1%
    $gasTvaSelf   = round($gasHt * $gasCat['tvaRate'], 2);
    $gasTtcSelf   = round($gasHt + $gasTvaSelf, 2);
    // Water: 2.6% on (consumption + base), 0% on solidarity
    $waterTaxable = round($waterHt - (float)($waterSolidaritySt ?? 0), 2);
    $waterTvaSelf = round($waterTaxable * $waterCat['tvaRate'], 2);
    // Sewage: 8.1%
    $sewageTvaSelf = round($sewageHt * $sewageCat['tvaRate'], 2);

    $computedTtc = round($gasTtcSelf + $waterHt + $waterTvaSelf + $sewageHt + $sewageTvaSelf, 2);

    // Extract OCR TTC ("Montant total de la facture N'NNN.NN" or sum of sections)
    $ocrTtc = ocr_extract_float($ocrN, "/Montant\s+total\s+de\s+la\s+facture\s+(?:Arrondi\s+[-\d.,]+\s+)?([\d']+\.?\d*)/i");
    if ($ocrTtc === null) {
        // Try from section totals
        $ocrGasTtc  = $gasTtc;
        $ocrWatTtc  = $waterSeweageTtc;
        if ($ocrGasTtc !== null && $ocrWatTtc !== null) {
            $ocrTtc = round($ocrGasTtc + $ocrWatTtc, 2);
        }
    }

    // NOTE: doc_invoices.total_ht for SIL is WRONG (= TTC/1.081 from extractor bug).
    // We validate against OCR TTC instead.
    $tolerance    = max(1.0, $grandTotalHt * 0.001); // ±1 CHF or 0.1%
    $ttcTolerance = max(1.0, $computedTtc * 0.001);

    if ($ocrTtc !== null) {
        $ttcDrift = abs($computedTtc - $ocrTtc);
        $ttcOk    = $ttcDrift <= $ttcTolerance;
    } else {
        $ttcDrift = null;
        $ttcOk    = true; // cannot validate
    }

    // Detect the known DB total_ht bug for informational purposes
    $dbTtcEquiv = round($dbTotalHt * 1.081, 2);
    $dbBugDetected = ($ocrTtc !== null && abs($dbTtcEquiv - $ocrTtc) < 0.10);

    // ── Print breakdown ────────────────────────────────────────────────────

    echo "GAS BREAKDOWN\n";
    echo "  Volume:        " . ($gasVolumeM3 !== null ? fmt($gasVolumeM3) . " m³" : "n/a") . "\n";
    echo "  kWh:           " . ($gasKwh !== null ? fmt($gasKwh) . " kWh" : "n/a") . "\n";
    echo "  Peak kW:       " . fmt($gasPeakKwFinal) . " kW" . ($gasPeakKw === null ? " (catalog default)" : " (OCR)") . "\n";
    echo "  Subscription:  " . ($gasSubscription !== null ? fmt($gasSubscription) : "n/a") . " CHF\n";
    echo "  Consumption:   " . ($gasConsumptionCharge !== null ? fmt($gasConsumptionCharge) : "n/a") . " CHF\n";
    echo "  Power clause:  " . ($gasPowerCharge !== null ? fmt($gasPowerCharge) : "n/a") . " CHF\n";
    echo "  CO2 rate:      " . ($gasCo2Rate !== null ? $gasCo2Rate : "n/a") . " CHF/kWh";
    if ($gasCo2Rate !== null && abs($gasCo2Rate - $gasCat['co2TaxCHFPerKWh']) > 0.0001) {
        echo "  *** DRIFT vs catalog " . $gasCat['co2TaxCHFPerKWh'] . " ***";
    }
    echo "\n";
    echo "  CO2 charge:    " . ($gasCo2Charge !== null ? fmt($gasCo2Charge) : "n/a") . " CHF\n";
    echo "  Vente sub-total: " . ($gasVenteSousTotal !== null ? fmt($gasVenteSousTotal) : "n/a") . " CHF\n";
    echo "  Taxes sub-total: " . ($gasTaxesSousTotal !== null ? fmt($gasTaxesSousTotal) : "n/a") . " CHF\n";
    echo "  >>> GAS HT:    " . fmt($gasHt) . " CHF\n";
    echo "\n";

    echo "WATER BREAKDOWN\n";
    echo "  Volume:        " . ($waterVolumeM3 !== null ? fmt($waterVolumeM3) . " m³" : "n/a") . "\n";
    echo "  Consumption:   " . ($waterConsumptionSt !== null ? fmt($waterConsumptionSt) : "n/a") . " CHF\n";
    echo "  Base+rental:   " . ($waterBaseSt !== null ? fmt($waterBaseSt) : "n/a") . " CHF\n";
    echo "  Solidarity:    " . ($waterSolidaritySt !== null ? fmt($waterSolidaritySt) : "n/a") . " CHF\n";
    echo "  >>> WATER HT:  " . fmt($waterHt) . " CHF\n";
    echo "\n";

    echo "SEWAGE BREAKDOWN\n";
    echo "  Base rate:     " . $sewageCat['baseRateCHFPerM3'] . " CHF/m³\n";
    echo "  Gross:         " . ($sewageGross !== null ? fmt($sewageGross) : "n/a") . " CHF\n";
    echo "  Rebate (7%):   " . ($sewageRebate !== null ? "-" . fmt($sewageRebate) : "n/a") . " CHF\n";
    echo "  >>> SEWAGE HT: " . fmt($sewageHt) . " CHF\n";
    echo "\n";

    echo str_repeat('─', 60) . "\n";
    echo "VALIDATION\n";
    echo "  Gas HT:        " . fmt($gasHt) . " CHF\n";
    echo "  Water HT:      " . fmt($waterHt) . " CHF\n";
    echo "  Sewage HT:     " . fmt($sewageHt) . " CHF\n";
    echo "  Total HT sum:  " . fmt($grandTotalHt) . " CHF\n";
    echo "  Computed TTC:  " . fmt($computedTtc) . " CHF\n";
    echo "  OCR TTC:       " . ($ocrTtc !== null ? fmt($ocrTtc) . " CHF" : "n/a (extracted from sections)") . "\n";
    if ($ttcDrift !== null) {
        echo "  TTC drift:     " . fmt($ttcDrift) . " CHF (tolerance " . fmt($ttcTolerance) . ") [" . check_mark($ttcOk) . "]\n";
    }
    if ($dbBugDetected) {
        echo "  NOTE: doc_invoices.total_ht={$dbTotalHt} is WRONG for SIL (extracted as TTC/1.081).\n";
        echo "        True HT={$grandTotalHt}; validation uses OCR TTC.\n";
    } else {
        echo "  DB total_ht:   " . fmt($dbTotalHt) . " CHF (note: may be incorrect for SIL mixed-VAT)\n";
    }
    echo str_repeat('─', 60) . "\n";

    if (!$ttcOk && !$force) {
        echo "HALT: TTC validation failed (drift " . fmt((float)$ttcDrift) . " CHF > tolerance " . fmt($ttcTolerance) . " CHF)\n";
        echo "Per-component breakdown printed above. Use --force to write anyway.\n";
        exit(2);
    }

    if (!$ttcOk && $force) {
        echo "WARNING: TTC drift exceeds tolerance — writing anyway due to --force\n";
    }

    // ── Build details JSON ─────────────────────────────────────────────────

    $gasDetails = [
        'service'           => 'gas',
        'periodStart'       => $periodStart,
        'periodEnd'         => $periodEnd,
        'months'            => $months,
        'gasVolumeM3'       => $gasVolumeM3,
        'gasKwh'            => $gasKwh,
        'peakKw'            => $gasPeakKwFinal,
        'components'        => [
            'subscription'   => $gasSubscription,
            'consumption'    => $gasConsumptionCharge,
            'power'          => $gasPowerCharge,
            'co2Tax'         => $gasCo2Charge,
            'venteSousTotal' => $gasVenteSousTotal,
            'taxesSousTotal' => $gasTaxesSousTotal,
        ],
        'tariffVersion'     => $tariff['effectiveFrom'],
        'co2RateActual'     => $gasCo2Rate,
        'co2RateCatalog'    => $gasCat['co2TaxCHFPerKWh'],
        'validationTtcOk'   => $ttcOk,
        'computedTtc'       => $computedTtc,
        'ocrTtc'            => $ocrTtc,
    ];

    $waterSewageDetails = [
        'service'             => 'water_sewage',
        'periodStart'         => $periodStart,
        'periodEnd'           => $periodEnd,
        'months'              => $months,
        'waterVolumeM3'       => $waterVolumeM3,
        'waterComponents'     => [
            'consumption'     => $waterConsumptionSt,
            'baseAndRental'   => $waterBaseSt,
            'solidarity'      => $waterSolidaritySt,
        ],
        'sewageComponents'    => [
            'gross'           => $sewageGross,
            'rebate'          => $sewageRebate !== null ? -$sewageRebate : null,
            'net'             => $sewageHt,
        ],
        'tariffVersion'       => $tariff['effectiveFrom'],
        'validationTtcOk'     => $ttcOk,
    ];

    // ── Find open RQ row ───────────────────────────────────────────────────

    $rqStmt = $pdo->prepare(
        "SELECT id, queue_id FROM doc_review_queue
          WHERE type = 'invoice-line-items-needed'
            AND invoice_ref = ?
            AND status = 'open'
          ORDER BY id DESC
          LIMIT 1"
    );
    $rqStmt->execute([$invoiceRef]);
    $rqRow = $rqStmt->fetch();

    echo "\n";
    if ($rqRow) {
        echo "RQ row:        #{$rqRow['id']} ({$rqRow['queue_id']}) — will close\n";
    } else {
        echo "RQ row:        none open for this invoice_ref\n";
    }
    $rqId = $rqRow ? (int)$rqRow['id'] : 0;

    // ── Write (under --apply) ─────────────────────────────────────────────

    echo "\n";
    if ($dryRun) {
        echo "DRY-RUN RESULT:\n";
        echo "  Would insert inv_deliveries row A (UTIL_GAS_SIL):\n";
        echo "    unit_price = " . fmt($gasHt) . " CHF, details = gas breakdown\n";
        echo "  Would insert inv_deliveries row B (UTIL_WATER_SEWAGE_SIL):\n";
        echo "    unit_price = " . fmt(round($waterHt + $sewageHt, 2)) . " CHF, details = water+sewage breakdown\n";
        if ($rqRow) {
            echo "  Would close RQ #{$rqRow['id']}: status=resolved, decision=auto-process\n";
        }
        echo "\nRun with --apply to write.\n";
    } else {
        $pdo->beginTransaction();
        try {
            // Row A: gas
            $resultGas = ta_materialize_delivery($pdo, [
                'rq_id'           => $rqId,
                'line_index'      => 0,
                'mi_internal_id'  => $miGasId,
                'mi_id_str'       => 'UTIL_GAS_SIL',
                'description'     => "Gaz SIL {$periodStart} – {$periodEnd}",
                'qty'             => 1.0,
                'unit_price'      => $gasHt,
                'invoice_id'      => $invoiceId,
                'invoice_ref'     => $invoiceRef,
                'invoice_date'    => $periodEnd,
                'supplier_raw'    => $supplierRaw,
                'supplier_fk'     => $supplierFk,
                'currency'        => 'CHF',
                'source'          => 'utility-process',
                'source_origin'   => 'cli',
            ]);
            // Override the details JSON with our rich breakdown
            if ($resultGas['inserted']) {
                $pdo->prepare(
                    "UPDATE inv_deliveries SET details = ? WHERE row_hash = ?"
                )->execute([json_encode($gasDetails, JSON_UNESCAPED_UNICODE), $resultGas['row_hash']]);
            }

            // Row B: water+sewage
            $resultWat = ta_materialize_delivery($pdo, [
                'rq_id'           => $rqId,
                'line_index'      => 1,
                'mi_internal_id'  => $miWaterId,
                'mi_id_str'       => 'UTIL_WATER_SEWAGE_SIL',
                'description'     => "Eau & Assainissement SIL {$periodStart} – {$periodEnd}",
                'qty'             => 1.0,
                'unit_price'      => round($waterHt + $sewageHt, 2),
                'invoice_id'      => $invoiceId,
                'invoice_ref'     => $invoiceRef,
                'invoice_date'    => $periodEnd,
                'supplier_raw'    => $supplierRaw,
                'supplier_fk'     => $supplierFk,
                'currency'        => 'CHF',
                'source'          => 'utility-process',
                'source_origin'   => 'cli',
            ]);
            if ($resultWat['inserted']) {
                $pdo->prepare(
                    "UPDATE inv_deliveries SET details = ? WHERE row_hash = ?"
                )->execute([json_encode($waterSewageDetails, JSON_UNESCAPED_UNICODE), $resultWat['row_hash']]);
            }

            // Close RQ row
            $rqNote = sprintf(
                "processed via process_utility_invoice.php — gas %.2f CHF, water+sewage %.2f CHF",
                $gasHt,
                round($waterHt + $sewageHt, 2)
            );
            if ($rqRow) {
                $pdo->prepare(
                    "UPDATE doc_review_queue
                        SET status          = 'resolved',
                            decision        = 'auto-process',
                            resolution_note = ?,
                            decided_at      = NOW(),
                            decided_by      = 'utility-process-cli',
                            updated_at      = NOW()
                      WHERE id = ?"
                )->execute([$rqNote, $rqId]);
            }

            $pdo->commit();

            echo "APPLY RESULT:\n";
            echo "  Gas row:       " . ($resultGas['inserted'] ? "INSERTED" : "SKIPPED (duplicate)") . " hash={$resultGas['row_hash']}\n";
            echo "  Water row:     " . ($resultWat['inserted'] ? "INSERTED" : "SKIPPED (duplicate)") . " hash={$resultWat['row_hash']}\n";
            if ($rqRow) {
                echo "  RQ #{$rqRow['id']}:  CLOSED — {$rqNote}\n";
            }

        } catch (Throwable $e) {
            $pdo->rollBack();
            fwrite(STDERR, "Error: transaction rolled back — " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    echo "\nNEXT STEP:\n";
    echo "  Run from maltytask repo to update BSF EnergyData:\n";
    $yearMonth = substr($periodEnd, 0, 7);
    echo "  node scripts/update-energy-actuals.js --month {$yearMonth}\n";

    exit(0);
}

// ─── SIE PIPELINE ────────────────────────────────────────────────────────────

if ($service === 'sie') {

    // Validate SIE markers
    $hasSieMarker = (
        stripos($ocrText, 'Intercommunal des') !== false ||
        stripos($ocrText, 'SIE SA') !== false ||
        preg_match('/[ÉE]LECTRICIT[ÉE]/ui', $ocrText)
    );

    if (!$hasSieMarker) {
        fwrite(STDERR, "Error: OCR text does not contain SIE markers (Intercommunal des / SIE SA / ÉLECTRICITÉ). Wrong invoice?\n");
        if (!$force) exit(1);
        echo "WARNING: SIE markers absent — proceeding with --force\n";
    }

    // ── Rejection check: TVT/multimedia or office meter ──────────────────

    $hasSoloPermanent = (bool)preg_match('/Solo\s+Permanent/i', $ocrText);
    $hasTopMeter      = (bool)preg_match('/Top\s+(HP|HC|Nature)/i', $ocrText);
    $isSmall          = ($dbTotalHt < 2000.0);

    if (($hasSoloPermanent || $isSmall) && !$hasTopMeter) {
        echo "REJECTED: Non-brewery electricity invoice\n";
        echo "  Reason: " . ($hasSoloPermanent ? "Solo Permanent tariff (small office meter)" : "") .
             ($isSmall ? ($hasSoloPermanent ? " + " : "") . "total_ht {$dbTotalHt} CHF < 2000 CHF threshold" : "") . "\n";
        if ($hasSoloPermanent) {
            echo "  Tariff: Solo Permanent = single-phase office meter (not industrial Top tariff)\n";
        }
        echo "  Action: Manual triage — set status to resolved/rejected in triage UI or:\n";
        echo "          UPDATE doc_review_queue SET status='rejected', decision='skip', resolution_note='non-brewery electricity (Solo Permanent / TVT)' WHERE invoice_ref='{$invoiceRef}';\n";
        echo "\n";
        echo "NOTE: SIE also bills TVT/multimedia services — check for 'Multimédia TVT' section in OCR.\n";
        exit(3); // exit code 3 = rejected (not an error)
    }

    // ── Extract billing period ─────────────────────────────────────────────

    $period = ocr_extract_period($ocrText);
    if (!$period) {
        fwrite(STDERR, "Error: cannot extract billing period from OCR text\n");
        if (!$force) exit(1);
        $period = ['start' => substr($invoiceDate, 0, 7) . '-01', 'end' => $invoiceDate];
    }

    $periodStart = $period['start'];
    $periodEnd   = $period['end'];
    $months      = period_months($periodStart, $periodEnd);

    echo "Period:        {$periodStart} to {$periodEnd} ({$months} months)\n";

    // ── Select tariff ──────────────────────────────────────────────────────

    $tariff = select_tariff($tariffCatalog, $periodEnd, $tariffOverride);
    if (!$tariff) {
        fwrite(STDERR, "Error: tariff version not found\n");
        exit(1);
    }
    echo "Tariff version: {$tariff['effectiveFrom']}\n";
    echo str_repeat('─', 60) . "\n";

    $elecCat = $tariff['electricity'];

    // Normalize curly apostrophes for regex work
    $ocrSN = str_replace("\xE2\x80\x99", "'", $ocrText);

    // ── Extract consumption data from OCR ────────────────────────────────

    // HP and HC kWh from meter section: "Top HP Nature 9'204 kWh"
    $hpKwh = ocr_extract_float($ocrSN, "/Top\s+HP\s+(?:Nature\s+)?(\d[\d']*)\s+kWh/i");
    $hcKwh = ocr_extract_float($ocrSN, "/Top\s+HC\s+(?:Nature\s+)?(\d[\d']*)\s+kWh/i");

    // Peak kW: "Achem. regional Puissance NN kW"
    $peakKwOcr = ocr_extract_float($ocrSN, '/Achem[\.\s]+r[ée]gional\s+Puissance\s+(\d+(?:[.,]\d+)?)\s+kW/i');
    if ($peakKwOcr === null) {
        // "Pointes de puissance: DD.MM.YYYY HH:MM:SS NN kW"
        $peakKwOcr = ocr_extract_float($ocrSN, '/Pointes\s+de\s+puissance\s*:[^:]+\s+(\d+(?:[.,]\d+)?)\s+kW/i');
    }

    $peakKw = $peakKwOverride ?? $peakKwOcr ?? $elecCat['defaultPeakPowerKW'];
    $peakKwSource = $peakKwOverride !== null ? '--peak-kw override'
                  : ($peakKwOcr !== null ? 'OCR' : 'catalog default');

    // Reactive kVArh
    $reactiveKvarh = ocr_extract_float($ocrSN, "/R[ée]actif\s+([\d']+\.?\d*)\s+kVarh/i");
    if ($reactiveKvarh === null) {
        $reactiveKvarh = ocr_extract_float($ocrSN, "/(\d[\d']*)\s+kVarh/i");
    }

    // Total kWh (HP + HC)
    $totalKwh = null;
    if ($hpKwh !== null && $hcKwh !== null) {
        $totalKwh = $hpKwh + $hcKwh;
    }

    // ── Extract section sub-totals from OCR ───────────────────────────────
    // These are the source of truth for HT amounts.

    // "Sous-total Energie (hors TVA) 2'308.54"
    $stEnergy = ocr_extract_float($ocrSN, "/Sous-total\s+Energie\s+\(hors\s+TVA\)\s+([\d']+\.?\d*)/i");
    // "Sous-total Acheminement régional (hors TVA) 1'389.30"
    $stAchemReg = ocr_extract_float($ocrSN, "/Sous-total\s+Acheminement\s+r[ée]gional\s+\(hors\s+TVA\)\s+([\d']+\.?\d*)/i");
    // "Sous-total Acheminement national (hors TVA) 346.44"
    $stAchemNat = ocr_extract_float($ocrSN, "/Sous-total\s+Acheminement\s+national\s+\(hors\s+TVA\)\s+([\d']+\.?\d*)/i");
    // "Sous-total Taxes (hors TVA) 562.40"
    $stTaxes = ocr_extract_float($ocrSN, "/Sous-total\s+Taxes\s+\(hors\s+TVA\)\s+([\d']+\.?\d*)/i");
    // "Total Electricité (hors TVA) 4'606.68"
    $ocrTotalElecHt = ocr_extract_float($ocrSN, "/Total\s+[ÉE]lectricit[ée]\s+\(hors\s+TVA\)\s+([\d']+\.?\d*)/iu");

    // Extract HP rate for catalog drift comparison
    $hpRateOcr = null;
    if ($hpKwh !== null) {
        $hpRateOcr = ocr_extract_float($ocrSN, "/Top\s+HP\s+Nature\s+[\d']+\s+([\d.]+)\s+[\d']/i");
    }

    // Peak power rate from "Achem. régional Puissance M 1 6.5100 371.07"
    $peakRateOcr = null;
    if (preg_match("/Achem[\.\s]+r[ée]gional\s+Puissance\s+\S+\s+[\d.]+\s+([\d.]+)\s+[\d']+\.?\d*/i", $ocrSN, $mPR)) {
        $peakRateOcr = (float)$mPR[1];
    }

    // ── Compute electricity total HT ──────────────────────────────────────

    if ($stEnergy !== null && $stAchemReg !== null && $stAchemNat !== null && $stTaxes !== null) {
        $electricityHt = round($stEnergy + $stAchemReg + $stAchemNat + $stTaxes, 2);
    } elseif ($ocrTotalElecHt !== null) {
        $electricityHt = $ocrTotalElecHt;
        echo "NOTE: using 'Total Electricité (hors TVA)' directly from OCR\n";
    } else {
        fwrite(STDERR, "Error: cannot compute electricity HT — section sub-totals not found in OCR\n");
        if (!$force) exit(1);
        $electricityHt = $dbTotalHt;
        echo "WARNING: using db total_ht as fallback\n";
    }

    // ── Look up MI ─────────────────────────────────────────────────────────

    $miElec = $pdo->prepare("SELECT id FROM ref_mi WHERE mi_id = ? LIMIT 1");
    $miElec->execute(['UTIL_ELECTRICITY_SIE']);
    $miElecRow = $miElec->fetch();

    if (!$miElecRow) {
        fwrite(STDERR, "Error: UTIL_ELECTRICITY_SIE not found in ref_mi\n");
        exit(1);
    }
    $miElecId = (int)$miElecRow['id'];

    // ── Validate against doc_invoices.total_ht ────────────────────────────
    // SIE's total_ht is correctly extracted (all at 8.1% VAT, clean computation).

    $tolerance = max(1.0, $dbTotalHt * 0.002); // ±1 CHF or 0.2%
    $htDrift   = abs($electricityHt - $dbTotalHt);
    $htOk      = $htDrift <= $tolerance;

    // Note rate differences vs catalog
    $rateDrifts = [];
    if ($peakRateOcr !== null && abs($peakRateOcr - $elecCat['achemRegional']['peakPowerCHFPerKWPerMonth']) > 0.001) {
        $rateDrifts[] = sprintf("Regional peak power rate: catalog=%.4f, invoice=%.4f", $elecCat['achemRegional']['peakPowerCHFPerKWPerMonth'], $peakRateOcr);
    }
    // Check energy rates if extractable
    if ($hpRateOcr !== null && abs($hpRateOcr - $elecCat['energy']['hpCHFPerKWh']) > 0.0001) {
        $rateDrifts[] = sprintf("Energy HP rate: catalog=%.4f, invoice=%.4f", $elecCat['energy']['hpCHFPerKWh'], $hpRateOcr);
    }

    // ── Print breakdown ────────────────────────────────────────────────────

    echo "ELECTRICITY BREAKDOWN (extracted from OCR section sub-totals)\n";
    echo "  HP consumption:  " . ($hpKwh !== null ? fmt($hpKwh) . " kWh" : "n/a") . "\n";
    echo "  HC consumption:  " . ($hcKwh !== null ? fmt($hcKwh) . " kWh" : "n/a") . "\n";
    echo "  Total kWh:       " . ($totalKwh !== null ? fmt($totalKwh) . " kWh" : "n/a") . "\n";
    echo "  Peak power:      " . fmt($peakKw) . " kW ({$peakKwSource})\n";
    echo "  Reactive:        " . ($reactiveKvarh !== null ? fmt($reactiveKvarh) . " kVArh" : "n/a") . "\n";
    echo "\n";
    echo "  Energy (HT):          " . ($stEnergy !== null ? fmt($stEnergy) : "n/a") . " CHF\n";
    echo "  Achem. régional (HT): " . ($stAchemReg !== null ? fmt($stAchemReg) : "n/a") . " CHF\n";
    echo "  Achem. national (HT): " . ($stAchemNat !== null ? fmt($stAchemNat) : "n/a") . " CHF\n";
    echo "  Taxes (HT):           " . ($stTaxes !== null ? fmt($stTaxes) : "n/a") . " CHF\n";
    echo "  >>> ELECTRICITY HT:   " . fmt($electricityHt) . " CHF\n";
    echo "\n";

    if (!empty($rateDrifts)) {
        echo "TARIFF RATE DRIFTS (invoice vs catalog — informational, does not affect HT):\n";
        foreach ($rateDrifts as $d) {
            echo "  * {$d}\n";
        }
        echo "  Update utility-tariffs.json if these represent a tariff change.\n";
        echo "\n";
    }

    echo str_repeat('─', 60) . "\n";
    echo "VALIDATION\n";
    echo "  Computed HT:   " . fmt($electricityHt) . " CHF\n";
    echo "  DB total_ht:   " . fmt($dbTotalHt) . " CHF\n";
    echo "  HT drift:      " . fmt($htDrift) . " CHF (tolerance " . fmt($tolerance) . ") [" . check_mark($htOk) . "]\n";

    if ($ocrTotalElecHt !== null) {
        $ocrDrift = abs($electricityHt - $ocrTotalElecHt);
        echo "  vs OCR 'Total Electricité': " . fmt($ocrTotalElecHt) . " CHF, drift=" . fmt($ocrDrift) . " [" . check_mark($ocrDrift < 0.01) . "]\n";
    }
    echo str_repeat('─', 60) . "\n";

    if (!$htOk && !$force) {
        echo "HALT: HT validation failed (drift " . fmt($htDrift) . " CHF > tolerance " . fmt($tolerance) . " CHF)\n";
        echo "Use --force to write anyway.\n";
        exit(2);
    }
    if (!$htOk && $force) {
        echo "WARNING: HT drift exceeds tolerance — writing anyway due to --force\n";
    }

    // ── Build details JSON ─────────────────────────────────────────────────

    $electricityDetails = [
        'service'         => 'electricity',
        'periodStart'     => $periodStart,
        'periodEnd'       => $periodEnd,
        'months'          => $months,
        'hpKwh'           => $hpKwh,
        'hcKwh'           => $hcKwh,
        'totalKwh'        => $totalKwh,
        'peakKw'          => $peakKw,
        'peakKwSource'    => $peakKwSource,
        'reactiveKvarh'   => $reactiveKvarh,
        'subtotals'       => [
            'energy'      => $stEnergy,
            'achemRegional' => $stAchemReg,
            'achemNational' => $stAchemNat,
            'taxes'       => $stTaxes,
        ],
        'tariffVersion'   => $tariff['effectiveFrom'],
        'tariffDrifts'    => $rateDrifts,
        'validationOk'    => $htOk,
        'computedHt'      => $electricityHt,
        'dbTotalHt'       => $dbTotalHt,
    ];

    // ── Find open RQ row ───────────────────────────────────────────────────

    $rqStmt = $pdo->prepare(
        "SELECT id, queue_id FROM doc_review_queue
          WHERE type = 'invoice-line-items-needed'
            AND invoice_ref = ?
            AND status = 'open'
          ORDER BY id DESC
          LIMIT 1"
    );
    $rqStmt->execute([$invoiceRef]);
    $rqRow = $rqStmt->fetch();

    echo "\n";
    if ($rqRow) {
        echo "RQ row:        #{$rqRow['id']} ({$rqRow['queue_id']}) — will close\n";
    } else {
        echo "RQ row:        none open for this invoice_ref\n";
    }
    $rqId = $rqRow ? (int)$rqRow['id'] : 0;

    // ── Write (under --apply) ─────────────────────────────────────────────

    echo "\n";
    if ($dryRun) {
        echo "DRY-RUN RESULT:\n";
        echo "  Would insert inv_deliveries row (UTIL_ELECTRICITY_SIE):\n";
        echo "    unit_price = " . fmt($electricityHt) . " CHF, details = electricity breakdown\n";
        if ($rqRow) {
            echo "  Would close RQ #{$rqRow['id']}: status=resolved, decision=auto-process\n";
        }
        echo "\nRun with --apply to write.\n";
    } else {
        $pdo->beginTransaction();
        try {
            $resultElec = ta_materialize_delivery($pdo, [
                'rq_id'           => $rqId,
                'line_index'      => 0,
                'mi_internal_id'  => $miElecId,
                'mi_id_str'       => 'UTIL_ELECTRICITY_SIE',
                'description'     => "Électricité SIE {$periodStart} – {$periodEnd}",
                'qty'             => 1.0,
                'unit_price'      => $electricityHt,
                'invoice_id'      => $invoiceId,
                'invoice_ref'     => $invoiceRef,
                'invoice_date'    => $periodEnd,
                'supplier_raw'    => $supplierRaw,
                'supplier_fk'     => $supplierFk,
                'currency'        => 'CHF',
                'source'          => 'utility-process',
                'source_origin'   => 'cli',
            ]);
            if ($resultElec['inserted']) {
                $pdo->prepare(
                    "UPDATE inv_deliveries SET details = ? WHERE row_hash = ?"
                )->execute([json_encode($electricityDetails, JSON_UNESCAPED_UNICODE), $resultElec['row_hash']]);
            }

            $rqNote = sprintf("processed via process_utility_invoice.php — electricity %.2f CHF", $electricityHt);
            if ($rqRow) {
                $pdo->prepare(
                    "UPDATE doc_review_queue
                        SET status          = 'resolved',
                            decision        = 'auto-process',
                            resolution_note = ?,
                            decided_at      = NOW(),
                            decided_by      = 'utility-process-cli',
                            updated_at      = NOW()
                      WHERE id = ?"
                )->execute([$rqNote, $rqId]);
            }

            $pdo->commit();

            echo "APPLY RESULT:\n";
            echo "  Electricity row: " . ($resultElec['inserted'] ? "INSERTED" : "SKIPPED (duplicate)") . " hash={$resultElec['row_hash']}\n";
            if ($rqRow) {
                echo "  RQ #{$rqRow['id']}:  CLOSED — {$rqNote}\n";
            }

        } catch (Throwable $e) {
            $pdo->rollBack();
            fwrite(STDERR, "Error: transaction rolled back — " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    echo "\nNEXT STEP:\n";
    echo "  Run from maltytask repo to update BSF EnergyData:\n";
    $yearMonth = substr($periodEnd, 0, 7);
    echo "  node scripts/update-energy-actuals.js --month {$yearMonth}\n";

    exit(0);
}
