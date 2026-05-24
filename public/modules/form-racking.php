<?php
declare(strict_types=1);
/**
 * form-racking.php — Operator racking entry form (bd_racking_v2).
 *
 * This is the REFERENCE FORM for the Phase-6 framework. Fan-out forms for
 * brewing/fermenting/packaging clone this pattern:
 *   1. require app/db-write-helpers.php
 *   2. POST handler: CSRF → coerce inputs → bd_qc_flag → bd_lookup_pk_by_nk
 *      → bd_fetch_before → bd_upsert → log_revision → flash_set → redirect
 *   3. GET: load ref data → render form with op-form__* CSS classes
 *   4. JS: FormFramework.init({ formId, draftKey, thresholds, diffFields })
 *
 * Writes to: bd_racking_v2 + audit_row_revisions
 * Natural key: (submitted_at, neb_beer, neb_batch, contract_beer, contract_batch, seq)
 * NOTE: seq is always 0 from the web form (same-second double racking is a
 *       form-submission race condition; the ingest python handles it for backfill).
 *
 * NOT added to topbar nav yet — orchestrator will add when approved.
 * URL: /modules/form-racking.php
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/settings-helpers.php';
require __DIR__ . '/../../app/db-write-helpers.php';

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
        $nebBeer     = post_str('neb_beer')     ?? '';
        $nebBatch    = post_str('neb_batch')    ?? '';
        $contractBeer  = post_str('contract_beer')  ?? '';
        $contractBatch = post_str('contract_batch') ?? '';

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
        ];

        $nkCols = ['submitted_at', 'neb_beer', 'neb_batch', 'contract_beer', 'contract_batch', 'seq'];

        // ── 6. Before-snapshot (lookup by NK) ────────────────────────────
        // For web-form inserts, submitted_at is always new — before is always null.
        // Future edit endpoint would pass the existing row's PK.
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
        flash_set('ok', "Soutirage enregistré : {$beerLabel}{$qcLabel}");

    } catch (Throwable $e) {
        flash_set('err', pdo_friendly_error($e, 'form-racking'));
    }

    redirect_to('/modules/form-racking.php');
}

// ── GET ───────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = maltytask_pdo();

    // Load ref data
    $recipes = $pdo->query(
        "SELECT id, name FROM ref_recipes WHERE is_active = 1 ORDER BY name ASC"
    )->fetchAll();

    $bbts = $pdo->query("SELECT number FROM ref_bbt ORDER BY number ASC")->fetchAll();
    $ccts = $pdo->query("SELECT number FROM ref_cct ORDER BY number ASC")->fetchAll();

    // Recent submissions (operator's last 10 racking entries)
    $recentRows = $pdo->prepare(
        "SELECT id, event_date, neb_beer, neb_batch, contract_beer, contract_batch,
                rack_type, target_tank_raw, racked_vol_hl, qc_flag_col, audit_flags,
                email, submitted_at
         FROM (
           SELECT r.*,
                  CASE
                    WHEN FIND_IN_SET('qc_outlier', REPLACE(audit_flags,',','|')) > 0 THEN 'outlier'
                    WHEN FIND_IN_SET('qc_elevated', REPLACE(audit_flags,',','|')) > 0 THEN 'elevated'
                    ELSE 'normal'
                  END AS qc_flag_col
           FROM bd_racking_v2 r
           WHERE audit_flags LIKE '%web_entry%'
         ) sub
         ORDER BY submitted_at DESC LIMIT 10"
    );
    $recentRows->execute();
    $recentRackings = $recentRows->fetchAll();

    $loadErr = null;
} catch (Throwable $e) {
    $recipes = [];
    $bbts    = [];
    $ccts    = [];
    $recentRackings = [];
    $loadErr = $e->getMessage();
}

$csrf = csrf_token();
$active_module = 'racking';
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Saisie Soutirage — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
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
    <div class="op-form__eyebrow">Soutirage · Racking</div>
    <h1 class="op-form__title">Saisie <em>soutirage</em></h1>
    <p class="op-form__sub">
      Transfert CCT → BBT. Toutes les valeurs sont acceptées — des avertissements sont affichés
      si une mesure est hors plage typique, jamais bloquants.
    </p>
  </div>

  <!-- ── FORM ─────────────────────────────────────────────────────────── -->
  <form id="racking-form" method="post" action="/modules/form-racking.php" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

    <!-- Warning panel (populated by form-framework.js) -->
    <div id="racking-warnings" class="op-form__warnings" hidden aria-live="polite"></div>

    <!-- ── Section: Identité bière ───────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— identité bière</div>
      <div class="op-form__grid">

        <!-- Nébuleuse beer -->
        <div class="op-form__field">
          <label class="op-form__label" for="neb_beer">Recette Nébuleuse</label>
          <select id="neb_beer" name="neb_beer" class="op-form__select">
            <option value="">— aucune (contrat) —</option>
            <?php foreach ($recipes as $r): ?>
              <option value="<?= htmlspecialchars($r['name']) ?>"
                      data-recipe-id="<?= (int)$r['id'] ?>">
                <?= htmlspecialchars($r['name']) ?>
              </option>
            <?php endforeach ?>
          </select>
        </div>

        <!-- Hidden recipe ID for Nébuleuse -->
        <input type="hidden" id="neb_recipe_id_fk" name="neb_recipe_id_fk" value="">

        <!-- Nébuleuse batch -->
        <div class="op-form__field">
          <label class="op-form__label" for="neb_batch">N° brassin Nébuleuse</label>
          <input id="neb_batch" name="neb_batch" type="text" class="op-form__input"
                 placeholder="ex. B2503" autocomplete="off">
        </div>

        <!-- Contract beer -->
        <div class="op-form__field">
          <label class="op-form__label" for="contract_beer">Recette contrat</label>
          <select id="contract_beer" name="contract_beer" class="op-form__select">
            <option value="">— aucune (Nébuleuse) —</option>
            <?php foreach ($recipes as $r): ?>
              <option value="<?= htmlspecialchars($r['name']) ?>"
                      data-recipe-id="<?= (int)$r['id'] ?>">
                <?= htmlspecialchars($r['name']) ?>
              </option>
            <?php endforeach ?>
          </select>
        </div>

        <!-- Hidden recipe ID for contract -->
        <input type="hidden" id="contract_recipe_id_fk" name="contract_recipe_id_fk" value="">

        <!-- Contract batch -->
        <div class="op-form__field">
          <label class="op-form__label" for="contract_batch">N° brassin contrat</label>
          <input id="contract_batch" name="contract_batch" type="text" class="op-form__input"
                 placeholder="ex. B-002" autocomplete="off">
        </div>

        <!-- Client -->
        <div class="op-form__field">
          <label class="op-form__label" for="client">Client</label>
          <input id="client" name="client" type="text" class="op-form__input"
                 placeholder="Nébuleuse / nom client" autocomplete="off">
        </div>

      </div><!-- grid -->
    </div><!-- card -->

    <!-- ── Section: Opération ─────────────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— opération de soutirage</div>
      <div class="op-form__grid">

        <!-- Date -->
        <div class="op-form__field">
          <label class="op-form__label" for="event_date">Date soutirage</label>
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

        <!-- CCT number -->
        <div class="op-form__field" id="cct-field">
          <label class="op-form__label" for="cct_number">CCT n°</label>
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
            Volume soutiré <span class="op-form__unit">HL</span>
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
      <button type="submit" class="op-form__btn op-form__btn--primary">
        Enregistrer le soutirage →
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
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentRackings as $r): ?>
            <?php
              $beerLabel = $r['neb_beer'] !== '' ? $r['neb_beer'] : $r['contract_beer'];
              $batchLabel = $r['neb_batch'] !== '' ? $r['neb_batch'] : $r['contract_batch'];
              $qc = $r['qc_flag_col'] ?? 'normal';
            ?>
            <tr>
              <td class="op-form__mono"><?= htmlspecialchars($r['event_date'] ?? '') ?></td>
              <td><?= htmlspecialchars($beerLabel) ?></td>
              <td class="op-form__mono"><?= htmlspecialchars($batchLabel) ?></td>
              <td><?= htmlspecialchars($r['rack_type'] ?? '—') ?></td>
              <td class="op-form__mono"><?= htmlspecialchars($r['target_tank_raw'] ?? '—') ?></td>
              <td class="op-form__mono"><?= $r['racked_vol_hl'] !== null ? htmlspecialchars($r['racked_vol_hl']) : '—' ?></td>
              <td><span class="op-form__qc-badge op-form__qc-badge--<?= $qc ?>"><?= $qc ?></span></td>
              <td class="op-form__mono"><?= htmlspecialchars($r['email'] ?? '') ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </div>

</main>

<script src="/js/form-framework.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

  // ── Wire recipe ID hidden fields when select changes ──────────────────
  document.getElementById('neb_beer').addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    document.getElementById('neb_recipe_id_fk').value = opt.dataset.recipeId ?? '';
  });
  document.getElementById('contract_beer').addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    document.getElementById('contract_recipe_id_fk').value = opt.dataset.recipeId ?? '';
  });

  // ── Show/hide BBT/CCT selects based on destination type ──────────────
  const destSel = document.getElementById('racking_destination_type');
  const bbtFld  = document.getElementById('bbt-field');
  const cctFld  = document.getElementById('cct-field');

  function updateDestFields() {
    const v = destSel.value;
    bbtFld.style.display = (v === 'BBT' || v === '') ? '' : 'none';
    cctFld.style.display = (v === 'CCT') ? '' : 'none';
    if (v !== 'BBT') document.getElementById('bbt_number').value = '';
    if (v !== 'CCT') document.getElementById('cct_number').value = '';
  }
  destSel.addEventListener('change', updateDestFields);
  updateDestFields();

  // ── FormFramework init ────────────────────────────────────────────────
  FormFramework.init({
    formId:        'racking-form',
    draftKey:      'racking-draft',
    warningPanelId: 'racking-warnings',
    thresholds: {
      bbt_co2: {
        label: 'CO₂ BBT', unit: ' g/L',
        warn:    [3.5, 5.0],
        outlier: [2.5, 6.0],
      },
      bbt_o2: {
        label: 'O₂ BBT', unit: ' ppb',
        warn:    [0, 50],
        outlier: [0, 200],
      },
      racked_vol_hl: {
        label: 'Volume soutiré', unit: ' HL',
        warn:    [10, 100],
        outlier: [1, 150],
      },
      bbt_pressure: {
        label: 'Pression BBT', unit: ' bar',
        warn:    [0.8, 2.5],
        outlier: [0.0, 3.5],
      },
    },
    diffFields: [
      'neb_beer','neb_batch','contract_beer','contract_batch',
      'rack_type','event_date','racking_destination_type',
      'bbt_number','cct_number',
      'racked_vol_hl','bbt_co2','bbt_o2','bbt_pressure','blend_hl',
    ],
    diffLabels: {
      neb_beer:                  'Recette Nébuleuse',
      neb_batch:                 'Brassin Nébuleuse',
      contract_beer:             'Recette contrat',
      contract_batch:            'Brassin contrat',
      rack_type:                 'Type de rack',
      event_date:                'Date',
      racking_destination_type:  'Type destination',
      bbt_number:                'BBT n°',
      cct_number:                'CCT n°',
      racked_vol_hl:             'Volume (HL)',
      bbt_co2:                   'CO₂ (g/L)',
      bbt_o2:                    'O₂ (ppb)',
      bbt_pressure:              'Pression (bar)',
      blend_hl:                  'Blend (HL)',
    },
  });
});
</script>

</body>
</html>
