<?php
declare(strict_types=1);
/**
 * racking-phase-end.php — END phase sections for the racking form (P-C).
 *
 * Loaded by session-body-racking.php (the phase dispatcher) when phase='end'.
 * Inherits the dispatcher's scope: $session, $pdo, $csrf.
 *
 * P-C changes vs P-A:
 *   - Recap card added at top (ss-recap* styles, reads loss_metrics_for_batches).
 *   - safety_cip_done moved here from racking-phase-in-progress.php.
 *   - Wrapped in its own <form> POSTing to /api/racking-phase-submit.php (phase=end).
 *   - Submit button changed to "Clôturer la session".
 *
 * Sections:
 *   SR  Recap pertes (read-only, top of end phase)
 *   S7  Commentaires + fw_comment
 *   S8  Pertes (user-entered loss volumes)
 *   S9  Transfert interrompu
 *   S6b Safety CIP (moved from in-progress)
 *   Submit bar
 */
require_once __DIR__ . '/../../../app/loss-metrics.php';

// ── Recap data ────────────────────────────────────────────────────────────────
// Load loss metrics for the batch linked to this session.
// Tolerates missing data silently — recap section renders as "Données insuffisantes"
// when the batch hasn't been fully processed yet.
$_recapMetrics   = [];
$_recapThresholds = [];
$_recapErr        = null;

try {
    $_recapMetrics    = loss_metrics_for_batches($pdo, ['session_id' => (int)$session['id']]);
    $_recapThresholds = loss_thresholds($pdo);
} catch (Throwable $_recapLoadErr) {
    $_recapErr = $_recapLoadErr->getMessage();
}

// Helper: format a percentage value or '—' when null.
function _recap_pct(?float $v, int $decimals = 1): string
{
    if ($v === null) return '—';
    return number_format(abs($v), $decimals, '.', '') . ' %';
}

// Helper: render a palier badge (ss-recap-badge).
// warn  threshold only (no critical in the data model).
// green = OK, amber = warn exceeded.
function _recap_badge(?float $pct, float $warnPct): string
{
    if ($pct === null) return '<span class="ss-recap-badge ss-recap-badge--unknown">—</span>';
    if ($pct > $warnPct) {
        return '<span class="ss-recap-badge ss-recap-badge--warn">⚠ ' . number_format($pct, 1, '.', '') . ' %</span>';
    }
    return '<span class="ss-recap-badge ss-recap-badge--ok">✓ ' . number_format($pct, 1, '.', '') . ' %</span>';
}
?>

<!-- ── SR: Recap pertes (read-only) ──────────────────────────────────────── -->
<div class="op-form__card ss-recap-card" id="ss-recap-pertes">
  <div class="op-form__card-title">— récap pertes de ce transfert</div>

  <?php if ($_recapErr !== null): ?>
    <p class="ss-recap-notice">Erreur de chargement des métriques : <?= htmlspecialchars($_recapErr) ?></p>

  <?php elseif (empty($_recapMetrics)): ?>
    <p class="ss-recap-notice">Données insuffisantes — les métriques seront disponibles après enregistrement du transfert.</p>

  <?php else:
    $m = $_recapMetrics[0]; // session_id filter returns at most 1 batch
    $th = $_recapThresholds;
  ?>
    <div class="ss-recap-grid">

      <!-- Cast-out (volume effectif) -->
      <div class="ss-recap-item">
        <span class="ss-recap-label">Volume effectif (cast-out)</span>
        <span class="ss-recap-value">
          <?= $m['cast_out_hl'] !== null ? number_format((float)$m['cast_out_hl'], 2, '.', '') . ' HL' : '—' ?>
        </span>
      </div>

      <!-- Nominal -->
      <div class="ss-recap-item">
        <span class="ss-recap-label">Volume nominal</span>
        <span class="ss-recap-value ss-recap-value--muted"
              title="<?= htmlspecialchars($m['nominal_basis'] ?? '') ?>">
          <?= $m['nominal_hl'] !== null ? number_format((float)$m['nominal_hl'], 1, '.', '') . ' HL' : '—' ?>
        </span>
      </div>

      <!-- Racked vol -->
      <div class="ss-recap-item">
        <span class="ss-recap-label">Volume transféré</span>
        <span class="ss-recap-value">
          <?php
            // Racked vol comes from the bd_racking_v2 row linked to this session.
            // loss_metrics doesn't surface it directly; it can be null at recap time.
            // For the end phase, render a short note.
          ?>
          <span class="ss-recap-value--muted">voir mesures ci-dessus</span>
        </span>
      </div>

      <!-- Packaged -->
      <div class="ss-recap-item">
        <span class="ss-recap-label">Volume conditionné</span>
        <span class="ss-recap-value">
          <?= $m['packaged_hl'] !== null ? number_format((float)$m['packaged_hl'], 2, '.', '') . ' HL' : '—' ?>
          <?php if (!$m['complete']): ?>
            <span class="ss-recap-badge ss-recap-badge--pending">en cours</span>
          <?php endif ?>
        </span>
      </div>

    </div><!-- /.ss-recap-grid -->

    <!-- Palier badges row -->
    <div class="ss-recap-badges-row">

      <div class="ss-recap-badge-item">
        <span class="ss-recap-badge-label">Pertes transfert</span>
        <?= _recap_badge($m['rack_loss_pct'], $th['pertes_rack_warn_pct']) ?>
      </div>

      <div class="ss-recap-badge-item">
        <span class="ss-recap-badge-label">Pertes brassage</span>
        <?= _recap_badge($m['brewing_loss_pct'], $th['pertes_brewing_warn_pct']) ?>
      </div>

      <?php if ($m['complete']): ?>
      <div class="ss-recap-badge-item">
        <span class="ss-recap-badge-label">Total vs effectif</span>
        <?= _recap_badge($m['loss_vs_effectif_pct'], $th['pertes_total_effectif_warn_pct']) ?>
      </div>
      <div class="ss-recap-badge-item">
        <span class="ss-recap-badge-label">Total vs nominal</span>
        <?= _recap_badge($m['loss_vs_nominal_pct'], $th['pertes_total_nominal_warn_pct']) ?>
      </div>
      <?php endif ?>

    </div><!-- /.ss-recap-badges-row -->

  <?php endif ?>
</div><!-- /#ss-recap-pertes -->

<!-- ── END-phase form (P-C: own <form> for phase-split write) ────────────── -->
<form id="racking-end-form" novalidate>
  <input type="hidden" name="csrf"       value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
  <input type="hidden" name="phase"      value="end">

<!-- ── S6c: Flowmeter end reading ────────────────────────────────────────── -->
<div class="op-form__card">
  <div class="op-form__card-title">— relevé compteur fin</div>
  <div class="op-form__grid">

    <div class="op-form__field">
      <label class="op-form__label" for="flowmeter_end_hl">
        Relevé compteur — fin <span class="op-form__unit">HL</span>
      </label>
      <input id="flowmeter_end_hl" name="flowmeter_end_hl" type="text" inputmode="decimal"
             class="op-form__input" placeholder="ex. 12375.1">
      <div id="rf-flowmeter-end-error" class="op-form__inline-error" hidden></div>
    </div>

  </div>
</div>

<!-- ── S6b: Safety CIP (moved here from in-progress — end-phase field) ───── -->
<div class="op-form__card">
  <div class="op-form__card-title">— nettoyage</div>
  <div class="op-form__grid">

    <div class="op-form__field">
      <label class="op-form__label" for="safety_cip_done">Safety CIP effectué ?</label>
      <select id="safety_cip_done" name="safety_cip_done" class="op-form__select">
        <option value="">—</option>
        <?php foreach (CENTRI_RINSED_YN as $yn): ?>
          <option value="<?= htmlspecialchars($yn) ?>"><?= htmlspecialchars($yn) ?></option>
        <?php endforeach ?>
      </select>
    </div>

  </div>
</div>

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

<!-- Submit bar — P-C: closes the session after persisting end-phase data. -->
<div class="op-form__submit-bar">
  <button type="button" id="racking-end-cancel"
          class="op-form__btn op-form__btn--secondary">
    Annuler
  </button>
  <button type="submit" id="racking-end-submit"
          class="op-form__btn op-form__btn--primary">
    Clôturer la session →
  </button>
</div>

</form><!-- /#racking-end-form -->
