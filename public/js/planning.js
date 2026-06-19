/**
 * planning.js — Progressive enhancement for the Planning calendar.
 *
 * Behaviors:
 *   1. Wort process select toggle: show/hide .pl-brewing-fields vs
 *      .pl-nonbrewing-fields based on selected wort_process value.
 *   2. Hors process checkbox toggle: show/hide .pl-reason-wrap inside the
 *      same form/container when the checkbox state changes.
 *   3. Eligible beer dropdowns for non-brewing wort forms — populated from
 *      window.PLANNING_ELIGIBILITY (server-hydrated).
 *   4. Eligible beer selects for packaging forms — populated from
 *      window.PLANNING_ELIGIBILITY[date]['packaging'].
 */
(function () {
  'use strict';

  // ── helpers ────────────────────────────────────────────────────────────────

  /** Get the date string for a form (from hidden plan_date input). */
  function getFormDate(form) {
    var inp = form.querySelector('input[name="plan_date"]');
    return inp ? inp.value : null;
  }

  /**
   * Populate a <select> with eligible beers for a given process+date from
   * window.PLANNING_ELIGIBILITY. Clears existing options first.
   *
   * @param {HTMLSelectElement} sel      Target select element.
   * @param {string}            date     'YYYY-MM-DD'
   * @param {string}            process  'racking'|'kze'|'dry_hopping'|'packaging'
   * @param {boolean}           includeOverride  Append an "⚠ Autre (hors process)" option.
   */
  function populateEligibleSelect(sel, date, process, includeOverride) {
    var elig = (window.PLANNING_ELIGIBILITY && window.PLANNING_ELIGIBILITY[date])
      ? (window.PLANNING_ELIGIBILITY[date][process] || []) : [];

    // Clear
    while (sel.options.length) sel.remove(0);

    // Placeholder
    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = '— Recette éligible —';
    sel.appendChild(placeholder);

    // Eligible options — skip entries without a recipe_id (server gate cannot validate them)
    elig.forEach(function (e) {
      if (e.recipe_id == null) return;
      var opt = document.createElement('option');
      opt.value = String(e.recipe_id);
      var label = e.beer + ' — brassin ' + e.batch;
      if (e.cct_number) label += ' (CCT ' + e.cct_number + ')';
      if (e.bbt_number) label += ' (BBT ' + e.bbt_number + ')';
      opt.dataset.bbtNumber = e.bbt_number != null ? String(e.bbt_number) : '';
      opt.textContent = label;
      sel.appendChild(opt);
    });

    // If no eligible beers and includeOverride, show explanatory disabled option
    if (elig.length === 0 && includeOverride) {
      var noElig = document.createElement('option');
      noElig.disabled = true;
      noElig.textContent = '— Aucune bière éligible (garde / état cuve) —';
      sel.appendChild(noElig);
    }

    if (includeOverride) {
      var sep = document.createElement('option');
      sep.disabled = true;
      sep.textContent = '─────────────';
      sel.appendChild(sep);
      var over = document.createElement('option');
      over.value = '__hors_process__';
      over.textContent = '⚠ Autre (hors process)';
      sel.appendChild(over);
    }
  }

  // ── wort process toggle ────────────────────────────────────────────────────

  /**
   * Initialise the wort_process select toggle for a given .pl-wort-form.
   * Shows .pl-brewing-fields when value === 'brewing',
   * shows .pl-nonbrewing-fields otherwise.
   * When non-brewing, also populates the eligible beer select from ELIGIBILITY.
   */
  function initWortProcessToggle(form) {
    var sel = form.querySelector('select[name="wort_process"]');
    if (!sel) return;

    var nonBrewRecipeSel = form.querySelector('select[name="recipe_id_fk_nonbrew"]');
    var horsProcessCb    = form.querySelector('input[name="hors_process"]');

    function toggle() {
      var isBrewing     = sel.value === 'brewing';
      var brewFields    = form.querySelector('.pl-brewing-fields');
      var nonBrewFields = form.querySelector('.pl-nonbrewing-fields');
      if (brewFields)    brewFields.hidden    = !isBrewing;
      if (nonBrewFields) nonBrewFields.hidden = isBrewing;

      if (!isBrewing && nonBrewRecipeSel) {
        var date = getFormDate(form);
        var process = sel.value; // 'racking', 'kze', 'dry_hopping'
        populateEligibleSelect(nonBrewRecipeSel, date, process, true);
      }
    }

    sel.addEventListener('change', toggle);
    toggle(); // initialise on page load

    // Handle hors-process override selection
    if (nonBrewRecipeSel) {
      nonBrewRecipeSel.addEventListener('change', function () {
        if (nonBrewRecipeSel.value === '__hors_process__') {
          // Auto-check hors_process and show reason field
          if (horsProcessCb) {
            horsProcessCb.checked = true;
            horsProcessCb.dispatchEvent(new Event('change'));
          }
          // Reset the select to empty so validation fails gracefully and the
          // operator must use the hors_process path.
          nonBrewRecipeSel.value = '';
          // Mark the select with override styling
          nonBrewRecipeSel.classList.add('pl-elig-select--override');
        } else {
          nonBrewRecipeSel.classList.remove('pl-elig-select--override');
        }
      });
    }
  }

  // ── hors process checkbox toggle ───────────────────────────────────────────

  /**
   * Initialise the hors_process checkbox toggle for a given container.
   * Finds the first input[name="hors_process"] and the nearest
   * .pl-reason-wrap in the same container; toggles its `hidden` attribute.
   */
  function initHorsProcessToggle(container) {
    var cb   = container.querySelector('input[name="hors_process"]');
    if (!cb) return;
    var wrap = container.querySelector('.pl-reason-wrap');
    if (!wrap) return;

    function toggle() {
      wrap.hidden = !cb.checked;
    }

    cb.addEventListener('change', toggle);
    toggle(); // initialise on page load
  }

  // ── packaging beer select ──────────────────────────────────────────────────

  /**
   * Initialise the packaging recipe select for a .pl-add-form[action=add_packaging].
   * Reads eligible beers from PLANNING_ELIGIBILITY[date]['packaging'] and
   * wires the BBT hidden field to update when selection changes.
   */
  function initPackagingForm(form) {
    var recipeSel  = form.querySelector('select[name="recipe_id_fk"]');
    var bbtHidden  = form.querySelector('input[name="bbt_number"]');
    if (!recipeSel) return;

    var date = getFormDate(form);
    if (!date) return;

    // Populate from eligibility
    populateEligibleSelect(recipeSel, date, 'packaging', false);

    // Sync BBT hidden when recipe changes
    if (bbtHidden) {
      recipeSel.addEventListener('change', function () {
        var opt = recipeSel.options[recipeSel.selectedIndex];
        bbtHidden.value = (opt && opt.dataset.bbtNumber) ? opt.dataset.bbtNumber : '';
      });
    }

    // Show/hide serving-tank client field based on pkg_type
    var pkgTypeSel = form.querySelector('select[name="pkg_type"]');
    var stClientWrap = form.querySelector('.pl-serving-tank-client-field');
    var stClientSel = stClientWrap ? stClientWrap.querySelector('select[name="customer_id_fk"]') : null;
    if (pkgTypeSel && stClientWrap) {
      function toggleStClient() {
        var isServingTank = pkgTypeSel.value === 'serving_tank';
        stClientWrap.hidden = !isServingTank;
        if (!isServingTank && stClientSel) {
          stClientSel.value = '';
        }
      }
      pkgTypeSel.addEventListener('change', toggleStClient);
      toggleStClient(); // initialise on page load
    }
  }

  // ── boot ──────────────────────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', function () {
    // Wort process toggles — one per .pl-wort-form
    document.querySelectorAll('.pl-wort-form').forEach(initWortProcessToggle);

    // Hors process toggles — one per .pl-day-section (covers wort/packaging/logistics)
    document.querySelectorAll('.pl-day-section').forEach(initHorsProcessToggle);

    // Packaging recipe selects
    document.querySelectorAll('.pl-add-form').forEach(function (form) {
      var actionInput = form.querySelector('input[name="action"]');
      if (actionInput && actionInput.value === 'add_packaging') {
        initPackagingForm(form);
      }
    });

    // Confirm on reject proposal buttons
    document.querySelectorAll('.pl-proposed-reject').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        if (!confirm('Rejeter cette suggestion ?')) {
          e.preventDefault();
        }
      });
    });

    // ── P0-1: Add-form collapse/expand ────────────────────────────────────────

    // Trigger chips toggle forms open
    document.querySelectorAll('.pl-add-trigger').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var section = btn.dataset.section;
        // Find the sibling .pl-add-form with matching data-section
        var form = btn.parentElement.querySelector('.pl-add-form[data-section="' + section + '"]');
        if (!form) return;
        form.classList.add('pl-add-form--open');
        btn.classList.add('pl-add-trigger--open');
        // Focus the first focusable input in the form
        var firstInput = form.querySelector('select, input:not([type="hidden"]), textarea');
        if (firstInput) firstInput.focus();
      });
    });

    // Before each add-form submits, save last-add state to sessionStorage
    document.querySelectorAll('.pl-add-form').forEach(function (form) {
      form.addEventListener('submit', function () {
        var date = form.dataset.planDate;
        var section = form.dataset.section;
        if (date && section) {
          try {
            sessionStorage.setItem('pl-last-add', JSON.stringify({ date: date, section: section }));
          } catch (e) {}
        }
      });
    });

    // On load, reopen the form that was last submitted (after PRG redirect)
    (function () {
      var flashOk = document.querySelector('.pl-flash--ok');
      if (!flashOk) return;
      var stored;
      try { stored = JSON.parse(sessionStorage.getItem('pl-last-add') || 'null'); } catch (e) {}
      if (!stored) return;
      var form = document.querySelector(
        '.pl-add-form[data-plan-date="' + stored.date + '"][data-section="' + stored.section + '"]'
      );
      if (!form) { sessionStorage.removeItem('pl-last-add'); return; }
      form.classList.add('pl-add-form--open');
      var trigger = form.parentElement.querySelector('.pl-add-trigger[data-section="' + stored.section + '"]');
      if (trigger) trigger.classList.add('pl-add-trigger--open');
      form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      sessionStorage.removeItem('pl-last-add');
    }());

    // P1-6: Delete confirm — delegated, no inline JS
    document.addEventListener('submit', function (e) {
      var form = e.target.closest('.pl-item-card__del-form');
      if (!form) return;
      if (!confirm('Supprimer cet élément ?')) {
        e.preventDefault();
      }
    });
  });
}());
