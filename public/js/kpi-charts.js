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

    const axisLabel = (opts.xLabels && opts.xLabels[mi] !== undefined) ? opts.xLabels[mi] : ((points.length === 12) ? KPC_MONTHS_FR[mi] : ('Q' + (mi + 1)));
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
      const axisLabelTip = (opts.xLabels && opts.xLabels[mi] !== undefined) ? opts.xLabels[mi].toUpperCase() : ((points.length === 12) ? KPC_MONTHS_FR[mi].toUpperCase() : ('Q' + (mi + 1)));
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

  /* Previous year — dashed grey. Truncate to the same span as the current
     series (lastDataMi) so a zero-padded prev tail doesn't crash the ghost
     line to zero past the current month. xOf keeps the 12-month scale. */
  const prevPoints = (prev && lastDataMi >= 0) ? prev.slice(0, lastDataMi + 1) : [];
  if (prevPoints.some(function(v) { return v > 0; })) {
    var prevPath = '';
    for (var i = 0; i < prevPoints.length; i++) {
      prevPath += (i === 0 ? 'M' : 'L') + xOf(i).toFixed(1) + ',' + yOf(prevPoints[i] || 0).toFixed(1);
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
    case 'recap':
      renderKpiRecap(container, tracker, result, tCls);
      break;
    case 'grouped_bar':
      renderKpiGroupedBar(container, tracker, result, tCls);
      break;
    case 'stacked_columns':
      renderKpiStackedColumns(container, tracker, result, tCls);
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
  const xLabels = series.every(function(p) { return p.period; }) ? series.map(function(p) { return p.period; }) : undefined;
  buildBarChart(chartDiv, points, [
    { color: C.core, labelFn: function() { return label; } },
  ], {
    height: 140,
    yUnit: result.unit || '',
    xLabels: xLabels,
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

/* ── grouped_bar ─────────────────────────────────────────── */
/* Horizontal per-beer bar rows: solid current year, ghost prior year.
   Reads result.breakdown[]{key, label, value, meta:{prior_year, classification}}
   sorted desc (already sorted by handler).
   Cap: show top 12 rows max; if truncated show "+N autres" line. */
function renderKpiGroupedBar(container, tracker, result, tCls) {
  const label = result.label || tracker.label;
  container.innerHTML = '<div class="kpc-card__label">' + escHtml(label) + '</div>';

  const breakdown = result.breakdown || [];

  if (!breakdown.length) {
    const noData = document.createElement('div');
    noData.className = 'kpc-no-data';
    noData.textContent = '—';
    container.appendChild(noData);
    if (result.value != null) {
      const valDiv = document.createElement('div');
      valDiv.className = 'kpc-card__value ' + tCls;
      valDiv.innerHTML = escHtml(fmt(result.value, 1)) + (result.unit ? ' <span class="kpc-unit">' + escHtml(result.unit) + '</span>' : '');
      container.appendChild(valDiv);
    }
    return;
  }

  const CAP = 12;
  const shown     = breakdown.slice(0, CAP);
  const truncated = breakdown.length - shown.length;

  const maxCurr = shown.reduce(function(m, b) { return Math.max(m, b.value || 0); }, 0.01);
  const maxGhost = shown.reduce(function(m, b) {
    return Math.max(m, (b.meta && b.meta.prior_year) ? b.meta.prior_year : 0);
  }, 0.01);
  const maxScale = Math.max(maxCurr, maxGhost);

  const wrap = document.createElement('div');
  wrap.className = 'kpc-grouped-list';

  shown.forEach(function(b) {
    const curr  = b.value || 0;
    const prior = (b.meta && b.meta.prior_year != null) ? b.meta.prior_year : null;
    const currPct  = maxScale > 0 ? (curr  / maxScale * 100) : 0;
    const ghostPct = (prior != null && maxScale > 0) ? (prior / maxScale * 100) : 0;

    var chipHtml = '';
    if (b.meta && b.meta.chip_label != null) {
      // Custom verbatim chip — neutral, no computed YoY
      chipHtml = '<span class="kpc-grouped-delta kpc-grouped-delta--neutral">' + escHtml(b.meta.chip_label) + '</span>';
    } else if (prior === null || prior === 0) {
      if (curr > 0) {
        chipHtml = '<span class="kpc-grouped-delta kpc-grouped-delta--new">nouveau</span>';
      } else {
        chipHtml = '<span class="kpc-grouped-delta kpc-grouped-delta--neutral">—</span>';
      }
    } else {
      const dpct = Math.round((curr - prior) / prior * 100);
      if (dpct >= 0) {
        chipHtml = '<span class="kpc-grouped-delta kpc-grouped-delta--up">▲ +' + dpct + '%</span>';
      } else {
        chipHtml = '<span class="kpc-grouped-delta kpc-grouped-delta--down">▼ ' + dpct + '%</span>';
      }
    }

    const row = document.createElement('div');
    row.className = 'kpc-grouped-row';
    row.innerHTML =
      '<div class="kpc-grouped-row__lbl">' + escHtml(b.label) + '</div>'
      + '<div class="kpc-grouped-row__track">'
      +   (ghostPct > 0 ? '<div class="kpc-grouped-bar kpc-grouped-bar--ghost" style="width:' + ghostPct.toFixed(1) + '%"></div>' : '')
      +   '<div class="kpc-grouped-bar kpc-grouped-bar--curr" style="width:' + currPct.toFixed(1) + '%"></div>'
      + '</div>'
      + '<div class="kpc-grouped-row__val">'
      +   escHtml(fmt(curr, 1)) + ((b.unit != null ? b.unit : result.unit) ? '&nbsp;<span class="kpc-unit">' + escHtml(b.unit != null ? b.unit : result.unit) + '</span>' : '')
      + '</div>'
      + '<div class="kpc-grouped-row__delta">' + chipHtml + '</div>';
    wrap.appendChild(row);
  });

  if (truncated > 0) {
    const more = document.createElement('div');
    more.className = 'kpc-grouped-more';
    more.textContent = '+' + truncated + ' autres';
    wrap.appendChild(more);
  }

  container.appendChild(wrap);

  if (result.value != null) {
    const valDiv = document.createElement('div');
    valDiv.className = 'kpc-card__value ' + tCls;
    valDiv.innerHTML = escHtml(fmt(result.value, 1)) + (result.unit ? ' <span class="kpc-unit">' + escHtml(result.unit) + '</span>' : '');
    container.appendChild(valDiv);
  }
  if (result.delta != null) {
    const dDiv = document.createElement('div');
    dDiv.innerHTML = deltaHtml(result.delta, result.delta_label);
    container.appendChild(dDiv);
  }
}

/* ── stacked_columns ──────────────────────────────────────────────────────── */
/* 12-month vertical stacked column chart. result.meta.columns = array of    */
/* { period, total, segments: [{key, value}] }. result.breakdown = legend.   */
function renderKpiStackedColumns(container, tracker, result, tCls) {
  const label = result.label || tracker.label;
  container.innerHTML = '<div class="kpc-card__label">' + escHtml(label) + '</div>';

  const columns   = (result.meta && result.meta.columns) ? result.meta.columns : [];
  const breakdown = result.breakdown || [];

  if (!columns.length) {
    const nd = document.createElement('div');
    nd.className = 'kpc-no-data';
    nd.textContent = '—';
    container.appendChild(nd);
    return;
  }

  /* Inject CSS once */
  if (!document.getElementById('kpc-stacked-col-style')) {
    const s = document.createElement('style');
    s.id = 'kpc-stacked-col-style';
    s.textContent = [
      '.kpc-stacked-col-wrap{margin:6px 0 4px;}',
      '.kpc-stacked-col-chart{display:flex;align-items:flex-end;gap:2px;height:120px;overflow:hidden;}',
      '.kpc-stacked-col-month{display:flex;flex-direction:column;align-items:center;flex:1;min-width:0;}',
      '.kpc-stacked-col-bar{display:flex;flex-direction:column-reverse;width:100%;border-radius:2px 2px 0 0;overflow:hidden;min-height:2px;}',
      '.kpc-stacked-col-seg{width:100%;flex-shrink:0;}',
      '.kpc-stacked-col-axis{font-size:9px;color:var(--ink-faint,#7a6647);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;text-align:center;}',
      '.kpc-stacked-col-legend{display:flex;flex-wrap:wrap;gap:3px 10px;margin-top:8px;}',
      '.kpc-stacked-col-legend-item{display:flex;align-items:center;gap:4px;font-size:11px;color:var(--ink-soft,#3d2f1f);}',
      '.kpc-stacked-col-legend-swatch{width:10px;height:10px;border-radius:2px;flex-shrink:0;}',
    ].join('');
    document.head.appendChild(s);
  }

  const C = kpcColors();
  const SEG_COLORS = [C.core, C.spec, C.contract, C.amber, C.ok, C.ember || '#b34428', '#c9b352', '#b08d57'];

  const maxTotal = Math.max.apply(null, columns.map(function(c) { return c.total || 0; }).concat([1]));

  /* Chart */
  const chartDiv = document.createElement('div');
  chartDiv.className = 'kpc-stacked-col-chart';

  columns.forEach(function(col) {
    const total    = col.total || 0;
    const segments = col.segments || [];
    const barH     = maxTotal > 0 ? (total / maxTotal * 100) : 0; // % of 120px container

    const monthDiv = document.createElement('div');
    monthDiv.className = 'kpc-stacked-col-month';

    const barDiv = document.createElement('div');
    barDiv.className = 'kpc-stacked-col-bar';
    barDiv.style.height = barH.toFixed(1) + '%';

    /* segments in top-to-bottom order inside the column-reverse flex */
    segments.forEach(function(seg, i) {
      const segH = (total > 0 && seg.value > 0) ? (seg.value / total * 100) : 0;
      if (segH <= 0) return;
      const segDiv = document.createElement('div');
      segDiv.className = 'kpc-stacked-col-seg';
      segDiv.style.height     = segH.toFixed(1) + '%';
      segDiv.style.background = SEG_COLORS[i % SEG_COLORS.length];
      barDiv.appendChild(segDiv);
    });

    const periodStr = col.period || '';
    const mm = periodStr.length >= 7 ? parseInt(periodStr.slice(5), 10) - 1 : -1;
    const axisDiv = document.createElement('div');
    axisDiv.className = 'kpc-stacked-col-axis';
    axisDiv.textContent = (mm >= 0 && KPC_MONTHS_FR[mm]) ? KPC_MONTHS_FR[mm] : (periodStr.slice(5) || '?');

    monthDiv.appendChild(barDiv);
    monthDiv.appendChild(axisDiv);
    chartDiv.appendChild(monthDiv);
  });

  const wrap = document.createElement('div');
  wrap.className = 'kpc-stacked-col-wrap';
  wrap.appendChild(chartDiv);

  /* Period label + delta */
  const periodLbl = (result.meta && result.meta.period_label) ? result.meta.period_label : '';
  if (periodLbl) {
    const pl = document.createElement('div');
    pl.className = 'kpc-period';
    pl.textContent = periodLbl;
    wrap.appendChild(pl);
  }
  if (result.delta != null) {
    const dDiv = document.createElement('div');
    dDiv.innerHTML = deltaHtml(result.delta, result.delta_label);
    wrap.appendChild(dDiv);
  }

  /* Legend */
  const CAP_LEGEND = 8;
  const shownLegend   = breakdown.slice(0, CAP_LEGEND);
  const extraLegend   = breakdown.length - shownLegend.length;
  if (shownLegend.length) {
    const legendDiv = document.createElement('div');
    legendDiv.className = 'kpc-stacked-col-legend';
    shownLegend.forEach(function(b, i) {
      const item = document.createElement('div');
      item.className = 'kpc-stacked-col-legend-item';
      const sw = document.createElement('div');
      sw.className = 'kpc-stacked-col-legend-swatch';
      sw.style.background = SEG_COLORS[i % SEG_COLORS.length];
      const lbl = document.createElement('span');
      lbl.textContent = b.label;
      item.appendChild(sw);
      item.appendChild(lbl);
      legendDiv.appendChild(item);
    });
    if (extraLegend > 0) {
      const more = document.createElement('div');
      more.className = 'kpc-stacked-col-legend-item';
      more.style.color = 'var(--ink-faint,#7a6647)';
      more.textContent = '+' + extraLegend + ' autres';
      legendDiv.appendChild(more);
    }
    wrap.appendChild(legendDiv);
  }

  container.appendChild(wrap);

  /* Scalar value footer */
  if (result.value != null) {
    const valDiv = document.createElement('div');
    valDiv.className = 'kpc-card__value ' + tCls;
    valDiv.innerHTML = escHtml(fmt(result.value, 1)) + (result.unit ? ' <span class="kpc-unit">' + escHtml(result.unit) + '</span>' : '');
    container.appendChild(valDiv);
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

/* ── recap ──────────────────────────────────────────────────
   Composite "daily summary" card.
   meta.sections  → headline metric strips
   breakdown[]    → per-run rows (key prefix 'run_') + per-material rows
   Material label map keeps DB column names out of the UI.  */

var KPC_RECAP_MAT_LABELS = {
  mat_label:       'Étiquettes',
  mat_crown:       'Capsules',
  mat_can_lid:     'Couvercles canette',
  mat_cont_btl:    'Bouteilles',
  mat_cont_can:    'Canettes',
  mat_4pack_btl:   'Packs bouteille',
  mat_4pack_can:   'Packs canette',
  mat_wrap_btl:    'Fardelage bouteille',
  mat_wrap_can:    'Fardelage canette',
  mat_keg_liq:     'Perte liquide fût (L)',
  mat_keg_collar:  'Capuchons fût',
};

function renderKpiRecap(container, tracker, result, tCls) {
  var meta      = result.meta      || {};
  var sections  = meta.sections    || [];
  var breakdown = result.breakdown || [];
  var label     = escHtml(result.label || tracker.label);

  // Wort tiles carry a "(saisi aujourd'hui)" subtitle cue
  var isWort    = tracker.source_domain === 'wort';
  var subtitle  = isWort
    ? '<span class="kpc-recap__subtitle">(saisi aujourd\'hui)</span>'
    : '';

  // Headline metrics strip
  var sectHtml = '';
  for (var i = 0; i < sections.length; i++) {
    var s = sections[i];
    var v = (s.value != null) ? escHtml(fmt(s.value, null, s.unit || '')) : '—';
    var u = s.unit ? ' <span class="kpc-unit">' + escHtml(s.unit) + '</span>' : '';
    var tintCls = s.tint === 'amber' ? ' kpc-recap__metric--amber' : '';
    sectHtml +=
      '<div class="kpc-recap__metric' + tintCls + '">'
      + '<span class="kpc-recap__metric-val">' + v + u + '</span>'
      + '<span class="kpc-recap__metric-lbl">' + escHtml(s.label) + '</span>'
      + '</div>';
  }

  // Run-type → French operator label (FIX2a, 2026-06-10). No DB codes in the UI.
  var RUN_TYPE_FR = {
    'bot':   'bouteille',
    'can':   'canette',
    'can33': 'canette 33cl',
    'keg':   'fût',
    'cuv':   'cuve'
  };

  // recapRowLabel — compose the per-row label string.
  // Packaging per-run rows: "{bière} · {SKU} · #{batch} · {type}"
  //   SKU from meta.sku (null → "—", never blank — 78/2271 rows have no sku_id_fk).
  // Wort/brew/rack/quality rows: "{recipe_name} · #{batch}" (no SKU, no run-type).
  // null / empty batch → "#—" (honest absence, never fabricated).
  function recapRowLabel(row) {
    var base     = row.label || '';
    var m        = row.meta || {};
    var batch    = (m.batch != null && m.batch !== '') ? String(m.batch) : null;
    var batchStr = escHtml(batch != null ? batch : '—');
    var lbl = escHtml(base);
    // SKU: packaging per-run rows only (meta.sku present)
    if (m.run_type != null) {
      var skuStr = (m.sku != null && m.sku !== '') ? escHtml(String(m.sku)) : '—';
      lbl += ' <span class="kpc-recap__sku">· ' + skuStr + '</span>';
    }
    lbl += ' <span class="kpc-recap__batch">· #' + batchStr + '</span>';
    // Append run-type for packaging per-run rows
    if (m.run_type != null) {
      var typeFr = RUN_TYPE_FR[m.run_type] || escHtml(m.run_type);
      lbl += ' <span class="kpc-recap__run-type">· ' + escHtml(typeFr) + '</span>';
    }
    return lbl;
  }

  // Row partitioning:
  //  - packaging recap: 'run_*' rows = per-run, all other = material-loss rows.
  //  - wort recap: 'brew_*' rows = brassins par (recette, lot),
  //    'rack_*' = transferts par (recette, lot),
  //    'dryhop_*' = dry-hop per lot, 'coldcrash_*' = cold crash per lot.
  var bkHtml = '';
  var runRows       = breakdown.filter(function(r) { return r.key && r.key.indexOf('run_') === 0; });
  var brewRows      = breakdown.filter(function(r) { return r.key && r.key.indexOf('brew_') === 0; });
  var rackRows      = breakdown.filter(function(r) { return r.key && r.key.indexOf('rack_') === 0; });
  var dhRows        = breakdown.filter(function(r) { return r.key && r.key.indexOf('dryhop_') === 0; });
  var ccRows        = breakdown.filter(function(r) { return r.key && r.key.indexOf('coldcrash_') === 0; });
  // qualityRows: per-brassin quality metrics (Expansion 3, wort recap only)
  var qualityRows   = breakdown.filter(function(r) { return r.key && r.key.indexOf('quality_') === 0; });
  // matRows: packaging material-loss rows — everything not one of the above event-row prefixes
  var matRows  = breakdown.filter(function(r) {
    if (!r.key) return true;
    return r.key.indexOf('run_') !== 0
        && r.key.indexOf('brew_') !== 0
        && r.key.indexOf('rack_') !== 0
        && r.key.indexOf('dryhop_') !== 0
        && r.key.indexOf('coldcrash_') !== 0
        && r.key.indexOf('quality_') !== 0;
  });

  // run_type → section title mapping (CHANGE 1, 2026-06-10).
  // Sections are rendered in this canonical order; empty sections suppressed.
  var RUN_TYPE_SECTION = {
    'bot':   'Bouteille',
    'can':   'Canette',
    'can33': 'Canette',   // can33 lives inside the Canette section (tagged inline as "canette 33cl")
    'keg':   'Fût',
    'cuv':   'Cuve'
  };
  // Canonical section order (suppress empty; render in this sequence)
  var SECTION_ORDER = ['Bouteille', 'Canette', 'Fût', 'Cuve'];

  if (runRows.length) {
    // Group by section title while preserving within-section order
    var sectionMap = {};
    for (var j = 0; j < runRows.length; j++) {
      var row   = runRows[j];
      var rMeta = row.meta || {};
      var secTitle = RUN_TYPE_SECTION[rMeta.run_type] || 'Autre';
      if (!sectionMap[secTitle]) { sectionMap[secTitle] = []; }
      sectionMap[secTitle].push(row);
    }

    for (var si = 0; si < SECTION_ORDER.length; si++) {
      var sTitle = SECTION_ORDER[si];
      if (!sectionMap[sTitle] || sectionMap[sTitle].length === 0) { continue; }
      bkHtml += '<div class="kpc-recap__section-title">' + escHtml(sTitle) + '</div>';
      bkHtml += '<ul class="kpc-recap__list">';
      for (var j2 = 0; j2 < sectionMap[sTitle].length; j2++) {
        var rowS   = sectionMap[sTitle][j2];
        var rMetaS = rowS.meta || {};
        var hlS    = (rowS.value != null) ? escHtml(fmt(rowS.value, 1, 'HL')) + ' HL' : '—';
        // loss% at 2 dp — server returns rMetaS.loss_pct already rounded to 2dp.
        // Canonical label = "perte bière" (NOT "perte liquide" — that names form dispositions).
        var lossStrS = '';
        if (rMetaS.loss_pct != null) {
          lossStrS = ' <span class="kpc-recap__loss" title="Perte bière (bière perdue / vendable)">'
            + escHtml(fmt(rMetaS.loss_pct, 2, '%')) + '&thinsp;% perte</span>';
        }
        // reach% vs objective: show "92,0% obj." when set, "—" when no objective
        var reachStrS = '';
        if (rMetaS.objective_hl != null && rMetaS.objective_hl > 0) {
          var reachValS = rMetaS.reach_pct != null ? escHtml(fmt(rMetaS.reach_pct, 1, '%')) + '% obj.' : '—';
          reachStrS = ' <span class="kpc-recap__reach">' + reachValS + '</span>';
        }
        bkHtml +=
          '<li class="kpc-recap__row">'
          + '<span class="kpc-recap__row-lbl">' + recapRowLabel(rowS) + '</span>'
          + '<span class="kpc-recap__row-val">' + hlS + lossStrS + reachStrS + '</span>'
          + '</li>';
      }
      bkHtml += '</ul>';
    }
    // Catch any run_type not in SECTION_ORDER (future-proof)
    if (sectionMap['Autre'] && sectionMap['Autre'].length > 0) {
      bkHtml += '<div class="kpc-recap__section-title">Autre</div>';
      bkHtml += '<ul class="kpc-recap__list">';
      for (var jx = 0; jx < sectionMap['Autre'].length; jx++) {
        var rowX   = sectionMap['Autre'][jx];
        var rMetaX = rowX.meta || {};
        var hlX    = (rowX.value != null) ? escHtml(fmt(rowX.value, 1, 'HL')) + ' HL' : '—';
        var lossStrX = '';
        if (rMetaX.loss_pct != null) {
          lossStrX = ' <span class="kpc-recap__loss" title="Perte bière (bière perdue / vendable)">'
            + escHtml(fmt(rMetaX.loss_pct, 2, '%')) + '&thinsp;% perte</span>';
        }
        bkHtml +=
          '<li class="kpc-recap__row">'
          + '<span class="kpc-recap__row-lbl">' + recapRowLabel(rowX) + '</span>'
          + '<span class="kpc-recap__row-val">' + hlX + lossStrX + '</span>'
          + '</li>';
      }
      bkHtml += '</ul>';
    }
  }

  // Wort recap: brassins par (recette, lot)
  if (brewRows.length) {
    bkHtml += '<div class="kpc-recap__section-title">Brassins</div>';
    bkHtml += '<ul class="kpc-recap__list">';
    for (var b = 0; b < brewRows.length; b++) {
      var brow = brewRows[b];
      var bHl  = (brow.value != null) ? escHtml(fmt(brow.value, 1, 'HL')) + ' HL' : '—';
      bkHtml +=
        '<li class="kpc-recap__row">'
        + '<span class="kpc-recap__row-lbl">' + recapRowLabel(brow) + '</span>'
        + '<span class="kpc-recap__row-val">' + bHl + '</span>'
        + '</li>';
    }
    bkHtml += '</ul>';
  }

  // Wort recap: transferts par (recette, lot)
  if (rackRows.length) {
    bkHtml += '<div class="kpc-recap__section-title">Transferts</div>';
    bkHtml += '<ul class="kpc-recap__list">';
    for (var rr = 0; rr < rackRows.length; rr++) {
      var rarow = rackRows[rr];
      var raHl  = (rarow.value != null) ? escHtml(fmt(rarow.value, 1, 'HL')) + ' HL' : '—';
      bkHtml +=
        '<li class="kpc-recap__row">'
        + '<span class="kpc-recap__row-lbl">' + recapRowLabel(rarow) + '</span>'
        + '<span class="kpc-recap__row-val">' + raHl + '</span>'
        + '</li>';
    }
    bkHtml += '</ul>';
  }

  // Dry-hop per lot (wort recap)
  if (dhRows.length) {
    bkHtml += '<div class="kpc-recap__section-title">Dry-hop</div>';
    bkHtml += '<ul class="kpc-recap__list">';
    for (var dh = 0; dh < dhRows.length; dh++) {
      var dhrow = dhRows[dh];
      bkHtml +=
        '<li class="kpc-recap__row">'
        + '<span class="kpc-recap__row-lbl">' + recapRowLabel(dhrow) + '</span>'
        + '<span class="kpc-recap__row-val">' + escHtml(String(dhrow.value || 0)) + ' événement(s)</span>'
        + '</li>';
    }
    bkHtml += '</ul>';
  }

  // Cold crash per lot (wort recap)
  if (ccRows.length) {
    bkHtml += '<div class="kpc-recap__section-title">Cold crash</div>';
    bkHtml += '<ul class="kpc-recap__list">';
    for (var cc = 0; cc < ccRows.length; cc++) {
      var ccrow = ccRows[cc];
      bkHtml +=
        '<li class="kpc-recap__row">'
        + '<span class="kpc-recap__row-lbl">' + recapRowLabel(ccrow) + '</span>'
        + '<span class="kpc-recap__row-val">' + escHtml(String(ccrow.value || 0)) + ' événement(s)</span>'
        + '</li>';
    }
    bkHtml += '</ul>';
  }

  // Quality moût: per-brassin OG, pH, duration + rolling variation (#3, 2026-06-10).
  // Columns: OG (°P), pH moût, Durée (h), variation vs 10 derniers brassins du même recette.
  // NOT: FG, ABV, OG target (no source); never fabricate unavailable metrics.
  if (qualityRows.length) {
    bkHtml += '<div class="kpc-recap__section-title">Qualité moût</div>';
    bkHtml += '<ul class="kpc-recap__list">';
    for (var qi = 0; qi < qualityRows.length; qi++) {
      var qrow  = qualityRows[qi];
      var qm    = qrow.meta || {};
      var qLbl  = recapRowLabel(qrow);
      // Build quality chips: show each metric only when populated
      var qChips = '';
      if (qm.og_plato != null) {
        qChips += '<span class="kpc-recap__quality-chip" title="OG moyen (densité initiale moût, °Plato — NOT densité finale)">'
          + 'OG&nbsp;' + escHtml(fmt(qm.og_plato, 2, '')) + '&thinsp;°P</span>';
      }
      if (qm.ph_mout != null) {
        qChips += '<span class="kpc-recap__quality-chip" title="pH moût (post-ébullition, mesure Cooling)">'
          + 'pH&nbsp;' + escHtml(fmt(qm.ph_mout, 2, '')) + '</span>';
      }
      if (qm.duration_h != null) {
        qChips += '<span class="kpc-recap__quality-chip" title="Durée moyenne par brassin (brew_start→brew_end)">'
          + escHtml(fmt(qm.duration_h, 2, '')) + '&thinsp;h</span>';
      }
      // Rolling variation vs last N brews of same recipe (CHANGE 2, replaces intra-day CV).
      // rolling_var_pct: signed %; null = 0 priors → "—"; rolling_n: actual prior count.
      // Show only when duration is available (else no meaningful comparison).
      if (qm.duration_h != null) {
        var rollingN   = qm.rolling_n != null ? qm.rolling_n : 0;
        var rollingLbl = rollingN >= 10 ? 'vs 10 derniers' : ('vs ' + rollingN + ' derniers');
        var rollingDisplay;
        if (qm.rolling_var_pct != null) {
          // Signed format: "+4,2 %" or "−3,1 %"; use HTML minus (−) for negatives.
          var varSign = qm.rolling_var_pct >= 0 ? '+' : '−';
          rollingDisplay = varSign
            + escHtml(fmt(Math.abs(qm.rolling_var_pct), 1, '')) + '&thinsp;%';
          if (rollingN < 10 && rollingN > 0) {
            rollingDisplay += ' <span class="kpc-recap__quality-n">(' + rollingN + ' brassins)</span>';
          }
        } else {
          rollingDisplay = '—';
        }
        var varChipCls = (qm.rolling_var_pct != null && Math.abs(qm.rolling_var_pct) > 10)
          ? ' kpc-recap__quality-chip--var kpc-recap__quality-chip--alert'
          : ' kpc-recap__quality-chip--var';
        qChips += '<span class="kpc-recap__quality-chip' + varChipCls + '" title="Variation vs 10 derniers brassins de la même recette">'
          + escHtml(rollingLbl) + '&nbsp;' + rollingDisplay + '</span>';
      }
      bkHtml +=
        '<li class="kpc-recap__row kpc-recap__row--quality">'
        + '<span class="kpc-recap__row-lbl">' + qLbl + '</span>'
        + '<span class="kpc-recap__row-val kpc-recap__row-val--quality">' + (qChips || '—') + '</span>'
        + '</li>';
    }
    bkHtml += '</ul>';
  }

  if (matRows.length) {
    bkHtml += '<div class="kpc-recap__section-title">Pertes matières</div>';
    bkHtml += '<ul class="kpc-recap__list">';
    for (var k = 0; k < matRows.length; k++) {
      var mrow  = matRows[k];
      var mmeta = mrow.meta || {};
      var mLbl  = KPC_RECAP_MAT_LABELS[mrow.key] || escHtml(mrow.label);
      var mVal  = (mrow.value != null) ? escHtml(fmt(mrow.value, 0, '')) : '—';
      var mUnit = mrow.unit ? ' ' + escHtml(mrow.unit) : '';
      var rateBadge = '';
      if (mmeta.rate_type === 'pending') {
        rateBadge = ' <span class="kpc-recap__badge kpc-recap__badge--pending">taux en attente</span>';
      } else if (mmeta.rate_pct != null) {
        rateBadge = ' <span class="kpc-recap__rate">' + escHtml(fmt(mmeta.rate_pct, 1, '%')) + ' %</span>';
      }
      bkHtml +=
        '<li class="kpc-recap__row">'
        + '<span class="kpc-recap__row-lbl">' + escHtml(mLbl) + '</span>'
        + '<span class="kpc-recap__row-val">' + mVal + mUnit + rateBadge + '</span>'
        + '</li>';
    }
    bkHtml += '</ul>';
  }

  // Empty-state for no activity today
  var hasAnyRows = runRows.length || matRows.length || brewRows.length || rackRows.length || dhRows.length || ccRows.length;
  if (!hasAnyRows && sections.length === 0) {
    bkHtml = '<div class="kpc-no-data">Aucune activité aujourd\'hui</div>';
  }

  container.innerHTML =
    '<div class="kpc-card__label">' + label + subtitle + '</div>'
    + (sectHtml ? '<div class="kpc-recap__metrics">' + sectHtml + '</div>' : '')
    + (bkHtml   ? '<div class="kpc-recap__body">'   + bkHtml   + '</div>' : '');
}


/* ── Public exports (consumed by wort-kpis.js and mon-tableau) ── */
window.KpcCharts = {
  escHtml:             escHtml,
  fmt:                 fmt,
  svgEl:               svgEl,
  buildBarChart:       buildBarChart,
  buildSparkSvg:       buildSparkSvg,
  paceTintResult:      paceTintResult,
  renderKpiCard:        renderKpiCard,
  renderKpiRecap:       renderKpiRecap,
  renderKpiGroupedBar:       renderKpiGroupedBar,
  renderKpiStackedColumns:   renderKpiStackedColumns,
  kpcTok:               kpcTok,
  KPC_MONTHS_FR:        KPC_MONTHS_FR,
  KPC_RECAP_MAT_LABELS: KPC_RECAP_MAT_LABELS,
};
