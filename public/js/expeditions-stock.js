/**
 * expeditions-stock.js — Stock PF view interaction.
 *
 * Handles:
 *  - Family filter chips (data-filter-family — keyed by display_family)
 *  - Family group header visibility (exp-stock-family-header rows)
 *  - ⚠ alertes chip
 *  - "Afficher SKUs dormants" toggle
 *  - "Alertes d'abord" sort toggle
 *  - Row click → expand/collapse inline drill-down ledger
 *
 * No fetches — data is fully server-rendered.
 * All DOM mutations are class-based; no style= inline writes.
 */
(function () {
  'use strict';

  var tbody       = document.getElementById('exp-stock-tbody');
  var filterWrap  = document.querySelector('.exp-stock-filters');
  var showDormant = document.getElementById('exp-stock-show-dormant');
  var sortAlerts  = document.getElementById('exp-stock-sort-alerts');

  if (!tbody || !filterWrap) return;

  // Current filter state
  var currentFamily  = 'all';  // 'all' | display_family string | 'alerts'
  var showDormantVal = false;
  var sortAlertsVal  = false;

  // ── Filter chips ────────────────────────────────────────────────────────
  filterWrap.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-filter-family]');
    if (!btn) return;

    currentFamily = btn.dataset.filterFamily;

    // Update aria-pressed + active class on all chips
    filterWrap.querySelectorAll('button[data-filter-family]').forEach(function (b) {
      var active = b === btn;
      b.classList.toggle('exp-stock-chip--active', active);
      b.setAttribute('aria-pressed', active ? 'true' : 'false');
    });

    applyVisibility();
  });

  // ── Dormant toggle ──────────────────────────────────────────────────────
  if (showDormant) {
    showDormant.addEventListener('change', function () {
      showDormantVal = showDormant.checked;
      applyVisibility();
    });
  }

  // ── Alerts sort toggle ──────────────────────────────────────────────────
  if (sortAlerts) {
    sortAlerts.addEventListener('change', function () {
      sortAlertsVal = sortAlerts.checked;
      applySortAndVisibility();
    });
  }

  // ── Apply visibility filter ─────────────────────────────────────────────
  function applyVisibility() {
    if (sortAlertsVal) {
      applySortAndVisibility();
      return;
    }
    var rows = tbody.querySelectorAll('tr.exp-stock-row');
    rows.forEach(function (row) {
      var drill = document.getElementById('exp-drill-' + row.dataset.skuId);
      var visible = isRowVisible(row);
      row.hidden         = !visible;
      if (drill) drill.hidden = true;  // collapse drill on filter change
      if (visible) row.setAttribute('aria-expanded', 'false');
    });
    // Show/hide family group header rows based on whether any SKU in the group is visible
    tbody.querySelectorAll('tr.exp-stock-family-header').forEach(function (hdr) {
      var grp = hdr.dataset.familyGroup;
      var anyVisible = false;
      tbody.querySelectorAll('tr.exp-stock-row[data-family="' + CSS.escape(grp) + '"]').forEach(function (r) {
        if (!r.hidden) anyVisible = true;
      });
      hdr.hidden = !anyVisible;
    });
  }

  function isRowVisible(row) {
    var fam       = row.dataset.family;
    var hasFlag   = row.dataset.hasFlag === '1';
    var isDormant = row.dataset.dormant === '1';

    // Dormant rows hidden unless checkbox is checked
    if (isDormant && !showDormantVal) return false;

    // Family/alert filter
    if (currentFamily === 'all')    return true;
    if (currentFamily === 'alerts') return hasFlag;
    return fam === currentFamily;
  }

  // ── Apply sort + visibility (alerts-first mode) ──────────────────────────
  function applySortAndVisibility() {
    var allRows = Array.prototype.slice.call(
      tbody.querySelectorAll('tr.exp-stock-row, tr.exp-stock-drill, tr.exp-stock-family-header')
    );

    var dataRows = allRows.filter(function (r) { return r.classList.contains('exp-stock-row'); });

    if (!sortAlertsVal) {
      // Restore original PHP render order (stamped as data-sort-order at init time).
      var restored = dataRows.slice().sort(function (a, b) {
        return (parseInt(a.dataset.sortOrder, 10) || 0)
             - (parseInt(b.dataset.sortOrder, 10) || 0);
      });
      var restoreFrag = document.createDocumentFragment();
      // Re-insert: family headers stay above their group; rebuild in family order
      tbody.querySelectorAll('tr.exp-stock-family-header').forEach(function (hdr) {
        restoreFrag.appendChild(hdr);
        var grp = hdr.dataset.familyGroup;
        restored.filter(function (r) { return r.dataset.family === grp; }).forEach(function (row) {
          var drill = document.getElementById('exp-drill-' + row.dataset.skuId);
          if (drill) { drill.hidden = true; row.setAttribute('aria-expanded', 'false'); }
          restoreFrag.appendChild(row);
          if (drill) restoreFrag.appendChild(drill);
        });
      });
      tbody.appendChild(restoreFrag);
      applyVisibility();
      return;
    }

    // Alerts-first: flagged rows first (across families), then non-flagged
    var flagged    = dataRows.filter(function (r) { return r.dataset.hasFlag === '1'; });
    var nonFlagged = dataRows.filter(function (r) { return r.dataset.hasFlag !== '1'; });
    var sorted     = flagged.concat(nonFlagged);

    // Re-insert pairs in new order (family headers hidden in alerts mode)
    var frag = document.createDocumentFragment();
    // Hide all family headers during alerts sort
    tbody.querySelectorAll('tr.exp-stock-family-header').forEach(function (hdr) {
      hdr.hidden = true;
      frag.appendChild(hdr);
    });
    sorted.forEach(function (row) {
      var sid   = row.dataset.skuId;
      var drill = document.getElementById('exp-drill-' + sid);
      if (drill) { drill.hidden = true; row.setAttribute('aria-expanded', 'false'); }
      frag.appendChild(row);
      if (drill) frag.appendChild(drill);
    });
    tbody.appendChild(frag);

    // Now apply visibility on top
    applyVisibility();
  }

  // ── Row click → drill-down toggle ──────────────────────────────────────
  tbody.addEventListener('click', function (e) {
    // Ignore clicks on buttons or links inside the row
    if (e.target.closest('a, button, input')) return;

    var row = e.target.closest('tr.exp-stock-row');
    if (!row) return;
    if (row.hidden) return;

    var sid   = row.dataset.skuId;
    var drill = document.getElementById('exp-drill-' + sid);
    if (!drill) return;

    var isOpen = drill.hidden === false;
    drill.hidden = isOpen;
    row.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
    row.classList.toggle('exp-stock-row--open', !isOpen);
  });

  // ── Keyboard accessibility on data rows ─────────────────────────────────
  tbody.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var row = e.target.closest('tr.exp-stock-row');
    if (!row) return;
    e.preventDefault();
    row.click();
  });

  // Make data rows focusable for keyboard users; stamp original PHP render order
  // for sort-restore when "Alertes d'abord" is unchecked.
  tbody.querySelectorAll('tr.exp-stock-row').forEach(function (row, idx) {
    row.setAttribute('tabindex', '0');
    row.setAttribute('role', 'button');
    row.style.cursor = 'pointer';
    row.dataset.sortOrder = idx;
  });

  // ── Initial: hide dormant rows (default) ────────────────────────────────
  applyVisibility();

}());
