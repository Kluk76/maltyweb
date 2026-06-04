<?php
declare(strict_types=1);
/**
 * fermenting-phase-in-progress.php — IN_PROGRESS phase for the fermenting form.
 *
 * Loaded by session-body-fermenting.php when phase='in_progress':
 * at least one bd_fermenting_v2 event exists for the (beer, batch) pair,
 * and no ColdCrash event has been recorded yet.
 *
 * In P-A: renders the full unified fermenting form verbatim — same HTML as
 * fermenting-phase-start.php. The operator can continue adding Reads, DryHop,
 * Purge, or ColdCrash events. No phase-split write (P-C work).
 *
 * The repeating-event-capture surface for the active fermenting arc:
 *   - Each submission adds one event row to bd_fermenting_v2.
 *   - Multiple DryHop events per day are allowed (distinct submitted_at).
 *   - ColdCrash event transitions the phase to 'end' on next page load.
 *
 * Inherits scope: $pdo, $me, $csrf, $recipes, $hopsJs, $displayFmt,
 *                 $ff_beer, $ff_batch, $ff_recipeId (from session-body-fermenting.php)
 *
 * Q2/Q3 gates (LOCKED — P-B/P-C scope):
 *   - Q2: 1 session = 1 (recipe_id_fk, batch) over the full fermenting arc.
 *   - Q3: Reads no-threshold; DryHop same-day max; Purge ≥3d cadence;
 *     ColdCrash via ref_yeast_family_defaults.min_ferment_days.
 *   These are NOT implemented here — this is P-A structural extraction only.
 */
?>

<!-- ── IN_PROGRESS PHASE: Repeating event capture (P-C) ─────────────────────── -->
<form id="fermenting-form" method="post" action="/api/fermenting-phase-submit.php" novalidate>
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="phase" value="in_progress">
  <input type="hidden" name="session_id" value="<?= (int)$ff_sessionId ?>">
  <input type="hidden" id="recipe_id_fk" name="recipe_id_fk"
         value="<?= $ff_recipeId !== null ? (int)$ff_recipeId : '' ?>">

  <!-- Warning panel (populated by form-framework.js) -->
  <div id="fermenting-warnings" class="op-form__warnings" hidden aria-live="polite"></div>

  <!-- ── Section: Identité ────────────────────────────────────────────────── -->
  <div class="op-form__card">
    <div class="op-form__card-title">— identité du brassin</div>

    <!-- Return-to-selector link — always visible; mirrors the "✕ changer" affordance -->
    <div class="ferm-identity-strip">
      <a href="/modules/form-fermenting.php" class="ferm-identity-change">← Choisir un autre lot</a>
    </div>

    <div class="op-form__grid">

      <!-- Beer / recipe picker -->
      <div class="op-form__field">
        <label class="op-form__label" for="beer_select">Bière (recette)</label>
        <select id="beer_select" name="beer_select" class="op-form__select">
          <option value="">— sélectionner —</option>
          <?php foreach ($recipes as $r): ?>
            <?php
            // Pre-select by recipe_id (canonical) when available; fall back to
            // name-string match only for legacy direct-URLs without recipe_id.
            $isSelected = $ff_recipeId !== null
                ? ((int)$r['id'] === $ff_recipeId)
                : ($ff_beer !== '' && ($r['recipe_short_name'] === $ff_beer || $r['name'] === $ff_beer));
            ?>
            <option value="<?= htmlspecialchars($r['recipe_short_name'] ?: $r['name']) ?>"
                    data-recipe-id="<?= (int)$r['id'] ?>"
                    data-recipe-name="<?= htmlspecialchars($r['name']) ?>"
                    <?= $isSelected ? 'selected' : '' ?>>
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

      <!-- Event type — maps to bd_fermenting_v2.event_type ENUM.
           ColdCrash is intentionally absent — it is captured via the checkbox below. -->
      <div class="op-form__field">
        <label class="op-form__label" for="event_type">Type d'évènement</label>
        <select id="event_type" name="event_type" class="op-form__select">
          <?php
          // Pre-select the event type carried from the card-click URL ($ff_eventType).
          // ColdCrash is excluded: it is captured via the cold-crash checkbox, not the dropdown.
          $evtOptions = [
              'Reads'  => 'Mesures densité / pH / temp',
              'DryHop' => 'Houblonnage à froid',
              'Purge'  => 'Purge',
          ];
          $activeEvtType = in_array($ff_eventType, array_keys($evtOptions), true)
              ? $ff_eventType : 'Reads';
          foreach ($evtOptions as $evtVal => $evtLabel):
          ?>
          <option value="<?= htmlspecialchars($evtVal) ?>"
                  <?= ($activeEvtType === $evtVal) ? 'selected' : '' ?>>
            <?= htmlspecialchars($evtLabel) ?>
          </option>
          <?php endforeach ?>
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

    <!-- ── Cold-crash flag: replaces the ColdCrash dropdown option ──────────── -->
    <div class="ferm-coldcrash-flag" id="ferm-coldcrash-flag-wrap">
      <label class="ferm-coldcrash-flag__label">
        <input type="checkbox"
               id="ferm_cold_crash_flag"
               name="cold_crash_flag"
               value="1"
               class="ferm-coldcrash-flag__cb"
               aria-describedby="ferm-cc-flag-desc">
        <span class="ferm-coldcrash-flag__text">Cold Crash — termine la fermentation</span>
      </label>
      <p class="ferm-coldcrash-flag__desc" id="ferm-cc-flag-desc">
        Cocher pour enregistrer le refroidissement final. Cette action termine la session
        de fermentation et débloque le passage en garde / rack.
      </p>
    </div>
  </div>

  <!-- ── Section: Dry-hop (shown when event_type = DryHop) ───────────────── -->
  <div class="op-form__card" id="section-dryhop" hidden>
    <div class="op-form__card-title">
      — houblonnage à froid
      <span class="ferm-dh-count" id="dh-count-badge"></span>
    </div>

    <div class="op-form__field">
      <label class="op-form__label" for="dh_temperature">
        Température du dry-hop (°C)
        <span class="op-form__unit">(optionnel)</span>
      </label>
      <input type="number" id="dh_temperature" name="dh_temperature"
             class="op-form__input"
             placeholder="—" step="0.1">
    </div>

    <table class="ferm-dh-table">
      <thead>
        <tr>
          <th class="ferm-dh-col--hop">Ingrédient (MI)</th>
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
      Houblons et autres ingrédients (additions à froid). Catégorie dérivée automatiquement du MI.
    </p>
  </div>

  <!-- ── Section: Purge (shown when event_type = Purge) ──────────────────── -->
  <div class="op-form__card" id="section-purge" hidden>
    <div class="op-form__card-title">— purge</div>
    <div class="op-form__grid--1 op-form__grid">
      <div class="op-form__field">
        <label class="op-form__label" for="purge_pressure_bar">
          Pression cuve (bar)
          <span class="op-form__unit">(optionnel)</span>
        </label>
        <input type="number" id="purge_pressure_bar" name="purge_pressure_bar"
               class="op-form__input"
               placeholder="—" step="0.01" min="0" autocomplete="off">
      </div>
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

  <!-- Submit bar ─────────────────────────────────────────────────────────── -->
  <!-- Single CTA — phase advance (including ColdCrash → end) is always server-side. -->
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
