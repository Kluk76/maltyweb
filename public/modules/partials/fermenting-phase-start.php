<?php
declare(strict_types=1);
/**
 * fermenting-phase-start.php — START phase for the fermenting form (P-B).
 *
 * Loaded by session-body-fermenting.php when phase='none' or phase='start'.
 *
 * P-B upgrades this from the P-A "pick beer + batch + submit" placeholder to
 * a real pre-start firewall view with three predicates, mirroring the racking
 * pilot 3 P-B pattern (commit 60e0b89).
 *
 * Inherits scope from session-body-fermenting.php:
 *   $pdo, $me, $csrf, $recipes, $hopsJs, $displayFmt
 *   $ff_beer, $ff_batch, $ff_recipeId (URL params)
 *   $ff_cctNumber  — int|null      CCT number for this (beer, batch)
 *   $ff_cctMissing — bool          brewday row found but cct IS NULL
 *   $ff_yeastInfo  — array|null    from resolve_recipe_yeast(); null if no recipe
 *
 * Firewall predicates (all display-only; submit button state derived from them):
 *   1. CCT-presence gate — resolves (beer, batch) → CCT or shows banner
 *   2. Yeast eligibility — earliest ColdCrash date from garde_days_min (display only)
 *
 * The form <form> wraps the complete fermenting event fields. The submit button
 * is disabled when any firewall predicate is RED (no CCT). Gate 2 (CIP cadence)
 * is intentionally absent from the fermenting start firewall.
 *
 * POST handler: /modules/form-fermenting.php (unchanged, P-C work).
 *
 * CSS: /css/form-fermenting.css (ferm-* namespace).
 */

// ── Derive firewall verdict ────────────────────────────────────────────────────

// Gate 1: CCT presence
// RED if no CCT number and no brewday row (cctNumber null AND cctMissing false
// means no brewday row at all); RED also if brewday row exists but cct is NULL.
$gate1Severity = 'ok';
$gate1Label    = '';
$hasBeerBatch  = ($ff_beer !== '' && $ff_batch !== '');

if ($hasBeerBatch) {
    if ($ff_cctNumber !== null) {
        $gate1Severity = 'ok';
        $gate1Label    = 'CCT ' . $ff_cctNumber . ' (saisie brewday)';
    } elseif ($ff_cctMissing) {
        // Brewday row exists but CCT not recorded.
        $gate1Severity = 'red';
        $gate1Label    = 'CCT non renseignée dans la saisie brewday — corriger avant de démarrer';
    } else {
        // No brewday row at all for (beer, batch).
        $gate1Severity = 'red';
        $gate1Label    = 'Aucune CCT trouvée pour ce brassin — vérifier la saisie brewday';
    }
} else {
    // No beer/batch selected yet: gate is not yet applicable (show as neutral).
    $gate1Severity = 'pending';
    $gate1Label    = 'Sélectionner un brassin pour évaluer la CCT';
}

// Gate 2 (CIP cadence) has been removed from the fermenting start firewall.
// It remains active in the racking start firewall (session-body-racking.php).

// Gate 2: Yeast eligibility (display-only — never blocks submit)
$gate3Available   = false;
$gate3Label       = '';
$gate3EarliestCc  = null;  // DateTimeImmutable or null

if ($ff_yeastInfo !== null) {
    $gardeDays  = $ff_yeastInfo['garde_days_min'];
    $strainName = $ff_yeastInfo['strain_name'];
    $family     = $ff_yeastInfo['family_label'];

    if ($gardeDays !== null) {
        $gate3Available = true;
        // Earliest ColdCrash date = today + garde_days_min (pitch day = today for new ferments).
        $earliestTs    = strtotime("+{$gardeDays} days");
        $gate3EarliestCc = date('d.m.Y', $earliestTs);  // display format (system: DMY)
        $strainDisplay  = htmlspecialchars($strainName ?? ($family ?? 'Levure inconnue'));
        $gate3Label     = "ColdCrash possible ≥ {$gate3EarliestCc} ({$strainDisplay}, garde min {$gardeDays}j)";
    } else {
        $gate3Available = true;
        $strainDisplay  = htmlspecialchars($strainName ?? ($family ?? 'Levure inconnue'));
        $gate3Label     = "Garde minimum non définie ({$strainDisplay}) — ColdCrash hors process uniquement";
    }
}

// ── Overall submit-blocking verdict ────────────────────────────────────────────
// RED in gate1 → submit disabled. Gate 2 (CIP cadence) removed from fermenting.
// EDIT MODE: a correction never re-runs the start firewall (the firewall is for
// commissioning a NEW ferment). Migrated rows carry beer_raw values (e.g. "STI 171")
// that don't resolve to a brewday CCT, which would otherwise wrongly disable the
// submit button. Always allow submit when correcting an existing row.
$submitBlocked = (!empty($editMode)) ? false : ($gate1Severity === 'red');

?>

<?php
// $ff_phase / $ff_eventType / $hasBeerBatch are set by the calling partials.
// $canOverride is set in form-fermenting.php (is_admin || is_manager).
?>

<?php if ($ff_phase === 'none'): ?>
<!-- ──────────────────────────────────────────────────────────────────────────
     SELECTOR VIEW — no beer/batch selected yet.
     Shows: event_type selector + dynamic candidate cards.
     Card click navigates via GET (?beer=…&batch=…&event_type=…).
     The existing firewall + form sections only render once beer/batch are known.
     ────────────────────────────────────────────────────────────────────────── -->
<div class="op-form__card" id="ferm-selector-card">
  <div class="op-form__card-title">— sélectionner un brassin en fermentation</div>

  <!-- Event type drives which card set is shown -->
  <div class="op-form__field ferm-sel-type-field">
    <label class="op-form__label" for="ferm_sel_event_type">Type d'évènement</label>
    <select id="ferm_sel_event_type" class="op-form__select" style="max-width:320px;">
      <option value="Reads">Mesures densité / pH / temp</option>
      <option value="DryHop">Houblonnage à froid</option>
      <option value="Purge">Purge</option>
    </select>
  </div>

  <?php if ($canOverride): ?>
  <!-- Hors-process toggle (admin / manager only — PHP-gated, not just CSS-hidden) -->
  <div class="ferm-hp-block" id="ferm-hp-block">
    <label class="ferm-hp-label">
      <input type="checkbox" id="ferm_hp_checkbox" class="ferm-hp-checkbox"
             aria-describedby="ferm-hp-desc">
      <span class="ferm-hp-text">Choix Hors Process</span>
      <span class="ferm-hp-badge">Manager / Admin</span>
    </label>
    <p class="ferm-hp-desc" id="ferm-hp-desc">
      Affiche tous les lots en CCT ou BBT, indépendamment de l'état de l'évènement.
    </p>
  </div>
  <?php endif ?>

  <!-- Normal (gated) candidates — populated by form-fermenting.js -->
  <div id="ferm-normal-candidates">
    <div class="ferm-cand-grid" id="ferm-cand-grid-normal">
      <!-- Cards injected by form-fermenting.js on event_type change -->
    </div>
  </div>

  <?php if ($canOverride): ?>
  <!-- Hors-process candidates — populated by form-fermenting.js when toggle active -->
  <div id="ferm-override-candidates" hidden>
    <div class="ferm-cand-grid" id="ferm-cand-grid-override">
      <!-- Cards injected by form-fermenting.js when hors-process is enabled -->
    </div>
  </div>
  <?php endif ?>

</div><!-- /#ferm-selector-card -->

<?php else: ?>
<!-- ──────────────────────────────────────────────────────────────────────────
     FORM VIEW — beer/batch selected; render full firewall + event sections.
     In edit mode ($editMode): identity strip is locked, event_type is locked,
     event_date is editable, edit_submitted_at hidden field preserves NK.
     ────────────────────────────────────────────────────────────────────────── -->

<!-- ── START PHASE: Fermenting session firewall (P-B / P-C) / Edit correction ── -->
<form id="fermenting-form" method="post" action="/api/fermenting-phase-submit.php" novalidate>
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="phase" value="start">
  <input type="hidden" name="session_id" value="<?= (int)$ff_sessionId ?>">
  <input type="hidden" id="recipe_id_fk" name="recipe_id_fk"
         value="<?= $ff_recipeId !== null ? (int)$ff_recipeId : '' ?>">

  <?php if (!empty($editMode) && $editOrigSubmittedAt !== null): ?>
  <!-- Edit-mode guard: carry original submitted_at to preserve the NK on re-POST.
       CRITICAL: without this, bd_upsert stamps a fresh submitted_at → new NK → duplicate row. -->
  <input type="hidden" name="edit_submitted_at"
         value="<?= htmlspecialchars($editOrigSubmittedAt) ?>">
  <input type="hidden" name="edit_id"
         value="<?= (int)$editId ?>">
  <?php endif ?>

  <!-- Warning panel (populated by form-framework.js) -->
  <div id="fermenting-warnings" class="op-form__warnings" hidden aria-live="polite"></div>

  <!-- ── Section: Identité ────────────────────────────────────────────────── -->
  <div class="op-form__card">
    <div class="op-form__card-title">— identité du brassin</div>

    <?php if (!empty($editMode) && $editBanner !== null): ?>
    <!-- Edit-mode banner — identifies the event being corrected -->
    <div class="ferm-edit-banner" role="alert">
      <span class="ferm-edit-banner__label">Correction</span>
      <span class="ferm-edit-banner__detail">
        <strong><?= htmlspecialchars($editBanner['beer']) ?></strong>
        · Brassin <?= htmlspecialchars($editBanner['batch']) ?>
        · <?= htmlspecialchars($editBanner['event_type']) ?>
        · <?= htmlspecialchars($editBanner['event_date'] !== '' ? date('d.m.Y', strtotime($editBanner['event_date'])) : '') ?>
      </span>
    </div>

    <!-- Non-editable identity strip in edit mode: beer/batch/event_type are locked -->
    <div class="ferm-edit-identity-strip">
      <strong><?= htmlspecialchars($prefillEdit['beer_raw'] ?? '') ?></strong>
      <span>·</span>
      <span>Brassin <?= htmlspecialchars($prefillEdit['batch'] ?? '') ?></span>
      <span>·</span>
      <span><?= htmlspecialchars($prefillEdit['event_type'] ?? '') ?></span>
    </div>

    <!-- Hidden identity fields consumed by the POST handler -->
    <input type="hidden" name="beer_select" value="<?= htmlspecialchars($prefillEdit['beer_raw'] ?? '') ?>">
    <input type="hidden" name="batch"       value="<?= htmlspecialchars($prefillEdit['batch']    ?? '') ?>">
    <!-- event_type locked: passed as hidden field, not editable select -->
    <input type="hidden" name="event_type"  value="<?= htmlspecialchars($prefillEdit['event_type'] ?? 'Reads') ?>">

    <?php else: ?>
    <!-- Identity strip: show selected beer + batch + "change" link -->
    <div class="ferm-identity-strip">
      <span class="ferm-identity-beer"><?= htmlspecialchars($ff_beer) ?></span>
      <span class="ferm-identity-sep">·</span>
      <span class="ferm-identity-batch">Brassin <?= htmlspecialchars($ff_batch) ?></span>
      <a href="/modules/form-fermenting.php" class="ferm-identity-change">✕ changer</a>
    </div>

    <!-- Hidden identity fields consumed by the POST handler -->
    <input type="hidden" name="beer_select" value="<?= htmlspecialchars($ff_beer) ?>">
    <input type="hidden" name="batch"       value="<?= htmlspecialchars($ff_batch) ?>">
    <?php endif ?>

    <div class="op-form__grid">

      <!-- Event date — editable in both normal and edit modes -->
      <?php
      $editDateValue = (!empty($editMode) && !empty($prefillEdit['event_date']))
          ? htmlspecialchars($prefillEdit['event_date'])
          : htmlspecialchars(date('Y-m-d'));
      ?>
      <div class="op-form__field">
        <label class="op-form__label" for="event_date">
          Date
          <span class="op-form__unit"><?= htmlspecialchars($displayFmt) ?></span>
        </label>
        <input id="event_date" name="event_date" type="date" class="op-form__input"
               value="<?= $editDateValue ?>" required>
      </div>

      <?php if (empty($editMode)): ?>
      <!-- Event type — editable only in normal mode; locked as hidden field in edit mode.
           ColdCrash is intentionally absent — it is captured via the checkbox below. -->
      <div class="op-form__field">
        <label class="op-form__label" for="event_type">Type d'évènement</label>
        <select id="event_type" name="event_type" class="op-form__select">
          <?php
          // $ff_eventType is validated in session-body-fermenting.php; empty = default Reads.
          // ColdCrash is excluded: it is captured via the cold-crash checkbox, not the dropdown.
          $evtOptions = [
              'Reads'  => 'Mesures densité / pH / temp',
              'DryHop' => 'Houblonnage à froid',
              'Purge'  => 'Purge',
          ];
          // If we arrived here via a ColdCrash deep-link (URL param), default to Reads.
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
      <?php endif ?>

    </div>
  </div>

  <?php if (empty($editMode) && $hasBeerBatch): ?>
  <!-- ── Section: Vérifications pré-démarrage (firewall) ─────────────────── -->
  <!-- Skipped in edit mode: corrections bypass the firewall (event already submitted). -->
  <div class="op-form__card ferm-fw-card" id="ferm-fw-card">
    <div class="op-form__card-title">— vérifications pré-démarrage</div>

    <ul class="ferm-fw-checklist" role="list" aria-label="Vérifications pare-feu fermentation">

      <!-- Gate 1: CCT presence ───────────────────────────────────────────── -->
      <li class="ferm-fw-item ferm-fw-item--<?= htmlspecialchars($gate1Severity) ?>">
        <span class="ferm-fw-icon" aria-hidden="true">
          <?php if ($gate1Severity === 'ok'): ?>✅
          <?php elseif ($gate1Severity === 'red'): ?>🚫
          <?php else: ?>⏳
          <?php endif ?>
        </span>
        <span class="ferm-fw-text"><?= htmlspecialchars($gate1Label) ?></span>
      </li>

      <!-- Gate 2: Yeast eligibility (display-only) ──────────────────────── -->
      <?php if ($gate3Available): ?>
      <li class="ferm-fw-item ferm-fw-item--info">
        <span class="ferm-fw-icon" aria-hidden="true">ℹ️</span>
        <span class="ferm-fw-text"><?= htmlspecialchars($gate3Label) ?></span>
      </li>
      <?php endif ?>

    </ul>

  </div><!-- /.ferm-fw-card -->
  <?php endif ?>

  <!-- ── Section: Mesures densité / pH / température ──────────────────────── -->
  <div class="op-form__card" id="section-readings">
    <div class="op-form__card-title">— mesures (°Plato · pH · °C)</div>
    <div class="ferm-readings-note">
      La densité est saisie en <strong>°Plato</strong> (pas en SG).
      Valeurs typiques : OG 10–20°P, FG 1–5°P.
    </div>

    <div class="ferm-readings-grid">

      <?php
      // Edit-mode prefill values for reading inputs
      $_editGravity = (!empty($editMode) && $prefillEdit['gravity'] !== null)
          ? htmlspecialchars((string)$prefillEdit['gravity']) : '';
      $_editPh = (!empty($editMode) && $prefillEdit['ph'] !== null)
          ? htmlspecialchars((string)$prefillEdit['ph']) : '';
      $_editTemp = (!empty($editMode) && $prefillEdit['temperature'] !== null)
          ? htmlspecialchars((string)$prefillEdit['temperature']) : '';
      ?>

      <div class="ferm-reading-card">
        <div class="ferm-reading-card__head">
          <span class="ferm-reading-card__label">Densité</span>
          <span class="ferm-reading-card__unit">°Plato</span>
        </div>
        <input type="number" id="gravity" name="gravity"
               class="ferm-reading-input"
               placeholder="—" step="0.1" min="0" max="30" autocomplete="off"
               <?= $_editGravity !== '' ? "value=\"{$_editGravity}\"" : '' ?>>
        <div class="ferm-reading-hint" id="gravity-hint">OG typique 10–20°P · FG 0.5–5°P</div>
      </div>

      <div class="ferm-reading-card">
        <div class="ferm-reading-card__head">
          <span class="ferm-reading-card__label">pH</span>
          <span class="ferm-reading-card__unit">pH</span>
        </div>
        <input type="number" id="ph" name="ph"
               class="ferm-reading-input"
               placeholder="—" step="0.01" min="2" max="8" autocomplete="off"
               <?= $_editPh !== '' ? "value=\"{$_editPh}\"" : '' ?>>
        <div class="ferm-reading-hint" id="ph-hint">Pale Ale typique 4.1–4.6</div>
      </div>

      <div class="ferm-reading-card">
        <div class="ferm-reading-card__head">
          <span class="ferm-reading-card__label">Température</span>
          <span class="ferm-reading-card__unit">°C</span>
        </div>
        <input type="number" id="temperature" name="temperature"
               class="ferm-reading-input"
               placeholder="—" step="0.1" min="-5" max="40" autocomplete="off"
               <?= $_editTemp !== '' ? "value=\"{$_editTemp}\"" : '' ?>>
        <div class="ferm-reading-hint" id="temp-hint">Fermentation 16–22°C · Cold crash 0–4°C</div>
      </div>

    </div>

    <?php
    // Cold-crash checkbox logic:
    //   - Non-edit mode: unchecked, always enabled.
    //   - Edit mode, existing row IS ColdCrash: ticked + DISABLED (cannot un-terminate).
    //   - Edit mode, existing row is NOT ColdCrash: unchecked + ENABLED (conversion path).
    $isColdCrashEdit   = (!empty($editMode) && ($prefillEdit['event_type'] ?? '') === 'ColdCrash');
    $ccFlagChecked     = $isColdCrashEdit;
    $ccFlagDisabled    = $isColdCrashEdit; // locked only when already a ColdCrash row
    ?>
    <!-- ── Cold-crash flag: replaces the ColdCrash dropdown option ──────────── -->
    <div class="ferm-coldcrash-flag" id="ferm-coldcrash-flag-wrap">
      <label class="ferm-coldcrash-flag__label">
        <input type="checkbox"
               id="ferm_cold_crash_flag"
               name="cold_crash_flag"
               value="1"
               class="ferm-coldcrash-flag__cb"
               <?= $ccFlagChecked  ? 'checked'   : '' ?>
               <?= $ccFlagDisabled ? 'disabled aria-disabled="true"' : '' ?>
               aria-describedby="ferm-cc-flag-desc">
        <span class="ferm-coldcrash-flag__text">Cold Crash — termine la fermentation</span>
      </label>
      <p class="ferm-coldcrash-flag__desc" id="ferm-cc-flag-desc">
        <?php if (!empty($editMode) && !$isColdCrashEdit): ?>
        Cocher pour convertir cette mesure en cold crash (in-place, même ID).
        Cette action termine la session de fermentation et débloque le passage en soutirage.
        <?php else: ?>
        Cocher pour enregistrer le refroidissement final. Cette action termine la session
        de fermentation et débloque le passage en garde / rack.
        <?php endif ?>
      </p>
    </div>
  </div>

  <!-- ── Section: Dry-hop (shown when event_type = DryHop) ───────────────── -->
  <div class="op-form__card" id="section-dryhop" hidden>
    <div class="op-form__card-title">
      — houblonnage à froid
      <span class="ferm-dh-count" id="dh-count-badge"></span>
    </div>

    <?php
    $_editDhTemp = (!empty($editMode) && $prefillEdit['temperature'] !== null)
        ? htmlspecialchars((string)$prefillEdit['temperature']) : '';
    ?>
    <div class="op-form__field">
      <label class="op-form__label" for="dh_temperature">
        Température du dry-hop (°C)
        <span class="op-form__unit">(optionnel)</span>
      </label>
      <input type="number" id="dh_temperature" name="dh_temperature"
             class="op-form__input"
             placeholder="—" step="0.1"
             <?= $_editDhTemp !== '' ? "value=\"{$_editDhTemp}\"" : '' ?>>
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
               placeholder="—" step="0.01" min="0" autocomplete="off"
               <?= (!empty($editMode) && isset($prefillEdit['purge_pressure_bar']) && $prefillEdit['purge_pressure_bar'] !== null)
                   ? 'value="' . htmlspecialchars((string)$prefillEdit['purge_pressure_bar']) . '"'
                   : '' ?>>
      </div>
      <div class="op-form__field op-form__field--full">
        <label class="op-form__label" for="comment_purge">
          Commentaire purge
          <span class="op-form__unit">(optionnel)</span>
        </label>
        <textarea id="comment_purge" name="comment_purge"
                  class="op-form__textarea" rows="3"
                  placeholder="Fuites constatées, pression anormale, anomalies…"><?= htmlspecialchars(!empty($editMode) ? ($prefillEdit['comment_purge'] ?? '') : '') ?></textarea>
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
                  placeholder="Temp. cible, durée prévue, observations…"><?= htmlspecialchars(!empty($editMode) ? ($prefillEdit['comment_cold_crash'] ?? '') : '') ?></textarea>
      </div>
    </div>
    <div class="ferm-unwired-notice">
      <strong>Non câblé :</strong> Température cible, date crash et durée nécessitent
      une migration. La <strong>température</strong> (°C) est capturée via Mesures ci-dessus.
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
                  placeholder="Notes, odeurs, aspect visuel, écarts, problèmes…"><?= htmlspecialchars(!empty($editMode) ? ($prefillEdit['final_comments'] ?? '') : '') ?></textarea>
      </div>
    </div>
  </div>

  <!-- Submit bar -->
  <div class="op-form__submit-bar">
    <button type="button" class="op-form__btn op-form__btn--secondary"
            onclick="if(confirm('Effacer le brouillon ?')){localStorage.removeItem('fermenting-draft');location.reload();}">
      Effacer brouillon
    </button>
    <button type="submit"
            class="op-form__btn op-form__btn--primary"
            id="ferm-submit-btn"
            <?= $submitBlocked ? 'disabled aria-disabled="true"' : '' ?>>
      Enregistrer →
    </button>
  </div>

</form>

<?php endif /* $ff_phase === 'none' vs form view */ ?>
