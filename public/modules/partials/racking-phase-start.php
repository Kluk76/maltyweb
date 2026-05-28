<?php
declare(strict_types=1);
/**
 * racking-phase-start.php — START phase sections for the racking form.
 *
 * Loaded by session-body-racking.php (the phase dispatcher).
 * Inherits the dispatcher's scope: $cipConfig, $canOverride,
 * $candidates, $candidatesOverride.
 *
 * Sections (P-A: render-only, byte-for-byte from form-racking.php):
 *   S1  CIP (lines 945-946)   — shared cip-section.php partial
 *   S2  KZE conditional reveal (lines 948-977) — moved to IN_PROGRESS per spec
 *       NB: S2 lives in IN_PROGRESS per the spec mapping; it is NOT in this file.
 *   S3  Lot source CCT (lines 979-1135)
 */
?>

<!-- ── S1: CIP (FIRST — as per Round-2 #8) ─────────────────────────────── -->
<?php require __DIR__ . '/../../../app/partials/cip-section.php' ?>

<!-- ── S3: Sélection lot source (CCT) ────────────────────────────────────── -->
<div class="op-form__card">
  <div class="op-form__card-title">— sélection lot source (CCT)</div>

  <?php if ($canOverride): ?>
  <!-- Choix Hors Process — MANAGER / ADMIN ONLY. -->
  <div class="rf-override-block" id="rf-override-block">
    <label class="rf-override-label">
      <input type="checkbox" id="rf-override-checkbox" class="rf-override-checkbox"
             aria-describedby="rf-override-desc">
      <span class="rf-override-text">Choix Hors Process</span>
      <span class="rf-override-badge">Manager / Admin</span>
    </label>
    <p class="rf-override-desc" id="rf-override-desc">
      Bypasse la garde minimum (jours depuis cold crash). Affiche tous les lots
      actuellement occupant une CCT ou BBT, quelle que soit leur date de cold crash
      ou leur classification levure. Toute saisie créée via cet override sera marquée
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
          les lots en CCT/BBT indépendamment de la garde.
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
            $recipeId  = (int)($cand['recipe_id'] ?? 0);
            $nebBeerVal = htmlspecialchars($cand['beer'] ?? '');
            $nebBatchVal= htmlspecialchars($cand['batch'] ?? '');
            $simVolHl  = round((float)($cand['sim_vol_hl'] ?? 0), 2);
          ?>
          <button type="button"
                  class="rf-cand-card"
                  data-neb-beer="<?= $nebBeerVal ?>"
                  data-neb-batch="<?= $nebBatchVal ?>"
                  data-recipe-id="<?= $recipeId ?>"
                  data-source-cct="<?= $cctNum ?>"
                  data-sim-vol-hl="<?= $simVolHl ?>"
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

  <!-- Override candidate cards (hors-process) -->
  <?php if ($canOverride): ?>
  <div id="rf-override-candidates" hidden>
    <?php if (empty($candidatesOverride)): ?>
      <div class="rf-empty-state">
        Aucun lot en CCT ou BBT actuellement.
      </div>
    <?php else: ?>
      <div class="rf-cand-grid" id="rf-cand-grid-override">
        <?php foreach ($candidatesOverride as $cand): ?>
          <?php
            $srcType   = $cand['source_tank_type'] ?? 'CCT';
            $beerDisp  = htmlspecialchars($cand['beer_display'] ?? $cand['beer'] ?? '—');
            $batchDisp = htmlspecialchars($cand['batch'] ?? '—');

            if ($srcType === 'BBT') {
                $tankLabel = 'BBT ' . (int)($cand['source_bbt'] ?? 0);
                $cctNum    = 0;
            } else {
                $cctNum    = (int)($cand['source_cct'] ?? 0);
                $tankLabel = 'CCT ' . $cctNum;
            }

            $ccDate    = $cand['cold_crash_date'] !== null
                           ? htmlspecialchars($cand['cold_crash_date'])
                           : 'pas encore';
            $daysCold  = $cand['days_since_cold_crash'] !== null
                           ? (int)$cand['days_since_cold_crash'] . 'j'
                           : '—';
            $effGarde  = $cand['effective_garde'] !== null ? (int)$cand['effective_garde'] : null;
            $recipeId  = (int)($cand['recipe_id'] ?? $cand['neb_recipe_id_fk'] ?? $cand['contract_recipe_id_fk'] ?? 0);
            $nebBeerVal = htmlspecialchars($cand['neb_beer'] ?? $cand['beer'] ?? '');
            $nebBatchVal= htmlspecialchars($cand['neb_batch'] ?? $cand['batch'] ?? '');
            $simVolHlOvr = round((float)($cand['sim_vol_hl'] ?? 0), 2);
          ?>
          <button type="button"
                  class="rf-cand-card rf-cand-card--hors-process"
                  data-neb-beer="<?= $nebBeerVal ?>"
                  data-neb-batch="<?= $nebBatchVal ?>"
                  data-recipe-id="<?= $recipeId ?>"
                  data-source-cct="<?= $cctNum ?>"
                  data-source-bbt="<?= $srcType === 'BBT' ? (int)($cand['source_bbt'] ?? 0) : 0 ?>"
                  data-source-type="<?= htmlspecialchars($srcType) ?>"
                  data-sim-vol-hl="<?= $simVolHlOvr ?>"
                  data-hors-process="1">
            <div class="rf-cand-card__label"><?= htmlspecialchars($tankLabel) ?></div>
            <div class="rf-cand-card__beer"><?= $beerDisp ?></div>
            <div class="rf-cand-card__batch">Brassin <?= $batchDisp ?></div>
            <?php if ($srcType === 'CCT'): ?>
              <div class="rf-cand-card__cc-date">Cold crash : <?= $ccDate ?> (<?= $daysCold ?>)</div>
              <?php if ($effGarde !== null): ?>
                <div class="rf-cand-card__garde">Garde : <?= $effGarde ?>j (non atteinte)</div>
              <?php else: ?>
                <div class="rf-cand-card__garde" style="color:var(--ink-mute)">Garde : non définie</div>
              <?php endif ?>
            <?php else: ?>
              <div class="rf-cand-card__cc-date">En BBT (post-transfert)</div>
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

  <!-- hors_process override reason — populated here for the eligibility attestation payload. -->
  <!-- hors_process hidden input is in the parent form (session-body-racking.php). -->
</div><!-- card lot source -->

<!-- ── START PHASE: Firewall attestation buttons (P-B) ───────────────────── -->
<!--
  These three buttons POST to /api/session-action.php via session-framework.js
  attest_* handlers. They are SEPARATE from the form submit (which still goes to
  /modules/form-racking.php for P-B; split-write is P-C work).

  Disabled state: each button is disabled when already attested (derived from
  window.SESSION_FIREWALL injected server-side). JS reads the flag on DOMContentLoaded
  and applies disabled + done styling without a page reload.

  Payloads (P-B, option b — CIP attestation records operator choices without
  a cip_event_id, which is written on the form's eventual submit):
    attest_cip         → {cip_types: ['centri','kze'], dest_cip: true}  (read from CIP section)
    attest_eligibility → {lots: [...], override: null|'hors_process', override_reason: ''}
    attest_firewall    → {predicate: 'racking_eligibility_v1', readings: {}, passed: bool,
                          failed_fields: [], operator_override_reason: null}
-->
<div class="ss-fw-attest-row" id="ss-fw-attest-row"
     aria-label="Attestations pare-feu — Démarrage soutirage">

  <!-- Gate 1: CIP -->
  <button type="button"
          class="ss-btn ss-btn--attest"
          id="ss-attest-cip"
          data-action="attest_cip"
          data-gate="cip_done"
          <?= ($firewall['cip_done'] ?? false) ? 'disabled aria-disabled="true"' : '' ?>>
    <?php if ($firewall['cip_done'] ?? false): ?>
      <svg class="ss-btn__check" width="11" height="9" viewBox="0 0 11 9" fill="none" aria-hidden="true"><path d="M1 4.5L4 7.5L10 1.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
      CIP validé
    <?php else: ?>
      Valider CIP
    <?php endif ?>
  </button>

  <!-- Gate 2: Lot eligibility -->
  <button type="button"
          class="ss-btn ss-btn--attest"
          id="ss-attest-eligibility"
          data-action="attest_eligibility"
          data-gate="eligibility_done"
          <?= ($firewall['eligibility_done'] ?? false) ? 'disabled aria-disabled="true"' : '' ?>>
    <?php if ($firewall['eligibility_done'] ?? false): ?>
      <svg class="ss-btn__check" width="11" height="9" viewBox="0 0 11 9" fill="none" aria-hidden="true"><path d="M1 4.5L4 7.5L10 1.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Lots validés
    <?php else: ?>
      Valider lots
    <?php endif ?>
  </button>

  <!-- Gate 3: QC readings -->
  <?php
  // hors_process override reason input for firewall QC attestation.
  // Only shown when the lot requires an override comment (eligibility not yet attested
  // or override checkbox is checked). The JS shows/hides this via rf-override logic.
  ?>
  <div class="ss-fw-attest-row__qc-wrap">
    <div class="ss-fw-attest-row__override-reason" id="ss-fw-override-reason" hidden>
      <label class="ss-fw-attest-row__override-label" for="ss-fw-qc-override-note">
        Motif override QC <span class="ss-fw-attest-row__required">*</span>
      </label>
      <input type="text" id="ss-fw-qc-override-note"
             class="ss-fw-attest-row__override-input"
             placeholder="ex. lot urgent — accord chef de cave"
             maxlength="255">
    </div>
    <button type="button"
            class="ss-btn ss-btn--attest"
            id="ss-attest-firewall"
            data-action="attest_firewall"
            data-gate="qc_done"
            <?= ($firewall['qc_done'] ?? false) ? 'disabled aria-disabled="true"' : '' ?>>
      <?php if ($firewall['qc_done'] ?? false): ?>
        <svg class="ss-btn__check" width="11" height="9" viewBox="0 0 11 9" fill="none" aria-hidden="true"><path d="M1 4.5L4 7.5L10 1.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        QC validé
      <?php else: ?>
        Valider QC
      <?php endif ?>
    </button>
  </div>

  <!-- CIP cadence badge — shown by JS when any BBT has warn/critical signal -->
  <div class="ss-cadence-badge ss-cadence-badge--hidden" id="ss-cadence-badge" role="status" aria-live="polite">
    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
      <circle cx="6" cy="6" r="5" stroke="currentColor" stroke-width="1.3"/>
      <path d="M6 4v2.5M6 8v.4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
    </svg>
    <span id="ss-cadence-badge-text"></span>
  </div>

</div><!-- /.ss-fw-attest-row -->
