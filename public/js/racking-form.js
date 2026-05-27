/**
 * racking-form.js — JS for /modules/form-racking.php (Saisie Transferts)
 *
 * Responsibilities:
 *   1. Candidate card selection → populate hidden identity fields.
 *   2. Choix Hors Process toggle → switch between normal and override card sets.
 *   3. Destination type → show/hide BBT/CCT destination selects.
 *   4. FormFramework init (draft save, diff-preview, QC thresholds).
 *
 * Data injected by PHP:
 *   window.RF_CANDIDATES          — normal (gated) candidate list
 *   window.RF_CANDIDATES_OVERRIDE — hors-process candidate list (manager/admin only)
 *   window.RF_CAN_OVERRIDE        — boolean: current user may see the override block
 */

document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  // ── State ──────────────────────────────────────────────────────────────
  let selectedCard    = null;   // the currently selected .rf-cand-card button
  let isOverrideMode  = false;  // true when Choix Hors Process is active

  // ── Element refs ────────────────────────────────────────────────────────
  const overrideCheckbox    = document.getElementById('rf-override-checkbox');
  const overrideReasonRow   = document.getElementById('rf-override-reason-row');
  const normalCandSection   = document.getElementById('rf-normal-candidates');
  const overrideCandSection = document.getElementById('rf-override-candidates');
  const selectedLotDiv      = document.getElementById('rf-selected-lot');
  const selectedSummary     = document.getElementById('rf-selected-summary');
  const deselectBtn         = document.getElementById('rf-deselect');
  const horsProcessInput    = document.getElementById('hors_process');
  const destSel             = document.getElementById('racking_destination_type');
  const bbtFld              = document.getElementById('bbt-field');
  const cctFld              = document.getElementById('cct-field');

  // ── Hidden identity fields ──────────────────────────────────────────────
  const hidNebBeer        = document.getElementById('neb_beer');
  const hidNebBatch       = document.getElementById('neb_batch');
  const hidNebRecipeId    = document.getElementById('neb_recipe_id_fk');
  const hidContractBeer   = document.getElementById('contract_beer');
  const hidContractBatch  = document.getElementById('contract_batch');
  const hidContractRecipeId = document.getElementById('contract_recipe_id_fk');
  const hidSourceCct      = document.getElementById('source_cct_number');

  // ── Helper: escHtml (XSS guard for dynamic DOM) ───────────────────────
  function escHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Card selection logic ────────────────────────────────────────────────
  function selectCard(card) {
    // Deselect any previously selected card
    if (selectedCard) {
      selectedCard.classList.remove('rf-cand-card--selected');
    }

    selectedCard = card;
    card.classList.add('rf-cand-card--selected');

    // Populate hidden identity fields from data attributes
    const nebBeer   = card.dataset.nebBeer   ?? '';
    const nebBatch  = card.dataset.nebBatch  ?? '';
    const recipeId  = card.dataset.recipeId  ?? '';
    const sourceCct = card.dataset.sourceCct ?? '';
    const hp        = card.dataset.horsProcess ?? '0';

    hidNebBeer.value        = nebBeer;
    hidNebBatch.value       = nebBatch;
    hidNebRecipeId.value    = recipeId;
    hidContractBeer.value   = '';
    hidContractBatch.value  = '';
    hidContractRecipeId.value = '';
    hidSourceCct.value      = sourceCct;
    horsProcessInput.value  = hp;

    // Update the selected summary strip
    const hpLabel = hp === '1' ? ' [HORS PROCESS]' : '';
    selectedSummary.textContent =
      `${escHtml(nebBeer) || '(contrat)'}  ·  Brassin ${escHtml(nebBatch)}  ·  CCT ${escHtml(sourceCct)}${hpLabel}`;
    selectedLotDiv.hidden = false;
  }

  function deselect() {
    if (selectedCard) {
      selectedCard.classList.remove('rf-cand-card--selected');
      selectedCard = null;
    }
    hidNebBeer.value          = '';
    hidNebBatch.value         = '';
    hidNebRecipeId.value      = '';
    hidContractBeer.value     = '';
    hidContractBatch.value    = '';
    hidContractRecipeId.value = '';
    hidSourceCct.value        = '';
    horsProcessInput.value    = '0';
    selectedLotDiv.hidden     = true;
  }

  // Attach click listeners to all candidate cards (both normal and override)
  document.querySelectorAll('.rf-cand-card').forEach(function (card) {
    card.addEventListener('click', function () {
      selectCard(this);
    });
  });

  if (deselectBtn) {
    deselectBtn.addEventListener('click', deselect);
  }

  // ── Choix Hors Process toggle ────────────────────────────────────────────
  if (overrideCheckbox) {
    overrideCheckbox.addEventListener('change', function () {
      isOverrideMode = this.checked;

      // Show/hide the reason input row
      if (overrideReasonRow) overrideReasonRow.hidden = !isOverrideMode;

      // Swap candidate sections
      if (normalCandSection)   normalCandSection.hidden   = isOverrideMode;
      if (overrideCandSection) overrideCandSection.hidden = !isOverrideMode;

      // Clear current selection when toggling (card sets are different)
      deselect();
    });
  }

  // ── Destination type: show/hide BBT/CCT selects ─────────────────────────
  function updateDestFields() {
    const v = destSel ? destSel.value : '';
    if (bbtFld) bbtFld.style.display = (v === 'BBT' || v === '') ? '' : 'none';
    if (cctFld) cctFld.style.display = (v === 'CCT') ? '' : 'none';
    if (v !== 'BBT') {
      const bbtSel = document.getElementById('bbt_number');
      if (bbtSel) bbtSel.value = '';
    }
    if (v !== 'CCT') {
      const cctSel = document.getElementById('cct_number');
      if (cctSel) cctSel.value = '';
    }
  }
  if (destSel) destSel.addEventListener('change', updateDestFields);
  updateDestFields();

  // ── FormFramework init ────────────────────────────────────────────────
  if (typeof FormFramework !== 'undefined') {
    FormFramework.init({
      formId:        'racking-form',
      draftKey:      'racking-draft',
      warningPanelId: 'racking-warnings',
      thresholds: {
        bbt_co2: {
          label: 'CO₂ BBT', unit: ' g/L',
          warn:    [3.5, 5.0],
          outlier: [2.5, 6.0],
        },
        bbt_o2: {
          label: 'O₂ BBT', unit: ' ppb',
          warn:    [0, 50],
          outlier: [0, 200],
        },
        racked_vol_hl: {
          label: 'Volume transféré', unit: ' HL',
          warn:    [10, 100],
          outlier: [1, 150],
        },
        bbt_pressure: {
          label: 'Pression BBT', unit: ' bar',
          warn:    [0.8, 2.5],
          outlier: [0.0, 3.5],
        },
      },
      diffFields: [
        'neb_beer', 'neb_batch', 'contract_beer', 'contract_batch',
        'rack_type', 'event_date', 'racking_destination_type',
        'bbt_number', 'cct_number',
        'racked_vol_hl', 'bbt_co2', 'bbt_o2', 'bbt_pressure', 'blend_hl',
      ],
      diffLabels: {
        neb_beer:                  'Recette Nébuleuse',
        neb_batch:                 'Brassin Nébuleuse',
        contract_beer:             'Recette contrat',
        contract_batch:            'Brassin contrat',
        rack_type:                 'Type de rack',
        event_date:                'Date',
        racking_destination_type:  'Type destination',
        bbt_number:                'BBT n°',
        cct_number:                'CCT destination n°',
        racked_vol_hl:             'Volume (HL)',
        bbt_co2:                   'CO₂ (g/L)',
        bbt_o2:                    'O₂ (ppb)',
        bbt_pressure:              'Pression (bar)',
        blend_hl:                  'Blend (HL)',
      },
    });
  }
});
