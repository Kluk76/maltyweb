/**
 * magnitude-guard.js — Order-of-magnitude sanity guard for cuv (serving-tank fill) rows.
 *
 * Scope: cuv run_type ONLY.
 *   When run_type='cuv', the qty field (prod_total_units / qte_unites) is in LITRES
 *   and represents a serving-tank or liner fill. The classic unit-slip is the operator
 *   entering 8.5 (HL) where ~850 L is expected.
 *
 *   keg/bot/can/can33 qty counts: NOT checked. A count has no HL-vs-L slip mode,
 *   and a 10× band false-flags small contract runs (6-keg Jasper, 100-bottle runs).
 *   loss_keg_liquid_l / taproom_keg_l: NOT checked. These are small, highly-variable
 *   litre values with no unit-slip failure mode.
 *
 * Band derivation (canonical, server-side):
 *   Sourced from ref_serving_tanks.capacity_hl via window.CUV_FILL_BAND = {loL, hiL}.
 *   The server computes:
 *     loL = MIN(capacity_hl) × 100 / 10   (e.g. 5 HL → 500 L → floor 50 L)
 *     hiL = MAX(capacity_hl) × 100 × 10   (e.g. 30 HL → 3000 L → ceiling 30 000 L)
 *   If window.CUV_FILL_BAND is absent or invalid, the guard is disabled for that
 *   session (server must have reported an error — the form itself also blocks on empty table).
 *
 * Soft-confirm flow (unchanged):
 *   Warnings returned at level='outlier' go through FormFramework.extraWarnings()
 *   → forces a comment → sets magnitude_outlier_confirmed=1 on confirm.
 *   Edit-mode suppression: unchanged value that was already confirmed is not re-nagged.
 *
 * No framework dependency. Vanilla ES2020.
 */

const MagnitudeGuard = (() => {

  /**
   * Pure function: is this cuv fill value a magnitude outlier?
   *
   * Only meaningful for run_type='cuv'. All other run types return { outlier: false }.
   *
   * @param {number}  value    — numeric litres value entered by operator
   * @param {string}  runType  — 'cuv' | 'keg' | 'bot' | 'can' | 'can33' | ''
   * @returns {{ outlier: boolean, loL: number, hiL: number }}
   */
  function isMagnitudeOutlier(value, runType) {
    if (runType !== 'cuv') return { outlier: false, loL: 0, hiL: 0 };
    if (isNaN(value) || value <= 0) return { outlier: false, loL: 0, hiL: 0 };

    const band = window.CUV_FILL_BAND;
    // Guard disabled if the server did not inject a valid band (empty table / DB error).
    if (!band || typeof band.loL !== 'number' || typeof band.hiL !== 'number'
        || band.loL <= 0 || band.hiL <= 0) {
      return { outlier: false, loL: 0, hiL: 0 };
    }

    return {
      outlier: value < band.loL || value > band.hiL,
      loL:     band.loL,
      hiL:     band.hiL,
    };
  }

  /**
   * Check all rendered format rows for cuv-fill magnitude outliers.
   * Reads format row DOM elements rendered by packaging-form.js.
   * Returns an array of FormFramework-compatible warning objects.
   *
   * Called by packaging-form.js extraWarnings() hook.
   *
   * Edit-mode suppression: if a format row carries data-mag-confirmed-fields as a
   * JSON map of fieldName → confirmedValue AND the current value is unchanged, the
   * warning is suppressed for that row+field combination.
   *
   * @returns {Array<{field:string, level:string, message:string, commentTarget:string}>}
   */
  function checkFormatRows() {
    const warnings = [];

    const rows = document.querySelectorAll('.pf-format-row');
    rows.forEach(function (rowEl) {
      const idx    = rowEl.dataset.idx;
      const prefix = 'formats[' + idx + ']';

      // Only cuv rows are checked.
      const runTypeSel = rowEl.querySelector('[name="' + prefix + '[run_type]"]');
      const runType    = runTypeSel ? (runTypeSel.value || '') : '';
      if (runType !== 'cuv') return;

      // Determine the qty field name for this row origin.
      const originInput = rowEl.querySelector('[name="' + prefix + '[row_origin]"]');
      const isMain      = !originInput || originInput.value === 'main';
      const qtyName     = isMain ? (prefix + '[prod_total_units]') : (prefix + '[qte_unites]');

      _checkField(rowEl, qtyName, warnings);
    });

    return warnings;
  }

  /**
   * Internal: check a single cuv qty field within a row element.
   * Appends to warnings[] if an outlier is detected and not suppressed.
   */
  function _checkField(rowEl, fieldName, warnings) {
    const el = rowEl.querySelector('[name="' + fieldName + '"]');
    if (!el) return;

    const raw = el.value.trim();
    if (raw === '' || raw === '0') return;

    const value = parseFloat(raw.replace(',', '.'));
    if (isNaN(value) || value <= 0) return;

    const result = isMagnitudeOutlier(value, 'cuv');
    if (!result.outlier) return;

    // Edit-mode suppression: if this field already passed a magnitude confirmation
    // in a previous submit AND the value is unchanged, suppress the re-nag.
    const confirmedRaw = rowEl.dataset.magConfirmedFields;
    if (confirmedRaw) {
      try {
        const confirmed = JSON.parse(confirmedRaw);
        if (Object.prototype.hasOwnProperty.call(confirmed, fieldName)) {
          if (String(confirmed[fieldName]) === raw) return;
        }
      } catch (_) { /* ignore malformed JSON */ }
    }

    // Build human-readable French message.
    const loStr = result.loL.toLocaleString('fr-CH');
    const hiStr = result.hiL.toLocaleString('fr-CH');
    const msg = 'Volume cuve : ' + value + ' L — hors de l\'ordre de grandeur attendu ' +
      '(~' + loStr + '–' + hiStr + ' L). Vérifiez l\'unité (HL au lieu de litres ?)';

    warnings.push({
      field:         fieldName,
      level:         'outlier',
      message:       msg,
      commentTarget: 'fw_comment',
    });
  }

  return { checkFormatRows, isMagnitudeOutlier };
})();
