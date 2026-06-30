/**
 * packaging-form.js — JS for /modules/form-packaging.php
 *
 * Responsibilities:
 *   1. Hydrate tank-candidate grid from window.PF_CANDIDATES (server-injected).
 *   2. Wire card selection → populate hidden fields (neb_beer, neb_batch,
 *      source_tank_type, source_tank_id, etc.).
 *   3. Multi-format parallel rows (decision 8):
 *      - One row per format line (main + N parallels).
 *      - prod_total on main; qte_unites on parallels (ADD, not subtract).
 *      - Loss fields + QA analysis per format row.
 *   4. Show/hide keg section when any format is keg/cuv.
 *   5. Show/hide white-label name field.
 *   6. Show/hide client dropdown for contract beer or white-label.
 *   7. Enable submit only once a tank is selected AND ≥1 format row exists.
 *   8. Init FormFramework for soft-validation + draft persistence.
 *   9. Wire "Choix Hors Process" override checkbox (manager/admin only):
 *      - When checked: rebuild the tank grid from PF_CANDIDATES_OVERRIDE
 *        (all lots in CCT/BBT, no date gate) instead of PF_CANDIDATES.
 *      - Update hors_process hidden field (1 when checked, 0 otherwise).
 *      - Show override banner with prominent warning.
 *      - Server enforces the role — this JS is defense-in-depth, not the gate.
 *
 * No framework. Vanilla ES2020.
 * Reads: window.PF_CANDIDATES, window.PF_CANDIDATES_OVERRIDE, window.PF_CAN_OVERRIDE,
 *        window.PF_CLIENTS, window.RUN_TYPE_LABELS,
 *        window.FORMAT_SUFFIXES, window.MIN_DAYS_AFTER_RACKING
 */

'use strict';

function escHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', function () {

  // ── References ────────────────────────────────────────────────────────────
  const form             = document.getElementById('packaging-form');
  const submitBtn        = document.getElementById('pf-submit');
  const tankCards        = document.querySelectorAll('.pf-tank-card');
  const selectedPanel    = document.getElementById('pf-selected-tank');
  const selectedSummary  = document.getElementById('pf-selected-summary');
  const deselectBtn      = document.getElementById('pf-deselect');

  const fldTankType      = document.getElementById('source_tank_type');
  const fldTankId        = document.getElementById('source_tank_id');
  const fldTankNum       = document.getElementById('source_tank_num');
  const fldNebBeer       = document.getElementById('neb_beer');
  const fldNebBatch      = document.getElementById('neb_batch');
  const fldContractBeer  = document.getElementById('contract_beer');
  const fldContractBatch = document.getElementById('contract_batch');
  const fldRecipeId      = document.getElementById('recipe_id_fk');

  const formatsContainer = document.getElementById('pf-formats-container');
  const addFormatBtn     = document.getElementById('pf-add-format');

  const kegSection         = document.getElementById('pf-keg-section');
  const wlSelect           = document.getElementById('is_white_label');
  const wlNameField        = document.getElementById('pf-wl-name-field');
  const clientSection      = document.getElementById('pf-client-section');

  // Override (Choix Hors Process) — manager/admin only
  const overrideCheckbox   = document.getElementById('pf-override-checkbox');
  const overrideReasonRow  = document.getElementById('pf-override-reason-row');
  const horsProcessFld     = document.getElementById('hors_process');
  const tankGrid           = document.querySelector('.pf-tank-grid');

  // ── State ────────────────────────────────────────────────────────────────
  let selectedCard = null;     // currently-selected tank card element
  let isContractBeer = false;  // whether the selected lot has a contract beer
  let formatRows = [];         // array of row-index values rendered

  // ── Tank card selection ───────────────────────────────────────────────────

  function selectTank(card) {
    if (selectedCard) selectedCard.classList.remove('pf-tank-card--selected');
    selectedCard = card;
    card.classList.add('pf-tank-card--selected');

    const tankType    = card.dataset.tankType   || '';
    const tankNum     = card.dataset.tankNum    || '';
    const tankFkId    = card.dataset.tankFkId   || '';
    const nebBeer     = card.dataset.nebBeer    || '';
    const nebBatch    = card.dataset.nebBatch   || '';
    const cBeer       = card.dataset.contractBeer  || '';
    const cBatch      = card.dataset.contractBatch || '';
    const recipeId    = card.dataset.recipeId   || '';

    fldTankType.value    = tankType;
    fldTankId.value      = tankFkId;
    fldTankNum.value     = tankNum;
    fldNebBeer.value     = nebBeer;
    fldNebBatch.value    = nebBatch;
    fldContractBeer.value= cBeer;
    fldContractBatch.value = cBatch;
    fldRecipeId.value    = recipeId;

    const beerDisplay  = nebBeer || cBeer || '?';
    const batchDisplay = nebBatch || cBatch || '?';

    selectedPanel.hidden = false;
    selectedSummary.textContent =
      tankType + ' ' + tankNum + ' — ' + beerDisplay + ' · Brassin ' + batchDisplay;

    // Track contract-beer state for client-section visibility
    isContractBeer = (cBeer !== '' && nebBeer === '');
    updateClientSection();

    tryEnableSubmit();
    saveDraft({ tankType, tankNum, tankFkId, nebBeer, nebBatch, cBeer, cBatch, recipeId });
  }

  function deselectTank() {
    if (selectedCard) {
      selectedCard.classList.remove('pf-tank-card--selected');
      selectedCard = null;
    }
    fldTankType.value     = '';
    fldTankId.value       = '';
    fldTankNum.value      = '';
    fldNebBeer.value      = '';
    fldNebBatch.value     = '';
    fldContractBeer.value = '';
    fldContractBatch.value= '';
    fldRecipeId.value     = '';
    selectedPanel.hidden  = true;
    selectedSummary.textContent = '';
    isContractBeer = false;
    updateClientSection();
    submitBtn.disabled = true;
  }

  tankCards.forEach(function (card) {
    card.addEventListener('click', function () {
      if (card.disabled) return;
      selectTank(card);
    });
    card.addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ' ') && !card.disabled) {
        e.preventDefault();
        selectTank(card);
      }
    });
  });

  if (deselectBtn) deselectBtn.addEventListener('click', deselectTank);

  // ── Multi-format rows (decision 8) ───────────────────────────────────────
  //
  // Each row is identified by a sequential index (0, 1, 2...).
  // Index 0 is always the 'main' row. Indexes 1+ are 'parallel'.
  // PHP reads formats[N][...] arrays.

  const LOSS_FIELDS = [
    { name: 'loss_4pack_btl_units',    label: 'Pertes 4-pack bouteille',  unit: 'unités' },
    { name: 'loss_4pack_can_units',    label: 'Pertes 4-pack canette',    unit: 'unités' },
    { name: 'loss_wrap_btl_units',     label: 'Pertes wraparound btl',    unit: 'unités' },
    { name: 'loss_wrap_can_units',     label: 'Pertes wraparound can',    unit: 'unités' },
    { name: 'loss_label_btl_units',    label: 'Pertes étiquette btl',     unit: 'unités' },
    { name: 'loss_keg_collar_units',   label: 'Pertes collier fût',       unit: 'unités' },
    { name: 'loss_crown_cork_units',   label: 'Pertes capsule couronne',  unit: 'unités' },
    { name: 'loss_can_lid_units',      label: 'Pertes couvercle canette', unit: 'unités' },
    { name: 'loss_keg_save_units',     label: 'Pertes rinçage fût',       unit: 'unités' },
    { name: 'loss_container_btl_units',label: 'Pertes contenant btl',     unit: 'unités' },
    { name: 'loss_container_can_units',label: 'Pertes contenant can',     unit: 'unités' },
    { name: 'loss_liquid_other_units', label: 'Pertes liquide autres',    unit: 'L / unités' },
  ];

  const QA_FIELDS = [
    { name: 'qa_analyses_units', label: 'Unités analyses QA',   unit: 'unités' },
    { name: 'qa_library_units',  label: 'Unités bibliothèque QA',unit: 'unités' },
  ];

  function buildFormatRow(idx, isMain) {
    const origin    = isMain ? 'main' : 'parallel';
    const prefix    = 'formats[' + idx + ']';
    const idPfx     = 'fmt' + idx;

    const suffixOptions = Object.entries(window.FORMAT_SUFFIXES || {}).map(([v, l]) =>
      '<option value="' + escHtml(v) + '">' + escHtml(l) + '</option>'
    ).join('');

    const runTypeOptions = Object.entries(window.RUN_TYPE_LABELS || {}).map(([v, l]) =>
      '<option value="' + escHtml(v) + '">' + escHtml(l) + '</option>'
    ).join('');

    // Build loss fields HTML
    const lossHtml = LOSS_FIELDS.map(function (lf) {
      const fid = idPfx + '_' + lf.name;
      return '<div class="op-form__field pf-loss-field">' +
        '<label class="op-form__label pf-loss-label" for="' + fid + '">' + escHtml(lf.label) +
          ' <span class="op-form__unit">' + escHtml(lf.unit) + '</span></label>' +
        '<input id="' + fid + '" name="' + prefix + '[' + lf.name + ']"' +
          ' type="text" inputmode="numeric" class="op-form__input pf-loss-input"' +
          ' placeholder="0">' +
        '</div>';
    }).join('');

    const qaHtml = QA_FIELDS.map(function (qf) {
      const fid = idPfx + '_' + qf.name;
      return '<div class="op-form__field">' +
        '<label class="op-form__label" for="' + fid + '">' + escHtml(qf.label) +
          ' <span class="op-form__unit">' + escHtml(qf.unit) + '</span></label>' +
        '<input id="' + fid + '" name="' + prefix + '[' + qf.name + ']"' +
          ' type="text" inputmode="numeric" class="op-form__input" placeholder="0">' +
        '</div>';
    }).join('');

    const qtiesHtml = isMain
      ? '<div class="op-form__field">' +
          '<label class="op-form__label" for="' + idPfx + '_prod_total_units">' +
            'Unités produites <span class="op-form__unit">(total main)</span></label>' +
          '<input id="' + idPfx + '_prod_total_units" name="' + prefix + '[prod_total_units]"' +
            ' type="text" inputmode="numeric" class="op-form__input" placeholder="ex. 2736">' +
        '</div>'
      : '<div class="op-form__field">' +
          '<label class="op-form__label" for="' + idPfx + '_qte_unites">' +
            'Unités format parallèle <span class="op-form__unit">(additif)</span></label>' +
          '<input id="' + idPfx + '_qte_unites" name="' + prefix + '[qte_unites]"' +
            ' type="text" inputmode="numeric" class="op-form__input" placeholder="ex. 288">' +
        '</div>';

    const removeBtn = isMain
      ? ''
      : '<button type="button" class="pf-remove-format op-form__btn op-form__btn--danger-sm"' +
          ' data-idx="' + idx + '" title="Supprimer ce format">✕</button>';

    return '<div class="pf-format-row" id="pf-fmt-' + idx + '" data-idx="' + idx + '">' +
      '<div class="pf-format-row__header">' +
        '<span class="pf-format-row__badge pf-format-row__badge--' + origin + '">' +
          (isMain ? 'PRINCIPAL' : 'PARALLÈLE') + '</span>' +
        removeBtn +
      '</div>' +
      '<input type="hidden" name="' + prefix + '[row_origin]" value="' + origin + '">' +
      '<div class="op-form__grid--3 op-form__grid pf-format-top-grid">' +
        '<div class="op-form__field">' +
          '<label class="op-form__label" for="' + idPfx + '_run_type">Format conditionné</label>' +
          '<select id="' + idPfx + '_run_type" name="' + prefix + '[run_type]"' +
            ' class="op-form__select pf-run-type-select" required>' +
            '<option value="">— sélectionner —</option>' + runTypeOptions +
          '</select>' +
        '</div>' +
        '<div class="op-form__field">' +
          '<label class="op-form__label" for="' + idPfx + '_format_suffix">Suffixe SKU</label>' +
          '<select id="' + idPfx + '_format_suffix" name="' + prefix + '[format_suffix]"' +
            ' class="op-form__select">' +
            suffixOptions +
          '</select>' +
        '</div>' +
        qtiesHtml +
        '<div class="op-form__field">' +
          '<label class="op-form__label" for="' + idPfx + '_vendable_hl">Volume vendable <span class="op-form__unit">HL</span></label>' +
          '<input id="' + idPfx + '_vendable_hl" name="' + prefix + '[vendable_hl]"' +
            ' type="text" inputmode="decimal" class="op-form__input" placeholder="ex. 27.8">' +
        '</div>' +
        '<div class="op-form__field">' +
          '<label class="op-form__label" for="' + idPfx + '_unsaleable_units">Unités invendables <span class="op-form__unit">(qualité)</span></label>' +
          '<input id="' + idPfx + '_unsaleable_units" name="' + prefix + '[unsaleable_units]"' +
            ' type="text" inputmode="numeric" class="op-form__input" placeholder="ex. 12">' +
        '</div>' +
      '</div>' +
      '<details class="pf-losses-details">' +
        '<summary class="pf-losses-summary">Pertes par type <span class="pf-losses-summary__hint">(développer)</span></summary>' +
        '<div class="op-form__grid pf-losses-grid">' + lossHtml + '</div>' +
        '<div class="op-form__grid--3 op-form__grid pf-qa-grid">' + qaHtml + '</div>' +
      '</details>' +
    '</div>';
  }

  function addFormatRow(isMain) {
    const idx = formatRows.length;
    formatRows.push(idx);
    formatsContainer.insertAdjacentHTML('beforeend', buildFormatRow(idx, isMain));

    // Wire run_type→keg section visibility + remove btn
    const rowEl = document.getElementById('pf-fmt-' + idx);
    if (rowEl) {
      const sel = rowEl.querySelector('.pf-run-type-select');
      if (sel) sel.addEventListener('change', updateKegSection);

      const removeBtn = rowEl.querySelector('.pf-remove-format');
      if (removeBtn) {
        removeBtn.addEventListener('click', function () {
          removeFormatRow(idx);
        });
      }
    }
    tryEnableSubmit();
  }

  function removeFormatRow(idx) {
    const el = document.getElementById('pf-fmt-' + idx);
    if (el) el.remove();
    formatRows = formatRows.filter(function (i) { return i !== idx; });
    updateKegSection();
    tryEnableSubmit();
  }

  function updateKegSection() {
    const selects = formatsContainer ? formatsContainer.querySelectorAll('.pf-run-type-select') : [];
    const hasKegOrCuv = Array.from(selects).some(function (s) {
      return s.value === 'keg' || s.value === 'cuv';
    });
    if (kegSection) kegSection.hidden = !hasKegOrCuv;
  }

  if (addFormatBtn) {
    addFormatBtn.addEventListener('click', function () {
      addFormatRow(false);  // 'parallel'
    });
  }

  // Seed the main format row on load
  addFormatRow(true);

  // ── White label visibility ────────────────────────────────────────────────
  function updateWlField() {
    if (!wlSelect || !wlNameField) return;
    wlNameField.hidden = (wlSelect.value !== '1');
    updateClientSection();
  }
  if (wlSelect) {
    wlSelect.addEventListener('change', updateWlField);
    updateWlField();
  }

  // ── Client section visibility (decision 7) ────────────────────────────────
  // Show for contract beers or white-label runs
  function updateClientSection() {
    if (!clientSection) return;
    const isWl = wlSelect && wlSelect.value === '1';
    clientSection.hidden = !(isContractBeer || isWl);
  }

  // ── Enable submit ─────────────────────────────────────────────────────────
  function tryEnableSubmit() {
    const hasTank    = (fldTankType.value !== '');
    const hasFormat  = formatRows.length > 0;
    submitBtn.disabled = !(hasTank && hasFormat);
  }

  // ── Draft persistence helpers ─────────────────────────────────────────────
  function saveDraft(obj) {
    try {
      const draftRaw = localStorage.getItem('packaging-draft') || '{}';
      const draft = JSON.parse(draftRaw);
      Object.assign(draft, obj);
      localStorage.setItem('packaging-draft', JSON.stringify(draft));
    } catch (_) {}
  }

  // ── Restore from draft ────────────────────────────────────────────────────
  try {
    const draftRaw = localStorage.getItem('packaging-draft');
    if (draftRaw) {
      const draft = JSON.parse(draftRaw);
      if (draft.tankType && draft.tankFkId) {
        const card = document.querySelector(
          '.pf-tank-card[data-tank-type="' + escHtml(draft.tankType) + '"]' +
          '[data-tank-fk-id="' + escHtml(draft.tankFkId) + '"]'
        );
        if (card && !card.disabled) selectTank(card);
      }
    }
  } catch (_) {}

  // ── Choix Hors Process override wiring ───────────────────────────────────
  // Only wired when the server injected the checkbox (manager/admin sessions).
  // PF_CAN_OVERRIDE is also checked as a belt-and-suspenders guard, but the
  // real enforcement is server-side (PHP ignores hors_process=1 for operators).

  function buildTankCardsFromData(candidates) {
    // Rebuild the tank-card grid from a candidates array.
    // Used by the override to swap to the full (no date gate) list.
    if (!tankGrid) return;

    // Deselect any currently selected tank
    deselectTank();

    // Clear existing cards
    tankGrid.innerHTML = '';

    if (!candidates || candidates.length === 0) {
      tankGrid.innerHTML = '<p class="op-form__muted" style="font-size:0.82rem;">Aucun lot disponible.</p>';
      return;
    }

    candidates.forEach(function (cand) {
      const hasBeer   = cand.beer !== null && cand.beer !== '';
      const tankType  = escHtml(cand.tank_type || '?');
      const tankNum   = parseInt(cand.tank_number, 10) || 0;
      const tankFkId  = parseInt(cand.tank_fk_id, 10) || 0;
      const capHl     = cand.capacity_hl !== null ? Math.round(parseFloat(cand.capacity_hl)) : '—';
      // Headline: simulator remaining volume; secondary: original racked volume.
      const simVolHl  = cand.sim_vol_hl != null ? parseFloat(cand.sim_vol_hl).toFixed(1) + ' HL' : '—';
      const rackedHl  = cand.racked_vol_hl != null ? parseFloat(cand.racked_vol_hl).toFixed(1) + ' HL' : '—';
      const rackedAt  = cand.racked_at || '—';
      const beerName  = escHtml(cand.beer || '');
      const batchNum  = escHtml(cand.batch_num || '');
      const isCct     = cand.tank_type === 'CCT';

      var cardClasses = 'pf-tank-card' +
        (hasBeer ? '' : ' pf-tank-card--empty') +
        (isCct ? ' pf-tank-card--cct' : '');

      const inner =
        '<div class="pf-tank-card__label">' + tankType + ' ' + tankNum + '</div>' +
        '<div class="pf-tank-card__cap">' + capHl + ' HL</div>' +
        (hasBeer
          ? '<div class="pf-tank-card__beer">' + beerName + '</div>' +
            '<div class="pf-tank-card__batch">' + batchNum + '</div>' +
            '<div class="pf-tank-card__vol">' + escHtml(simVolHl) + '</div>' +
            '<div class="pf-tank-card__vol-racked">raclé ' + escHtml(rackedHl) + '</div>' +
            '<div class="pf-tank-card__date">soutirée ' + escHtml(rackedAt) + '</div>'
          : '<div class="pf-tank-card__empty-label">— vide / inconnu —</div>');

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = cardClasses;
      btn.dataset.tankType      = cand.tank_type || '';
      btn.dataset.tankNum       = String(tankNum);
      btn.dataset.tankFkId      = String(tankFkId);
      btn.dataset.nebBeer       = cand.neb_beer || '';
      btn.dataset.nebBatch      = cand.neb_batch || '';
      btn.dataset.contractBeer  = cand.contract_beer || '';
      btn.dataset.contractBatch = cand.contract_batch || '';
      btn.dataset.recipeId      = String(cand.neb_recipe_id_fk || cand.contract_recipe_id_fk || 0);
      btn.dataset.vol           = String(cand.sim_vol_hl != null ? cand.sim_vol_hl : (cand.racked_vol_hl || ''));
      btn.innerHTML             = inner;

      if (!hasBeer) {
        btn.disabled = true;
        btn.setAttribute('aria-disabled', 'true');
      }

      btn.addEventListener('click', function () {
        if (btn.disabled) return;
        selectTank(btn);
      });
      btn.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && !btn.disabled) {
          e.preventDefault();
          selectTank(btn);
        }
      });

      tankGrid.appendChild(btn);
    });
  }

  if (overrideCheckbox && window.PF_CAN_OVERRIDE) {
    // Show reason row when checked, hide when unchecked
    overrideCheckbox.addEventListener('change', function () {
      const isChecked = overrideCheckbox.checked;

      // Update hidden field for POST
      if (horsProcessFld) horsProcessFld.value = isChecked ? '1' : '0';

      // Show/hide reason row
      if (overrideReasonRow) overrideReasonRow.hidden = !isChecked;

      if (isChecked) {
        // Swap to override candidate list (no date gate)
        const overrideCandidates = window.PF_CANDIDATES_OVERRIDE || [];
        buildTankCardsFromData(overrideCandidates);

        // Show override banner
        var existingBanner = document.getElementById('pf-override-active-banner');
        if (!existingBanner && tankGrid) {
          var banner = document.createElement('div');
          banner.id        = 'pf-override-active-banner';
          banner.className = 'pf-override-active-banner';
          banner.innerHTML =
            '<strong>HORS PROCESS</strong> — Tous les lots en BBT/CCT sont affichés ' +
            '(délai minimum bypassé). La saisie sera marquée <code>hors_process_flag = 1</code>.';
          tankGrid.parentNode.insertBefore(banner, tankGrid);
        }
      } else {
        // Restore normal candidate list
        buildTankCardsFromData(window.PF_CANDIDATES || []);

        // Remove override banner
        var b = document.getElementById('pf-override-active-banner');
        if (b) b.remove();

        // Clear the hidden field
        if (horsProcessFld) horsProcessFld.value = '0';
      }
    });
  }

  // ── FormFramework init ────────────────────────────────────────────────────
  if (typeof FormFramework !== 'undefined') {
    FormFramework.init({
      formId:         'packaging-form',
      draftKey:       'packaging-draft',
      warningPanelId: 'packaging-warnings',
      thresholds: {
        tank_co2: {
          label: 'CO₂ cuve', unit: ' g/L',
          warn:    [3.5, 5.0],
          outlier: [2.5, 6.0],
        },
        tank_o2: {
          label: 'O₂ cuve', unit: ' ppb',
          warn:    [0, 50],
          outlier: [0, 200],
        },
      },
      diffFields: ['event_date', 'tank_co2', 'tank_o2'],
      diffLabels: {
        event_date: 'Date conditionnement',
        tank_co2:   'CO₂ cuve (g/L)',
        tank_o2:    'O₂ cuve (ppb)',
      },
    });
  }

});
