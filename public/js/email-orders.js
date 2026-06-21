/**
 * email-orders.js — Commandes e-mail validation module JS.
 *
 * Reads window.EMAIL_CUSTOMERS and window.EMAIL_SKUS (hydrated by PHP).
 * Features: suggestion chips, human SKU labels, progress badges,
 * sticky validate bar with blocker text, live poll for new orders.
 *
 * Never-guess contract:
 *   - customer_id hidden input starts EMPTY (0) regardless of pre-fill text.
 *   - sku_id hidden inputs start EMPTY (0) regardless of pre-fill text.
 *   - Chips are NEVER pre-selected. A high-confidence match is a suggestion until clicked.
 *   - Server-side whitelist re-validates every FK.
 *
 * Vanilla JS only — no external libraries.
 * XSS: all dynamic HTML uses escHtml() or textContent.
 */

'use strict';

(function () {
  const CUSTOMERS = window.EMAIL_CUSTOMERS || [];
  const SKUS      = window.EMAIL_SKUS      || [];

  // ── Utilities ────────────────────────────────────────────────────────────
  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#x27;');
  }
  function qs(sel, root)  { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  // ── Normalizer for scoring ────────────────────────────────────────────────
  function normalizeStr(s) {
    return String(s).toLowerCase()
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9\s]/g, ' ')
      .replace(/\s+/g, ' ').trim();
  }

  function tokenOverlap(a, b) {
    const ta = new Set(normalizeStr(a).split(' ').filter(Boolean));
    const tb = normalizeStr(b).split(' ').filter(Boolean);
    if (ta.size === 0 || tb.length === 0) return 0;
    const hits = tb.filter(function(t) { return ta.has(t); }).length;
    return hits / Math.max(ta.size, tb.length);
  }

  // ── SKU scorer ────────────────────────────────────────────────────────────
  // Returns [{sku, confidence:'forte'|'moyenne'}, ...] top 3 sorted desc
  function rankSkus(hint) {
    if (!hint) return [];
    const hNorm   = normalizeStr(hint);
    const hTokens = hNorm.split(' ').filter(Boolean);

    var scored = SKUS.map(function(s) {
      var score = 0;
      var codeNorm = normalizeStr(s.sku_code);
      if (hTokens.some(function(t) { return t === codeNorm; })) {
        score = 1.0;
      } else {
        var labelNorm   = normalizeStr(s.label || s.sku_code);
        var overlapLabel = tokenOverlap(hNorm, labelNorm);
        var overlapCode  = tokenOverlap(hNorm, codeNorm);
        score = Math.max(overlapLabel, overlapCode * 0.8);
        var shortHint = hNorm.substring(0, Math.max(3, hNorm.length - 2));
        if (hNorm.includes(codeNorm) || labelNorm.includes(shortHint)) {
          score = Math.max(score, 0.5);
        }
      }
      return { sku: s, score: score };
    });

    return scored
      .filter(function(x) { return x.score >= 0.35; })
      .sort(function(a, b) { return b.score - a.score; })
      .slice(0, 3)
      .map(function(x) {
        return { sku: x.sku, confidence: x.score >= 0.75 ? 'forte' : 'moyenne' };
      });
  }

  // ── Customer scorer ───────────────────────────────────────────────────────
  function rankCustomers(hint) {
    if (!hint) return [];
    var hNorm = normalizeStr(hint);
    var scored = CUSTOMERS.map(function(c) {
      var nameNorm = normalizeStr(c.name);
      var score = 0;
      if (nameNorm === hNorm) score = 1.0;
      else if (nameNorm.includes(hNorm) || hNorm.includes(nameNorm)) score = 0.8;
      else score = tokenOverlap(hNorm, nameNorm);
      return { cust: c, score: score };
    });
    return scored
      .filter(function(x) { return x.score >= 0.35; })
      .sort(function(a, b) { return b.score - a.score; })
      .slice(0, 3)
      .map(function(x) {
        return { cust: x.cust, confidence: x.score >= 0.75 ? 'forte' : 'moyenne' };
      });
  }

  // ── Progress ──────────────────────────────────────────────────────────────
  function countResolved(suborder) {
    var done = 0, total = 0;
    var custId = qs('.eo-customer-id', suborder);
    total += 1;
    if (custId && parseInt(custId.value, 10) > 0) done += 1;
    qsa('.eo-line-row', suborder).forEach(function(row) {
      total += 1;
      var skuId = qs('.eo-sku-id', row);
      if (skuId && parseInt(skuId.value, 10) > 0) done += 1;
    });
    return { done: done, total: total };
  }

  function updateProgress(card) {
    qsa('.eo-suborder', card).forEach(function(suborder) {
      var badge = qs('.eo-progress-badge', suborder);
      if (!badge) return;
      var r = countResolved(suborder);
      badge.textContent = r.done + ' / ' + r.total + ' résolu';
      badge.classList.toggle('eo-progress-badge--complete', r.done >= r.total && r.total > 0);
    });
  }

  // ── validateCard ─────────────────────────────────────────────────────────
  function validateCard(card) {
    var submitBtn = qs('.eo-btn-validate', card);
    if (!submitBtn) return;

    var allOk = true;
    var blockers = [];
    var suborders = qsa('.eo-suborder', card);

    if (suborders.length === 0) {
      submitBtn.disabled = true;
      return;
    }

    suborders.forEach(function(suborder, si) {
      var customerId = qs('.eo-customer-id', suborder);
      var dateInput  = qs('.eo-requested-date', suborder);
      var custOk = customerId && parseInt(customerId.value, 10) > 0;
      var dateOk = dateInput && /^\d{4}-\d{2}-\d{2}$/.test(dateInput.value);
      var subLabel = suborders.length > 1 ? 'Commande ' + (si + 1) + ' : ' : '';
      var subBlockers = [];
      if (!custOk) subBlockers.push('client');
      if (!dateOk) subBlockers.push('date');

      var lineRows = qsa('.eo-line-row', suborder);
      if (lineRows.length === 0) {
        subBlockers.push('lignes');
        allOk = false;
      } else {
        var unresolvedLines = 0;
        lineRows.forEach(function(row) {
          var skuId = qs('.eo-sku-id', row);
          var qty   = qs('.eo-qty-input', row);
          if (!skuId || parseInt(skuId.value, 10) <= 0) unresolvedLines++;
          if (!qty   || parseFloat(qty.value) <= 0)     unresolvedLines++;
        });
        if (unresolvedLines > 0) subBlockers.push(unresolvedLines + ' ligne' + (unresolvedLines > 1 ? 's' : ''));
      }

      if (subBlockers.length > 0) {
        allOk = false;
        blockers.push(subLabel + subBlockers.join(' + ') + ' à résoudre');
      }
    });

    submitBtn.disabled = !allOk;

    var blockerEl = qs('.eo-sticky-validate__blocker', card);
    if (blockerEl) {
      blockerEl.textContent = allOk ? '' : blockers.join(' — ');
    }

    updateProgress(card);
  }

  // ── Chip builders ─────────────────────────────────────────────────────────

  function buildSkuChips(row, suborder, card) {
    var chipsDiv    = qs('.eo-sku-chips', row);
    var manualDiv   = qs('.eo-sku-manual', row);
    var autreBtn    = qs('.eo-autre-btn', row);
    var resolvedDiv = qs('.eo-sku-resolved', row);
    var hiddenId    = qs('.eo-sku-id', row);
    var hintAttr    = row.dataset.skuHint || '';

    if (!chipsDiv || !hiddenId) return;

    var ranked = rankSkus(hintAttr);
    chipsDiv.innerHTML = '';

    ranked.forEach(function(item) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'eo-chip eo-chip--sku eo-chip--' + item.confidence;
      btn.innerHTML =
        '<span class="eo-chip__label">' + escHtml(item.sku.label || item.sku.sku_code) + '</span>' +
        '<span class="eo-chip__code">'  + escHtml(item.sku.sku_code) + '</span>' +
        '<span class="eo-chip__conf eo-chip__conf--' + item.confidence + '">' + item.confidence + '</span>';

      btn.addEventListener('click', function() {
        hiddenId.value = String(item.sku.id);
        if (resolvedDiv) {
          var lbl = qs('.eo-sku-resolved__label', resolvedDiv);
          if (lbl) lbl.textContent = item.sku.label || item.sku.sku_code;
          resolvedDiv.hidden = false;
        }
        chipsDiv.hidden = true;
        if (autreBtn) autreBtn.hidden = true;
        row.classList.add('eo-line-row--resolved');
        validateCard(card);
      });
      chipsDiv.appendChild(btn);
    });

    // "Autre…" button reveals manual typeahead
    if (autreBtn && manualDiv) {
      autreBtn.addEventListener('click', function() {
        manualDiv.hidden = false;
        autreBtn.hidden  = true;
        chipsDiv.hidden  = true;
        var skuInput = qs('.eo-sku-search', manualDiv);
        if (skuInput) skuInput.focus();
      });
    }

    // Clear/change button
    var clearBtn = resolvedDiv ? qs('.eo-sku-resolved__clear', resolvedDiv) : null;
    if (clearBtn) {
      clearBtn.addEventListener('click', function() {
        hiddenId.value = '0';
        resolvedDiv.hidden = true;
        chipsDiv.hidden = ranked.length === 0;
        if (autreBtn) autreBtn.hidden = ranked.length === 0;
        row.classList.remove('eo-line-row--resolved');
        validateCard(card);
      });
    }
  }

  function buildCustChips(suborder, card) {
    var chipsDiv    = qs('.eo-cust-chips', suborder);
    var manualDiv   = qs('.eo-cust-manual', suborder);
    var autreBtn    = qs('.eo-cust-autre-btn', suborder);
    var resolvedDiv = qs('.eo-cust-resolved', suborder);
    var hiddenId    = qs('.eo-customer-id', suborder);

    if (!chipsDiv || !hiddenId) return;

    // If already pre-filled (internal rep), show resolved and wire clear to reveal manual
    if (parseInt(hiddenId.value, 10) > 0) {
      chipsDiv.hidden = true;
      if (autreBtn) autreBtn.hidden = true;
      if (resolvedDiv) resolvedDiv.hidden = false;
      // Wire clear button so operator can override the prefill
      var preClearBtn = resolvedDiv ? qs('.eo-cust-resolved__clear', resolvedDiv) : null;
      if (preClearBtn && manualDiv) {
        preClearBtn.addEventListener('click', function() {
          hiddenId.value = '0';
          resolvedDiv.hidden = true;
          manualDiv.hidden = false;
          var custSearch = qs('.eo-cust-search', manualDiv);
          if (custSearch) custSearch.focus();
          validateCard(card);
        });
      }
      validateCard(card);
      return;
    }

    var hintAttr = chipsDiv.dataset.custHint || '';
    var ranked   = rankCustomers(hintAttr);
    chipsDiv.innerHTML = '';

    ranked.forEach(function(item) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'eo-chip eo-chip--cust eo-chip--' + item.confidence;
      btn.innerHTML =
        '<span class="eo-chip__label">' + escHtml(item.cust.name) + '</span>' +
        '<span class="eo-chip__conf eo-chip__conf--' + item.confidence + '">' + item.confidence + '</span>';

      btn.addEventListener('click', function() {
        hiddenId.value = String(item.cust.id);
        if (resolvedDiv) {
          var lbl = qs('.eo-cust-resolved__label', resolvedDiv);
          if (lbl) lbl.textContent = item.cust.name;
          resolvedDiv.hidden = false;
        }
        chipsDiv.hidden = true;
        if (autreBtn) autreBtn.hidden = true;
        // Also update any visible search input
        var search = manualDiv ? qs('.eo-cust-search', manualDiv) : null;
        if (search) search.value = item.cust.name;
        validateCard(card);
      });
      chipsDiv.appendChild(btn);
    });

    // "Autre…" button
    if (autreBtn && manualDiv) {
      autreBtn.addEventListener('click', function() {
        manualDiv.hidden = false;
        autreBtn.hidden  = true;
        chipsDiv.hidden  = true;
        var custSearch = qs('.eo-cust-search', manualDiv);
        if (custSearch) custSearch.focus();
      });
    }

    // Clear button
    var clearBtn = resolvedDiv ? qs('.eo-cust-resolved__clear', resolvedDiv) : null;
    if (clearBtn) {
      clearBtn.addEventListener('click', function() {
        hiddenId.value = '0';
        resolvedDiv.hidden = true;
        chipsDiv.hidden = ranked.length === 0;
        if (autreBtn) autreBtn.hidden = ranked.length === 0;
        validateCard(card);
      });
    }
  }

  // ── Customer typeahead (manual fallback) ──────────────────────────────────
  function buildCustTypeahead(scope, card) {
    card = card || scope;
    var manualWrapper = qs('.eo-cust-manual', scope) || scope;
    var searchInput   = qs('.eo-cust-search', manualWrapper);
    var dropdown      = qs('.eo-cust-dropdown', manualWrapper);
    var hiddenId      = qs('.eo-customer-id', scope);
    var resolvedDiv   = qs('.eo-cust-resolved', scope);

    if (!searchInput || !dropdown || !hiddenId) return;

    function filterCustomers(q) {
      var lq = normalizeStr(q);
      return CUSTOMERS.filter(function(c) { return normalizeStr(c.name).includes(lq); }).slice(0, 20);
    }

    var selIdx = -1;

    function renderDropdown(q) {
      var results = filterCustomers(q);
      dropdown.innerHTML = '';
      selIdx = -1;
      if (!results.length) { dropdown.hidden = true; return; }
      results.forEach(function(c, i) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.dataset.idx = String(i);
        li.innerHTML = '<span>' + escHtml(c.name) + '</span>';
        li.addEventListener('mousedown', function(e) {
          e.preventDefault();
          searchInput.value = c.name;
          hiddenId.value    = String(c.id);
          if (resolvedDiv) {
            var lbl = qs('.eo-cust-resolved__label', resolvedDiv);
            if (lbl) lbl.textContent = c.name;
            resolvedDiv.hidden = false;
            var mw = qs('.eo-cust-manual', scope);
            if (mw) mw.hidden = true;
          }
          dropdown.hidden = true;
          selIdx = -1;
          validateCard(card);
        });
        dropdown.appendChild(li);
      });
      dropdown.hidden = false;
    }

    function moveFocus(dir) {
      if (dropdown.hidden) return;
      var items = qsa('li[role="option"]', dropdown);
      if (!items.length) return;
      items.forEach(function(li) { li.setAttribute('aria-selected', 'false'); });
      selIdx += dir;
      if (selIdx < 0) selIdx = items.length - 1;
      if (selIdx >= items.length) selIdx = 0;
      items[selIdx].setAttribute('aria-selected', 'true');
      items[selIdx].scrollIntoView({ block: 'nearest' });
    }

    searchInput.addEventListener('input', function() {
      hiddenId.value = '0';
      validateCard(card);
      if (resolvedDiv) resolvedDiv.hidden = true;
      var q = searchInput.value;
      if (q.length < 1) { dropdown.hidden = true; selIdx = -1; return; }
      renderDropdown(q);
    });

    searchInput.addEventListener('keydown', function(e) {
      if (e.key === 'ArrowDown')  { e.preventDefault(); moveFocus(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); moveFocus(-1); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        if (!dropdown.hidden && selIdx >= 0) {
          var sel = qsa('li[role="option"]', dropdown)[selIdx];
          if (sel) sel.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        }
      } else if (e.key === 'Escape') { dropdown.hidden = true; selIdx = -1; }
    });

    searchInput.addEventListener('blur', function() { setTimeout(function() { dropdown.hidden = true; selIdx = -1; }, 150); });
    searchInput.addEventListener('focus', function() { if (searchInput.value.length > 0) renderDropdown(searchInput.value); });
  }

  // ── SKU typeahead (manual fallback) ──────────────────────────────────────
  function buildSkuTypeahead(row, scope, card) {
    card = card || scope;
    var manualWrapper = qs('.eo-sku-manual', row) || row;
    var searchInput   = qs('.eo-sku-search', manualWrapper);
    var dropdown      = qs('.eo-sku-dropdown', manualWrapper);
    var hiddenId      = qs('.eo-sku-id', row);
    var resolvedDiv   = qs('.eo-sku-resolved', row);

    if (!searchInput || !dropdown || !hiddenId) return;

    function filterSkus(q) {
      var lq = normalizeStr(q);
      return SKUS.filter(function(s) {
        return normalizeStr(s.label || s.sku_code).includes(lq) || normalizeStr(s.sku_code).includes(lq);
      }).slice(0, 30);
    }

    var selIdx = -1;

    function renderDropdown(q) {
      var results = filterSkus(q);
      dropdown.innerHTML = '';
      selIdx = -1;
      if (!results.length) { dropdown.hidden = true; return; }
      results.forEach(function(s, i) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.dataset.idx = String(i);
        li.innerHTML =
          '<span class="eo-typeahead-label">' + escHtml(s.label || s.sku_code) + '</span>' +
          '<span class="eo-typeahead-code">'  + escHtml(s.sku_code) + '</span>';
        li.addEventListener('mousedown', function(e) {
          e.preventDefault();
          searchInput.value = s.label || s.sku_code;
          hiddenId.value    = String(s.id);
          if (resolvedDiv) {
            var lbl = qs('.eo-sku-resolved__label', resolvedDiv);
            if (lbl) lbl.textContent = s.label || s.sku_code;
            resolvedDiv.hidden = false;
            var mw = qs('.eo-sku-manual', row);
            if (mw) mw.hidden = true;
          }
          row.classList.add('eo-line-row--resolved');
          dropdown.hidden = true;
          selIdx = -1;
          validateCard(card);
        });
        dropdown.appendChild(li);
      });
      dropdown.hidden = false;
    }

    function moveFocus(dir) {
      if (dropdown.hidden) return;
      var items = qsa('li[role="option"]', dropdown);
      if (!items.length) return;
      items.forEach(function(li) { li.setAttribute('aria-selected', 'false'); });
      selIdx += dir;
      if (selIdx < 0) selIdx = items.length - 1;
      if (selIdx >= items.length) selIdx = 0;
      items[selIdx].setAttribute('aria-selected', 'true');
      items[selIdx].scrollIntoView({ block: 'nearest' });
    }

    searchInput.addEventListener('input', function() {
      hiddenId.value = '0';
      row.classList.remove('eo-line-row--resolved');
      if (resolvedDiv) resolvedDiv.hidden = true;
      validateCard(card);
      var q = searchInput.value;
      if (q.length < 1) { dropdown.hidden = true; selIdx = -1; return; }
      renderDropdown(q);
    });

    searchInput.addEventListener('keydown', function(e) {
      if (e.key === 'ArrowDown')  { e.preventDefault(); moveFocus(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); moveFocus(-1); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        if (!dropdown.hidden && selIdx >= 0) {
          var sel = qsa('li[role="option"]', dropdown)[selIdx];
          if (sel) sel.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        }
      } else if (e.key === 'Escape') { dropdown.hidden = true; selIdx = -1; }
    });

    searchInput.addEventListener('blur', function() { setTimeout(function() { dropdown.hidden = true; selIdx = -1; }, 150); });
    searchInput.addEventListener('focus', function() { if (searchInput.value.length > 0) renderDropdown(searchInput.value); });
  }

  // ── buildLineRow (JS-added rows) ──────────────────────────────────────────
  var globalLineCounter = 1000;

  function buildLineRow(scope, card, skuHint, qty) {
    card = card || scope;
    globalLineCounter++;
    var idx      = globalLineCounter;
    var subIndex = scope.dataset && scope.dataset.subIndex;
    var skuName  = (subIndex !== undefined && subIndex !== '') ? 'sub[' + subIndex + '][line_sku_id][]' : 'line_sku_id[]';
    var qtyName  = (subIndex !== undefined && subIndex !== '') ? 'sub[' + subIndex + '][line_qty][]'    : 'line_qty[]';

    var row = document.createElement('div');
    row.className    = 'eo-line-row';
    row.dataset.lineIdx = String(idx);

    // For JS-added rows: no chips (no hint context), just typeahead directly
    var manualDiv = document.createElement('div');
    manualDiv.className = 'eo-sku-manual';

    var skuWrap = document.createElement('div');
    skuWrap.className = 'eo-typeahead-wrap';

    var skuInput = document.createElement('input');
    skuInput.type        = 'text';
    skuInput.className   = 'eo-input eo-sku-search';
    skuInput.placeholder = 'Rechercher un SKU…';
    skuInput.autocomplete = 'off';
    skuInput.value       = '';

    var skuDrop = document.createElement('ul');
    skuDrop.className = 'eo-typeahead-dropdown eo-sku-dropdown';
    skuDrop.setAttribute('role', 'listbox');
    skuDrop.hidden = true;

    skuWrap.appendChild(skuInput);
    skuWrap.appendChild(skuDrop);
    manualDiv.appendChild(skuWrap);

    var skuHiddenId = document.createElement('input');
    skuHiddenId.type      = 'hidden';
    skuHiddenId.className = 'eo-sku-id';
    skuHiddenId.name      = skuName;
    skuHiddenId.value     = '0';

    var resolvedDiv = document.createElement('div');
    resolvedDiv.className = 'eo-sku-resolved';
    resolvedDiv.hidden    = true;
    var resolvedLabel = document.createElement('span');
    resolvedLabel.className = 'eo-sku-resolved__label';
    var resolvedClear = document.createElement('button');
    resolvedClear.type      = 'button';
    resolvedClear.className = 'eo-sku-resolved__clear';
    resolvedClear.setAttribute('aria-label', 'Changer');
    resolvedClear.textContent = '✎';
    resolvedClear.addEventListener('click', function() {
      skuHiddenId.value  = '0';
      resolvedDiv.hidden = true;
      manualDiv.hidden   = false;
      row.classList.remove('eo-line-row--resolved');
      validateCard(card);
    });
    resolvedDiv.appendChild(resolvedLabel);
    resolvedDiv.appendChild(resolvedClear);

    var qtyInput = document.createElement('input');
    qtyInput.type        = 'number';
    qtyInput.className   = 'eo-input eo-qty-input';
    qtyInput.name        = qtyName;
    qtyInput.min         = '0.01';
    qtyInput.step        = '0.5';
    qtyInput.placeholder = 'Qté';
    qtyInput.value       = qty > 0 ? String(qty) : '';
    qtyInput.addEventListener('input', function() { validateCard(card); });

    var removeBtn = document.createElement('button');
    removeBtn.type      = 'button';
    removeBtn.className = 'eo-line-remove';
    removeBtn.setAttribute('aria-label', 'Supprimer la ligne');
    removeBtn.textContent = '×';
    removeBtn.addEventListener('click', function() { row.remove(); validateCard(card); });

    row.appendChild(manualDiv);
    row.appendChild(skuHiddenId);
    row.appendChild(resolvedDiv);
    row.appendChild(qtyInput);
    row.appendChild(removeBtn);

    buildSkuTypeahead(row, scope, card);
    return row;
  }

  // ── initCard ─────────────────────────────────────────────────────────────
  function initCard(card) {
    qsa('.eo-suborder', card).forEach(function(suborder) {
      // Customer chips + typeahead
      buildCustChips(suborder, card);
      buildCustTypeahead(suborder, card);

      var dateInput = qs('.eo-requested-date', suborder);
      if (dateInput) dateInput.addEventListener('input', function() { validateCard(card); });

      // SKU chips + typeahead per line row
      qsa('.eo-line-row', suborder).forEach(function(row) {
        buildSkuChips(row, suborder, card);
        buildSkuTypeahead(row, suborder, card);
        var qtyInput = qs('.eo-qty-input', row);
        if (qtyInput) qtyInput.addEventListener('input', function() { validateCard(card); });
        var removeBtn = qs('.eo-line-remove', row);
        if (removeBtn) removeBtn.addEventListener('click', function() { row.remove(); validateCard(card); });
      });

      var addBtn         = qs('.eo-add-line-btn', suborder);
      var linesContainer = qs('.eo-lines', suborder);
      if (addBtn && linesContainer) {
        addBtn.addEventListener('click', function() {
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

  // ── Live poll (counts) ────────────────────────────────────────────────────
  var initialParsedCount = (function() {
    var badge = document.getElementById('eo-badge-review');
    return badge ? parseInt(badge.textContent, 10) || 0 : 0;
  })();

  function pollCounts() {
    fetch('/modules/email-orders.php?counts=1', { credentials: 'same-origin' })
      .then(function(r) { return r.ok ? r.json() : null; })
      .then(function(data) {
        if (!data) return;
        var rb = document.getElementById('eo-badge-review');
        var eb = document.getElementById('eo-badge-error');
        var db = document.getElementById('eo-badge-done');
        if (rb) rb.textContent = data.parsed;
        if (eb) eb.textContent = data.no_match;
        if (db) db.textContent = data.done;

        var banner = document.getElementById('eo-new-orders-banner');
        if (banner && data.parsed > initialParsedCount) {
          var n = data.parsed - initialParsedCount;
          banner.textContent = '↻ ' + n + ' nouvelle' + (n > 1 ? 's' : '') + ' commande' + (n > 1 ? 's' : '') + ' — cliquer pour charger';
          banner.hidden = false;
          banner.onclick = function() { window.location.reload(); };
        }
      })
      .catch(function() { /* silent */ });
  }

  var pollTimer = null;
  function startPoll() { if (!pollTimer) pollTimer = setInterval(pollCounts, 25000); }
  function stopPoll()  { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
  document.addEventListener('visibilitychange', function() {
    document.hidden ? stopPoll() : startPoll();
  });
  startPoll();

  // ── Bootstrap all cards ────────────────────────────────────────────────────
  document.querySelectorAll('.eo-review-card').forEach(function(card) { initCard(card); });

})();
