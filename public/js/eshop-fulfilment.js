/**
 * eshop-fulfilment.js — Phase 2A eshop fulfilment workflow chips.
 *
 * Handles chip clicks → POST to /api/eshop-fulfilment-status.php,
 * CSRF retry-once, optimistic chip/badge DOM update.
 *
 * Reads:
 *   window.EXP_CSRF  — shared CSRF token (set by expeditions.php commandes view)
 *
 * DOM contract (rendered by exp_render_eshop_chips in expeditions.php):
 *   .ef-chips[data-eshop-order-id]  — chips container per order
 *     button.ef-chip[data-action, data-eshop-order-id]  — action chip
 *   .ef-sync-badge[data-eshop-order-id]  — sync badge
 */
(function () {
  'use strict';

  // ── CSRF state ──────────────────────────────────────────────────────────────
  let csrfToken = (typeof window.EXP_CSRF !== 'undefined') ? window.EXP_CSRF : '';

  // Keep in sync with global keepalive rewrites
  function updateCsrf(token) {
    csrfToken = token;
    // Also rewrite any hidden inputs so PRG forms stay in sync
    document.querySelectorAll('input[name="csrf"]').forEach(function (el) {
      el.value = token;
    });
  }

  // ── Status labels (mirrors PHP ESHOP_FULFIL_LABELS) ─────────────────────────
  const LABELS = {
    new:               'Nouveau',
    picking:           'En préparation',
    picked:            'Préparé',
    ready_for_pickup:  'Prêt au retrait',
    fulfilled:         'Expédié',
    picked_up:         'Remis',
    cancelled:         'Annulé',
  };

  // ── Mode-aware advance maps (mirrors PHP constants) ──────────────────────────
  const ADVANCE_DELIVERY = {
    new:     'picking',
    picking: 'picked',
    picked:  'fulfilled',
  };
  const ADVANCE_PICKUP = {
    new:              'picking',
    picking:          'picked',
    picked:           'ready_for_pickup',
    ready_for_pickup: 'picked_up',
  };
  const REVERT_MAP = {
    picking:           'new',
    picked:            'picking',
    ready_for_pickup:  'picked',
    fulfilled:         'picked',
    picked_up:         'ready_for_pickup',
  };
  const TERMINALS = ['fulfilled', 'picked_up', 'cancelled'];
  const PUSH_TRIGGERS = ['fulfilled', 'ready_for_pickup', 'picked_up'];

  // ── Sync badge labels ────────────────────────────────────────────────────────
  const SYNC_LABELS = {
    idle:    '',
    pending: '⟳ à pousser',
    pushed:  '✓ Shopify',
    failed:  '⚠ erreur',
  };

  // ── Build chips HTML for a given status + mode ───────────────────────────────
  function buildChipsHtml(eshopOrderId, status, mode) {
    const eid = eshopOrderId;
    if (status === 'cancelled') {
      return '<span class="ef-chip ef-chip--cancelled">Annulé</span>';
    }

    const advMap = (mode === 'pickup') ? ADVANCE_PICKUP : ADVANCE_DELIVERY;
    const stages = (mode === 'pickup')
      ? ['picking', 'picked', 'ready_for_pickup', 'picked_up']
      : ['picking', 'picked', 'fulfilled'];

    let html = '';
    // Build advance stages
    for (const stage of stages) {
      const isPast = isPastStatus(status, stage, mode);
      const isNext = advMap[status] === stage;
      const isDone = isPast && !isNext;
      const isCurrent = status === stage;
      let cls = 'ef-chip ef-chip--' + stage;
      if (isCurrent || isPast) cls += ' ef-chip--done';
      if (isNext) cls += ' ef-chip--next';
      const lbl = LABELS[stage] || stage;
      const aria = isCurrent ? ('✓ ' + lbl + ' — fait')
                 : isNext    ? ('Marquer : ' + lbl)
                 : (isDone   ? ('✓ ' + lbl) : lbl);
      const dis = (!isNext) ? ' disabled aria-disabled="true"' : '';
      const tagContent = (isCurrent || isPast ? '✓ ' : '') + escHtml(lbl);
      if (isNext) {
        html += '<button class="' + escHtml(cls) + '" data-eshop-order-id="' + eid
          + '" data-action="advance" data-target-status="' + escHtml(stage)
          + '"' + dis + ' aria-label="' + escHtml(aria) + '">' + tagContent + '</button>';
      } else {
        html += '<span class="' + escHtml(cls) + '" aria-label="' + escHtml(aria) + '">'
          + tagContent + '</span>';
      }
    }

    // Revert chip (if not at start)
    if (status !== 'new' && !TERMINALS.includes(status)) {
      const prevSt = REVERT_MAP[status];
      if (prevSt) {
        html += '<button class="ef-chip ef-chip--revert" data-eshop-order-id="' + eid
          + '" data-action="revert" aria-label="Retour à ' + escHtml(LABELS[prevSt] || prevSt) + '">'
          + '↩ ' + escHtml(LABELS[prevSt] || prevSt) + '</button>';
      }
    }

    // Cancel chip (if not terminal)
    if (!TERMINALS.includes(status)) {
      html += '<button class="ef-chip ef-chip--cancel" data-eshop-order-id="' + eid
        + '" data-action="cancel" aria-label="Annuler la commande">✕</button>';
    }

    return html;
  }

  // Helper: is 'stage' before or equal to current 'status' in the pipeline?
  function isPastStatus(status, stage, mode) {
    const order = (mode === 'pickup')
      ? ['new', 'picking', 'picked', 'ready_for_pickup', 'picked_up']
      : ['new', 'picking', 'picked', 'fulfilled'];
    return order.indexOf(status) >= order.indexOf(stage);
  }

  // ── Update the sync badge DOM ────────────────────────────────────────────────
  function updateSyncBadge(eshopOrderId, syncState) {
    const badge = document.querySelector('.ef-sync-badge[data-eshop-order-id="' + eshopOrderId + '"]');
    if (!badge) return;
    badge.className = 'ef-sync-badge ef-sync-badge--' + (syncState || 'idle');
    badge.textContent = SYNC_LABELS[syncState] || '';
  }

  // ── Post action to API ───────────────────────────────────────────────────────
  async function postAction(eshopOrderId, action, chipsContainer, mode) {
    // Optimistic: dim chips while in-flight
    chipsContainer.classList.add('ef-chip--loading');

    let retried = false;

    async function attempt(csrf) {
      const res = await fetch('/api/eshop-fulfilment-status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          csrf:           csrf,
          eshop_order_id: eshopOrderId,
          action:         action,
        }),
      });
      return res.json();
    }

    try {
      let json = await attempt(csrfToken);

      // CSRF retry once
      if (!json.ok && json.reason === 'expired' && !retried) {
        retried = true;
        if (json.csrf) updateCsrf(json.csrf);
        json = await attempt(csrfToken);
      }

      if (!json.ok) {
        chipsContainer.classList.remove('ef-chip--loading');
        // Show error message briefly
        const errSpan = document.createElement('span');
        errSpan.className = 'ef-classify-hint';
        errSpan.textContent = json.error || 'Erreur';
        chipsContainer.innerHTML = '';
        chipsContainer.appendChild(errSpan);
        // Reload chips from DOM after 2s
        setTimeout(function () {
          location.reload();
        }, 2000);
        return;
      }

      // Success: update CSRF, rebuild chips, update sync badge
      if (json.csrf) updateCsrf(json.csrf);

      const newStatus = json.status;
      chipsContainer.innerHTML = buildChipsHtml(eshopOrderId, newStatus, mode);
      chipsContainer.classList.remove('ef-chip--loading');
      updateSyncBadge(eshopOrderId, json.shopify_sync || 'idle');

    } catch (err) {
      chipsContainer.classList.remove('ef-chip--loading');
      console.error('[eshop-fulfilment] fetch error:', err);
    }
  }

  // ── Escape helper ────────────────────────────────────────────────────────────
  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // ── Event delegation — attach to document once ──────────────────────────────
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('button.ef-chip[data-eshop-order-id][data-action]');
    if (!btn || btn.disabled) return;

    const eshopOrderId = parseInt(btn.dataset.eshopOrderId, 10);
    const action       = btn.dataset.action;
    if (!eshopOrderId || !action) return;

    // Find chips container + mode
    const container = btn.closest('.ef-chips[data-eshop-order-id]');
    if (!container) return;
    const mode = container.dataset.mode || 'delivery';

    e.preventDefault();
    if (action === 'classify') {
      // Mode comes from the button's own data-mode (container has none for classify)
      postClassify(eshopOrderId, btn.dataset.mode, container);
    } else {
      postAction(eshopOrderId, action, container, mode);
    }
  });

  // ── Classify (review → pickup|delivery) ─────────────────────────────────────
  async function postClassify(eshopOrderId, mode, container) {
    container.classList.add('ef-chip--loading');

    let retried = false;

    async function attempt(csrf) {
      const res = await fetch('/api/eshop-fulfilment-status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          csrf:           csrf,
          eshop_order_id: eshopOrderId,
          action:         'classify',
          mode:           mode,
        }),
      });
      return res.json();
    }

    try {
      let json = await attempt(csrfToken);

      // CSRF retry once
      if (!json.ok && json.reason === 'expired' && !retried) {
        retried = true;
        if (json.csrf) updateCsrf(json.csrf);
        json = await attempt(csrfToken);
      }

      if (!json.ok) {
        container.classList.remove('ef-chip--loading');
        const errSpan = document.createElement('span');
        errSpan.className = 'ef-classify-hint';
        errSpan.textContent = json.error || 'Erreur';
        container.innerHTML = '';
        container.appendChild(errSpan);
        setTimeout(function () { location.reload(); }, 2000);
        return;
      }

      // Success: update CSRF then reload so card re-renders with full workflow chips
      if (json.csrf) updateCsrf(json.csrf);
      location.reload();

    } catch (err) {
      container.classList.remove('ef-chip--loading');
      console.error('[eshop-fulfilment] classify error:', err);
    }
  }

})();
