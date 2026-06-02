<?php
declare(strict_types=1);
/**
 * racking-phase-in-progress.php — IN_PROGRESS phase sections for the racking form (P-C).
 *
 * Loaded by session-body-racking.php (the phase dispatcher) when phase='in_progress'.
 * Inherits the dispatcher's scope: $bbts, $ccts, $yts, $bbtBlendCandidatesJson,
 * $candidatesJson, $candidatesOverrideJson, $qcThresholdsJson, $pertesConfigJson,
 * $session, $csrf.
 *
 * P-C changes vs P-A:
 *   - Wrapped in its own <form> (id="racking-in-progress-form").
 *   - safety_cip_done moved to racking-phase-end.php (end-phase field).
 *   - JS data-injection block (window.RF_*) retained here (emitted once, guarded).
 *   - Submit button: "Enregistrer le transfert →"
 *
 * Sections:
 *   S2  Pasteurisation flash KZE (hidden by default; JS reveals)
 *   S4  Opération date/heures
 *   S5  Destination tank + S5b BBT blend candidates
 *   S6  Mesures volumes/CO₂/O₂ (safety_cip_done REMOVED — now in end phase)
 */
?>

<!-- ── IN_PROGRESS phase form (P-C: per-phase split-write) ───────────────── -->
<form id="racking-in-progress-form" novalidate>
  <input type="hidden" name="csrf"       value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
  <input type="hidden" name="phase"      value="in_progress">

  <!-- Hidden lot-identity fields populated by JS from the attested candidate card.
       The start-phase attestation already locked the lot; JS re-populates these
       from the stored SESSION_FIREWALL.eligibility state on page load. -->
  <input type="hidden" id="neb_beer"              name="neb_beer"              value="">
  <input type="hidden" id="neb_batch"             name="neb_batch"             value="">
  <input type="hidden" id="neb_recipe_id_fk"      name="neb_recipe_id_fk"      value="">
  <input type="hidden" id="contract_beer"         name="contract_beer"         value="">
  <input type="hidden" id="contract_batch"        name="contract_batch"        value="">
  <input type="hidden" id="contract_recipe_id_fk" name="contract_recipe_id_fk" value="">
  <input type="hidden" id="source_cct_number"     name="source_cct_number"     value="">
  <input type="hidden" id="hors_process"          name="hors_process"          value="0">

  <!-- Warning panel (populated by racking-form.js) -->
  <div id="racking-warnings" class="op-form__warnings" hidden aria-live="polite"></div>

<!-- ── S2: Pasteurisation flash (KZE) ─────────────────────────────────────── -->
<!-- Hidden by default. JS shows when KZE is in the CIP set.
     NO static `required` — JS drives required only while visible. -->
<div class="op-form__card rf-kze-pu-section" id="rf-kze-pu-section" hidden>
  <div class="op-form__card-title">— pasteurisation flash (KZE)</div>
  <div class="op-form__grid">

    <div class="op-form__field">
      <label class="op-form__label" for="kze_target_pu">
        Target PU <span class="op-form__unit">PU</span>
      </label>
      <input id="kze_target_pu" name="kze_target_pu" type="text" inputmode="decimal"
             class="op-form__input" placeholder="ex. 25"
             data-pu-required="1">
    </div>

    <div class="op-form__field">
      <label class="op-form__label" for="kze_avg_pu">
        Moyenne PU <span class="op-form__unit">PU</span>
        <span class="op-form__opt">(réalisé)</span>
      </label>
      <input id="kze_avg_pu" name="kze_avg_pu" type="text" inputmode="decimal"
             class="op-form__input" placeholder="ex. 26.1"
             data-pu-required="1">
    </div>

  </div>
</div>

<!-- ── S4: Opération ──────────────────────────────────────────────────────── -->
<div class="op-form__card">
  <div class="op-form__card-title">— opération de transfert</div>
  <div class="op-form__grid">

    <!-- Date de transfert (no min/max; back-dating works) -->
    <div class="op-form__field">
      <label class="op-form__label" for="event_date">Date transfert</label>
      <input id="event_date" name="event_date" type="date" class="op-form__input"
             value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
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

<!-- ── S5: Destination tank ──────────────────────────────────────────────── -->
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

    <!-- BBT N° -->
    <div class="op-form__field" id="bbt-field" style="display:none">
      <label class="op-form__label" for="bbt_number">BBT N°</label>
      <select id="bbt_number" name="bbt_number" class="op-form__select">
        <option value="">—</option>
        <?php foreach ($bbts as $b): ?>
          <option value="<?= (int)$b['number'] ?>">BBT <?= (int)$b['number'] ?></option>
        <?php endforeach ?>
      </select>
    </div>

    <!-- CCT N° -->
    <div class="op-form__field" id="cct-field" style="display:none">
      <label class="op-form__label" for="cct_number">CCT N°</label>
      <select id="cct_number" name="cct_number" class="op-form__select">
        <option value="">—</option>
        <?php foreach ($ccts as $c): ?>
          <option value="<?= (int)$c['number'] ?>">CCT <?= (int)$c['number'] ?></option>
        <?php endforeach ?>
      </select>
    </div>

    <!-- YT N° -->
    <div class="op-form__field" id="yt-field" style="display:none">
      <label class="op-form__label" for="yt_number">YT N°</label>
      <select id="yt_number" name="yt_number" class="op-form__select">
        <option value="">—</option>
        <?php foreach ($yts as $y): ?>
          <option value="<?= (int)$y['number'] ?>">YT <?= (int)$y['number'] ?></option>
        <?php endforeach ?>
      </select>
    </div>

  </div>

  <!-- S5b: BBT blend-candidate cards (C5 — JS-populated, nothing emitted from PHP).
       Hidden by default; JS shows/hides and injects the cards. -->
  <div id="rf-bbt-blend-section" hidden>
    <div class="rf-bbt-blend-label">
      Blending — même bière en BBT :
    </div>
    <!-- Candidate cards injected by JS -->
    <div class="rf-cand-grid rf-bbt-blend-grid" id="rf-bbt-blend-grid"></div>
    <!-- Message when no same-beer BBT is found -->
    <div id="rf-bbt-blend-none" class="rf-bbt-blend-none" hidden>
      Aucune BBT contenant cette bière actuellement. Pour transférer vers une BBT vide
      ou une BBT avec une autre bière, utiliser <strong>Choix Hors Process</strong>.
    </div>
  </div>

</div>

<!-- ── S6: Mesures ────────────────────────────────────────────────────────── -->
<!-- Note: safety_cip_done REMOVED — moved to racking-phase-end.php (end-phase field). -->
<div class="op-form__card">
  <div class="op-form__card-title">— mesures</div>
  <div class="op-form__grid">

    <div class="op-form__field">
      <label class="op-form__label" for="flowmeter_start_hl">
        Relevé compteur — début <span class="op-form__unit">HL</span>
      </label>
      <input id="flowmeter_start_hl" name="flowmeter_start_hl" type="text" inputmode="decimal"
             class="op-form__input" placeholder="ex. 12345.6">
    </div>

    <div class="op-form__field">
      <label class="op-form__label" for="racked_vol_hl">
        Volume transféré <span class="op-form__unit">HL</span>
        <span id="rf-vol-calculé-hint" class="op-form__opt" hidden>(calculé depuis le compteur)</span>
      </label>
      <input id="racked_vol_hl" name="racked_vol_hl" type="text" inputmode="decimal"
             class="op-form__input" placeholder="ex. 29.5">
      <div id="rf-flowmeter-error" class="op-form__inline-error" hidden></div>
    </div>

    <!-- "Volume résiduel en cuve" (column stays blend_hl) -->
    <div class="op-form__field">
      <label class="op-form__label" for="blend_hl">
        Volume résiduel en cuve <span class="op-form__unit">HL</span>
      </label>
      <input id="blend_hl" name="blend_hl" type="text" inputmode="decimal"
             class="op-form__input" placeholder="0">
    </div>

    <!-- "Volume résultant en cuve" — pure JS display, nothing persisted -->
    <div class="op-form__field" id="rf-resultant-field">
      <label class="op-form__label">
        Volume résultant en cuve <span class="op-form__unit">HL</span>
        <span class="op-form__opt">(calculé)</span>
      </label>
      <div id="rf-resultant-display" class="op-form__readout" aria-live="polite">—</div>
    </div>

    <!-- CO₂ label dynamic by dest type (default "CO₂ BBT", swapped JS-side) -->
    <div class="op-form__field">
      <label class="op-form__label" for="bbt_co2">
        <span id="lbl-co2">CO₂ BBT</span> <span class="op-form__unit">g/L</span>
      </label>
      <input id="bbt_co2" name="bbt_co2" type="text" inputmode="decimal"
             class="op-form__input" placeholder="ex. 4.2">
    </div>

    <!-- O₂ label dynamic by dest type -->
    <div class="op-form__field">
      <label class="op-form__label" for="bbt_o2">
        <span id="lbl-o2">O₂ BBT</span> <span class="op-form__unit">ppb</span>
      </label>
      <input id="bbt_o2" name="bbt_o2" type="text" inputmode="decimal"
             class="op-form__input" placeholder="ex. 18">
    </div>

    <div class="op-form__field">
      <label class="op-form__label" for="bbt_pressure">
        Pression destination <span class="op-form__unit">bar</span>
      </label>
      <input id="bbt_pressure" name="bbt_pressure" type="text" inputmode="decimal"
             class="op-form__input" placeholder="ex. 1.2">
    </div>

    <div class="op-form__field">
      <label class="op-form__label">
        Turbidité <span class="op-form__unit">NTU</span>
      </label>
      <div id="rf-turbidity-msr"></div>
    </div>

    <!-- avg_speed intentionally absent — column kept for historical data (#7) -->

    <div class="op-form__field">
      <label class="op-form__label" for="centri_rinsed">Centri rincée ?</label>
      <select id="centri_rinsed" name="centri_rinsed" class="op-form__select">
        <option value="">—</option>
        <?php foreach (CENTRI_RINSED_YN as $yn): ?>
          <option value="<?= htmlspecialchars($yn) ?>"><?= htmlspecialchars($yn) ?></option>
        <?php endforeach ?>
      </select>
    </div>

    <!-- safety_cip_done intentionally absent — moved to end phase (P-C spec). -->

  </div>
</div>

<!-- Submit bar — P-C: saves in-progress data and advances to end phase. -->
<div class="op-form__submit-bar">
  <button type="submit" id="racking-ip-submit"
          class="op-form__btn op-form__btn--primary">
    Enregistrer le transfert →
  </button>
</div>

</form><!-- /#racking-in-progress-form -->

<!-- JS data surfaces for racking-form.js — injected once (by whichever partial loads first).
     Guard with a flag so the block is not duplicated when both in-progress and end load. -->
<?php if (!defined('_RACKING_JS_DATA_INJECTED')): define('_RACKING_JS_DATA_INJECTED', true); ?>
<script>
// Variable names MUST match racking-form.js runtime reads (RF_* prefix, not RACK_*).
// BBT_CLEAN_STATES is actively read at racking-form.js:389 (dest-CIP gate);
// missing it silently regresses every BBT to "unknown clean state".
window.RF_CANDIDATES          = <?= $candidatesJson ?>;
window.RF_CANDIDATES_OVERRIDE = <?= $candidatesOverrideJson ?>;
window.RF_CAN_OVERRIDE        = <?= $canOverride ? 'true' : 'false' ?>;
window.BBT_BLEND_CANDIDATES   = <?= $bbtBlendCandidatesJson ?>;
window.BBT_CLEAN_STATES       = <?= json_encode($bbtCleanStates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.QC_THRESHOLDS          = <?= $qcThresholdsJson ?? 'null' ?>;
window.PERTES_CONFIG          = <?= $pertesConfigJson ?>;
</script>
<?php endif ?>
