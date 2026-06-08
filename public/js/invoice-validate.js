/**
 * invoice-validate.js — « À valider » page interactions (B1b)
 *
 * Handles per-card Valider/Refuser actions and the Tout valider bulk button.
 * All data is server-rendered; this file is purely interaction + polling.
 *
 * Contract:
 *   window.IV_CSRF   — current CSRF token (string)
 *   window.IV_COUNT  — number of invoices listed (integer)
 *
 * Polling: GET /api/upload-status.php?upload_id=N
 *   commit_status: 'pending' | 'done' | 'failed' | 'rejected'
 *   Polls every 2s up to 120s (commit worker takes ~5-30s).
 *
 * CSRF expiry: on {ok:false, error:'csrf_invalid'} responses the CSRF token
 * is refreshed from the session-ping endpoint and the request retried once.
 */

(function () {
  'use strict';

  var csrf = window.IV_CSRF || '';

  /* ── CSRF refresh (mirrors form-resilience.js pattern) ──────────────────── */
  function refreshCsrf(callback) {
    fetch('/api/session-ping.php', {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.csrf) {
          csrf = data.csrf;
        }
        callback();
      })
      .catch(function () { callback(); });
  }

  /* ── escHtml helper ─────────────────────────────────────────────────────── */
  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /* ── SR status update ───────────────────────────────────────────────────── */
  var srStatus = document.getElementById('iv-sr-status');
  function announceSr(msg) {
    if (srStatus) { srStatus.textContent = msg; }
  }

  /* ── Overlay helpers ────────────────────────────────────────────────────── */
  function showSpinner(uploadId, msg) {
    var overlay = document.getElementById('iv-overlay-' + uploadId);
    if (!overlay) return;
    overlay.hidden = false;
    var inner = overlay.querySelector('.iv-overlay-inner');
    if (inner) {
      inner.innerHTML =
        '<div class="iv-spinner" aria-hidden="true"></div>' +
        '<div style="color:var(--ink-mute);font-size:13px;">' + escHtml(msg) + '</div>';
    }
  }

  function showCardDone(uploadId) {
    var card = document.getElementById('iv-card-' + uploadId);
    var overlay = document.getElementById('iv-overlay-' + uploadId);
    if (overlay) {
      overlay.hidden = false;
      var inner = overlay.querySelector('.iv-overlay-inner');
      if (inner) {
        inner.innerHTML =
          '<div style="font-size:28px;color:var(--ok);">✓</div>' +
          '<div style="color:var(--ok);font-size:15px;font-weight:600;">Écrit en base</div>';
      }
    }
    if (card) { card.classList.add('iv-card--done'); }
    // Remove card from list after brief delay
    setTimeout(function () {
      if (card) { card.remove(); }
      updateBulkButton();
    }, 1400);
    announceSr('Facture validée et écrite en base.');
  }

  function showCardError(uploadId, errMsg) {
    var card = document.getElementById('iv-card-' + uploadId);
    var overlay = document.getElementById('iv-overlay-' + uploadId);
    if (overlay) {
      overlay.hidden = false;
      var inner = overlay.querySelector('.iv-overlay-inner');
      if (inner) {
        inner.innerHTML =
          '<div style="font-size:24px;color:var(--ember);">✗</div>' +
          '<div style="color:var(--ember);font-size:13px;font-weight:600;">Échec</div>' +
          '<div style="color:var(--ink-mute);font-size:12px;max-width:280px;">' + escHtml(errMsg || 'Erreur inconnue') + '</div>' +
          '<button type="button" class="iv-btn" style="margin-top:4px;font-size:12px;padding:5px 12px;min-height:36px;border-color:var(--hairline-2);background:var(--bg);" onclick="(function(b){var ov=b.closest(\'.iv-card-overlay\');if(ov)ov.hidden=true;})(this)">Fermer</button>';
      }
    }
    if (card) { card.classList.add('iv-card--error'); }
    // Re-enable action buttons
    setCardButtonsDisabled(uploadId, false);
    announceSr('Échec de validation : ' + (errMsg || 'erreur inconnue'));
  }

  /* Neutral "still working" state — a poll timeout is NOT a failure. The commit
     worker (cold-start tsx) can outlive the poll window; the write may already
     have landed or be moments away. Do NOT mark the card as error and do NOT
     re-enable Valider (a re-click would be a no-op — validated_at is idempotent). */
  function showCardTimeout(uploadId) {
    var overlay = document.getElementById('iv-overlay-' + uploadId);
    if (overlay) {
      overlay.hidden = false;
      var inner = overlay.querySelector('.iv-overlay-inner');
      if (inner) {
        inner.innerHTML =
          '<div style="font-size:24px;color:var(--oak);">⏳</div>' +
          '<div style="color:var(--ink);font-size:13px;font-weight:600;">Toujours en cours</div>' +
          '<div style="color:var(--ink-mute);font-size:12px;max-width:280px;">L\'écriture prend plus de temps que prévu. Rechargez la page pour vérifier l\'état — elle a probablement abouti.</div>' +
          '<button type="button" class="iv-btn" style="margin-top:4px;font-size:12px;padding:5px 12px;min-height:36px;border-color:var(--hairline-2);background:var(--bg);" onclick="location.reload()">Recharger</button>';
      }
    }
    announceSr('Validation toujours en cours. Rechargez la page pour vérifier l\'état.');
  }

  function showCardRejected(uploadId) {
    var card = document.getElementById('iv-card-' + uploadId);
    var overlay = document.getElementById('iv-overlay-' + uploadId);
    if (overlay) {
      overlay.hidden = true;  /* rejection is handled separately */
    }
    if (card) {
      card.remove();
    }
    updateBulkButton();
    announceSr('Facture refusée.');
  }

  /* ── Card button enable/disable ─────────────────────────────────────────── */
  function setCardButtonsDisabled(uploadId, disabled) {
    var card = document.getElementById('iv-card-' + uploadId);
    if (!card) return;
    var btns = card.querySelectorAll('.iv-btn-validate, .iv-btn-reject');
    btns.forEach(function (btn) { btn.disabled = disabled; });
  }

  /* ── Bulk button count update ───────────────────────────────────────────── */
  function updateBulkButton() {
    var list      = document.getElementById('iv-card-list');
    var bulkBtn   = document.getElementById('iv-btn-bulk-validate');
    var countSpan = bulkBtn && bulkBtn.querySelector('.iv-bulk-count');
    if (!list || !bulkBtn) return;
    var remaining = list.querySelectorAll('.iv-card:not(.iv-card--done)').length;
    if (remaining === 0) {
      bulkBtn.disabled = true;
    }
    if (countSpan) {
      countSpan.textContent = '(' + remaining + ')';
    }
  }

  /* ── Polling ────────────────────────────────────────────────────────────── */
  var POLL_INTERVAL_MS = 2000;
  var POLL_MAX_ATTEMPTS = 120; /* 4 min — covers the commit worker's tsx cold-start */

  function pollCommit(uploadId, attempt) {
    attempt = attempt || 0;
    if (attempt >= POLL_MAX_ATTEMPTS) {
      /* One final status check before giving up — the worker may have just
         finished. Only show the neutral timeout state if it's genuinely still
         pending; surface a real failure as an error. */
      fetch('/api/upload-status.php?upload_id=' + encodeURIComponent(uploadId), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store',
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.commit_status === 'done') {
            showCardDone(uploadId);
          } else if (data.commit_status === 'failed') {
            showCardError(uploadId, data.commit_error || data.error_text || 'Erreur du worker');
          } else {
            showCardTimeout(uploadId);
          }
        })
        .catch(function () { showCardTimeout(uploadId); });
      return;
    }
    fetch('/api/upload-status.php?upload_id=' + encodeURIComponent(uploadId), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var cs = data.commit_status;
        if (cs === 'done') {
          showCardDone(uploadId);
        } else if (cs === 'failed') {
          showCardError(uploadId, data.commit_error || data.error_text || 'Erreur du worker');
        } else if (cs === 'rejected') {
          /* Rejected while polling — treat as error */
          showCardError(uploadId, 'Facture refusée pendant le traitement.');
        } else {
          /* pending — keep polling */
          setTimeout(function () {
            pollCommit(uploadId, attempt + 1);
          }, POLL_INTERVAL_MS);
        }
      })
      .catch(function () {
        /* Network error — retry */
        setTimeout(function () {
          pollCommit(uploadId, attempt + 1);
        }, POLL_INTERVAL_MS * 2);
      });
  }

  /* ── Validate action ────────────────────────────────────────────────────── */
  function doValidate(uploadId, isRetry) {
    setCardButtonsDisabled(uploadId, true);
    showSpinner(uploadId, 'Validation en cours…');

    var formData = new FormData();
    formData.append('upload_id', String(uploadId));
    formData.append('csrf', csrf);

    fetch('/api/invoice-validate.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      body: formData,
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          /* Worker fired — start polling */
          showSpinner(uploadId, 'Commit en cours…');
          pollCommit(uploadId, 0);
        } else if (data.error === 'csrf_invalid' && !isRetry) {
          refreshCsrf(function () { doValidate(uploadId, true); });
        } else if (data.error === 'not_validatable') {
          /* Already committed (race) — reload to reflect */
          showCardDone(uploadId);
        } else {
          showCardError(uploadId, data.error || 'Erreur inconnue');
        }
      })
      .catch(function (err) {
        showCardError(uploadId, 'Erreur réseau : ' + (err.message || 'inconnue'));
      });
  }

  /* ── Reject dialog state ────────────────────────────────────────────────── */
  var rejectDialog      = document.getElementById('iv-reject-dialog');
  var rejectConfirmBtn  = document.getElementById('iv-reject-confirm');
  var rejectCancelBtn   = document.getElementById('iv-reject-cancel');
  var rejectReasonInput = document.getElementById('iv-reject-reason');
  var pendingRejectId   = null;

  function openRejectDialog(uploadId) {
    pendingRejectId = uploadId;
    if (rejectReasonInput) rejectReasonInput.value = '';
    if (rejectDialog) {
      rejectDialog.showModal();
      if (rejectReasonInput) rejectReasonInput.focus();
    }
  }

  function closeRejectDialog() {
    pendingRejectId = null;
    if (rejectDialog) rejectDialog.close();
  }

  if (rejectCancelBtn) {
    rejectCancelBtn.addEventListener('click', closeRejectDialog);
  }
  if (rejectDialog) {
    rejectDialog.addEventListener('click', function (e) {
      /* Close on backdrop click */
      if (e.target === rejectDialog) closeRejectDialog();
    });
    rejectDialog.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeRejectDialog();
    });
  }

  function doReject(uploadId, reason, isRetry) {
    var formData = new FormData();
    formData.append('upload_id', String(uploadId));
    formData.append('csrf', csrf);
    if (reason) formData.append('reason', reason);

    fetch('/api/invoice-reject.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      body: formData,
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          showCardRejected(uploadId);
        } else if (data.error === 'csrf_invalid' && !isRetry) {
          refreshCsrf(function () { doReject(uploadId, reason, true); });
        } else {
          showCardError(uploadId, data.error || 'Erreur lors du refus');
          setCardButtonsDisabled(uploadId, false);
        }
      })
      .catch(function (err) {
        showCardError(uploadId, 'Erreur réseau : ' + (err.message || 'inconnue'));
        setCardButtonsDisabled(uploadId, false);
      });
  }

  if (rejectConfirmBtn) {
    rejectConfirmBtn.addEventListener('click', function () {
      if (pendingRejectId === null) return;
      var uploadId = pendingRejectId;
      var reason   = rejectReasonInput ? rejectReasonInput.value.trim() : '';
      closeRejectDialog();
      setCardButtonsDisabled(uploadId, true);
      doReject(uploadId, reason, false);
    });
  }

  /* ── Wire card-level action buttons ─────────────────────────────────────── */
  var cardList = document.getElementById('iv-card-list');
  if (cardList) {
    cardList.addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      if (!btn) return;

      var uploadId = parseInt(btn.dataset.uploadId, 10);
      if (!uploadId) return;

      if (btn.classList.contains('iv-btn-validate')) {
        doValidate(uploadId, false);
      } else if (btn.classList.contains('iv-btn-reject')) {
        openRejectDialog(uploadId);
      }
    });
  }

  /* ── Tout valider (bulk) ─────────────────────────────────────────────────── */
  var bulkBtn = document.getElementById('iv-btn-bulk-validate');
  if (bulkBtn) {
    bulkBtn.addEventListener('click', function () {
      bulkBtn.disabled = true;
      announceSr('Validation en masse démarrée…');

      /* Collect all pending upload IDs from current DOM */
      var cards = document.querySelectorAll('#iv-card-list .iv-card');
      var ids   = [];
      cards.forEach(function (card) {
        var id = parseInt(card.dataset.uploadId, 10);
        if (id && !card.classList.contains('iv-card--done')) {
          ids.push(id);
        }
      });

      if (ids.length === 0) return;

      /* Fire all validates concurrently — each card polls its own completion */
      ids.forEach(function (uploadId) {
        doValidate(uploadId, false);
      });
    });
  }

})();
