/* ═══════════════════════════════════════════════════════════
   wort-kpis.js — Production de moût KPIs
   Data source: window.WORT_KPIS (server-injected)
   No CDN chart library — hand-rolled SVG/CSS charts.
   ═══════════════════════════════════════════════════════════ */

'use strict';

/* ── XSS guard ── */
function escHtml(s) {
  if (s == null) return '';
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/* ── Number formatter (FR-CH) ── */
function fmt(n, dec) {
  if (n === 0 || n == null) return '0';
  dec = (dec == null) ? 1 : dec;
  return Number(n).toLocaleString('fr-CH', { minimumFractionDigits: dec, maximumFractionDigits: dec });
}

/* ── SVG helper ── */
function svgEl(tag, attrs) {
  const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
  attrs = attrs || {};
  for (const k in attrs) el.setAttribute(k, attrs[k]);
  return el;
}

/* ── French month short labels ── */
const MONTHS_FR = ['jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];

/* ── Palette — read from CSS tokens at runtime ── */
const CS = getComputedStyle(document.documentElement);
function tok(name) { return CS.getPropertyValue(name).trim(); }
const C = {
  core:     tok('--hop')       || '#567020',
  spec:     tok('--cat-process') || '#5a4a8c',
  contract: tok('--cold')      || '#2f5575',
  ok:       tok('--ok')        || '#3d6826',
  ember:    tok('--ember')     || '#b34428',
  hairline: tok('--hairline')  || '#c8b48a',
  bg:       tok('--bg')        || '#f1e8d4',
  ink_faint:tok('--ink-faint') || '#7a6647',
  ink_mute: tok('--ink-mute')  || '#4a3820',
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

/* ═══════════════════════════════════════════════════════════
   SVG BAR CHART — grouped or single series
   ═══════════════════════════════════════════════════════════ */
function buildBarChart(container, points, series, opts) {
  /*
    points: array of arrays indexed to match series
    series: array of { color, labelFn }
    opts: { height, showZeroLabels, partial, illustrative }
  */
  opts = opts || {};
  const height = opts.height || 200;
  const partial = !!opts.partial;
  const illustrative = !!opts.illustrative;

  const W = 840, H = height + 52;
  const padL = 44, padR = 12, padT = 16, padB = 36;
  const chartW = W - padL - padR;
  const chartH = H - padT - padB;

  const maxVal = Math.max.apply(null, points.map(function(m) {
    return series.reduce(function(s, _, i) { return s + (m[i] || 0); }, 0);
  }).concat([1]));
  const gridTop = Math.ceil(maxVal / 100) * 100;
  const yScale = function(v) { return chartH * (1 - v / gridTop); };

  const n = series.length;
  const monthW = chartW / points.length;
  const gap = monthW * 0.18;
  const barW = (monthW - gap * 2) / n;

  const svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img' });

  /* Grid lines */
  const gridSteps = 5;
  for (let i = 0; i <= gridSteps; i++) {
    const v = gridTop * i / gridSteps;
    const y = padT + yScale(v);
    svg.appendChild(svgEl('line', {
      x1: padL, y1: y, x2: W - padR, y2: y,
      stroke: C.hairline, 'stroke-width': i === 0 ? 1.5 : 0.5,
      opacity: i === 0 ? 0.9 : 0.5,
    }));
    if (i > 0) {
      const lbl = svgEl('text', {
        x: padL - 5, y: y + 4, 'text-anchor': 'end', fill: C.ink_faint,
        'font-family': 'JetBrains Mono,monospace', 'font-size': 9, 'letter-spacing': '0.04em',
      });
      lbl.textContent = v >= 1000 ? (v / 1000).toFixed(1) + 'k' : v.toFixed(0);
      svg.appendChild(lbl);
    }
  }

  /* Columns */
  points.forEach(function(m, mi) {
    const xBase = padL + mi * monthW + gap;
    const isFuture = partial && illustrative && mi >= points.length - (12 - KD.last_data_month);

    /* Month/quarter label */
    const axisLabel = (points.length === 12) ? MONTHS_FR[mi] : ('Q' + (mi + 1));
    const lbl = svgEl('text', {
      x: padL + mi * monthW + monthW / 2,
      y: H - padB + 14,
      'text-anchor': 'middle',
      fill: isFuture ? C.hairline : C.ink_faint,
      'font-family': 'JetBrains Mono,monospace',
      'font-size': 9,
      'letter-spacing': '0.06em',
    });
    lbl.textContent = axisLabel;
    svg.appendChild(lbl);

    series.forEach(function(s, si) {
      const val = m[si] || 0;
      if (val === 0) {
        if (series.length === 1) {
          svg.appendChild(svgEl('rect', {
            x: xBase + si * barW, y: padT + chartH - 1,
            width: Math.max(barW - 1, 4), height: 1,
            fill: isFuture ? C.hairline : s.color, opacity: 0.3, rx: 1,
          }));
        }
        return;
      }
      const bh = yScale(0) - yScale(val);
      const bx = xBase + si * barW;
      const by = padT + yScale(val);
      const rect = svgEl('rect', {
        x: bx, y: by,
        width: Math.max(barW - 1, 4), height: Math.max(bh, 1),
        fill: s.color, opacity: isFuture ? 0.3 : 1, rx: 2,
      });
      const totalRow = series.reduce(function(acc, _, ii) { return acc + (m[ii] || 0); }, 0);
      const axisLabelForTip = (points.length === 12) ? MONTHS_FR[mi].toUpperCase() : ('Q' + (mi + 1));
      rect.addEventListener('mouseenter', function(e) {
        showTip(e, '<strong>' + axisLabelForTip + '</strong> · ' + escHtml(s.labelFn()) + ' : <strong>' + fmt(val) + ' HL</strong><br><span style="color:' + C.ink_faint + '">Total : ' + fmt(totalRow) + ' HL</span>');
      });
      rect.addEventListener('mousemove', moveTip);
      rect.addEventListener('mouseleave', hideTip);
      svg.appendChild(rect);

      if (bh > 18) {
        const vlbl = svgEl('text', {
          x: bx + (barW - 1) / 2, y: by - 3,
          'text-anchor': 'middle', fill: s.color,
          'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'font-weight': 500,
        });
        vlbl.textContent = val >= 100 ? Math.round(val) : val.toFixed(1);
        svg.appendChild(vlbl);
      }
    });
  });

  /* Y-axis label */
  const yAxisLbl = svgEl('text', {
    x: 10, y: padT + chartH / 2, 'text-anchor': 'middle', fill: C.ink_faint,
    'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'letter-spacing': '0.1em',
    transform: 'rotate(-90, 10, ' + (padT + chartH / 2) + ')',
  });
  yAxisLbl.textContent = 'HL';
  svg.appendChild(yAxisLbl);

  container.innerHTML = '';
  container.appendChild(svg);
}

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
  ], { height: 200, partial: d.is_partial, illustrative: d.is_illustrative });
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
  ], { height: 180, partial: d.is_partial, illustrative: d.is_illustrative });
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
  ], { height: 180, partial: d.is_partial, illustrative: d.is_illustrative });
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
   F. CUMULATIVE YTD
   ═══════════════════════════════════════════════════════════ */
function renderCumul(d) {
  const el = document.getElementById('wk-chart-cumul');
  if (!el) return;
  el.innerHTML = '';

  const W = 840, H = 220;
  const padL = 48, padR = 16, padT = 16, padB = 36;
  const chartW = W - padL - padR, chartH = H - padT - padB;

  const cumPoints = [];
  let running = 0;
  let lastDataMi = -1;
  d.monthly.forEach(function(m, i) {
    if ((m[0] || 0) + (m[1] || 0) > 0) lastDataMi = i;
  });
  for (let i = 0; i <= (lastDataMi >= 0 ? lastDataMi : 11); i++) {
    running += (d.monthly[i][0] || 0) + (d.monthly[i][1] || 0);
    cumPoints.push({ mi: i, val: running });
  }

  if (cumPoints.length === 0 || lastDataMi < 0) { el.textContent = '—'; return; }

  const maxCum = running;
  const totalMonths = lastDataMi;
  const xScale = function(mi) {
    if (totalMonths === 0) return padL + chartW / 2;
    return padL + (mi / Math.max(totalMonths, 1)) * chartW;
  };
  const yScale2 = function(v) { return padT + chartH * (1 - v / maxCum); };

  const svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H });

  for (let i = 0; i <= 4; i++) {
    const v = maxCum * i / 4;
    const y = yScale2(v);
    svg.appendChild(svgEl('line', { x1: padL, y1: y, x2: W - padR, y2: y,
      stroke: C.hairline, 'stroke-width': i === 0 ? 1.5 : 0.5, opacity: i === 0 ? 0.9 : 0.45 }));
    if (i > 0) {
      const lbl = svgEl('text', { x: padL - 5, y: y + 4, 'text-anchor': 'end', fill: C.ink_faint,
        'font-family': 'JetBrains Mono,monospace', 'font-size': 9 });
      lbl.textContent = v >= 1000 ? (v / 1000).toFixed(1) + 'k' : Math.round(v);
      svg.appendChild(lbl);
    }
  }

  const firstX = xScale(cumPoints[0].mi);
  const lastX  = xScale(cumPoints[cumPoints.length - 1].mi);
  svg.appendChild(svgEl('path', {
    d: 'M' + firstX + ',' + yScale2(0) + ' ' +
       cumPoints.map(function(p) { return 'L' + xScale(p.mi) + ',' + yScale2(p.val); }).join(' ') +
       ' L' + lastX + ',' + yScale2(0) + ' Z',
    fill: C.core, opacity: 0.12,
  }));

  const linePath = cumPoints.map(function(p, i) {
    return (i === 0 ? 'M' : 'L') + xScale(p.mi) + ',' + yScale2(p.val);
  }).join(' ');
  svg.appendChild(svgEl('path', { d: linePath, fill: 'none', stroke: C.core,
    'stroke-width': 2.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));

  cumPoints.forEach(function(p) {
    const cx = xScale(p.mi), cy = yScale2(p.val);
    const dot = svgEl('circle', { cx: cx, cy: cy, r: 4, fill: C.core });
    dot.addEventListener('mouseenter', function(e) {
      showTip(e, '<strong>' + MONTHS_FR[p.mi].toUpperCase() + '</strong> · Cumul : <strong>' + fmt(p.val, 1) + ' HL</strong>');
    });
    dot.addEventListener('mousemove', moveTip);
    dot.addEventListener('mouseleave', hideTip);
    svg.appendChild(dot);
  });

  cumPoints.forEach(function(p) {
    const t = svgEl('text', { x: xScale(p.mi), y: H - padB + 14, 'text-anchor': 'middle', fill: C.ink_faint,
      'font-family': 'JetBrains Mono,monospace', 'font-size': 9, 'letter-spacing': '0.06em' });
    t.textContent = MONTHS_FR[p.mi];
    svg.appendChild(t);
  });

  const last = cumPoints[cumPoints.length - 1];
  const finalLbl = svgEl('text', {
    x: xScale(last.mi), y: yScale2(last.val) - 8,
    'text-anchor': last.mi >= totalMonths * 0.85 ? 'end' : 'middle',
    fill: C.core, 'font-family': 'JetBrains Mono,monospace', 'font-size': 10, 'font-weight': 500,
  });
  finalLbl.textContent = fmt(last.val, 1) + ' HL';
  svg.appendChild(finalLbl);

  const yAxisLbl = svgEl('text', { x: 10, y: padT + chartH / 2, 'text-anchor': 'middle', fill: C.ink_faint,
    'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'letter-spacing': '0.1em',
    transform: 'rotate(-90, 10, ' + (padT + chartH / 2) + ')' });
  yAxisLbl.textContent = 'HL Nébuleuse';
  svg.appendChild(yAxisLbl);

  el.appendChild(svg);
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
}

/* ── Year buttons ── */
document.querySelectorAll('.wk-year-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.wk-year-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
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
