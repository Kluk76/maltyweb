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
</div><!-- card lot source -->
