/**
 * racking-form.js — JS for /modules/form-racking.php (Saisie Transferts)
 *
 * Responsibilities:
 *   1. Candidate card selection → populate hidden identity fields.
 *   2. Choix Hors Process toggle → switch between normal and override card sets.
 *   3. Destination type → show/hide BBT / CCT / YT destination selects.
 *      Drives: CO₂/O₂ label swap (#6), CIP vessel dynamic label (#9),
 *      dest-CIP required flag (#10 conditional), resultant display (#5).
 *   4. Residual (blend_hl) change → update resultant display (#5) and re-evaluate
 *      dest-CIP required client-side (#10 conditional).
 *   5. FormFramework init (draft save, diff-preview, QC thresholds).
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
  const ytFld               = document.getElementById('yt-field');    // #4

  // #5 — Residual + resultant display
  const blendHlInput        = document.getElementById('blend_hl');
  const rackedVolInput      = document.getElementById('racked_vol_hl');
  const resultantDisplay    = document.getElementById('rf-resultant-display');

  // #6 — Dynamic CO₂/O₂ labels
  const lblCo2              = document.getElementById('lbl-co2');
  const lblO2               = document.getElementById('lbl-o2');

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

  // ── Helper: parse a decimal from a text input (comma or dot decimal sep) ─
  function parseDecimal(v) {
    if (v === null || v === undefined || String(v).trim() === '') return null;
    return parseFloat(String(v).trim().replace(',', '.'));
  }

  // ── #5 — Resultant volume display ──────────────────────────────────────
  // Volume résultant = racked_vol_hl + blend_hl (pure JS; nothing stored).
  // TankSimulator is the only authoritative compute engine.
  function updateResultant() {
    if (!resultantDisplay) return;
    var racked = parseDecimal(rackedVolInput ? rackedVolInput.value : null);
    var blend  = parseDecimal(blendHlInput  ? blendHlInput.value  : null);
    if (racked !== null && !isNaN(racked)) {
      var b = (blend !== null && !isNaN(blend) && blend > 0) ? blend : 0;
      resultantDisplay.textContent = (racked + b).toFixed(2) + ' HL';
    } else {
      resultantDisplay.textContent = '—';
    }
  }

  if (rackedVolInput) rackedVolInput.addEventListener('input', updateResultant);
  if (blendHlInput)  blendHlInput.addEventListener('input', function () {
    updateResultant();
    // #10 conditional — re-evaluate dest CIP required when residual changes
    updateDestCipRequired();
  });
  updateResultant();

  // ── #10 conditional — dest CIP required client-side sync ──────────────
  // Mirror of cip_dest_required($blend_hl) server-side.
  // When residual > 0: dest CIP vessel checkbox is optional (unmark required).
  // When residual = 0 or empty: dest CIP vessel checkbox triggers required on fields.
  function updateDestCipRequired() {
    var blend = parseDecimal(blendHlInput ? blendHlInput.value : null);
    var destRequired = !(blend !== null && !isNaN(blend) && blend > 0);

    // Find the first vessel-toggle checkbox (index 0) and update the cipConfig
    // The cip-section partial's cipSyncRequired handles field-level required.
    // We only need to update the vessel row's "required" status.
    var vesselToggle = document.getElementById('cip_vessel_0_done');
    if (vesselToggle) {
      var fields = document.getElementById('cip-vessel-0-fields');
      if (fields) {
        // Add/remove a data attribute so the operator gets a visual hint
        var vesselRow = document.getElementById('cip-vessel-0-row');
        if (vesselRow) {
          if (destRequired) {
            vesselRow.classList.add('cip-dest-required');
          } else {
            vesselRow.classList.remove('cip-dest-required');
          }
        }

        // Re-apply required state to fields inside (mirrors cipSyncRequired behaviour)
        if (vesselToggle.checked) {
          // If checked, required state follows destRequired for each data-cip-required field
          fields.querySelectorAll('[data-cip-required]').forEach(function (el) {
            if (destRequired) {
              el.setAttribute('required', '');
            } else {
              el.removeAttribute('required');
            }
          });
          fields.querySelectorAll('select.cip-section__select').forEach(function (el) {
            if (destRequired) {
              el.setAttribute('required', '');
            } else {
              el.removeAttribute('required');
            }
          });
        }
        // If not checked, required is already stripped by cipSyncRequired in cip-section.php
      }
    }
  }
  updateDestCipRequired();

  // ── #6 — CO₂/O₂ label swap by destination type ─────────────────────────
  // Columns (bbt_co2 / bbt_o2) stay. Only the labels change.
  function updateGasLabels(destType) {
    var type = destType || 'BBT';
    var suffix = (type === 'BBT') ? 'BBT' : (type === 'CCT' ? 'CCT' : 'YT');
    if (lblCo2) lblCo2.textContent = 'CO₂ ' + suffix;
    if (lblO2)  lblO2.textContent  = 'O₂ '  + suffix;
  }

  // ── #4 / #9 — Destination type → show/hide selects + update CIP vessel label ─
  function getDestNumber(destType) {
    if (destType === 'BBT') {
      var s = document.getElementById('bbt_number');
      return s ? (parseInt(s.value, 10) || null) : null;
    }
    if (destType === 'CCT') {
      var s = document.getElementById('cct_number');
      return s ? (parseInt(s.value, 10) || null) : null;
    }
    if (destType === 'YT') {
      var s = document.getElementById('yt_number');
      return s ? (parseInt(s.value, 10) || null) : null;
    }
    return null;
  }

  function updateDestFields() {
    var v = destSel ? destSel.value : '';

    // Show/hide the three dest-number selects
    if (bbtFld) bbtFld.style.display = (v === 'BBT') ? '' : 'none';
    if (cctFld) cctFld.style.display = (v === 'CCT') ? '' : 'none';
    if (ytFld)  ytFld.style.display  = (v === 'YT')  ? '' : 'none';

    // Clear deselected selects' values
    if (v !== 'BBT') { var e = document.getElementById('bbt_number'); if (e) e.value = ''; }
    if (v !== 'CCT') { var e = document.getElementById('cct_number'); if (e) e.value = ''; }
    if (v !== 'YT')  { var e = document.getElementById('yt_number');  if (e) e.value = ''; }

    // #6 — Update CO₂/O₂ labels
    updateGasLabels(v);

    // #9 — Update CIP dynamic vessel label (drives the cip-section partial)
    var cipCode = v ? v.toLowerCase() : 'bbt';  // 'bbt'|'cct'|'yt'
    var cipNum  = getDestNumber(v);
    if (typeof window.cipUpdateVesselLabel === 'function') {
      window.cipUpdateVesselLabel(cipCode, cipNum);
    }
  }

  // Also update CIP label when a dest-number select changes
  function onDestNumChange() {
    updateDestFields();
  }

  var bbtNumSel = document.getElementById('bbt_number');
  var cctNumSel = document.getElementById('cct_number');
  var ytNumSel  = document.getElementById('yt_number');
  if (bbtNumSel) bbtNumSel.addEventListener('change', onDestNumChange);
  if (cctNumSel) cctNumSel.addEventListener('change', onDestNumChange);
  if (ytNumSel)  ytNumSel.addEventListener('change', onDestNumChange);

  if (destSel) destSel.addEventListener('change', updateDestFields);
  updateDestFields(); // initial state

  // ── Card selection logic ────────────────────────────────────────────────
  function selectCard(card) {
    if (selectedCard) {
      selectedCard.classList.remove('rf-cand-card--selected');
    }
    selectedCard = card;
    card.classList.add('rf-cand-card--selected');

    const nebBeer   = card.dataset.nebBeer   ?? '';
    const nebBatch  = card.dataset.nebBatch  ?? '';
    const recipeId  = card.dataset.recipeId  ?? '';
    const sourceCct = card.dataset.sourceCct ?? '';
    const hp        = card.dataset.horsProcess ?? '0';
    const srcType   = card.dataset.sourceType ?? 'CCT';  // #1 — BBT lots have sourceType=BBT

    hidNebBeer.value        = nebBeer;
    hidNebBatch.value       = nebBatch;
    hidNebRecipeId.value    = recipeId;
    hidContractBeer.value   = '';
    hidContractBatch.value  = '';
    hidContractRecipeId.value = '';
    hidSourceCct.value      = sourceCct;
    horsProcessInput.value  = hp;

    // Summary strip — show tank label based on source type
    var tankLabel;
    if (srcType === 'BBT') {
      tankLabel = 'BBT ' + escHtml(card.dataset.sourceBbt ?? '?');
    } else {
      tankLabel = 'CCT ' + escHtml(sourceCct);
    }
    const hpLabel = hp === '1' ? ' [HORS PROCESS]' : '';
    selectedSummary.textContent =
      (escHtml(nebBeer) || '(contrat)') +
      '  ·  Brassin ' + escHtml(nebBatch) +
      '  ·  ' + tankLabel + hpLabel;
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
      if (overrideReasonRow) overrideReasonRow.hidden = !isOverrideMode;
      if (normalCandSection)   normalCandSection.hidden   = isOverrideMode;
      if (overrideCandSection) overrideCandSection.hidden = !isOverrideMode;
      deselect();
    });
  }

  // ── FormFramework init ────────────────────────────────────────────────
  if (typeof FormFramework !== 'undefined') {
    FormFramework.init({
      formId:        'racking-form',
      draftKey:      'racking-draft',
      warningPanelId: 'racking-warnings',
      thresholds: {
        bbt_co2: {
          label: 'CO₂ destination', unit: ' g/L',
          warn:    [3.5, 5.0],
          outlier: [2.5, 6.0],
        },
        bbt_o2: {
          label: 'O₂ destination', unit: ' ppb',
          warn:    [0, 50],
          outlier: [0, 200],
        },
        racked_vol_hl: {
          label: 'Volume transféré', unit: ' HL',
          warn:    [10, 100],
          outlier: [1, 150],
        },
        bbt_pressure: {
          label: 'Pression destination', unit: ' bar',
          warn:    [0.8, 2.5],
          outlier: [0.0, 3.5],
        },
      },
      diffFields: [
        'neb_beer', 'neb_batch', 'contract_beer', 'contract_batch',
        'rack_type', 'event_date', 'racking_destination_type',
        'bbt_number', 'cct_number', 'yt_number',
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
        bbt_number:                'BBT N°',
        cct_number:                'CCT N°',
        yt_number:                 'YT N°',
        racked_vol_hl:             'Volume (HL)',
        bbt_co2:                   'CO₂ (g/L)',
        bbt_o2:                    'O₂ (ppb)',
        bbt_pressure:              'Pression (bar)',
        blend_hl:                  'Résiduel (HL)',
      },
    });
  }
});
