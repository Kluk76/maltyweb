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
    DryHop:     ['section-dryhop'],   // no OG/pH readings for dry-hop
    Purge:      ['section-purge'],    // pressure (optional) + comment only — no readings card
    ColdCrash:  ['section-readings', 'section-coldcrash'],
  };

  function updateSections(eventType) {
    // section-coldcrash visibility is driven by the cold-crash checkbox, not the dropdown.
    const all = ['section-readings', 'section-dryhop', 'section-purge'];
    const show = SECTIONS[eventType] || ['section-readings'];
    for (const id of all) {
      const el = document.getElementById(id);
      if (!el) continue;
      el.hidden = !show.includes(id);
    }

    // Update primary submit button label (cold-crash case is handled by updateColdCrash).
    const btn = document.getElementById('ferm-submit-btn');
    if (btn) {
      const labels = {
        Reads:  'Enregistrer les mesures →',
        DryHop: 'Enregistrer le houblonnage →',
        Purge:  'Enregistrer la purge →',
      };
      // Only overwrite label if not already set by updateColdCrash (checkbox ticked).
      const ccCb = document.getElementById('ferm_cold_crash_flag');
      if (!ccCb || !ccCb.checked) {
        btn.textContent = labels[eventType] || 'Enregistrer →';
      }
    }
  }

  // Update section-coldcrash visibility and submit label based on the checkbox state.
  function updateColdCrash(checked) {
    const ccSection = document.getElementById('section-coldcrash');
    if (ccSection) ccSection.hidden = !checked;

    const btn = document.getElementById('ferm-submit-btn');
    if (!btn) return;
    if (checked) {
      btn.textContent = 'Enregistrer le cold crash →';
    } else {
      // Re-derive label from the current dropdown value.
      const evtSel = document.getElementById('event_type');
      const et = evtSel ? evtSel.value : 'Reads';
      const labels = {
        Reads:  'Enregistrer les mesures →',
        DryHop: 'Enregistrer le houblonnage →',
        Purge:  'Enregistrer la purge →',
      };
      btn.textContent = labels[et] || 'Enregistrer →';
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
         <select name="dh_mi_id[${idx}]" class="ferm-dh__hop-select" aria-label="Ingrédient MI">
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
           <option value="ml">ml</option>
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
    var beer     = cand.beer  || '';
    var batch    = cand.batch || '';
    var recipeId = cand.recipe_id != null ? String(cand.recipe_id) : '';
    if (!beer || !batch) return;
    // Navigate — carries recipe_id (canonical identity) + event_type (pre-selects section)
    window.location = '/modules/form-fermenting.php' +
      '?beer='       + encodeURIComponent(beer) +
      '&batch='      + encodeURIComponent(batch) +
      '&event_type=' + encodeURIComponent(eventType) +
      '&recipe_id='  + encodeURIComponent(recipeId);
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

  /* ── Edit-mode DryHop prefill ────────────────────────────────────────── */
  // Called from init() when window.FERM_EDIT_DH_LINES is a non-empty array.
  // For each persisted line: adds a picker row then sets its select/inputs to the
  // stored values so the operator sees the original dry-hop composition.
  function prefillDhLines(lines) {
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      addDhRow();  // appends row, increments dhRowCount; row index = i

      const tbody = document.getElementById('dh-tbody');
      if (!tbody) continue;
      const rows = tbody.querySelectorAll('tr');
      const tr = rows[rows.length - 1];  // the row just added
      if (!tr) continue;

      const hopSel  = tr.querySelector('select[name^="dh_mi_id"]');
      const qtyIn   = tr.querySelector('input[name^="dh_qty"]');
      const unitSel = tr.querySelector('select[name^="dh_unit"]');
      const lotIn   = tr.querySelector('input[name^="dh_lot"]');

      // mi_id option values are MI code strings (e.g. 'HOPS_MOSAIC') — same as dh_raw_name.
      if (hopSel && line.mi_id) {
        for (let j = 0; j < hopSel.options.length; j++) {
          if (hopSel.options[j].value === line.mi_id) {
            hopSel.selectedIndex = j;
            break;
          }
        }
      }
      if (qtyIn  && line.qty  !== '')  qtyIn.value  = line.qty;
      if (unitSel && line.unit !== '') {
        for (let j = 0; j < unitSel.options.length; j++) {
          if (unitSel.options[j].value === line.unit) {
            unitSel.selectedIndex = j;
            break;
          }
        }
      }
      if (lotIn  && line.lot  !== '')  lotIn.value  = line.lot;
    }
  }

  /* ── Init ────────────────────────────────────────────────────────────── */
  function init() {
    // ── Selector view (no beer/batch selected): initialise card module
    initBeerSelector();

    // ── Form view (beer/batch selected): wire up sections + framework
    const form = document.getElementById('fermenting-form');
    if (!form) return;

    // Event-type switcher.
    // In edit mode the <select id="event_type"> is absent (replaced by a hidden field);
    // derive the active type from the hidden field instead so updateSections fires correctly.
    const evtSel    = document.getElementById('event_type');
    const evtHidden = form.querySelector('input[type="hidden"][name="event_type"]');
    const initEvtType = evtSel
      ? evtSel.value
      : (evtHidden ? evtHidden.value : 'Reads');

    updateSections(initEvtType);
    if (evtSel) {
      evtSel.addEventListener('change', () => updateSections(evtSel.value));
    }

    // Cold-crash checkbox — reveal section-coldcrash + relabel submit button.
    const ccCb = document.getElementById('ferm_cold_crash_flag');
    if (ccCb) {
      // Initial state (edit mode: checkbox may already be checked).
      updateColdCrash(ccCb.checked);
      ccCb.addEventListener('change', () => updateColdCrash(ccCb.checked));
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

    // Edit-mode DryHop line repopulation — must run AFTER updateSections shows
    // section-dryhop and AFTER window._fermAddDhRow is exposed.
    if (Array.isArray(window.FERM_EDIT_DH_LINES) && window.FERM_EDIT_DH_LINES.length > 0) {
      prefillDhLines(window.FERM_EDIT_DH_LINES);
    }

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
