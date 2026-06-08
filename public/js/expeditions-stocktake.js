/**
 * expeditions-stocktake.js — FG Inventaire multi-site saisie JS.
 *
 * Reads:
 *   window.EXP_ST_SKUS      [{id, sku_code, format, hl_per_unit}]
 *   window.EXP_ST_PRIOR     {loc_id: {sku_id: {qty, counted_at, month_closed}}}
 *   window.EXP_ST_SITES     [{id, name, site_type, sort_order, notes, is_consignment}]
 *   window.EXP_ST_FRESHNESS {loc_id: last_counted_date_or_null}
 *   window.EXP_ST_TODAY     'YYYY-MM-DD'
 *   window.EXP_CSRF         string
 *
 * Responsibilities:
 *   - Running summary: count of entered SKUs + total HL
 *   - Submit-button date label sync
 *   - Search/filter: show/hide rows + update family counts
 *   - Highlight rows where a qty has been entered
 *   - Family collapse (header click)
 *   - Prior-count prefill-hint visibility
 *
 * PRG form — submit is a regular POST. No AJAX on submit.
 * Vanilla JS only. No external libraries. XSS: only textContent / numeric values.
 */

'use strict';

(function () {

  /* ── Data from server ──────────────────────────────────────────────────── */
  const SKUS      = window.EXP_ST_SKUS      || [];
  const PRIOR     = window.EXP_ST_PRIOR     || {};
  const TODAY     = window.EXP_ST_TODAY     || '';

  /* ── Build sku_id → hl_per_unit lookup ────────────────────────────────── */
  const hlBySkuId = {};
  SKUS.forEach(function (s) { hlBySkuId[s.id] = s.hl_per_unit; });

  /* ── Utility ────────────────────────────────────────────────────────────── */
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  /* ── DOM references ─────────────────────────────────────────────────────── */
  const form         = qs('#exp-st-form');
  const searchInput  = qs('#exp-st-search');
  const countEl      = qs('#exp-st-count');
  const hlEl         = qs('#exp-st-hl');
  const submitBtn    = qs('#exp-st-submit');
  const submitDateEl = qs('#exp-st-submit-date');
  const countedAtEl  = qs('#exp-st-counted-at');
  const allRows      = qsa('.exp-st-row');
  const qtyInputs    = qsa('.exp-st-qty-input');

  if (!form) return; // Guard: only run on stocktake view

  /* ── Format a date 'YYYY-MM-DD' → 'dd/mm/yyyy' (DMY system-wide) ───────── */
  function fmtDate(iso) {
    if (!iso || iso.length < 10) return iso || '';
    return iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4);
  }

  /* ── Running summary ──────────────────────────────────────────────────── */
  function recompute() {
    var count = 0;
    var totalHl = 0.0;
    qtyInputs.forEach(function (inp) {
      var raw = inp.value.trim();
      if (raw === '') return;
      var qty = parseFloat(raw);
      if (isNaN(qty) || qty < 0) return;
      count++;
      var sid = parseInt(inp.closest('.exp-st-row').dataset.skuId, 10);
      var hpu = hlBySkuId[sid] || 0;
      totalHl += qty * hpu;

      // Highlight row when entered
      inp.closest('.exp-st-row').classList.toggle('exp-st-row--entered', true);
    });

    // Clear entered flag on blank rows
    qtyInputs.forEach(function (inp) {
      if (inp.value.trim() === '') {
        inp.closest('.exp-st-row').classList.remove('exp-st-row--entered');
      }
    });

    if (countEl) {
      countEl.textContent = count + ' SKU' + (count !== 1 ? 's' : '') + ' saisi' + (count !== 1 ? 's' : '');
    }
    if (hlEl) {
      // Format as fr-CH: space thousand-separator, comma decimal
      hlEl.textContent = formatHl(totalHl) + ' HL';
    }

    updateFamilyCounts();
  }

  function formatHl(val) {
    return val.toLocaleString('fr-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  /* ── Update per-family count labels ──────────────────────────────────────── */
  function updateFamilyCounts() {
    qsa('.exp-st-family').forEach(function (fam) {
      var slug = fam.dataset.family;
      var visibleRows = qsa('.exp-st-row:not(.exp-st-row--hidden)', fam);
      var enteredCount = visibleRows.filter(function (r) {
        return r.classList.contains('exp-st-row--entered');
      }).length;
      var countLabel = qs('#exp-st-fc-' + slug);
      if (countLabel) {
        var total = visibleRows.length;
        if (enteredCount > 0) {
          countLabel.textContent = enteredCount + '/' + total + ' renseigné' + (enteredCount !== 1 ? 's' : '');
        } else {
          countLabel.textContent = total + ' SKU' + (total !== 1 ? 's' : '');
        }
      }
    });
  }

  /* ── Submit-button date label ─────────────────────────────────────────── */
  function syncSubmitDate() {
    if (!countedAtEl || !submitDateEl) return;
    var val = countedAtEl.value;
    submitDateEl.textContent = fmtDate(val) || val;
  }

  if (countedAtEl) {
    countedAtEl.addEventListener('change', syncSubmitDate);
    syncSubmitDate(); // initial
  }

  /* ── Search filter ────────────────────────────────────────────────────── */
  function applyFilter() {
    var q = searchInput ? searchInput.value.trim().toUpperCase() : '';
    allRows.forEach(function (row) {
      var code = (row.dataset.skuCode || '').toUpperCase();
      var hidden = (q !== '' && code.indexOf(q) === -1);
      row.classList.toggle('exp-st-row--hidden', hidden);
    });

    // Show/hide entire family if all rows hidden
    qsa('.exp-st-family').forEach(function (fam) {
      var visibleRows = qsa('.exp-st-row:not(.exp-st-row--hidden)', fam);
      fam.classList.toggle('exp-st-family--hidden', visibleRows.length === 0);
    });

    updateFamilyCounts();
  }

  if (searchInput) {
    searchInput.addEventListener('input', applyFilter);
  }

  /* ── Wire qty inputs ─────────────────────────────────────────────────── */
  qtyInputs.forEach(function (inp) {
    inp.addEventListener('input', recompute);
    inp.addEventListener('change', recompute);
  });

  /* ── Family header collapse/expand (optional UX touch) ───────────────── */
  qsa('.exp-st-family-header').forEach(function (header) {
    header.style.cursor = 'pointer';
    header.setAttribute('title', 'Cliquer pour plier/déplier la famille');
    header.addEventListener('click', function () {
      var fam = header.closest('.exp-st-family');
      var rows = qs('.exp-st-family-rows', fam);
      if (!rows) return;
      var isCollapsed = rows.hidden;
      rows.hidden = !isCollapsed;
      header.setAttribute('aria-expanded', isCollapsed ? 'true' : 'false');
    });
  });

  /* ── Prevent double-submit ───────────────────────────────────────────── */
  if (form && submitBtn) {
    form.addEventListener('submit', function () {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Enregistrement…';
    });
  }

  /* ── Initial recompute ─────────────────────────────────────────────────── */
  recompute();

})();
