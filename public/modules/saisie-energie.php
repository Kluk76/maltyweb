<?php
declare(strict_types=1);
/**
 * modules/saisie-energie.php — Saisie mensuelle des index compteurs énergie.
 *
 * Permet à l'opérateur de saisir les relevés mensuels (eau, gaz, électricité
 * jour/nuit) pour les mois non encore couverts par une facture SIE/SIL.
 * Les lignes source='invoice' ne sont jamais modifiables ici.
 *
 * PRG pattern: POST handler at top → redirect → GET render below.
 *
 * Table: inv_energydata
 *   UNIQUE KEY uq_inv_energydata_period (period)
 *   peak_kw / reactive_kvarh preserved on upsert (ingest-owned columns).
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/settings-helpers.php';
require __DIR__ . '/../../app/db-write-helpers.php';

require_page_access('saisie-energie');
$me = current_user();
$active_module = 'saisie-energie';

// ── POST handler (PRG) ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. CSRF first
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Token CSRF invalide — veuillez réessayer.');
        redirect_to('/modules/saisie-energie.php');
    }

    // 2. Read + validate period
    $period = trim($_POST['period'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        flash_set('err', 'Période invalide.');
        redirect_to('/modules/saisie-energie.php');
    }

    // 3. Check existing row — refuse if source='invoice'
    $pdoPost = maltytask_pdo();
    $checkStmt = $pdoPost->prepare(
        "SELECT id, source, eau_m3, gaz_kwh, elec_jour_kwh, elec_nuit_kwh,
                peak_kw, reactive_kvarh, last_modified_by, updated_at
           FROM inv_energydata
          WHERE period = ?"
    );
    $checkStmt->execute([$period]);
    $existingRow = $checkStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($existingRow !== null && $existingRow['source'] === 'invoice') {
        flash_set('err', 'Ce mois est déjà renseigné depuis la facture SIE/SIL — non modifiable ici.');
        redirect_to('/modules/saisie-energie.php?period=' . urlencode($period));
    }

    // 4. Read 4 decimal inputs with '' default, validate numeric or empty
    $rawEau  = trim($_POST['eau_m3']       ?? '');
    $rawGaz  = trim($_POST['gaz_kwh']      ?? '');
    $rawJour = trim($_POST['elec_jour_kwh'] ?? '');
    $rawNuit = trim($_POST['elec_nuit_kwh'] ?? '');

    $validationErrors = [];
    foreach (['eau_m3' => $rawEau, 'gaz_kwh' => $rawGaz, 'elec_jour_kwh' => $rawJour, 'elec_nuit_kwh' => $rawNuit] as $fname => $fval) {
        if ($fval !== '' && !is_numeric($fval)) {
            $validationErrors[] = "Valeur invalide pour {$fname}.";
        }
    }
    if (!empty($validationErrors)) {
        flash_set('err', implode(' ', $validationErrors));
        redirect_to('/modules/saisie-energie.php?period=' . urlencode($period));
    }

    // Convert to float|null
    $eau  = ($rawEau  !== '') ? (float) $rawEau  : null;
    $gaz  = ($rawGaz  !== '') ? (float) $rawGaz  : null;
    $jour = ($rawJour !== '') ? (float) $rawJour : null;
    $nuit = ($rawNuit !== '') ? (float) $rawNuit : null;

    // 5. Compute row_hash: "manual-meter|{period}|{eau}|{gaz}|{jour}|{nuit}"
    //    each value is sprintf('%.3f', val) if entered, else ''
    $hashEau  = $eau  !== null ? sprintf('%.3f', $eau)  : '';
    $hashGaz  = $gaz  !== null ? sprintf('%.3f', $gaz)  : '';
    $hashJour = $jour !== null ? sprintf('%.3f', $jour) : '';
    $hashNuit = $nuit !== null ? sprintf('%.3f', $nuit) : '';
    $rowHash  = hash('sha256', "manual-meter|{$period}|{$hashEau}|{$hashGaz}|{$hashJour}|{$hashNuit}");

    // 6. Build $before from existing row (or null if new)
    $before = $existingRow !== null ? $existingRow : null;

    // 7. Upsert — peak_kw and reactive_kvarh NOT in INSERT → preserved on update
    //    COALESCE(VALUES(col), col) preserves existing value when NULL is submitted
    try {
        $upsertStmt = $pdoPost->prepare(
            "INSERT INTO inv_energydata
               (period, eau_m3, gaz_kwh, elec_jour_kwh, elec_nuit_kwh, source, last_modified_by, row_hash)
             VALUES (:period, :eau, :gaz, :jour, :nuit, 'estimate', 'web', :row_hash)
             ON DUPLICATE KEY UPDATE
               eau_m3          = COALESCE(VALUES(eau_m3), eau_m3),
               gaz_kwh         = COALESCE(VALUES(gaz_kwh), gaz_kwh),
               elec_jour_kwh   = COALESCE(VALUES(elec_jour_kwh), elec_jour_kwh),
               elec_nuit_kwh   = COALESCE(VALUES(elec_nuit_kwh), elec_nuit_kwh),
               source          = 'estimate',
               last_modified_by = 'web',
               row_hash        = VALUES(row_hash),
               updated_at      = CURRENT_TIMESTAMP"
        );
        $upsertStmt->execute([
            ':period'   => $period,
            ':eau'      => $eau,
            ':gaz'      => $gaz,
            ':jour'     => $jour,
            ':nuit'     => $nuit,
            ':row_hash' => $rowHash,
        ]);
    } catch (Throwable $e) {
        flash_set('err', 'Erreur base de données : ' . pdo_friendly_error($e, 'upsert'));
        redirect_to('/modules/saisie-energie.php?period=' . urlencode($period));
    }

    // 8. Get the PK
    $pkStmt = $pdoPost->prepare("SELECT id FROM inv_energydata WHERE period = ?");
    $pkStmt->execute([$period]);
    $pk = (int) $pkStmt->fetchColumn();

    // 9. log_revision
    $after = [
        'period'          => $period,
        'eau_m3'          => $eau,
        'gaz_kwh'         => $gaz,
        'elec_jour_kwh'   => $jour,
        'elec_nuit_kwh'   => $nuit,
        'source'          => 'estimate',
        'last_modified_by' => 'web',
        'row_hash'        => $rowHash,
    ];
    log_revision($pdoPost, $me, 'inv_energydata', $pk, $before, $after, 'normal', 'saisie manuelle index compteur');

    // 10. Flash + redirect
    flash_set('ok', 'Index enregistré pour ' . $period);
    redirect_to('/modules/saisie-energie.php?period=' . urlencode($period));
}

// ── GET ────────────────────────────────────────────────────────────────────────
$pdo = maltytask_pdo();

// Determine selected period
$rawPeriod = $_GET['period'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $rawPeriod)) {
    $rawPeriod = date('Y-m');
}
$selectedPeriod = $rawPeriod;

// Compute previous month
$prevPeriod = date('Y-m', strtotime($selectedPeriod . '-01 -1 month'));

// Flash pop
$flash = flash_pop();

// Load ALL energydata rows
$allRows = [];
$stmt = $pdo->query(
    "SELECT period, eau_m3, gaz_kwh, elec_jour_kwh, elec_nuit_kwh,
            peak_kw, reactive_kvarh, source, last_modified_by, updated_at
       FROM inv_energydata
      ORDER BY period DESC"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $allRows[$r['period']] = $r;
}

// Selected + previous rows
$selectedRow       = $allRows[$selectedPeriod] ?? null;
$prevRow           = $allRows[$prevPeriod] ?? null;
$isInvoiceLocked   = $selectedRow !== null && $selectedRow['source'] === 'invoice';
$isExistingEstimate = $selectedRow !== null && $selectedRow['source'] === 'estimate';

// ── Utilities breakdown panel data ────────────────────────────────────────────
$seEstimate    = null;
$seEstimateErr = null;
if ($selectedRow !== null && $prevRow !== null) {
    if (!function_exists('utilities_estimate_month')) {
        require_once __DIR__ . '/../../app/utilities-estimate.php';
    }
    try {
        $seEstimate = utilities_estimate_month($pdo, $selectedPeriod, true);
    } catch (\Throwable $e) {
        $seEstimateErr = $e->getMessage();
    }
}

// ── Compute trailing-3-month mean for each field (for warn threshold) ──────────
// Used in history table for delta outlier detection
$fields3 = ['eau_m3', 'gaz_kwh', 'elec_jour_kwh', 'elec_nuit_kwh'];

/**
 * Compute trailing 3-month mean delta for a field from allRows, before the given period.
 * Returns null if fewer than 2 preceding periods exist.
 */
function se_trailing3_mean_delta(array $allRows, string $beforePeriod, string $field): ?float
{
    // Collect up to 4 consecutive periods ending before $beforePeriod
    $sorted = array_keys($allRows);
    sort($sorted);
    $idx = array_search($beforePeriod, $sorted);
    if ($idx === false || $idx < 2) return null;

    $deltas = [];
    for ($i = $idx - 1; $i >= max(0, $idx - 3) && count($deltas) < 3; $i--) {
        $p    = $sorted[$i];
        $pPrv = $sorted[$i - 1] ?? null;
        if ($pPrv === null) break;
        $cur  = $allRows[$p][$field]    ?? null;
        $prv  = $allRows[$pPrv][$field] ?? null;
        if ($cur !== null && $prv !== null) {
            $deltas[] = abs((float)$cur - (float)$prv);
        }
    }
    if (count($deltas) === 0) return null;
    return array_sum($deltas) / count($deltas);
}

/**
 * Format a float value for display (3 decimal places or '—' for null).
 */
function se_fmt(?string $val): string
{
    if ($val === null || $val === '') return '—';
    return number_format((float)$val, 3, '.', ' ');
}

/**
 * Compute delta between current and previous row for a field.
 * Returns [formatted string, is_negative bool] or ['—', false] if not computable.
 */
function se_delta(?string $cur, ?string $prv): array
{
    if ($cur === null || $prv === null) return ['—', false, null];
    $d = (float)$cur - (float)$prv;
    if ($d > 0)  return ['+' . number_format($d, 3, '.', ' '), false, $d];
    if ($d < 0)  return [number_format($d, 3, '.', ' '), true, $d];
    return ['0.000', false, 0.0];
}

// Build sorted lists for history table and delta computation
$sortedPeriods = array_keys($allRows); // already DESC from ORDER BY
$sortedAsc = array_keys($allRows);
sort($sortedAsc); // ASC for prev-period lookup

// Compute per-row warn flags and deltas
$historyRows = [];
foreach ($sortedPeriods as $p) {
    $row  = $allRows[$p];
    $pIdx = array_search($p, $sortedAsc);
    $pPrev = ($pIdx !== false && $pIdx > 0) ? $sortedAsc[$pIdx - 1] : null;
    $prevRowData = ($pPrev !== null) ? ($allRows[$pPrev] ?? null) : null;

    $deltaEau  = se_delta($row['eau_m3']       ?? null, $prevRowData['eau_m3']       ?? null);
    $deltaGaz  = se_delta($row['gaz_kwh']      ?? null, $prevRowData['gaz_kwh']      ?? null);
    $deltaJour = se_delta($row['elec_jour_kwh'] ?? null, $prevRowData['elec_jour_kwh'] ?? null);
    $deltaNuit = se_delta($row['elec_nuit_kwh'] ?? null, $prevRowData['elec_nuit_kwh'] ?? null);

    // Warn if any delta is negative OR > 3× trailing mean
    $warn = false;
    foreach (['eau_m3', 'gaz_kwh', 'elec_jour_kwh', 'elec_nuit_kwh'] as $fi => $fn) {
        $deltaArr = [$deltaEau, $deltaGaz, $deltaJour, $deltaNuit][$fi];
        if ($deltaArr[1] === true) { $warn = true; break; }  // negative delta
        if ($deltaArr[2] !== null && $deltaArr[2] > 0) {
            $mean3 = se_trailing3_mean_delta($allRows, $p, $fn);
            if ($mean3 !== null && $mean3 > 0 && $deltaArr[2] > 3 * $mean3) {
                $warn = true; break;
            }
        }
    }

    $historyRows[$p] = [
        'row'       => $row,
        'deltaEau'  => $deltaEau,
        'deltaGaz'  => $deltaGaz,
        'deltaJour' => $deltaJour,
        'deltaNuit' => $deltaNuit,
        'warn'      => $warn,
    ];
}

$csrfToken = csrf_token();

// Prev-row values as floats for JS data attributes
$prevEauJs  = ($prevRow && $prevRow['eau_m3']       !== null) ? (float)$prevRow['eau_m3']       : '';
$prevGazJs  = ($prevRow && $prevRow['gaz_kwh']       !== null) ? (float)$prevRow['gaz_kwh']      : '';
$prevJourJs = ($prevRow && $prevRow['elec_jour_kwh'] !== null) ? (float)$prevRow['elec_jour_kwh'] : '';
$prevNuitJs = ($prevRow && $prevRow['elec_nuit_kwh'] !== null) ? (float)$prevRow['elec_nuit_kwh'] : '';

// Prefill values for estimate edit or invoice display
$fillEau  = $selectedRow ? $selectedRow['eau_m3']        : null;
$fillGaz  = $selectedRow ? $selectedRow['gaz_kwh']       : null;
$fillJour = $selectedRow ? $selectedRow['elec_jour_kwh'] : null;
$fillNuit = $selectedRow ? $selectedRow['elec_nuit_kwh'] : null;

// Month label helper
$frMonths = ['01'=>'Janvier','02'=>'Février','03'=>'Mars','04'=>'Avril','05'=>'Mai',
             '06'=>'Juin','07'=>'Juillet','08'=>'Août','09'=>'Septembre','10'=>'Octobre',
             '11'=>'Novembre','12'=>'Décembre'];

/**
 * Format "YYYY-MM" → "Janvier 2026"
 */
function se_month_label(string $period, array $frMonths): string
{
    [$y, $m] = explode('-', $period, 2);
    return ($frMonths[$m] ?? $m) . ' ' . $y;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Saisie Énergie — MaltyTask</title>
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../public/css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/saisie-energie.css?v=<?= @filemtime(__DIR__ . '/../../public/css/saisie-energie.css') ?: time() ?>">
</head>
<body class="home saisie-energie">
  <?php require __DIR__ . '/../../app/partials/topbar.php'; ?>

  <main id="main-content" class="main">
    <div class="se-wrap">

      <!-- ── Page header ─────────────────────────────────────────────────────── -->
      <header class="se-page-head">
        <h1 class="se-page-title">Saisie des index compteurs</h1>
        <p class="se-page-sub">Relevés mensuels eau · gaz · électricité (HP/HC)</p>
      </header>

      <!-- ── Flash ───────────────────────────────────────────────────────────── -->
      <?php if ($flash !== null): ?>
        <div class="se-flash se-flash--<?= htmlspecialchars($flash['type']) ?>" role="alert">
          <?= $flash['type'] === 'ok' ? '✓' : '⚠' ?>
          <?= htmlspecialchars($flash['msg']) ?>
        </div>
      <?php endif; ?>

      <!-- ── Period selector (GET form) ──────────────────────────────────────── -->
      <section class="se-period-selector" aria-label="Sélection du mois">
        <form method="get" action="/modules/saisie-energie.php" class="se-period-form" id="se-period-form">
          <label class="se-period-label" for="se-month-input">Mois de saisie</label>
          <input type="month" id="se-month-input" name="period"
                 value="<?= htmlspecialchars($selectedPeriod) ?>"
                 class="se-month-input">
          <button type="submit" class="se-period-btn">Afficher</button>
        </form>
      </section>

      <!-- ── Entry form card ─────────────────────────────────────────────────── -->
      <section class="se-form-card" aria-labelledby="se-form-heading">
        <h2 id="se-form-heading" class="se-form-title">
          <?= htmlspecialchars(se_month_label($selectedPeriod, $frMonths)) ?>
        </h2>

        <?php if ($isInvoiceLocked): ?>
          <!-- Invoice-locked notice -->
          <div class="se-invoice-notice" role="status">
            ⚡ Ce mois est déjà renseigné depuis la facture SIE/SIL — non modifiable ici.
          </div>

          <!-- Read-only display of invoice values -->
          <div class="se-readonly-grid" aria-label="Valeurs de la facture">
            <div class="se-readonly-row">
              <span class="se-readonly-label">Eau (m³)</span>
              <span class="se-readonly-val"><?= se_fmt($fillEau) ?></span>
            </div>
            <div class="se-readonly-row">
              <span class="se-readonly-label">Gaz (m³)</span>
              <span class="se-readonly-val"><?= se_fmt($fillGaz) ?></span>
            </div>
            <div class="se-readonly-row">
              <span class="se-readonly-label">Élec. Jour / HP</span>
              <span class="se-readonly-val"><?= se_fmt($fillJour) ?></span>
            </div>
            <div class="se-readonly-row">
              <span class="se-readonly-label">Élec. Nuit / HC</span>
              <span class="se-readonly-val"><?= se_fmt($fillNuit) ?></span>
            </div>
          </div>

        <?php else: ?>
          <!-- Editable form -->
          <form method="post" action="/modules/saisie-energie.php" class="se-entry-form" id="se-entry-form"
                data-prev-eau="<?= htmlspecialchars((string)$prevEauJs) ?>"
                data-prev-gaz="<?= htmlspecialchars((string)$prevGazJs) ?>"
                data-prev-jour="<?= htmlspecialchars((string)$prevJourJs) ?>"
                data-prev-nuit="<?= htmlspecialchars((string)$prevNuitJs) ?>">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="period" value="<?= htmlspecialchars($selectedPeriod) ?>">

            <?php if ($isExistingEstimate): ?>
              <p class="se-edit-notice">
                ✎ Estimation existante — vous pouvez modifier les valeurs ci-dessous.
              </p>
            <?php endif; ?>

            <!-- Eau -->
            <div class="se-field-row">
              <label class="se-field-label" for="se-eau">
                Eau
                <span class="se-field-unit">index compteur, m³</span>
              </label>
              <input type="number" id="se-eau" name="eau_m3" step="any" min="0"
                     class="se-field-input"
                     value="<?= $fillEau !== null ? htmlspecialchars((string)(float)$fillEau) : '' ?>"
                     placeholder="ex. 1234.567"
                     data-field="eau_m3">
              <span class="se-prev-hint" data-field="eau_m3" data-prev="<?= htmlspecialchars((string)$prevEauJs) ?>">
                <?php if ($prevRow && $prevRow['eau_m3'] !== null): ?>
                  Mois précédent: <?= se_fmt($prevRow['eau_m3']) ?>
                <?php else: ?>
                  Mois précédent: —
                <?php endif; ?>
              </span>
              <span class="se-delta" data-field="eau_m3" aria-live="polite">—</span>
            </div>

            <!-- Gaz -->
            <div class="se-field-row">
              <label class="se-field-label" for="se-gaz">
                Gaz
                <span class="se-field-unit">index compteur, m³</span>
              </label>
              <input type="number" id="se-gaz" name="gaz_kwh" step="any" min="0"
                     class="se-field-input"
                     value="<?= $fillGaz !== null ? htmlspecialchars((string)(float)$fillGaz) : '' ?>"
                     placeholder="ex. 5678.000"
                     data-field="gaz_kwh">
              <span class="se-prev-hint" data-field="gaz_kwh" data-prev="<?= htmlspecialchars((string)$prevGazJs) ?>">
                <?php if ($prevRow && $prevRow['gaz_kwh'] !== null): ?>
                  Mois précédent: <?= se_fmt($prevRow['gaz_kwh']) ?>
                <?php else: ?>
                  Mois précédent: —
                <?php endif; ?>
              </span>
              <span class="se-delta" data-field="gaz_kwh" aria-live="polite">—</span>
            </div>

            <!-- Élec. Jour / HP -->
            <div class="se-field-row">
              <label class="se-field-label" for="se-jour">
                Électricité Jour <abbr title="Heures Pleines">HP</abbr>
                <span class="se-field-unit">index compteur, kWh</span>
              </label>
              <input type="number" id="se-jour" name="elec_jour_kwh" step="any" min="0"
                     class="se-field-input"
                     value="<?= $fillJour !== null ? htmlspecialchars((string)(float)$fillJour) : '' ?>"
                     placeholder="ex. 12345.000"
                     data-field="elec_jour_kwh">
              <span class="se-prev-hint" data-field="elec_jour_kwh" data-prev="<?= htmlspecialchars((string)$prevJourJs) ?>">
                <?php if ($prevRow && $prevRow['elec_jour_kwh'] !== null): ?>
                  Mois précédent: <?= se_fmt($prevRow['elec_jour_kwh']) ?>
                <?php else: ?>
                  Mois précédent: —
                <?php endif; ?>
              </span>
              <span class="se-delta" data-field="elec_jour_kwh" aria-live="polite">—</span>
            </div>

            <!-- Élec. Nuit / HC -->
            <div class="se-field-row">
              <label class="se-field-label" for="se-nuit">
                Électricité Nuit <abbr title="Heures Creuses">HC</abbr>
                <span class="se-field-unit">index compteur, kWh</span>
              </label>
              <input type="number" id="se-nuit" name="elec_nuit_kwh" step="any" min="0"
                     class="se-field-input"
                     value="<?= $fillNuit !== null ? htmlspecialchars((string)(float)$fillNuit) : '' ?>"
                     placeholder="ex. 8765.000"
                     data-field="elec_nuit_kwh">
              <span class="se-prev-hint" data-field="elec_nuit_kwh" data-prev="<?= htmlspecialchars((string)$prevNuitJs) ?>">
                <?php if ($prevRow && $prevRow['elec_nuit_kwh'] !== null): ?>
                  Mois précédent: <?= se_fmt($prevRow['elec_nuit_kwh']) ?>
                <?php else: ?>
                  Mois précédent: —
                <?php endif; ?>
              </span>
              <span class="se-delta" data-field="elec_nuit_kwh" aria-live="polite">—</span>
            </div>

            <div class="se-form-actions">
              <button type="submit" class="se-submit-btn" id="se-submit-btn">
                Enregistrer l'index
              </button>
              <p class="se-form-note">
                Les champs non renseignés conserveront leur valeur précédente s'il en existe une.
              </p>
            </div>
          </form>
        <?php endif; ?>
      </section>

      <!-- ── Breakdown panel ────────────────────────────────────────────────────── -->
      <section class="se-breakdown-section" aria-labelledby="se-breakdown-heading">
        <?php if ($selectedRow === null || $prevRow === null): ?>
          <details class="se-bd-details">
            <summary class="se-bd-summary" id="se-breakdown-heading">
              <span class="se-bd-title">Détail du calcul</span>
              <span class="se-bd-chevron" aria-hidden="true">▸</span>
            </summary>
            <div class="se-bd-placeholder">
              Saisir les index pour voir le calcul du coût énergie anticipé.
            </div>
          </details>

        <?php elseif ($seEstimateErr !== null): ?>
          <details class="se-bd-details">
            <summary class="se-bd-summary" id="se-breakdown-heading">
              <span class="se-bd-title">Détail du calcul</span>
              <span class="se-bd-chevron" aria-hidden="true">▸</span>
            </summary>
            <div class="se-bd-placeholder">
              Calcul indisponible : <?= htmlspecialchars($seEstimateErr) ?>
            </div>
          </details>

        <?php else: ?>
          <?php
            /* Determine panel framing: invoice source = confirmed values, else estimate */
            $seIsInvoice  = $isInvoiceLocked;
            $seTitle      = $seIsInvoice
              ? 'Détail du calcul — Valeurs issues de la facture'
              : 'Détail du calcul — Coût anticipé';

            $seC  = $seEstimate['consumption'];
            $seG  = $seEstimate['gas'];
            $seW  = $seEstimate['waterSewage'];
            $seE  = $seEstimate['electricity'];

            /* Raw meter deltas for consumption derivation display */
            $seRawEau  = (float)($selectedRow['eau_m3']        ?? 0) - (float)($prevRow['eau_m3']        ?? 0);
            $seRawGaz  = (float)($selectedRow['gaz_kwh']       ?? 0) - (float)($prevRow['gaz_kwh']       ?? 0);
            $seRawJour = (float)($selectedRow['elec_jour_kwh'] ?? 0) - (float)($prevRow['elec_jour_kwh'] ?? 0);
            $seRawNuit = (float)($selectedRow['elec_nuit_kwh'] ?? 0) - (float)($prevRow['elec_nuit_kwh'] ?? 0);

            /* Gas coefficient back-derived from raw delta and consumption result */
            $seGazCoeff  = ($seRawGaz  > 0) ? round($seC['gas_kWh']     / $seRawGaz,  4) : 10.6079;
            $seElecCoeff = ($seRawJour > 0) ? round($seC['elec_hp_kWh'] / $seRawJour, 4) : 15.0;

            /* Helper: format CHF */
            $seFmt = fn(float $v): string => number_format($v, 2, '.', "'");
            /* Helper: format raw meter values */
            $seFmtRaw = fn(float $v): string => number_format($v, 3, '.', ' ');
            /* Helper: format consumption values */
            $seFmtCons = fn(float $v): string => number_format($v, 1, '.', ' ');

            /* Peak source label */
            $sePeakLabels = [
              'actual-invoice'     => 'relevé facture réelle',
              'frozen-rolling'     => 'moyenne glissante figée (mois clôturé)',
              'rolling-at-closure' => 'moyenne glissante à la clôture (estimé)',
              'rolling-live'       => 'moyenne glissante en cours (estimé)',
              'fallback'           => 'valeur tarifaire par défaut',
            ];
            $sePeakLabel = $sePeakLabels[$seEstimate['peakSource']] ?? $seEstimate['peakSource'];
          ?>

          <details class="se-bd-details">
            <summary class="se-bd-summary" id="se-breakdown-heading">
              <span class="se-bd-title"><?= htmlspecialchars($seTitle) ?></span>
              <span class="se-bd-total">
                Total HT :
                <strong><?= $seFmt($seEstimate['total']) ?> CHF</strong>
              </span>
              <span class="se-bd-chevron" aria-hidden="true">▸</span>
            </summary>

            <div class="se-bd-body">

              <!-- ── Consumption derivation ────────────────────────────────────────── -->
              <div class="se-bd-block">
                <h3 class="se-bd-block-title">Dérivation de la consommation</h3>
                <div class="se-bd-conso-grid">

                  <div class="se-bd-conso-row">
                    <span class="se-bd-conso-label">Eau</span>
                    <code class="se-bd-conso-math">
                      <?= $seFmtRaw((float)($selectedRow['eau_m3'] ?? 0)) ?> − <?= $seFmtRaw((float)($prevRow['eau_m3'] ?? 0)) ?>
                      = <?= $seFmtRaw($seRawEau) ?> m³ × 1 = <strong><?= $seFmtCons($seC['water_m3']) ?> m³</strong>
                    </code>
                  </div>

                  <div class="se-bd-conso-row">
                    <span class="se-bd-conso-label">Gaz</span>
                    <code class="se-bd-conso-math">
                      <?= $seFmtRaw((float)($selectedRow['gaz_kwh'] ?? 0)) ?> − <?= $seFmtRaw((float)($prevRow['gaz_kwh'] ?? 0)) ?>
                      = <?= $seFmtRaw($seRawGaz) ?> m³ × <?= htmlspecialchars((string)$seGazCoeff) ?> kWh/m³ = <strong><?= $seFmtCons($seC['gas_kWh']) ?> kWh</strong>
                    </code>
                  </div>

                  <div class="se-bd-conso-row">
                    <span class="se-bd-conso-label">Élec. Jour HP</span>
                    <code class="se-bd-conso-math">
                      <?= $seFmtRaw((float)($selectedRow['elec_jour_kwh'] ?? 0)) ?> − <?= $seFmtRaw((float)($prevRow['elec_jour_kwh'] ?? 0)) ?>
                      = <?= $seFmtRaw($seRawJour) ?> × <?= htmlspecialchars((string)$seElecCoeff) ?> = <strong><?= $seFmtCons($seC['elec_hp_kWh']) ?> kWh</strong>
                    </code>
                  </div>

                  <div class="se-bd-conso-row">
                    <span class="se-bd-conso-label">Élec. Nuit HC</span>
                    <code class="se-bd-conso-math">
                      <?= $seFmtRaw((float)($selectedRow['elec_nuit_kwh'] ?? 0)) ?> − <?= $seFmtRaw((float)($prevRow['elec_nuit_kwh'] ?? 0)) ?>
                      = <?= $seFmtRaw($seRawNuit) ?> × <?= htmlspecialchars((string)$seElecCoeff) ?> = <strong><?= $seFmtCons($seC['elec_hc_kWh']) ?> kWh</strong>
                    </code>
                  </div>

                </div>
              </div>

              <!-- ── Gas ───────────────────────────────────────────────────────────── -->
              <div class="se-bd-block">
                <h3 class="se-bd-block-title">Gaz <span class="se-bd-ht"><?= $seFmt($seG['ht']) ?> CHF HT</span></h3>
                <table class="se-bd-table" aria-label="Détail gaz">
                  <tbody>
                    <tr><td class="se-bd-line-label">Abonnement mensuel</td>          <td class="se-bd-line-val"><?= $seFmt($seG['breakdown']['subscription']) ?></td></tr>
                    <tr><td class="se-bd-line-label">Clause puissance souscrite</td>  <td class="se-bd-line-val"><?= $seFmt($seG['breakdown']['powerClause']) ?></td></tr>
                    <tr><td class="se-bd-line-label">Conso. (<?= $seFmtCons($seC['gas_kWh']) ?> kWh)</td> <td class="se-bd-line-val"><?= $seFmt($seG['breakdown']['consumption']) ?></td></tr>
                    <tr><td class="se-bd-line-label">Taxe CO₂</td>                    <td class="se-bd-line-val"><?= $seFmt($seG['breakdown']['co2Tax']) ?></td></tr>
                    <tr class="se-bd-subtotal"><td>Sous-total HT</td>                 <td><?= $seFmt($seG['ht']) ?></td></tr>
                  </tbody>
                </table>
              </div>

              <!-- ── Water + Sewage ────────────────────────────────────────────────── -->
              <div class="se-bd-block">
                <h3 class="se-bd-block-title">Eau &amp; égouts <span class="se-bd-ht"><?= $seFmt($seW['ht']) ?> CHF HT</span></h3>
                <table class="se-bd-table" aria-label="Détail eau et égouts">
                  <tbody>
                    <tr><td class="se-bd-line-label">Frais fixes mensuels</td>         <td class="se-bd-line-val"><?= $seFmt($seW['breakdown']['fixedMonthly']) ?></td></tr>
                    <tr><td class="se-bd-line-label">Consommation (<?= $seFmtCons($seC['water_m3']) ?> m³)</td> <td class="se-bd-line-val"><?= $seFmt($seW['breakdown']['waterVariable']) ?></td></tr>
                    <tr><td class="se-bd-line-label">Fonds solidarité</td>             <td class="se-bd-line-val"><?= $seFmt($seW['breakdown']['solidarityFund']) ?></td></tr>
                    <tr><td class="se-bd-line-label">Égouts (avec rabais non-potable)</td> <td class="se-bd-line-val"><?= $seFmt($seW['breakdown']['sewage']) ?></td></tr>
                    <tr class="se-bd-subtotal"><td>Sous-total HT</td>                  <td><?= $seFmt($seW['ht']) ?></td></tr>
                  </tbody>
                </table>
              </div>

              <!-- ── Electricity ───────────────────────────────────────────────────── -->
              <div class="se-bd-block">
                <h3 class="se-bd-block-title">Électricité <span class="se-bd-ht"><?= $seFmt($seE['ht']) ?> CHF HT</span></h3>

                <!-- Peak demand basis notice -->
                <p class="se-bd-peak-note">
                  Puissance de pointe :
                  <strong class="se-bd-peak-kw"><?= htmlspecialchars(number_format($seEstimate['peakKW'], 1)) ?> kW</strong>
                  —
                  <span class="se-bd-peak-src <?= $seEstimate['peakSource'] === 'actual-invoice' ? 'se-bd-peak-src--real' : 'se-bd-peak-src--est' ?>">
                    <?= htmlspecialchars($sePeakLabel) ?>
                  </span>
                </p>

                <table class="se-bd-table" aria-label="Détail électricité">
                  <tbody>
                    <tr><td class="se-bd-line-label">Énergie (HP + HC)</td>                    <td class="se-bd-line-val"><?= $seFmt($seE['breakdown']['energy']) ?></td></tr>
                    <tr><td class="se-bd-line-label">ACHEM régional (réseau local SIE)</td>    <td class="se-bd-line-val"><?= $seFmt($seE['breakdown']['achemRegional']) ?></td></tr>
                    <tr><td class="se-bd-line-label">ACHEM national (réseau Swissgrid)</td>    <td class="se-bd-line-val"><?= $seFmt($seE['breakdown']['achemNational']) ?></td></tr>
                    <tr><td class="se-bd-line-label">Taxes fédérales / cantonales / comm. (TVA)</td> <td class="se-bd-line-val"><?= $seFmt($seE['breakdown']['taxesTvable']) ?></td></tr>
                    <tr><td class="se-bd-line-label">Taxe cantonale LVLEne + comm. spécifique (exonéré TVA)</td> <td class="se-bd-line-val"><?= $seFmt($seE['breakdown']['taxesTvaExempt']) ?></td></tr>
                    <tr class="se-bd-subtotal"><td>Sous-total HT</td>                          <td><?= $seFmt($seE['ht']) ?></td></tr>
                  </tbody>
                </table>
              </div>

              <!-- ── Grand total ───────────────────────────────────────────────────── -->
              <div class="se-bd-grand-total">
                <span class="se-bd-gt-label">Coût énergie anticipé (HT)</span>
                <span class="se-bd-gt-caption">Gaz + Eau &amp; égouts + Électricité — repris dans le COP/COGS jusqu'à l'arrivée de la facture</span>
                <span class="se-bd-gt-value"><?= $seFmt($seEstimate['total']) ?> CHF</span>
              </div>

            </div><!-- /.se-bd-body -->
          </details>
        <?php endif; ?>
      </section>

      <!-- ── History table ────────────────────────────────────────────────────── -->
      <section class="se-history-section" aria-labelledby="se-history-heading">
        <h2 id="se-history-heading" class="se-history-title">Historique</h2>

        <?php if (empty($historyRows)): ?>
          <p class="se-history-empty">Aucune donnée enregistrée.</p>
        <?php else: ?>
          <div class="se-table-wrap" role="region" aria-label="Historique des index" tabindex="0">
            <table class="se-history" aria-labelledby="se-history-heading">
              <thead>
                <tr>
                  <th scope="col">Mois</th>
                  <th scope="col">Eau (m³)</th>
                  <th scope="col">Gaz (m³)</th>
                  <th scope="col">Élec. Jour HP</th>
                  <th scope="col">Élec. Nuit HC</th>
                  <th scope="col">Source</th>
                  <th scope="col">Modifié par</th>
                  <th scope="col">Δ Eau</th>
                  <th scope="col">Δ Gaz</th>
                  <th scope="col">Δ Jour</th>
                  <th scope="col">Δ Nuit</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($historyRows as $p => $hr): ?>
                  <?php
                    $row     = $hr['row'];
                    $rowCls  = 'se-history__row';
                    if ($p === $selectedPeriod) $rowCls .= ' se-row--selected';
                    if ($hr['warn'])            $rowCls .= ' se-row--warn';
                  ?>
                  <tr class="<?= htmlspecialchars($rowCls) ?>">
                    <td class="se-cell se-cell--period"><?= htmlspecialchars($p) ?></td>
                    <td class="se-cell se-cell--num"><?= se_fmt($row['eau_m3']       ?? null) ?></td>
                    <td class="se-cell se-cell--num"><?= se_fmt($row['gaz_kwh']      ?? null) ?></td>
                    <td class="se-cell se-cell--num"><?= se_fmt($row['elec_jour_kwh'] ?? null) ?></td>
                    <td class="se-cell se-cell--num"><?= se_fmt($row['elec_nuit_kwh'] ?? null) ?></td>
                    <td class="se-cell">
                      <?php if ($row['source'] === 'invoice'): ?>
                        <span class="se-badge se-badge--invoice">facture</span>
                      <?php else: ?>
                        <span class="se-badge se-badge--estimate">estimation</span>
                      <?php endif; ?>
                    </td>
                    <td class="se-cell se-cell--meta"><?= htmlspecialchars($row['last_modified_by'] ?? '—') ?></td>
                    <td class="se-cell se-cell--delta <?= $hr['deltaEau'][1]  ? 'se-delta-neg' : '' ?>"><?= htmlspecialchars($hr['deltaEau'][0])  ?></td>
                    <td class="se-cell se-cell--delta <?= $hr['deltaGaz'][1]  ? 'se-delta-neg' : '' ?>"><?= htmlspecialchars($hr['deltaGaz'][0])  ?></td>
                    <td class="se-cell se-cell--delta <?= $hr['deltaJour'][1] ? 'se-delta-neg' : '' ?>"><?= htmlspecialchars($hr['deltaJour'][0]) ?></td>
                    <td class="se-cell se-cell--delta <?= $hr['deltaNuit'][1] ? 'se-delta-neg' : '' ?>"><?= htmlspecialchars($hr['deltaNuit'][0]) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

    </div><!-- /.se-wrap -->
  </main>

  <script src="/js/saisie-energie.js?v=<?= @filemtime(__DIR__ . '/../../public/js/saisie-energie.js') ?: time() ?>"></script>
</body>
</html>
