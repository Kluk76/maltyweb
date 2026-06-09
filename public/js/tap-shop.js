/* ════════════════════════════════════════════════════════════════
   tap-shop.js — Tap&Shop direct-sales tracker
   Hydrates from:
     window.TS_DATA   — {periods, sales_by_period, beer_table, fg_stock,
                          totals, anchor_month, anchor_date,
                          eshop_end, taproom_end}
   Uses:
     window.KpcCharts — buildBarChart(), fmt(), escHtml(), KPC_MONTHS_FR
   Read-only. Zero writes.
   ════════════════════════════════════════════════════════════════ */
'use strict';

(function () {
  var D = window.TS_DATA;
  var KC = window.KpcCharts;
  if (!D || !KC) return;

  var escHtml = KC.escHtml;
  var fmt     = KC.fmt;

  /* ── State ─────────────────────────────────────────────────── */
  var state = {
    period: D.periods && D.periods.length ? D.periods[D.periods.length - 1] : null,
    metric: 'hl',   // 'hl' | 'chf'
  };

  /* ── Period button strip ────────────────────────────────────── */
  function renderPeriodBtns() {
    var bar = document.getElementById('ts-period-bar');
    if (!bar || !D.periods) return;
    var html = '<label>Période :</label>';
    D.periods.forEach(function (p) {
      var active = p === state.period ? ' ts-period-btn--active' : '';
      html += '<button class="ts-period-btn' + active + '" data-period="' + escHtml(p) + '">'
              + escHtml(p) + '</button>';
    });
    bar.innerHTML = html;
    bar.querySelectorAll('.ts-period-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        state.period = btn.getAttribute('data-period');
        renderAll();
      });
    });
  }

  /* ── Toggle HL / CHF ────────────────────────────────────────── */
  function renderToggle() {
    var row = document.getElementById('ts-toggle-row');
    if (!row) return;
    row.querySelectorAll('.ts-toggle-btn').forEach(function (btn) {
      var m = btn.getAttribute('data-metric');
      btn.classList.toggle('ts-toggle-btn--active', m === state.metric);
      btn.addEventListener('click', function () {
        state.metric = m;
        renderAll();
      });
    });
  }

  /* ── Sales-over-time chart ──────────────────────────────────── */
  function renderSalesChart() {
    var wrap = document.getElementById('ts-chart-overtime');
    if (!wrap) return;

    var periods = D.periods || [];
    if (!periods.length) {
      wrap.innerHTML = '<div class="ts-chart-empty">Aucune donnée</div>';
      return;
    }

    var isHl = state.metric === 'hl';
    var points = periods.map(function (p) {
      var row = D.sales_by_period[p] || {};
      var eshopVal   = isHl ? (row.eshop_hl   || 0) : (row.eshop_chf   || 0);
      var taproomVal = isHl ? (row.taproom_hl  || 0) : (row.taproom_chf || 0);
      return [eshopVal, taproomVal];
    });

    var series = [
      { label: 'Eshop',   color: KC.kpcTok('--hop')   || '#567020' },
      { label: 'Taproom', color: KC.kpcTok('--ember') || '#b34428' },
    ];

    wrap.innerHTML = '';
    KC.buildBarChart(wrap, points, series, {
      yUnit:   isHl ? 'HL' : 'CHF',
      height:  200,
      labels:  periods,
      stacked: false,
    });
  }

  /* ── Top-beers chart ────────────────────────────────────────── */
  function renderTopBeersChart() {
    var wrap = document.getElementById('ts-chart-topbeers');
    if (!wrap) return;

    var rows = D.beer_table || [];
    var isHl = state.metric === 'hl';
    var pRows = rows.filter(function (r) { return r.sku_id !== null; });
    if (state.period !== 'ALL') {
      pRows = pRows.filter(function (r) {
        var ps = r.period_data && r.period_data[state.period];
        return ps && (isHl ? ps.total_hl : ps.total_chf) > 0;
      });
    }

    // Aggregate per beer (recipe_id)
    var beerMap = {};
    pRows.forEach(function (r) {
      var key = r.recipe_id || r.sku_id;
      var ps  = state.period !== 'ALL'
                ? (r.period_data && r.period_data[state.period] || {})
                : { total_hl: r.total_hl_all, total_chf: r.total_chf_all };
      var val = isHl ? (ps.total_hl || 0) : (ps.total_chf || 0);
      if (!beerMap[key]) {
        beerMap[key] = { label: r.beer_name || r.sku_code, val: 0 };
      }
      beerMap[key].val += val;
    });

    var entries = Object.values(beerMap)
      .filter(function (e) { return e.val > 0; })
      .sort(function (a, b) { return b.val - a.val; })
      .slice(0, 10);

    if (!entries.length) {
      wrap.innerHTML = '<div class="ts-chart-empty">Aucune vente sur cette période</div>';
      return;
    }

    // Single-series horizontal bar simulation via vertical bar chart
    var points = entries.map(function (e) { return [e.val]; });
    var series = [{ label: isHl ? 'HL' : 'CHF', color: KC.kpcTok('--hop') || '#567020' }];

    wrap.innerHTML = '';
    KC.buildBarChart(wrap, points, series, {
      yUnit:  isHl ? 'HL' : 'CHF',
      height: 200,
      labels: entries.map(function (e) { return e.label; }),
    });
  }

  /* ── Per-beer table ─────────────────────────────────────────── */
  function renderBeerTable() {
    var tbody = document.getElementById('ts-beer-tbody');
    if (!tbody) return;

    var rows = D.beer_table || [];
    var isHl = state.metric === 'hl';

    // Per-period filter
    var fRows = rows.map(function (r) {
      var ps = state.period !== 'ALL'
               ? (r.period_data && r.period_data[state.period] || {})
               : { total_qty: r.total_qty_all, total_hl: r.total_hl_all, total_chf: r.total_chf_all };
      return Object.assign({}, r, {
        _qty:  parseFloat(ps.total_qty  || 0),
        _hl:   parseFloat(ps.total_hl   || 0),
        _chf:  parseFloat(ps.total_chf  || 0),
      });
    });

    // Group by display_family
    var familyGroups = {};
    var familyOrder  = [];
    fRows.forEach(function (r) {
      var fam = r.display_family || 'Autre';
      if (!familyGroups[fam]) {
        familyGroups[fam] = [];
        familyOrder.push(fam);
      }
      familyGroups[fam].push(r);
    });

    var html = '';
    var grandQty = 0, grandHl = 0, grandChf = 0;

    familyOrder.forEach(function (fam) {
      var famRows = familyGroups[fam];
      var famQty = 0, famHl = 0, famChf = 0;

      // Family header
      html += '<tr class="ts-family-row"><td colspan="7">' + escHtml(fam) + '</td></tr>';

      famRows.forEach(function (r) {
        famQty  += r._qty;
        famHl   += r._hl;
        famChf  += r._chf;
        grandQty += r._qty;
        grandHl  += r._hl;
        grandChf += r._chf;

        var stockRow = r.sku_id ? (D.fg_stock[r.sku_id] || null) : null;
        var physique    = stockRow ? stockRow.physique         : null;
        var velWeekly   = stockRow ? stockRow.velocity_weekly  : null;
        var semaines    = stockRow ? stockRow.semaines_stock   : null;
        var flSurvendu  = stockRow ? stockRow.flag_survendu    : false;
        var flLow       = stockRow ? stockRow.flag_low_stock   : false;
        var flDormant   = stockRow ? stockRow.flag_dormant     : false;

        var physiqueHtml = '';
        if (physique !== null) {
          var physNum = parseFloat(physique);
          var cls = physNum === 0 ? ' ts-physique--zero' : '';
          physiqueHtml = '<span class="ts-physique' + cls + '">' + fmt(physNum, 0) + '</span>';
          if (flSurvendu) physiqueHtml += '<span class="ts-flag ts-flag--survendu">SURVENDU</span>';
          else if (flLow) physiqueHtml += '<span class="ts-flag ts-flag--low">FAIBLE</span>';
          else if (flDormant) physiqueHtml += '<span class="ts-flag ts-flag--dormant">DORMANT</span>';
        } else {
          physiqueHtml = '<span class="ts-physique ts-physique--zero">—</span>';
        }

        var semHtml = '';
        if (semaines !== null && semaines !== undefined) {
          semHtml = '<span class="ts-cover">' + fmt(parseFloat(semaines), 1) + ' sem.</span>';
        } else if (velWeekly === null || velWeekly === 0) {
          semHtml = '<span class="ts-cover ts-cover--inf">∞</span>';
        } else {
          semHtml = '<span class="ts-cover ts-cover--inf">—</span>';
        }

        var isUnresolved = r.sku_id === null;
        var rowClass = isUnresolved ? ' class="ts-unresolved-row"' : '';

        html += '<tr' + rowClass + '>';
        html += '<td>';
        if (!isUnresolved && r.sku_code) {
          html += '<span class="ts-sku-pill">' + escHtml(r.sku_code) + '</span>';
        }
        html += escHtml(r.beer_name || r.sku_code || 'Non rattaché');
        html += '</td>';
        html += '<td>' + escHtml(r.channel || '—') + '</td>';
        html += '<td>' + fmt(r._qty, 0) + '</td>';
        html += '<td>' + (r._hl > 0 ? fmt(r._hl, 2) : '—') + '</td>';
        html += '<td>' + (r._chf > 0 ? fmt(r._chf, 0) + ' CHF' : '—') + '</td>';
        html += '<td>' + physiqueHtml + '</td>';
        html += '<td>' + semHtml + '</td>';
        html += '</tr>';
      });

      // Family subtotal
      html += '<tr class="ts-subtotal-row">';
      html += '<td colspan="2">Sous-total ' + escHtml(fam) + '</td>';
      html += '<td>' + fmt(famQty, 0) + '</td>';
      html += '<td>' + (famHl > 0 ? fmt(famHl, 2) : '—') + '</td>';
      html += '<td>' + (famChf > 0 ? fmt(famChf, 0) + ' CHF' : '—') + '</td>';
      html += '<td colspan="2"></td>';
      html += '</tr>';
    });

    tbody.innerHTML = html;

    // Update grand totals
    var elQty  = document.getElementById('ts-grand-qty');
    var elHl   = document.getElementById('ts-grand-hl');
    var elChf  = document.getElementById('ts-grand-chf');
    if (elQty)  elQty.textContent  = fmt(grandQty, 0);
    if (elHl)   elHl.textContent   = fmt(grandHl, 2) + ' HL';
    if (elChf)  elChf.textContent  = fmt(grandChf, 0) + ' CHF';
  }

  /* ── Master render ──────────────────────────────────────────── */
  function renderAll() {
    renderPeriodBtns();
    renderToggle();
    renderSalesChart();
    renderTopBeersChart();
    renderBeerTable();
  }

  /* ── Boot ───────────────────────────────────────────────────── */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderAll);
  } else {
    renderAll();
  }

})();
