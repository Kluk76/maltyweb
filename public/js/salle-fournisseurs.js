/**
 * salle-fournisseurs.js
 * Hydrates window.SF_SUPPLIERS and window.SF_ROLE; renders the
 * Salle des Machines governance fiche for each supplier.
 *
 * Rendering rules:
 * - All interpolated values go through escHtml() — no raw insertion.
 * - No loading="lazy" on images (none used here — no PDF previews).
 * - Write affordances (confirm/pin/validate) render as visible stubs,
 *   disabled pending step-3 endpoints. JS shows toast: "En cours de câblage".
 * - Modal pattern: class-toggle "open" on .sf-modal-overlay, NOT display prop.
 * - window.SF_SUPPLIERS key shapes: see PHP module (id, supplier_id, name,
 *   gl_account, gl_label, currency, is_active, commissioning_state, parser_key,
 *   country, country_display, vat_number, vat_regime, hors_perimetre, sporadique,
 *   last_modified_by, last_seen_at, imported_at, notes, aliases[], catalogue[],
 *   gls[], gl_footprint_obs[], pins{field→pin}, stats{invoices,total_chf},
 *   completeness, completeness_max).
 */

(function () {
  'use strict';

  /* ── Payload ──────────────────────────────────────────────────────── */
  const SUPPLIERS  = window.SF_SUPPLIERS || [];
  const ROLE       = window.SF_ROLE || 'manager';
  const CSRF       = window.SF_CSRF || '';
  const USER_EMAIL = window.SF_USER_EMAIL || '';
  const CAN_COMM   = window.SF_CAN_COMM === true;

  /* ── GL label map (static supplement — DB-driven preferred) ───────── */
  const GL_LABELS = {
    "4101":"Malt","4102":"Houblon","4103":"Levure","4104":"Adjuvants brassage",
    "4200":"Emballage (verre)","4201":"Emballage","4202":"Canettes / emballage",
    "4203":"Emballage","4205":"Liners","4206":"Étiquettes","4207":"Cartons",
    "4208":"Fruits","4209":"Emballage autre","4300":"Process / chimie",
    "4301":"Produits nettoyants","4302":"Équipement / divers","4500":"QA / Contrôle qualité",
    "4510":"R&D / Labo","4600":"Transport / fret","4700":"Utilities (eau / gaz)",
    "4701":"Déchets / recyclage","4702":"Électricité","5810":"Masse salariale",
    "6100":"Maintenance","6203":"Location véhicules","6232":"Taxe véhicule",
    "6285":"Consommables emballage","6607":"Vaisselle / merch",
    "1302":"Dépôts / cautions","6281":"Fret / VDGlass",
  };

  /* ── VAT regime labels (no DB codes in operator text) ────────────── */
  const VAT_LABELS = {
    'ch_vat':              '8,1 % TVA CH standard',
    'ch_reduced_vat':      '2,6 % TVA CH réduit',
    'intra_eu_vat':        '0 % export intra-UE',
    'third_country_0vat':  '0 % pays tiers',
    'non_taxable':         'Hors périmètre TVA',
  };

  /* ── State ────────────────────────────────────────────────────────── */
  let currentFilter = 'all';
  let currentSearch = '';
  let currentSort   = 'alpha';
  let activeId      = null;

  /* ── Utilities ────────────────────────────────────────────────────── */
  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function fmtChf(val) {
    if (val == null || val === 0) return '—';
    return new Intl.NumberFormat('fr-CH', {
      style: 'currency', currency: 'CHF', maximumFractionDigits: 0
    }).format(val);
  }

  function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return String(iso).slice(0, 10);
    return d.toLocaleDateString('fr-CH', { day: '2-digit', month: '2-digit', year: 'numeric' });
  }

  function initials(name) {
    const parts = (name || '').trim().split(/\s+/);
    if (parts.length >= 2)
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    return (name || 'XX').slice(0, 2).toUpperCase();
  }

  function avatarBg(name) {
    let h = 0;
    for (let i = 0; i < (name || '').length; i++)
      h = (h * 31 + name.charCodeAt(i)) >>> 0;
    const palettes = [
      'rgba(158,176,96,.25)', 'rgba(101,147,184,.25)', 'rgba(160,122,72,.25)',
      'rgba(122,95,160,.25)', 'rgba(197,100,74,.2)',   'rgba(200,134,58,.2)',
    ];
    return palettes[h % palettes.length];
  }

  function catClass(cat) {
    if (!cat) return '';
    const c = cat.toLowerCase();
    if (c.includes('malt'))                                     return 'cat-malt';
    if (c.includes('hop') || c.includes('houblon'))            return 'cat-hops';
    if (c.includes('yeast') || c.includes('levure'))           return 'cat-yeast';
    if (c.includes('adjunct') || c.includes('adj'))            return 'cat-adj';
    if (c.includes('packag') || c.includes('emball') ||
        c.includes('label') || c.includes('liner') ||
        c.includes('carton') || c.includes('tiquette'))        return 'cat-pkg';
    if (c.includes('clean') || c.includes('nettoy'))           return 'cat-clean';
    if (c.includes('util') || c.includes('waste') ||
        c.includes('recycl') || c.includes('electr') ||
        c.includes('déchet'))                                   return 'cat-util';
    if (c.includes('maint'))                                    return 'cat-maint';
    if (c.includes('r&d') || c.includes('labo') ||
        c.includes('qa') || c.includes('qualit'))              return 'cat-rd';
    if (c.includes('transport') || c.includes('freight') ||
        c.includes('fret') || c.includes('tax'))               return 'cat-trans';
    if (c.includes('process') || c.includes('chimie') ||
        c.includes('proc') || c.includes('chemi'))             return 'cat-proc';
    return '';
  }

  function glLabel(gl, dbLabel) {
    if (dbLabel) return dbLabel;
    return GL_LABELS[gl] || gl || '—';
  }

  /* ── Provenance determination ────────────────────────────────────── */
  function getProvenance(s, field) {
    const pin = (s.pins || {})[field];
    if (pin) return { state: 'locked', pin };
    // Fields that are admin-only (never auto-populated by ingest)
    const adminOnlyFields = ['vat_regime', 'vat_number', 'hors_perimetre', 'sporadique'];
    if (adminOnlyFields.includes(field)) {
      const val = s[field];
      if (val == null || val === false || val === '') return { state: 'gap' };
      return { state: 'auto' }; // set by web (last_modified_by='web') or ingest
    }
    if (s.last_modified_by === 'web') return { state: 'verified' };
    return { state: 'auto' };
  }

  function provBadge(s, field) {
    if (ROLE === 'operateur') return '';
    const p = getProvenance(s, field);
    if (p.state === 'locked') {
      const by  = escHtml(p.pin.pinned_by || 'admin');
      const at  = fmtDate(p.pin.pinned_at);
      return `<span class="sf-prov sf-prov-locked" title="Verrouillé par ${by} le ${at}">🔒 verrouillé</span>`;
    }
    if (p.state === 'verified') return `<span class="sf-prov sf-prov-verified">✓ vérifié</span>`;
    if (p.state === 'gap')      return `<span class="sf-prov sf-prov-gap">— à renseigner</span>`;
    return `<span class="sf-prov sf-prov-auto">⟳ auto-ingest</span>`;
  }

  /* Field action buttons — wired to real endpoints */
  function fieldActions(s, field) {
    if (ROLE === 'operateur') return '';
    const p = getProvenance(s, field);
    const isPinnable = ['gl_account','currency','country','vat_regime','vat_number',
                        'parser_key','hors_perimetre_cogs','sporadique'].includes(field);
    const btnLabel = ROLE === 'manager' ? '⟳ Proposer' : '✓ Confirmer';
    const id       = s.id;
    const pinBtn   = (ROLE === 'admin' && isPinnable)
      ? `<button class="sf-btn-micro sf-btn-pin-field"
           onclick="sfPinField(${id},'${escHtml(field)}',${p.state === 'locked' ? 'true' : 'false'})"
           title="${p.state === 'locked' ? 'Désépingler ce champ' : 'Épingler ce champ'}">
           ${p.state === 'locked' ? '🔓 Désépingler' : '📌 Épingler'}
         </button>`
      : '';

    if (p.state === 'auto' || p.state === 'gap') {
      return `<div class="sf-field-actions">
        ${ROLE === 'admin' ? `<button class="sf-btn-micro sf-btn-confirm-field" onclick="sfConfirmField(${id},'${escHtml(field)}')">${btnLabel}</button>` : ''}
        <button class="sf-btn-micro sf-btn-modify-field" onclick="sfEditField(${id},'${escHtml(field)}')">✎ Modifier</button>
        ${pinBtn}
      </div>`;
    }
    return `<div class="sf-field-actions">
      <button class="sf-btn-micro sf-btn-modify-field" onclick="sfEditField(${id},'${escHtml(field)}')">✎ Modifier</button>
      ${pinBtn}
    </div>`;
  }

  /* ── Completeness ring SVG ────────────────────────────────────────── */
  function completenessRing(s) {
    const filled = s.completeness || 0;
    const total  = s.completeness_max || 6;
    const r = 14;
    const circ = 2 * Math.PI * r;
    const offset = circ * (1 - filled / total);
    const color = filled === total ? 'var(--hop)' : 'var(--dock)';
    return `<svg class="sf-ring" viewBox="0 0 36 36" aria-label="${filled} / ${total} champs renseignés">
      <circle cx="18" cy="18" r="${r}" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="4"/>
      <circle cx="18" cy="18" r="${r}" fill="none" stroke="${color}" stroke-width="4"
        stroke-dasharray="${circ.toFixed(2)}" stroke-dashoffset="${offset.toFixed(2)}"
        transform="rotate(-90 18 18)"/>
      <text x="18" y="22" text-anchor="middle" font-family="JetBrains Mono,monospace"
        font-size="9" fill="${color}">${filled}/${total}</text>
    </svg>`;
  }

  /* ── GL Footprint section ─────────────────────────────────────────── */
  function renderGlFootprint(s) {
    const barColors = [
      'var(--dock)', 'var(--bbt)', 'var(--hop)',
      'var(--ember)', 'var(--oak)', 'var(--steel-mid)',
    ];

    // Prefer ref_supplier_gls; fall back to inv_deliveries observation
    const hasGlsRows = (s.gls || []).length > 0;
    const hasObsRows = (s.gl_footprint_obs || []).length > 0;

    let bars = '';
    let sourceLabel = '';
    let multiNote = '';

    if (hasGlsRows) {
      /* ref_supplier_gls is populated */
      sourceLabel = 'ref_supplier_gls';
      const rows = s.gls;
      const totalDel = rows.reduce((acc, r) => acc + (r.del_count || 0), 0) || 1;
      bars = rows.map((r, i) => {
        const pct = totalDel > 0 && r.del_count != null
          ? Math.round(r.del_count / totalDel * 100)
          : (i === 0 ? 100 : 0);
        const lbl = glLabel(r.gl, r.gl_label);
        const primaryTag = r.is_primary
          ? `<span class="sf-gl-modal-tag">modal ✓</span>` : '';
        const exclTag = r.excluded_cogs
          ? `<span class="sf-gl-excluded-tag">exclu COGS</span>` : '';
        return `<div class="sf-gl-bar-row">
          <span class="sf-gl-bar-label">${escHtml(r.gl)} · ${escHtml(lbl)}</span>
          <div class="sf-gl-bar-track"><div class="sf-gl-bar-fill" style="width:${pct}%;background:${barColors[i % barColors.length]}"></div></div>
          <span class="sf-gl-bar-pct">${pct}%</span>
          ${primaryTag}${exclTag}
        </div>`;
      }).join('');
      if (rows.length > 1) {
        multiNote = `<div class="sf-gl-multi-note"><p>ℹ Fournisseur multi-GL : l'ingestion assigne le GL à la ligne MI, pas au fournisseur. Le GL principal est utilisé comme défaut en cas de résolution impossible.</p></div>`;
      }
    } else if (hasObsRows) {
      /* Fallback: compute from inv_deliveries observation */
      sourceLabel = '⟳ historique livraisons';
      const obs = s.gl_footprint_obs;
      const totalChf = obs.reduce((acc, r) => acc + (r.chf || 0), 0) || 1;
      bars = obs.map((r, i) => {
        const pct = Math.round((r.chf || 0) / totalChf * 100);
        const lbl = glLabel(r.gl, r.gl_label);
        const isModal = i === 0;
        const primaryTag = isModal ? `<span class="sf-gl-modal-tag">modal ✓</span>` : '';
        return `<div class="sf-gl-bar-row">
          <span class="sf-gl-bar-label">${escHtml(r.gl)} · ${escHtml(lbl)}</span>
          <div class="sf-gl-bar-track"><div class="sf-gl-bar-fill" style="width:${pct}%;background:${barColors[i % barColors.length]}"></div></div>
          <span class="sf-gl-bar-pct">${pct}%</span>
          ${primaryTag}
        </div>`;
      }).join('');
      if (obs.length > 1) {
        multiNote = `<div class="sf-gl-multi-note"><p>ℹ Fournisseur multi-GL (observé). Données calculées depuis les livraisons historiques — avant peuplement de ref_supplier_gls.</p></div>`;
      }
    } else {
      /* No data at all — single GL from supplier record */
      sourceLabel = '⟳ données historiques';
      const lbl = glLabel(s.gl_account, s.gl_label);
      bars = `<div class="sf-gl-bar-row">
        <span class="sf-gl-bar-label">${escHtml(s.gl_account || '—')} · ${escHtml(lbl)}</span>
        <div class="sf-gl-bar-track"><div class="sf-gl-bar-fill" style="width:100%;background:var(--dock)"></div></div>
        <span class="sf-gl-bar-pct">100%</span>
        <span class="sf-gl-modal-tag">modal ✓</span>
      </div>`;
    }

    return `<div class="sf-gl-section">
      <div class="sf-gl-head">
        <span class="sf-gl-title">Empreinte GL</span>
        <span class="sf-gl-source">${escHtml(sourceLabel)}</span>
      </div>
      ${bars}
      <div class="sf-gl-note">TVA import récupérable (passthrough) + fret inbound non-transport exclus du calcul.</div>
      ${multiNote}
    </div>`;
  }

  /* ── Governance fields section ────────────────────────────────────── */
  function renderGovSection(s) {
    const vatLabel = VAT_LABELS[s.vat_regime || ''] || null;
    const vatDisplay = vatLabel
      ? `<span class="sf-gov-field-value">${escHtml(vatLabel)}</span>`
      : `<span class="sf-gov-field-value sf-gap">à renseigner</span>`;

    const vatPin = (s.pins || {})['vat_regime'];
    const vatPinDetail = vatPin
      ? `<div class="sf-pin-detail">🔒 verrouillé par ${escHtml(vatPin.pinned_by)} · ${fmtDate(vatPin.pinned_at)}</div>`
      : '';

    const horsDisplay = s.hors_perimetre
      ? `<span class="sf-gov-field-value" style="color:var(--ember)">Oui — auto-ignoré à l'ingestion</span>`
      : `<span class="sf-gov-field-value">Non</span>`;

    const sporDisplay = s.sporadique
      ? `<span class="sf-gov-field-value" style="color:var(--oak)">Oui</span>`
      : `<span class="sf-gov-field-value">Non</span>`;

    const parserDisplay = s.parser_key
      ? `<span class="sf-gov-field-value" style="color:var(--dock)">${escHtml(s.parser_key)}</span>`
      : `<span class="sf-gov-field-value sf-gap">non configuré</span>`;

    const vatNrDisplay = s.vat_number
      ? `<span class="sf-gov-field-value mono" style="font-family:'JetBrains Mono',monospace;font-size:11px">${escHtml(s.vat_number)}</span>`
      : `<span class="sf-gov-field-value sf-gap">à renseigner</span>`;

    return `<div class="sf-gov-section">
      <div class="sf-gov-section-title">Champs de gouvernance</div>
      <div class="sf-gov-field-grid">
        <div class="sf-gov-field">
          ${provBadge(s, 'vat_regime')}
          <div class="sf-gov-field-label">Régime TVA</div>
          ${vatDisplay}
          ${vatPinDetail}
          ${fieldActions(s, 'vat_regime')}
        </div>
        <div class="sf-gov-field">
          ${provBadge(s, 'vat_number')}
          <div class="sf-gov-field-label">N° TVA / UID</div>
          ${vatNrDisplay}
          ${fieldActions(s, 'vat_number')}
        </div>
        <div class="sf-gov-field">
          ${provBadge(s, 'hors_perimetre')}
          <div class="sf-gov-field-label">Hors périmètre COGS</div>
          ${horsDisplay}
          ${fieldActions(s, 'hors_perimetre')}
        </div>
        <div class="sf-gov-field">
          ${provBadge(s, 'sporadique')}
          <div class="sf-gov-field-label">Fournisseur sporadique</div>
          ${sporDisplay}
          ${fieldActions(s, 'sporadique')}
        </div>
        <div class="sf-gov-field">
          ${provBadge(s, 'parser_key')}
          <div class="sf-gov-field-label">Clé parseur OCR</div>
          ${parserDisplay}
          ${fieldActions(s, 'parser_key')}
        </div>
        <div class="sf-gov-field">
          <div class="sf-gov-field-label">Dernière ingest</div>
          <span class="sf-gov-field-value">${fmtDate(s.last_seen_at)}</span>
        </div>
      </div>
    </div>`;
  }

  /* ── Fiche rendering ─────────────────────────────────────────────── */
  function openFiche(id) {
    const s = SUPPLIERS.find(x => x.id === id);
    if (!s) return;
    activeId = id;

    document.querySelectorAll('.sf-row').forEach(r =>
      r.classList.toggle('sf-active', parseInt(r.dataset.id) === id)
    );
    document.getElementById('sf-dock-empty').style.display = 'none';
    const ficheEl = document.getElementById('sf-fiche');
    ficheEl.classList.add('sf-visible');

    const isDraft  = s.commissioning_state === 'draft';
    const isAdmin  = ROLE === 'admin';

    /* ── Intake banner (draft only) ── */
    const intakeBanner = isDraft
      ? `<div class="sf-intake-banner">
          <div class="sf-intake-row">
            <div class="sf-intake-text">
              <strong>Nouveau fournisseur</strong> · créé par ingestion ·
              ${s.imported_at ? 'Importé le ' + fmtDate(s.imported_at) : 'Date inconnue'}<br>
              Vérifier les champs auto-remplis, confirmer ou corriger, puis valider la fiche.
            </div>
          </div>
          ${isAdmin ? `<div class="sf-intake-actions">
            <button class="sf-btn-validate-now" onclick="openValidateModal(${s.id})">→ Valider la fiche</button>
          </div>` : ''}
        </div>`
      : '';

    /* ── Hors périmètre banner ── */
    const horsBanner = s.hors_perimetre
      ? `<div class="sf-hors-banner">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
            <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
            <line x1="8" y1="4" x2="8" y2="9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            <circle cx="8" cy="11.5" r=".8" fill="currentColor"/>
          </svg>
          <span>Fournisseur hors périmètre COGS — documents auto-ignorés à l'ingestion.</span>
        </div>`
      : '';

    /* ── Parser chip ── */
    const parserChip = s.parser_key
      ? `<div class="sf-parser-chip"><span class="sf-parser-dot"></span>${escHtml(s.parser_key)}</div>`
      : `<div class="sf-no-parser">Pas de parseur</div>`;

    /* ── Commissioning state badge ── */
    const commState = s.commissioning_state || 'active';
    const commLabels = { draft: '⟳ À valider', active: 'Actif', retired: 'Retraité' };
    const commBadge = `<span class="sf-state-badge ${escHtml(commState)}">${commLabels[commState] || commState}</span>`;

    /* ── is_active badge ── */
    const activeBadge = `<span class="sf-state-badge ${s.is_active ? 'is-active-on' : 'is-active-off'}">${s.is_active ? 'Actif' : 'Inactif'}</span>`;

    /* ── Country display ── */
    const ctryDisplay = s.country_display
      ? (s.country_display.includes('présumé')
          ? escHtml(s.country_display) + ' <span style="font-size:10px;color:var(--ember);border:1px dashed rgba(197,100,74,.35);border-radius:3px;padding:0 4px">présumé</span>'
          : escHtml(s.country_display))
      : '<em style="color:var(--ink-faint)">à renseigner</em>';

    /* ── Aliases strip ── */
    const aliasItems = (s.aliases || []).map(a =>
      `<span class="sf-alias-tag ${escHtml(a.source)}" title="Source: ${escHtml(a.source)}">${escHtml(a.alias)}</span>`
    ).join('');
    const aliasStrip = `<div class="sf-aliases-strip">
      <span class="sf-alias-label">Variantes OCR :</span>
      ${aliasItems || '<span style="color:var(--ink-faint);font-size:11px;font-style:italic">Aucune</span>'}
      ${isAdmin
        ? `<button class="sf-btn-micro sf-btn-confirm-field" style="margin-left:4px" onclick="sfAddAlias(${s.id})">+ Alias</button>`
        : ''}
    </div>`;

    /* ── Stats strip ── */
    const stats = s.stats || { invoices: 0, total_chf: null };
    const statsStrip = `<div class="sf-stats-strip">
      <div class="sf-stat">
        <span class="sf-stat-num">${stats.invoices || 0}</span>
        <span class="sf-stat-lbl">factures</span>
      </div>
      <div class="sf-stat">
        <span class="sf-stat-num">${fmtChf(stats.total_chf)}</span>
        <span class="sf-stat-lbl">CHF total livraisons</span>
      </div>
      <div class="sf-stat">
        <span class="sf-stat-num">${(s.catalogue || []).length}</span>
        <span class="sf-stat-lbl">MI catalogue</span>
      </div>
    </div>`;

    /* ── GL footprint ── */
    const glSection = renderGlFootprint(s);

    /* ── Catalogue table ── */
    let catalogueHtml = '';
    const cat = s.catalogue || [];
    if (cat.length > 0) {
      const rows = cat.map((mi, i) => `<tr>
        <td class="sf-td-mi-id">
          <span class="sf-line-num">${String(i + 1).padStart(2, '0')}</span>
          ${escHtml(mi.mi_id)}
        </td>
        <td>${escHtml(mi.name)}</td>
        <td class="sf-td-cat ${catClass(mi.category)}">${escHtml(mi.category || '—')}</td>
        <td class="sf-td-del">${mi.deliveries}×</td>
      </tr>`).join('');
      catalogueHtml = `<table class="sf-manifest-table">
        <thead><tr>
          <th>MI_ID</th><th>Désignation</th><th>Catégorie</th><th style="text-align:right">Lignes</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>`;
    } else {
      catalogueHtml = `<div class="sf-empty-cat">Aucune livraison résolue — catalogue MI vide.</div>`;
    }

    /* ── Governance section ── */
    const govSection = renderGovSection(s);

    /* ── Change log (stub — no audit data yet) ── */
    const changeLog = `<div class="sf-change-log">
      <button class="sf-log-toggle" onclick="sfToggleLog(this)">▼ Journal des modifications</button>
      <div class="sf-log-entries">
        <div class="sf-log-entry">
          <span>${fmtDate(s.imported_at) || '—'} · ingest</span> · Fiche créée par ingestion
        </div>
        ${s.last_modified_by === 'web'
          ? `<div class="sf-log-entry"><span>${fmtDate(s.last_seen_at)} · web</span> · Champs modifiés via interface</div>`
          : ''}
        <div class="sf-log-entry" style="color:var(--ink-faint);font-style:italic;">
          Historique complet disponible après câblage de l'audit-log (step 3).
        </div>
      </div>
    </div>`;

    /* ── Validate button (admin, draft or explicit re-curation) ── */
    const validateBtn = isAdmin
      ? `<button class="sf-validate-btn" onclick="openValidateModal(${s.id})">
          <span>✓</span>
          <div>
            <div class="sf-vfb-text">${isDraft ? 'Valider la fiche complète' : 'Re-valider / verrouiller des champs'}</div>
            <div class="sf-vfb-sub">${isDraft ? 'Marque comme curatée · verrouille les champs confirmés' : 'Épingle les champs actuels — fiche déjà active'}</div>
          </div>
        </button>`
      : '';

    ficheEl.innerHTML = `<div class="sf-fiche-tabs">
      <!-- Tab strip -->
      <div class="sf-tab-strip" role="tablist">
        <button class="sf-tab sf-tab--active" role="tab" data-tab="fiche" aria-selected="true">Fiche</button>
        <button class="sf-tab" role="tab" data-tab="eval" aria-selected="false">Évaluation</button>
        ${CAN_COMM ? '<button class="sf-tab" role="tab" data-tab="disc" aria-selected="false">Discussions</button>' : ''}
      </div>

      <!-- Panel: Fiche (default active) -->
      <div class="sf-panel sf-panel--active" id="sf-panel-fiche" role="tabpanel">
        <div class="sf-doc-paper sf-reveal">
          ${intakeBanner}
          ${horsBanner}
          <div class="sf-doc-head">
            <div class="sf-doc-stamp">BON FOURNISSEUR</div>
            <div class="sf-doc-head-main">
              <div class="sf-doc-sup-name">${escHtml(s.name)}</div>
              <div class="sf-doc-sup-id">${escHtml(s.supplier_id)}</div>
            </div>
            <div class="sf-doc-head-meta">
              ${activeBadge}
              ${commBadge}
              ${completenessRing(s)}
              ${parserChip}
            </div>
          </div>

          ${statsStrip}

          <div class="sf-doc-fields">

            <div class="sf-doc-field ${!s.gl_account ? 'sf-field-gap' : ''}">
              ${provBadge(s, 'gl_account')}
              <div class="sf-doc-field-label">Compte GL</div>
              <div class="sf-doc-field-value mono">
                ${s.gl_account
                  ? escHtml(s.gl_account) + ' · ' + escHtml(glLabel(s.gl_account, s.gl_label))
                  : '<em style="color:var(--ink-faint)">non défini</em>'}
              </div>
              ${fieldActions(s, 'gl_account')}
            </div>

            <div class="sf-doc-field ${!s.currency ? 'sf-field-gap' : ''}">
              ${provBadge(s, 'currency')}
              <div class="sf-doc-field-label">Devise</div>
              <div class="sf-doc-field-value mono">${s.currency ? escHtml(s.currency) : '<em style="color:var(--ink-faint)">à renseigner</em>'}</div>
              ${fieldActions(s, 'currency')}
            </div>

            <div class="sf-doc-field ${!s.country ? 'sf-field-gap' : ''}">
              ${provBadge(s, 'country')}
              <div class="sf-doc-field-label">Pays</div>
              <div class="sf-doc-field-value">${ctryDisplay}</div>
              ${fieldActions(s, 'country')}
            </div>

            <div class="sf-doc-field">
              <div class="sf-doc-field-label">ID interne</div>
              <div class="sf-doc-field-value mono">${s.id}</div>
            </div>

            ${s.notes ? `<div class="sf-doc-field sf-span2">
              <div class="sf-doc-field-label">Notes</div>
              <div class="sf-doc-field-value" style="font-size:11.5px;color:var(--ink-mute)">${escHtml(s.notes)}</div>
            </div>` : ''}

          </div>

          ${glSection}

          <div class="sf-explainer">
            <p><strong>Pourquoi cette fiche ?</strong> C'est la référence canonique que l'ingestion et les parseurs lisent à chaque réception de document pour résoudre l'identité fournisseur, l'affectation comptable, le régime TVA et le catalogue MI — la résolution est déterministe, jamais devinée.</p>
          </div>

          ${aliasStrip}

          <div class="sf-cat-head">
            <div class="sf-cat-title">Catalogue MI</div>
            <div class="sf-cat-sub">${cat.length} article${cat.length !== 1 ? 's' : ''} · résolution parseur</div>
          </div>
          ${cat.length > 0 ? `<div class="sf-cat-callout">
            <b>Liste de résolution parseur.</b> Le parseur OCR résout les lignes de facture <em>contre cette liste</em>, pas contre l'intégralité de l'univers MI.
          </div>` : ''}
          ${catalogueHtml}

          ${govSection}
          ${changeLog}
          ${validateBtn}
        </div>
      </div>

      <!-- Panel: Évaluation (lazy-loaded on first click) -->
      <div class="sf-panel" id="sf-panel-eval" role="tabpanel"></div>

      <!-- Panel: Discussions (lazy-loaded on first click, manager+/admin only) -->
      ${CAN_COMM ? '<div class="sf-panel" id="sf-panel-disc" role="tabpanel"></div>' : ''}
    </div>`;

    _wireTabStrip(ficheEl, id);
  }

  /* ── Tab strip wiring ───────────────────────────────────────────── */
  function _wireTabStrip(ficheEl, supplierId) {
    const tabs = ficheEl.querySelectorAll('.sf-tab-strip [data-tab]');
    const _loaded = new Set(['fiche']); // fiche panel is always pre-rendered

    tabs.forEach(tabBtn => {
      tabBtn.addEventListener('click', () => {
        const tab = tabBtn.dataset.tab;

        // Update tab active state
        tabs.forEach(t => { t.classList.remove('sf-tab--active'); t.setAttribute('aria-selected', 'false'); });
        tabBtn.classList.add('sf-tab--active');
        tabBtn.setAttribute('aria-selected', 'true');

        // Update panel visibility
        ficheEl.querySelectorAll('.sf-panel').forEach(p => p.classList.remove('sf-panel--active'));
        const panel = ficheEl.querySelector(`#sf-panel-${tab}`);
        if (panel) panel.classList.add('sf-panel--active');

        // Poll management: pause poll when leaving disc tab
        if (tab !== 'disc') {
          if (_discPollInterval) {
            clearInterval(_discPollInterval); _discPollInterval = null;
          }
        }

        // Lazy-load
        if (tab === 'eval' && !_loaded.has('eval')) {
          _loaded.add('eval');
          renderEvalSection(supplierId);
        }

        if (tab === 'disc' && CAN_COMM) {
          // Always re-call renderDiscussionSection when switching to disc
          // (re-fetches + re-starts poll; idempotent — removes existing section first)
          renderDiscussionSection(supplierId);
        }
      });
    });
  }

  /* ── List rendering ──────────────────────────────────────────────── */
  function isIncomplet(s) {
    return !s.country || !s.vat_regime || s.completeness < (s.completeness_max || 6);
  }

  function filteredSuppliers() {
    let result = SUPPLIERS.filter(s => {
      if (currentFilter === 'active'    && !s.is_active)                    return false;
      if (currentFilter === 'parser'    && !s.parser_key)                   return false;
      if (currentFilter === 'catalogue' && (s.catalogue || []).length === 0) return false;
      if (currentFilter === 'hors'      && !s.hors_perimetre)               return false;
      if (currentFilter === 'draft'     && s.commissioning_state !== 'draft') return false;
      if (currentFilter === 'incomplet' && !isIncomplet(s))                 return false;
      if (currentSearch) {
        const q = currentSearch.toLowerCase();
        return s.name.toLowerCase().includes(q)
          || s.supplier_id.toLowerCase().includes(q)
          || (s.gl_account || '').includes(q)
          || (s.aliases || []).some(a => a.alias.toLowerCase().includes(q))
          || (s.parser_key || '').toLowerCase().includes(q);
      }
      return true;
    });

    if (currentSort === 'volume') {
      result = result.slice().sort((a, b) =>
        ((b.stats || {}).total_chf || 0) - ((a.stats || {}).total_chf || 0)
      );
    } else if (currentSort === 'incomplet') {
      result = result.slice().sort((a, b) => (isIncomplet(b) ? 1 : 0) - (isIncomplet(a) ? 1 : 0));
    } else if (currentSort === 'draft') {
      result = result.slice().sort((a, b) =>
        (b.commissioning_state === 'draft' ? 1 : 0) - (a.commissioning_state === 'draft' ? 1 : 0)
      );
    } else {
      result = result.slice().sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    }
    return result;
  }

  function renderList() {
    const listEl  = document.getElementById('sf-list');
    const countEl = document.getElementById('sf-list-count');
    if (!listEl) return;

    const items = filteredSuppliers();

    listEl.innerHTML = items.map(s => {
      const isDraft   = s.commissioning_state === 'draft';
      const incomplet = isIncomplet(s) && !isDraft;

      const rowClasses = [
        'sf-row',
        !s.is_active ? 'sf-inactive' : '',
        s.id === activeId ? 'sf-active' : '',
        isDraft ? 'sf-row-draft' : '',
      ].filter(Boolean).join(' ');

      const healthBadge = isDraft
        ? `<span class="sf-badge sf-badge-draft">à valider</span>`
        : incomplet
          ? `<span class="sf-badge sf-badge-incomplet">○ incomplet</span>`
          : '';

      return `<div class="${rowClasses}" data-id="${s.id}" role="listitem" tabindex="0"
          onclick="sfOpenFiche(${s.id})" onkeydown="if(event.key==='Enter')sfOpenFiche(${s.id})">
        <div class="sf-avatar" style="background:${avatarBg(s.name)}" aria-hidden="true">${initials(s.name)}</div>
        <div class="sf-row-info">
          <div class="sf-row-name">${escHtml(s.name)}</div>
          <div class="sf-row-meta">
            <span class="sf-row-id">${s.id}</span>
            <span class="sf-row-gl">GL ${escHtml(s.gl_account || '—')}</span>
            <span class="sf-row-cur">${escHtml(s.currency || '—')}</span>
          </div>
        </div>
        <div class="sf-badges">
          ${healthBadge}
          ${s.hors_perimetre ? `<span class="sf-badge sf-badge-hors">hors</span>` : ''}
          ${s.parser_key ? `<span class="sf-badge sf-badge-parser">parseur</span>` : ''}
          ${(s.catalogue || []).length > 0
            ? `<span class="sf-badge sf-badge-mi">${s.catalogue.length} MI</span>` : ''}
          <span class="sf-badge ${s.is_active ? 'sf-badge-active' : 'sf-badge-inactive'}">${s.is_active ? 'actif' : 'inactif'}</span>
        </div>
      </div>`;
    }).join('');

    const draftN = SUPPLIERS.filter(s => s.commissioning_state === 'draft').length;
    countEl.textContent = `${items.length} / ${SUPPLIERS.length} · ${draftN} à valider`;
  }

  /* ── Toast ──────────────────────────────────────────────────────── */
  function sfToast(msg) {
    const t = document.getElementById('sf-toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2800);
  }

  /* ── API helper ─────────────────────────────────────────────────── */
  async function sfPost(endpoint, data) {
    const body = new URLSearchParams({ csrf: CSRF, ...data });
    const resp = await fetch(endpoint, { method: 'POST', body });
    const ct   = resp.headers.get('content-type') || '';
    if (ct.includes('application/json')) return resp.json();
    // Non-JSON (e.g. 302/auth redirect) — treat as auth error
    return { ok: false, error: 'Session expirée. Rechargez la page.' };
  }

  /* Update supplier in local SUPPLIERS array (optimistic UI) */
  function sfLocalUpdate(id, patch) {
    const idx = SUPPLIERS.findIndex(x => x.id === id);
    if (idx >= 0) Object.assign(SUPPLIERS[idx], patch);
  }

  /* ── sfEditField — inline edit widget ──────────────────────────── */
  window.sfEditField = function (id, field) {
    const s = SUPPLIERS.find(x => x.id === id);
    if (!s) return;

    // Build the input element for the field
    let inputHtml = '';
    const VAT_REGIMES = [
      ['','— vide —'],
      ['ch_vat','8,1 % TVA CH standard'],
      ['ch_reduced_vat','2,6 % TVA CH réduit'],
      ['intra_eu_vat','0 % export intra-UE'],
      ['third_country_0vat','0 % pays tiers'],
      ['non_taxable','Hors périmètre TVA'],
    ];
    const currentVal = s[field] !== null && s[field] !== undefined ? String(s[field]) : '';

    if (field === 'vat_regime') {
      const opts = VAT_REGIMES.map(([v, l]) =>
        `<option value="${escHtml(v)}" ${currentVal === v ? 'selected' : ''}>${escHtml(l)}</option>`
      ).join('');
      inputHtml = `<select id="sf-inline-input">${opts}</select>`;
    } else if (field === 'hors_perimetre_cogs' || field === 'sporadique') {
      inputHtml = `<select id="sf-inline-input">
        <option value="0" ${currentVal !== '1' ? 'selected' : ''}>Non</option>
        <option value="1" ${currentVal === '1'  ? 'selected' : ''}>Oui</option>
      </select>`;
    } else if (field === 'country') {
      inputHtml = `<input id="sf-inline-input" type="text" value="${escHtml(currentVal)}"
        maxlength="2" pattern="[A-Z]{2}" placeholder="CH" style="text-transform:uppercase;width:60px">`;
    } else {
      inputHtml = `<input id="sf-inline-input" type="text" value="${escHtml(currentVal)}"
        maxlength="${field === 'notes' ? '1000' : '64'}" style="width:${field === 'notes' ? '280px' : '200px'}">`;
    }

    const isCogs = ['gl_account', 'currency'].includes(field);
    const diffEl = document.getElementById('sf-modal-diff');
    if (diffEl) {
      diffEl.innerHTML = `
        <div style="margin-bottom:10px">
          <strong>${escHtml(field)}</strong> · Fournisseur <em>${escHtml(s.name)}</em>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span style="color:var(--ink-mute)">Avant :</span>
          <code style="color:var(--ember)">${escHtml(currentVal || '—')}</code>
          <span style="color:var(--ink-mute)">→</span>
          ${inputHtml}
        </div>
        ${isCogs ? '<div style="margin-top:8px;color:var(--ember);font-size:11px">⚠ Champ COGS-impacting — une confirmation supplémentaire sera demandée.</div>' : ''}
        ${ROLE === 'manager' ? '<div style="margin-top:8px;color:var(--oak);font-size:11px">ℹ Votre modification sera soumise comme proposition en attente de validation admin.</div>' : ''}
      `;
    }

    const overlay = document.getElementById('sf-modal-overlay');
    const confirmBtn = document.getElementById('sf-modal-confirm');
    if (overlay) overlay.classList.add('open');

    // Focus the input after modal opens
    setTimeout(() => {
      const inp = document.getElementById('sf-inline-input');
      if (inp) { inp.focus(); if (inp.select) inp.select(); }
    }, 80);

    // Bind confirm button
    if (confirmBtn) {
      confirmBtn.onclick = async () => {
        const inp = document.getElementById('sf-inline-input');
        const newVal = inp ? inp.value.trim() : '';
        overlay.classList.remove('open');
        await sfDoUpdateField(id, field, newVal, false);
      };
    }
  };

  /* ── sfConfirmField — confirm current value (set verified provenance) ── */
  window.sfConfirmField = function (id, field) {
    const s = SUPPLIERS.find(x => x.id === id);
    if (!s) return;
    const currentVal = s[field] !== null && s[field] !== undefined ? String(s[field]) : '';
    // Confirming = updating to the same value — sets last_modified_by='web'
    sfDoUpdateField(id, field, currentVal, false);
  };

  /* ── sfPinField — toggle pin for a field ──────────────────────── */
  window.sfPinField = function (id, field, isCurrentlyPinned) {
    const s = SUPPLIERS.find(x => x.id === id);
    if (!s) return;
    if (isCurrentlyPinned) {
      // Unpin immediately
      sfDoPin(id, field, 'unpin', null, null);
    } else {
      // Pin: use current value, optionally ask for reason
      const currentVal = s[field] !== null && s[field] !== undefined ? String(s[field]) : '';
      const reason = window.prompt(
        `Raison de l'épingle pour « ${field} » (optionnel) :`, ''
      );
      if (reason === null) return; // cancelled
      sfDoPin(id, field, 'pin', currentVal, reason);
    }
  };

  async function sfDoPin(id, field, action, pinnedValue, pinReason) {
    try {
      const data = { supplier_fk: String(id), field_name: field, action };
      if (action === 'pin') {
        data.pinned_value = pinnedValue !== null ? pinnedValue : '';
        data.pin_reason   = pinReason || '';
      }
      const res = await sfPost('/api/sf-pin-field.php', data);
      if (!res.ok) {
        sfToast('Erreur : ' + (res.error || 'inconnue'));
        return;
      }
      // Update local state
      const s = SUPPLIERS.find(x => x.id === id);
      if (s) {
        if (action === 'pin' && res.pin) {
          s.pins = s.pins || {};
          s.pins[field] = res.pin;
        } else {
          if (s.pins) delete s.pins[field];
        }
      }
      sfToast(action === 'pin'
        ? `Champ « ${field} » verrouillé.`
        : `Champ « ${field} » désépinglé.`);
      // Re-render fiche
      openFiche(id);
    } catch (e) {
      sfToast('Erreur réseau : ' + e.message);
    }
  }

  async function sfDoUpdateField(id, field, newValue, isConfirmedCogs) {
    try {
      const data = {
        supplier_fk: String(id),
        field_name:  field,
        new_value:   newValue,
      };
      if (isConfirmedCogs) data.confirmed = '1';

      const res = await sfPost('/api/sf-update-field.php', data);

      if (!res.ok && res.needs_confirm) {
        // COGS-impacting: show confirm dialog
        const diffEl = document.getElementById('sf-modal-diff');
        if (diffEl) {
          diffEl.innerHTML = `
            <div style="color:var(--ember);margin-bottom:10px">⚠ Modification COGS-impacting</div>
            <strong>${escHtml(field)}</strong> · Fournisseur <em>${escHtml((SUPPLIERS.find(x=>x.id===id)||{}).name||'')}</em><br><br>
            <span style="color:var(--ink-mute)">Avant :</span> <code style="color:var(--ember)">${escHtml(String(res.old_value ?? '—'))}</code>
            &nbsp;→&nbsp;
            <span style="color:var(--ink-mute)">Après :</span> <code style="color:var(--hop)">${escHtml(String(res.new_value ?? ''))}</code>
            <div style="margin-top:10px;font-size:11px;color:var(--ink-mute)">Ce champ affecte le calcul COGS. Confirmez pour appliquer.</div>
          `;
        }
        const overlay = document.getElementById('sf-modal-overlay');
        const confirmBtn = document.getElementById('sf-modal-confirm');
        if (overlay) overlay.classList.add('open');
        if (confirmBtn) {
          confirmBtn.onclick = async () => {
            overlay.classList.remove('open');
            await sfDoUpdateField(id, field, newValue, true);
          };
        }
        return;
      }

      if (!res.ok) {
        sfToast('Erreur : ' + (res.error || 'inconnue'));
        return;
      }

      if (res.pending) {
        sfToast(`Proposition pour « ${field} » soumise — en attente admin.`);
        return;
      }

      // Success: update local supplier state
      const s = SUPPLIERS.find(x => x.id === id);
      if (s) {
        s[field] = res.new_value;
        s.last_modified_by = 'web';
        // Update country_display if country changed
        if (field === 'country') {
          s.country_display = res.new_value || '';
        }
      }
      sfToast(`Champ « ${field} » mis à jour.`);
      openFiche(id);
    } catch (e) {
      sfToast('Erreur réseau : ' + e.message);
    }
  }

  /* ── sfAddAlias — add an OCR alias ─────────────────────────────── */
  window.sfAddAlias = function (id) {
    const s = SUPPLIERS.find(x => x.id === id);
    if (!s) return;

    const aliasText = window.prompt(`Ajouter un alias OCR pour «${s.name}» :`);
    if (!aliasText || !aliasText.trim()) return;

    sfPost('/api/sf-add-alias.php', {
      supplier_fk: String(id),
      alias:       aliasText.trim(),
      source:      'manual',
    }).then(res => {
      if (!res.ok) {
        sfToast('Erreur : ' + (res.error || 'inconnue'));
        return;
      }
      // Update local state
      const s2 = SUPPLIERS.find(x => x.id === id);
      if (s2) {
        s2.aliases = s2.aliases || [];
        s2.aliases.push({ alias: res.alias.alias, source: res.alias.source });
      }
      sfToast(`Alias «${aliasText.trim()}» ajouté.`);
      openFiche(id);
    }).catch(e => sfToast('Erreur réseau : ' + e.message));
  };

  /* ── Validate modal ─────────────────────────────────────────────── */
  window.openValidateModal = function (id) {
    const s = SUPPLIERS.find(x => x.id === id);
    if (!s) return;
    const isDraft = s.commissioning_state === 'draft';

    // Build a checklist of fields that have values to pin
    const PINNABLE = ['gl_account','currency','country','vat_regime','vat_number','parser_key'];
    const fieldsWithValues = PINNABLE.filter(f => {
      const v = s[f];
      return v !== null && v !== undefined && v !== '';
    });

    const diffEl = document.getElementById('sf-validate-diff');
    if (diffEl) {
      const stateHtml = isDraft
        ? `commissioning_state : <span style="color:var(--ember)">draft</span> → <span style="color:var(--hop)">active</span><br>`
        : `<span style="color:var(--hop)">Fiche déjà active</span> — action : verrouiller les champs confirmés.<br>`;
      const fieldList = fieldsWithValues.length > 0
        ? `<br><strong>Champs qui seront verrouillés :</strong><ul style="margin:6px 0 0 16px;font-size:11px">` +
          fieldsWithValues.map(f => `<li><code>${escHtml(f)}</code> = <em>${escHtml(String(s[f] || ''))}</em></li>`).join('') +
          `</ul>`
        : '<br><em style="color:var(--ink-mute)">Aucun champ renseigné à verrouiller.</em>';
      diffEl.innerHTML = `Fiche <strong>${escHtml(s.name)}</strong> (id ${s.id})<br>${stateHtml}${fieldList}`;
    }

    const overlay = document.getElementById('sf-validate-modal');
    const confirmBtn = document.getElementById('sf-validate-confirm');
    if (overlay) overlay.classList.add('open');

    // Enable confirm button and bind click
    if (confirmBtn) {
      confirmBtn.disabled = false;
      confirmBtn.title    = '';
      confirmBtn.textContent = isDraft ? '✓ Valider' : '✓ Verrouiller';
      confirmBtn.onclick = async () => {
        confirmBtn.disabled = true;
        confirmBtn.textContent = '…';
        overlay.classList.remove('open');
        await sfDoValidate(id, fieldsWithValues);
      };
    }
  };

  async function sfDoValidate(id, confirmedFields) {
    try {
      const res = await sfPost('/api/sf-validate-supplier.php', {
        supplier_fk:      String(id),
        confirmed_fields: JSON.stringify(confirmedFields),
      });
      if (!res.ok) {
        sfToast('Erreur : ' + (res.error || 'inconnue'));
        return;
      }
      // Update local state
      const s = SUPPLIERS.find(x => x.id === id);
      if (s) {
        s.commissioning_state = 'active';
        // Mark pinned fields
        s.pins = s.pins || {};
        confirmedFields.forEach(f => {
          s.pins[f] = {
            pinned_value: s[f] !== undefined ? String(s[f]) : null,
            pinned_by:    'web',
            pinned_at:    new Date().toISOString(),
            pin_reason:   'Validé via fiche fournisseur',
          };
        });
      }
      sfToast(res.already_active
        ? `Fiche validée — ${res.pins_created} champ(s) verrouillé(s).`
        : `Fiche activée · ${res.pins_created} champ(s) verrouillé(s).`);
      renderList();
      openFiche(id);
    } catch (e) {
      sfToast('Erreur réseau : ' + e.message);
    }
  }

  /* ── Log toggle ─────────────────────────────────────────────────── */
  window.sfToggleLog = function (btn) {
    const entries = btn.nextElementSibling;
    if (!entries) return;
    entries.classList.toggle('open');
    btn.textContent = entries.classList.contains('open')
      ? '▲ Journal des modifications'
      : '▼ Journal des modifications';
  };

  /* ── Public open fiche (called from PHP-rendered onclick) ─────── */
  window.sfOpenFiche = function (id) { openFiche(id); };

  /* ── Event wiring ───────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', function () {

    /* Search */
    const searchEl = document.getElementById('sf-search');
    if (searchEl) {
      searchEl.addEventListener('input', e => {
        currentSearch = e.target.value.trim();
        renderList();
      });
    }

    /* Filter chips */
    document.querySelectorAll('.sf-chip').forEach(btn => {
      btn.addEventListener('click', () => {
        currentFilter = btn.dataset.filter;
        document.querySelectorAll('.sf-chip').forEach(b =>
          b.classList.toggle('on', b.dataset.filter === currentFilter)
        );
        renderList();
      });
    });

    /* Sort select */
    const sortEl = document.getElementById('sf-sort-select');
    if (sortEl) {
      sortEl.addEventListener('change', e => {
        currentSort = e.target.value;
        renderList();
      });
    }

    /* Modal cancel / backdrop close */
    const overlay = document.getElementById('sf-modal-overlay');
    const cancelBtn = document.getElementById('sf-modal-cancel');
    if (overlay) {
      overlay.addEventListener('click', e => {
        if (e.target === overlay) overlay.classList.remove('open');
      });
    }
    if (cancelBtn) cancelBtn.addEventListener('click', () => {
      document.getElementById('sf-modal-overlay').classList.remove('open');
    });

    const validateOverlay = document.getElementById('sf-validate-modal');
    const validateCancel  = document.getElementById('sf-validate-cancel');
    if (validateOverlay) {
      validateOverlay.addEventListener('click', e => {
        if (e.target === validateOverlay) validateOverlay.classList.remove('open');
      });
    }
    if (validateCancel) validateCancel.addEventListener('click', () => {
      document.getElementById('sf-validate-modal').classList.remove('open');
    });

    /* Keyboard navigation */
    document.addEventListener('keydown', e => {
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
      if (e.key === 'Escape') {
        document.querySelectorAll('.sf-modal-overlay.open').forEach(m =>
          m.classList.remove('open')
        );
      }
    });

    /* Initial render */
    renderList();

    /* Auto-open first active supplier with catalogue for a warm UX */
    const firstGood = SUPPLIERS.find(s => s.is_active && (s.catalogue || []).length > 0);
    if (firstGood) {
      setTimeout(() => openFiche(firstGood.id), 350);
    }
  });

  /* ── EVAL SECTION (Wave 3) ───────────────────────────────────────────── */

  const EVAL_TYPE_LABELS = {
    'initial':      'Initiale',
    'annuel':       'Annuelle',
    'biennal':      'Biennale',
    'evenementiel': 'Événementielle',
  };

  const NC_TYPE_LABELS = {
    'food_safety':   'Sécurité alimentaire',
    'quality':       'Qualité',
    'delivery':      'Livraison',
    'documentation': 'Documentation',
    'esg':           'RSE/ESG',
    'other':         'Autre',
  };

  const NC_STATUS_LABELS = {
    'open':        'Ouverte',
    'in_progress': 'En cours',
    'closed':      'Clôturée',
  };

  function evalResultBadge(result, totalPct) {
    if (!result) return '';
    const classMap = {
      'agree':                  'agree',
      'agree_sous_surveillance': 'agree-surv',
      'non_agree':              'non-agree',
      'draft':                  'draft',
    };
    const labelMap = {
      'agree':                  'Agréé',
      'agree_sous_surveillance': 'Agréé sous surveillance',
      'non_agree':              'Non agréé',
      'draft':                  'Brouillon',
    };
    const cls   = classMap[result]  || 'draft';
    const label = labelMap[result]  || escHtml(result);
    const pctStr = totalPct != null
      ? ` · ${parseFloat(totalPct).toFixed(1)} %`
      : '';
    return `<span class="sf-eval-result-badge ${cls}">${label}${escHtml(pctStr)}</span>`;
  }

  async function renderEvalSection(supplierId) {
    const ficheEl = document.getElementById('sf-fiche');
    if (!ficheEl) return;

    // Remove any existing eval section
    const existing = document.getElementById('sf-eval-section');
    if (existing) existing.remove();

    // Append placeholder
    const placeholder = document.createElement('div');
    placeholder.id = 'sf-eval-section';
    placeholder.className = 'sf-eval-section';
    placeholder.innerHTML = '<div class="sf-eval-loading">Chargement évaluation…</div>';
    const evalPanel = ficheEl.querySelector('#sf-panel-eval') || ficheEl.querySelector('.sf-doc-paper');
    evalPanel.appendChild(placeholder);

    try {
      const resp = await fetch(`/api/sf-supplier-evaluation.php?supplier_id=${encodeURIComponent(supplierId)}`);
      if (!resp.ok) {
        placeholder.innerHTML = `<div class="sf-eval-loading">Impossible de charger les données d'évaluation.</div>`;
        return;
      }
      const data = await resp.json();
      if (!data.ok) {
        placeholder.innerHTML = `<div class="sf-eval-loading">Erreur : ${escHtml(data.error || 'inconnue')}</div>`;
        return;
      }

      placeholder.innerHTML = renderEvalContent(data);
      wireEvalEvents(placeholder, data, supplierId);
    } catch (e) {
      placeholder.innerHTML = `<div class="sf-eval-loading">Erreur réseau : ${escHtml(e.message)}</div>`;
    }
  }

  /* ── Discussion timeline section ────────────────────────────────── */
  let _discPollInterval = null;

  async function renderDiscussionSection(supplierId) {
    if (_discPollInterval) { clearInterval(_discPollInterval); _discPollInterval = null; }

    const ficheEl = document.getElementById('sf-fiche');
    if (!ficheEl) return;
    const existing = document.getElementById('sf-disc-section');
    if (existing) existing.remove();

    const placeholder = document.createElement('div');
    placeholder.id = 'sf-disc-section';
    placeholder.className = 'sf-disc-section';
    placeholder.innerHTML = '<div class="sf-disc-loading">Chargement des discussions…</div>';
    const discPanel = ficheEl.querySelector('#sf-panel-disc') || ficheEl.querySelector('.sf-doc-paper');
    discPanel.appendChild(placeholder);

    await _loadDiscussionSection(supplierId, placeholder);

    _discPollInterval = setInterval(async () => {
      const sec = document.getElementById('sf-disc-section');
      const dPanel = document.getElementById('sf-panel-disc');
      if (!sec || !sec.closest('#sf-fiche.sf-visible')) {
        clearInterval(_discPollInterval); _discPollInterval = null; return;
      }
      // Only poll if disc panel is active
      if (dPanel && !dPanel.classList.contains('sf-panel--active')) {
        return; // skip this tick, don't clear — panel may become active again
      }
      await _loadDiscussionSection(supplierId, sec);
    }, 45000);
  }

  async function _loadDiscussionSection(supplierId, container) {
    try {
      const resp = await fetch(`/api/sf-comm-thread.php?supplier_id=${encodeURIComponent(supplierId)}`);
      if (!resp.ok) { container.innerHTML = `<div class="sf-disc-loading">Impossible de charger les discussions.</div>`; return; }
      const data = await resp.json();
      if (!data.ok) { container.innerHTML = `<div class="sf-disc-loading">Erreur : ${escHtml(data.error || 'inconnue')}</div>`; return; }
      // Capture scroll position BEFORE destroying old DOM
      const prevFeed = container.querySelector('#sf-disc-feed');
      const prevFeedScroll = prevFeed ? (parseInt(prevFeed.dataset.scrollTop || '0', 10) || prevFeed.scrollTop || 0) : 0;

      container.innerHTML = _renderDiscContent(supplierId, data);
      container.__sfDiscData = data;
      _wireDiscEvents(container, supplierId);

      // Restore feed scroll position across poll re-renders
      if (prevFeedScroll > 0) {
        const newFeed = container.querySelector('#sf-disc-feed');
        if (newFeed) newFeed.scrollTop = prevFeedScroll;
      }

      // Update Discussions tab badge
      const discTab = document.querySelector('.sf-tab-strip [data-tab="disc"]');
      if (discTab) {
        const msgCount = (data.timeline || []).length;
        const reviewCount = (data.review_threads || []).length;
        const total = msgCount;
        const hasReview = reviewCount > 0 && ROLE === 'admin';
        discTab.dataset.discCount = total;
        discTab.innerHTML = `Discussions${total > 0 ? ` <span class="sf-tab-badge">${total}</span>` : ''}${hasReview ? ' <span class="sf-tab-warn">⚠</span>' : ''}`;
      }
    } catch (e) {
      container.innerHTML = `<div class="sf-disc-loading">Erreur réseau : ${escHtml(e.message)}</div>`;
    }
  }

  function _renderOneMessage(item) {
    try {
      const dirClass = item.direction === 'in' ? 'sf-disc-in' : 'sf-disc-out';
      const dirLabel = item.direction === 'in' ? '← Reçu' : '→ Envoyé';
      const sourceLabel = item.source === 'manual' ? 'Note manuelle' : 'Email';
      const srcClass = item.source === 'manual' ? 'sf-disc-source-manual' : 'sf-disc-source-email';

      const docsHtml = (item.docs || []).map(doc => {
        const viewUrl = doc.doc_file_uuid
          ? `/api/document.php?file_id=${encodeURIComponent(doc.doc_file_uuid)}`
          : '#';
        const target = doc.doc_file_uuid ? ' target="_blank" rel="noopener"' : '';
        return `<a class="sf-disc-doc-chip" href="${escHtml(viewUrl)}"${target}>
            <span class="sf-disc-doc-icon">📎</span>
            <span class="sf-disc-doc-name">${escHtml(doc.attachment_filename)}</span>
          </a>`;
      }).join('');

      const bodyHtml = item.body_plain
        ? `<div class="sf-disc-body">${escHtml(item.body_plain)}</div>`
        : '';

      const sentByHtml = (item.direction === 'out' && item.sent_by_display)
        ? `<span class="sf-disc-sent-by">par ${escHtml(item.sent_by_display)}</span>`
        : '';

      return `<div class="sf-disc-item ${dirClass}">
          <div class="sf-disc-item-head">
            <span class="sf-disc-dir-badge ${dirClass}">${dirLabel}</span>
            <span class="sf-disc-from">${escHtml(item.from_address || '—')}</span>
            <span class="sf-disc-date">${fmtDate(item.sent_at)}</span>
            <span class="sf-disc-source ${srcClass}">${sourceLabel}</span>
            ${sentByHtml}
          </div>
          ${bodyHtml}
          ${docsHtml ? `<div class="sf-disc-docs">${docsHtml}</div>` : ''}
        </div>`;
    } catch (e) {
      return `<div class="sf-disc-item sf-disc-item--unreadable"><em>(message illisible)</em></div>`;
    }
  }

  function _renderDiscContent(supplierId, data) {
    const timeline      = data.timeline || [];
    const reviewThreads = data.review_threads || [];

    // ── Collapsed "À rattacher" banner (admin only, review threads present) ──
    let bannerHtml = '';
    if (ROLE === 'admin' && reviewThreads.length > 0) {
      const bannerItems = reviewThreads.map(t => {
        const supplierOpts = (SUPPLIERS || []).map(s =>
          `<option value="${s.id}">${escHtml(s.name)}</option>`
        ).join('');
        const dateShort = t.last_message_at
          ? new Date(t.last_message_at).toLocaleDateString('fr-CH', { day: 'numeric', month: 'short' })
          : '—';
        return `<div class="sf-disc-review-banner-item" data-thread-id="${t.id}" role="button" tabindex="0">
            <div class="sf-disc-review-banner-subject">⚠ ${escHtml(t.subject || '(sans objet)')}</div>
            <div class="sf-disc-review-banner-meta">
              <span>${escHtml(t.counterparty_addresses || '—')}</span>
              <span>${escHtml(dateShort)}</span>
              <span>${t.message_count} msg</span>
            </div>
            <div class="sf-disc-review-banner-body" id="sf-disc-review-body-${t.id}"></div>
            <div class="sf-disc-triage-bar sf-disc-triage-bar--inline" data-thread-id="${t.id}">
              <span class="sf-disc-triage-label">⚠ Rattacher ce fil :</span>
              <select class="sf-disc-review-supplier-pick">
                <option value="">— Choisir fournisseur —</option>
                ${supplierOpts}
              </select>
              <button class="sf-disc-btn-assign">Rattacher</button>
            </div>
          </div>`;
      }).join('');

      bannerHtml = `<div class="sf-disc-review-banner" id="sf-disc-review-banner">
          <button class="sf-disc-review-banner-toggle" id="sf-disc-review-banner-toggle" type="button">
            ⚠ ${reviewThreads.length} email${reviewThreads.length > 1 ? 's' : ''} à rattacher
            <span class="sf-disc-review-banner-chevron">▾</span>
          </button>
          <div class="sf-disc-review-list" id="sf-disc-review-list">
            ${bannerItems}
          </div>
        </div>`;
    }

    // ── Full-width chronological feed ──
    let feedHtml = '';
    if (timeline.length === 0) {
      feedHtml = `<div class="sf-disc-feed-empty">Aucun message pour ce fournisseur.</div>`;
    } else {
      feedHtml = timeline.map(item => _renderOneMessage(item)).join('');
    }

    // ── Compose-note (once, at bottom) ──
    const composeHtml = `<div class="sf-disc-convo-compose">
        <div class="sf-disc-compose-head">Ajouter une note</div>
        <textarea id="sf-disc-note-text" class="sf-disc-note-textarea" rows="3" placeholder="Note, appel téléphonique, décision…"></textarea>
        <div class="sf-disc-compose-row">
          <input type="date" id="sf-disc-note-date" class="sf-disc-note-date" title="Date (optionnelle — défaut : maintenant)">
          <button id="sf-disc-note-submit" class="sf-disc-btn-submit">Ajouter une note</button>
        </div>
      </div>`;

    // ── Reply composer — target most-recent inbound across whole timeline ──
    const _canReply = () => {
      if (ROLE !== 'admin' && ROLE !== 'manager') return { can: false, reason: 'role' };
      if (!USER_EMAIL || !USER_EMAIL.endsWith('@lanebuleuse.ch')) return { can: false, reason: 'email' };
      const lastInbound = [...timeline].reverse().find(m => m.direction === 'in');
      if (!lastInbound) return { can: false, reason: 'no_inbound' };
      return { can: true, recipient: lastInbound.from_address || '', thread_id: lastInbound.thread_id };
    };

    const replyGate = _canReply();
    let replyHtml = '';
    if (!replyGate.can) {
      if (replyGate.reason === 'email') {
        replyHtml = `<div class="sf-disc-reply-gate">
            <div class="sf-disc-reply-gate-msg">Votre compte n'a pas d'adresse e-mail @lanebuleuse.ch — l'envoi est indisponible.</div>
          </div>`;
      } else if (replyGate.reason === 'no_inbound') {
        replyHtml = `<div class="sf-disc-reply-gate">
            <div class="sf-disc-reply-gate-msg">Pas de destinataire pour répondre à ce fournisseur.</div>
          </div>`;
      }
    } else {
      const recipientEsc = escHtml(replyGate.recipient);
      replyHtml = `<div class="sf-disc-reply-compose" id="sf-disc-reply-compose" data-reply-thread-id="${replyGate.thread_id}">
          <div class="sf-disc-reply-head">
            <span class="sf-disc-reply-label">Répondre par e-mail</span>
            <span class="sf-disc-reply-distinguish">Ceci envoie un email réel au fournisseur</span>
          </div>
          <div class="sf-disc-reply-to">À : <span id="sf-disc-reply-recipient">${recipientEsc}</span></div>
          <textarea id="sf-disc-reply-body" class="sf-disc-reply-textarea" rows="4" placeholder="Corps du message…"></textarea>

          <div class="sf-disc-attach-zone" id="sf-disc-attach-zone">
            <div class="sf-disc-attach-drop" id="sf-disc-attach-drop" role="button" tabindex="0" aria-label="Glisser-déposer des fichiers ou cliquer pour parcourir">
              <span class="sf-disc-attach-drop-icon">📎</span>
              <span>Glisser-déposer ou <u>parcourir</u></span>
              <input type="file" id="sf-disc-attach-input" multiple hidden>
            </div>
            <button class="sf-disc-attach-picker-btn" id="sf-disc-attach-picker-btn" type="button">
              + Joindre un document du fournisseur
            </button>
            <div class="sf-disc-attach-chips" id="sf-disc-attach-chips"></div>
            <div class="sf-disc-supplier-docs-picker" id="sf-disc-supplier-docs-picker" style="display:none">
              <div class="sf-disc-sdp-loading" id="sf-disc-sdp-loading">Chargement…</div>
              <div class="sf-disc-sdp-list" id="sf-disc-sdp-list"></div>
            </div>
            <div class="sf-disc-attach-errors" id="sf-disc-attach-errors"></div>
          </div>

          <div class="sf-disc-reply-actions" id="sf-disc-reply-actions">
            <div class="sf-disc-reply-confirm" id="sf-disc-reply-confirm" style="display:none">
              <span class="sf-disc-reply-confirm-text">Confirmer l'envoi à <strong id="sf-disc-reply-confirm-addr"></strong> ?</span>
              <button class="sf-disc-reply-confirm-yes" id="sf-disc-reply-confirm-yes">Confirmer</button>
              <button class="sf-disc-reply-confirm-no" id="sf-disc-reply-confirm-no">Annuler</button>
            </div>
            <button class="sf-disc-reply-send-btn" id="sf-disc-reply-send-btn" type="button">Envoyer</button>
          </div>
          <div class="sf-disc-reply-error" id="sf-disc-reply-error" style="display:none"></div>
        </div>`;
    }

    return `<div class="sf-disc-feed-wrap">
        ${bannerHtml}
        <div class="sf-disc-feed" id="sf-disc-feed">
          ${feedHtml}
        </div>
        ${replyHtml}
        <div class="sf-disc-note-toggle-bar">
          <button type="button" class="sf-disc-note-toggle-btn" id="sf-disc-note-toggle" aria-expanded="false" aria-controls="sf-disc-note-collapse">+ Ajouter une note interne</button>
        </div>
        <div class="sf-disc-note-collapse" id="sf-disc-note-collapse">
          ${composeHtml}
        </div>
      </div>`;
  }

  function _wireDiscEvents(container, supplierId) {
    const data = container.__sfDiscData || { timeline: [], threads: [], review_threads: [] };

    // ── Scroll-position preservation ──
    const feedEl = container.querySelector('#sf-disc-feed');
    if (feedEl) {
      feedEl.dataset.scrollTop = feedEl.scrollTop || '0';
      feedEl.addEventListener('scroll', () => {
        feedEl.dataset.scrollTop = String(feedEl.scrollTop);
      }, { passive: true });
    }

    // ── Banner expand/collapse ──
    const bannerToggle = container.querySelector('#sf-disc-review-banner-toggle');
    const reviewList   = container.querySelector('#sf-disc-review-list');
    const banner       = container.querySelector('#sf-disc-review-banner');
    if (bannerToggle && reviewList) {
      bannerToggle.addEventListener('click', () => {
        const isOpen = banner.classList.contains('sf-disc-review-banner--open');
        banner.classList.toggle('sf-disc-review-banner--open', !isOpen);
      });
    }


    // ── Note-toggle expand/collapse ──
    const noteToggleBtn  = container.querySelector('#sf-disc-note-toggle');
    const noteCollapseEl = container.querySelector('#sf-disc-note-collapse');
    if (noteToggleBtn && noteCollapseEl) {
      noteToggleBtn.addEventListener('click', () => {
        const isOpen = noteCollapseEl.classList.contains('sf-disc-note-collapse--open');
        noteCollapseEl.classList.toggle('sf-disc-note-collapse--open', !isOpen);
        noteToggleBtn.setAttribute('aria-expanded', String(!isOpen));
        if (!isOpen) {
          // Focus the textarea when expanding
          const ta = noteCollapseEl.querySelector('#sf-disc-note-text');
          if (ta) ta.focus();
        }
      });
    }

    // Cache for on-demand fetched review thread timelines
    var _reviewTimelineCache = {};

    // ── Banner item clicks — lazy load review thread body ──
    if (reviewList) {
      reviewList.querySelectorAll('.sf-disc-review-banner-item').forEach(function(itemEl) {
        const threadId = parseInt(itemEl.dataset.threadId, 10);
        const bodyEl   = itemEl.querySelector('#sf-disc-review-body-' + threadId);
        var _loaded = false;

        const loadBody = async () => {
          if (_loaded) return;
          if (!bodyEl) return;
          if (_reviewTimelineCache[threadId]) {
            bodyEl.innerHTML = _reviewTimelineCache[threadId].map(i => _renderOneMessage(i)).join('') || '<em class="sf-disc-review-body-empty">Aucun message.</em>';
            _loaded = true;
            return;
          }
          bodyEl.innerHTML = '<em class="sf-disc-review-body-loading">Chargement…</em>';
          try {
            const resp = await fetch('/api/sf-comm-thread.php?review_thread_id=' + encodeURIComponent(threadId));
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const json = await resp.json();
            if (!json.ok) throw new Error(json.error || 'Erreur API');
            _reviewTimelineCache[threadId] = json.timeline || [];
            bodyEl.innerHTML = _reviewTimelineCache[threadId].map(i => _renderOneMessage(i)).join('') || '<em class="sf-disc-review-body-empty">Aucun message.</em>';
            _loaded = true;
          } catch (e) {
            bodyEl.innerHTML = '<em class="sf-disc-review-body-error">Erreur : ' + escHtml(e.message) + '</em>';
          }
        };

        itemEl.addEventListener('click', async function(e) {
          // Don't trigger if clicking inside triage bar buttons/selects
          if (e.target.closest('.sf-disc-triage-bar--inline')) return;
          await loadBody();
          itemEl.classList.toggle('sf-disc-review-banner-item--expanded');
        });
        itemEl.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); itemEl.click(); }
        });
      });

      // ── Triage assign buttons inside banner ──
      reviewList.querySelectorAll('.sf-disc-triage-bar--inline').forEach(function(triageBar) {
        const btn  = triageBar.querySelector('.sf-disc-btn-assign');
        const pick = triageBar.querySelector('.sf-disc-review-supplier-pick');
        const tid  = triageBar.dataset.threadId;
        if (btn && pick && tid) {
          btn.addEventListener('click', async function(e) {
            e.stopPropagation();
            const selectedSupplierId = pick.value;
            if (!selectedSupplierId) { sfToast('Veuillez sélectionner un fournisseur.'); return; }
            btn.disabled = true;
            btn.textContent = '…';
            try {
              const res = await sfPost('/api/sf-comm-thread.php', {
                action:      'assign_thread',
                thread_id:   tid,
                supplier_id: selectedSupplierId,
              });
              if (res.ok) {
                sfToast('Fil rattaché.');
                const sec = document.getElementById('sf-disc-section');
                if (sec) await _loadDiscussionSection(supplierId, sec);
              } else {
                sfToast('Erreur : ' + (res.error || 'inconnue'));
                btn.disabled = false;
                btn.textContent = 'Rattacher';
              }
            } catch (e2) {
              sfToast('Erreur réseau : ' + e2.message);
              btn.disabled = false;
              btn.textContent = 'Rattacher';
            }
          });
          // Prevent clicks inside select from bubbling up to item expand
          pick.addEventListener('click', function(e) { e.stopPropagation(); });
        }
      });
    }

    // ── Compose note (bottom, no thread_id required) ──
    const submitBtn = container.querySelector('#sf-disc-note-submit');
    const textEl    = container.querySelector('#sf-disc-note-text');
    const dateEl    = container.querySelector('#sf-disc-note-date');
    if (submitBtn && textEl) {
      submitBtn.addEventListener('click', async () => {
        const text = (textEl.value || '').trim();
        if (!text) { sfToast('Le texte de la note est requis.'); textEl.focus(); return; }
        submitBtn.disabled = true;
        submitBtn.textContent = '…';
        try {
          const body = { supplier_id: String(supplierId), text };
          if (dateEl && dateEl.value) body.note_date = dateEl.value;
          const res = await sfPost('/api/sf-comm-thread.php', { action: 'add_note', ...body });
          if (res.ok) {
            textEl.value = '';
            if (dateEl) dateEl.value = '';
            const sec = document.getElementById('sf-disc-section');
            if (sec) await _loadDiscussionSection(supplierId, sec);
          } else {
            sfToast('Erreur : ' + (res.error || 'inconnue'));
            submitBtn.disabled = false;
            submitBtn.textContent = 'Ajouter une note';
          }
        } catch (e) {
          sfToast('Erreur réseau : ' + e.message);
          submitBtn.disabled = false;
          submitBtn.textContent = 'Ajouter une note';
        }
      });
    }

    // ── Reply composer (thread_id from data-reply-thread-id) ──
    const replyCompose = container.querySelector('#sf-disc-reply-compose');
    if (replyCompose) {
      const threadIdForReply = parseInt(replyCompose.dataset.replyThreadId, 10) || null;
      const bodyEl      = container.querySelector('#sf-disc-reply-body');
      const dropEl      = container.querySelector('#sf-disc-attach-drop');
      const inputEl     = container.querySelector('#sf-disc-attach-input');
      const pickerBtn   = container.querySelector('#sf-disc-attach-picker-btn');
      const chipsEl     = container.querySelector('#sf-disc-attach-chips');
      const sdpEl       = container.querySelector('#sf-disc-supplier-docs-picker');
      const sdpList     = container.querySelector('#sf-disc-sdp-list');
      const sdpLoading  = container.querySelector('#sf-disc-sdp-loading');
      const sendBtn     = container.querySelector('#sf-disc-reply-send-btn');
      const confirmEl   = container.querySelector('#sf-disc-reply-confirm');
      const confirmAddr = container.querySelector('#sf-disc-reply-confirm-addr');
      const confirmYes  = container.querySelector('#sf-disc-reply-confirm-yes');
      const confirmNo   = container.querySelector('#sf-disc-reply-confirm-no');
      const replyErrEl  = container.querySelector('#sf-disc-reply-error');
      const recipientEl = container.querySelector('#sf-disc-reply-recipient');

      var _sdpLoaded = false;

      // Dropzone
      if (dropEl && inputEl) {
        dropEl.addEventListener('click', () => inputEl.click());
        dropEl.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inputEl.click(); } });
        dropEl.addEventListener('dragover', e => { e.preventDefault(); dropEl.classList.add('sf-disc-attach-drop--over'); });
        dropEl.addEventListener('dragleave', () => dropEl.classList.remove('sf-disc-attach-drop--over'));
        dropEl.addEventListener('drop', e => {
          e.preventDefault();
          dropEl.classList.remove('sf-disc-attach-drop--over');
          if (e.dataTransfer.files.length) _handleFileUploads(e.dataTransfer.files);
        });
        inputEl.addEventListener('change', () => {
          if (inputEl.files.length) _handleFileUploads(inputEl.files);
          inputEl.value = '';
        });
      }

      // Supplier docs picker
      if (pickerBtn && sdpEl) {
        pickerBtn.addEventListener('click', async () => {
          const visible = sdpEl.style.display !== 'none';
          sdpEl.style.display = visible ? 'none' : 'block';
          if (!visible && !_sdpLoaded) {
            _sdpLoaded = true;
            try {
              const resp = await fetch('/api/sf-comm-thread.php?supplier_docs=' + encodeURIComponent(supplierId));
              const json = await resp.json();
              if (!json.ok) { if (sdpList) sdpList.innerHTML = '<div class="sf-disc-sdp-empty">Erreur chargement.</div>'; return; }
              if (sdpLoading) sdpLoading.style.display = 'none';
              const docs = json.docs || [];
              if (docs.length === 0) {
                if (sdpList) sdpList.innerHTML = '<div class="sf-disc-sdp-empty">Aucun document disponible.</div>';
              } else {
                const groups = {};
                docs.forEach(d => {
                  const g = d.source_label || 'Autre';
                  if (!groups[g]) groups[g] = [];
                  groups[g].push(d);
                });
                let listHtml = '';
                Object.entries(groups).forEach(([grp, items]) => {
                  listHtml += '<div class="sf-disc-sdp-group-head">' + escHtml(grp) + '</div>';
                  items.forEach(d => {
                    listHtml += '<div class="sf-disc-sdp-item" data-doc-file-id="' + escHtml(String(d.doc_file_id)) + '" data-file-name="' + escHtml(d.file_name || '') + '">' +
                      '<span class="sf-disc-sdp-item-name">' + escHtml(d.file_name || '—') + '</span>' +
                      '<span class="sf-disc-sdp-item-date">' + (d.dated ? escHtml(String(d.dated).slice(0, 10)) : '') + '</span>' +
                      '</div>';
                  });
                });
                if (sdpList) {
                  sdpList.innerHTML = listHtml;
                  sdpList.querySelectorAll('.sf-disc-sdp-item').forEach(itemEl => {
                    itemEl.addEventListener('click', () => {
                      const docFileId = itemEl.dataset.docFileId;
                      const fileName  = itemEl.dataset.fileName;
                      const existing  = chipsEl ? chipsEl.querySelector('[data-doc-file-id="' + CSS.escape(docFileId) + '"]') : null;
                      if (!existing) _addDocChip(docFileId, fileName);
                      sdpEl.style.display = 'none';
                    });
                  });
                }
              }
            } catch (e2) {
              if (sdpList) sdpList.innerHTML = '<div class="sf-disc-sdp-empty">Erreur réseau.</div>';
            }
          }
        });
      }

      // Send flow
      if (sendBtn) {
        sendBtn.addEventListener('click', () => {
          if (!bodyEl || !bodyEl.value.trim()) { sfToast('Le corps du message est requis.'); return; }
          const uploading = chipsEl ? chipsEl.querySelector('[data-uploading="1"]') : null;
          if (uploading) { sfToast('Attendez la fin des téléversements.'); return; }
          const recipient = recipientEl ? recipientEl.textContent : '';
          if (confirmAddr) confirmAddr.textContent = recipient;
          if (confirmEl) confirmEl.style.display = 'flex';
          sendBtn.disabled = true;
        });
      }
      if (confirmNo) {
        confirmNo.addEventListener('click', () => {
          if (confirmEl) confirmEl.style.display = 'none';
          if (sendBtn) sendBtn.disabled = false;
        });
      }
      if (confirmYes) {
        confirmYes.addEventListener('click', async () => {
          if (confirmYes) confirmYes.disabled = true;
          if (confirmNo)  confirmNo.disabled  = true;
          if (sendBtn)    sendBtn.disabled     = true;
          await _doSendReply();
        });
      }

      function _addDocChip(docFileId, fileName) {
        if (!chipsEl) return;
        const chip = document.createElement('div');
        chip.className = 'sf-disc-attach-chip';
        chip.dataset.docFileId = String(docFileId);
        chip.innerHTML = '<span class="sf-disc-chip-name">' + escHtml(fileName) + '</span>' +
          '<button class="sf-disc-chip-remove" type="button" title="Retirer">✕</button>';
        chip.querySelector('.sf-disc-chip-remove').addEventListener('click', () => chip.remove());
        chipsEl.appendChild(chip);
      }

      async function _handleFileUploads(files) {
        const fileArray = Array.from(files);
        for (const file of fileArray) {
          const chip = document.createElement('div');
          chip.className = 'sf-disc-attach-chip sf-disc-attach-chip--uploading';
          chip.dataset.uploading = '1';
          chip.innerHTML = '<span class="sf-disc-chip-name">' + escHtml(file.name) + '</span> <span class="sf-disc-chip-spinner">…</span>';
          if (chipsEl) chipsEl.appendChild(chip);
          try {
            const fd = new FormData();
            fd.append('csrf', CSRF);
            fd.append('action', 'attach_upload');
            fd.append('file', file);
            const resp = await fetch('/api/sf-comm-thread.php', { method: 'POST', body: fd });
            const json = await resp.json();
            if (!json.ok) throw new Error(json.error || 'Upload échoué');
            chip.className = 'sf-disc-attach-chip';
            delete chip.dataset.uploading;
            chip.dataset.docFileId = String(json.doc_file_id);
            chip.innerHTML = '<span class="sf-disc-chip-name">' + escHtml(file.name) + '</span>' +
              '<button class="sf-disc-chip-remove" type="button" title="Retirer">✕</button>';
            chip.querySelector('.sf-disc-chip-remove').addEventListener('click', () => chip.remove());
          } catch (err) {
            chip.className = 'sf-disc-attach-chip sf-disc-attach-chip--error';
            delete chip.dataset.uploading;
            chip.innerHTML = '<span class="sf-disc-chip-name">' + escHtml(file.name) + ' — ' + escHtml(err.message) + '</span>' +
              '<button class="sf-disc-chip-remove" type="button" title="Retirer">✕</button>';
            chip.querySelector('.sf-disc-chip-remove').addEventListener('click', () => chip.remove());
          }
        }
      }

      async function _doSendReply() {
        const body = bodyEl ? bodyEl.value.trim() : '';
        const docFileIds = chipsEl
          ? Array.from(chipsEl.querySelectorAll('[data-doc-file-id]')).map(c => c.dataset.docFileId)
          : [];
        try {
          const params = new URLSearchParams({
            csrf:         CSRF,
            action:       'send_reply',
            thread_id:    String(threadIdForReply || ''),
            body:         body,
            doc_file_ids: JSON.stringify(docFileIds),
          });
          const resp = await fetch('/api/sf-comm-thread.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString(),
          });
          const json = await resp.json();
          if (json.ok) {
            if (bodyEl)    bodyEl.value    = '';
            if (chipsEl)   chipsEl.innerHTML = '';
            if (confirmEl) confirmEl.style.display = 'none';
            if (replyErrEl) replyErrEl.style.display = 'none';
            sfToast('Réponse envoyée.');
            const sec = document.getElementById('sf-disc-section');
            if (sec) await _loadDiscussionSection(supplierId, sec);
          } else {
            if (replyErrEl) { replyErrEl.textContent = json.error || 'Erreur inconnue.'; replyErrEl.style.display = 'block'; }
            if (confirmYes) confirmYes.disabled = false;
            if (confirmNo)  confirmNo.disabled  = false;
            if (sendBtn)    sendBtn.disabled     = false;
          }
        } catch (e) {
          if (replyErrEl) { replyErrEl.textContent = 'Erreur réseau : ' + e.message; replyErrEl.style.display = 'block'; }
          if (confirmYes) confirmYes.disabled = false;
          if (confirmNo)  confirmNo.disabled  = false;
          if (sendBtn)    sendBtn.disabled     = false;
        }
      }
    }
  }

  function renderEvalContent(data) {
    return [
      renderEvalStatus(data),
      renderAutofeed(data.autofeed),
      data.grid ? renderEvalForm(data) : '',
      renderEvalHistory(data.history),
      renderNcSection(data),
      renderCertsSection(data),
    ].join('');
  }

  /* ── A. Status bandeau ─────────────────────────────────────────────── */
  function renderEvalStatus(data) {
    const s        = data.supplier || {};
    const latest   = data.latest_evaluation || null;
    const today    = new Date().toISOString().slice(0, 10);

    // Criticality badge
    let critHtml = '';
    if (s.criticality === 'critique') {
      const src = (data.autofeed || {}).is_critical_derived ? 'dérivé' : 'manuel';
      critHtml = `<span class="sf-crit-badge critique">${escHtml(src)} : critique</span>`;
    } else if (s.criticality === 'non_critique') {
      const src = (data.autofeed || {}).is_critical_derived ? 'dérivé' : 'manuel';
      critHtml = `<span class="sf-crit-badge non-critique">${escHtml(src)} : non critique</span>`;
    } else {
      critHtml = `<span class="sf-eval-crit-undef">Criticité à définir</span>`;
    }

    // Override form (admin only)
    let overrideHtml = '';
    if (ROLE === 'admin') {
      overrideHtml = `
        <button class="sf-eval-add-toggle" id="sf-crit-override-toggle">→ Modifier criticité</button>
        <div class="sf-crit-override-form" id="sf-crit-override-form">
          <label><input type="radio" name="sf-crit-val" value="critique"> Critique</label>
          <label><input type="radio" name="sf-crit-val" value="non_critique"> Non critique</label>
          <button class="sf-btn-finalize" id="sf-crit-override-apply">Appliquer</button>
        </div>`;
    }

    // Latest result
    let latestHtml = '';
    let koHtml     = '';
    let flagHtml   = '';
    if (latest) {
      latestHtml = evalResultBadge(latest.result, latest.total_pct);
      if (latest.food_safety_ko) {
        koHtml = `<div class="sf-eval-ko-banner">Critère éliminatoire — NON AGRÉÉ</div>`;
      }
      if (!latest.valid_until || latest.valid_until < today) {
        flagHtml = `<div class="sf-eval-flag-banner">⚑ À évaluer / À réévaluer</div>`;
      }
    } else {
      latestHtml = `<em class="sf-eval-empty">Aucune évaluation enregistrée</em>`;
      flagHtml   = `<div class="sf-eval-flag-banner">⚑ À évaluer / À réévaluer</div>`;
    }

    return `<div class="sf-eval-subsection">
      <div class="sf-eval-subsection-title">Statut évaluation</div>
      <div class="sf-eval-status-row">
        ${critHtml}
        ${latestHtml}
      </div>
      ${overrideHtml}
      ${koHtml}
      ${flagHtml}
    </div>`;
  }

  /* ── B. Autofeed panel ─────────────────────────────────────────────── */
  function renderAutofeed(autofeed) {
    if (!autofeed) return '';
    const items = [];

    if (autofeed.delivery_count != null) {
      items.push(`<div class="sf-autofeed-item">
        <span class="sf-autofeed-item-label">Livraisons</span>
        <span class="sf-autofeed-item-value">${escHtml(String(autofeed.delivery_count))}</span>
      </div>`);
    }
    if (autofeed.last_delivery_date) {
      items.push(`<div class="sf-autofeed-item">
        <span class="sf-autofeed-item-label">Dernière livraison</span>
        <span class="sf-autofeed-item-value">${escHtml(fmtDate(autofeed.last_delivery_date))}</span>
      </div>`);
    }
    if (Array.isArray(autofeed.distinct_mi_categories) && autofeed.distinct_mi_categories.length > 0) {
      const chips = autofeed.distinct_mi_categories
        .map(c => `<span class="sf-mi-cat-chip">${escHtml(c)}</span>`)
        .join('');
      items.push(`<div class="sf-autofeed-item">
        <span class="sf-autofeed-item-label">Catégories MI</span>
        <span class="sf-autofeed-item-value">${chips}</span>
      </div>`);
    }
    if (autofeed.open_nc_count != null || autofeed.total_nc_count != null) {
      const open  = autofeed.open_nc_count  != null ? escHtml(String(autofeed.open_nc_count))  : '—';
      const total = autofeed.total_nc_count != null ? escHtml(String(autofeed.total_nc_count)) : '—';
      items.push(`<div class="sf-autofeed-item">
        <span class="sf-autofeed-item-label">Non-conformités</span>
        <span class="sf-autofeed-item-value">${open} ouvertes / ${total} total</span>
      </div>`);
    }

    if (items.length === 0) return '';

    return `<div class="sf-eval-subsection">
      <div class="sf-eval-subsection-title">Données automatiques</div>
      <div class="sf-autofeed-panel">
        <div class="sf-autofeed-grid">${items.join('')}</div>
      </div>
    </div>`;
  }

  /* ── C. Evaluation form ────────────────────────────────────────────── */
  function renderEvalForm(data) {
    const grid   = data.grid;
    const latest = data.latest_evaluation || null;
    const today  = new Date().toISOString().slice(0, 10);

    // Group criteria by pillar
    const pillars = {};
    (grid.criteria || []).forEach(c => {
      if (!pillars[c.pillar]) pillars[c.pillar] = [];
      pillars[c.pillar].push(c);
    });

    // Pre-fill map
    const prefill = {};
    if (latest && Array.isArray(latest.criteria_scores)) {
      latest.criteria_scores.forEach(cs => {
        prefill[cs.grid_criterion_id_fk] = cs;
      });
    }

    let criteriaHtml = '';
    Object.entries(pillars).sort((a, b) => a[0].localeCompare(b[0])).forEach(([pillar, crits]) => {
      criteriaHtml += `<div class="sf-eval-pillar-head">Pilier ${escHtml(pillar)}</div>`;
      crits.forEach(c => {
        const pf       = prefill[c.id] || {};
        const scoreVal = pf.score != null ? pf.score : '';
        const eviVal   = pf.evidence_note || '';

        let scoreOpts = `<option value="">— sans objet —</option>`;
        for (let i = 0; i <= (c.max_score || 4); i++) {
          const sel = String(scoreVal) === String(i) ? 'selected' : '';
          scoreOpts += `<option value="${i}" ${sel}>${i}</option>`;
        }

        const koTag = c.is_ko_flag
          ? `<span class="sf-eval-crit-ko-tag">éliminatoire</span>`
          : '';

        criteriaHtml += `<div class="sf-eval-crit-row">
          <span class="sf-eval-crit-label">${escHtml(c.label || c.criterion_label || '')}</span>
          ${koTag}
          <select class="sf-eval-score-select" name="scores[${c.id}]" data-pillar="${escHtml(pillar)}" data-max="${c.max_score || 4}">${scoreOpts}</select>
          <input class="sf-eval-evidence-input" type="text" name="evidence_note[${c.id}]" placeholder="Note de preuve…" value="${escHtml(eviVal)}">
        </div>`;
      });
    });

    const evalTypeOpts = Object.entries(EVAL_TYPE_LABELS).map(([v, l]) =>
      `<option value="${v}">${escHtml(l)}</option>`
    ).join('');

    const koCheckedAttr = (latest && latest.explicit_ko) ? 'checked' : '';
    const commentVal    = (latest && latest.comment)     ? escHtml(latest.comment) : '';

    return `<div class="sf-eval-subsection">
      <div class="sf-eval-subsection-title">Évaluation</div>
      <div class="sf-eval-form" id="sf-eval-form-wrap">
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">Type d'évaluation</label>
          <select id="sf-eval-type">${evalTypeOpts}</select>
        </div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">Date d'évaluation</label>
          <input type="date" id="sf-eval-date" value="${today}">
        </div>
        ${criteriaHtml}
        <div class="sf-eval-subtotal" id="sf-eval-subtotal">—</div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-check">
            <input type="checkbox" id="sf-eval-ko" ${koCheckedAttr}> Manquement grave constaté
          </label>
        </div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">Commentaire</label>
          <textarea id="sf-eval-comment" rows="3">${commentVal}</textarea>
        </div>
        <div class="sf-eval-form-footer">
          <button class="sf-btn-draft"    id="sf-eval-btn-draft">Enregistrer brouillon</button>
          <button class="sf-btn-finalize" id="sf-eval-btn-finalize">Finaliser</button>
        </div>
      </div>
    </div>`;
  }

  /* ── D. History ────────────────────────────────────────────────────── */
  function renderEvalHistory(history) {
    if (!Array.isArray(history) || history.length === 0) {
      return `<div class="sf-eval-subsection">
        <div class="sf-eval-subsection-title">Historique évaluations</div>
        <div class="sf-eval-empty">Aucune évaluation enregistrée</div>
      </div>`;
    }

    const rows = history.map(h => {
      const typeLabel  = EVAL_TYPE_LABELS[h.evaluation_type] || escHtml(h.evaluation_type || '—');
      const scorePct   = h.total_pct != null ? `${parseFloat(h.total_pct).toFixed(1)} %` : '—';
      const superseded = h.superseded_by_id != null
        ? `<span class="sf-eval-history-superseded">remplacée</span>`
        : '';
      return `<tr>
        <td class="sf-eval-history-td sf-eval-history-td-date">${escHtml(fmtDate(h.evaluated_at))}</td>
        <td class="sf-eval-history-td">${escHtml(typeLabel)}</td>
        <td class="sf-eval-history-td sf-eval-history-td-mono">${escHtml(scorePct)}</td>
        <td class="sf-eval-history-td">${evalResultBadge(h.result, null)} ${superseded}</td>
      </tr>`;
    }).join('');

    return `<div class="sf-eval-subsection">
      <div class="sf-eval-subsection-title">Historique évaluations</div>
      <table class="sf-eval-history-table">
        <thead><tr>
          <th class="sf-eval-history-th">Date</th>
          <th class="sf-eval-history-th">Type</th>
          <th class="sf-eval-history-th">Score</th>
          <th class="sf-eval-history-th">Résultat</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
  }

  /* ── E. Non-conformités ────────────────────────────────────────────── */
  function renderNcSection(data) {
    const ncs = data.ncs || [];
    const today = new Date().toISOString().slice(0, 10);

    let ncListHtml = '';
    if (ncs.length === 0) {
      ncListHtml = `<div class="sf-eval-empty">Aucune NC enregistrée</div>`;
    } else {
      ncListHtml = `<div class="sf-nc-list">` + ncs.map(nc => {
        const desc = nc.description && nc.description.length > 120
          ? nc.description.slice(0, 120) + '…'
          : (nc.description || '');
        const capaHtml = nc.capa_ref
          ? `<div class="sf-nc-capa">CAPA : ${escHtml(nc.capa_ref)}</div>`
          : '';
        return `<div class="sf-nc-row">
          <div class="sf-nc-row-head">
            <span class="sf-nc-detected-date">${escHtml(fmtDate(nc.detected_on))}</span>
            <span class="sf-nc-severity ${escHtml(nc.severity || '')}">${escHtml(nc.severity || '—')}</span>
            <span class="sf-nc-status">${escHtml(NC_STATUS_LABELS[nc.status] || nc.status || '—')}</span>
            <span class="sf-nc-type-label">${escHtml(NC_TYPE_LABELS[nc.nc_type] || nc.nc_type || '—')}</span>
          </div>
          <div class="sf-nc-desc">${escHtml(desc)}</div>
          ${capaHtml}
        </div>`;
      }).join('') + `</div>`;
    }

    const ncTypeOpts = Object.entries(NC_TYPE_LABELS).map(([v, l]) =>
      `<option value="${v}">${escHtml(l)}</option>`
    ).join('');

    const ncForm = `
      <button class="sf-eval-add-toggle" id="sf-nc-add-toggle">+ Ajouter une NC</button>
      <div class="sf-eval-collapsible-form" id="sf-nc-add-form">
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">Date détection</label>
          <input type="date" id="sf-nc-detected-on" value="${today}">
        </div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">Type</label>
          <select id="sf-nc-type">${ncTypeOpts}</select>
        </div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">Sévérité</label>
          <select id="sf-nc-severity">
            <option value="mineure">Mineure</option>
            <option value="majeure">Majeure</option>
            <option value="critique">Critique</option>
          </select>
        </div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">Description *</label>
          <textarea id="sf-nc-description" rows="3" required></textarea>
        </div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">Registre CAPA (optionnel)</label>
          <input type="text" id="sf-nc-capa-register">
        </div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">Réf. CAPA (optionnel)</label>
          <input type="text" id="sf-nc-capa-ref">
        </div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-check">
            <input type="checkbox" id="sf-nc-triggered-eval"> Déclencher évaluation
          </label>
        </div>
        <div class="sf-eval-form-footer">
          <button class="sf-btn-finalize" id="sf-nc-submit">Enregistrer NC</button>
        </div>
      </div>`;

    return `<div class="sf-eval-subsection">
      <div class="sf-eval-subsection-title">Non-conformités</div>
      ${ncListHtml}
      ${ncForm}
    </div>`;
  }

  /* ── F. Certificats / Documents ────────────────────────────────────── */
  function renderCertsSection(data) {
    const certs = data.certs || [];
    const today = new Date().toISOString().slice(0, 10);
    const in90  = new Date(Date.now() + 90 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);

    let certListHtml = '';
    if (certs.length === 0) {
      certListHtml = `<div class="sf-eval-empty">Aucun certificat enregistré</div>`;
    } else {
      certListHtml = `<div class="sf-cert-list">` + certs.map(cert => {
        const isExpired  = cert.expires_on && cert.expires_on < today;
        const isExpiring = cert.expires_on && !isExpired && cert.expires_on < in90;
        const dateClass  = isExpired ? 'sf-cert-expired' : isExpiring ? 'sf-cert-expiring' : '';
        const expiresHtml = cert.expires_on
          ? `<span class="sf-cert-date ${dateClass}">exp. ${escHtml(fmtDate(cert.expires_on))}</span>`
          : '';
        const issuedHtml = cert.issued_on
          ? `<span class="sf-cert-date">émis ${escHtml(fmtDate(cert.issued_on))}</span>`
          : '';
        const fileHtml = cert.file_name
          ? `<span class="sf-cert-filename">${escHtml(cert.file_name)}</span>`
          : '';
        return `<div class="sf-cert-row ${dateClass}">
          <span class="sf-cert-type">${escHtml(cert.doc_type || '—')}</span>
          <span class="sf-cert-ref">${escHtml(cert.reference_label || '—')}</span>
          ${issuedHtml}
          ${expiresHtml}
          ${fileHtml}
        </div>`;
      }).join('') + `</div>`;
    }

    const certForm = `
      <button class="sf-eval-add-toggle" id="sf-cert-link-toggle">+ Lier un document</button>
      <div class="sf-eval-collapsible-form" id="sf-cert-link-form">
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">Type document</label>
          <input type="text" id="sf-cert-doc-type">
        </div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">Référence / libellé</label>
          <input type="text" id="sf-cert-ref-label">
        </div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">Date d'émission</label>
          <input type="date" id="sf-cert-issued-on">
        </div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">Date d'expiration</label>
          <input type="date" id="sf-cert-expires-on">
        </div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">ID fichier (optionnel)</label>
          <input type="number" id="sf-cert-file-id">
        </div>
        <div class="sf-eval-form-row">
          <label class="sf-eval-form-label">ID évaluation liée (optionnel)</label>
          <input type="number" id="sf-cert-eval-id">
        </div>
        <div class="sf-eval-form-footer">
          <button class="sf-btn-finalize" id="sf-cert-submit">Lier document</button>
        </div>
      </div>`;

    return `<div class="sf-eval-subsection">
      <div class="sf-eval-subsection-title">Documents / Certificats</div>
      ${certListHtml}
      ${certForm}
    </div>`;
  }

  /* ── Event wiring ──────────────────────────────────────────────────── */
  function wireEvalEvents(sectionEl, data, supplierId) {
    // Criticality override toggle (admin only)
    const critToggle = sectionEl.querySelector('#sf-crit-override-toggle');
    const critForm   = sectionEl.querySelector('#sf-crit-override-form');
    if (critToggle && critForm) {
      critToggle.addEventListener('click', () => critForm.classList.toggle('open'));

      const applyBtn = sectionEl.querySelector('#sf-crit-override-apply');
      if (applyBtn) {
        applyBtn.addEventListener('click', async () => {
          const selected = sectionEl.querySelector('input[name="sf-crit-val"]:checked');
          if (!selected) return;
          applyBtn.disabled = true;
          applyBtn.textContent = '…';
          try {
            const res = await sfPost('/api/sf-criticality-override.php', {
              supplier_id_fk: String(supplierId),
              criticality:    selected.value,
            });
            if (res.ok) {
              await renderEvalSection(supplierId);
            } else {
              sfToast('Erreur : ' + (res.error || 'inconnue'));
              applyBtn.disabled = false;
              applyBtn.textContent = 'Appliquer';
            }
          } catch (e) {
            sfToast('Erreur réseau : ' + e.message);
            applyBtn.disabled = false;
            applyBtn.textContent = 'Appliquer';
          }
        });
      }
    }

    // NC add toggle
    const ncToggle = sectionEl.querySelector('#sf-nc-add-toggle');
    const ncForm   = sectionEl.querySelector('#sf-nc-add-form');
    if (ncToggle && ncForm) {
      ncToggle.addEventListener('click', () => ncForm.classList.toggle('open'));
    }

    // NC submit
    const ncSubmit = sectionEl.querySelector('#sf-nc-submit');
    if (ncSubmit) {
      ncSubmit.addEventListener('click', async () => {
        const desc = sectionEl.querySelector('#sf-nc-description');
        if (!desc || !desc.value.trim()) {
          sfToast('La description est obligatoire.');
          return;
        }
        ncSubmit.disabled = true;
        ncSubmit.textContent = '…';
        try {
          const res = await sfPost('/api/sf-nc-save.php', {
            supplier_id_fk:       String(supplierId),
            detected_on:          sectionEl.querySelector('#sf-nc-detected-on').value,
            nc_type:              sectionEl.querySelector('#sf-nc-type').value,
            severity:             sectionEl.querySelector('#sf-nc-severity').value,
            description:          desc.value.trim(),
            capa_register:        (sectionEl.querySelector('#sf-nc-capa-register').value || '').trim(),
            capa_ref:             (sectionEl.querySelector('#sf-nc-capa-ref').value || '').trim(),
            triggered_evaluation: sectionEl.querySelector('#sf-nc-triggered-eval').checked ? '1' : '0',
          });
          if (res.ok) {
            await renderEvalSection(supplierId);
          } else {
            sfToast('Erreur : ' + (res.error || 'inconnue'));
            ncSubmit.disabled = false;
            ncSubmit.textContent = 'Enregistrer NC';
          }
        } catch (e) {
          sfToast('Erreur réseau : ' + e.message);
          ncSubmit.disabled = false;
          ncSubmit.textContent = 'Enregistrer NC';
        }
      });
    }

    // Cert link toggle
    const certToggle = sectionEl.querySelector('#sf-cert-link-toggle');
    const certForm   = sectionEl.querySelector('#sf-cert-link-form');
    if (certToggle && certForm) {
      certToggle.addEventListener('click', () => certForm.classList.toggle('open'));
    }

    // Cert submit
    const certSubmit = sectionEl.querySelector('#sf-cert-submit');
    if (certSubmit) {
      certSubmit.addEventListener('click', async () => {
        certSubmit.disabled = true;
        certSubmit.textContent = '…';
        try {
          const fileIdEl = sectionEl.querySelector('#sf-cert-file-id');
          const evalIdEl = sectionEl.querySelector('#sf-cert-eval-id');
          const body = {
            supplier_id_fk:  String(supplierId),
            doc_type:        (sectionEl.querySelector('#sf-cert-doc-type').value || '').trim(),
            reference_label: (sectionEl.querySelector('#sf-cert-ref-label').value || '').trim(),
            issued_on:       sectionEl.querySelector('#sf-cert-issued-on').value,
            expires_on:      sectionEl.querySelector('#sf-cert-expires-on').value,
          };
          if (fileIdEl && fileIdEl.value) body.doc_file_id_fk = fileIdEl.value;
          if (evalIdEl && evalIdEl.value) body.linked_evaluation_id_fk = evalIdEl.value;

          const res = await sfPost('/api/sf-cert-link.php', body);
          if (res.ok) {
            await renderEvalSection(supplierId);
          } else {
            sfToast('Erreur : ' + (res.error || 'inconnue'));
            certSubmit.disabled = false;
            certSubmit.textContent = 'Lier document';
          }
        } catch (e) {
          sfToast('Erreur réseau : ' + e.message);
          certSubmit.disabled = false;
          certSubmit.textContent = 'Lier document';
        }
      });
    }

    // Eval form score selects → subtotals
    if (data.grid) {
      const grid    = data.grid;
      const selects = sectionEl.querySelectorAll('select[name^="scores["]');
      const updateSubtotals = () => calcSubtotals(sectionEl, grid);
      selects.forEach(sel => sel.addEventListener('change', updateSubtotals));
      updateSubtotals();
    }

    // Eval form submit buttons
    const btnDraft    = sectionEl.querySelector('#sf-eval-btn-draft');
    const btnFinalize = sectionEl.querySelector('#sf-eval-btn-finalize');

    function submitEval(status) {
      return async () => {
        if (btnDraft)    { btnDraft.disabled    = true; btnDraft.textContent    = '…'; }
        if (btnFinalize) { btnFinalize.disabled = true; btnFinalize.textContent = '…'; }

        const body = {
          supplier_id_fk:  String(supplierId),
          evaluation_type: (sectionEl.querySelector('#sf-eval-type')    || {}).value || 'annuel',
          evaluated_at:    (sectionEl.querySelector('#sf-eval-date')    || {}).value || new Date().toISOString().slice(0, 10),
          comment:         (sectionEl.querySelector('#sf-eval-comment') || {}).value || '',
          status:          status,
          explicit_ko:     (sectionEl.querySelector('#sf-eval-ko')      || {}).checked ? '1' : '0',
        };

        // Collect scores and evidence
        sectionEl.querySelectorAll('select[name^="scores["]').forEach(sel => {
          if (sel.name && sel.value !== '') body[sel.name] = sel.value;
        });
        sectionEl.querySelectorAll('input[name^="evidence_note["]').forEach(inp => {
          if (inp.name && inp.value.trim()) body[inp.name] = inp.value.trim();
        });

        try {
          const res = await sfPost('/api/sf-evaluation-save.php', body);
          if (res.ok) {
            await renderEvalSection(supplierId);
          } else {
            sfToast('Erreur : ' + (res.error || 'inconnue'));
            if (btnDraft)    { btnDraft.disabled    = false; btnDraft.textContent    = 'Enregistrer brouillon'; }
            if (btnFinalize) { btnFinalize.disabled = false; btnFinalize.textContent = 'Finaliser'; }
          }
        } catch (e) {
          sfToast('Erreur réseau : ' + e.message);
          if (btnDraft)    { btnDraft.disabled    = false; btnDraft.textContent    = 'Enregistrer brouillon'; }
          if (btnFinalize) { btnFinalize.disabled = false; btnFinalize.textContent = 'Finaliser'; }
        }
      };
    }

    if (btnDraft)    btnDraft.addEventListener('click',    submitEval('draft'));
    if (btnFinalize) btnFinalize.addEventListener('click', submitEval('final'));
  }

  function calcSubtotals(sectionEl, grid) {
    const pillars = {};
    (grid.criteria || []).forEach(c => {
      if (!pillars[c.pillar]) pillars[c.pillar] = { score: 0, max: 0 };
      const sel = sectionEl.querySelector(`select[name="scores[${c.id}]"]`);
      const val = sel ? parseInt(sel.value) : NaN;
      pillars[c.pillar].max += (c.max_score || 4);
      if (!isNaN(val)) pillars[c.pillar].score += val;
    });
    const subtotalEl = sectionEl.querySelector('.sf-eval-subtotal');
    if (subtotalEl) {
      subtotalEl.textContent = Object.entries(pillars)
        .sort((a, b) => a[0].localeCompare(b[0]))
        .map(([p, v]) => `${p}: ${v.score}/${v.max}`)
        .join(' — ');
    }
  }

})();
