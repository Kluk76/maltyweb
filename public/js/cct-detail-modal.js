/* cct-detail-modal.js — CCT detail popup for the fermentation tank board */
'use strict';

(function () {
  const CHART = {
    w: 800, h: 340,
    pad: { top: 28, right: 64, bottom: 50, left: 56 },
    fgMin: 0, fgMax: 16,
    phMin: 4.0, phMax: 5.4,
    dayMin: 0, dayMax: 12,
  };

  const plotW = () => CHART.w - CHART.pad.left - CHART.pad.right;
  const plotH = () => CHART.h - CHART.pad.top - CHART.pad.bottom;
  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
  const xPos  = d  => CHART.pad.left + (clamp(d, CHART.dayMin, CHART.dayMax) - CHART.dayMin) / (CHART.dayMax - CHART.dayMin) * plotW();
  const yFG   = fg => CHART.pad.top  + (1 - (clamp(fg, CHART.fgMin, CHART.fgMax) - CHART.fgMin) / (CHART.fgMax - CHART.fgMin)) * plotH();
  const yPH   = ph => CHART.pad.top  + (1 - (clamp(ph, CHART.phMin, CHART.phMax) - CHART.phMin) / (CHART.phMax - CHART.phMin)) * plotH();

  /**
   * Monotone-cubic Hermite spline (Fritsch–Carlson).
   * Produces a smooth curve that NEVER overshoots between data points —
   * essential for fermentation data where FG approaches 0 and a cardinal
   * spline could swing the curve into negative territory.
   */
  function pathFrom(points) {
    const n = points.length;
    if (!n) return '';
    if (n === 1) return `M ${points[0].x} ${points[0].y}`;
    if (n === 2) return `M ${points[0].x} ${points[0].y} L ${points[1].x} ${points[1].y}`;

    // secant slopes
    const slopes = new Array(n - 1);
    for (let i = 0; i < n - 1; i++) {
      const dx = points[i + 1].x - points[i].x || 1e-9;
      slopes[i] = (points[i + 1].y - points[i].y) / dx;
    }

    // initial tangents (averaged secants, zero on sign change)
    const tang = new Array(n);
    tang[0] = slopes[0];
    tang[n - 1] = slopes[n - 2];
    for (let i = 1; i < n - 1; i++) {
      tang[i] = (slopes[i - 1] * slopes[i] <= 0) ? 0 : (slopes[i - 1] + slopes[i]) / 2;
    }

    // Fritsch–Carlson monotonicity constraint
    for (let i = 0; i < n - 1; i++) {
      if (slopes[i] === 0) { tang[i] = 0; tang[i + 1] = 0; continue; }
      const a = tang[i] / slopes[i];
      const b = tang[i + 1] / slopes[i];
      const r = Math.hypot(a, b);
      if (r > 3) {
        tang[i]     = 3 * a * slopes[i] / r;
        tang[i + 1] = 3 * b * slopes[i] / r;
      }
    }

    // emit cubic-bezier segments
    let d = `M ${points[0].x} ${points[0].y}`;
    for (let i = 0; i < n - 1; i++) {
      const h = (points[i + 1].x - points[i].x) / 3;
      const c1x = points[i].x + h;
      const c1y = points[i].y + tang[i] * h;
      const c2x = points[i + 1].x - h;
      const c2y = points[i + 1].y - tang[i + 1] * h;
      d += ` C ${c1x} ${c1y}, ${c2x} ${c2y}, ${points[i + 1].x} ${points[i + 1].y}`;
    }
    return d;
  }

  // ── State ──
  let VISIBLE = {};
  let CURRENT_DATA = null;
  let TOOLTIP = null;

  // ── Tooltip ──
  // Must live INSIDE the dialog: open <dialog>s render in the browser's
  // top layer; anything in body sits behind that layer and is invisible.
  function ensureTooltip() {
    const host = document.getElementById('cct-detail-modal');
    if (!host) return null;
    if (TOOLTIP && host.contains(TOOLTIP)) return TOOLTIP;
    TOOLTIP = document.createElement('div');
    TOOLTIP.className = 'cct-modal__tooltip';
    TOOLTIP.style.display = 'none';
    host.appendChild(TOOLTIP);
    return TOOLTIP;
  }
  function showTooltip(html, x, y) {
    const t = ensureTooltip();
    if (!t) return;
    t.innerHTML = html;
    t.style.display = 'block';
    positionTooltip(x, y);
  }
  function positionTooltip(x, y) {
    const t = TOOLTIP;
    if (!t) return;
    t.style.left = '0px'; t.style.top = '0px'; // reset before measuring
    const rect = t.getBoundingClientRect();
    let nx = x + 14;
    let ny = y - rect.height - 12;
    if (nx + rect.width > window.innerWidth - 8)  nx = x - rect.width - 14;
    if (ny < 8)                                   ny = y + 18;
    if (ny + rect.height > window.innerHeight - 8) ny = window.innerHeight - rect.height - 8;
    t.style.left = nx + 'px';
    t.style.top  = ny + 'px';
  }
  function hideTooltip() {
    if (TOOLTIP) TOOLTIP.style.display = 'none';
  }

  // ── Chart render ──
  function renderChart(svgEl, data) {
    if (!svgEl || !data) return;
    const out = [];

    // Read CSS tokens once so SVG strings track the global theme automatically.
    const css     = getComputedStyle(document.documentElement);
    const ink     = css.getPropertyValue('--ink').trim();      // #241b10
    const inkMute = css.getPropertyValue('--ink-mute').trim(); // #4a3820
    const bg      = css.getPropertyValue('--bg').trim();       // #f1e8d4
    const ember   = css.getPropertyValue('--ember').trim();    // #b34428
    const hopDeep = css.getPropertyValue('--hop-deep').trim(); // #3f4d14
    const oak     = css.getPropertyValue('--oak').trim();      // #8b5e2a
    const cold    = css.getPropertyValue('--cold').trim();     // #2f5575
    const bbt     = css.getPropertyValue('--bbt').trim();      // #2f6d99

    // Grid + axes
    for (let v = CHART.fgMin; v <= CHART.fgMax; v += 4) {
      const y = yFG(v);
      out.push(`<line x1="${CHART.pad.left}" y1="${y}" x2="${CHART.w - CHART.pad.right}" y2="${y}" stroke="rgba(74,56,32,0.06)" stroke-width="1"/>`);
      out.push(`<text x="${CHART.pad.left - 10}" y="${y + 3.5}" text-anchor="end" font-family="JetBrains Mono, monospace" font-size="9.5" fill="${inkMute}" font-weight="500">${v}</text>`);
    }
    for (let v = 4.0; v <= 5.4 + 0.001; v += 0.4) {
      out.push(`<text x="${CHART.w - CHART.pad.right + 10}" y="${yPH(v) + 3.5}" text-anchor="start" font-family="JetBrains Mono, monospace" font-size="9.5" fill="${inkMute}" font-weight="500">${v.toFixed(1)}</text>`);
    }
    for (let d = 2; d <= 12; d += 2) {
      const x = xPos(d);
      out.push(`<line x1="${x}" y1="${CHART.pad.top}" x2="${x}" y2="${CHART.h - CHART.pad.bottom}" stroke="rgba(74,56,32,0.04)" stroke-width="1"/>`);
    }
    out.push(`<line x1="${CHART.pad.left}" y1="${CHART.h - CHART.pad.bottom}" x2="${CHART.w - CHART.pad.right}" y2="${CHART.h - CHART.pad.bottom}" stroke="rgba(74,56,32,0.20)" stroke-width="1"/>`);
    for (let d = 0; d <= 12; d += 2) {
      const x = xPos(d);
      out.push(`<text x="${x}" y="${CHART.h - CHART.pad.bottom + 18}" text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="9.5" fill="${inkMute}" font-weight="500">J+${d}</text>`);
    }
    out.push(`<text x="${CHART.pad.left - 10}" y="${CHART.pad.top - 10}" text-anchor="end" font-family="JetBrains Mono, monospace" font-size="8.5" fill="${inkMute}" letter-spacing="1.6" font-weight="500">FG · °P</text>`);
    out.push(`<text x="${CHART.w - CHART.pad.right + 10}" y="${CHART.pad.top - 10}" text-anchor="start" font-family="JetBrains Mono, monospace" font-size="8.5" fill="${inkMute}" letter-spacing="1.6" font-weight="500">pH</text>`);
    out.push(`<text x="${CHART.w / 2}" y="${CHART.h - 10}" text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="8.5" fill="${inkMute}" letter-spacing="2.5" font-weight="500">JOURS DEPUIS DÉBUT FERMENTATION</text>`);

    // Historical ghosts — each batch wrapped in a hover-able <g>
    const hist = data.historical || [];
    for (const batch of hist) {
      if (!VISIBLE[batch.batch]) continue;
      const reads = batch.reads || [];
      const fgPts = reads.filter(r => r.fg != null).map(r => ({ x: xPos(r.day), y: yFG(r.fg) }));
      const phPts = reads.filter(r => r.ph != null).map(r => ({ x: xPos(r.day), y: yPH(r.ph) }));
      const fgD = fgPts.length > 1 ? pathFrom(fgPts) : null;
      const phD = phPts.length > 1 ? pathFrom(phPts) : null;
      out.push(`<g class="cct-modal__hist-batch" data-batch="${batch.batch}">`);
      // wide invisible hit overlays — capture hover even though stroke is thin
      if (fgD) out.push(`<path d="${fgD}" fill="none" stroke="transparent" stroke-width="14" pointer-events="stroke" class="hit"/>`);
      if (phD) out.push(`<path d="${phD}" fill="none" stroke="transparent" stroke-width="14" pointer-events="stroke" class="hit"/>`);
      // visible (decorative; pointer-events off so hit overlay catches)
      if (fgD) out.push(`<path d="${fgD}" fill="none" stroke="${ember}" stroke-width="1.2" opacity="0.18" stroke-linecap="round" pointer-events="none" class="hist-fg"/>`);
      if (phD) out.push(`<path d="${phD}" fill="none" stroke="${hopDeep}" stroke-width="1.2" opacity="0.20" stroke-dasharray="4 3" stroke-linecap="round" pointer-events="none" class="hist-ph"/>`);
      // small visible datapoints (faded; brighten on group hover via CSS)
      for (const r of reads) {
        if (r.fg != null) out.push(`<circle cx="${xPos(r.day)}" cy="${yFG(r.fg)}" r="1.8" fill="${ember}" opacity="0.35" pointer-events="none" class="hist-dot hist-dot--fg"/>`);
        if (r.ph != null) out.push(`<circle cx="${xPos(r.day)}" cy="${yPH(r.ph)}" r="1.6" fill="${hopDeep}" opacity="0.40" pointer-events="none" class="hist-dot hist-dot--ph"/>`);
      }
      if (batch.cc_day != null) {
        const ccx = xPos(batch.cc_day);
        const lastFg = reads.find(r => r.day === batch.cc_day);
        if (lastFg && lastFg.fg != null) {
          out.push(`<circle cx="${ccx}" cy="${yFG(lastFg.fg)}" r="2.8" fill="none" stroke="${cold}" stroke-width="1.2" opacity="0.55" pointer-events="none"/>`);
        }
      }
      out.push(`</g>`);
    }

    // Current batch
    const cur = data.current_reads || [];
    if (VISIBLE['current'] && cur.length > 0) {
      const fgPts = cur.filter(r => r.fg != null).map(r => ({ x: xPos(r.day), y: yFG(r.fg) }));
      const phPts = cur.filter(r => r.ph != null).map(r => ({ x: xPos(r.day), y: yPH(r.ph) }));

      if (fgPts.length > 1) {
        out.push(`<path d="${pathFrom(fgPts)}" fill="none" stroke="${ember}" stroke-width="5" opacity="0.10" stroke-linecap="round"/>`);
        out.push(`<path d="${pathFrom(fgPts)}" fill="none" stroke="${ember}" stroke-width="2.6" stroke-linecap="round"/>`);
      }
      if (phPts.length > 1) {
        out.push(`<path d="${pathFrom(phPts)}" fill="none" stroke="${hopDeep}" stroke-width="2" stroke-linecap="round" stroke-dasharray="6 3"/>`);
      }
      for (const r of cur) {
        if (r.fg != null) out.push(`<circle cx="${xPos(r.day)}" cy="${yFG(r.fg)}" r="3.2" fill="${ember}" stroke="${ink}" stroke-width="1.6"/>`);
        if (r.ph != null) out.push(`<circle cx="${xPos(r.day)}" cy="${yPH(r.ph)}" r="2.6" fill="${hopDeep}" stroke="${ink}" stroke-width="1.4"/>`);
      }

      // Current-day line
      const latest = [...cur].reverse().find(r => r.fg != null || r.ph != null);
      if (latest) {
        const cx = xPos(latest.day);
        out.push(`<line x1="${cx}" y1="${CHART.pad.top}" x2="${cx}" y2="${CHART.h - CHART.pad.bottom}" stroke="${oak}" stroke-width="0.8" stroke-dasharray="2 3" opacity="0.85"/>`);
        out.push(`<g transform="translate(${cx}, ${CHART.pad.top - 18})"><text text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="8.5" fill="${inkMute}" font-weight="500" letter-spacing="1.4">AUJOURD'HUI</text></g>`);

        // Readout pills
        if (latest.fg != null && latest.ph != null) {
          const lyFg = yFG(latest.fg);
          const lyPh = yPH(latest.ph);
          const pillW = 60, pillH = 21;
          const pillX = cx + 16;
          let phPillY = Math.max(lyPh - 30, CHART.pad.top + 2);
          let fgPillY = Math.min(lyFg + 12, CHART.h - CHART.pad.bottom - pillH - 4);
          if (fgPillY < phPillY + pillH + 8) fgPillY = phPillY + pillH + 8;
          const phMidY = phPillY + pillH / 2;
          const fgMidY = fgPillY + pillH / 2;
          out.push(`<path d="M ${cx + 3.5} ${lyPh - 1.5} Q ${cx + 9} ${(lyPh + phMidY) / 2}, ${pillX} ${phMidY}" fill="none" stroke="${hopDeep}" stroke-width="0.9" opacity="0.55"/>`);
          out.push(`<path d="M ${cx + 3.5} ${lyFg + 1.5} Q ${cx + 9} ${(lyFg + fgMidY) / 2}, ${pillX} ${fgMidY}" fill="none" stroke="${ember}" stroke-width="0.9" opacity="0.55"/>`);
          out.push(`<g transform="translate(${pillX}, ${phPillY})"><rect x="0" y="0" width="${pillW}" height="${pillH}" rx="2" fill="${hopDeep}"/><text x="${pillW / 2}" y="13.5" text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="10.5" font-weight="500" fill="${bg}" letter-spacing="0.4">pH ${latest.ph.toFixed(2)}</text></g>`);
          out.push(`<g transform="translate(${pillX}, ${fgPillY})"><rect x="0" y="0" width="${pillW}" height="${pillH}" rx="2" fill="${ember}"/><text x="${pillW / 2}" y="13.5" text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="10.5" font-weight="500" fill="${bg}" letter-spacing="0.4">${latest.fg.toFixed(1)} °P</text></g>`);
        }
      }

      // Estimated CC marker
      const ccEst = data.cc_estimated;
      if (ccEst != null) {
        const ex = xPos(ccEst);
        out.push(`<line x1="${ex}" y1="${CHART.pad.top + 14}" x2="${ex}" y2="${CHART.h - CHART.pad.bottom}" stroke="${cold}" stroke-width="1" stroke-dasharray="3 4" opacity="0.55"/>`);
        out.push(`<g transform="translate(${ex}, ${CHART.pad.top + 6})"><text text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="9" fill="${bbt}" font-weight="500" letter-spacing="0.8">❄ CC ~J+${ccEst}</text></g>`);
      }
    }

    // Snap-point focus rings (toggled by JS during hover)
    out.push(`<circle id="cct-hover-fg" class="cct-modal__hover-dot" r="5.5" fill="none" stroke="${ember}" stroke-width="1.5" style="display:none" pointer-events="none"/>`);
    out.push(`<circle id="cct-hover-ph" class="cct-modal__hover-dot" r="4.8" fill="none" stroke="${hopDeep}" stroke-width="1.5" style="display:none" pointer-events="none"/>`);
    out.push(`<line   id="cct-hover-x"  class="cct-modal__hover-dot" x1="0" y1="${CHART.pad.top}" x2="0" y2="${CHART.h - CHART.pad.bottom}" stroke="${oak}" stroke-width="0.6" stroke-dasharray="2 3" opacity="0.55" style="display:none" pointer-events="none"/>`);

    svgEl.innerHTML = out.join('');
  }

  // ── Chips render ──
  function renderChips(container, data) {
    if (!container || !data) return;
    const out = [];
    out.push(`<button class="batch-chip batch-chip--current" disabled aria-pressed="true"><span class="batch-chip__dot"></span>${data.batch}</button>`);
    const hist = data.historical || [];
    for (const b of hist) {
      const active = !!VISIBLE[b.batch];
      out.push(`<button class="batch-chip ${active ? '' : 'batch-chip--inactive'}" data-batch="${b.batch}" aria-pressed="${active}"><span class="batch-chip__dot"></span>${b.batch}</button>`);
    }
    container.innerHTML = out.join('');
    container.querySelectorAll('button[data-batch]').forEach(btn => {
      btn.addEventListener('click', () => {
        const k = btn.dataset.batch;
        VISIBLE[k] = !VISIBLE[k];
        const svgEl = document.getElementById('cct-ferm-chart');
        renderChart(svgEl, CURRENT_DATA);
        renderChips(container, data);
      });
    });
  }

  // ── Card HTML builder ──
  function renderCard(detail) {
    const isCold = detail.state === 'cold';
    const stateClass = isCold ? 'cct-modal__card--cold' : '';
    const stateLabel = isCold ? 'Cold Crash' : 'Fermentation';
    const stateModifier = isCold ? 'cct-modal__state--cold' : '';
    const fillPct = detail.capacity_hl > 0
      ? Math.round(Math.min(1, detail.volume_hl / detail.capacity_hl) * 100)
      : 0;

    const m = detail.metrics || {};
    const y = detail.yeast   || {};

    const ogFmt      = m.og        != null ? `${(+m.og).toFixed(1)}`        : null;
    const fgFmt      = m.fg_current!= null ? `${(+m.fg_current).toFixed(1)}`: null;
    const phFmt      = m.ph_current!= null ? `${(+m.ph_current).toFixed(2)}`: null;
    const tFgFmt     = m.target_fg != null ? `${(+m.target_fg).toFixed(1)}` : null;
    const tPhFmt     = m.target_ph != null ? `${(+m.target_ph).toFixed(2)}` : null;
    const attenFmt   = m.attenuation_pct  != null ? `${m.attenuation_pct}` : null;
    const progressFmt= m.progress_pct     != null ? `${m.progress_pct}`    : null;

    const hist = detail.historical || [];
    const curReads = detail.current_reads || [];
    const histCount = hist.length;

    return `
<div class="cct-modal__card ${stateClass}" id="cct-modal-card">
  <button class="cct-modal__close" id="cct-modal-close" aria-label="Fermer">&#x2715;</button>
  <header class="cct-modal__head">
    <div class="cct-modal__id">
      <span class="cct-modal__id-label">Fermenteur</span>
      <span class="cct-modal__id-num">${String(detail.cct).padStart(2, '0')}</span>
    </div>
    <div class="cct-modal__title">
      <h2 class="cct-modal__beer">${escHtml(detail.beer)}<span class="batch">${escHtml(detail.batch)}</span></h2>
      <p class="cct-modal__meta">
        ${detail.beer_classification ? `<span class="style">${escHtml(detail.beer_classification)}</span><span class="sep">·</span>` : ''}
        ${detail.brewdate ? `<span>brassé le ${escHtml(detail.brewdate)}</span><span class="sep">·</span>` : ''}
        ${detail.days_in != null ? `<span class="days">J+${detail.days_in}</span>` : ''}
        ${detail.cc_estimated != null && !isCold ? `<span class="sep">·</span><span>CC estimé J+${detail.cc_estimated}</span>` : ''}
        ${isCold && detail.cc_actual != null ? `<span class="sep">·</span><span>❄ CC J+${detail.cc_actual}</span>` : ''}
      </p>
    </div>
    <span class="cct-modal__state ${stateModifier}">
      <span class="cct-modal__pulse"></span>
      ${escHtml(stateLabel)}
    </span>
  </header>

  <section class="cct-modal__data">
    <div class="cct-modal__tankcol">
      <div class="cct-modal__stage">
        <span class="cct-modal__stage-tag">Vue technique</span>
        <div id="cct-modal-svg-host"></div>
      </div>
      <span class="vol">${(+detail.volume_hl).toFixed(1)}<span class="unit">HL</span></span>
      <span class="cap">sur ${(+detail.capacity_hl).toFixed(0)} HL · ${fillPct}%</span>
      <div class="cap-bar" style="--pct: ${fillPct}%"></div>
    </div>

    <div class="cct-modal__col">
      <h3 class="cct-modal__col-head">Mesures</h3>
      <dl>
        <div class="cct-modal__row">
          <dt>OG</dt>
          <dd>${ogFmt != null ? `${ogFmt}<span class="unit">°P</span>` : '<span style="color:var(--ink-mute);font-size:18px">—</span>'}</dd>
          <span class="target">au cooling</span>
        </div>
        <div class="cct-modal__row cct-modal__row--hoverable" data-reads-tip="fg">
          <dt>FG</dt>
          <dd>${fgFmt != null ? `${fgFmt}<span class="unit">°P</span>` : '<span style="color:var(--ink-mute);font-size:18px">—</span>'}</dd>
          <span class="target">${tFgFmt != null ? `cible <strong>${tFgFmt}</strong>` : ''}</span>
        </div>
        <div class="cct-modal__row cct-modal__row--hoverable" data-reads-tip="ph">
          <dt>pH</dt>
          <dd>${phFmt != null ? phFmt : '<span style="color:var(--ink-mute);font-size:18px">—</span>'}</dd>
          <span class="target">${tPhFmt != null ? `cible <strong>${tPhFmt}</strong>` : ''}</span>
        </div>
      </dl>
      <div class="cct-modal__pillrow">
        <div class="cct-modal__pill" style="--pct: ${attenFmt ?? 0}%">
          <span class="cct-modal__pill-label">Atténuation</span>
          <span class="cct-modal__pill-value">${attenFmt ?? '—'}<span class="unit">%</span></span>
          <span class="cct-modal__pill-bar"></span>
        </div>
        <div class="cct-modal__pill" style="--pct: ${progressFmt ?? 0}%">
          <span class="cct-modal__pill-label">Progression</span>
          <span class="cct-modal__pill-value">${progressFmt ?? '—'}<span class="unit">%</span></span>
          <span class="cct-modal__pill-bar"></span>
        </div>
      </div>
    </div>

    <div class="cct-modal__col">
      <h3 class="cct-modal__col-head">Levure</h3>
      <dl>
        <div class="cct-modal__row cct-modal__row--mono">
          <dt>Souche</dt>
          <dd>${y.strain ? escHtml(y.strain) : '<span style="color:var(--ink-mute);font-size:18px">—</span>'}</dd>
          <span class="target"></span>
        </div>
        <div class="cct-modal__row">
          <dt>Gén</dt>
          <dd>${y.generation != null ? y.generation : '<span style="color:var(--ink-mute);font-size:18px">—</span>'}</dd>
          <span class="target">${y.repitch_count ? `re-pitch <strong>${y.repitch_count}×</strong>` : ''}</span>
        </div>
        ${y.pitched_from ? `
        <div class="cct-modal__row cct-modal__row--mono">
          <dt>De</dt>
          <dd>${escHtml(y.pitched_from)}</dd>
          <span class="target">pitched from</span>
        </div>` : ''}
        <div class="cct-modal__row cct-modal__row--placeholder">
          <dt>Vers</dt>
          <dd></dd>
          <span class="target">pitched into</span>
        </div>
        <div class="cct-modal__row cct-modal__row--placeholder">
          <dt>PCR</dt>
          <dd></dd>
          <span class="target">contrôle ADN</span>
        </div>
      </dl>
    </div>
  </section>

  <section class="cct-modal__chart-section">
    <div class="cct-modal__chart-head">
      <h3 class="cct-modal__chart-title">Courbe de fermentation</h3>
      <p class="cct-modal__chart-sub">FG &amp; pH dans le temps · ${histCount + 1} lots</p>
    </div>
    <div class="cct-modal__legend">
      <span class="cct-modal__legend-item">
        <span class="cct-modal__legend-swatch" style="background: var(--ember);"></span>
        FG &nbsp;<span style="color: var(--ink-mute); font-weight: 400;">°Plato</span>
      </span>
      <span class="cct-modal__legend-item">
        <span class="cct-modal__legend-swatch cct-modal__legend-swatch--ph"></span>
        pH
      </span>
      <span class="cct-modal__legend-note">─── lot ${escHtml(detail.batch)} (en cours) &nbsp;·&nbsp; ┄ ┄ ┄ &nbsp;${histCount} lots précédents</span>
    </div>
    <svg class="cct-modal__chart-svg" viewBox="0 0 800 340" id="cct-ferm-chart" preserveAspectRatio="xMidYMid meet"></svg>
    <div class="cct-modal__chips-row">
      <span class="cct-modal__chips-label">Lots affichés</span>
      <div class="cct-modal__chips" id="cct-batch-chips"></div>
    </div>
  </section>
</div>`;
  }

  function escHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // ── Open modal ──
  function openModal(cctNum) {
    const details = (window.CCT_DETAILS || {})[cctNum];
    if (!details) return;

    CURRENT_DATA = details;
    VISIBLE = { current: true };
    (details.historical || []).forEach(b => { VISIBLE[b.batch] = true; });

    const dialog = document.getElementById('cct-detail-modal');
    if (!dialog) return;

    // Populate card
    dialog.innerHTML = `
      <div class="cct-modal__overlay" data-close></div>
      ${renderCard(details)}
    `;

    // Clone SVG template into stage host
    const tmpl = document.getElementById('cct-svg-' + cctNum);
    const host = dialog.querySelector('#cct-modal-svg-host');
    if (tmpl && host) {
      const clone = tmpl.content.cloneNode(true);
      host.appendChild(clone);
    }

    // Render chart
    renderChart(dialog.querySelector('#cct-ferm-chart'), details);
    renderChips(dialog.querySelector('#cct-batch-chips'), details);

    // Hover interactions (tooltips + curve highlighting)
    wireChartHover(dialog.querySelector('#cct-ferm-chart'), details);
    wireMesuresHover(dialog, details);

    // Wire close button
    dialog.querySelector('#cct-modal-close')?.addEventListener('click', () => { hideTooltip(); dialog.close(); });
    dialog.querySelector('[data-close]')?.addEventListener('click',     () => { hideTooltip(); dialog.close(); });
    dialog.addEventListener('close', hideTooltip);

    dialog.showModal();
  }

  // ── Chart-curve hover: highlight curve + scrub the closest datapoint ──
  function wireChartHover(svgEl, data) {
    if (!svgEl) return;
    const hist = data.historical || [];
    const byBatch = {};
    for (const b of hist) byBatch[b.batch] = { reads: b.reads || [], cc_day: b.cc_day };

    const focusFg = svgEl.querySelector('#cct-hover-fg');
    const focusPh = svgEl.querySelector('#cct-hover-ph');
    const focusX  = svgEl.querySelector('#cct-hover-x');
    const hideFocus = () => {
      if (focusFg) focusFg.style.display = 'none';
      if (focusPh) focusPh.style.display = 'none';
      if (focusX)  focusX.style.display  = 'none';
    };

    // viewport client coords → SVG viewBox coords
    function svgPt(clientX) {
      const rect = svgEl.getBoundingClientRect();
      return (clientX - rect.left) * (CHART.w / rect.width);
    }
    // SVG viewBox X → day (fractional, snap separately)
    function xToDay(svgX) {
      const span = (CHART.w - CHART.pad.left - CHART.pad.right);
      return (svgX - CHART.pad.left) / span * (CHART.dayMax - CHART.dayMin) + CHART.dayMin;
    }

    function update(e, g) {
      const batch = g.dataset.batch;
      const info  = byBatch[batch];
      if (!info || !info.reads.length) return;

      const dayCursor = xToDay(svgPt(e.clientX));
      let nearest = info.reads[0];
      let minDist = Math.abs(nearest.day - dayCursor);
      for (const r of info.reads) {
        const d = Math.abs(r.day - dayCursor);
        if (d < minDist) { minDist = d; nearest = r; }
      }

      // Move focus rings to the snap point
      const snapX = xPos(nearest.day);
      if (nearest.fg != null && focusFg) {
        focusFg.setAttribute('cx', String(snapX));
        focusFg.setAttribute('cy', String(yFG(nearest.fg)));
        focusFg.style.display = 'block';
      } else if (focusFg) { focusFg.style.display = 'none'; }
      if (nearest.ph != null && focusPh) {
        focusPh.setAttribute('cx', String(snapX));
        focusPh.setAttribute('cy', String(yPH(nearest.ph)));
        focusPh.style.display = 'block';
      } else if (focusPh) { focusPh.style.display = 'none'; }
      if (focusX) {
        focusX.setAttribute('x1', String(snapX));
        focusX.setAttribute('x2', String(snapX));
        focusX.style.display = 'block';
      }

      // Build tooltip
      const fgL = nearest.fg != null ? `<div class="cct-modal__tooltip-row"><span class="dt">FG</span><span class="dd">${(+nearest.fg).toFixed(1)} °P</span></div>` : '';
      const phL = nearest.ph != null ? `<div class="cct-modal__tooltip-row"><span class="dt">pH</span><span class="dd">${(+nearest.ph).toFixed(2)}</span></div>` : '';
      const ccL = info.cc_day === nearest.day
        ? `<div class="cct-modal__tooltip-row cct-modal__tooltip-row--cc"><span class="dt">❄ CC</span><span class="dd">J+${info.cc_day}</span></div>`
        : '';
      showTooltip(
        `<div class="cct-modal__tooltip-head"><span>Lot ${escHtml(batch)}</span><span class="day">J+${nearest.day}</span></div>${fgL}${phL}${ccL}`,
        e.clientX, e.clientY
      );
    }

    svgEl.addEventListener('mouseover', (e) => {
      const g = e.target.closest('.cct-modal__hist-batch');
      if (!g) return;
      g.classList.add('is-hover');
      update(e, g);
    });
    svgEl.addEventListener('mousemove', (e) => {
      const g = e.target.closest('.cct-modal__hist-batch');
      if (!g) return;
      update(e, g);
    });
    svgEl.addEventListener('mouseout', (e) => {
      const g = e.target.closest('.cct-modal__hist-batch');
      if (!g) return;
      if (!g.contains(e.relatedTarget)) {
        g.classList.remove('is-hover');
        hideFocus();
        hideTooltip();
      }
    });
  }

  // ── Mesures FG/pH hover: list-all-reads popup ──
  function wireMesuresHover(dialog, data) {
    const reads = data.current_reads || [];
    dialog.querySelectorAll('[data-reads-tip]').forEach(el => {
      el.addEventListener('mouseenter', () => {
        const kind = el.dataset.readsTip;        // 'fg' | 'ph'
        const filt = reads.filter(r => r[kind] != null);
        if (!filt.length) return;
        const fmt = kind === 'fg' ? v => `${(+v).toFixed(1)} °P` : v => (+v).toFixed(2);
        const head = kind === 'fg' ? 'Historique FG' : 'Historique pH';
        const rows = filt
          .map(r => `<div class="cct-modal__tooltip-row"><span class="dt">J+${r.day}</span><span class="dd">${fmt(r[kind])}</span></div>`)
          .join('');
        const rect = el.getBoundingClientRect();
        showTooltip(
          `<div class="cct-modal__tooltip-head"><span>${head}</span><span class="day">${filt.length} lectures</span></div>${rows}`,
          rect.right + 4, rect.top + rect.height / 2
        );
      });
      el.addEventListener('mouseleave', hideTooltip);
    });
  }

  // ── Event delegation — open on [data-cct] click ──
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-cct]');
    if (!btn) return;
    openModal(+btn.dataset.cct);
  });

  // ── ESC key already handled natively by <dialog> ──

})();
