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

  /* ── Init ────────────────────────────────────────────────────────────── */
  function init() {
    const form = document.getElementById('fermenting-form');
    if (!form) return;

    // Event-type switcher
    const evtSel = document.getElementById('event_type');
    if (evtSel) {
      updateSections(evtSel.value);
      evtSel.addEventListener('change', () => updateSections(evtSel.value));
    }

    // Recipe → recipe_id_fk
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
