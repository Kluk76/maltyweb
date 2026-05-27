<?php
declare(strict_types=1);
/**
 * form-racking.php — Operator transfer (racking) entry form (bd_racking_v2).
 *
 * Deliverable A: renamed from "Soutirage" → "Transferts" in all user-facing labels.
 *   File name, route (?m=racking / URL /modules/form-racking.php), and DB table
 *   names are intentionally UNCHANGED.
 *
 * Deliverable B: Beer selection replaced from ungated dropdown to selectable
 *   eligibility cards. A beer is eligible iff:
 *     - It is currently in a CCT per TankSimulator (the authoritative state engine).
 *       SQL is a coarse pre-filter only; the sim is the truth (mirrors form-packaging.php).
 *     - Its latest ColdCrash event (bd_fermenting_v2) is ≥ effective_garde days ago.
 *     - effective_garde comes from yeast-eligibility.php (COALESCE override→family).
 *     - NULL effective_garde → NOT eligible (surfaces only under hors-process).
 *
 * Deliverable C: "Choix Hors Process" toggle (manager/admin only). Expands
 *   candidate set to ALL beers physically in a CCT ignoring the time gate.
 *   Server-side role enforcement: hors_process=1 from a non-manager is silently ignored.
 *   Writes hors_process_flag + hors_process_reason to bd_racking_v2 (migration 174).
 *
 * Writes to: bd_racking_v2 + audit_row_revisions
 * Natural key: (submitted_at, neb_beer, neb_batch, contract_beer, contract_batch, seq)
 * URL: /modules/form-racking.php  (route unchanged)
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/settings-helpers.php';
require __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/yeast-eligibility.php';
require_once __DIR__ . '/../../app/tank-simulator.php';

require_login();
$me = current_user();

// ── Allowed enum values ───────────────────────────────────────────────────
const RACK_TYPES        = ['Centri', 'KZE', 'Pump', 'Centri+KZE'];
const DEST_TYPES        = ['BBT', 'CCT', 'YT'];
const CIP_YN            = ['Oui', 'Non'];
const CENTRI_RINSED_YN  = ['Oui', 'Non'];

// ── POST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/form-racking.php');
    }

    try {
        $pdo = maltytask_pdo();

        // ── 1. Coerce + validate inputs ──────────────────────────────────

        // ── Override: Choix Hors Process (manager/admin only) ───────────
        // Server-side enforcement: silently ignore hors_process=1 from a
        // non-manager/admin. Never trust the client-side gate alone.
        $horsProcessRequested = (post_int('hors_process') === 1);
        $horsProcessAllowed   = (is_admin($me) || is_manager($me));
        $horsProcessFlag      = ($horsProcessRequested && $horsProcessAllowed) ? 1 : 0;
        $horsProcessReason    = ($horsProcessFlag === 1) ? post_str('hors_process_reason') : null;

        // Beer identity — populated by JS from card selection (hidden fields)
        $nebBeer      = post_str('neb_beer')     ?? '';
        $nebBatch     = post_str('neb_batch')    ?? '';
        $contractBeer  = post_str('contract_beer')  ?? '';
        $contractBatch = post_str('contract_batch') ?? '';
        $sourceCct     = post_int('source_cct_number');   // which CCT the beer is coming from

        if ($nebBeer === '' && $contractBeer === '') {
            throw new RuntimeException("Au moins une bière (Nébuleuse ou contrat) est requise.");
        }

        $nebRecipeId      = post_int('neb_recipe_id_fk');
        $contractRecipeId = post_int('contract_recipe_id_fk');
        $rackType         = post_str('rack_type');
        if ($rackType !== null) must_be_one_of('rack_type', $rackType, RACK_TYPES);

        $destType   = post_str('racking_destination_type');
        if ($destType !== null) must_be_one_of('racking_destination_type', $destType, DEST_TYPES);

        $bbtNumber = post_int('bbt_number');
        $cctNumber = post_int('cct_number');

        // Build target_tank_raw from parsed destination
        $targetTankRaw = null;
        if ($destType === 'BBT' && $bbtNumber !== null) {
            $targetTankRaw = "BBT {$bbtNumber}";
        } elseif ($destType === 'CCT' && $cctNumber !== null) {
            $targetTankRaw = "CCT {$cctNumber}";
        } elseif ($destType === 'YT') {
            $targetTankRaw = 'YT';
        }

        $client         = post_str('client');
        $lastCipDate    = post_str('last_cip_date');
        $cipType        = post_str('cip_type');

        $startTimeRaw = post_str('start_time');
        $endTimeRaw   = post_str('end_time');
        $eventDateRaw = post_str('event_date');
        // Combine event_date + times for start_time/end_time DATETIME columns
        $startTime = ($eventDateRaw && $startTimeRaw) ? "{$eventDateRaw} {$startTimeRaw}:00" : null;
        $endTime   = ($eventDateRaw && $endTimeRaw)   ? "{$eventDateRaw} {$endTimeRaw}:00"   : null;

        $bbtCo2      = post_decimal('bbt_co2');
        $bbtO2       = post_decimal('bbt_o2');
        $rackedVolHl = post_decimal('racked_vol_hl');
        $blendHl     = post_decimal('blend_hl');
        $avgTurbidity = post_decimal('avg_turbidity');
        $avgSpeed    = post_decimal('avg_speed');
        $bbtPressure = post_decimal('bbt_pressure');

        $centriRinsed = post_str('centri_rinsed');
        if ($centriRinsed !== null) must_be_one_of('centri_rinsed', $centriRinsed, CENTRI_RINSED_YN);

        $cipBbtDone = post_str('cip_bbt_done');
        if ($cipBbtDone !== null) must_be_one_of('cip_bbt_done', $cipBbtDone, CIP_YN);
        $cipBbtType = post_str('cip_bbt_type');
        $cipBbtDate = post_str('cip_bbt_date');
        $comments   = post_str('comments');

        // Operator comment from diff-preview dialog (may be empty string)
        $fwComment = post_str('fw_comment');

        // ── 2. QC flag ───────────────────────────────────────────────────
        $measurements = array_filter([
            'bbt_co2'      => $bbtCo2,
            'bbt_o2'       => $bbtO2,
            'racked_vol_hl'=> $rackedVolHl,
            'bbt_pressure' => $bbtPressure,
        ], fn($v) => $v !== null);
        $qcFlag = bd_qc_flag($measurements);

        // ── 3. Build submitted_at (now with microseconds) ────────────────
        $submittedAt = date('Y-m-d H:i:s.u');
        $eventDate   = $eventDateRaw ?? date('Y-m-d');

        // Build audit_flags
        $auditTokens = ['web_entry'];
        if ($qcFlag !== 'normal') $auditTokens[] = "qc_{$qcFlag}";
        if ($horsProcessFlag === 1) $auditTokens[] = 'hors_process_override';
        $auditFlags = implode(',', $auditTokens);

        // ── 4. Canonical row for hash (exclude meta cols) ────────────────
        $hashCols = [
            $nebBeer, $nebBatch, $nebRecipeId ?? '',
            $contractBeer, $contractBatch, $contractRecipeId ?? '',
            $rackType ?? '', $client ?? '',
            $startTime ?? '', $endTime ?? '',
            $destType ?? '', $bbtNumber ?? '', $cctNumber ?? '',
            $targetTankRaw ?? '',
            $bbtCo2 ?? '', $bbtO2 ?? '', $rackedVolHl ?? '', $blendHl ?? '',
            $avgTurbidity ?? '', $avgSpeed ?? '', $bbtPressure ?? '',
            $centriRinsed ?? '', $lastCipDate ?? '', $cipType ?? '',
            $cipBbtDone ?? '', $cipBbtType ?? '', $cipBbtDate ?? '',
            $comments ?? '',
            $horsProcessFlag,
        ];
        $rowHash = bd_row_hash($hashCols);

        // ── 5. Build row array ───────────────────────────────────────────
        $row = [
            'row_hash'                 => $rowHash,
            'audit_flags'              => $auditFlags,
            'submitted_at'             => $submittedAt,
            'email'                    => $me['username'],
            'event_date'               => $eventDate,
            'seq'                      => 0,
            'neb_beer'                 => $nebBeer,
            'neb_batch'                => $nebBatch,
            'neb_recipe_id_fk'         => $nebRecipeId,
            'contract_beer'            => $contractBeer,
            'contract_batch'           => $contractBatch,
            'contract_recipe_id_fk'    => $contractRecipeId,
            'last_cip_date'            => $lastCipDate,
            'cip_type'                 => $cipType,
            'rack_type'                => $rackType,
            'client'                   => $client,
            'start_time'               => $startTime,
            'end_time'                 => $endTime,
            'racking_destination_type' => $destType,
            'bbt_number'               => $bbtNumber,
            'cct_number'               => $cctNumber,
            'target_tank_raw'          => $targetTankRaw,
            'bbt_co2'                  => $bbtCo2,
            'bbt_o2'                   => $bbtO2,
            'racked_vol_hl'            => $rackedVolHl,
            'blend_hl'                 => $blendHl,
            'avg_turbidity'            => $avgTurbidity,
            'avg_speed'                => $avgSpeed,
            'bbt_pressure'             => $bbtPressure,
            'centri_rinsed'            => $centriRinsed,
            'cip_bbt_done'             => $cipBbtDone,
            'cip_bbt_type'             => $cipBbtType,
            'cip_bbt_date'             => $cipBbtDate,
            'comments'                 => $comments,
            'hors_process_flag'        => $horsProcessFlag,
            'hors_process_reason'      => $horsProcessReason,
        ];

        $nkCols = ['submitted_at', 'neb_beer', 'neb_batch', 'contract_beer', 'contract_batch', 'seq'];

        // ── 6. Before-snapshot (lookup by NK) ────────────────────────────
        // Web-form inserts always have a new submitted_at — before is always null.
        $beforeSnapshot = null;

        // ── 7. UPSERT ────────────────────────────────────────────────────
        $result = bd_upsert($pdo, 'bd_racking_v2', $row, $nkCols);

        // ── 8. Audit revision ─────────────────────────────────────────────
        log_revision(
            $pdo,
            $me,
            'bd_racking_v2',
            $result['id'],
            $beforeSnapshot,
            $row,
            $qcFlag,
            $fwComment ?: null
        );

        // ── 9. Success response ───────────────────────────────────────────
        $qcLabel = match($qcFlag) {
            'elevated' => ' — ⚠ valeurs à vérifier',
            'outlier'  => ' — 🔴 outlier enregistré',
            default    => '',
        };
        $beerLabel = $nebBeer !== '' ? $nebBeer : $contractBeer;
        $hpLabel   = $horsProcessFlag ? ' [HORS PROCESS]' : '';
        flash_set('ok', "Transfert enregistré : {$beerLabel}{$qcLabel}{$hpLabel}");

    } catch (Throwable $e) {
        flash_set('err', pdo_friendly_error($e, 'form-racking'));
    }

    redirect_to('/modules/form-racking.php');
}

// ── GET ───────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = maltytask_pdo();

    // ── Current user role for override capability ─────────────────────────
    $canOverride = (is_admin($me) || is_manager($me));

    // ── Candidate lots: beers in a CCT that have cold-crashed ≥ effective_garde days ──
    //
    // Eligibility derivation:
    //   1. Cold-crash day = latest bd_fermenting_v2.event_date WHERE event_type='ColdCrash'
    //      for (recipe_id_fk, batch).
    //   2. Source CCT = bd_brewing_brewday_v2.cct for that (recipe_id_fk, batch).
    //   3. SQL occupancy guard (COARSE PRE-FILTER ONLY): no later brew into the same CCT.
    //      The TankSimulator is the authoritative occupancy truth — see sim-filter below.
    //      NOTE: bd_racking_v2 is the DESTINATION of racking (output); source-CCT occupancy
    //      is owned by the TankSimulator (RACKING events clear the CCT there at ~L372-376).
    //   4. Eligible = DATEDIFF(CURDATE(), cold_crash_date) >= effective_garde.
    //      effective_garde from yeast_eligibility_join_fragment() +
    //      yeast_eligibility_select_expressions() (single COALESCE source).
    //      NULL effective_garde → NOT eligible (no strain classified → hors-process only).
    //
    // CCT→CCT blend limitation (OUT OF SCOPE / backlog):
    //   A blended lot's bd_brewing_brewday_v2.cct points at its original brew CCT, not any
    //   blend-destination CCT. Tracking current CCT after a blend needs a deliberate model
    //   extension. Until then, blended lots are accessible via hors-process only.
    //
    // Base SQL fragment (occupancy guard + cold-crash join) — reused by both
    // the normal query (with garde gate) and hors-process query (without garde gate).

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

    // Normal (gated) query: effective_garde must be non-NULL AND days >= effective_garde.
    // Extra pre-filter: drop lots that already have a live racking row (cheap SQL guard).
    // The TankSimulator (below) is the final authority; this NOT EXISTS clause just
    // prunes the obvious cases before the sim runs, keeping the gated path conservative.
    // NOT added to the hors-process path — a partial-rack edge case (CCT still partially
    // occupied after a mid-process rack) must surface there for the operator to decide.
    $candStmt = $pdo->prepare(
        $candidateBaseSql .
        " HAVING effective_garde IS NOT NULL
               AND days_since_cold_crash >= effective_garde
               AND NOT EXISTS (
                     SELECT 1 FROM bd_racking_v2 rk
                     WHERE rk.neb_recipe_id_fk = bfw.recipe_id_fk
                       AND rk.neb_batch = bfw.batch
                       AND rk.is_tombstoned = 0
                   )
          ORDER BY days_since_cold_crash DESC"
    );
    $candStmt->execute();
    $candidates = $candStmt->fetchAll(PDO::FETCH_ASSOC);

    // Override (hors-process) query: all CCT-occupying beers, no time gate.
    // Built only when current user has manager/admin role.
    // No NOT EXISTS on racking here — hors-process is the deliberate escape hatch
    // for partial-rack or edge-case lots that may still have a CCT presence.
    $candidatesOverride = [];
    if ($canOverride) {
        $overrideStmt = $pdo->prepare(
            $candidateBaseSql .
            " ORDER BY bfw.event_date DESC"
        );
        $overrideStmt->execute();
        $candidatesOverride = $overrideStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── TankSimulator: authoritative CCT occupancy filter ────────────────────
    //
    // The SQL occupancy guard (no-later-brew) is a COARSE pre-filter. It cannot detect
    // racked-out lots: a CCT is only freed in SQL when a NEW brew enters it, not when
    // the existing lot is racked out. The TankSimulator models the full event replay
    // (COOLING fills CCT, RACKING empties CCT at ~L372-376 of tank-simulator.php) and
    // is therefore the authoritative source of CCT state.
    //
    // We mirror form-packaging.php exactly:
    //   - Instantiate once on page load.
    //   - Key on (strtolower(tank_type), (int)tank_number) from the candidate's source_cct.
    //   - Drop any candidate whose source CCT is null/empty in the sim.
    //   - Do NOT compare beer strings — trust the sim's tank-occupancy as truth.
    //   - Apply to BOTH the gated and hors-process lists: hors-process relaxes the TIME
    //     gate (garde days), not physical reality — a racked-out lot is physically gone.
    $simState = (new TankSimulator($pdo))->run(new DateTimeImmutable('today'));

    /**
     * Drop candidates whose source CCT is null/empty in the TankSimulator state.
     *
     * Keying: 'cct' + (int)source_cct matches $simState['cct'][N].
     * We do NOT compare beer strings — the sim's occupancy is the truth.
     *
     * @param array[] $list       Candidate rows (each has 'source_cct' key)
     * @param array   $simState   ['cct'=>[N=>state|null,...], 'bbt'=>[...]]
     * @return array[]
     */
    $simFilterCct = function (array $list, array $simState): array {
        $out = [];
        foreach ($list as $cand) {
            $cctNum   = (int)($cand['source_cct'] ?? 0);
            $tankState = $simState['cct'][$cctNum] ?? null;
            if ($tankState === null) {
                // CCT is empty/expired in the sim — lot has been racked out. Drop it.
                continue;
            }
            $out[] = $cand;
        }
        return $out;
    };

    $candidates         = $simFilterCct($candidates,         $simState);
    $candidatesOverride = $simFilterCct($candidatesOverride, $simState);

    // Ref data for destination dropdowns
    $bbts = $pdo->query("SELECT number FROM ref_bbt ORDER BY number ASC")->fetchAll();
    $ccts = $pdo->query("SELECT number FROM ref_cct ORDER BY number ASC")->fetchAll();

    // Recent submissions (last 10 racking entries from web)
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

    $loadErr = null;
} catch (Throwable $e) {
    $candidates         = [];
    $candidatesOverride = [];
    $bbts               = [];
    $ccts               = [];
    $recentRackings     = [];
    $canOverride        = false;
    $loadErr = $e->getMessage();
}

$csrf = csrf_token();
$active_module = 'racking';

// Inject server-side data for JS
$candidatesJson         = json_encode($candidates,         JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$candidatesOverrideJson = json_encode($candidatesOverride, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Saisie Transferts — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/racking-form.css?v=<?= @filemtime(__DIR__ . '/../css/racking-form.css') ?: time() ?>">
</head>
<body class="home op-form-page op-form-racking">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main class="main">

  <?php flash_render() ?>

  <?php if ($loadErr): ?>
    <div class="db-flash db-flash--err">⚠ Erreur de chargement : <?= htmlspecialchars($loadErr) ?></div>
  <?php endif ?>

  <!-- Header -->
  <div class="op-form__header">
    <div class="op-form__eyebrow">Transferts · Racking</div>
    <h1 class="op-form__title">Saisie <em>transferts</em></h1>
    <p class="op-form__sub">
      Transfert CCT → BBT (ou CCT). Sélectionner un lot éligible (cold crash ≥ garde minimum).
      Toutes les mesures sont acceptées — des avertissements sont affichés si une valeur
      est hors plage typique, jamais bloquants.
    </p>
  </div>

  <!-- ── FORM ─────────────────────────────────────────────────────────── -->
  <form id="racking-form" method="post" action="/modules/form-racking.php" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

    <!-- Hidden fields populated by JS from card selection -->
    <input type="hidden" id="neb_beer"              name="neb_beer"              value="">
    <input type="hidden" id="neb_batch"             name="neb_batch"             value="">
    <input type="hidden" id="neb_recipe_id_fk"      name="neb_recipe_id_fk"      value="">
    <input type="hidden" id="contract_beer"         name="contract_beer"         value="">
    <input type="hidden" id="contract_batch"        name="contract_batch"        value="">
    <input type="hidden" id="contract_recipe_id_fk" name="contract_recipe_id_fk" value="">
    <input type="hidden" id="source_cct_number"     name="source_cct_number"     value="">
    <!-- hors_process: set by JS to 1 when override checkbox is checked.
         Server enforces role: if not manager/admin, this field is silently ignored. -->
    <input type="hidden" id="hors_process" name="hors_process" value="0">

    <!-- Warning panel (populated by form-framework.js) -->
    <div id="racking-warnings" class="op-form__warnings" hidden aria-live="polite"></div>

    <!-- ── Section: Sélection lot source (CCT) ───────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— sélection lot source (CCT)</div>

      <?php if ($canOverride): ?>
      <!-- Choix Hors Process — MANAGER / ADMIN ONLY.
           Operators never see this block (PHP-gated, not just CSS-hidden).
           Server will silently ignore hors_process=1 if the role gate fails anyway. -->
      <div class="rf-override-block" id="rf-override-block">
        <label class="rf-override-label">
          <input type="checkbox" id="rf-override-checkbox" class="rf-override-checkbox"
                 aria-describedby="rf-override-desc">
          <span class="rf-override-text">Choix Hors Process</span>
          <span class="rf-override-badge">Manager / Admin</span>
        </label>
        <p class="rf-override-desc" id="rf-override-desc">
          Bypasse la garde minimum (jours depuis cold crash). Affiche tous les lots
          actuellement occupant une CCT, quelle que soit leur date de cold crash ou leur
          classification levure. Toute saisie créée via cet override sera marquée
          <code>hors_process_flag = 1</code> dans <code>bd_racking_v2</code>.
        </p>
        <div class="rf-override-reason-row" id="rf-override-reason-row" hidden>
          <label class="op-form__label rf-override-reason-label" for="hors_process_reason">
            Justification <span class="op-form__opt">(recommandé)</span>
          </label>
          <input id="hors_process_reason" name="hors_process_reason" type="text"
                 class="op-form__input rf-override-reason-input"
                 placeholder="ex. Transfert urgent — CCT8 nécessaire pour brassage suivant">
        </div>
      </div>
      <?php endif ?>

      <!-- Normal candidate cards (gated: cold crash ≥ effective_garde) -->
      <div id="rf-normal-candidates">
        <?php if (empty($candidates)): ?>
          <div class="rf-empty-state">
            <strong>Aucun lot éligible.</strong><br>
            Un lot est éligible lorsqu'il est en CCT et que son cold crash date de plus
            longtemps que la garde minimum de sa levure (COALESCE override recette →
            défaut famille). Les recettes sans levure classifiée ne sont pas éligibles
            (levure non liée ou famille sans garde définie → hors process uniquement).
            <?php if ($canOverride): ?>
              Utiliser <strong>Choix Hors Process</strong> ci-dessus pour accéder à tous
              les lots en CCT indépendamment de la garde.
            <?php endif ?>
          </div>
        <?php else: ?>
          <div class="rf-cand-grid" id="rf-cand-grid-normal">
            <?php foreach ($candidates as $cand): ?>
              <?php
                $beerDisp  = htmlspecialchars($cand['beer_display'] ?? $cand['beer'] ?? '—');
                $batchDisp = htmlspecialchars($cand['batch'] ?? '—');
                $cctNum    = (int)($cand['source_cct'] ?? 0);
                $ccDate    = htmlspecialchars($cand['cold_crash_date'] ?? '—');
                $daysCold  = (int)($cand['days_since_cold_crash'] ?? 0);
                $effGarde  = $cand['effective_garde'] !== null ? (int)$cand['effective_garde'] : null;
                $recipeName= htmlspecialchars($cand['recipe_name'] ?? '');
                $recipeId  = (int)($cand['recipe_id'] ?? 0);
                $nebBeerVal = htmlspecialchars($cand['beer'] ?? '');
                $nebBatchVal= htmlspecialchars($cand['batch'] ?? '');
              ?>
              <button type="button"
                      class="rf-cand-card"
                      data-neb-beer="<?= $nebBeerVal ?>"
                      data-neb-batch="<?= $nebBatchVal ?>"
                      data-recipe-id="<?= $recipeId ?>"
                      data-source-cct="<?= $cctNum ?>"
                      data-hors-process="0">
                <div class="rf-cand-card__label">CCT <?= $cctNum ?></div>
                <div class="rf-cand-card__beer"><?= $beerDisp ?></div>
                <div class="rf-cand-card__batch">Brassin <?= $batchDisp ?></div>
                <div class="rf-cand-card__cc-date">Cold crash : <?= $ccDate ?> (<?= $daysCold ?>j)</div>
                <?php if ($effGarde !== null): ?>
                  <div class="rf-cand-card__garde">Garde : <?= $effGarde ?>j minimum</div>
                <?php endif ?>
              </button>
            <?php endforeach ?>
          </div>
        <?php endif ?>
      </div>

      <!-- Override candidate cards (hors-process: ALL CCT-occupying beers) -->
      <?php if ($canOverride): ?>
      <div id="rf-override-candidates" hidden>
        <?php if (empty($candidatesOverride)): ?>
          <div class="rf-empty-state">
            Aucun lot en CCT actuellement.
          </div>
        <?php else: ?>
          <div class="rf-cand-grid" id="rf-cand-grid-override">
            <?php foreach ($candidatesOverride as $cand): ?>
              <?php
                $beerDisp  = htmlspecialchars($cand['beer_display'] ?? $cand['beer'] ?? '—');
                $batchDisp = htmlspecialchars($cand['batch'] ?? '—');
                $cctNum    = (int)($cand['source_cct'] ?? 0);
                $ccDate    = $cand['cold_crash_date'] !== null
                               ? htmlspecialchars($cand['cold_crash_date'])
                               : 'pas encore';
                $daysCold  = $cand['days_since_cold_crash'] !== null
                               ? (int)$cand['days_since_cold_crash'] . 'j'
                               : '—';
                $effGarde  = $cand['effective_garde'] !== null ? (int)$cand['effective_garde'] : null;
                $recipeId  = (int)($cand['recipe_id'] ?? 0);
                $nebBeerVal = htmlspecialchars($cand['beer'] ?? '');
                $nebBatchVal= htmlspecialchars($cand['batch'] ?? '');
              ?>
              <button type="button"
                      class="rf-cand-card rf-cand-card--hors-process"
                      data-neb-beer="<?= $nebBeerVal ?>"
                      data-neb-batch="<?= $nebBatchVal ?>"
                      data-recipe-id="<?= $recipeId ?>"
                      data-source-cct="<?= $cctNum ?>"
                      data-hors-process="1">
                <div class="rf-cand-card__label">CCT <?= $cctNum ?></div>
                <div class="rf-cand-card__beer"><?= $beerDisp ?></div>
                <div class="rf-cand-card__batch">Brassin <?= $batchDisp ?></div>
                <div class="rf-cand-card__cc-date">Cold crash : <?= $ccDate ?> (<?= $daysCold ?>)</div>
                <?php if ($effGarde !== null): ?>
                  <div class="rf-cand-card__garde">Garde : <?= $effGarde ?>j (non atteinte)</div>
                <?php else: ?>
                  <div class="rf-cand-card__garde" style="color:var(--ink-mute)">Garde : non définie</div>
                <?php endif ?>
                <div class="rf-cand-card__badge-hp">HORS PROCESS</div>
              </button>
            <?php endforeach ?>
          </div>
        <?php endif ?>
      </div>
      <?php endif ?>

      <!-- Selected lot summary strip -->
      <div id="rf-selected-lot" class="rf-selected-lot" hidden>
        <span class="rf-selected-lot__label">Lot sélectionné :</span>
        <span id="rf-selected-summary" class="rf-selected-lot__summary"></span>
        <button type="button" id="rf-deselect" class="rf-selected-lot__clear">✕ changer</button>
      </div>
    </div><!-- card lot source -->

    <!-- ── Section: Opération ─────────────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— opération de transfert</div>
      <div class="op-form__grid">

        <!-- Date -->
        <div class="op-form__field">
          <label class="op-form__label" for="event_date">Date transfert</label>
          <input id="event_date" name="event_date" type="date" class="op-form__input"
                 value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
        </div>

        <!-- Rack type -->
        <div class="op-form__field">
          <label class="op-form__label" for="rack_type">Type de rack</label>
          <select id="rack_type" name="rack_type" class="op-form__select">
            <option value="">— sélectionner —</option>
            <?php foreach (RACK_TYPES as $rt): ?>
              <option value="<?= htmlspecialchars($rt) ?>"><?= htmlspecialchars($rt) ?></option>
            <?php endforeach ?>
          </select>
        </div>

        <!-- Start time -->
        <div class="op-form__field">
          <label class="op-form__label" for="start_time">Heure début <span class="op-form__unit">HH:MM</span></label>
          <input id="start_time" name="start_time" type="time" class="op-form__input">
        </div>

        <!-- End time -->
        <div class="op-form__field">
          <label class="op-form__label" for="end_time">Heure fin <span class="op-form__unit">HH:MM</span></label>
          <input id="end_time" name="end_time" type="time" class="op-form__input">
        </div>

        <!-- Client (for contract brews — optional) -->
        <div class="op-form__field">
          <label class="op-form__label" for="client">Client <span class="op-form__opt">(contrat)</span></label>
          <input id="client" name="client" type="text" class="op-form__input"
                 placeholder="Nébuleuse / nom client" autocomplete="off">
        </div>

      </div>
    </div>

    <!-- ── Section: Destination tank ─────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— tank destination</div>
      <div class="op-form__grid--3 op-form__grid">

        <!-- Destination type -->
        <div class="op-form__field">
          <label class="op-form__label" for="racking_destination_type">Type destination</label>
          <select id="racking_destination_type" name="racking_destination_type" class="op-form__select">
            <option value="">— sélectionner —</option>
            <?php foreach (DEST_TYPES as $dt): ?>
              <option value="<?= htmlspecialchars($dt) ?>"><?= htmlspecialchars($dt) ?></option>
            <?php endforeach ?>
          </select>
        </div>

        <!-- BBT number -->
        <div class="op-form__field" id="bbt-field">
          <label class="op-form__label" for="bbt_number">BBT n°</label>
          <select id="bbt_number" name="bbt_number" class="op-form__select">
            <option value="">—</option>
            <?php foreach ($bbts as $b): ?>
              <option value="<?= (int)$b['number'] ?>">BBT <?= (int)$b['number'] ?></option>
            <?php endforeach ?>
          </select>
        </div>

        <!-- CCT number (destination CCT — distinct from source CCT) -->
        <div class="op-form__field" id="cct-field">
          <label class="op-form__label" for="cct_number">CCT destination n°</label>
          <select id="cct_number" name="cct_number" class="op-form__select">
            <option value="">—</option>
            <?php foreach ($ccts as $c): ?>
              <option value="<?= (int)$c['number'] ?>">CCT <?= (int)$c['number'] ?></option>
            <?php endforeach ?>
          </select>
        </div>

      </div>
    </div>

    <!-- ── Section: Mesures ───────────────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— mesures</div>
      <div class="op-form__grid">

        <div class="op-form__field">
          <label class="op-form__label" for="racked_vol_hl">
            Volume transféré <span class="op-form__unit">HL</span>
          </label>
          <input id="racked_vol_hl" name="racked_vol_hl" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 29.5">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="blend_hl">
            Volume blend <span class="op-form__unit">HL</span>
          </label>
          <input id="blend_hl" name="blend_hl" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="—">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="bbt_co2">
            CO₂ BBT <span class="op-form__unit">g/L</span>
          </label>
          <input id="bbt_co2" name="bbt_co2" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 4.2">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="bbt_o2">
            O₂ BBT <span class="op-form__unit">ppb</span>
          </label>
          <input id="bbt_o2" name="bbt_o2" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 18">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="bbt_pressure">
            Pression BBT <span class="op-form__unit">bar</span>
          </label>
          <input id="bbt_pressure" name="bbt_pressure" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 1.2">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="avg_turbidity">
            Turbidité moy. <span class="op-form__unit">NTU</span>
          </label>
          <input id="avg_turbidity" name="avg_turbidity" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 0.5">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="avg_speed">
            Vitesse moy. <span class="op-form__unit">HL/h</span>
          </label>
          <input id="avg_speed" name="avg_speed" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="—">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="centri_rinsed">Centri rincée ?</label>
          <select id="centri_rinsed" name="centri_rinsed" class="op-form__select">
            <option value="">—</option>
            <?php foreach (CENTRI_RINSED_YN as $yn): ?>
              <option value="<?= htmlspecialchars($yn) ?>"><?= htmlspecialchars($yn) ?></option>
            <?php endforeach ?>
          </select>
        </div>

      </div>
    </div>

    <!-- ── Section: CIP équipement ───────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— CIP équipement (centri / KZE)</div>
      <div class="op-form__grid--3 op-form__grid">

        <div class="op-form__field">
          <label class="op-form__label" for="last_cip_date">Date dernier CIP</label>
          <input id="last_cip_date" name="last_cip_date" type="text" class="op-form__input"
                 placeholder="ex. 2026-05-20">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="cip_type">Type CIP</label>
          <input id="cip_type" name="cip_type" type="text" class="op-form__input"
                 placeholder="ex. Soude, Acide…">
        </div>

      </div>
    </div>

    <!-- ── Section: CIP BBT destination ──────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— CIP BBT destination</div>
      <div class="op-form__grid--3 op-form__grid">

        <div class="op-form__field">
          <label class="op-form__label" for="cip_bbt_done">CIP BBT effectué ?</label>
          <select id="cip_bbt_done" name="cip_bbt_done" class="op-form__select">
            <option value="">—</option>
            <?php foreach (CIP_YN as $yn): ?>
              <option value="<?= htmlspecialchars($yn) ?>"><?= htmlspecialchars($yn) ?></option>
            <?php endforeach ?>
          </select>
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="cip_bbt_type">Type CIP BBT</label>
          <input id="cip_bbt_type" name="cip_bbt_type" type="text" class="op-form__input"
                 placeholder="ex. Soude, Vapeur…">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="cip_bbt_date">Date CIP BBT</label>
          <input id="cip_bbt_date" name="cip_bbt_date" type="text" class="op-form__input"
                 placeholder="ex. 2026-05-23">
        </div>

      </div>
    </div>

    <!-- ── Section: Commentaires ──────────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— commentaires</div>
      <div class="op-form__grid--1 op-form__grid">
        <div class="op-form__field op-form__field--full">
          <label class="op-form__label" for="comments">Commentaires libres</label>
          <textarea id="comments" name="comments" class="op-form__textarea" rows="3"
                    placeholder="Observations, problèmes rencontrés…"></textarea>
        </div>
      </div>
    </div>

    <!-- Submit bar -->
    <div class="op-form__submit-bar">
      <button type="button" class="op-form__btn op-form__btn--secondary"
              onclick="if(confirm('Effacer le brouillon ?')){localStorage.removeItem('racking-draft');location.reload();}">
        Effacer brouillon
      </button>
      <button type="submit" id="rf-submit" class="op-form__btn op-form__btn--primary">
        Enregistrer le transfert →
      </button>
    </div>

  </form>

  <!-- ── Recent submissions ─────────────────────────────────────────── -->
  <div class="op-form__recent">
    <div class="op-form__recent-title">— saisies récentes (web)</div>
    <?php if (empty($recentRackings)): ?>
      <p class="op-form__muted" style="font-size:0.82rem;">Aucune saisie web pour le moment.</p>
    <?php else: ?>
      <table class="op-form__recent-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Bière</th>
            <th>Brassin</th>
            <th>Type</th>
            <th>Destination</th>
            <th>Vol (HL)</th>
            <th>QC</th>
            <th>Opérateur</th>
            <th>Override</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentRackings as $r): ?>
            <?php
              $beerLabel  = ($r['neb_beer'] ?? '') !== '' ? $r['neb_beer'] : ($r['contract_beer'] ?? '—');
              $batchLabel = ($r['neb_batch'] ?? '') !== '' ? $r['neb_batch'] : ($r['contract_batch'] ?? '—');
              $flags      = $r['audit_flags'] ?? '';
              $isHorsProc = str_contains($flags, 'hors_process_override');
              $isOutlier  = str_contains($flags, 'qc_outlier');
              $isElevated = str_contains($flags, 'qc_elevated');
              $qc         = $isOutlier ? 'outlier' : ($isElevated ? 'elevated' : 'normal');
              $hpFlag     = (bool)($r['hors_process_flag'] ?? false);
            ?>
            <tr>
              <td class="op-form__mono"><?= htmlspecialchars($r['event_date'] ?? '') ?></td>
              <td><?= htmlspecialchars($beerLabel) ?></td>
              <td class="op-form__mono"><?= htmlspecialchars($batchLabel) ?></td>
              <td><?= htmlspecialchars($r['rack_type'] ?? '—') ?></td>
              <td class="op-form__mono"><?= htmlspecialchars($r['target_tank_raw'] ?? '—') ?></td>
              <td class="op-form__mono"><?= $r['racked_vol_hl'] !== null ? htmlspecialchars((string)$r['racked_vol_hl']) : '—' ?></td>
              <td><span class="op-form__qc-badge op-form__qc-badge--<?= $qc ?>"><?= $qc ?></span></td>
              <td class="op-form__mono"><?= htmlspecialchars($r['email'] ?? '') ?></td>
              <td>
                <?php if ($hpFlag || $isHorsProc): ?>
                  <span class="rf-hp-badge" title="<?= htmlspecialchars($r['hors_process_reason'] ?? '') ?>">HORS PROCESS</span>
                <?php else: ?>
                  <span class="rf-hp-badge rf-hp-badge--normal">—</span>
                <?php endif ?>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </div>

</main>

<!-- ── Inject server-side data for JS ────────────────────────────────────── -->
<script>
window.RF_CANDIDATES          = <?= $candidatesJson ?>;
window.RF_CANDIDATES_OVERRIDE = <?= $candidatesOverrideJson ?>;
window.RF_CAN_OVERRIDE        = <?= $canOverride ? 'true' : 'false' ?>;
</script>

<script src="/js/form-framework.js?v=<?= @filemtime(__DIR__ . '/../js/form-framework.js') ?: time() ?>" defer></script>
<script src="/js/racking-form.js?v=<?= @filemtime(__DIR__ . '/../js/racking-form.js') ?: time() ?>" defer></script>

</body>
</html>
