/**
 * financier.js — Pôle Financier client-side rendering
 *
 * Reads from:
 *   window.FIN_COP              — COP payload keyed by monthKey
 *   window.FIN_COP_MONTHS       — sorted month array for COP
 *   window.FIN_COP_DEFAULT      — default COP monthKey
 *   window.FIN_SALES_TREND      — compact COGS trend (totals only, no bySKU)
 *   window.FIN_SALES_MONTHS     — sorted month array for COGS/Marge
 *   window.FIN_SALES_DEFAULT    — default COGS monthKey
 *   window.FIN_SALES_DEFAULT_SLICE — pre-loaded default month's full slice
 *
 * Lazy-fetches per-month COGS detail from /api/financier-data.php?module=cogs&month=YYYY-MM.
 * All monetary values in CHF. fr-CH formatting via KpcCharts.fmt().
 */

(function () {
  'use strict';

  /* ── Shorthands ────────────────────────────────────────────────────────── */
  const esc    = window.KpcCharts ? window.KpcCharts.escHtml : function(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  };
  const fmt    = window.KpcCharts ? window.KpcCharts.fmt : function(n, dec) {
    if (n == null) return '—';
    return Number(n).toLocaleString('fr-CH', {
      minimumFractionDigits:  dec != null ? dec : 2,
      maximumFractionDigits:  dec != null ? dec : 2,
    });
  };
  const svgEl  = window.KpcCharts ? window.KpcCharts.svgEl : function(tag, attrs) {
    var el = document.createElementNS('http://www.w3.org/2000/svg', tag);
    for (var k in (attrs || {})) el.setAttribute(k, attrs[k]);
    return el;
  };
  const kpcTok = window.KpcCharts ? window.KpcCharts.kpcTok : function(n) {
    return getComputedStyle(document.documentElement).getPropertyValue(n).trim();
  };

  function fmtChf(v)  { return fmt(v, 2); }
  function fmtHl(v)   { return fmt(v, 1); }
  function fmtPct(v)  { return fmt(v, 1) + ' %'; }
  function fmtUnits(v){ return fmt(v, 0); }

  function monthFr(key) {
    var fr = { '01':'Jan.','02':'Fév.','03':'Mar.','04':'Avr.','05':'Mai',
               '06':'Jun.','07':'Jul.','08':'Aoû.','09':'Sep.','10':'Oct.',
               '11':'Nov.','12':'Déc.' };
    var parts = (key || '').split('-');
    return (fr[parts[1]] || parts[1]) + ' ' + parts[0];
  }

  /* ── Per-month COGS slice cache (keyed by monthKey) ─────────────────────── */
  var sliceCache = {};
  if (window.FIN_SALES_DEFAULT && window.FIN_SALES_DEFAULT_SLICE) {
    sliceCache[window.FIN_SALES_DEFAULT] = window.FIN_SALES_DEFAULT_SLICE;
  }

  /* ── Fetch COGS slice with simple dedup ─────────────────────────────────── */
  var inflight = {};
  function fetchCogsSlice(monthKey, cb) {
    if (sliceCache[monthKey]) { cb(null, sliceCache[monthKey]); return; }
    if (inflight[monthKey]) { inflight[monthKey].push(cb); return; }
    inflight[monthKey] = [cb];
    fetch('/api/financier-data.php?module=cogs&month=' + encodeURIComponent(monthKey))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.ok) { sliceCache[monthKey] = data; }
        var cbs = inflight[monthKey] || [];
        delete inflight[monthKey];
        cbs.forEach(function(fn) { fn(data.ok ? null : (data.reason || 'error'), data); });
      })
      .catch(function(err) {
        var cbs = inflight[monthKey] || [];
        delete inflight[monthKey];
        cbs.forEach(function(fn) { fn('network_error', null); });
      });
  }

  /* ════════════════════════════════════════════════════════════════════════════
     MODULE A — COP
  ════════════════════════════════════════════════════════════════════════════ */
  var copData    = window.FIN_COP || {};
  var copMonths  = window.FIN_COP_MONTHS || [];

  function renderCop(monthKey) {
    var mo = copData[monthKey];
    if (!mo) return;

    var cop       = mo;
    var total     = (cop.totalVariables || {}).total  || 0;
    var perHL     = (cop.totalVariables || {}).perHL  || 0;
    var hlBrewed  = cop.hlBrewed    || 0;
    var hlPkg     = cop.hlPackaged  || 0;

    /* ── KPI tiles ─────────────────────────────────────────────────────────── */
    var kpis = [
      { label: 'COP total', value: fmtChf(total),  unit: 'CHF', accent: true },
      { label: 'COP / HL',  value: fmtChf(perHL),  unit: 'CHF/HL', accent: true },
      { label: 'HL brassés',value: fmtHl(hlBrewed), unit: 'HL' },
      { label: 'HL packagés',value: fmtHl(hlPkg),   unit: 'HL' },
    ];
    var kpiGrid = document.getElementById('cop-kpis');
    if (kpiGrid) {
      kpiGrid.innerHTML = kpis.map(function(k) {
        return '<div class="fin-kpi' + (k.accent ? ' fin-kpi--accent' : '') + '">'
          + '<div class="fin-kpi__label">' + esc(k.label) + '</div>'
          + '<div class="fin-kpi__value">' + esc(k.value) + '</div>'
          + '<div class="fin-kpi__unit">' + esc(k.unit) + '</div>'
          + '</div>';
      }).join('');
      var sr = document.getElementById('cop-kpi-status');
      if (sr) sr.textContent = 'COP ' + monthFr(monthKey) + ' : ' + fmtChf(total) + ' CHF / ' + fmtChf(perHL) + ' CHF/HL';
    }

    /* ── Section tiles ─────────────────────────────────────────────────────── */
    var sections = [
      { name: 'Brassage',     val: (cop.brewing   || {}).total  || 0 },
      { name: 'Packaging',    val: (cop.packaging || {}).total  || 0 },
      { name: 'Indirect',     val: (cop.indirect  || {}).total  || 0 },
      { name: 'Services',     val: (cop.utilities || {}).total  || 0 },
      { name: 'R&D',          val: (cop.rd        || {}).total  || 0 },
    ];
    var secGrid = document.getElementById('cop-sections');
    if (secGrid) {
      secGrid.innerHTML = sections.map(function(s) {
        var pct = total > 0 ? (s.val / total * 100) : 0;
        return '<div class="fin-section-tile">'
          + '<div class="fin-section-tile__name">' + esc(s.name) + '</div>'
          + '<div class="fin-section-tile__total">' + esc(fmtChf(s.val)) + ' CHF</div>'
          + '<div class="fin-section-tile__share">' + esc(fmtPct(pct)) + ' du COP</div>'
          + '</div>';
      }).join('');
    }

    /* ── Brewing sub-detail ─────────────────────────────────────────────────── */
    var brew = cop.brewing || {};
    var rows = [
      { name: 'Malts',         obj: brew.malts       || {} },
      { name: 'Houblons',      obj: brew.hops        || {} },
      { name: 'Ingrédients',   obj: brew.ingredients || {} },
    ];
    var brewCard = document.getElementById('cop-brew-detail');
    if (brewCard) {
      var html = '<div class="fin-brew-head">Détail brassage</div>';
      html += '<div class="fin-brew-row"><div class="fin-brew-row__name"></div>'
        + '<div class="fin-brew-row__val" style="font-size:.7rem;color:var(--ink-mute)">Total CHF</div>'
        + '<div class="fin-brew-row__val" style="font-size:.7rem;color:var(--ink-mute)">CHF/HL</div>'
        + '<div class="fin-brew-row__val" style="font-size:.7rem;color:var(--ink-mute)">% brassage</div>'
        + '</div>';
      var brewTotal = (brew.total || 0);
      rows.forEach(function(r) {
        var cur = r.obj.current || {};
        var tot = cur.total || 0;
        var phl = cur.perHL || 0;
        var pct = brewTotal > 0 ? (tot / brewTotal * 100) : 0;
        html += '<div class="fin-brew-row">'
          + '<div class="fin-brew-row__name">' + esc(r.name) + '</div>'
          + '<div class="fin-brew-row__val">' + esc(fmtChf(tot)) + '</div>'
          + '<div class="fin-brew-row__val">' + esc(fmtChf(phl)) + '</div>'
          + '<div class="fin-brew-row__val">' + esc(fmtPct(pct)) + '</div>'
          + '</div>';
      });
      brewCard.innerHTML = html;
    }
  }

  function buildCopTrendChart() {
    var container = document.getElementById('cop-trend-chart');
    if (!container || !copMonths.length) return;
    var vals = copMonths.map(function(mk) {
      var mo = copData[mk];
      return mo ? ((mo.totalVariables || {}).perHL || 0) : 0;
    });
    buildFinLineChart(container, copMonths, vals, { yUnit: 'CHF/HL', color: kpcTok('--oak') || '#8b5a1a' });
  }

  /* ── COP month picker ──────────────────────────────────────────────────── */
  var copSelect = document.getElementById('cop-month-select');
  if (copSelect) {
    var copDefault = window.FIN_COP_DEFAULT || (copMonths.length ? copMonths[copMonths.length - 1] : null);
    if (copDefault) renderCop(copDefault);
    buildCopTrendChart();
    copSelect.addEventListener('change', function() { renderCop(this.value); });
  }

  /* ════════════════════════════════════════════════════════════════════════════
     MODULE B — COGS & Ventes
  ════════════════════════════════════════════════════════════════════════════ */
  var salesMonths = window.FIN_SALES_MONTHS || [];
  var salesTrend  = window.FIN_SALES_TREND  || {};

  /* Current COGS sort key */
  var cogsSort = 'revenueCHF';

  function renderCogsSlice(slice) {
    if (!slice) return;
    var totals = slice.totals || {};
    var bySku  = slice.bySKU  || {};

    /* ── KPI tiles ─────────────────────────────────────────────────────────── */
    var kpiDefs = [
      { label: 'Unités vendues',  value: fmtUnits(totals.units || 0),       unit: 'u' },
      { label: 'HL vendus',       value: fmtHl(totals.HL || 0),             unit: 'HL' },
      { label: 'CA',              value: fmtChf(totals.revenueCHF || 0),    unit: 'CHF', accent: true },
      { label: 'Matières',        value: fmtChf(totals.material_CHF || 0),  unit: 'CHF' },
      { label: 'Taxe bière',      value: fmtChf(totals.beerTax_CHF || 0),   unit: 'CHF' },
      { label: 'COGS total',      value: fmtChf(totals.salesCOGS_CHF || 0), unit: 'CHF', accent: true },
    ];
    var grid = document.getElementById('cogs-kpis');
    if (grid) {
      grid.innerHTML = kpiDefs.map(function(k) {
        return '<div class="fin-kpi' + (k.accent ? ' fin-kpi--accent' : '') + '">'
          + '<div class="fin-kpi__label">' + esc(k.label) + '</div>'
          + '<div class="fin-kpi__value">' + esc(k.value) + '</div>'
          + '<div class="fin-kpi__unit">' + esc(k.unit) + '</div>'
          + '</div>';
      }).join('');
      var sr = document.getElementById('cogs-kpi-status');
      if (sr) sr.textContent = 'CA ' + fmtChf(totals.revenueCHF || 0) + ' CHF · COGS ' + fmtChf(totals.salesCOGS_CHF || 0) + ' CHF';
    }

    /* ── Per-SKU table ─────────────────────────────────────────────────────── */
    renderCogsSkuTable(bySku);

    /* ── Beer-tax breakdown ─────────────────────────────────────────────────── */
    var beerTax = slice.beerTax || {};
    var byCategory = beerTax.byCategory || [];
    var taxGrid = document.getElementById('cogs-beertax');
    if (taxGrid) {
      if (!byCategory.length) {
        taxGrid.innerHTML = '<p class="fin-empty">Aucune donnée taxe bière.</p>';
      } else {
        taxGrid.innerHTML = byCategory.map(function(c, i) {
          var catLabel = 'Cat. ' + i;
          // category index corresponds to tax bracket (0=0%, 1=normal, 2=reduced…)
          return '<div class="fin-beertax-tile">'
            + '<div class="fin-beertax-tile__cat">' + esc(catLabel) + '</div>'
            + '<div class="fin-beertax-tile__hl">' + esc(fmtHl(c.hl || 0)) + ' HL</div>'
            + '<div class="fin-beertax-tile__tax">' + esc(fmtChf(c.tax || 0)) + ' CHF</div>'
            + '</div>';
        }).join('');
      }
    }

    /* ── Non-rattachés ─────────────────────────────────────────────────────── */
    var unknownSKUs  = slice.unknownSKUs  || [];
    var nonBeerSKUs  = slice.nonBeerSKUs  || [];
    var unmatchedWrap = document.getElementById('cogs-unmatched-wrap');
    var unmatchedDiv  = document.getElementById('cogs-unmatched');
    if (unmatchedWrap) {
      var all = unknownSKUs.concat(nonBeerSKUs);
      if (all.length > 0) {
        unmatchedWrap.hidden = false;
        unmatchedDiv.innerHTML = '<div class="fin-unmatched-chips">'
          + all.map(function(x) {
            return '<span class="fin-unmatched-chip" title="' + esc(fmtUnits(x.units || 0)) + ' u / ' + esc(fmtChf(x.revenueCHF || 0)) + ' CHF">'
              + esc(x.sku) + '</span>';
          }).join('')
          + '</div>';
      } else {
        unmatchedWrap.hidden = true;
      }
    }
  }

  function renderCogsSkuTable(bySku) {
    var tbody = document.getElementById('cogs-sku-tbody');
    if (!tbody) return;
    var skuArr = Object.keys(bySku).map(function(sku) {
      var d = bySku[sku];
      return {
        sku:          sku,
        units:        d.units        || 0,
        HL:           d.HL           || 0,
        unitCost:     d.unitCost     || 0,
        material_CHF: d.material_CHF || 0,
        beerTax_CHF:  d.beerTax_CHF  || 0,
        salesCOGS_CHF:d.salesCOGS_CHF|| 0,
        revenueCHF:   d.revenueCHF   || 0,
        beerTaxCat:   d.beerTaxCat   || null,
      };
    });

    // Sort
    var sort = cogsSort;
    skuArr.sort(function(a, b) {
      if (sort === 'sku') return a.sku.localeCompare(b.sku);
      return (b[sort] || 0) - (a[sort] || 0);
    });

    if (!skuArr.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="fin-empty">Aucun SKU.</td></tr>';
      return;
    }

    tbody.innerHTML = skuArr.map(function(d) {
      var catBadge = d.beerTaxCat != null
        ? '<span class="fin-taxcat fin-taxcat--' + esc(d.beerTaxCat) + '">' + esc('Cat. ' + d.beerTaxCat) + '</span>'
        : '<span class="fin-taxcat fin-taxcat--0">—</span>';
      return '<tr>'
        + '<td class="fin-td--sku">' + esc(d.sku) + '</td>'
        + '<td class="fin-td--num">' + esc(fmtUnits(d.units)) + '</td>'
        + '<td class="fin-td--num">' + esc(fmtChf(d.unitCost)) + '</td>'
        + '<td class="fin-td--num">' + esc(fmtChf(d.material_CHF)) + '</td>'
        + '<td class="fin-td--num">' + esc(fmtChf(d.beerTax_CHF)) + '</td>'
        + '<td class="fin-td--num">' + esc(fmtChf(d.salesCOGS_CHF)) + '</td>'
        + '<td class="fin-td--num">' + esc(fmtChf(d.revenueCHF)) + '</td>'
        + '<td>' + catBadge + '</td>'
        + '</tr>';
    }).join('');
  }

  function buildCogsTrendChart() {
    var container = document.getElementById('cogs-trend-chart');
    if (!container || !salesMonths.length) return;

    var cogsVals = salesMonths.map(function(mk) {
      return (salesTrend[mk] || {}).salesCOGS_CHF || 0;
    });
    var caVals = salesMonths.map(function(mk) {
      return (salesTrend[mk] || {}).revenueCHF || 0;
    });
    buildFinLineChart2(container, salesMonths, cogsVals, caVals, {
      yUnit: 'CHF',
      color1: kpcTok('--oak') || '#8b5a1a',
      color2: kpcTok('--hop') || '#567020',
      label1: 'COGS',
      label2: 'CA',
    });
  }

  var currentCogsMonth = window.FIN_SALES_DEFAULT;
  var cogsLoading      = document.getElementById('cogs-loading');

  function loadAndRenderCogs(monthKey) {
    if (cogsLoading) cogsLoading.hidden = false;
    fetchCogsSlice(monthKey, function(err, data) {
      if (cogsLoading) cogsLoading.hidden = true;
      if (!err && data) {
        currentCogsMonth = monthKey;
        renderCogsSlice(data);
      } else {
        var grid = document.getElementById('cogs-kpis');
        if (grid) grid.innerHTML = '<p class="fin-empty">Erreur chargement données (' + esc(String(err)) + ').</p>';
      }
    });
  }

  var cogsSelect = document.getElementById('cogs-month-select');
  var cogsSort$  = document.getElementById('cogs-sort-select');
  if (cogsSelect) {
    // Render default month immediately (pre-loaded slice)
    if (window.FIN_SALES_DEFAULT_SLICE) {
      renderCogsSlice(window.FIN_SALES_DEFAULT_SLICE);
    } else if (window.FIN_SALES_DEFAULT) {
      loadAndRenderCogs(window.FIN_SALES_DEFAULT);
    }
    buildCogsTrendChart();
    cogsSelect.addEventListener('change', function() {
      loadAndRenderCogs(this.value);
    });
  }
  if (cogsSort$) {
    cogsSort$.addEventListener('change', function() {
      cogsSort = this.value;
      var slice = sliceCache[currentCogsMonth];
      if (slice) renderCogsSkuTable(slice.bySKU || {});
    });
  }

  /* ════════════════════════════════════════════════════════════════════════════
     MODULE C — Marge / ASP
  ════════════════════════════════════════════════════════════════════════════ */
  var margeSort        = 'grossMargin';
  var currentMargeMonth = window.FIN_SALES_DEFAULT;
  var margeLoading     = document.getElementById('marge-loading');

  function renderMargeSlice(slice) {
    if (!slice) return;
    var totals = slice.totals || {};
    var bySku  = slice.bySKU  || {};

    var rev   = totals.revenueCHF    || 0;
    var cogs  = totals.salesCOGS_CHF || 0;
    var gross = rev - cogs;
    var pct   = rev > 0 ? (gross / rev * 100) : 0;
    var units = totals.units || 0;
    var asp   = units > 0 ? (rev / units) : 0;

    /* ── KPI tiles ─────────────────────────────────────────────────────────── */
    var kpiDefs = [
      { label: 'CA',           value: fmtChf(rev),   unit: 'CHF', accent: true },
      { label: 'COGS',         value: fmtChf(cogs),  unit: 'CHF' },
      { label: 'Marge brute',  value: fmtChf(gross), unit: 'CHF', accent: true },
      { label: 'Marge %',      value: fmtPct(pct),   unit: '',    accent: pct > 0 },
      { label: 'ASP',          value: fmtChf(asp),   unit: 'CHF/u' },
    ];
    var grid = document.getElementById('marge-kpis');
    if (grid) {
      grid.innerHTML = kpiDefs.map(function(k) {
        return '<div class="fin-kpi' + (k.accent ? ' fin-kpi--accent' : '') + '">'
          + '<div class="fin-kpi__label">' + esc(k.label) + '</div>'
          + '<div class="fin-kpi__value">' + esc(k.value) + '</div>'
          + '<div class="fin-kpi__unit">' + esc(k.unit) + '</div>'
          + '</div>';
      }).join('');
      var sr = document.getElementById('marge-kpi-status');
      if (sr) sr.textContent = 'Marge ' + fmtPct(pct) + ' · ASP ' + fmtChf(asp) + ' CHF/u';
    }

    /* ── Per-SKU marge table ───────────────────────────────────────────────── */
    renderMargeSkuTable(bySku);
  }

  function renderMargeSkuTable(bySku) {
    var tbody = document.getElementById('marge-sku-tbody');
    if (!tbody) return;

    var skuArr = Object.keys(bySku).map(function(sku) {
      var d = bySku[sku];
      var rev  = d.revenueCHF    || 0;
      var cogs = d.salesCOGS_CHF || 0;
      var gm   = rev - cogs;
      var pct  = rev > 0 ? (gm / rev * 100) : 0;
      var asp  = (d.units || 0) > 0 ? (rev / d.units) : 0;
      return {
        sku: sku, revenueCHF: rev, salesCOGS_CHF: cogs,
        grossMargin: gm, marginPct: pct, asp: asp,
        beerTaxCat: d.beerTaxCat,
      };
    });

    var sort = margeSort;
    skuArr.sort(function(a, b) {
      if (sort === 'sku') return a.sku.localeCompare(b.sku);
      if (sort === 'marginPct') return b.marginPct - a.marginPct;
      return (b[sort] || 0) - (a[sort] || 0);
    });

    if (!skuArr.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="fin-empty">Aucun SKU.</td></tr>';
      return;
    }

    tbody.innerHTML = skuArr.map(function(d) {
      var margeCls = d.grossMargin >= 0 ? 'fin-td--margin-pos' : 'fin-td--margin-neg';
      var catBadge = d.beerTaxCat != null
        ? '<span class="fin-taxcat fin-taxcat--' + esc(d.beerTaxCat) + '">' + esc('Cat. ' + d.beerTaxCat) + '</span>'
        : '—';
      return '<tr>'
        + '<td class="fin-td--sku">' + esc(d.sku) + '</td>'
        + '<td class="fin-td--num">' + esc(fmtChf(d.revenueCHF)) + '</td>'
        + '<td class="fin-td--num">' + esc(fmtChf(d.salesCOGS_CHF)) + '</td>'
        + '<td class="fin-td--num ' + margeCls + '">' + esc(fmtChf(d.grossMargin)) + '</td>'
        + '<td class="fin-td--num ' + margeCls + '">' + esc(fmtPct(d.marginPct)) + '</td>'
        + '<td class="fin-td--num">' + esc(fmtChf(d.asp)) + '</td>'
        + '<td>' + catBadge + '</td>'
        + '</tr>';
    }).join('');
  }

  function loadAndRenderMarge(monthKey) {
    if (margeLoading) margeLoading.hidden = false;
    fetchCogsSlice(monthKey, function(err, data) {
      if (margeLoading) margeLoading.hidden = true;
      if (!err && data) {
        currentMargeMonth = monthKey;
        renderMargeSlice(data);
      }
    });
  }

  var margeSelect = document.getElementById('marge-month-select');
  var margeSort$  = document.getElementById('marge-sort-select');
  if (margeSelect) {
    if (window.FIN_SALES_DEFAULT_SLICE) {
      renderMargeSlice(window.FIN_SALES_DEFAULT_SLICE);
    } else if (window.FIN_SALES_DEFAULT) {
      loadAndRenderMarge(window.FIN_SALES_DEFAULT);
    }
    margeSelect.addEventListener('change', function() {
      loadAndRenderMarge(this.value);
    });
    // Sync marge + cogs pickers to same month selection
    margeSelect.addEventListener('change', function() {
      var cogsMonthSel = document.getElementById('cogs-month-select');
      if (cogsMonthSel && cogsMonthSel.value !== this.value) {
        cogsMonthSel.value = this.value;
        loadAndRenderCogs(this.value);
      }
    });
  }
  if (margeSort$) {
    margeSort$.addEventListener('change', function() {
      margeSort = this.value;
      var slice = sliceCache[currentMargeMonth];
      if (slice) renderMargeSkuTable(slice.bySKU || {});
    });
  }

  /* ════════════════════════════════════════════════════════════════════════════
     TAB NAVIGATION
  ════════════════════════════════════════════════════════════════════════════ */
  var tabs   = document.querySelectorAll('.fin-tab');
  var panels = document.querySelectorAll('.fin-panel');

  tabs.forEach(function(tab) {
    tab.addEventListener('click', function() {
      var target = this.dataset.tab;
      tabs.forEach(function(t) {
        var active = t.dataset.tab === target;
        t.classList.toggle('fin-tab--active', active);
        t.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      panels.forEach(function(p) {
        var panelId = 'fin-panel-' + target;
        p.hidden = (p.id !== panelId);
        p.classList.toggle('fin-panel--active', p.id === panelId);
      });
      // Lazy-load marge if switching to it for first time
      if (target === 'marge' && !sliceCache[currentMargeMonth || window.FIN_SALES_DEFAULT]) {
        loadAndRenderMarge(currentMargeMonth || window.FIN_SALES_DEFAULT);
      }
    });
    // Keyboard navigation
    tab.addEventListener('keydown', function(e) {
      var tabList = Array.from(tabs);
      var idx = tabList.indexOf(this);
      if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
        var next = e.key === 'ArrowRight' ? (idx + 1) % tabList.length : (idx - 1 + tabList.length) % tabList.length;
        tabList[next].focus();
        tabList[next].click();
      }
    });
  });

  /* ════════════════════════════════════════════════════════════════════════════
     TREND CHART BUILDERS (SVG, ~31–66 points)
  ════════════════════════════════════════════════════════════════════════════ */

  /**
   * Single-series line chart spanning many months.
   * months: array of "YYYY-MM" strings
   * vals:   parallel array of numbers
   */
  function buildFinLineChart(container, months, vals, opts) {
    opts = opts || {};
    var W = 840, H = 200;
    var padL = 60, padR = 16, padT = 16, padB = 44;
    var cW = W - padL - padR;
    var cH = H - padT - padB;

    var n    = months.length;
    if (!n) { container.innerHTML = '<p class="fin-empty">Pas de données.</p>'; return; }
    var maxV = Math.max.apply(null, vals.concat([1]));
    var minV = Math.min.apply(null, vals.filter(function(v) { return v > 0; }).concat([0]));
    var range = maxV - minV || 1;

    function xOf(i) { return padL + (i / (n - 1 || 1)) * cW; }
    function yOf(v) { return padT + cH * (1 - (v - minV) / range); }

    var color = opts.color || kpcTok('--oak') || '#8b5a1a';
    var yUnit = opts.yUnit || '';

    var svg = svgEl('svg', {
      viewBox: '0 0 ' + W + ' ' + H,
      'aria-label': 'Tendance ' + yUnit,
      role: 'img',
    });

    // Grid lines (5)
    for (var g = 0; g <= 4; g++) {
      var yy = padT + (g / 4) * cH;
      var gridVal = maxV - (g / 4) * range;
      svg.appendChild(svgEl('line', {
        x1: padL - 4, y1: yy, x2: W - padR, y2: yy,
        stroke: kpcTok('--hairline') || '#c8b48a',
        'stroke-width': g === 4 ? 1 : 0.5,
        opacity: 0.6,
      }));
      var lbl = svgEl('text', {
        x: padL - 6, y: yy + 4,
        'text-anchor': 'end',
        'font-size': 9, fill: kpcTok('--ink-mute') || '#4a3820',
        'font-family': 'JetBrains Mono, monospace',
      });
      lbl.textContent = Number(gridVal).toLocaleString('fr-CH', { maximumFractionDigits: 0 });
      svg.appendChild(lbl);
    }

    // Area fill
    var aD = 'M' + xOf(0).toFixed(1) + ',' + (padT + cH);
    vals.forEach(function(v, i) { aD += 'L' + xOf(i).toFixed(1) + ',' + yOf(v).toFixed(1); });
    aD += 'L' + xOf(n - 1).toFixed(1) + ',' + (padT + cH) + 'Z';
    svg.appendChild(svgEl('path', { d: aD, fill: color, opacity: 0.08 }));

    // Line
    var lD = '';
    vals.forEach(function(v, i) { lD += (i === 0 ? 'M' : 'L') + xOf(i).toFixed(1) + ',' + yOf(v).toFixed(1); });
    svg.appendChild(svgEl('path', {
      d: lD, fill: 'none', stroke: color,
      'stroke-width': 1.8, 'stroke-linejoin': 'round', 'stroke-linecap': 'round',
    }));

    // X-axis labels (show ~6 evenly spaced)
    var step = Math.ceil(n / 6);
    months.forEach(function(mk, i) {
      if (i % step !== 0 && i !== n - 1) return;
      var parts = mk.split('-');
      var frMo = ['jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];
      var lbl = svgEl('text', {
        x: xOf(i).toFixed(1), y: H - 6,
        'text-anchor': 'middle',
        'font-size': 9, fill: kpcTok('--ink-mute') || '#4a3820',
        'font-family': 'JetBrains Mono, monospace',
      });
      lbl.textContent = (frMo[(parseInt(parts[1], 10) - 1)] || parts[1]) + ' ' + parts[0].slice(2);
      svg.appendChild(lbl);
    });

    container.innerHTML = '';
    container.appendChild(svg);
  }

  /** Two-series line chart (e.g. COGS + CA) */
  function buildFinLineChart2(container, months, vals1, vals2, opts) {
    opts = opts || {};
    var W = 840, H = 220;
    var padL = 64, padR = 90, padT = 16, padB = 44;
    var cW = W - padL - padR;
    var cH = H - padT - padB;

    var n = months.length;
    if (!n) { container.innerHTML = '<p class="fin-empty">Pas de données.</p>'; return; }

    var allVals = vals1.concat(vals2);
    var maxV = Math.max.apply(null, allVals.concat([1]));
    var minV = 0;
    var range = maxV - minV || 1;

    function xOf(i) { return padL + (i / (n - 1 || 1)) * cW; }
    function yOf(v) { return padT + cH * (1 - (v - minV) / range); }

    var color1 = opts.color1 || kpcTok('--oak') || '#8b5a1a';
    var color2 = opts.color2 || kpcTok('--hop') || '#567020';
    var label1 = opts.label1 || 'Série 1';
    var label2 = opts.label2 || 'Série 2';

    var svg = svgEl('svg', {
      viewBox: '0 0 ' + W + ' ' + H,
      'aria-label': label1 + ' et ' + label2,
      role: 'img',
    });

    // Grid
    for (var g = 0; g <= 4; g++) {
      var yy = padT + (g / 4) * cH;
      var gv = maxV - (g / 4) * range;
      svg.appendChild(svgEl('line', {
        x1: padL - 4, y1: yy, x2: W - padR, y2: yy,
        stroke: kpcTok('--hairline') || '#c8b48a',
        'stroke-width': g === 4 ? 1 : 0.5, opacity: 0.6,
      }));
      var gLbl = svgEl('text', {
        x: padL - 6, y: yy + 4,
        'text-anchor': 'end', 'font-size': 9,
        fill: kpcTok('--ink-mute') || '#4a3820',
        'font-family': 'JetBrains Mono, monospace',
      });
      gLbl.textContent = Number(gv).toLocaleString('fr-CH', { maximumFractionDigits: 0 });
      svg.appendChild(gLbl);
    }

    function drawLine(vals, color, dashed) {
      var lD = '';
      vals.forEach(function(v, i) { lD += (i === 0 ? 'M' : 'L') + xOf(i).toFixed(1) + ',' + yOf(v).toFixed(1); });
      svg.appendChild(svgEl('path', {
        d: lD, fill: 'none', stroke: color,
        'stroke-width': 1.8,
        'stroke-linejoin': 'round', 'stroke-linecap': 'round',
        'stroke-dasharray': dashed ? '4 3' : 'none',
      }));
    }
    drawLine(vals1, color1, false);
    drawLine(vals2, color2, true);

    // Legend
    var legX = W - padR + 8;
    [[color1, label1, false], [color2, label2, true]].forEach(function(leg, li) {
      var ly = padT + 20 + li * 22;
      svg.appendChild(svgEl('line', {
        x1: legX, y1: ly, x2: legX + 18, y2: ly,
        stroke: leg[0], 'stroke-width': 1.8,
        'stroke-dasharray': leg[2] ? '4 3' : 'none',
      }));
      var legLbl = svgEl('text', {
        x: legX + 22, y: ly + 4,
        'font-size': 9, fill: kpcTok('--ink') || '#1f1200',
        'font-family': 'DM Sans, sans-serif',
      });
      legLbl.textContent = leg[1];
      svg.appendChild(legLbl);
    });

    // X-axis
    var step = Math.ceil(n / 6);
    months.forEach(function(mk, i) {
      if (i % step !== 0 && i !== n - 1) return;
      var parts = mk.split('-');
      var frMo = ['jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];
      var aLbl = svgEl('text', {
        x: xOf(i).toFixed(1), y: H - 6,
        'text-anchor': 'middle', 'font-size': 9,
        fill: kpcTok('--ink-mute') || '#4a3820',
        'font-family': 'JetBrains Mono, monospace',
      });
      aLbl.textContent = (frMo[(parseInt(parts[1], 10) - 1)] || parts[1]) + ' ' + parts[0].slice(2);
      svg.appendChild(aLbl);
    });

    container.innerHTML = '';
    container.appendChild(svg);
  }

})();
