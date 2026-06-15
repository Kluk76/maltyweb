/**
 * expeditions-line-status.js
 *
 * Handles per-line status dropdowns on the order edit/view form.
 * Each line rendered by expeditions-form.js may carry a data-line-id + data-line-status
 * attribute set by the PHP server. This module scans for those controls and wires
 * POST requests to /api/expeditions-line-status.php.
 *
 * Label map — NEVER expose DB enum literals to users:
 *   to_fulfil → "À livrer"
 *   non_livre → "Non livré"
 *   rupture   → "Rupture"
 *
 * CSRF: reads window.EXP_CSRF (set by the parent page) and auto-refreshes on
 * 'expired' response (one retry, then surfaces an error).
 *
 * Loaded via <script> on the form view; does nothing if no line-status controls
 * are present in the DOM.
 */
(function () {
  'use strict';

  var LABEL_MAP = {
    to_fulfil:  'À livrer',
    non_livre:  'Non livré',
    rupture:    'Rupture',
  };

  // Colour modifier classes — CSS drives actual colours via .exp-line-status--<key>
  var ALLOWED = Object.keys(LABEL_MAP);

  var currentCsrf = (window.EXP_CSRF || '');

  /**
   * POST a line-status change.
   * @param {number} lineId
   * @param {string} newStatus
   * @param {string} csrf
   * @param {function(Error|null, object|null)} cb
   */
  function postLineStatus(lineId, newStatus, csrf, cb) {
    fetch('/api/expeditions-line-status.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ csrf: csrf, line_id: lineId, status: newStatus }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) { cb(null, data); })
    .catch(function (err) { cb(err, null); });
  }

  /**
   * Update the visual state of a select + its sibling chip after a successful write.
   * @param {HTMLSelectElement} sel
   * @param {string} newStatus
   */
  function applyStatus(sel, newStatus) {
    sel.value = newStatus;
    sel.dataset.currentStatus = newStatus;

    // Update sibling chip label + modifier class
    var chip = sel.closest('.exp-line-status-wrap') &&
               sel.closest('.exp-line-status-wrap').querySelector('.exp-line-status-chip');
    if (chip) {
      chip.textContent = LABEL_MAP[newStatus] || newStatus;
      ALLOWED.forEach(function (k) {
        chip.classList.remove('exp-line-status-chip--' + k);
      });
      chip.classList.add('exp-line-status-chip--' + newStatus);
    }

    // Update the row wrapper's data attribute for CSS row-level colouring
    var row = sel.closest('.exp-line-row');
    if (row) {
      ALLOWED.forEach(function (k) {
        row.classList.remove('exp-line-row--status-' + k);
      });
      row.classList.add('exp-line-row--status-' + newStatus);
    }
  }

  /**
   * Wire up one line-status <select> element.
   * @param {HTMLSelectElement} sel
   */
  function wireSelect(sel) {
    sel.addEventListener('change', function () {
      var lineId    = parseInt(sel.dataset.lineId, 10);
      var newStatus = sel.value;

      if (!lineId || ALLOWED.indexOf(newStatus) === -1) {
        sel.value = sel.dataset.currentStatus || 'to_fulfil';
        return;
      }

      sel.disabled = true;

      function doRequest(csrf) {
        postLineStatus(lineId, newStatus, csrf, function (err, data) {
          sel.disabled = false;
          if (err || !data) {
            alert('Erreur réseau — statut non mis à jour.');
            sel.value = sel.dataset.currentStatus || 'to_fulfil';
            return;
          }
          if (!data.ok) {
            if (data.reason === 'expired' && data.csrf) {
              currentCsrf = data.csrf;
              doRequest(currentCsrf);
              return;
            }
            alert('Erreur : ' + (data.error || 'Statut non mis à jour.'));
            sel.value = sel.dataset.currentStatus || 'to_fulfil';
            return;
          }
          // Success
          if (data.csrf) currentCsrf = data.csrf;
          applyStatus(sel, data.status);
        });
      }

      doRequest(currentCsrf);
    });
  }

  /**
   * Build and insert a line-status control into an exp-line-row.
   * Called by the form initialisation below.
   * @param {HTMLElement} row  — .exp-line-row DOM element
   * @param {number}      lineId
   * @param {string}      currentStatus
   * @param {boolean}     readOnly
   */
  function buildLineStatusControl(row, lineId, currentStatus, readOnly) {
    var wrap = document.createElement('div');
    wrap.className = 'exp-line-status-wrap op-form__field';

    var label = document.createElement('label');
    label.className = 'op-form__label';
    label.textContent = 'Statut ligne';
    label.style.fontSize = '0.6rem';

    var selId = 'exp-lstat-' + lineId;
    label.setAttribute('for', selId);
    wrap.appendChild(label);

    if (readOnly) {
      // Read-only: show chip only (no select)
      var chip = document.createElement('span');
      chip.className = 'exp-line-status-chip exp-line-status-chip--' + currentStatus;
      chip.textContent = LABEL_MAP[currentStatus] || currentStatus;
      wrap.appendChild(chip);
    } else {
      // Editable: show both select (functional) + chip (visual hint)
      var sel = document.createElement('select');
      sel.id = selId;
      sel.className = 'exp-line-status-select';
      sel.dataset.lineId = String(lineId);
      sel.dataset.currentStatus = currentStatus;
      // 44px touch target via CSS min-height on the select
      sel.setAttribute('aria-label', 'Statut de la ligne — À livrer, Non livré, ou Rupture');

      ALLOWED.forEach(function (k) {
        var opt = document.createElement('option');
        opt.value = k;
        opt.textContent = LABEL_MAP[k];
        if (k === currentStatus) opt.selected = true;
        sel.appendChild(opt);
      });

      var chip = document.createElement('span');
      chip.className = 'exp-line-status-chip exp-line-status-chip--' + currentStatus;
      chip.textContent = LABEL_MAP[currentStatus] || currentStatus;
      chip.setAttribute('aria-hidden', 'true');

      wrap.appendChild(sel);
      wrap.appendChild(chip);

      wireSelect(sel);
    }

    row.classList.add('exp-line-row--status-' + currentStatus);

    // Insert after the comment field (last child before end of row)
    row.appendChild(wrap);
  }

  // ── Initialise on DOMContentLoaded ────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    // Line-status controls are initialised from window.EXP_LINE_STATUS_DATA,
    // an array of { line_id, status } injected by PHP alongside EXP_EDIT_LINES.
    var lineData = window.EXP_LINE_STATUS_DATA || [];
    if (!lineData.length) return;

    // Build a map: line_id → status for quick lookup when form.js creates rows
    var statusByLineId = {};
    lineData.forEach(function (d) {
      statusByLineId[d.line_id] = d.status || 'to_fulfil';
    });

    var isReadOnly = !!(window.EXP_EDIT_ORDER &&
      (window.EXP_EDIT_ORDER.status === 'shipped' ||
       window.EXP_EDIT_ORDER.status === 'cancelled'));

    // The form JS populates #exp-lines-container asynchronously via requestAnimationFrame.
    // We observe the container and inject controls as rows are added.
    var container = document.getElementById('exp-lines-container');
    if (!container) return;

    function injectIntoRow(row) {
      // The hidden sku_id input carries the row's sku_id; line_id comes from
      // a data attribute we inject via PHP into EXP_EDIT_LINES (see below).
      // We find the line_id via data-line-id on the row itself, set by the
      // form-init loop that reads EXP_EDIT_LINES.
      var lineId = parseInt(row.dataset.lineId || '0', 10);
      if (!lineId) return;
      // Already injected?
      if (row.querySelector('.exp-line-status-wrap')) return;
      var status = statusByLineId[lineId] || 'to_fulfil';
      buildLineStatusControl(row, lineId, status, isReadOnly);
    }

    // Observe for rows added by form JS
    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        m.addedNodes.forEach(function (node) {
          if (node.nodeType === 1 && node.classList.contains('exp-line-row')) {
            injectIntoRow(node);
          }
        });
      });
    });
    observer.observe(container, { childList: true });

    // Catch rows already present (e.g. pre-rendered or fast form init)
    container.querySelectorAll('.exp-line-row').forEach(injectIntoRow);
  });
}());
