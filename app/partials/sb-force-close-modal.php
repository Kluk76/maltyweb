<?php
declare(strict_types=1);
/**
 * Partial: sb-force-close-modal.php
 *
 * Force-close confirmation modal for the mother-shell drill-in (sb-mother.php).
 * Included ONCE at the bottom of sb-mother.php, before </main>.
 *
 * Precondition: only include when ($isActive && !$isArchived) AND admin role.
 *   Gating in sb-mother.php ensures this partial is never rendered for operators.
 *
 * Requires (from sb-mother.php scope):
 *   $csrf       — string CSRF token
 *   $motherId   — int ID of the mother to close
 *   $recipeName — string recipe name
 *   $batch      — string batch identifier
 *
 * JS interaction:
 *   window.sbForceClose.open()  — called by the "Clôturer manuellement" footer button onclick.
 *
 * Wires to: POST /api/sb-force-close.php
 *
 * Design contract: sb-fc-* CSS namespace, body.sb-mother scope.
 */

$fcMotherIdJs   = (int)$motherId;
$fcRecipeNameJs = htmlspecialchars(json_encode($recipeName ?? ''), ENT_QUOTES, 'UTF-8');
$fcBatchJs      = htmlspecialchars(json_encode($batch ?? ''), ENT_QUOTES, 'UTF-8');
$fcCsrfJs       = htmlspecialchars(json_encode($csrf), ENT_QUOTES, 'UTF-8');
?>

<!-- ══ Force-close modal (Atom 8) ══════════════════════════════════════ -->
<div class="sb-fc-backdrop" id="sb-fc-backdrop" role="dialog" aria-modal="true"
     aria-labelledby="sb-fc-title" hidden>

  <div class="sb-fc-modal">

    <!-- Header -->
    <div class="sb-fc-header">
      <div class="sb-fc-header__icon" aria-hidden="true">⊘</div>
      <div class="sb-fc-header__text">
        <h2 class="sb-fc-title" id="sb-fc-title">Clôture manuelle — lot</h2>
        <p class="sb-fc-sub" id="sb-fc-subtitle">
          <!-- recipe + batch filled by JS -->
        </p>
      </div>
      <button class="sb-fc-close" id="sb-fc-close-btn"
              aria-label="Annuler et fermer">×</button>
    </div>

    <!-- Body -->
    <div class="sb-fc-body">

      <div class="sb-fc-warn-banner" role="alert">
        <span class="sb-fc-warn-banner__icon" aria-hidden="true">⚠</span>
        <span class="sb-fc-warn-banner__text">
          Cette clôture manuelle est réservée aux administrateurs et est irréversible.
          Elle court-circuite le workflow normal (cuve-vide, packaging).
        </span>
      </div>

      <div class="sb-fc-field">
        <label class="sb-fc-label" for="sb-fc-reason">
          Raison de clôture <span class="sb-fc-required" aria-hidden="true">*</span>
        </label>
        <textarea class="sb-fc-textarea" id="sb-fc-reason"
                  placeholder="Ex: lot abandonné — défaut qualité irrémédiable"
                  aria-required="true"
                  aria-describedby="sb-fc-reason-hint"
                  rows="3"></textarea>
        <p class="sb-fc-hint" id="sb-fc-reason-hint">
          Minimum 5 caractères. Cette raison sera enregistrée dans le journal d'audit.
        </p>
        <div class="sb-fc-reason-error" id="sb-fc-reason-error" hidden
             role="alert" aria-live="polite"></div>
      </div>

    </div><!-- /sb-fc-body -->

    <!-- Footer -->
    <div class="sb-fc-footer">
      <div class="sb-fc-footer-micro">
        Action admin uniquement — journalisée dans audit_row_revisions.
      </div>
      <div class="sb-fc-footer-actions">
        <button class="smh-btn smh-btn--secondary" id="sb-fc-cancel-btn">Annuler</button>
        <button class="smh-btn smh-btn--danger"    id="sb-fc-confirm-btn">
          Clôturer manuellement
        </button>
      </div>
    </div>

  </div><!-- /sb-fc-modal -->

</div><!-- /sb-fc-backdrop -->

<script>
/* global sbForceClose — wired immediately; hoisted before DOMContentLoaded */
(function () {
  'use strict';

  var MOTHER_ID   = <?= $fcMotherIdJs ?>;
  var RECIPE_NAME = <?= $fcRecipeNameJs ?>;
  var BATCH       = <?= $fcBatchJs ?>;
  var CSRF        = <?= $fcCsrfJs ?>;

  var backdrop    = document.getElementById('sb-fc-backdrop');
  var subtitle    = document.getElementById('sb-fc-subtitle');
  var reasonInput = document.getElementById('sb-fc-reason');
  var reasonError = document.getElementById('sb-fc-reason-error');
  var confirmBtn  = document.getElementById('sb-fc-confirm-btn');
  var cancelBtn   = document.getElementById('sb-fc-cancel-btn');
  var closeBtn    = document.getElementById('sb-fc-close-btn');

  /* ── escaping ── */
  function escHtml(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  /* ── focus trap ── */
  var _focusTrapHandler = null;
  var _prevFocus = null;

  function installFocusTrap() {
    var modal = document.querySelector('.sb-fc-modal');
    if (!modal) { return; }
    /* Nit 4 fix: live-query focusables per Tab event so any dynamically
       enabled/disabled buttons (validation state) stay in the cycle. */
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
    var modal = document.querySelector('.sb-fc-modal');
    if (modal && _focusTrapHandler) {
      modal.removeEventListener('keydown', _focusTrapHandler);
      _focusTrapHandler = null;
    }
    if (_prevFocus) { _prevFocus.focus(); _prevFocus = null; }
  }

  /* ── open ── */
  function open() {
    /* Populate subtitle with this mother's identity */
    if (subtitle) {
      subtitle.textContent = (RECIPE_NAME || '—') + ' · lot #' + (BATCH || '?');
    }

    /* Reset form */
    reasonInput.value = '';
    reasonError.hidden = true;
    reasonError.textContent = '';
    confirmBtn.disabled = false;
    confirmBtn.textContent = 'Clôturer manuellement';

    _prevFocus = document.activeElement || null;
    backdrop.hidden = false;
    backdrop.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    installFocusTrap();
  }

  /* ── close ── */
  function close() {
    backdrop.hidden = true;
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    removeFocusTrap();
  }

  /* ── confirm (POST) ── */
  function handleConfirm() {
    var reason = reasonInput.value.trim();

    /* Client-side validation */
    if (reason.length < 5) {
      reasonError.textContent = 'La raison doit faire au moins 5 caractères.';
      reasonError.hidden = false;
      reasonInput.focus();
      return;
    }
    reasonError.hidden = true;

    if (!confirm(
      'Clôturer définitivement ce lot ?\n'
      + (RECIPE_NAME || '—') + ' · #' + (BATCH || '?') + '\n\n'
      + 'Cette action est irréversible et sera journalisée.'
    )) { return; }

    confirmBtn.disabled    = true;
    confirmBtn.textContent = 'Application…';

    var body = new URLSearchParams();
    body.append('csrf',      CSRF);
    body.append('mother_id', String(MOTHER_ID));
    body.append('reason',    reason);

    fetch('/api/sb-force-close.php', {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:        body.toString(),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok) {
        confirmBtn.disabled    = false;
        confirmBtn.textContent = 'Clôturer manuellement';
        if (data.error === 'admin-required') {
          alert('Accès refusé — rôle administrateur requis.');
        } else {
          alert('Erreur: ' + escHtml(data.detail || data.error || 'interne'));
        }
        return;
      }
      window.location.reload();
    })
    .catch(function () {
      confirmBtn.disabled    = false;
      confirmBtn.textContent = 'Clôturer manuellement';
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
  window.sbForceClose = { open: open };
}());
</script>
