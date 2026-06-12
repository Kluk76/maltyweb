/**
 * repack.js — Reconditionnement advisory panel for ?view=repack.
 *
 * Handles:
 *   - "Confirmer la décomposition" button per order → POST all proposal rows
 *     for that order to /api/expeditions-repack.php
 *   - CSRF retry-once on expired token
 *   - 60s live auto-refresh (house preference: live/dynamic)
 *   - Optimistic UI: confirm button → loading state → done or error
 *
 * Reads:
 *   window.RKP_CSRF      — shared CSRF token (injected by expeditions.php)
 *   window.RKP_DATE      — ISO date string for the current day
 *   window.RKP_FLAG_LIVE — bool: whether depletion is live
 *
 * DOM contract (rendered by exp_render_repack_order_block in expeditions.php):
 *   .rkp-order-block[data-order-id]              — wrapper per order
 *     .rkp-proposal-row[data-from-sku-id, data-from-qty, data-to-sku-id,
 *                        data-to-qty, data-component-bottles, data-loose-units,
 *                        data-to-kind, data-site-id]
 *     button.rkp-confirm-btn[data-order-id, data-mode]
 */
(function () {
  'use strict';

  // ── State ───────────────────────────────────────────────────────────────────
  let csrfToken = (typeof window.RKP_CSRF !== 'undefined') ? window.RKP_CSRF : '';
  const rkpDate = (typeof window.RKP_DATE !== 'undefined') ? window.RKP_DATE : '';

  function updateCsrf(token) {
    csrfToken = token;
    document.querySelectorAll('input[name="csrf"]').forEach(function (el) {
      el.value = token;
    });
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Collect proposal rows for an order block ────────────────────────────────
  function collectProposalRows(orderBlock) {
    const rows = [];
    orderBlock.querySelectorAll('.rkp-proposal-row').forEach(function (tr) {
      rows.push({
        from_sku_id:       parseInt(tr.dataset.fromSkuId, 10)  || 0,
        from_qty:          parseInt(tr.dataset.fromQty, 10)    || 0,
        to_sku_id:         parseInt(tr.dataset.toSkuId, 10)    || 0,
        to_qty:            parseInt(tr.dataset.toQty, 10)      || 0,
        component_bottles: parseInt(tr.dataset.componentBottles, 10) || 0,
        loose_units:       parseInt(tr.dataset.looseUnits, 10) || 0,
        to_kind:           tr.dataset.toKind || 'bundle',
        site_id:           parseInt(tr.dataset.siteId, 10)     || 0,
      });
    });
    return rows;
  }

  // ── POST to /api/expeditions-repack.php ────────────────────────────────────
  function postRepackConfirm(orderId, mode, rows, retrying) {
    return fetch('/api/expeditions-repack.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf:       csrfToken,
        order_id:   orderId,
        mode:       mode,
        moved_on:   rkpDate,
        rows:       rows,
      }),
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (!data.ok && data.reason === 'expired' && !retrying) {
        // Retry once with fresh CSRF
        updateCsrf(data.csrf);
        return postRepackConfirm(orderId, mode, rows, true);
      }
      return data;
    });
  }

  // ── Wire confirm buttons ────────────────────────────────────────────────────
  function wireConfirmButtons() {
    document.querySelectorAll('.rkp-confirm-btn').forEach(function (btn) {
      if (btn.dataset.wired) return;
      btn.dataset.wired = '1';

      btn.addEventListener('click', function () {
        if (btn.disabled) return;

        const orderId   = parseInt(btn.dataset.orderId, 10) || 0;
        const mode      = btn.dataset.mode || 'delivery';
        const orderBlock = btn.closest('.rkp-order-block');
        if (!orderBlock) return;

        const rows = collectProposalRows(orderBlock);
        if (!rows.length) return;

        // Optimistic loading state
        btn.classList.add('rkp-confirm-btn--loading');
        btn.disabled = true;
        btn.textContent = '…';

        postRepackConfirm(orderId, mode, rows, false)
          .then(function (data) {
            if (data.ok) {
              if (data.csrf) updateCsrf(data.csrf);
              btn.classList.remove('rkp-confirm-btn--loading');
              btn.classList.add('rkp-confirm-btn--done');
              btn.textContent = '✓ Enregistré';
            } else {
              btn.classList.remove('rkp-confirm-btn--loading');
              btn.disabled = false;
              btn.textContent = 'Confirmer la décomposition';
              const errMsg = escHtml(data.error || 'Erreur inconnue');
              const errEl = document.createElement('span');
              errEl.className = 'rkp-post-error';
              errEl.setAttribute('role', 'alert');
              errEl.innerHTML = '⚠ ' + errMsg;
              // Insert after button, replace any previous error
              const prev = orderBlock.querySelector('.rkp-post-error');
              if (prev) prev.remove();
              btn.insertAdjacentElement('afterend', errEl);
            }
          })
          .catch(function (err) {
            btn.classList.remove('rkp-confirm-btn--loading');
            btn.disabled = false;
            btn.textContent = 'Confirmer la décomposition';
            console.error('[repack] POST failed:', err);
          });
      });
    });
  }

  // ── 60s live auto-refresh ───────────────────────────────────────────────────
  // Only refresh when the tab is visible (house preference: dynamic but not wasteful).
  function startAutoRefresh() {
    let refreshTimer = null;

    function scheduleRefresh() {
      if (refreshTimer) clearTimeout(refreshTimer);
      refreshTimer = setTimeout(function () {
        if (!document.hidden) {
          window.location.reload();
        } else {
          scheduleRefresh();
        }
      }, 60000);
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        // Tab became visible — reschedule from now
        scheduleRefresh();
      }
    });

    scheduleRefresh();
  }

  // ── Init ────────────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    wireConfirmButtons();
    startAutoRefresh();
  });

  // Also wire immediately if DOMContentLoaded already fired
  if (document.readyState !== 'loading') {
    wireConfirmButtons();
    startAutoRefresh();
  }

})();
