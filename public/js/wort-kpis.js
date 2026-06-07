/* ═══════════════════════════════════════════════════════════
   wort-kpis.js — Production de moût KPIs
   Data source: window.WORT_KPIS (server-injected)
   No CDN chart library — hand-rolled SVG/CSS charts.
   Shared primitives live in kpi-charts.js (loaded first).
   ═══════════════════════════════════════════════════════════ */

'use strict';

/* ── Pull shared primitives from kpi-charts.js ── */
const escHtml        = window.KpcCharts.escHtml;
const fmt            = window.KpcCharts.fmt;
const svgEl          = window.KpcCharts.svgEl;
const MONTHS_FR      = window.KpcCharts.KPC_MONTHS_FR;
const buildBarChart  = window.KpcCharts.buildBarChart;
const paceTintResult = window.KpcCharts.paceTintResult;

/* ── Palette — read from CSS tokens at runtime ── */
const CS = getComputedStyle(document.documentElement);
function tok(name) { return CS.getPropertyValue(name).trim(); }
const C = {
  core:     tok('--hop')         || '#567020',
  spec:     tok('--cat-process') || '#5a4a8c',
  contract: tok('--cold')        || '#2f5575',
  ok:       tok('--ok')          || '#3d6826',
  ember:    tok('--ember')       || '#b34428',
  hairline: tok('--hairline')    || '#c8b48a',
  bg:       tok('--bg')          || '#f1e8d4',
  ink_faint:tok('--ink-faint')   || '#7a6647',
  ink_mute: tok('--ink-mute')    || '#4a3820',
};

/* ═══════════════════════════════════════════════════════════
   STATE
   ═══════════════════════════════════════════════════════════ */
const KD = window.WORT_KPIS;   /* server payload */
let activeYear = KD.active_year;
let hmGranularity = 'month';   /* 'month' | 'quarter' */

/* ═══════════════════════════════════════════════════════════
   TOOLTIP
   ═══════════════════════════════════════════════════════════ */
const tip = document.getElementById('wk-tooltip');
function showTip(e, html) {
  if (!tip) return;
  tip.innerHTML = html;
  tip.classList.add('visible');
  moveTip(e);
}
function moveTip(e) {
  if (!tip) return;
  const x = e.clientX + 14, y = e.clientY - 10;
  tip.style.left = Math.min(x, window.innerWidth - tip.offsetWidth - 8) + 'px';
  tip.style.top  = Math.max(8, y) + 'px';
}
function hideTip() { if (tip) tip.classList.remove('visible'); }

/* ═══════════════════════════════════════════════════════════
   RESOLVE YEAR DATA from WORT_KPIS payload
   ═══════════════════════════════════════════════════════════ */
function resolveData(year) {
  if (year === 'all') {
    return KD.annual_view;
  }
  const y = KD.years_data[year];
  if (!y) return null;
  return y;
}

/* ═══════════════════════════════════════════════════════════
   A. STAT CARDS
   ═══════════════════════════════════════════════════════════ */
function renderStats(year, d) {
  const el = document.getElementById('wk-stat-cards');
  if (!el || !d) return;

  const t = d.cards;
  const partialClass = d.is_partial ? 'wk-stat--partial' : '';

  /* YoY card */
  let yoyHtml = '';
  if (d.yoy != null) {
    const pct = d.yoy;
    const cls = pct >= 0 ? 'wk-stat--up' : 'wk-stat--down';
    const label = year === 'all' ? 'Δ vie totale' : ('Δ vs ' + escHtml(String(year - 1)));
    yoyHtml = '<div class="wk-stat ' + cls + '">' +
      '<div class="wk-stat__label">' + label + '</div>' +
      '<div class="wk-stat__val">' + escHtml(Math.abs(pct).toFixed(1)) + '%</div>' +
      '<div class="wk-stat__sub">Nébuleuse ' + (d.yoy_label ? escHtml(d.yoy_label) : 'totale') + '</div>' +
      '</div>';
  }

  const avgHl = (t.brews && t.brews > 0) ? fmt(t.total_hl / t.brews, 1) : '—';
  const ratioNeb = t.total_hl > 0 ? fmt(t.neb_hl / t.total_hl * 100, 1) : '—';
  const brewsSub = (year !== 'all' && t.neb_brews != null)
    ? (escHtml(String(t.neb_brews)) + ' Neb + ' + escHtml(String(t.contract_brews)) + ' Contrat')
    : 'Toutes années';

  el.innerHTML =
    '<div class="wk-stat ' + partialClass + '">' +
      '<div class="wk-stat__label">Total HL produit</div>' +
      '<div class="wk-stat__val">' + escHtml(fmt(t.total_hl, 1)) + '</div>' +
      '<div class="wk-stat__sub">HL de moût</div>' +
    '</div>' +
    '<div class="wk-stat wk-stat--accent ' + partialClass + '">' +
      '<div class="wk-stat__label">HL Nébuleuse</div>' +
      '<div class="wk-stat__val">' + escHtml(fmt(t.neb_hl, 1)) + '</div>' +
      '<div class="wk-stat__sub">Gamme principale + Spéciales</div>' +
    '</div>' +
    '<div class="wk-stat wk-stat--contract">' +
      '<div class="wk-stat__label">HL Contrat</div>' +
      '<div class="wk-stat__val">' + escHtml(fmt(t.contract_hl, 1)) + '</div>' +
      '<div class="wk-stat__sub">Brassages clients</div>' +
    '</div>' +
    '<div class="wk-stat">' +
      '<div class="wk-stat__label">Ratio Nébuleuse</div>' +
      '<div class="wk-stat__val">' + escHtml(ratioNeb) + '%</div>' +
      '<div class="wk-stat__sub">Part Nébuleuse</div>' +
    '</div>' +
    '<div class="wk-stat">' +
      '<div class="wk-stat__label">Brews</div>' +
      '<div class="wk-stat__val">' + escHtml(t.brews != null ? String(t.brews) : '—') + '</div>' +
      '<div class="wk-stat__sub">' + brewsSub + '</div>' +
    '</div>' +
    '<div class="wk-stat">' +
      '<div class="wk-stat__label">Moy. HL / brassin</div>' +
      '<div class="wk-stat__val">' + escHtml(avgHl) + '</div>' +
      '<div class="wk-stat__sub">HL par brew</div>' +
    '</div>' +
    '<div class="wk-stat">' +
      '<div class="wk-stat__label">Gamme principale</div>' +
      '<div class="wk-stat__val">' + escHtml(fmt(t.core_hl, 1)) + '</div>' +
      '<div class="wk-stat__sub">Core recipes</div>' +
    '</div>' +
    '<div class="wk-stat">' +
      '<div class="wk-stat__label">Spéciales</div>' +
      '<div class="wk-stat__val">' + escHtml(fmt(t.spec_hl, 1)) + '</div>' +
      '<div class="wk-stat__sub">Collabs, EPH</div>' +
    '</div>' +
    yoyHtml;
}

/* buildBarChart is imported from kpi-charts.js (window.KpcCharts.buildBarChart).
   The wort page passes the shared tooltip callbacks via opts; kpi-charts.js
   creates its own local tooltip per chart, which is fine for the KPI tab. */

/* ═══════════════════════════════════════════════════════════
   B. NEB vs CONTRACT
   ═══════════════════════════════════════════════════════════ */
function renderNebContract(d) {
  const el = document.getElementById('wk-chart-neb-contract');
  if (!el) return;
  const monthly = d.monthly.map(function(m) { return [m[0] + m[1], m[2]]; });
  buildBarChart(el, monthly, [
    { color: C.core,     labelFn: function() { return 'Nébuleuse'; } },
    { color: C.contract, labelFn: function() { return 'Contrat'; } },
  ], { height: 200, partial: d.is_partial, illustrative: d.is_illustrative, yUnit: 'HL' });
}

/* ═══════════════════════════════════════════════════════════
   C. NEB COMPOSITION
   ═══════════════════════════════════════════════════════════ */
function renderNebComp(d) {
  const el = document.getElementById('wk-chart-neb-comp');
  if (!el) return;
  const monthly = d.monthly.map(function(m) { return [m[0], m[1]]; });
  buildBarChart(el, monthly, [
    { color: C.core, labelFn: function() { return 'Gamme principale'; } },
    { color: C.spec, labelFn: function() { return 'Spéciales'; } },
  ], { height: 180, partial: d.is_partial, illustrative: d.is_illustrative, yUnit: 'HL' });
}

/* ═══════════════════════════════════════════════════════════
   E. CONTRACT CHART
   ═══════════════════════════════════════════════════════════ */
function renderContract(d) {
  const el = document.getElementById('wk-chart-contract');
  if (!el) return;
  const monthly = d.monthly.map(function(m) { return [m[2]]; });
  buildBarChart(el, monthly, [
    { color: C.contract, labelFn: function() { return 'Contrat'; } },
  ], { height: 180, partial: d.is_partial, illustrative: d.is_illustrative, yUnit: 'HL' });
}

/* ═══════════════════════════════════════════════════════════
   D. QUARTERLY
   ═══════════════════════════════════════════════════════════ */
function renderQuarterly(d) {
  const el = document.getElementById('wk-chart-quarterly');
  if (!el) return;
  const quarters = [
    { label: 'Q1 — Jan/Fév/Mar', months: [0,1,2] },
    { label: 'Q2 — Avr/Mai/Jun', months: [3,4,5] },
    { label: 'Q3 — Jul/Aoû/Sep', months: [6,7,8] },
    { label: 'Q4 — Oct/Nov/Déc', months: [9,10,11] },
  ];

  const maxQTotal = Math.max.apply(null, quarters.map(function(q) {
    return q.months.reduce(function(s, mi) {
      return s + (d.monthly[mi][0] || 0) + (d.monthly[mi][1] || 0) + (d.monthly[mi][2] || 0);
    }, 0);
  }).concat([1]));

  el.innerHTML = quarters.map(function(q) {
    const core = q.months.reduce(function(s, mi) { return s + (d.monthly[mi][0] || 0); }, 0);
    const spec  = q.months.reduce(function(s, mi) { return s + (d.monthly[mi][1] || 0); }, 0);
    const ctr   = q.months.reduce(function(s, mi) { return s + (d.monthly[mi][2] || 0); }, 0);
    const total = core + spec + ctr;

    const scalePct = function(v) { return Math.max(v / maxQTotal * 100, v > 0 ? 1 : 0).toFixed(1); };

    const coreBar = core > 0 ? '<div class="wk-qtr-bar wk-qtr-bar--core" style="width:' + scalePct(core) + '%">' + (core >= 50 ? Math.round(core) + ' HL' : '') + '</div>' : '';
    const specBar  = spec > 0 ? '<div class="wk-qtr-bar wk-qtr-bar--spec" style="width:' + scalePct(spec) + '%">' + (spec >= 50 ? Math.round(spec) + ' HL' : '') + '</div>' : '';
    const ctrBar   = ctr > 0  ? '<div class="wk-qtr-bar wk-qtr-bar--contract" style="width:' + scalePct(ctr) + '%">' + (ctr >= 50 ? Math.round(ctr) + ' HL' : '') + '</div>' : '';
    const zeroHint = total === 0 ? '<div style="color:var(--ink-faint);font-size:.68rem;font-family:\'JetBrains Mono\',monospace;padding:4px 0">—</div>' : '';

    return '<div class="wk-qtr-col">' +
      '<div class="wk-qtr-col__label">' + escHtml(q.label) + '</div>' +
      '<div class="wk-qtr-col__total-banner"><small>TOTAL</small>' + escHtml(fmt(total, 1)) + ' HL</div>' +
      '<div class="wk-qtr-stack">' + coreBar + specBar + ctrBar + zeroHint + '</div>' +
      '<div class="wk-qtr-total">' +
        '<span class="wk-qtr-total__lbl">Nébuleuse </span>' + escHtml(fmt(core + spec, 1)) + ' HL' +
        (ctr > 0 ? '<br><span class="wk-qtr-total__lbl">dont Contrat </span>' + escHtml(fmt(ctr, 1)) + ' HL' : '') +
      '</div>' +
    '</div>';
  }).join('');
}

/* ═══════════════════════════════════════════════════════════
   F. CUMULATIVE YTD — multi-year overlay
   Active year: thick --hop accent, area fill, dots, end-value label.
   Other years 2021→present: thin muted lines, end-of-line year label.
   In-progress year (last data month < 11) stops at last data month.
   Complete years run all 12 months.
   X-axis: uniform Jan→Dec (0→11) shared across all series.
   Y-axis: global max across all years.
   ═══════════════════════════════════════════════════════════ */
function renderCumul(d) {
  const el = document.getElementById('wk-chart-cumul');
  if (!el) return;
  el.innerHTML = '';

  /* ── Build per-year cumulative series from years_data ── */
  /* years_data[yr].monthly is an array of 12 elements [core, special, contract].
     Nébuleuse = monthly[i][0] + monthly[i][1] (core + special). */
  const allYears = Object.keys(KD.years_data).map(Number).sort();
  if (!allYears.length) { el.textContent = '—'; return; }

  /* Compute the last data month index for the active year (d) — reuse existing logic */
  let activeLastDataMi = -1;
  d.monthly.forEach(function(m, i) {
    if ((m[0] || 0) + (m[1] || 0) > 0) activeLastDataMi = i;
  });
  if (activeLastDataMi < 0) { el.textContent = '—'; return; }

  /* Build series for all years */
  const yearSeries = {};
  allYears.forEach(function(yr) {
    const yd = KD.years_data[yr];
    if (!yd || !yd.monthly) return;
    /* Last data month for this year (0-based idx) */
    let lastMi = -1;
    yd.monthly.forEach(function(m, i) {
      if ((m[0] || 0) + (m[1] || 0) > 0) lastMi = i;
    });
    if (lastMi < 0) return; /* no data — skip */
    /* Build cumulative array, stopping at lastMi */
    const cum = [];
    let run = 0;
    for (let i = 0; i <= lastMi; i++) {
      run += (yd.monthly[i][0] || 0) + (yd.monthly[i][1] || 0);
      cum.push({ mi: i, val: Math.round(run * 10) / 10 });
    }
    yearSeries[yr] = { cum: cum, lastMi: lastMi, total: Math.round(run * 10) / 10 };
  });

  const seriesYears = Object.keys(yearSeries).map(Number).sort();
  if (!seriesYears.length) { el.textContent = '—'; return; }

  /* Global Y max across all series */
  let globalMax = 0;
  seriesYears.forEach(function(yr) {
    const last = yearSeries[yr].cum[yearSeries[yr].cum.length - 1];
    if (last && last.val > globalMax) globalMax = last.val;
  });
  if (globalMax === 0) globalMax = 1;

  /* ── SVG dimensions ── */
  const W = 840, H = 240;
  const padL = 48, padR = 50, padT = 16, padB = 36;
  const chartW = W - padL - padR, chartH = H - padT - padB;

  /* X: uniform Jan(0)→Dec(11) scale */
  const xOf = function(mi) { return padL + (mi / 11) * chartW; };
  /* Y: 0→globalMax */
  const yOf = function(v)  { return padT + chartH * (1 - v / globalMax); };

  const svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img' });

  /* Grid lines (4 horizontal) */
  for (let i = 0; i <= 4; i++) {
    const v = globalMax * i / 4;
    const y = yOf(v);
    svg.appendChild(svgEl('line', { x1: padL, y1: y, x2: W - padR, y2: y,
      stroke: C.hairline, 'stroke-width': i === 0 ? 1.5 : 0.5, opacity: i === 0 ? 0.9 : 0.45 }));
    if (i > 0) {
      const lbl = svgEl('text', { x: padL - 5, y: y + 4, 'text-anchor': 'end', fill: C.ink_faint,
        'font-family': 'JetBrains Mono,monospace', 'font-size': 9 });
      lbl.textContent = v >= 1000 ? (v / 1000).toFixed(1) + 'k' : Math.round(v);
      svg.appendChild(lbl);
    }
  }

  /* Month axis labels (Jan→Dec) */
  MONTHS_FR.forEach(function(label, mi) {
    const t = svgEl('text', { x: xOf(mi), y: H - padB + 14, 'text-anchor': 'middle', fill: C.ink_faint,
      'font-family': 'JetBrains Mono,monospace', 'font-size': 9, 'letter-spacing': '0.05em' });
    t.textContent = label;
    svg.appendChild(t);
  });

  /* Y-axis label */
  const yAxisLbl = svgEl('text', { x: 10, y: padT + chartH / 2, 'text-anchor': 'middle', fill: C.ink_faint,
    'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'letter-spacing': '0.1em',
    transform: 'rotate(-90, 10, ' + (padT + chartH / 2) + ')' });
  yAxisLbl.textContent = 'HL Nébuleuse';
  svg.appendChild(yAxisLbl);

  /* ── Draw muted lines first (background), then active year on top ── */
  seriesYears.forEach(function(yr) {
    if (yr === activeYear) return; /* draw active year last */
    const s = yearSeries[yr];
    if (!s || !s.cum.length) return;

    const pathD = s.cum.map(function(p, i) {
      return (i === 0 ? 'M' : 'L') + xOf(p.mi).toFixed(1) + ',' + yOf(p.val).toFixed(1);
    }).join(' ');

    svg.appendChild(svgEl('path', {
      d: pathD, fill: 'none',
      stroke: C.ink_mute,
      'stroke-width': 1,
      opacity: 0.35,
      'stroke-linejoin': 'round', 'stroke-linecap': 'round',
    }));

    /* End-of-line year label (right of last point, inside padR) */
    const last = s.cum[s.cum.length - 1];
    const lx = xOf(last.mi) + 4;
    const ly = yOf(last.val);
    const endLbl = svgEl('text', {
      x: lx, y: ly + 4,
      fill: C.ink_faint, opacity: 0.7,
      'font-family': 'JetBrains Mono,monospace', 'font-size': 8,
    });
    endLbl.textContent = String(yr);
    /* Tooltip on the line endpoint */
    const dot = svgEl('circle', { cx: xOf(last.mi), cy: ly, r: 2.5, fill: C.ink_mute, opacity: 0.45 });
    const yrLabel = String(yr);
    const totalVal = s.total;
    dot.addEventListener('mouseenter', function(e) {
      showTip(e, '<strong>' + escHtml(yrLabel) + '</strong> · Cumul ' + escHtml(MONTHS_FR[last.mi].toUpperCase()) + ' : <strong>' + fmt(totalVal, 1) + ' HL</strong>');
    });
    dot.addEventListener('mousemove', moveTip);
    dot.addEventListener('mouseleave', hideTip);
    svg.appendChild(dot);
    svg.appendChild(endLbl);
  });

  /* ── Active year: thick accent line with area fill and dots ── */
  const activeSeries = yearSeries[activeYear];
  if (activeSeries && activeSeries.cum.length) {
    const cumPoints = activeSeries.cum;
    const lastPoint = cumPoints[cumPoints.length - 1];

    /* Area fill */
    const areaD = 'M' + xOf(cumPoints[0].mi).toFixed(1) + ',' + yOf(0).toFixed(1) + ' ' +
      cumPoints.map(function(p) { return 'L' + xOf(p.mi).toFixed(1) + ',' + yOf(p.val).toFixed(1); }).join(' ') +
      ' L' + xOf(lastPoint.mi).toFixed(1) + ',' + yOf(0).toFixed(1) + ' Z';
    svg.appendChild(svgEl('path', { d: areaD, fill: C.core, opacity: 0.10 }));

    /* Line */
    const lineD = cumPoints.map(function(p, i) {
      return (i === 0 ? 'M' : 'L') + xOf(p.mi).toFixed(1) + ',' + yOf(p.val).toFixed(1);
    }).join(' ');
    svg.appendChild(svgEl('path', { d: lineD, fill: 'none', stroke: C.core,
      'stroke-width': 2.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));

    /* Dots with tooltip */
    cumPoints.forEach(function(p) {
      const cx = xOf(p.mi), cy = yOf(p.val);
      const dot = svgEl('circle', { cx: cx, cy: cy, r: 4, fill: C.core });
      dot.addEventListener('mouseenter', function(e) {
        showTip(e, '<strong>' + escHtml(String(activeYear)) + ' · ' + MONTHS_FR[p.mi].toUpperCase() + '</strong> · Cumul : <strong>' + fmt(p.val, 1) + ' HL</strong>');
      });
      dot.addEventListener('mousemove', moveTip);
      dot.addEventListener('mouseleave', hideTip);
      svg.appendChild(dot);
    });

    /* End-value label for active year */
    const endX = xOf(lastPoint.mi);
    const endY = yOf(lastPoint.val);
    const anchor = lastPoint.mi >= 9 ? 'end' : 'middle';
    const finalLbl = svgEl('text', {
      x: anchor === 'end' ? endX - 6 : endX, y: endY - 9,
      'text-anchor': anchor,
      fill: C.core, 'font-family': 'JetBrains Mono,monospace', 'font-size': 10, 'font-weight': 500,
    });
    finalLbl.textContent = fmt(lastPoint.val, 1) + ' HL';
    svg.appendChild(finalLbl);
  }

  el.appendChild(svg);

  /* ── Inject / refresh compact legend below the chart ── */
  const section = document.getElementById('wk-section-cumul');
  if (section) {
    var existingLegend = section.querySelector('.wk-cumul-legend');
    if (existingLegend) existingLegend.remove();
    var legendDiv = document.createElement('div');
    legendDiv.className = 'wk-cumul-legend';
    var legendHtml = '';
    /* Active year first */
    legendHtml += '<span class="wk-cumul-legend__item wk-cumul-legend__item--active">'
      + '<span class="wk-cumul-legend__swatch wk-cumul-legend__swatch--active"></span>'
      + escHtml(String(activeYear)) + '</span>';
    /* Muted years in reverse order (newest first among non-active) */
    var otherYears = seriesYears.filter(function(yr) { return yr !== activeYear; }).reverse();
    otherYears.forEach(function(yr) {
      legendHtml += '<span class="wk-cumul-legend__item">'
        + '<span class="wk-cumul-legend__swatch"></span>'
        + escHtml(String(yr)) + '</span>';
    });
    legendDiv.innerHTML = legendHtml;
    var chartCard = section.querySelector('.wk-chart-card');
    if (chartCard) {
      chartCard.after(legendDiv);
    } else {
      section.appendChild(legendDiv);
    }
  }
}

/* ═══════════════════════════════════════════════════════════
   G. ANNUAL TREND (year=all)
   ═══════════════════════════════════════════════════════════ */
function renderAnnualTrend() {
  const el = document.getElementById('wk-chart-annual');
  if (!el) return;
  el.innerHTML = '';

  const annual = KD.annual;
  if (!annual || !annual.length) { el.textContent = '—'; return; }

  const W = 760, H = 260;
  const padL = 48, padR = 16, padT = 16, padB = 48;
  const chartW = W - padL - padR, chartH = H - padT - padB;

  const maxVal = Math.max.apply(null, annual.map(function(a) { return a.total; }).concat([1]));
  const gridTop = Math.ceil(maxVal / 1000) * 1000;
  const yScale = function(v) { return chartH * (1 - v / gridTop); };
  const yearW = chartW / annual.length;
  const gap = yearW * 0.15;
  const stackW = yearW - gap * 2;

  const svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img' });

  /* Grid */
  for (let i = 0; i <= 5; i++) {
    const v = gridTop * i / 5;
    const y = padT + yScale(v);
    svg.appendChild(svgEl('line', { x1: padL, y1: y, x2: W - padR, y2: y,
      stroke: C.hairline, 'stroke-width': i === 0 ? 1.5 : 0.5, opacity: i === 0 ? 0.9 : 0.45 }));
    if (i > 0) {
      const lbl = svgEl('text', { x: padL - 5, y: y + 4, 'text-anchor': 'end', fill: C.ink_faint,
        'font-family': 'JetBrains Mono,monospace', 'font-size': 9 });
      lbl.textContent = (v / 1000).toFixed(0) + 'k';
      svg.appendChild(lbl);
    }
  }

  annual.forEach(function(a, ai) {
    const xBase = padL + ai * yearW + gap;
    const series = [
      { val: a.core,     color: C.core,     label: 'Gamme principale' },
      { val: a.spec,     color: C.spec,     label: 'Spéciales' },
      { val: a.contract, color: C.contract, label: 'Contrat' },
    ];

    /* Stacked bars */
    let yOffsetHL = 0;
    series.forEach(function(s) {
      if (!s.val) return;
      const bh = yScale(0) - yScale(s.val);
      const by = padT + yScale(s.val + yOffsetHL);
      const rect = svgEl('rect', {
        x: xBase, y: by, width: stackW, height: Math.max(bh, 1),
        fill: s.color, rx: 2,
      });
      rect.addEventListener('mouseenter', function(e) {
        showTip(e, '<strong>' + escHtml(String(a.year)) + '</strong> · ' + escHtml(s.label) + ' : <strong>' + escHtml(fmt(s.val, 1)) + ' HL</strong><br><span style="color:' + C.ink_faint + '">Total : ' + escHtml(fmt(a.total, 1)) + ' HL</span>');
      });
      rect.addEventListener('mousemove', moveTip);
      rect.addEventListener('mouseleave', hideTip);
      svg.appendChild(rect);
      yOffsetHL += s.val;
    });

    /* Total label above stacked bar */
    if (a.total > 0) {
      const topY = padT + yScale(a.total);
      const tlbl = svgEl('text', {
        x: xBase + stackW / 2, y: topY - 4,
        'text-anchor': 'middle', fill: C.ink_faint,
        'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'font-weight': 500,
      });
      tlbl.textContent = (a.total / 1000).toFixed(1) + 'k';
      svg.appendChild(tlbl);
    }

    /* Year label */
    const ylbl = svgEl('text', {
      x: xBase + stackW / 2, y: H - padB + 14,
      'text-anchor': 'middle', fill: C.ink_faint,
      'font-family': 'JetBrains Mono,monospace', 'font-size': 9, 'letter-spacing': '0.04em',
    });
    ylbl.textContent = String(a.year);
    svg.appendChild(ylbl);
  });

  /* Y-axis label */
  const yAxisLbl = svgEl('text', { x: 10, y: padT + chartH / 2, 'text-anchor': 'middle', fill: C.ink_faint,
    'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'letter-spacing': '0.1em',
    transform: 'rotate(-90, 10, ' + (padT + chartH / 2) + ')' });
  yAxisLbl.textContent = 'HL';
  svg.appendChild(yAxisLbl);

  el.appendChild(svg);
}

/* ═══════════════════════════════════════════════════════════
   G. HEATMAP — per-recipe × month OR × quarter (toggle)
   ═══════════════════════════════════════════════════════════ */
function renderHeatmap(year) {
  const container = document.getElementById('wk-chart-heatmap');
  const noteEl    = document.getElementById('wk-heatmap-note');
  if (!container) return;
  container.innerHTML = '';

  const d = year === 'all' ? null : resolveData(year);
  if (!d || !d.recipe_month || !d.recipe_month.length) {
    if (noteEl) noteEl.textContent = 'indisponible';
    container.innerHTML = '<div class="wk-hm-unavail">Données par recette indisponibles pour ' +
      escHtml(year === 'all' ? 'la vue multi-années' : String(year)) +
      ' — vue annuelle agrégée uniquement.</div>';
    return;
  }

  if (noteEl) noteEl.textContent = 'HL brassés — données réelles';

  const useQuarter = (hmGranularity === 'quarter');
  const recipeData = useQuarter ? d.recipe_quarter : d.recipe_month;
  const numCols = useQuarter ? 4 : (d.last_month || 12);
  const colHeaders = useQuarter
    ? ['Q1','Q2','Q3','Q4']
    : MONTHS_FR.slice(0, numCols);

  /* Group by bucket */
  const bucketOrder = ['core','special','contract'];
  const bucketLabels = { core: 'Gamme principale (Core)', special: 'Spéciales (collabs, EPH)', contract: 'Contrat' };
  const buckets = {};
  bucketOrder.forEach(function(b) { buckets[b] = []; });
  recipeData.forEach(function(r) {
    if (buckets[r.bucket]) buckets[r.bucket].push(r);
  });

  /* Global max for colour scaling */
  let globalMax = 0;
  recipeData.forEach(function(r) {
    r.vals.forEach(function(v) { if (v > globalMax) globalMax = v; });
  });
  if (globalMax === 0) globalMax = 1;

  function hexToRgb(h) {
    return [parseInt(h.slice(1,3),16), parseInt(h.slice(3,5),16), parseInt(h.slice(5,7),16)];
  }
  function lerp(a, b, t) { return Math.round(a + (b - a) * t); }
  function cellColor(val, bucket) {
    if (val <= 0) return null;
    const t = Math.pow(val / globalMax, 0.55);
    const bg = hexToRgb('#f1e8d4');
    const maps = { core: hexToRgb('#567020'), special: hexToRgb('#5a4a8c'), contract: hexToRgb('#2f5575') };
    const fg = maps[bucket] || maps.core;
    return 'rgb(' + lerp(bg[0],fg[0],t) + ',' + lerp(bg[1],fg[1],t) + ',' + lerp(bg[2],fg[2],t) + ')';
  }
  function cellTextColor(val) {
    return Math.pow(val / globalMax, 0.55) > 0.55 ? 'rgba(255,255,255,0.92)' : 'var(--ink-mute)';
  }

  const tbl = document.createElement('table');
  tbl.className = 'wk-heatmap' + (useQuarter ? ' wk-heatmap--quarterly' : '');

  /* Header */
  const thead = document.createElement('thead');
  let headHtml = '<tr><th class="wk-hm-recipe-col">Recette</th>';
  colHeaders.forEach(function(h) { headHtml += '<th>' + escHtml(h) + '</th>'; });
  headHtml += '<th class="wk-hm-total-col">Total</th></tr>';
  thead.innerHTML = headHtml;
  tbl.appendChild(thead);

  const tbody = document.createElement('tbody');
  bucketOrder.forEach(function(bk) {
    const recipes = buckets[bk];
    if (!recipes.length) return;

    /* Bucket separator */
    const sepRow = document.createElement('tr');
    sepRow.className = 'wk-hm-bucket';
    sepRow.innerHTML = '<td colspan="' + (numCols + 2) + '">' + escHtml(bucketLabels[bk]) + '</td>';
    tbody.appendChild(sepRow);

    /* Sort by total desc */
    const sorted = recipes.slice().sort(function(a, b) {
      return b.vals.reduce(function(s,v){return s+v;},0) - a.vals.reduce(function(s,v){return s+v;},0);
    });

    sorted.forEach(function(r) {
      const total = r.vals.reduce(function(s,v){return s+v;},0);
      const row = document.createElement('tr');
      row.className = 'wk-hm-row';

      let rowHtml = '<td class="wk-hm-name">' + escHtml(r.recipe) + '</td>';
      for (let ci = 0; ci < numCols; ci++) {
        const val = r.vals[ci] || 0;
        const bg = cellColor(val, bk);
        const tipLabel = useQuarter ? ('Q' + (ci+1)) : MONTHS_FR[ci];
        if (bg) {
          const txtColor = cellTextColor(val);
          const displayVal = val >= 100 ? Math.round(val) : val > 0 ? val.toFixed(1) : '';
          rowHtml += '<td><div class="wk-hm-cell" style="background:' + bg + ';color:' + txtColor + '" title="' + escHtml(r.recipe) + ' · ' + escHtml(tipLabel) + ' : ' + (val > 0 ? val.toFixed(1) + ' HL' : '—') + '">' + escHtml(displayVal) + '</div></td>';
        } else {
          rowHtml += '<td><div class="wk-hm-cell wk-hm-cell--empty" title="' + escHtml(r.recipe) + ' · ' + escHtml(tipLabel) + ' : —"></div></td>';
        }
      }
      rowHtml += '<td class="wk-hm-total"><span class="wk-hm-total-cell">' + escHtml(total > 0 ? fmt(total, 1) : '—') + '</span></td>';
      row.innerHTML = rowHtml;
      tbody.appendChild(row);
    });
  });

  tbl.appendChild(tbody);
  container.appendChild(tbl);
}

/* paceTintResult is imported from kpi-charts.js (window.KpcCharts.paceTintResult).
   Same logic: 'ok' | 'ember' | 'nouveau' | 'arrete' | 'neutral'. */

/* ═══════════════════════════════════════════════════════════
   H. YOY PCT — "Par bière · % du total {prevYear}"
   Bar-table: each beer's YTD curr vs full prevYear total,
   with a progress-bar showing % achieved.
   Tint is driven by PACE (same-period comparison), not headline %.
   ═══════════════════════════════════════════════════════════ */
function renderYoyPct(year) {
  var el = document.getElementById('wk-chart-yoy-pct');
  if (!el) return;

  var yoy = KD.yoy;
  if (!yoy || !yoy.beers || !yoy.beers.length) {
    el.innerHTML = '<div class="wk-hm-unavail">Données YoY indisponibles.</div>';
    return;
  }

  var prevYear = yoy.prevYear;
  var kpiYear  = yoy.kpiYear;
  var lastIdx  = yoy.lastDataMonthIdx; // 0-based

  // Update title labels (the year selector may have changed)
  var titlePrev = document.getElementById('wk-yoy-prev-year-label');
  if (titlePrev) titlePrev.textContent = String(prevYear);

  var BUCKET_ORDER  = ['core', 'special', 'contract'];
  var BUCKET_LABELS = { core: 'Gamme principale', special: 'Spéciales', contract: 'Contrat' };

  // Group beers by bucket
  var byBucket = { core: [], special: [], contract: [] };
  yoy.beers.forEach(function(b) {
    if (byBucket[b.bucket]) byBucket[b.bucket].push(b);
  });

  var months_fr = ['jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];
  var paceEndLabel = lastIdx >= 0 ? months_fr[lastIdx] : '—';

  // Legend line explaining colour meaning
  var legendHtml = '<div class="wk-yoy-colour-legend">'
    + 'Couleur : rythme vs ' + escHtml(String(prevYear)) + ' même période'
    + ' — <span class="wk-yoy-cl-ok">▲ vert = en avance</span>'
    + ', <span class="wk-yoy-cl-ember">▼ rouge = en retard</span>'
    + ' · Barre = % du total annuel ' + escHtml(String(prevYear))
    + '</div>';

  var html = legendHtml + '<table class="wk-yoy-pct-table">';
  html += '<thead><tr>'
    + '<th class="wk-yoy-col-beer">Bière</th>'
    + '<th class="wk-yoy-col-curr">Cette année (YTD)</th>'
    + '<th class="wk-yoy-col-prev">' + escHtml(String(prevYear)) + ' (total)</th>'
    + '<th class="wk-yoy-col-pct">% atteint · rythme jan–' + escHtml(paceEndLabel) + '</th>'
    + '</tr></thead>';
  html += '<tbody>';

  BUCKET_ORDER.forEach(function(bk) {
    var beers = byBucket[bk];
    if (!beers || !beers.length) return;

    html += '<tr class="wk-yoy-bucket-sep"><td colspan="4">' + escHtml(BUCKET_LABELS[bk]) + '</td></tr>';

    beers.forEach(function(b) {
      var pct     = b.pct;
      var status  = b.status;

      // Unified tint — covers the paceRefPrev==0 bug
      var tint = paceTintResult(b);
      var tintClass = (tint === 'ok') ? 'wk-yoy-bar--ok' : (tint === 'ember') ? 'wk-yoy-bar--ember' : '';

      var pctCell = '';
      if (status === 'nouveau') {
        pctCell = '<div class="wk-yoy-pct-inner">'
          + '<div class="wk-yoy-status-chip wk-yoy-status-chip--nouveau">nouveau</div>'
          + '<span class="wk-yoy-curr-hl">' + escHtml(fmt(b.currTotal, 1)) + ' HL</span>'
          + '</div>';
      } else if (status === 'arrete') {
        pctCell = '<div class="wk-yoy-pct-inner">'
          + '<div class="wk-yoy-pct-readout wk-yoy-pct-readout--arrete">'
          + '<span class="wk-yoy-status-chip wk-yoy-status-chip--arrete">arrêté</span>'
          + '</div>'
          + '</div>';
      } else {
        // headline % vs full prevYear — may exceed 100
        var fillPct = Math.min(pct !== null ? pct : 0, 100);
        var overBar = (pct !== null && pct > 100);
        var pctLabel = pct !== null ? escHtml(pct.toFixed(1)) + '%' : '—';
        pctCell = '<div class="wk-yoy-pct-inner">'
          + '<div class="wk-yoy-bar-wrap' + (overBar ? ' wk-yoy-bar-wrap--over' : '') + '">'
          + '<div class="wk-yoy-bar ' + tintClass + '" style="width:' + fillPct.toFixed(1) + '%">'
          + (overBar ? '<span class="wk-yoy-bar__ahead">&#x25BA;</span>' : '')
          + '</div>'
          + '</div>'
          + '<span class="wk-yoy-pct-label ' + tintClass + '">' + pctLabel + '</span>'
          + '</div>';
      }

      var currDisplay = b.status === 'arrete' ? '<span class="wk-yoy-muted">—</span>' : escHtml(fmt(b.currTotal, 1)) + ' HL';
      var prevDisplay = b.prevTotal > 0 ? escHtml(fmt(b.prevTotal, 1)) + ' HL' : '<span class="wk-yoy-muted">—</span>';

      html += '<tr class="wk-yoy-row">'
        + '<td class="wk-yoy-col-beer">' + escHtml(b.label || b.name) + '</td>'
        + '<td class="wk-yoy-col-curr">' + currDisplay + '</td>'
        + '<td class="wk-yoy-col-prev">' + prevDisplay + '</td>'
        + '<td class="wk-yoy-col-pct">' + pctCell + '</td>'
        + '</tr>';
    });
  });

  html += '</tbody></table>';
  el.innerHTML = html;
}

/* ═══════════════════════════════════════════════════════════
   I. YOY SPARKLINES — "Comparatif mensuel par bière"
   Small-multiples grid: one 12-month dual-series SVG per beer.
   curr year = solid line (--hop); prev year = dashed muted (--ink-mute).
   A "Tous" aggregate mini-chart is rendered first.
   Sorted by currTotal desc; all beers with HL in either year shown.

   Change (2026-05-31): curr series truncated at lastDataMonthIdx
   (no flat future months). Prev stays full 12 months (complete year).
   ═══════════════════════════════════════════════════════════ */
function renderYoySparklines(year) {
  var el = document.getElementById('wk-chart-yoy-spark');
  if (!el) return;

  var yoy = KD.yoy;
  if (!yoy || !yoy.beers || !yoy.beers.length) {
    el.innerHTML = '<div class="wk-hm-unavail">Données YoY indisponibles.</div>';
    return;
  }

  var prevYear     = yoy.prevYear;
  var kpiYear      = yoy.kpiYear;
  var lastDataMi   = yoy.lastDataMonthIdx; // 0-based; -1 if no curr data

  // Update title + legend labels
  var titleCurr = document.getElementById('wk-yoy-curr-year-label');
  var titlePrev2 = document.getElementById('wk-yoy-prev-year-label2');
  var legendCurr = document.getElementById('wk-yoy-legend-curr');
  var legendPrev = document.getElementById('wk-yoy-legend-prev');
  if (titleCurr) titleCurr.textContent = String(kpiYear);
  if (titlePrev2) titlePrev2.textContent = String(prevYear);
  if (legendCurr) legendCurr.textContent = String(kpiYear);
  if (legendPrev) legendPrev.textContent = String(prevYear);

  // Build aggregate "Tous" arrays (full 12, truncation happens in buildSparkSvg)
  var allCurr = new Array(12).fill(0);
  var allPrev = new Array(12).fill(0);
  yoy.beers.forEach(function(b) {
    for (var i = 0; i < 12; i++) {
      allCurr[i] += (b.curr[i] || 0);
      allPrev[i] += (b.prev[i] || 0);
    }
  });

  /* Build the sparkline SVG.
     curr: full 12-entry array but we draw only [0..lastDataMi] (truncated).
     prev: full 12 months (complete reference year).
  */
  function buildSparkSvg(curr, prev, label, bucket, isTous) {
    var W = 200, H = 90;
    var padL = 4, padR = 4, padT = 10, padB = 20;
    var cW = W - padL - padR;
    var cH = H - padT - padB;

    // Curr series: only up to lastDataMi (no future flat months)
    var currPoints = lastDataMi >= 0 ? curr.slice(0, lastDataMi + 1) : [];

    // Scale: max across full prev AND the truncated curr
    var allVals = currPoints.concat(prev);
    var maxVal = Math.max.apply(null, allVals.concat([0.1]));

    // Prev uses all 12 x positions; curr uses positions 0..lastDataMi
    function xOfAll(i, total) {
      // Position index i in a series of `total` points across the chart width.
      // When total===12 both series share the same x scale.
      if (total <= 1) return padL;
      return padL + (i / (total - 1)) * cW;
    }
    // Convenience: both series use 12-point x scale (prev is always 12).
    function xOf(i) { return padL + (i / 11) * cW; }
    function yOf(v)  { return padT + cH * (1 - v / maxVal); }

    var svg = svgEl('svg', {
      viewBox: '0 0 ' + W + ' ' + H,
      class: 'wk-yoy-spark-svg',
      'aria-label': escHtml(label),
    });

    // Faint grid line at 50%
    svg.appendChild(svgEl('line', {
      x1: padL, y1: padT + cH * 0.5,
      x2: W - padR, y2: padT + cH * 0.5,
      stroke: tok('--hairline') || '#c8b48a',
      'stroke-width': 0.5, opacity: 0.5,
    }));

    // Previous year — dashed grey line (full 12 months)
    var prevHasData = prev.some(function(v) { return v > 0; });
    if (prevHasData) {
      var prevPath = '';
      for (var i = 0; i < 12; i++) {
        prevPath += (i === 0 ? 'M' : 'L') + xOf(i).toFixed(1) + ',' + yOf(prev[i]).toFixed(1);
      }
      svg.appendChild(svgEl('path', {
        d: prevPath, fill: 'none',
        stroke: tok('--ink-mute') || '#4a3820',
        'stroke-width': 1,
        'stroke-dasharray': '3 3',
        opacity: 0.45,
        'stroke-linejoin': 'round', 'stroke-linecap': 'round',
      }));
    }

    // Current year — solid coloured line, STOPS at lastDataMi
    var BUCKET_COLORS = {
      core:     tok('--hop')  || '#567020',
      special:  tok('--cat-process') || '#5a4a8c',
      contract: tok('--cold') || '#2f5575',
    };
    var lineColor = isTous ? (tok('--ink-soft') || '#3d2f1f') : (BUCKET_COLORS[bucket] || BUCKET_COLORS.core);

    var currHasData = currPoints.some(function(v) { return v > 0; });
    if (currHasData) {
      // Area fill (subtle) — only over the valid months
      var areaD = 'M' + xOf(0) + ',' + (padT + cH);
      for (var j = 0; j < currPoints.length; j++) {
        areaD += 'L' + xOf(j).toFixed(1) + ',' + yOf(currPoints[j]).toFixed(1);
      }
      areaD += 'L' + xOf(currPoints.length - 1).toFixed(1) + ',' + (padT + cH) + 'Z';
      svg.appendChild(svgEl('path', { d: areaD, fill: lineColor, opacity: 0.07 }));

      var currPath = '';
      for (var k = 0; k < currPoints.length; k++) {
        currPath += (k === 0 ? 'M' : 'L') + xOf(k).toFixed(1) + ',' + yOf(currPoints[k]).toFixed(1);
      }
      svg.appendChild(svgEl('path', {
        d: currPath, fill: 'none',
        stroke: lineColor, 'stroke-width': isTous ? 1.8 : 1.5,
        'stroke-linejoin': 'round', 'stroke-linecap': 'round',
      }));
    }

    // Month axis labels (jan, avr, jul, oct)
    var axisMonths = [0, 3, 6, 9];
    axisMonths.forEach(function(i) {
      var t = svgEl('text', {
        x: xOf(i), y: H - 4,
        'text-anchor': 'middle',
        fill: tok('--ink-faint') || '#7a6647',
        'font-family': 'JetBrains Mono,monospace',
        'font-size': 7,
        'letter-spacing': '0.04em',
      });
      t.textContent = MONTHS_FR[i];
      svg.appendChild(t);
    });

    return svg;
  }

  /* Build the KPI readout block for a beer card.
     b: beer object (or null for the "Tous" aggregate).
     For "Tous": pass a synthetic pseudo-beer object.
  */
  function buildReadout(b, prevYear) {
    var status     = b.status;
    var currTotal  = b.currTotal;
    var prevTotal  = b.prevTotal;
    var pct        = b.pct;
    var pacePct    = b.pacePct;
    var paceRefPrev= b.paceRefPrev;

    var tint = paceTintResult(b);

    var div = document.createElement('div');
    div.className = 'wk-yoy-spark-readout';

    // Line 1: currTotal HL (prominent)
    var hl = document.createElement('div');
    hl.className = 'wk-spark-hl';
    if (status === 'arrete') {
      hl.textContent = '—';
      hl.className += ' wk-spark-hl--muted';
    } else {
      hl.textContent = escHtml(fmt(currTotal, 1)) + ' HL';
    }
    div.appendChild(hl);

    // Line 2: pct / status label
    var pctLine = document.createElement('div');
    pctLine.className = 'wk-spark-pct';
    if (status === 'nouveau') {
      pctLine.innerHTML = '<span class="wk-yoy-status-chip wk-yoy-status-chip--nouveau">nouveau</span>';
    } else if (status === 'arrete') {
      pctLine.innerHTML = 'arrêté · '
        + escHtml(String(prevYear)) + '&nbsp;: '
        + escHtml(fmt(prevTotal, 1)) + '&nbsp;HL';
    } else {
      var pctText = pct !== null ? escHtml(pct.toFixed(1)) + '&nbsp;%' : '—';
      pctLine.innerHTML = pctText + ' du total ' + escHtml(String(prevYear));
    }
    div.appendChild(pctLine);

    // Line 3: pace line (only for actif or paceRefPrev==0 ahead case)
    if (status !== 'nouveau' && status !== 'arrete') {
      var paceLine = document.createElement('div');
      paceLine.className = 'wk-spark-pace';
      if (tint === 'ok') {
        paceLine.className += ' wk-spark-pace--ok';
        if ((paceRefPrev === 0 || paceRefPrev == null) && currTotal > 0) {
          // Producing when reference period was zero
          paceLine.innerHTML = '▲ <span class="wk-spark-pace-label">nouv. sur période</span>';
        } else {
          paceLine.innerHTML = '▲ ' + escHtml(pacePct !== null ? pacePct.toFixed(1) : '—') + '&nbsp;% du rythme';
        }
      } else {
        paceLine.className += ' wk-spark-pace--ember';
        paceLine.innerHTML = '▼ ' + escHtml(pacePct !== null ? pacePct.toFixed(1) : '—') + '&nbsp;% du rythme';
      }
      div.appendChild(paceLine);
    }

    // Line 4: prev year reference (muted), skip for nouveau (no prev) or arrete (already shown)
    if (status === 'actif' && prevTotal > 0) {
      var ref = document.createElement('div');
      ref.className = 'wk-spark-prev-ref';
      ref.innerHTML = escHtml(String(prevYear)) + '&nbsp;: ' + escHtml(fmt(prevTotal, 1)) + '&nbsp;HL';
      div.appendChild(ref);
    }

    return div;
  }

  el.innerHTML = '';

  // Legend line for Section 2
  var legendDiv = document.createElement('div');
  legendDiv.className = 'wk-yoy-colour-legend wk-yoy-colour-legend--spark';
  legendDiv.innerHTML = 'Couleur&nbsp;: rythme vs ' + escHtml(String(prevYear)) + ' même période'
    + ' — <span class="wk-yoy-cl-ok">▲ vert&nbsp;= en avance</span>'
    + ', <span class="wk-yoy-cl-ember">▼ rouge&nbsp;= en retard</span>';
  el.parentElement && el.parentElement.insertBefore(legendDiv, el);
  // (We prepend into the card grid container instead; see below)
  // Actually we add to el directly as a sibling would be complex; insert into section head instead.
  // The section head already contains title + legend so we put a note div just before the grid.
  var sparkSection = document.getElementById('wk-section-yoy-spark');
  if (sparkSection) {
    // Remove any existing colour legend we already inserted (for re-renders)
    var existingLegend = sparkSection.querySelector('.wk-yoy-colour-legend--spark');
    if (existingLegend) existingLegend.remove();
    // Insert before the wk-chart-card
    var chartCard = sparkSection.querySelector('.wk-chart-card');
    if (chartCard) {
      sparkSection.insertBefore(legendDiv, chartCard);
    }
  } else {
    // Fallback: prepend to grid
    el.insertAdjacentElement('beforebegin', legendDiv);
  }

  // ── "Tous" aggregate chart first ──
  var allCurrTotal = allCurr.reduce(function(s,v){return s+v;},0);
  var allPrevTotal = allPrev.reduce(function(s,v){return s+v;},0);

  // Pseudo-beer for "Tous" readout (actif, pct computed vs prevYear total)
  var tousPseudo = {
    status:      'actif',
    currTotal:   allCurrTotal,
    prevTotal:   allPrevTotal,
    pct:         allPrevTotal > 0 ? Math.round(allCurrTotal / allPrevTotal * 1000) / 10 : null,
    paceRefPrev: allPrev.slice(0, lastDataMi + 1).reduce(function(s,v){return s+v;}, 0),
    pacePct:     null,
  };
  if (tousPseudo.paceRefPrev > 0) {
    tousPseudo.pacePct = Math.round(allCurrTotal / tousPseudo.paceRefPrev * 1000) / 10;
  }

  var tousCard = document.createElement('div');
  tousCard.className = 'wk-yoy-spark-card wk-yoy-spark-card--tous';

  var tousLabel = document.createElement('div');
  tousLabel.className = 'wk-yoy-spark-label';
  tousLabel.textContent = 'Tous';

  tousCard.appendChild(tousLabel);
  tousCard.appendChild(buildSparkSvg(allCurr, allPrev, 'Tous', 'core', true));
  tousCard.appendChild(buildReadout(tousPseudo, prevYear));
  el.appendChild(tousCard);

  // ── Per-beer mini charts ──
  yoy.beers.forEach(function(b) {
    // Render all beers that have any HL in either year (the union — no truncation)
    if (b.currTotal === 0 && b.prevTotal === 0) return;

    var tint = paceTintResult(b);

    var card = document.createElement('div');
    card.className = 'wk-yoy-spark-card';
    if (b.status === 'nouveau')  card.classList.add('wk-yoy-spark-card--nouveau');
    if (b.status === 'arrete')   card.classList.add('wk-yoy-spark-card--arrete');
    // Tint border for actif beers: subtle ok/ember top border
    if (b.status === 'actif' && tint === 'ok')    card.classList.add('wk-yoy-spark-card--ok');
    if (b.status === 'actif' && tint === 'ember')  card.classList.add('wk-yoy-spark-card--ember');

    var lbl = document.createElement('div');
    lbl.className = 'wk-yoy-spark-label';
    lbl.textContent = b.label || b.name;

    if (b.status !== 'actif') {
      var chip = document.createElement('span');
      chip.className = 'wk-yoy-status-chip wk-yoy-status-chip--' + escHtml(b.status);
      chip.textContent = b.status === 'nouveau' ? 'nouveau' : 'arrêté';
      card.appendChild(lbl);
      card.appendChild(chip);
    } else {
      card.appendChild(lbl);
    }

    card.appendChild(buildSparkSvg(b.curr, b.prev, b.label || b.name, b.bucket, false));
    card.appendChild(buildReadout(b, prevYear));
    el.appendChild(card);
  });
}

/* ═══════════════════════════════════════════════════════════
   FULL RENDER
   ═══════════════════════════════════════════════════════════ */
function render(year) {
  const d = resolveData(year);
  const isAll = (year === 'all');

  /* YTD note */
  const ytdNote = document.getElementById('wk-ytd-note');
  if (ytdNote) ytdNote.style.display = (d && d.is_partial) ? '' : 'none';

  /* Footnote */
  const fn = document.getElementById('wk-footnote');
  if (fn) fn.style.display = (!isAll && year <= 2025) || isAll ? '' : 'none';

  /* "All years" view — show annual trend, hide monthly sections */
  const secMonthly  = document.getElementById('wk-section-monthly');
  const secRow2     = document.getElementById('wk-section-row2');
  const secQuarterly = document.getElementById('wk-section-quarterly');
  const secCumul    = document.getElementById('wk-section-cumul');
  const secHeatmap  = document.getElementById('wk-section-heatmap');
  const secAnnual   = document.getElementById('wk-section-annual');

  if (isAll) {
    if (secMonthly)   secMonthly.style.display   = 'none';
    if (secRow2)      secRow2.style.display       = 'none';
    if (secQuarterly) secQuarterly.style.display  = 'none';
    if (secCumul)     secCumul.style.display      = 'none';
    if (secAnnual)    secAnnual.style.display      = '';
    if (secHeatmap)   secHeatmap.style.display    = '';
    renderStats('all', KD.annual_view);
    renderAnnualTrend();
    renderHeatmap('all');
    renderYoyPct('all');
    renderYoySparklines('all');
    return;
  }

  if (secMonthly)   secMonthly.style.display   = '';
  if (secRow2)      secRow2.style.display       = '';
  if (secQuarterly) secQuarterly.style.display  = '';
  if (secCumul)     secCumul.style.display      = '';
  if (secAnnual)    secAnnual.style.display      = 'none';
  if (secHeatmap)   secHeatmap.style.display    = '';

  if (!d) return;

  /* Illustrative note on section titles */
  if (d.is_illustrative && !isAll) {
    document.querySelectorAll('#wk-section-row2 .wk-section__title, #wk-section-monthly .wk-section__title').forEach(function(el) {
      const base = el.textContent.split('(')[0].trim();
      el.innerHTML = escHtml(base) + ' <span style="font-size:.7rem;color:var(--oak);font-family:\'DM Sans\',sans-serif">(répartition indicative)</span>';
    });
  } else {
    document.querySelectorAll('#wk-section-row2 .wk-section__title, #wk-section-monthly .wk-section__title').forEach(function(el) {
      el.textContent = el.textContent.split('(')[0].trim();
    });
  }

  renderStats(year, d);
  renderNebContract(d);
  renderNebComp(d);
  renderContract(d);
  renderQuarterly(d);
  renderCumul(d);
  renderHeatmap(year);
  renderYoyPct(year);
  renderYoySparklines(year);
}

/* ── Year buttons ── */
document.querySelectorAll('.wk-year-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.wk-year-btn').forEach(function(b) {
      b.classList.remove('active');
      b.setAttribute('aria-pressed', 'false');
    });
    btn.classList.add('active');
    btn.setAttribute('aria-pressed', 'true');
    const raw = btn.dataset.year;
    activeYear = raw === 'all' ? 'all' : parseInt(raw, 10);
    render(activeYear);
  });
});

/* ── Heatmap granularity toggle ── */
document.querySelectorAll('.wk-hm-toggle-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.wk-hm-toggle-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    hmGranularity = btn.dataset.gran;
    renderHeatmap(activeYear);
  });
});

/* ── Tab switcher (lazy-init guard for zero-width SVG problem)
   SVG charts rendered inside display:none panels get zero client width,
   so bar widths compute wrong. We defer the initial render until the
   KPIs tab is first activated, then re-render on window resize. ── */
var kpisRendered = false;

function initTabSwitcher() {
  var tabBtns   = document.querySelectorAll('.wort-tab-btn');
  var tabPanels = document.querySelectorAll('.wort-tab-panel');

  tabBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      var target = btn.dataset.tab;

      tabBtns.forEach(function(b) {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
      });
      tabPanels.forEach(function(p) { p.classList.remove('active'); });

      btn.classList.add('active');
      btn.setAttribute('aria-selected', 'true');

      var panel = document.getElementById('wort-panel-' + target);
      if (panel) panel.classList.add('active');

      /* Lazy-init: render KPIs only on first activation of the tab */
      if (target === 'kpis' && !kpisRendered) {
        kpisRendered = true;
        render(activeYear);
      }
    });
  });
}

/* Re-render on resize (SVG viewBox is fixed, but container-width-dependent
   elements like the heatmap table can benefit from a re-flow) */
var resizeTimer = null;
window.addEventListener('resize', function() {
  if (!kpisRendered) return;
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(function() { render(activeYear); }, 200);
});

initTabSwitcher();

/* Initial render ONLY if KPIs tab is already active on load.
   Default is Brassins, so in the normal case we skip the render here
   — it fires lazily on first tab click above. */
var kpiPanel = document.getElementById('wort-panel-kpis');
if (kpiPanel && kpiPanel.classList.contains('active')) {
  kpisRendered = true;
  render(activeYear);
}
