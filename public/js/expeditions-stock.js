/**
 * expeditions-stock.js — Stock PF view interaction.
 *
 * Handles:
 *  - Family filter chips (data-filter-format)
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
  var currentFormat  = 'all';  // 'all' | format string | 'alerts'
  var showDormantVal = false;
  var sortAlertsVal  = false;

  // ── Filter chips ────────────────────────────────────────────────────────
  filterWrap.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-filter-format]');
    if (!btn) return;

    currentFormat = btn.dataset.filterFormat;

    // Update aria-pressed + active class on all chips
    filterWrap.querySelectorAll('button[data-filter-format]').forEach(function (b) {
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
  }

  function isRowVisible(row) {
    var fmt       = row.dataset.format;
    var hasFlag   = row.dataset.hasFlag === '1';
    var isDormant = row.dataset.dormant === '1';

    // Dormant rows hidden unless checkbox is checked
    if (isDormant && !showDormantVal) return false;

    // Format/alert filter
    if (currentFormat === 'all')    return true;
    if (currentFormat === 'alerts') return hasFlag;
    return fmt === currentFormat;
  }

  // ── Apply sort + visibility (alerts-first mode) ──────────────────────────
  function applySortAndVisibility() {
    var rows = Array.prototype.slice.call(
      tbody.querySelectorAll('tr.exp-stock-row, tr.exp-stock-drill')
    );

    // Separate data rows from drill rows; build pairs
    var dataRows = rows.filter(function (r) { return r.classList.contains('exp-stock-row'); });

    if (!sortAlertsVal) {
      // Restore original PHP render order (stamped as data-sort-order at init time).
      var restored = dataRows.slice().sort(function (a, b) {
        return (parseInt(a.dataset.sortOrder, 10) || 0)
             - (parseInt(b.dataset.sortOrder, 10) || 0);
      });
      var restoreFrag = document.createDocumentFragment();
      restored.forEach(function (row) {
        var drill = document.getElementById('exp-drill-' + row.dataset.skuId);
        if (drill) { drill.hidden = true; row.setAttribute('aria-expanded', 'false'); }
        restoreFrag.appendChild(row);
        if (drill) restoreFrag.appendChild(drill);
      });
      tbody.appendChild(restoreFrag);
      applyVisibility();
      return;
    }

    // Partition: flagged first, then non-flagged; within each group keep existing DOM order
    var flagged    = dataRows.filter(function (r) { return r.dataset.hasFlag === '1'; });
    var nonFlagged = dataRows.filter(function (r) { return r.dataset.hasFlag !== '1'; });
    var sorted     = flagged.concat(nonFlagged);

    // Re-insert pairs in new order
    var frag = document.createDocumentFragment();
    sorted.forEach(function (row) {
      var sid   = row.dataset.skuId;
      var drill = document.getElementById('exp-drill-' + sid);
      // Collapse drill on re-sort
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
