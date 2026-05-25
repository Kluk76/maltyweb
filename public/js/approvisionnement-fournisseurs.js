/**
 * approvisionnement-fournisseurs.js
 * READ-ONLY supplier dashboard — consumes window.SUPPLIERS injected by the PHP page.
 * All write paths have been removed / disabled.
 */
'use strict';

/* ── State ──────────────────────────────────────────────────────── */
let activeId        = null;
let currentFilter   = 'all';
let currentSearch   = '';
let currentSort     = 'alpha';

const SUPPLIERS  = window.SUPPLIERS  || [];
const DEDUP_PAIRS = window.DEDUP_PAIRS || [];

/* ── Utility ─────────────────────────────────────────────────────── */

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

/* Avatar helpers */
function initials(name) {
  const words = (name || '').trim().split(/\s+/);
  if (words.length >= 2) return (words[0][0] + words[1][0]).toUpperCase();
  return (name || '?').slice(0, 2).toUpperCase();
}

const avatarPalette = [
  '#4a3820','#3e4a28','#1e3a4a','#3a2a4a','#2a3a2a','#4a2a2a','#2a4040',
];
function avatarBg(name) {
  let h = 0;
  for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
  return avatarPalette[h % avatarPalette.length];
}

/* Volume: parse from notes field (mockup used notes string — live data uses stats.total_chf) */
function parseVolume(s) {
  if (s.stats && s.stats.total_chf) return parseFloat(s.stats.total_chf) || 0;
  return 0;
}

/* ── Filters & sort ──────────────────────────────────────────────── */

function filteredSuppliers() {
  let result = SUPPLIERS.slice();

  // Filter chip
  if (currentFilter === 'active') {
    result = result.filter(s => s.is_active);
  } else if (currentFilter === 'parser') {
    result = result.filter(s => s.parser_key);
  } else if (currentFilter === 'catalogue') {
    result = result.filter(s => s.catalogue && s.catalogue.length > 0);
  } else if (currentFilter === 'hors') {
    result = result.filter(s => s.hors_perimetre);
  }
  // 'all' = no filter

  // Search
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

  // Sort
  if (currentSort === 'volume') {
    result = result.slice().sort((a, b) => parseVolume(b) - parseVolume(a));
  } else {
    result = result.slice().sort((a, b) => a.name.localeCompare(b.name));
  }

  return result;
}

/* ── List render ─────────────────────────────────────────────────── */

function renderList() {
  const list  = document.getElementById('af-sup-list');
  const count = document.getElementById('af-list-count');
  if (!list) return;

  const items = filteredSuppliers();

  list.innerHTML = items.map(s => {
    const bg    = avatarBg(s.name);
    const ini   = initials(s.name);
    const rowCls = [
      !s.is_active ? 'inactive-sup' : '',
      s.id === activeId ? 'active' : '',
    ].filter(Boolean).join(' ');

    const horsBadge    = s.hors_perimetre ? '<span class="af-badge af-badge-hors">hors</span>' : '';
    const parserBadge  = s.parser_key     ? '<span class="af-badge af-badge-parser">parser</span>' : '';
    const miCount      = (s.catalogue || []).length;
    const miBadge      = miCount > 0      ? `<span class="af-badge af-badge-mi">${miCount} MI</span>` : '';
    const activeBadge  = s.is_active
      ? '<span class="af-badge af-badge-active">actif</span>'
      : '<span class="af-badge af-badge-inactive">inactif</span>';

    return `<div class="af-sup-row ${rowCls}" data-id="${s.id}" role="button" tabindex="0"
      aria-label="${escHtml(s.name)}">
      <div class="af-sup-avatar" style="background:${bg}">${ini}</div>
      <div class="af-sup-info">
        <div class="af-sup-name">${escHtml(s.name)}</div>
        <div class="af-sup-meta">
          <span class="af-sup-id">${s.id}</span>
          <span class="af-sup-gl">GL ${s.gl_account || '—'}</span>
          <span class="af-sup-cur">${s.currency || '—'}</span>
        </div>
      </div>
      <div class="af-sup-badges">
        ${horsBadge}${parserBadge}${miBadge}${activeBadge}
      </div>
    </div>`;
  }).join('');

  if (count) {
    count.textContent = `${items.length} / ${SUPPLIERS.length} fournisseurs`;
  }

  // Re-attach row click listeners
  list.querySelectorAll('.af-sup-row').forEach(row => {
    row.addEventListener('click', () => openFiche(parseInt(row.dataset.id, 10)));
    row.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openFiche(parseInt(row.dataset.id, 10));
      }
    });
  });
}

/* ── GL footprint render ─────────────────────────────────────────── */

function renderGlFootprint(s) {
  const fp = s.gl_footprint || [];
  const barColors = [
    'var(--dock)', 'var(--bbt)', 'var(--hop)', 'var(--ember)', 'var(--oak)', 'var(--steel-mid)',
  ];

  if (!fp.length) {
    const glLabel = s.gl_label || s.gl_account || '—';
    return `<div class="af-gl-footprint">
      <div class="af-gl-footprint-head">
        <span class="af-gl-footprint-title">Empreinte GL</span>
        <span class="af-gl-footprint-calc">⟳ données historiques</span>
      </div>
      <div class="af-gl-bar-row">
        <span class="af-gl-bar-label">${escHtml(s.gl_account || '—')} · ${escHtml(glLabel)}</span>
        <div class="af-gl-bar-track"><div class="af-gl-bar-fill" style="width:100%;background:var(--dock)"></div></div>
        <span class="af-gl-bar-pct">100%</span>
        <span class="af-gl-bar-modal">modal ✓</span>
      </div>
      <div class="af-gl-exclusion-note">Ligne unique · passthrough TVA et fret non-transport exclus du calcul.</div>
    </div>`;
  }

  const totalChf = fp.reduce((sum, item) => sum + (parseFloat(item.chf) || 0), 0);
  const maxChf   = Math.max(...fp.map(item => parseFloat(item.chf) || 0));

  const barsHtml = fp.map((item, i) => {
    const pct      = totalChf > 0 ? Math.round((parseFloat(item.chf) || 0) / totalChf * 100) : 0;
    const isModal  = (parseFloat(item.chf) || 0) === maxChf;
    const gl       = escHtml(item.gl || '—');
    const label    = escHtml(item.gl_label || item.gl || '—');
    return `<div class="af-gl-bar-row">
      <span class="af-gl-bar-label">${gl} · ${label}</span>
      <div class="af-gl-bar-track"><div class="af-gl-bar-fill" style="width:${pct}%;background:${barColors[i % barColors.length]}"></div></div>
      <span class="af-gl-bar-pct">${pct}%</span>
      ${isModal ? '<span class="af-gl-bar-modal">modal ✓</span>' : ''}
    </div>`;
  }).join('');

  const multiCallout = fp.filter(item => {
    const pct = totalChf > 0 ? (parseFloat(item.chf) || 0) / totalChf * 100 : 0;
    return pct > 10;
  }).length > 2 ? `<div class="af-gl-multi-callout">
    <p>ℹ Ce fournisseur livre sur plusieurs GLs (fournisseur multi-GL). L'ingestion assigne le GL à la ligne MI, pas au fournisseur. Le GL principal est utilisé comme valeur par défaut.</p>
  </div>` : '';

  const primaryItem = fp.find((item, i) => (parseFloat(item.chf) || 0) === maxChf) || fp[0];
  const glPrimaryVal = primaryItem
    ? `${escHtml(primaryItem.gl)} · ${escHtml(primaryItem.gl_label || primaryItem.gl)}`
    : escHtml(s.gl_account || '—');

  return `<div class="af-gl-footprint">
    <div class="af-gl-footprint-head">
      <span class="af-gl-footprint-title">Empreinte GL</span>
      <span class="af-gl-footprint-calc">⟳ calculé · historique</span>
    </div>
    ${barsHtml}
    <div class="af-gl-exclusion-note">Passthrough TVA import + fret inbound non-transport exclus. Empreinte basée sur lignes historiques actives/consommées.</div>
    ${multiCallout}
    <div class="af-gl-primary-row">
      <span class="af-gl-primary-label">GL principal :</span>
      <span class="af-gl-primary-value">${glPrimaryVal}</span>
    </div>
  </div>`;
}

/* ── Completeness ring ───────────────────────────────────────────── */

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
  const color  = filled === total ? 'var(--hop)' : 'var(--dock)';

  return `<svg class="af-completeness-ring af-ring-svg" viewBox="0 0 36 36" aria-label="${filled}/${total} champs renseignés">
    <circle cx="18" cy="18" r="${r}" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="4"/>
    <circle cx="18" cy="18" r="${r}" fill="none" stroke="${color}" stroke-width="4"
      stroke-dasharray="${circ.toFixed(2)}" stroke-dashoffset="${offset.toFixed(2)}"
      transform="rotate(-90 18 18)"/>
    <text x="18" y="22" text-anchor="middle" font-family="JetBrains Mono,monospace" font-size="9" fill="${color}">${filled}/${total}</text>
  </svg>`;
}

/* ── Fiche render ────────────────────────────────────────────────── */

function openFiche(id) {
  const s = SUPPLIERS.find(x => x.id === id);
  if (!s) return;
  activeId = id;

  // Highlight active row
  document.querySelectorAll('.af-sup-row').forEach(r => {
    r.classList.toggle('active', parseInt(r.dataset.id, 10) === id);
  });

  const empty = document.getElementById('af-dock-empty');
  const fiche = document.getElementById('af-fiche');
  if (empty) empty.style.display = 'none';
  if (!fiche) return;
  fiche.classList.add('visible');

  /* ── Build fiche HTML ── */

  // Country display
  const countryDisplay = s.country_display || '—';
  const countryNeedsConfirm = countryDisplay.includes('présumé') || countryDisplay.includes('à renseigner');
  const countryHtml = countryNeedsConfirm
    ? `${escHtml(countryDisplay)} <span class="af-pending-tag">à confirmer</span>`
    : escHtml(countryDisplay);

  // Parser chip
  const parserHtml = s.parser_key
    ? `<div class="af-doc-parser-chip"><span class="parser-dot"></span>Parser : ${escHtml(s.parser_key)}</div>`
    : `<div class="af-doc-no-parser">Pas de parseur dédié</div>`;

  // Active badge
  const activeBadge = `<span class="af-doc-active-badge ${s.is_active ? 'on' : 'off'}">${s.is_active ? '● Actif' : '○ Inactif'}</span>`;

  // Hors périmètre banner
  const horsHtml = s.hors_perimetre
    ? `<div class="af-hors-banner">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
          <line x1="8" y1="4" x2="8" y2="9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          <circle cx="8" cy="11.5" r=".8" fill="currentColor"/>
        </svg>
        <span>Fournisseur hors périmètre COGS — les documents sont auto-ignorés à l'ingestion.</span>
       </div>`
    : '';

  // VAT regime display
  const vatDisplay = s.vat_regime
    ? escHtml(s.vat_regime)
    : `<span class="af-pending-tag">à renseigner</span>`;

  // Commissioning state
  const commState = s.commissioning_state
    ? escHtml(s.commissioning_state)
    : `<span style="color:var(--ink-faint);font-style:italic">—</span>`;

  // Notes
  const notesHtml = s.notes
    ? escHtml(s.notes)
    : `<span style="color:var(--ink-faint);font-style:italic">—</span>`;

  // Aliases strip
  const aliasesHtml = (s.aliases && s.aliases.length > 0)
    ? `<div class="af-aliases-strip">
        <span class="af-alias-label">Alias</span>
        ${s.aliases.map(a => `<span class="af-alias-tag">${escHtml(a)}</span>`).join('')}
      </div>`
    : '';

  // GL footprint
  const glFootprintHtml = renderGlFootprint(s);

  // MI catalogue
  const catalogue    = s.catalogue || [];
  const catalogueHtml = catalogue.length > 0
    ? `<table class="af-manifest-table" aria-label="Catalogue MI">
        <thead><tr>
          <th>MI ID</th>
          <th>Nom</th>
          <th>Catégorie</th>
          <th style="text-align:right">BL</th>
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
      </table>`
    : `<div class="af-empty-catalogue">Aucun MI observé — aucune livraison Active/Consumed enregistrée.</div>`;

  // Stats
  const stats = s.stats || {};
  const totalChfFmt = stats.total_chf
    ? new Intl.NumberFormat('fr-CH', { style: 'currency', currency: 'CHF', maximumFractionDigits: 0 }).format(parseFloat(stats.total_chf))
    : '—';
  const invoicesCount = stats.invoices != null ? stats.invoices : '—';

  const statsHtml = `<div class="af-stats-section">
    <div class="af-stats-title">Statistiques</div>
    <div class="af-stats-row">
      <div class="af-stat-item">
        <div class="af-stat-val">${invoicesCount}</div>
        <div class="af-stat-lbl">Factures actives</div>
      </div>
      <div class="af-stat-item">
        <div class="af-stat-val" style="font-size:18px">${totalChfFmt}</div>
        <div class="af-stat-lbl">Total CHF livraisons</div>
      </div>
    </div>
  </div>`;

  // Read-only notice (visible to all roles — this is a read-only build)
  const roNotice = `<div class="af-fiche-readonly-notice">
    🔒 Lecture seule — l'édition arrive en phase 2
  </div>`;

  // Assemble the full fiche
  fiche.innerHTML = `
  <div class="af-doc-paper">
    ${roNotice}
    <div class="af-doc-head">
      <span class="af-doc-stamp">fiche fournisseur</span>
      <div class="af-doc-head-main">
        <div class="af-doc-sup-name">${escHtml(s.name)}</div>
        <div class="af-doc-sup-id">${escHtml(s.supplier_id)}</div>
      </div>
      <div class="af-doc-head-meta">
        ${activeBadge}
        ${parserHtml}
        ${completenessRing(s)}
      </div>
    </div>

    ${horsHtml}

    <div class="af-doc-fields">
      <div class="af-doc-field">
        <div class="af-doc-field-label">Pays / origine</div>
        <div class="af-doc-field-value">${countryHtml}</div>
      </div>
      <div class="af-doc-field">
        <div class="af-doc-field-label">Devise</div>
        <div class="af-doc-field-value mono">${escHtml(s.currency || '—')}</div>
      </div>
      <div class="af-doc-field">
        <div class="af-doc-field-label">Régime TVA</div>
        <div class="af-doc-field-value">${vatDisplay}</div>
      </div>
      <div class="af-doc-field">
        <div class="af-doc-field-label">GL principal</div>
        <div class="af-doc-field-value mono">${escHtml(s.gl_account || '—')} ${s.gl_label ? '· ' + escHtml(s.gl_label) : ''}</div>
      </div>
      <div class="af-doc-field">
        <div class="af-doc-field-label">N° TVA</div>
        <div class="af-doc-field-value mono">${s.vat_number ? escHtml(s.vat_number) : '<span style="color:var(--ink-faint);font-style:italic">—</span>'}</div>
      </div>
      <div class="af-doc-field">
        <div class="af-doc-field-label">État commissioning</div>
        <div class="af-doc-field-value">${commState}</div>
      </div>
      <div class="af-doc-field" style="grid-column:1/-1;">
        <div class="af-doc-field-label">Notes</div>
        <div class="af-doc-field-value">${notesHtml}</div>
      </div>
    </div>

    ${aliasesHtml}

    ${glFootprintHtml}

    ${statsHtml}

    <div class="af-catalogue-head">
      <span class="af-catalogue-title">Catalogue MI</span>
      <span class="af-catalogue-sub">${catalogue.length} ingrédients observés</span>
    </div>
    ${catalogue.length > 0
      ? `<div class="af-catalogue-callout">MI observés sur des livraisons <b>Active</b> ou <b>Consumed</b> — lignes non-résolues (ingredient_fk NULL) et passthrough TVA exclus.</div>`
      : ''}
    ${catalogueHtml}
  </div>`;
}

/* ── Event wiring ─────────────────────────────────────────────────── */

document.addEventListener('DOMContentLoaded', () => {

  /* Chip filters */
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

  /* Sort select */
  const sortSelect = document.getElementById('af-sort-select');
  if (sortSelect) {
    sortSelect.addEventListener('change', () => {
      currentSort = sortSelect.value;
      renderList();
    });
  }

  /* Initial render */
  renderList();
});
