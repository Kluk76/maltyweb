<?php
declare(strict_types=1);
/**
 * Partial: sb-retro-link-modal.php
 *
 * Retro-link proposals modal for the Mother Shell board (sb-board.php).
 * Included ONCE at the bottom of sb-board.php, before </main>.
 *
 * Admin-only precondition: caller MUST gate the include on
 *   ($me['role'] ?? '') === 'admin'
 *
 * Requires (from sb-board.php scope):
 *   $csrf  — string CSRF token
 *
 * The modal fetches proposals on open via GET /api/sb-retro-link.php.
 * Apply is a POST /api/sb-retro-link.php with apply=1 + CSRF.
 *
 * JS interaction:
 *   window.sbRetroLink.open()  — called by the .sb-rl-trigger button onclick.
 *
 * Design contract: sb-rl-* CSS namespace, body.sb-board scope.
 */

$rlCsrfJs = htmlspecialchars(json_encode($csrf), ENT_QUOTES, 'UTF-8');
?>

<!-- ══ Retro-link modal (Atom 11.a) ══════════════════════════════════════ -->
<div class="sb-rl-backdrop" id="sb-rl-backdrop" role="dialog" aria-modal="true"
     aria-labelledby="sb-rl-title" hidden>

  <div class="sb-rl-modal">

    <!-- Header -->
    <div class="sb-rl-header">
      <div class="sb-rl-header__icon" aria-hidden="true">⇆</div>
      <div class="sb-rl-header__text">
        <h2 class="sb-rl-title" id="sb-rl-title">Liaisons rétroactives — propositions</h2>
        <p class="sb-rl-sub" id="sb-rl-subtitle">Chargement des propositions…</p>
      </div>
      <button class="sb-rl-close" id="sb-rl-close-btn"
              aria-label="Fermer">×</button>
    </div>

    <!-- Body -->
    <div class="sb-rl-body" id="sb-rl-body">

      <!-- Loading state (shown on open) -->
      <div class="sb-rl-loading" id="sb-rl-loading" aria-live="polite">
        Chargement des propositions…
      </div>

      <!-- Error state -->
      <div class="sb-rl-error" id="sb-rl-error" hidden role="alert">
        <span class="sb-rl-error__text">Erreur de chargement — réessayer.</span>
        <button class="sb-rl-retry-btn" id="sb-rl-retry-btn">Réessayer</button>
      </div>

      <!-- Empty state -->
      <div class="sb-rl-empty" id="sb-rl-empty" hidden>
        Aucune liaison rétroactive nécessaire — toutes les sessions historiques sont déjà rattachées à une mother.
      </div>

      <!-- Proposals table -->
      <div class="sb-rl-table-wrap" id="sb-rl-table-wrap" hidden>
        <table class="sb-rl-table" id="sb-rl-table">
          <thead>
            <tr>
              <th scope="col">Session</th>
              <th scope="col">Type</th>
              <th scope="col">Recette</th>
              <th scope="col">Lot</th>
              <th scope="col">Action</th>
              <th scope="col">Détail</th>
            </tr>
          </thead>
          <tbody id="sb-rl-tbody"></tbody>
        </table>
      </div>

    </div><!-- /sb-rl-body -->

    <!-- Footer -->
    <div class="sb-rl-footer">
      <div class="sb-rl-footer-micro" id="sb-rl-footer-micro">
        Action admin uniquement — liaisons irréversibles.
      </div>
      <div class="sb-rl-footer-actions">
        <button class="sb-rl-btn sb-rl-btn--secondary" id="sb-rl-cancel-btn">Fermer</button>
        <button class="sb-rl-btn sb-rl-btn--apply" id="sb-rl-apply-btn" disabled hidden>
          Appliquer ces liaisons
        </button>
      </div>
    </div>

  </div><!-- /sb-rl-modal -->

</div><!-- /sb-rl-backdrop -->

<script>
/* global sbRetroLink — wired immediately; hoisted before DOMContentLoaded */
(function () {
  'use strict';

  var CSRF = <?= $rlCsrfJs ?>;

  var backdrop   = document.getElementById('sb-rl-backdrop');
  var subtitle   = document.getElementById('sb-rl-subtitle');
  var loading    = document.getElementById('sb-rl-loading');
  var errorBox   = document.getElementById('sb-rl-error');
  var emptyBox   = document.getElementById('sb-rl-empty');
  var tableWrap  = document.getElementById('sb-rl-table-wrap');
  var tbody      = document.getElementById('sb-rl-tbody');
  var applyBtn   = document.getElementById('sb-rl-apply-btn');
  var cancelBtn  = document.getElementById('sb-rl-cancel-btn');
  var closeBtn   = document.getElementById('sb-rl-close-btn');
  var retryBtn   = document.getElementById('sb-rl-retry-btn');

  /* ── escaping ── */
  function escHtml(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  /* ── state ── */
  var _proposals = [];

  /* ── focus trap ── */
  var _focusTrapHandler = null;
  var _prevFocus = null;

  function installFocusTrap() {
    var modal = document.querySelector('.sb-rl-modal');
    if (!modal) { return; }
    /* Live-query focusables per Tab event so dynamic button state is respected. */
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
    var modal = document.querySelector('.sb-rl-modal');
    if (modal && _focusTrapHandler) {
      modal.removeEventListener('keydown', _focusTrapHandler);
      _focusTrapHandler = null;
    }
    if (_prevFocus) { _prevFocus.focus(); _prevFocus = null; }
  }

  /* ── reset body to loading state ── */
  function resetBody() {
    loading.hidden   = false;
    errorBox.hidden  = true;
    emptyBox.hidden  = true;
    tableWrap.hidden = true;
    tbody.innerHTML  = '';
    applyBtn.disabled = true;
    applyBtn.hidden   = true;
    applyBtn.textContent = 'Appliquer ces liaisons';
    subtitle.textContent = 'Chargement des propositions…';
    _proposals = [];
  }

  /* ── open ── */
  function open() {
    resetBody();
    _prevFocus = document.activeElement || null;
    backdrop.hidden = false;
    backdrop.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    installFocusTrap();
    fetchProposals();
  }

  /* ── close ── */
  function close() {
    backdrop.hidden = true;
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    removeFocusTrap();
  }

  /* ── fetch proposals via GET ── */
  function fetchProposals() {
    fetch('/api/sb-retro-link.php', { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) { throw new Error('http-' + r.status); }
        return r.json();
      })
      .then(function (data) {
        loading.hidden = true;
        if (!data.ok) {
          showError();
          return;
        }
        _proposals = data.proposals || [];
        renderProposals(_proposals);
      })
      .catch(function () {
        loading.hidden = true;
        showError();
      });
  }

  /* ── show error state ── */
  function showError() {
    errorBox.hidden = true; /* force reflow so aria-live fires */
    void errorBox.offsetHeight;
    errorBox.hidden = false;
    subtitle.textContent = 'Erreur de chargement.';
  }

  /* ── action label ── */
  function actionLabel(action) {
    if (action === 'link')   { return 'Lier'; }
    if (action === 'create') { return 'Créer + Lier'; }
    if (action === 'skip')   { return 'Ignorer'; }
    return escHtml(action);
  }

  /* ── action CSS modifier ── */
  function actionMod(action) {
    if (action === 'link')   { return 'link'; }
    if (action === 'create') { return 'create'; }
    if (action === 'skip')   { return 'skip'; }
    return '';
  }

  /* ── form_type human label ── */
  function typeLabel(t) {
    var map = {
      'brewing':    'Brassage',
      'fermenting': 'Fermentation',
      'racking':    'Transfert',
      'packaging':  'Conditionnement',
    };
    return map[t] || escHtml(t);
  }

  /* ── recipe name lookup (injected as window.SB_RECIPES by sb-board.php) ── */
  function recipeName(recipeIdFk) {
    var recipes = window.SB_RECIPES || {};
    var name = recipes[recipeIdFk];
    return name ? escHtml(name) : '<span class="sb-rl-id-fallback">id=' + escHtml(String(recipeIdFk)) + '</span>';
  }

  /* ── render proposals table ── */
  function renderProposals(proposals) {
    var actionable = proposals.filter(function (p) { return p.action !== 'skip'; });

    if (proposals.length === 0) {
      emptyBox.hidden = false;
      subtitle.textContent = 'Aucune proposition — tout est déjà lié.';
      return;
    }

    /* Subtitle */
    var countMsg = proposals.length === 1
      ? '1 proposition trouvée'
      : proposals.length + ' propositions trouvées';
    if (actionable.length < proposals.length) {
      countMsg += ' (' + (proposals.length - actionable.length) + ' ignorée' + (proposals.length - actionable.length > 1 ? 's' : '') + ')';
    }
    subtitle.textContent = countMsg;

    /* Build rows */
    tbody.innerHTML = '';
    proposals.forEach(function (p) {
      var tr = document.createElement('tr');
      tr.className = 'sb-rl-row sb-rl-row--' + escHtml(actionMod(p.action));

      /* session_id */
      var tdId = document.createElement('td');
      tdId.className = 'sb-rl-td sb-rl-td--id';
      tdId.textContent = String(p.session_id);
      tr.appendChild(tdId);

      /* form_type */
      var tdType = document.createElement('td');
      tdType.className = 'sb-rl-td sb-rl-td--type';
      tdType.textContent = typeLabel(p.form_type);
      tr.appendChild(tdType);

      /* recipe */
      var tdRecipe = document.createElement('td');
      tdRecipe.className = 'sb-rl-td sb-rl-td--recipe';
      tdRecipe.innerHTML = recipeName(p.recipe_id_fk);
      tr.appendChild(tdRecipe);

      /* batch */
      var tdBatch = document.createElement('td');
      tdBatch.className = 'sb-rl-td sb-rl-td--batch';
      tdBatch.textContent = p.batch || '—';
      tr.appendChild(tdBatch);

      /* action badge */
      var tdAction = document.createElement('td');
      tdAction.className = 'sb-rl-td sb-rl-td--action';
      var badge = document.createElement('span');
      badge.className = 'sb-rl-badge sb-rl-badge--' + escHtml(actionMod(p.action));
      badge.textContent = actionLabel(p.action);
      /* mother_id hint for link actions */
      if (p.action === 'link' && p.mother_id) {
        badge.title = 'Lier à la mother #' + p.mother_id;
      }
      tdAction.appendChild(badge);
      tr.appendChild(tdAction);

      /* reason */
      var tdReason = document.createElement('td');
      tdReason.className = 'sb-rl-td sb-rl-td--reason';
      tdReason.textContent = p.reason || '—';
      tr.appendChild(tdReason);

      tbody.appendChild(tr);
    });

    tableWrap.hidden = false;

    /* Only show apply button when there are actionable proposals */
    if (actionable.length > 0) {
      applyBtn.textContent = 'Appliquer ces ' + actionable.length + ' liaison' + (actionable.length > 1 ? 's' : '');
      applyBtn.disabled = false;
      applyBtn.hidden   = false;
    }
  }

  /* ── apply (POST) ── */
  function handleApply() {
    var actionable = _proposals.filter(function (p) { return p.action !== 'skip'; });
    if (actionable.length === 0) { return; }

    if (!confirm(
      'Appliquer ' + actionable.length + ' liaison' + (actionable.length > 1 ? 's' : '') + ' rétroactive' + (actionable.length > 1 ? 's' : '') + ' ?\n'
      + 'Cette action modifie les sessions historiques et est irréversible.'
    )) { return; }

    applyBtn.disabled    = true;
    applyBtn.textContent = 'Application…';

    var body = new URLSearchParams();
    body.append('csrf',  CSRF);
    body.append('apply', '1');

    fetch('/api/sb-retro-link.php', {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:        body.toString(),
    })
    .then(function (r) { if (!r.ok) { throw new Error('http-' + r.status); } return r.json(); })
    .then(function (data) {
      if (!data.ok) {
        applyBtn.disabled    = false;
        applyBtn.textContent = 'Appliquer ces ' + actionable.length + ' liaison' + (actionable.length > 1 ? 's' : '');
        if (data.error === 'admin-required') {
          alert('Accès refusé — rôle administrateur requis.');
        } else {
          alert('Erreur: ' + escHtml(data.error || 'interne'));
        }
        return;
      }
      var result  = data.result || {};
      var errors  = result.errors || [];
      if (errors.length > 0) {
        alert(
          'Liaisons partiellement appliquées:\n'
          + 'Appliquées : ' + (result.applied || 0) + '\n'
          + 'Ignorées : '   + (result.skipped || 0) + '\n'
          + 'Erreurs:\n - ' + errors.join('\n - ')
        );
      }
      window.location.reload();
    })
    .catch(function () {
      applyBtn.disabled    = false;
      applyBtn.textContent = 'Appliquer ces ' + actionable.length + ' liaison' + (actionable.length > 1 ? 's' : '');
      alert('Erreur réseau — réessayer.');
    });
  }

  /* ── event wiring ── */
  if (closeBtn)  { closeBtn.addEventListener('click',  close); }
  if (cancelBtn) { cancelBtn.addEventListener('click', close); }
  if (applyBtn)  { applyBtn.addEventListener('click',  handleApply); }
  if (retryBtn)  { retryBtn.addEventListener('click',  function () { resetBody(); fetchProposals(); }); }

  if (backdrop) {
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) { close(); }
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !backdrop.hidden) { close(); }
  });

  /* Expose open() globally for trigger button onclick */
  window.sbRetroLink = { open: open };
}());
</script>
