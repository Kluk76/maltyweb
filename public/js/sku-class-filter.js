'use strict';
(function () {

  var PREF_ENDPOINT = '/api/ui-pref.php';
  var csrf = window.SKUF_CSRF || window.WH_CSRF || '';

  function applyFilter(value) {
    // 1. Rows/cards with data-sku-class attribute
    var items = document.querySelectorAll('[data-sku-class]');
    for (var i = 0; i < items.length; i++) {
      var el = items[i];
      if (value === 'all' || el.getAttribute('data-sku-class') === value) {
        el.classList.remove('skuf-hidden');
      } else {
        el.classList.add('skuf-hidden');
      }
    }
    // 2. Filterable selects
    var selects = document.querySelectorAll('select[data-sku-filterable]');
    for (var j = 0; j < selects.length; j++) {
      filterSelect(selects[j], value);
    }
  }

  function filterSelect(sel, value) {
    // Cache original options on first call
    if (!sel._skufOrigOptions) {
      sel._skufOrigOptions = Array.prototype.slice.call(sel.options);
    }
    var currentVal = sel.value;
    // Rebuild options
    while (sel.options.length > 0) sel.remove(0);
    var firstOpt = null;
    for (var k = 0; k < sel._skufOrigOptions.length; k++) {
      var opt = sel._skufOrigOptions[k];
      var optClass = opt.getAttribute('data-sku-class');
      // Keep options with no data-sku-class (e.g. placeholder), or matching value
      if (!optClass || value === 'all' || optClass === value) {
        sel.add(opt.cloneNode(true));
        if (firstOpt === null) firstOpt = opt.value;
      }
    }
    // Restore selection or reset to first option
    var found = false;
    for (var m = 0; m < sel.options.length; m++) {
      if (sel.options[m].value === currentVal) { sel.value = currentVal; found = true; break; }
    }
    if (!found && sel.options.length > 0) sel.selectedIndex = 0;
  }

  function persistPref(value, isRetry) {
    // Lazy re-read in case the partial's <script> tag ran after this IIFE evaluated csrf
    if (!csrf) csrf = window.SKUF_CSRF || '';
    var fd = new FormData();
    fd.append('key', 'sku_class_filter');
    fd.append('value', value);
    fd.append('csrf', csrf);
    fetch(PREF_ENDPOINT, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok === false && data.reason === 'expired' && !isRetry) {
          csrf = data.csrf || csrf;
          persistPref(value, true);
        } else if (data && !data.ok) {
          console.warn('[sku-class-filter] pref persist failed:', data);
        }
      })
      .catch(function (e) { console.warn('[sku-class-filter] pref persist error:', e); });
  }

  function init() {
    var roots = document.querySelectorAll('.skuf');
    for (var i = 0; i < roots.length; i++) {
      (function (root) {
        var currentValue = root.getAttribute('data-skuf-value') || 'Neb';
        // Run initial filter
        applyFilter(currentValue);

        var buttons = root.querySelectorAll('.skuf-btn');
        for (var b = 0; b < buttons.length; b++) {
          buttons[b].addEventListener('click', function () {
            var newValue = this.getAttribute('data-skuf-val');
            if (newValue === currentValue) return;
            currentValue = newValue;
            // Update active button
            for (var k = 0; k < buttons.length; k++) {
              var isActive = buttons[k].getAttribute('data-skuf-val') === newValue;
              buttons[k].classList.toggle('skuf-btn-active', isActive);
              buttons[k].setAttribute('aria-pressed', isActive ? 'true' : 'false');
            }
            // Update data attribute
            root.setAttribute('data-skuf-value', newValue);
            // Apply filter
            applyFilter(newValue);
            // Persist
            persistPref(newValue, false);
            // Dispatch event
            document.dispatchEvent(new CustomEvent('skufilter:change', { bubbles: true, detail: { value: newValue } }));
          });
        }
      })(roots[i]);
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    init();
  });

  // Global escape hatch for other scripts
  window.SkuClassFilter = { apply: applyFilter };

})();
