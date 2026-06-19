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

  /* ── POST helper for set_comment (carries extra `comment` field) ───────── */
  function postWithComment(orderId, comment, csrf, callback) {
    fetch('/api/expeditions-status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ csrf: csrf, order_id: orderId, action: 'set_comment', comment: comment }),
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

    // ── Comment edit: open inline editor ─────────────────────────────────── //
    if (action === 'set_comment') {
      const editBtn = btn;
      const commentSpan = editBtn.closest('.exp-order-comment');
      if (!commentSpan) return;
      // Prevent opening a second editor
      if (commentSpan.querySelector('.exp-comment-editor')) return;

      const currentComment = editBtn.dataset.currentComment || '';

      const editor = document.createElement('div');
      editor.className = 'exp-comment-editor';
      editor.innerHTML =
        '<textarea class="exp-comment-textarea" maxlength="2000" rows="2" placeholder="Commentaire…">'
        + escHtml(currentComment)
        + '</textarea>'
        + '<div class="exp-comment-editor-actions">'
        +   '<button type="button" class="exp-comment-save-btn">Sauvegarder</button>'
        +   '<button type="button" class="exp-comment-cancel-btn">Annuler</button>'
        + '</div>';

      // Hide the edit button + text while editing
      editBtn.hidden = true;
      const textEl = commentSpan.querySelector('.exp-order-comment__text');
      if (textEl) textEl.hidden = true;

      commentSpan.appendChild(editor);

      const textarea  = editor.querySelector('.exp-comment-textarea');
      const saveBtn   = editor.querySelector('.exp-comment-save-btn');
      const cancelBtn = editor.querySelector('.exp-comment-cancel-btn');

      // Focus textarea and place cursor at end
      textarea.focus();
      textarea.setSelectionRange(textarea.value.length, textarea.value.length);

      function closeEditor(restoreText) {
        editor.remove();
        editBtn.hidden = false;
        // restoreText=true only on Cancel — on Save the caller already set correct visibility
        if (restoreText && textEl) textEl.hidden = false;
      }

      cancelBtn.addEventListener('click', function () { closeEditor(true); });

      saveBtn.addEventListener('click', function () {
        const newComment = textarea.value.trim();
        saveBtn.disabled = true;
        cancelBtn.disabled = true;

        function doSave(csrf) {
          postWithComment(orderId, newComment, csrf, function (err, data) {
            if (err) {
              saveBtn.disabled = false;
              cancelBtn.disabled = false;
              showOrderError(orderRow, 'Erreur réseau — réessaie.');
              return;
            }
            if (!data.ok) {
              if (data.reason === 'expired' && data.csrf) {
                currentCsrf = data.csrf;
                doSave(currentCsrf);
                return;
              }
              saveBtn.disabled = false;
              cancelBtn.disabled = false;
              showOrderError(orderRow, data.error || 'Erreur inconnue.');
              return;
            }
            // Success
            if (data.csrf) currentCsrf = data.csrf;
            const savedComment = data.comment || '';

            // Update data-current-comment on the edit button
            editBtn.setAttribute('data-current-comment', savedComment);

            // Update displayed text
            const MAX = 40;
            const truncated = savedComment.length > MAX ? savedComment.slice(0, MAX) + '…' : savedComment;

            if (textEl) {
              textEl.textContent = truncated;
              textEl.setAttribute('title', savedComment);
            }

            // Update icon placeholder visibility
            const iconPlaceholder = commentSpan.querySelector('.exp-order-comment__icon--placeholder');
            if (iconPlaceholder) iconPlaceholder.remove();
            if (savedComment && !commentSpan.querySelector('.exp-order-comment__icon:not(.exp-order-comment__icon--placeholder)')) {
              const icon = document.createElement('span');
              icon.className = 'exp-order-comment__icon';
              icon.setAttribute('aria-hidden', 'true');
              icon.textContent = '💬';
              commentSpan.insertBefore(icon, commentSpan.firstChild);
            }

            // Update the --empty class
            commentSpan.classList.toggle('exp-order-comment--empty', !savedComment);

            if (textEl) {
              if (savedComment) textEl.hidden = false;
              else textEl.hidden = true;
            } else if (savedComment) {
              // No textEl yet (was empty before), create it
              const newTextEl = document.createElement('span');
              newTextEl.className = 'exp-order-comment__text';
              newTextEl.textContent = truncated;
              newTextEl.setAttribute('title', savedComment);
              commentSpan.insertBefore(newTextEl, editBtn);
            }

            closeEditor(false);
          });
        }

        doSave(currentCsrf);
      });

      // Keyboard: Ctrl+Enter to save, Escape to cancel
      textarea.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { closeEditor(); ev.preventDefault(); }
        if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter') { saveBtn.click(); }
      });

      return; // don't fall through to status-advance logic
    }

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

  /* ── Stock-risk detail modal ───────────────────────────────────────────── */
  (function () {
    var modal = document.getElementById('exp-stock-detail-modal');
    if (!modal || !modal.showModal) return; // guard: <dialog> not supported

    var detail = window.EXP_CMD_STOCK_DETAIL || {};

    // Close helpers
    function closeModal() { modal.close(); }

    // ✕ button and backdrop close — wired once
    modal.addEventListener('click', function (e) {
      // Close on ✕ button
      if (e.target.closest('.exp-sdm-close')) { closeModal(); return; }
      // Close on backdrop (click landed directly on the dialog element, not its content)
      if (e.target === modal) { closeModal(); }
    });
    // Belt+suspenders: Escape (browsers fire 'cancel' natively; wire 'cancel' too)
    modal.addEventListener('cancel', function () { closeModal(); });

    // Build modal content for a given order
    function buildContent(oid, items) {
      var rows = '';
      items.forEach(function (item) {
        rows += '<tr>'
          + '<td class="exp-sdm-td exp-sdm-sku">' + escHtml(item.sku_code) + '</td>'
          + '<td class="exp-sdm-td exp-sdm-num">' + escHtml(item.requested) + '</td>'
          + '<td class="exp-sdm-td exp-sdm-num">' + escHtml(item.available) + '</td>'
          + '<td class="exp-sdm-td exp-sdm-num">' + escHtml(item.physique !== null ? item.physique : '—') + '</td>'
          + '<td class="exp-sdm-td exp-sdm-num exp-sdm-short">' + escHtml(item.short_by) + '</td>'
          + '</tr>';
      });
      return '<div class="exp-sdm-inner">'
        + '<div class="exp-sdm-header">'
        +   '<h2 class="exp-sdm-title">Détail du risque de stock — commande #' + escHtml(oid) + '</h2>'
        +   '<button type="button" class="exp-sdm-close" aria-label="Fermer">✕</button>'
        + '</div>'
        + '<p class="exp-sdm-advisory">Indicatif — la vente à découvert reste autorisée.</p>'
        + '<div class="exp-sdm-table-wrap">'
        +   '<table class="exp-sdm-table">'
        +     '<thead><tr>'
        +       '<th class="exp-sdm-th">SKU</th>'
        +       '<th class="exp-sdm-th exp-sdm-num">Demandé</th>'
        +       '<th class="exp-sdm-th exp-sdm-num">Disponible (engagé)</th>'
        +       '<th class="exp-sdm-th exp-sdm-num">Physique</th>'
        +       '<th class="exp-sdm-th exp-sdm-num">Manque</th>'
        +     '</tr></thead>'
        +     '<tbody>' + rows + '</tbody>'
        +   '</table>'
        + '</div>'
        + '</div>';
    }

    // Delegate chip clicks on the whole page (no duplicate listener)
    document.addEventListener('click', function (e) {
      var chip = e.target.closest('.exp-stock-risk-chip[data-order-id]');
      if (!chip) return;
      var oid   = chip.dataset.orderId;
      var items = detail[oid];
      if (!items || !items.length) return;
      // Rebuild content on each open (no stale state)
      modal.innerHTML = buildContent(oid, items);
      modal.showModal();
    });
  }());

  /* ── Pull-list collapsible toggle ──────────────────────────────────────── */
  (function () {
    var toggleBtn = document.getElementById('exp-pull-toggle');
    var body      = document.getElementById('exp-pull-body');
    if (!toggleBtn || !body) return;

    toggleBtn.addEventListener('click', function () {
      var expanded = toggleBtn.getAttribute('aria-expanded') === 'true';
      toggleBtn.setAttribute('aria-expanded', String(!expanded));
      body.hidden = expanded;
    });
  }());

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
