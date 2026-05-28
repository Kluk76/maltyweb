<?php
declare(strict_types=1);
/**
 * session-body-racking.php — Phase dispatcher for racking sessions.
 *
 * Loaded by session-shell.php (line 171) when $session['form_type'] === 'racking'.
 * Inherits the shell's scope: $session, $steps, $firewall, $labels, $me, $pdo.
 *
 * P-A (skeleton): renders ALL three phase partials concatenated under ONE form.
 * The form POSTs to the original single-submit endpoint /modules/form-racking.php.
 * No phase-gating logic exists here — that is P-B (firewall cards) + P-C (split-write).
 * The point is to verify that the partial extraction composes correctly with the
 * shell envelope before adding firewall logic.
 *
 * Variables built here and made available to the phase sub-partials:
 *   $candidates, $candidatesOverride, $canOverride,
 *   $bbts, $ccts, $yts, $cipTypes, $cipConfig,
 *   $recentRackings, $bbtBlendCandidates, $bbtCleanStates,
 *   $candidatesJson, $candidatesOverrideJson, $bbtBlendCandidatesJson,
 *   $qcThresholdsJson, $pertesConfigJson, $csrf
 */

require_once __DIR__ . '/../../../app/yeast-eligibility.php';
require_once __DIR__ . '/../../../app/tank-simulator.php';
require_once __DIR__ . '/../../../app/cip-events.php';
require_once __DIR__ . '/../../../app/qc-thresholds.php';
require_once __DIR__ . '/../../../app/cip-cadence.php';

// ── Allowed enum values (mirror form-racking.php constants) ──────────────────
// Constants RACK_TYPES / DEST_TYPES / CENTRI_RINSED_YN are defined in form-racking.php.
// When loaded via the shell (not form-racking.php), they may not exist yet.
// Guard with defined() to avoid redeclaration errors if the session-shell path
// ever loads form-racking.php first (defensive; today they don't conflict).
if (!defined('DEST_TYPES')) {
    define('DEST_TYPES',       ['BBT', 'CCT', 'YT']);
}
if (!defined('CENTRI_RINSED_YN')) {
    define('CENTRI_RINSED_YN', ['Oui', 'Non']);
}

// ── Build the data the phase partials need ────────────────────────────────────
$_rack_loadErr = null;
try {
    // Role gate
    $canOverride = (is_admin($me) || is_manager($me));

    // ── Candidate lots (CCT-based gated list) ────────────────────────────────
    $candidateBaseSql = "
        SELECT
          bfw.recipe_id_fk,
          bfw.cct                                                      AS source_cct,
          bfw.batch,
          bfw.beer,
          bfw.event_date                                               AS brew_date,
          r.id                                                         AS recipe_id,
          r.name                                                       AS recipe_name,
          COALESCE(NULLIF(bfw.beer,''), r.name)                        AS beer_display,
          MAX(f.event_date)                                            AS cold_crash_date,
          DATEDIFF(CURDATE(), MAX(f.event_date))                       AS days_since_cold_crash,
          " . implode(",\n          ", yeast_eligibility_select_expressions()) . "
        FROM bd_brewing_brewday_v2 bfw
        JOIN ref_recipes r ON r.id = bfw.recipe_id_fk
        LEFT JOIN bd_fermenting_v2 f
             ON f.recipe_id_fk = bfw.recipe_id_fk
            AND f.batch = bfw.batch
            AND f.event_type = 'ColdCrash'
            AND f.is_tombstoned = 0
        " . yeast_eligibility_join_fragment() . "
        WHERE bfw.is_tombstoned = 0
          AND bfw.cct IS NOT NULL
          AND r.is_active = 1
          AND NOT EXISTS (
            SELECT 1 FROM bd_brewing_brewday_v2 b2
            WHERE b2.cct = bfw.cct
              AND b2.is_tombstoned = 0
              AND b2.event_date IS NOT NULL
              AND b2.event_date > bfw.event_date
          )
        GROUP BY
          bfw.recipe_id_fk, bfw.cct, bfw.batch, bfw.beer, bfw.event_date,
          r.id, r.name,
          r.yeast_strain_id_fk, r.garde_days_min_override,
          r.ferm_temp_min_override, r.ferm_temp_max_override,
          ys.name, ys.family,
          yfd.garde_days_min, yfd.ferm_temp_min, yfd.ferm_temp_max";

    // Normal (gated) query
    $candStmt = $pdo->prepare(
        $candidateBaseSql .
        " HAVING effective_garde IS NOT NULL
               AND days_since_cold_crash >= effective_garde
               AND NOT EXISTS (
                     SELECT 1 FROM bd_racking_v2 rk
                     WHERE rk.neb_recipe_id_fk = bfw.recipe_id_fk
                       AND rk.neb_batch = bfw.batch
                       AND rk.is_tombstoned = 0
                       AND rk.interrupted_flag = 0
                   )
          ORDER BY days_since_cold_crash DESC"
    );
    $candStmt->execute();
    $candidates = $candStmt->fetchAll(PDO::FETCH_ASSOC);

    // TankSimulator authoritative CCT occupancy filter
    $simState = (new TankSimulator($pdo))->run(new DateTimeImmutable('today'));

    $simFilterCct = function (array $list, array $simState): array {
        $out = [];
        foreach ($list as $cand) {
            $cctNum    = (int)($cand['source_cct'] ?? 0);
            $tankState = $simState['cct'][$cctNum] ?? null;
            if ($tankState === null) continue;
            $cand['sim_vol_hl'] = round((float)($tankState['volume_hl'] ?? 0), 2);
            $out[] = $cand;
        }
        return $out;
    };

    $candidates = $simFilterCct($candidates, $simState);

    // Override (hors-process) candidate list
    $candidatesOverride = [];
    if ($canOverride) {
        $overrideStmt = $pdo->prepare($candidateBaseSql . " ORDER BY bfw.event_date DESC");
        $overrideStmt->execute();
        $candidatesOverrideCct = $overrideStmt->fetchAll(PDO::FETCH_ASSOC);
        $candidatesOverrideCct = $simFilterCct($candidatesOverrideCct, $simState);

        $seenTanks = [];
        foreach ($candidatesOverrideCct as $cand) {
            $key = 'cct|' . (int)($cand['source_cct'] ?? 0);
            $seenTanks[$key] = true;
            $cand['source_tank_type'] = 'CCT';
            $candidatesOverride[] = $cand;
        }

        // BBT-occupied lots
        $bbtCandSql = "
            SELECT
              r.id                                                     AS racking_id,
              'BBT'                                                    AS source_tank_type,
              r.bbt_number                                             AS source_bbt,
              r.neb_beer,
              r.neb_batch,
              r.neb_recipe_id_fk,
              r.contract_beer,
              r.contract_batch,
              r.contract_recipe_id_fk,
              COALESCE(NULLIF(r.neb_beer,''), r.contract_beer)        AS beer_display,
              r.racked_vol_hl,
              r.event_date                                             AS brew_date,
              r2.name                                                  AS recipe_name
            FROM bd_racking_v2 r
            LEFT JOIN ref_recipes r2
              ON r2.id = COALESCE(r.neb_recipe_id_fk, r.contract_recipe_id_fk)
            WHERE r.racking_destination_type IN ('BBT', 'CCT')
              AND r.bbt_number IS NOT NULL
              AND r.is_tombstoned = 0
              AND r.id = (
                SELECT id FROM bd_racking_v2 r2i
                WHERE r2i.bbt_number = r.bbt_number
                  AND r2i.racking_destination_type IN ('BBT','CCT')
                  AND r2i.is_tombstoned = 0
                ORDER BY r2i.submitted_at DESC
                LIMIT 1
              )
            ORDER BY r.bbt_number ASC";

        $bbtCandRows = $pdo->query($bbtCandSql)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bbtCandRows as $cand) {
            $bbtNum    = (int)($cand['source_bbt'] ?? 0);
            $tankState = $simState['bbt'][$bbtNum] ?? null;
            if ($tankState === null || (float)($tankState['volume_hl'] ?? 0) <= 0) continue;
            $key = 'bbt|' . $bbtNum;
            if (isset($seenTanks[$key])) continue;
            $seenTanks[$key] = true;
            $cand['sim_vol_hl'] = round((float)($tankState['volume_hl'] ?? 0), 2);
            $candidatesOverride[] = $cand;
        }
    }

    // Vessel lists
    $bbtsStmt = $pdo->query("SELECT number, capacity_hl FROM ref_bbt WHERE status='active' ORDER BY number ASC");
    $bbts = $bbtsStmt->fetchAll(PDO::FETCH_ASSOC);

    $cctsStmt = $pdo->query("SELECT number FROM ref_cct WHERE status='active' ORDER BY number ASC");
    $ccts = $cctsStmt->fetchAll(PDO::FETCH_ASSOC);

    $ytsStmt  = $pdo->query("SELECT number FROM ref_yt  WHERE status='active' ORDER BY number ASC");
    $yts  = $ytsStmt->fetchAll(PDO::FETCH_ASSOC);

    // BBT clean states
    $bbtCleanStates = [];
    foreach ($bbts as $bbtRow) {
        $bbtNum = (int)$bbtRow['number'];
        $bbtCleanStates[$bbtNum] = cip_dest_bbt_is_clean($pdo, $bbtNum);
    }

    // CIP options
    $cipTypes = cip_type_options($pdo);

    // Recent submissions
    $recentRows = $pdo->prepare(
        "SELECT id, event_date, neb_beer, neb_batch, contract_beer, contract_batch,
                rack_type, target_tank_raw, racked_vol_hl, audit_flags,
                email, submitted_at, hors_process_flag, hors_process_reason
         FROM bd_racking_v2
         WHERE audit_flags LIKE '%web_entry%'
           AND is_tombstoned = 0
         ORDER BY submitted_at DESC LIMIT 10"
    );
    $recentRows->execute();
    $recentRackings = $recentRows->fetchAll();

} catch (Throwable $e) {
    $_rack_loadErr      = $e->getMessage();
    $candidates         = [];
    $candidatesOverride = [];
    $bbts               = [];
    $ccts               = [];
    $yts                = [];
    $cipTypes           = [];
    $recentRackings     = [];
    $bbtCleanStates     = [];
    $canOverride        = false;
}

// C5 — BBT blend candidates
$bbtBlendCandidates = [];
try {
    $bbtComposition = tank_bbt_composition($simState ?? []);
    foreach ($bbtComposition as $entry) {
        $beer = $entry['beer'];
        if (!isset($bbtBlendCandidates[$beer])) $bbtBlendCandidates[$beer] = [];
        $bbtBlendCandidates[$beer][] = $entry;
    }
} catch (Throwable $_bbtErr) {
    $bbtBlendCandidates = [];
}

// JSON surfaces for JS
$candidatesJson         = json_encode($candidates,         JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$candidatesOverrideJson = json_encode($candidatesOverride, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$bbtBlendCandidatesJson = json_encode($bbtBlendCandidates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

// QC thresholds for JS
$qcThresholdsJson = 'null';
try {
    $allQcRecipeIds = [];
    foreach ($candidates as $c) {
        $rid = (int)($c['recipe_id'] ?? 0);
        if ($rid > 0) $allQcRecipeIds[] = $rid;
    }
    foreach ($candidatesOverride as $c) {
        $rid = (int)($c['recipe_id'] ?? $c['neb_recipe_id_fk'] ?? $c['contract_recipe_id_fk'] ?? 0);
        if ($rid > 0) $allQcRecipeIds[] = $rid;
    }
    $allQcRecipeIds = array_values(array_unique(array_filter($allQcRecipeIds)));

    $metricToField = static function (array $metricMap): array {
        return [
            'racked_vol_hl' => $metricMap['racked_vol_hl'],
            'bbt_co2'       => $metricMap['co2'],
            'bbt_o2'        => $metricMap['o2'],
            'bbt_pressure'  => $metricMap['pressure'],
        ];
    };

    $qcThresholds = [];
    $globalBands  = qc_global_bands($pdo);
    $globalMetric = [
        'racked_vol_hl' => ['label' => 'Volume transféré',    'unit' => ' HL',  'warn' => $globalBands['vol']['warn'],      'outlier' => $globalBands['vol']['outlier']],
        'co2'           => ['label' => 'CO₂ destination',     'unit' => ' g/L', 'warn' => $globalBands['co2']['warn'],      'outlier' => $globalBands['co2']['outlier']],
        'o2'            => ['label' => 'O₂ destination',      'unit' => ' ppb', 'warn' => $globalBands['o2']['warn'],       'outlier' => $globalBands['o2']['outlier']],
        'pressure'      => ['label' => 'Pression destination', 'unit' => ' bar', 'warn' => $globalBands['pressure']['warn'], 'outlier' => $globalBands['pressure']['outlier']],
    ];
    $qcThresholds['__global'] = $metricToField($globalMetric);

    if (!empty($allQcRecipeIds)) {
        $perRecipe = qc_thresholds_for_recipes($pdo, $allQcRecipeIds);
        foreach ($perRecipe as $recipeId => $metricMap) {
            $qcThresholds[(string)$recipeId] = $metricToField($metricMap);
        }
    }
    $qcThresholdsJson = json_encode($qcThresholds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
} catch (Throwable $_qcErr) {
    $qcThresholdsJson = 'null';
}

// Pertes config for JS
$pertesConfig = ['rack_warn_pct' => 2.0];
try {
    $pertesStmt = $pdo->prepare(
        "SELECT key_name, value_num
           FROM commissioning_settings
          WHERE section = 'pertes' AND key_name = 'pertes_rack_warn_pct' AND is_active = 1
          LIMIT 1"
    );
    $pertesStmt->execute();
    $pertesRow = $pertesStmt->fetch(PDO::FETCH_ASSOC);
    if ($pertesRow !== false && $pertesRow['value_num'] !== null) {
        $pertesConfig['rack_warn_pct'] = (float)$pertesRow['value_num'];
    }
} catch (Throwable $_pertesErr) {
    // Non-fatal — JS uses the default 2 %.
}
$pertesConfigJson = json_encode($pertesConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

// CIP partial config (new submission)
$cipConfig = [
    'machines'            => ['centri', 'kze', 'pump'],
    'show_inline_combine' => true,
    'vessels'             => [[
        'code'          => 'bbt',
        'number'        => null,
        'label'         => 'CIP BBT',
        'dynamic_label' => true,
        'required'      => true,
    ]],
    'cip_types' => $cipTypes,
    'existing'  => null,
];

// CSRF token (shell has already generated $csrf; generate here if not in scope)
if (!isset($csrf)) {
    require_once __DIR__ . '/../../../app/csrf.php';
    $csrf = csrf_token();
}

// ── Error banner ──────────────────────────────────────────────────────────────
if ($_rack_loadErr): ?>
<div class="db-flash db-flash--err">
  ⚠ Erreur de chargement du formulaire : <?= htmlspecialchars($_rack_loadErr) ?>
</div>
<?php endif; ?>

<!--
  P-C RENDER STRATEGY: Phase-gated dispatcher.
  Only the partial matching $session['phase'] is rendered.
  Each active phase partial wraps its own <form> with CSRF + session_id.
  Closed/abandoned sessions render a read-only terminal banner.
-->
<?php
$_phase = (string)($session['phase'] ?? 'start');

if (in_array($session['status'] ?? '', ['closed', 'abandoned'], true)): ?>
  <!-- Terminal state: no form rendered — shell footer already shows the closed banner. -->
<?php elseif ($_phase === 'start'): ?>
  <?php require __DIR__ . '/racking-phase-start.php' ?>
<?php elseif ($_phase === 'in_progress'): ?>
  <?php require __DIR__ . '/racking-phase-in-progress.php' ?>
<?php elseif ($_phase === 'end'): ?>
  <?php require __DIR__ . '/racking-phase-end.php' ?>
<?php else: ?>
  <div class="db-flash db-flash--err">
    Phase de session inconnue : <?= htmlspecialchars($_phase) ?>
  </div>
<?php endif ?>

<!-- Recent submissions are rendered OUTSIDE the <form> — matching form-racking.php structure. -->
<?php require __DIR__ . '/racking-phase-recent.php' ?>

<?php
// ── C6b: BBT CIP cadence — P-B data surface ──────────────────────────────────
// Computed once here and injected as window.BBT_CIP_CADENCE for the JS layer.
// P-B: used by the attestation button JS to show cadence badges.
// P-C: consumed in the BBT picker (S5 IN_PROGRESS) for inline warn chips.
$bbtCipCadenceJson = 'null';
try {
    // array_values() re-indexes so json_encode produces a real JSON array (not an
    // object keyed by bbt_number). JS iterates via Array.prototype.forEach.
    $bbtCipCadence     = array_values(cadence_for_all_bbts($pdo));
    $bbtCipCadenceJson = json_encode($bbtCipCadence, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);
} catch (Throwable $_cadErr) {
    // Non-fatal: cadence data is informational; missing data is safe.
}
?>
<!-- Form assets: loaded after </form>, outside the form element. -->
<script>
// Window globals expected by racking-form.js (matching form-racking.php names).
window.RF_CANDIDATES          = <?= $candidatesJson ?>;
window.RF_CANDIDATES_OVERRIDE = <?= $candidatesOverrideJson ?>;
window.RF_CAN_OVERRIDE        = <?= ($canOverride ?? false) ? 'true' : 'false' ?>;
window.QC_THRESHOLDS          = <?= $qcThresholdsJson ?>;
window.PERTES_CONFIG          = <?= $pertesConfigJson ?>;
window.BBT_BLEND_CANDIDATES   = <?= $bbtBlendCandidatesJson ?>;
window.BBT_CLEAN_STATES       = <?= json_encode($bbtCleanStates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
// P-B: C6b cadence resolver output + session firewall state for attestation JS.
window.BBT_CIP_CADENCE        = <?= $bbtCipCadenceJson ?>;
window.SESSION_FIREWALL       = <?= json_encode([
    'cip_done'         => (bool)($firewall['cip_done'] ?? false),
    'eligibility_done' => (bool)($firewall['eligibility_done'] ?? false),
    'qc_done'          => (bool)($firewall['qc_done'] ?? false),
    'all_clear'        => (bool)($firewall['all_clear'] ?? false),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?>;
</script>
<script src="/js/racking-form.js?v=<?= @filemtime(__DIR__ . '/../../js/racking-form.js') ?: time() ?>"></script>
<link rel="stylesheet" href="/css/cip-section.css?v=<?= @filemtime(__DIR__ . '/../../css/cip-section.css') ?: time() ?>">
<link rel="stylesheet" href="/css/racking-form.css?v=<?= @filemtime(__DIR__ . '/../../css/racking-form.css') ?: time() ?>">
