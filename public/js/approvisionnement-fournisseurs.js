/**
 * approvisionnement-fournisseurs.js
 * READ-ONLY supplier dashboard — consumes window.SUPPLIERS injected by PHP.
 * Design: "Kraft / manila dossiers" — warm analog warehouse records aesthetic.
 * All write paths removed. No DB mutations.
 */
'use strict';

/* ── State ────────────────────────────────────────────────────── */
let activeId      = null;
let currentFilter = 'all';
let currentSearch = '';
let currentSort   = 'alpha';
let activeTab     = 'overview';
let inlineOpenId  = null;   /* invoice id with expanded inline preview */

const SUPPLIERS   = window.SUPPLIERS  || [];
const DEDUP_PAIRS = window.DEDUP_PAIRS || [];

/* ── Utility ──────────────────────────────────────────────────── */

function escHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function showToast(msg) {
  const t = document.getElementById('af-toast');
  if (!t) return;
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2600);
}

function getRole() {
  return document.body.dataset.role || 'operateur';
}

/* ── Formatting helpers ───────────────────────────────────────── */

function fmtChf(val, digits) {
  if (val == null || val === '' || isNaN(parseFloat(val))) return '—';
  return new Intl.NumberFormat('fr-CH', {
    style: 'currency', currency: 'CHF',
    maximumFractionDigits: digits ?? 0,
  }).format(parseFloat(val));
}

function fmtNum(val) {
  if (val == null || val === '') return '—';
  return new Intl.NumberFormat('fr-CH').format(parseInt(val, 10));
}

function fmtDate(s) {
  if (!s) return '—';
  const d = s.slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) return escHtml(s.slice(0, 10));
  const [y, m, dy] = d.split('-');
  return `${dy}.${m}.${y}`;
}

/* GL category → accent color for folder-tab spines.
   Colours are adjusted for light kraft background (darker, saturated) */
const GL_ACCENT = {
  '4101': '#7a4e10',
  '4102': '#3a5a10',
  '4103': '#1a4a6a',
  '4104': '#5a3a10',
  '4201': '#4a2a6a',
  '4202': '#4a2a6a',
  '4401': '#1a4a50',
  '4500': '#3a207a',
  '4510': '#3a207a',
  '4701': '#3a4a20',
  '6100': '#3a3a3a',
  '6281': '#2a3a5a',
  '1302': '#6b4e10',
};
function glAccent(gl) {
  return GL_ACCENT[String(gl)] || '#5a3a1a';
}

function parseVolume(s) {
  if (s.stats && s.stats.total_chf) return parseFloat(s.stats.total_chf) || 0;
  return 0;
}

/* ── Filters & sort ───────────────────────────────────────────── */

function filteredSuppliers() {
  let result = SUPPLIERS.slice();

  if (currentFilter === 'active')    result = result.filter(s => s.is_active);
  /* 'parser' chip removed — parser is not an operator filter */
  else if (currentFilter === 'catalogue') result = result.filter(s => s.catalogue && s.catalogue.length > 0);
  else if (currentFilter === 'hors')      result = result.filter(s => s.hors_perimetre);

  if (currentSearch) {
    const q = currentSearch.toLowerCase();
    result = result.filter(s =>
      s.name.toLowerCase().includes(q)
      || (s.supplier_id || '').toLowerCase().includes(q)
      || (s.gl_account  || '').includes(q)
      || (s.aliases     || []).some(a => a.toLowerCase().includes(q))
      || (s.parser_key  || '').toLowerCase().includes(q)
    );
  }

  if (currentSort === 'volume') {
    result = result.slice().sort((a, b) => parseVolume(b) - parseVolume(a));
  } else {
    result = result.slice().sort((a, b) => a.name.localeCompare(b.name));
  }

  return result;
}

/* ── Index-card list render ───────────────────────────────────── */

function renderList() {
  const list  = document.getElementById('af-sup-list');
  const count = document.getElementById('af-list-count');
  if (!list) return;

  const items  = filteredSuppliers();
  const maxVol = Math.max(...items.map(parseVolume), 1);

  list.innerHTML = items.map(s => {
    const accent   = glAccent(s.gl_account);
    const vol      = parseVolume(s);
    const volPct   = Math.min(100, Math.round(vol / maxVol * 100));
    const volFmt   = vol > 0 ? fmtChf(vol) : '—';
    const miCount  = (s.catalogue || []).length;
    const invCount = (s.invoices  || []).length;
    const isActive = s.id === activeId;

    const statusDot = s.is_active
      ? `<span class="af-ldg-dot af-ldg-dot-active" title="Actif"></span>`
      : `<span class="af-ldg-dot af-ldg-dot-inactive" title="Inactif"></span>`;
    const horsMark = s.hors_perimetre
      ? `<span class="af-ldg-chip af-ldg-chip-hors" title="Hors périmètre COGS">H</span>`
      : '';

    return `<div class="af-ledger${isActive ? ' active' : ''}${!s.is_active ? ' af-ledger-inactive' : ''}"
      data-id="${s.id}" role="button" tabindex="0" aria-label="${escHtml(s.name)}"
      style="--ldg-accent:${accent}">
      <div class="af-ldg-spine"></div>
      <div class="af-ldg-body">
        <div class="af-ldg-header">
          <div class="af-ldg-name">${escHtml(s.name)}</div>
          <div class="af-ldg-chips">
            ${statusDot}${horsMark}
          </div>
        </div>
        <div class="af-ldg-meta">
          <span class="af-ldg-gl">GL ${escHtml(s.gl_account || '—')}</span>
          <span class="af-ldg-sep">·</span>
          <span class="af-ldg-cur">${escHtml(s.currency || '—')}</span>
          ${miCount > 0 ? `<span class="af-ldg-sep">·</span><span class="af-ldg-mi">${miCount} MI</span>` : ''}
        </div>
        <div class="af-ldg-foot">
          <div class="af-ldg-vol-bar" aria-hidden="true">
            <div class="af-ldg-vol-fill" style="width:${volPct}%;background:${accent}"></div>
          </div>
          <div class="af-ldg-vol-row">
            <span class="af-ldg-vol-amt">${volFmt}</span>
            <span class="af-ldg-inv-count">${invCount} fact.</span>
          </div>
        </div>
      </div>
    </div>`;
  }).join('');

  if (count) count.textContent = `${items.length} / ${SUPPLIERS.length} fournisseurs`;

  list.querySelectorAll('.af-ledger').forEach(row => {
    row.addEventListener('click', () => openFiche(parseInt(row.dataset.id, 10)));
    row.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openFiche(parseInt(row.dataset.id, 10));
      }
    });
  });
}

/* ── KPI sparkline helpers ────────────────────────────────────── */

function buildSparkline(points, w, h, color) {
  if (!points || points.length < 2) {
    return `<svg width="${w}" height="${h}" aria-hidden="true"></svg>`;
  }
  const vals = points.map(p => parseFloat(p.value) || 0);
  const maxV = Math.max(...vals, 1);
  const minV = Math.min(...vals, 0);
  const range = maxV - minV || 1;
  const pad = 2;
  const pts = points.map((p, i) => {
    const x = pad + (i / (points.length - 1)) * (w - pad * 2);
    const y = h - pad - ((parseFloat(p.value) || 0) - minV) / range * (h - pad * 2);
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  }).join(' ');
  return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" aria-hidden="true" preserveAspectRatio="none">
    <polyline points="${pts}" fill="none" stroke="${color}" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" opacity=".6"/>
  </svg>`;
}

function invoiceSparkPoints(invoices) {
  const monthly = {};
  for (const inv of invoices) {
    if (!inv.date) continue;
    const key = inv.date.slice(0, 7);
    const val = parseFloat(inv.total_ht ?? inv.total_ttc) || 0;
    monthly[key] = (monthly[key] || 0) + val;
  }
  const keys = Object.keys(monthly).sort();
  return keys.map(k => ({ date: k, value: monthly[k] }));
}

/* ── GL footprint mini-donut SVG ──────────────────────────────── */

function glDonut(fp, r) {
  const size = r * 2 + 4;
  const cx   = r + 2;
  const cy   = r + 2;
  const ir   = r * 0.55;
  const total = fp.reduce((s, x) => s + (parseFloat(x.chf) || 0), 0);
  if (!total) return '';
  let startAngle = -Math.PI / 2;
  const slices = fp.map((item) => {
    const frac  = (parseFloat(item.chf) || 0) / total;
    const angle = frac * 2 * Math.PI;
    const x1 = cx + r * Math.cos(startAngle);
    const y1 = cy + r * Math.sin(startAngle);
    startAngle += angle;
    const x2 = cx + r * Math.cos(startAngle);
    const y2 = cy + r * Math.sin(startAngle);
    const xi1 = cx + ir * Math.cos(startAngle - angle);
    const yi1 = cy + ir * Math.sin(startAngle - angle);
    const xi2 = cx + ir * Math.cos(startAngle);
    const yi2 = cy + ir * Math.sin(startAngle);
    const large = angle > Math.PI ? 1 : 0;
    const col = glAccent(item.gl);
    return `<path d="M ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2} L ${xi2} ${yi2} A ${ir} ${ir} 0 ${large} 0 ${xi1} ${yi1} Z" fill="${col}" opacity=".85"/>`;
  }).join('');
  /* Light paper circle border */
  return `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" aria-hidden="true">
    <circle cx="${cx}" cy="${cy}" r="${r+1}" fill="#dcc9a4" opacity=".4"/>
    ${slices}
  </svg>`;
}

/* ── Completeness ring ────────────────────────────────────────── */

function completenessRing(s) {
  const checks = [
    !!s.name,
    !!s.gl_account,
    !!s.currency,
    !!(s.country && !s.country_display.includes('présumé')),
    !!(s.vat_regime),
  ];
  const filled = checks.filter(Boolean).length;
  const total  = checks.length;
  const pct    = filled / total;
  const r      = 14;
  const circ   = 2 * Math.PI * r;
  const offset = circ * (1 - pct);
  const color  = filled === total ? '#3d6b2c' : '#7a2f25';

  return `<svg class="af-completeness-ring af-ring-svg" viewBox="0 0 36 36" aria-label="${filled}/${total} champs renseignés">
    <circle cx="18" cy="18" r="${r}" fill="none" stroke="rgba(160,128,96,.25)" stroke-width="4"/>
    <circle cx="18" cy="18" r="${r}" fill="none" stroke="${color}" stroke-width="4"
      stroke-dasharray="${circ.toFixed(2)}" stroke-dashoffset="${offset.toFixed(2)}"
      transform="rotate(-90 18 18)"/>
    <text x="18" y="22" text-anchor="middle" font-family="JetBrains Mono,monospace" font-size="9" fill="${color}">${filled}/${total}</text>
  </svg>`;
}

/* ── GL footprint bar render ──────────────────────────────────── */

function renderGlFootprint(s) {
  const fp = s.gl_footprint || [];

  if (!fp.length) {
    const glLabel = s.gl_label || s.gl_account || '—';
    return `<div class="af-gl-footprint">
      <div class="af-gl-footprint-head">
        <span class="af-gl-footprint-title">Empreinte GL</span>
        <span class="af-gl-footprint-calc">⟳ données historiques</span>
      </div>
      <div class="af-gl-bar-row">
        <span class="af-gl-bar-label">${escHtml(s.gl_account || '—')} · ${escHtml(glLabel)}</span>
        <div class="af-gl-bar-track"><div class="af-gl-bar-fill" style="width:100%;background:${glAccent(s.gl_account)}"></div></div>
        <span class="af-gl-bar-pct">100%</span>
        <span class="af-gl-bar-modal">modal ✓</span>
      </div>
      <div class="af-gl-exclusion-note">Ligne unique · passthrough TVA et fret non-transport exclus.</div>
    </div>`;
  }

  const totalChf = fp.reduce((sum, item) => sum + (parseFloat(item.chf) || 0), 0);
  const maxChf   = Math.max(...fp.map(item => parseFloat(item.chf) || 0));

  const barsHtml = fp.map((item) => {
    const pct     = totalChf > 0 ? Math.round((parseFloat(item.chf) || 0) / totalChf * 100) : 0;
    const isModal = (parseFloat(item.chf) || 0) === maxChf;
    const gl      = escHtml(item.gl || '—');
    const label   = escHtml(item.gl_label || item.gl || '—');
    const col     = glAccent(item.gl);
    return `<div class="af-gl-bar-row">
      <span class="af-gl-bar-label">${gl} · ${label}</span>
      <div class="af-gl-bar-track"><div class="af-gl-bar-fill" style="width:${pct}%;background:${col}"></div></div>
      <span class="af-gl-bar-pct">${pct}%</span>
      ${isModal ? '<span class="af-gl-bar-modal">modal ✓</span>' : ''}
    </div>`;
  }).join('');

  const multiCallout = fp.filter(item => {
    const pct = totalChf > 0 ? (parseFloat(item.chf) || 0) / totalChf * 100 : 0;
    return pct > 10;
  }).length > 2 ? `<div class="af-gl-multi-callout">
    <p>Ce fournisseur livre sur plusieurs GLs (fournisseur multi-GL). L'ingestion assigne le GL à la ligne MI, pas au fournisseur.</p>
  </div>` : '';

  const primaryItem = fp.find(item => (parseFloat(item.chf) || 0) === maxChf) || fp[0];
  const glPrimaryVal = primaryItem
    ? `${escHtml(primaryItem.gl)} · ${escHtml(primaryItem.gl_label || primaryItem.gl)}`
    : escHtml(s.gl_account || '—');

  return `<div class="af-gl-footprint">
    <div class="af-gl-footprint-head">
      <span class="af-gl-footprint-title">Empreinte GL</span>
      <span class="af-gl-footprint-calc">⟳ calculé · historique</span>
    </div>
    ${barsHtml}
    <div class="af-gl-exclusion-note">Passthrough TVA import + fret inbound non-transport exclus.</div>
    ${multiCallout}
    <div class="af-gl-primary-row">
      <span class="af-gl-primary-label">GL principal :</span>
      <span class="af-gl-primary-value">${glPrimaryVal}</span>
    </div>
  </div>`;
}

/* ── Tab switcher ──────────────────────────────────────────────── */

function switchTab(tab) {
  activeTab = tab;
  inlineOpenId = null;
  const s = SUPPLIERS.find(x => x.id === activeId);
  if (!s) return;

  document.querySelectorAll('.af-db-tab').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.tab === tab);
    btn.setAttribute('aria-selected', btn.dataset.tab === tab);
  });
  document.querySelectorAll('.af-db-pane').forEach(pane => {
    pane.hidden = pane.dataset.pane !== tab;
  });
}

/* ── Overview tab ─────────────────────────────────────────────── */

function renderOverviewPane(s) {
  const stats    = s.stats || {};
  const invoices = s.invoices || [];
  const fp       = s.gl_footprint || [];

  const totalChf   = stats.total_chf ? parseFloat(stats.total_chf) : null;
  const invCount   = stats.invoices ?? 0;
  const miCount    = (s.catalogue || []).length;
  const dlvCount   = (s.catalogue || []).reduce((sum, m) => sum + (m.deliveries || 0), 0);
  const avgInv     = invCount > 0 && totalChf ? totalChf / invCount : null;

  const sortedInv  = invoices.slice().sort((a, b) => (b.date || '').localeCompare(a.date || ''));
  const lastInvDate = sortedInv.length > 0 ? fmtDate(sortedInv[0].date) : '—';

  const sparkPts = invoiceSparkPoints(invoices);
  const sparkSvg = buildSparkline(sparkPts, 80, 28, '#8b5e2a');
  const donutSvg = fp.length > 1 ? glDonut(fp, 32) : '';

  const countryDisplay = s.country_display || '—';
  const countryNeedsConfirm = countryDisplay.includes('présumé') || countryDisplay.includes('à renseigner');

  /* Parser info: shown as a small informational chip (not a filter surface) */
  const parserHtml = s.parser_key
    ? `<div class="af-doc-parser-chip"><span class="parser-dot"></span>${escHtml(s.parser_key)}</div>`
    : `<div class="af-doc-no-parser">Pas de parseur automatique</div>`;

  const horsHtml = s.hors_perimetre
    ? `<div class="af-hors-banner">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
          <line x1="8" y1="4" x2="8" y2="9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          <circle cx="8" cy="11.5" r=".8" fill="currentColor"/>
        </svg>
        <span>Hors périmètre COGS — documents auto-ignorés à l'ingestion.</span>
       </div>` : '';

  const aliasesHtml = (s.aliases && s.aliases.length > 0)
    ? `<div class="af-aliases-strip">
        <span class="af-alias-label">Alias</span>
        ${s.aliases.map(a => `<span class="af-alias-tag">${escHtml(a)}</span>`).join('')}
      </div>` : '';

  return `
    ${horsHtml}
    <div class="af-kpi-row">
      <div class="af-kpi-card af-kpi-primary">
        <div class="af-kpi-label">Volume total CHF</div>
        <div class="af-kpi-val">${totalChf != null ? fmtChf(totalChf) : '—'}</div>
        <div class="af-kpi-spark">${sparkSvg}</div>
        <div class="af-kpi-sub">cumulé livraisons Active/Consumed</div>
      </div>
      <div class="af-kpi-card">
        <div class="af-kpi-label">Factures</div>
        <div class="af-kpi-val af-kpi-val-sm">${fmtNum(invCount)}</div>
        <div class="af-kpi-sub">actives en base</div>
      </div>
      <div class="af-kpi-card">
        <div class="af-kpi-label">Moy. / facture</div>
        <div class="af-kpi-val af-kpi-val-sm">${avgInv != null ? fmtChf(avgInv) : '—'}</div>
        <div class="af-kpi-sub">CHF par facture active</div>
      </div>
      <div class="af-kpi-card">
        <div class="af-kpi-label">Ingrédients</div>
        <div class="af-kpi-val af-kpi-val-sm">${fmtNum(miCount)}</div>
        <div class="af-kpi-sub">${fmtNum(dlvCount)} lignes BL</div>
      </div>
      <div class="af-kpi-card">
        <div class="af-kpi-label">Dernière facture</div>
        <div class="af-kpi-val af-kpi-val-sm af-kpi-val-date">${lastInvDate}</div>
        <div class="af-kpi-sub">invoice la plus récente</div>
      </div>
    </div>

    <div class="af-ov-section-title">Identité fournisseur</div>
    <div class="af-ov-fields">
      <div class="af-ov-field">
        <div class="af-ov-field-lbl">Pays / origine</div>
        <div class="af-ov-field-val">${countryNeedsConfirm
          ? `${escHtml(countryDisplay)} <span class="af-pending-tag">à confirmer</span>`
          : escHtml(countryDisplay)}</div>
      </div>
      <div class="af-ov-field">
        <div class="af-ov-field-lbl">Devise</div>
        <div class="af-ov-field-val mono">${escHtml(s.currency || '—')}</div>
      </div>
      <div class="af-ov-field">
        <div class="af-ov-field-lbl">Régime TVA</div>
        <div class="af-ov-field-val">${s.vat_regime
          ? escHtml(s.vat_regime)
          : '<span class="af-pending-tag">à renseigner</span>'}</div>
      </div>
      <div class="af-ov-field">
        <div class="af-ov-field-lbl">GL principal</div>
        <div class="af-ov-field-val mono">${escHtml(s.gl_account || '—')}${s.gl_label ? ' · ' + escHtml(s.gl_label) : ''}</div>
      </div>
      <div class="af-ov-field">
        <div class="af-ov-field-lbl">N° TVA</div>
        <div class="af-ov-field-val mono">${s.vat_number ? escHtml(s.vat_number) : '<span style="color:var(--kf-brown);font-style:italic">—</span>'}</div>
      </div>
      <div class="af-ov-field">
        <div class="af-ov-field-lbl">Parseur facture</div>
        <div class="af-ov-field-val">${parserHtml}</div>
      </div>
      ${s.notes ? `<div class="af-ov-field af-ov-field-wide">
        <div class="af-ov-field-lbl">Notes</div>
        <div class="af-ov-field-val">${escHtml(s.notes)}</div>
      </div>` : ''}
    </div>

    ${aliasesHtml}

    <div class="af-ov-section-title">Empreinte comptable</div>
    <div class="af-gl-ov-wrap">
      ${fp.length > 1 ? `<div class="af-gl-donut">${donutSvg}</div>` : ''}
      <div class="af-gl-bars-wrap">${renderGlFootprint(s)}</div>
    </div>

    ${sparkPts.length > 1 ? renderActivityChart(sparkPts) : ''}
  `;
}

/* ── Activity bar chart ───────────────────────────────────────── */

function renderActivityChart(pts) {
  if (!pts || pts.length < 2) return '';
  const vals = pts.map(p => parseFloat(p.value) || 0);
  const maxV = Math.max(...vals, 1);
  const barW = Math.max(4, Math.min(18, Math.floor(280 / pts.length) - 2));
  const H    = 40;

  const bars = pts.map((p, i) => {
    const h   = Math.max(2, Math.round(p.value / maxV * H));
    const x   = i * (barW + 2);
    const y   = H - h;
    const col = p.value === maxV ? '#5a3a1a' : 'rgba(139,94,42,.45)';
    return `<rect x="${x}" y="${y}" width="${barW}" height="${h}" rx="2" fill="${col}">
      <title>${escHtml(p.date)} · ${fmtChf(p.value)}</title></rect>`;
  }).join('');

  const totalW  = pts.length * (barW + 2) - 2;
  const lblFirst = escHtml(pts[0].date);
  const lblLast  = escHtml(pts[pts.length - 1].date);

  return `<div class="af-ov-section-title">Historique mensuel des achats</div>
  <div class="af-activity-chart">
    <svg width="${totalW}" height="${H + 2}" viewBox="0 0 ${totalW} ${H + 2}" aria-label="Historique mensuel" style="overflow:visible">
      ${bars}
    </svg>
    <div class="af-activity-lbl-row">
      <span>${lblFirst}</span>
      <span>${lblLast}</span>
    </div>
  </div>`;
}

/* ── Factures tab ─────────────────────────────────────────────── */

function renderFacturesPane(s) {
  const invoices = s.invoices || [];
  if (!invoices.length) {
    return `<div class="af-empty-catalogue">Aucune facture enregistrée pour ce fournisseur.</div>`;
  }

  const rows = invoices.map(inv => {
    const ref     = inv.ref || ('Facture #' + inv.id);
    const dateStr = fmtDate(inv.date);
    const htFmt   = fmtChf(inv.total_ht, 2);
    const ttcFmt  = fmtChf(inv.total_ttc, 2);
    const hasPdf  = inv.has_pdf && inv.drive_file_id;
    const isOpen  = inlineOpenId === inv.id;

    const previewBtn = hasPdf
      ? `<button class="af-inv-row-preview-btn${isOpen ? ' active' : ''}"
            data-inv-id="${inv.id}" data-file-id="${escHtml(inv.drive_file_id)}"
            data-ref="${escHtml(ref)}"
            aria-label="${isOpen ? 'Fermer l\'aperçu' : 'Aperçu'} ${escHtml(ref)}"
            title="${isOpen ? 'Fermer l\'aperçu' : 'Aperçu inline'}">
          <svg width="13" height="15" viewBox="0 0 13 15" fill="none" aria-hidden="true">
            <rect x=".75" y=".75" width="11.5" height="13.5" rx="1.5" stroke="currentColor" stroke-width="1.1"/>
            <line x1="3" y1="4.5" x2="10" y2="4.5" stroke="currentColor" stroke-width=".9" stroke-linecap="round"/>
            <line x1="3" y1="6.5" x2="10" y2="6.5" stroke="currentColor" stroke-width=".9" stroke-linecap="round"/>
            <line x1="3" y1="8.5" x2="7" y2="8.5" stroke="currentColor" stroke-width=".9" stroke-linecap="round"/>
          </svg>
          ${isOpen ? 'Fermer' : 'Aperçu'}
        </button>`
      : `<span class="af-inv-row-nopdf">pas de PDF</span>`;

    const fullscreenBtn = hasPdf
      ? `<button class="af-inv-row-fs-btn" data-file-id="${escHtml(inv.drive_file_id)}" data-ref="${escHtml(ref)}"
            aria-label="Plein écran ${escHtml(ref)}" title="Ouvrir en plein écran">
          <svg width="11" height="11" viewBox="0 0 12 12" fill="none" aria-hidden="true">
            <polyline points="1,4 1,1 4,1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            <polyline points="8,1 11,1 11,4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            <polyline points="11,8 11,11 8,11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            <polyline points="4,11 1,11 1,8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
          </svg>
        </button>` : '';

    const parserTag = inv.parser
      ? `<span class="af-inv-row-parser" title="${escHtml(inv.parser)}">${escHtml(inv.parser)}</span>`
      : '';

    /* Inline preview panel — NOT lazy; src set immediately on expand */
    const inlinePanel = isOpen && hasPdf
      ? `<tr class="af-inv-inline-row" data-inline-for="${inv.id}">
          <td colspan="6" class="af-inv-inline-cell">
            <div class="af-inv-inline-panel">
              <div class="af-inv-inline-header">
                <span class="af-inv-inline-ref">${escHtml(ref)} · ${escHtml(dateStr)}</span>
                <div class="af-inv-inline-actions">
                  <a class="af-inv-pdf-btn af-inv-pdf-btn-sm"
                     href="/api/document.php?file_id=${encodeURIComponent(inv.drive_file_id)}"
                     target="_blank" rel="noopener">PDF complet</a>
                  <button class="af-inv-row-fs-btn" data-file-id="${escHtml(inv.drive_file_id)}"
                          data-ref="${escHtml(ref)}" title="Plein écran HD">
                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                      <polyline points="1,4 1,1 4,1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                      <polyline points="8,1 11,1 11,4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                      <polyline points="11,8 11,11 8,11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                      <polyline points="4,11 1,11 1,8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                    </svg>
                    Plein écran HD
                  </button>
                </div>
              </div>
              <div class="af-inv-inline-img-wrap" id="af-inline-wrap-${inv.id}">
                <div class="af-inv-inline-loading" id="af-inline-loading-${inv.id}">
                  <span class="af-inv-spinner"></span><span>Chargement…</span>
                </div>
                <img
                  class="af-inv-inline-img"
                  id="af-inline-img-${inv.id}"
                  alt="Aperçu ${escHtml(ref)}"
                  style="display:none"
                >
                <div class="af-inv-inline-err" id="af-inline-err-${inv.id}" style="display:none">Aperçu indisponible</div>
              </div>
            </div>
          </td>
        </tr>` : '';

    return `<tr class="af-inv-trow${isOpen ? ' af-inv-trow-open' : ''}" data-inv-id="${inv.id}">
      <td class="af-inv-td-ref">${escHtml(ref)}</td>
      <td class="af-inv-td-date">${escHtml(dateStr)}</td>
      <td class="af-inv-td-ht">${htFmt}</td>
      <td class="af-inv-td-ttc">${ttcFmt}</td>
      <td class="af-inv-td-cur">${escHtml(inv.currency || 'CHF')}</td>
      <td class="af-inv-td-actions">
        ${parserTag}${previewBtn}${fullscreenBtn}
      </td>
    </tr>
    ${inlinePanel}`;
  }).join('');

  return `
    <div class="af-inv-table-wrap">
      <table class="af-inv-table" aria-label="Factures ${escHtml(s.name)}">
        <thead>
          <tr>
            <th>Référence</th>
            <th>Date</th>
            <th>HT</th>
            <th>TTC</th>
            <th>Devise</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

/* ── Catalogue MI tab ─────────────────────────────────────────── */

function renderCataloguePane(s) {
  const catalogue = s.catalogue || [];
  if (!catalogue.length) {
    return `<div class="af-empty-catalogue">Aucun MI observé — aucune livraison Active/Consumed enregistrée.</div>`;
  }
  return `
    <div class="af-catalogue-callout">MI observés sur des livraisons <b>Active</b> ou <b>Consumed</b> — lignes non-résolues (ingredient_fk NULL) et passthrough TVA exclus.</div>
    <table class="af-manifest-table" aria-label="Catalogue MI ${escHtml(s.name)}">
      <thead><tr>
        <th>MI ID</th><th>Nom</th><th>Catégorie</th><th style="text-align:right">BL</th>
      </tr></thead>
      <tbody>
      ${catalogue.map((mi, idx) => `<tr>
        <td class="af-td-mi-id">
          <span class="af-line-num">${idx + 1}</span>
          ${escHtml(mi.mi_id)}
        </td>
        <td class="af-td-name">${escHtml(mi.name)}</td>
        <td class="af-td-cat">${escHtml(mi.category || '—')}</td>
        <td class="af-td-del">${mi.deliveries}</td>
      </tr>`).join('')}
      </tbody>
    </table>`;
}

/* ── Main fiche / dossier render ──────────────────────────────── */

function openFiche(id) {
  const s = SUPPLIERS.find(x => x.id === id);
  if (!s) return;
  const reopening = activeId === id;
  activeId     = id;
  activeTab    = reopening ? activeTab : 'overview';
  inlineOpenId = null;

  document.querySelectorAll('.af-ledger').forEach(r => {
    r.classList.toggle('active', parseInt(r.dataset.id, 10) === id);
  });

  const empty = document.getElementById('af-dock-empty');
  const fiche = document.getElementById('af-fiche');
  if (empty) empty.style.display = 'none';
  if (!fiche) return;
  fiche.classList.add('visible');

  const activeBadge = `<span class="af-db-active-badge ${s.is_active ? 'on' : 'off'}">${s.is_active ? '● Actif' : '○ Inactif'}</span>`;
  const accent   = glAccent(s.gl_account);
  const invCount = (s.invoices  || []).length;
  const miCount  = (s.catalogue || []).length;

  fiche.innerHTML = `
  <div class="af-databook" style="--db-accent:${accent}">

    <!-- Dossier cover band -->
    <div class="af-db-cover">
      <div class="af-db-cover-spine"></div>
      <div class="af-db-cover-body">
        <div class="af-db-cover-stamp">dossier fournisseur · lecture seule</div>
        <div class="af-db-sup-name">${escHtml(s.name)}</div>
        <div class="af-db-sup-id">${escHtml(s.supplier_id)}</div>
        <div class="af-db-cover-meta">
          ${activeBadge}
          ${completenessRing(s)}
          <span class="af-db-cover-gl">GL ${escHtml(s.gl_account || '—')}</span>
        </div>
      </div>
    </div>

    <!-- Tab bar (folder divider tabs) -->
    <div class="af-db-tabs" role="tablist">
      <button class="af-db-tab${activeTab === 'overview' ? ' active' : ''}" data-tab="overview"
              role="tab" aria-selected="${activeTab === 'overview'}">
        <svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true">
          <rect x="1" y="1" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.2"/>
          <rect x="8" y="1" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.2"/>
          <rect x="1" y="8" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.2"/>
          <rect x="8" y="8" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.2"/>
        </svg>
        Vue d'ensemble
      </button>
      <button class="af-db-tab${activeTab === 'factures' ? ' active' : ''}" data-tab="factures"
              role="tab" aria-selected="${activeTab === 'factures'}">
        <svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true">
          <rect x="2" y="1" width="10" height="12" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
          <line x1="4.5" y1="4.5" x2="9.5" y2="4.5" stroke="currentColor" stroke-width="1" stroke-linecap="round"/>
          <line x1="4.5" y1="7" x2="9.5" y2="7" stroke="currentColor" stroke-width="1" stroke-linecap="round"/>
          <line x1="4.5" y1="9.5" x2="7.5" y2="9.5" stroke="currentColor" stroke-width="1" stroke-linecap="round"/>
        </svg>
        Factures <span class="af-db-tab-count">${invCount}</span>
      </button>
      <button class="af-db-tab${activeTab === 'catalogue' ? ' active' : ''}" data-tab="catalogue"
              role="tab" aria-selected="${activeTab === 'catalogue'}">
        <svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true">
          <circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/>
          <line x1="4.5" y1="7" x2="9.5" y2="7" stroke="currentColor" stroke-width="1" stroke-linecap="round"/>
          <line x1="7" y1="4.5" x2="7" y2="9.5" stroke="currentColor" stroke-width="1" stroke-linecap="round"/>
        </svg>
        Catalogue MI <span class="af-db-tab-count">${miCount}</span>
      </button>
    </div>

    <!-- Tab panes -->
    <div class="af-db-pane" data-pane="overview" ${activeTab !== 'overview' ? 'hidden' : ''}>
      ${renderOverviewPane(s)}
    </div>
    <div class="af-db-pane" data-pane="factures" ${activeTab !== 'factures' ? 'hidden' : ''}>
      ${renderFacturesPane(s)}
    </div>
    <div class="af-db-pane" data-pane="catalogue" ${activeTab !== 'catalogue' ? 'hidden' : ''}>
      ${renderCataloguePane(s)}
    </div>

  </div>`;

  fiche.querySelectorAll('.af-db-tab').forEach(btn => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
  });

  wireFactureButtons(fiche, s);
}

/* ── Wire invoice action buttons ──────────────────────────────── */

function wireFactureButtons(fiche, s) {
  fiche.querySelectorAll('.af-inv-row-preview-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const invId  = parseInt(btn.dataset.invId, 10);
      const fileId = btn.dataset.fileId;
      const ref    = btn.dataset.ref || '';
      toggleInlinePreview(invId, fileId, ref, s);
    });
  });

  fiche.querySelectorAll('.af-inv-row-fs-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      openInvoiceViewer(btn.dataset.fileId, btn.dataset.ref || '');
    });
  });
}

/* ── Inline invoice preview toggle ───────────────────────────── */

function toggleInlinePreview(invId, fileId, ref, s) {
  if (inlineOpenId === invId) {
    inlineOpenId = null;
  } else {
    inlineOpenId = invId;
  }

  const pane = document.querySelector('.af-db-pane[data-pane="factures"]');
  if (!pane) return;
  pane.innerHTML = renderFacturesPane(s);
  const fiche = document.getElementById('af-fiche');
  if (fiche) wireFactureButtons(fiche, s);

  if (inlineOpenId === invId) {
    const img     = document.getElementById(`af-inline-img-${invId}`);
    const loading = document.getElementById(`af-inline-loading-${invId}`);
    const err     = document.getElementById(`af-inline-err-${invId}`);
    if (!img) return;

    /* NOT lazy — set src immediately (image starts display:none) */
    img.onload = () => {
      if (loading) loading.style.display = 'none';
      img.style.display = 'block';
    };
    img.onerror = () => {
      if (loading) loading.style.display = 'none';
      if (err) err.style.display = 'flex';
    };
    img.src = '/api/document-preview-png.php?file_id=' + encodeURIComponent(fileId);

    setTimeout(() => {
      const panel = document.querySelector(`.af-inv-inline-row[data-inline-for="${invId}"]`);
      if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, 50);
  }
}

/* ── Invoice viewer modal (full-screen, two-tier zoom) ────────── */

const _afZoom = {
  scale: 1, panX: 0, panY: 0,
  minScale: 1, maxScale: 4, step: 0.35,
  dragging: false,
  dragStartX: 0, dragStartY: 0, dragStartPanX: 0, dragStartPanY: 0,
};

function _afZoomApply(img, wrap, zoomLabel, animated) {
  img.style.transition = animated ? 'transform .1s ease' : 'none';
  img.style.transform  = `translate(${_afZoom.panX}px, ${_afZoom.panY}px) scale(${_afZoom.scale})`;
  if (zoomLabel) zoomLabel.textContent = Math.round(_afZoom.scale * 100) + '%';
  wrap.style.cursor = _afZoom.scale > _afZoom.minScale ? 'grab' : 'default';
}

function _afZoomClampPan(img, wrap) {
  if (_afZoom.scale <= _afZoom.minScale) { _afZoom.panX = 0; _afZoom.panY = 0; return; }
  const wW = wrap.clientWidth;
  const wH = wrap.clientHeight;
  const iW = img.naturalWidth  || img.offsetWidth  || wW;
  const iH = img.naturalHeight || img.offsetHeight || wH;
  const scaleToFit = Math.min(wW / iW, wH / iH, 1);
  const rendW = iW * scaleToFit * _afZoom.scale;
  const rendH = iH * scaleToFit * _afZoom.scale;
  const maxX  = Math.max(0, (rendW - wW) / 2);
  const maxY  = Math.max(0, (rendH - wH) / 2);
  _afZoom.panX = Math.max(-maxX, Math.min(maxX, _afZoom.panX));
  _afZoom.panY = Math.max(-maxY, Math.min(maxY, _afZoom.panY));
}

function _afZoomReset() {
  _afZoom.scale = 1; _afZoom.panX = 0; _afZoom.panY = 0; _afZoom.dragging = false;
}

function openInvoiceViewer(fileId, invoiceRef) {
  let modal = document.getElementById('af-inv-modal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'af-inv-modal';
    modal.className = 'af-inv-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-label', 'Aperçu document');
    document.body.appendChild(modal);
    modal.addEventListener('click', e => {
      if (e.target === modal) closeInvoiceViewer();
    });
  }

  _afZoomReset();

  const safeRef = escHtml(invoiceRef || fileId);
  const safeId  = encodeURIComponent(fileId);

  modal.innerHTML = `
    <div class="af-inv-dialog">
      <div class="af-inv-titlebar">
        <div style="display:flex;align-items:center;min-width:0;gap:8px;overflow:hidden">
          <span class="af-inv-titlebar-ref" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${safeRef}</span>
          <span class="af-modal-hd-badge" id="af-modal-hd-badge" style="display:none">HD…</span>
        </div>
        <button class="af-inv-close" aria-label="Fermer" title="Fermer (Echap)">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <line x1="3" y1="3" x2="13" y2="13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            <line x1="13" y1="3" x2="3" y2="13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
          </svg>
        </button>
      </div>
      <div class="af-inv-body">
        <div class="af-inv-img-wrap">
          <div class="af-inv-loading" id="af-inv-loading">
            <span class="af-inv-spinner"></span><span>Chargement...</span>
          </div>
          <img class="af-inv-preview" id="af-inv-preview" alt="Aperçu document" style="display:none">
          <div class="af-inv-err" id="af-inv-err" style="display:none">Aperçu indisponible</div>
          <div class="af-zoom-bar" id="af-zoom-bar" style="display:none" aria-label="Contrôles zoom">
            <button class="af-zoom-btn" id="af-zoom-out" aria-label="Zoom arrière" title="Zoom arrière (−)">
              <svg width="11" height="11" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                <line x1="2" y1="6" x2="10" y2="6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
              </svg>
            </button>
            <span class="af-zoom-level" id="af-zoom-level">100%</span>
            <button class="af-zoom-btn" id="af-zoom-in" aria-label="Zoom avant" title="Zoom avant (+)">
              <svg width="11" height="11" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                <line x1="6" y1="2" x2="6" y2="10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                <line x1="2" y1="6" x2="10" y2="6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
              </svg>
            </button>
            <button class="af-zoom-btn af-zoom-fit" id="af-zoom-fit" aria-label="Ajuster à la fenêtre">
              <svg width="11" height="11" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                <polyline points="1,4 1,1 4,1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                <polyline points="8,1 11,1 11,4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                <polyline points="11,8 11,11 8,11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                <polyline points="4,11 1,11 1,8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
              </svg>
            </button>
          </div>
        </div>
      </div>
      <div class="af-inv-footer">
        <a class="af-inv-pdf-btn" href="/api/document.php?file_id=${safeId}" target="_blank" rel="noopener">
          <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <rect x="3" y="1" width="8" height="12" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
            <line x1="5.5" y1="5" x2="10.5" y2="5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
            <line x1="5.5" y1="7.5" x2="10.5" y2="7.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
            <line x1="5.5" y1="10" x2="8.5" y2="10" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
          </svg>
          Ouvrir le PDF complet
        </a>
      </div>
    </div>`;

  modal.querySelector('.af-inv-close').addEventListener('click', closeInvoiceViewer);

  const img       = modal.querySelector('#af-inv-preview');
  const loading   = modal.querySelector('#af-inv-loading');
  const err       = modal.querySelector('#af-inv-err');
  const wrap      = modal.querySelector('.af-inv-img-wrap');
  const zoomBar   = modal.querySelector('#af-zoom-bar');
  const zoomLbl   = modal.querySelector('#af-zoom-level');
  const btnIn     = modal.querySelector('#af-zoom-in');
  const btnOut    = modal.querySelector('#af-zoom-out');
  const btnFit    = modal.querySelector('#af-zoom-fit');
  const hdBadge   = modal.querySelector('#af-modal-hd-badge');

  function applyZoom(animated) {
    _afZoomClampPan(img, wrap);
    _afZoomApply(img, wrap, zoomLbl, animated);
  }

  /* ── Two-tier hi-res: prefetch 600 dpi IMMEDIATELY on open ──
     By the time the user zooms past 1.5×, the 600dpi is already
     cached in the browser. onerror falls back to keeping 300 dpi. */
  let hiResReady     = false;
  let hiResRequested = false;
  let initialLoaded  = false;

  const hiSrc = '/api/document-preview-png.php?file_id=' + safeId + '&dpi=600';

  function prefetchHiRes() {
    if (hiResRequested) return;
    hiResRequested = true;
    if (hdBadge) hdBadge.style.display = 'inline-flex';
    const hi = new Image();
    hi.onload = () => {
      hiResReady = true;
      if (hdBadge) hdBadge.style.display = 'none';
    };
    hi.onerror = () => {
      /* Server-side failed — keep 300dpi silently */
      hiResRequested = false; /* allow retry on next zoom */
      if (hdBadge) hdBadge.style.display = 'none';
    };
    hi.src = hiSrc;
  }

  function maybeUpgradeHiRes() {
    if (_afZoom.scale <= 1.5) return;
    if (!hiResReady) { prefetchHiRes(); return; } /* still loading — prefetch triggered */
    /* Hi-res is cached — swap in if not already */
    if (img.src !== hiSrc) img.src = hiSrc;
  }

  /* Start prefetch as soon as 300dpi is loaded (background, no-wait) */
  img.onload = () => {
    if (!initialLoaded) {
      initialLoaded = true;
      loading.style.display = 'none';
      img.style.display = 'block';
      _afZoomReset();
      img.style.transformOrigin = '50% 50%';
      applyZoom(false);
      zoomBar.style.display = 'flex';
      /* Kick off 600dpi prefetch immediately after 300dpi is shown */
      prefetchHiRes();
    } else {
      /* Hi-res swap completed — re-clamp pan */
      _afZoomClampPan(img, wrap);
      _afZoomApply(img, wrap, zoomLbl, false);
    }
  };
  img.onerror = () => {
    loading.style.display = 'none';
    err.style.display = 'flex';
  };
  /* NOT lazy */
  img.src = '/api/document-preview-png.php?file_id=' + safeId;

  /* ── Zoom controls ── */
  btnIn.addEventListener('click', () => {
    _afZoom.scale = Math.min(_afZoom.maxScale, _afZoom.scale + _afZoom.step);
    applyZoom(true);
    maybeUpgradeHiRes();
  });
  btnOut.addEventListener('click', () => {
    _afZoom.scale = Math.max(_afZoom.minScale, _afZoom.scale - _afZoom.step);
    if (_afZoom.scale <= _afZoom.minScale) { _afZoom.panX = 0; _afZoom.panY = 0; }
    applyZoom(true);
  });
  btnFit.addEventListener('click', () => {
    _afZoom.scale = _afZoom.minScale; _afZoom.panX = 0; _afZoom.panY = 0;
    applyZoom(true);
  });

  wrap.addEventListener('wheel', e => {
    e.preventDefault();
    const delta = e.deltaY < 0 ? _afZoom.step : -_afZoom.step;
    _afZoom.scale = Math.max(_afZoom.minScale, Math.min(_afZoom.maxScale, _afZoom.scale + delta));
    if (_afZoom.scale <= _afZoom.minScale) { _afZoom.panX = 0; _afZoom.panY = 0; }
    applyZoom(false);
    if (delta > 0) maybeUpgradeHiRes();
  }, { passive: false });

  img.addEventListener('pointerdown', e => {
    if (_afZoom.scale <= _afZoom.minScale) return;
    e.preventDefault();
    img.setPointerCapture(e.pointerId);
    _afZoom.dragging = true;
    _afZoom.dragStartX    = e.clientX;
    _afZoom.dragStartY    = e.clientY;
    _afZoom.dragStartPanX = _afZoom.panX;
    _afZoom.dragStartPanY = _afZoom.panY;
    wrap.style.cursor = 'grabbing';
    img.style.transition = 'none';
  });
  img.addEventListener('pointermove', e => {
    if (!_afZoom.dragging) return;
    _afZoom.panX = _afZoom.dragStartPanX + (e.clientX - _afZoom.dragStartX);
    _afZoom.panY = _afZoom.dragStartPanY + (e.clientY - _afZoom.dragStartY);
    _afZoomClampPan(img, wrap);
    img.style.transform = `translate(${_afZoom.panX}px, ${_afZoom.panY}px) scale(${_afZoom.scale})`;
  });
  img.addEventListener('pointerup', () => {
    if (!_afZoom.dragging) return;
    _afZoom.dragging = false;
    wrap.style.cursor = _afZoom.scale > _afZoom.minScale ? 'grab' : 'default';
  });
  img.addEventListener('pointercancel', () => {
    _afZoom.dragging = false;
    wrap.style.cursor = _afZoom.scale > _afZoom.minScale ? 'grab' : 'default';
  });

  img.addEventListener('dblclick', e => {
    e.preventDefault();
    if (_afZoom.scale > _afZoom.minScale) {
      _afZoom.scale = _afZoom.minScale; _afZoom.panX = 0; _afZoom.panY = 0;
    } else {
      _afZoom.scale = 2;
      maybeUpgradeHiRes();
    }
    applyZoom(true);
  });

  modal.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeInvoiceViewer() {
  const modal = document.getElementById('af-inv-modal');
  if (modal) {
    modal.classList.remove('open');
    document.body.style.overflow = '';
    const img = modal.querySelector('#af-inv-preview');
    if (img) img.src = '';
    _afZoomReset();
  }
}

/* ── Event wiring ─────────────────────────────────────────────── */

document.addEventListener('DOMContentLoaded', () => {

  /* Chip filters — 'parser' chip removed (not operator-facing) */
  document.querySelectorAll('.af-chip[data-filter]').forEach(btn => {
    btn.addEventListener('click', () => {
      currentFilter = btn.dataset.filter;
      document.querySelectorAll('.af-chip').forEach(c => c.classList.remove('on'));
      btn.classList.add('on');
      renderList();
    });
  });

  /* Search */
  const searchInput = document.getElementById('af-search');
  if (searchInput) {
    searchInput.addEventListener('input', () => {
      currentSearch = searchInput.value.trim();
      renderList();
    });
  }

  /* Sort */
  const sortSelect = document.getElementById('af-sort-select');
  if (sortSelect) {
    sortSelect.addEventListener('change', () => {
      currentSort = sortSelect.value;
      renderList();
    });
  }

  /* Escape closes invoice viewer */
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeInvoiceViewer();
  });

  renderList();
});
