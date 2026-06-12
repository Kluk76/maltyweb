<?php
declare(strict_types=1);
/**
 * api/cogs-comprehensive-csv.php — COGS complet CSV (4 sections) — CFO export
 * Auth: manager+ (require_page_access)
 * ?month=YYYY-MM  (required)
 *
 * Section 1 : Fiche COGS — Variation de stock (from MySQL cogs_fiche_* tables)
 * Section 2 : COGS par SKU sur ventes (from sales-cogs-data.json)
 * Section 3 : COP — Coût de production (from cogs-report-data.json)
 * Section 4 : Taxe bière (from sales-cogs-data.json byCategory + breakdown)
 */

require __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings-helpers.php';

require_page_access('financier');

$month = trim($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'month param required (YYYY-MM)']);
    exit;
}

// ── helpers ──────────────────────────────────────────────────────────────────

function fmt(mixed $val): string
{
    return number_format((float)$val, 2, '.', '');
}

/**
 * Write a section title + blank row then return.
 * Each section = title row → blank row → header row → data rows → total row → 2 blank rows
 */
function sec_title($out, string $title): void
{
    fputcsv($out, [$title], ',', '"');
    fputcsv($out, [], ',', '"');
}

function blank_rows($out, int $n = 2): void
{
    for ($i = 0; $i < $n; $i++) {
        fputcsv($out, [], ',', '"');
    }
}

// ── Section 1: load from MySQL ───────────────────────────────────────────────

$ficheRows = [];
$ficheTotals = ['rm_chf' => 0.0, 'wip_chf' => 0.0, 'fg_chf' => 0.0,
                'total_chf' => 0.0, 'opening_chf' => 0.0, 'variation_chf' => 0.0];
$ficheBasisRows = [];

try {
    $pdo = maltytask_pdo();

    // Validate month exists in data
    $stmt_months = $pdo->query("
        SELECT DISTINCT month_key FROM (
            SELECT month_key FROM cogs_fiche_seed
            UNION
            SELECT month_key FROM cogs_fiche_monthly
        ) AS all_months
    ");
    $valid_months = $stmt_months->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array($month, $valid_months, true)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'reason' => 'No COGS fiche data for ' . $month]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            c.category_key, c.label_fr, c.inv_gl, c.charge_gl, c.display_order,
            COALESCE(m.rm_chf,        s.rm_chf)        AS rm_chf,
            COALESCE(m.wip_chf,       s.wip_chf)       AS wip_chf,
            COALESCE(m.fg_chf,        s.fg_chf)        AS fg_chf,
            COALESCE(m.total_chf,     s.total_chf)     AS total_chf,
            COALESCE(m.opening_chf,   s.opening_chf)   AS opening_chf,
            COALESCE(m.variation_chf, s.variation_chf) AS variation_chf,
            m.basis_adjustment_chf,
            CASE WHEN m.id IS NOT NULL THEN 'computed' ELSE 'seed' END AS provenance
        FROM ref_cogs_fiche_categories c
        LEFT JOIN cogs_fiche_seed s    ON s.month_key = :month  AND s.category_key = c.category_key
        LEFT JOIN cogs_fiche_monthly m ON m.month_key = :month2 AND m.category_key = c.category_key
        WHERE c.is_active = 1
          AND (s.month_key IS NOT NULL OR m.month_key IS NOT NULL)
        ORDER BY c.display_order
    ");
    $stmt->execute([':month' => $month, ':month2' => $month]);
    $ficheRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[cogs-comprehensive-csv] DB error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'DB error']);
    exit;
}

if (empty($ficheRows)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'No fiche data for ' . $month]);
    exit;
}

foreach ($ficheRows as $row) {
    foreach (array_keys($ficheTotals) as $k) {
        $ficheTotals[$k] += (float)($row[$k] ?? 0);
    }
    if ($row['basis_adjustment_chf'] !== null) {
        $ficheBasisRows[] = $row;
    }
}

// ── Section 2: load from sales-cogs-data.json ────────────────────────────────

$salesJson = [];
$salesPath = '/var/www/maltytask/interfaces/sales-cogs-data.json';
if (is_readable($salesPath)) {
    $salesJson = json_decode(file_get_contents($salesPath), true) ?? [];
}
$monthSales = $salesJson['months'][$month] ?? null;
$bySKU      = $monthSales['bySKU'] ?? [];
$beerTax    = $monthSales['beerTax'] ?? [];

// ── Section 3: load from cogs-report-data.json ───────────────────────────────

$cogsJson = [];
$cogsPath = '/var/www/maltytask/interfaces/cogs-report-data.json';
if (is_readable($cogsPath)) {
    $cogsJson = json_decode(file_get_contents($cogsPath), true) ?? [];
}
$monthEntry = null;
foreach ($cogsJson['months'] ?? [] as $m) {
    if (($m['monthKey'] ?? '') === $month) {
        $monthEntry = $m;
        break;
    }
}
$cop = $monthEntry['cop'] ?? null;

// ── Emit CSV ─────────────────────────────────────────────────────────────────

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="cogs-complet-' . $month . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

// ════════════════════════════════════════════════════════════════════════════
// SECTION 1 — FICHE COGS — VARIATION DE STOCK
// ════════════════════════════════════════════════════════════════════════════

sec_title($out, 'FICHE COGS — VARIATION DE STOCK (' . $month . ')');

fputcsv($out, [
    'Catégorie', 'Comptes Inv.', 'Cptes Charge',
    'Valeur Stock (RM)', 'Bières en cours (WIP)', 'Bières prêtes (FG)',
    'Total Inventaire', 'Compta mois préc.', 'Variation Stock',
], ',', '"');

foreach ($ficheRows as $row) {
    fputcsv($out, [
        $row['label_fr'],
        $row['inv_gl']  ?? '',
        $row['charge_gl'] ?? '',
        fmt($row['rm_chf']),
        fmt($row['wip_chf']),
        fmt($row['fg_chf']),
        fmt($row['total_chf']),
        fmt($row['opening_chf']),
        fmt($row['variation_chf']),
    ], ',', '"');
}

fputcsv($out, [
    'Total', '', '',
    fmt($ficheTotals['rm_chf']),
    fmt($ficheTotals['wip_chf']),
    fmt($ficheTotals['fg_chf']),
    fmt($ficheTotals['total_chf']),
    fmt($ficheTotals['opening_chf']),
    fmt($ficheTotals['variation_chf']),
], ',', '"');

foreach ($ficheBasisRows as $row) {
    fputcsv($out, [
        'Ajustement de base — ' . $row['label_fr'],
        '', '', '', '', '', '', '',
        fmt($row['basis_adjustment_chf']),
    ], ',', '"');
}

blank_rows($out, 2);

// ════════════════════════════════════════════════════════════════════════════
// SECTION 2 — COGS PAR SKU (sur ventes)
// ════════════════════════════════════════════════════════════════════════════

sec_title($out, 'COGS PAR SKU (sur ventes) — ' . $month);

fputcsv($out, [
    'SKU', 'HL', 'Qté vendue',
    'COGS matière (CHF)', 'Taxe bière (CHF)', 'COGS total (CHF)',
    'CA (CHF)', 'Coût/unité (CHF)', 'HL/unité',
], ',', '"');

$skuTotals = [
    'HL' => 0.0, 'units' => 0.0,
    'material_CHF' => 0.0, 'beerTax_CHF' => 0.0,
    'salesCOGS_CHF' => 0.0, 'revenueCHF' => 0.0,
];

foreach ($bySKU as $sku => $v) {
    fputcsv($out, [
        $sku,
        fmt($v['HL']            ?? 0),
        fmt($v['units']         ?? 0),
        fmt($v['material_CHF']  ?? 0),
        fmt($v['beerTax_CHF']   ?? 0),
        fmt($v['salesCOGS_CHF'] ?? 0),
        fmt($v['revenueCHF']    ?? 0),
        fmt($v['unitCost']      ?? 0),
        fmt($v['hlPerUnit']     ?? 0),
    ], ',', '"');

    $skuTotals['HL']           += (float)($v['HL']            ?? 0);
    $skuTotals['units']        += (float)($v['units']         ?? 0);
    $skuTotals['material_CHF'] += (float)($v['material_CHF']  ?? 0);
    $skuTotals['beerTax_CHF']  += (float)($v['beerTax_CHF']   ?? 0);
    $skuTotals['salesCOGS_CHF']+= (float)($v['salesCOGS_CHF'] ?? 0);
    $skuTotals['revenueCHF']   += (float)($v['revenueCHF']    ?? 0);
}

fputcsv($out, [
    'Total',
    fmt($skuTotals['HL']),
    fmt($skuTotals['units']),
    fmt($skuTotals['material_CHF']),
    fmt($skuTotals['beerTax_CHF']),
    fmt($skuTotals['salesCOGS_CHF']),
    fmt($skuTotals['revenueCHF']),
    '', // unitCost — n/a for total
    '', // hlPerUnit — n/a for total
], ',', '"');

blank_rows($out, 2);

// ════════════════════════════════════════════════════════════════════════════
// SECTION 3 — COP — COÛT DE PRODUCTION
// ════════════════════════════════════════════════════════════════════════════

sec_title($out, 'COP — COÛT DE PRODUCTION — ' . $month);

$hlBrewed   = $cop['hlBrewed']   ?? null;
$hlPackaged = $cop['hlPackaged'] ?? null;
$totalVars  = $cop['totalVariables'] ?? [];

// Sub-header with HL context
if ($hlBrewed !== null || $hlPackaged !== null) {
    fputcsv($out, [
        'HL brassés : ' . ($hlBrewed !== null ? fmt($hlBrewed) : 'N/D'),
        'HL conditionnés : ' . ($hlPackaged !== null ? fmt($hlPackaged) : 'N/D'),
    ], ',', '"');
    fputcsv($out, [], ',', '"');
}

fputcsv($out, ['Section', 'Ligne', 'CHF (mois)', 'CHF/HL (mois)'], ',', '"');

// French labels for sections and line items
$sectionLabels = [
    'brewing'       => 'Brassage',
    'packaging'     => 'Conditionnement',
    'indirect'      => 'Indirect',
    'utilities'     => 'Utilités',
    'rd'            => 'R&D',
    'semiVariables' => 'Semi-variables',
];

$lineLabels = [
    // brewing
    'malts'             => 'Malts',
    'hops'              => 'Houblons',
    'yeast'             => 'Levure',
    'ingredients'       => 'Autres ingrédients',   // legacy JSON key
    'otherIngredients'  => 'Autres ingrédients',   // current JSON key
    // packaging
    'bottles'           => 'Bouteilles',
    'cans'              => 'Canettes',
    'cardboard'         => 'Cartons',
    'labels'            => 'Étiquettes',
    'kegMaterial'       => 'Matériel fûts',
    // indirect
    'co2'               => 'CO2',
    'chemical'          => 'Produits chimiques',
    'smallEquipment'    => 'Petit équipement',
    'transport'         => 'Transport',
    'maintenance'       => 'Maintenance',
    'sales'             => 'Ventes',
    // utilities
    'gas'               => 'Gaz',
    'electricity'       => 'Électricité',
    'waterSewage'       => 'Eau/assainissement',
    'waste'             => 'Déchets',
    // rd
    'qaqc'              => 'QA/QC',
    'purchases'         => 'Achats R&D',
    // semiVariables
    'fixedStaffBrewing'     => 'Personnel brassage (fixe)',
    'fixedStaffPackaging'   => 'Personnel conditionnement (fixe)',
    'tempStaffPackaging'    => 'Personnel conditionnement (intérim)',
    // subtotals
    'total'             => 'Sous-total',
];

$sectionsOrder = ['brewing', 'packaging', 'indirect', 'utilities', 'rd', 'semiVariables'];

foreach ($sectionsOrder as $secKey) {
    $secData = $cop[$secKey] ?? null;
    if ($secData === null) {
        continue;
    }

    $secLabel = $sectionLabels[$secKey] ?? $secKey;

    // Determine section total
    $secTotal   = null;
    $secPerHL   = null;

    if (isset($secData['subtotal']['current']['total'])) {
        // Current JSON format: subtotal.current.{total,hl,perHL}
        $secTotal = (float)$secData['subtotal']['current']['total'];
        $secPerHL = isset($secData['subtotal']['current']['perHL'])
            ? (float)$secData['subtotal']['current']['perHL']
            : null;
    } elseif (isset($secData['total']) && is_numeric($secData['total'])) {
        // Legacy JSON format: top-level numeric total, perHL computed from hlBrewed
        $secTotal = (float)$secData['total'];
        $secPerHL = ($hlBrewed !== null && $hlBrewed > 0)
            ? $secTotal / (float)$hlBrewed
            : null;
    }

    // Section header row (bold semantically — CFO reads it as a section break)
    fputcsv($out, [
        $secLabel,
        '',
        $secTotal !== null ? fmt($secTotal) : '',
        $secPerHL !== null ? fmt($secPerHL) : '',
    ], ',', '"');

    // Per-line rows (only if the section has named sub-items beyond 'total')
    foreach ($secData as $lineKey => $lineVal) {
        if ($lineKey === 'total' || $lineKey === 'subtotal') {
            continue;
        }

        $lineLabel = $lineLabels[$lineKey] ?? $lineKey;

        // Sub-item can be structured {current:{total,perHL,...}} or numeric
        if (is_array($lineVal) && isset($lineVal['current']['total'])) {
            $lineCHF   = (float)$lineVal['current']['total'];
            $linePerHL = isset($lineVal['current']['perHL'])
                ? (float)$lineVal['current']['perHL']
                : null;
        } elseif (is_numeric($lineVal)) {
            $lineCHF   = (float)$lineVal;
            $linePerHL = ($hlBrewed !== null && $hlBrewed > 0)
                ? $lineCHF / (float)$hlBrewed
                : null;
        } else {
            continue; // skip non-renderable structures (e.g. glLines)
        }

        fputcsv($out, [
            $secLabel,
            $lineLabel,
            fmt($lineCHF),
            $linePerHL !== null ? fmt($linePerHL) : '',
        ], ',', '"');
    }
}

// Grand total
$tvTotal = isset($totalVars['total']) ? (float)$totalVars['total'] : null;
$tvPerHL = isset($totalVars['perHL']) ? (float)$totalVars['perHL'] : null;

fputcsv($out, [
    'TOTAL COP (variables)',
    '',
    $tvTotal !== null ? fmt($tvTotal) : '',
    $tvPerHL !== null ? fmt($tvPerHL) : '',
], ',', '"');

blank_rows($out, 2);

// ════════════════════════════════════════════════════════════════════════════
// SECTION 4 — TAXE BIÈRE
// ════════════════════════════════════════════════════════════════════════════

sec_title($out, 'TAXE BIÈRE — ' . $month);

$byCategory = $beerTax['byCategory'] ?? [];
$reduction  = isset($beerTax['reduction']) ? (float)$beerTax['reduction'] : null;
$breakdown  = $beerTax['breakdown'] ?? [];

// Part A — by-category table
fputcsv($out, [
    'Catégorie', 'HL taxables', 'Taux plein (CHF/HL)', 'Réduction',
    'Taux effectif (CHF/HL)', 'Taxe (CHF)',
], ',', '"');

$catTotalHL  = 0.0;
$catTotalTax = 0.0;

// byCategory is a 0-indexed array; index = category number (0=exempt,1,2,3)
foreach ($byCategory as $idx => $cat) {
    $catHL  = (float)($cat['hl']  ?? 0);
    $catTax = (float)($cat['tax'] ?? 0);

    $catTotalHL  += $catHL;
    $catTotalTax += $catTax;

    if ($idx === 0) {
        // Category 0: exempt
        fputcsv($out, [
            'Cat. 0 (exonérée)', fmt($catHL), '0.00', 'N/A', '0.00', fmt($catTax),
        ], ',', '"');
    } else {
        // Derive effective rate from data (tax / hl) — never hardcode
        $effectiveRate = ($catHL > 0) ? $catTax / $catHL : 0.0;
        // Derive full rate from effective rate and reduction factor
        // Gate on catHL > 0: a zero-volume category has no meaningful rate
        $fullRate = ($catHL > 0 && $reduction !== null && $reduction < 1.0 && $reduction >= 0.0)
            ? $effectiveRate / (1.0 - $reduction)
            : null;
        // Reduction expressed as percentage kept (e.g. 60% kept = 40% reduction)
        $reductionLabel = ($reduction !== null)
            ? number_format((1.0 - $reduction) * 100, 0, '.', '') . '%'
            : 'N/D';

        fputcsv($out, [
            'Cat. ' . $idx,
            fmt($catHL),
            $fullRate !== null ? fmt($fullRate) : 'N/D',
            $reductionLabel,
            fmt($effectiveRate),
            fmt($catTax),
        ], ',', '"');
    }
}

fputcsv($out, [
    'Total', fmt($catTotalHL), '', '', '', fmt($catTotalTax),
], ',', '"');

fputcsv($out, [], ',', '"');

// Part B — breakdown table
fputcsv($out, ['Source', 'Taxe (CHF)'], ',', '"');

$breakdownLabels = [
    'fromSales'              => 'Ventes',
    'fromInvendables'        => 'Invendables',
    'fromContractPackaging'  => 'Contract Packaging',
    'exportsExcludedHL'      => 'HL exportés (exonérés, HL)',
];

foreach ($breakdown as $bKey => $bVal) {
    $bLabel = $breakdownLabels[$bKey] ?? $bKey;
    if ($bKey === 'exportsExcludedHL') {
        // Volume (HL), not CHF — emit in separate column to avoid CFO misreading as tax amount
        fputcsv($out, [$bLabel, '', fmt($bVal) . ' HL'], ',', '"');
    } else {
        fputcsv($out, [$bLabel, fmt($bVal)], ',', '"');
    }
}

$beerTaxTotal = $beerTax['total'] ?? null;
fputcsv($out, [
    'Total taxe bière',
    $beerTaxTotal !== null ? fmt($beerTaxTotal) : '',
], ',', '"');

blank_rows($out, 2);

fclose($out);
