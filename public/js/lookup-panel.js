'use strict';
/**
 * lookup-panel.js — Shared interactivity for collapsible lookup panels.
 *
 * Handles: toggle, tab switching, fetch, and two renderers
 * (packaging, brewing). Driven by data-endpoint and data-type on the
 * root .lookup-panel element.
 *
 * No inline JS, no inline CSS, no ES modules (IIFE-scoped).
 */

(function () {

  // ── Constants ─────────────────────────────────────────────────────────────
  var RUN_TYPE_LABELS = {
    bot:   'Bouteille',
    can:   'Canette',
    can33: 'Canette',
    keg:   'Fût',
    cuv:   'Cuve de service',
  };

  // ── Helpers ───────────────────────────────────────────────────────────────

  function esc(v) {
    if (v == null) return '';
    return String(v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function nullCell(v) {
    if (v == null || v === '') return '<span class="lp-null">—</span>';
    return esc(v);
  }

  function fmtNum(v, decimals) {
    if (v == null) return '<span class="lp-null">—</span>';
    return parseFloat(v).toFixed(decimals != null ? decimals : 2);
  }

  function diffMinutes(start, end) {
    if (!start || !end) return null;
    var s = new Date(start);
    var e = new Date(end);
    if (isNaN(s) || isNaN(e)) return null;
    return Math.round((e - s) / 60000);
  }

  function todayISO() {
    var d = new Date();
    var mm = String(d.getMonth() + 1).padStart(2, '0');
    var dd = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + mm + '-' + dd;
  }

  // ── Panel toggle ──────────────────────────────────────────────────────────

  function initToggle(panel) {
    var btn  = panel.querySelector('.lp-toggle');
    var body = panel.querySelector('.lp-body');
    if (!btn || !body) return;

    btn.addEventListener('click', function () {
      var open = panel.classList.toggle('lp-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        body.removeAttribute('hidden');
      } else {
        body.setAttribute('hidden', '');
      }
    });
  }

  // ── Tab switching ─────────────────────────────────────────────────────────

  function initTabs(panel) {
    var tabs  = panel.querySelectorAll('.lp-tab');
    var panes = panel.querySelectorAll('.lp-tab-pane');

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        tabs.forEach(function (t) {
          t.classList.remove('lp-tab-active');
          t.setAttribute('aria-selected', 'false');
        });
        panes.forEach(function (p) { p.setAttribute('hidden', ''); });

        tab.classList.add('lp-tab-active');
        tab.setAttribute('aria-selected', 'true');

        var paneId = tab.getAttribute('aria-controls');
        if (paneId) {
          var pane = document.getElementById(paneId);
          if (pane) pane.removeAttribute('hidden');
        }
      });
    });
  }

  // ── Rendering ─────────────────────────────────────────────────────────────

  function renderError(container, msg) {
    container.innerHTML = '<div class="lp-error">' + esc(msg) + '</div>';
  }

  function renderEmpty(container) {
    container.innerHTML = '<p class="lp-empty">Aucun résultat trouvé.</p>';
  }

  // Packaging renderer
  function renderPackaging(container, data) {
    if (!data.events || data.events.length === 0) {
      renderEmpty(container);
      return;
    }

    var co2o2 = data.co2o2 || {};
    var s     = data.summary || {};

    var rows = data.events.map(function (ev) {
      var gas    = co2o2[String(ev.id)] || null;
      var remark = '';
      if (ev.reuses_packaging_id_fk != null) {
        remark += '<span class="lp-reuse-badge">réutilisation</span>';
      }
      return '<tr>'
        + '<td>' + esc(ev.event_date) + '</td>'
        + '<td>' + esc(ev.sku_code) + '</td>'
        + '<td>' + nullCell(ev.batch) + '</td>'
        + '<td>' + esc(RUN_TYPE_LABELS[ev.run_type] || ev.run_type) + '</td>'
        + '<td>' + nullCell(ev.prod_total_units) + '</td>'
        + '<td>' + fmtNum(ev.vendable_hl, 4) + '</td>'
        + '<td>' + fmtNum(ev.loss_kpi_hl, 4) + '</td>'
        + '<td>' + (gas ? fmtNum(gas.avg_co2, 3) : '<span class="lp-null">—</span>') + '</td>'
        + '<td>' + (gas ? fmtNum(gas.avg_o2, 2)  : '<span class="lp-null">—</span>') + '</td>'
        + '<td>' + (remark || '<span class="lp-null">—</span>') + '</td>'
        + '</tr>';
    }).join('');

    // Summary bar
    var lossHl      = parseFloat(s.total_loss_kpi_hl) || 0;
    var vendableHl  = parseFloat(s.total_vendable_hl) || 0;
    var lossTotal   = vendableHl + lossHl;
    var lossPct     = lossTotal > 0 ? ((lossHl / lossTotal) * 100).toFixed(1) : '0.0';

    var summary = '<div class="lp-summary">'
      + s.n_events + ' événement' + (s.n_events !== 1 ? 's' : '')
      + ' &middot; ' + s.total_units + ' unités'
      + ' &middot; ' + vendableHl.toFixed(4) + ' hl vendable'
      + ' &middot; ' + lossPct + '% perte'
      + '</div>';

    container.innerHTML = summary
      + '<div class="lp-table-wrap"><table class="lp-table">'
      + '<thead><tr>'
      + '<th>Date</th><th>SKU</th><th>Lot</th><th>Type</th>'
      + '<th>Unités prod.</th><th>Vendable HL</th><th>Perte HL</th>'
      + '<th>CO&#x2082; moy.</th><th>O&#x2082; moy.</th><th>Remarque</th>'
      + '</tr></thead>'
      + '<tbody>' + rows + '</tbody>'
      + '</table></div>';
  }

  // Brewing renderer — day mode
  function renderBrewingDay(container, data) {
    if (!data.brews || data.brews.length === 0) {
      renderEmpty(container);
      return;
    }

    var rows = data.brews.map(function (br) {
      var t1     = (br.timings && br.timings[0]) ? br.timings[0] : null;
      var start  = t1 ? t1.brew_start : null;
      var end    = t1 ? t1.brew_end   : null;
      var dur    = diffMinutes(start, end);
      return '<tr>'
        + '<td>' + esc(br.event_date) + '</td>'
        + '<td>' + nullCell(br.recipe_name) + '</td>'
        + '<td>' + esc(br.batch) + '</td>'
        + '<td>' + nullCell(br.cct) + '</td>'
        + '<td>' + nullCell(start) + '</td>'
        + '<td>' + nullCell(end) + '</td>'
        + '<td>' + (dur != null ? dur + ' min' : '<span class="lp-null">—</span>') + '</td>'
        + '</tr>';
    }).join('');

    container.innerHTML = '<div class="lp-table-wrap"><table class="lp-table">'
      + '<thead><tr>'
      + '<th>Date</th><th>Recette</th><th>Lot</th><th>CCT</th>'
      + '<th>Début</th><th>Fin</th><th>Durée</th>'
      + '</tr></thead>'
      + '<tbody>' + rows + '</tbody>'
      + '</table></div>';
  }

  // Brewing renderer — batch mode
  function renderBrewingBatch(container, data) {
    if (!data.brews || data.brews.length === 0) {
      renderEmpty(container);
      return;
    }

    // Header table
    var headerRows = data.brews.map(function (br) {
      var t1    = (br.timings && br.timings[0]) ? br.timings[0] : null;
      var start = t1 ? t1.brew_start : null;
      var end   = t1 ? t1.brew_end   : null;
      var dur   = diffMinutes(start, end);
      return '<tr>'
        + '<td>' + esc(br.event_date) + '</td>'
        + '<td>' + nullCell(br.recipe_name) + '</td>'
        + '<td>' + esc(br.batch) + '</td>'
        + '<td>' + nullCell(br.cct) + '</td>'
        + '<td>' + nullCell(start) + '</td>'
        + '<td>' + nullCell(end) + '</td>'
        + '<td>' + (dur != null ? dur + ' min' : '<span class="lp-null">—</span>') + '</td>'
        + '</tr>';
    }).join('');

    var headerTable = '<div class="lp-table-wrap"><table class="lp-table">'
      + '<thead><tr>'
      + '<th>Date</th><th>Recette</th><th>Lot</th><th>CCT</th>'
      + '<th>Début</th><th>Fin</th><th>Durée</th>'
      + '</tr></thead>'
      + '<tbody>' + headerRows + '</tbody>'
      + '</table></div>';

    // Gravity sub-table
    var gravRows = (data.gravity || []).map(function (g) {
      return '<tr>'
        + '<td>' + esc(g.brew) + '</td>'
        + '<td>' + esc(g.event_type) + '</td>'
        + '<td>' + fmtNum(g.firstwort_gravity, 3) + '</td>'
        + '<td>' + fmtNum(g.final_gravity, 3) + '</td>'
        + '<td>' + fmtNum(g.final_volume, 2) + '</td>'
        + '<td>' + fmtNum(g.batch_dilution, 3) + '</td>'
        + '</tr>';
    }).join('');

    var gravSection = '';
    if (gravRows) {
      gravSection = '<h4 class="lp-sub-heading">Gravimétrie</h4>'
        + '<div class="lp-table-wrap"><table class="lp-table">'
        + '<thead><tr>'
        + '<th>Brassage</th><th>Étape</th><th>Densité firstwort</th>'
        + '<th>Densité finale</th><th>Volume final (hl)</th><th>Dilution</th>'
        + '</tr></thead>'
        + '<tbody>' + gravRows + '</tbody>'
        + '</table></div>';
    }

    // Ingredients sub-table
    var ingRows = (data.ingredients || []).map(function (i) {
      var name = (i.mi_name != null && i.mi_name !== '') ? i.mi_name : i.raw_name;
      return '<tr>'
        + '<td>' + esc(i.line_idx) + '</td>'
        + '<td>' + esc(i.category) + '</td>'
        + '<td>' + nullCell(name) + '</td>'
        + '<td>' + fmtNum(i.qty, 3) + '</td>'
        + '<td>' + nullCell(i.unit) + '</td>'
        + '<td>' + nullCell(i.lot) + '</td>'
        + '</tr>';
    }).join('');

    var ingSection = '';
    if (ingRows) {
      ingSection = '<h4 class="lp-sub-heading">Ingrédients</h4>'
        + '<div class="lp-table-wrap"><table class="lp-table">'
        + '<thead><tr>'
        + '<th>#</th><th>Catégorie</th><th>Ingrédient</th>'
        + '<th>Qté</th><th>Unité</th><th>Lot</th>'
        + '</tr></thead>'
        + '<tbody>' + ingRows + '</tbody>'
        + '</table></div>';
    }

    container.innerHTML = headerTable + gravSection + ingSection;
  }

  // ── Search submit ─────────────────────────────────────────────────────────

  function buildParams(panel, mode) {
    var params = new URLSearchParams();
    params.set('mode', mode);

    if (mode === 'day') {
      // Simpler: just look for the date input in the day pane
      var dayPane = panel.querySelector('[id$="-pane-day"]');
      if (dayPane) {
        var di = dayPane.querySelector('.lp-date-input');
        if (di) params.set('date', di.value);
      }
    } else {
      // batch: collect all named inputs/selects in the batch pane
      var batchPane = panel.querySelector('[id$="-pane-batch"]');
      if (batchPane) {
        var inputs = batchPane.querySelectorAll('input[name], select[name]');
        inputs.forEach(function (inp) {
          if (inp.name) params.set(inp.name, inp.value);
        });
      }
    }

    return params;
  }

  function doSearch(panel, mode) {
    var endpoint  = panel.getAttribute('data-endpoint') || '';
    var type      = panel.getAttribute('data-type') || '';
    var panelId   = panel.id;
    var container = document.getElementById(panelId + '-results');
    if (!container) return;

    var params = buildParams(panel, mode);
    var url    = endpoint + '?' + params.toString();

    container.innerHTML = '<p class="lp-loading">Chargement…</p>';

    fetch(url, { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data.ok) {
          renderError(container, data.error || 'Erreur inconnue.');
          return;
        }
        if (type === 'packaging') {
          renderPackaging(container, data);
        } else if (type === 'brewing') {
          if (mode === 'batch') {
            renderBrewingBatch(container, data);
          } else {
            renderBrewingDay(container, data);
          }
        } else {
          container.innerHTML = '<pre>' + esc(JSON.stringify(data, null, 2)) + '</pre>';
        }
      })
      .catch(function (err) {
        renderError(container, 'Erreur réseau : ' + err.message);
      });
  }

  // ── Search button + Enter key wiring ──────────────────────────────────────

  function initSearch(panel) {
    panel.addEventListener('click', function (e) {
      var btn = e.target.closest('.lp-search-btn');
      if (!btn) return;
      var mode = btn.getAttribute('data-mode') || 'day';
      doSearch(panel, mode);
    });

    // Enter key in text inputs
    panel.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      var inp = e.target;
      if (!inp.matches('.lp-text-input, .lp-date-input')) return;

      // Find the search button in the active pane
      var activePane = panel.querySelector('.lp-tab-pane:not([hidden])');
      if (!activePane) return;
      var btn = activePane.querySelector('.lp-search-btn');
      if (btn) btn.click();
    });
  }

  // ── Date defaults ─────────────────────────────────────────────────────────

  function setDateDefaults() {
    var today = todayISO();
    var inputs = document.querySelectorAll('.lookup-panel .lp-date-input');
    inputs.forEach(function (inp) {
      if (!inp.value) inp.value = today;
    });
  }

  // ── Init all panels ───────────────────────────────────────────────────────

  function initAll() {
    var panels = document.querySelectorAll('.lookup-panel');
    panels.forEach(function (panel) {
      initToggle(panel);
      initTabs(panel);
      initSearch(panel);
    });
    setDateDefaults();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

}());
