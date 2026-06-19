/**
 * salle-de-controle.js — Formats subtab logic
 *
 * Reads window.SDC_FORMATS_DATA (injected by PHP GET block).
 * Exposes window.sdcFormats.render(recipeId) called by the
 * inline switchSubtab() / selectRecipe() code.
 *
 * POST actions: activate_format, deactivate_format, set_binding.
 * All writes use traditional <form> submit for PRG — no fetch() —
 * so the CSRF token comes from window.SDC_CSRF injected by PHP.
 *
 * Run-type → human labels (no DB codes in operator-facing text).
 */

(function () {
  'use strict';

  /* ── Label maps ──────────────────────────────────────────────────────── */
  const RUN_LABELS = {
    bot:   'Run bouteille',
    can:   'Run canette 50cl',
    can33: 'Run canette 33cl',
    keg:   'Fût',
    cuv:   'Cuve de service',
  };

  const ROLE_LABELS = {
    label:      'Étiquette',
    can:        'Canette (imprimée)',
    sticker:    'Sticker',
    holder:     'Porte-4pack',
    outer_tray: 'Tray / plateau',
    scotch:     'Scotch',
  };

  /* ── Helpers ─────────────────────────────────────────────────────────── */
  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function csrfInput() {
    const v = (window.SDC_CSRF || '');
    return '<input type="hidden" name="csrf" value="' + escHtml(v) + '">';
  }

  function postForm(fields) {
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = '/modules/salle-de-controle.php';
    Object.entries(fields).forEach(([k, v]) => {
      const i = document.createElement('input');
      i.type  = 'hidden';
      i.name  = k;
      i.value = v;
      f.appendChild(i);
    });
    document.body.appendChild(f);
    f.submit();
  }

  function submitChangeRequest(payload) {
    return fetch('/modules/salle-de-controle.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action:  'submit_change_request',
        csrf:    window.SDC_CSRF || '',
        recipe_id:   payload.recipe_id,
        change_kind: payload.change_kind,
        summary:     payload.summary || '',
        lines: JSON.stringify(payload.lines || []),
      }).toString(),
    })
      .then(r => r.json())
      .then(data => {
        if (data.ok) {
          if (window.showToast) showToast('Demande envoyée — en attente d\'approbation administrateur.');
          setTimeout(() => location.reload(), 1200);
        } else {
          if (window.showToast) showToast('Erreur : ' + (data.error || 'Erreur inconnue'));
        }
      })
      .catch(err => {
        if (window.showToast) showToast('Erreur réseau : ' + err.message);
      });
  }

  /* ── Main render ─────────────────────────────────────────────────────── */
  function render(recipeId) {
    const container = document.getElementById('fmtPaneInner');
    if (!container) return;

    const data = window.SDC_FORMATS_DATA;
    if (!data) {
      container.innerHTML = '<div class="fmt-err-banner">Données Formats non disponibles.</div>';
      return;
    }

    const recData = data.recipe_data[recipeId];
    if (!recData) {
      // Check if it's a no-prefix recipe
      const noPrefix = (data.no_prefix_recipes || []).find(r => r.id === recipeId);
      if (noPrefix) {
        container.innerHTML = renderNoPrefixBanner(noPrefix.name);
      } else {
        container.innerHTML = '<div class="fmt-placeholder"><span>Recette non trouvée dans les données Formats.</span></div>';
      }
      return;
    }

    if (!recData.sku_prefix) {
      container.innerHTML = renderNoPrefixBanner(recData.name);
      return;
    }

    container.innerHTML = renderFormatsContent(recData, data);
    bindBindingDropdowns(recipeId, recData, data);
  }

  function renderNoPrefixBanner(name) {
    return '<div class="fmt-no-prefix-banner">'
      + '<div class="fmt-no-prefix-icon">⚠</div>'
      + '<div>'
      + '<div class="fmt-no-prefix-title">Préfixe SKU manquant</div>'
      + '<div class="fmt-no-prefix-msg">La recette <b>' + escHtml(name) + '</b> '
      + 'n\'a pas de préfixe SKU défini. Aucun format ne peut être activé '
      + 'tant que ce préfixe est absent — à définir dans la fiche recette.</div>'
      + '</div>'
      + '</div>';
  }

  function renderFormatsContent(recData, data) {
    const isAdmin = (window.SDC_ROLE === 'admin');
    const isManager = (window.SDC_ROLE === 'manager' || isAdmin);
    const gatedFormats = data.gated_formats || [];
    const skus = recData.skus || {};      // keyed by format_id (number keys as strings)
    const bindings = recData.bindings || {};

    // Group gated formats by run_type
    const grouped = {};
    gatedFormats.forEach(f => {
      const label = RUN_LABELS[f.run_type] || f.run_type;
      if (!grouped[label]) grouped[label] = [];
      grouped[label].push(f);
    });

    let html = '<div class="fmt-content">';

    // ── Format activation grid ──────────────────────────────────────────
    html += '<div class="fmt-section-head">'
      + '<span class="fmt-section-label">Formats activés</span>'
      + '<span class="fmt-section-sub">' + escHtml(recData.sku_prefix) + ' · '
      + escHtml(recData.name) + '</span>'
      + '</div>';

    html += '<div class="fmt-grid">';
    Object.entries(grouped).forEach(([runLabel, formats]) => {
      html += '<div class="fmt-run-group">'
        + '<div class="fmt-run-label">' + escHtml(runLabel) + '</div>'
        + '<div class="fmt-tiles">';
      formats.forEach(f => {
        const skuRow = skus[f.id] || skus[String(f.id)];
        const isActive = skuRow && skuRow.is_active;
        const skuCode  = isActive ? skuRow.sku_code : recData.expected_skus[f.id];
        const hlLabel  = f.hl_per_unit >= 0.1
          ? f.hl_per_unit.toFixed(3) + ' hl'
          : (f.hl_per_unit * 1000).toFixed(1) + ' L';

        // BOM template selector (admin only)
        const currentBom = isActive && skuRow.bom_template_id ? skuRow.bom_template_id : '';
        let bomSelect = '';
        if (isAdmin) {
          bomSelect = '<select class="fmt-bom-select" name="bom_template_id" title="BOM template">'
            + '<option value="">BOM auto</option>';
          // Only the bom for this format
          const auto = (data.bom_by_format_id || {})[f.id];
          if (auto) {
            bomSelect += '<option value="' + escHtml(auto) + '"'
              + (currentBom == auto ? ' selected' : '')
              + '>Template #' + escHtml(auto) + ' (auto)</option>';
          }
          bomSelect += '</select>';
        }

        // Toggle button
        let toggleBtn = '';
        if (isAdmin || isManager) {
          if (isActive) {
            toggleBtn = '<button class="fmt-tile-deactivate" '
              + 'data-recipe-id="' + escHtml(recData.id) + '" '
              + 'data-format-id="' + escHtml(f.id) + '" '
              + 'data-sku-code="' + escHtml(skuCode) + '" '
              + 'title="Désactiver ce format">Désactiver</button>';
          } else {
            toggleBtn = '<button class="fmt-tile-activate" '
              + 'data-recipe-id="' + escHtml(recData.id) + '" '
              + 'data-format-id="' + escHtml(f.id) + '">Activer</button>';
          }
        }

        html += '<div class="fmt-tile' + (isActive ? ' fmt-tile--active' : '') + '">'
          + '<div class="fmt-tile-name">' + escHtml(f.display_name) + '</div>'
          + '<div class="fmt-tile-code">' + escHtml(f.format_code) + '</div>'
          + '<div class="fmt-tile-meta">' + escHtml(hlLabel) + '</div>'
          + (isActive
            ? '<div class="fmt-tile-sku">' + escHtml(skuCode) + '</div>'
            : '<div class="fmt-tile-sku-preview">' + escHtml(skuCode || '?') + '</div>')
          + (isAdmin ? '<div class="fmt-bom-row">' + bomSelect + '</div>' : '')
          + '<div class="fmt-tile-actions">' + toggleBtn + '</div>'
          + '</div>';
      });
      html += '</div></div>'; // /fmt-tiles /fmt-run-group
    });
    html += '</div>'; // /fmt-grid

    // ── Bindings panel ──────────────────────────────────────────────────
    const slotDefs = data.slot_defs || [];
    const roleCandidates = recData.role_candidates || {};

    // Determine which roles are applicable given active formats
    const activeRunTypes = new Set();
    gatedFormats.forEach(f => {
      const skuRow = skus[f.id] || skus[String(f.id)];
      if (skuRow && skuRow.is_active) activeRunTypes.add(f.run_type);
    });

    const hasBottle = activeRunTypes.has('bot');
    const hasCan    = activeRunTypes.has('can') || activeRunTypes.has('can33');

    // has24Box: any active SKU whose format is one of the 24-box formats (B id1, C id7, BC id8)
    const BOX24_FORMAT_IDS = new Set([1, 7, 8]);
    const has24Box = gatedFormats.some(f => {
      const skuRow = skus[f.id] || skus[String(f.id)];
      return BOX24_FORMAT_IDS.has(f.id) && skuRow && skuRow.is_active;
    });

    // has33C: any active SKU with format_code '33C' (single can, format id13)
    const has33C = gatedFormats.some(f => {
      const skuRow = skus[f.id] || skus[String(f.id)];
      return f.format_code === '33C' && skuRow && skuRow.is_active;
    });

    // Scotch binding for A/B exclusivity logic (scotch=TRANSP → sticker required on box)
    const scotchBinding = bindings['scotch'];
    const scotchIsBranded = scotchBinding
      && scotchBinding.mi_code.startsWith('PKG_SCOTCH_')
      && scotchBinding.mi_code !== 'PKG_SCOTCH_TRANSP';

    // Filter relevant slots
    // scotch: relevant iff a 24-box format is active (B/C/BC only)
    // sticker: relevant iff has24Box OR has33C (box sticker config-B + on-can sticker)
    const relevantSlots = slotDefs.filter(sd => {
      const role = sd.role;
      if (role === 'label' || role === 'outer_tray' || role === 'holder') {
        return hasBottle;
      }
      if (role === 'can') return hasCan;
      if (role === 'scotch') return has24Box;
      if (role === 'sticker') return has24Box || has33C;
      return true;
    });

    if (relevantSlots.length > 0) {
      html += '<div class="fmt-section-head fmt-bindings-head">'
        + '<span class="fmt-section-label">Liaisons packaging</span>'
        + '<span class="fmt-section-sub">Ingrédients spécifiques à la bière · par rôle</span>'
        + '</div>';

      html += '<div class="fmt-bindings-grid" id="fmtBindingsGrid-' + recData.id + '">';
      relevantSlots.forEach(sd => {
        const bound     = bindings[sd.role];
        const candidates = roleCandidates[sd.role] || [];
        const hasBound  = !!bound;
        const hasGap    = !hasBound && candidates.length > 0;

        // A/B exclusivity: sticker gap suppressed for the box-sticker reason when scotch
      // resolves to a branded MI (config A — box sticker intentionally absent).
      // Still gap-flag sticker if has33C and unbound (on-can sticker is always needed).
      let effectiveGap = hasGap;
      if (sd.role === 'sticker' && !hasBound && has24Box && scotchIsBranded && !has33C) {
        effectiveGap = false; // config A: branded scotch → no box sticker required
      }

      html += '<div class="fmt-binding-row' + (effectiveGap ? ' fmt-binding-row--gap' : '') + '">'
          + '<div class="fmt-binding-label">'
          + (effectiveGap ? '<span class="fmt-gap-flag" title="Liaison manquante">!</span>' : '')
          + escHtml(ROLE_LABELS[sd.role] || sd.role)
          + (sd.role === 'scotch'
            ? '<span class="fmt-binding-hint">(A) scotch imprimé, ou (B) transparent + sticker</span>'
            : '')
          + '<span class="fmt-binding-scope">' + escHtml(sd.scope) + '</span>'
          + '</div>';

        if (hasBound) {
          html += '<div class="fmt-binding-value">'
            + '<span class="fmt-bound-name">' + escHtml(bound.mi_name) + '</span>'
            + '<span class="fmt-bound-code">' + escHtml(bound.mi_code) + '</span>'
            + '</div>';
        } else {
          html += '<div class="fmt-binding-value fmt-binding-value--empty">— non défini —</div>';
        }

        if ((isAdmin || isManager) && candidates.length > 0) {
          html += '<div class="fmt-binding-select-wrap">'
            + '<select class="fmt-binding-select" '
            + 'data-recipe-id="' + escHtml(recData.id) + '" '
            + 'data-role="' + escHtml(sd.role) + '" '
            + 'id="fmt-sel-' + escHtml(recData.id) + '-' + escHtml(sd.role) + '">'
            + '<option value="">— choisir —</option>';
          candidates.forEach(c => {
            const sel = (hasBound && bound.mi_id_fk === c.id) ? ' selected' : '';
            html += '<option value="' + escHtml(c.id) + '"' + sel + '>'
              + escHtml(c.name) + ' (' + escHtml(c.code) + ')</option>';
          });
          html += '</select>'
            + '<button class="fmt-binding-save" '
            + 'data-recipe-id="' + escHtml(recData.id) + '" '
            + 'data-role="' + escHtml(sd.role) + '">Enregistrer</button>'
            + '</div>';
        } else if (candidates.length === 0) {
          html += '<div class="fmt-binding-no-candidates">Aucun MI «'
            + escHtml(recData.sku_prefix) + '» trouvé pour ce rôle</div>';
        } else {
          // opérateur: read only
          html += '<div class="fmt-binding-readonly">Modification : admin requis</div>';
        }

        html += '</div>'; // /fmt-binding-row
      });
      html += '</div>'; // /fmt-bindings-grid
    }

    html += '</div>'; // /fmt-content
    return html;
  }

  /* ── Post-render event bindings ────────────────────────────────────────── */
  function bindBindingDropdowns(recipeId, recData, data) {
    // Activate buttons
    const isManager = (window.SDC_ROLE === 'manager');
    const isAdmin   = (window.SDC_ROLE === 'admin');
    document.querySelectorAll('.fmt-tile-activate').forEach(btn => {
      btn.addEventListener('click', () => {
        const fid = btn.dataset.formatId;
        const rid = btn.dataset.recipeId;
        const tile = btn.closest('.fmt-tile');
        const bomSel = tile ? tile.querySelector('.fmt-bom-select') : null;
        const bomVal = bomSel ? bomSel.value : '';
        if (isManager && !isAdmin) {
          submitChangeRequest({
            recipe_id:   rid,
            change_kind: 'format_activate',
            summary:     'Activation format #' + fid,
            lines: [{
              target_table: 'ref_packaging_formats',
              field:        'format_id',
              old_value:    '',
              new_value:    fid,
            }],
          });
        } else {
          postForm({
            action: 'activate_format',
            recipe_id: rid,
            format_id: fid,
            bom_template_id: bomVal,
            csrf: window.SDC_CSRF || '',
          });
        }
      });
    });

    // Deactivate buttons
    document.querySelectorAll('.fmt-tile-deactivate').forEach(btn => {
      btn.addEventListener('click', () => {
        const sku = btn.dataset.skuCode;
        const fid = btn.dataset.formatId;
        const rid = btn.dataset.recipeId;
        if (!confirm('Désactiver le format «' + sku + '» ?\n'
          + 'Le SKU restera en base, non-actif.')) return;
        if (isManager && !isAdmin) {
          submitChangeRequest({
            recipe_id:   rid,
            change_kind: 'format_deactivate',
            summary:     'Désactivation format ' + sku,
            lines: [{
              target_table: 'ref_packaging_formats',
              field:        'format_id',
              old_value:    '',
              new_value:    fid,
            }],
          });
        } else {
          postForm({
            action: 'deactivate_format',
            recipe_id: rid,
            format_id: fid,
            csrf: window.SDC_CSRF || '',
          });
        }
      });
    });

    // Binding save buttons
    document.querySelectorAll('.fmt-binding-save').forEach(btn => {
      btn.addEventListener('click', () => {
        const rid  = btn.dataset.recipeId;
        const role = btn.dataset.role;
        const sel  = document.getElementById('fmt-sel-' + rid + '-' + role);
        if (!sel || !sel.value) {
          if (window.showToast) showToast('Choisir un ingrédient avant d\'enregistrer');
          return;
        }
        const miId = sel.value;
        if (isManager && !isAdmin) {
          submitChangeRequest({
            recipe_id:   rid,
            change_kind: 'bom_binding',
            summary:     'Liaison packaging rôle ' + role,
            lines: [{
              target_table: 'ref_sku_bom',
              field:        role,
              mi_id_fk:     miId,
              old_value:    '',
              new_value:    miId,
            }],
          });
        } else {
          postForm({
            action: 'set_binding',
            recipe_id: rid,
            role: role,
            mi_id_fk: miId,
            csrf: window.SDC_CSRF || '',
          });
        }
      });
    });
  }

  /* ── Expose public interface ─────────────────────────────────────────── */
  window.sdcFormats = { render };

})();

/* ═══════════════════════════════════════════════════════════════════════════
   CO₂/O₂ CONFORMITÉ SECTION — sdcConf
   Reads: window.SDC_TANK_BEERS, window.SDC_TANK_SERIES (injected by PHP).
   Renders: beer selector list, per-beer CO₂ line chart + O₂ secondary panel,
            spec band when target is defined, summary strip, raw table.
   Chart technique: hand-rolled SVG (same as packaging.js / wort-kpis.js).
   No external chart library.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── Guard: abort if data globals are absent ──────────────────────────── */
  if (!window.SDC_TANK_BEERS || !window.SDC_TANK_SERIES) return;

  /* ── XSS helper ─────────────────────────────────────────────────────── */
  function esc(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* ── Number formatter ───────────────────────────────────────────────── */
  function fmt(n, dec) {
    if (n == null) return '—';
    dec = (dec == null) ? 2 : dec;
    return Number(n).toLocaleString('fr-CH', {
      minimumFractionDigits: dec,
      maximumFractionDigits: dec,
    });
  }

  /* ── SVG element factory ─────────────────────────────────────────────── */
  function svgEl(tag, attrs) {
    const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
    for (const k in (attrs || {})) el.setAttribute(k, attrs[k]);
    return el;
  }

  /* ── CSS token reader (same approach as packaging.js) ──────────────── */
  const _cs = getComputedStyle(document.documentElement);
  function tok(name) { return _cs.getPropertyValue(name).trim(); }
  const C = {
    hop:      tok('--hop')        || '#567020',
    cold:     tok('--cold')       || '#2f5575',
    ember:    tok('--ember')      || '#b34428',
    oak:      tok('--oak')        || '#8b5e2a',
    lab:      tok('--lab')        || '#2e6b58',
    hairline: tok('--hairline')   || '#c8b48a',
    ink_faint:tok('--ink-faint')  || '#c8b48a',
    ink_mute: tok('--ink-mute')   || '#8a7a60',
    ink_soft: tok('--ink-soft')   || '#5a4830',
    ink:      tok('--ink')        || '#2a1f0e',
    bg:       tok('--bg')         || '#f1e8d4',
    bg_elev:  tok('--bg-elev')    || '#ece0c6',
  };

  /* ── State ───────────────────────────────────────────────────────────── */
  let _selectedKey = null;

  /* ── Tooltip (reuse sdc-page tooltip or create a simple one) ─────────── */
  let _tip = document.getElementById('sdcConfTip');
  if (!_tip) {
    _tip = document.createElement('div');
    _tip.id = 'sdcConfTip';
    _tip.className = 'sdc-conf-tip';
    document.body.appendChild(_tip);
  }
  function tipShow(e, html) {
    _tip.innerHTML = html;
    _tip.classList.add('visible');
    tipMove(e);
  }
  function tipMove(e) {
    const x = e.clientX + 14, y = e.clientY - 10;
    _tip.style.left = Math.min(x, window.innerWidth - _tip.offsetWidth - 8) + 'px';
    _tip.style.top  = Math.max(8, y) + 'px';
  }
  function tipHide() { _tip.classList.remove('visible'); }

  /* ═══════════════════════════════════════════════════════════════════════
     BEER SELECTOR
     ═══════════════════════════════════════════════════════════════════════ */
  function buildSelector() {
    const wrap = document.getElementById('sdcConfSelector');
    if (!wrap) return;

    const beers = window.SDC_TANK_BEERS || [];
    if (beers.length === 0) {
      wrap.innerHTML = '<p class="sdc-conf-empty">Aucune recette avec lectures en-cuve.</p>';
      return;
    }

    let html = '<div class="sdc-conf-sel-list">';

    /* NEB group */
    const neb      = beers.filter(b => b.lane === 'neb');
    const contract = beers.filter(b => b.lane === 'contract');

    if (neb.length) {
      html += '<div class="sdc-conf-sel-group-label">Nébuleuse</div>';
      neb.forEach(b => { html += renderBeerCard(b); });
    }
    if (contract.length) {
      html += '<div class="sdc-conf-sel-group-label">Contrats</div>';
      contract.forEach(b => { html += renderBeerCard(b); });
    }

    html += '</div>';
    wrap.innerHTML = html;

    /* Wire click handlers */
    wrap.querySelectorAll('.sdc-conf-sel-card').forEach(card => {
      card.addEventListener('click', function () {
        selectBeer(this.dataset.beerKey);
      });
    });

    /* Auto-select first */
    if (beers.length > 0) selectBeer(beers[0].beer_key);
  }

  function renderBeerCard(b) {
    const specPart = b.co2_target != null
      ? '<span class="sdc-conf-sel-spec">' +
          esc(fmt(b.co2_target, 2)) + ' ± ' + esc(fmt(b.co2_tolerance, 2)) + ' g/L' +
        '</span>'
      : '<span class="sdc-conf-sel-nospec">pas de cible</span>';

    const conformPart = b.co2_target != null
      ? '<span class="sdc-conf-sel-chip ' +
          (b.n_readings > 0 ? 'sdc-conf-sel-chip--ok' : '') + '">' +
          esc(String(b.n_in_spec)) + '/' + esc(String(b.n_readings)) + ' conformes' +
        '</span>'
      : '';

    return '<div class="sdc-conf-sel-card" data-beer-key="' + esc(b.beer_key) + '">' +
      '<span class="sdc-conf-sel-lane sdc-conf-sel-lane--' + esc(b.lane) + '">' +
        (b.lane === 'neb' ? 'NEB' : 'CTR') +
      '</span>' +
      '<span class="sdc-conf-sel-label">' + esc(b.display_label) + '</span>' +
      '<span class="sdc-conf-sel-meta">' +
        specPart +
        (conformPart ? ' · ' + conformPart : '') +
      '</span>' +
      '</div>';
  }

  /* ═══════════════════════════════════════════════════════════════════════
     SELECT BEER — renders summary + charts + table
     ═══════════════════════════════════════════════════════════════════════ */
  function selectBeer(beerKey) {
    _selectedKey = beerKey;

    /* Update card active state */
    const wrap = document.getElementById('sdcConfSelector');
    if (wrap) {
      wrap.querySelectorAll('.sdc-conf-sel-card').forEach(card => {
        card.classList.toggle('active', card.dataset.beerKey === beerKey);
      });
    }

    const beers  = window.SDC_TANK_BEERS  || [];
    const beer   = beers.find(b => b.beer_key === beerKey);
    const series = (window.SDC_TANK_SERIES || {})[beerKey] || [];

    renderSummary(beer, series);
    renderNospecNotice(beer);
    renderCharts(beer, series);
    renderTable(series);
  }

  /* ── Summary strip ───────────────────────────────────────────────────── */
  function renderSummary(beer, series) {
    const el = document.getElementById('sdcConfSummary');
    if (!el) return;
    if (!beer) { el.innerHTML = ''; return; }

    const latestCo2 = beer.latest_co2_gl != null ? fmt(beer.latest_co2_gl, 2) + ' g/L' : '—';
    const latestO2  = beer.latest_o2_ppb != null ? fmt(beer.latest_o2_ppb, 1) + ' ppb'  : '—';
    const dateRange = beer.first_read_date
      ? esc(beer.first_read_date) + ' → ' + esc(beer.last_read_date)
      : '—';

    el.innerHTML =
      '<div class="sdc-conf-sum-kpis">' +
        kpi('Lectures', esc(String(beer.n_readings))) +
        (beer.co2_target != null
          ? kpi('Conformes', esc(String(beer.n_in_spec)) + '/' + esc(String(beer.n_readings)))
          : kpi('Conformes', '—')) +
        kpi('Dernier CO₂', esc(latestCo2)) +
        kpi('Dernier O₂',  esc(latestO2)) +
        kpi('Période', esc(dateRange)) +
      '</div>';
  }

  function kpi(label, val) {
    return '<div class="sdc-conf-kpi">' +
      '<span class="sdc-conf-kpi-val">' + val + '</span>' +
      '<span class="sdc-conf-kpi-lbl">' + esc(label) + '</span>' +
    '</div>';
  }

  /* ── No-spec notice ──────────────────────────────────────────────────── */
  function renderNospecNotice(beer) {
    const el = document.getElementById('sdcConfNospec');
    if (!el) return;
    el.style.display = (!beer || beer.co2_target == null) ? '' : 'none';
  }

  /* ═══════════════════════════════════════════════════════════════════════
     CHARTS
     CO₂ chart (primary, with optional spec band) + O₂ chart (secondary).
     SVG hand-rolled — same technique as packaging.js.
     ═══════════════════════════════════════════════════════════════════════ */
  function renderCharts(beer, series) {
    const wrap = document.getElementById('sdcConfCharts');
    if (!wrap) return;
    wrap.innerHTML = '';

    if (!series || series.length === 0) {
      wrap.innerHTML = '<p class="sdc-conf-empty">Aucune donnée pour cette sélection.</p>';
      return;
    }

    /* CO₂ chart */
    const co2Wrap = document.createElement('div');
    co2Wrap.className = 'sdc-conf-chart-block';
    const co2Head = document.createElement('div');
    co2Head.className = 'sdc-conf-chart-title';
    co2Head.innerHTML = 'CO₂ <span class="sdc-conf-chart-unit">g/L</span>';
    co2Wrap.appendChild(co2Head);
    const co2Svg = buildCo2Chart(beer, series);
    if (co2Svg) co2Wrap.appendChild(co2Svg);
    wrap.appendChild(co2Wrap);

    /* O₂ chart (only when at least one reading has o2_ppb) */
    const hasO2 = series.some(p => p.o2_ppb != null);
    if (hasO2) {
      const o2Wrap = document.createElement('div');
      o2Wrap.className = 'sdc-conf-chart-block sdc-conf-chart-block--o2';
      const o2Head = document.createElement('div');
      o2Head.className = 'sdc-conf-chart-title';
      o2Head.innerHTML = 'O₂ <span class="sdc-conf-chart-unit">ppb</span> <span class="sdc-conf-chart-note">axe indépendant · pas de cible</span>';
      o2Wrap.appendChild(o2Head);
      const o2Svg = buildO2Chart(series);
      if (o2Svg) o2Wrap.appendChild(o2Svg);
      wrap.appendChild(o2Wrap);
    }
  }

  /* ── CO₂ line chart ─────────────────────────────────────────────────── */
  function buildCo2Chart(beer, series) {
    const pts = series.filter(p => p.co2_gl != null);
    if (pts.length === 0) return null;

    const W = 780, H = 220;
    const padL = 48, padR = 20, padT = 20, padB = 40;
    const cW = W - padL - padR, cH = H - padT - padB;

    const vals   = pts.map(p => p.co2_gl);
    const target = beer && beer.co2_target != null ? beer.co2_target : null;
    const tol    = beer && beer.co2_tolerance != null ? beer.co2_tolerance : null;

    /* Y range: include spec band if set, plus 15% headroom */
    let lo = Math.min.apply(null, vals);
    let hi = Math.max.apply(null, vals);
    if (target != null && tol != null) {
      lo = Math.min(lo, target - tol * 1.5);
      hi = Math.max(hi, target + tol * 1.5);
    }
    const pad = (hi - lo) * 0.15 || 0.1;
    lo -= pad; hi += pad;
    if (lo === hi) { lo -= 0.1; hi += 0.1; }

    function yS(v) { return padT + cH * (1 - (v - lo) / (hi - lo)); }
    function xS(i) { return padL + (pts.length === 1 ? cW / 2 : i * cW / (pts.length - 1)); }

    const svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img',
      'aria-label': 'CO₂ en-cuve' });

    /* Grid lines (5 steps) */
    const steps = 5;
    for (let i = 0; i <= steps; i++) {
      const v  = lo + (hi - lo) * i / steps;
      const y  = yS(v);
      svg.appendChild(svgEl('line', {
        x1: padL, y1: y, x2: W - padR, y2: y,
        stroke: C.hairline, 'stroke-width': i === 0 ? 1 : 0.4, opacity: 0.6,
      }));
      const lbl = svgEl('text', {
        x: padL - 5, y: y + 4,
        'text-anchor': 'end', fill: C.ink_faint,
        'font-family': 'JetBrains Mono,monospace', 'font-size': 9,
      });
      lbl.textContent = v.toFixed(2);
      svg.appendChild(lbl);
    }

    /* Y-axis label */
    const yLbl = svgEl('text', {
      x: 10, y: padT + cH / 2, 'text-anchor': 'middle', fill: C.ink_faint,
      'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'letter-spacing': '0.1em',
      transform: 'rotate(-90,10,' + (padT + cH / 2) + ')',
    });
    yLbl.textContent = 'g/L';
    svg.appendChild(yLbl);

    /* Spec band (when target+tolerance available) */
    if (target != null && tol != null) {
      const bandHi = yS(target + tol);
      const bandLo = yS(target - tol);
      svg.appendChild(svgEl('rect', {
        x: padL, y: bandHi,
        width: cW, height: Math.abs(bandLo - bandHi),
        fill: C.hop, opacity: 0.10, rx: 2,
      }));
      /* Target line */
      const ty = yS(target);
      svg.appendChild(svgEl('line', {
        x1: padL, y1: ty, x2: W - padR, y2: ty,
        stroke: C.hop, 'stroke-width': 1.2,
        'stroke-dasharray': '5,4', opacity: 0.7,
      }));
      /* Target label */
      const tLbl = svgEl('text', {
        x: W - padR + 3, y: ty + 4,
        fill: C.hop, 'font-family': 'JetBrains Mono,monospace', 'font-size': 8,
      });
      tLbl.textContent = 'cible';
      svg.appendChild(tLbl);

      /* +tol / −tol dashed band borders */
      [target + tol, target - tol].forEach(function (v) {
        const y = yS(v);
        svg.appendChild(svgEl('line', {
          x1: padL, y1: y, x2: W - padR, y2: y,
          stroke: C.hop, 'stroke-width': 0.7,
          'stroke-dasharray': '3,5', opacity: 0.45,
        }));
      });
    }

    /* X-axis date labels (show first, last, and a few evenly spaced) */
    const maxLabels = Math.min(pts.length, 8);
    const step = pts.length > 1 ? Math.floor((pts.length - 1) / (maxLabels - 1)) : 1;
    const labelIdxs = new Set([0, pts.length - 1]);
    for (let i = 0; i < pts.length; i += step) labelIdxs.add(i);

    labelIdxs.forEach(function (i) {
      const x = xS(i);
      const lbl = svgEl('text', {
        x: x, y: H - padB + 14,
        'text-anchor': 'middle', fill: C.ink_faint,
        'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'letter-spacing': '0.04em',
      });
      lbl.textContent = pts[i].read_date ? pts[i].read_date.slice(5) : '';
      svg.appendChild(lbl);
    });

    /* Line path */
    let d = '';
    pts.forEach(function (p, i) {
      const x = xS(i), y = yS(p.co2_gl);
      d += (i === 0 ? 'M' : 'L') + x.toFixed(1) + ',' + y.toFixed(1) + ' ';
    });
    svg.appendChild(svgEl('path', {
      d: d.trim(), fill: 'none',
      stroke: C.cold, 'stroke-width': 1.8, 'stroke-linejoin': 'round',
      'stroke-linecap': 'round',
    }));

    /* Data points — colored by in_spec */
    pts.forEach(function (p, i) {
      const x = xS(i), y = yS(p.co2_gl);
      let dotColor, dotR;
      if (p.in_spec === true)  { dotColor = C.hop;   dotR = 3.5; }
      else if (p.in_spec === false) { dotColor = C.ember; dotR = 4; }
      else                     { dotColor = C.cold;  dotR = 3; }

      const circle = svgEl('circle', {
        cx: x, cy: y, r: dotR,
        fill: dotColor, stroke: C.bg, 'stroke-width': 1.2,
      });

      const batchLabel = p.batch || '—';
      const specLabel  = p.in_spec === true ? '✓ conforme'
                       : p.in_spec === false ? '✗ hors spec'
                       : 'pas de cible';

      circle.addEventListener('mouseenter', function (e) {
        tipShow(e,
          '<strong>' + esc(p.read_date) + '</strong>' +
          '<br>CO₂ : <strong>' + esc(fmt(p.co2_gl, 2)) + ' g/L</strong>' +
          '<br>Lot : ' + esc(batchLabel) +
          '<br><span style="color:' + dotColor + '">' + esc(specLabel) + '</span>'
        );
      });
      circle.addEventListener('mousemove', tipMove);
      circle.addEventListener('mouseleave', tipHide);
      svg.appendChild(circle);
    });

    return svg;
  }

  /* ── O₂ line chart (no spec — plain trend) ──────────────────────────── */
  function buildO2Chart(series) {
    const pts = series.filter(p => p.o2_ppb != null);
    if (pts.length === 0) return null;

    const W = 780, H = 160;
    const padL = 54, padR = 20, padT = 16, padB = 36;
    const cW = W - padL - padR, cH = H - padT - padB;

    const vals = pts.map(p => p.o2_ppb);
    let lo = Math.min.apply(null, vals), hi = Math.max.apply(null, vals);
    const pad = (hi - lo) * 0.2 || 10;
    lo -= pad; hi += pad;
    if (lo < 0) lo = 0;
    if (lo === hi) { lo = 0; hi += 10; }

    function yS(v) { return padT + cH * (1 - (v - lo) / (hi - lo)); }
    function xS(i) { return padL + (pts.length === 1 ? cW / 2 : i * cW / (pts.length - 1)); }

    const svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img',
      'aria-label': 'O₂ en-cuve ppb' });

    /* Grid */
    const steps = 4;
    for (let i = 0; i <= steps; i++) {
      const v = lo + (hi - lo) * i / steps;
      const y = yS(v);
      svg.appendChild(svgEl('line', {
        x1: padL, y1: y, x2: W - padR, y2: y,
        stroke: C.hairline, 'stroke-width': 0.4, opacity: 0.5,
      }));
      const lbl = svgEl('text', {
        x: padL - 5, y: y + 4,
        'text-anchor': 'end', fill: C.ink_faint,
        'font-family': 'JetBrains Mono,monospace', 'font-size': 9,
      });
      lbl.textContent = Math.round(v);
      svg.appendChild(lbl);
    }

    /* Y-axis label */
    const yLbl = svgEl('text', {
      x: 10, y: padT + cH / 2, 'text-anchor': 'middle', fill: C.ink_faint,
      'font-family': 'JetBrains Mono,monospace', 'font-size': 8, 'letter-spacing': '0.1em',
      transform: 'rotate(-90,10,' + (padT + cH / 2) + ')',
    });
    yLbl.textContent = 'ppb';
    svg.appendChild(yLbl);

    /* X-axis labels */
    const maxLabels = Math.min(pts.length, 8);
    const step = pts.length > 1 ? Math.floor((pts.length - 1) / (maxLabels - 1)) : 1;
    const labelIdxs = new Set([0, pts.length - 1]);
    for (let i = 0; i < pts.length; i += step) labelIdxs.add(i);
    labelIdxs.forEach(function (i) {
      const lbl = svgEl('text', {
        x: xS(i), y: H - padB + 14,
        'text-anchor': 'middle', fill: C.ink_faint,
        'font-family': 'JetBrains Mono,monospace', 'font-size': 8,
      });
      lbl.textContent = pts[i].read_date ? pts[i].read_date.slice(5) : '';
      svg.appendChild(lbl);
    });

    /* Line path */
    let d = '';
    pts.forEach(function (p, i) {
      const x = xS(i), y = yS(p.o2_ppb);
      d += (i === 0 ? 'M' : 'L') + x.toFixed(1) + ',' + y.toFixed(1) + ' ';
    });
    svg.appendChild(svgEl('path', {
      d: d.trim(), fill: 'none',
      stroke: C.oak, 'stroke-width': 1.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round',
    }));

    /* Points */
    pts.forEach(function (p, i) {
      const x = xS(i), y = yS(p.o2_ppb);
      const circle = svgEl('circle', {
        cx: x, cy: y, r: 3,
        fill: C.oak, stroke: C.bg, 'stroke-width': 1.2,
      });
      circle.addEventListener('mouseenter', function (e) {
        tipShow(e,
          '<strong>' + esc(p.read_date) + '</strong>' +
          '<br>O₂ : <strong>' + esc(fmt(p.o2_ppb, 1)) + ' ppb</strong>' +
          '<br>Lot : ' + esc(p.batch || '—')
        );
      });
      circle.addEventListener('mousemove', tipMove);
      circle.addEventListener('mouseleave', tipHide);
      svg.appendChild(circle);
    });

    return svg;
  }

  /* ═══════════════════════════════════════════════════════════════════════
     RAW READINGS TABLE
     ═══════════════════════════════════════════════════════════════════════ */
  function renderTable(series) {
    const wrap = document.getElementById('sdcConfTableWrap');
    if (!wrap) return;
    if (!series || series.length === 0) { wrap.innerHTML = ''; return; }

    /* Show most recent 25 rows; ASC is already chronological, so we reverse for display */
    const display = series.slice().reverse().slice(0, 25);

    let html =
      '<details class="sdc-conf-table-details">' +
      '<summary class="sdc-conf-table-summary">Lectures brutes (' + esc(String(series.length)) + ' au total)</summary>' +
      '<div class="sdc-conf-table-scroll">' +
      '<table class="sdc-conf-table" aria-label="Lectures en-cuve">' +
      '<thead><tr>' +
        '<th>Date</th><th>Lot</th><th>CO₂ g/L</th><th>O₂ ppb</th><th>Conformité</th>' +
      '</tr></thead><tbody>';

    display.forEach(function (p) {
      const specClass = p.in_spec === true  ? 'sdc-conf-td--ok'
                      : p.in_spec === false ? 'sdc-conf-td--err'
                      : '';
      const specLabel = p.in_spec === true  ? '✓ conforme'
                      : p.in_spec === false ? '✗ hors spec'
                      : '—';
      html +=
        '<tr>' +
          '<td class="sdc-conf-td-date">' + esc(p.read_date) + '</td>' +
          '<td class="sdc-conf-td-batch">' + esc(p.batch || '—') + '</td>' +
          '<td class="sdc-conf-td-mono">' + (p.co2_gl != null ? esc(fmt(p.co2_gl, 2)) : '—') + '</td>' +
          '<td class="sdc-conf-td-mono">' + (p.o2_ppb != null ? esc(fmt(p.o2_ppb, 1)) : '—') + '</td>' +
          '<td class="sdc-conf-td-spec ' + esc(specClass) + '">' + esc(specLabel) + '</td>' +
        '</tr>';
    });

    html += '</tbody></table></div></details>';
    wrap.innerHTML = html;
  }

  /* ═══════════════════════════════════════════════════════════════════════
     INIT — runs on section activate (lazy init via switchSection hook)
     ═══════════════════════════════════════════════════════════════════════ */
  let _initialized = false;

  function init() {
    if (_initialized) return;
    _initialized = true;
    buildSelector();
  }

  /* Hook into switchSection: the section becomes .active before switchSection returns.
     We detect by wrapping the global function if sdcConf is enabled. */
  (function hookSwitchSection() {
    const orig = window.switchSection;
    if (typeof orig !== 'function') return;
    window.switchSection = function (sec) {
      orig(sec);
      if (sec === 'conformite') init();
    };
  })();

  /* Also init if page loaded directly on this section (SDC_INITIAL = 'conformite').
     This script loads at end-of-body so DOM is already ready — call directly. */
  if (typeof window.SDC_INITIAL !== 'undefined' && window.SDC_INITIAL === 'conformite') {
    init();
  }

  /* Expose for debugging */
  window.sdcConf = { init, selectBeer };

})();
