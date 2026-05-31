/* ═══════════════════════════════════════════════════════════
   packaging.js — Packaging KPI dashboard (KPIs 1–4)
   Data source: window.PKG_STATS (server-injected by packaging.php)
   Chart method: hand-rolled inline SVG (same technique as wort-kpis.js).
   No external chart library.
   ═══════════════════════════════════════════════════════════ */

'use strict';

/* ── XSS guard ── */
function pkgEscHtml(s) {
  if (s == null) return '';
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/* ── Number formatter (FR-CH) ── */
function pkgFmt(n, dec) {
  if (n == null) return '0';
  dec = (dec == null) ? 1 : dec;
  return Number(n).toLocaleString('fr-CH', {
    minimumFractionDigits: dec,
    maximumFractionDigits: dec,
  });
}

/* ── SVG element factory ── */
function pkgSvgEl(tag, attrs) {
  const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
  for (const k in (attrs || {})) el.setAttribute(k, attrs[k]);
  return el;
}

/* ── French month short labels ── */
const PKG_MONTHS_FR = ['jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];

/* ── Palette — read from CSS tokens at runtime ── */
const _CS2 = getComputedStyle(document.documentElement);
function pkgTok(name) { return _CS2.getPropertyValue(name).trim(); }
const PKG_C = {
  hop:      pkgTok('--hop')       || '#567020',
  cold:     pkgTok('--cold')      || '#2f5575',
  ember:    pkgTok('--ember')     || '#b34428',
  oak:      pkgTok('--oak')       || '#8b5e2a',
  steel:    pkgTok('--steel')     || '#8a8f94',
  steel_mid:pkgTok('--steel-mid') || '#5e6266',
  hairline: pkgTok('--hairline')  || '#c8b48a',
  bg:       pkgTok('--bg')        || '#f1e8d4',
  bg_elev:  pkgTok('--bg-elev')   || '#ece0c6',
  ink_faint:pkgTok('--ink-faint') || '#c8b48a',
  ink_mute: pkgTok('--ink-mute')  || '#4a3820',
  ink_soft: pkgTok('--ink-soft')  || '#3a2a18',
};

/* ── Format-family display map — the ONE canonical map (run_type → label FR) ──
   can + can33 both map to Canette; never collapse cuv into keg. */
const PKG_FORMAT_FAMILY = {
  bot:   'Bouteille',
  can:   'Canette',
  can33: 'Canette',
  keg:   'Fût',
  cuv:   'Cuve de service',
};

/* ── Format-family colors (one per display family) ── */
const PKG_FORMAT_COLOR = {
  Bouteille:        PKG_C.hop,
  Canette:          PKG_C.ember,
  'Fût':            PKG_C.oak,
  'Cuve de service':PKG_C.steel_mid,
};

/* ── SKU palette — deterministic color per SKU code ── */
const PKG_SKU_PALETTE = [
  '#567020','#8b5e2a','#2f5575','#b34428','#3d6826',
  '#5a4a8c','#2d7a88','#2f6d99','#7a5c30','#4a7a3a',
  '#8a5210','#3d4a8c','#2f7d45','#7a2f25','#5a6820',
  '#4a3880','#2a6878','#8a6030','#3a7050','#5a2830',
];
function pkgSkuColor(sku) {
  let h = 0;
  for (let i = 0; i < sku.length; i++) h = ((h << 5) - h) + sku.charCodeAt(i);
  return PKG_SKU_PALETTE[Math.abs(h) % PKG_SKU_PALETTE.length];
}

/* ═══════════════════════════════════════════════════════════
   TOOLTIP
   ═══════════════════════════════════════════════════════════ */
const pkgTip = document.getElementById('pkg-tooltip');
function pkgShowTip(e, html) {
  if (!pkgTip) return;
  pkgTip.innerHTML = html;
  pkgTip.classList.add('visible');
  pkgMoveTip(e);
}
function pkgMoveTip(e) {
  if (!pkgTip) return;
  const x = e.clientX + 14, y = e.clientY - 10;
  pkgTip.style.left = Math.min(x, window.innerWidth - pkgTip.offsetWidth - 8) + 'px';
  pkgTip.style.top  = Math.max(8, y) + 'px';
}
function pkgHideTip() { if (pkgTip) pkgTip.classList.remove('visible'); }

/* ═══════════════════════════════════════════════════════════
   SVG STACKED BAR CHART
   Renders 12 month columns, each column = stacked segments per series.
   series: array of { key, color, label }
   data:   array of 12 elements, each an object keyed by series.key → hl value
   ═══════════════════════════════════════════════════════════ */
function pkgBuildStackedBarChart(container, data12, series) {
  /* Compute per-month totals for scale */
  const totals = data12.map(function(m) {
    return series.reduce(function(s, sr) { return s + (m[sr.key] || 0); }, 0);
  });
  const maxVal = Math.max.apply(null, totals.concat([1]));
  const gridTop = Math.ceil(maxVal / 50) * 50 || 50;

  const W = 840, H = 280;
  const padL = 44, padR = 12, padT = 14, padB = 34;
  const chartW = W - padL - padR;
  const chartH = H - padT - padB;

  function yScale(v) { return chartH * (1 - v / gridTop); }

  const svg = pkgSvgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img' });

  /* Grid lines */
  const gridSteps = 5;
  for (let i = 0; i <= gridSteps; i++) {
    const v = gridTop * i / gridSteps;
    const y = padT + yScale(v);
    svg.appendChild(pkgSvgEl('line', {
      x1: padL, y1: y, x2: W - padR, y2: y,
      stroke: PKG_C.hairline,
      'stroke-width': i === 0 ? 1.5 : 0.5,
      opacity: i === 0 ? 0.9 : 0.5,
    }));
    if (i > 0) {
      const lbl = pkgSvgEl('text', {
        x: padL - 5, y: y + 4,
        'text-anchor': 'end', fill: PKG_C.ink_faint,
        'font-family': 'JetBrains Mono,monospace', 'font-size': 9, 'letter-spacing': '0.04em',
      });
      lbl.textContent = v >= 1000 ? (v / 1000).toFixed(1) + 'k' : v.toFixed(0);
      svg.appendChild(lbl);
    }
  }

  /* Y-axis label */
  const yAxisLbl = pkgSvgEl('text', {
    x: 10, y: padT + chartH / 2, 'text-anchor': 'middle', fill: PKG_C.ink_faint,
    'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'letter-spacing': '0.1em',
    transform: 'rotate(-90, 10, ' + (padT + chartH / 2) + ')',
  });
  yAxisLbl.textContent = 'HL';
  svg.appendChild(yAxisLbl);

  const monthW = chartW / 12;
  const gap = monthW * 0.14;
  const barW = monthW - gap * 2;

  data12.forEach(function(mData, mi) {
    const xBase = padL + mi * monthW + gap;
    const total = totals[mi];

    /* Month label */
    const lbl = pkgSvgEl('text', {
      x: padL + mi * monthW + monthW / 2,
      y: H - padB + 14,
      'text-anchor': 'middle', fill: PKG_C.ink_faint,
      'font-family': 'JetBrains Mono,monospace', 'font-size': 9, 'letter-spacing': '0.06em',
    });
    lbl.textContent = PKG_MONTHS_FR[mi];
    svg.appendChild(lbl);

    if (total === 0) {
      /* Stub tick for empty months */
      svg.appendChild(pkgSvgEl('rect', {
        x: xBase, y: padT + chartH - 1,
        width: Math.max(barW, 4), height: 1,
        fill: PKG_C.hairline, opacity: 0.3, rx: 1,
      }));
      return;
    }

    /* Stack segments bottom-up */
    let yBase = padT + chartH; /* bottom of chart area */
    series.forEach(function(sr) {
      const val = mData[sr.key] || 0;
      if (val <= 0) return;
      const bh = yScale(0) - yScale(val);
      const by = yBase - bh;
      const rect = pkgSvgEl('rect', {
        x: xBase, y: by,
        width: Math.max(barW, 4), height: Math.max(bh, 1),
        fill: sr.color, rx: 2,
      });
      rect.addEventListener('mouseenter', function(e) {
        pkgShowTip(e,
          '<strong>' + PKG_MONTHS_FR[mi].toUpperCase() + '</strong> · ' +
          pkgEscHtml(sr.label) + ' : <strong>' + pkgFmt(val) + ' HL</strong>' +
          (series.length > 1 ? '<br><span style="color:' + PKG_C.ink_faint + '">Total : ' + pkgFmt(total) + ' HL</span>' : '')
        );
      });
      rect.addEventListener('mousemove', pkgMoveTip);
      rect.addEventListener('mouseleave', pkgHideTip);
      svg.appendChild(rect);
      yBase = by;
    });

    /* Total label above the tallest bar (only if bar is tall enough) */
    const totalBarH = yScale(0) - yScale(total);
    if (totalBarH > 18) {
      const topY = padT + yScale(total);
      const vlbl = pkgSvgEl('text', {
        x: xBase + barW / 2, y: topY - 3,
        'text-anchor': 'middle', fill: PKG_C.ink_mute,
        'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'font-weight': 500,
      });
      vlbl.textContent = total >= 100 ? Math.round(total) : pkgFmt(total, 1);
      svg.appendChild(vlbl);
    }
  });

  container.innerHTML = '';
  container.appendChild(svg);
}

/* ═══════════════════════════════════════════════════════════
   Build 12-slot data array from sparse server rows
   data: list of {mo:int(1-12), [key]:value, ...}
   keys: array of keys to copy per month
   ═══════════════════════════════════════════════════════════ */
function pkgBuild12(rows, keyMap) {
  /* keyMap: { srcKey: destKey } or just array of keys (src=dest) */
  const result = [];
  for (let i = 0; i < 12; i++) result.push({});
  rows.forEach(function(row) {
    const idx = row.mo - 1;
    if (idx < 0 || idx > 11) return;
    if (Array.isArray(keyMap)) {
      keyMap.forEach(function(k) { result[idx][k] = (result[idx][k] || 0) + (row[k] || 0); });
    } else {
      for (const src in keyMap) {
        const dest = keyMap[src];
        result[idx][dest] = (result[idx][dest] || 0) + (row[src] || 0);
      }
    }
  });
  return result;
}

/* ═══════════════════════════════════════════════════════════
   Legend builder (shared by KPI 2 SKU chart)
   ═══════════════════════════════════════════════════════════ */
function pkgBuildLegend(container, series, yearTotal, events) {
  const wrap = document.createElement('div');
  wrap.className = 'pkg-kpi-legend';

  if (yearTotal != null) {
    const tot = document.createElement('div');
    tot.className = 'pkg-kpi-legend__total';
    tot.innerHTML =
      '<span class="pkg-kpi-legend__total-num">' + pkgEscHtml(pkgFmt(yearTotal)) + ' HL</span>' +
      (events != null ? '<span class="pkg-kpi-legend__total-ev"> · ' + pkgEscHtml(String(events)) + ' lots</span>' : '');
    wrap.appendChild(tot);
  }

  const list = document.createElement('div');
  list.className = 'pkg-kpi-legend__list';

  series.forEach(function(sr) {
    const item = document.createElement('span');
    item.className = 'pkg-kpi-legend__item';
    item.innerHTML =
      '<span class="pkg-kpi-legend__swatch" style="background:' + pkgEscHtml(sr.color) + '"></span>' +
      '<span class="pkg-kpi-legend__label">' + pkgEscHtml(sr.label) + '</span>' +
      (sr.hl != null ? '<span class="pkg-kpi-legend__hl">' + pkgEscHtml(pkgFmt(sr.hl)) + '</span>' : '');
    list.appendChild(item);
  });

  wrap.appendChild(list);
  container.innerHTML = '';
  container.appendChild(wrap);
}

/* ═══════════════════════════════════════════════════════════
   KPI section header builder
   ═══════════════════════════════════════════════════════════ */
function pkgSectionHead(titleHtml, subtitleHtml) {
  const h = document.createElement('div');
  h.className = 'pkg-kpi-section__head';
  h.innerHTML =
    '<h3 class="pkg-kpi-section__title">' + titleHtml + '</h3>' +
    (subtitleHtml ? '<span class="pkg-kpi-section__sub">' + subtitleHtml + '</span>' : '');
  return h;
}

/* ═══════════════════════════════════════════════════════════
   Empty state renderer
   ═══════════════════════════════════════════════════════════ */
function pkgEmptyState(container, msg) {
  container.innerHTML = '<p class="pkg-kpi-empty">' + pkgEscHtml(msg) + '</p>';
}

/* ═══════════════════════════════════════════════════════════
   KPI 1 — Total Nébuleuse HL par mois
   ═══════════════════════════════════════════════════════════ */
function pkgRenderKpi1(stats) {
  const wrap = document.getElementById('pkg-kpi-1');
  if (!wrap) return;
  wrap.innerHTML = '';

  const rows = stats.neb_hl_by_month || [];
  if (rows.length === 0) {
    wrap.appendChild(pkgSectionHead('HL Nébuleuse par mois', null));
    pkgEmptyState(wrap, 'Aucune donnée Nébuleuse pour cette année.');
    return;
  }

  const yearTotal = rows.reduce(function(s, r) { return s + r.hl; }, 0);
  const yearEvents = rows.reduce(function(s, r) { return s + r.events; }, 0);

  wrap.appendChild(pkgSectionHead(
    'HL Nébuleuse par mois',
    pkgEscHtml(pkgFmt(yearTotal)) + ' HL · ' + pkgEscHtml(String(yearEvents)) + ' lots'
  ));

  const data12 = pkgBuild12(rows, ['hl']);
  const chartWrap = document.createElement('div');
  chartWrap.className = 'pkg-kpi-chart';
  wrap.appendChild(chartWrap);

  pkgBuildStackedBarChart(chartWrap, data12, [
    { key: 'hl', color: PKG_C.hop, label: 'Nébuleuse' },
  ]);
}

/* ═══════════════════════════════════════════════════════════
   KPI 2 — Nébuleuse HL par SKU × mois — HEATMAP GRID
   One row per SKU; 12 real equal-width month columns.
   Cell darkness = HL intensity (0→light, max→full --hop).
   Top 15 by year total + "Autres" fold row.
   ═══════════════════════════════════════════════════════════ */

/* Full French month names for tooltips */
const PKG_MONTHS_FULL = [
  'Janvier','Février','Mars','Avril','Mai','Juin',
  'Juillet','Août','Septembre','Octobre','Novembre','Décembre',
];

function pkgRenderKpi2(stats) {
  var wrap = document.getElementById('pkg-kpi-2');
  if (!wrap) return;
  wrap.innerHTML = '';

  var rows = stats.neb_hl_by_sku_month || [];
  if (rows.length === 0) {
    wrap.appendChild(pkgSectionHead('HL Nébuleuse par SKU', null));
    pkgEmptyState(wrap, 'Aucune donnée SKU pour cette année.');
    return;
  }

  /* Build per-SKU totals + monthly breakdown + event counts */
  var skuTotals  = {};
  var skuEvents  = {};
  var skuByMonth = {};   /* { sku_code: [0..11 => hl] } */
  var skuEventsByMonth = {}; /* { sku_code: [0..11 => events] } */

  rows.forEach(function(r) {
    skuTotals[r.sku_code] = (skuTotals[r.sku_code] || 0) + r.hl;
    skuEvents[r.sku_code] = (skuEvents[r.sku_code] || 0) + r.events;
    if (!skuByMonth[r.sku_code]) {
      skuByMonth[r.sku_code]       = [0,0,0,0,0,0,0,0,0,0,0,0];
      skuEventsByMonth[r.sku_code] = [0,0,0,0,0,0,0,0,0,0,0,0];
    }
    var idx = r.mo - 1;
    if (idx >= 0 && idx < 12) {
      skuByMonth[r.sku_code][idx]       += r.hl;
      skuEventsByMonth[r.sku_code][idx] += r.events;
    }
  });

  var TOP_N       = 15;
  var sortedSkus  = Object.keys(skuTotals).sort(function(a, b) { return skuTotals[b] - skuTotals[a]; });
  var topSkus     = sortedSkus.slice(0, TOP_N);
  var otherSkus   = sortedSkus.slice(TOP_N);
  var autresTotal = otherSkus.reduce(function(s, k) { return s + skuTotals[k]; }, 0);

  var yearTotal  = Object.values(skuTotals).reduce(function(s, v) { return s + v; }, 0);
  var yearEvents = Object.values(skuEvents).reduce(function(s, v) { return s + v; }, 0);
  var nDisplayed = topSkus.length + (autresTotal > 0 ? 1 : 0);

  wrap.appendChild(pkgSectionHead(
    'HL Nébuleuse par SKU',
    pkgEscHtml(pkgFmt(yearTotal)) + ' HL · ' + pkgEscHtml(String(yearEvents)) + ' lots · ' +
    nDisplayed + ' lignes'
  ));

  /* Build "Autres" aggregated month breakdown */
  var autresByMonth       = [0,0,0,0,0,0,0,0,0,0,0,0];
  var autresEventsByMonth = [0,0,0,0,0,0,0,0,0,0,0,0];
  otherSkus.forEach(function(sku) {
    var mo = skuByMonth[sku] || [];
    var ev = skuEventsByMonth[sku] || [];
    mo.forEach(function(v, i) { autresByMonth[i] += v; });
    ev.forEach(function(v, i) { autresEventsByMonth[i] += v; });
  });

  /* Compile display rows */
  var heatRows = topSkus.map(function(sku) {
    return {
      label:     sku,
      total:     skuTotals[sku],
      mo:        skuByMonth[sku]       || [0,0,0,0,0,0,0,0,0,0,0,0],
      ev:        skuEventsByMonth[sku] || [0,0,0,0,0,0,0,0,0,0,0,0],
      isAutres:  false,
    };
  });
  if (autresTotal > 0) {
    heatRows.push({
      label:    'Autres (' + otherSkus.length + ' SKUs)',
      total:    autresTotal,
      mo:       autresByMonth,
      ev:       autresEventsByMonth,
      isAutres: true,
    });
  }

  /* Max single-cell HL value — drives the intensity ramp */
  var maxCellHl = 0;
  heatRows.forEach(function(r) {
    r.mo.forEach(function(v) { if (v > maxCellHl) maxCellHl = v; });
  });
  if (maxCellHl < 1) maxCellHl = 1;

  /* Build HTML table */
  var table = document.createElement('table');
  table.className = 'pkg-skuheat';
  table.setAttribute('role', 'grid');
  table.setAttribute('aria-label', 'HL Nébuleuse par SKU et par mois');

  /* Header row */
  var thead = document.createElement('thead');
  var headTr = document.createElement('tr');

  /* SKU header cell (blank — the label column) */
  var thSku = document.createElement('th');
  thSku.className = 'pkg-skuheat__th-sku';
  thSku.scope = 'col';
  headTr.appendChild(thSku);

  /* 12 month header cells */
  PKG_MONTHS_FR.forEach(function(mo) {
    var th = document.createElement('th');
    th.className = 'pkg-skuheat__th-mo';
    th.scope = 'col';
    th.textContent = mo;
    headTr.appendChild(th);
  });

  /* "Total" header */
  var thTot = document.createElement('th');
  thTot.className = 'pkg-skuheat__th-tot';
  thTot.scope = 'col';
  thTot.textContent = 'total';
  headTr.appendChild(thTot);

  thead.appendChild(headTr);
  table.appendChild(thead);

  /* Data rows */
  var tbody = document.createElement('tbody');

  heatRows.forEach(function(row) {
    var tr = document.createElement('tr');
    tr.className = row.isAutres ? 'pkg-skuheat__row pkg-skuheat__row--autres' : 'pkg-skuheat__row';

    /* SKU label cell */
    var tdLbl = document.createElement('td');
    tdLbl.className = 'pkg-skuheat__sku';
    tdLbl.textContent = row.label;
    tr.appendChild(tdLbl);

    /* 12 month cells */
    row.mo.forEach(function(hl, mi) {
      var td = document.createElement('td');
      td.className = 'pkg-skuheat__cell';

      if (hl > 0) {
        /* intensity 0..1 relative to grid max */
        var intensity = hl / maxCellHl;

        /* Background: color-mix of --hop at computed alpha into --bg-elev.
           We set a CSS custom property on the element and let the stylesheet
           use it — but color-mix() with a JS-computed percentage is simpler
           as a direct style assignment since it's dynamic data. */
        td.style.background = 'color-mix(in srgb, var(--hop) ' +
          Math.round(intensity * 82 + 6) + '%, var(--bg-elev))';

        /* Text contrast: switch ink colour at ~45% intensity threshold */
        td.style.color = intensity > 0.45 ? 'var(--bg)' : 'var(--ink-soft)';

        /* HL value — integer if ≥10, one decimal if <10 */
        var hlLabel = hl >= 10 ? String(Math.round(hl)) : pkgFmt(hl, 1);
        td.textContent = hlLabel;

        /* Tooltip */
        (function(skuLabel, moIdx, hlVal, evVal) {
          td.addEventListener('mouseenter', function(e) {
            pkgShowTip(e,
              '<strong>' + pkgEscHtml(skuLabel) + '</strong><br>' +
              pkgEscHtml(PKG_MONTHS_FULL[moIdx]) + '<br>' +
              '<strong>' + pkgEscHtml(pkgFmt(hlVal, 1)) + ' HL</strong>' +
              (evVal > 0 ? ' · ' + pkgEscHtml(String(evVal)) + ' lot' + (evVal > 1 ? 's' : '') : '')
            );
          });
          td.addEventListener('mousemove', pkgMoveTip);
          td.addEventListener('mouseleave', pkgHideTip);
        })(row.label, mi, hl, row.ev[mi] || 0);
      }

      tr.appendChild(td);
    });

    /* Total cell */
    var tdTot = document.createElement('td');
    tdTot.className = 'pkg-skuheat__total';
    tdTot.textContent = pkgFmt(row.total, 1);
    tr.appendChild(tdTot);

    tbody.appendChild(tr);
  });

  table.appendChild(tbody);

  /* Scroll wrapper (overflow-x on narrow viewports) */
  var scrollWrap = document.createElement('div');
  scrollWrap.className = 'pkg-skuheat__scroll';
  scrollWrap.appendChild(table);
  wrap.appendChild(scrollWrap);

  /* "(SKU manquant)" honesty note */
  if (skuTotals['(SKU manquant)']) {
    var note = document.createElement('p');
    note.className = 'pkg-kpi-note';
    note.textContent = '⚠ ' + pkgFmt(skuTotals['(SKU manquant)']) + ' HL sans SKU résolu.';
    wrap.appendChild(note);
  }
}

/* ═══════════════════════════════════════════════════════════
   KPI 3 — Nébuleuse HL par format par mois
   ═══════════════════════════════════════════════════════════ */
function pkgRenderKpi3(stats) {
  const wrap = document.getElementById('pkg-kpi-3');
  if (!wrap) return;
  wrap.innerHTML = '';

  const rows = stats.neb_hl_by_format_month || [];
  if (rows.length === 0) {
    wrap.appendChild(pkgSectionHead('HL Nébuleuse par format', null));
    pkgEmptyState(wrap, 'Aucune donnée format pour cette année.');
    return;
  }

  /* Aggregate can+can33 into Canette family */
  const familyRows = rows.map(function(r) {
    return {
      mo:      r.mo,
      family:  PKG_FORMAT_FAMILY[r.run_type] || r.run_type,
      hl:      r.hl,
      events:  r.events,
    };
  });

  /* Distinct families present in data */
  const familySet = {};
  familyRows.forEach(function(r) {
    familySet[r.family] = (familySet[r.family] || 0) + r.hl;
  });
  const families = Object.keys(familySet).sort(function(a, b) {
    return familySet[b] - familySet[a];
  });

  const yearTotal = Object.values(familySet).reduce(function(s, v) { return s + v; }, 0);
  const yearEvents = rows.reduce(function(s, r) { return s + r.events; }, 0);

  wrap.appendChild(pkgSectionHead(
    'HL Nébuleuse par format',
    pkgEscHtml(pkgFmt(yearTotal)) + ' HL · ' + pkgEscHtml(String(yearEvents)) + ' lots'
  ));

  /* Build data12: one key per display family */
  const data12 = [];
  for (let i = 0; i < 12; i++) {
    const slot = {};
    families.forEach(function(f) { slot[f] = 0; });
    data12.push(slot);
  }
  familyRows.forEach(function(r) {
    const idx = r.mo - 1;
    if (idx < 0 || idx > 11) return;
    data12[idx][r.family] = (data12[idx][r.family] || 0) + r.hl;
  });

  const series = families.map(function(f) {
    return { key: f, color: PKG_FORMAT_COLOR[f] || PKG_C.steel_mid, label: f };
  });

  const chartWrap = document.createElement('div');
  chartWrap.className = 'pkg-kpi-chart';
  wrap.appendChild(chartWrap);

  pkgBuildStackedBarChart(chartWrap, data12, series);

  const legendSeries = families.map(function(f) {
    return { color: PKG_FORMAT_COLOR[f] || PKG_C.steel_mid, label: f, hl: familySet[f] };
  });
  const legendWrap = document.createElement('div');
  wrap.appendChild(legendWrap);
  pkgBuildLegend(legendWrap, legendSeries, null, null);
}

/* ═══════════════════════════════════════════════════════════
   KPI 4 — Contract HL par format par mois (own section)
   ═══════════════════════════════════════════════════════════ */
function pkgRenderKpi4(stats) {
  const wrap = document.getElementById('pkg-kpi-4');
  if (!wrap) return;
  wrap.innerHTML = '';

  const rows = stats.contract_hl_by_format_month || [];
  const yearTotal = rows.reduce(function(s, r) { return s + r.hl; }, 0);
  const yearEvents = rows.reduce(function(s, r) { return s + r.events; }, 0);

  /* Section head — always render, even for empty year */
  const contractHeadEl = document.createElement('div');
  contractHeadEl.className = 'pkg-kpi-section__contract-head';
  contractHeadEl.innerHTML =
    '<span class="pkg-kpi-section__contract-badge">Contrat</span>' +
    '<h3 class="pkg-kpi-section__title">HL Contract par format</h3>' +
    (yearTotal > 0
      ? '<span class="pkg-kpi-section__sub">' +
          pkgEscHtml(pkgFmt(yearTotal)) + ' HL · ' + pkgEscHtml(String(yearEvents)) + ' lots</span>'
      : '');
  wrap.appendChild(contractHeadEl);

  if (rows.length === 0 || yearTotal === 0) {
    pkgEmptyState(wrap, 'Aucun conditionnement Contract enregistré pour cette année.');
    return;
  }

  /* Aggregate into format families */
  const familyRows = rows.map(function(r) {
    return {
      mo:     r.mo,
      family: PKG_FORMAT_FAMILY[r.run_type] || r.run_type,
      hl:     r.hl,
      events: r.events,
    };
  });

  const familySet = {};
  familyRows.forEach(function(r) {
    familySet[r.family] = (familySet[r.family] || 0) + r.hl;
  });
  const families = Object.keys(familySet).sort(function(a, b) {
    return familySet[b] - familySet[a];
  });

  const data12 = [];
  for (let i = 0; i < 12; i++) {
    const slot = {};
    families.forEach(function(f) { slot[f] = 0; });
    data12.push(slot);
  }
  familyRows.forEach(function(r) {
    const idx = r.mo - 1;
    if (idx < 0 || idx > 11) return;
    data12[idx][r.family] = (data12[idx][r.family] || 0) + r.hl;
  });

  /* Contract uses cooler blue tones to visually separate from Neb */
  const CONTRACT_COLOR = {
    Bouteille:        '#2f5575',
    Canette:          '#2d7a88',
    'Fût':            '#2f6d99',
    'Cuve de service':'#4a5a78',
  };

  const series = families.map(function(f) {
    return { key: f, color: CONTRACT_COLOR[f] || PKG_C.cold, label: f };
  });

  const chartWrap = document.createElement('div');
  chartWrap.className = 'pkg-kpi-chart';
  wrap.appendChild(chartWrap);

  pkgBuildStackedBarChart(chartWrap, data12, series);

  const legendSeries = families.map(function(f) {
    return { color: CONTRACT_COLOR[f] || PKG_C.cold, label: f, hl: familySet[f] };
  });
  const legendWrap = document.createElement('div');
  wrap.appendChild(legendWrap);
  pkgBuildLegend(legendWrap, legendSeries, null, null);

  /* Note: no SKU code fabricated for Contract (no Nébuleuse sku_code) */
  const note = document.createElement('p');
  note.className = 'pkg-kpi-note';
  note.textContent = 'Les conditionnements Contract n\'ont pas de code SKU Nébuleuse — groupés par format uniquement.';
  wrap.appendChild(note);
}

/* ═══════════════════════════════════════════════════════════
   BOOT — hydrate from window.PKG_STATS
   ═══════════════════════════════════════════════════════════ */
(function pkgInit() {
  const stats = window.PKG_STATS;
  if (!stats) {
    /* No payload — server rendered the "no data" state; nothing to do */
    return;
  }

  pkgRenderKpi1(stats);
  pkgRenderKpi2(stats);
  pkgRenderKpi3(stats);
  pkgRenderKpi4(stats);
  pkgRenderWeek(stats);
  pkgRenderQa(stats);
  pkgRenderRhythm(stats);
})();

/* ---------------------------------------------------------------
   A3a -- Cette semaine: current-week packaging events table
   --------------------------------------------------------------- */
function pkgRenderWeek(stats) {
  var wrap = document.getElementById('pkg-kpi-week');
  if (!wrap) return;
  wrap.innerHTML = '';

  var weekData = stats.week_events || {};
  var events   = weekData.list || [];
  var label    = weekData.week_label || 'Cette semaine';

  wrap.appendChild(pkgSectionHead(
    'Cette semaine <span class="pkg-kpi-section__week-badge">' + pkgEscHtml(label) + '</span>',
    events.length + ' lot' + (events.length !== 1 ? 's' : '')
  ));

  if (events.length === 0) {
    pkgEmptyState(wrap, 'Aucun lot conditionne cette semaine.');
    return;
  }

  var tableWrap = document.createElement('div');
  tableWrap.className = 'pkg-week-table-wrap';

  var table = document.createElement('table');
  table.className = 'pkg-week-table';
  table.setAttribute('aria-label', 'Conditionnements ' + pkgEscHtml(label));

  var thead = document.createElement('thead');
  thead.innerHTML =
    '<tr>' +
      '<th class="pkg-week-th">Date</th>' +
      '<th class="pkg-week-th">SKU</th>' +
      '<th class="pkg-week-th">Format</th>' +
      '<th class="pkg-week-th pkg-week-th--num">Unites</th>' +
      '<th class="pkg-week-th pkg-week-th--num">HL vendable</th>' +
      '<th class="pkg-week-th pkg-week-th--num">CO₂ (g/L)</th>' +
      '<th class="pkg-week-th pkg-week-th--num">O₂ (ppb)</th>' +
      '<th class="pkg-week-th pkg-week-th--num">% pertes</th>' +
    '</tr>';
  table.appendChild(thead);

  var tbody = document.createElement('tbody');
  events.forEach(function(ev) {
    var tr = document.createElement('tr');
    tr.className = 'pkg-week-tr';

    var dateStr = ev.event_date || '';
    var dateLabel = dateStr;
    if (dateStr.length === 10) {
      var p = dateStr.split('-');
      dateLabel = p[2] + '/' + p[1];
    }

    var nRead   = ev.n_co2o2_readings || 0;
    var co2Cell = (nRead > 0 && ev.avg_co2 != null)
      ? pkgFmt(ev.avg_co2, 2) + '<span class="pkg-week-td-sub">n=' + nRead + '</span>'
      : '<span class="pkg-week-td-null">—</span>';
    var o2Cell  = (nRead > 0 && ev.avg_o2 != null)
      ? pkgFmt(ev.avg_o2, 0) + '<span class="pkg-week-td-sub">n=' + nRead + '</span>'
      : '<span class="pkg-week-td-null">—</span>';

    var lossCell;
    if (ev.loss_pct != null) {
      var lossPctVal = ev.loss_pct * 100;
      var lossClass = lossPctVal > 5 ? 'pkg-week-loss--warn' : (lossPctVal > 2 ? 'pkg-week-loss--mid' : '');
      lossCell = '<span class="pkg-week-loss ' + lossClass + '">' + pkgFmt(lossPctVal, 1) + '%</span>';
    } else {
      lossCell = '<span class="pkg-week-td-null">—</span>';
    }

    var unitsFmt = ev.prod_total_units != null
      ? pkgEscHtml(String(Number(ev.prod_total_units).toLocaleString('fr-CH')))
      : '—';
    var hlFmt = ev.vendable_hl != null ? pkgFmt(ev.vendable_hl, 1) : '—';

    tr.innerHTML =
      '<td class="pkg-week-td pkg-week-td--date">' + pkgEscHtml(dateLabel) + '</td>' +
      '<td class="pkg-week-td pkg-week-td--sku tanks-mono">' + pkgEscHtml(ev.sku_code || '—') + '</td>' +
      '<td class="pkg-week-td">' + pkgEscHtml(ev.display_family || ev.run_type || '—') + '</td>' +
      '<td class="pkg-week-td pkg-week-td--num">' + unitsFmt + '</td>' +
      '<td class="pkg-week-td pkg-week-td--num tanks-mono">' + hlFmt + '</td>' +
      '<td class="pkg-week-td pkg-week-td--num">' + co2Cell + '</td>' +
      '<td class="pkg-week-td pkg-week-td--num">' + o2Cell + '</td>' +
      '<td class="pkg-week-td pkg-week-td--num">' + lossCell + '</td>';

    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  tableWrap.appendChild(table);
  wrap.appendChild(tableWrap);

  var measuredCount = events.filter(function(e) { return e.n_co2o2_readings > 0; }).length;
  if (measuredCount < events.length) {
    var note = document.createElement('p');
    note.className = 'pkg-kpi-note';
    note.textContent = 'O₂/CO₂ : ' + measuredCount + ' lot' +
      (measuredCount !== 1 ? 's' : '') + ' mesure' + (measuredCount !== 1 ? 's' : '') +
      ' sur ' + events.length + ' — couverture en cours de deploiement.';
    wrap.appendChild(note);
  }
}

/* ---------------------------------------------------------------
   A3b -- Qualité & Pertes (Change 3 redesign)
   Sections:
     1. QA reads trend (monthly bar — qa_analyses + qa_library)
     2. O2/CO2 sparse points + honest coverage caveat
     3. Pertes bière par format (monthly line per format family)
     4. Pertes consommables (rateable rates + raw-count panel)
   --------------------------------------------------------------- */
function pkgRenderQa(stats) {
  var wrap = document.getElementById('pkg-kpi-qa');
  if (!wrap) return;
  wrap.innerHTML = '';

  var year = stats.year || '';

  wrap.appendChild(pkgSectionHead(
    'Qualité &amp; Pertes',
    pkgEscHtml(String(year))
  ));

  /* ── Helper: small sub-section heading ── */
  function qaSubHead(text) {
    var h = document.createElement('div');
    h.className = 'pkg-qa-sub-head';
    h.textContent = text;
    return h;
  }

  /* ── Helper: simple KPI tile ── */
  function qaKpi(val, label, subNote) {
    var el = document.createElement('div');
    el.className = 'pkg-qa-kpi';
    el.innerHTML =
      '<span class="pkg-qa-kpi__num">' + pkgEscHtml(String(val)) + '</span>' +
      '<span class="pkg-qa-kpi__label">' + label + '</span>' +
      (subNote ? '<span class="pkg-qa-kpi__sub">' + pkgEscHtml(subNote) + '</span>' : '');
    return el;
  }

  /* ══════════════════════════════════════════════
     SECTION 1 — QA reads trend (monthly bar)
     ══════════════════════════════════════════════ */
  wrap.appendChild(qaSubHead('Prélèvements QA par mois'));

  var qaTrend = stats.qa_trend_by_month || [];
  if (qaTrend.length === 0) {
    var emptyQa = document.createElement('p');
    emptyQa.className = 'pkg-kpi-note';
    emptyQa.textContent = 'Aucune donnée QA pour cette année.';
    wrap.appendChild(emptyQa);
  } else {
    /* Build 12-slot arrays */
    var qaMo     = [0,0,0,0,0,0,0,0,0,0,0,0];
    var qaLibMo  = [0,0,0,0,0,0,0,0,0,0,0,0];
    qaTrend.forEach(function(r) {
      var idx = r.mo - 1;
      if (idx >= 0 && idx < 12) {
        qaMo[idx]    = r.qa_analyses;
        qaLibMo[idx] = r.qa_library;
      }
    });

    var data12qa = [];
    for (var mi = 0; mi < 12; mi++) {
      data12qa.push({ analyses: qaMo[mi], library: qaLibMo[mi] });
    }

    var qaChartWrap = document.createElement('div');
    qaChartWrap.className = 'pkg-kpi-chart';
    wrap.appendChild(qaChartWrap);

    pkgBuildStackedBarChart(qaChartWrap, data12qa, [
      { key: 'analyses', color: PKG_C.hop,  label: 'Analyses QA' },
      { key: 'library',  color: PKG_C.cold, label: 'Bibliothèque' },
    ]);

    var totAnalyses = qaMo.reduce(function(s, v) { return s + v; }, 0);
    var totLib      = qaLibMo.reduce(function(s, v) { return s + v; }, 0);
    var qaLeg = document.createElement('div');
    wrap.appendChild(qaLeg);
    pkgBuildLegend(qaLeg, [
      { color: PKG_C.hop,  label: 'Analyses QA',  hl: null },
      { color: PKG_C.cold, label: 'Bibliothèque', hl: null },
    ], null, null);

    var qaNote = document.createElement('p');
    qaNote.className = 'pkg-kpi-note';
    qaNote.textContent = 'Total ' + year + ' : ' +
      Number(totAnalyses).toLocaleString('fr-CH') + ' unités analyses + ' +
      Number(totLib).toLocaleString('fr-CH') + ' unités bibliothèque.';
    wrap.appendChild(qaNote);
  }

  /* ══════════════════════════════════════════════
     SECTION 2 — O₂/CO₂ sparse points
     ══════════════════════════════════════════════ */
  wrap.appendChild(qaSubHead('Mesures O₂/CO₂ en ligne'));

  var co2Data  = stats.co2o2_readings || { readings: [], n_readings: 0, n_events: 0 };
  var co2Rows  = co2Data.readings || [];
  var nRd      = co2Data.n_readings || 0;
  var nEv      = co2Data.n_events || 0;

  var co2Cover = document.createElement('p');
  co2Cover.className = 'pkg-kpi-note';
  if (nRd === 0) {
    co2Cover.textContent = 'Aucune mesure O₂/CO₂ pour cette année. Couverture en cours de constitution.';
  } else {
    co2Cover.textContent = 'n=' + nRd + ' lectures / ' + nEv + ' lot' + (nEv !== 1 ? 's' : '') +
      ' — couverture en cours de constitution.';
  }
  wrap.appendChild(co2Cover);

  if (co2Rows.length > 0) {
    /* Render sparse readings as a compact grid */
    var co2Grid = document.createElement('div');
    co2Grid.className = 'pkg-co2-grid';

    /* Group by packaging_id */
    var byEvent = {};
    co2Rows.forEach(function(r) {
      var k = r.packaging_id;
      if (!byEvent[k]) byEvent[k] = { date: r.event_date, sku: r.sku_code, run_type: r.run_type, readings: [] };
      byEvent[k].readings.push(r);
    });

    Object.keys(byEvent).forEach(function(k) {
      var ev  = byEvent[k];
      var co2vals = ev.readings.map(function(r) { return r.co2_gl; }).filter(function(v) { return v != null; });
      var o2vals  = ev.readings.map(function(r) { return r.o2_ppb; }).filter(function(v) { return v != null; });
      var avgCo2 = co2vals.length ? co2vals.reduce(function(s, v) { return s + v; }, 0) / co2vals.length : null;
      var avgO2  = o2vals.length  ? o2vals.reduce(function(s, v) { return s + v; }, 0) / o2vals.length : null;

      var card = document.createElement('div');
      card.className = 'pkg-co2-card';
      var dateStr = ev.date ? ev.date.slice(5).replace('-', '/') : '—';
      card.innerHTML =
        '<div class="pkg-co2-card__sku">' + pkgEscHtml(ev.sku) + ' <span class="pkg-co2-card__date">' + pkgEscHtml(dateStr) + '</span></div>' +
        '<div class="pkg-co2-card__vals">' +
          (avgCo2 != null ? '<span class="pkg-co2-card__co2">' + pkgFmt(avgCo2, 2) + ' g/L CO₂</span>' : '') +
          (avgO2  != null ? '<span class="pkg-co2-card__o2">'  + pkgFmt(avgO2,  0) + ' ppb O₂</span>' : '') +
        '</div>' +
        '<div class="pkg-co2-card__n">n=' + ev.readings.length + '</div>';
      co2Grid.appendChild(card);
    });

    wrap.appendChild(co2Grid);
  }

  /* ══════════════════════════════════════════════
     SECTION 3 — Pertes bière par format (trend)
     ══════════════════════════════════════════════ */
  wrap.appendChild(qaSubHead('Perte bière par format (%)'));

  var lossFormatRows = stats.beer_loss_by_format_month || [];
  var lossYearRows   = stats.beer_loss_by_year || [];

  /* Check for incomplete years (2021/2022 data gap) */
  var incompleteYears = [];
  lossYearRows.forEach(function(r) {
    if (r.incomplete_data && r.yr <= 2022) incompleteYears.push(r.yr);
  });

  if (lossFormatRows.length === 0) {
    var noLoss = document.createElement('p');
    noLoss.className = 'pkg-kpi-note';
    noLoss.textContent = 'Aucune donnée de perte pour cette année.';
    if (incompleteYears.length > 0) noLoss.textContent += ' (données incomplètes attendues pour ' + incompleteYears.join(', ') + ')';
    wrap.appendChild(noLoss);
  } else {
    /* Aggregate families: bot→Bouteille, can+can33→Canette, keg→Fût, cuv→Cuve */
    var familyMonthLoss = {};  /* { family: [12 pct values] } */
    var familyMonthVend = {};  /* for denominator aggregation */
    var familyMonthLossHL = {};

    lossFormatRows.forEach(function(r) {
      var fam = PKG_FORMAT_FAMILY[r.run_type] || r.run_type;
      if (!familyMonthLossHL[fam]) {
        familyMonthLossHL[fam] = new Array(12).fill(0);
        familyMonthVend[fam]   = new Array(12).fill(0);
      }
      var idx = r.mo - 1;
      if (idx >= 0 && idx < 12) {
        familyMonthLossHL[fam][idx] += r.beer_loss_hl;
        familyMonthVend[fam][idx]   += r.vendable_hl;
      }
    });

    /* Recompute % from aggregated HL (so can+can33 combine properly) */
    var families = Object.keys(familyMonthLossHL);
    families.forEach(function(fam) {
      familyMonthLoss[fam] = familyMonthLossHL[fam].map(function(lhl, i) {
        var vend = familyMonthVend[fam][i];
        return (vend > 0) ? Math.round(100 * lhl / vend * 100) / 100 : null;
      });
    });

    var lossChartWrap = document.createElement('div');
    lossChartWrap.className = 'pkg-kpi-chart';
    wrap.appendChild(lossChartWrap);
    pkgBuildLossLineChart(lossChartWrap, familyMonthLoss, families);

    /* Legend */
    var lossLegWrap = document.createElement('div');
    wrap.appendChild(lossLegWrap);
    pkgBuildLegend(lossLegWrap, families.map(function(f) {
      return { color: PKG_FORMAT_COLOR[f] || PKG_C.steel_mid, label: f, hl: null };
    }), null, null);

    if (incompleteYears.indexOf(year) >= 0) {
      var gapNote = document.createElement('p');
      gapNote.className = 'pkg-kpi-note';
      gapNote.textContent = '⚠ Données incomplètes pour ' + year +
        ' — le saisie des pertes liquides était absente avant 2023. La valeur réelle n\'est pas 0%.';
      wrap.appendChild(gapNote);
    }
  }

  /* ══════════════════════════════════════════════
     SECTION 4 — Pertes consommables
     ══════════════════════════════════════════════ */
  wrap.appendChild(qaSubHead('Pertes consommables'));

  var consRates = stats.consumable_loss_rates || { rateable: [], raw_count: [] };
  var rateable  = consRates.rateable  || [];
  var rawCount  = consRates.raw_count || [];

  if (rateable.length === 0 && rawCount.length === 0) {
    var noCons = document.createElement('p');
    noCons.className = 'pkg-kpi-note';
    noCons.textContent = 'Aucune donnée de pertes consommables pour cette année.';
    wrap.appendChild(noCons);
  } else {
    var consGrid = document.createElement('div');
    consGrid.className = 'pkg-qa-grid pkg-cons-grid';

    /* Rateable — show rate % */
    rateable.forEach(function(item) {
      if (item.waste_units === 0 && item.rate_pct === null) return;
      var rateStr = item.rate_pct != null ? pkgFmt(item.rate_pct, 2) + ' %' : '—';
      consGrid.appendChild(qaKpi(rateStr, pkgEscHtml(item.label_fr),
        Number(item.waste_units).toLocaleString('fr-CH') + ' unités rebuts'));
    });

    /* Not rateable — raw count + pending flag */
    rawCount.forEach(function(item) {
      if (item.waste_units === 0) return;
      consGrid.appendChild(qaKpi(
        Number(item.waste_units).toLocaleString('fr-CH') + ' u.',
        pkgEscHtml(item.label_fr),
        item.pending_note || ''
      ));
    });

    wrap.appendChild(consGrid);

    var consNote = document.createElement('p');
    consNote.className = 'pkg-kpi-note';
    consNote.textContent =
      'Taux = rebuts / (production + rebuts) pour les consommables 1:1. ' +
      'Fardelage et 4-packs : taux en attente du câblage pack-size — comptage brut affiché.';
    wrap.appendChild(consNote);
  }
}

/* ── Loss % line chart for format families ──
   familyData: { family: [12 pct|null values] }
   families: sorted array of family names */
function pkgBuildLossLineChart(container, familyData, families) {
  var W = 840, H = 200, padL = 44, padR = 12, padT = 14, padB = 34;
  var chartW = W - padL - padR;
  var chartH = H - padT - padB;

  /* Find max pct */
  var maxPct = 0.5;
  families.forEach(function(f) {
    (familyData[f] || []).forEach(function(v) {
      if (v != null && v > maxPct) maxPct = v;
    });
  });
  var gridTop = Math.ceil(maxPct * 2) / 2 + 0.5;  /* round up to nearest 0.5 */
  if (gridTop < 1) gridTop = 1;

  function yScale(v) { return chartH * (1 - v / gridTop); }
  var moW = chartW / 12;

  var svg = pkgSvgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img' });

  /* Grid lines */
  var gridSteps = 4;
  for (var gi = 0; gi <= gridSteps; gi++) {
    var gv = gridTop * gi / gridSteps;
    var gy = padT + yScale(gv);
    svg.appendChild(pkgSvgEl('line', {
      x1: padL, y1: gy, x2: W - padR, y2: gy,
      stroke: PKG_C.hairline, 'stroke-width': gi === 0 ? 1.5 : 0.5,
      opacity: gi === 0 ? 0.9 : 0.5,
    }));
    if (gi > 0) {
      var gl = pkgSvgEl('text', { x: padL - 5, y: gy + 4, 'text-anchor': 'end',
        fill: PKG_C.ink_faint, 'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'letter-spacing': '0.04em' });
      gl.textContent = gv.toFixed(1) + '%';
      svg.appendChild(gl);
    }
  }

  /* Month labels */
  for (var lmi = 0; lmi < 12; lmi++) {
    var lx = padL + lmi * moW + moW / 2;
    var ll = pkgSvgEl('text', { x: lx, y: H - padB + 14, 'text-anchor': 'middle',
      fill: PKG_C.ink_faint, 'font-family': 'JetBrains Mono,monospace', 'font-size': 9, 'letter-spacing': '0.06em' });
    ll.textContent = PKG_MONTHS_FR[lmi];
    svg.appendChild(ll);
  }

  /* One line per family */
  families.forEach(function(fam) {
    var pts = familyData[fam] || [];
    var color = PKG_FORMAT_COLOR[fam] || PKG_C.steel_mid;
    var polyPts = [];

    pts.forEach(function(v, mi) {
      if (v == null) return;
      var px = padL + mi * moW + moW / 2;
      var py = padT + yScale(v);
      polyPts.push(px + ',' + py);
    });

    if (polyPts.length > 1) {
      svg.appendChild(pkgSvgEl('polyline', {
        points: polyPts.join(' '),
        fill: 'none', stroke: color, 'stroke-width': 2.2,
      }));
    }

    /* Dots with tooltips */
    pts.forEach(function(v, mi) {
      if (v == null) return;
      var px = padL + mi * moW + moW / 2;
      var py = padT + yScale(v);
      var dot = pkgSvgEl('circle', { cx: px, cy: py, r: 3, fill: color });
      (function(mo, pct, fname) {
        dot.addEventListener('mouseenter', function(e) {
          pkgShowTip(e, '<strong>' + pkgEscHtml(PKG_MONTHS_FR[mo].toUpperCase()) + '</strong> · ' +
            pkgEscHtml(fname) + '<br><strong>' + pkgFmt(pct, 2) + '%</strong> perte bière');
        });
        dot.addEventListener('mousemove', pkgMoveTip);
        dot.addEventListener('mouseleave', pkgHideTip);
      })(mi, v, fam);
      svg.appendChild(dot);
    });
  });

  container.innerHTML = '';
  container.appendChild(svg);
}

/* ---------------------------------------------------------------
   A4 -- Output rhythm: quarterly grouped bars + cumulative YTD line
   --------------------------------------------------------------- */
function pkgRenderRhythm(stats) {
  var wrap = document.getElementById('pkg-kpi-rhythm');
  if (!wrap) return;
  wrap.innerHTML = '';

  var year      = stats.year || new Date().getFullYear();
  var priorYear = year - 1;
  var qRows     = stats.quarterly_hl || [];
  var mRows     = stats.monthly_ytd  || [];

  var qCurr  = {}, qPrior = {};
  qRows.forEach(function(r) {
    if (r.yr === year)      qCurr[r.q]  = r.hl;
    if (r.yr === priorYear) qPrior[r.q] = r.hl;
  });

  var mCurr = {}, mPrior = {};
  mRows.forEach(function(r) {
    if (r.yr === year)      mCurr[r.mo]  = r.hl;
    if (r.yr === priorYear) mPrior[r.mo] = r.hl;
  });

  function cumulArr(map) {
    var arr = [], cum = 0;
    for (var m = 1; m <= 12; m++) {
      cum += (map[m] || 0);
      arr.push({ mo: m, hl: map[m] || 0, ytd: cum });
    }
    return arr;
  }

  var maxMoCurr = 0;
  Object.keys(mCurr).forEach(function(k) { var n = parseInt(k,10); if (n > maxMoCurr) maxMoCurr = n; });

  var cumCurr  = cumulArr(mCurr);
  var cumPrior = cumulArr(mPrior);

  var ytdCurr  = maxMoCurr > 0 ? cumCurr[maxMoCurr - 1].ytd  : 0;
  var ytdPrior = maxMoCurr > 0 ? cumPrior[maxMoCurr - 1].ytd : 0;
  var ytdDelta = ytdCurr - ytdPrior;
  var ytdPct   = ytdPrior > 0 ? ytdDelta / ytdPrior * 100 : null;

  var ytdSign = ytdDelta >= 0 ? '+' : '';
  var ytdDeltaStr = ytdPrior > 0
    ? ytdSign + pkgFmt(ytdDelta, 0) + ' HL (' + ytdSign + pkgFmt(ytdPct, 1) + '% vs ' + priorYear + ')'
    : null;

  wrap.appendChild(pkgSectionHead(
    'Rythme de production',
    pkgEscHtml(String(year)) + (ytdDeltaStr ? ' · YTD ' + pkgEscHtml(ytdDeltaStr) : '')
  ));

  /* --- Part 1: Quarterly grouped bars --- */
  var qHead = document.createElement('div');
  qHead.className = 'pkg-rhythm-sub-head';
  qHead.textContent = 'Production trimestrielle HL — ' + year + ' vs ' + priorYear;
  wrap.appendChild(qHead);

  (function buildQuarterly() {
    var quarters = [1,2,3,4];
    var qLabels  = ['T1','T2','T3','T4'];
    var allVals  = quarters.map(function(q) { return Math.max(qCurr[q]||0, qPrior[q]||0); });
    var maxVal   = Math.max.apply(null, allVals.concat([1]));
    var gridTop  = Math.ceil(maxVal / 200) * 200 || 200;

    var W=620, H=200, padL=48, padR=14, padT=14, padB=40;
    var chartW = W-padL-padR, chartH = H-padT-padB;
    function yQ(v) { return chartH*(1-v/gridTop); }

    var svg = pkgSvgEl('svg', { viewBox:'0 0 '+W+' '+H, role:'img' });

    for (var i=0; i<=4; i++) {
      var v = gridTop*i/4, y = padT+yQ(v);
      svg.appendChild(pkgSvgEl('line',{x1:padL,y1:y,x2:W-padR,y2:y,stroke:PKG_C.hairline,'stroke-width':i===0?1.5:0.5,opacity:i===0?0.9:0.5}));
      if (i>0) {
        var gl=pkgSvgEl('text',{x:padL-5,y:y+4,'text-anchor':'end',fill:PKG_C.ink_faint,'font-family':'JetBrains Mono,monospace','font-size':9,'letter-spacing':'0.04em'});
        gl.textContent = v>=1000?(v/1000).toFixed(1)+'k':v.toFixed(0);
        svg.appendChild(gl);
      }
    }

    var groupW = chartW/4, barGap = groupW*0.08, barW = (groupW-barGap*3)/2;

    quarters.forEach(function(q,qi) {
      var xGroup   = padL + qi*groupW + barGap;
      var hlCurr   = qCurr[q]  || 0;
      var hlPrior2 = qPrior[q] || 0;

      if (hlPrior2 > 0) {
        var bh = yQ(0)-yQ(hlPrior2), by = padT+yQ(hlPrior2);
        var r = pkgSvgEl('rect',{x:xGroup,y:by,width:Math.max(barW,3),height:Math.max(bh,1),fill:PKG_C.steel_mid,opacity:0.38,rx:2});
        (function(q2,hl){ r.addEventListener('mouseenter',function(e){ pkgShowTip(e,priorYear+' T'+q2+' : <strong>'+pkgFmt(hl)+' HL</strong>'); }); })(q,hlPrior2);
        r.addEventListener('mousemove',pkgMoveTip); r.addEventListener('mouseleave',pkgHideTip);
        svg.appendChild(r);
      }
      if (hlCurr > 0) {
        var bh2 = yQ(0)-yQ(hlCurr), by2 = padT+yQ(hlCurr);
        var r2 = pkgSvgEl('rect',{x:xGroup+barW+barGap,y:by2,width:Math.max(barW,3),height:Math.max(bh2,1),fill:PKG_C.hop,rx:2});
        (function(q2,hc,hp){ r2.addEventListener('mouseenter',function(e){
          var tip=year+' T'+q2+' : <strong>'+pkgFmt(hc)+' HL</strong>';
          if (hp>0){ var s=hc>=hp?'+':''; tip+='<br><span style="color:'+PKG_C.ink_faint+'">'+s+pkgFmt(hc-hp,0)+' HL ('+s+pkgFmt((hc-hp)/hp*100,1)+'% vs '+priorYear+')</span>'; }
          pkgShowTip(e,tip);
        }); })(q,hlCurr,hlPrior2);
        r2.addEventListener('mousemove',pkgMoveTip); r2.addEventListener('mouseleave',pkgHideTip);
        svg.appendChild(r2);
        if (bh2>16){
          var vl=pkgSvgEl('text',{x:xGroup+barW+barGap+barW/2,y:padT+yQ(hlCurr)-3,'text-anchor':'middle',fill:PKG_C.ink_mute,'font-family':'JetBrains Mono,monospace','font-size':8,'font-weight':500});
          vl.textContent=Math.round(hlCurr); svg.appendChild(vl);
        }
      }

      var qlx = padL + qi*groupW + groupW/2;
      var ql = pkgSvgEl('text',{x:qlx,y:H-padB+14,'text-anchor':'middle',fill:PKG_C.ink_faint,'font-family':'JetBrains Mono,monospace','font-size':10,'letter-spacing':'0.06em'});
      ql.textContent = qLabels[qi]; svg.appendChild(ql);

      if (hlPrior2>0) {
        var dp = (hlCurr-hlPrior2)/hlPrior2*100;
        var dl=pkgSvgEl('text',{x:qlx,y:H-padB+26,'text-anchor':'middle',fill:dp>=0?PKG_C.hop:PKG_C.ember,'font-family':'JetBrains Mono,monospace','font-size':8,'letter-spacing':'0.04em'});
        dl.textContent=(dp>=0?'+':'')+pkgFmt(dp,0)+'%'; svg.appendChild(dl);
      }
    });

    var cd=document.createElement('div'); cd.className='pkg-kpi-chart pkg-rhythm-chart'; cd.appendChild(svg); wrap.appendChild(cd);
    var leg=document.createElement('div'); leg.className='pkg-kpi-legend';
    leg.innerHTML='<div class="pkg-kpi-legend__list">'+
      '<span class="pkg-kpi-legend__item"><span class="pkg-kpi-legend__swatch" style="background:'+PKG_C.hop+'"></span><span class="pkg-kpi-legend__label">'+pkgEscHtml(String(year))+'</span></span>'+
      '<span class="pkg-kpi-legend__item"><span class="pkg-kpi-legend__swatch" style="background:'+PKG_C.steel_mid+';opacity:.5"></span><span class="pkg-kpi-legend__label">'+pkgEscHtml(String(priorYear))+' (comparaison)</span></span>'+
      '</div>';
    wrap.appendChild(leg);
  })();

  /* --- Part 2: Cumulative YTD line chart --- */
  var ytdHead=document.createElement('div'); ytdHead.className='pkg-rhythm-sub-head pkg-rhythm-sub-head--ytd';
  ytdHead.textContent='HL cumulés YTD — '+year+' vs '+priorYear;
  wrap.appendChild(ytdHead);

  if (ytdPrior>0 && maxMoCurr>0) {
    var dc=document.createElement('div'); dc.className='pkg-ytd-delta';
    var s=ytdDelta>=0?'+':''; var col=ytdDelta>=0?'var(--hop)':'var(--ember)';
    dc.innerHTML=
      '<span class="pkg-ytd-delta__num" style="color:'+col+'">'+s+pkgEscHtml(pkgFmt(ytdDelta,0))+' HL</span>'+
      '<span class="pkg-ytd-delta__label">YTD J1–M'+maxMoCurr+' '+year+' vs '+priorYear+'</span>'+
      '<span class="pkg-ytd-delta__pct" style="color:'+col+'">'+s+pkgEscHtml(pkgFmt(ytdPct,1))+'%</span>'+
      '<span class="pkg-ytd-delta__vals">'+pkgEscHtml(pkgFmt(ytdCurr,0))+' HL ('+year+') vs '+pkgEscHtml(pkgFmt(ytdPrior,0))+' HL ('+priorYear+')</span>';
    wrap.appendChild(dc);
  }

  (function buildYtd() {
    var allYtd=[];
    for (var i=0;i<12;i++) { allYtd.push(cumCurr[i].ytd,cumPrior[i].ytd); }
    var maxVal=Math.max.apply(null,allYtd.concat([1]));
    var gridTop=Math.ceil(maxVal/500)*500||500;
    var W=840,H=220,padL=48,padR=14,padT=14,padB=34;
    var chartW=W-padL-padR,chartH=H-padT-padB;
    function yL(v){return chartH*(1-v/gridTop);}

    var svg=pkgSvgEl('svg',{viewBox:'0 0 '+W+' '+H,role:'img'});
    for (var i=0;i<=4;i++){
      var v=gridTop*i/4,y=padT+yL(v);
      svg.appendChild(pkgSvgEl('line',{x1:padL,y1:y,x2:W-padR,y2:y,stroke:PKG_C.hairline,'stroke-width':i===0?1.5:0.5,opacity:i===0?0.9:0.45}));
      if(i>0){var gl=pkgSvgEl('text',{x:padL-5,y:y+4,'text-anchor':'end',fill:PKG_C.ink_faint,'font-family':'JetBrains Mono,monospace','font-size':9,'letter-spacing':'0.04em'});gl.textContent=v>=1000?(v/1000).toFixed(1)+'k':v.toFixed(0);svg.appendChild(gl);}
    }
    var moW=chartW/12;

    function buildPts(arr,maxMo,isCurr){
      var pts=[];
      for(var i=0;i<(isCurr?maxMo:12);i++){
        var d=arr[i]; if(!d)continue;
        var x=padL+i*moW+moW/2, y2=padT+yL(d.ytd);
        pts.push(x+','+y2);
      }
      return pts.join(' ');
    }

    var ppPts=buildPts(cumPrior,12,false);
    var cpPts=buildPts(cumCurr,maxMoCurr,true);

    if(ppPts) svg.appendChild(pkgSvgEl('polyline',{points:ppPts,fill:'none',stroke:PKG_C.steel_mid,'stroke-width':1.8,'stroke-dasharray':'4 3',opacity:0.55}));
    if(cpPts) svg.appendChild(pkgSvgEl('polyline',{points:cpPts,fill:'none',stroke:PKG_C.hop,'stroke-width':2.5}));

    for(var i=0;i<maxMoCurr;i++){
      var d=cumCurr[i]; if(!d||d.hl===0)continue;
      var x=padL+i*moW+moW/2, y2=padT+yL(d.ytd);
      var py=(cumPrior[i]||{}).ytd||0;
      var dot=pkgSvgEl('circle',{cx:x,cy:y2,r:3.5,fill:PKG_C.hop});
      (function(mo,ytdV,prV){
        dot.addEventListener('mouseenter',function(e){
          var tip=PKG_MONTHS_FR[mo].toUpperCase()+' · YTD <strong>'+pkgFmt(ytdV,0)+' HL</strong>';
          if(prV>0){var sg=ytdV>=prV?'+':'';tip+='<br><span style="color:'+PKG_C.ink_faint+'">'+sg+pkgFmt(ytdV-prV,0)+' HL vs '+priorYear+'</span>';}
          pkgShowTip(e,tip);
        });
      })(i,d.ytd,py);
      dot.addEventListener('mousemove',pkgMoveTip); dot.addEventListener('mouseleave',pkgHideTip);
      svg.appendChild(dot);
    }

    for(var i=0;i<12;i++){
      var lbl=pkgSvgEl('text',{x:padL+i*moW+moW/2,y:H-padB+14,'text-anchor':'middle',fill:PKG_C.ink_faint,'font-family':'JetBrains Mono,monospace','font-size':9,'letter-spacing':'0.06em'});
      lbl.textContent=PKG_MONTHS_FR[i]; svg.appendChild(lbl);
    }

    if(maxMoCurr>0&&maxMoCurr<12){
      var mx=padL+maxMoCurr*moW;
      svg.appendChild(pkgSvgEl('line',{x1:mx,y1:padT,x2:mx,y2:padT+chartH,stroke:PKG_C.ember,'stroke-width':1,'stroke-dasharray':'3 3',opacity:0.5}));
    }

    var cd=document.createElement('div'); cd.className='pkg-kpi-chart pkg-rhythm-chart'; cd.appendChild(svg); wrap.appendChild(cd);
    var leg=document.createElement('div'); leg.className='pkg-kpi-legend';
    leg.innerHTML='<div class="pkg-kpi-legend__list">'+
      '<span class="pkg-kpi-legend__item"><span class="pkg-kpi-legend__swatch" style="background:'+PKG_C.hop+'"></span><span class="pkg-kpi-legend__label">'+pkgEscHtml(String(year))+' (cumulé)</span></span>'+
      '<span class="pkg-kpi-legend__item"><span class="pkg-kpi-legend__swatch" style="background:'+PKG_C.steel_mid+';opacity:.5"></span><span class="pkg-kpi-legend__label">'+pkgEscHtml(String(priorYear))+' (cumulé)</span></span>'+
      '</div>';
    wrap.appendChild(leg);

    if(maxMoCurr>0&&maxMoCurr<12){
      var note=document.createElement('p'); note.className='pkg-kpi-note';
      note.textContent='Année partielle — '+year+' : données disponibles jusqu\'en '+PKG_MONTHS_FR[maxMoCurr-1]+'. La ligne tiretée ('+priorYear+') montre la courbe complète.';
      wrap.appendChild(note);
    }
  })();
}
