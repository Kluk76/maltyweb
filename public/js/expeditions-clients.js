/**
 * expeditions-clients.js — Carnet clients view JS.
 *
 * Responsibilities:
 *   - Review queue section collapse/expand toggle
 *   - Per-row merge panel open/close
 *   - Merge typeahead over window.EXP_CRM_ROWS (CRM rows: bc_customer_no NOT NULL, is_active=1)
 *   - Target selection + preview line + hidden input population
 *   - Cancel-merge button
 *
 * Reads: window.EXP_CRM_ROWS, window.EXP_CSRF
 * No AJAX — all writes go through standard PRG forms.
 * Vanilla JS only — no external libraries.
 * XSS: all dynamic HTML uses escHtml() or textContent.
 */

'use strict';

(function () {

  /* ── Data from server ──────────────────────────────────────────────────── */
  const CRM_ROWS = window.EXP_CRM_ROWS || [];

  /* ── Utility ────────────────────────────────────────────────────────────── */
  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#x27;');
  }

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return (root || document).querySelectorAll(sel); }

  /* ── Review section toggle ──────────────────────────────────────────────── */
  const reviewToggle = qs('#exp-review-toggle');
  const reviewList   = qs('#exp-review-list');

  if (reviewToggle && reviewList) {
    reviewToggle.addEventListener('click', function () {
      const expanded = reviewToggle.getAttribute('aria-expanded') === 'true';
      if (expanded) {
        reviewList.hidden = true;
        reviewToggle.setAttribute('aria-expanded', 'false');
        reviewToggle.textContent = 'Afficher ▼';
      } else {
        reviewList.hidden = false;
        reviewToggle.setAttribute('aria-expanded', 'true');
        reviewToggle.textContent = 'Réduire ▲';
      }
    });
  }

  /* ── Merge panel management ─────────────────────────────────────────────── */

  // Track currently-open merge panel id (only one open at a time)
  let openMergeId = null;

  function closeMergePanel(rvId) {
    const panel = qs('#exp-merge-panel-' + rvId);
    const btn   = qs('[data-rv-id="' + rvId + '"].exp-clients-action-btn--merge');
    if (panel) panel.hidden = true;
    if (btn)   btn.setAttribute('aria-expanded', 'false');
    // Reset panel state
    resetMergePanel(rvId);
    if (openMergeId === rvId) openMergeId = null;
  }

  function resetMergePanel(rvId) {
    const search   = qs('#exp-merge-search-' + rvId);
    const drop     = qs('#exp-merge-drop-' + rvId);
    const preview  = qs('#exp-merge-preview-' + rvId);
    const form     = qs('#exp-merge-form-' + rvId);
    if (search)  search.value = '';
    if (drop)    { drop.innerHTML = ''; drop.hidden = true; }
    if (preview) preview.hidden = true;
    if (form)    {
      form.hidden = true;
      const hiddenTarget = form.querySelector('.exp-merge-target-id');
      if (hiddenTarget) hiddenTarget.value = '';
    }
  }

  // Delegate merge button clicks (handles dynamically-present rows)
  document.addEventListener('click', function (e) {
    const mergeBtn = e.target.closest('.exp-clients-action-btn--merge');
    if (mergeBtn) {
      const rvId = mergeBtn.dataset.rvId;
      if (!rvId) return;
      const panel = qs('#exp-merge-panel-' + rvId);
      if (!panel) return;

      const isOpen = !panel.hidden;
      // Close any currently-open panel first
      if (openMergeId && openMergeId !== rvId) {
        closeMergePanel(openMergeId);
      }

      if (isOpen) {
        closeMergePanel(rvId);
      } else {
        panel.hidden = false;
        mergeBtn.setAttribute('aria-expanded', 'true');
        openMergeId = rvId;
        // Focus the search input
        const search = qs('#exp-merge-search-' + rvId);
        if (search) search.focus();
      }
      return;
    }

    // Cancel merge button
    const cancelBtn = e.target.closest('.exp-clients-action-btn--cancel-merge');
    if (cancelBtn) {
      const rvId = cancelBtn.dataset.rvId;
      if (rvId) closeMergePanel(rvId);
    }
  });

  /* ── Merge typeahead ────────────────────────────────────────────────────── */

  function filterCrm(q) {
    const lq = q.toLowerCase();
    return CRM_ROWS.filter(function (c) {
      return c.name.toLowerCase().includes(lq)
          || (c.bc_no && c.bc_no.toLowerCase().includes(lq));
    }).slice(0, 15);
  }

  // Wire typeahead for each merge search input that exists in the DOM
  // Using event delegation on the review list container

  const reviewSection = qs('#exp-review-list');
  if (!reviewSection) return; // no review rows → nothing to wire

  // Delegate input events on all merge search inputs
  reviewSection.addEventListener('input', function (e) {
    if (!e.target.classList.contains('exp-merge-search')) return;
    const input = e.target;
    const rvId  = input.dataset.dupId;
    if (!rvId) return;
    renderMergeDropdown(rvId, input.value);
  });

  reviewSection.addEventListener('keydown', function (e) {
    if (!e.target.classList.contains('exp-merge-search')) return;
    const input = e.target;
    const rvId  = input.dataset.dupId;
    if (!rvId) return;

    const drop  = qs('#exp-merge-drop-' + rvId);
    if (!drop || drop.hidden) return;

    const items = drop.querySelectorAll('li[role="option"]');
    if (!items.length) return;

    let curIdx = -1;
    items.forEach(function (li, i) {
      if (li.getAttribute('aria-selected') === 'true') curIdx = i;
    });

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      moveMergeDropFocus(drop, items, curIdx, 1, rvId);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      moveMergeDropFocus(drop, items, curIdx, -1, rvId);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (curIdx >= 0) {
        items[curIdx].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
      }
    } else if (e.key === 'Escape') {
      drop.innerHTML = '';
      drop.hidden = true;
    }
  });

  reviewSection.addEventListener('blur', function (e) {
    if (!e.target.classList.contains('exp-merge-search')) return;
    const rvId = e.target.dataset.dupId;
    if (!rvId) return;
    setTimeout(function () {
      const drop = qs('#exp-merge-drop-' + rvId);
      if (drop) { drop.innerHTML = ''; drop.hidden = true; }
    }, 150);
  }, true);

  function moveMergeDropFocus(drop, items, curIdx, dir, rvId) {
    items.forEach(function (li) { li.setAttribute('aria-selected', 'false'); });
    let next = curIdx + dir;
    if (next < 0) next = items.length - 1;
    if (next >= items.length) next = 0;
    items[next].setAttribute('aria-selected', 'true');
    items[next].scrollIntoView({ block: 'nearest' });
  }

  function renderMergeDropdown(rvId, query) {
    const drop = qs('#exp-merge-drop-' + rvId);
    if (!drop) return;

    if (query.trim().length < 1) {
      drop.innerHTML = '';
      drop.hidden = true;
      return;
    }

    const results = filterCrm(query);
    drop.innerHTML = '';

    if (!results.length) {
      drop.hidden = true;
      return;
    }

    results.forEach(function (c) {
      const li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      li.innerHTML =
        '<span class="exp-suggest-name">' + escHtml(c.name) + '</span>' +
        (c.bc_no ? '<span class="exp-suggest-meta">' + escHtml(c.bc_no) + '</span>' : '');
      li.addEventListener('mousedown', function (e) {
        e.preventDefault();
        selectMergeTarget(rvId, c);
      });
      drop.appendChild(li);
    });

    drop.hidden = false;
  }

  function selectMergeTarget(rvId, target) {
    const drop      = qs('#exp-merge-drop-' + rvId);
    const search    = qs('#exp-merge-search-' + rvId);
    const preview   = qs('#exp-merge-preview-' + rvId);
    const previewTx = qs('#exp-merge-preview-text-' + rvId);
    const form      = qs('#exp-merge-form-' + rvId);
    const hiddenTgt = form ? form.querySelector('.exp-merge-target-id') : null;

    if (drop)     { drop.innerHTML = ''; drop.hidden = true; }
    if (search)   search.value = target.name + (target.bc_no ? ' (' + target.bc_no + ')' : '');

    // Fill preview
    if (previewTx && search) {
      const dupName = search.dataset.dupName || '';
      previewTx.textContent =
        'Fusionner « ' + dupName + ' » → « ' + target.name + ' »'
        + (target.bc_no ? ' (' + target.bc_no + ')' : '');
    }
    if (preview) preview.hidden = false;

    // Fill hidden target_id in the form and show confirm bar
    if (hiddenTgt) hiddenTgt.value = String(target.id);
    if (form) form.hidden = false;
  }

})();
