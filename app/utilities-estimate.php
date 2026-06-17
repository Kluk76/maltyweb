<?php
declare(strict_types=1);

/**
 * app/utilities-estimate.php
 *
 * Utility cost estimator — PHP port of lib/utilities.js.
 *
 * Public surface:
 *   utilities_estimate_month(PDO $pdo, string $monthKey, array $closedMonths = []): array
 *
 * Returns an array with keys:
 *   'gas'            => ['ht' => float, 'tva' => float, 'ttc' => float, 'breakdown' => [...]]
 *   'waterSewage'    => ['ht' => float, 'tva' => float, 'ttc' => float, 'breakdown' => [...]]
 *   'electricity'    => ['ht' => float, 'tva' => float, 'ttc' => float, 'breakdown' => [...]]
 *   'total'          => float   (gas.ht + waterSewage.ht + electricity.ht)
 *   'peakKW'         => float
 *   'peakSource'     => string
 *   'reactive_kVArh' => float
 *   'consumption'    => ['water_m3' => float, 'gas_kWh' => float, 'elec_hp_kWh' => float, 'elec_hc_kWh' => float]
 *
 * Pure compute for the non-write legs; inserts into ops_utility_closures ONLY
 * when no row already exists for a given period (INSERT IGNORE semantics).
 * Never updates or overwrites an existing closure row.
 */

// ── Rounding helper ────────────────────────────────────────────────────────────

/**
 * Round to 2 decimal places — mirrors JS Math.round(n*100)/100 exactly.
 *
 * PHP's round($n, 2) applies an epsilon-based algorithm that can disagree with
 * JS Math.round() on boundary values (e.g. 2883.77499999... rounds to 2883.78
 * in PHP but 2883.77 in JS). Using floor(n*100 + 0.5)/100 replicates JS's
 * native double-precision arithmetic without the epsilon correction.
 */
function utility_r2(float $n): float
{
  return floor($n * 100 + 0.5) / 100.0;
}

// ── Tariff loader ──────────────────────────────────────────────────────────────

/**
 * Load the tariff effective for $monthKey (YYYY-MM).
 *
 * Selects the tariff with the latest effective_from that is still <= the first
 * day of $monthKey. Falls back to the oldest tariff if no match found.
 *
 * @return array  Decoded tariff_json as a nested PHP array.
 */
function _utility_tariff_for(PDO $pdo, string $monthKey): array
{
  $stmt = $pdo->prepare("
    SELECT tariff_json
    FROM ref_utility_tariffs
    WHERE effective_from <= :first_day
    ORDER BY effective_from DESC
    LIMIT 1
  ");
  $stmt->execute([':first_day' => $monthKey . '-01']);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($row === false) {
    // Fallback: oldest tariff
    $fallbackStmt = $pdo->query("SELECT tariff_json FROM ref_utility_tariffs ORDER BY effective_from ASC LIMIT 1");
    $row = $fallbackStmt !== false ? $fallbackStmt->fetch(PDO::FETCH_ASSOC) : false;
  }

  if ($row === false || $row['tariff_json'] === null) {
    throw new \RuntimeException(sprintf(
      '_utility_tariff_for: no tariff found for month %s',
      $monthKey
    ));
  }

  $decoded = json_decode((string)$row['tariff_json'], true);
  if (!is_array($decoded)) {
    throw new \RuntimeException(sprintf(
      '_utility_tariff_for: invalid tariff_json for month %s',
      $monthKey
    ));
  }

  return $decoded;
}

// ── Readings loader ────────────────────────────────────────────────────────────

/**
 * Load all cumulative meter readings from inv_energydata.
 *
 * @return array<string, array{eau: float, gaz: float, elecJour: float, elecNuit: float, peakKW: float|null, reactive_kVArh: float}>
 *         Keyed by 'YYYY-MM'.
 */
function _utility_load_readings(PDO $pdo): array
{
  $stmt = $pdo->query("
    SELECT period, eau_m3, gaz_kwh, elec_jour_kwh, elec_nuit_kwh, peak_kw, reactive_kvarh
    FROM inv_energydata
    ORDER BY period
  ");

  $out = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $period = trim((string)$r['period']);
    if ($period === '') continue;
    $n = static function ($x): float {
      return ($x === null || $x === '') ? 0.0 : (float)$x;
    };
    $out[$period] = [
      'eau'           => $n($r['eau_m3']),
      'gaz'           => $n($r['gaz_kwh']),
      'elecJour'      => $n($r['elec_jour_kwh']),
      'elecNuit'      => $n($r['elec_nuit_kwh']),
      'peakKW'        => ($r['peak_kw'] !== null && $r['peak_kw'] !== '') ? (float)$r['peak_kw'] : null,
      'reactive_kVArh'=> $n($r['reactive_kvarh']),
    ];
  }

  return $out;
}

// ── Gas cost ───────────────────────────────────────────────────────────────────

/**
 * Compute gas cost for a month given kWh consumed.
 * Port of computeGasCost() in lib/utilities.js.
 *
 * @return array{ht: float, tva: float, ttc: float, breakdown: array}
 */
function _utility_compute_gas_cost(float $gas_kWh, array $tariff): array
{
  $g = $tariff['gas'];

  $subscription = (float)$g['subscriptionCHFPerMonth'];
  $powerClause  = (float)$g['subscribedCapacityKW'] * (float)$g['powerClauseCHFPerKWPerMonth'];
  $consumption  = $gas_kWh * (float)$g['consumptionCHFPerKWh'];
  $co2Tax       = $gas_kWh * (float)$g['co2TaxCHFPerKWh'];
  $ht           = $subscription + $powerClause + $consumption + $co2Tax;
  $tva          = $ht * (float)$g['tvaRate'];

  return [
    'ht'  => utility_r2($ht),
    'tva' => utility_r2($tva),
    'ttc' => utility_r2($ht + $tva),
    'breakdown' => [
      'subscription' => utility_r2($subscription),
      'powerClause'  => utility_r2($powerClause),
      'consumption'  => utility_r2($consumption),
      'co2Tax'       => utility_r2($co2Tax),
    ],
  ];
}

// ── Water + sewage cost ────────────────────────────────────────────────────────

/**
 * Compute water + sewage cost for a month given m³ consumed.
 * Port of computeWaterCost() in lib/utilities.js.
 *
 * @return array{ht: float, tva: float, ttc: float, breakdown: array}
 */
function _utility_compute_water_cost(float $water_m3, array $tariff): array
{
  $w = $tariff['water'];
  $s = $tariff['sewage'];

  $fixedMonthly = (float)$w['taxeBaseCHFPerMonth']
    + (float)$w['meterRentalCHFPerMonth']
    + (float)$w['antiReturnValveCHFPerMonth']
    + ((float)$w['meterCapacityM3h'] * (float)$w['flowFeeCHFPerM3hPerYear'] / 12);

  $waterVar  = $water_m3 * (float)$w['consumptionCHFPerM3'];
  $solidarity = $water_m3 * (float)$w['solidarityFundCHFPerM3'];
  $sewage    = $water_m3 * (float)$s['baseRateCHFPerM3'] * (1 - (float)$s['nonDrinkingRebate']);

  // TVA: water (fixed + variable) at w.tvaRate; solidarity not taxed; sewage at s.tvaRate
  $waterHt  = $fixedMonthly + $waterVar;
  $waterTva = $waterHt * (float)$w['tvaRate'];
  $sewageTva = $sewage * (float)$s['tvaRate'];
  $ht  = $waterHt + $solidarity + $sewage;
  $tva = $waterTva + $sewageTva;

  return [
    'ht'  => utility_r2($ht),
    'tva' => utility_r2($tva),
    'ttc' => utility_r2($ht + $tva),
    'breakdown' => [
      'fixedMonthly'  => utility_r2($fixedMonthly),
      'waterVariable' => utility_r2($waterVar),
      'solidarityFund'=> utility_r2($solidarity),
      'sewage'        => utility_r2($sewage),
    ],
  ];
}

// ── Electricity cost ───────────────────────────────────────────────────────────

/**
 * Compute electricity cost for a month.
 * Port of computeElectricityCost() in lib/utilities.js.
 *
 * @return array{ht: float, tva: float, ttc: float, breakdown: array}
 */
function _utility_compute_electricity_cost(
  float $hp_kWh,
  float $hc_kWh,
  array $tariff,
  float $peakKW,
  float $reactive_kVArh
): array {
  $e = $tariff['electricity'];

  $totalKWh = $hp_kWh + $hc_kWh;

  $energy = $hp_kWh * (float)$e['energy']['hpCHFPerKWh']
          + $hc_kWh * (float)$e['energy']['hcCHFPerKWh'];

  $r = $e['achemRegional'];
  $achemReg = (float)$r['subscriptionCHFPerMonth']
    + $hp_kWh * (float)$r['hpCHFPerKWh']
    + $hc_kWh * (float)$r['hcCHFPerKWh']
    + $peakKW * (float)$r['peakPowerCHFPerKWPerMonth']
    + max(0.0, $reactive_kVArh - (float)$r['reactiveFranchiseKVArh']) * (float)$r['reactiveChargeCHFPerKVArh']
    + (float)$r['measuringSubscriptionCHFPerMonth'];

  $n = $e['achemNational'];
  $achemNat = $hp_kWh * (float)$n['hpCHFPerKWh']
    + $hc_kWh * (float)$n['hcCHFPerKWh']
    + $peakKW * (float)$n['peakPowerCHFPerKWPerMonth']
    + $totalKWh * (float)$n['winterReserveCHFPerKWh']
    + $totalKWh * (float)$n['systemServicesCHFPerKWh'] * 2  // applied to both HP and HC lines in invoice
    + $totalKWh * (float)$n['solidarityCostsCHFPerKWh'];

  $t = $e['taxes'];
  $taxesTvable = $totalKWh * (
    (float)$t['federalesLEneCHFPerKWh']
    + (float)$t['emolumentCantonalCHFPerKWh']
    + (float)$t['emolumentCommunalCHFPerKWh']
  );
  $taxesTvaExempt = $totalKWh * (
    (float)$t['cantonalLVLEneCHFPerKWh']
    + (float)$t['taxeCommunaleSpecifiqueCHFPerKWh']
  );

  $ht      = $energy + $achemReg + $achemNat + $taxesTvable + $taxesTvaExempt;
  $tvaBase = $energy + $achemReg + $achemNat + $taxesTvable;
  $tva     = $tvaBase * (float)$e['tvaRate'];

  return [
    'ht'  => utility_r2($ht),
    'tva' => utility_r2($tva),
    'ttc' => utility_r2($ht + $tva),
    'breakdown' => [
      'energy'         => utility_r2($energy),
      'achemRegional'  => utility_r2($achemReg),
      'achemNational'  => utility_r2($achemNat),
      'taxesTvable'    => utility_r2($taxesTvable),
      'taxesTvaExempt' => utility_r2($taxesTvaExempt),
      'peakKW'         => $peakKW,
      'reactive_kVArh' => $reactive_kVArh,
    ],
  ];
}

// ── Peak kW resolver ───────────────────────────────────────────────────────────

/**
 * Resolve peak kW for every reading month using the 4-branch priority logic.
 *
 * Branch 1: inv_energydata.peak_kw > 0 → actual, source='actual-invoice'.
 *           INSERT IGNORE into ops_utility_closures only when no row exists yet.
 * Branch 2: closed month AND existing closure with source != 'actual-invoice'
 *           → frozen-rolling (no write).
 * Branch 3: closed month AND (no closure OR closure IS 'actual-invoice' with
 *           no energydata peak) → rollingMean, source='rolling-at-closure'.
 *           INSERT only when no row exists yet (never overwrite 'actual-invoice').
 * Branch 4: open month → rollingMean, source='rolling-live' (no write).
 *
 * Rolling window = last 12 months where inv_energydata.peak_kw > 0.
 * Fallback when no actuals = $fallback (tariff's defaultPeakPowerKW).
 *
 * @param PDO      $pdo
 * @param array    $readings     Output of _utility_load_readings()
 * @param string[] $closedMonths List of YYYY-MM strings considered closed
 * @param float    $fallback     Used when rollingMean cannot be computed
 * @param bool     $readonly     When true, skip all writes to ops_utility_closures
 * @return array<string, array{peakKW: float, reactive_kVArh: float, source: string}>
 */
function _utility_resolve_peak_kw(PDO $pdo, array $readings, array $closedMonths, float $fallback, bool $readonly = true): array
{
  $closedSet = array_flip($closedMonths);

  // Load existing closures from ops_utility_closures
  $closureStmt = $pdo->query("
    SELECT period, peak_kw, reactive_kvarh, snapshot_source
    FROM ops_utility_closures
  ");
  $existingClosures = [];
  foreach ($closureStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $existingClosures[(string)$row['period']] = [
      'peakKW'         => (float)$row['peak_kw'],
      'reactive_kVArh' => (float)$row['reactive_kvarh'],
      'source'         => (string)$row['snapshot_source'],
    ];
  }

  // Build actuals map: months where inv_energydata.peak_kw > 0
  $actuals = [];
  foreach ($readings as $mk => $r) {
    if ($r['peakKW'] !== null && $r['peakKW'] > 0) {
      $actuals[$mk] = $r['peakKW'];
    }
  }

  // Rolling mean: last 12 known actuals
  $actualKeys = array_keys($actuals);
  sort($actualKeys);
  $recent = array_slice($actualKeys, -12);
  $rollingMean = count($recent) > 0
    ? array_sum(array_map(fn(string $k) => $actuals[$k], $recent)) / count($recent)
    : $fallback;

  $result = [];
  $sortedMonths = array_keys($readings);
  sort($sortedMonths);

  $insertStmt = null;
  if (!$readonly) {
    $insertStmt = $pdo->prepare("
      INSERT INTO ops_utility_closures (period, peak_kw, reactive_kvarh, snapshot_source)
      VALUES (?, ?, ?, ?)
    ");
  }

  foreach ($sortedMonths as $mk) {
    $isClosed = isset($closedSet[$mk]);

    if (isset($actuals[$mk])) {
      // Branch 1: actual invoice data present
      $peakKW   = $actuals[$mk];
      $reactive = $readings[$mk]['reactive_kVArh'] ?? 0.0;
      $source   = 'actual-invoice';

      // Insert only if no row exists for this period (readonly gates all writes)
      if (!$readonly && !isset($existingClosures[$mk])) {
        $insertStmt->execute([$mk, $peakKW, $reactive, 'actual-invoice']);
        $existingClosures[$mk] = ['peakKW' => $peakKW, 'reactive_kVArh' => $reactive, 'source' => 'actual-invoice'];
      }

      $result[$mk] = ['peakKW' => $peakKW, 'reactive_kVArh' => $reactive, 'source' => $source];

    } elseif ($isClosed) {
      $snap = $existingClosures[$mk] ?? null;

      if ($snap !== null && $snap['source'] !== 'actual-invoice') {
        // Branch 2: closed with frozen-rolling snapshot
        $result[$mk] = [
          'peakKW'         => $snap['peakKW'],
          'reactive_kVArh' => $snap['reactive_kVArh'],
          'source'         => 'frozen-rolling',
        ];
      } else {
        // Branch 3: closed, no snapshot or snapshot is 'actual-invoice' with no energydata peak.
        // Node logic: falls through to rollingMean regardless (the snapshot 'actual-invoice'
        // is not used for cost computation when col G is absent — matches Node resolvePeakKW).
        $peakKW = $rollingMean;
        $source = 'rolling-at-closure';

        // Insert only when no row exists — never overwrite 'actual-invoice' with rolling
        if (!$readonly && $snap === null) {
          $insertStmt->execute([$mk, $peakKW, 0.0, 'rolling-at-closure']);
          $existingClosures[$mk] = ['peakKW' => $peakKW, 'reactive_kVArh' => 0.0, 'source' => 'rolling-at-closure'];
        }

        $result[$mk] = ['peakKW' => $peakKW, 'reactive_kVArh' => 0.0, 'source' => $source];
      }

    } else {
      // Branch 4: open month — live rolling mean, no write
      $result[$mk] = ['peakKW' => $rollingMean, 'reactive_kVArh' => 0.0, 'source' => 'rolling-live'];
    }
  }

  return $result;
}

// ── Public API ─────────────────────────────────────────────────────────────────

/**
 * Estimate utility costs for a single month.
 *
 * closedMonths is derived automatically from ops_utility_closures (all stored
 * periods = closed set), matching the live path used by the Node engine.
 *
 * $readonly = true (default): no writes to ops_utility_closures.
 * $readonly = false: may INSERT rolling-at-closure rows for newly-closed months.
 *   IMMUTABILITY RULE: 'actual-invoice' rows are never overwritten.
 *
 * @param PDO    $pdo
 * @param string $monthKey  YYYY-MM
 * @param bool   $readonly  gate writes to ops_utility_closures
 * @return array{
 *   gas:            array{ht: float, tva: float, ttc: float, breakdown: array},
 *   waterSewage:    array{ht: float, tva: float, ttc: float, breakdown: array},
 *   electricity:    array{ht: float, tva: float, ttc: float, breakdown: array},
 *   total:          float,
 *   peakKW:         float,
 *   peakSource:     string,
 *   reactive_kVArh: float,
 *   consumption:    array{water_m3: float, gas_kWh: float, elec_hp_kWh: float, elec_hc_kWh: float},
 *   monthKey:       string,
 *   notes:          string[]
 * }
 * @throws \InvalidArgumentException when $monthKey is not in readings or has no previous month.
 */
function utilities_estimate_month(PDO $pdo, string $monthKey, bool $readonly = true): array
{
  if (!preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
    throw new \InvalidArgumentException(sprintf(
      'utilities_estimate_month: invalid monthKey "%s" — expected YYYY-MM',
      $monthKey
    ));
  }

  $readings = _utility_load_readings($pdo);
  $tariff   = _utility_tariff_for($pdo, $monthKey);

  if (!isset($readings[$monthKey])) {
    throw new \InvalidArgumentException(sprintf(
      'utilities_estimate_month: no readings for month %s',
      $monthKey
    ));
  }

  // Find previous month in the sorted readings list
  $sortedKeys = array_keys($readings);
  sort($sortedKeys);
  $mkIndex = array_search($monthKey, $sortedKeys, true);

  if ($mkIndex === false || $mkIndex === 0) {
    throw new \InvalidArgumentException(sprintf(
      'utilities_estimate_month: no previous month reading available for %s',
      $monthKey
    ));
  }

  $prevKey = $sortedKeys[$mkIndex - 1];
  $cur     = $readings[$monthKey];
  $prev    = $readings[$prevKey];

  // Consumption deltas (cumulative → delta)
  $water_m3    = max(0.0, $cur['eau']      - $prev['eau']);
  $gas_kWh     = max(0.0, $cur['gaz']      - $prev['gaz'])      * (float)$tariff['gas']['meterCoefficient_kWhPerM3'];
  $elec_hp_kWh = max(0.0, $cur['elecJour'] - $prev['elecJour']) * (float)$tariff['electricity']['meterCoefficient'];
  $elec_hc_kWh = max(0.0, $cur['elecNuit'] - $prev['elecNuit']) * (float)$tariff['electricity']['meterCoefficient'];

  // closedMonths: derive from ops_utility_closures (all stored periods = closed set)
  $closedRows   = $pdo->query("SELECT period FROM ops_utility_closures ORDER BY period")->fetchAll(PDO::FETCH_ASSOC);
  $closedMonths = array_column($closedRows, 'period');

  // Resolve peak kW for all months (may write closures when $readonly = false)
  $fallback = (float)$tariff['electricity']['defaultPeakPowerKW'];
  $peakMap  = _utility_resolve_peak_kw($pdo, $readings, $closedMonths, $fallback, $readonly);
  $peakInfo = $peakMap[$monthKey] ?? ['peakKW' => $fallback, 'reactive_kVArh' => 0.0, 'source' => 'fallback'];

  // Reactive kVArh: from raw inv_energydata col G (matches Node loadAndComputeUtilities).
  // For months where col G is NULL, reactive_kVArh = 0 — consistent with Node behaviour.
  $reactive_kVArh = $readings[$monthKey]['reactive_kVArh'] ?? 0.0;

  $gasCost   = _utility_compute_gas_cost($gas_kWh, $tariff);
  $waterCost = _utility_compute_water_cost($water_m3, $tariff);
  $elecCost  = _utility_compute_electricity_cost($elec_hp_kWh, $elec_hc_kWh, $tariff, $peakInfo['peakKW'], $reactive_kVArh);

  $total = $gasCost['ht'] + $waterCost['ht'] + $elecCost['ht'];
  $notes = [];

  return [
    'gas'            => $gasCost,
    'waterSewage'    => $waterCost,
    'electricity'    => $elecCost,
    'total'          => utility_r2($total),
    'peakKW'         => $peakInfo['peakKW'],
    'peakSource'     => $peakInfo['source'],
    'reactive_kVArh' => $reactive_kVArh,
    'consumption'    => [
      'water_m3'    => $water_m3,
      'gas_kWh'     => $gas_kWh,
      'elec_hp_kWh' => $elec_hp_kWh,
      'elec_hc_kWh' => $elec_hc_kWh,
    ],
    'monthKey'       => $monthKey,
    'notes'          => $notes,
  ];
}

/**
 * Canonical accessor for COP utilities cost for a given month (HT only).
 *
 * Returns ['gas_water' => float, 'electricity' => float] where:
 *   gas_water    = gas.ht + waterSewage.ht  (GL 4700)
 *   electricity  = electricity.ht           (GL 4702)
 * Returns null on failure (no readings, no tariff, future month).
 * 4701 (waste) is intentionally excluded — waste remains booked-only.
 *
 * This is the single canonical entry point for all COP-utilities consumers.
 * All surfaces (CSV §3, KPI tiles, financier board) MUST call this function —
 * never inline-copy the override logic.
 */
function utilities_cop_ht_for_month(PDO $pdo, string $monthKey): ?array
{
    try {
        $est = utilities_estimate_month($pdo, $monthKey, true);
        return [
            'gas_water'   => (float)$est['gas']['ht'] + (float)$est['waterSewage']['ht'],
            'electricity' => (float)$est['electricity']['ht'],
        ];
    } catch (\Throwable $e) {
        return null;
    }
}
