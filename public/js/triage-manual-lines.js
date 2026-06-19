/**
 * triage-manual-lines.js — Manual line builder for invoice triage
 *
 * Manages a dynamic table of invoice lines (description + MI + qty + unit price).
 * Computes per-row totals and an overall delta vs invoice total_ht live.
 * Submit button enabled only when ≥ 1 complete line exists.
 *
 * No external dependencies. Vanilla JS.
 */

(function () {
  'use strict';

  const form       = document.getElementById('manual-lines-form');
  const tbody      = document.getElementById('manual-lines-tbody');
  const addBtn     = document.getElementById('manual-lines-add');
  const submitBtn  = document.getElementById('manual-lines-submit');
  const totalEl    = document.getElementById('manual-lines-total');
  const deltaEl    = document.getElementById('manual-lines-delta');

  if (!form || !tbody) return; // Not on this page / wrong row type

  // Invoice total_ht supplied by PHP in a data attribute (null if unknown)
  const invoiceTotalHt = form.dataset.invoiceTotalHt !== undefined
    ? parseFloat(form.dataset.invoiceTotalHt)
    : null;

  let lineCount = 0;

  // ── Add a new row ────────────────────────────────────────────────────────────
  function addLine() {
    const idx = lineCount++;
    const tr  = document.createElement('tr');
    tr.className = 'manual-line-row';
    tr.dataset.lineIdx = String(idx);

    tr.innerHTML =
      '<td class="ml-cell ml-cell--num">' + (idx + 1) + '</td>'
      + '<td class="ml-cell ml-cell--desc">'
      +   '<input class="manual-line-input" type="text"'
      +     ' name="lines[' + idx + '][description]"'
      +     ' placeholder="Description de la ligne"'
      +     ' maxlength="500"'
      +     ' autocomplete="off">'
      + '</td>'
      + '<td class="ml-cell ml-cell--mi">'
      +   '<input class="manual-line-mi" type="text"'
      +     ' name="lines[' + idx + '][mi_id]"'
      +     ' list="mi-options"'
      +     ' placeholder="MI_CODE"'
      +     ' autocomplete="off"'
      +     ' spellcheck="false">'
      + '</td>'
      + '<td class="ml-cell ml-cell--qty">'
      +   '<input class="manual-line-num" type="number"'
      +     ' name="lines[' + idx + '][qty]"'
      +     ' placeholder="0"'
      +     ' min="0" step="any">'
      + '</td>'
      + '<td class="ml-cell ml-cell--price">'
      +   '<input class="manual-line-num" type="number"'
      +     ' name="lines[' + idx + '][unit_price]"'
      +     ' placeholder="0.00 HT"'
      +     ' title="Prix unitaire hors taxe et hors remise"'
      +     ' min="0" step="0.0001">'
      + '</td>'
      + '<td class="ml-cell ml-cell--total">'
      +   '<span class="manual-line-total">—</span>'
      + '</td>'
      + '<td class="ml-cell ml-cell--remove">'
      +   '<button type="button" class="manual-line-remove" aria-label="Supprimer la ligne">&times;</button>'
      + '</td>';

    tbody.appendChild(tr);
    wireRow(tr);
    recomputeTotals();
    validateSubmit();

    // Focus the description input of the new row
    const descInput = tr.querySelector('.manual-line-input');
    if (descInput) descInput.focus();
  }

  // ── Wire inputs on a row ─────────────────────────────────────────────────────
  function wireRow(tr) {
    const inputs = tr.querySelectorAll('input');
    inputs.forEach(function (inp) {
      inp.addEventListener('input', function () {
        updateRowTotal(tr);
        recomputeTotals();
        validateSubmit();
      });
    });

    const removeBtn = tr.querySelector('.manual-line-remove');
    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        removeLine(tr);
      });
    }
  }

  // ── Update the computed total for a single row ───────────────────────────────
  function updateRowTotal(tr) {
    const qtyInput   = tr.querySelector('input[name*="[qty]"]');
    const priceInput = tr.querySelector('input[name*="[unit_price]"]');
    const totalSpan  = tr.querySelector('.manual-line-total');
    if (!qtyInput || !priceInput || !totalSpan) return;

    const qty   = parseFloat(qtyInput.value);
    const price = parseFloat(priceInput.value);

    if (isFinite(qty) && qty > 0 && isFinite(price) && price >= 0) {
      const total = qty * price;
      totalSpan.textContent = total.toFixed(2);
      totalSpan.dataset.value = String(total);
    } else {
      totalSpan.textContent = '—';
      totalSpan.dataset.value = '';
    }
  }

  // ── Remove a row and re-index the # column ────────────────────────────────────
  function removeLine(tr) {
    tr.remove();
    // Re-number the # cells
    const rows = tbody.querySelectorAll('.manual-line-row');
    rows.forEach(function (row, i) {
      const numCell = row.querySelector('.ml-cell--num');
      if (numCell) numCell.textContent = String(i + 1);
    });
    recomputeTotals();
    validateSubmit();
  }

  // ── Recompute grand total + delta ────────────────────────────────────────────
  function recomputeTotals() {
    let sum = 0;
    tbody.querySelectorAll('.manual-line-total').forEach(function (span) {
      const v = parseFloat(span.dataset.value || '');
      if (isFinite(v)) sum += v;
    });

    if (totalEl) {
      totalEl.textContent = sum.toFixed(2) + ' CHF';
    }

    if (deltaEl) {
      if (invoiceTotalHt === null || !isFinite(invoiceTotalHt)) {
        deltaEl.textContent = '';
        deltaEl.className   = 'manual-delta';
        return;
      }
      const delta    = sum - invoiceTotalHt;
      const deltaAbs = Math.abs(delta);
      const sign     = delta >= 0 ? '+' : '';
      deltaEl.textContent = 'Δ vs facture : ' + sign + delta.toFixed(2) + ' CHF';

      deltaEl.className = 'manual-delta';
      if (deltaAbs <= 1) {
        deltaEl.classList.add('manual-delta--ok');
      } else if (deltaAbs <= 5) {
        deltaEl.classList.add('manual-delta--warn');
      } else {
        deltaEl.classList.add('manual-delta--err');
      }
    }
  }

  // ── Enable/disable submit based on form completeness ─────────────────────────
  function validateSubmit() {
    if (!submitBtn) return;

    const rows = tbody.querySelectorAll('.manual-line-row');
    if (rows.length === 0) {
      submitBtn.disabled = true;
      return;
    }

    let hasComplete = false;
    rows.forEach(function (tr) {
      const desc  = (tr.querySelector('input[name*="[description]"]') || {}).value || '';
      const mi    = (tr.querySelector('input[name*="[mi_id]"]') || {}).value || '';
      const qty   = parseFloat((tr.querySelector('input[name*="[qty]"]') || {}).value || '');
      const price = parseFloat((tr.querySelector('input[name*="[unit_price]"]') || {}).value || '');

      if (desc.trim() !== '' && mi.trim() !== ''
          && isFinite(qty) && qty > 0
          && isFinite(price) && price >= 0) {
        hasComplete = true;
      }
    });

    submitBtn.disabled = !hasComplete;

    // Update button label with line count
    const completeCount = tbody.querySelectorAll('.manual-line-row').length;
    if (hasComplete && submitBtn) {
      submitBtn.textContent = 'Sauvegarder ' + completeCount
        + ' ligne' + (completeCount > 1 ? 's' : '');
    }
  }

  // ── Wire add button ──────────────────────────────────────────────────────────
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      addLine();
    });
  }

  // ── Init: add the first empty line ───────────────────────────────────────────
  addLine();
  // After first addLine(), submit should be disabled (empty line)
  if (submitBtn) submitBtn.disabled = true;

})();
