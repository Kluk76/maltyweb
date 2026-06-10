/* ═══════════════════════════════════════════════════════════
   kpi-charts.js — Shared KPI viz layer
   Renders whatever shape kpi_dispatch() returns, keyed on viz_type.
   Pure presentation — never fetches, never recomputes.
   Consumed by: mon-tableau.php (MY_KPIS), wort-kpis.js (re-exports).
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

/* ── Freshness chip
   Renders a compact provenance stamp when result.meta.computed_at is present.
   Text: "au MM/YYYY · calculé le DD.MM.YYYY"
   Applies kpc-freshness--stale modifier when result.meta.is_stale is true.
   Returns empty string when the meta field is absent (generic / non-COGS tiles). */
function freshnessChipHtml(meta) {
  if (!meta || !meta.computed_at) return '';
  var period  = meta.data_period    ? 'au ' + escHtml(meta.data_period) + ' · ' : '';
  var calcd   = meta.computed_label ? 'calculé le ' + escHtml(meta.computed_label) : '';
  if (!period && !calcd) return '';
  var staleCls = meta.is_stale ? ' kpc-freshness--stale' : '';
  return '<div class="kpc-freshness' + staleCls + '">' + period + calcd + '</div>';
}

/* ── Number formatter (FR-CH)
   dec: explicit decimal places (0/1/2/…) OR omit to auto-derive from unit.
   unit: optional — used only when dec is omitted.
     '%' or 'pct'  → 1 decimal
     'CHF'         → 2 decimals
     '' / undefined → 1 decimal (default)
   Existing callers in wort-kpis.js pass (n, dec) — behaviour unchanged.
   ── */
function fmt(n, dec, unit) {
  if (n === 0 || n == null) return '0';
  if (dec == null) {
    var u = (unit || '').toLowerCase();
    dec = (u === '%' || u === 'pct') ? 1 : (u === 'chf') ? 2 : 1;
  }
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
const KPC_MONTHS_FR = ['jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];

/* ── Palette — read from CSS tokens at runtime ── */
function kpcTok(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}
function kpcColors() {
  return {
    core:      kpcTok('--hop')          || '#567020',
    spec:      kpcTok('--cat-process')  || '#5a4a8c',
    contract:  kpcTok('--cold')         || '#2f5575',
    ok:        kpcTok('--ok')           || '#3d6826',
    ember:     kpcTok('--ember')        || '#b34428',
    amber:     kpcTok('--oak')          || '#8b5a1a',
    hairline:  kpcTok('--hairline')     || '#c8b48a',
    bg:        kpcTok('--bg')           || '#f1e8d4',
    ink_faint: kpcTok('--ink-faint')    || '#7a6647',
    ink_mute:  kpcTok('--ink-mute')     || '#4a3820',
    ink_soft:  kpcTok('--ink-soft')     || '#3d2f1f',
  };
}

/* ── Tint → CSS class mapping ── */
function tintClass(tint) {
  return {
    green:   'kpc-tint-green',
    red:     'kpc-tint-red',
    amber:   'kpc-tint-amber',
    neutral: '',
  }[tint] || '';
}

/* ── Delta arrow + sign ── */
function deltaHtml(delta, deltaLabel) {
  if (delta == null) return '';
  const sign = delta >= 0 ? '▲' : '▼';
  const cls  = delta >= 0 ? 'kpc-delta--up' : 'kpc-delta--down';
  const val  = Math.abs(delta);
  /* Use fmt() so large deltas (e.g. 271283.3) get fr-CH thousands separators */
  const dec  = Number.isInteger(val) ? 0 : 1;
  const disp = fmt(val, dec);
  return '<span class="kpc-delta ' + cls + '">' + sign + ' ' + escHtml(disp)
    + (deltaLabel ? ' <span class="kpc-delta-lbl">' + escHtml(deltaLabel) + '</span>' : '')
    + '</span>';
}

/* ═══════════════════════════════════════════════════════════
   BAR CHART — grouped or single series
   points: array of arrays indexed to match series
   series: array of { color, labelFn }
   opts:   { height, partial, illustrative, yUnit }
   ═══════════════════════════════════════════════════════════ */
function buildBarChart(container, points, series, opts) {
  opts = opts || {};
  const C = kpcColors();
  const height = opts.height || 200;
  const partial = !!opts.partial;
  const illustrative = !!opts.illustrative;
  const yUnit = opts.yUnit || '';

  const W = 840, H = height + 52;
  const padL = 44, padR = 12, padT = 16, padB = 36;
  const chartW = W - padL - padR;
  const chartH = H - padT - padB;

  const maxVal = Math.max.apply(null, points.map(function(m) {
    return series.reduce(function(s, _, i) { return s + (m[i] || 0); }, 0);
  }).concat([1]));
  const gridTop = Math.ceil(maxVal / 100) * 100 || 100;
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

  /* Tooltip helper — local div for bar charts */
  const localTip = document.createElement('div');
  localTip.className = 'kpc-tip';
  localTip.style.cssText = 'position:fixed;display:none;z-index:2000;background:var(--bg-elev,#ede4cc);border:1px solid var(--hairline,#c8b48a);border-radius:4px;padding:6px 10px;font-size:.78rem;pointer-events:none;max-width:200px';
  document.body.appendChild(localTip);

  function showLocalTip(e, html) {
    localTip.innerHTML = html;
    localTip.style.display = 'block';
    moveLocalTip(e);
  }
  function moveLocalTip(e) {
    const x = e.clientX + 14, y = e.clientY - 10;
    localTip.style.left = Math.min(x, window.innerWidth - 180) + 'px';
    localTip.style.top  = Math.max(8, y) + 'px';
  }
  function hideLocalTip() { localTip.style.display = 'none'; }

  /* Columns */
  points.forEach(function(m, mi) {
    const xBase = padL + mi * monthW + gap;
    const isFuture = partial && illustrative && mi >= points.length - (12 - (opts.lastDataMonth || 11));

    const axisLabel = (points.length === 12) ? KPC_MONTHS_FR[mi] : ('Q' + (mi + 1));
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
      const axisLabelTip = (points.length === 12) ? KPC_MONTHS_FR[mi].toUpperCase() : ('Q' + (mi + 1));
      rect.addEventListener('mouseenter', function(e) {
        showLocalTip(e, '<strong>' + axisLabelTip + '</strong> · ' + escHtml(s.labelFn()) + ' : <strong>' + fmt(val) + ' ' + escHtml(yUnit) + '</strong>');
      });
      rect.addEventListener('mousemove', moveLocalTip);
      rect.addEventListener('mouseleave', hideLocalTip);
      svg.appendChild(rect);

      if (bh > 18) {
        const vlbl = svgEl('text', {
          x: bx + (barW - 1) / 2, y: by - 3,
          'text-anchor': 'middle', fill: s.color,
          'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'font-weight': 500,
        });
        vlbl.textContent = fmt(val, val >= 100 ? 0 : 1);
        svg.appendChild(vlbl);
      }
    });
  });

  /* Y-axis label */
  if (yUnit) {
    const yAxisLbl = svgEl('text', {
      x: 10, y: padT + chartH / 2, 'text-anchor': 'middle', fill: C.ink_faint,
      'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'letter-spacing': '0.1em',
      transform: 'rotate(-90, 10, ' + (padT + chartH / 2) + ')',
    });
    yAxisLbl.textContent = yUnit;
    svg.appendChild(yAxisLbl);
  }

  container.innerHTML = '';
  container.appendChild(svg);
  return localTip; /* caller can remove on cleanup if needed */
}

/* ═══════════════════════════════════════════════════════════
   SPARKLINE SVG — dual-series (curr + prev)
   curr, prev: arrays of numbers (12 months)
   opts: { lastDataMi, label, color, W, H }
   ═══════════════════════════════════════════════════════════ */
function buildSparkSvg(curr, prev, opts) {
  opts = opts || {};
  const W = opts.W || 200, H = opts.H || 90;
  const padL = 4, padR = 4, padT = 10, padB = 20;
  const cW = W - padL - padR;
  const cH = H - padT - padB;
  const lastDataMi = (opts.lastDataMi != null) ? opts.lastDataMi : (curr.length - 1);
  const lineColor = opts.color || kpcTok('--hop') || '#567020';

  const currPoints = lastDataMi >= 0 ? curr.slice(0, lastDataMi + 1) : [];
  const allVals = currPoints.concat(prev || []);
  const maxVal = Math.max.apply(null, allVals.concat([0.1]));

  function xOf(i) { return padL + (i / 11) * cW; }
  function yOf(v) { return padT + cH * (1 - v / maxVal); }

  const svg = svgEl('svg', {
    viewBox: '0 0 ' + W + ' ' + H,
    class: 'kpc-spark-svg',
    'aria-label': escHtml(opts.label || ''),
  });

  /* Faint midline */
  svg.appendChild(svgEl('line', {
    x1: padL, y1: padT + cH * 0.5,
    x2: W - padR, y2: padT + cH * 0.5,
    stroke: kpcTok('--hairline') || '#c8b48a',
    'stroke-width': 0.5, opacity: 0.5,
  }));

  /* Previous year — dashed grey */
  if (prev && prev.some(function(v) { return v > 0; })) {
    var prevPath = '';
    for (var i = 0; i < 12; i++) {
      prevPath += (i === 0 ? 'M' : 'L') + xOf(i).toFixed(1) + ',' + yOf(prev[i] || 0).toFixed(1);
    }
    svg.appendChild(svgEl('path', {
      d: prevPath, fill: 'none',
      stroke: kpcTok('--ink-mute') || '#4a3820',
      'stroke-width': 1,
      'stroke-dasharray': '3 3',
      opacity: 0.45,
      'stroke-linejoin': 'round', 'stroke-linecap': 'round',
    }));
  }

  /* Current series — solid */
  if (currPoints.some(function(v) { return v > 0; })) {
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
      stroke: lineColor, 'stroke-width': 1.5,
      'stroke-linejoin': 'round', 'stroke-linecap': 'round',
    }));
  }

  /* Month axis labels (jan, avr, jul, oct) */
  [0, 3, 6, 9].forEach(function(mi) {
    const t = svgEl('text', {
      x: xOf(mi), y: H - 4,
      'text-anchor': 'middle',
      fill: kpcTok('--ink-faint') || '#7a6647',
      'font-family': 'JetBrains Mono,monospace',
      'font-size': 7,
      'letter-spacing': '0.04em',
    });
    t.textContent = KPC_MONTHS_FR[mi];
    svg.appendChild(t);
  });

  return svg;
}

/* ═══════════════════════════════════════════════════════════
   paceTintResult — shared pace tint helper
   Input: { status, currTotal, paceRefPrev, pacePct }
   Returns: 'ok' | 'ember' | 'nouveau' | 'arrete' | 'neutral'
   ═══════════════════════════════════════════════════════════ */
function paceTintResult(b) {
  if (b.status === 'nouveau') return 'nouveau';
  if (b.status === 'arrete')  return 'arrete';
  if ((b.paceRefPrev === 0 || b.paceRefPrev == null) && b.currTotal > 0) return 'ok';
  if (b.pacePct === null && b.currTotal > 0) return 'ok';
  return (b.pacePct !== null && b.pacePct >= 100) ? 'ok' : 'ember';
}

/* ═══════════════════════════════════════════════════════════
   renderKpiCard — render one kpi_dispatch result into a container div.
   Dispatches on result.meta.stub (handler not ready) and viz_type.
   container: DOM element to render into
   tracker:   ref_kpi_trackers row { slug, label, viz_type, category, … }
   result:    kpi_dispatch() output { value, unit, label, delta, … }
   isAdmin:   bool — whether to show admin-only "handler pending" note
   ═══════════════════════════════════════════════════════════ */
function renderKpiCard(container, tracker, result, isAdmin) {
  container.innerHTML = '';
  container.className = 'kpc-card kpc-card--' + escHtml(tracker.viz_type || 'kpi_number');

  /* Error state */
  if (result.error) {
    container.innerHTML = '<div class="kpc-card__label">' + escHtml(tracker.label) + '</div>'
      + '<div class="kpc-card__error">Erreur&nbsp;: ' + escHtml(result.error) + '</div>';
    return;
  }

  /* Stub state — data_ready=1 but handler not yet implemented */
  if (result.meta && result.meta.stub) {
    if (isAdmin) {
      container.classList.add('kpc-card--stub');
      container.innerHTML = '<div class="kpc-card__label">' + escHtml(tracker.label) + '</div>'
        + '<div class="kpc-card__stub-note">Handler en attente&nbsp;: <code>' + escHtml(result.meta.domain + '/' + result.meta.handler) + '</code></div>';
    } else {
      /* Non-admins: this tracker should never have been visible — hide silently */
      container.style.display = 'none';
    }
    return;
  }

  const tCls = tintClass(result.tint);

  switch (tracker.viz_type) {
    case 'kpi_number':
      renderKpiNumber(container, tracker, result, tCls);
      break;
    case 'sparkline':
      renderKpiSparkline(container, tracker, result, tCls);
      break;
    case 'bar':
      renderKpiBar(container, tracker, result, tCls);
      break;
    case 'stacked_bar':
      renderKpiStackedBar(container, tracker, result, tCls);
      break;
    case 'flag':
      renderKpiFlag(container, tracker, result, tCls);
      break;
    case 'donut':
      renderKpiDonut(container, tracker, result, tCls);
      break;
    case 'table':
      renderKpiTable(container, tracker, result, tCls);
      break;
    case 'waterfall':
      renderKpiWaterfall(container, tracker, result, tCls);
      break;
    case 'line':
      renderKpiLine(container, tracker, result, tCls);
      break;
    default:
      renderKpiNumber(container, tracker, result, tCls);
  }
}

/* ── kpi_number ──────────────────────────────────────────── */
function renderKpiNumber(container, tracker, result, tCls) {
  const val  = result.value != null ? escHtml(fmt(result.value, null, result.unit)) : '—';
  const unit = result.unit  ? ' <span class="kpc-unit">' + escHtml(result.unit) + '</span>' : '';
  const periodLbl = result.meta && result.meta.period_label
    ? '<div class="kpc-period">' + escHtml(result.meta.period_label) + '</div>' : '';
  const freshness = freshnessChipHtml(result.meta);

  container.innerHTML =
    '<div class="kpc-card__label">' + escHtml(result.label || tracker.label) + '</div>'
    + periodLbl
    + freshness
    + '<div class="kpc-card__value ' + tCls + '">' + val + unit + '</div>'
    + deltaHtml(result.delta, result.delta_label);
}

/* ── sparkline ───────────────────────────────────────────── */
function renderKpiSparkline(container, tracker, result, tCls) {
  const series = result.series || [];
  const curr = series.map(function(p) { return p.value || 0; });
  const prev = (result.meta && result.meta.prev_series)
    ? result.meta.prev_series.map(function(p) { return p.value || 0; })
    : [];

  const lastDataMi = curr.reduce(function(last, v, i) { return v > 0 ? i : last; }, -1);

  container.innerHTML =
    '<div class="kpc-card__label">' + escHtml(result.label || tracker.label) + '</div>';

  if (curr.length > 0) {
    const svg = buildSparkSvg(curr, prev.length ? prev : null, {
      lastDataMi: lastDataMi,
      label: tracker.label,
      color: kpcTok('--hop') || '#567020',
    });
    container.appendChild(svg);
  } else {
    const noData = document.createElement('div');
    noData.className = 'kpc-no-data';
    noData.textContent = '—';
    container.appendChild(noData);
  }

  if (result.value != null) {
    const valDiv = document.createElement('div');
    valDiv.className = 'kpc-card__value ' + tCls;
    valDiv.innerHTML = escHtml(fmt(result.value, null, result.unit))
      + (result.unit ? ' <span class="kpc-unit">' + escHtml(result.unit) + '</span>' : '');
    container.appendChild(valDiv);
  }
  if (result.delta != null) {
    const dDiv = document.createElement('div');
    dDiv.innerHTML = deltaHtml(result.delta, result.delta_label);
    container.appendChild(dDiv);
  }
}

/* ── bar ─────────────────────────────────────────────────── */
function renderKpiBar(container, tracker, result, tCls) {
  const series = result.series || [];
  if (!series.length) {
    container.innerHTML = '<div class="kpc-card__label">' + escHtml(result.label || tracker.label) + '</div>'
      + '<div class="kpc-no-data">—</div>';
    return;
  }

  container.innerHTML = '<div class="kpc-card__label">' + escHtml(result.label || tracker.label) + '</div>';
  const chartDiv = document.createElement('div');
  chartDiv.className = 'kpc-bar-wrap';
  container.appendChild(chartDiv);

  const C = kpcColors();
  const points = series.map(function(p) { return [p.value || 0]; });
  const label = result.label || tracker.label;
  buildBarChart(chartDiv, points, [
    { color: C.core, labelFn: function() { return label; } },
  ], {
    height: 140,
    yUnit: result.unit || '',
  });
}

/* ── stacked_bar ─────────────────────────────────────────── */
function renderKpiStackedBar(container, tracker, result, tCls) {
  const breakdown = result.breakdown || [];
  const series    = result.series    || [];

  const freshChip = freshnessChipHtml(result.meta);
  container.innerHTML = '<div class="kpc-card__label">' + escHtml(result.label || tracker.label) + '</div>'
    + freshChip;

  if (breakdown.length) {
    /* Horizontal stacked bar showing breakdown proportions */
    const total = breakdown.reduce(function(s, b) { return s + (b.value || 0); }, 0);
    const C = kpcColors();
    const COLORS = [C.core, C.spec, C.contract, C.amber, C.ok];

    const barWrap = document.createElement('div');
    barWrap.className = 'kpc-stacked-bar';
    const barInner = document.createElement('div');
    barInner.className = 'kpc-stacked-bar__inner';

    breakdown.forEach(function(b, i) {
      const pct = total > 0 ? b.value / total * 100 : 0;
      const seg = document.createElement('div');
      seg.className = 'kpc-stacked-bar__seg';
      seg.style.cssText = 'width:' + pct.toFixed(2) + '%;background:' + (COLORS[i % COLORS.length]);
      seg.title = escHtml(b.label) + ': ' + fmt(b.value, 0) + (result.unit ? ' ' + result.unit : '');
      barInner.appendChild(seg);
    });
    barWrap.appendChild(barInner);
    container.appendChild(barWrap);

    /* Legend */
    const legend = document.createElement('div');
    legend.className = 'kpc-stacked-legend';
    breakdown.forEach(function(b, i) {
      const item = document.createElement('span');
      item.className = 'kpc-stacked-legend__item';
      item.innerHTML = '<span class="kpc-stacked-legend__sw" style="background:' + COLORS[i % COLORS.length] + '"></span>'
        + escHtml(b.label) + '&nbsp;<strong>' + fmt(b.value, 0) + (result.unit ? '&nbsp;' + escHtml(result.unit) : '') + '</strong>';
      legend.appendChild(item);
    });
    container.appendChild(legend);
  }

  if (result.value != null) {
    const valDiv = document.createElement('div');
    valDiv.className = 'kpc-card__value ' + tCls;
    valDiv.innerHTML = escHtml(fmt(result.value, 0))
      + (result.unit ? ' <span class="kpc-unit">' + escHtml(result.unit) + '</span>' : '');
    container.appendChild(valDiv);
  }
  if (result.delta != null) {
    const dDiv = document.createElement('div');
    dDiv.innerHTML = deltaHtml(result.delta, result.delta_label);
    container.appendChild(dDiv);
  }
}

/* ── flag ────────────────────────────────────────────────── */
function renderKpiFlag(container, tracker, result, tCls) {
  const val = result.value;
  const isAlert = val != null && Number(val) > 0;

  /* Format flag value: if numeric use fmt(), otherwise pass through as string */
  var valDisplay = '—';
  if (val != null) {
    valDisplay = (typeof val === 'number') ? escHtml(fmt(val, Number.isInteger(val) ? 0 : 1)) : escHtml(String(val));
  }
  container.innerHTML =
    '<div class="kpc-card__label">' + escHtml(result.label || tracker.label) + '</div>'
    + '<div class="kpc-flag ' + (isAlert ? 'kpc-flag--alert' : 'kpc-flag--ok') + '">'
    +   valDisplay
    +   (result.unit ? ' <span class="kpc-unit">' + escHtml(result.unit) + '</span>' : '')
    + '</div>';

  if (result.breakdown && result.breakdown.length) {
    let bkHtml = '<ul class="kpc-flag-list">';
    result.breakdown.forEach(function(b) {
      var bv = (typeof b.value === 'number')
        ? escHtml(fmt(b.value, Number.isInteger(b.value) ? 0 : 1))
        : escHtml(String(b.value));
      bkHtml += '<li>' + escHtml(b.label) + ': <strong>' + bv + '</strong></li>';
    });
    bkHtml += '</ul>';
    container.innerHTML += bkHtml;
  }
}

/* ── donut ───────────────────────────────────────────────── */
function renderKpiDonut(container, tracker, result, tCls) {
  const breakdown = result.breakdown || [];
  const C = kpcColors();
  const COLORS = [C.core, C.spec, C.contract, C.amber, C.ok, C.ember];

  container.innerHTML = '<div class="kpc-card__label">' + escHtml(result.label || tracker.label) + '</div>';

  if (!breakdown.length) {
    const noData = document.createElement('div');
    noData.className = 'kpc-no-data';
    noData.textContent = '—';
    container.appendChild(noData);
    return;
  }

  const total = breakdown.reduce(function(s, b) { return s + (b.value || 0); }, 0);
  const W = 160, H = 160, r = 60, cx = 80, cy = 80;
  const svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'kpc-donut-svg', role: 'img' });

  let angle = -Math.PI / 2;
  breakdown.forEach(function(b, i) {
    if (!b.value) return;
    const sweep = total > 0 ? (b.value / total) * Math.PI * 2 : 0;
    const x1 = cx + r * Math.cos(angle);
    const y1 = cy + r * Math.sin(angle);
    const x2 = cx + r * Math.cos(angle + sweep);
    const y2 = cy + r * Math.sin(angle + sweep);
    const large = sweep > Math.PI ? 1 : 0;
    const path = svgEl('path', {
      d: 'M' + cx + ',' + cy + ' L' + x1.toFixed(2) + ',' + y1.toFixed(2)
        + ' A' + r + ',' + r + ' 0 ' + large + ',1 ' + x2.toFixed(2) + ',' + y2.toFixed(2) + ' Z',
      fill: COLORS[i % COLORS.length],
      stroke: 'var(--bg,#f1e8d4)',
      'stroke-width': 2,
    });
    path.title = escHtml(b.label) + ': ' + fmt(b.value, 0);
    svg.appendChild(path);
    angle += sweep;
  });
  container.appendChild(svg);

  /* Legend */
  const legend = document.createElement('div');
  legend.className = 'kpc-donut-legend';
  breakdown.forEach(function(b, i) {
    if (!b.value) return;
    const item = document.createElement('div');
    item.className = 'kpc-donut-legend__item';
    item.innerHTML = '<span class="kpc-donut-legend__sw" style="background:' + COLORS[i % COLORS.length] + '"></span>'
      + escHtml(b.label) + '&nbsp;<strong>' + fmt(b.value, 0) + (result.unit ? '&nbsp;' + escHtml(result.unit) : '') + '</strong>';
    legend.appendChild(item);
  });
  container.appendChild(legend);
}

/* ── table ───────────────────────────────────────────────── */
function renderKpiTable(container, tracker, result, tCls) {
  container.innerHTML = '<div class="kpc-card__label">' + escHtml(result.label || tracker.label) + '</div>';

  const rows = result.breakdown || result.series || [];
  if (!rows.length) {
    const noData = document.createElement('div');
    noData.className = 'kpc-no-data';
    noData.textContent = '—';
    container.appendChild(noData);
    return;
  }

  const tbl = document.createElement('table');
  tbl.className = 'kpc-table';
  let html = '<thead><tr><th>Élément</th><th>' + escHtml(result.unit || 'Valeur') + '</th></tr></thead><tbody>';
  rows.slice(0, 12).forEach(function(r) {
    html += '<tr><td>' + escHtml(r.label || r.key) + '</td><td>' + escHtml(fmt(r.value, 2)) + '</td></tr>';
  });
  if (rows.length > 12) {
    html += '<tr class="kpc-table__more"><td colspan="2">+' + (rows.length - 12) + ' lignes…</td></tr>';
  }
  html += '</tbody>';
  tbl.innerHTML = html;
  container.appendChild(tbl);

  if (result.value != null) {
    const valDiv = document.createElement('div');
    valDiv.className = 'kpc-card__value ' + tCls;
    valDiv.innerHTML = 'Total&nbsp;: ' + escHtml(fmt(result.value, 2))
      + (result.unit ? ' <span class="kpc-unit">' + escHtml(result.unit) + '</span>' : '');
    container.appendChild(valDiv);
  }
}

/* ── waterfall ───────────────────────────────────────────── */
function renderKpiWaterfall(container, tracker, result, tCls) {
  container.innerHTML = '<div class="kpc-card__label">' + escHtml(result.label || tracker.label) + '</div>';

  const breakdown = result.breakdown || [];
  if (!breakdown.length) {
    const noData = document.createElement('div');
    noData.className = 'kpc-no-data';
    noData.textContent = '—';
    container.appendChild(noData);
    return;
  }

  const C = kpcColors();
  const barW = 28, gap = 12, padL = 80, padT = 16, padB = 24, H = 200;
  const totalW = padL + breakdown.length * (barW + gap) + 20;
  const W = Math.min(totalW, 840);

  const vals = breakdown.map(function(b) { return b.value || 0; });
  const absMax = Math.max.apply(null, vals.map(Math.abs).concat([1]));
  const yMid = padT + (H - padT - padB) / 2;
  const scale = (H - padT - padB) / 2 / absMax;

  const svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'kpc-waterfall-svg', role: 'img' });

  /* Baseline */
  svg.appendChild(svgEl('line', { x1: 0, y1: yMid, x2: W, y2: yMid, stroke: C.hairline, 'stroke-width': 1 }));

  breakdown.forEach(function(b, i) {
    const x = padL + i * (barW + gap);
    const val = b.value || 0;
    const bh = Math.max(Math.abs(val) * scale, 2);
    const by = val >= 0 ? yMid - bh : yMid;
    const fill = val >= 0 ? C.core : C.ember;
    svg.appendChild(svgEl('rect', { x: x, y: by, width: barW, height: bh, fill: fill, rx: 2 }));

    const lbl = svgEl('text', {
      x: x + barW / 2, y: H - 4,
      'text-anchor': 'middle', fill: C.ink_faint,
      'font-family': 'JetBrains Mono,monospace', 'font-size': 7,
    });
    lbl.textContent = (b.label || b.key || '').slice(0, 8);
    svg.appendChild(lbl);
  });

  container.appendChild(svg);

  if (result.value != null) {
    const valDiv = document.createElement('div');
    valDiv.className = 'kpc-card__value ' + tCls;
    valDiv.innerHTML = escHtml(fmt(result.value, 2))
      + (result.unit ? ' <span class="kpc-unit">' + escHtml(result.unit) + '</span>' : '');
    container.appendChild(valDiv);
  }
}

/* ── line ────────────────────────────────────────────────── */
function renderKpiLine(container, tracker, result, tCls) {
  /* Reuse sparkline renderer with single series */
  const series = result.series || [];
  const curr = series.map(function(p) { return p.value || 0; });
  const lastDataMi = curr.reduce(function(last, v, i) { return v > 0 ? i : last; }, -1);

  container.innerHTML = '<div class="kpc-card__label">' + escHtml(result.label || tracker.label) + '</div>';
  if (curr.length > 0) {
    const svg = buildSparkSvg(curr, null, {
      lastDataMi: lastDataMi,
      label: tracker.label,
      color: kpcTok('--hop') || '#567020',
      W: 320, H: 120,
    });
    container.appendChild(svg);
  }
  if (result.value != null) {
    const valDiv = document.createElement('div');
    valDiv.className = 'kpc-card__value ' + tCls;
    valDiv.innerHTML = escHtml(fmt(result.value, null, result.unit))
      + (result.unit ? ' <span class="kpc-unit">' + escHtml(result.unit) + '</span>' : '');
    container.appendChild(valDiv);
  }
  if (result.delta != null) {
    const dDiv = document.createElement('div');
    dDiv.innerHTML = deltaHtml(result.delta, result.delta_label);
    container.appendChild(dDiv);
  }
}

/* ── Public exports (consumed by wort-kpis.js and mon-tableau) ── */
window.KpcCharts = {
  escHtml:         escHtml,
  fmt:             fmt,
  svgEl:           svgEl,
  buildBarChart:   buildBarChart,
  buildSparkSvg:   buildSparkSvg,
  paceTintResult:  paceTintResult,
  renderKpiCard:   renderKpiCard,
  kpcTok:          kpcTok,
  KPC_MONTHS_FR:   KPC_MONTHS_FR,
};
