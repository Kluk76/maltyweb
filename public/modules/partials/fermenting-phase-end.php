<?php
declare(strict_types=1);
/**
 * fermenting-phase-end.php — END phase for the fermenting form.
 *
 * Loaded by session-body-fermenting.php when phase='end':
 * a ColdCrash event has been recorded for this (beer, batch) pair.
 *
 * Fermenting closes AUTOMATICALLY when a racking session opens for this
 * (recipe_id_fk, batch) — there is NO explicit close button in this form.
 * This partial renders a "ready-for-racking" green-light state showing the
 * operator that ColdCrash is confirmed and the lot is awaiting racking.
 *
 * The close-button logic and garde-eligibility check are P-C work
 * (reusing app/yeast-eligibility.php already wired in racking).
 *
 * Inherits scope: $pdo, $me, $csrf, $ff_beer, $ff_batch, $ff_recipeId
 *                 (from session-body-fermenting.php)
 *
 * P-A: pure UI stub — no SQL writes, no new API endpoints.
 * P-C: will add garde-days countdown + racking eligibility signal.
 *
 * TODO (P-C):
 *   - Load loss_metrics / garde progress via yeast-eligibility.php
 *   - Show days-since-cold-crash vs min_ferment_days countdown
 *   - Signal eligibility for racking (green badge when garde met)
 *   - Close-session endpoint at /api/fermenting-phase-submit.php (phase=end)
 */
?>

<!-- ── END PHASE: Ready-for-racking view ─────────────────────────────────── -->
<div class="op-form__card ferm-end-card" id="ferm-end-ready">
  <div class="op-form__card-title">— cold crash enregistré</div>

  <div class="ferm-end-state">
    <div class="ferm-end-state__icon" aria-hidden="true">
      <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
        <circle cx="16" cy="16" r="14" stroke="var(--hop)" stroke-width="1.5"/>
        <path d="M10 16.5L14 20.5L22 12.5" stroke="var(--hop)" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
    <div class="ferm-end-state__body">
      <p class="ferm-end-state__title">Cold Crash enregistré</p>
      <?php if ($ff_beer !== '' && $ff_batch !== ''): ?>
        <p class="ferm-end-state__detail">
          <?= htmlspecialchars($ff_beer) ?> — Brassin <?= htmlspecialchars($ff_batch) ?>
        </p>
      <?php endif ?>
      <p class="ferm-end-state__note">
        Ce lot est en attente de soutirage. La session de fermentation se clôturera
        automatiquement à l'ouverture de la session de transfert correspondante.
      </p>
    </div>
  </div>

  <!-- TODO (P-C): garde-days countdown + eligibility badge will go here. -->
  <!-- TODO (P-C): if garde not yet met → amber warning; if met → green "Éligible au soutirage". -->

  <div class="ferm-end-note ferm-unwired-notice">
    <strong>P-A — stub :</strong>
    La progression garde minimum (jours depuis cold crash vs
    <code>ref_yeast_family_defaults.min_ferment_days</code>) sera affichée ici en P-C.
    La logique réutilise <code>app/yeast-eligibility.php</code> depuis le pilot racking.
  </div>
</div>

<!-- Operator can still add a Reads event post-ColdCrash if needed (temp monitoring). -->
<!-- P-B will enforce the cadence rules and show/hide this form accordingly. -->
<form id="fermenting-form" method="post" action="/modules/form-fermenting.php" novalidate>
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" id="recipe_id_fk" name="recipe_id_fk"
         value="<?= $ff_recipeId !== null ? (int)$ff_recipeId : '' ?>">

  <div id="fermenting-warnings" class="op-form__warnings" hidden aria-live="polite"></div>

  <!-- ── Section: Identité (pre-filled, collapsed visual — beer/batch locked) ── -->
  <div class="op-form__card">
    <div class="op-form__card-title">— identité du brassin</div>
    <div class="op-form__grid">

      <div class="op-form__field">
        <label class="op-form__label" for="beer_select">Bière (recette)</label>
        <select id="beer_select" name="beer_select" class="op-form__select">
          <option value="">— sélectionner —</option>
          <?php foreach ($recipes as $r): ?>
            <option value="<?= htmlspecialchars($r['recipe_short_name'] ?: $r['name']) ?>"
                    data-recipe-id="<?= (int)$r['id'] ?>"
                    data-recipe-name="<?= htmlspecialchars($r['name']) ?>"
                    <?= ($ff_beer !== '' && ($r['recipe_short_name'] === $ff_beer || $r['name'] === $ff_beer)) ? 'selected' : '' ?>>
              <?= htmlspecialchars($r['name']) ?>
              <?php if ($r['recipe_short_name']): ?>
                (<?= htmlspecialchars($r['recipe_short_name']) ?>)
              <?php endif ?>
            </option>
          <?php endforeach ?>
        </select>
      </div>

      <div class="op-form__field">
        <label class="op-form__label" for="batch">N° brassin</label>
        <input id="batch" name="batch" type="text" class="op-form__input"
               placeholder="ex. 213" autocomplete="off" required
               value="<?= htmlspecialchars($ff_batch) ?>">
      </div>

      <div class="op-form__field">
        <label class="op-form__label" for="event_date">
          Date
          <span class="op-form__unit"><?= htmlspecialchars($displayFmt) ?></span>
        </label>
        <input id="event_date" name="event_date" type="date" class="op-form__input"
               value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
      </div>

      <div class="op-form__field">
        <label class="op-form__label" for="event_type">Type d'évènement</label>
        <select id="event_type" name="event_type" class="op-form__select">
          <option value="Reads">Mesures densité / pH / temp</option>
          <option value="DryHop">Houblonnage à froid</option>
          <option value="Purge">Purge CO₂</option>
          <option value="ColdCrash">Cold Crash</option>
        </select>
      </div>

    </div>
  </div>

  <!-- Readings card (available post-ColdCrash for temp monitoring) -->
  <div class="op-form__card" id="section-readings">
    <div class="op-form__card-title">— mesures (°Plato · pH · °C)</div>
    <div class="ferm-readings-note">
      La densité est saisie en <strong>°Plato</strong> (pas en SG).
    </div>
    <div class="ferm-readings-grid">
      <div class="ferm-reading-card">
        <div class="ferm-reading-card__head">
          <span class="ferm-reading-card__label">Densité</span>
          <span class="ferm-reading-card__unit">°Plato</span>
        </div>
        <input type="number" id="gravity" name="gravity"
               class="ferm-reading-input"
               placeholder="—" step="0.1" min="0" max="30" autocomplete="off">
        <div class="ferm-reading-hint" id="gravity-hint">FG typique 0.5–5°P</div>
      </div>
      <div class="ferm-reading-card">
        <div class="ferm-reading-card__head">
          <span class="ferm-reading-card__label">pH</span>
          <span class="ferm-reading-card__unit">pH</span>
        </div>
        <input type="number" id="ph" name="ph"
               class="ferm-reading-input"
               placeholder="—" step="0.01" min="2" max="8" autocomplete="off">
        <div class="ferm-reading-hint" id="ph-hint">Pale Ale typique 4.1–4.6</div>
      </div>
      <div class="ferm-reading-card">
        <div class="ferm-reading-card__head">
          <span class="ferm-reading-card__label">Température</span>
          <span class="ferm-reading-card__unit">°C</span>
        </div>
        <input type="number" id="temperature" name="temperature"
               class="ferm-reading-input"
               placeholder="—" step="0.1" min="-5" max="40" autocomplete="off">
        <div class="ferm-reading-hint" id="temp-hint">Cold crash 0–4°C</div>
      </div>
    </div>
  </div>

  <!-- DryHop (JS-toggled) -->
  <div class="op-form__card" id="section-dryhop" hidden>
    <div class="op-form__card-title">
      — houblonnage à froid
      <span class="ferm-dh-count" id="dh-count-badge"></span>
    </div>
    <table class="ferm-dh-table">
      <thead>
        <tr>
          <th class="ferm-dh-col--hop">Houblon (MI)</th>
          <th class="ferm-dh-col--qty">Quantité</th>
          <th class="ferm-dh-col--unit">Unité</th>
          <th class="ferm-dh-col--lot">N° lot</th>
          <th class="ferm-dh-col--del"></th>
        </tr>
      </thead>
      <tbody id="dh-tbody"></tbody>
    </table>
    <button type="button" class="ferm-dh-add-btn" onclick="window._fermAddDhRow()">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
        <line x1="7" y1="1" x2="7" y2="13" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
        <line x1="1" y1="7" x2="13" y2="7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
      Ajouter une addition
    </button>
    <p class="ferm-dh-note">
      Saisir en grammes (g) ou kilogrammes (kg). Les additions sont enregistrées
      dans <code>bd_fermenting_v2</code> avec <code>dh_category = 'hops_dry'</code>.
    </p>
  </div>

  <!-- Purge (JS-toggled) -->
  <div class="op-form__card" id="section-purge" hidden>
    <div class="op-form__card-title">— purge CO₂</div>
    <div class="op-form__grid--1 op-form__grid">
      <div class="op-form__field op-form__field--full">
        <label class="op-form__label" for="comment_purge">
          Commentaire purge <span class="op-form__unit">(optionnel)</span>
        </label>
        <textarea id="comment_purge" name="comment_purge"
                  class="op-form__textarea" rows="3"
                  placeholder="Fuites constatées, pression anormale, anomalies…"></textarea>
      </div>
    </div>
  </div>

  <!-- ColdCrash (JS-toggled — re-recordable for corrections) -->
  <div class="op-form__card" id="section-coldcrash" hidden>
    <div class="op-form__card-title">— cold crash / refroidissement</div>
    <div class="op-form__grid--1 op-form__grid">
      <div class="op-form__field op-form__field--full">
        <label class="op-form__label" for="comment_cold_crash">
          Commentaire cold crash <span class="op-form__unit">(optionnel)</span>
        </label>
        <textarea id="comment_cold_crash" name="comment_cold_crash"
                  class="op-form__textarea" rows="3"
                  placeholder="Temp. cible, durée prévue, observations…"></textarea>
      </div>
    </div>
  </div>

  <!-- Comments -->
  <div class="op-form__card">
    <div class="op-form__card-title">— commentaires</div>
    <div class="op-form__grid--1 op-form__grid">
      <div class="op-form__field op-form__field--full">
        <label class="op-form__label" for="final_comments">Observations générales</label>
        <textarea id="final_comments" name="final_comments"
                  class="op-form__textarea" rows="3"
                  placeholder="Notes, odeurs, aspect visuel, écarts, problèmes…"></textarea>
      </div>
    </div>
  </div>

  <!-- Submit bar -->
  <div class="op-form__submit-bar">
    <button type="button" class="op-form__btn op-form__btn--secondary"
            onclick="if(confirm('Effacer le brouillon ?')){localStorage.removeItem('fermenting-draft');location.reload();}">
      Effacer brouillon
    </button>
    <button type="submit" class="op-form__btn op-form__btn--primary" id="ferm-submit-btn">
      Enregistrer →
    </button>
  </div>

</form>
