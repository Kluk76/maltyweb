<?php
declare(strict_types=1);
/**
 * racking-phase-end.php — END phase sections for the racking form.
 *
 * Loaded by session-body-racking.php (the phase dispatcher).
 * Inherits the dispatcher's scope: $recentRackings, $csrf.
 *
 * Sections (P-A: render-only, byte-for-byte from form-racking.php):
 *   S7  Commentaires fw_comment (lines 1332-1342)
 *   S8  Pertes (lines 1344-1416)
 *   S9  Transfert interrompu (lines 1418-1472)
 *   Submit bar + recent submissions table (lines 1474-end)
 *
 * JS data surfaces (window.RACK_CANDIDATES etc.) are emitted once by
 * racking-phase-in-progress.php via the _RACKING_JS_DATA_INJECTED guard.
 * If in-progress is somehow omitted (future phase-gate scenarios), this
 * partial has a fallback guard that does NOT emit the block again.
 * The racking-form.js <script> tag is emitted here (last partial = safe).
 */
?>

<!-- ── S7: Commentaires ───────────────────────────────────────────────────── -->
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

<!-- ── S8: Pertes (C3) ────────────────────────────────────────────────────── -->
<!-- Hidden section revealed by toggle. NO static `required` on any field.
     JS drives required-while-visible for loss_cause when a volume is entered. -->
<div class="op-form__card rf-pertes-card">
  <div class="op-form__card-title">— pertes</div>

  <label class="rf-pertes-toggle-label">
    <input type="checkbox" id="rf-perte-toggle" name="perte_toggle" value="1"
           class="rf-pertes-toggle-checkbox">
    <span class="rf-pertes-toggle-text">Des pertes à signaler ?</span>
  </label>

  <div id="rf-pertes-fields" hidden>
    <div class="op-form__grid">

      <div class="op-form__field">
        <label class="op-form__label" for="loss_source_hl">
          Perte cuve départ <span class="op-form__unit">HL</span>
          <span class="op-form__opt">(facultatif)</span>
        </label>
        <input id="loss_source_hl" name="loss_source_hl" type="number"
               inputmode="decimal" step="0.001" min="0"
               class="op-form__input" placeholder="0.000">
      </div>

      <div class="op-form__field">
        <label class="op-form__label" for="loss_dest_hl">
          Perte cuve arrivée <span class="op-form__unit">HL</span>
          <span class="op-form__opt">(facultatif)</span>
        </label>
        <input id="loss_dest_hl" name="loss_dest_hl" type="number"
               inputmode="decimal" step="0.001" min="0"
               class="op-form__input" placeholder="0.000">
      </div>

      <div class="op-form__field">
        <label class="op-form__label" for="loss_cause">
          Cause
        </label>
        <!-- No static `required` — JS adds it when a volume > 0 is entered -->
        <select id="loss_cause" name="loss_cause" class="op-form__select">
          <option value="">— sélectionner —</option>
          <option value="produit">Produit</option>
          <option value="machine">Machine</option>
          <option value="humain">Humain</option>
        </select>
      </div>

      <!-- Volume balance preview (read-only, JS-computed) -->
      <div class="op-form__field" id="rf-loss-balance-field">
        <label class="op-form__label">
          Bilan volumes <span class="op-form__opt">(calculé)</span>
        </label>
        <div id="rf-loss-balance" class="op-form__readout rf-loss-balance" aria-live="polite">—</div>
      </div>

    </div>

    <div class="op-form__grid--1 op-form__grid" style="margin-top:0.5rem">
      <div class="op-form__field op-form__field--full">
        <label class="op-form__label" for="loss_note">
          Détails / explication <span class="op-form__opt">(facultatif)</span>
        </label>
        <textarea id="loss_note" name="loss_note" class="op-form__textarea" rows="2"
                  maxlength="500"
                  placeholder="Cause, contexte, lot concerné…"></textarea>
      </div>
    </div>
  </div>
</div>

<!-- ── S9: Transfert interrompu (C4) ─────────────────────────────────────── -->
<!-- Hidden section revealed by toggle. NO static `required` on any field.
     JS adds required to interrupted_reason while revealed + to dest_bbt_still_clean
     when also racked_vol_hl == 0/empty. -->
<div class="op-form__card rf-interrupted-card">
  <div class="op-form__card-title">— transfert interrompu</div>

  <label class="rf-interrupted-toggle-label">
    <input type="checkbox" id="rf-interrupted-toggle" name="interrupted_flag" value="1"
           class="rf-interrupted-toggle-checkbox">
    <span class="rf-interrupted-toggle-text">Le transfert a été interrompu</span>
  </label>

  <div id="rf-interrupted-fields" hidden>

    <div class="op-form__grid--1 op-form__grid" style="margin-top:0.75rem">
      <div class="op-form__field op-form__field--full">
        <label class="op-form__label" for="interrupted_reason">
          Raison de l'interruption
          <!-- No static required — JS adds it while section is visible -->
        </label>
        <textarea id="interrupted_reason" name="interrupted_reason"
                  class="op-form__textarea" rows="2" maxlength="500"
                  placeholder="Décris l'événement : cause, état de la cuve, suite prévue…"></textarea>
      </div>
    </div>

    <!-- BBT encore propre ? — shown ONLY when racked_vol_hl == 0 / empty.
         NO static required — JS adds required when this sub-section is visible. -->
    <div id="rf-bbt-propre-row" class="op-form__grid--1 op-form__grid" style="margin-top:0.5rem" hidden>
      <div class="op-form__field op-form__field--full">
        <label class="op-form__label">BBT encore propre ?</label>
        <div class="rf-bbt-propre-radios">
          <label class="rf-radio-label">
            <input type="radio" name="dest_bbt_still_clean" value="1"
                   id="dest_bbt_clean_oui" class="rf-bbt-propre-radio">
            Oui — BBT reste propre
          </label>
          <label class="rf-radio-label">
            <input type="radio" name="dest_bbt_still_clean" value="0"
                   id="dest_bbt_clean_non" class="rf-bbt-propre-radio">
            Non — BBT à nettoyer avant le prochain transfert
          </label>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Submit bar — this IS the real submit for the single-submit endpoint (P-A).
     In P-C this bar will move to a per-phase action. -->
<div class="op-form__submit-bar">
  <button type="button" class="op-form__btn op-form__btn--secondary"
          onclick="if(confirm('Effacer le brouillon ?')){localStorage.removeItem('racking-draft');location.reload();}">
    Effacer brouillon
  </button>
  <button type="submit" id="rf-submit" class="op-form__btn op-form__btn--primary">
    Enregistrer le transfert →
  </button>
</div>

<!-- racking-form.js and form stylesheets are loaded by the dispatcher
     (session-body-racking.php) after </form>, outside the form element. -->
