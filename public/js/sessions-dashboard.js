/**
 * sessions-dashboard.js — Journal de bord interactive behaviours.
 *
 * Responsibilities:
 *   1. View toggle: Direction C (timeline) ↔ Direction B (vessels).
 *      Persisted via localStorage key `sessions-dashboard-view`.
 *   2. Abandoned toggle: show/hide sd-entry.status-abandoned rows.
 *   3. Clock: live time display in topstrip (updates every minute).
 *
 * Read-only page — no fetches, no writes. All data server-rendered.
 * No inline event handlers in PHP (all wired here via querySelectorAll).
 */

(function () {
  'use strict';

  const LS_VIEW_KEY    = 'sessions-dashboard-view';
  const DEFAULT_VIEW   = 'timeline';  // 'timeline' | 'vessels'

  // ── 1. View toggle ─────────────────────────────────────────────────────────

  const panelTimeline= document.getElementById('sd-panel-timeline');
  const panelVessels = document.getElementById('sd-panel-vessels');
  const btnTimeline  = document.getElementById('sd-btn-timeline');
  const btnVessels   = document.getElementById('sd-btn-vessels');

  function applyView(view) {
    const isTimeline = (view !== 'vessels');

    if (panelTimeline) panelTimeline.hidden = !isTimeline;
    if (panelVessels)  panelVessels.hidden  =  isTimeline;
    if (btnTimeline)   btnTimeline.classList.toggle('active',  isTimeline);
    if (btnVessels)    btnVessels.classList.toggle('active',  !isTimeline);

    try { localStorage.setItem(LS_VIEW_KEY, isTimeline ? 'timeline' : 'vessels'); }
    catch (_) { /* storage blocked */ }
  }

  // Read persisted preference.
  let savedView = DEFAULT_VIEW;
  try { savedView = localStorage.getItem(LS_VIEW_KEY) || DEFAULT_VIEW; }
  catch (_) { /* ignore */ }

  // Server-side ?view= param takes precedence (allows deep-linking).
  const urlView = (new URLSearchParams(window.location.search)).get('view');
  applyView(urlView || savedView);

  if (btnTimeline) btnTimeline.addEventListener('click', function () { applyView('timeline'); });
  if (btnVessels)  btnVessels.addEventListener('click',  function () { applyView('vessels');  });

  // ── 2. Abandoned toggle ───────────────────────────────────────────────────

  const abandonedCb = document.getElementById('sd-show-abandoned');
  if (abandonedCb) {
    function applyAbandonedFilter() {
      const show = abandonedCb.checked;
      const els = document.querySelectorAll('.sd-entry.status-abandoned, .sd-vessel-card.abandoned');
      els.forEach(function (el) { el.hidden = !show; });

      // Server-side filter strips abandoned rows when ?show_abandoned=0 (default).
      // So when the operator ticks the checkbox but no rows are in the DOM to show,
      // the only honest behaviour is to reload with the flag set — pure JS show would
      // do nothing visible and confuse the operator.
      if (show && els.length === 0) {
        const form = abandonedCb.closest('form');
        if (form) form.submit();
      }
    }

    // Initialise from checkbox state (server renders it checked/unchecked based on GET).
    applyAbandonedFilter();
    abandonedCb.addEventListener('change', applyAbandonedFilter);
  }

  // ── 3. Live clock ─────────────────────────────────────────────────────────

  const clockEl = document.getElementById('sd-clock');
  function updateClock() {
    if (!clockEl) return;
    const now = new Date();
    const hh  = String(now.getHours()).padStart(2, '0');
    const mm  = String(now.getMinutes()).padStart(2, '0');
    clockEl.textContent = hh + ':' + mm;
  }
  updateClock();
  setInterval(updateClock, 30000);

})();
