<?php
declare(strict_types=1);
/**
 * Partial: sb-cuve-vide-modal.php
 *
 * Cuve-vide confirmation modal for the mother-shell drill-in (sb-mother.php).
 * Included ONCE at the bottom of sb-mother.php, before </main>.
 *
 * Requires (from sb-mother.php scope):
 *   $csrf                — string CSRF token
 *   $currentVesselKind   — string vessel kind (derived from children in sb-mother.php)
 *   $currentVesselNumber — int vessel number (derived from children in sb-mother.php)
 *
 * JS interaction (in sb-mother.js or inline at bottom of sb-mother.php):
 *   window.sbCuveVide.open()  — called by the "Cuve vide" footer button onclick.
 *
 * Wires to: GET/POST /api/sb-cuve-vide.php
 *
 * Design contract: packaging-cuve-vide-modal.html (sb-modal-* namespace).
 */

// Use the derived vars from sb-mother.php (FIX 1 — these are not in $payload top-level).
$cvVesselKind   = $currentVesselKind   ?? null;
$cvVesselNumber = $currentVesselNumber ?? null;

// Human vessel label (reuse smh_vessel_label from sb-mother.php scope).
$cvVesselLabel  = smh_vessel_label($cvVesselKind, $cvVesselNumber);

// JSON-safe vessel params for JS.
$cvKindJs   = htmlspecialchars(json_encode($cvVesselKind), ENT_QUOTES, 'UTF-8');
$cvNumberJs = (int)$cvVesselNumber;
$cvCsrfJs   = htmlspecialchars(json_encode($csrf), ENT_QUOTES, 'UTF-8');
?>

<!-- ══ Cuve-vide modal (Atom 7) ══════════════════════════════════════════ -->
<div class="smh-cv-backdrop" id="smh-cv-backdrop" role="dialog" aria-modal="true"
     aria-labelledby="smh-cv-title" hidden>

  <div class="smh-cv-modal">

    <!-- Header -->
    <div class="smh-cv-header">
      <div class="smh-cv-header__icon" aria-hidden="true">⊠</div>
      <div class="smh-cv-header__text">
        <h2 class="smh-cv-title" id="smh-cv-title">
          <?= smh_esc($cvVesselLabel) ?> est vide — confirmer la clôture
        </h2>
        <p class="smh-cv-sub">
          Les mother shells dont le contenu était dans cette cuve vont être clôturées.
        </p>
      </div>
      <button class="smh-cv-close" id="smh-cv-close-btn"
              aria-label="Annuler et fermer">×</button>
    </div>

    <!-- Body: loaded dynamically by JS -->
    <div class="smh-cv-body" id="smh-cv-body">
      <div class="smh-cv-loading" id="smh-cv-loading" aria-live="polite">
        Chargement des lots concernés…
      </div>

      <!-- Mother cards (rendered by JS after GET preview) -->
      <div class="smh-cv-cards" id="smh-cv-cards" hidden></div>

      <!-- TankSim warning (rendered by JS when tanksim_empty=false) -->
      <div class="smh-cv-tanksim-warn" id="smh-cv-tanksim-warn" hidden role="alert">
        <span class="smh-cv-tanksim-warn__icon" aria-hidden="true">⚠</span>
        <span class="smh-cv-tanksim-warn__text" id="smh-cv-tanksim-text"></span>
      </div>

      <!-- No mothers found -->
      <div class="smh-cv-empty" id="smh-cv-empty" hidden>
        Aucun lot actif trouvé pour cette cuve.
        Vérifiez que les sessions sont bien liées à une mother shell.
      </div>

      <!-- Global reason (shown when vessel has exactly one mother or as fallback) -->
      <div class="smh-cv-global-reason" id="smh-cv-global-reason" hidden>
        <label class="smh-cv-label" for="smh-cv-reason-global">Raison de clôture</label>
        <select class="smh-cv-select" id="smh-cv-reason-global">
          <option value="emballe">Emballé — packaging terminé</option>
          <option value="jete">Jeté — perdu / mauvaise qualité</option>
          <option value="vendu_mout">Vendu moût — cédé avant packaging</option>
          <option value="encore_en_cuve">Encore en cuve — cuve non vide (annule la clôture)</option>
        </select>
      </div>

      <!-- Note -->
      <div class="smh-cv-note-area" id="smh-cv-note-area" hidden>
        <label class="smh-cv-label" for="smh-cv-note">Note de clôture (optionnel)</label>
        <textarea class="smh-cv-textarea" id="smh-cv-note"
                  placeholder="Observations sur la vidange, pertes constatées…"
                  aria-label="Note de clôture optionnelle"></textarea>
      </div>

    </div><!-- /smh-cv-body -->

    <!-- Footer -->
    <div class="smh-cv-footer" id="smh-cv-footer" hidden>
      <div class="smh-cv-footer-micro">
        ⚠ Cette action est irréversible. Pour rouvrir une mother shell, contacter l'administrateur.
      </div>
      <div class="smh-cv-footer-actions">
        <button class="smh-btn smh-btn--secondary" id="smh-cv-cancel-btn">Annuler</button>
        <button class="smh-btn smh-btn--danger"    id="smh-cv-confirm-btn">
          Confirmer la clôture
        </button>
      </div>
    </div>

  </div><!-- /smh-cv-modal -->

</div><!-- /smh-cv-backdrop -->

<script>
/* global sbCuveVide — wired immediately; hoisted before DOMContentLoaded */
(function () {
  'use strict';

  var VESSEL_KIND   = <?= $cvKindJs ?>;
  var VESSEL_NUMBER = <?= $cvNumberJs ?>;
  var CSRF          = <?= $cvCsrfJs ?>;

  var backdrop   = document.getElementById('smh-cv-backdrop');
  var loading    = document.getElementById('smh-cv-loading');
  var cards      = document.getElementById('smh-cv-cards');
  var tsWarn     = document.getElementById('smh-cv-tanksim-warn');
  var tsText     = document.getElementById('smh-cv-tanksim-text');
  var emptyMsg   = document.getElementById('smh-cv-empty');
  var globalRsn  = document.getElementById('smh-cv-global-reason');
  var noteArea   = document.getElementById('smh-cv-note-area');
  var footer     = document.getElementById('smh-cv-footer');
  var confirmBtn = document.getElementById('smh-cv-confirm-btn');
  var cancelBtn  = document.getElementById('smh-cv-cancel-btn');
  var closeBtn   = document.getElementById('smh-cv-close-btn');

  /* ── escaping ── */
  function escHtml(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  /* ── state ── */
  var _preview = null; /* result from GET preview */

  /* ── FIX 6: focus trap ── */
  var _focusTrapHandler = null;
  var _prevFocus = null;

  function installFocusTrap() {
    var modal = document.querySelector('.smh-cv-modal');
    if (!modal) { return; }
    var focusables = modal.querySelectorAll(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );
    if (focusables.length === 0) { return; }
    var firstFocusable = focusables[0];
    var lastFocusable  = focusables[focusables.length - 1];

    _focusTrapHandler = function (e) {
      if (e.key !== 'Tab') { return; }
      if (e.shiftKey) {
        if (document.activeElement === firstFocusable) {
          e.preventDefault();
          lastFocusable.focus();
        }
      } else {
        if (document.activeElement === lastFocusable) {
          e.preventDefault();
          firstFocusable.focus();
        }
      }
    };
    modal.addEventListener('keydown', _focusTrapHandler);
    firstFocusable.focus();
  }

  function removeFocusTrap() {
    var modal = document.querySelector('.smh-cv-modal');
    if (modal && _focusTrapHandler) {
      modal.removeEventListener('keydown', _focusTrapHandler);
      _focusTrapHandler = null;
    }
    if (_prevFocus) { _prevFocus.focus(); _prevFocus = null; }
  }

  /* ── open ── */
  function open() {
    if (!VESSEL_KIND || !VESSEL_NUMBER) {
      alert("Aucune cuve assignée à ce lot — impossible de déclencher Cuve vide.");
      return;
    }
    /* reset UI */
    loading.hidden  = false;
    cards.hidden    = true;
    cards.innerHTML = '';
    tsWarn.hidden   = true;
    emptyMsg.hidden = true;
    globalRsn.hidden= true;
    noteArea.hidden = true;
    footer.hidden   = true;
    confirmBtn.disabled = false;
    confirmBtn.textContent = 'Confirmer la clôture';
    _preview = null;

    _prevFocus = document.activeElement || null;
    backdrop.hidden = false;
    backdrop.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    installFocusTrap();

    fetchPreview();
  }

  /* ── close ── */
  function close() {
    backdrop.hidden = true;
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    _preview = null;
    removeFocusTrap();
  }

  /* ── fetch dry-run preview ── */
  function fetchPreview() {
    var url = '/api/sb-cuve-vide.php?vessel_kind=' + encodeURIComponent(VESSEL_KIND)
            + '&vessel_number=' + encodeURIComponent(VESSEL_NUMBER);
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        loading.hidden = true;
        if (!data.ok) {
          emptyMsg.hidden = false;
          emptyMsg.textContent = 'Erreur: ' + escHtml(data.error || 'interne');
          return;
        }
        _preview = data;
        renderPreview(data);
      })
      .catch(function (err) {
        loading.hidden = true;
        emptyMsg.hidden = false;
        emptyMsg.textContent = 'Erreur réseau — réessayer.';
      });
  }

  /* ── render preview ── */
  function renderPreview(data) {
    var mothers = data.mothers || [];

    /* TankSim warning */
    if (!data.tanksim_empty) {
      tsText.textContent = data.tanksim_note || '';
      tsWarn.hidden = false;
    }

    if (mothers.length === 0) {
      emptyMsg.hidden = false;
      return; /* no footer — nothing to apply */
    }

    /* Mother cards — FIX 7: pct_packaged removed (was duplicate of _sb_pct_packaged;
       not actionable at close time). Cards show recipe/batch/opened_at only. */
    var html = '';
    mothers.forEach(function (m) {
      html += '<div class="smh-cv-card smh-cv-card--included" id="smh-cv-card-' + m.id + '">'
            + '  <div class="smh-cv-card__head">'
            + '    <div class="smh-cv-card__name"><em>' + escHtml(m.recipe_name) + '</em> #' + escHtml(m.batch) + '</div>'
            + (m.opened_at ? '    <span class="smh-cv-card__date">Ouvert le ' + escHtml(m.opened_at.slice(0, 10)) + '</span>' : '')
            + '  </div>'
            + '</div>';
    });
    cards.innerHTML = html;
    cards.hidden = false;

    /* Global reason + note */
    globalRsn.hidden = false;
    noteArea.hidden  = false;
    footer.hidden    = false;
  }

  /* ── confirm (POST) ── */
  function handleConfirm() {
    if (_preview === null) { return; } /* preview not loaded yet */

    var mothers = (_preview && _preview.mothers) || [];
    if (mothers.length === 0) { return; }

    var reason = document.getElementById('smh-cv-reason-global').value;

    /* encore_en_cuve path: warn clearly */
    if (reason === 'encore_en_cuve') {
      if (!confirm(
        'Vous avez sélectionné "Encore en cuve".\n' +
        'Les mother shells ne seront PAS clôturées — elles resteront ouvertes.\n' +
        'Continuer ?'
      )) { return; }
    } else {
      /* Destructive confirm for close paths */
      var n = mothers.length;
      if (!confirm(
        'Clôturer ' + n + ' mother shell' + (n > 1 ? 's' : '') + ' ?\n' +
        'Cette action est irréversible.'
      )) { return; }
    }

    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Application…';

    var note = (document.getElementById('smh-cv-note').value || '').trim();

    var body = new URLSearchParams();
    body.append('csrf',          CSRF);
    body.append('vessel_kind',   VESSEL_KIND);
    body.append('vessel_number', String(VESSEL_NUMBER));
    body.append('reason',        reason);
    if (note) { body.append('note', note); }

    fetch('/api/sb-cuve-vide.php', {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:        body.toString()
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok) {
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Confirmer la clôture';
        alert('Erreur: ' + (data.error || 'interne'));
        return;
      }
      /* FIX 3 — surface partial failures before reloading. */
      var errors = (data.result && data.result.errors) || data.errors || [];
      if (errors.length > 0) {
        alert(
          'Action partiellement appliquée:\n' +
          'Mothers closes : ' + ((data.result && data.result.closed_mothers) || data.closed_mothers || []).length + '\n' +
          'Mothers gardées : ' + ((data.result && data.result.kept_open) || data.kept_open || []).length + '\n' +
          'Erreurs:\n - ' + errors.join('\n - ')
        );
      }
      /* Success — reload the page so the drill-in reflects closed status */
      window.location.reload();
    })
    .catch(function () {
      confirmBtn.disabled = false;
      confirmBtn.textContent = 'Confirmer la clôture';
      alert('Erreur réseau — réessayer.');
    });
  }

  /* ── event wiring ── */
  if (closeBtn)  { closeBtn.addEventListener('click',  close); }
  if (cancelBtn) { cancelBtn.addEventListener('click', close); }
  if (confirmBtn){ confirmBtn.addEventListener('click', handleConfirm); }

  /* Backdrop click closes (outside modal box) */
  if (backdrop) {
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) { close(); }
    });
  }

  /* Escape key */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !backdrop.hidden) { close(); }
  });

  /* Expose open() globally for the footer button onclick */
  window.sbCuveVide = { open: open };
}());
</script>
