/**
 * expeditions-side-stock.js — Restes d'emballage (side-stock) view live refresh.
 *
 * Live-refresh per "maltyweb prefers live/dynamic visibility" standing order.
 * Reloads the page every 60 s while visible; reloads immediately on tab focus
 * if ≥30 s have elapsed since last load.
 *
 * Also: double-submit guard on the giveaway form.
 * CSS in /css/expeditions.css (body.expeditions .exp-ssl-*).
 */

(function () {
  'use strict';

  const REFRESH_INTERVAL_MS = 60_000;  // 60 s
  const FOCUS_STALE_MS      = 30_000;  // re-fetch on focus if older than 30 s

  let lastLoadTime = Date.now();
  let refreshTimer = null;

  function refreshPage () {
    window.location.reload();
  }

  function armTimer () {
    clearTimeout(refreshTimer);
    if (!document.hidden) {
      refreshTimer = setTimeout(refreshPage, REFRESH_INTERVAL_MS);
    }
  }

  // Reload on visibility change (tab back-to-foreground).
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      const age = Date.now() - lastLoadTime;
      if (age >= FOCUS_STALE_MS) {
        refreshPage();
        return;
      }
      armTimer();
    } else {
      clearTimeout(refreshTimer);
    }
  });

  // Arm the auto-refresh timer on page load.
  armTimer();

  // ── Double-submit guard on the giveaway form ─────────────────────────────
  // Prevents accidental double-bank on a slow network or double-click.
  const giveForm = document.getElementById('exp-ssl-give-form');
  const giveBtn  = document.getElementById('ssl-give-submit');
  if (giveForm && giveBtn) {
    giveForm.addEventListener('submit', function () {
      giveBtn.disabled = true;
      giveBtn.textContent = 'Envoi en cours…';
      // Re-enable after 8 s in case the server never responds (network error).
      setTimeout(function () {
        giveBtn.disabled = false;
        giveBtn.textContent = 'Enregistrer le giveaway';
      }, 8000);
    });
  }

  // ── CSRF sync from global keepalive (form-resilience.js) ─────────────────
  // form-resilience.js calls updateAllCsrfInputs() on session ping; this page's
  // hidden csrf fields are thus kept fresh automatically — no extra wiring needed.

}());
