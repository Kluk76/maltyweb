/**
 * expeditions-stocktake.js — FG Inventaire multi-site saisie JS.
 *
 * Reads:
 *   window.EXP_ST_SKUS      [{id, sku_code, format, hl_per_unit, is_cage, stocktake_scope}]
 *   window.EXP_ST_PRIOR     {loc_id: {sku_id: {qty, counted_at, month_closed}}}
 *   window.EXP_ST_SITES     [{id, name, site_type, sort_order, notes, is_consignment}]
 *   window.EXP_ST_FRESHNESS {loc_id: last_counted_date_or_null}
 *   window.EXP_ST_TODAY     'YYYY-MM-DD'
 *   window.EXP_CSRF         string
 *   window.EXP_ST_IS_MANAGER  boolean — true for manager and admin roles
 *   window.EXP_ST_SEL_LOC_ID  int — currently selected location id
 *
 * Responsibilities:
 *   - Running summary: count of entered SKUs + total HL
 *     Cage rows: input is whole bottles; HL = bottles × hl_per_unit (0.00330 post-redenomination)
 *   - Submit-button date label sync
 *   - For managers: date-picker change navigates to ?view=stocktake&loc=X&date=YYYY-MM-DD
 *     to reload the page with the correct prefill. No inline onclick.
 *   - Search/filter: show/hide rows + update family counts
 *   - Highlight rows where a qty has been entered
 *   - Family collapse (header click)
 *   - Cage live hint: bottles → "= Y.YY hl"
 *
 * Cage SKUs are identified by data-is-cage="1" on the row.
 * Input is in whole bottles (integers), stored as-entered (post-redenomination).
 *
 * Visibility (scope × site_type) is enforced server-side: PHP renders only
 * the SKU rows permitted at the selected location. The form never contains
 * out-of-scope rows, and the POST handler rejects them as a defense-in-depth.
 * stocktake_scope is included in EXP_ST_SKUS for informational use only.
 *
 * PRG form — submit is a regular POST. No AJAX on submit.
 * Vanilla JS only. No external libraries. XSS: only textContent / numeric values.
 */

'use strict';

(function () {

  /* ── Data from server ──────────────────────────────────────────────────── */
  const SKUS       = window.EXP_ST_SKUS       || [];
  const PRIOR      = window.EXP_ST_PRIOR      || {};
  const TODAY      = window.EXP_ST_TODAY      || '';
  const IS_MANAGER = window.EXP_ST_IS_MANAGER || false;
  const SEL_LOC_ID = window.EXP_ST_SEL_LOC_ID || 0;

  /* ── Build sku_id → {hl_per_unit, is_cage, stocktake_scope} lookup ── */
  const skuMeta = {};
  SKUS.forEach(function (s) {
    skuMeta[s.id] = {
      hl_per_unit:     s.hl_per_unit,
      is_cage:         s.is_cage || false,
      stocktake_scope: s.stocktake_scope || 'none',
    };
  });

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

  /* ── Format HL: fr-CH locale (space thousands, comma decimal) ────────────── */
  function formatHl(val) {
    return val.toLocaleString('fr-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  /* ── Cage live hint: update "= Y.YY hl" for one row ─────────────────── */
  // Post-redenomination: input is integer bottles; hl_per_unit = 0.00330 per bottle.
  function updateCageLiveHint(row, inp) {
    var sid = parseInt(row.dataset.skuId, 10);
    var meta = skuMeta[sid];
    if (!meta || !meta.is_cage) return;

    var hintEl = qs('#exp-st-cage-live-' + sid);
    if (!hintEl) return;

    var raw = inp.value.trim();
    if (raw === '') {
      hintEl.textContent = '';
      return;
    }
    var bottles = parseFloat(raw);
    if (isNaN(bottles) || bottles < 0) {
      hintEl.textContent = '';
      return;
    }
    // Input is bottles; hl_per_unit = 0.00330/bottle post-redenomination.
    var hlVal = bottles * meta.hl_per_unit;
    hintEl.textContent = '= ' + formatHl(hlVal) + ' hl';
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

      var row = inp.closest('.exp-st-row');
      var sid = parseInt(row.dataset.skuId, 10);
      var meta = skuMeta[sid] || {};
      var hpu  = meta.hl_per_unit || 0;

      // All SKU types: qty × hl_per_unit. Post-redenomination cage units = bottles,
      // hl_per_unit = 0.00330/bottle. Non-cage: qty in pack units, hl_per_unit per pack.
      totalHl += qty * hpu;

      // Highlight row when entered
      row.classList.add('exp-st-row--entered');
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
      hlEl.textContent = formatHl(totalHl) + ' HL';
    }

    updateFamilyCounts();
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

    /* ── Manager: date-picker change reloads the page with ?date= ────────── */
    // This triggers a GET reload so the PHP prefill map is built for the new date.
    // Operators have a readonly picker so this listener is harmless for them but
    // IS_MANAGER is an explicit gate.
    if (IS_MANAGER) {
      countedAtEl.addEventListener('change', function () {
        var val = countedAtEl.value;
        if (!val || val.length < 10) return;
        // Build URL: preserve current loc, add ?date= (drop ?date= if it equals today)
        var base = '/modules/expeditions.php?view=stocktake';
        if (SEL_LOC_ID) base += '&loc=' + SEL_LOC_ID;
        if (val !== TODAY) base += '&date=' + encodeURIComponent(val);
        window.location.href = base;
      });
    }
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
    var row = inp.closest('.exp-st-row');
    inp.addEventListener('input', function () {
      recompute();
      if (row && row.dataset.isCage === '1') {
        updateCageLiveHint(row, inp);
      }
    });
    inp.addEventListener('change', function () {
      recompute();
      if (row && row.dataset.isCage === '1') {
        updateCageLiveHint(row, inp);
      }
    });
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

  /* ── Initial recompute (also handles prefilled values) ─────────────────── */
  recompute();
  // For cage rows that were prefilled server-side, trigger the live hint.
  qsa('.exp-st-row--cage .exp-st-qty-input').forEach(function (inp) {
    var row = inp.closest('.exp-st-row');
    if (inp.value.trim() !== '') {
      updateCageLiveHint(row, inp);
    }
  });

})();
