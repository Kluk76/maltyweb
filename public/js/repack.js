/**
 * repack.js — Reconditionnement advisory panel for ?view=repack.
 *
 * Handles:
 *   - "Confirmer la décomposition" button per order → POST all proposal rows
 *     for that order to /api/expeditions-repack.php
 *   - CSRF retry-once on expired token
 *   - 60s live auto-refresh (house preference: live/dynamic)
 *   - Optimistic UI: confirm button → loading state → done or error
 *
 * Reads:
 *   window.RKP_CSRF      — shared CSRF token (injected by expeditions.php)
 *   window.RKP_DATE      — ISO date string for the current day
 *   window.RKP_FLAG_LIVE — bool: whether depletion is live
 *
 * DOM contract (rendered by exp_render_repack_order_block in expeditions.php):
 *   .rkp-order-block[data-order-id]              — wrapper per order
 *     .rkp-proposal-row[data-from-sku-id, data-from-qty, data-to-sku-id,
 *                        data-to-qty, data-component-bottles, data-loose-units,
 *                        data-to-kind, data-site-id]
 *     button.rkp-confirm-btn[data-order-id, data-mode]
 */
(function () {
  'use strict';

  // ── State ───────────────────────────────────────────────────────────────────
  let csrfToken = (typeof window.RKP_CSRF !== 'undefined') ? window.RKP_CSRF : '';
  const rkpDate = (typeof window.RKP_DATE !== 'undefined') ? window.RKP_DATE : '';

  function updateCsrf(token) {
    csrfToken = token;
    document.querySelectorAll('input[name="csrf"]').forEach(function (el) {
      el.value = token;
    });
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Collect proposal rows for an order block ────────────────────────────────
  function collectProposalRows(orderBlock) {
    const rows = [];
    orderBlock.querySelectorAll('.rkp-proposal-row').forEach(function (tr) {
      rows.push({
        from_sku_id:       parseInt(tr.dataset.fromSkuId, 10)  || 0,
        from_qty:          parseInt(tr.dataset.fromQty, 10)    || 0,
        to_sku_id:         parseInt(tr.dataset.toSkuId, 10)    || 0,
        to_qty:            parseInt(tr.dataset.toQty, 10)      || 0,
        component_bottles: parseInt(tr.dataset.componentBottles, 10) || 0,
        loose_units:       parseInt(tr.dataset.looseUnits, 10) || 0,
        to_kind:           tr.dataset.toKind || 'bundle',
        site_id:           parseInt(tr.dataset.siteId, 10)     || 0,
      });
    });
    return rows;
  }

  // ── POST to /api/expeditions-repack.php ────────────────────────────────────
  function postRepackConfirm(orderId, mode, rows, retrying) {
    return fetch('/api/expeditions-repack.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf:       csrfToken,
        order_id:   orderId,
        mode:       mode,
        moved_on:   rkpDate,
        rows:       rows,
      }),
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (!data.ok && data.reason === 'expired' && !retrying) {
        // Retry once with fresh CSRF
        updateCsrf(data.csrf);
        return postRepackConfirm(orderId, mode, rows, true);
      }
      return data;
    });
  }

  // ── Wire confirm buttons ────────────────────────────────────────────────────
  function wireConfirmButtons() {
    document.querySelectorAll('.rkp-confirm-btn').forEach(function (btn) {
      if (btn.dataset.wired) return;
      btn.dataset.wired = '1';

      btn.addEventListener('click', function () {
        if (btn.disabled) return;

        const orderId   = parseInt(btn.dataset.orderId, 10) || 0;
        const mode      = btn.dataset.mode || 'delivery';
        const orderBlock = btn.closest('.rkp-order-block');
        if (!orderBlock) return;

        const rows = collectProposalRows(orderBlock);
        if (!rows.length) return;

        // Optimistic loading state
        btn.classList.add('rkp-confirm-btn--loading');
        btn.disabled = true;
        btn.textContent = '…';

        postRepackConfirm(orderId, mode, rows, false)
          .then(function (data) {
            if (data.ok) {
              if (data.csrf) updateCsrf(data.csrf);
              btn.classList.remove('rkp-confirm-btn--loading');
              btn.classList.add('rkp-confirm-btn--done');
              btn.textContent = '✓ Enregistré';
            } else {
              btn.classList.remove('rkp-confirm-btn--loading');
              btn.disabled = false;
              btn.textContent = 'Confirmer la décomposition';
              const errMsg = escHtml(data.error || 'Erreur inconnue');
              const errEl = document.createElement('span');
              errEl.className = 'rkp-post-error';
              errEl.setAttribute('role', 'alert');
              errEl.innerHTML = '⚠ ' + errMsg;
              // Insert after button, replace any previous error
              const prev = orderBlock.querySelector('.rkp-post-error');
              if (prev) prev.remove();
              btn.insertAdjacentElement('afterend', errEl);
            }
          })
          .catch(function (err) {
            btn.classList.remove('rkp-confirm-btn--loading');
            btn.disabled = false;
            btn.textContent = 'Confirmer la décomposition';
            console.error('[repack] POST failed:', err);
          });
      });
    });
  }

  // ── 60s live auto-refresh ───────────────────────────────────────────────────
  // Only refresh when the tab is visible (house preference: dynamic but not wasteful).
  function startAutoRefresh() {
    let refreshTimer = null;

    function scheduleRefresh() {
      if (refreshTimer) clearTimeout(refreshTimer);
      refreshTimer = setTimeout(function () {
        if (!document.hidden) {
          window.location.reload();
        } else {
          scheduleRefresh();
        }
      }, 60000);
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        // Tab became visible — reschedule from now
        scheduleRefresh();
      }
    });

    scheduleRefresh();
  }

  // ── Init ────────────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    wireConfirmButtons();
    startAutoRefresh();
    initAssemblyPanel();
  });

  // Also wire immediately if DOMContentLoaded already fired
  if (document.readyState !== 'loading') {
    wireConfirmButtons();
    startAutoRefresh();
    initAssemblyPanel();
  }

  // ── Assembly panel ──────────────────────────────────────────────────────────
  var _rkpaInited = false;
  function initAssemblyPanel() {
    if (_rkpaInited) return;
    var rkpaData = (typeof window.RKPA_DATA !== 'undefined') ? window.RKPA_DATA : null;
    if (!rkpaData || !rkpaData.packs || rkpaData.packs.length === 0) return;

    var toggleBtn = document.getElementById('rkpa-toggle');
    var panel     = document.getElementById('rkpa-panel');
    if (!toggleBtn || !panel) return;
    _rkpaInited = true;

    // ── State ─────────────────────────────────────────────────────────────
    var state = {
      packIdx:   0,    // index into rkpaData.packs
      qty:       1,
      date:      rkpDate || (new Date().toISOString().slice(0, 10)),
      siteId:    rkpaData.default_site_id || 0,
      // perBeer: recipe_id → [{sku_id, qty}]
      perBeer:   {},
    };

    function currentPack() { return rkpaData.packs[state.packIdx]; }

    // Default per-beer assignment: cage first (dispo>0), then first base with dispo>0, else first
    function defaultSourceForBeer(beer) {
      var sources = beer.sources;
      // cage first
      for (var i = 0; i < sources.length; i++) {
        if (sources[i].scope === 'cage' && sources[i].dispo > 0) return sources[i].sku_id;
      }
      // base next
      for (var i = 0; i < sources.length; i++) {
        if (sources[i].scope === 'base' && sources[i].dispo > 0) return sources[i].sku_id;
      }
      // single
      for (var i = 0; i < sources.length; i++) {
        if (sources[i].scope === 'single' && sources[i].dispo > 0) return sources[i].sku_id;
      }
      // fallback: first available
      return sources.length > 0 ? sources[0].sku_id : null;
    }

    function initPerBeer() {
      state.perBeer = {};
      var pack = currentPack();
      pack.member_beers.forEach(function(beer) {
        var defSku = defaultSourceForBeer(beer);
        state.perBeer[beer.recipe_id] = defSku ? [{sku_id: defSku, qty: beer.units_per_recipe * state.qty}] : [];
      });
    }

    function applyAllFromCage() {
      var pack = currentPack();
      pack.member_beers.forEach(function(beer) {
        var cageSku = null;
        beer.sources.forEach(function(s) {
          if (s.scope === 'cage' && s.dispo > 0) cageSku = s.sku_id;
        });
        if (cageSku !== null) {
          state.perBeer[beer.recipe_id] = [{sku_id: cageSku, qty: beer.units_per_recipe * state.qty}];
        }
      });
      render();
    }

    function sourceById(beer, skuId) {
      for (var i = 0; i < beer.sources.length; i++) {
        if (beer.sources[i].sku_id === skuId) return beer.sources[i];
      }
      return null;
    }

    // ── Balance check ──────────────────────────────────────────────────────
    function beerNeeded(beer) { return beer.units_per_recipe * state.qty; }
    function beerAssigned(recipeId) {
      var rows = state.perBeer[recipeId] || [];
      return rows.reduce(function(s, r) { return s + (r.qty || 0); }, 0);
    }
    function isBeerBalanced(beer) { return beerAssigned(beer.recipe_id) === beerNeeded(beer); }
    function allBalanced() {
      return currentPack().member_beers.every(function(b) { return isBeerBalanced(b); });
    }

    // Check cage source has enough dispo (advisory warning only)
    function hasDispoWarning() {
      var pack = currentPack();
      var warned = false;
      pack.member_beers.forEach(function(beer) {
        var rows = state.perBeer[beer.recipe_id] || [];
        rows.forEach(function(row) {
          var src = sourceById(beer, row.sku_id);
          if (src && row.qty > src.dispo) warned = true;
        });
      });
      return warned;
    }

    // ── Render ─────────────────────────────────────────────────────────────
    function render() {
      var pack = currentPack();
      var html = '';

      // Header: pack selector + qty + date + site
      html += '<div class="rkpa-header">';

      // Pack selector (only show if >1 pack)
      if (rkpaData.packs.length > 1) {
        html += '<label class="rkpa-field-label" for="rkpa-pack-sel">Pack</label>';
        html += '<select id="rkpa-pack-sel" class="rkpa-pack-select op-form__input">';
        rkpaData.packs.forEach(function(p, idx) {
          html += '<option value="' + idx + '"' + (idx === state.packIdx ? ' selected' : '') + '>' + escHtml(p.unit_label) + '</option>';
        });
        html += '</select>';
      } else {
        html += '<span class="rkpa-pack-label">' + escHtml(pack.unit_label) + '</span>';
      }

      // Qty stepper
      html += '<div class="rkpa-qty-stepper">';
      html += '<label class="rkpa-field-label" for="rkpa-qty-input">Quantité</label>';
      html += '<div class="rkpa-qty-controls">';
      html += '<button type="button" class="rkpa-qty-btn" id="rkpa-qty-dec" aria-label="Diminuer" ' + (state.qty <= 1 ? 'disabled' : '') + '>−</button>';
      html += '<input type="number" id="rkpa-qty-input" class="rkpa-qty-input" value="' + state.qty + '" min="1" max="9999">';
      html += '<button type="button" class="rkpa-qty-btn" id="rkpa-qty-inc" aria-label="Augmenter">+</button>';
      html += '</div></div>';

      // Date
      html += '<div class="rkpa-date-wrap">';
      html += '<label class="rkpa-field-label" for="rkpa-date-input">Date</label>';
      html += '<input type="date" id="rkpa-date-input" class="rkpa-date-input op-form__input" value="' + escHtml(state.date) + '">';
      html += '</div>';

      // Site
      html += '<div class="rkpa-site-wrap">';
      html += '<label class="rkpa-field-label" for="rkpa-site-sel">Site</label>';
      html += '<select id="rkpa-site-sel" class="rkpa-site-select op-form__input">';
      rkpaData.sites.forEach(function(s) {
        html += '<option value="' + s.id + '"' + (parseInt(s.id) === state.siteId ? ' selected' : '') + '>' + escHtml(s.name) + '</option>';
      });
      html += '</select>';
      html += '</div>';

      html += '</div>'; // /rkpa-header

      // "Tout depuis la cage" button
      var allHaveCage = pack.member_beers.every(function(b) {
        return b.sources.some(function(s) { return s.scope === 'cage' && s.dispo > 0; });
      });
      html += '<div class="rkpa-shortcut-bar">';
      html += '<button type="button" class="rkpa-default-btn ef-chip" id="rkpa-all-cage"' + (allHaveCage ? '' : ' disabled title="Certaines bières n\'ont pas de cage disponible"') + '>Tout depuis la cage</button>';
      html += '<span class="rkpa-needed-info">Il faut <strong>' + (pack.member_beers[0] ? pack.member_beers[0].units_per_recipe * state.qty : state.qty) + '</strong> bouteille(s) de chaque bière</span>';
      html += '</div>';

      // Per-beer table
      html += '<div class="rkpa-beer-table" role="group" aria-label="Sources par bière">';
      pack.member_beers.forEach(function(beer) {
        var needed = beerNeeded(beer);
        var assigned = beerAssigned(beer.recipe_id);
        var balanced = (assigned === needed);
        var rows = state.perBeer[beer.recipe_id] || [];

        html += '<div class="rkpa-beer-row' + (balanced ? '' : ' rkpa-beer-row--err') + '" data-recipe-id="' + beer.recipe_id + '">';
        html += '<div class="rkpa-beer-meta">';
        html += '<span class="rkpa-beer-name">' + escHtml(beer.beer_name) + '</span>';
        html += '<span class="rkpa-beer-needed">' + needed + ' btl</span>';
        html += '<span class="rkpa-tally' + (balanced ? ' rkpa-tally--ok' : ' rkpa-tally--err') + '">' + assigned + '/' + needed + '</span>';
        html += '</div>';
        html += '<div class="rkpa-src-rows">';

        rows.forEach(function(row, rowIdx) {
          var srcMeta = sourceById(beer, row.sku_id);
          var isOverDispo = srcMeta && row.qty > srcMeta.dispo;
          html += '<div class="rkpa-src-row" data-row-idx="' + rowIdx + '">';

          // Source dropdown
          html += '<select class="rkpa-src-select op-form__input" data-field="src" aria-label="Source pour ' + escHtml(beer.beer_name) + '">';
          beer.sources.forEach(function(s) {
            var disabledAttr = s.dispo <= 0 ? ' disabled' : '';
            var selectedAttr = s.sku_id === row.sku_id ? ' selected' : '';
            var scopeLabel = s.scope === 'cage' ? 'Cage' : (s.scope === 'base' ? 'Caisse' : 'Bouteille');
            html += '<option value="' + s.sku_id + '"' + selectedAttr + disabledAttr + '>';
            html += scopeLabel + ' ' + escHtml(s.sku_code) + ' (dispo: ' + s.dispo + ')';
            html += '</option>';
          });
          html += '</select>';

          // Qty input
          html += '<input type="number" class="rkpa-src-qty op-form__input" data-field="qty" value="' + row.qty + '" min="1" aria-label="Quantité ' + escHtml(beer.beer_name) + '"' + (isOverDispo ? ' class="rkpa-src-qty rkpa-qty-over"' : '') + '>';

          // − button
          html += '<button type="button" class="rkpa-del-row-btn" data-field="del" aria-label="Supprimer ligne"' + (rows.length <= 1 ? ' disabled' : '') + '>−</button>';

          if (isOverDispo) {
            html += '<span class="rkpa-warn-inline" aria-live="polite">⚠ dispo ' + srcMeta.dispo + '</span>';
          }
          html += '</div>'; // /rkpa-src-row
        });

        // ＋ add sub-row button
        html += '<button type="button" class="rkpa-add-row-btn" data-recipe-id="' + beer.recipe_id + '" aria-label="Ajouter une source pour ' + escHtml(beer.beer_name) + '">＋ source</button>';
        html += '</div>'; // /rkpa-src-rows
        html += '</div>'; // /rkpa-beer-row
      });
      html += '</div>'; // /rkpa-beer-table

      // Footer: matériel consommé
      html += '<div class="rkpa-footer">';
      html += '<span class="rkpa-footer-label">Matériel consommé :</span> ';
      html += '<span class="rkpa-footer-val">' + state.qty + ' × boîte ' + escHtml(pack.unit_label) + '</span>';
      html += ' <span class="rkpa-footer-note">(info)</span>';
      html += '</div>';

      // Dispo warning
      if (hasDispoWarning()) {
        html += '<div class="rkpa-warn" role="alert">Attention : une ou plusieurs sources ont un stock insuffisant.</div>';
      }

      // Flash message area
      html += '<div class="rkpa-flash" id="rkpa-flash" aria-live="polite"></div>';

      // Actions
      html += '<div class="rkpa-actions">';
      html += '<button type="button" class="op-form__btn" id="rkpa-cancel">Annuler</button>';
      html += '<button type="button" class="op-form__btn op-form__btn--primary" id="rkpa-submit"' + (!allBalanced() ? ' disabled' : '') + '>Valider</button>';
      html += '</div>';

      panel.innerHTML = html;
      wirePanel();
    }

    // ── Wire panel events ──────────────────────────────────────────────────
    function wirePanel() {
      // Pack selector
      var packSel = document.getElementById('rkpa-pack-sel');
      if (packSel) packSel.addEventListener('change', function() {
        state.packIdx = parseInt(this.value, 10);
        initPerBeer();
        render();
      });

      // Qty controls
      var qtyInput = document.getElementById('rkpa-qty-input');
      var qtyDec   = document.getElementById('rkpa-qty-dec');
      var qtyInc   = document.getElementById('rkpa-qty-inc');
      if (qtyDec) qtyDec.addEventListener('click', function() {
        if (state.qty > 1) { state.qty--; syncQtyToState(); render(); }
      });
      if (qtyInc) qtyInc.addEventListener('click', function() {
        state.qty++; syncQtyToState(); render();
      });
      if (qtyInput) qtyInput.addEventListener('change', function() {
        var v = parseInt(this.value, 10);
        if (v >= 1) { state.qty = v; syncQtyToState(); render(); }
      });

      function syncQtyToState() {
        var pack = currentPack();
        // Rescale all per-beer quantities proportionally to new total needed
        pack.member_beers.forEach(function(beer) {
          var rows = state.perBeer[beer.recipe_id] || [];
          if (rows.length === 1) {
            rows[0].qty = beer.units_per_recipe * state.qty;
          }
          // multi-row: just leave as-is (operator re-balances manually)
        });
      }

      // Date
      var dateInput = document.getElementById('rkpa-date-input');
      if (dateInput) dateInput.addEventListener('change', function() { state.date = this.value; });

      // Site
      var siteSel = document.getElementById('rkpa-site-sel');
      if (siteSel) siteSel.addEventListener('change', function() { state.siteId = parseInt(this.value, 10); });

      // All from cage
      var allCageBtn = document.getElementById('rkpa-all-cage');
      if (allCageBtn) allCageBtn.addEventListener('click', applyAllFromCage);

      // Per-beer source/qty/add/del events — delegated on .rkpa-beer-table
      var beerTable = panel.querySelector('.rkpa-beer-table');
      if (beerTable) {
        beerTable.addEventListener('change', function(e) {
          var srcRow = e.target.closest('.rkpa-src-row');
          if (!srcRow) return;
          var beerRow = e.target.closest('.rkpa-beer-row');
          if (!beerRow) return;
          var recipeId = parseInt(beerRow.dataset.recipeId, 10);
          var rowIdx   = parseInt(srcRow.dataset.rowIdx, 10);
          var field    = e.target.dataset.field;
          if (!state.perBeer[recipeId]) return;
          if (field === 'src') {
            state.perBeer[recipeId][rowIdx].sku_id = parseInt(e.target.value, 10);
          } else if (field === 'qty') {
            state.perBeer[recipeId][rowIdx].qty = parseInt(e.target.value, 10) || 0;
          }
          render();
        });

        beerTable.addEventListener('click', function(e) {
          // Add sub-row
          var addBtn = e.target.closest('.rkpa-add-row-btn');
          if (addBtn) {
            var recipeId = parseInt(addBtn.dataset.recipeId, 10);
            var pack = currentPack();
            var beer = pack.member_beers.find(function(b) { return b.recipe_id === recipeId; });
            if (!beer) return;
            var defSku = defaultSourceForBeer(beer);
            if (!state.perBeer[recipeId]) state.perBeer[recipeId] = [];
            state.perBeer[recipeId].push({sku_id: defSku || (beer.sources[0] ? beer.sources[0].sku_id : 0), qty: 0});
            render();
            return;
          }

          // Delete sub-row
          var delBtn = e.target.closest('[data-field="del"]');
          if (delBtn) {
            var srcRow   = delBtn.closest('.rkpa-src-row');
            var beerRow  = delBtn.closest('.rkpa-beer-row');
            if (!srcRow || !beerRow) return;
            var recipeId = parseInt(beerRow.dataset.recipeId, 10);
            var rowIdx   = parseInt(srcRow.dataset.rowIdx, 10);
            if (!state.perBeer[recipeId] || state.perBeer[recipeId].length <= 1) return;
            state.perBeer[recipeId].splice(rowIdx, 1);
            render();
            return;
          }
        });
      }

      // Cancel
      var cancelBtn = document.getElementById('rkpa-cancel');
      if (cancelBtn) cancelBtn.addEventListener('click', closePanel);

      // Submit
      var submitBtn = document.getElementById('rkpa-submit');
      if (submitBtn) submitBtn.addEventListener('click', submitAssembly);
    }

    function buildRows() {
      var pack = currentPack();
      var rows = [];
      pack.member_beers.forEach(function(beer) {
        var subRows = state.perBeer[beer.recipe_id] || [];
        subRows.forEach(function(row) {
          var src = sourceById(beer, row.sku_id);
          if (!src || row.qty <= 0) return;
          var fromQty = Math.ceil(row.qty / src.units_per_pack);
          rows.push({
            from_sku_id:       row.sku_id,
            from_qty:          fromQty,
            to_sku_id:         pack.pack_sku_id,
            to_qty:            state.qty,
            component_bottles: row.qty,
            loose_units:       fromQty * src.units_per_pack - row.qty,
            to_kind:           'pd8',
            site_id:           state.siteId,
          });
        });
      });
      return rows;
    }

    function submitAssembly(retrying) {
      var submitBtn = document.getElementById('rkpa-submit');
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Enregistrement…'; }

      var rows = buildRows();
      fetch('/api/expeditions-repack.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          csrf:     csrfToken,
          order_id: 0,
          mode:     'assembly',
          moved_on: state.date,
          rows:     rows,
        }),
      })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (!data.ok && data.reason === 'expired' && !retrying) {
          updateCsrf(data.csrf);
          submitAssembly(true);
          return;
        }
        if (data.csrf) updateCsrf(data.csrf);
        var flash = document.getElementById('rkpa-flash');
        if (data.ok) {
          var msg = state.qty + ' pack(s) ' + escHtml(currentPack().sku_code) + ' assemblé(s) — ' + data.inserted + ' ligne(s) enregistrée(s).';
          if (flash) { flash.className = 'rkpa-flash rkpa-flash--ok'; flash.innerHTML = msg; }
          // Replace form with success + "Nouveau" button
          panel.innerHTML = '<div class="rkpa-success">' +
            '<p class="rkpa-success-msg">' + msg + '</p>' +
            '<button type="button" class="rkpa-new-btn ef-chip ef-chip--next" id="rkpa-new">Nouvel assemblage</button>' +
            '</div>';
          var newBtn = document.getElementById('rkpa-new');
          if (newBtn) newBtn.addEventListener('click', function() { initPerBeer(); render(); });
        } else {
          if (flash) { flash.className = 'rkpa-flash rkpa-flash--err'; flash.textContent = data.error || 'Erreur serveur.'; }
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Valider'; }
        }
      })
      .catch(function(err) {
        var flash = document.getElementById('rkpa-flash');
        if (flash) { flash.className = 'rkpa-flash rkpa-flash--err'; flash.textContent = 'Erreur réseau.'; }
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Valider'; }
      });
    }

    function openPanel() {
      initPerBeer();
      render();
      panel.removeAttribute('hidden');
      toggleBtn.hidden = true;
    }
    function closePanel() {
      panel.setAttribute('hidden', '');
      panel.innerHTML = '';
      toggleBtn.hidden = false;
    }

    toggleBtn.addEventListener('click', openPanel);
  }

})();
