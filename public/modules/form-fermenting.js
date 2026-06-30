/**
 * form-fermenting.js — Fermentation entry form JS.
 *
 * Responsibilities:
 *   1. Event-type switcher — show/hide form sections based on #event_type.
 *   2. Dynamic dry-hop rows (add / remove).
 *   3. Recipe → recipe_id_fk hidden field sync (mirrors form-brewing.js pattern).
 *   4. QC hints on gravity / pH / temperature inputs (soft, no-block).
 *   5. FormFramework.init() integration — draft persistence + diff-preview dialog.
 *
 * Data flow:
 *   window.FERMENTING_HOPS (injected by PHP) — array of { id, mi_id, name, unit }.
 *
 * Schema constraints honoured:
 *   - gravity stored in °Plato (not SG); user must enter °Plato.
 *   - dh_unit ENUM('kg','g') — form defaults to 'g'.
 *   - event_type ENUM('DryHop','Reads','Purge','ColdCrash').
 *
 * No framework dependency. Vanilla ES2020.
 */

'use strict';

(function () {

  /* ── Section visibility ──────────────────────────────────────────────── */
  const SECTIONS = {
    Reads:      ['section-readings'],
    DryHop:     ['section-readings', 'section-dryhop'],
    Purge:      ['section-readings', 'section-purge'],
    ColdCrash:  ['section-readings', 'section-coldcrash'],
  };

  function updateSections(eventType) {
    const all = ['section-readings', 'section-dryhop', 'section-purge', 'section-coldcrash'];
    const show = SECTIONS[eventType] || ['section-readings'];
    for (const id of all) {
      const el = document.getElementById(id);
      if (!el) continue;
      el.hidden = !show.includes(id);
    }

    // Update primary submit button label
    const btn = document.getElementById('ferm-submit-btn');
    if (btn) {
      const labels = {
        Reads:     'Enregistrer les mesures →',
        DryHop:    'Enregistrer le houblonnage →',
        Purge:     'Enregistrer la purge →',
        ColdCrash: 'Enregistrer le cold crash →',
      };
      btn.textContent = labels[eventType] || 'Enregistrer →';
    }

    // Dual-CTA: enable "Terminer fermentation" only for ColdCrash.
    // The terminate button is rendered disabled/aria-disabled by PHP; JS gates it.
    const terminateBtn = document.getElementById('ferm-btn-terminate');
    if (terminateBtn) {
      const isColdCrash = (eventType === 'ColdCrash');
      terminateBtn.disabled      = !isColdCrash;
      terminateBtn.setAttribute('aria-disabled', isColdCrash ? 'false' : 'true');
      terminateBtn.title = isColdCrash
        ? 'Enregistrer le cold crash et terminer la fermentation'
        : 'Disponible uniquement pour l\'évènement Cold Crash';
    }
  }

  /* ── Recipe → recipe_id_fk sync ─────────────────────────────────────── */
  function syncRecipeId() {
    const sel = document.getElementById('beer_select');
    const fk  = document.getElementById('recipe_id_fk');
    if (!sel || !fk) return;
    const opt = sel.options[sel.selectedIndex];
    fk.value = opt ? (opt.dataset.recipeId || '') : '';
  }

  /* ── Dry-hop rows ──────────────────────────────────────────────────────── */
  const HOPS = (window.FERMENTING_HOPS || []);
  let dhRowCount = 0;

  function buildHopOptions() {
    return HOPS.map(h =>
      `<option value="${escAttr(h.mi_id)}">${escHtml(h.name)}</option>`
    ).join('');
  }

  function escAttr(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function addDhRow() {
    const tbody = document.getElementById('dh-tbody');
    if (!tbody) return;

    const tr = document.createElement('tr');
    const idx = dhRowCount++;

    tr.innerHTML =
      `<td>
         <select name="dh_mi_id[${idx}]" class="ferm-dh__hop-select" aria-label="Houblon MI">
           <option value="">— choisir —</option>
           ${buildHopOptions()}
         </select>
       </td>
       <td>
         <input name="dh_qty[${idx}]" type="number" class="ferm-dh__qty-input"
                placeholder="0" step="1" min="0" aria-label="Quantité">
       </td>
       <td>
         <select name="dh_unit[${idx}]" class="ferm-dh__unit-select" aria-label="Unité">
           <option value="g" selected>g</option>
           <option value="kg">kg</option>
         </select>
       </td>
       <td>
         <input name="dh_lot[${idx}]" type="text" class="ferm-dh__lot-input"
                placeholder="lot" autocomplete="off" aria-label="N° lot">
       </td>
       <td>
         <button type="button" class="ferm-dh__remove-btn" aria-label="Supprimer">
           <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
             <path d="M1.5 1.5l9 9M10.5 1.5l-9 9" stroke="currentColor"
                   stroke-width="1.3" stroke-linecap="round"/>
           </svg>
         </button>
       </td>`;

    tr.querySelector('.ferm-dh__remove-btn').addEventListener('click', () => {
      tr.remove();
      updateDhBadge();
    });

    tbody.appendChild(tr);
    updateDhBadge();

    // Focus the new hop select
    tr.querySelector('.ferm-dh__hop-select').focus();
  }

  function updateDhBadge() {
    const tbody = document.getElementById('dh-tbody');
    const badge = document.getElementById('dh-count-badge');
    if (!tbody || !badge) return;
    const n = tbody.querySelectorAll('tr').length;
    if (n > 0) {
      badge.textContent = n + ' addition' + (n > 1 ? 's' : '');
      badge.classList.add('visible');
    } else {
      badge.textContent = '';
      badge.classList.remove('visible');
    }
  }

  /* ── QC hint helpers ─────────────────────────────────────────────────── */
  // Gravity in °Plato: normal fermentation range 0.5–22°P
  // pH: normal beer range 3.8–5.5
  // Temperature: fermentation 14–25°C, cold crash -2–10°C
  const THRESHOLDS = {
    gravity:     { label: 'Densité', unit: '°P', warn: [0.3, 25],  outlier: [-0.5, 30]  },
    ph:          { label: 'pH',       unit: '',   warn: [3.8, 5.5], outlier: [2.5, 7.5]  },
    temperature: { label: 'Temp',    unit: '°C', warn: [-2, 35],   outlier: [-5, 40]    },
  };

  /* ── Firewall: CIP override (YELLOW cadence gate) ───────────────────── */
  // When window.FERMENTING_FIREWALL.gate2_allow_override is true (YELLOW severity),
  // the PHP renders the override checkbox + reason field. The submit button starts
  // disabled (PHP sets disabled when gate2 is warn without committed override);
  // JS enables it only once the operator ticks the box AND fills the reason.
  function initCipOverride() {
    const fw = window.FERMENTING_FIREWALL || {};
    if (!fw.gate2_allow_override) return;

    const cb             = document.getElementById('ferm_cip_override_cb');
    const reasonRow      = document.getElementById('ferm-cip-override-reason-row');
    const reasonInput    = document.getElementById('ferm_cip_override_reason_text');
    const hiddenOverride = document.getElementById('ferm_fw_cip_override');
    const hiddenReason   = document.getElementById('ferm_fw_cip_override_reason');
    const submitBtn      = document.getElementById('ferm-submit-btn');

    if (!cb || !reasonRow || !reasonInput || !hiddenOverride || !hiddenReason) return;

    function syncReason() {
      const val = reasonInput.value.trim();
      hiddenReason.value = val;
      if (submitBtn) submitBtn.disabled = (val.length === 0);
    }

    function syncOverride() {
      const checked = cb.checked;
      reasonRow.hidden = !checked;
      hiddenOverride.value = checked ? '1' : '0';

      if (checked) {
        reasonInput.setAttribute('required', '');
        reasonInput.addEventListener('input', syncReason);
        syncReason();
      } else {
        reasonInput.removeAttribute('required');
        reasonInput.removeEventListener('input', syncReason);
        hiddenReason.value = '';
        if (submitBtn) submitBtn.disabled = true;
      }
    }

    cb.addEventListener('change', syncOverride);
    // On initial load: YELLOW gate means PHP already disabled submit; confirm here.
    if (submitBtn) submitBtn.disabled = true;
    syncOverride();
  }

  /* ── FormFramework initialisation ─────────────────────────────────────── */
  function initFramework() {
    if (typeof FormFramework === 'undefined') return;

    FormFramework.init({
      formId:         'fermenting-form',
      draftKey:       'fermenting-draft',
      thresholds:     THRESHOLDS,
      swapPairs:      [
        // Detect if gravity and pH values look swapped
        // gravity range ~0.5–22°P, pH range 3.5–6.5
        ['gravity', 'ph', [0.5, 22], [3.5, 6.5]],
      ],
      diffFields:     ['gravity', 'ph', 'temperature', 'final_comments'],
      diffLabels: {
        gravity:     'Densité (°P)',
        ph:          'pH',
        temperature: 'Température (°C)',
        final_comments: 'Commentaires',
      },
      warningPanelId: 'fermenting-warnings',
    });
  }

  /* ── Beer-selection card module (selector view, ff_phase='none') ───────── */
  //
  // Renders candidate cards into #ferm-cand-grid-normal / #ferm-cand-grid-override.
  // On card click: navigates via GET (?beer=…&batch=…&event_type=…) so the firewall
  // and form sections render server-side. Does NOT submit a POST form.
  //
  // Data: window.FERM_CANDIDATES { Reads, ColdCrash, DryHop, Purge }
  //       window.FERM_CANDIDATES_HP (hors-process, admin/manager only)
  //       window.FERM_CAN_OVERRIDE  (bool)

  var _selNormalGrid   = null;
  var _selOverrideGrid = null;
  var _selEventType    = 'Reads';
  var _selHpMode       = false;

  function _selEscHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function _selBuildCard(cand, isHp) {
    var btn  = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ferm-cand-card' + (isHp ? ' ferm-cand-card--hors-process' : '');

    // data-beer = raw bfw.beer string (NOT recipe_short_name).
    // The firewall/phase-detection in session-body-fermenting.php matches on this exact string.
    // COALESCE(NULLIF(bfw.beer,''), r.name) is beer_display (human label only).
    btn.dataset.beer    = cand.beer    || '';
    btn.dataset.batch   = cand.batch   || '';
    btn.dataset.recipeId = cand.recipe_id != null ? String(cand.recipe_id) : '';

    var srcType = cand.source_tank_type || 'CCT';
    btn.dataset.sourceType = srcType;

    var tankLabel, tankClass;
    if (srcType === 'BBT') {
      tankLabel = 'BBT ' + _selEscHtml(String(cand.source_bbt || '?'));
      tankClass = 'ferm-cand-card__label--bbt';
    } else {
      tankLabel = 'CCT ' + _selEscHtml(String(cand.source_cct || '?'));
      tankClass = '';
    }

    var beerDisp = _selEscHtml(cand.beer_display || cand.beer || '—');
    var batchDisp = _selEscHtml(String(cand.batch || '—'));
    var volHl = cand.sim_vol_hl != null ? Number(cand.sim_vol_hl).toFixed(1) + ' HL' : '';

    btn.innerHTML =
      '<div class="ferm-cand-card__label ' + tankClass + '">' + tankLabel + '</div>' +
      '<div class="ferm-cand-card__beer">'  + beerDisp  + '</div>' +
      '<div class="ferm-cand-card__batch">Brassin ' + batchDisp + '</div>' +
      (volHl ? '<div class="ferm-cand-card__vol">' + _selEscHtml(volHl) + '</div>' : '') +
      (isHp  ? '<div class="ferm-cand-card__badge-hp">HORS PROCESS</div>' : '');

    btn.addEventListener('click', function () {
      _selOnCardClick(cand, _selEventType);
    });
    return btn;
  }

  function _selOnCardClick(cand, eventType) {
    var beer  = cand.beer  || '';
    var batch = cand.batch || '';
    if (!beer || !batch) return;
    // Navigate — carries event_type so the correct section is pre-selected after load
    window.location = '/modules/form-fermenting.php' +
      '?beer='       + encodeURIComponent(beer) +
      '&batch='      + encodeURIComponent(batch) +
      '&event_type=' + encodeURIComponent(eventType);
  }

  function _selRenderNormal(eventType) {
    if (!_selNormalGrid) return;
    _selNormalGrid.innerHTML = '';
    var data = window.FERM_CANDIDATES || {};
    var list = data[eventType] || [];
    if (list.length === 0) {
      var empty = document.createElement('div');
      empty.className = 'ferm-empty-state';
      empty.innerHTML = '<strong>Aucun lot éligible</strong> pour cet évènement.<br>'
        + 'Les lots cold-crashés, déjà traités ou dont la CCT est vide en simulation ne s\'affichent pas.';
      _selNormalGrid.appendChild(empty);
      return;
    }
    list.forEach(function (cand) {
      _selNormalGrid.appendChild(_selBuildCard(cand, false));
    });
  }

  function _selRenderOverride() {
    if (!_selOverrideGrid) return;
    _selOverrideGrid.innerHTML = '';
    var list = window.FERM_CANDIDATES_HP || [];
    if (list.length === 0) {
      var empty = document.createElement('div');
      empty.className = 'ferm-empty-state';
      empty.innerHTML = '<strong>Aucun lot</strong> en CCT ou BBT actuellement.';
      _selOverrideGrid.appendChild(empty);
      return;
    }
    list.forEach(function (cand) {
      _selOverrideGrid.appendChild(_selBuildCard(cand, true));
    });
  }

  function initBeerSelector() {
    var selectorCard = document.getElementById('ferm-selector-card');
    if (!selectorCard) return; // Not in selector view (beer/batch already set)

    _selNormalGrid   = document.getElementById('ferm-cand-grid-normal');
    _selOverrideGrid = document.getElementById('ferm-cand-grid-override');

    // Event-type select drives which normal candidate set is shown
    var typeSel = document.getElementById('ferm_sel_event_type');
    if (typeSel) {
      _selEventType = typeSel.value;
      _selRenderNormal(_selEventType);
      typeSel.addEventListener('change', function () {
        _selEventType = this.value;
        _selRenderNormal(_selEventType);
      });
    }

    // Hors-process toggle (only present for admin/manager — PHP-gated)
    var hpCb = document.getElementById('ferm_hp_checkbox');
    var normalSection   = document.getElementById('ferm-normal-candidates');
    var overrideSection = document.getElementById('ferm-override-candidates');
    if (hpCb) {
      hpCb.addEventListener('change', function () {
        _selHpMode = this.checked;
        if (normalSection)   normalSection.hidden   = _selHpMode;
        if (overrideSection) overrideSection.hidden = !_selHpMode;
        if (_selHpMode && _selOverrideGrid) {
          _selRenderOverride();
        }
      });
    }
  }

  /* ── Init ────────────────────────────────────────────────────────────── */
  function init() {
    // ── Selector view (no beer/batch selected): initialise card module
    initBeerSelector();

    // ── Form view (beer/batch selected): wire up sections + framework
    const form = document.getElementById('fermenting-form');
    if (!form) return;

    // Event-type switcher
    const evtSel = document.getElementById('event_type');
    if (evtSel) {
      updateSections(evtSel.value);
      evtSel.addEventListener('change', () => updateSections(evtSel.value));
    }

    // Recipe → recipe_id_fk (not needed in form view since identity is fixed,
    // but kept for safety — beer_select hidden input has no data-recipe-id so
    // syncRecipeId is a no-op; recipe_id_fk is set from PHP directly)
    const beerSel = document.getElementById('beer_select');
    if (beerSel) {
      syncRecipeId();
      beerSel.addEventListener('change', syncRecipeId);
    }

    // Expose dry-hop add to PHP button onclick
    window._fermAddDhRow = addDhRow;

    // Firewall CIP override (YELLOW gate only)
    initCipOverride();

    // FormFramework
    initFramework();
  }

  // Wait for DOM + deferred scripts
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
