<?php
declare(strict_types=1);
/**
 * fermenting-phase-start.php — START phase sections for the fermenting form.
 *
 * Loaded by session-body-fermenting.php when phase='none' or phase='start'.
 * Mirrors racking-phase-start.php's role: the pre-session firewall view.
 *
 * In P-A: renders the full unified fermenting form verbatim (identity card
 * + event-type picker + all measurement sections). The operator picks beer,
 * batch, event type, fills measurements, and submits — exactly as before
 * extraction. No phase-gating is applied here (P-B work).
 *
 * If $ff_beer / $ff_batch / $ff_recipeId are set (from URL params, passed by
 * session-body-fermenting.php), they pre-fill the hidden recipe_id_fk and
 * may be used by JS to pre-select the beer/batch dropdowns.
 *
 * Inherits scope: $pdo, $me, $csrf, $recipes, $hopsJs, $displayFmt,
 *                 $ff_beer, $ff_batch, $ff_recipeId (from session-body-fermenting.php)
 *
 * Sections (verbatim from form-fermenting.php GET block):
 *   Identity card (beer/batch/date/event_type)
 *   Readings card (gravity/pH/temperature)
 *   DryHop card (JS-toggled table)
 *   Purge card (JS-toggled)
 *   ColdCrash card (JS-toggled)
 *   Comments card
 *   Submit bar
 */
?>

<!-- ── START PHASE: Full fermenting event form ────────────────────────────── -->
<form id="fermenting-form" method="post" action="/modules/form-fermenting.php" novalidate>
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" id="recipe_id_fk" name="recipe_id_fk"
         value="<?= $ff_recipeId !== null ? (int)$ff_recipeId : '' ?>">

  <!-- Warning panel (populated by form-framework.js) -->
  <div id="fermenting-warnings" class="op-form__warnings" hidden aria-live="polite"></div>

  <!-- ── Section: Identité ────────────────────────────────────────────────── -->
  <div class="op-form__card">
    <div class="op-form__card-title">— identité du brassin</div>
    <div class="op-form__grid">

      <!-- Beer / recipe picker (state-gated: is_active = 1) -->
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

      <!-- Batch number -->
      <div class="op-form__field">
        <label class="op-form__label" for="batch">N° brassin</label>
        <input id="batch" name="batch" type="text" class="op-form__input"
               placeholder="ex. 213" autocomplete="off" required
               value="<?= htmlspecialchars($ff_batch) ?>">
      </div>

      <!-- Event date -->
      <div class="op-form__field">
        <label class="op-form__label" for="event_date">
          Date
          <span class="op-form__unit"><?= htmlspecialchars($displayFmt) ?></span>
        </label>
        <input id="event_date" name="event_date" type="date" class="op-form__input"
               value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
      </div>

      <!-- Event type — maps to bd_fermenting_v2.event_type ENUM -->
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

  <!-- ── Section: Mesures densité / pH / température ──────────────────────── -->
  <div class="op-form__card" id="section-readings">
    <div class="op-form__card-title">— mesures (°Plato · pH · °C)</div>
    <div class="ferm-readings-note">
      La densité est saisie en <strong>°Plato</strong> (pas en SG).
      Valeurs typiques : OG 10–20°P, FG 1–5°P.
      Un seul champ densité — le contexte (début/fin de fermentation) est
      déterminé par la date et le type d'évènement.
    </div>

    <div class="ferm-readings-grid">

      <!-- Gravity — °Plato; stored in bd_fermenting_v2.gravity -->
      <div class="ferm-reading-card">
        <div class="ferm-reading-card__head">
          <span class="ferm-reading-card__label">Densité</span>
          <span class="ferm-reading-card__unit">°Plato</span>
        </div>
        <input type="number" id="gravity" name="gravity"
               class="ferm-reading-input"
               placeholder="—" step="0.1" min="0" max="30"
               autocomplete="off">
        <div class="ferm-reading-hint" id="gravity-hint">
          OG typique 10–20°P · FG 0.5–5°P
        </div>
      </div>

      <!-- pH — stored in bd_fermenting_v2.ph -->
      <div class="ferm-reading-card">
        <div class="ferm-reading-card__head">
          <span class="ferm-reading-card__label">pH</span>
          <span class="ferm-reading-card__unit">pH</span>
        </div>
        <input type="number" id="ph" name="ph"
               class="ferm-reading-input"
               placeholder="—" step="0.01" min="2" max="8"
               autocomplete="off">
        <div class="ferm-reading-hint" id="ph-hint">
          Pale Ale typique 4.1–4.6
        </div>
      </div>

      <!-- Temperature — stored in bd_fermenting_v2.temperature -->
      <div class="ferm-reading-card">
        <div class="ferm-reading-card__head">
          <span class="ferm-reading-card__label">Température</span>
          <span class="ferm-reading-card__unit">°C</span>
        </div>
        <input type="number" id="temperature" name="temperature"
               class="ferm-reading-input"
               placeholder="—" step="0.1" min="-5" max="40"
               autocomplete="off">
        <div class="ferm-reading-hint" id="temp-hint">
          Fermentation 16–22°C · Cold crash 0–4°C
        </div>
      </div>

    </div>
  </div>

  <!-- ── Section: Dry-hop (shown when event_type = DryHop) ───────────────── -->
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
      <tbody id="dh-tbody">
        <!-- Rows added by form-fermenting.js -->
      </tbody>
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

  <!-- ── Section: Purge (shown when event_type = Purge) ──────────────────── -->
  <div class="op-form__card" id="section-purge" hidden>
    <div class="op-form__card-title">— purge CO₂</div>
    <div class="op-form__grid--1 op-form__grid">
      <div class="op-form__field op-form__field--full">
        <label class="op-form__label" for="comment_purge">
          Commentaire purge
          <span class="op-form__unit">(optionnel)</span>
        </label>
        <textarea id="comment_purge" name="comment_purge"
                  class="op-form__textarea" rows="3"
                  placeholder="Fuites constatées, pression anormale, anomalies…"></textarea>
      </div>
    </div>
    <div class="ferm-unwired-notice">
      <strong>Non câblé — colonnes manquantes :</strong>
      CO₂ pression (bar) et CO₂ dissous (g/L) sont visibles dans le mock
      de design mais absents de <code>bd_fermenting_v2</code>.
      Une migration est nécessaire pour les ajouter.
      Seul le commentaire est stocké.
    </div>
  </div>

  <!-- ── Section: Cold Crash (shown when event_type = ColdCrash) ──────────── -->
  <div class="op-form__card" id="section-coldcrash" hidden>
    <div class="op-form__card-title">— cold crash / refroidissement</div>
    <div class="op-form__grid--1 op-form__grid">
      <div class="op-form__field op-form__field--full">
        <label class="op-form__label" for="comment_cold_crash">
          Commentaire cold crash
          <span class="op-form__unit">(optionnel)</span>
        </label>
        <textarea id="comment_cold_crash" name="comment_cold_crash"
                  class="op-form__textarea" rows="3"
                  placeholder="Temp. cible, durée prévue, observations…"></textarea>
      </div>
    </div>
    <div class="ferm-unwired-notice">
      <strong>Non câblé — colonnes manquantes :</strong>
      Température cible, température actuelle, date crash et durée prévue
      sont dans le mock de design mais absents de <code>bd_fermenting_v2</code>.
      La <strong>température</strong> (°C) est capturée via la section Mesures ci-dessus.
      Les autres champs nécessitent une migration.
    </div>
  </div>

  <!-- ── Section: Commentaires ─────────────────────────────────────────────── -->
  <div class="op-form__card">
    <div class="op-form__card-title">— commentaires</div>
    <div class="op-form__grid--1 op-form__grid">
      <div class="op-form__field op-form__field--full">
        <label class="op-form__label" for="final_comments">
          Observations générales
        </label>
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
