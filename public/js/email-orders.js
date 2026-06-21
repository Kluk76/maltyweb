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
 *   - Multi-order: scoped per .eo-suborder within the card
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

  /**
   * Build customer typeahead scoped to `scope` (.eo-suborder or card for single).
   * validateCard is called on the parent card.
   */
  function buildCustTypeahead(scope, card) {
    card = card || scope;
    const searchInput = qs('.eo-cust-search', scope);
    const dropdown    = qs('.eo-cust-dropdown', scope);
    const hiddenId    = qs('.eo-customer-id', scope);

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

  /**
   * Build SKU typeahead scoped to `row`. Scope is the .eo-suborder (or card for
   * backward compat). validateCard is called on the parent card.
   */
  function buildSkuTypeahead(row, scope, card) {
    card = card || scope;
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

  /**
   * Checks ALL .eo-suborder elements in the card.
   * For a single-order card: exactly one .eo-suborder with no data-sub-index.
   * For a multi-order card: N .eo-suborder elements each with data-sub-index.
   */
  function validateCard(card) {
    const submitBtn = qs('.eo-btn-validate', card);
    if (!submitBtn) return;

    var allOk = true;
    var suborders = qsa('.eo-suborder', card);

    if (suborders.length === 0) {
      submitBtn.disabled = true;
      return;
    }

    suborders.forEach(function (suborder) {
      var customerId = qs('.eo-customer-id', suborder);
      var dateInput  = qs('.eo-requested-date', suborder);
      var custOk = customerId && parseInt(customerId.value, 10) > 0;
      var dateOk = dateInput && dateInput.value.match(/^\d{4}-\d{2}-\d{2}$/);
      if (!custOk || !dateOk) { allOk = false; return; }
      var lineRows = qsa('.eo-line-row', suborder);
      if (lineRows.length === 0) { allOk = false; return; }
      lineRows.forEach(function (row) {
        var skuId = qs('.eo-sku-id', row);
        var qty   = qs('.eo-qty-input', row);
        if (!skuId || parseInt(skuId.value, 10) <= 0) allOk = false;
        if (!qty   || parseFloat(qty.value) <= 0)     allOk = false;
      });
    });

    submitBtn.disabled = !allOk;
  }

  /* ── Line management ────────────────────────────────────────────────────── */

  var globalLineCounter = 1000; // avoid collisions with server-rendered indices

  /**
   * Build a new line row scoped to `scope` (.eo-suborder or card).
   * Field names use sub[N][...] if data-sub-index is present, else flat names.
   */
  function buildLineRow(scope, card, skuHint, qty) {
    card = card || scope;
    globalLineCounter++;
    var idx      = globalLineCounter;
    var subIndex = scope.dataset && scope.dataset.subIndex;
    var skuName  = subIndex !== undefined && subIndex !== '' ? 'sub[' + subIndex + '][line_sku_id][]' : 'line_sku_id[]';
    var qtyName  = subIndex !== undefined && subIndex !== '' ? 'sub[' + subIndex + '][line_qty][]'    : 'line_qty[]';

    var row = document.createElement('div');
    row.className = 'eo-line-row';
    row.dataset.lineIdx = String(idx);

    var skuWrap = document.createElement('div');
    skuWrap.className = 'eo-typeahead-wrap';

    var skuInput = document.createElement('input');
    skuInput.type = 'text';
    skuInput.className = 'eo-input eo-sku-search';
    skuInput.placeholder = 'SKU…';
    skuInput.autocomplete = 'off';
    skuInput.value = skuHint || '';

    var skuDrop = document.createElement('ul');
    skuDrop.className = 'eo-typeahead-dropdown eo-sku-dropdown';
    skuDrop.setAttribute('role', 'listbox');
    skuDrop.hidden = true;

    var skuHiddenId = document.createElement('input');
    skuHiddenId.type = 'hidden';
    skuHiddenId.className = 'eo-sku-id';
    skuHiddenId.name = skuName;
    skuHiddenId.value = '0';

    skuWrap.appendChild(skuInput);
    skuWrap.appendChild(skuDrop);
    skuWrap.appendChild(skuHiddenId);

    var qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.className = 'eo-input eo-qty-input';
    qtyInput.name = qtyName;
    qtyInput.min = '0.01';
    qtyInput.step = '0.5';
    qtyInput.placeholder = 'Qté';
    qtyInput.value = qty > 0 ? String(qty) : '';
    qtyInput.addEventListener('input', function () { validateCard(card); });

    var removeBtn = document.createElement('button');
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

    buildSkuTypeahead(row, scope, card);
    return row;
  }

  /* ── Init per card ──────────────────────────────────────────────────────── */

  function initCard(card) {
    qsa('.eo-suborder', card).forEach(function (suborder) {
      buildCustTypeahead(suborder, card);

      var dateInput = qs('.eo-requested-date', suborder);
      if (dateInput) {
        dateInput.addEventListener('input', function () { validateCard(card); });
      }

      qsa('.eo-line-row', suborder).forEach(function (row) {
        buildSkuTypeahead(row, suborder, card);

        var qtyInput = qs('.eo-qty-input', row);
        if (qtyInput) {
          qtyInput.addEventListener('input', function () { validateCard(card); });
        }

        var removeBtn = qs('.eo-line-remove', row);
        if (removeBtn) {
          removeBtn.addEventListener('click', function () {
            row.remove();
            validateCard(card);
          });
        }
      });

      var addBtn        = qs('.eo-add-line-btn', suborder);
      var linesContainer = qs('.eo-lines', suborder);
      if (addBtn && linesContainer) {
        addBtn.addEventListener('click', function () {
          var newRow = buildLineRow(suborder, card, '', 0);
          linesContainer.insertBefore(newRow, addBtn);
          validateCard(card);
          var newSkuInput = qs('.eo-sku-search', newRow);
          if (newSkuInput) newSkuInput.focus();
        });
      }
    });

    validateCard(card);
  }

  /* ── Bootstrap all cards ────────────────────────────────────────────────── */

  document.querySelectorAll('.eo-review-card').forEach(function (card) {
    initCard(card);
  });

})();
