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
 *   5. KZE PU section visibility — shown when KZE is in the CIP set:
 *      cip_inline_combine checked OR cip_machine_kze checked.
 *      Drives data-pu-required → required attribute on the two PU inputs.
 *   6. FormFramework init (draft save, diff-preview, QC thresholds).
 *
 * C5 — BBT blend-candidate UX:
 *   When type=BBT + source card selected, surface same-beer non-empty BBTs as
 *   blend-candidate cards showing volume + lot composition. Selecting one:
 *     - sets bbt_number (the existing field POST expects),
 *     - auto-fills blend_hl with that BBT's total_hl and makes it read-only,
 *     - re-syncs resultant, dest-CIP required, and warnings.
 *   When no same-beer BBT exists, a hors-process direction message is shown.
 *   Non-BBT destinations and hors-process are unchanged.
 *
 * Data injected by PHP:
 *   window.RF_CANDIDATES            — normal (gated) candidate list
 *   window.RF_CANDIDATES_OVERRIDE   — hors-process candidate list (manager/admin only)
 *   window.RF_CAN_OVERRIDE          — boolean: current user may see the override block
 *   window.BBT_BLEND_CANDIDATES     — C5: {beerName: [{bbt, beer, total_hl, lots}]}
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

  // Flowmeter counter refs
  const flowStartInput      = document.getElementById('flowmeter_start_hl');
  const flowEndInput        = document.getElementById('flowmeter_end_hl');
  const flowCalcHint        = document.getElementById('rf-vol-calculé-hint');
  const flowErrorDiv        = document.getElementById('rf-flowmeter-error');

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

  // ── Flowmeter counter live logic ────────────────────────────────────────
  // When both start and end readings are valid numbers, derive racked_vol_hl
  // as (end - start), set the input read-only, and show the "(calculé)" hint.
  // If end < start, surface an inline error and clear the derived value.
  // If either reading is blank, restore racked_vol_hl to editable.
  function syncFlowmeterDerive() {
    if (!flowStartInput || !flowEndInput || !rackedVolInput) return;
    var start = parseDecimal(flowStartInput.value);
    var end   = parseDecimal(flowEndInput.value);
    var hasStart = start !== null && !isNaN(start);
    var hasEnd   = end   !== null && !isNaN(end);

    if (hasStart && hasEnd) {
      if (end < start) {
        // Validation error — end < start
        if (flowErrorDiv) {
          flowErrorDiv.textContent = 'Relevé fin (' + end.toFixed(1) + ') < début (' + start.toFixed(1) + ') — vérifiez les relevés.';
          flowErrorDiv.hidden = false;
        }
        rackedVolInput.removeAttribute('readonly');
        rackedVolInput.value = '';
        if (flowCalcHint) flowCalcHint.hidden = true;
      } else {
        // Valid — derive and lock
        if (flowErrorDiv) { flowErrorDiv.hidden = true; flowErrorDiv.textContent = ''; }
        var delta = end - start;
        rackedVolInput.value = delta.toFixed(1);
        rackedVolInput.setAttribute('readonly', '');
        if (flowCalcHint) flowCalcHint.hidden = false;
        updateResultant();
      }
    } else {
      // Missing one or both — restore manual entry
      if (flowErrorDiv) { flowErrorDiv.hidden = true; flowErrorDiv.textContent = ''; }
      rackedVolInput.removeAttribute('readonly');
      if (flowCalcHint) flowCalcHint.hidden = true;
    }
  }

  if (flowStartInput) flowStartInput.addEventListener('input', syncFlowmeterDerive);
  if (flowEndInput)   flowEndInput.addEventListener('input', syncFlowmeterDerive);
  syncFlowmeterDerive();

  // ── Loss field refs (C3 — Pertes section) ─────────────────────────────
  var perteToggle       = document.getElementById('rf-perte-toggle');
  var perteFields       = document.getElementById('rf-pertes-fields');
  var lossSourceInput   = document.getElementById('loss_source_hl');
  var lossDestInput     = document.getElementById('loss_dest_hl');
  var lossCauseSelect   = document.getElementById('loss_cause');
  var lossBalanceDisplay= document.getElementById('rf-loss-balance');

  // ── C4 — Interrupted transfer refs ────────────────────────────────────
  var interruptedToggle  = document.getElementById('rf-interrupted-toggle');
  var interruptedFields  = document.getElementById('rf-interrupted-fields');
  var interruptedReason  = document.getElementById('interrupted_reason');
  var bbtPropreRow       = document.getElementById('rf-bbt-propre-row');
  var bbtPropreRadios    = document.querySelectorAll('.rf-bbt-propre-radio');

  // ── #5 — Resultant volume display ──────────────────────────────────────
  // Volume résultant = racked_vol_hl + blend_hl (pure JS; nothing stored).
  // TankSimulator is the only authoritative compute engine.
  //
  // C3 extension: also updates the Pertes balance readout:
  //   Cuve arrivée après = residual + racked_vol − loss_dest
  //   Perte totale       = loss_source + loss_dest
  // And fires the soft over-volume warning checks.
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

    // C3 — Update the Pertes balance display and refresh warnings via FormFramework
    updateLossBalance(racked, blend);
    if (typeof FormFramework !== 'undefined' && typeof FormFramework.refreshWarnings === 'function') {
      FormFramework.refreshWarnings();
    }
  }

  if (rackedVolInput) rackedVolInput.addEventListener('input', updateResultant);
  if (blendHlInput)  blendHlInput.addEventListener('input', function () {
    updateResultant();
    // #10 conditional — re-evaluate dest CIP required when residual changes
    updateDestCipRequired();
  });
  updateResultant();

  // ── C3 — Pertes toggle reveal ──────────────────────────────────────────
  // Matches the KZE PU section pattern: checkbox shows/hides the block;
  // loss_cause is NOT statically required (drives required from JS only).
  function syncPerteToggle() {
    var revealed = perteToggle ? perteToggle.checked : false;
    if (perteFields) perteFields.hidden = !revealed;

    // When collapsed: remove required from all loss fields (hidden-required deadlock prevention).
    // When revealed: loss_cause required only if a loss volume > 0 (see syncLossCauseRequired).
    if (!revealed) {
      if (lossCauseSelect) lossCauseSelect.removeAttribute('required');
    } else {
      syncLossCauseRequired();
    }
    // Re-run balance + warnings since reveal state changed
    var racked = parseDecimal(rackedVolInput ? rackedVolInput.value : null);
    var blend  = parseDecimal(blendHlInput  ? blendHlInput.value  : null);
    updateLossBalance(racked, blend);
    if (typeof FormFramework !== 'undefined' && typeof FormFramework.refreshWarnings === 'function') {
      FormFramework.refreshWarnings();
    }
  }

  // loss_cause is required-while-visible when any volume > 0 is entered.
  // If both volumes are 0/empty → no required (no-loss path, everything stays NULL server-side).
  function syncLossCauseRequired() {
    if (!lossCauseSelect) return;
    var revealed    = perteToggle ? perteToggle.checked : false;
    var lostSrc     = parseDecimal(lossSourceInput ? lossSourceInput.value : null);
    var lostDst     = parseDecimal(lossDestInput   ? lossDestInput.value   : null);
    var hasSrcVol   = (lostSrc !== null && !isNaN(lostSrc) && lostSrc > 0);
    var hasDstVol   = (lostDst !== null && !isNaN(lostDst) && lostDst > 0);
    var needsCause  = revealed && (hasSrcVol || hasDstVol);
    if (needsCause) {
      lossCauseSelect.setAttribute('required', '');
    } else {
      lossCauseSelect.removeAttribute('required');
    }
  }

  if (perteToggle) perteToggle.addEventListener('change', syncPerteToggle);
  if (lossSourceInput) lossSourceInput.addEventListener('input', function () {
    syncLossCauseRequired();
    updateResultant();
  });
  if (lossDestInput) lossDestInput.addEventListener('input', function () {
    syncLossCauseRequired();
    updateResultant();
  });
  // loss_note input: when the operator types a justification, the palier warning
  // demotes from 'outlier' (forced comment) to 'warn' (advisory) — refresh live.
  var lossNoteInput = document.getElementById('loss_note');
  if (lossNoteInput) {
    lossNoteInput.addEventListener('input', function () {
      if (typeof FormFramework !== 'undefined' && typeof FormFramework.refreshWarnings === 'function') {
        FormFramework.refreshWarnings();
      }
    });
  }
  syncPerteToggle(); // initial state

  // ── C3 — Loss balance readout ──────────────────────────────────────────
  // Shows: "Cuve arrivée après : X HL  |  Perte totale : Y HL"
  // Read-only, informational. Rendered only when Pertes section is revealed.
  function updateLossBalance(racked, blend) {
    if (!lossBalanceDisplay) return;
    var revealed = perteToggle ? perteToggle.checked : false;
    if (!revealed) {
      lossBalanceDisplay.textContent = '—';
      return;
    }
    var lostSrc = parseDecimal(lossSourceInput ? lossSourceInput.value : null);
    var lostDst = parseDecimal(lossDestInput   ? lossDestInput.value   : null);
    var src     = (lostSrc !== null && !isNaN(lostSrc)) ? lostSrc : 0;
    var dst     = (lostDst !== null && !isNaN(lostDst)) ? lostDst : 0;
    var total   = src + dst;

    if (racked !== null && !isNaN(racked)) {
      var b             = (blend !== null && !isNaN(blend) && blend > 0) ? blend : 0;
      var afterDest     = Math.max(0, racked + b - dst);
      lossBalanceDisplay.textContent =
        'Cuve arrivée après : ' + afterDest.toFixed(2) + ' HL\n' +
        'Perte totale : ' + total.toFixed(2) + ' HL';
    } else {
      lossBalanceDisplay.textContent = 'Perte totale : ' + total.toFixed(2) + ' HL';
    }
  }

  // ── C3c — extraWarnings provider for FormFramework ────────────────────
  // Returns an array of warning objects consumed by FormFramework.collectAllWarnings
  // and rendered into #racking-warnings via FormFramework.refreshWarnings().
  //
  // Replaces the C3b direct-panel injection (checkRackPalierWarning /
  // checkOverVolumeWarning) so warnings go through the shared pipeline and the
  // submit dialog can enforce a comment when the palier fires without a justification.
  //
  // Warning definitions:
  //
  //   Rack palier (loss % > threshold):
  //     level = 'outlier' when loss_note is currently EMPTY  → forces comment in submit dialog
  //     level = 'warn'    when loss_note already has text    → advisory only (no force)
  //     commentTarget = 'loss_note'                          → dialog comment injects into loss_note
  //
  //   Over-volume (impossible volume drain):
  //     level = 'warn' always — PM ruling: never forces a comment, stays advisory.
  //     No commentTarget.
  function rfExtraWarnings() {
    var warnings = [];
    var revealed  = perteToggle ? perteToggle.checked : false;
    if (!revealed) return warnings;

    var racked    = parseDecimal(rackedVolInput ? rackedVolInput.value : null);
    var blend     = parseDecimal(blendHlInput   ? blendHlInput.value   : null);
    var lostSrc   = parseDecimal(lossSourceInput ? lossSourceInput.value : null);
    var lostDst   = parseDecimal(lossDestInput   ? lossDestInput.value   : null);
    var src       = (lostSrc !== null && !isNaN(lostSrc)) ? lostSrc : 0;
    var dst       = (lostDst !== null && !isNaN(lostDst)) ? lostDst : 0;
    var totalLoss = src + dst;
    var b         = (blend !== null && !isNaN(blend) && blend > 0) ? blend : 0;

    // ── Rack palier ──────────────────────────────────────────────────────
    if (racked !== null && !isNaN(racked) && racked > 0 && totalLoss > 0) {
      var warnPct = (window.PERTES_CONFIG && typeof window.PERTES_CONFIG.rack_warn_pct === 'number')
        ? window.PERTES_CONFIG.rack_warn_pct
        : 2.0;
      var lossPct = (totalLoss / racked) * 100;
      if (lossPct > warnPct) {
        // level is 'outlier' (forces comment) only when loss_note is currently empty.
        // Once the operator has typed a justification the warning stays advisory ('warn').
        var lossNoteEl  = document.getElementById('loss_note');
        var hasNote     = lossNoteEl && lossNoteEl.value.trim() !== '';
        warnings.push({
          level:         hasNote ? 'warn' : 'outlier',
          commentTarget: 'loss_note',
          message:       '⚠ Perte totale ' + lossPct.toFixed(1) + ' % du volume transféré ' +
                         '(seuil : ' + warnPct + ' %). Justification requise dans « Détails / explication ».',
        });
      }
    }

    // ── Over-volume (always advisory, never forces a comment) ────────────
    // a) loss_dest > residual + racked_vol
    if (lostDst !== null && !isNaN(lostDst) && lostDst > 0 &&
        racked !== null && !isNaN(racked) && lostDst > racked + b) {
      warnings.push({
        level:   'warn',
        message: '⚠ Perte cuve arrivée (' + lostDst.toFixed(3) + ' HL) supérieure au volume disponible ' +
                 '(' + (racked + b).toFixed(2) + ' HL résiduel + transféré).',
      });
    }
    // b) loss_source > sim_vol_hl from the selected card
    if (lostSrc !== null && !isNaN(lostSrc) && lostSrc > 0 && selectedCard) {
      var simVol = parseFloat(selectedCard.dataset.simVolHl || '0');
      if (!isNaN(simVol) && simVol > 0 && lostSrc > simVol) {
        warnings.push({
          level:   'warn',
          message: '⚠ Perte cuve départ (' + lostSrc.toFixed(3) + ' HL) supérieure au volume estimé en CCT ' +
                   '(' + simVol.toFixed(2) + ' HL).',
        });
      }
    }

    return warnings;
  }

  // ── C4 — Interrupted transfer toggle reveal ───────────────────────────
  // Matches the Pertes section reveal pattern exactly.
  // interrupted_reason: required while the section is visible.
  // bbt-propre sub-row: revealed only when interrupted is checked AND racked_vol_hl == 0/empty.
  //   Radio Oui/Non required while sub-row is visible (one must be chosen).
  function syncInterruptedToggle() {
    var revealed = interruptedToggle ? interruptedToggle.checked : false;
    if (interruptedFields) interruptedFields.hidden = !revealed;

    // interrupted_reason: required while visible (no static required on the element).
    if (interruptedReason) {
      if (revealed) {
        interruptedReason.setAttribute('required', '');
      } else {
        interruptedReason.removeAttribute('required');
      }
    }

    // BBT-propre sub-row visibility depends on interrupted AND racked_vol == 0.
    syncBbtPropreVisibility();
  }

  // BBT-propre sub-row: show when interrupted is checked AND racked_vol_hl is 0 or empty.
  // Radios get required while visible (no static required).
  function syncBbtPropreVisibility() {
    var interrupted = interruptedToggle ? interruptedToggle.checked : false;
    var racked = parseDecimal(rackedVolInput ? rackedVolInput.value : null);
    var rackedIsZero = (racked === null || isNaN(racked) || racked === 0);
    var showPropre = interrupted && rackedIsZero;

    if (bbtPropreRow) bbtPropreRow.hidden = !showPropre;

    // Required: at least one radio must be chosen when the sub-row is visible.
    // Strategy: mark the radio inputs required while visible (browser validates).
    bbtPropreRadios.forEach(function (radio) {
      if (showPropre) {
        radio.setAttribute('required', '');
      } else {
        radio.removeAttribute('required');
      }
    });
  }

  if (interruptedToggle) {
    interruptedToggle.addEventListener('change', function () {
      syncInterruptedToggle();
      updateDestCipRequired(); // re-evaluate since interrupted changes the semantic
    });
  }

  // Re-evaluate bbt-propre when racked_vol changes (already wired for updateResultant above)
  // We extend the existing rackedVolInput listener chain via a wrapper rather than adding
  // a duplicate addEventListener. The updateResultant() path already fires for input events;
  // we add syncBbtPropreVisibility() call there via a separate targeted handler.
  if (rackedVolInput) {
    rackedVolInput.addEventListener('input', syncBbtPropreVisibility);
  }

  syncInterruptedToggle(); // initial state on DOMContentLoaded

  // ── #10 conditional — dest CIP required client-side sync ──────────────
  // Composition (mirrors cip_dest_required() server-side after C4 extension):
  //   destRequired = (residual == 0) AND NOT(dest BBT is clean)
  //
  // Residual > 0: blend case → CIP always optional, regardless of clean-state.
  // Residual = 0: look up window.BBT_CLEAN_STATES[bbtNumber].
  //   'clean'   → CIP NOT required (recent CIP or interrupted-zero attested clean).
  //   'dirty'   → CIP IS required.
  //   'unknown' → CIP IS required (conservative).
  //   No BBT number selected yet → default to required (conservative).
  //
  // Non-BBT destinations (CCT, YT): clean-state concept does not apply to them
  // in this form (no bd_cip_events with target_code='cct' stored from racking).
  // For CCT/YT destinations, fall back to the residual-only rule.
  function updateDestCipRequired() {
    var blend = parseDecimal(blendHlInput ? blendHlInput.value : null);
    var residualIsZero = !(blend !== null && !isNaN(blend) && blend > 0);

    var destRequired;
    if (!residualIsZero) {
      // Blend case: dest CIP always optional.
      destRequired = false;
    } else {
      // Residual = 0: compose with BBT clean-state.
      var destType = destSel ? destSel.value : '';
      if (destType === 'BBT') {
        var bbtSel = document.getElementById('bbt_number');
        var bbtNum = bbtSel ? (parseInt(bbtSel.value, 10) || null) : null;
        if (bbtNum && window.BBT_CLEAN_STATES && window.BBT_CLEAN_STATES[bbtNum] === 'clean') {
          destRequired = false; // BBT is clean → no CIP needed for this fill
        } else {
          destRequired = true;  // dirty, unknown, or no BBT selected → required
        }
      } else {
        // CCT / YT / no dest selected → residual-only rule (residual = 0 → required)
        destRequired = true;
      }
    }

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

  // ── C5 — BBT blend-candidate UX ────────────────────────────────────────
  // When type=BBT and a source card is selected, surface same-beer non-empty BBTs
  // from window.BBT_BLEND_CANDIDATES as selectable cards.
  // blend_hl becomes read-only (auto-filled) while a blend candidate is active.
  // Returns to manual when dest type changes away from BBT or card is deselected.

  var bbtBlendSection  = document.getElementById('rf-bbt-blend-section');
  var bbtBlendGrid     = document.getElementById('rf-bbt-blend-grid');
  var bbtBlendNone     = document.getElementById('rf-bbt-blend-none');
  var bbtVideRow       = document.getElementById('rf-bbt-vide-row');
  var bbtVideToggle    = document.getElementById('rf-bbt-vide-toggle');
  var selectedBlendBbt = null; // currently selected blend-candidate BBT number (int | null)

  // Return the canonical beer name from the currently selected source card.
  // beer is in data-neb-beer (may be '' for contract beers, which is fine — no
  // same-beer candidates can exist if the name is empty).
  function selectedBeerName() {
    if (!selectedCard) return '';
    return selectedCard.dataset.nebBeer || '';
  }

  // Render blend-candidate cards for the given beer name.
  // Clears and rebuilds #rf-bbt-blend-grid each time.
  function renderBlendCandidates(beerName) {
    if (!bbtBlendGrid) return;
    bbtBlendGrid.innerHTML = '';
    selectedBlendBbt = null;

    var candidates = (window.BBT_BLEND_CANDIDATES && beerName)
      ? (window.BBT_BLEND_CANDIDATES[beerName] || [])
      : [];

    if (candidates.length === 0) {
      if (bbtBlendNone) bbtBlendNone.hidden = false;
      return;
    }
    if (bbtBlendNone) bbtBlendNone.hidden = true;

    candidates.forEach(function (cand) {
      var bbtNum  = cand.bbt;
      var totalHl = (typeof cand.total_hl === 'number') ? cand.total_hl : parseFloat(cand.total_hl || '0');
      var lots    = Array.isArray(cand.lots) ? cand.lots : [];

      // Lot composition line: "brassin 209 18 % · brassin 210 82 %"
      var lotLine = lots.map(function (l) {
        return 'brassin ' + escHtml(l.batch) + ' ' + l.pct + ' %';
      }).join(' · ');

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'rf-bbt-cand-card';
      btn.dataset.bbtNum  = String(bbtNum);
      btn.dataset.totalHl = String(totalHl);
      btn.innerHTML =
        '<div class="rf-bbt-cand-card__label">BBT ' + escHtml(String(bbtNum)) + '</div>' +
        '<div class="rf-bbt-cand-card__vol">' + totalHl.toFixed(2) + ' HL</div>' +
        (lotLine ? '<div class="rf-bbt-cand-card__lots">' + lotLine + '</div>' : '');

      btn.addEventListener('click', function () {
        selectBlendCandidate(bbtNum, totalHl, btn);
      });

      bbtBlendGrid.appendChild(btn);
    });
  }

  // Select a blend candidate: set bbt_number, auto-fill blend_hl (read-only),
  // re-sync resultant, dest-CIP required, CIP vessel label, and warnings.
  function selectBlendCandidate(bbtNum, totalHl, btn) {
    // Deselect previous blend card
    if (bbtBlendGrid) {
      bbtBlendGrid.querySelectorAll('.rf-bbt-cand-card--selected').forEach(function (el) {
        el.classList.remove('rf-bbt-cand-card--selected');
      });
    }
    btn.classList.add('rf-bbt-cand-card--selected');
    selectedBlendBbt = bbtNum;

    // Set bbt_number (the field POST expects)
    var bbtNumSel = document.getElementById('bbt_number');
    if (bbtNumSel) {
      bbtNumSel.value = String(bbtNum);
    }

    // Auto-fill blend_hl and make it read-only
    if (blendHlInput) {
      blendHlInput.value = totalHl.toFixed(2);
      blendHlInput.setAttribute('readonly', '');
    }

    // Reveal "BBT vide" override checkbox and reset it unchecked
    if (bbtVideRow) bbtVideRow.hidden = false;
    if (bbtVideToggle) bbtVideToggle.checked = false;

    // Re-sync all dependents
    updateResultant();
    updateDestCipRequired();

    // Update CIP vessel label to reflect the now-selected BBT number
    if (typeof window.cipUpdateVesselLabel === 'function') {
      window.cipUpdateVesselLabel('bbt', bbtNum);
    }
  }

  // "BBT vide" toggle — phantom-residual discard.
  // Checked: scraps the tracked residual (blend_hl → 0) so sim routes through
  //          fresh-fill branch. NOT a perte; no loss_dest path involved.
  // Unchecked: restores the selected candidate's residual from its card dataset.
  if (bbtVideToggle) {
    bbtVideToggle.addEventListener('change', function () {
      if (!blendHlInput) return;
      if (bbtVideToggle.checked) {
        blendHlInput.value = '0';
      } else {
        // Restore from the currently-selected candidate card
        var selCard = bbtBlendGrid
          ? bbtBlendGrid.querySelector('.rf-bbt-cand-card--selected')
          : null;
        if (selCard && selCard.dataset.totalHl !== undefined) {
          blendHlInput.value = parseFloat(selCard.dataset.totalHl).toFixed(2);
        }
      }
      updateResultant();
      updateDestCipRequired();
    });
  }

  // Clear the blend-candidate selection (but keep section visible if still type=BBT).
  // Restores blend_hl to manual.
  function clearBlendSelection() {
    if (bbtBlendGrid) {
      bbtBlendGrid.querySelectorAll('.rf-bbt-cand-card--selected').forEach(function (el) {
        el.classList.remove('rf-bbt-cand-card--selected');
      });
    }
    selectedBlendBbt = null;

    // Restore blend_hl to manual (remove readonly, but don't clear the value —
    // the operator may have a previously typed value they want to keep)
    if (blendHlInput) {
      blendHlInput.removeAttribute('readonly');
    }

    // Hide and reset the "BBT vide" override — it no longer applies
    if (bbtVideRow) bbtVideRow.hidden = true;
    if (bbtVideToggle) bbtVideToggle.checked = false;
  }

  // Show or hide the blend-candidate section based on dest type + source card.
  // Called whenever dest type changes or a card is selected/deselected.
  function syncBlendSection() {
    var destType = destSel ? destSel.value : '';
    var beer     = selectedBeerName();

    // Only show for BBT, normal (non-hors-process) mode, and with a beer name.
    // hors-process: the existing hors-process flow is untouched (all tanks visible).
    var shouldShow = (destType === 'BBT' && !isOverrideMode && beer !== '');

    if (!bbtBlendSection) return;

    if (!shouldShow) {
      bbtBlendSection.hidden = true;
      // When section hides, clear any blend candidate selection and restore manual blend_hl
      clearBlendSelection();
      if (bbtBlendGrid) bbtBlendGrid.innerHTML = '';
      if (bbtBlendNone) bbtBlendNone.hidden = true;
      // Ensure "BBT vide" override is hidden and unchecked when section is hidden
      if (bbtVideRow) bbtVideRow.hidden = true;
      if (bbtVideToggle) bbtVideToggle.checked = false;
      return;
    }

    bbtBlendSection.hidden = false;
    renderBlendCandidates(beer);
  }

  // ── KZE PU section toggle ──────────────────────────────────────────────
  // The "Pasteurisation flash (KZE)" section is shown when KZE is in the CIP set:
  //   - cip_inline_combine (simultané) is checked, OR
  //   - cip_machine_kze (individual KZE done-checkbox) is checked.
  // When simultané is on, the individual KZE row is hidden by the CIP partial's JS —
  // so the effective trigger is either checkbox, checked via getElementById (survives DOM
  // changes by the CIP partial).
  //
  // Required discipline: we do NOT put a static `required` on the PU inputs in the markup.
  // Instead, inputs carry data-pu-required="1" and we set/remove `required` purely from JS,
  // synced on DOMContentLoaded AND on every relevant checkbox change.
  var kzePuSection    = document.getElementById('rf-kze-pu-section');
  var kzeInlineCb     = document.getElementById('cip_inline_combine');   // simultané checkbox
  var kzeMachineCb    = document.getElementById('cip_machine_kze');      // individual KZE done-checkbox

  function cipKzeActive() {
    var inlineChecked = kzeInlineCb ? kzeInlineCb.checked : false;
    var kzeChecked    = kzeMachineCb ? kzeMachineCb.checked : false;
    return inlineChecked || kzeChecked;
  }

  function togglePuSection() {
    if (!kzePuSection) return;
    var active = cipKzeActive();
    kzePuSection.hidden = !active;
    // Sync required attribute on all data-pu-required inputs.
    kzePuSection.querySelectorAll('[data-pu-required]').forEach(function (el) {
      if (active) {
        el.setAttribute('required', '');
      } else {
        el.removeAttribute('required');
      }
    });
  }

  // Wire to both checkboxes. Attach by id so the listener works even when the CIP partial's
  // JS re-hides/shows the individual KZE row (the checkbox itself is never removed from DOM).
  if (kzeInlineCb) kzeInlineCb.addEventListener('change', togglePuSection);
  if (kzeMachineCb) kzeMachineCb.addEventListener('change', togglePuSection);
  // Initial state on DOMContentLoaded.
  togglePuSection();

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

    // C5 — sync blend-candidate section (only active for BBT + normal mode + source selected)
    syncBlendSection();
  }

  // Also update CIP label when a dest-number select changes.
  // And re-evaluate dest CIP required: switching BBT may change clean-state.
  function onDestNumChange() {
    updateDestFields();
    updateDestCipRequired();
  }

  var bbtNumSel = document.getElementById('bbt_number');
  var cctNumSel = document.getElementById('cct_number');
  var ytNumSel  = document.getElementById('yt_number');
  if (bbtNumSel) bbtNumSel.addEventListener('change', onDestNumChange);
  if (cctNumSel) cctNumSel.addEventListener('change', onDestNumChange);
  if (ytNumSel)  ytNumSel.addEventListener('change', onDestNumChange);

  if (destSel) destSel.addEventListener('change', updateDestFields);
  updateDestFields(); // initial state

  // ── Helper: apply per-recipe QC thresholds ────────────────────────────────
  // Looks up window.QC_THRESHOLDS[recipeId] (or __global fallback) and calls
  // FormFramework.setThresholds() to swap the active band immediately.
  // No-op when QC_THRESHOLDS is null (resolver threw server-side — static init
  // thresholds remain active, same as before this feature was added).
  function applyQcThresholds(recipeId) {
    if (typeof FormFramework === 'undefined' || typeof FormFramework.setThresholds !== 'function') return;
    var map = window.QC_THRESHOLDS;
    if (!map) return;
    var bands = (recipeId && map[String(recipeId)]) ? map[String(recipeId)] : (map['__global'] || null);
    if (bands) {
      FormFramework.setThresholds(bands);
    }
  }

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

    // Switch QC threshold bands to this recipe's per-recipe values
    applyQcThresholds(recipeId);

    // C5 — re-render blend candidates for the newly selected beer
    syncBlendSection();

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

    // C5 — hide blend section (no source beer selected)
    syncBlendSection();
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
      // C5 — blend section is not shown in hors-process mode (syncBlendSection
      // reads isOverrideMode and hides accordingly; deselect() already calls it)
    });
  }

  // ── Machine CIP mandatory check — client-side submit guard ────────────
  // Mirrors the server-side throw: at least one machine CIP (centri / KZE / pompe)
  // must be active before submission. When none is active, rack_type cannot be derived
  // server-side → the server rejects anyway, but blocking early gives instant feedback.
  //
  // Checks (in priority order):
  //   1. cip_inline_combine (simultané) is checked — implies centri+KZE both present.
  //   2. cip_machine_centri is checked (individual mode).
  //   3. cip_machine_kze    is checked (individual mode).
  //   4. cip_machine_pump   is checked (individual mode).
  //
  // The error banner reuses the existing #racking-warnings panel (aria-live="polite").
  // Does NOT use hidden-required on the checkboxes — that deadlocks with the CIP partial's
  // JS (consistent with existing KZE PU section discipline).
  function cipMachineActive() {
    var inlineCb = document.getElementById('cip_inline_combine');
    var centriCb = document.getElementById('cip_machine_centri');
    var kzeCb    = document.getElementById('cip_machine_kze');
    var pumpCb   = document.getElementById('cip_machine_pump');
    return (inlineCb && inlineCb.checked) ||
           (centriCb && centriCb.checked) ||
           (kzeCb    && kzeCb.checked)    ||
           (pumpCb   && pumpCb.checked);
  }

  var rfForm = document.getElementById('racking-form');
  if (rfForm) {
    rfForm.addEventListener('submit', function (e) {
      if (!cipMachineActive()) {
        e.preventDefault();
        var panel = document.getElementById('racking-warnings');
        if (panel) {
          panel.hidden = false;
          panel.textContent =
            'Au moins un équipement CIP (centri / KZE / pompe) doit être renseigné ' +
            '— il détermine le type de transfert.';
          panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      }
    });
  }

  // ── FormFramework init ────────────────────────────────────────────────
  if (typeof FormFramework !== 'undefined') {
    FormFramework.init({
      formId:         'racking-form',
      draftKey:       'racking-draft',
      warningPanelId: 'racking-warnings',
      extraWarnings:  rfExtraWarnings,   // C3c — palier + over-vol via shared pipeline
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
        'event_date', 'racking_destination_type',
        'bbt_number', 'cct_number', 'yt_number',
        'racked_vol_hl', 'bbt_co2', 'bbt_o2', 'bbt_pressure', 'blend_hl',
      ],
      diffLabels: {
        neb_beer:                  'Recette Nébuleuse',
        neb_batch:                 'Brassin Nébuleuse',
        contract_beer:             'Recette contrat',
        contract_batch:            'Brassin contrat',
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

  // Re-sync conditional UI after draft restoration.
  // FormFramework.loadDraft() restores field values programmatically (no change event),
  // so every JS-driven conditional UI needs a second pass here: the dest number-field
  // show/hide, the resultant display, the conditional dest-CIP required, the KZE PU
  // section, and the QC threshold bands (restored from the hidden neb_recipe_id_fk
  // field that loadDraft populates — note: hidden fields are skipped by loadDraft, so
  // the recipe id must be read from the card that matches the draft's neb_beer/batch
  // values instead; fall back to __global if no card is re-selected).
  //
  // Draft restore does not auto-select a card (the card is a button, not a form field),
  // so the threshold fallback is __global unless the operator manually re-clicks a card.
  // That is acceptable: the draft is an unsubmitted WIP state; the operator must confirm
  // the beer selection anyway. The global band is active until they do.
  applyQcThresholds(null); // applies __global until a card is selected
  updateDestFields();
  syncFlowmeterDerive();   // re-sync flowmeter readonly state after draft restore
  updateResultant();     // calls refreshWarnings() internally via C3 extension
  updateDestCipRequired();
  togglePuSection();
  syncPerteToggle();       // C3 — re-sync Pertes section after draft restore
  syncInterruptedToggle(); // C4 — re-sync Interrupted section after draft restore
  // C5 — blend section: no source card is re-selected after draft restore (draft
  // only covers field values, not card clicks), so section starts hidden. This is
  // acceptable: the operator must re-click a source card to confirm beer selection.
  syncBlendSection();
  // Explicit refresh after all draft values are restored, in case loss_note was
  // already filled (drops the palier from 'outlier' to 'warn' on re-load).
  if (typeof FormFramework !== 'undefined' && typeof FormFramework.refreshWarnings === 'function') {
    FormFramework.refreshWarnings();
  }

  // ── Multi-entry turbidity widget (item #4) ──────────────────────────────
  // Mounts on both form-racking.php and racking-phase-in-progress.php (they share
  // this JS file). The mount div id is the same on both surfaces — only one renders
  // at a time so there is no collision.
  //
  // mode='average': each operator reading is entered individually; the session
  // average is computed client-side and stored in the hidden <input name=avg_turbidity>.
  // Server-side (form-racking.php:166, racking-phase-submit.php) is UNCHANGED —
  // it reads post_decimal('avg_turbidity') exactly as before.
  //
  // initialRows=[]: this is an append-only form; no existing avg_turbidity value
  // is prefilled by the server, so we start with one blank row (minRows=1).
  if (document.getElementById('rf-turbidity-msr')) {
    if (typeof MultiSubmitReads !== 'undefined') {
      MultiSubmitReads.init({
        mountId:     'rf-turbidity-msr',
        mode:        'average',
        outputName:  'avg_turbidity',
        decimals:    3,
        minRows:     1,
        maxRows:     20,
        fields: [{
          key:         'v',
          label:       'Turbidité',
          unit:        'NTU',
          placeholder: 'ex. 0.5',
          step:        '0.001',
        }],
        initialRows: [],
      });
    }
  }
});
