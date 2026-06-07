/**
 * expeditions.js — Commandes view JS for Expéditions module.
 *
 * Reads window.EXP_CSRF (injected in the Commandes view by expeditions.php).
 *
 * Responsibilities:
 *   - Wire status chip buttons → POST /api/expeditions-status.php
 *   - Optimistic UI update on success (chip state + action buttons)
 *   - Error rollback + user-readable inline notice
 *   - CSRF auto-retry once on 'expired' response
 *
 * Vanilla JS only — no external libraries.
 */

'use strict';

(function () {

  let currentCsrf = window.EXP_CSRF || '';

  /* ── Status rank map — mirrors PHP EXP_STATUS_RANK ───────────────────── */
  const STATUS_RANK = {
    entered:    0,
    confirmed:  1,
    picked:     2,
    bl_printed: 3,
    shipped:    4,
    cancelled:  -1,
  };

  const STATUS_LABELS = {
    entered:    'Saisie',
    confirmed:  'Confirmée',
    picked:     'Préparée',
    bl_printed: 'BL imprimé',
    shipped:    'Livrée',
    cancelled:  'Annulée',
  };

  const STAGE_ORDER = ['confirmed', 'picked', 'bl_printed', 'shipped'];

  /* ── Utility ────────────────────────────────────────────────────────────── */
  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#x27;');
  }

  /* ── Determine current order status from its progress chips ────────────── */
  function orderStatusFromRow(orderRow) {
    const chips = orderRow.querySelectorAll('.exp-chip[data-status]');
    let curRank = 0; // default: entered
    let curStatus = 'entered';
    chips.forEach(function (chip) {
      if (chip.classList.contains('exp-chip--done')) {
        const st = chip.dataset.status;
        const r  = STATUS_RANK[st] !== undefined ? STATUS_RANK[st] : -1;
        if (r > curRank) { curRank = r; curStatus = st; }
      }
    });
    return curStatus;
  }

  /* ── Optimistic chip update ─────────────────────────────────────────────── */
  function applyStatusToRow(orderRow, newStatus) {
    const newRank = STATUS_RANK[newStatus] !== undefined ? STATUS_RANK[newStatus] : -1;

    if (newStatus === 'cancelled') {
      const prog = orderRow.querySelector('.exp-progress');
      if (prog) {
        prog.innerHTML = '<span class="exp-chip exp-chip--cancelled">Annulée</span>';
      }
      // Hide cancel button
      const cancelBtn = orderRow.querySelector('.exp-action-btn--cancel');
      if (cancelBtn) cancelBtn.hidden = true;
      return;
    }

    // Update each stage chip
    const chips = orderRow.querySelectorAll('.exp-chip[data-status]');
    chips.forEach(function (chip) {
      const stStatus = chip.dataset.status;
      const stRank   = STATUS_RANK[stStatus] !== undefined ? STATUS_RANK[stStatus] : 0;
      const isDone   = newRank >= stRank;
      const isNext   = !isDone && (newRank === stRank - 1);

      chip.classList.toggle('exp-chip--done', isDone);
      chip.classList.toggle('exp-chip--next', isNext);

      chip.disabled = isDone || !isNext;
      chip.setAttribute('aria-disabled', String(isDone || !isNext));

      if (isDone) {
        chip.setAttribute('aria-label', '✓ ' + (STATUS_LABELS[stStatus] || stStatus) + ' — fait');
      } else if (isNext) {
        chip.setAttribute('aria-label', 'Marquer : ' + (STATUS_LABELS[stStatus] || stStatus));
      } else {
        chip.setAttribute('aria-label', STATUS_LABELS[stStatus] || stStatus);
      }

      // Update text: show ✓ on done stages
      chip.textContent = (isDone ? '✓ ' : '') + (STATUS_LABELS[stStatus] || stStatus);
    });

    // If shipped, hide cancel button
    if (newStatus === 'shipped') {
      const cancelBtn = orderRow.querySelector('.exp-action-btn--cancel');
      if (cancelBtn) cancelBtn.hidden = true;
    }
  }

  /* ── POST helper ────────────────────────────────────────────────────────── */
  function postStatus(orderId, action, csrf, callback) {
    fetch('/api/expeditions-status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ csrf: csrf, order_id: orderId, action: action }),
    })
      .then(function (res) { return res.json(); })
      .then(function (data) { callback(null, data); })
      .catch(function (err) { callback(err, null); });
  }

  /* ── Inline error notice ────────────────────────────────────────────────── */
  function showOrderError(orderRow, msg) {
    let notice = orderRow.querySelector('.exp-inline-err');
    if (!notice) {
      notice = document.createElement('span');
      notice.className = 'exp-inline-err';
      const actions = orderRow.querySelector('.exp-order-actions');
      if (actions) actions.appendChild(notice); else orderRow.appendChild(notice);
    }
    notice.textContent = msg;
    // Auto-clear after 5 s
    clearTimeout(notice._timer);
    notice._timer = setTimeout(function () { notice.textContent = ''; }, 5000);
  }

  /* ── Handle status button clicks ────────────────────────────────────────── */
  document.addEventListener('click', function (e) {
    // ── SKU pill "+N more" expand ────────────────────────────────────────── //
    const moreBtn = e.target.closest('.exp-sku-more');
    if (moreBtn) {
      const hiddenHtml = moreBtn.getAttribute('data-hidden-pills') || '';
      const pills = moreBtn.closest('.exp-sku-pills');
      if (pills) {
        // Insert hidden pills before the +N button, then remove the button
        const temp = document.createElement('span');
        temp.innerHTML = hiddenHtml;
        while (temp.firstChild) {
          pills.insertBefore(temp.firstChild, moreBtn);
        }
        moreBtn.remove();
      }
      return;
    }

    // ── Cancelled toggle ─────────────────────────────────────────────────── //
    const toggleBtn = e.target.closest('#exp-toggle-cancelled');
    if (toggleBtn) {
      const isShowing = toggleBtn.getAttribute('aria-pressed') === 'true';
      const rows = document.querySelectorAll('.exp-order-row--cancelled');
      rows.forEach(function (r) {
        r.hidden = isShowing;
      });
      toggleBtn.setAttribute('aria-pressed', String(!isShowing));
      toggleBtn.textContent = isShowing ? 'Afficher annulées' : 'Masquer annulées';
      return;
    }

    // ── Status chips / cancel action ─────────────────────────────────────── //
    const btn = e.target.closest('[data-action][data-order-id]');
    if (!btn) return;

    const action  = btn.dataset.action;
    const orderId = parseInt(btn.dataset.orderId || btn.getAttribute('data-order-id'), 10);
    if (!orderId || !action) return;

    const orderRow = btn.closest('.exp-order-row');
    if (!orderRow) return;

    // Disable all action buttons on this row while the request is in flight
    const actionBtns = orderRow.querySelectorAll('[data-action]');
    actionBtns.forEach(function (b) { b.disabled = true; });

    function doRequest(csrf) {
      postStatus(orderId, action, csrf, function (err, data) {
        // Re-enable buttons
        actionBtns.forEach(function (b) { b.disabled = false; });

        if (err) {
          showOrderError(orderRow, 'Erreur réseau — réessaie.');
          return;
        }

        if (!data.ok) {
          // CSRF expired: retry once with fresh token
          if (data.reason === 'expired' && data.csrf) {
            currentCsrf = data.csrf;
            doRequest(currentCsrf);
            return;
          }
          showOrderError(orderRow, data.error || 'Erreur inconnue.');
          return;
        }

        // Success: update CSRF + apply UI
        if (data.csrf) currentCsrf = data.csrf;
        applyStatusToRow(orderRow, data.status);

        // After cancel: mark row as cancelled for the toggle to work
        if (data.status === 'cancelled') {
          orderRow.classList.add('exp-order-row--cancelled');
          orderRow.setAttribute('data-status', 'cancelled');
          // If toggle is currently hiding cancelled rows, hide this row too
          const tb = document.getElementById('exp-toggle-cancelled');
          if (tb && tb.getAttribute('aria-pressed') === 'false') {
            orderRow.hidden = true;
          }
        }
      });
    }

    doRequest(currentCsrf);
  });

  /* ── Range date auto-fill: Au = Du when Au is empty ───────────────────────── */
  (function () {
    var duInput = document.getElementById('exp-range-du');
    var auInput = document.getElementById('exp-range-au');
    if (!duInput || !auInput) return;
    duInput.addEventListener('change', function () {
      if (!auInput.value || auInput.value < duInput.value) {
        auInput.value = duInput.value;
      }
    });
  }());

  /* ── Cancelled rows: start hidden ───────────────────────────────────────── */
  (function () {
    var rows = document.querySelectorAll('.exp-order-row--cancelled');
    rows.forEach(function (r) { r.hidden = true; });
  }());

})();
