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
      { name: 'Packaging',    val: (cop.packaging || {}).total  || 0,
        repack: (cop.packaging || {}).repack || 0 },
      { name: 'Indirect',     val: (cop.indirect  || {}).total  || 0 },
      { name: 'Services',     val: (cop.utilities || {}).total  || 0 },
      { name: 'R&D',          val: (cop.rd        || {}).total  || 0 },
    ];
    var secGrid = document.getElementById('cop-sections');
    if (secGrid) {
      secGrid.innerHTML = sections.map(function(s) {
        var pct = total > 0 ? (s.val / total * 100) : 0;
        var noteHtml = '';
        if (s.repack > 0) {
          noteHtml = '<div class="fin-section-tile__note">dont '
            + esc(fmtChf(s.repack)) + ' CHF reconditionnement PD8</div>';
        }
        return '<div class="fin-section-tile">'
          + '<div class="fin-section-tile__name">' + esc(s.name) + '</div>'
          + '<div class="fin-section-tile__total">' + esc(fmtChf(s.val)) + ' CHF</div>'
          + '<div class="fin-section-tile__share">' + esc(fmtPct(pct)) + ' du COP</div>'
          + noteHtml
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

  /* ════════════════════════════════════════════════════════════════════════════
     MODULE GL-GRID — P&L Grid (COP + COGS tabs)
  ════════════════════════════════════════════════════════════════════════════ */

  /* Simple per-module fetch cache keyed by "module:monthKey" */
  var gridCache = {};

  function fetchGridSlice(gridModule, monthKey, cb) {
    var cacheKey = gridModule + ':' + monthKey;
    if (gridCache[cacheKey]) { cb(null, gridCache[cacheKey]); return; }
    fetch('/api/financier-data.php?module=' + encodeURIComponent(gridModule)
          + '&month=' + encodeURIComponent(monthKey))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.ok) { gridCache[cacheKey] = data; }
        cb(data.ok ? null : (data.reason || 'error'), data);
      })
      .catch(function() { cb('network_error', null); });
  }

  /**
   * Render the COGS P&L grid (10 data columns):
   *   ACTUALS[mois,YTD] | BUDGET[mois,YTD] | N-1[mois,YTD] | Vs BUDGET[mois,YTD] | Vs N-1[mois,YTD]
   *
   * BUDGET + Vs BUDGET: live from row.budgetPerHl.
   * N-1 + Vs N-1: live from data.n1 (exercice 2025 opérationnel).
   * data: the cogs-grid endpoint response {operational, n1, booked, check, ytdLabel, month}
   */
  function renderCogsPLGrid(wrapEl, data) {
    if (!wrapEl || !data || !data.operational) {
      if (wrapEl) wrapEl.innerHTML = '<p class="fin-empty">Données indisponibles.</p>';
      return;
    }

    var op       = data.operational;
    var n1Data   = data.n1       || {};
    var bk       = data.booked   || {};
    var chk      = data.check    || {};
    var ytdLabel = data.ytdLabel || 'YTD';
    var lines    = (op.month || {}).lines || [];

    // 10 data cols: ACTUALS(2) + BUDGET(2) + N-1(2) + Vs BUDGET(2) + Vs N-1(2)
    var numCols = 10;
    var html = '<div class="fin-grid-scroll"><table class="fin-grid-table" aria-label="P&amp;L COGS Grid">';
    html += '<thead>';
    html += '<tr class="fin-grid-hdr fin-grid-hdr--group">'
      + '<th rowspan="3" class="fin-grid-th fin-grid-th--label" scope="col"></th>'
      + '<th colspan="2" class="fin-grid-th fin-grid-th--group fin-grid-th--actuals" scope="colgroup">ACTUALS</th>'
      + '<th colspan="2" class="fin-grid-th fin-grid-th--group fin-grid-th--budget" scope="colgroup">BUDGET</th>'
      + '<th colspan="2" class="fin-grid-th fin-grid-th--group fin-grid-th--n1" scope="colgroup">N-1</th>'
      + '<th colspan="2" class="fin-grid-th fin-grid-th--group fin-grid-th--vs-budget" scope="colgroup">Vs BUDGET</th>'
      + '<th colspan="2" class="fin-grid-th fin-grid-th--group fin-grid-th--vs-n1" scope="colgroup">Vs N-1</th>'
      + '</tr>';
    html += '<tr class="fin-grid-hdr fin-grid-hdr--sub">'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--actuals" scope="col">Mois</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--actuals" scope="col">' + esc(ytdLabel) + '</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--budget" scope="col">Mois</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--budget" scope="col">' + esc(ytdLabel) + '</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--n1" scope="col">Mois</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--n1" scope="col">' + esc(ytdLabel) + '</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--vs-budget" scope="col">Mois</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--vs-budget" scope="col">' + esc(ytdLabel) + '</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--vs-n1" scope="col">Mois</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--vs-n1" scope="col">' + esc(ytdLabel) + '</th>'
      + '</tr>';
    html += '<tr class="fin-grid-hdr fin-grid-hdr--unit">'
      + '<th class="fin-grid-th fin-grid-th--actuals" scope="col">CHF/HL</th>'
      + '<th class="fin-grid-th fin-grid-th--actuals" scope="col">CHF/HL</th>'
      + '<th class="fin-grid-th fin-grid-th--budget" scope="col">CHF/HL</th>'
      + '<th class="fin-grid-th fin-grid-th--budget" scope="col">CHF/HL</th>'
      + '<th class="fin-grid-th fin-grid-th--n1" scope="col">CHF/HL</th>'
      + '<th class="fin-grid-th fin-grid-th--n1" scope="col">CHF/HL</th>'
      + '<th class="fin-grid-th fin-grid-th--vs-budget" scope="col">%</th>'
      + '<th class="fin-grid-th fin-grid-th--vs-budget" scope="col">%</th>'
      + '<th class="fin-grid-th fin-grid-th--vs-n1" scope="col">%</th>'
      + '<th class="fin-grid-th fin-grid-th--vs-n1" scope="col">%</th>'
      + '</tr>';
    html += '</thead><tbody>';

    var ytdByKey   = {};
    var n1MoByKey  = {};
    var n1YtdByKey = {};
    ((op.ytd  || {}).lines || []).forEach(function(r) { ytdByKey[r.key]   = r; });
    ((n1Data.month || {}).lines || []).forEach(function(r) { n1MoByKey[r.key]  = r; });
    ((n1Data.ytd   || {}).lines || []).forEach(function(r) { n1YtdByKey[r.key] = r; });

    var sectionOf = {
      malts: 'Brewing', hops: 'Brewing', yeast: 'Brewing', other_ing: 'Brewing', sub_brewing: 'Brewing',
      bottles: 'Packaging', cans: 'Packaging', reg_cardboard: 'Packaging', spec_cardboard: 'Packaging',
      plastic: 'Packaging', labels: 'Packaging', keg: 'Packaging', sub_packaging: 'Packaging',
      co2: 'Indirect', chemical: 'Indirect', small_equip: 'Indirect', transport: 'Indirect', sub_indirect: 'Indirect',
      gas_water: 'Utilities', electricity: 'Utilities', waste: 'Utilities', sub_utilities: 'Utilities',
      qa_qc: 'R&D', rd_purchases: 'R&D', sub_rd: 'R&D',
      grand_variable: null, beer_tax: null,
    };
    var lastSection = null;
    var sectionLabels = { Brewing: 'Brewing', Packaging: 'Packaging', Indirect: 'Indirect', Utilities: 'Utilities', 'R&D': 'R&D' };

    lines.forEach(function(row) {
      var type   = row.type;
      var key    = row.key;
      var sec    = sectionOf[key] !== undefined ? sectionOf[key] : null;
      var ytdRow   = ytdByKey[key]   || {};
      var n1MoRow  = n1MoByKey[key]  || {};
      var n1YtdRow = n1YtdByKey[key] || {};

      if (sec !== null && sec !== lastSection) {
        lastSection = sec;
        html += '<tr class="fin-grid-row fin-grid-row--subhdr">'
          + '<td colspan="' + (numCols + 1) + '" class="fin-grid-td fin-grid-td--subhdr">'
          + esc(sectionLabels[sec] || sec) + '</td></tr>';
      }

      var isSubtotal = (type === 'subtotal');
      var isGrand    = (type === 'grand_subtotal');
      var isBold     = isSubtotal || isGrand;

      var rowCls  = 'fin-grid-row';
      if (isSubtotal) rowCls += ' fin-grid-row--subtotal';
      if (isGrand)    rowCls += ' fin-grid-row--grand-subtotal';

      var labelCls = 'fin-grid-td fin-grid-td--label';
      if (isBold) labelCls += ' fin-grid-td--label-strong';

      function actCell(phl) {
        var cls = 'fin-grid-td fin-grid-td--num fin-grid-td--actuals';
        if (isBold) cls += ' fin-grid-td--bold';
        var txt = (phl === null || phl === undefined) ? '0.00' : fmt(phl, 2);
        if (phl !== null && phl !== undefined && phl < 0) cls += ' fin-grid-td--negative';
        return '<td class="' + cls + '">' + esc(txt) + '</td>';
      }

      function budgetCell(bgt) {
        var cls = 'fin-grid-td fin-grid-td--num fin-grid-td--budget';
        if (isBold) cls += ' fin-grid-td--bold';
        if (bgt === null || bgt === undefined) {
          return '<td class="' + cls + '">—</td>';
        }
        return '<td class="' + cls + '">' + esc(fmt(bgt, 2)) + '</td>';
      }

      function n1Cell(phl) {
        var cls = 'fin-grid-td fin-grid-td--num fin-grid-td--n1';
        if (isBold) cls += ' fin-grid-td--bold';
        var txt = (phl === null || phl === undefined) ? '0.00' : fmt(phl, 2);
        return '<td class="' + cls + '">' + esc(txt) + '</td>';
      }

      function vsBudgetCell(actual, bgt) {
        if (bgt === null || bgt === undefined || bgt === 0) {
          var cls0 = 'fin-grid-td fin-grid-td--num fin-grid-td--placeholder';
          if (isBold) cls0 += ' fin-grid-td--bold';
          return '<td class="' + cls0 + '">—</td>';
        }
        var pct = ((actual - bgt) / bgt) * 100;
        var cls = 'fin-grid-td fin-grid-td--num fin-grid-td--vs-budget';
        if (isBold) cls += ' fin-grid-td--bold';
        if (pct > 0) cls += ' fin-grid-td--vs-budget-over';
        else if (pct < 0) cls += ' fin-grid-td--vs-budget-under';
        var sign = pct >= 0 ? '+' : '';
        return '<td class="' + cls + '">' + esc(sign + fmt(pct, 1) + '%') + '</td>';
      }

      function vsN1Cell(actual, n1phl) {
        if (n1phl === null || n1phl === undefined || n1phl === 0) {
          var cls0 = 'fin-grid-td fin-grid-td--num fin-grid-td--vs-n1';
          if (isBold) cls0 += ' fin-grid-td--bold';
          return '<td class="' + cls0 + '">—</td>';
        }
        var pct = ((actual - n1phl) / Math.abs(n1phl)) * 100;
        var cls = 'fin-grid-td fin-grid-td--num fin-grid-td--vs-n1';
        if (isBold) cls += ' fin-grid-td--bold';
        if (pct > 0) cls += ' fin-grid-td--vs-n1-over';
        else if (pct < 0) cls += ' fin-grid-td--vs-n1-under';
        var sign = pct >= 0 ? '+' : '';
        return '<td class="' + cls + '">' + esc(sign + fmt(pct, 1) + '%') + '</td>';
      }

      var actMo  = row.perHl    !== undefined ? row.perHl    : null;
      var actYtd = ytdRow.perHl !== undefined ? ytdRow.perHl : null;
      var bgt    = row.budgetPerHl !== undefined ? row.budgetPerHl : null;
      var bgtYtd = bgt; // annual flat rate — same for month and YTD
      var n1Mo   = n1MoRow.perHl  !== undefined ? n1MoRow.perHl  : null;
      var n1Ytd  = n1YtdRow.perHl !== undefined ? n1YtdRow.perHl : null;

      html += '<tr class="' + rowCls + '">';
      html += '<td class="' + labelCls + '">' + esc(row.label) + '</td>';
      html += actCell(actMo);
      html += actCell(actYtd);
      html += budgetCell(bgt);
      html += budgetCell(bgtYtd);
      html += n1Cell(n1Mo);
      html += n1Cell(n1Ytd);
      html += vsBudgetCell(actMo  !== null ? actMo  : 0, bgt);
      html += vsBudgetCell(actYtd !== null ? actYtd : 0, bgtYtd);
      html += vsN1Cell(actMo  !== null ? actMo  : 0, n1Mo);
      html += vsN1Cell(actYtd !== null ? actYtd : 0, n1Ytd);
      html += '</tr>';
    });

    html += '</tbody></table></div>';

    // Source chip
    html += '<p class="fin-secondary-note" style="margin-top:0.5rem">'
      + 'N-1 = exercice 2025 opérationnel. BUDGET = ref_budget_cogs (CHF/HL annuel).'
      + '</p>';

    // ── Vue comptable (GL booké) secondary block (same as COP) ───────────────
    var bkMo  = bk.month  || {};
    var bkYtd = bk.ytd    || {};
    var chkMo = chk.month || {};
    var chkYtd = chk.ytd  || {};

    var bkSections = [
      { key: 'brewing',   label: 'Brewing' },
      { key: 'packaging', label: 'Packaging' },
      { key: 'indirect',  label: 'Indirect' },
      { key: 'utilities', label: 'Utilities' },
      { key: 'rd',        label: 'R&D' },
    ];

    html += '<div class="fin-vue-comptable">';
    html += '<h4 class="fin-secondary-title">Vue comptable (GL booké)</h4>';
    html += '<p class="fin-secondary-note">Source : charges BC importées — décalage comptable possible (mois non clôturés sous-estimés).</p>';
    html += '<div class="fin-grid-scroll"><table class="fin-grid-table fin-table-compact" aria-label="Vue comptable GL">';
    html += '<thead><tr class="fin-grid-hdr fin-grid-hdr--group">'
      + '<th rowspan="2" class="fin-grid-th fin-grid-th--label" scope="col">Catégorie</th>'
      + '<th colspan="2" class="fin-grid-th fin-grid-th--group fin-grid-th--actuals" scope="colgroup">ACTUALS</th>'
      + '<th colspan="2" class="fin-grid-th fin-grid-th--group fin-grid-th--placeholder" scope="colgroup">BUDGET</th>'
      + '<th colspan="2" class="fin-grid-th fin-grid-th--group fin-grid-th--placeholder" scope="colgroup">N-1</th>'
      + '</tr><tr class="fin-grid-hdr fin-grid-hdr--sub">'
      + '<th class="fin-grid-th fin-grid-th--actuals" scope="col">Mois CHF/HL</th>'
      + '<th class="fin-grid-th fin-grid-th--actuals" scope="col">' + esc(ytdLabel) + ' CHF/HL</th>'
      + '<th class="fin-grid-th fin-grid-th--placeholder" scope="col">Mois</th>'
      + '<th class="fin-grid-th fin-grid-th--placeholder" scope="col">' + esc(ytdLabel) + '</th>'
      + '<th class="fin-grid-th fin-grid-th--placeholder" scope="col">Mois</th>'
      + '<th class="fin-grid-th fin-grid-th--placeholder" scope="col">' + esc(ytdLabel) + '</th>'
      + '</tr></thead><tbody>';

    bkSections.forEach(function(s) {
      var mo  = bkMo[s.key]  || {};
      var ytd = bkYtd[s.key] || {};
      html += '<tr class="fin-grid-row">'
        + '<td class="fin-grid-td fin-grid-td--label">' + esc(s.label) + '</td>'
        + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--actuals">' + esc(mo.perHl  != null ? fmt(mo.perHl,  2) : '0.00') + '</td>'
        + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--actuals">' + esc(ytd.perHl != null ? fmt(ytd.perHl, 2) : '0.00') + '</td>'
        + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--placeholder">—</td>'
        + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--placeholder">—</td>'
        + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--placeholder">—</td>'
        + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--placeholder">—</td>'
        + '</tr>';
    });

    var totMoPhl  = bkMo.totalPerHl  != null ? fmt(bkMo.totalPerHl,  2) : '0.00';
    var totYtdPhl = bkYtd.totalPerHl != null ? fmt(bkYtd.totalPerHl, 2) : '0.00';
    html += '<tr class="fin-grid-row fin-grid-row--grand-subtotal">'
      + '<td class="fin-grid-td fin-grid-td--label fin-grid-td--label-strong">TOTAL COGS VARIABLE</td>'
      + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--actuals fin-grid-td--bold">' + esc(totMoPhl)  + '</td>'
      + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--actuals fin-grid-td--bold">' + esc(totYtdPhl) + '</td>'
      + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--placeholder">—</td>'
      + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--placeholder">—</td>'
      + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--placeholder">—</td>'
      + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--placeholder">—</td>'
      + '</tr>';

    html += '</tbody></table></div>';

    var diffMo     = chkMo.diffChf  != null ? chkMo.diffChf  : null;
    var diffYtd    = chkYtd.diffChf != null ? chkYtd.diffChf : null;
    var diffMoPhl  = (diffMo  != null && bkMo.hlPackaged  > 0) ? diffMo  / bkMo.hlPackaged  : null;
    var diffYtdPhl = (diffYtd != null && bkYtd.hlPackaged > 0) ? diffYtd / bkYtd.hlPackaged : null;

    function checkSign(v) {
      if (v === null) return '';
      return v > 0 ? 'fin-check-positive' : (v < 0 ? 'fin-check-negative' : '');
    }
    function checkTxt(v) {
      if (v === null) return '—';
      return (v >= 0 ? '+' : '') + fmt(v, 2) + ' CHF/HL';
    }

    html += '<div class="fin-check-row">';
    html += '<span class="fin-check-label">Écart opérationnel − comptable (mois sélectionné) :</span> ';
    html += '<span class="fin-check-value ' + checkSign(diffMoPhl) + '">' + esc(checkTxt(diffMoPhl)) + '</span>';
    html += '&ensp;<span class="fin-check-sep">|</span>&ensp;';
    html += '<span class="fin-check-label">' + esc(ytdLabel) + ' :</span> ';
    html += '<span class="fin-check-value ' + checkSign(diffYtdPhl) + '">' + esc(checkTxt(diffYtdPhl)) + '</span>';
    html += '</div>';
    html += '<p class="fin-check-note">Un écart important sur un mois non clôturé est attendu (données comptables BC incomplètes).</p>';
    html += '</div>'; // fin-vue-comptable

    wrapEl.innerHTML = html;
  }

  /**
   * Render the operational COP P&L grid (primary) + Vue comptable secondary block.
   * data: the cop-grid endpoint response {operational, n1, booked, check, ytdLabel, month}
   * Column layout: ACTUALS[mois,YTD] | N-1[mois,YTD] | Vs N-1[mois,YTD] — 6 data cols.
   * COP has no budget (board decision); no BUDGET column on this grid.
   */
  function renderCopPLGrid(wrapEl, data) {
    if (!wrapEl || !data || !data.operational) {
      if (wrapEl) wrapEl.innerHTML = '<p class="fin-empty">Données indisponibles.</p>';
      return;
    }

    var op        = data.operational;
    var n1Data    = data.n1        || {};
    var bk        = data.booked    || {};
    var chk       = data.check     || {};
    var ytdLabel  = data.ytdLabel  || 'YTD';
    var lines     = (op.month || {}).lines || [];

    // COP grid: ACTUALS[mois,YTD] | N-1[mois,YTD] | Vs N-1[mois,YTD] — 6 data cols
    var numCols = 6;
    var html = '<div class="fin-grid-scroll"><table class="fin-grid-table" aria-label="P&amp;L COP Grid">';
    html += '<thead>';
    html += '<tr class="fin-grid-hdr fin-grid-hdr--group">'
      + '<th rowspan="3" class="fin-grid-th fin-grid-th--label" scope="col"></th>'
      + '<th colspan="2" class="fin-grid-th fin-grid-th--group fin-grid-th--actuals" scope="colgroup">ACTUALS</th>'
      + '<th colspan="2" class="fin-grid-th fin-grid-th--group fin-grid-th--n1" scope="colgroup">N-1</th>'
      + '<th colspan="2" class="fin-grid-th fin-grid-th--group fin-grid-th--vs-n1" scope="colgroup">Vs N-1</th>'
      + '</tr>';
    html += '<tr class="fin-grid-hdr fin-grid-hdr--sub">'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--actuals" scope="col">Mois</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--actuals" scope="col">' + esc(ytdLabel) + '</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--n1" scope="col">Mois</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--n1" scope="col">' + esc(ytdLabel) + '</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--vs-n1" scope="col">Mois</th>'
      + '<th class="fin-grid-th fin-grid-th--period fin-grid-th--vs-n1" scope="col">' + esc(ytdLabel) + '</th>'
      + '</tr>';
    html += '<tr class="fin-grid-hdr fin-grid-hdr--unit">'
      + '<th class="fin-grid-th fin-grid-th--actuals" scope="col">CHF/HL</th>'
      + '<th class="fin-grid-th fin-grid-th--actuals" scope="col">CHF/HL</th>'
      + '<th class="fin-grid-th fin-grid-th--n1" scope="col">CHF/HL</th>'
      + '<th class="fin-grid-th fin-grid-th--n1" scope="col">CHF/HL</th>'
      + '<th class="fin-grid-th fin-grid-th--vs-n1" scope="col">%</th>'
      + '<th class="fin-grid-th fin-grid-th--vs-n1" scope="col">%</th>'
      + '</tr>';
    html += '</thead><tbody>';

    // Build lookup maps: key → row for actuals YTD, N-1 month, N-1 YTD
    var ytdByKey  = {};
    var n1MoByKey = {};
    var n1YtdByKey = {};
    ((op.ytd  || {}).lines || []).forEach(function(r) { ytdByKey[r.key]   = r; });
    ((n1Data.month || {}).lines || []).forEach(function(r) { n1MoByKey[r.key]  = r; });
    ((n1Data.ytd   || {}).lines || []).forEach(function(r) { n1YtdByKey[r.key] = r; });

    var sectionOf = {
      malts: 'Brewing', hops: 'Brewing', yeast: 'Brewing', other_ing: 'Brewing', sub_brewing: 'Brewing',
      bottles: 'Packaging', cans: 'Packaging', reg_cardboard: 'Packaging', spec_cardboard: 'Packaging',
      plastic: 'Packaging', labels: 'Packaging', keg: 'Packaging', external: 'Packaging', sub_packaging: 'Packaging',
      co2: 'Indirect', chemical: 'Indirect', small_equip: 'Indirect', transport: 'Indirect', sub_indirect: 'Indirect',
      gas_water: 'Utilities', electricity: 'Utilities', waste: 'Utilities', sub_utilities: 'Utilities',
      qa_qc: 'R&D', rd_purchases: 'R&D', sub_rd: 'R&D',
      grand_variable: null,
    };
    var lastSection = null;
    var sectionLabels = { Brewing: 'Brewing', Packaging: 'Packaging', Indirect: 'Indirect', Utilities: 'Utilities', 'R&D': 'R&D' };

    lines.forEach(function(row) {
      var type   = row.type;
      var key    = row.key;
      var sec    = sectionOf[key] !== undefined ? sectionOf[key] : null;
      var ytdRow   = ytdByKey[key]   || {};
      var n1MoRow  = n1MoByKey[key]  || {};
      var n1YtdRow = n1YtdByKey[key] || {};

      if (sec !== null && sec !== lastSection) {
        lastSection = sec;
        html += '<tr class="fin-grid-row fin-grid-row--subhdr">'
          + '<td colspan="' + (numCols + 1) + '" class="fin-grid-td fin-grid-td--subhdr">'
          + esc(sectionLabels[sec] || sec) + '</td></tr>';
      }

      var isSubtotal = (type === 'subtotal');
      var isGrand    = (type === 'grand_subtotal');
      var isBold     = isSubtotal || isGrand;

      var rowCls   = 'fin-grid-row';
      if (isSubtotal) rowCls += ' fin-grid-row--subtotal';
      if (isGrand)    rowCls += ' fin-grid-row--grand-subtotal';

      var labelCls = 'fin-grid-td fin-grid-td--label';
      if (isBold) labelCls += ' fin-grid-td--label-strong';

      function actCell(phl) {
        var cls = 'fin-grid-td fin-grid-td--num fin-grid-td--actuals';
        if (isBold) cls += ' fin-grid-td--bold';
        var txt = (phl === null || phl === undefined) ? '0.00' : fmt(phl, 2);
        if (phl !== null && phl !== undefined && phl < 0) cls += ' fin-grid-td--negative';
        return '<td class="' + cls + '">' + esc(txt) + '</td>';
      }

      function n1Cell(phl) {
        var cls = 'fin-grid-td fin-grid-td--num fin-grid-td--n1';
        if (isBold) cls += ' fin-grid-td--bold';
        var txt = (phl === null || phl === undefined) ? '0.00' : fmt(phl, 2);
        return '<td class="' + cls + '">' + esc(txt) + '</td>';
      }

      function vsN1Cell(actual, n1phl) {
        if (n1phl === null || n1phl === undefined || n1phl === 0) {
          var cls0 = 'fin-grid-td fin-grid-td--num fin-grid-td--vs-n1';
          if (isBold) cls0 += ' fin-grid-td--bold';
          return '<td class="' + cls0 + '">—</td>';
        }
        var pct = ((actual - n1phl) / Math.abs(n1phl)) * 100;
        var cls = 'fin-grid-td fin-grid-td--num fin-grid-td--vs-n1';
        if (isBold) cls += ' fin-grid-td--bold';
        if (pct > 0) {
          cls += ' fin-grid-td--vs-n1-over';
        } else if (pct < 0) {
          cls += ' fin-grid-td--vs-n1-under';
        }
        var sign = pct >= 0 ? '+' : '';
        return '<td class="' + cls + '">' + esc(sign + fmt(pct, 1) + '%') + '</td>';
      }

      var actMo  = row.perHl    !== undefined ? row.perHl    : null;
      var actYtd = ytdRow.perHl !== undefined ? ytdRow.perHl : null;
      var n1Mo   = n1MoRow.perHl  !== undefined ? n1MoRow.perHl  : null;
      var n1Ytd  = n1YtdRow.perHl !== undefined ? n1YtdRow.perHl : null;

      html += '<tr class="' + rowCls + '">';
      html += '<td class="' + labelCls + '">' + esc(row.label) + '</td>';
      html += actCell(actMo);
      html += actCell(actYtd);
      html += n1Cell(n1Mo);
      html += n1Cell(n1Ytd);
      html += vsN1Cell(actMo  !== null ? actMo  : 0, n1Mo);
      html += vsN1Cell(actYtd !== null ? actYtd : 0, n1Ytd);
      html += '</tr>';
    });

    html += '</tbody></table></div>';

    // Source chip
    html += '<p class="fin-secondary-note" style="margin-top:0.5rem">'
      + 'N-1 = exercice 2025 opérationnel. Certaines lignes overhead 2025 partiellement alimentées (lisent 0).'
      + '</p>';

    // ── Vue comptable (GL booké) secondary block ──────────────────────────────
    var bkMo  = bk.month  || {};
    var bkYtd = bk.ytd    || {};
    var chkMo = chk.month || {};
    var chkYtd = chk.ytd  || {};

    var bkSections = [
      { key: 'brewing',   label: 'Brewing' },
      { key: 'packaging', label: 'Packaging' },
      { key: 'indirect',  label: 'Indirect' },
      { key: 'utilities', label: 'Utilities' },
      { key: 'rd',        label: 'R&D' },
    ];

    html += '<div class="fin-vue-comptable">';
    html += '<h4 class="fin-secondary-title">Vue comptable (GL booké)</h4>';
    html += '<p class="fin-secondary-note">Source : charges BC importées — décalage comptable possible (mois non clôturés sous-estimés).</p>';
    html += '<div class="fin-grid-scroll"><table class="fin-grid-table fin-table-compact" aria-label="Vue comptable GL">';
    html += '<thead><tr class="fin-grid-hdr fin-grid-hdr--group">'
      + '<th rowspan="2" class="fin-grid-th fin-grid-th--label" scope="col">Catégorie</th>'
      + '<th colspan="2" class="fin-grid-th fin-grid-th--group fin-grid-th--actuals" scope="colgroup">ACTUALS</th>'
      + '</tr><tr class="fin-grid-hdr fin-grid-hdr--sub">'
      + '<th class="fin-grid-th fin-grid-th--actuals" scope="col">Mois CHF/HL</th>'
      + '<th class="fin-grid-th fin-grid-th--actuals" scope="col">' + esc(ytdLabel) + ' CHF/HL</th>'
      + '</tr></thead><tbody>';

    bkSections.forEach(function(s) {
      var mo  = bkMo[s.key]  || {};
      var ytd = bkYtd[s.key] || {};
      html += '<tr class="fin-grid-row">'
        + '<td class="fin-grid-td fin-grid-td--label">' + esc(s.label) + '</td>'
        + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--actuals">' + esc(mo.perHl  != null ? fmt(mo.perHl,  2) : '0.00') + '</td>'
        + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--actuals">' + esc(ytd.perHl != null ? fmt(ytd.perHl, 2) : '0.00') + '</td>'
        + '</tr>';
    });

    var totMoPhl  = bkMo.totalPerHl  != null ? fmt(bkMo.totalPerHl,  2) : '0.00';
    var totYtdPhl = bkYtd.totalPerHl != null ? fmt(bkYtd.totalPerHl, 2) : '0.00';
    html += '<tr class="fin-grid-row fin-grid-row--grand-subtotal">'
      + '<td class="fin-grid-td fin-grid-td--label fin-grid-td--label-strong">TOTAL COGS VARIABLE</td>'
      + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--actuals fin-grid-td--bold">' + esc(totMoPhl)  + '</td>'
      + '<td class="fin-grid-td fin-grid-td--num fin-grid-td--actuals fin-grid-td--bold">' + esc(totYtdPhl) + '</td>'
      + '</tr>';

    html += '</tbody></table></div>';

    var diffMo  = chkMo.diffChf  != null ? chkMo.diffChf  : null;
    var diffYtd = chkYtd.diffChf != null ? chkYtd.diffChf : null;
    var diffMoPhl  = (diffMo  != null && bkMo.hlPackaged  > 0) ? diffMo  / bkMo.hlPackaged  : null;
    var diffYtdPhl = (diffYtd != null && bkYtd.hlPackaged > 0) ? diffYtd / bkYtd.hlPackaged : null;

    function checkSign(v) {
      if (v === null) return '';
      return v > 0 ? 'fin-check-positive' : (v < 0 ? 'fin-check-negative' : '');
    }
    function checkTxt(v) {
      if (v === null) return '—';
      return (v >= 0 ? '+' : '') + fmt(v, 2) + ' CHF/HL';
    }

    html += '<div class="fin-check-row">';
    html += '<span class="fin-check-label">Écart opérationnel − comptable (mois sélectionné) :</span> ';
    html += '<span class="fin-check-value ' + checkSign(diffMoPhl) + '">' + esc(checkTxt(diffMoPhl)) + '</span>';
    html += '&ensp;<span class="fin-check-sep">|</span>&ensp;';
    html += '<span class="fin-check-label">' + esc(ytdLabel) + ' :</span> ';
    html += '<span class="fin-check-value ' + checkSign(diffYtdPhl) + '">' + esc(checkTxt(diffYtdPhl)) + '</span>';
    html += '</div>';
    html += '<p class="fin-check-note">Un écart important sur un mois non clôturé est attendu (données comptables BC incomplètes).</p>';
    html += '</div>'; // fin-vue-comptable

    wrapEl.innerHTML = html;
  }

  /* ── COP grid ──────────────────────────────────────────────────────────── */
  var copGridSelect  = document.getElementById('cop-grid-month-select');
  var copGridLoading = document.getElementById('cop-grid-loading');
  var copGridWrap    = document.getElementById('cop-grid-wrap');

  function loadAndRenderCopGrid(monthKey) {
    if (!copGridWrap) return;
    if (copGridLoading) copGridLoading.hidden = false;
    fetchGridSlice('cop-grid', monthKey, function(err, data) {
      if (copGridLoading) copGridLoading.hidden = true;
      if (!err && data) {
        renderCopPLGrid(copGridWrap, data);
      } else {
        copGridWrap.innerHTML = '<p class="fin-empty">Erreur chargement grille COP (' + esc(String(err)) + ').</p>';
      }
    });
  }

  if (copGridSelect) {
    var copGridDefault = window.FIN_GL_DEFAULT || (window.FIN_GL_MONTHS && window.FIN_GL_MONTHS.length
      ? window.FIN_GL_MONTHS[window.FIN_GL_MONTHS.length - 1] : null);
    if (copGridDefault) loadAndRenderCopGrid(copGridDefault);
    copGridSelect.addEventListener('change', function() { loadAndRenderCopGrid(this.value); });
  }

  /* ── COGS grid ─────────────────────────────────────────────────────────── */
  var cogsGridSelect  = document.getElementById('cogs-grid-month-select');
  var cogsGridLoading = document.getElementById('cogs-grid-loading');
  var cogsGridWrap    = document.getElementById('cogs-grid-wrap');

  function loadAndRenderCogsGrid(monthKey) {
    if (!cogsGridWrap) return;
    if (cogsGridLoading) cogsGridLoading.hidden = false;
    fetchGridSlice('cogs-grid', monthKey, function(err, data) {
      if (cogsGridLoading) cogsGridLoading.hidden = true;
      if (!err && data) {
        renderCogsPLGrid(cogsGridWrap, data);
      } else {
        cogsGridWrap.innerHTML = '<p class="fin-empty">Erreur chargement grille COGS (' + esc(String(err)) + ').</p>';
      }
    });
  }

  if (cogsGridSelect) {
    var cogsGridDefault = window.FIN_GL_DEFAULT || (window.FIN_GL_MONTHS && window.FIN_GL_MONTHS.length
      ? window.FIN_GL_MONTHS[window.FIN_GL_MONTHS.length - 1] : null);
    if (cogsGridDefault) loadAndRenderCogsGrid(cogsGridDefault);
    cogsGridSelect.addEventListener('change', function() { loadAndRenderCogsGrid(this.value); });
  }

  /* ════════════════════════════════════════════════════════════════════════════
     MODULE D — Coût par SKU — filtres client-side
     Filtre les lignes déjà rendues (server-side) sans rechargement.
  ════════════════════════════════════════════════════════════════════════════ */
  (function() {
    var recipeSel = document.getElementById('fin-sku-filter-recipe');
    var formatSel = document.getElementById('fin-sku-filter-format');
    var classSel  = document.getElementById('fin-sku-filter-class');
    var resetBtn  = document.getElementById('fin-sku-filter-reset');
    var countEl   = document.getElementById('fin-sku-filter-count');
    var emptyMsg  = document.getElementById('fin-sku-empty-msg');

    if (!recipeSel || !formatSel || !classSel) return;  // panel not rendered (DB error)

    var table = document.getElementById('fin-sku-table');
    if (!table) return;

    function applyFilters() {
      var fRecipe = recipeSel.value;
      var fFormat = formatSel.value;
      var fClass  = classSel.value;
      var anyFilter = fRecipe !== '' || fFormat !== '' || fClass !== '';

      var tbody = table.querySelector('tbody');
      if (!tbody) return;

      var allRows = Array.from(tbody.querySelectorAll('tr'));
      var visibleSkuCount = 0;
      var activeRecipes = new Set();

      // First pass: tag each sku-row visible/hidden, collect visible recipe names
      allRows.forEach(function(tr) {
        if (!tr.classList.contains('sku-row') && !tr.classList.contains('sku-group-head')) return;
        if (tr.classList.contains('sku-row')) {
          var r = tr.dataset.recipe  || '';
          var f = tr.dataset.format  || '';
          var c = tr.dataset.classification || '';
          var show = (!fRecipe || r === fRecipe)
                  && (!fFormat || f === fFormat)
                  && (!fClass  || c === fClass);
          tr.hidden = !show;
          if (show) { visibleSkuCount++; activeRecipes.add(r); }
        }
      });

      // Second pass: show group-head only if its recipe has ≥1 visible sku-row
      allRows.forEach(function(tr) {
        if (!tr.classList.contains('sku-group-head')) return;
        tr.hidden = !activeRecipes.has(tr.dataset.recipe || '');
      });

      // Update count badge + reset button
      if (resetBtn) { resetBtn.hidden = !anyFilter; }
      if (countEl)  {
        if (anyFilter) {
          countEl.hidden = false;
          countEl.textContent = visibleSkuCount + ' SKU' + (visibleSkuCount !== 1 ? 's' : '');
        } else {
          countEl.hidden = true;
        }
      }
      if (emptyMsg) { emptyMsg.hidden = visibleSkuCount > 0; }
    }

    recipeSel.addEventListener('change', applyFilters);
    formatSel.addEventListener('change', applyFilters);
    classSel.addEventListener('change',  applyFilters);
    if (resetBtn) {
      resetBtn.addEventListener('click', function() {
        recipeSel.value = '';
        formatSel.value = '';
        classSel.value  = '';
        applyFilters();
      });
    }
  }());

  /* ════════════════════════════════════════════════════════════════════════════
     MODULE D — Coût par SKU — DRILLDOWN MODAL
     Opens an in-page <dialog> with BOM decomposition when a SKU code is clicked.
     Lazy endpoint: /api/financier-data.php?module=sku-detail&sku=CODE
     Cache: one entry per SKU code (same sliceCache pattern as COGS months).
  ════════════════════════════════════════════════════════════════════════════ */
  (function() {
    var modal     = document.getElementById('fin-sku-modal');
    var modalBody = document.getElementById('fin-modal-body');
    var closeBtn  = document.getElementById('fin-sku-modal-close');
    if (!modal) return;

    /* Per-SKU response cache */
    var skuCache = {};

    /* Fetch with dedup — mirrors fetchCogsSlice pattern */
    var skuInflight = {};
    function fetchSkuDetail(skuCode, cb) {
      if (skuCache[skuCode]) { cb(null, skuCache[skuCode]); return; }
      if (skuInflight[skuCode]) { skuInflight[skuCode].push(cb); return; }
      skuInflight[skuCode] = [cb];
      fetch('/api/financier-data.php?module=sku-detail&sku=' + encodeURIComponent(skuCode))
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.ok) { skuCache[skuCode] = data; }
          var cbs = skuInflight[skuCode] || [];
          delete skuInflight[skuCode];
          cbs.forEach(function(fn) { fn(data.ok ? null : (data.reason || 'error'), data); });
        })
        .catch(function(err) {
          var cbs = skuInflight[skuCode] || [];
          delete skuInflight[skuCode];
          cbs.forEach(function(fn) { fn('network_error', null); });
        });
    }

    /* Populate modal header fields */
    function populateModalHeader(data) {
      var titleEl = document.getElementById('fin-sku-modal-title');
      var recipeEl = modal.querySelector('.fin-modal-recipe');
      var fmtEl    = modal.querySelector('.fin-modal-format-badge');
      var totalEl  = document.getElementById('fin-modal-total-chf');
      var chlEl    = document.getElementById('fin-modal-chf-hl');
      var freshEl  = document.getElementById('fin-modal-freshness');

      if (titleEl)  titleEl.textContent  = data.sku_code || '';
      if (recipeEl) recipeEl.textContent = data.recipe_short_name || '';
      if (fmtEl) {
        var fmtLow = (data.format || '').toLowerCase();
        fmtEl.className = 'fin-modal-format-badge sku-format-badge sku-format-badge--' + esc(fmtLow);
        fmtEl.textContent = data.format || '';
      }
      if (totalEl) totalEl.textContent = fmtChf(data.total || 0) + ' CHF';
      if (chlEl)   chlEl.textContent   = data.chf_per_hl != null ? fmtChf(data.chf_per_hl) + ' CHF/HL' : '—';
      if (freshEl) freshEl.textContent = data.freshness
        ? 'BOM compilé le ' + data.freshness
        : '';
    }

    /* Render BOM table + subtotals into modal body.
       Reuses .wort-table / .sku-bom-table classes from app.css — same look as
       sku-cost-detail.php. Every cell is run through esc() — no raw interpolation. */
    function renderModalBody(data) {
      if (!modalBody) return;
      var lines   = data.lines  || [];
      var total   = data.total  || 0;
      var brewSub = data.brewing_subtotal   || 0;
      var pkgSub  = data.packaging_subtotal || 0;

      if (!lines.length) {
        modalBody.innerHTML = '<p class="fin-empty">Aucune ligne BOM.</p>';
        return;
      }

      var html = '<div class="fin-modal-table-scroll">'
        + '<table class="wort-table sku-bom-table fin-modal-bom-table" aria-label="BOM ' + esc(data.sku_code || '') + '">'
        + '<thead><tr>'
        + '<th scope="col">Catégorie</th>'
        + '<th scope="col">Ingrédient</th>'
        + '<th scope="col">MI ID</th>'
        + '<th scope="col" class="fin-th--num">Qté</th>'
        + '<th scope="col">Unité</th>'
        + '<th scope="col" class="fin-th--num">Prix</th>'
        + '<th scope="col" class="fin-th--num">Coût CHF</th>'
        + '</tr></thead><tbody>';

      var prevSource = null;
      for (var i = 0; i < lines.length; i++) {
        var b      = lines[i];
        var src    = b.source || '';
        var cost   = typeof b.cost === 'number' ? b.cost : 0;
        var miMatched = !!b.mi_canonical;

        if (src !== prevSource) {
          html += '<tr class="sku-bom-source-head">'
            + '<td colspan="7" class="sku-bom-source-head__cell">' + esc(src || '—') + '</td>'
            + '</tr>';
          prevSource = src;
        }

        var catLabel = esc(b.category_canonical || b.category_raw || '—');
        var ingLabel = '<span class="' + (miMatched ? 'sku-ing--matched' : 'sku-ing--unresolved') + '">'
          + esc(b.ingredient_raw || '—') + '</span>';
        var miLabel  = b.mi_canonical
          ? '<span class="wort-mono wort-muted sku-bom-miid">' + esc(b.mi_canonical) + '</span>'
          : '<span class="wort-muted">—</span>';
        var qtyLabel = (typeof b.qty_per_unit === 'number')
          ? '<span class="wort-mono">' + esc(fmt(b.qty_per_unit, 4)) + '</span>'
          : '—';
        var priceLabel = (typeof b.price === 'number')
          ? '<span class="wort-mono wort-muted">' + esc(fmt(b.price, 4))
            + (b.currency && b.currency !== 'CHF' ? ' <span class="sku-bom-currency">' + esc(b.currency) + '</span>' : '')
            + '</span>'
          : '—';
        var costLabel = cost > 0
          ? '<span class="wort-mono sku-total-cost">' + esc(fmt(cost, 3)) + '</span>'
          : '—';

        html += '<tr class="sku-bom-row">'
          + '<td class="wort-td sku-bom-td sku-bom-td--cat">' + catLabel + '</td>'
          + '<td class="wort-td sku-bom-td sku-bom-td--ing">' + ingLabel + '</td>'
          + '<td class="wort-td sku-bom-td sku-bom-td--miid">' + miLabel + '</td>'
          + '<td class="wort-td sku-bom-td sku-bom-td--num">' + qtyLabel + '</td>'
          + '<td class="wort-td sku-bom-td">' + esc(b.ing_unit || '—') + '</td>'
          + '<td class="wort-td sku-bom-td sku-bom-td--num">' + priceLabel + '</td>'
          + '<td class="wort-td sku-bom-td sku-bom-td--num">' + costLabel + '</td>'
          + '</tr>';
      }
      html += '</tbody></table></div>';

      /* Subtotals section */
      html += '<div class="fin-modal-subtotals">'
        + '<div class="fin-modal-subtot-row">'
        + '<span class="fin-modal-subtot-label">Brewing</span>'
        + '<span class="fin-modal-subtot-val wort-mono">' + esc(fmtChf(brewSub)) + ' CHF</span>'
        + '</div>'
        + '<div class="fin-modal-subtot-row">'
        + '<span class="fin-modal-subtot-label">Packaging</span>'
        + '<span class="fin-modal-subtot-val wort-mono">' + esc(fmtChf(pkgSub)) + ' CHF</span>'
        + '</div>'
        + '<div class="fin-modal-subtot-row fin-modal-subtot-row--total">'
        + '<span class="fin-modal-subtot-label">Total</span>'
        + '<span class="fin-modal-subtot-val wort-mono">' + esc(fmtChf(total)) + ' CHF</span>'
        + '</div>'
        + '</div>';

      modalBody.innerHTML = html;
    }

    /* Open modal for a given SKU code */
    function openSkuModal(skuCode) {
      /* Show loading state immediately; populateModalHeader with skeleton */
      var titleEl = document.getElementById('fin-sku-modal-title');
      if (titleEl) titleEl.textContent = skuCode;
      if (modalBody) {
        modalBody.innerHTML = '<p class="fin-modal-loading" aria-live="polite">Chargement…</p>';
      }
      /* showModal() before fetch so the dialog is in the top layer;
         content is populated once the fetch resolves */
      modal.showModal();

      fetchSkuDetail(skuCode, function(err, data) {
        if (!modal.open) return; // user closed before data arrived
        if (err || !data) {
          if (modalBody) {
            modalBody.innerHTML = '<p class="fin-empty">Erreur chargement détail (' + esc(String(err)) + ').</p>';
          }
          return;
        }
        populateModalHeader(data);
        renderModalBody(data);
      });
    }

    /* Close modal helpers */
    function closeModal() {
      if (modal.open) modal.close();
    }

    /* Close on explicit close button */
    if (closeBtn) {
      closeBtn.addEventListener('click', closeModal);
    }

    /* Close on Escape is native to <dialog> — no extra handler needed.
       Close on backdrop click: detect click on the dialog element itself
       (outside the inner content box). */
    modal.addEventListener('click', function(e) {
      /* The dialog's padding/background IS the backdrop area here.
         If the target is the <dialog> itself (not a descendant), it's a backdrop click. */
      if (e.target === modal) {
        closeModal();
      }
    });

    /* Delegate click to all .sku-drilldown-btn buttons (server-rendered) */
    var skuTable = document.getElementById('fin-sku-table');
    if (skuTable) {
      skuTable.addEventListener('click', function(e) {
        var btn = e.target.closest('.sku-drilldown-btn');
        if (!btn) return;
        var skuCode = btn.dataset.sku;
        if (skuCode) openSkuModal(skuCode);
      });
    }
  }());

})();

/* ════════════════════════════════════════════════════════════════════════════
   MODULE E — FICHE COGS (variation de stock)
═══════════════════════════════════════════════════════════════════════════ */
(function () {
  var monthSel       = document.getElementById('fiche-month-select');
  var provenanceChip = document.getElementById('fiche-provenance-chip');
  var incompleteWarn = document.getElementById('fiche-incomplete-warn');
  var csvBtn         = document.getElementById('fiche-csv-btn');
  var sealBtn        = document.getElementById('fiche-seal-btn');
  if (!monthSel) return; // panel not present

  // ── Month-label helper (matches PHP fin_month_label) ──────────────────────
  var FR_MONTHS = ['Jan.','Fév.','Mar.','Avr.','Mai','Jun.','Jul.','Aoû.','Sep.','Oct.','Nov.','Déc.'];
  function fmtMonthLabel(mk) {
    if (!mk) return mk;
    var parts = mk.split('-');
    var mIdx = parseInt(parts[1], 10) - 1;
    return FR_MONTHS[mIdx] + ' ' + parts[0];
  }

  // ── CHF formatter (2 decimals, thousands separator) ───────────────────────
  function fmtChfVal(n) {
    return new Intl.NumberFormat('fr-CH', {
      minimumFractionDigits: 2, maximumFractionDigits: 2
    }).format(n) + ' CHF';
  }

  function updateFicheMonth(mk) {
    document.querySelectorAll('.fin-fiche-month-block').forEach(function (el) {
      el.hidden = el.dataset.month !== mk;
    });

    var prov     = (window.FIN_FICHE_PROVENANCE || {})[mk];
    var sealedBy = (window.FIN_FICHE_SEALED_BY  || {})[mk];
    var sealedAt = (window.FIN_FICHE_SEALED_AT  || {})[mk];

    if (provenanceChip) {
      if (prov === 'sealed') {
        // Format sealed_at as dd.mm.yyyy for fr-CH display
        var dateLabel = '';
        if (sealedAt) {
          var d = new Date(sealedAt.replace(' ', 'T'));
          dateLabel = d.toLocaleDateString('fr-CH');
        }
        var byLabel = sealedBy ? sealedBy : '';
        provenanceChip.textContent = 'Clôturé · signé' + (byLabel ? ' ' + byLabel : '') + (dateLabel ? ' · ' + dateLabel : '');
        provenanceChip.className   = 'fin-fiche-provenance-chip fin-fiche-provenance-chip--sealed';
      } else if (prov === 'seed') {
        provenanceChip.textContent = 'Référence d\'ouverture';
        provenanceChip.className   = 'fin-fiche-provenance-chip fin-fiche-provenance-chip--seed';
      } else if (prov === 'live') {
        provenanceChip.textContent = 'Calculé (live)';
        provenanceChip.className   = 'fin-fiche-provenance-chip fin-fiche-provenance-chip--computed';
      } else {
        provenanceChip.textContent = '';
        provenanceChip.className   = 'fin-fiche-provenance-chip';
      }
    }

    if (incompleteWarn) {
      incompleteWarn.hidden = !(window.FIN_FICHE_INCOMPLETE || {})[mk];
    }

    if (csvBtn) {
      csvBtn.href = '/api/cogs-fiche-csv.php?month=' + encodeURIComponent(mk);
    }

    var compCsvBtn = document.getElementById('fiche-comprehensive-csv-btn');
    if (compCsvBtn) {
      compCsvBtn.href = '/api/cogs-comprehensive-csv.php?month=' + encodeURIComponent(mk);
    }

    // Seal button: visible only to eligible users, only for live/sealed months
    if (sealBtn) {
      var canSeal = !!window.FIN_CAN_SEAL && (prov === 'live' || prov === 'sealed');
      sealBtn.hidden = !canSeal;
      sealBtn.textContent = (prov === 'sealed') ? 'Restater' : 'Sceller';
    }
  }

  var defaultMk = window.FIN_FICHE_DEFAULT || monthSel.value;
  updateFicheMonth(defaultMk);

  monthSel.addEventListener('change', function () {
    updateFicheMonth(this.value);
  });

  // ── Seal / Restate modal logic ─────────────────────────────────────────────
  var sealModal   = document.getElementById('fiche-seal-modal');
  if (!sealModal || !sealBtn) return;

  var sealForm    = document.getElementById('fiche-seal-form');
  var sealMonthIn = document.getElementById('fiche-seal-month-input');
  var sealTitle   = document.getElementById('fiche-seal-modal-title');
  var sealSummary = document.getElementById('fiche-seal-summary');
  var restateInfo = document.getElementById('fiche-seal-restate-info');
  var noteRow     = document.getElementById('fiche-seal-note-row');
  var noteField   = document.getElementById('fiche-seal-note');
  var sealSubmit  = document.getElementById('fiche-seal-submit');
  var sealCancel  = document.getElementById('fiche-seal-cancel');
  var sealClose   = document.getElementById('fiche-seal-modal-close');

  sealBtn.addEventListener('click', function () {
    var mk   = monthSel.value;
    var prov = (window.FIN_FICHE_PROVENANCE || {})[mk];
    var tot  = (window.FIN_FICHE_TOTALS    || {})[mk] || 0;
    var totFmt = fmtChfVal(tot);
    var mkLabel = fmtMonthLabel(mk);

    sealMonthIn.value = mk;
    noteField.value   = '';

    if (prov === 'sealed') {
      // Restatement
      var prevSealedBy = (window.FIN_FICHE_SEALED_BY || {})[mk];
      var prevSealedAt = (window.FIN_FICHE_SEALED_AT || {})[mk];
      var prevDate = '';
      if (prevSealedAt) {
        prevDate = new Date(prevSealedAt.replace(' ', 'T')).toLocaleDateString('fr-CH');
      }
      sealTitle.textContent = 'Restater la fiche — ' + mkLabel;
      sealSummary.textContent = 'Ce mois a déjà été scellé'
        + (prevSealedBy ? ' par ' + prevSealedBy : '')
        + (prevDate ? ' le ' + prevDate : '')
        + '. Un nouveau scellement remplacera les valeurs figées par : '
        + totFmt + '.';
      restateInfo.hidden = false;
      restateInfo.textContent = 'Le motif de restatement est obligatoire.';
      noteRow.hidden = false;
      noteField.required = true;
      sealSubmit.textContent = 'Restater définitivement';
    } else {
      // First seal
      sealTitle.textContent = 'Sceller la fiche — ' + mkLabel;
      sealSummary.textContent = 'Figer définitivement ' + mkLabel + ' à ' + totFmt + '.';
      restateInfo.hidden = true;
      noteRow.hidden = false;
      noteField.required = false;
      sealSubmit.textContent = 'Sceller définitivement';
    }

    sealModal.showModal();
    noteField.focus();
  });

  function closeSealModal() { sealModal.close(); }
  sealCancel.addEventListener('click', closeSealModal);
  sealClose.addEventListener('click', closeSealModal);
  sealModal.addEventListener('click', function (e) {
    if (e.target === sealModal) closeSealModal();
  });
  sealModal.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeSealModal();
  });

  // Form submit: validate mandatory note on restate, then let it POST naturally (PRG)
  sealForm.addEventListener('submit', function (e) {
    var prov = (window.FIN_FICHE_PROVENANCE || {})[sealMonthIn.value];
    if (prov === 'sealed' && noteField.value.trim().length === 0) {
      e.preventDefault();
      noteField.setCustomValidity('Le motif de restatement est obligatoire.');
      noteField.reportValidity();
      return;
    }
    noteField.setCustomValidity('');
    sealSubmit.disabled = true;
    sealSubmit.textContent = 'En cours…';
  });
}());
