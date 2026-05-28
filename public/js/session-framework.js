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

  // ── START-phase firewall attestations (P-B) ─────────────────────────────────
  // Buttons live in racking-phase-start.php; data surface via window.SESSION_FIREWALL
  // (injected by session-body-racking.php) and window.BBT_CIP_CADENCE (C6b resolver).

  var SF = window.SESSION_FIREWALL || {};
  var btnAttestCip         = document.getElementById('ss-attest-cip');
  var btnAttestEligibility = document.getElementById('ss-attest-eligibility');
  var btnAttestFirewall    = document.getElementById('ss-attest-firewall');
  var cadenceBadge         = document.getElementById('ss-cadence-badge');
  var cadenceBadgeText     = document.getElementById('ss-cadence-badge-text');
  var overrideReasonWrap   = document.getElementById('ss-fw-override-reason');
  var overrideReasonInput  = document.getElementById('ss-fw-qc-override-note');

  // Apply server-derived done states immediately (mirrors PHP disabled attr — defensive).
  function _applyAttestDone(btn) {
    if (!btn) return;
    btn.disabled = true;
    btn.setAttribute('aria-disabled', 'true');
  }
  if (SF.cip_done)         _applyAttestDone(btnAttestCip);
  if (SF.eligibility_done) _applyAttestDone(btnAttestEligibility);
  if (SF.qc_done)          _applyAttestDone(btnAttestFirewall);

  // ── Cadence badge — show when any BBT has warn/critical severity ─────────────
  (function () {
    var cadence = window.BBT_CIP_CADENCE;
    if (!cadence || !cadenceBadge || !cadenceBadgeText) return;
    var worst = 'ok';
    var msgs = [];
    cadence.forEach(function (bbt) {
      var sev = bbt.severity || 'ok';
      if (sev === 'critical') { worst = 'critical'; msgs.push('BBT ' + bbt.bbt_number + ' : CIP requis'); }
      else if (sev === 'warn' && worst !== 'critical') { worst = 'warn'; msgs.push('BBT ' + bbt.bbt_number + ' : CIP recommandé'); }
    });
    if (worst !== 'ok' && msgs.length) {
      cadenceBadgeText.textContent = msgs.join(' · ');
      cadenceBadge.classList.remove('ss-cadence-badge--hidden');
      // Critical: red-ish (ember); warn: amber (oak). Both already get ember border from CSS.
      if (worst === 'warn') {
        cadenceBadge.style.background = 'color-mix(in srgb, var(--oak) 12%, transparent)';
        cadenceBadge.style.borderColor = 'color-mix(in srgb, var(--oak) 30%, transparent)';
        cadenceBadge.style.color = 'var(--oak)';
      }
    }
  }());

  // ── Gate 1: Attest CIP ───────────────────────────────────────────────────────
  if (btnAttestCip && !SF.cip_done) {
    btnAttestCip.addEventListener('click', function () {
      if (btnAttestCip.disabled) return;
      btnAttestCip.disabled = true;

      // Collect CIP choices from the CIP section (written by cip-section.php/JS).
      // Reads checkbox states for machines and the BBT CIP type; tolerant when not found.
      var cipChoices = {};
      var machineCheckboxes = document.querySelectorAll('[name="cip_machines[]"]:checked, [name="cip_machine"]:checked');
      var machines = [];
      machineCheckboxes.forEach(function (cb) { machines.push(cb.value); });
      cipChoices.machines = machines;

      var destTypeEl = document.querySelector('[name="dest_cip_type_id_fk"], [name="cip_dest_type"]');
      cipChoices.dest_cip_type_id_fk = destTypeEl ? (parseInt(destTypeEl.value, 10) || null) : null;

      postAction('attest_cip', cipChoices, function () {
        SF.cip_done = true;
        showToast('CIP validé.', false);
        // Optimistic UI: show checkmark text inline.
        var checkSvg = '<svg class="ss-btn__check" width="11" height="9" viewBox="0 0 11 9" fill="none" aria-hidden="true"><path d="M1 4.5L4 7.5L10 1.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        btnAttestCip.innerHTML = checkSvg + ' CIP validé';
        btnAttestCip.setAttribute('aria-disabled', 'true');
      }, function () {
        btnAttestCip.disabled = false;
      });
    });
  }

  // ── Gate 2: Attest eligibility ───────────────────────────────────────────────
  if (btnAttestEligibility && !SF.eligibility_done) {
    btnAttestEligibility.addEventListener('click', function () {
      if (btnAttestEligibility.disabled) return;
      btnAttestEligibility.disabled = true;

      // Collect selected lot(s) from the candidate cards.
      var selectedCard = document.querySelector('.rf-cand-card.rf-cand-card--selected, [data-selected="1"]');
      var lots = [];
      var override = null;
      var overrideReason = '';

      if (selectedCard) {
        lots.push({
          neb_beer:    selectedCard.dataset.nebBeer  || null,
          neb_batch:   selectedCard.dataset.nebBatch || null,
          recipe_id:   parseInt(selectedCard.dataset.recipeId, 10) || null,
          source_cct:  parseInt(selectedCard.dataset.sourceCct, 10) || null,
          hors_process: selectedCard.dataset.horsProcess === '1',
        });
        if (selectedCard.dataset.horsProcess === '1') {
          override = 'hors_process';
          var reasonInput = document.getElementById('hors_process_reason');
          overrideReason = reasonInput ? reasonInput.value.trim() : '';
        }
      }

      // Collect recipe ids from all candidates for the recipes array.
      var recipeIds = [];
      var recipeSet = {};
      document.querySelectorAll('.rf-cand-card').forEach(function (card) {
        var rid = parseInt(card.dataset.recipeId, 10);
        if (rid && !recipeSet[rid]) { recipeSet[rid] = true; recipeIds.push(rid); }
      });

      var eligPayload = { lots: lots, recipes: recipeIds };
      if (override) {
        eligPayload.override = override;
        eligPayload.override_reason = overrideReason;
      }

      postAction('attest_eligibility', eligPayload, function () {
        SF.eligibility_done = true;
        showToast('Lots validés.', false);
        var checkSvg = '<svg class="ss-btn__check" width="11" height="9" viewBox="0 0 11 9" fill="none" aria-hidden="true"><path d="M1 4.5L4 7.5L10 1.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        btnAttestEligibility.innerHTML = checkSvg + ' Lots validés';
        btnAttestEligibility.setAttribute('aria-disabled', 'true');
      }, function () {
        btnAttestEligibility.disabled = false;
      });
    });
  }

  // ── Gate 3: Attest firewall QC ───────────────────────────────────────────────
  // Show the override-reason input when a hors-process lot is selected.
  (function () {
    function _syncQcOverrideVisibility() {
      if (!overrideReasonWrap) return;
      var horsProcessHidden = document.getElementById('hors_process');
      var isOverride = horsProcessHidden && horsProcessHidden.value === '1';
      overrideReasonWrap.hidden = !isOverride;
    }
    var hpCheckbox = document.getElementById('rf-override-checkbox');
    if (hpCheckbox) {
      hpCheckbox.addEventListener('change', _syncQcOverrideVisibility);
    }
    // Also sync when any candidate card is clicked (delegated).
    document.addEventListener('click', function (e) {
      if (e.target.closest('.rf-cand-card')) _syncQcOverrideVisibility();
    });
    _syncQcOverrideVisibility();
  }());

  if (btnAttestFirewall && !SF.qc_done) {
    btnAttestFirewall.addEventListener('click', function () {
      if (btnAttestFirewall.disabled) return;

      // If override reason input is visible, require a non-empty note.
      if (overrideReasonWrap && !overrideReasonWrap.hidden) {
        var note = overrideReasonInput ? overrideReasonInput.value.trim() : '';
        if (!note) {
          if (overrideReasonInput) {
            overrideReasonInput.focus();
            overrideReasonInput.style.borderColor = 'var(--ember)';
          }
          showToast('Motif d\'override QC requis.', true);
          return;
        }
        if (overrideReasonInput) overrideReasonInput.style.borderColor = '';
      }

      btnAttestFirewall.disabled = true;

      // Collect QC readings from the QC section fields (in_progress phase inputs).
      // For P-B, the QC fields may not be filled yet; send what is available.
      var readings = {};
      var qcFields = ['bbt_co2', 'bbt_o2', 'bbt_pressure', 'racked_vol_hl'];
      qcFields.forEach(function (field) {
        var el = document.getElementById(field) || document.querySelector('[name="' + field + '"]');
        if (el && el.value !== '') readings[field] = parseFloat(el.value) || null;
      });

      var fwPayload = {
        predicate:           'racking_eligibility_v1',
        passed:              Object.keys(readings),
        failed:              [],
        thresholds_snapshot: window.QC_THRESHOLDS || {},
      };
      if (overrideReasonInput && overrideReasonInput.value.trim()) {
        fwPayload.operator_override_reason = overrideReasonInput.value.trim();
      }

      postAction('attest_firewall', fwPayload, function () {
        SF.qc_done = true;
        showToast('Contrôle QC validé.', false);
        var checkSvg = '<svg class="ss-btn__check" width="11" height="9" viewBox="0 0 11 9" fill="none" aria-hidden="true"><path d="M1 4.5L4 7.5L10 1.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        btnAttestFirewall.innerHTML = checkSvg + ' QC validé';
        btnAttestFirewall.setAttribute('aria-disabled', 'true');
        if (overrideReasonWrap) overrideReasonWrap.hidden = true;
      }, function () {
        btnAttestFirewall.disabled = false;
      });
    });
  }

})();
