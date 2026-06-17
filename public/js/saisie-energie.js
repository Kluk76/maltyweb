/**
 * saisie-energie.js — Saisie mensuelle index compteurs énergie.
 *
 * Responsibilities:
 *   1. Live delta computation: for each of the 4 meter fields, compute
 *      current − prev on every input event and update the .se-delta span.
 *   2. Month picker navigation: when <input type="month"> changes, navigate
 *      to ?period= + the new value immediately.
 *   3. Form submit guard: disable submit button + change text to prevent
 *      double-submit.
 *   4. Delta on page load: run once for any prefilled (edit-mode) values.
 *
 * Plain IIFE — no module syntax (matches existing pages in this codebase).
 * Data wiring: previous-month values come from data-prev-* attributes on
 * the #se-entry-form element, and data-prev on each .se-prev-hint span.
 */
(function () {
  'use strict';

  // ── Field map: input id → field name ──────────────────────────────────────
  var FIELDS = [
    { inputId: 'se-eau',  fieldName: 'eau_m3' },
    { inputId: 'se-gaz',  fieldName: 'gaz_kwh' },
    { inputId: 'se-jour', fieldName: 'elec_jour_kwh' },
    { inputId: 'se-nuit', fieldName: 'elec_nuit_kwh' },
  ];

  // ── Helpers ───────────────────────────────────────────────────────────────

  /**
   * Format a numeric delta for display.
   * Returns '+123.456', '-12.300', '0.000', or '—' when input is empty.
   */
  function formatDelta(delta) {
    if (delta === null) return '—';
    if (delta > 0) return '+' + delta.toFixed(3);
    if (delta < 0) return delta.toFixed(3);
    return '0.000';
  }

  /**
   * Update the .se-delta span for a given field.
   * Reads the current input value and the prev value from the form's
   * data-prev-* attribute (most reliable single source).
   */
  function updateDelta(form, inputEl, fieldName) {
    var deltaSpan = document.querySelector('.se-delta[data-field="' + fieldName + '"]');
    if (!deltaSpan) return;

    var rawCur = inputEl.value.trim();

    if (rawCur === '') {
      deltaSpan.textContent = '—';
      deltaSpan.className = 'se-delta';
      return;
    }

    var cur = parseFloat(rawCur);
    if (isNaN(cur)) {
      deltaSpan.textContent = '—';
      deltaSpan.className = 'se-delta';
      return;
    }

    // Previous value from form data attribute
    var attrName = 'data-prev-' + fieldName.replace(/_/g, '-');
    // Normalize: 'eau_m3' → 'prev-eau-m3' etc.
    // The PHP emits: data-prev-eau, data-prev-gaz, data-prev-jour, data-prev-nuit
    // Map fieldName to the short key
    var shortMap = {
      'eau_m3':       'eau',
      'gaz_kwh':      'gaz',
      'elec_jour_kwh': 'jour',
      'elec_nuit_kwh': 'nuit',
    };
    var shortKey = shortMap[fieldName] || fieldName;
    var prevRaw = form.getAttribute('data-prev-' + shortKey);

    if (prevRaw === null || prevRaw === '') {
      // No previous value — show current without delta
      deltaSpan.textContent = '—';
      deltaSpan.className = 'se-delta';
      return;
    }

    var prev = parseFloat(prevRaw);
    if (isNaN(prev)) {
      deltaSpan.textContent = '—';
      deltaSpan.className = 'se-delta';
      return;
    }

    var delta = cur - prev;
    deltaSpan.textContent = formatDelta(delta);

    // Apply class
    if (delta > 0) {
      deltaSpan.className = 'se-delta se-delta--pos';
    } else if (delta < 0) {
      deltaSpan.className = 'se-delta se-delta--neg';
    } else {
      deltaSpan.className = 'se-delta se-delta--zero';
    }
  }

  // ── Wire up delta inputs ──────────────────────────────────────────────────

  function initDeltas() {
    var form = document.getElementById('se-entry-form');
    if (!form) return; // Not present on invoice-locked view

    FIELDS.forEach(function (f) {
      var inputEl = document.getElementById(f.inputId);
      if (!inputEl) return;

      // Live update on input
      inputEl.addEventListener('input', function () {
        updateDelta(form, inputEl, f.fieldName);
      });

      // Compute once on load (for edit-mode prefilled values)
      updateDelta(form, inputEl, f.fieldName);
    });
  }

  // ── Month picker navigation ───────────────────────────────────────────────

  function initMonthPicker() {
    var monthInput = document.getElementById('se-month-input');
    if (!monthInput) return;

    monthInput.addEventListener('change', function () {
      var val = monthInput.value;
      if (/^\d{4}-\d{2}$/.test(val)) {
        window.location.href = '/modules/saisie-energie.php?period=' + encodeURIComponent(val);
      }
    });
  }

  // ── Form submit guard ─────────────────────────────────────────────────────

  function initSubmitGuard() {
    var form = document.getElementById('se-entry-form');
    if (!form) return;

    var btn = document.getElementById('se-submit-btn');
    if (!btn) return;

    form.addEventListener('submit', function () {
      btn.disabled = true;
      btn.textContent = 'Enregistrement…';
    });
  }

  // ── Init ──────────────────────────────────────────────────────────────────

  function init() {
    initDeltas();
    initMonthPicker();
    initSubmitGuard();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
