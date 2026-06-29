/**
 * expeditions-stocktake-guided.js — Guided 1-by-1 FG census + operator past-date
 * date-picker navigation.
 *
 * Reads from DOM and from window globals set in expeditions.php:
 *   window.EXP_CSRF           — CSRF token
 *   window.EXP_ST_SEL_LOC_ID  — selected location id (int)
 *   window.EXP_ST_SEL_DATE    — selected census date (YYYY-MM-DD)
 *   window.EXP_ST_TODAY       — today (YYYY-MM-DD)
 *   window.EXP_ST_DATE_NAVIGATE — bool: any role that gets date-change navigation
 *   window.EXP_ST_IS_MANAGER   — bool: managers already wired in expeditions-stocktake.js
 *
 * Walk-list derived from DOM: all .exp-st-row elements in document order.
 * Per-SKU upsert: POST /api/stocktake-line-upsert.php
 * Resume: sessionStorage['exp_st_guided_{locId}_{date}']
 */

'use strict';

(function () {

  /* ── Globals ──────────────────────────────────────────────────────────── */
  var CSRF       = window.EXP_CSRF         || '';
  var SEL_LOC_ID = window.EXP_ST_SEL_LOC_ID || 0;
  var SEL_DATE   = window.EXP_ST_SEL_DATE   || '';
  var TODAY      = window.EXP_ST_TODAY      || '';

  /* ── Utilities ──────────────────────────────────────────────────────────── */
  function qs(sel, root)  { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  /* ── Date-picker navigation for operators (mirrors manager behavior) ─── */
  (function initDateNav() {
    if (!window.EXP_ST_DATE_NAVIGATE) return;
    var picker = qs('#exp-st-counted-at');
    if (!picker || picker.readOnly) return;
    // Guard: managers already have this listener in expeditions-stocktake.js via IS_MANAGER.
    if (window.EXP_ST_IS_MANAGER) return;
    picker.addEventListener('change', function () {
      var val = picker.value;
      if (!val || val.length < 10) return;
      var base = '/modules/expeditions.php?view=stocktake';
      if (SEL_LOC_ID) base += '&loc=' + SEL_LOC_ID;
      if (val !== TODAY) base += '&date=' + encodeURIComponent(val);
      window.location.href = base;
    });
  }());

  /* ── Guided mode setup ──────────────────────────────────────────────── */
  var openBtn = qs('#exp-st-guided-open');
  var overlay = qs('#exp-st-guided-overlay');
  var dialog  = qs('#exp-st-guided-dialog');
  if (!openBtn || !overlay) return;

  /* ── Build walk-list from DOM rows ──────────────────────────────────── */
  var walkList = [];
  qsa('.exp-st-row').forEach(function (row) {
    var skuId = parseInt(row.dataset.skuId, 10);
    if (!skuId) return;
    var familyEl    = row.closest('.exp-st-family');
    var familyLabel = familyEl ? ((qs('.exp-st-family-label', familyEl) || {}).textContent || '') : '';
    walkList.push({
      skuId:       skuId,
      skuCode:     row.dataset.skuCode || '',
      isCage:      row.dataset.isCage === '1',
      familyLabel: familyLabel.trim(),
    });
  });

  var TOTAL = walkList.length;

  /* ── SessionStorage resume key ───────────────────────────────────────── */
  var STORE_KEY = 'exp_st_guided_' + SEL_LOC_ID + '_' + SEL_DATE;

  function loadState() {
    try {
      var raw = sessionStorage.getItem(STORE_KEY);
      if (raw) return JSON.parse(raw);
    } catch (e) { /* ignore */ }
    return { done: [], snapshotDone: false };
  }

  function saveState(state) {
    try { sessionStorage.setItem(STORE_KEY, JSON.stringify(state)); } catch (e) { /* ignore */ }
  }

  function clearState() {
    try { sessionStorage.removeItem(STORE_KEY); } catch (e) { /* ignore */ }
  }

  /* ── State ──────────────────────────────────────────────────────────── */
  var state   = loadState();
  var doneIds = {};
  state.done.forEach(function (d) { doneIds[d.skuId] = d.qty; });

  var currentIdx = 0;

  /* ── DOM refs (inside overlay) ──────────────────────────────────────── */
  var fillEl   = qs('#exp-st-guided-fill');
  var labelEl  = qs('#exp-st-guided-label');
  var familyEl = qs('#exp-st-guided-family');
  var codeEl   = qs('#exp-st-guided-code');
  var unitEl   = qs('#exp-st-guided-unit');
  var qtyEl    = qs('#exp-st-guided-qty');
  var statusEl = qs('#exp-st-guided-status');
  var zeroBtn  = qs('#exp-st-guided-zero');
  var nextBtn  = qs('#exp-st-guided-next');
  var pauseBtn = qs('#exp-st-guided-pause');
  var quitBtn  = qs('#exp-st-guided-quit');

  /* ── Update progress bar ─────────────────────────────────────────────── */
  function updateProgress() {
    var doneCount = state.done.length;
    var pct = TOTAL > 0 ? Math.round((doneCount / TOTAL) * 100) : 0;
    if (fillEl)  fillEl.style.width = pct + '%';
    if (labelEl) labelEl.textContent = doneCount + ' / ' + TOTAL + ' comptés';
  }

  /* ── Find next undone index ──────────────────────────────────────────── */
  function findNextUndone(startIdx) {
    for (var i = startIdx; i < walkList.length; i++) {
      if (!Object.prototype.hasOwnProperty.call(doneIds, walkList[i].skuId)) return i;
    }
    return -1;
  }

  /* ── Show a SKU ──────────────────────────────────────────────────────── */
  function showSku(idx) {
    currentIdx = idx;
    var sku = walkList[idx];
    if (familyEl) familyEl.textContent = sku.familyLabel;
    if (codeEl)   codeEl.textContent   = sku.skuCode;
    if (unitEl)   unitEl.textContent   = sku.isCage ? '(bouteilles)' : '(unités)';
    if (qtyEl)  { qtyEl.value = ''; qtyEl.focus(); }
    if (statusEl) statusEl.textContent = '';
    updateProgress();
  }

  /* ── AJAX upsert ─────────────────────────────────────────────────────── */
  function upsertSku(skuId, qty, doSnapshot, cb) {
    var body = JSON.stringify({
      csrf:        CSRF,
      loc_id:      SEL_LOC_ID,
      counted_at:  SEL_DATE,
      count_type:  'operational',
      sku_id:      skuId,
      qty:         qty,
      do_snapshot: doSnapshot ? 1 : 0,
    });
    fetch('/api/stocktake-line-upsert.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    body,
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok && data.reason === 'expired' && data.csrf) {
        CSRF = data.csrf;
        var retryBody = JSON.stringify({
          csrf:        CSRF,
          loc_id:      SEL_LOC_ID,
          counted_at:  SEL_DATE,
          count_type:  'operational',
          sku_id:      skuId,
          qty:         qty,
          do_snapshot: 0,
        });
        return fetch('/api/stocktake-line-upsert.php', {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    retryBody,
        }).then(function (r2) { return r2.json(); }).then(cb);
      }
      cb(data);
    })
    .catch(function (e) {
      cb({ ok: false, error: 'Erreur réseau: ' + e.message });
    });
  }

  /* ── Advance: record current SKU and move to next ────────────────────── */
  function advance(qty) {
    if (currentIdx < 0 || currentIdx >= walkList.length) return;
    var sku = walkList[currentIdx];

    if (nextBtn) nextBtn.disabled = true;
    if (zeroBtn) zeroBtn.disabled = true;
    if (statusEl) statusEl.textContent = 'Enregistrement…';

    var doSnap = state.done.length === 0 && !state.snapshotDone;

    upsertSku(sku.skuId, qty, doSnap, function (data) {
      if (!data.ok) {
        if (statusEl) statusEl.textContent = 'Erreur : ' + (data.error || 'inconnue');
        if (nextBtn) nextBtn.disabled = false;
        if (zeroBtn) zeroBtn.disabled = false;
        return;
      }

      if (doSnap) state.snapshotDone = true;
      doneIds[sku.skuId] = qty;
      state.done.push({ skuId: sku.skuId, qty: qty });
      saveState(state);

      if (nextBtn) nextBtn.disabled = false;
      if (zeroBtn) zeroBtn.disabled = false;

      var nextIdx = findNextUndone(currentIdx + 1);
      if (nextIdx >= 0) {
        showSku(nextIdx);
      } else {
        finishGuided();
      }
    });
  }

  /* ── Finish ──────────────────────────────────────────────────────────── */
  function finishGuided() {
    clearState();
    if (statusEl) statusEl.textContent = 'Comptage terminé — rechargement…';
    setTimeout(function () {
      window.location.href = '/modules/expeditions.php?view=stocktake&loc=' + SEL_LOC_ID
        + (SEL_DATE !== TODAY ? '&date=' + encodeURIComponent(SEL_DATE) : '');
    }, 800);
  }

  /* ── Final gate: confirm zeros for unvisited SKUs ────────────────────── */
  function checkAndFinish() {
    var undone = walkList.filter(function (s) {
      return !Object.prototype.hasOwnProperty.call(doneIds, s.skuId);
    });
    if (undone.length === 0) {
      finishGuided();
      return;
    }
    var msgEl = qs('#exp-st-guided-dialog-msg');
    if (msgEl) {
      msgEl.textContent = undone.length + ' SKU' + (undone.length !== 1 ? 's' : '')
        + ' non compté' + (undone.length !== 1 ? 's' : '') + ' — les passer à 0 ?';
    }
    if (dialog) dialog.showModal();
  }

  /* ── Dialog actions ─────────────────────────────────────────────────── */
  var dialogConfirm = qs('#exp-st-guided-dialog-confirm');
  var dialogCancel  = qs('#exp-st-guided-dialog-cancel');

  if (dialogConfirm) {
    dialogConfirm.addEventListener('click', function () {
      if (dialog) dialog.close();
      var undone = walkList.filter(function (s) {
        return !Object.prototype.hasOwnProperty.call(doneIds, s.skuId);
      });
      writeZeros(undone, 0, function () { finishGuided(); });
    });
  }

  if (dialogCancel) {
    dialogCancel.addEventListener('click', function () {
      if (dialog) dialog.close();
      var nextIdx = findNextUndone(0);
      if (nextIdx >= 0) showSku(nextIdx);
    });
  }

  function writeZeros(list, idx, done) {
    if (idx >= list.length) { done(); return; }
    var sku = list[idx];
    if (statusEl) statusEl.textContent = 'Passage à 0 : ' + sku.skuCode + '…';
    upsertSku(sku.skuId, 0, false, function (data) {
      if (data.ok) {
        doneIds[sku.skuId] = 0;
        state.done.push({ skuId: sku.skuId, qty: 0 });
        saveState(state);
      }
      writeZeros(list, idx + 1, done);
    });
  }

  /* ── Open guided mode ────────────────────────────────────────────────── */
  function openGuided() {
    overlay.hidden = false;
    openBtn.setAttribute('aria-expanded', 'true');
    openBtn.setAttribute('aria-pressed', 'true');
    var startIdx = findNextUndone(0);
    if (startIdx < 0) {
      updateProgress();
      checkAndFinish();
      return;
    }
    showSku(startIdx);
  }

  openBtn.addEventListener('click', openGuided);

  /* ── Action buttons ──────────────────────────────────────────────────── */
  if (zeroBtn) {
    zeroBtn.addEventListener('click', function () { advance(0); });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      var raw = qtyEl ? qtyEl.value.trim() : '';
      if (raw === '') {
        if (statusEl) statusEl.textContent = 'Entrez une quantité (0 si rupture) ou utilisez « 0 et suivant ».';
        return;
      }
      var qty = parseFloat(raw);
      if (isNaN(qty) || qty < 0) {
        if (statusEl) statusEl.textContent = 'Quantité invalide.';
        return;
      }
      advance(Math.round(qty));
    });
  }

  if (qtyEl) {
    qtyEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        var raw = qtyEl.value.trim();
        if (raw === '') { advance(0); return; }
        var qty = parseFloat(raw);
        if (!isNaN(qty) && qty >= 0) advance(Math.round(qty));
        else if (statusEl) statusEl.textContent = 'Quantité invalide.';
      }
    });
  }

  if (pauseBtn) {
    pauseBtn.addEventListener('click', function () {
      overlay.hidden = true;
      openBtn.setAttribute('aria-expanded', 'false');
      openBtn.setAttribute('aria-pressed', 'false');
      openBtn.textContent = '▶ Reprendre le comptage guidé (' + state.done.length + ' / ' + TOTAL + ' faits)';
    });
  }

  if (quitBtn) {
    quitBtn.addEventListener('click', function () {
      clearState();
      overlay.hidden = true;
      openBtn.setAttribute('aria-expanded', 'false');
      openBtn.setAttribute('aria-pressed', 'false');
      // Restore icon + text
      openBtn.innerHTML = '<span class="exp-st-guided-btn__icon" aria-hidden="true">▶</span> Comptage guidé (1 SKU à la fois)';
    });
  }

}());
