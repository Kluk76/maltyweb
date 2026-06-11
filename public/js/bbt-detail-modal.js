/* bbt-detail-modal.js — BBT CIP cadence detail popup for the packaging board */
'use strict';

(function () {

  function escHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /**
   * Format an ISO date string (YYYY-MM-DD) as DD/MM/YYYY (DMY, system convention).
   * Returns '—' for null/empty.
   */
  function fmtDateDMY(isoStr) {
    if (!isoStr) return '—';
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(isoStr);
    if (!m) return escHtml(isoStr);
    return m[3] + '/' + m[2] + '/' + m[1];
  }

  /**
   * Render the modal card HTML for a given BBT.
   * Returns an HTML string to set as the bbt-modal__card innerHTML.
   * Reads new cycle fields directly: since_last_cip, last_cip_type, last_cip_at,
   * next_cip_type (all emitted by cip-cadence.php).
   */
  function renderCard(num, d) {
    // Header
    const beerName = d.recipe_short_name || d.beer || null;
    const headerBeer = beerName
      ? `<span class="bbt-modal__beer">${escHtml(beerName)}</span>`
      : '';
    const headerBatch = d.batch
      ? `<span class="bbt-modal__batch tanks-mono">${escHtml(d.batch)}</span>`
      : '';

    // Severity chip — label from recommended_action + severity, not type
    const severityLabel = {
      ok:       'Propre',
      warn:     'CIP recommandé',
      critical: 'CIP requis',
    }[d.severity] || d.severity;
    const severityClass = `bbt-modal__severity bbt-modal__severity--${escHtml(d.severity)}`;

    // Progress bar
    const sinceLastCip  = d.since_last_cip  ?? 0;
    const threshLeg     = d.threshold_acid  ?? 6;
    const isOverdue     = sinceLastCip >= threshLeg;
    const filledSegs    = Math.min(sinceLastCip, threshLeg);

    let segmentHtml = '';
    for (let i = 0; i < threshLeg; i++) {
      segmentHtml += `<span class="cip-seg${i < filledSegs ? ' cip-seg--filled' : ''}"></span>`;
    }

    const overdueNote = isOverdue
      ? ` <span class="cip-progress__overdue">en retard (${escHtml(String(sinceLastCip - threshLeg))} blend${sinceLastCip - threshLeg !== 1 ? 's' : ''})</span>`
      : '';

    // Dernier CIP
    const lastTypeLabel = d.last_cip_type === 'acid'
      ? 'CIP acide'
      : d.last_cip_type === 'full'
        ? 'CIP complet'
        : null;
    const dernierLine = lastTypeLabel
      ? `${escHtml(lastTypeLabel)} · ${escHtml(fmtDateDMY(d.last_cip_at))}`
      : 'aucun CIP enregistré';

    // Prochain CIP
    const nextLabel = (d.next_cip_type === 'full') ? 'CIP complet' : 'CIP acide';
    const blendsBefore = Math.max(0, threshLeg - sinceLastCip);
    let prochainLine;
    if (isOverdue) {
      prochainLine = `<strong>${escHtml(nextLabel)}</strong> — requis maintenant`;
    } else {
      prochainLine = `${escHtml(nextLabel)} · dans ${blendsBefore} blend${blendsBefore !== 1 ? 's' : ''}`;
    }

    // Cycle dots
    // leg 1 (last was full or none): acid = next (●), complet = later (◌)
    // leg 2 (last was acid):          acid = done (✓), complet = next (●)
    const onLeg2 = (d.last_cip_type === 'acid');
    const acidState  = onLeg2 ? 'done'  : 'next';
    const fullState  = onLeg2 ? 'next'  : 'later';
    const acidPip  = `<span class="cip-dot cip-dot--${acidState}"><span class="cip-dot__mark"></span><span class="cip-dot__label">acide</span></span>`;
    const arrowSep = `<span class="cip-dot__arrow">→</span>`;
    const fullPip  = `<span class="cip-dot cip-dot--${fullState}"><span class="cip-dot__mark"></span><span class="cip-dot__label">complet</span></span>`;

    return `
<button class="bbt-modal__close" id="bbt-modal-close" aria-label="Fermer" type="button">&#x2715;</button>

<div class="bbt-modal__head">
  <div class="bbt-modal__id">
    <span class="bbt-modal__id-label">BBT</span>
    <span class="bbt-modal__id-num">${escHtml(String(num))}</span>
  </div>
  <div class="bbt-modal__title-block">
    ${headerBeer}
    ${headerBatch}
  </div>
  <span class="${severityClass}">${escHtml(severityLabel)}</span>
</div>

<div class="bbt-modal__body">
  <div class="bbt-modal__progress-section">
    <span class="bbt-modal__stat-label">Depuis le dernier CIP</span>
    <div class="cip-progress">
      <div class="cip-progress__segs">${segmentHtml}</div>
      <span class="cip-progress__count">${escHtml(String(sinceLastCip))} / ${escHtml(String(threshLeg))} blends${overdueNote}</span>
    </div>
  </div>

  <dl class="bbt-modal__stats">
    <div class="bbt-modal__stat">
      <dt class="bbt-modal__stat-label">Dernier</dt>
      <dd class="bbt-modal__stat-value">${dernierLine}</dd>
    </div>
    <div class="bbt-modal__stat bbt-modal__stat--next">
      <dt class="bbt-modal__stat-label">Prochain</dt>
      <dd class="bbt-modal__stat-value">${prochainLine}</dd>
    </div>
  </dl>

  <div class="bbt-modal__cycle">
    <span class="bbt-modal__stat-label">Cycle</span>
    <div class="cip-cycle">
      ${acidPip}
      ${arrowSep}
      ${fullPip}
    </div>
  </div>
</div>`;
  }

  function openModal(num) {
    const details = window.BBT_CIP_DETAILS ? window.BBT_CIP_DETAILS[num] : null;
    const dialog  = document.getElementById('bbt-detail-modal');
    if (!dialog) return;

    const card = document.getElementById('bbt-modal-card');
    if (!card) return;

    if (!details) {
      card.innerHTML = `
<button class="bbt-modal__close" id="bbt-modal-close" aria-label="Fermer" type="button">&#x2715;</button>
<div class="bbt-modal__head">
  <div class="bbt-modal__id">
    <span class="bbt-modal__id-label">BBT</span>
    <span class="bbt-modal__id-num">${escHtml(String(num))}</span>
  </div>
</div>
<p class="bbt-modal__empty">Aucune donnée CIP disponible pour ce tank.</p>`;
    } else {
      card.innerHTML = renderCard(num, details);
    }

    // Wire close handlers
    const closeBtn = dialog.querySelector('#bbt-modal-close');
    if (closeBtn) closeBtn.addEventListener('click', () => dialog.close());
    const overlay = dialog.querySelector('[data-close]');
    if (overlay) overlay.addEventListener('click', () => dialog.close());

    dialog.showModal();
  }

  // Event delegation — open on [data-bbt] click
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-bbt]');
    if (!btn) return;
    openModal(+btn.dataset.bbt);
  });

  // ESC is handled natively by <dialog>

})();
