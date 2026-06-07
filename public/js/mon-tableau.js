/* ═══════════════════════════════════════════════════════════
   mon-tableau.js — Personal KPI dashboard interactions
   Data source: window.MY_KPIS (server-injected)
   Depends on: kpi-charts.js (window.KpcCharts)
   ═══════════════════════════════════════════════════════════ */

'use strict';

(function () {
  var KD  = window.MY_KPIS;
  var kpc = window.KpcCharts;

  if (!KD || !kpc) return; /* guards if either dep is absent */

  var isAdmin = KD.is_admin;

  /* ── 1. Render all selected KPI cards ── */
  KD.trackers.forEach(function (tracker) {
    var container = document.getElementById('mt-card-' + tracker.id);
    if (!container) return;

    var result = KD.results[String(tracker.id)];
    if (!result) {
      result = {
        error: 'Résultat manquant',
        value: null, unit: null, label: tracker.label,
        delta: null, delta_label: null, tint: 'neutral',
        series: null, breakdown: null, meta: {},
      };
    }

    kpc.renderKpiCard(container, tracker, result, isAdmin);

    /* Add remove button (×) and drag handle (⠿) overlay */
    var removeBtn = document.createElement('button');
    removeBtn.className = 'kpc-card__remove';
    removeBtn.title = 'Retirer cet indicateur';
    removeBtn.setAttribute('aria-label', 'Retirer ' + kpc.escHtml(tracker.label));
    removeBtn.innerHTML = '×';
    removeBtn.addEventListener('click', function () {
      toggleTracker(tracker.id, false);
    });
    container.appendChild(removeBtn);

    var drag = document.createElement('span');
    drag.className = 'kpc-card__drag';
    drag.title = 'Réordonner';
    drag.setAttribute('aria-hidden', 'true');
    drag.innerHTML = '⠿';
    container.appendChild(drag);
  });

  /* ── 2. Picker toggle ── */
  var picker       = document.getElementById('mt-picker');
  var pickerToggle = document.getElementById('mt-picker-toggle');
  var pickerBody   = document.getElementById('mt-picker-body');

  if (pickerToggle && picker) {
    pickerToggle.addEventListener('click', function () {
      var open = picker.classList.toggle('mt-picker--open');
      pickerToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* ── 3. Tracker toggle (click on picker items) ── */
  var selectedIds = KD.trackers.map(function (t) { return t.id; });

  function toggleTracker(id, forceState) {
    var idx     = selectedIds.indexOf(id);
    var isNow   = (forceState !== undefined) ? forceState : (idx === -1);

    if (isNow && idx === -1) {
      selectedIds.push(id);
    } else if (!isNow && idx !== -1) {
      selectedIds.splice(idx, 1);
    }

    /* Update picker item appearance */
    var item = document.querySelector('.mt-tracker-item[data-tracker-id="' + id + '"]');
    if (item) {
      var check = item.querySelector('.mt-tracker-item__check');
      item.classList.toggle('mt-tracker-item--selected', isNow);
      item.classList.toggle('mt-tracker-item--unselected', !isNow);
      item.setAttribute('aria-checked', isNow ? 'true' : 'false');
      if (check) check.textContent = isNow ? '✓' : '○';
    }

    /* Auto-submit selection */
    submitSelection();
  }

  document.querySelectorAll('.mt-tracker-item').forEach(function (item) {
    function doToggle() {
      var id = parseInt(item.getAttribute('data-tracker-id'), 10);
      if (!id) return;
      var isSelected = item.classList.contains('mt-tracker-item--selected');
      toggleTracker(id, !isSelected);
    }
    item.addEventListener('click', doToggle);
    item.addEventListener('keydown', function (e) {
      if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); doToggle(); }
    });
  });

  /* ── 4. Submit selection (populate hidden inputs + submit form) ── */
  var form    = document.getElementById('mt-picker-form');
  var saveBtn = document.getElementById('mt-save-btn');

  function submitSelection() {
    if (!form) return;

    /* Remove any previously-injected tracker_ids[] inputs */
    form.querySelectorAll('input[name="tracker_ids[]"]').forEach(function (el) {
      el.parentNode.removeChild(el);
    });

    /* Inject one hidden input per selected tracker */
    selectedIds.forEach(function (id) {
      var inp = document.createElement('input');
      inp.type  = 'hidden';
      inp.name  = 'tracker_ids[]';
      inp.value = String(id);
      form.appendChild(inp);
    });

    if (saveBtn) saveBtn.disabled = true;
    form.submit();
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      submitSelection();
    });
  }

  /* ── 5. Search / filter ── */
  var searchInput = document.getElementById('mt-picker-search');
  if (searchInput) {
    searchInput.addEventListener('input', function () {
      var q = this.value.trim().toLowerCase();
      document.querySelectorAll('.mt-tracker-item').forEach(function (item) {
        var label = item.getAttribute('data-tracker-label') || '';
        item.classList.toggle('mt-tracker-item--hidden', q.length > 0 && !label.includes(q));
      });

      /* Hide / show group depending on whether any of its items are visible */
      document.querySelectorAll('.mt-picker__group').forEach(function (group) {
        var visible = group.querySelectorAll('.mt-tracker-item:not(.mt-tracker-item--hidden)');
        group.style.display = visible.length === 0 && q.length > 0 ? 'none' : '';
        /* When search is active, force-expand all groups so results are visible */
        if (q.length > 0) {
          var toggle = group.querySelector('.mt-picker__group-toggle');
          var grid   = group.querySelector('.mt-tracker-grid');
          if (toggle && grid && toggle.getAttribute('aria-expanded') === 'false') {
            toggle.setAttribute('aria-expanded', 'true');
            grid.removeAttribute('hidden');
          }
        }
      });
    });
  }

  /* ── 6. Collapsible picker category groups ── */
  document.querySelectorAll('.mt-picker__group-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var expanded = btn.getAttribute('aria-expanded') === 'true';
      var targetId = btn.getAttribute('aria-controls');
      var grid     = document.getElementById(targetId);
      btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      if (grid) {
        if (expanded) {
          grid.setAttribute('hidden', '');
        } else {
          grid.removeAttribute('hidden');
        }
      }
    });
  });

  /* ── 7. CSRF refresh from session keepalive ── */
  /* form-resilience.js (loaded by topbar) already rewrites input[name="csrf"]
     on each ping. The hidden CSRF input in the picker form is covered. */

})();
