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
 *   4. Per-row disposition groups: bottle/keg (run_type-driven); client (cuv OR
 *      contract/WL session); liner (cuv only). Re-evaluated on run_type change
 *      AND on tank select/deselect/WL toggle via updateAllRowDispositionGroups().
 *   5. Show/hide white-label name field.
 *   7. Enable submit only once a tank is selected AND ≥1 format row exists.
 *   8. Init FormFramework for soft-validation + draft persistence.
 *   9. Wire "Choix Hors Process" override checkbox (manager/admin only):
 *      - When checked: rebuild the tank grid from PF_CANDIDATES_OVERRIDE
 *        (all lots in CCT/BBT, no date gate) instead of PF_CANDIDATES.
 *      - Update hors_process hidden field (1 when checked, 0 otherwise).
 *      - Show override banner with prominent warning.
 *      - Server enforces the role — this JS is defense-in-depth, not the gate.
 *  10. SKU mosaic: when a tank is selected, render the recipe's activated formats
 *      as clickable tiles above the format rows. Tile click pre-fills format row 0.
 *      NULL-format SKUs land in an "à assigner" tray, never as tiles.
 *      Reads: window.PF_RECIPE_SKUS, window.PF_RECIPE_UNASSIGNED
 *  11. Cuve réutilisée (mig 237): for cuv rows, an optional "Cuve réutilisée"
 *      dropdown lists eligible source cuves from window.PF_CUVE_CANDIDATES.
 *      When a candidate is chosen: source-tank/qty/loss inputs are hidden for
 *      that row (no volume); client becomes required. Clearing restores normal
 *      cuv behaviour.  Reads: window.PF_CUVE_CANDIDATES.
 *
 * No framework. Vanilla ES2020.
 * Reads: window.PF_CANDIDATES, window.PF_CANDIDATES_OVERRIDE, window.PF_CAN_OVERRIDE,
 *        window.PF_PACKAGING_CLIENTS, window.RUN_TYPE_LABELS, window.FORMAT_SUFFIXES,
 *        window.MIN_DAYS_AFTER_RACKING, window.PF_RECIPE_SKUS,
 *        window.PF_RECIPE_UNASSIGNED, window.PF_CUVE_CANDIDATES,
 *        window.PF_TANK_READINGS
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
  const skuMosaic        = document.getElementById('pf-sku-mosaic');

  // Override (Choix Hors Process) — manager/admin only
  const overrideCheckbox   = document.getElementById('pf-override-checkbox');
  const overrideReasonRow  = document.getElementById('pf-override-reason-row');
  const horsProcessFld     = document.getElementById('hors_process');
  const tankGrid           = document.querySelector('.pf-tank-grid');

  // Réassigner cuve toggle + panel
  const reassignCheckbox    = document.getElementById('pf-reassign-checkbox');
  const reassignSessionFld  = document.getElementById('is_reassigner_session');
  const reassignPanel       = document.getElementById('pf-reassign-panel');
  const reassignLegsContainer = document.getElementById('pf-reassign-legs-container');
  const reassignAddLegBtn   = document.getElementById('pf-reassign-add-leg');
  // Elements hidden when réassigner mode is ON (normal form sections)
  const tankGridCard        = document.querySelector('.pf-tank-grid') && document.querySelector('.pf-tank-grid').closest('.op-form__card');

  // ── State ────────────────────────────────────────────────────────────────
  let selectedCard = null;       // currently-selected tank card element
  let isContractBeer = false;    // whether the selected lot has a contract beer
  let formatRows = [];           // array of row-index values rendered
  let isReassignerMode = false;  // whether the "Réassigner cuve" toggle is ON

  // ── SKU mosaic ────────────────────────────────────────────────────────────
  //
  // Renders activated format tiles for the recipe of the selected tank.
  // Tile click fills the MAIN format row (row 0): run_type select, suffix
  // select/field, and the hidden format_id input.
  // NULL-format SKUs are shown in an "à assigner" tray below the tiles.

  function renderSkuMosaic(recipeId) {
    if (!skuMosaic) return;
    skuMosaic.innerHTML = '';

    const rid  = parseInt(recipeId, 10) || 0;
    const skus = (window.PF_RECIPE_SKUS || {})[rid] || [];

    if (rid === 0 || skus.length === 0) {
      // No recipe or no activated SKUs — hide mosaic gracefully
      skuMosaic.hidden = true;
      return;
    }

    skuMosaic.hidden = false;

    var html = '<div class="pf-sku-mosaic__label">Formats activés — cliquer pour pré-remplir le format principal</div>' +
               '<div class="pf-sku-mosaic__grid">';

    skus.forEach(function (sku) {
      html +=
        '<button type="button" class="pf-sku-tile"' +
          ' data-run-type="' + escHtml(sku.run_type) + '"' +
          ' data-format-code="' + escHtml(sku.format_code) + '"' +
          ' data-format-id="' + escHtml(String(sku.format_id)) + '"' +
          ' data-sku-code="' + escHtml(sku.sku_code) + '">' +
          '<span class="pf-sku-tile__name">' + escHtml(sku.display_name) + '</span>' +
          '<span class="pf-sku-tile__code">' + escHtml(sku.sku_code) + '</span>' +
        '</button>';
    });

    html += '</div>';

    // "À assigner" tray
    var unassigned = (window.PF_RECIPE_UNASSIGNED || {})[rid] || [];
    if (unassigned.length > 0) {
      var codes = unassigned.map(function (u) { return escHtml(u.sku_code); }).join(', ');
      html +=
        '<div class="pf-sku-mosaic__tray">' +
          '<span class="pf-sku-mosaic__tray-label">À assigner (format_id manquant) :</span> ' +
          '<span class="pf-sku-mosaic__tray-codes">' + codes + '</span>' +
        '</div>';
    }

    skuMosaic.innerHTML = html;

    // Wire tile clicks
    skuMosaic.querySelectorAll('.pf-sku-tile').forEach(function (tile) {
      tile.addEventListener('click', function () {
        applySkuTile(tile);
      });
    });
  }

  function clearSkuMosaic() {
    if (!skuMosaic) return;
    skuMosaic.hidden = true;
    skuMosaic.innerHTML = '';
  }

  function applySkuTile(tile) {
    var runType    = tile.dataset.runType    || '';
    var formatCode = tile.dataset.formatCode || '';
    var formatId   = tile.dataset.formatId   || '';

    // Mark selected tile
    skuMosaic.querySelectorAll('.pf-sku-tile').forEach(function (t) {
      t.classList.remove('pf-sku-tile--selected');
    });
    tile.classList.add('pf-sku-tile--selected');

    // Target: main format row (row 0)
    var row0 = document.getElementById('pf-fmt-0');
    if (!row0) return;

    // Set run_type select
    var runTypeSelect = row0.querySelector('select[name="formats[0][run_type]"]');
    if (runTypeSelect) {
      runTypeSelect.value = runType;
      // Dispatch change so keg-section + per-row disposition groups update
      runTypeSelect.dispatchEvent(new Event('change'));
    }

    // Set format_suffix select — select existing option if present, otherwise add it
    var suffixSelect = row0.querySelector('select[name="formats[0][format_suffix]"]');
    if (suffixSelect) {
      var existing = Array.from(suffixSelect.options).find(function (o) { return o.value === formatCode; });
      if (!existing) {
        var opt = document.createElement('option');
        opt.value       = formatCode;
        opt.textContent = formatCode;
        suffixSelect.appendChild(opt);
      }
      suffixSelect.value = formatCode;
    }

    // Set hidden format_id
    var formatIdInput = row0.querySelector('input[name="formats[0][format_id]"]');
    if (formatIdInput) {
      formatIdInput.value = formatId;
    }
  }

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

    // Render SKU mosaic for this recipe
    renderSkuMosaic(recipeId);

    const beerDisplay  = nebBeer || cBeer || '?';
    const batchDisplay = nebBatch || cBatch || '?';

    selectedPanel.hidden = false;
    selectedSummary.textContent =
      tankType + ' ' + tankNum + ' — ' + beerDisplay + ' · Brassin ' + batchDisplay;

    // Track contract-beer state; drives per-row client block visibility
    isContractBeer = (cBeer !== '' && nebBeer === '');
    updateAllRowDispositionGroups();

    // Re-evaluate in-tank read state after tank selection
    updateTankReadState();
    updateTankReadingOverrideVisibility();

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
    updateAllRowDispositionGroups();
    clearSkuMosaic();
    // Clear in-tank read state on deselect
    clearTankReadInheritMode();
    updateTankReadingOverrideVisibility();
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

  // ── Disposition field definitions (run_type-aware) ───────────────────────
  //
  // bot/can/can33: beer-disposition fields (decrement vendable + affect tax)
  //               + material-scrap fields (stored only, never affect vendable HL)
  // keg/cuv:      keg-specific disposition fields only

  // Beer-disposition fields for bottle/can (subtract from vendable, affect tax/KPI)
  const BOTTLE_DISPOSITION_FIELDS = [
    { name: 'unsaleable_units',        label: 'Invendable',                    unit: 'unités' },
    { name: 'loss_uncapped_units',     label: 'Perte liquide sans capsule',    unit: 'unités' },
    { name: 'loss_untaxed_full_units', label: 'Unité perdue (pleine)',          unit: 'unités' },
    { name: 'loss_half_filled_units',  label: 'Perte liquide à moitié remplie', unit: 'unités (×0,5 vol)' },
    { name: 'qa_library_units',        label: 'Bibliothèque QA',               unit: 'unités' },
    { name: 'qa_analyses_units',       label: 'Mesures QA',                    unit: 'unités' },
  ];

  // Material-scrap fields for bottle/can (stored for material loss tally only)
  const BOTTLE_SCRAP_FIELDS = [
    { name: 'loss_4pack_btl_units',    label: 'Pertes 4-pack bouteille',  unit: 'unités' },
    { name: 'loss_4pack_can_units',    label: 'Pertes 4-pack canette',    unit: 'unités' },
    { name: 'loss_wrap_btl_units',     label: 'Pertes wraparound btl',    unit: 'unités' },
    { name: 'loss_wrap_can_units',     label: 'Pertes wraparound can',    unit: 'unités' },
    { name: 'loss_label_btl_units',    label: 'Pertes étiquette btl',     unit: 'unités' },
    { name: 'loss_crown_cork_units',   label: 'Pertes capsule couronne',  unit: 'unités' },
    { name: 'loss_can_lid_units',      label: 'Pertes couvercle canette', unit: 'unités' },
    { name: 'loss_container_btl_units',label: 'Pertes contenant btl',     unit: 'unités' },
    { name: 'loss_container_can_units',label: 'Pertes contenant can',     unit: 'unités' },
  ];

  // Disposition fields for keg/cuv.
  // loss_keg_liquid_l: canonical liquid-loss field for both keg and cuv (serving-tank).
  // For cuv, this is the field to use for burst-bag / liner-rupture losses in litres.
  // Label is overridden dynamically on run_type change in updateRowDispositionGroups():
  //   keg → 'Perte liquide fût'  |  cuv → 'Perte liquide / poche éclatée'
  const KEG_DISPOSITION_FIELDS = [
    { name: 'loss_keg_liquid_l', label: 'Perte liquide fût',               unit: 'L' },
    { name: 'taproom_keg_l',     label: 'Fût taproom',                     unit: 'L (taxé)' },
    { name: 'loss_keg_save_units',label: 'Perte capuchon fût',             unit: 'unités' },
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

    // Fields converted to multi-submit widget (SUM mode). qa_analyses_units is
    // excluded — it becomes auto-derived in a subsequent task and stays a plain input.
    const MSR_LOSS_FIELDS = new Set([
      'unsaleable_units', 'loss_uncapped_units', 'loss_untaxed_full_units',
      'loss_half_filled_units', 'qa_library_units',
      'loss_4pack_btl_units', 'loss_4pack_can_units', 'loss_wrap_btl_units',
      'loss_wrap_can_units', 'loss_label_btl_units', 'loss_crown_cork_units',
      'loss_can_lid_units', 'loss_container_btl_units', 'loss_container_can_units',
      'loss_keg_liquid_l', 'taproom_keg_l', 'loss_keg_save_units',
    ]);

    // Helper: build a single field div — widget mount for convertible fields, plain
    // input for qa_analyses_units and any non-convertible field.
    function fieldHtml(fieldDef, extraCss) {
      const fid = idPfx + '_' + fieldDef.name;
      const isDecimal = fieldDef.unit === 'L' || fieldDef.unit === 'L (taxé)' || fieldDef.unit === 'L / unités';

      if (MSR_LOSS_FIELDS.has(fieldDef.name)) {
        // Multi-submit widget mount — hidden output created by MultiSubmitReads.init().
        const mountId = idPfx + '_' + fieldDef.name + '_msr';
        return '<div class="op-form__field pf-loss-field--msr' + (extraCss ? ' ' + extraCss : '') + '">' +
          '<label class="op-form__label pf-loss-label">' + escHtml(fieldDef.label) +
            ' <span class="op-form__unit">' + escHtml(fieldDef.unit) + '</span></label>' +
          '<div class="pf-loss-msr" id="' + mountId + '"' +
            ' data-fmt-idx="' + idx + '"' +
            ' data-loss-name="' + escHtml(fieldDef.name) + '"' +
            ' data-decimals="' + (isDecimal ? '3' : '0') + '">' +
          '</div>' +
        '</div>';
      }

      // Plain input. qa_analyses_units is auto-derived (read-only + computed hint).
      var isQaAnalyses = (fieldDef.name === 'qa_analyses_units');
      return '<div class="op-form__field' + (extraCss ? ' ' + extraCss : '') + '">' +
        '<label class="op-form__label pf-loss-label' + (isQaAnalyses ? ' op-form__opt' : '') + '" for="' + fid + '">' +
          escHtml(fieldDef.label) +
          (isQaAnalyses ? ' <span class="op-form__unit op-form__unit--derived">(calculé)</span>' : ' <span class="op-form__unit">' + escHtml(fieldDef.unit) + '</span>') +
        '</label>' +
        '<input id="' + fid + '" name="' + prefix + '[' + fieldDef.name + ']"' +
          ' type="text" inputmode="' + (isDecimal ? 'decimal' : 'numeric') + '"' +
          ' class="op-form__input pf-loss-input' + (isQaAnalyses ? ' pf-loss-input--derived' : '') + '"' +
          ' placeholder="0"' +
          (isQaAnalyses ? ' readonly tabindex="-1"' : '') + '>' +
        '</div>';
    }

    // Bottle/can disposition section
    const bottleDispositionHtml =
      '<div class="pf-disposition-group pf-disposition-group--bottle" data-fmt-idx="' + idx + '">' +
        '<div class="op-form__grid--3 op-form__grid pf-disposition-grid">' +
          BOTTLE_DISPOSITION_FIELDS.map(function (f) { return fieldHtml(f, ''); }).join('') +
        '</div>' +
        '<details class="pf-losses-details">' +
          '<summary class="pf-losses-summary">Pertes matière <span class="pf-losses-summary__hint">(comptage emballages, non déduit du volume)</span></summary>' +
          '<div class="op-form__grid pf-losses-grid">' +
            BOTTLE_SCRAP_FIELDS.map(function (f) { return fieldHtml(f, 'pf-loss-field'); }).join('') +
          '</div>' +
        '</details>' +
      '</div>';

    // Keg/cuv disposition section
    const kegDispositionHtml =
      '<div class="pf-disposition-group pf-disposition-group--keg" data-fmt-idx="' + idx + '">' +
        '<div class="op-form__grid--3 op-form__grid pf-disposition-grid">' +
          KEG_DISPOSITION_FIELDS.map(function (f) { return fieldHtml(f, ''); }).join('') +
        '</div>' +
      '</div>';

    // ── Client sub-block (cuv ONLY) ──────────────────────────────────────────
    // Shown when: run_type='cuv' only.
    // Source: window.PF_PACKAGING_CLIENTS (ref_packaging_clients — venues/festivals).
    // Visibility is re-evaluated on run_type change via updateAllRowDispositionGroups().
    var clientOptions = '<option value="">— sélectionner —</option>';
    (window.PF_PACKAGING_CLIENTS || []).forEach(function (cl) {
      clientOptions += '<option value="' + escHtml(String(cl.id)) + '">' + escHtml(cl.name) + '</option>';
    });
    var noClientHint = (!window.PF_PACKAGING_CLIENTS || window.PF_PACKAGING_CLIENTS.length === 0)
      ? '<span class="op-form__hint pf-hint--warn">Aucun client dans ref_packaging_clients — ajouter via Réglages généraux.</span>'
      : '';

    const clientGroupHtml =
      '<div class="pf-disposition-group pf-disposition-group--client" data-fmt-idx="' + idx + '">' +
        '<div class="pf-client-title">— client</div>' +
        '<div class="op-form__grid--3 op-form__grid">' +
          '<div class="op-form__field">' +
            '<label class="op-form__label" for="' + idPfx + '_client_fk">Client livraison cuve</label>' +
            '<select id="' + idPfx + '_client_fk" name="' + prefix + '[client_fk]" class="op-form__select">' +
              clientOptions +
            '</select>' +
            noClientHint +
          '</div>' +
        '</div>' +
      '</div>';

    // ── Liner sub-block (cuv only) ────────────────────────────────────────────
    // Shown only when run_type='cuv'. Keg/bot/can rows never show liners.
    // Dropdowns built from window.PF_LINER_MIS (canonical ref_mi Liner subcategory);
    // first option is "— aucun —" (NULL, no liner). Default pre-selected from
    // window.PF_LINER_DEFAULT_MI (BOM slot liner_client default_mi_id_fk).
    var linerMis     = window.PF_LINER_MIS     || [];
    var linerDefault = window.PF_LINER_DEFAULT_MI || 0;

    function buildLinerOptions() {
      var opts = '<option value="">— aucun —</option>';
      linerMis.forEach(function (lm) {
        var sel = (linerDefault && lm.id === linerDefault) ? ' selected' : '';
        opts += '<option value="' + lm.id + '"' + sel + '>' + escHtml(lm.name) + '</option>';
      });
      return opts;
    }

    const linerGroupHtml =
      '<div class="pf-disposition-group pf-disposition-group--liner" data-fmt-idx="' + idx + '">' +
        '<div class="pf-liner-title">— liners cuve</div>' +
        '<div class="op-form__grid--3 op-form__grid">' +
          '<div class="op-form__field">' +
            '<label class="op-form__label" for="' + idPfx + '_liner_client_mi_id_fk">Liner client</label>' +
            '<select id="' + idPfx + '_liner_client_mi_id_fk" name="' + prefix + '[liner_client_mi_id_fk]" class="op-form__select">' +
              buildLinerOptions() +
            '</select>' +
          '</div>' +
          '<div class="op-form__field">' +
            '<label class="op-form__label" for="' + idPfx + '_liner_transport_mi_id_fk">Liner transport</label>' +
            '<select id="' + idPfx + '_liner_transport_mi_id_fk" name="' + prefix + '[liner_transport_mi_id_fk]" class="op-form__select">' +
              buildLinerOptions() +
            '</select>' +
          '</div>' +
        '</div>' +
      '</div>';

    // ── Cuve réutilisée sub-block (cuv only, mig 237) ────────────────────────
    // Optional: operator selects a source cuv row to re-allocate to a new client.
    // When a candidate is chosen: qty/loss/tank inputs for this row are hidden
    // (no new volume packaged); client becomes required.
    var cuveCandOptions = '<option value="">— nouvelle cuve (pas de réutilisation) —</option>';
    (window.PF_CUVE_CANDIDATES || []).forEach(function (cc) {
      // Display: "BEER Brassin BATCH — DD/MM/YYYY — Xu HL — Client"
      var dateStr = cc.event_date
        ? cc.event_date.replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$3/$2/$1')
        : '';
      var hlStr = cc.vendable_hl !== null && cc.vendable_hl !== undefined
        ? parseFloat(cc.vendable_hl).toFixed(1) + ' HL'
        : '';
      var clientStr = cc.client_label ? cc.client_label : '';
      var parts = [escHtml(cc.beer || '?'), 'B.' + escHtml(cc.batch || '?')];
      if (dateStr) parts.push(dateStr);
      if (hlStr)   parts.push(hlStr);
      if (clientStr) parts.push(clientStr);
      cuveCandOptions += '<option value="' + escHtml(String(cc.id)) + '">' + parts.join(' — ') + '</option>';
    });

    const reuseGroupHtml =
      '<div class="pf-disposition-group pf-disposition-group--reuse" data-fmt-idx="' + idx + '">' +
        '<div class="pf-reuse-title">— cuve réutilisée <span class="op-form__opt">(optionnel)</span></div>' +
        '<div class="op-form__grid--3 op-form__grid">' +
          '<div class="op-form__field op-form__field--span2">' +
            '<label class="op-form__label" for="' + idPfx + '_reuses_packaging_id_fk">' +
              'Réutilisation d\'une cuve existante' +
            '</label>' +
            '<select id="' + idPfx + '_reuses_packaging_id_fk"' +
              ' name="' + prefix + '[reuses_packaging_id_fk]"' +
              ' class="op-form__select pf-reuse-select" data-fmt-idx="' + idx + '">' +
              cuveCandOptions +
            '</select>' +
            '<span class="op-form__hint pf-reuse-hint">' +
              'Sélectionner pour transférer la propriété d\'une cuve déjà remplie à un nouveau client. ' +
              'Aucun nouveau volume n\'est conditionné — la cuve source garde son vendable_hl.' +
            '</span>' +
          '</div>' +
        '</div>' +
        // Warning banner shown when a reuse is selected
        '<div class="pf-reuse-active-banner" id="' + idPfx + '_reuse_banner" hidden>' +
          '<strong>Cuve réutilisée</strong> — Aucun volume conditionné. ' +
          'Sélectionner le nouveau client ci-dessous.' +
        '</div>' +
      '</div>';

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

    // objective_hl: per-run planning annotation (mig 313). Optional. Present on BOTH
    // main and parallel rows — each is its own bd_packaging_v2 row and carries its own
    // objective. NOT part of the natural key. NOT fiscal — recap read-only.
    const objectiveHtml =
      '<div class="op-form__field">' +
        '<label class="op-form__label" for="' + idPfx + '_objective_hl">' +
          'Objectif <span class="op-form__unit">(HL, optionnel)</span></label>' +
        '<input id="' + idPfx + '_objective_hl" name="' + prefix + '[objective_hl]"' +
          ' type="text" inputmode="decimal" class="op-form__input pf-objective-hl"' +
          ' placeholder="ex. 25.0" autocomplete="off">' +
      '</div>';

    const removeBtn = isMain
      ? ''
      : '<button type="button" class="pf-remove-format op-form__btn op-form__btn--danger-sm"' +
          ' data-idx="' + idx + '" title="Supprimer ce format">✕</button>';

    // ── Per-card white-label sub-block ────────────────────────────────────────
    // Always rendered (no run_type gate). Default: Non. When Oui, reveals
    // wl_units (additive WL quantity) and white_label_name.
    const wlGroupHtml =
      '<div class="pf-disposition-group pf-disposition-group--wl" data-fmt-idx="' + idx + '">' +
        '<div class="pf-wl-title">— white label <span class="op-form__opt">(optionnel)</span></div>' +
        '<div class="op-form__grid--3 op-form__grid">' +
          '<div class="op-form__field">' +
            '<label class="op-form__label" for="' + idPfx + '_is_white_label">White label ?</label>' +
            '<select id="' + idPfx + '_is_white_label"' +
              ' name="' + prefix + '[is_white_label]"' +
              ' class="op-form__select pf-wl-select" data-fmt-idx="' + idx + '">' +
              '<option value="0">Non</option>' +
              '<option value="1">Oui</option>' +
            '</select>' +
          '</div>' +
          '<div class="op-form__field pf-wl-units-field" id="' + idPfx + '_wl_units_field" hidden>' +
            '<label class="op-form__label" for="' + idPfx + '_wl_units">' +
              'Unités white label <span class="op-form__unit">(additif)</span></label>' +
            '<input id="' + idPfx + '_wl_units" name="' + prefix + '[wl_units]"' +
              ' type="text" inputmode="numeric" class="op-form__input" placeholder="0">' +
          '</div>' +
          '<div class="op-form__field pf-wl-name-field" id="' + idPfx + '_wl_name_field" hidden>' +
            '<label class="op-form__label" for="' + idPfx + '_wl_name">Nom white label</label>' +
            '<input id="' + idPfx + '_wl_name" name="' + prefix + '[white_label_name]"' +
              ' type="text" class="op-form__input" placeholder="ex. Monoprix Lager">' +
          '</div>' +
        '</div>' +
      '</div>';

    return '<div class="pf-format-row" id="pf-fmt-' + idx + '" data-idx="' + idx + '">' +
      '<div class="pf-format-row__header">' +
        '<span class="pf-format-row__badge pf-format-row__badge--' + origin + '">' +
          (isMain ? 'PRINCIPAL' : 'PARALLÈLE') + '</span>' +
        removeBtn +
      '</div>' +
      '<input type="hidden" name="' + prefix + '[row_origin]" value="' + origin + '">' +
      '<input type="hidden" name="' + prefix + '[format_id]" value="">' +
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
        objectiveHtml +
      '</div>' +
      bottleDispositionHtml +
      kegDispositionHtml +
      reuseGroupHtml +
      clientGroupHtml +
      linerGroupHtml +
      wlGroupHtml +
    '</div>';
  }

  // Returns true when the given cuv row has a reuse source selected.
  function rowIsReuse(rowEl) {
    if (!rowEl) return false;
    const sel = rowEl.querySelector('.pf-reuse-select');
    return sel ? (sel.value !== '' && sel.value !== '0') : false;
  }

  function updateRowDispositionGroups(rowEl) {
    if (!rowEl) return;
    const sel     = rowEl.querySelector('.pf-run-type-select');
    const runType = sel ? sel.value : '';
    const isKegOrCuv = (runType === 'keg' || runType === 'cuv');
    const isCuv      = (runType === 'cuv');
    const isReuse    = isCuv && rowIsReuse(rowEl);

    const bottleGroup = rowEl.querySelector('.pf-disposition-group--bottle');
    const kegGroup    = rowEl.querySelector('.pf-disposition-group--keg');
    // Client block: cuv only
    const clientGroup = rowEl.querySelector('.pf-disposition-group--client');
    // Liner block: cuv only
    const linerGroup  = rowEl.querySelector('.pf-disposition-group--liner');
    // Reuse block: cuv only
    const reuseGroup  = rowEl.querySelector('.pf-disposition-group--reuse');
    // Reuse banner
    const reuseIdPfx  = rowEl.id ? rowEl.id.replace('pf-fmt-', 'fmt') : '';
    const reuseBanner = reuseIdPfx ? document.getElementById(reuseIdPfx + '_reuse_banner') : null;

    // Group visibility:
    //   bottle group: shown for bot/can/can33 only
    //   keg group:    shown for keg/cuv, but HIDDEN for cuv-reuse rows (no volume to enter)
    //   client group: shown for cuv ONLY (mig 237 repoint — no longer contract/WL)
    //   liner group:  shown for cuv only
    //   reuse group:  PERMANENTLY HIDDEN for cuv — the new "Réassigner cuve" toggle is the
    //                 sole path for cuv reassignment. The mig-237 inline sub-block is retired
    //                 for cuv runs. The schema column (reuses_packaging_id_fk) and server-side
    //                 handling are preserved for non-cuv backward compatibility.
    if (bottleGroup) bottleGroup.hidden = isKegOrCuv;
    if (kegGroup)    kegGroup.hidden    = !isKegOrCuv || isReuse;
    if (clientGroup) clientGroup.hidden = !isCuv;
    if (linerGroup)  linerGroup.hidden  = !isCuv;
    if (reuseGroup)  reuseGroup.hidden  = true;  // always hidden — retired for cuv (see above)

    // Hide qty input for reuse rows (no volume packaged)
    const qtiesSection = rowEl.querySelector('.pf-format-top-grid');
    if (qtiesSection) {
      // The prod_total or qte_unites field is the 3rd child of the top grid
      const qtyField = qtiesSection.querySelector('[name*="prod_total_units"],[name*="qte_unites"]');
      if (qtyField) {
        const qtyWrapper = qtyField.closest('.op-form__field');
        if (qtyWrapper) qtyWrapper.hidden = isReuse;
      }
    }

    // Reuse banner
    if (reuseBanner) reuseBanner.hidden = !isReuse;

    // Update loss_keg_liquid_l label: "poche éclatée" is cuv-specific; keg uses the
    // plain "fût" term. The MSR-widget label has no `for` — find it via the mount div.
    if (kegGroup) {
      var lossKegMountEl = kegGroup.querySelector('[id$="_loss_keg_liquid_l_msr"]');
      if (lossKegMountEl) {
        var lossKegLabelEl = lossKegMountEl.closest('.op-form__field')
          ? lossKegMountEl.closest('.op-form__field').querySelector('.op-form__label') : null;
        if (lossKegLabelEl) {
          // Preserve the <span class="op-form__unit"> child — only replace the text node.
          var unitSpan = lossKegLabelEl.querySelector('.op-form__unit');
          lossKegLabelEl.childNodes.forEach(function (n) {
            if (n.nodeType === Node.TEXT_NODE) n.nodeValue = isCuv
              ? 'Perte liquide / poche éclatée '
              : 'Perte liquide fût ';
          });
          // If there is no text node (first render edge-case), prepend one.
          if (!lossKegLabelEl.firstChild || lossKegLabelEl.firstChild === unitSpan) {
            lossKegLabelEl.insertBefore(
              document.createTextNode(isCuv ? 'Perte liquide / poche éclatée ' : 'Perte liquide fût '),
              unitSpan
            );
          }
        }
      }
    }
  }

  // Re-evaluate all rendered rows when session-level state changes (tank select/deselect/WL toggle).
  function updateAllRowDispositionGroups() {
    if (!formatsContainer) return;
    formatsContainer.querySelectorAll('.pf-format-row').forEach(function (rowEl) {
      updateRowDispositionGroups(rowEl);
    });
  }

  // syncQaAnalysesDisplay — mirrors the server's $co2o2Pairs count to the UI.
  // Main format row: qa_analyses_units = number of in-filling reads with co2 OR o2 filled.
  // All non-main rows: qa_analyses_units = 0.
  // The field is readonly; this only updates its display value.
  function syncQaAnalysesDisplay() {
    // Count non-blank in-filling read rows (mirror server's blank-skip: co2 OR o2 non-empty).
    var co2o2Count = 0;
    var msrEl = document.getElementById('pf-co2o2-msr');
    if (msrEl) {
      // Inputs are named co2o2[N][co2] and co2o2[N][o2].
      // Group by index N; count indices that have at least one non-empty value.
      var co2Inputs = form.querySelectorAll('input[name^="co2o2["]');
      var seenIndices = {};
      co2Inputs.forEach(function (inp) {
        // name format: co2o2[N][co2] or co2o2[N][o2]
        var m = inp.name.match(/^co2o2\[(\d+)\]/);
        if (!m) return;
        var idx = m[1];
        if (inp.value.trim() !== '') seenIndices[idx] = true;
      });
      co2o2Count = Object.keys(seenIndices).length;
    }

    // Find all format rows and update their qa_analyses_units inputs.
    formatsContainer.querySelectorAll('.pf-format-row').forEach(function (rowEl) {
      // Identify main row via hidden row_origin input.
      var originInput = rowEl.querySelector('input[name$="[row_origin]"]');
      var isMainRow = originInput && originInput.value === 'main';
      var qaInput = rowEl.querySelector('input[name$="[qa_analyses_units]"]');
      if (qaInput) {
        qaInput.value = isMainRow ? String(co2o2Count) : '0';
      }
    });
  }

  function addFormatRow(isMain, _editValues) {
    const idx = formatRows.length;
    formatRows.push(idx);
    // _editValues: optional map of fieldName→value used during edit hydration to
    // seed widget initialRows before init (avoids a post-init re-seed race).
    const editValues = _editValues || {};

    formatsContainer.insertAdjacentHTML('beforeend', buildFormatRow(idx, isMain));

    // Wire run_type→keg section visibility + per-row disposition groups + remove btn
    const rowEl = document.getElementById('pf-fmt-' + idx);
    if (rowEl) {
      const sel = rowEl.querySelector('.pf-run-type-select');
      if (sel) {
        sel.addEventListener('change', function () {
          updateRowDispositionGroups(rowEl);
        });
      }

      // Wire reuse-select change → update disposition groups + update in-tank exempt state
      const reuseSelect = rowEl.querySelector('.pf-reuse-select');
      if (reuseSelect) {
        reuseSelect.addEventListener('change', function () {
          updateRowDispositionGroups(rowEl);
          // Row 0 (main) cuv reuse toggles the in-tank read exempt condition
          if (idx === 0) updateTankReadState();
        });
      }

      const removeBtn = rowEl.querySelector('.pf-remove-format');
      if (removeBtn) {
        removeBtn.addEventListener('click', function () {
          removeFormatRow(idx);
        });
      }

      // Init MultiSubmitReads widgets for all .pf-loss-msr mounts in this row.
      // ALL mounts are inited regardless of which disposition group is currently
      // visible — hidden groups still need their hidden output fields so they post
      // an empty string (= server null) identical to today's plain-input behaviour.
      if (typeof MultiSubmitReads !== 'undefined') {
        rowEl.querySelectorAll('.pf-loss-msr').forEach(function (mount) {
          const lossName  = mount.dataset.lossName;
          const decimals  = parseInt(mount.dataset.decimals, 10) || 0;
          const editVal   = editValues[lossName];
          const initRows  = (editVal !== null && editVal !== undefined && editVal !== '')
                              ? [[String(editVal)]] : [];

          const inst = MultiSubmitReads.init({
            mountId:     mount.id,
            mode:        'sum',
            outputName:  'formats[' + idx + '][' + lossName + ']',
            decimals:    decimals,
            minRows:     1,
            maxRows:     30,
            fields:      [{ key: 'v', placeholder: '0', step: decimals ? '0.001' : '1' }],
            initialRows: initRows,
          });
          // Store instance on the mount element for potential future use.
          mount._msr = inst;
        });
      }

      // Per-card WL toggle: show wl_units + white_label_name when Oui selected.
      var wlSel = rowEl.querySelector('.pf-wl-select');
      if (wlSel) {
        var updateWlSubfields = function (sel) {
          var isWl = (sel.value === '1');
          var unitsField = document.getElementById(
            'fmt' + sel.dataset.fmtIdx + '_wl_units_field'
          );
          var nameField = document.getElementById(
            'fmt' + sel.dataset.fmtIdx + '_wl_name_field'
          );
          if (unitsField) unitsField.hidden = !isWl;
          if (nameField)  nameField.hidden  = !isWl;
        };
        wlSel.addEventListener('change', function () { updateWlSubfields(wlSel); });
        updateWlSubfields(wlSel);
      }

      // Set initial state (no run_type selected → show bottle group by default)
      updateRowDispositionGroups(rowEl);
    }
    tryEnableSubmit();
    syncQaAnalysesDisplay();
  }

  function removeFormatRow(idx) {
    const el = document.getElementById('pf-fmt-' + idx);
    if (el) el.remove();
    formatRows = formatRows.filter(function (i) { return i !== idx; });
    tryEnableSubmit();
    syncQaAnalysesDisplay();
  }

  if (addFormatBtn) {
    addFormatBtn.addEventListener('click', function () {
      addFormatRow(false);  // 'parallel'
    });
  }

  // Seed the main format row on load
  addFormatRow(true);

  // ── Enable submit ─────────────────────────────────────────────────────────
  function tryEnableSubmit() {
    if (isReassignerMode) {
      // Réassigner mode: submit is enabled when at least one leg has a source and a client chosen.
      var hasValidLeg = false;
      if (reassignLegsContainer) {
        reassignLegsContainer.querySelectorAll('.pf-reassign-leg').forEach(function (leg) {
          var srcSel    = leg.querySelector('.pf-reassign-source-select');
          var clientSel = leg.querySelector('.pf-reassign-client-select');
          if (srcSel && clientSel && srcSel.value !== '' && clientSel.value !== '') {
            hasValidLeg = true;
          }
        });
      }
      submitBtn.disabled = !hasValidLeg;
    } else {
      const hasTank    = (fldTankType.value !== '');
      const hasFormat  = formatRows.length > 0;
      submitBtn.disabled = !(hasTank && hasFormat);
    }
  }

  // ── Réassigner cuve ───────────────────────────────────────────────────────
  //
  // Build a source-tank picker + new-client + new-liner per reassignment leg.
  // In réassigner mode: normal form sections are hidden; the réassigner panel
  // replaces the tank-grid interaction. Legs are posted as formats[N][...] with:
  //   formats[N][run_type] = 'cuv'
  //   formats[N][row_origin] = 'main' (first leg) or 'parallel' (subsequent)
  //   formats[N][reassign_source_id] = source bd_packaging_v2 id
  //   formats[N][client_fk] = new client ref_packaging_clients id
  //   formats[N][liner_client_mi_id_fk] = new liner MI id
  //
  // The inline "Cuve réutilisée" sub-block (old path, mig 237) is RETIRED for
  // cuv rows when réassigner mode is active. The new bypass mode is the single
  // path for cuv reassignment. The sub-block HTML is still rendered by
  // buildFormatRow() for backward-compatibility (non-cuv rows, edit-mode reuse
  // of the schema column) but is hidden via CSS in réassigner mode and suppressed
  // in updateRowDispositionGroups() when this mode is on.
  //
  // Note: the old sub-block's posted reuses_packaging_id_fk is still handled
  // server-side (mig 237 legacy path) for sessions NOT in réassigner mode.

  var reassignLegCount = 0;  // tracks legs added in the current réassigner session

  function buildReassignLinerOptions() {
    // Builds liner <option> list for réassigner leg new-liner picker.
    // Source: window.PF_LINER_MIS (same as the normal cuv liner dropdown).
    var html = '<option value="">— aucun liner —</option>';
    (window.PF_LINER_MIS || []).forEach(function (mi) {
      html += '<option value="' + escHtml(String(mi.id)) + '">'
             + escHtml(mi.name || mi.mi_id) + '</option>';
    });
    return html;
  }

  function buildClientOptions() {
    var html = '<option value="">— sélectionner un client —</option>';
    (window.PF_PACKAGING_CLIENTS || []).forEach(function (cl) {
      html += '<option value="' + escHtml(String(cl.id)) + '">'
             + escHtml(cl.name) + '</option>';
    });
    return html;
  }

  function buildReassignSourceOptions() {
    var html = '<option value="">— sélectionner une cuve —</option>';
    (window.PF_REASSIGNER_CANDIDATES || []).forEach(function (rc) {
      var dateStr = rc.event_date
        ? rc.event_date.replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$3/$2/$1')
        : '';
      var hlStr = rc.vendable_hl !== null && rc.vendable_hl !== undefined
        ? parseFloat(rc.vendable_hl).toFixed(1) + ' HL'
        : '';
      var clientStr = rc.client_label ? rc.client_label : '';
      var parts = [escHtml(rc.beer || '?'), 'B.' + escHtml(rc.batch || '?')];
      if (dateStr) parts.push(dateStr);
      if (hlStr)   parts.push(hlStr);
      if (clientStr) parts.push(clientStr);
      html += '<option value="' + escHtml(String(rc.id)) + '">'
             + parts.join(' — ') + '</option>';
    });
    return html;
  }

  function addReassignLeg() {
    var legIdx = reassignLegCount;
    reassignLegCount++;
    var isMain = (legIdx === 0);
    var fmtIdx = formatRows.length;
    formatRows.push(fmtIdx);

    var prefix  = 'formats[' + fmtIdx + ']';
    var idPfx   = 'rasleg_' + fmtIdx;

    var html =
      '<div class="pf-reassign-leg" id="pf-rasleg-' + fmtIdx + '" data-fmt-idx="' + fmtIdx + '">' +
        '<div class="pf-reassign-leg__header">' +
          '<span class="pf-reassign-leg__badge">' + (isMain ? 'Cuve principale' : 'Cuve additionnelle') + '</span>' +
          (!isMain
            ? '<button type="button" class="pf-reassign-leg__remove op-form__btn op-form__btn--danger-sm"' +
                ' data-fmt-idx="' + fmtIdx + '" title="Supprimer cette cuve">✕</button>'
            : '') +
        '</div>' +
        // Hidden fields carrying the leg's identity to the server.
        '<input type="hidden" name="' + prefix + '[run_type]" value="cuv">' +
        '<input type="hidden" name="' + prefix + '[row_origin]" value="' + (isMain ? 'main' : 'parallel') + '">' +
        '<div class="op-form__grid--3 op-form__grid">' +
          // (a) Source tank picker
          '<div class="op-form__field op-form__field--span3">' +
            '<label class="op-form__label" for="' + idPfx + '_source">Cuve source</label>' +
            '<select id="' + idPfx + '_source" name="' + prefix + '[reassign_source_id]"' +
              ' class="op-form__select pf-reassign-source-select" data-fmt-idx="' + fmtIdx + '">' +
              buildReassignSourceOptions() +
            '</select>' +
            '<div class="pf-reassign-source-info" id="' + idPfx + '_sourceinfo"></div>' +
          '</div>' +
          // (b) New client picker
          '<div class="op-form__field op-form__field--span2">' +
            '<label class="op-form__label" for="' + idPfx + '_client">Nouveau client</label>' +
            '<select id="' + idPfx + '_client" name="' + prefix + '[client_fk]"' +
              ' class="op-form__select pf-reassign-client-select">' +
              buildClientOptions() +
            '</select>' +
          '</div>' +
          // (c) New liner picker
          '<div class="op-form__field">' +
            '<label class="op-form__label" for="' + idPfx + '_liner">Liner client <span class="op-form__opt">(nouveau)</span></label>' +
            '<select id="' + idPfx + '_liner" name="' + prefix + '[liner_client_mi_id_fk]"' +
              ' class="op-form__select">' +
              buildReassignLinerOptions() +
            '</select>' +
          '</div>' +
        '</div>' +
      '</div>';

    if (reassignLegsContainer) {
      reassignLegsContainer.insertAdjacentHTML('beforeend', html);
    }

    // Wire the remove button (parallel legs only).
    var legEl = document.getElementById('pf-rasleg-' + fmtIdx);
    if (legEl && !isMain) {
      var removeBtn = legEl.querySelector('.pf-reassign-leg__remove');
      if (removeBtn) {
        removeBtn.addEventListener('click', function () {
          removeReassignLeg(fmtIdx);
        });
      }
    }

    // Wire source-select change → show info (beer / batch / current client / vendable HL).
    if (legEl) {
      var srcSel = legEl.querySelector('.pf-reassign-source-select');
      var infoEl = document.getElementById(idPfx + '_sourceinfo');
      if (srcSel && infoEl) {
        srcSel.addEventListener('change', function () {
          var chosenId = parseInt(srcSel.value, 10) || 0;
          var cand = (window.PF_REASSIGNER_CANDIDATES || []).find(function (rc) {
            return rc.id === chosenId;
          });
          if (cand) {
            var dateStr = cand.event_date
              ? cand.event_date.replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$3/$2/$1')
              : '';
            var hlStr = cand.vendable_hl !== null && cand.vendable_hl !== undefined
              ? parseFloat(cand.vendable_hl).toFixed(1) + ' HL' : '';
            infoEl.innerHTML =
              '<span class="pf-reassign-source-info__beer">' + escHtml(cand.beer || '?') + '</span>' +
              ' — Brassin <strong>' + escHtml(cand.batch || '?') + '</strong>' +
              (dateStr ? ' — ' + escHtml(dateStr) : '') +
              (hlStr   ? ' — <strong>' + escHtml(hlStr) + '</strong>' : '') +
              (cand.client_label ? ' — client actuel : ' + escHtml(cand.client_label) : '');
          } else {
            infoEl.innerHTML = '';
          }
          tryEnableSubmit();
        });
      }
      // Wire client-select change → re-check submit readiness.
      var clientSel = legEl.querySelector('.pf-reassign-client-select');
      if (clientSel) {
        clientSel.addEventListener('change', function () {
          tryEnableSubmit();
        });
      }
    }

    tryEnableSubmit();
  }

  function removeReassignLeg(fmtIdx) {
    var el = document.getElementById('pf-rasleg-' + fmtIdx);
    if (el) el.remove();
    formatRows = formatRows.filter(function (i) { return i !== fmtIdx; });
    tryEnableSubmit();
  }

  function toggleReassignerMode(on) {
    isReassignerMode = on;
    if (reassignSessionFld) reassignSessionFld.value = on ? '1' : '0';

    // Sections to hide when réassigner mode is ON.
    // Tank grid card (the whole card containing the tank selector).
    // We can't hide the whole card because the toggle itself lives in it.
    // Instead hide: the tank grid, the selected-tank summary, the empty-msg.
    var tankGridEl    = document.querySelector('.pf-tank-grid');
    var tankSelectedEl = document.getElementById('pf-selected-tank');
    var emptyMsgEl    = document.getElementById('pf-candidates-empty-msg');
    var skuMosaicEl   = document.getElementById('pf-sku-mosaic');
    // Cards hidden entirely: all cards after the first (tank card).
    // Target: tank-read card, CO2/O2 in-filling card, formats card.
    var tankReadCard   = document.getElementById('pf-tank-read-card');
    var formatsCard    = document.getElementById('pf-formats-card');
    var co2o2Card      = document.getElementById('pf-co2o2-card');

    if (on) {
      // Hide normal tank selection UI.
      if (tankGridEl)     tankGridEl.hidden     = true;
      if (tankSelectedEl) tankSelectedEl.hidden = true;
      if (emptyMsgEl)     emptyMsgEl.hidden     = true;
      if (skuMosaicEl)    skuMosaicEl.hidden     = true;
      // Hide the main form sections not applicable to réassignment.
      if (tankReadCard)   tankReadCard.hidden   = true;
      if (formatsCard)    formatsCard.hidden    = true;
      if (co2o2Card)      co2o2Card.hidden      = true;
      // Show the réassigner panel.
      if (reassignPanel)  reassignPanel.hidden  = false;
      if (reassignAddLegBtn) reassignAddLegBtn.style.display = '';

      // Clear any existing normal tank selection.
      deselectTank();

      // Neutralise the normal format leg(s): hiding the formats card is NOT
      // enough — the seeded normal row's hidden formats[0][row_origin]='main'
      // input still posts and collides with the reassign leg's 'main', tripping
      // the PHP "Exactement un format principal (main) requis" guard. Remove the
      // normal format rows entirely while in réassigner mode (re-seeded on exit).
      if (formatsContainer) formatsContainer.innerHTML = '';
      formatRows = [];

      // Seed the first leg if none yet.
      if (reassignLegCount === 0) {
        addReassignLeg();
      }

      // Show the réassigner active banner.
      var existingBanner = document.getElementById('pf-reassign-active-banner');
      if (!existingBanner && reassignPanel) {
        var banner = document.createElement('div');
        banner.id        = 'pf-reassign-active-banner';
        banner.className = 'pf-reassign-active-banner';
        banner.innerHTML =
          '<strong>Réassignation active</strong> — Aucun volume prélevé depuis le BBT/CCT. ' +
          'Chaque cuve sélectionnée ci-dessous sera réattribuée au nouveau client choisi.';
        reassignPanel.parentNode.insertBefore(banner, reassignPanel);
      }
    } else {
      // Restore normal UI.
      if (tankGridEl)     tankGridEl.hidden     = false;
      if (tankSelectedEl) tankSelectedEl.hidden  = true;  // keeps hidden until a tank is selected
      if (emptyMsgEl)     emptyMsgEl.hidden     = false;
      if (tankReadCard)   tankReadCard.hidden   = false;
      if (formatsCard)    formatsCard.hidden    = false;
      if (co2o2Card)      co2o2Card.hidden      = false;
      if (reassignPanel)  reassignPanel.hidden  = true;
      if (reassignAddLegBtn) reassignAddLegBtn.style.display = 'none';

      // Clear réassigner legs + reset counter.
      if (reassignLegsContainer) reassignLegsContainer.innerHTML = '';
      reassignLegCount = 0;
      formatRows = [];

      // Remove the réassigner active banner.
      var b = document.getElementById('pf-reassign-active-banner');
      if (b) b.remove();

      // Re-seed the main format row.
      addFormatRow(true);
      tryEnableSubmit();
    }
  }

  // Wire the réassigner toggle checkbox.
  if (reassignCheckbox) {
    reassignCheckbox.addEventListener('change', function () {
      toggleReassignerMode(reassignCheckbox.checked);
    });
  }

  // Wire the "Ajouter une deuxième cuve" button in the réassigner panel.
  if (reassignAddLegBtn) {
    reassignAddLegBtn.addEventListener('click', function () {
      addReassignLeg();
    });
    // Initially hidden (JS shows it in réassigner mode via toggleReassignerMode).
    reassignAddLegBtn.style.display = 'none';
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

    // PF-03: announce grid changes via the separate sr-only status element —
    // a summary string, never the full card content (an aria-live grid would
    // spam screen readers with every card's text on each rebuild).
    var gridStatus = document.getElementById('pf-tank-grid-status');

    if (!candidates || candidates.length === 0) {
      tankGrid.innerHTML = '<p class="op-form__muted" style="font-size:0.82rem;">Aucun lot disponible.</p>';
      if (gridStatus) gridStatus.textContent = 'Aucun lot disponible.';
      return;
    }
    if (gridStatus) {
      gridStatus.textContent = candidates.length + (candidates.length > 1 ? ' lots disponibles.' : ' lot disponible.');
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
            '(délai minimum bypassé). La saisie sera marquée comme saisie hors-process.';
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

  // ── CO₂/O₂ in-filling session measurements (up to 20 pairs) ─────────────
  //
  // POST shape: co2o2[N][co2], co2o2[N][o2]   (contiguous 0-based, serialize mode)
  // A row is present when co2 OR o2 is non-empty; fully-blank rows are skipped
  // server-side. QC is server-only (no client-side threshold display).
  // Isolated from the formats[N] repeater — do NOT mix these indices.
  //
  // Edit-mode: PF_EDIT_STICKY_FILLING is mapped to initialRows [[co2, o2], ...].
  // If absent or empty, minRows=3 blank rows are seeded.

  if (document.getElementById('pf-co2o2-msr') && typeof MultiSubmitReads !== 'undefined') {
    var fillingInitialRows = [];
    var stickyFillingData = window.PF_EDIT_STICKY_FILLING || [];
    if (stickyFillingData.length > 0) {
      fillingInitialRows = stickyFillingData.map(function (pair) {
        return [
          (pair.co2 !== null && pair.co2 !== undefined) ? String(pair.co2) : '',
          (pair.o2  !== null && pair.o2  !== undefined) ? String(pair.o2)  : '',
        ];
      });
    }
    MultiSubmitReads.init({
      mountId:     'pf-co2o2-msr',
      mode:        'serialize',
      arrayName:   'co2o2',
      fields: [
        { key: 'co2', label: 'CO₂', unit: 'g/L',  placeholder: 'ex. 4.2', step: '0.001' },
        { key: 'o2',  label: 'O₂',  unit: 'ppb',  placeholder: 'ex. 18',  step: '0.001' },
      ],
      minRows:     3,
      maxRows:     20,
      initialRows: fillingInitialRows.length > 0 ? fillingInitialRows : undefined,
      // Keep qa_analyses_units display in sync as the operator adds/removes/edits reads.
      onChange:    function () { syncQaAnalysesDisplay(); },
    });
    // Belt-and-suspenders: delegated listener catches any value change inside
    // the mount (including draft-restore values set without change events).
    var co2o2Mount = document.getElementById('pf-co2o2-msr');
    if (co2o2Mount) {
      co2o2Mount.addEventListener('input', function () { syncQaAnalysesDisplay(); });
    }
  }

  // ── In-tank CO₂/O₂ single-pair auto-fill ────────────────────────────────
  //
  // When the operator selects a tank + date matching an existing bd_tank_readings
  // row (same lot-day), the in-tank inputs are auto-filled and made read-only
  // with an inherit banner. The override block is hidden in this state.
  //
  // When no match → inputs are editable + required; show override block (admin/manager).
  // Reused-cuve: if the main format row is a cuv reuse, in-tank inputs are optional
  // (no tank was freshly filled). JS detects this from .pf-reuse-select value.
  //
  // Resolution triggered by: tank card click, tank deselect, date change.
  // Neb lane: recipe_id_fk + neb_batch + event_date.
  // Contract lane (recipe_id_fk falsy): contract_beer + contract_batch + event_date.

  var tankReadEventDateFld = document.getElementById('event_date');
  var tankReadInheritBanner = document.getElementById('pf-tank-read-inherit-banner');
  var tankCo2Input  = document.getElementById('pf-tank-co2');
  var tankO2Input   = document.getElementById('pf-tank-o2');
  var tankReadInheritMode = false;  // true = existing lot-day read found + displayed

  function resolveTankRead(recipeId, nebBatchVal, cBeer, cBatch, eventDateVal) {
    var readings = window.PF_TANK_READINGS || [];
    var rId = recipeId ? parseInt(recipeId, 10) : null;
    for (var i = 0; i < readings.length; i++) {
      var r = readings[i];
      if (r.read_date !== eventDateVal) continue;
      if (rId !== null) {
        // Neb lane: recipe_id_fk + neb_batch
        if (r.recipe_id_fk === rId && r.neb_batch === nebBatchVal) return r;
      } else {
        // Contract lane
        if (r.recipe_id_fk === null
            && (r.contract_beer  || null) === (cBeer  || null)
            && (r.contract_batch || null) === (cBatch || null)) return r;
      }
    }
    return null;
  }

  function setTankReadInheritMode(reading) {
    tankReadInheritMode = true;
    if (tankCo2Input) {
      tankCo2Input.value    = reading.co2_gl !== null ? String(reading.co2_gl) : '';
      tankCo2Input.readOnly = true;
      tankCo2Input.classList.add('pf-tank-read-input--readonly');
    }
    if (tankO2Input) {
      tankO2Input.value    = reading.o2_ppb !== null ? String(reading.o2_ppb) : '';
      tankO2Input.readOnly = true;
      tankO2Input.classList.add('pf-tank-read-input--readonly');
    }
    if (tankReadInheritBanner) tankReadInheritBanner.hidden = false;
  }

  function clearTankReadInheritMode() {
    tankReadInheritMode = false;
    if (tankCo2Input) {
      tankCo2Input.value    = '';
      tankCo2Input.readOnly = false;
      tankCo2Input.classList.remove('pf-tank-read-input--readonly');
    }
    if (tankO2Input) {
      tankO2Input.value    = '';
      tankO2Input.readOnly = false;
      tankO2Input.classList.remove('pf-tank-read-input--readonly');
    }
    if (tankReadInheritBanner) tankReadInheritBanner.hidden = true;
  }

  function isReuseSessionActive() {
    // Check if the main format row (row 0) has a cuv reuse selected.
    var row0 = document.getElementById('pf-fmt-0');
    if (!row0) return false;
    return rowIsReuse(row0);
  }

  function updateTankReadState() {
    var recipeId     = fldRecipeId    ? fldRecipeId.value    : '';
    var nebBatchVal  = fldNebBatch    ? fldNebBatch.value    : '';
    var cBeer        = fldContractBeer  ? fldContractBeer.value  : '';
    var cBatch       = fldContractBatch ? fldContractBatch.value : '';
    var eventDateVal = tankReadEventDateFld ? tankReadEventDateFld.value : '';

    if (!fldTankType || fldTankType.value === '') {
      clearTankReadInheritMode();
      updateTankReadingOverrideVisibility();
      return;
    }

    // Reused-cuve: in-tank not required
    if (isReuseSessionActive()) {
      clearTankReadInheritMode();
      if (tankCo2Input) tankCo2Input.removeAttribute('required');
      if (tankO2Input)  tankO2Input.removeAttribute('required');
      updateTankReadingOverrideVisibility();
      return;
    }

    var existing = resolveTankRead(recipeId || null, nebBatchVal, cBeer, cBatch, eventDateVal);

    if (existing) {
      setTankReadInheritMode(existing);
      // Inherited → not required (values already locked-in)
      if (tankCo2Input) tankCo2Input.removeAttribute('required');
      if (tankO2Input)  tankO2Input.removeAttribute('required');
    } else {
      clearTankReadInheritMode();
      // First entry of lot-day → make required
      if (tankCo2Input) tankCo2Input.setAttribute('required', 'required');
      if (tankO2Input)  tankO2Input.setAttribute('required', 'required');
    }
    updateTankReadingOverrideVisibility();
  }

  // Wire date change → re-evaluate in-tank read
  if (tankReadEventDateFld) {
    tankReadEventDateFld.addEventListener('change', updateTankReadState);
  }

  // Also re-evaluate when the main cuv reuse-select changes (exempt condition)
  // Wire happens inside addFormatRow (row 0) via the reuseSelect change listener —
  // call updateTankReadState from there.

  // Wire the tank-reading override checkbox show/hide (manager/admin only)
  var tankReadingOverrideCb        = document.getElementById('pf-tank-reading-override-checkbox');
  var tankReadingOverrideBlock     = document.getElementById('pf-co2o2-override-block');
  var tankReadingOverrideReasonRow = document.getElementById('pf-tank-reading-override-reason-row');

  if (tankReadingOverrideCb) {
    tankReadingOverrideCb.addEventListener('change', function () {
      if (tankReadingOverrideReasonRow) {
        tankReadingOverrideReasonRow.hidden = !tankReadingOverrideCb.checked;
      }
    });
  }

  // Show override block only when: tank selected, NOT inherit mode, NOT reuse-exempt.
  function updateTankReadingOverrideVisibility() {
    if (!tankReadingOverrideBlock) return;
    var tankSelected = fldTankType && fldTankType.value !== '';
    var isGateCase   = tankSelected && !tankReadInheritMode && !isReuseSessionActive();
    tankReadingOverrideBlock.hidden = !isGateCase;
  }

  // ── CO₂/O₂ inversion guard on submit ─────────────────────────────────────
  //
  // If a pair looks inverted (swapped values produce a lower severity than
  // as-entered), show a confirm dialog before submission.  The operator can
  // still override — we never hard-block (mirrors server QC: import-then-flag).
  //
  // Iterates inputs by name pattern co2o2[N][co2|o2] (MSR serialize output),
  // grouped by the [N] index.  Decoupled from old bespoke DOM classes.

  var co2o2InversionConfirmed = false;

  // QC thresholds — mirrors db-write-helpers.php bd_qc_flag exactly.
  // Severity: 0=normal, 1=elevated, 2=outlier.
  function co2Flag(v) {
    if (v < 2.5 || v > 6.0) return 2;   // outlier
    if (v < 3.5 || v > 5.0) return 1;   // elevated
    return 0;                             // normal
  }

  function o2Flag(v) {
    if (v > 200) return 2;               // outlier
    if (v >= 50) return 1;               // elevated
    return 0;                             // normal
  }

  // ── Production-volume guard ───────────────────────────────────────────────
  // Blocks submission when any format row has a missing or zero unit count.
  // Réassigner mode and cuve-réutilisée rows are exempt (no new volume expected).
  if (form) {
    form.addEventListener('submit', function (e) {
      if (isReassignerMode) return; // réassigner mode: no volume required

      // Clear stale errors from a previous failed attempt.
      form.querySelectorAll('.pf-qty-error').forEach(function (el) {
        el.style.display = 'none';
      });

      for (var i = 0; i < formatRows.length; i++) {
        var idx = formatRows[i];
        var rowEl = document.getElementById('pf-fmt-' + idx);
        if (!rowEl) continue;

        // Skip cuve-réutilisée rows — zero new volume is expected there.
        if (rowIsReuse(rowEl)) continue;

        // Determine origin: main or parallel.
        var originInput = rowEl.querySelector('input[name$="[row_origin]"]');
        var origin = originInput ? originInput.value : (i === 0 ? 'main' : 'parallel');
        var isMain = (origin === 'main');

        // Read the appropriate qty input.
        var qtyInput = isMain
          ? rowEl.querySelector('[name*="prod_total_units"]')
          : rowEl.querySelector('[name*="qte_unites"]');

        if (!qtyInput) continue; // input not rendered (unusual) — skip

        var qtyVal = parseInt(qtyInput.value, 10);
        var isEmpty = (qtyInput.value === '' || isNaN(qtyVal) || qtyVal <= 0);

        if (isEmpty) {
          e.preventDefault();

          var msg = isMain
            ? 'Run principal : le volume (unités) est obligatoire et doit être > 0.'
            : 'Format parallèle #' + idx + ' : le volume (unités) est obligatoire et doit être > 0.';

          // Find or create the inline error element.
          var existingErr = rowEl.querySelector('.pf-qty-error');
          if (!existingErr) {
            existingErr = document.createElement('p');
            existingErr.className = 'pf-qty-error op-form__error';
            qtyInput.parentNode.insertBefore(existingErr, qtyInput.nextSibling);
          }
          existingErr.textContent = msg;
          existingErr.style.display = 'block';

          qtyInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
          qtyInput.focus();
          return; // one error at a time
        }
      }
    });
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      if (co2o2InversionConfirmed) return; // operator already confirmed — let through

      // Collect all co2o2 inputs rendered by MSR (name="co2o2[N][co2|o2]"),
      // grouped into pairs by the [N] index extracted from the name attribute.
      var allInputs = form.querySelectorAll('input[name^="co2o2["]');
      // pairMap: { N: { co2: input, o2: input } }
      var pairMap = {};
      var pairOrder = []; // preserve insertion order of indices
      for (var ii = 0; ii < allInputs.length; ii++) {
        var inp = allInputs[ii];
        // name format: co2o2[N][co2] or co2o2[N][o2]
        var m = inp.name.match(/^co2o2\[(\d+)\]\[(co2|o2)\]$/);
        if (!m) continue;
        var idx = m[1];
        var key = m[2]; // 'co2' or 'o2'
        if (!pairMap[idx]) {
          pairMap[idx] = {};
          pairOrder.push(idx);
        }
        pairMap[idx][key] = inp;
      }

      var inverted = [];

      for (var pi = 0; pi < pairOrder.length; pi++) {
        var pIdx = pairOrder[pi];
        var co2Input = pairMap[pIdx].co2;
        var o2Input  = pairMap[pIdx].o2;
        if (!co2Input || !o2Input) continue;

        var co2Val = parseFloat(co2Input.value);
        var o2Val  = parseFloat(o2Input.value);
        if (isNaN(co2Val) || isNaN(o2Val)) continue; // blank or non-numeric — skip

        var sevAsEntered = Math.max(co2Flag(co2Val), o2Flag(o2Val));
        var sevSwapped   = Math.max(co2Flag(o2Val),  o2Flag(co2Val));

        if (sevSwapped < sevAsEntered) {
          inverted.push({
            readingNum: String(parseInt(pIdx, 10) + 1),
            co2: co2Val,
            o2: o2Val,
            co2Input: co2Input,
          });
        }
      }

      if (inverted.length === 0) return; // nothing suspicious — submit normally

      e.preventDefault();

      var msg;
      if (inverted.length === 1) {
        msg = 'Lecture ' + inverted[0].readingNum + ' : les mesures CO₂/O₂ semblent inversées' +
              ' (CO₂ ' + inverted[0].co2 + ' g/L, O₂ ' + inverted[0].o2 + ' ppb).' +
              '\n\nTu valides quand même ?';
      } else {
        var nums = inverted.map(function (p) { return p.readingNum; }).join(', ');
        msg = 'Lectures ' + nums + ' : les mesures CO₂/O₂ semblent inversées.' +
              '\n\nTu valides quand même ?';
      }

      if (window.confirm(msg)) {
        co2o2InversionConfirmed = true;
        form.submit(); // programmatic — bypasses the listener
      } else {
        // Focus the first inverted pair's CO₂ input so the operator can fix it
        var firstInput = inverted[0].co2Input;
        if (firstInput) {
          firstInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
          firstInput.focus();
        }
      }
    });
  }

  // ── FormFramework init ────────────────────────────────────────────────────
  //
  // Magnitude-outlier confirm token plumbing:
  //   MagnitudeGuard.checkFormatRows() returns outlier warnings when a value
  //   is off by ≥10× from the expected order of magnitude (e.g. HL entered where L
  //   expected). These are level='outlier' → FormFramework forces a comment before
  //   the confirm button is enabled.
  //
  //   After the operator fills the comment and clicks "Oui, enregistrer",
  //   FormFramework calls form.submit() (native method — no DOM event fires).
  //   We patch form.submit to set magnitude_outlier_confirmed=1 when magnitude
  //   outliers were present in the most-recent extraWarnings() call, so the server
  //   can accept the confirmed submission.
  //
  //   Edit-mode suppression: unchanged values that already passed a previous confirm
  //   are suppressed by MagnitudeGuard (reads data-mag-confirmed-fields on the row el).

  // Track whether the last extraWarnings() call found magnitude outliers.
  var _magOutliersPending = false;

  if (typeof FormFramework !== 'undefined') {
    // Override form.submit before FormFramework.init() so the patch is in place
    // when FormFramework's confirm callback calls it.
    var _origFormSubmit = form ? form.submit.bind(form) : null;
    if (form && _origFormSubmit) {
      form.submit = function () {
        if (_magOutliersPending) {
          var magConfInput = form.querySelector('input[name="magnitude_outlier_confirmed"]');
          if (magConfInput) magConfInput.value = '1';
          // Reset flag — the next fresh submit (if the server rejects for another reason
          // and the operator re-submits) will re-evaluate via extraWarnings().
          _magOutliersPending = false;
        }
        _origFormSubmit();
      };
    }

    FormFramework.init({
      formId:         'packaging-form',
      draftKey:       'packaging-draft',
      warningPanelId: 'packaging-warnings',
      thresholds:     {},
      diffFields: ['event_date'],
      diffLabels: {
        event_date: 'Date conditionnement',
      },
      extraWarnings: function () {
        if (typeof MagnitudeGuard === 'undefined') return [];
        var warns = MagnitudeGuard.checkFormatRows();
        _magOutliersPending = warns.some(function (w) { return w.level === 'outlier'; });
        return warns;
      },
    });
  }
  // Re-derive qa_analyses_units after a draft restore. loadDraft() runs
  // synchronously inside FormFramework.init() and sets input values without
  // dispatching change events, so syncQaAnalysesDisplay() must run after init
  // to reflect the restored CO₂/O₂ pair count on a fresh page load.
  syncQaAnalysesDisplay();

  // ── Edit-mode prefill (PF_STICKY_*) ─────────────────────────────────────
  //
  // When window.PF_EDIT_MODE is true, the server injected:
  //   PF_EDIT_STICKY_HEADER   — header fields (tank, beer, batch, etc.)
  //   PF_EDIT_STICKY_FORMATS  — per-format row data
  //   PF_EDIT_STICKY_TANK     — {co2_gl, o2_ppb} or null
  //   PF_EDIT_STICKY_FILLING  — [{co2, o2}, ...] in-filling pairs
  //   PF_EDIT_SHARED_TANK_COUNT — referrer count for shared-reading warn
  //
  // Flow: restore tank selection → JS fires selectTank() → format rows rendered
  //       → fill format fields → fill in-tank inputs → fill in-filling pairs.
  //
  // The tank-card selection triggers selectTank() which populates all hidden
  // identity fields (neb_beer, neb_batch, recipe_id_fk, etc.) and enables submit.
  // If the exact tank card isn't found (e.g. lot no longer eligible), we set
  // hidden fields directly and show a warning — the operator can still save.

  if (window.PF_EDIT_MODE && window.PF_EDIT_STICKY_HEADER) {
    var stickyH = window.PF_EDIT_STICKY_HEADER;

    // ── 1. Restore tank selection ──────────────────────────────────────────
    var tankTypeVal = stickyH.source_tank_type || '';
    var tankFkId    = stickyH.tank_fk_id !== null && stickyH.tank_fk_id !== undefined
                        ? String(stickyH.tank_fk_id) : '';

    var foundCard = null;
    if (tankTypeVal && tankFkId) {
      // Try current tank grid (normal candidates)
      foundCard = document.querySelector(
        '.pf-tank-card[data-tank-type="' + escHtml(tankTypeVal) + '"][data-tank-fk-id="' + escHtml(tankFkId) + '"]'
      );
    }

    if (foundCard && !foundCard.disabled) {
      // Normal path: card is present and eligible → select it
      selectTank(foundCard);
    } else {
      // Fallback: tank no longer in normal candidate list
      // (could be ineligible lot, or date gate). Populate hidden fields directly
      // so the form can still be saved. Operator sees a warning.
      if (fldTankType)     fldTankType.value     = tankTypeVal;
      if (fldTankId)       fldTankId.value       = tankFkId;
      if (fldTankNum)      fldTankNum.value       = '';
      if (fldNebBeer)      fldNebBeer.value       = stickyH.neb_beer || '';
      if (fldNebBatch)     fldNebBatch.value      = stickyH.neb_batch || '';
      if (fldContractBeer) fldContractBeer.value  = stickyH.contract_beer || '';
      if (fldContractBatch)fldContractBatch.value = stickyH.contract_batch || '';
      if (fldRecipeId)     fldRecipeId.value      = stickyH.recipe_id_fk ? String(stickyH.recipe_id_fk) : '';

      isContractBeer = (stickyH.contract_beer && !stickyH.neb_beer);
      updateAllRowDispositionGroups();
      updateTankReadState();

      // Show selected-panel summary even without a card
      if (selectedPanel) selectedPanel.hidden = false;
      if (selectedSummary) {
        var bDisp = stickyH.neb_beer || stickyH.contract_beer || '?';
        var baDisp = stickyH.neb_batch || stickyH.contract_batch || '?';
        selectedSummary.textContent = tankTypeVal + ' — ' + bDisp + ' · Brassin ' + baDisp;
      }

      tryEnableSubmit();
    }

    // ── 1b. Restore hors_process override state ─────────────────────────────
    // Round-trip the original flag so a plain re-save does NOT silently clear it.
    // Set the POST hidden directly (do NOT dispatch the checkbox 'change' handler —
    // that would swap the tank candidate list and clobber the edit-mode selection
    // restored above). The visible checkbox/reason are set for UI consistency only.
    if (Number(stickyH.hors_process_flag) === 1) {
      if (horsProcessFld) horsProcessFld.value = '1';
      if (overrideCheckbox) overrideCheckbox.checked = true;
      if (overrideReasonRow) overrideReasonRow.hidden = false;
      var hpReasonInput = document.getElementById('hors_process_reason');
      if (hpReasonInput && stickyH.hors_process_reason) {
        hpReasonInput.value = stickyH.hors_process_reason;
      }
    }

    // ── 2. Restore format rows ──────────────────────────────────────────────
    // The main format row (index 0) was seeded by addFormatRow(true) above.
    // We need to restore its values AND add parallel rows if needed.
    //
    // For widget fields (MSR_LOSS_FIELDS): parallel rows pass editValues into
    // addFormatRow() so widgets are seeded at init time via initialRows.
    // Row 0 was already inited without values; we re-seed its widget mounts
    // by looking up the stored instance on each mount element.
    var stickyFmts = window.PF_EDIT_STICKY_FORMATS || [];

    // Fields that are plain inputs in edit hydration (not widgets):
    // qa_analyses_units is excluded from MSR_LOSS_FIELDS (stays plain input).
    // loss_liquid_other_units and loss_keg_collar_units are legacy fields not in the
    // current field-def arrays — restored via setFmtInput if present on server data.
    var PLAIN_DISP_FIELDS = [
      'loss_liquid_other_units', 'loss_keg_collar_units',
      'qa_analyses_units', 'objective_hl',
    ];
    // Widget fields list (must mirror MSR_LOSS_FIELDS in buildFormatRow above)
    var WIDGET_DISP_FIELDS = [
      'unsaleable_units', 'loss_uncapped_units', 'loss_untaxed_full_units',
      'loss_half_filled_units', 'qa_library_units',
      'loss_4pack_btl_units', 'loss_4pack_can_units', 'loss_wrap_btl_units',
      'loss_wrap_can_units', 'loss_label_btl_units', 'loss_crown_cork_units',
      'loss_can_lid_units', 'loss_container_btl_units', 'loss_container_can_units',
      'loss_keg_liquid_l', 'taproom_keg_l', 'loss_keg_save_units',
    ];

    stickyFmts.forEach(function (fmt, i) {
      var isMain = (fmt.row_origin === 'main');

      // Build editValues map for this format row (widget fields only).
      var editVals = {};
      WIDGET_DISP_FIELDS.forEach(function (fname) {
        var v = fmt[fname];
        if (v !== null && v !== undefined && v !== '') editVals[fname] = v;
      });

      // Row 0 already added (main); add additional rows for parallels.
      // Parallel rows receive editValues so their widgets are seeded at init.
      if (i > 0) {
        addFormatRow(false, editVals);
      }

      var rowEl = document.getElementById('pf-fmt-' + i);
      if (!rowEl) return;

      // For row 0 (already inited without values), re-seed widget instances now.
      if (i === 0 && typeof MultiSubmitReads !== 'undefined') {
        rowEl.querySelectorAll('.pf-loss-msr').forEach(function (mount) {
          var lossName = mount.dataset.lossName;
          var v = editVals[lossName];
          if (v !== undefined && mount._msr) {
            // The widget was inited with minRows:1 (one blank row).
            // Re-seed: addRow with the value, then the blank initial row
            // was created by the widget already. We need to destroy and
            // re-init with the value — easiest is to re-init entirely.
            mount._msr.destroy();
            var dec = parseInt(mount.dataset.decimals, 10) || 0;
            mount._msr = MultiSubmitReads.init({
              mountId:     mount.id,
              mode:        'sum',
              outputName:  'formats[0][' + lossName + ']',
              decimals:    dec,
              minRows:     1,
              maxRows:     30,
              fields:      [{ key: 'v', placeholder: '0', step: dec ? '0.001' : '1' }],
              initialRows: [[String(v)]],
            });
          }
        });
      }

      // Set run_type
      var rtSel = rowEl.querySelector('[name="formats[' + i + '][run_type]"]');
      if (rtSel && fmt.run_type) {
        rtSel.value = fmt.run_type;
        rtSel.dispatchEvent(new Event('change'));
      }

      // Set format_suffix
      var sfxSel = rowEl.querySelector('[name="formats[' + i + '][format_suffix]"]');
      if (sfxSel) {
        var sfxVal = fmt.nebuleuse_format_suffix || '';
        // Add option if missing (handles values outside FORMAT_SUFFIXES)
        var sfxOpt = Array.from(sfxSel.options).find(function (o) { return o.value === sfxVal; });
        if (!sfxOpt && sfxVal !== '') {
          var newOpt = document.createElement('option');
          newOpt.value = sfxVal;
          newOpt.textContent = sfxVal;
          sfxSel.appendChild(newOpt);
        }
        sfxSel.value = sfxVal;
      }

      // Set qty field (prod_total or qte_unites depending on origin)
      function setFmtInput(nameFragment, value) {
        var el = rowEl.querySelector('[name="formats[' + i + '][' + nameFragment + ']"]');
        if (el && value !== null && value !== undefined && value !== '') {
          el.value = String(value);
        }
      }

      if (isMain) {
        setFmtInput('prod_total_units', fmt.prod_total_units);
      } else {
        setFmtInput('qte_unites', fmt.special_qty_units);
      }

      // Plain disposition fields (non-widget inputs) — qa_analyses_units + legacy fields
      PLAIN_DISP_FIELDS.forEach(function (fname) {
        setFmtInput(fname, fmt[fname]);
      });

      // Per-row client / liner FKs
      setFmtInput('client_fk', fmt.client_fk);

      var linerClientSel = rowEl.querySelector('[name="formats[' + i + '][liner_client_mi_id_fk]"]');
      if (linerClientSel && fmt.liner_client_mi_id_fk !== null && fmt.liner_client_mi_id_fk !== undefined) {
        linerClientSel.value = String(fmt.liner_client_mi_id_fk);
      }
      var linerTransSel = rowEl.querySelector('[name="formats[' + i + '][liner_transport_mi_id_fk]"]');
      if (linerTransSel && fmt.liner_transport_mi_id_fk !== null && fmt.liner_transport_mi_id_fk !== undefined) {
        linerTransSel.value = String(fmt.liner_transport_mi_id_fk);
      }

      // Per-card WL fields (edit-mode hydration).
      // WL split legs come back with is_white_label=1 and suffix ending in '-WL'.
      // Strip the trailing '-WL' before setting the suffix dropdown so the format
      // renders cleanly; set wl_units from prod_total_units and wl_name from the row.
      var fmtSuffix = fmt.nebuleuse_format_suffix || '';
      var isWlLeg   = (fmt.is_white_label === 1 || fmt.is_white_label === '1')
                       && (fmtSuffix === 'WL' || fmtSuffix.slice(-3) === '-WL');
      if (isWlLeg) {
        // Correct the suffix dropdown: strip '-WL' / 'WL' trailing token.
        var cleanSuffix = (fmtSuffix === 'WL') ? '' : fmtSuffix.replace(/-WL$/, '');
        var sfxSelWl = rowEl.querySelector('[name="formats[' + i + '][format_suffix]"]');
        if (sfxSelWl) {
          var sfxOptWl = Array.from(sfxSelWl.options).find(function (o) { return o.value === cleanSuffix; });
          if (!sfxOptWl && cleanSuffix !== '') {
            var newOptWl = document.createElement('option');
            newOptWl.value = cleanSuffix;
            newOptWl.textContent = cleanSuffix;
            sfxSelWl.appendChild(newOptWl);
          }
          sfxSelWl.value = cleanSuffix;
        }
        // Set wl_units from prod_total_units (WL leg special_qty_units == prod_total_units)
        setFmtInput('wl_units', fmt.prod_total_units);
        // Set white_label_name
        setFmtInput('white_label_name', fmt.white_label_name);
      }
      // Set the is_white_label select (covers both WL legs and normal cards with is_white_label=1)
      var wlSelEdit = rowEl.querySelector('[name="formats[' + i + '][is_white_label]"]');
      if (wlSelEdit && (fmt.is_white_label === 1 || fmt.is_white_label === '1')) {
        wlSelEdit.value = '1';
        // Trigger the show/hide — reuse the same updater pattern
        wlSelEdit.dispatchEvent(new Event('change'));
        if (!isWlLeg) {
          // For a non-split card restoring is_white_label=1 (unlikely in current flow
          // but safe): wl_units and white_label_name would need to come from the split
          // leg which is a separate row — nothing extra to set here.
        }
      }

      // Reuses FK (cuv only)
      var reuseSel = rowEl.querySelector('[name="formats[' + i + '][reuses_packaging_id_fk]"]');
      if (reuseSel && fmt.reuses_packaging_id_fk !== null && fmt.reuses_packaging_id_fk !== undefined) {
        reuseSel.value = String(fmt.reuses_packaging_id_fk);
        reuseSel.dispatchEvent(new Event('change'));
      }

      // Re-evaluate disposition groups after all values set
      updateRowDispositionGroups(rowEl);

      // ── Magnitude-guard edit-mode suppression ──────────────────────────────
      // If this row was stored with magnitude_outlier_confirmed, inject a
      // data-mag-confirmed-fields attribute so MagnitudeGuard.checkFormatRows()
      // can suppress re-nag for unchanged values.
      // The stored field keys use the generic 'formats[0][…]' prefix as a placeholder
      // (the server doesn't know the row index ahead of time); rekey to the actual idx.
      if (fmt.mag_confirmed_values && typeof fmt.mag_confirmed_values === 'object') {
        var rekeyed = {};
        var actualPrefix = 'formats[' + i + '][';
        Object.keys(fmt.mag_confirmed_values).forEach(function (storedKey) {
          // storedKey is the bare field name (no prefix), since PHP builds it without prefix.
          // Build the full name as the DOM uses it.
          rekeyed[actualPrefix + storedKey + ']'] = fmt.mag_confirmed_values[storedKey];
        });
        try {
          rowEl.dataset.magConfirmedFields = JSON.stringify(rekeyed);
        } catch (_) {}
      }
    });

    // ── 3. Restore in-tank CO₂/O₂ ─────────────────────────────────────────
    var stickyTank = window.PF_EDIT_STICKY_TANK;
    if (stickyTank) {
      if (tankCo2Input) {
        tankCo2Input.value = stickyTank.co2_gl !== null && stickyTank.co2_gl !== undefined
          ? String(stickyTank.co2_gl) : '';
      }
      if (tankO2Input) {
        tankO2Input.value = stickyTank.o2_ppb !== null && stickyTank.o2_ppb !== undefined
          ? String(stickyTank.o2_ppb) : '';
      }
    }

    // ── 4. Restore in-filling CO₂/O₂ pairs ────────────────────────────────
    // Handled by MultiSubmitReads.init() above via initialRows (reads
    // window.PF_EDIT_STICKY_FILLING at init time). No re-seed needed here.

    // ── 5. Shared in-tank warn ─────────────────────────────────────────────
    var sharedCount = window.PF_EDIT_SHARED_TANK_COUNT || 0;
    var sharedWarnEl = document.getElementById('pf-shared-tank-warn');
    if (sharedWarnEl && sharedCount > 1) {
      var countEl = document.getElementById('pf-shared-tank-count');
      if (countEl) countEl.textContent = String(sharedCount);
      sharedWarnEl.hidden = false;
    }

    // ── 6. Sync qa_analyses_units display after full edit-restore ─────────
    // All rows and in-filling data are now restored; recompute the derived count
    // so the read-only field shows the correct value immediately.
    syncQaAnalysesDisplay();
  }

});

// ── Edit-mode: enable submit without tank selection ────────────────────────
// In edit mode, the submit button should not be gated on a freshly-selected
// tank card (the existing session's identity is already stored in hidden fields).
// Re-enable submit after prefill sets the hidden tank fields.
// (tryEnableSubmit already calls fldTankType.value check; after setting it in
//  the restore path above, we must call it again once DOM is settled.)
// Handled by the selectTank() call or the direct field-set + tryEnableSubmit() above.
