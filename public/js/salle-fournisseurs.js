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
  const SUPPLIERS = window.SF_SUPPLIERS || [];
  const ROLE      = window.SF_ROLE || 'manager';
  const CSRF      = window.SF_CSRF || '';

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

    ficheEl.innerHTML = `<div class="sf-doc-paper sf-reveal">
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
    </div>`;
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

})();
