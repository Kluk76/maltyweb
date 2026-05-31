/**
 * form-brewing.js — Brewing entry form JS.
 *
 * Responsibilities:
 *   1. Dynamic ingredient rows (add / remove) — pure DOM manipulation.
 *   2. Recipe → recipe_id_fk hidden field sync (mirrors form-racking.php pattern).
 *   3. MI category chip updates when MI selection changes.
 *   4. FormFramework.init() integration for draft persistence + QC warnings.
 *
 * Data flow: window.BREWING_MI (injected by PHP) is the MI catalog for the
 * ingredient pickers. Format: array of { id, mi_id, name, cat, unit }.
 *
 * NOTE: This script handles the ingredient sub-table only.
 * The brewday-header fields (beer, batch, CCT, yeast) are plain <select>/<input>
 * wired directly as form fields — FormFramework handles their draft.
 *
 * No framework dependency. Vanilla ES2020.
 */

'use strict';

(function () {

  /* ── Category display map ─────────────────────────────────────────────── */
  const CAT_LABELS = {
    malt:        'MLT',
    hops_kettle: 'HOP',
    hops_dry:    'DRY',
    adjunct:     'ADJ',
    mineral:     'MIN',
    process:     'PRC',
  };

  const CAT_CSS_MAP = {
    malt:        'malt',
    hops_kettle: 'hops',
    hops_dry:    'hops',
    adjunct:     'adjunct',
    mineral:     'mineral',
    process:     'process',
  };

  /* ── Unit options per category (convenience defaults — operator can change) */
  const CAT_DEFAULT_UNIT = {
    malt:        'kg',
    hops_kettle: 'g',
    hops_dry:    'g',
    adjunct:     'kg',
    mineral:     'g',
    process:     'g',
  };

  /* ── Row counters ───────────────────────────────────────────────────────── */
  let rowCounter = 0;   // ingredient rows

  /* ── Build one ingredient table row ────────────────────────────────────── */
  function buildRow(idx) {
    const miOptions = (window.BREWING_MI || []).map(m => {
      const esc = escAttr;
      return `<option value="${esc(m.mi_id)}" data-cat="${esc(m.cat)}" data-unit="${esc(m.unit)}">${esc(m.mi_id)} — ${esc(m.name)}</option>`;
    }).join('');

    const tr = document.createElement('tr');
    tr.dataset.rowIdx = idx;
    tr.innerHTML = `
      <td class="brew-ing__col--cat">
        <span class="brew-ing__cat-chip brew-ing__cat-chip--unknown" id="cat-chip-${idx}" title="Catégorie">?</span>
      </td>
      <td class="brew-ing__col--mi">
        <select name="ing_mi_id[${idx}]" class="brew-ing__mi-select op-form__select"
                id="mi-sel-${idx}" onchange="window._brewingOnMiChange(${idx})">
          <option value="">— sélectionner MI —</option>
          ${miOptions}
        </select>
        <input type="hidden" name="ing_cat[${idx}]" id="ing-cat-${idx}" value="">
      </td>
      <td class="brew-ing__col--qty">
        <input type="text" inputmode="decimal" name="ing_qty[${idx}]"
               class="brew-ing__qty-input op-form__input" placeholder="0.0"
               id="ing-qty-${idx}" autocomplete="off">
      </td>
      <td class="brew-ing__col--unit">
        <select name="ing_unit[${idx}]" class="brew-ing__unit-select op-form__select"
                id="ing-unit-${idx}">
          <option value="kg">kg</option>
          <option value="g" selected>g</option>
          <option value="ml">ml</option>
        </select>
      </td>
      <td class="brew-ing__col--lot">
        <input type="text" name="ing_lot[${idx}]"
               class="brew-ing__lot-input op-form__input" placeholder="N° lot"
               autocomplete="off">
      </td>
      <td class="brew-ing__col--del">
        <button type="button" class="brew-ing__remove-btn" title="Supprimer"
                onclick="window._brewingRemoveRow(this)">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
            <path d="M2 2l8 8M10 2L2 10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
          </svg>
        </button>
      </td>
    `;
    return tr;
  }

  /* ── Cooling row helpers ────────────────────────────────────────────────── */

  /**
   * Build one cooling sub-brew row. idx is the 0-based position within the tbody;
   * PHP receives cool_final_volume[], cool_final_gravity[], cool_final_ph[] arrays
   * and uses the array position +1 as the brew number.
   */
  function buildCoolRow(idx) {
    const tr = document.createElement('tr');
    tr.dataset.coolIdx = idx;
    tr.innerHTML = `
      <td class="brew-cool__col--num brew-cool__num-cell">${idx + 1}</td>
      <td class="brew-cool__col--vol">
        <input type="number" inputmode="decimal"
               name="cool_final_volume[]"
               class="brew-cool__input op-form__input"
               placeholder="ex. 29.3" step="0.1" min="0" max="500"
               autocomplete="off"
               oninput="window._brewingUpdateCoolTotal()">
      </td>
      <td class="brew-cool__col--og">
        <input type="number" inputmode="decimal"
               name="cool_final_gravity[]"
               class="brew-cool__input op-form__input"
               placeholder="ex. 12.0" step="0.1" min="0" max="35"
               autocomplete="off">
      </td>
      <td class="brew-cool__col--ph">
        <input type="number" inputmode="decimal"
               name="cool_final_ph[]"
               class="brew-cool__input op-form__input"
               placeholder="ex. 4.87" step="0.01" min="0" max="14"
               autocomplete="off">
      </td>
      <td class="brew-cool__col--del">
        <button type="button" class="brew-ing__remove-btn" title="Supprimer"
                onclick="window._brewingRemoveCoolRow(this)">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
            <path d="M2 2l8 8M10 2L2 10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
          </svg>
        </button>
      </td>
    `;
    return tr;
  }

  /** Renumber visible brassin labels after add/remove. */
  function _renumberCoolRows() {
    const tbody = document.getElementById('cool-tbody');
    if (!tbody) return;
    const rows = tbody.querySelectorAll('tr');
    rows.forEach(function (tr, i) {
      const cell = tr.querySelector('.brew-cool__num-cell');
      if (cell) cell.textContent = i + 1;
    });
    // Update count badge
    const badge = document.getElementById('cool-count-badge');
    if (badge) {
      const n = rows.length;
      badge.textContent = n > 1 ? `${n} brassins` : '';
    }
    // Show/hide total row
    window._brewingUpdateCoolTotal();
  }

  window._brewingAddCoolRow = function () {
    const tbody = document.getElementById('cool-tbody');
    if (!tbody) return;
    tbody.appendChild(buildCoolRow(tbody.querySelectorAll('tr').length));
    _renumberCoolRows();
    // Focus volume input in the new row
    const rows = tbody.querySelectorAll('tr');
    const lastRow = rows[rows.length - 1];
    if (lastRow) {
      const vol = lastRow.querySelector('input[name="cool_final_volume[]"]');
      if (vol) vol.focus();
    }
  };

  window._brewingRemoveCoolRow = function (btn) {
    const tr = btn.closest('tr');
    // Prevent removing the last row (keep at least one)
    const tbody = document.getElementById('cool-tbody');
    if (!tbody || tbody.querySelectorAll('tr').length <= 1) return;
    if (tr) tr.remove();
    _renumberCoolRows();
  };

  /** Recompute and display the running SUM of cool_final_volume[]. */
  window._brewingUpdateCoolTotal = function () {
    const tbody = document.getElementById('cool-tbody');
    const display = document.getElementById('cool-total-display');
    const totalEl = document.getElementById('cool-total-vol');
    if (!tbody || !display || !totalEl) return;
    let sum = 0;
    const inputs = tbody.querySelectorAll('input[name="cool_final_volume[]"]');
    inputs.forEach(function (inp) {
      const v = parseFloat(inp.value);
      if (!isNaN(v)) sum += v;
    });
    if (inputs.length > 1) {
      totalEl.textContent = sum.toFixed(1);
      display.hidden = false;
    } else {
      display.hidden = true;
    }
  };

  /* ── Escape helpers ─────────────────────────────────────────────────────── */
  function escAttr(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  /* ── Add row ────────────────────────────────────────────────────────────── */
  window._brewingAddRow = function () {
    const tbody = document.getElementById('ing-tbody');
    if (!tbody) return;
    const idx = rowCounter++;
    tbody.appendChild(buildRow(idx));
    _updateCount();
    // Focus the MI select in the new row
    const sel = document.getElementById(`mi-sel-${idx}`);
    if (sel) sel.focus();
  };

  /* ── Remove row ─────────────────────────────────────────────────────────── */
  window._brewingRemoveRow = function (btn) {
    const tr = btn.closest('tr');
    if (tr) tr.remove();
    _updateCount();
  };

  /* ── MI change handler — update chip + default unit ─────────────────────── */
  window._brewingOnMiChange = function (idx) {
    const sel = document.getElementById(`mi-sel-${idx}`);
    if (!sel) return;
    const opt = sel.options[sel.selectedIndex];
    const cat  = opt.dataset.cat  || '';
    const unit = opt.dataset.unit || 'kg';

    // Update hidden cat field
    const catInput = document.getElementById(`ing-cat-${idx}`);
    if (catInput) catInput.value = cat;

    // Update chip
    const chip = document.getElementById(`cat-chip-${idx}`);
    if (chip) {
      // Remove all category CSS modifier classes
      chip.className = 'brew-ing__cat-chip';
      const cssKey = CAT_CSS_MAP[cat] || 'unknown';
      chip.classList.add(`brew-ing__cat-chip--${cssKey}`);
      chip.textContent = CAT_LABELS[cat] || '?';
      chip.title = cat || '—';
    }

    // Default the unit to match MI's canonical unit
    const unitSel = document.getElementById(`ing-unit-${idx}`);
    if (unitSel && unit) {
      // Find matching option
      for (const o of unitSel.options) {
        if (o.value === unit) {
          unitSel.value = unit;
          break;
        }
      }
    }
  };

  /* ── Ingredient count badge ─────────────────────────────────────────────── */
  function _updateCount() {
    const tbody = document.getElementById('ing-tbody');
    const badge = document.getElementById('ing-count-badge');
    if (!badge || !tbody) return;
    const n = tbody.querySelectorAll('tr').length;
    badge.textContent = n > 0 ? `${n} ingrédient${n > 1 ? 's' : ''}` : '';
  }

  /* ── Recipe → recipe_id_fk sync ────────────────────────────────────────── */
  function wireRecipeSelect() {
    const sel = document.getElementById('recipe_select');
    const fk  = document.getElementById('recipe_id_fk');
    if (!sel || !fk) return;
    sel.addEventListener('change', function () {
      const opt = this.options[this.selectedIndex];
      fk.value = opt.dataset.recipeId ?? '';
    });
  }

  /* ── CIP vessel number sync ─────────────────────────────────────────────── */
  // The CIP partial emits cip_vessel_0_number (CCT) and cip_vessel_1_number (YT)
  // as hidden fields with null at render time. Keep them in sync with the
  // operator's CCT and YT selections so cip_upsert stores the right target_number.
  function wireCipVesselNumbers() {
    const cctSel = document.getElementById('cct');
    const ytInput = document.getElementById('yt_number');
    const cctNumField = document.querySelector('[name="cip_vessel_0_number"]');
    const ytNumField  = document.querySelector('[name="cip_vessel_1_number"]');

    if (cctSel && cctNumField) {
      const syncCct = function () {
        cctNumField.value = cctSel.value || '';
      };
      cctSel.addEventListener('change', syncCct);
      syncCct(); // seed on page load (empty until operator picks)
    }

    if (ytInput && ytNumField) {
      const syncYt = function () {
        ytNumField.value = ytInput.value || '';
      };
      ytInput.addEventListener('input', syncYt);
      syncYt(); // seed on page load
    }
  }

  /* ── Init ───────────────────────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', function () {
    wireRecipeSelect();
    wireCipVesselNumbers();

    // Seed cooling rows: restore sticky data from a conflict re-render, or add one blank row.
    const sticky = window.BREWING_STICKY_COOL;
    if (sticky && sticky.volumes && sticky.volumes.length > 0) {
      // Pre-populate from sticky POST data (overwrite-conflict re-render).
      const count = Math.max(sticky.volumes.length, sticky.gravities.length, sticky.phs.length);
      for (let i = 0; i < count; i++) {
        window._brewingAddCoolRow();
      }
      const tbody = document.getElementById('cool-tbody');
      if (tbody) {
        const rows = tbody.querySelectorAll('tr');
        rows.forEach(function (tr, i) {
          const volInput  = tr.querySelector('input[name="cool_final_volume[]"]');
          const gravInput = tr.querySelector('input[name="cool_final_gravity[]"]');
          const phInput   = tr.querySelector('input[name="cool_final_ph[]"]');
          if (volInput  && sticky.volumes[i]   !== undefined) volInput.value  = sticky.volumes[i];
          if (gravInput && sticky.gravities[i] !== undefined) gravInput.value = sticky.gravities[i];
          if (phInput   && sticky.phs[i]       !== undefined) phInput.value   = sticky.phs[i];
        });
        window._brewingUpdateCoolTotal();
      }
    } else {
      // Normal fresh load — seed one blank cooling row.
      window._brewingAddCoolRow();
    }

    // FormFramework integration — draft persistence only (no numeric thresholds
    // for the header fields that are all enums/free text; QC is on brew measurements
    // which live in separate gravity/timings forms).
    if (typeof FormFramework !== 'undefined') {
      FormFramework.init({
        formId:   'brewing-form',
        draftKey: 'brewing-draft',
        thresholds: {},
        diffFields: ['beer_select', 'batch', 'event_date', 'cct', 'yeast_select'],
        diffLabels: {
          beer_select:  'Recette',
          batch:        'N° brassin',
          event_date:   'Date brassage',
          cct:          'CCT',
          yeast_select: 'Levure',
        },
      });
    }
  });

})();
