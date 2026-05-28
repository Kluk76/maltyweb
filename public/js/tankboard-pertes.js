/* tankboard-pertes.js — Pertes par batch (C8): drill-in row toggle + "Tous" button.
 *
 * Click idiom: event delegation on document, same pattern as cct-detail-modal.js
 * which uses document.addEventListener('click', fn) + e.target.closest('[data-cct]').
 * Here we use [data-ppb-expand] for the batch identity cell.
 *
 * The "Tous" toggle adds/removes .ppb-section--show-all on the <section> element,
 * which CSS uses to flip display on .ppb-row--hidden and .ppb-detail-row--hidden.
 * No reload needed — all rows are pre-rendered server-side.
 */
'use strict';

(function () {

  // ── Drill-in toggle ─────────────────────────────────────────────────────────
  // Delegate on document (same pattern as [data-cct] in cct-detail-modal.js).
  // Uses CSS class .ppb-detail-row--collapsed (not the HTML hidden attribute)
  // so that CSS-driven visibility (.ppb-row--hidden / .ppb-section--show-all)
  // can coexist without specificity conflicts with [hidden].
  document.addEventListener('click', function (e) {
    const cell = e.target.closest('[data-ppb-expand]');
    if (!cell) return;

    const rowId     = cell.dataset.ppbExpand;          // e.g. "ppb-row-0"
    const detailRow = document.getElementById('ppb-detail-' + rowId);
    if (!detailRow) return;

    const isExpanded = cell.getAttribute('aria-expanded') === 'true';

    if (isExpanded) {
      cell.setAttribute('aria-expanded', 'false');
      detailRow.classList.add('ppb-detail-row--collapsed');
    } else {
      cell.setAttribute('aria-expanded', 'true');
      detailRow.classList.remove('ppb-detail-row--collapsed');
    }
  });

  // Keyboard support: Enter / Space on expand cells (a11y).
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const cell = e.target.closest('[data-ppb-expand]');
    if (!cell) return;
    e.preventDefault();
    cell.click();
  });

  // ── "Tous les batches" toggle ───────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-ppb-toggle-all]');
    if (!btn) return;

    const sectionId = btn.dataset.ppbToggleAll;
    const section   = document.getElementById(sectionId);
    if (!section) return;

    const isShowingAll = section.classList.toggle('ppb-section--show-all');
    btn.setAttribute('aria-pressed', isShowingAll ? 'true' : 'false');
    // Update button label for clarity
    btn.textContent = isShowingAll ? '20 derniers' : 'Tous les batches';
  });

})();
