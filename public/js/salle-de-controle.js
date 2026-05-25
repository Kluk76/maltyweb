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
        if (isAdmin) {
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
        } else if (isManager) {
          toggleBtn = '<span class="fmt-tile-readonly-note">Modification : admin requis</span>';
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

        // Dropdown (admin only)
        if (isAdmin && candidates.length > 0) {
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
          // manager / opérateur: read only
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
    document.querySelectorAll('.fmt-tile-activate').forEach(btn => {
      btn.addEventListener('click', () => {
        const fid = btn.dataset.formatId;
        const rid = btn.dataset.recipeId;
        // Get bom_template_id from sibling select if present
        const tile = btn.closest('.fmt-tile');
        const bomSel = tile ? tile.querySelector('.fmt-bom-select') : null;
        const bomVal = bomSel ? bomSel.value : '';
        postForm({
          action: 'activate_format',
          recipe_id: rid,
          format_id: fid,
          bom_template_id: bomVal,
          csrf: window.SDC_CSRF || '',
        });
      });
    });

    // Deactivate buttons
    document.querySelectorAll('.fmt-tile-deactivate').forEach(btn => {
      btn.addEventListener('click', () => {
        const sku = btn.dataset.skuCode;
        if (!confirm('Désactiver le format «' + sku + '» ?\n'
          + 'Le SKU restera en base, non-actif.')) return;
        postForm({
          action: 'deactivate_format',
          recipe_id: btn.dataset.recipeId,
          format_id: btn.dataset.formatId,
          csrf: window.SDC_CSRF || '',
        });
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
        postForm({
          action: 'set_binding',
          recipe_id: rid,
          role: role,
          mi_id_fk: sel.value,
          csrf: window.SDC_CSRF || '',
        });
      });
    });
  }

  /* ── Expose public interface ─────────────────────────────────────────── */
  window.sdcFormats = { render };

})();
