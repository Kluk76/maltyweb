/**
 * email-orders.js — Commandes e-mail validation module JS.
 *
 * Reads window.EMAIL_CUSTOMERS and window.EMAIL_SKUS (hydrated by PHP).
 * One instance per review card (keyed by card container id).
 *
 * Responsibilities:
 *   - Customer typeahead (pre-filled from customer_hint, hidden id empty until picked)
 *   - SKU typeahead per line row (pre-filled from sku_hint best match, id empty until picked)
 *   - Dynamic line add/remove
 *   - Prevent submission when any required FK is unresolved
 *
 * Never-guess contract:
 *   - customer_id hidden input starts EMPTY (0) regardless of pre-fill text.
 *   - sku_id hidden inputs start EMPTY (0) regardless of pre-fill text.
 *   - The form submits only when the operator has explicitly clicked a typeahead suggestion.
 *   - Server-side whitelist re-validates every FK — JS is convenience, not the gate.
 *
 * Vanilla JS only — no external libraries.
 * XSS: all dynamic HTML uses escHtml() or textContent.
 */

'use strict';

(function () {

  /* ── Data from server ──────────────────────────────────────────────────── */
  const CUSTOMERS = window.EMAIL_CUSTOMERS || [];
  const SKUS      = window.EMAIL_SKUS      || [];

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
  function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  /* ── Customer typeahead ─────────────────────────────────────────────────── */

  function filterCustomers(q) {
    const lq = q.toLowerCase();
    return CUSTOMERS.filter(function (c) {
      return c.name.toLowerCase().includes(lq);
    }).slice(0, 20);
  }

  function buildCustTypeahead(card) {
    const searchInput = qs('.eo-cust-search', card);
    const dropdown    = qs('.eo-cust-dropdown', card);
    const hiddenId    = qs('.eo-customer-id', card);

    if (!searchInput || !dropdown || !hiddenId) return;

    let selIdx = -1;

    function renderDropdown(q) {
      const results = filterCustomers(q);
      dropdown.innerHTML = '';
      selIdx = -1;

      if (results.length === 0) {
        dropdown.hidden = true;
        return;
      }

      results.forEach(function (c, i) {
        const li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.dataset.idx = String(i);
        li.innerHTML = '<span>' + escHtml(c.name) + '</span>';
        li.addEventListener('mousedown', function (e) {
          e.preventDefault();
          searchInput.value = c.name;
          hiddenId.value = String(c.id);
          dropdown.hidden = true;
          selIdx = -1;
          validateCard(card);
        });
        dropdown.appendChild(li);
      });

      dropdown.hidden = false;
    }

    function closeDropdown() {
      dropdown.hidden = true;
      selIdx = -1;
    }

    function moveFocus(dir) {
      if (dropdown.hidden) return;
      const items = qsa('li[role="option"]', dropdown);
      if (!items.length) return;
      items.forEach(function (li) { li.setAttribute('aria-selected', 'false'); });
      selIdx += dir;
      if (selIdx < 0) selIdx = items.length - 1;
      if (selIdx >= items.length) selIdx = 0;
      items[selIdx].setAttribute('aria-selected', 'true');
      items[selIdx].scrollIntoView({ block: 'nearest' });
    }

    // When operator types, clear the hidden id (they are no longer using the pre-fill)
    searchInput.addEventListener('input', function () {
      hiddenId.value = '0';
      validateCard(card);
      const q = searchInput.value;
      if (q.length < 1) { closeDropdown(); return; }
      renderDropdown(q);
    });

    searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); moveFocus(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); moveFocus(-1); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        if (!dropdown.hidden && selIdx >= 0) {
          const sel = qsa('li[role="option"]', dropdown)[selIdx];
          if (sel) sel.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        }
      }
      else if (e.key === 'Escape') { closeDropdown(); }
    });

    searchInput.addEventListener('blur', function () {
      setTimeout(closeDropdown, 150);
    });

    searchInput.addEventListener('focus', function () {
      if (searchInput.value.length > 0) renderDropdown(searchInput.value);
    });
  }

  /* ── SKU typeahead ──────────────────────────────────────────────────────── */

  function filterSkus(q) {
    const lq = q.toLowerCase();
    return SKUS.filter(function (s) {
      return s.sku_code.toLowerCase().includes(lq);
    }).slice(0, 30);
  }

  function buildSkuTypeahead(row, card) {
    const searchInput = qs('.eo-sku-search', row);
    const dropdown    = qs('.eo-sku-dropdown', row);
    const hiddenId    = qs('.eo-sku-id', row);

    if (!searchInput || !dropdown || !hiddenId) return;

    let selIdx = -1;

    function renderDropdown(q) {
      const results = filterSkus(q);
      dropdown.innerHTML = '';
      selIdx = -1;

      if (results.length === 0) {
        dropdown.hidden = true;
        return;
      }

      results.forEach(function (s, i) {
        const li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.dataset.idx = String(i);
        li.innerHTML =
          '<span class="eo-sku-code">' + escHtml(s.sku_code) + '</span>';
        li.addEventListener('mousedown', function (e) {
          e.preventDefault();
          searchInput.value = s.sku_code;
          hiddenId.value = String(s.id);
          dropdown.hidden = true;
          selIdx = -1;
          validateCard(card);
        });
        dropdown.appendChild(li);
      });

      dropdown.hidden = false;
    }

    function closeDropdown() {
      dropdown.hidden = true;
      selIdx = -1;
    }

    function moveFocus(dir) {
      if (dropdown.hidden) return;
      const items = qsa('li[role="option"]', dropdown);
      if (!items.length) return;
      items.forEach(function (li) { li.setAttribute('aria-selected', 'false'); });
      selIdx += dir;
      if (selIdx < 0) selIdx = items.length - 1;
      if (selIdx >= items.length) selIdx = 0;
      items[selIdx].setAttribute('aria-selected', 'true');
      items[selIdx].scrollIntoView({ block: 'nearest' });
    }

    searchInput.addEventListener('input', function () {
      hiddenId.value = '0';
      validateCard(card);
      const q = searchInput.value;
      if (q.length < 1) { closeDropdown(); return; }
      renderDropdown(q);
    });

    searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); moveFocus(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); moveFocus(-1); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        if (!dropdown.hidden && selIdx >= 0) {
          const sel = qsa('li[role="option"]', dropdown)[selIdx];
          if (sel) sel.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        }
      }
      else if (e.key === 'Escape') { closeDropdown(); }
    });

    searchInput.addEventListener('blur', function () {
      setTimeout(closeDropdown, 150);
    });

    searchInput.addEventListener('focus', function () {
      if (searchInput.value.length > 0) renderDropdown(searchInput.value);
    });
  }

  /* ── Validate card (enable/disable submit) ──────────────────────────────── */

  function validateCard(card) {
    const submitBtn   = qs('.eo-btn-validate', card);
    const customerId  = qs('.eo-customer-id', card);
    const dateInput   = qs('.eo-requested-date', card);
    if (!submitBtn) return;

    const custOk = customerId && parseInt(customerId.value, 10) > 0;
    const dateOk = dateInput && dateInput.value.match(/^\d{4}-\d{2}-\d{2}$/);

    // Every visible line must have a resolved SKU id and qty > 0
    const lineRows = qsa('.eo-line-row', card);
    let linesOk = true;
    lineRows.forEach(function (row) {
      const skuId = qs('.eo-sku-id', row);
      const qty   = qs('.eo-qty-input', row);
      if (!skuId || parseInt(skuId.value, 10) <= 0) linesOk = false;
      if (!qty   || parseFloat(qty.value) <= 0)     linesOk = false;
    });
    if (lineRows.length === 0) linesOk = false;

    submitBtn.disabled = !(custOk && dateOk && linesOk);
  }

  /* ── Line management ────────────────────────────────────────────────────── */

  let globalLineCounter = 1000; // avoid collisions with server-rendered indices

  function buildLineRow(card, skuHint, qty) {
    globalLineCounter++;
    const idx = globalLineCounter;

    const row = document.createElement('div');
    row.className = 'eo-line-row';
    row.dataset.lineIdx = String(idx);

    // SKU typeahead wrap
    const skuWrap = document.createElement('div');
    skuWrap.className = 'eo-typeahead-wrap';

    const skuInput = document.createElement('input');
    skuInput.type = 'text';
    skuInput.className = 'eo-input eo-sku-search';
    skuInput.placeholder = 'SKU…';
    skuInput.autocomplete = 'off';
    skuInput.value = skuHint || '';

    const skuDrop = document.createElement('ul');
    skuDrop.className = 'eo-typeahead-dropdown eo-sku-dropdown';
    skuDrop.setAttribute('role', 'listbox');
    skuDrop.hidden = true;

    const skuHiddenId = document.createElement('input');
    skuHiddenId.type = 'hidden';
    skuHiddenId.className = 'eo-sku-id';
    skuHiddenId.name = 'line_sku_id[]';
    skuHiddenId.value = '0'; // always start unresolved — human must pick

    skuWrap.appendChild(skuInput);
    skuWrap.appendChild(skuDrop);
    skuWrap.appendChild(skuHiddenId);

    // Qty input
    const qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.className = 'eo-input eo-qty-input';
    qtyInput.name = 'line_qty[]';
    qtyInput.min = '0.01';
    qtyInput.step = '0.5';
    qtyInput.placeholder = 'Qté';
    qtyInput.value = qty > 0 ? String(qty) : '';
    qtyInput.addEventListener('input', function () { validateCard(card); });

    // Remove button
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'eo-line-remove';
    removeBtn.setAttribute('aria-label', 'Supprimer la ligne');
    removeBtn.textContent = '×';
    removeBtn.addEventListener('click', function () {
      row.remove();
      validateCard(card);
    });

    row.appendChild(skuWrap);
    row.appendChild(qtyInput);
    row.appendChild(removeBtn);

    buildSkuTypeahead(row, card);
    return row;
  }

  /* ── Init per card ──────────────────────────────────────────────────────── */

  function initCard(card) {
    // Customer typeahead
    buildCustTypeahead(card);

    // Date input watcher
    const dateInput = qs('.eo-requested-date', card);
    if (dateInput) {
      dateInput.addEventListener('input', function () { validateCard(card); });
    }

    // Wire SKU typeahead on server-rendered line rows
    qsa('.eo-line-row', card).forEach(function (row) {
      buildSkuTypeahead(row, card);

      // Qty change on pre-rendered rows
      const qtyInput = qs('.eo-qty-input', row);
      if (qtyInput) {
        qtyInput.addEventListener('input', function () { validateCard(card); });
      }

      // Remove button on pre-rendered rows
      const removeBtn = qs('.eo-line-remove', row);
      if (removeBtn) {
        removeBtn.addEventListener('click', function () {
          row.remove();
          validateCard(card);
        });
      }
    });

    // Add line button
    const addBtn      = qs('.eo-add-line-btn', card);
    const linesContainer = qs('.eo-lines', card);
    if (addBtn && linesContainer) {
      addBtn.addEventListener('click', function () {
        const newRow = buildLineRow(card, '', 0);
        linesContainer.insertBefore(newRow, addBtn);
        validateCard(card);
        // Focus the new SKU input
        const newSkuInput = qs('.eo-sku-search', newRow);
        if (newSkuInput) newSkuInput.focus();
      });
    }

    // Initial state: disable submit until operator resolves FKs
    validateCard(card);
  }

  /* ── Bootstrap all cards ────────────────────────────────────────────────── */

  document.querySelectorAll('.eo-review-card').forEach(function (card) {
    initCard(card);
  });

})();
