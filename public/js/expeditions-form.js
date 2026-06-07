/**
 * expeditions-form.js — Saisie view JS for Expéditions module.
 *
 * Reads window.EXP_CUSTOMERS, window.EXP_SKUS, window.EXP_TRANSPORTERS,
 * window.EXP_EDIT_ORDER, window.EXP_EDIT_LINES.
 *
 * Responsibilities:
 *   - Type toggle (customer ↔ internal channel) binding
 *   - Customer typeahead with inline "➕ Nouveau client" affordance
 *   - Auto-fill transporter from customer default_transporter_id_fk
 *   - SKU typeahead per line row
 *   - Dynamic line management (add/remove)
 *   - Live recap (line count + total HL)
 *   - Prefill from EXP_EDIT_ORDER / EXP_EDIT_LINES (edit mode)
 *
 * Vanilla JS only — no external libraries.
 * XSS: all dynamic HTML uses escHtml() or textContent.
 */

'use strict';

(function () {

  /* ── Data from server ──────────────────────────────────────────────────── */
  const CUSTOMERS    = window.EXP_CUSTOMERS    || [];
  const SKUS         = window.EXP_SKUS         || [];
  const TRANSPORTERS = window.EXP_TRANSPORTERS || [];
  const EDIT_ORDER   = window.EXP_EDIT_ORDER   || null;
  const EDIT_LINES   = window.EXP_EDIT_LINES   || [];

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

  /* ── Type toggle ────────────────────────────────────────────────────────── */
  const btnCustomer  = qs('#exp-type-customer');
  const btnInternal  = qs('#exp-type-internal');
  const orderTypeInp = qs('#exp-order-type');
  const custPanel    = qs('#exp-customer-panel');
  const intPanel     = qs('#exp-internal-panel');

  function setOrderType(type) {
    if (!orderTypeInp) return;
    orderTypeInp.value = type;
    const isCustomer = type === 'customer';

    if (btnCustomer) {
      btnCustomer.classList.toggle('exp-toggle-btn--active', isCustomer);
      btnCustomer.setAttribute('aria-checked', String(isCustomer));
    }
    if (btnInternal) {
      btnInternal.classList.toggle('exp-toggle-btn--active', !isCustomer);
      btnInternal.setAttribute('aria-checked', String(!isCustomer));
    }
    if (custPanel) custPanel.classList.toggle('exp-party-panel--hidden', !isCustomer);
    if (intPanel)  intPanel.classList.toggle('exp-party-panel--hidden', isCustomer);
  }

  if (btnCustomer) {
    btnCustomer.addEventListener('click', function () { setOrderType('customer'); });
  }
  if (btnInternal) {
    btnInternal.addEventListener('click', function () { setOrderType('internal'); });
  }

  /* ── Customer typeahead ─────────────────────────────────────────────────── */
  const custSearch    = qs('#exp-cust-search');
  const custDropdown  = qs('#exp-cust-dropdown');
  const custIdInput   = qs('#exp-customer-id');
  const newCustPanel  = qs('#exp-new-cust-panel');
  const newCustInput  = qs('#exp-new-cust-name');
  const transSelect   = qs('#exp-transporter');
  const transHint     = qs('#exp-trans-hint');

  let custSelectedIdx = -1;

  function filterCustomers(q) {
    const lq = q.toLowerCase();
    return CUSTOMERS.filter(function (c) {
      return c.name.toLowerCase().includes(lq)
          || (c.bc_no && c.bc_no.toLowerCase().includes(lq));
    })
    // Rank: bc-linked (rank 0) first, needs_review (rank 2) last
    .sort(function (a, b) {
      const ra = a.rank !== undefined ? a.rank : 1;
      const rb = b.rank !== undefined ? b.rank : 1;
      if (ra !== rb) return ra - rb;
      return a.name.localeCompare(b.name, 'fr');
    })
    .slice(0, 20);
  }

  function channelBadge(ch) {
    if (!ch) return '';
    const label = ch === 'on_trade' ? 'ON' : 'OFF';
    const cls   = ch === 'on_trade' ? 'exp-suggest-badge--on' : 'exp-suggest-badge--off';
    return '<span class="exp-suggest-badge ' + cls + '">' + label + '</span>';
  }

  function renderCustDropdown(query) {
    if (!custDropdown) return;
    const results = filterCustomers(query);
    custDropdown.innerHTML = '';
    custSelectedIdx = -1;

    // Always show "➕ Nouveau client" option at bottom
    const showNew = query.trim().length > 0;
    if (results.length === 0 && !showNew) {
      custDropdown.hidden = true;
      return;
    }

    results.forEach(function (c, i) {
      const li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      li.dataset.idx = String(i);
      // needs_review rows get a warning badge and subdued style
      const reviewBadge = c.needs_review
        ? '<span class="exp-suggest-badge exp-suggest-badge--review" title="À valider — doublon possible">⚠ à valider</span>'
        : '';
      li.innerHTML =
        '<span class="exp-suggest-name' + (c.needs_review ? ' exp-suggest-name--review' : '') + '">'
          + escHtml(c.name) + '</span>' +
        (c.bc_no ? '<span class="exp-suggest-meta">' + escHtml(c.bc_no) + '</span>' : '') +
        channelBadge(c.channel) +
        reviewBadge;
      li.addEventListener('mousedown', function (e) {
        e.preventDefault();
        selectCustomer(c);
      });
      custDropdown.appendChild(li);
    });

    if (showNew) {
      if (results.length > 0) {
        const div = document.createElement('li');
        div.setAttribute('aria-hidden', 'true');
        div.className = 'exp-suggest-divider';
        div.style.listStyle = 'none';
        custDropdown.appendChild(div);
      }
      const newLi = document.createElement('li');
      newLi.setAttribute('role', 'option');
      newLi.setAttribute('aria-selected', 'false');
      newLi.className = 'exp-suggest-new';
      newLi.dataset.newClient = 'true';
      newLi.textContent = '➕ Nouveau client : "' + query.trim() + '"';
      newLi.addEventListener('mousedown', function (e) {
        e.preventDefault();
        activateNewClient(query.trim());
      });
      custDropdown.appendChild(newLi);
    }

    custDropdown.hidden = false;
  }

  function selectCustomer(c) {
    if (!custSearch || !custIdInput) return;
    custSearch.value = c.name;
    custIdInput.value = String(c.id);
    if (newCustPanel) newCustPanel.hidden = true;
    if (newCustInput) newCustInput.value = '';
    closeCustDropdown();

    // Auto-fill transporter if customer has a default
    if (c.default_trans && transSelect) {
      transSelect.value = String(c.default_trans);
      if (transHint) transHint.hidden = false;
    }
  }

  function activateNewClient(name) {
    if (!custSearch || !custIdInput) return;
    custSearch.value = '';
    custIdInput.value = '0';
    if (newCustPanel) newCustPanel.hidden = false;
    if (newCustInput) {
      newCustInput.value = name;
      newCustInput.focus();
    }
    closeCustDropdown();
  }

  function closeCustDropdown() {
    if (custDropdown) custDropdown.hidden = true;
    custSelectedIdx = -1;
  }

  function moveCustDropdownFocus(dir) {
    if (!custDropdown || custDropdown.hidden) return;
    const items = custDropdown.querySelectorAll('li[role="option"]');
    if (!items.length) return;
    items.forEach(function (li) { li.setAttribute('aria-selected', 'false'); });
    custSelectedIdx += dir;
    if (custSelectedIdx < 0) custSelectedIdx = items.length - 1;
    if (custSelectedIdx >= items.length) custSelectedIdx = 0;
    items[custSelectedIdx].setAttribute('aria-selected', 'true');
    items[custSelectedIdx].scrollIntoView({ block: 'nearest' });
  }

  if (custSearch) {
    custSearch.addEventListener('input', function () {
      const q = custSearch.value;
      if (q.length < 1) { closeCustDropdown(); return; }
      renderCustDropdown(q);
    });
    custSearch.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); moveCustDropdownFocus(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); moveCustDropdownFocus(-1); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        if (custDropdown && !custDropdown.hidden && custSelectedIdx >= 0) {
          const sel = custDropdown.querySelectorAll('li[role="option"]')[custSelectedIdx];
          if (sel) sel.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        }
      }
      else if (e.key === 'Escape') { closeCustDropdown(); }
    });
    custSearch.addEventListener('blur', function () {
      // Delay to allow mousedown to fire first
      setTimeout(closeCustDropdown, 150);
    });
    custSearch.addEventListener('focus', function () {
      if (custSearch.value.length > 0) renderCustDropdown(custSearch.value);
    });
  }

  /* ── SKU line management ────────────────────────────────────────────────── */
  const linesContainer = qs('#exp-lines-container');
  const addLineBtn     = qs('#exp-add-line');
  let lineCounter      = 0;

  function buildSkuDropdownItem(sku) {
    const li = document.createElement('li');
    li.setAttribute('role', 'option');
    li.setAttribute('aria-selected', 'false');
    li.dataset.skuId = String(sku.id);
    li.innerHTML =
      '<span class="exp-line-sku-code">' + escHtml(sku.sku_code) + '</span>' +
      (sku.format ? '<span class="exp-line-sku-format">' + escHtml(sku.format) + '</span>' : '');
    return li;
  }

  function buildLineRow(skuId, qty, comment, disabled) {
    lineCounter++;
    const idx = lineCounter;

    const row = document.createElement('div');
    row.className = 'exp-line-row';
    row.dataset.lineIdx = String(idx);

    // SKU typeahead wrap
    const skuWrap = document.createElement('div');
    skuWrap.className = 'exp-line-sku-wrap op-form__field';

    const label = document.createElement('label');
    label.className = 'op-form__label';
    label.textContent = 'Article';
    label.setAttribute('for', 'exp-line-sku-' + idx);
    skuWrap.appendChild(label);

    const skuSearch = document.createElement('input');
    skuSearch.type = 'text';
    skuSearch.id = 'exp-line-sku-' + idx;
    skuSearch.className = 'op-form__input exp-line-sku-search';
    skuSearch.setAttribute('placeholder', 'Code SKU…');
    skuSearch.setAttribute('autocomplete', 'off');
    skuSearch.setAttribute('autocorrect', 'off');
    skuSearch.setAttribute('spellcheck', 'false');
    if (disabled) skuSearch.disabled = true;

    const skuIdInput = document.createElement('input');
    skuIdInput.type = 'hidden';
    skuIdInput.name = 'line_sku_id[]';
    skuIdInput.value = skuId ? String(skuId) : '';

    const skuDrop = document.createElement('ul');
    skuDrop.className = 'exp-line-sku-dropdown';
    skuDrop.setAttribute('role', 'listbox');
    skuDrop.hidden = true;

    skuWrap.appendChild(skuSearch);
    skuWrap.appendChild(skuIdInput);
    skuWrap.appendChild(skuDrop);

    // Qty field
    const qtyField = document.createElement('div');
    qtyField.className = 'op-form__field';

    const qtyLabel = document.createElement('label');
    qtyLabel.className = 'op-form__label';
    qtyLabel.textContent = 'Qté';
    qtyLabel.setAttribute('for', 'exp-line-qty-' + idx);
    qtyField.appendChild(qtyLabel);

    const qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.id = 'exp-line-qty-' + idx;
    qtyInput.name = 'line_qty[]';
    qtyInput.className = 'op-form__input';
    qtyInput.min = '0.01';
    qtyInput.step = '0.01';
    qtyInput.placeholder = '0';
    if (qty) qtyInput.value = String(qty);
    if (disabled) qtyInput.disabled = true;
    qtyInput.addEventListener('input', updateRecap);
    qtyField.appendChild(qtyInput);

    // Comment field (hidden name, styled small — part of the line grid col-span)
    const commField = document.createElement('div');
    commField.className = 'op-form__field exp-line-comment';

    const commLabel = document.createElement('label');
    commLabel.className = 'op-form__label';
    commLabel.textContent = 'Note ligne';
    commLabel.style.fontSize = '0.6rem';
    commLabel.setAttribute('for', 'exp-line-comm-' + idx);
    commField.appendChild(commLabel);

    const commInput = document.createElement('input');
    commInput.type = 'text';
    commInput.id = 'exp-line-comm-' + idx;
    commInput.name = 'line_comment[]';
    commInput.className = 'op-form__input';
    commInput.placeholder = 'optionnel';
    if (comment) commInput.value = comment;
    if (disabled) commInput.disabled = true;
    commField.appendChild(commInput);

    // Remove button
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'exp-line-remove';
    removeBtn.setAttribute('aria-label', 'Supprimer cette ligne');
    removeBtn.textContent = '×';
    if (disabled) removeBtn.disabled = true;
    removeBtn.addEventListener('click', function () {
      row.remove();
      updateRecap();
    });

    row.appendChild(skuWrap);
    row.appendChild(qtyField);
    row.appendChild(removeBtn);
    row.appendChild(commField);

    // Prefill SKU search text from id if editing
    if (skuId) {
      const foundSku = SKUS.find(function (s) { return s.id === skuId; });
      if (foundSku) {
        skuSearch.value = foundSku.sku_code + (foundSku.format ? ' — ' + foundSku.format : '');
        skuIdInput.value = String(foundSku.id);
        // Store hl_per_unit on the input for recap calculation
        qtyInput.dataset.hlPerUnit = String(foundSku.hl_per_unit);
      }
    }

    // SKU typeahead logic for this row
    let lineDropdownIdx = -1;

    function filterSkus(q) {
      const lq = q.toLowerCase();
      return SKUS.filter(function (s) {
        return s.sku_code.toLowerCase().includes(lq)
            || (s.format && s.format.toLowerCase().includes(lq));
      }).slice(0, 30);
    }

    function renderSkuDropdown(q) {
      const results = filterSkus(q);
      skuDrop.innerHTML = '';
      lineDropdownIdx = -1;
      if (!results.length) { skuDrop.hidden = true; return; }
      results.forEach(function (sku) {
        const li = buildSkuDropdownItem(sku);
        li.addEventListener('mousedown', function (e) {
          e.preventDefault();
          selectSku(sku);
        });
        skuDrop.appendChild(li);
      });
      skuDrop.hidden = false;
    }

    function selectSku(sku) {
      skuSearch.value = sku.sku_code + (sku.format ? ' — ' + sku.format : '');
      skuIdInput.value = String(sku.id);
      qtyInput.dataset.hlPerUnit = String(sku.hl_per_unit);
      skuDrop.hidden = true;
      lineDropdownIdx = -1;
      qtyInput.focus();
      updateRecap();
    }

    function closeSkuDrop() {
      skuDrop.hidden = true;
      lineDropdownIdx = -1;
    }

    function moveSkuDrop(dir) {
      if (skuDrop.hidden) return;
      const items = skuDrop.querySelectorAll('li[role="option"]');
      if (!items.length) return;
      items.forEach(function (li) { li.setAttribute('aria-selected', 'false'); });
      lineDropdownIdx += dir;
      if (lineDropdownIdx < 0) lineDropdownIdx = items.length - 1;
      if (lineDropdownIdx >= items.length) lineDropdownIdx = 0;
      items[lineDropdownIdx].setAttribute('aria-selected', 'true');
      items[lineDropdownIdx].scrollIntoView({ block: 'nearest' });
    }

    skuSearch.addEventListener('input', function () {
      const q = skuSearch.value;
      // Clear hidden id if the user edits the text
      skuIdInput.value = '';
      qtyInput.dataset.hlPerUnit = '';
      if (q.length < 1) { closeSkuDrop(); return; }
      renderSkuDropdown(q);
    });
    skuSearch.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); moveSkuDrop(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); moveSkuDrop(-1); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        if (!skuDrop.hidden && lineDropdownIdx >= 0) {
          const sel = skuDrop.querySelectorAll('li[role="option"]')[lineDropdownIdx];
          if (sel) sel.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        }
      }
      else if (e.key === 'Escape') { closeSkuDrop(); }
    });
    skuSearch.addEventListener('blur', function () {
      setTimeout(closeSkuDrop, 150);
    });
    skuSearch.addEventListener('focus', function () {
      if (skuSearch.value.length > 0) renderSkuDropdown(skuSearch.value);
    });

    return row;
  }

  if (addLineBtn && linesContainer) {
    addLineBtn.addEventListener('click', function () {
      const row = buildLineRow(null, null, null, false);
      linesContainer.appendChild(row);
      // Focus the SKU search in the new row
      const search = row.querySelector('.exp-line-sku-search');
      if (search) search.focus();
      updateRecap();
    });
  }

  /* ── Live recap (line count + total HL) ─────────────────────────────────── */
  const recapEl    = qs('#exp-lines-recap');
  const recapCount = qs('#exp-recap-count');
  const recapS     = qs('#exp-recap-s');
  const recapHl    = qs('#exp-recap-hl');

  function updateRecap() {
    if (!linesContainer || !recapEl) return;
    const rows = linesContainer.querySelectorAll('.exp-line-row');
    let count = 0;
    let totalHl = 0;
    rows.forEach(function (row) {
      const qtyInp = row.querySelector('input[name="line_qty[]"]');
      const qty    = qtyInp ? parseFloat(qtyInp.value) : 0;
      if (!qty || qty <= 0) return;
      const hlPerUnit = qtyInp ? parseFloat(qtyInp.dataset.hlPerUnit || '0') : 0;
      count++;
      totalHl += qty * hlPerUnit;
    });

    if (recapCount) recapCount.textContent = String(count);
    if (recapS)     recapS.textContent     = count > 1 ? 's' : '';
    if (recapHl)    recapHl.textContent    = totalHl.toFixed(2);
    recapEl.hidden = (count === 0);
  }

  /* ── Prefill from server (edit mode) ────────────────────────────────────── */
  if (EDIT_ORDER && linesContainer) {
    // Lines
    EDIT_LINES.forEach(function (line) {
      const disabled = false; // read-only state is handled at PHP level via disabled attrs on form fields
      const row = buildLineRow(line.sku_id, line.qty, line.comment, false);
      linesContainer.appendChild(row);
    });
    updateRecap();
  } else if (linesContainer) {
    // New order: pre-add one empty row for convenience
    linesContainer.appendChild(buildLineRow(null, null, null, false));
  }

  /* ── Prevent double-submit ──────────────────────────────────────────────── */
  const form      = qs('#exp-order-form');
  const submitBtn = qs('#exp-submit-btn');

  if (form && submitBtn) {
    form.addEventListener('submit', function () {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Enregistrement…';
    });
  }

})();
