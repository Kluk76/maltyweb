/**
 * expeditions-stock.js — Stock PF view interaction.
 *
 * Handles:
 *  - Location card filter buttons (data-loc-id — "total" or numeric site id)
 *  - Family filter chips (data-filter-family — keyed by display_family), Total mode only
 *  - Family group header visibility (exp-stock-family-header rows)
 *  - ⚠ alertes chip
 *  - "Afficher SKUs dormants" toggle
 *  - "Alertes d'abord" sort toggle
 *  - Row click → expand/collapse inline drill-down ledger
 *
 * No fetches — data is fully server-rendered.
 * All DOM mutations are class-based; no style= inline writes.
 *
 * Location toggle model:
 *  - Default: "total" card active → #exp-loc-table-total visible, all per-location
 *    tables hidden, family chips visible.
 *  - Single-location: card for site N active → #exp-loc-table-total hidden,
 *    #exp-loc-table-{N} visible, family chips hidden (single-location tables
 *    show physical-count-only with no dispo/velocity columns).
 */
(function () {
  'use strict';

  // ── Total-view elements ─────────────────────────────────────────────────
  var tbody       = document.getElementById('exp-stock-tbody');
  var filterWrap  = document.querySelector('.exp-stock-filters');
  var showDormant = document.getElementById('exp-stock-show-dormant');
  var sortAlerts  = document.getElementById('exp-stock-sort-alerts');
  var totalView   = document.getElementById('exp-loc-table-total');
  var viewLabel   = document.getElementById('exp-stock-view-label');
  var locCards    = document.querySelectorAll('.exp-loc-card[data-loc-id]');

  // Collect all per-location view containers
  var locViews = {}; // locId (string) → element
  document.querySelectorAll('.exp-loc-view[data-loc-id]').forEach(function (el) {
    locViews[el.dataset.locId] = el;
  });

  // Current filter state (Total mode only)
  var currentFamily  = 'all';  // 'all' | display_family string | 'alerts'
  var showDormantVal = false;
  var sortAlertsVal  = false;

  // ── Location card selector ──────────────────────────────────────────────
  var locCardWrap = document.querySelector('.exp-loc-cards');
  if (locCardWrap) {
    locCardWrap.addEventListener('click', function (e) {
      var card = e.target.closest('button[data-loc-id]');
      if (!card) return;
      var locId = card.dataset.locId; // 'total' or numeric string
      selectLocation(locId, card);
    });

    locCardWrap.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      var card = e.target.closest('button[data-loc-id]');
      if (!card) return;
      e.preventDefault();
      card.click();
    });
  }

  function selectLocation(locId, clickedCard) {
    // Update aria-pressed on all cards
    locCards.forEach(function (c) {
      var active = c === clickedCard;
      c.setAttribute('aria-pressed', active ? 'true' : 'false');
      c.classList.toggle('exp-loc-card--active', active);
    });

    // Hide all views
    if (totalView) totalView.hidden = true;
    Object.keys(locViews).forEach(function (k) {
      locViews[k].hidden = true;
    });

    if (locId === 'total') {
      // Show total table + family chips
      if (totalView) totalView.hidden = false;
      if (filterWrap) filterWrap.hidden = false;
      if (viewLabel) viewLabel.textContent = 'Stock — Tous les sites';
    } else {
      // Show single-location table
      if (locViews[locId]) locViews[locId].hidden = false;
      // Hide family chips — not applicable in single-location mode
      if (filterWrap) filterWrap.hidden = true;
      // Update live label
      var cardName = clickedCard ? clickedCard.dataset.locName || locId : locId;
      var cardType = clickedCard ? clickedCard.dataset.locType || '' : '';
      if (viewLabel) {
        viewLabel.textContent = 'Stock — ' + cardName + (cardType ? ' (' + cardType + ')' : '');
      }
    }
  }

  // ── Family filter chips (Total mode only) ───────────────────────────────
  if (filterWrap) {
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
  }

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

  // ── Apply visibility filter (Total mode) ───────────────────────────────
  function applyVisibility() {
    if (!tbody) return;
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
    // Alert = rank 0–3 (survendu/epuise/critique/bas) — excludes sans_rotation/dormant
    var rank      = parseInt(row.dataset.stockRank, 10);
    var isAlert   = !isNaN(rank) && rank <= 3;

    // Dormant rows hidden unless checkbox is checked
    if (isDormant && !showDormantVal) return false;

    // Family/alert filter
    if (currentFamily === 'all')    return true;
    if (currentFamily === 'alerts') return isAlert || hasFlag; // backward-compat with old flag
    return fam === currentFamily;
  }

  // ── Apply sort + visibility (alerts-first mode) ──────────────────────────
  function applySortAndVisibility() {
    if (!tbody) return;
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

    // Alerts-first: sort by stock-health rank ASC (survendu=0 first), then sku_code
    var sorted = dataRows.slice().sort(function (a, b) {
      var ra = parseInt(a.dataset.stockRank, 10);
      var rb = parseInt(b.dataset.stockRank, 10);
      if (ra !== rb) return ra - rb;
      var ca = a.querySelector('.exp-st-sku-code');
      var cb = b.querySelector('.exp-st-sku-code');
      return (ca ? ca.textContent : '').localeCompare(cb ? cb.textContent : '');
    });

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

  // ── Row click → drill-down toggle (Total mode only) ─────────────────────
  if (tbody) {
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

    // ── Keyboard accessibility on data rows ───────────────────────────────
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
  }

  // ── Initial state: Total mode (default) ─────────────────────────────────
  // Find the "total" card and activate it (it already has aria-pressed=true
  // from server render; we just need to ensure JS state is consistent)
  var totalCard = document.querySelector('button.exp-loc-card[data-loc-id="total"]');
  if (totalCard) {
    // Ensure total view is visible, all location views hidden
    if (totalView) totalView.hidden = false;
    Object.keys(locViews).forEach(function (k) {
      locViews[k].hidden = true;
    });
    // Family chips visible in total mode
    if (filterWrap) filterWrap.hidden = false;
  }

  // Apply initial visibility for dormant rows in Total mode
  if (tbody) applyVisibility();

}());
