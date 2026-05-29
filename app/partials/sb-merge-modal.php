<?php
declare(strict_types=1);
/**
 * Partial: sb-merge-modal.php
 *
 * Merge modal for the mother-shell drill-in (sb-mother.php).
 * Included ONCE at the bottom of sb-mother.php, before </main>.
 *
 * Precondition: only include when ($isActive && !$isMergedSurvivor && !$isArchived).
 *
 * Requires (from sb-mother.php scope):
 *   $csrf       — string CSRF token
 *   $motherId   — int ID of the current mother (becomes the default survivor)
 *   $recipeName — string recipe name of this mother
 *   $batch      — string batch identifier
 *
 * JS interaction:
 *   window.sbMerge.open()  — called by the "Fusionner" footer button onclick.
 *
 * Wires to: POST /api/sb-merge.php
 *
 * Design contract: sb-merge-* CSS namespace, body.sb-mother scope.
 */

$mgMotherIdJs   = (int)$motherId;
$mgRecipeNameJs = htmlspecialchars(json_encode($recipeName ?? ''), ENT_QUOTES, 'UTF-8');
$mgBatchJs      = htmlspecialchars(json_encode($batch ?? ''), ENT_QUOTES, 'UTF-8');
$mgCsrfJs       = htmlspecialchars(json_encode($csrf), ENT_QUOTES, 'UTF-8');
?>

<!-- ══ Merge modal (Atom 8) ═════════════════════════════════════════════ -->
<div class="sb-merge-backdrop" id="sb-merge-backdrop" role="dialog" aria-modal="true"
     aria-labelledby="sb-merge-title" hidden>

  <div class="sb-merge-modal">

    <!-- Header -->
    <div class="sb-merge-header">
      <div class="sb-merge-header__icon" aria-hidden="true">⇌</div>
      <div class="sb-merge-header__text">
        <h2 class="sb-merge-title" id="sb-merge-title">Fusionner des lots</h2>
        <p class="sb-merge-sub">
          Choisissez un lot survivant et un ou plusieurs lots sources à absorber.
          Les sources seront clôturées et rattachées au survivant.
        </p>
      </div>
      <button class="sb-merge-close" id="sb-merge-close-btn"
              aria-label="Annuler et fermer">×</button>
    </div>

    <!-- Body -->
    <div class="sb-merge-body" id="sb-merge-body">

      <!-- Loading state -->
      <div class="sb-merge-loading" id="sb-merge-loading" aria-live="polite">
        Chargement des lots ouverts…
      </div>

      <!-- Error / empty state -->
      <div class="sb-merge-empty" id="sb-merge-empty" hidden></div>

      <!-- Survivor picker -->
      <div class="sb-merge-field" id="sb-merge-survivor-field" hidden>
        <label class="sb-merge-label" for="sb-merge-survivor-select">
          Lot survivant (absorbe les autres)
        </label>
        <select class="sb-merge-select" id="sb-merge-survivor-select"
                aria-describedby="sb-merge-survivor-hint">
        </select>
        <p class="sb-merge-hint" id="sb-merge-survivor-hint">
          Le lot survivant reste ouvert. Les sources seront clôturées et reliées à lui.
        </p>
      </div>

      <!-- Sources list -->
      <div class="sb-merge-field" id="sb-merge-sources-field" hidden>
        <div class="sb-merge-label">Lots sources (à absorber)</div>
        <div class="sb-merge-sources-list" id="sb-merge-sources-list"
             role="group" aria-label="Sélection des lots sources">
        </div>
        <p class="sb-merge-hint">
          Cochez un ou plusieurs lots à fusionner dans le survivant.
          Le survivant ne peut pas être coché.
        </p>
      </div>

      <!-- Blend share pct section — rendered by JS per checked source -->
      <div class="sb-merge-field sb-merge-blend-section" id="sb-merge-blend-section" hidden>
        <div class="sb-merge-label">Parts de blend (optionnel, 0–100 %)</div>
        <div class="sb-merge-blend-inputs" id="sb-merge-blend-inputs"></div>
        <p class="sb-merge-hint">
          Renseignez la part volumique de chaque source dans le blend final.
          La somme n'est pas imposée — c'est indicatif.
        </p>
      </div>

    </div><!-- /sb-merge-body -->

    <!-- Footer -->
    <div class="sb-merge-footer" id="sb-merge-footer" hidden>
      <div class="sb-merge-footer-micro">
        ⚠ Les lots sources seront clôturés définitivement. Action irréversible.
      </div>
      <div class="sb-merge-footer-actions">
        <button class="smh-btn smh-btn--secondary" id="sb-merge-cancel-btn">Annuler</button>
        <button class="smh-btn smh-btn--primary"   id="sb-merge-confirm-btn" disabled>
          Fusionner
        </button>
      </div>
    </div>

  </div><!-- /sb-merge-modal -->

</div><!-- /sb-merge-backdrop -->

<script>
/* global sbMerge — wired immediately; hoisted before DOMContentLoaded */
(function () {
  'use strict';

  var THIS_MOTHER_ID = <?= $mgMotherIdJs ?>;
  var CSRF           = <?= $mgCsrfJs ?>;

  var backdrop    = document.getElementById('sb-merge-backdrop');
  var loading     = document.getElementById('sb-merge-loading');
  var emptyMsg    = document.getElementById('sb-merge-empty');
  var survField   = document.getElementById('sb-merge-survivor-field');
  var survSelect  = document.getElementById('sb-merge-survivor-select');
  var srcField    = document.getElementById('sb-merge-sources-field');
  var srcList     = document.getElementById('sb-merge-sources-list');
  var blendSec    = document.getElementById('sb-merge-blend-section');
  var blendInputs = document.getElementById('sb-merge-blend-inputs');
  var footer      = document.getElementById('sb-merge-footer');
  var confirmBtn  = document.getElementById('sb-merge-confirm-btn');
  var cancelBtn   = document.getElementById('sb-merge-cancel-btn');
  var closeBtn    = document.getElementById('sb-merge-close-btn');

  /* ── escaping ── */
  function escHtml(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  /* ── state ── */
  var _allMothers = [];  /* [{id, recipe_name, batch, opened_at}, …] */

  /* ── focus trap ── */
  var _focusTrapHandler = null;
  var _prevFocus = null;

  function installFocusTrap() {
    var modal = document.querySelector('.sb-merge-modal');
    if (!modal) { return; }
    /* Nit 4 fix: live-query focusables inside handler so dynamically-rendered
       pickers (blend_share_pct inputs from renderPickers) are reachable via Tab.
       Closure-captured NodeList at open-time misses the dynamically-injected DOM. */
    var focusableSelector =
      'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), ' +
      'textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    var initialList = modal.querySelectorAll(focusableSelector);
    if (initialList.length === 0) { return; }

    _focusTrapHandler = function (e) {
      if (e.key !== 'Tab') { return; }
      var live = modal.querySelectorAll(focusableSelector);
      if (live.length === 0) { return; }
      var first = live[0];
      var last  = live[live.length - 1];
      if (e.shiftKey) {
        if (document.activeElement === first) { e.preventDefault(); last.focus(); }
      } else {
        if (document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    };
    modal.addEventListener('keydown', _focusTrapHandler);
    initialList[0].focus();
  }

  function removeFocusTrap() {
    var modal = document.querySelector('.sb-merge-modal');
    if (modal && _focusTrapHandler) {
      modal.removeEventListener('keydown', _focusTrapHandler);
      _focusTrapHandler = null;
    }
    if (_prevFocus) { _prevFocus.focus(); _prevFocus = null; }
  }

  /* ── open ── */
  function open() {
    /* reset UI */
    loading.hidden     = false;
    emptyMsg.hidden    = true;
    survField.hidden   = true;
    srcField.hidden    = true;
    blendSec.hidden    = true;
    footer.hidden      = true;
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Fusionner';
    survSelect.innerHTML = '';
    srcList.innerHTML    = '';
    blendInputs.innerHTML = '';
    _allMothers = [];

    _prevFocus = document.activeElement || null;
    backdrop.hidden = false;
    backdrop.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    installFocusTrap();

    fetchMothers();
  }

  /* ── close ── */
  function close() {
    backdrop.hidden = true;
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    _allMothers = [];
    removeFocusTrap();
  }

  /* ── fetch open mothers ── */
  function fetchMothers() {
    fetch('/api/sb-board-data.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        loading.hidden = true;
        /* RULE-2 BLOCK 1 fix: sb-board-data.php returns data.mothers as a
           zone-keyed object {brasserie:[...], fermentation:[...], ...},
           NOT a flat array. Flatten before filtering — was always crashing
           silently, rendering "Aucune mother shell ouverte" even with N>=2
           open mothers. The merged_into_id guard was also dead code (key
           never present in sb_open_mothers summary; SQL already excludes
           merged-departed via status='open' filter). Removed. */
        var zones = data.mothers || {};
        var flat  = Object.values(zones).reduce(function (acc, zone) {
          return acc.concat(Array.isArray(zone) ? zone : []);
        }, []);
        var mothers = flat.filter(function (m) {
          return m.status === 'open';
        });
        if (mothers.length < 2) {
          emptyMsg.hidden = false;
          emptyMsg.textContent = mothers.length === 0
            ? 'Aucune mother shell ouverte trouvée.'
            : 'Un seul lot ouvert — rien à fusionner.';
          return;
        }
        _allMothers = mothers;
        renderPickers(mothers);
      })
      .catch(function () {
        loading.hidden = true;
        emptyMsg.hidden = false;
        emptyMsg.textContent = 'Erreur réseau — réessayer.';
      });
  }

  /* ── render survivor select + source checkboxes ── */
  function renderPickers(mothers) {
    /* Survivor select — default to THIS_MOTHER_ID if it's in the list */
    survSelect.innerHTML = '';
    mothers.forEach(function (m) {
      var opt = document.createElement('option');
      opt.value = String(m.id);
      /* Nit 1 fix: textContent auto-escapes; escHtml double-encodes &/'/" entities */
      opt.textContent = (m.recipe_name || '—') + ' #' + (m.batch || '?')
                      + (m.id === THIS_MOTHER_ID ? ' (ce lot)' : '');
      if (m.id === THIS_MOTHER_ID) { opt.selected = true; }
      survSelect.appendChild(opt);
    });
    survField.hidden = false;

    /* Source checkboxes */
    srcList.innerHTML = '';
    mothers.forEach(function (m) {
      var wrap = document.createElement('label');
      wrap.className = 'sb-merge-source-row';
      wrap.dataset.motherId = String(m.id);

      var cb = document.createElement('input');
      cb.type    = 'checkbox';
      cb.value   = String(m.id);
      cb.className = 'sb-merge-source-cb';
      cb.id      = 'sb-merge-src-' + m.id;
      cb.setAttribute('aria-label',
        (m.recipe_name || '—') + ' #' + (m.batch || '?'));

      var nameSpan = document.createElement('span');
      nameSpan.className = 'sb-merge-source-name';
      nameSpan.innerHTML = '<em>' + escHtml(m.recipe_name || '—') + '</em>'
                         + ' <span class="sb-merge-source-batch">#' + escHtml(m.batch || '?') + '</span>';

      var dateSpan = document.createElement('span');
      dateSpan.className = 'sb-merge-source-date';
      if (m.opened_at) {
        dateSpan.textContent = 'Ouvert le ' + String(m.opened_at).slice(0, 10);
      }

      wrap.appendChild(cb);
      wrap.appendChild(nameSpan);
      wrap.appendChild(dateSpan);
      srcList.appendChild(wrap);
    });
    srcField.hidden = false;
    footer.hidden   = false;

    /* Event listeners */
    survSelect.addEventListener('change', updateSourceDisabledState);
    srcList.addEventListener('change', onSourceChange);
    updateSourceDisabledState();
  }

  /* ── disable survivor's own checkbox in source list ── */
  function updateSourceDisabledState() {
    var survivorId = parseInt(survSelect.value, 10);
    var cbs = srcList.querySelectorAll('.sb-merge-source-cb');
    cbs.forEach(function (cb) {
      var id = parseInt(cb.value, 10);
      cb.disabled = (id === survivorId);
      if (id === survivorId) { cb.checked = false; }
      cb.closest('label').classList.toggle('sb-merge-source-row--disabled', id === survivorId);
    });
    onSourceChange();
  }

  /* ── rebuild blend inputs when checked sources change ── */
  function onSourceChange() {
    var checkedSources = getCheckedSources();
    confirmBtn.disabled = checkedSources.length === 0;

    /* Rebuild blend inputs */
    blendInputs.innerHTML = '';
    if (checkedSources.length === 0) {
      blendSec.hidden = true;
      return;
    }
    blendSec.hidden = false;
    checkedSources.forEach(function (m) {
      var row = document.createElement('div');
      row.className = 'sb-merge-blend-row';

      var lbl = document.createElement('label');
      lbl.className = 'sb-merge-blend-label';
      lbl.setAttribute('for', 'sb-merge-blend-' + m.id);
      lbl.innerHTML = '<em>' + escHtml(m.recipe_name || '—') + '</em>'
                    + ' #' + escHtml(m.batch || '?');

      var inp = document.createElement('input');
      inp.type        = 'number';
      inp.id          = 'sb-merge-blend-' + m.id;
      inp.className   = 'sb-merge-blend-input';
      inp.min         = '0';
      inp.max         = '100';
      inp.step        = '0.01';
      inp.placeholder = '—';
      inp.dataset.motherId = String(m.id);
      inp.setAttribute('aria-label', 'Blend share % pour ' + (m.recipe_name || '') + ' #' + (m.batch || ''));

      var pctSpan = document.createElement('span');
      pctSpan.className = 'sb-merge-blend-unit';
      pctSpan.textContent = '%';

      row.appendChild(lbl);
      row.appendChild(inp);
      row.appendChild(pctSpan);
      blendInputs.appendChild(row);
    });
  }

  /* ── helpers ── */
  function getCheckedSources() {
    var cbs     = srcList.querySelectorAll('.sb-merge-source-cb:checked:not(:disabled)');
    var survivorId = parseInt(survSelect.value, 10);
    var result  = [];
    cbs.forEach(function (cb) {
      var id = parseInt(cb.value, 10);
      if (id !== survivorId) {
        var m = _allMothers.find(function (x) { return x.id === id; });
        if (m) { result.push(m); }
      }
    });
    return result;
  }

  /* ── confirm (POST) ── */
  function handleConfirm() {
    var survivorId     = parseInt(survSelect.value, 10);
    var checkedSources = getCheckedSources();

    if (checkedSources.length === 0) {
      alert('Sélectionnez au moins un lot source.');
      return;
    }
    if (!confirm(
      'Fusionner ' + checkedSources.length + ' lot' + (checkedSources.length > 1 ? 's' : '')
      + ' dans le survivant ?\nLes sources seront clôturées définitivement.'
    )) { return; }

    confirmBtn.disabled    = true;
    confirmBtn.textContent = 'Application…';

    /* Build form body */
    var body = new URLSearchParams();
    body.append('csrf',        CSRF);
    body.append('survivor_id', String(survivorId));

    checkedSources.forEach(function (m, i) {
      body.append('source_ids[]', String(m.id));
      /* blend_share_pct: read from rendered input */
      var inp = document.getElementById('sb-merge-blend-' + m.id);
      var pctVal = inp && inp.value.trim() !== '' ? inp.value.trim() : '';
      body.append('blend_share_pct[]', pctVal);
    });

    fetch('/api/sb-merge.php', {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:        body.toString(),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok) {
        confirmBtn.disabled    = false;
        confirmBtn.textContent = 'Fusionner';
        alert('Erreur: ' + escHtml(data.detail || data.error || 'interne'));
        return;
      }
      var result = data.result || {};
      var errors = result.errors || [];
      if (errors.length > 0) {
        alert(
          'Fusion partiellement appliquée:\n'
          + 'Fusionnés : ' + (result.merged_count || 0) + '\n'
          + 'Erreurs:\n - ' + errors.join('\n - ')
        );
      }
      window.location.reload();
    })
    .catch(function () {
      confirmBtn.disabled    = false;
      confirmBtn.textContent = 'Fusionner';
      alert('Erreur réseau — réessayer.');
    });
  }

  /* ── event wiring ── */
  if (closeBtn)  { closeBtn.addEventListener('click',  close); }
  if (cancelBtn) { cancelBtn.addEventListener('click', close); }
  if (confirmBtn){ confirmBtn.addEventListener('click', handleConfirm); }

  if (backdrop) {
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) { close(); }
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !backdrop.hidden) { close(); }
  });

  /* Expose open() globally for footer button onclick */
  window.sbMerge = { open: open };
}());
</script>
