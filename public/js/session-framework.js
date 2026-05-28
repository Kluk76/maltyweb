/* session-framework.js — Session lifecycle driver (phase chrome).
 * Sibling of form-framework.js; layers WITH it (this owns phase chrome + attestations + recap; FormFramework owns field QC).
 * Loaded by public/modules/session-shell.php (the server-rendered partial).
 *
 * Click idiom: event delegation on document, same pattern as tankboard-pertes.js
 * and cct-detail-modal.js (document.addEventListener + e.target.closest('[data-*]')).
 *
 * Data surface: PHP embeds window.SS_DATA = {...} (see session-shell.php).
 *   session_id  — int
 *   phase       — string (start|in_progress|end|closed|abandoned)
 *   status      — string (open|closed|abandoned)
 *   csrf        — string (session CSRF token for JSON body)
 *   is_terminal — bool
 *   next_phase  — string|null (the next phase for the Avancer button)
 *   opener_id   — int (opened_by_fk)
 *   me_id       — int (current user id)
 *   handover_dismissed_key — string (sessionStorage key for handover banner)
 */
'use strict';

(function () {
  // ── Data surface (injected by PHP) ─────────────────────────────────────────
  const SD = window.SS_DATA || {};
  const sessionId = SD.session_id || 0;
  const csrfToken = SD.csrf || '';
  const nextPhase = SD.next_phase || null;

  // ── Element refs ────────────────────────────────────────────────────────────
  const btnAdvance   = document.getElementById('ss-btn-advance');
  const btnHandover  = document.getElementById('ss-btn-handover');
  const btnNote      = document.getElementById('ss-btn-note');
  const btnAbandon   = document.getElementById('ss-btn-abandon');

  const dlgAbandon   = document.getElementById('ss-dlg-abandon');
  const dlgHandover  = document.getElementById('ss-dlg-handover');
  const dlgNote      = document.getElementById('ss-dlg-note');

  const toast        = document.getElementById('ss-toast');

  // ── Toast helper ────────────────────────────────────────────────────────────
  let toastTimer = null;
  function showToast(msg, isError) {
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.toggle('ss-toast--error', !!isError);
    toast.classList.add('ss-toast--visible');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      toast.classList.remove('ss-toast--visible');
    }, 4000);
  }

  // ── Core POST helper ────────────────────────────────────────────────────────
  // Sends a JSON body to /api/session-action.php.
  // CSRF token is embedded in the JSON body (matching project convention for
  // form-encoded POSTs that use csrf_verify($_POST['csrf'] ?? null)).
  // Since the endpoint reads php://input for the JSON body, the CSRF is in the
  // JSON payload — the endpoint extracts it from $data['csrf'].
  function postAction(action, payload, onSuccess, onError) {
    var body = Object.assign({ session_id: sessionId, action: action, csrf: csrfToken }, payload ? { payload: payload } : {});

    fetch('/api/session-action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          if (typeof onSuccess === 'function') onSuccess(data);
          else window.location.reload();
        } else {
          var msg = data.error || 'Erreur inconnue.';
          showToast(msg, true);
          if (typeof onError === 'function') onError(msg);
        }
      })
      .catch(function (err) {
        showToast('Erreur réseau. Réessayez.', true);
        if (typeof onError === 'function') onError(err);
      });
  }

  // ── Avancer (advance_phase) ─────────────────────────────────────────────────
  // Derives the next phase from window.SS_DATA.next_phase (set server-side).
  if (btnAdvance) {
    btnAdvance.addEventListener('click', function () {
      if (btnAdvance.disabled) return;
      if (!nextPhase) {
        showToast('Aucune phase suivante disponible.', true);
        return;
      }
      btnAdvance.disabled = true;
      postAction('advance_phase', { to_phase: nextPhase }, function () {
        window.location.reload();
      }, function () {
        // Re-enable on error so the operator can retry.
        btnAdvance.disabled = false;
      });
    });
  }

  // ── Abandonner ─────────────────────────────────────────────────────────────
  if (btnAbandon && dlgAbandon) {
    btnAbandon.addEventListener('click', function () {
      dlgAbandon.showModal();
    });

    // Close via ✕ button (data-dlg-close)
    dlgAbandon.addEventListener('click', function (e) {
      if (e.target.closest('[data-dlg-close]')) dlgAbandon.close();
    });

    // Keyboard: Escape closes (native for <dialog>); Enter confirms when focus is in textarea
    dlgAbandon.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') dlgAbandon.close();
    });

    var confirmAbandonBtn = dlgAbandon.querySelector('[data-confirm-abandon]');
    var abandonTextarea   = dlgAbandon.querySelector('[data-abandon-reason]');
    if (confirmAbandonBtn && abandonTextarea) {
      confirmAbandonBtn.addEventListener('click', function () {
        var reason = abandonTextarea.value.trim();
        if (!reason) {
          abandonTextarea.focus();
          abandonTextarea.style.borderColor = 'var(--ember)';
          return;
        }
        abandonTextarea.style.borderColor = '';
        confirmAbandonBtn.disabled = true;
        postAction('abandon', { reason: reason }, function () {
          dlgAbandon.close();
          window.location.reload();
        }, function () {
          confirmAbandonBtn.disabled = false;
        });
      });
    }
  }

  // ── Passer la main ─────────────────────────────────────────────────────────
  if (btnHandover && dlgHandover) {
    btnHandover.addEventListener('click', function () {
      dlgHandover.showModal();
    });

    dlgHandover.addEventListener('click', function (e) {
      if (e.target.closest('[data-dlg-close]')) dlgHandover.close();
    });

    dlgHandover.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') dlgHandover.close();
    });

    var confirmHandoverBtn = dlgHandover.querySelector('[data-confirm-handover]');
    var handoverSelect     = dlgHandover.querySelector('[data-handover-user]');
    var handoverNote       = dlgHandover.querySelector('[data-handover-note]');
    if (confirmHandoverBtn && handoverSelect) {
      confirmHandoverBtn.addEventListener('click', function () {
        var toUserId = parseInt(handoverSelect.value, 10);
        if (!toUserId || toUserId <= 0) {
          handoverSelect.focus();
          return;
        }
        var note = handoverNote ? handoverNote.value.trim() : null;
        confirmHandoverBtn.disabled = true;
        postAction('handover', { to_user_id: toUserId, note: note || null }, function () {
          dlgHandover.close();
          window.location.reload();
        }, function () {
          confirmHandoverBtn.disabled = false;
        });
      });
    }
  }

  // ── Ajouter une note ───────────────────────────────────────────────────────
  if (btnNote && dlgNote) {
    btnNote.addEventListener('click', function () {
      dlgNote.showModal();
    });

    dlgNote.addEventListener('click', function (e) {
      if (e.target.closest('[data-dlg-close]')) dlgNote.close();
    });

    dlgNote.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') dlgNote.close();
    });

    var confirmNoteBtn = dlgNote.querySelector('[data-confirm-note]');
    var noteTextarea   = dlgNote.querySelector('[data-note-text]');
    if (confirmNoteBtn && noteTextarea) {
      confirmNoteBtn.addEventListener('click', function () {
        var text = noteTextarea.value.trim();
        if (!text) {
          noteTextarea.focus();
          return;
        }
        confirmNoteBtn.disabled = true;
        postAction('add_note', { text: text }, function () {
          dlgNote.close();
          noteTextarea.value = '';
          window.location.reload();
        }, function () {
          confirmNoteBtn.disabled = false;
        });
      });
    }
  }

  // ── Keyboard: Enter/Space on dialog confirm buttons ─────────────────────────
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var btn = e.target.closest('[data-confirm-abandon],[data-confirm-handover],[data-confirm-note]');
    if (!btn) return;
    e.preventDefault();
    btn.click();
  });

  // ── Handover banner dismiss (UI-only, no POST) ──────────────────────────────
  // PHP may show the banner; JS dismisses and remembers via sessionStorage.
  var handoverBanner = document.getElementById('ss-handover-banner');
  if (handoverBanner) {
    var dismissKey = SD.handover_dismissed_key || ('ss-handover-dismissed-' + sessionId);
    // Re-hide if already dismissed this browser session.
    if (sessionStorage.getItem(dismissKey)) {
      handoverBanner.style.display = 'none';
    }
    var dismissBtn = handoverBanner.querySelector('[data-dismiss-banner]');
    if (dismissBtn) {
      dismissBtn.addEventListener('click', function () {
        handoverBanner.style.display = 'none';
        sessionStorage.setItem(dismissKey, '1');
      });
    }
  }

  // ── Audit rail collapse (tablet) ────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    var toggleBtn = e.target.closest('[data-rail-toggle]');
    if (!toggleBtn) return;

    var entriesId = toggleBtn.dataset.railToggle;
    var entries   = document.getElementById(entriesId);
    if (!entries) return;

    var expanded = toggleBtn.getAttribute('aria-expanded') === 'true';
    entries.classList.toggle('rail-collapsed', expanded);
    toggleBtn.setAttribute('aria-expanded', String(!expanded));
    toggleBtn.textContent = expanded ? 'Journal ▾' : 'Journal ▲';
  });

  // ── Keyboard: Enter/Space on rail toggle ───────────────────────────────────
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var btn = e.target.closest('[data-rail-toggle]');
    if (!btn) return;
    e.preventDefault();
    btn.click();
  });

  // ── Sticky rail offset — adjust for sticky header height ───────────────────
  // Runs once on load; the header is sticky so its height is stable.
  var rail = document.querySelector('.ss-rail');
  var header = document.querySelector('.ss-header');
  var stepper = document.querySelector('.ss-stepper');
  if (rail && header && stepper) {
    var topOffset = header.offsetHeight + stepper.offsetHeight;
    rail.style.top = topOffset + 'px';
    rail.style.maxHeight = 'calc(100vh - ' + topOffset + 'px)';
    rail.style.overflowY = 'auto';
  }

})();
