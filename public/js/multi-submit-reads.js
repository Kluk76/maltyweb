/**
 * multi-submit-reads.js — Generic multi-entry measurement widget.
 *
 * Exposes: window.MultiSubmitReads = { init: function(cfg) → instance }
 *
 * Usage:
 *   var inst = MultiSubmitReads.init({
 *     mountId:     'rf-turbidity-msr',
 *     mode:        'average',          // 'serialize' | 'sum' | 'average'
 *     outputName:  'avg_turbidity',    // sum/average: hidden <input> name
 *     decimals:    3,
 *     minRows:     1,
 *     maxRows:     50,
 *     fields:      [{ key:'v', label:'Turbidité', unit:'NTU',
 *                     placeholder:'ex. 0.5', step:'0.001' }],
 *     initialRows: [],                 // [[val,...], ...] for edit hydration
 *     // serialize only:
 *     // arrayName: 'co2o2',
 *     // onChange:  function(rowsValues, aggregate) {},
 *     // rowValidator: function(values, index) { return null | 'errorString'; },
 *   });
 *   inst.addRow([0.5]);
 *   inst.getRows();       // → [[0.5], [0.6]]
 *   inst.getAggregate();  // → 0.55
 *   inst.destroy();
 *
 * Vanilla ES2020 IIFE. No imports. No inline styles (CSS lives in multi-submit-reads.css).
 * Loaded deferred — must load BEFORE any script that calls MultiSubmitReads.init().
 */

(function (global) {
  'use strict';

  // ── XSS guard ───────────────────────────────────────────────────────────────
  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // ── Numeric helpers ─────────────────────────────────────────────────────────

  /**
   * Parse a decimal from a text input — normalise comma→dot (FR/CH locale).
   * Returns NaN for blank/non-numeric, matching parseFloat semantics.
   */
  function parseDecimal(v) {
    var s = String(v == null ? '' : v).trim().replace(',', '.');
    return parseFloat(s);
  }

  function roundTo(v, decimals) {
    var factor = Math.pow(10, decimals);
    return Math.round(v * factor) / factor;
  }

  // ── Core init ───────────────────────────────────────────────────────────────

  /**
   * @param {Object} cfg — see module header for full field list.
   * @returns {Object} instance with { addRow, getRows, getAggregate, destroy }
   */
  function init(cfg) {
    // ── Resolve config with defaults ────────────────────────────────────────
    var mountId    = cfg.mountId;
    var mode       = cfg.mode;                       // 'serialize'|'sum'|'average'
    var minRows    = (cfg.minRows  != null) ? cfg.minRows  : 1;
    var maxRows    = (cfg.maxRows  != null) ? cfg.maxRows  : 50;
    var decimals   = (cfg.decimals != null) ? cfg.decimals : 1;
    var fields     = cfg.fields || [];               // [{key, label, unit, placeholder, step}]
    var arrayName  = cfg.arrayName   || null;        // serialize mode
    var outputName = cfg.outputName  || null;        // sum/average mode
    var initialRows = cfg.initialRows || [];
    var formEl     = cfg.formEl      || null;
    var onChange   = cfg.onChange    || null;
    var rowValidator = cfg.rowValidator || null;

    // ── Validate mode ────────────────────────────────────────────────────────
    if (mode !== 'serialize' && mode !== 'sum' && mode !== 'average') {
      console.error('MultiSubmitReads: unknown mode "' + mode + '"');
      return null;
    }
    if ((mode === 'sum' || mode === 'average') && !outputName) {
      console.error('MultiSubmitReads: outputName required for mode "' + mode + '"');
      return null;
    }
    if (mode === 'serialize' && !arrayName) {
      console.error('MultiSubmitReads: arrayName required for mode "serialize"');
      return null;
    }

    // ── Mount point ──────────────────────────────────────────────────────────
    var mount = document.getElementById(mountId);
    if (!mount) {
      console.warn('MultiSubmitReads: mount #' + mountId + ' not found');
      return null;
    }

    // Resolve form: explicit cfg.formEl, then closest form ancestor, then null.
    var form = formEl || (mount.closest ? mount.closest('form') : null);

    // ── Internal row state ───────────────────────────────────────────────────
    // rows: array of {id, values:[string,...]}
    var rows = [];
    var nextId = 1;

    // ── Hidden output input (sum/average mode) ────────────────────────────────
    var hiddenOutput = null;
    if (mode === 'sum' || mode === 'average') {
      hiddenOutput = document.createElement('input');
      hiddenOutput.type  = 'hidden';
      hiddenOutput.name  = outputName;
      hiddenOutput.value = '';
      mount.appendChild(hiddenOutput);
    }

    // ── Build skeleton DOM ────────────────────────────────────────────────────
    var listEl    = document.createElement('div');
    listEl.className = 'msr-list';

    var addBtn = document.createElement('button');
    addBtn.type      = 'button';
    addBtn.className = 'msr-add';
    addBtn.textContent = '+ Ajouter';

    var readoutEl = document.createElement('div');
    readoutEl.className  = 'msr-readout';
    readoutEl.setAttribute('aria-live', 'polite');
    readoutEl.textContent = '';

    mount.appendChild(listEl);
    mount.appendChild(addBtn);
    mount.appendChild(readoutEl);

    // ── Render a single row ───────────────────────────────────────────────────
    function buildRowEl(row, index) {
      var rowEl = document.createElement('div');
      rowEl.className       = 'msr-row';
      rowEl.dataset.rowId   = row.id;

      // 1-based number badge
      var numEl = document.createElement('span');
      numEl.className   = 'msr-row__num';
      numEl.textContent = String(index + 1);
      rowEl.appendChild(numEl);

      // Fields wrapper
      var fieldsWrap = document.createElement('div');
      fieldsWrap.className = 'msr-row__fields';
      rowEl.appendChild(fieldsWrap);

      fields.forEach(function (fDef, fi) {
        var fieldDiv = document.createElement('div');
        fieldDiv.className = 'msr-field';

        if (fDef.label || fDef.unit) {
          var lbl = document.createElement('span');
          lbl.className   = 'msr-field__label';
          lbl.textContent = fDef.label
            ? (fDef.label + (fDef.unit ? ' (' + fDef.unit + ')' : ''))
            : (fDef.unit || '');
          fieldDiv.appendChild(lbl);
        }

        var inp = document.createElement('input');
        inp.type        = 'text';
        inp.inputMode   = 'decimal';
        inp.className   = 'msr-field__input';
        inp.placeholder = fDef.placeholder || '';
        if (fDef.step) inp.setAttribute('step', fDef.step);

        // For serialize mode: name is arrayName[i][key].
        // In sum/average mode: no name — only the hidden output submits.
        if (mode === 'serialize') {
          inp.name = escHtml(arrayName) + '[' + index + '][' + escHtml(fDef.key) + ']';
        }

        // Hydrate initial value if provided
        var initialVal = (row.values && row.values[fi] != null) ? row.values[fi] : '';
        inp.value = String(initialVal);

        inp.addEventListener('input', function () {
          // Comma→dot normalisation on keyup for display continuity
          var v = inp.value.replace(',', '.');
          if (v !== inp.value) inp.value = v;
          row.values[fi] = inp.value;
          onRowChange(row);
        });

        fieldDiv.appendChild(inp);
        fieldsWrap.appendChild(fieldDiv);
      });

      // Remove button
      var removeBtn = document.createElement('button');
      removeBtn.type      = 'button';
      removeBtn.className = 'msr-remove';
      removeBtn.title     = 'Supprimer cette lecture';
      removeBtn.setAttribute('aria-label', 'Supprimer la lecture ' + (index + 1));
      removeBtn.innerHTML = '&#215;'; // ×
      removeBtn.disabled  = (rows.length <= minRows);

      removeBtn.addEventListener('click', function () {
        removeRow(row.id);
      });

      rowEl.appendChild(removeBtn);

      return rowEl;
    }

    // ── Re-render all rows (called after add/remove to fix numbering+names) ──
    function renderAll() {
      listEl.innerHTML = '';
      rows.forEach(function (row, i) {
        listEl.appendChild(buildRowEl(row, i));
      });
      syncRemoveButtons();
      // After re-render, re-index serialize names (re-render already sets correct names)
      syncOutput();
    }

    // ── Enable/disable remove buttons based on minRows ────────────────────────
    function syncRemoveButtons() {
      var canRemove = rows.length > minRows;
      var btns = listEl.querySelectorAll('.msr-remove');
      btns.forEach(function (btn) {
        btn.disabled = !canRemove;
      });
      // Disable/enable Add button at maxRows
      addBtn.disabled = (rows.length >= maxRows);
    }

    // ── Compute aggregate ─────────────────────────────────────────────────────
    function computeAggregate() {
      if (mode === 'serialize') {
        return rows.length;
      }

      // For sum/average: only first field value is used for the scalar aggregate.
      // Multi-field serialize mode uses arrayName serialization, no aggregate.
      var nums = [];
      rows.forEach(function (row) {
        var raw = (row.values && row.values[0] != null) ? row.values[0] : '';
        if (raw === '') return; // skip blank
        var n = parseDecimal(raw);
        if (!isNaN(n)) nums.push(n);
      });

      if (mode === 'sum') {
        if (nums.length === 0) return null;
        var total = nums.reduce(function (a, b) { return a + b; }, 0);
        return roundTo(total, decimals);
      }

      if (mode === 'average') {
        if (nums.length === 0) return null;
        var sum = nums.reduce(function (a, b) { return a + b; }, 0);
        return roundTo(sum / nums.length, decimals);
      }

      return null;
    }

    // ── Update readout text ───────────────────────────────────────────────────
    function updateReadout() {
      if (mode === 'serialize') {
        var n = rows.length;
        var label = fields.length === 1
          ? (n === 1 ? '1 relevé' : n + ' relevés')
          : (n === 1 ? '1 paire'  : n + ' paires');
        readoutEl.textContent = label;
        return;
      }

      var agg = computeAggregate();
      if (agg === null) {
        readoutEl.textContent = '';
        return;
      }

      if (mode === 'sum') {
        readoutEl.textContent = 'Total : ' + agg;
      } else {
        // Count only non-blank rows for the denominator display
        var nonBlank = rows.filter(function (row) {
          var raw = (row.values && row.values[0] != null) ? row.values[0] : '';
          return raw !== '' && !isNaN(parseDecimal(raw));
        }).length;
        readoutEl.textContent = 'Moyenne : ' + agg + ' (' + nonBlank + ' relevé' + (nonBlank > 1 ? 's' : '') + ')';
      }
    }

    // ── Sync hidden output field (sum/average) ────────────────────────────────
    function syncHiddenOutput() {
      if (!hiddenOutput) return;
      var agg = computeAggregate();
      hiddenOutput.value = (agg !== null) ? String(agg) : '';
    }

    /**
     * Re-index serialize-mode input names so PHP receives a contiguous 0..n-1 array.
     * Called after every add/remove in serialize mode.
     * In sum/average mode this is a no-op — those inputs have no name attribute.
     */
    function reindexSerializeNames() {
      if (mode !== 'serialize') return;
      var allRows = listEl.querySelectorAll('.msr-row');
      allRows.forEach(function (rowEl, i) {
        var inputs = rowEl.querySelectorAll('.msr-field__input');
        inputs.forEach(function (inp, fi) {
          inp.name = arrayName + '[' + i + '][' + fields[fi].key + ']';
        });
        // Update row number badge
        var numBadge = rowEl.querySelector('.msr-row__num');
        if (numBadge) numBadge.textContent = String(i + 1);
      });
    }

    // ── Unified post-change sync ──────────────────────────────────────────────
    function syncOutput() {
      if (mode === 'serialize') {
        reindexSerializeNames();
        updateReadout();
      } else {
        syncHiddenOutput();
        updateReadout();
      }
    }

    // ── Show inline row validation error ──────────────────────────────────────
    function renderRowError(rowId, errMsg) {
      var rowEl = listEl.querySelector('[data-row-id="' + rowId + '"]');
      if (!rowEl) return;
      // Remove existing error if any
      var existing = rowEl.querySelector('.msr-row-error');
      if (existing) existing.remove();
      if (errMsg) {
        var errEl = document.createElement('div');
        errEl.className   = 'msr-row-error';
        errEl.textContent = errMsg;
        rowEl.appendChild(errEl);
      }
    }

    // ── Called on every input/add/remove ─────────────────────────────────────
    function onRowChange(row) {
      syncOutput();
      if (rowValidator) {
        var idx = rows.indexOf(row);
        var err = rowValidator(row.values.slice(), idx);
        renderRowError(row.id, err || null);
      }
      if (onChange) {
        var rowsValues = rows.map(function (r) { return r.values.slice(); });
        onChange(rowsValues, getAggregate());
      }
    }

    // ── Add a row ─────────────────────────────────────────────────────────────
    function addRow(values) {
      if (rows.length >= maxRows) return;
      var row = {
        id:     nextId++,
        values: fields.map(function (_, i) {
          return (values && values[i] != null) ? String(values[i]) : '';
        }),
      };
      rows.push(row);
      var idx = rows.length - 1;
      listEl.appendChild(buildRowEl(row, idx));
      syncRemoveButtons();
      syncOutput();
      if (onChange) {
        onChange(rows.map(function (r) { return r.values.slice(); }), getAggregate());
      }
    }

    // ── Remove a row ──────────────────────────────────────────────────────────
    function removeRow(id) {
      if (rows.length <= minRows) return;
      rows = rows.filter(function (r) { return r.id !== id; });
      // Re-render all so numbering + serialize names stay contiguous
      renderAll();
      // renderAll already calls syncOutput; fire onChange separately
      if (onChange) {
        onChange(rows.map(function (r) { return r.values.slice(); }), getAggregate());
      }
    }

    // ── Public: getRows ────────────────────────────────────────────────────────
    function getRows() {
      return rows.map(function (r) { return r.values.slice(); });
    }

    // ── Public: getAggregate ───────────────────────────────────────────────────
    function getAggregate() {
      return computeAggregate();
    }

    // ── Public: destroy ────────────────────────────────────────────────────────
    function destroy() {
      // Remove THIS instance's submit handler (registered below as `handler`).
      // `handler` is hoisted (var) and assigned by the time destroy() can run.
      if (form && typeof handler === 'function') {
        form.removeEventListener('submit', handler);
        if (form._msrSubmitHandlers) {
          form._msrSubmitHandlers = form._msrSubmitHandlers.filter(function (h) {
            return h !== handler;
          });
        }
      }
      mount.innerHTML = '';
      rows = [];
    }

    /**
     * Pre-submit sync: re-index serialize names OR sync the hidden output value.
     * This is the submit-time serialization step — called right before POST.
     *
     * For serialize mode: ensures PHP receives a gap-free 0..n-1 array (protects
     *   against any DOM manipulation between last edit and submit).
     * For sum/average mode: re-computes aggregate and writes it to hiddenOutput.value
     *   so the POST value is always up-to-date even if the user never triggered 'input'.
     */
    function preSubmitSync() {
      if (mode === 'serialize') {
        reindexSerializeNames();
      } else {
        syncHiddenOutput();
      }
    }

    // Wire form submit listener for pre-submit sync
    if (form) {
      var handler = function () { preSubmitSync(); };
      form.addEventListener('submit', handler);
      // Store on form element so destroy() can unregister without a closure ref issue
      // (multiple instances may share the same form — each registers its own handler)
      form._msrSubmitHandlers = form._msrSubmitHandlers || [];
      form._msrSubmitHandlers.push(handler);
    }

    // ── Seed with initial rows or minRows blank rows ───────────────────────────
    var seedRows = (initialRows && initialRows.length > 0) ? initialRows : [];
    var startCount = Math.max(minRows, seedRows.length);
    for (var i = 0; i < startCount; i++) {
      addRow(seedRows[i] || null);
    }

    // Wire add button
    addBtn.addEventListener('click', function () {
      addRow(null);
    });

    // Initial readout
    updateReadout();

    // Return public instance API
    return {
      addRow:       addRow,
      getRows:      getRows,
      getAggregate: getAggregate,
      destroy:      destroy,
    };
  }

  // ── Expose namespace ─────────────────────────────────────────────────────────
  global.MultiSubmitReads = { init: init };

}(window));
