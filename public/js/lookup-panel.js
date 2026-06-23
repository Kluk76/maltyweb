'use strict';
/**
 * lookup-panel.js — Shared interactivity for collapsible lookup panels.
 *
 * Handles: toggle, tab switching, fetch, and card-based renderers
 * (packaging, brewing). Driven by data-endpoint and data-type on the
 * root .lookup-panel element.
 *
 * After any render, displays all result cards regardless of the
 * Nébuleuse/Contract filter (which only narrows the selection dropdown).
 *
 * No inline JS, no inline CSS, no ES modules (IIFE-scoped).
 * Uses var for shared-global aliases; never const at module scope.
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

  // Classification pill HTML
  function classificationPill(cls) {
    if (!cls) return '';
    var label = cls === 'Contract' ? 'Contract' : 'Nébuleuse';
    var variant = cls === 'Contract' ? 'lp-pill-contract' : 'lp-pill-neb';
    return '<span class="lp-pill ' + variant + '">' + esc(label) + '</span>';
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

  // ── Summary strip builder ─────────────────────────────────────────────────
  // Takes the raw events array and renders the summary strip HTML. Returns the HTML string.
  function buildSummaryHtml(events, co2o2) {
    var n        = events.length;
    var totalUnits   = 0;
    var vendableHl   = 0;
    var lossHl       = 0;

    for (var i = 0; i < events.length; i++) {
      var ev = events[i];
      totalUnits += parseInt(ev.prod_total_units, 10) || 0;
      vendableHl += parseFloat(ev.vendable_hl)  || 0;
      lossHl     += parseFloat(ev.loss_kpi_hl)  || 0;
    }

    var lossTotal = vendableHl + lossHl;
    var lossPct   = lossTotal > 0 ? ((lossHl / lossTotal) * 100).toFixed(1) : '0.0';

    return '<div class="lp-summary">'
      + n + ' événement' + (n !== 1 ? 's' : '')
      + ' &middot; ' + totalUnits + ' unités'
      + ' &middot; ' + vendableHl.toFixed(4) + ' hl vendable'
      + ' &middot; ' + lossPct + '% perte'
      + '</div>';
  }

  // ── Packaging card renderer ───────────────────────────────────────────────
  function renderPackaging(container, data) {
    if (!data.events || data.events.length === 0) {
      renderEmpty(container);
      return;
    }

    var events  = data.events;
    var co2o2   = data.co2o2 || {};

    // Summary strip (always all events)
    var summaryHtml = buildSummaryHtml(events, co2o2);

    // Cards
    var cardsHtml = '';
    for (var i = 0; i < events.length; i++) {
      var ev     = events[i];
      var gas    = co2o2[String(ev.id)] || null;
      var cls    = ev.classification || 'Neb';
      var rtLabel = RUN_TYPE_LABELS[ev.run_type] || esc(ev.run_type);

      var reuseBadge = '';
      if (ev.reuses_packaging_id_fk != null) {
        reuseBadge = '<span class="lp-reuse-badge">réutilisation</span>';
      }

      var lossKpiHl  = ev.loss_kpi_hl  != null ? parseFloat(ev.loss_kpi_hl)  : null;
      var vendableHl = ev.vendable_hl   != null ? parseFloat(ev.vendable_hl)  : null;
      var lossTotal  = (vendableHl != null && lossKpiHl != null) ? vendableHl + lossKpiHl : null;
      var lossPct    = (lossTotal != null && lossTotal > 0)
                        ? ((lossKpiHl / lossTotal) * 100).toFixed(1) + '%'
                        : null;

      cardsHtml += '<div class="lp-card">'
        + '<div class="lp-card-head">'
        +   '<div class="lp-card-title-group">'
        +     '<span class="lp-card-title lp-mono">' + esc(ev.sku_code) + '</span>'
        +     '<span class="lp-card-run-type">' + esc(rtLabel) + '</span>'
        +   '</div>'
        +   '<div class="lp-card-head-right">'
        +     classificationPill(cls)
        +     reuseBadge
        +     '<span class="lp-card-date">' + esc(ev.event_date) + '</span>'
        +   '</div>'
        + '</div>'
        + '<div class="lp-stat-grid">'
        +   '<div class="lp-stat"><span class="lp-stat-label">Unités produites</span><span class="lp-stat-value">' + nullCell(ev.prod_total_units) + '</span></div>'
        +   '<div class="lp-stat"><span class="lp-stat-label">Vendable HL</span><span class="lp-stat-value lp-mono">' + fmtNum(ev.vendable_hl, 4) + '</span></div>'
        +   '<div class="lp-stat"><span class="lp-stat-label">Perte HL</span><span class="lp-stat-value lp-mono">' + fmtNum(ev.loss_kpi_hl, 4) + (lossPct ? ' <span class="lp-stat-pct">(' + lossPct + ')</span>' : '') + '</span></div>'
        +   '<div class="lp-stat"><span class="lp-stat-label">CO&#x2082; moy.</span><span class="lp-stat-value lp-mono">' + (gas ? fmtNum(gas.avg_co2, 3) : '<span class="lp-null">—</span>') + '</span></div>'
        +   '<div class="lp-stat"><span class="lp-stat-label">O&#x2082; moy.</span><span class="lp-stat-value lp-mono">' + (gas ? fmtNum(gas.avg_o2, 2) : '<span class="lp-null">—</span>') + '</span></div>'
        +   '<div class="lp-stat"><span class="lp-stat-label">Lot</span><span class="lp-stat-value">' + nullCell(ev.batch) + '</span></div>'
        + '</div>'
        + '</div>';
    }

    container.innerHTML = summaryHtml + '<div class="lp-cards">' + cardsHtml + '</div>';
  }

  // ── Packaging batch renderer (delegates to PackagingConsulter) ─────────────
  function renderPackagingBatch(container, data) {
    if (window.PackagingConsulter && typeof window.PackagingConsulter.renderBatch === 'function') {
      window.PackagingConsulter.renderBatch(container, data);
      return;
    }
    renderPackaging(container, data);
  }

  // ── Packaging day renderer (delegates to PackagingConsulter) ───────────────
  function renderPackagingDay(container, data) {
    if (window.PackagingConsulter && typeof window.PackagingConsulter.renderDay === 'function') {
      window.PackagingConsulter.renderDay(container, data);
      return;
    }
    renderPackaging(container, data);
  }

  // ── Brewing day card renderer ──────────────────────────────────────────────
  function renderBrewingDay(container, data) {
    // Feature-detect: use brewing schematic visual if available
    if (window.BrewingConsulter && typeof window.BrewingConsulter.renderDay === 'function') {
      window.BrewingConsulter.renderDay(container, data);
      return;
    }
    if (!data.brews || data.brews.length === 0) {
      renderEmpty(container);
      return;
    }

    var cardsHtml = '';
    for (var i = 0; i < data.brews.length; i++) {
      var br  = data.brews[i];
      var t1  = (br.timings && br.timings[0]) ? br.timings[0] : null;
      var start = t1 ? t1.brew_start : null;
      var end   = t1 ? t1.brew_end   : null;
      var dur   = diffMinutes(start, end);
      var cls   = br.classification || 'Neb';

      cardsHtml += '<div class="lp-card">'
        + '<div class="lp-card-head">'
        +   '<div class="lp-card-title-group">'
        +     '<span class="lp-card-title">' + esc(br.recipe_name || br.beer) + '</span>'
        +     '<span class="lp-card-run-type">Lot ' + esc(br.batch) + '</span>'
        +   '</div>'
        +   '<div class="lp-card-head-right">'
        +     classificationPill(cls)
        +     '<span class="lp-card-date">' + esc(br.event_date) + '</span>'
        +   '</div>'
        + '</div>'
        + '<div class="lp-stat-grid">'
        +   '<div class="lp-stat"><span class="lp-stat-label">CCT</span><span class="lp-stat-value lp-mono">' + nullCell(br.cct) + '</span></div>'
        +   '<div class="lp-stat"><span class="lp-stat-label">Début</span><span class="lp-stat-value lp-mono">' + nullCell(start) + '</span></div>'
        +   '<div class="lp-stat"><span class="lp-stat-label">Fin</span><span class="lp-stat-value lp-mono">' + nullCell(end) + '</span></div>'
        +   '<div class="lp-stat"><span class="lp-stat-label">Durée</span><span class="lp-stat-value lp-mono">' + (dur != null ? dur + ' min' : '<span class="lp-null">—</span>') + '</span></div>'
        + '</div>'
        + '</div>';
    }

    container.innerHTML = '<div class="lp-cards">' + cardsHtml + '</div>';
  }

  // ── Brewing batch card renderer ────────────────────────────────────────────
  function renderBrewingBatch(container, data) {
    // Feature-detect: use brewing schematic visual if available
    if (window.BrewingConsulter && typeof window.BrewingConsulter.renderBatch === 'function') {
      window.BrewingConsulter.renderBatch(container, data);
      return;
    }
    if (!data.brews || data.brews.length === 0) {
      renderEmpty(container);
      return;
    }

    var html = '';

    // One header card per brew session
    for (var i = 0; i < data.brews.length; i++) {
      var br    = data.brews[i];
      var t1    = (br.timings && br.timings[0]) ? br.timings[0] : null;
      var start = t1 ? t1.brew_start : null;
      var end   = t1 ? t1.brew_end   : null;
      var dur   = diffMinutes(start, end);
      var cls   = br.classification || 'Neb';

      html += '<div class="lp-card">'
        + '<div class="lp-card-head">'
        +   '<div class="lp-card-title-group">'
        +     '<span class="lp-card-title">' + esc(br.recipe_name || br.beer) + '</span>'
        +     '<span class="lp-card-run-type">Lot ' + esc(br.batch) + '</span>'
        +   '</div>'
        +   '<div class="lp-card-head-right">'
        +     classificationPill(cls)
        +     '<span class="lp-card-date">' + esc(br.event_date) + '</span>'
        +   '</div>'
        + '</div>'
        + '<div class="lp-stat-grid">'
        +   '<div class="lp-stat"><span class="lp-stat-label">CCT</span><span class="lp-stat-value lp-mono">' + nullCell(br.cct) + '</span></div>'
        +   '<div class="lp-stat"><span class="lp-stat-label">Début</span><span class="lp-stat-value lp-mono">' + nullCell(start) + '</span></div>'
        +   '<div class="lp-stat"><span class="lp-stat-label">Fin</span><span class="lp-stat-value lp-mono">' + nullCell(end) + '</span></div>'
        +   '<div class="lp-stat"><span class="lp-stat-label">Durée</span><span class="lp-stat-value lp-mono">' + (dur != null ? dur + ' min' : '<span class="lp-null">—</span>') + '</span></div>'
        + '</div>'
        + '</div>';
    }

    // Gravity sub-table (kept tabular — dense sub-list)
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

    if (gravRows) {
      html += '<h4 class="lp-sub-heading">Gravimétrie</h4>'
        + '<div class="lp-table-wrap"><table class="lp-table">'
        + '<thead><tr>'
        + '<th>Brassage</th><th>Étape</th><th>Densité firstwort</th>'
        + '<th>Densité finale</th><th>Volume final (hl)</th><th>Dilution</th>'
        + '</tr></thead>'
        + '<tbody>' + gravRows + '</tbody>'
        + '</table></div>';
    }

    // Ingredients sub-table (kept tabular — dense sub-list)
    var ingRows = (data.ingredients || []).map(function (ig) {
      var name = (ig.mi_name != null && ig.mi_name !== '') ? ig.mi_name : ig.raw_name;
      return '<tr>'
        + '<td>' + esc(ig.line_idx) + '</td>'
        + '<td>' + esc(ig.category) + '</td>'
        + '<td>' + nullCell(name) + '</td>'
        + '<td>' + fmtNum(ig.qty, 3) + '</td>'
        + '<td>' + nullCell(ig.unit) + '</td>'
        + '<td>' + nullCell(ig.lot) + '</td>'
        + '</tr>';
    }).join('');

    if (ingRows) {
      html += '<h4 class="lp-sub-heading">Ingrédients</h4>'
        + '<div class="lp-table-wrap"><table class="lp-table">'
        + '<thead><tr>'
        + '<th>#</th><th>Catégorie</th><th>Ingrédient</th>'
        + '<th>Qté</th><th>Unité</th><th>Lot</th>'
        + '</tr></thead>'
        + '<tbody>' + ingRows + '</tbody>'
        + '</table></div>';
    }

    container.innerHTML = html;
  }

  // ── Search submit ─────────────────────────────────────────────────────────

  function buildParams(panel, mode) {
    var params = new URLSearchParams();
    params.set('mode', mode);

    if (mode === 'day') {
      var dayPane = panel.querySelector('[id$="-pane-day"]');
      if (dayPane) {
        var di = dayPane.querySelector('.lp-date-input');
        if (di) params.set('date', di.value);
      }
    } else {
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
          if (mode === 'batch') {
            renderPackagingBatch(container, data);
          } else {
            renderPackagingDay(container, data);
          }
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

  // ── Day-card click handler ────────────────────────────────────────────────

  function activateDayCard(panel, card) {
    var batchPane = panel.querySelector('[id$="-pane-batch"]');
    if (!batchPane) return;
    var batch    = card.getAttribute('data-batch');
    var skuId    = card.getAttribute('data-sku-id');
    var recipeId = card.getAttribute('data-recipe-id');
    if (!batch || (!skuId && !recipeId)) return;
    var batchTab = panel.querySelector('.lp-tab[data-tab="batch"]');
    if (batchTab) batchTab.click();           // reuses initTabs pane-switch
    var recSel = batchPane.querySelector('select[name="recipe_id"]');
    if (recSel && recipeId) recSel.value = recipeId;
    var skuSel = batchPane.querySelector('select[name="sku_id"]');
    if (skuSel && skuId) skuSel.value = skuId;
    var batchInp = batchPane.querySelector('input[name="batch"]');
    if (batchInp) batchInp.value = batch;
    doSearch(panel, 'batch');
  }

  // ── Search button + Enter key wiring ──────────────────────────────────────

  function initSearch(panel) {
    panel.addEventListener('click', function (e) {
      var btn = e.target.closest('.lp-search-btn');
      if (btn) {
        var mode = btn.getAttribute('data-mode') || 'day';
        doSearch(panel, mode);
        return;
      }
      var card = e.target.closest('.day-card');
      if (card) {
        activateDayCard(panel, card);
      }
    });

    panel.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        var card = e.target.closest('.day-card');
        if (card) {
          if (e.key === ' ') e.preventDefault();
          activateDayCard(panel, card);
          return;
        }
      }
      if (e.key !== 'Enter') return;
      var inp = e.target;
      if (!inp.matches('.lp-text-input, .lp-date-input')) return;
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
