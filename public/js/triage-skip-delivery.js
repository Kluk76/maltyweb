/**
 * triage-skip-delivery.js
 *
 * Handles the skip-delivery checkbox UX across three contexts:
 *
 *  1. Per-line alias form (triage.php)  — .alias-skip-delivery-cb
 *  2. Chip forms in the create modal    — #modal-chip-skip (fills .chip-qty/price-hidden)
 *  3. All-MIs list in the create modal  — #milist-skip (fills .milist-qty/price-hidden)
 *
 * When skip is checked:
 *  - qty/price inputs in scope become disabled + visually greyed (class --disabled)
 *  - required attribute removed so the form submits without them
 * When skip is unchecked:
 *  - inputs re-enabled, required restored (if they had no prefilled value)
 */
(function () {
  'use strict';

  /* ── 1. Per-line alias form skip checkbox ──────────────────────────────────── */
  document.addEventListener('change', function (e) {
    if (!e.target.matches('.alias-skip-delivery-cb')) return;
    const form   = e.target.closest('.line-alias-form');
    if (!form) return;
    const skip   = e.target.checked;
    const qtyEl  = form.querySelector('input[name="qty"]');
    const priceEl = form.querySelector('input[name="unit_price"]');
    [qtyEl, priceEl].forEach(function (el) {
      if (!el) return;
      el.disabled = skip;
      el.classList.toggle('line-alias-form__num--disabled', skip);
      if (skip) {
        el.removeAttribute('required');
      } else {
        // Only restore required if the field was empty (i.e. not pre-filled)
        if (el.value === '') el.setAttribute('required', '');
      }
    });
  });

  /* ── 2. Similar-chip forms — shared qty/price inputs sync ─────────────────── */
  // Fill hidden qty/price/skip fields on every chip form before submission.
  // The inputs live outside the form (shared row), so we sync on submit.
  document.addEventListener('submit', function (e) {
    var form = e.target;

    // Chip forms (similar MI section in create modal)
    if (form.querySelector('.chip-qty-hidden') !== null) {
      var qtyShared   = document.getElementById('modal-chip-qty');
      var priceShared = document.getElementById('modal-chip-price');
      var skipShared  = document.getElementById('modal-chip-skip');
      var isSkip = skipShared && skipShared.checked;

      if (!isSkip) {
        // Validate shared inputs
        if (qtyShared && (!qtyShared.value || parseFloat(qtyShared.value) <= 0)) {
          e.preventDefault();
          qtyShared.focus();
          qtyShared.setCustomValidity('Qté requise');
          qtyShared.reportValidity();
          qtyShared.setCustomValidity('');
          return;
        }
        if (priceShared && priceShared.value === '') {
          e.preventDefault();
          priceShared.focus();
          priceShared.setCustomValidity('Prix requis');
          priceShared.reportValidity();
          priceShared.setCustomValidity('');
          return;
        }
      }

      var qtyHidden   = form.querySelector('.chip-qty-hidden');
      var priceHidden = form.querySelector('.chip-price-hidden');
      var skipHidden  = form.querySelector('.chip-skip-hidden');
      if (qtyHidden)   qtyHidden.value   = isSkip ? '' : (qtyShared   ? qtyShared.value   : '');
      if (priceHidden) priceHidden.value  = isSkip ? '' : (priceShared ? priceShared.value : '');
      if (skipHidden)  skipHidden.value   = isSkip ? '1' : '';
    }

    // All-MIs list forms (milist section in create modal)
    if (form.querySelector('.milist-qty-hidden') !== null) {
      var qtyMl    = document.getElementById('milist-qty');
      var priceMl  = document.getElementById('milist-price');
      var skipMl   = document.getElementById('milist-skip');
      var isMlSkip = skipMl && skipMl.checked;

      if (!isMlSkip) {
        if (qtyMl && (!qtyMl.value || parseFloat(qtyMl.value) <= 0)) {
          e.preventDefault();
          qtyMl.focus();
          qtyMl.setCustomValidity('Qté requise');
          qtyMl.reportValidity();
          qtyMl.setCustomValidity('');
          return;
        }
        if (priceMl && priceMl.value === '') {
          e.preventDefault();
          priceMl.focus();
          priceMl.setCustomValidity('Prix requis');
          priceMl.reportValidity();
          priceMl.setCustomValidity('');
          return;
        }
      }

      var qtyH   = form.querySelector('.milist-qty-hidden');
      var priceH = form.querySelector('.milist-price-hidden');
      var skipH  = form.querySelector('.milist-skip-hidden');
      if (qtyH)   qtyH.value   = isMlSkip ? '' : (qtyMl   ? qtyMl.value   : '');
      if (priceH) priceH.value  = isMlSkip ? '' : (priceMl ? priceMl.value : '');
      if (skipH)  skipH.value   = isMlSkip ? '1' : '';
    }
  });

  /* ── 3. Create modal skip checkbox — grey out delivery section ─────────────── */
  document.addEventListener('change', function (e) {
    if (!e.target.matches('.create-skip-delivery-cb')) return;
    var skip = e.target.checked;
    var row  = document.querySelector('.mi-modal-delivery-row');
    if (!row) return;
    var qtyEl   = row.querySelector('input[name="qty"]');
    var priceEl = row.querySelector('input[name="unit_price"]');
    [qtyEl, priceEl].forEach(function (el) {
      if (!el) return;
      el.disabled = skip;
      el.classList.toggle('mi-modal-input--disabled', skip);
      if (skip) {
        el.removeAttribute('required');
      } else {
        if (el.value === '') el.setAttribute('required', '');
      }
    });
  });

  /* ── 4. Modal chip skip checkbox — grey out shared inputs ──────────────────── */
  document.addEventListener('change', function (e) {
    if (!e.target.matches('.modal-chip-skip-cb')) return;
    var skip = e.target.checked;
    var qtyEl   = document.getElementById('modal-chip-qty');
    var priceEl = document.getElementById('modal-chip-price');
    [qtyEl, priceEl].forEach(function (el) {
      if (!el) return;
      el.disabled = skip;
      el.classList.toggle('mi-modal-similar__qp-input--disabled', skip);
      if (skip) el.removeAttribute('required');
      else if (el.value === '') el.setAttribute('required', '');
    });
  });

  /* ── 5. Category dropdown — reveal "new category" text input ──────────────── */
  document.addEventListener('change', function (e) {
    if (!e.target.matches('#mi_create_cat')) return;
    var isNew   = e.target.value === '__NEW__';
    var input   = document.getElementById('mi_create_cat_new');
    var hint    = document.getElementById('mi_create_cat_new_hint');
    if (!input) return;
    input.style.display = isNew ? '' : 'none';
    if (hint) hint.style.display = isNew ? '' : 'none';
    input.required = isNew;
    if (isNew) {
      // Clear the select value so server receives empty category, forcing category_new path.
      // We temporarily disable the select required so the form still submits.
      e.target.removeAttribute('required');
      e.target.value = '';
      input.focus();
    } else {
      e.target.setAttribute('required', '');
      input.required = false;
      input.value = '';
    }
  });

  /* ── 5b. Validate new-category form submit ─────────────────────────────────── */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches('.mi-modal-form')) return;
    var catSel  = form.querySelector('#mi_create_cat');
    var newInp  = form.querySelector('#mi_create_cat_new');
    if (!catSel || !newInp) return;
    // If category_new is filled but category is blank, ensure category gets set to __NEW__
    // so the backend can distinguish new-cat path. Backend reads category_new when category=''
    // and category_new is non-empty. Nothing extra needed here.
  });

  /* ── 6. Milist skip checkbox — grey out shared inputs ──────────────────────── */
  document.addEventListener('change', function (e) {
    if (!e.target.matches('.milist-skip-cb')) return;
    var skip = e.target.checked;
    var qtyEl   = document.getElementById('milist-qty');
    var priceEl = document.getElementById('milist-price');
    [qtyEl, priceEl].forEach(function (el) {
      if (!el) return;
      el.disabled = skip;
      el.classList.toggle('mi-modal-milist__qp-input--disabled', skip);
      if (skip) el.removeAttribute('required');
      else if (el.value === '') el.setAttribute('required', '');
    });
  });

})();
