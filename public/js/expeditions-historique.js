/**
 * expeditions-historique.js — Historique tab drill-down toggle.
 *
 * Each client row has a toggle button (aria-expanded) that shows/hides
 * the per-SKU drill panel. Pure progressive enhancement: the drill panel
 * is `hidden` server-side and this script wires the expand/collapse.
 *
 * Pattern mirrors the existing SKU-history drill in expeditions-stock.js:
 * aria-expanded + aria-controls + the [hidden] attribute on the target panel.
 * The toggle icon rotates via CSS (▸ → ▾) driven by aria-expanded.
 */
(function () {
  'use strict';

  function init() {
    var wrap = document.getElementById('exp-hist-wrap');
    if (!wrap) return;

    // Delegate click to a single listener on the wrap — works for any
    // number of week blocks and avoids per-button listener churn.
    wrap.addEventListener('click', function (e) {
      var btn = e.target.closest('.exp-hist-client-btn');
      if (!btn) return;

      var expanded   = btn.getAttribute('aria-expanded') === 'true';
      var controlsId = btn.getAttribute('aria-controls');
      if (!controlsId) return;

      var panel = document.getElementById(controlsId);
      if (!panel) return;

      var newExpanded = !expanded;
      btn.setAttribute('aria-expanded', String(newExpanded));

      if (newExpanded) {
        panel.removeAttribute('hidden');
      } else {
        panel.setAttribute('hidden', '');
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
