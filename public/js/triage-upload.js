/**
 * triage-upload.js — Upload UI for MaltyTask triage page
 *
 * Handles:
 *  - Desktop drag-drop + browse single/multi file (bulk mode: N files = N separate ingest jobs)
 *  - Mobile multi-shot camera capture panel (stitch-as-one-document, unchanged)
 *  - FormData POST to /api/upload-document.php
 *  - Polling /api/upload-status.php with backoff
 *  - State machine:
 *      Single/multishot: idle → capturing → uploading → polling → done/timeout/error
 *      Bulk:             idle → uploading → bulk-progress (per-file polling)
 *  - B2: When upload resolves to an invoice awaiting validation, renders the full
 *        per-line recap card (supplier/ref/date/total + lines table) with inline
 *        ✓ Valider / ✗ Refuser actions + commit_status polling.
 *
 * Requires: <meta name="csrf-token" content="..."> in the page head.
 * No external dependencies.
 */

(function () {
  'use strict';

  // ─── Config ─────────────────────────────────────────────────────────────────
  const POLL_INTERVAL_FAST = 2500;  // ms — first 10 polls
  const POLL_INTERVAL_SLOW = 5000;  // ms — after 10 polls
  const POLL_MAX           = 60;    // polls before timeout state
  const UPLOAD_ENDPOINT    = '/api/upload-document.php';
  const STATUS_ENDPOINT    = '/api/upload-status.php';

  // ─── State (single/multishot path) ──────────────────────────────────────────
  /** @type {'idle'|'capturing'|'uploading'|'polling'|'done'|'timeout'|'error'|'bulk-progress'} */
  let state       = 'idle';
  let pollCount   = 0;
  let pollTimer   = null;
  let uploadCtrl  = null; // AbortController for fetch
  let capturedFiles = []; // File[] collected in multi-shot mode
  let startedAt   = 0;    // Date.now() when upload POST fired
  let elapsedTimer = null;
  let lastPollData = null; // last JSON payload from upload-status (for summary + error_text)
  let lastUploadId = null; // upload_id for timeout message

  // ─── Commit-status polling (B2 — inline recap card) ─────────────────────────
  var commitPollTimer   = null;
  var commitPollCount   = 0;
  var COMMIT_POLL_MAX   = 60;       // 2 min max
  var COMMIT_POLL_MS    = 2000;

  // ─── Bulk upload state ───────────────────────────────────────────────────────
  /**
   * Per-file entry:
   *   { fileName, uploadId, statusUrl, status: 'uploading'|'queued'|'ingest'|'done'|'failed'|'timeout',
   *     pollCount, pollTimer, error, redirectUrl }
   * @type {Array<Object>}
   */
  let bulkItems = [];

  // ─── Element refs ─────────────────────────────────────────────────────────
  const zone       = document.getElementById('upload-zone');
  const browseInput  = document.getElementById('upload-browse');
  const browseBtn  = document.getElementById('upload-browse-btn');
  const statusBox  = document.getElementById('upload-status');
  const fab        = document.getElementById('capture-fab');
  const fabInput   = document.getElementById('fab-camera-input');
  const panel      = document.getElementById('multishot-panel');
  const thumbStrip = document.getElementById('thumb-strip');
  const addPageBtn = document.getElementById('add-page-btn');
  const addPageInput = document.getElementById('add-page-input');
  const submitBtn  = document.getElementById('multishot-submit');
  const cancelBtn  = document.getElementById('multishot-cancel');
  const panelCount = document.getElementById('multishot-count');

  if (!zone) return; // Not on triage page

  // ─── CSRF token ─────────────────────────────────────────────────────────────
  function getCsrf() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  // ─── Drag-drop wiring ───────────────────────────────────────────────────────
  zone.addEventListener('dragover', (e) => {
    e.preventDefault();
    if (state !== 'idle') return;
    zone.classList.add('upload-zone--dragover');
  });

  zone.addEventListener('dragleave', (e) => {
    // Only fire if leaving the zone itself (not a child)
    if (!zone.contains(e.relatedTarget)) {
      zone.classList.remove('upload-zone--dragover');
    }
  });

  zone.addEventListener('drop', (e) => {
    e.preventDefault();
    zone.classList.remove('upload-zone--dragover');
    if (state !== 'idle') return;
    const files = Array.from(e.dataTransfer.files);
    if (files.length > 0) handleFilesSelected(files, 'desktop');
  });

  // Paste support (e.g. Ctrl+V a screenshot)
  document.addEventListener('paste', (e) => {
    if (state !== 'idle') return;
    // Only if focus is not in a text input
    const tag = document.activeElement ? document.activeElement.tagName : '';
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return;
    const items = e.clipboardData ? e.clipboardData.items : [];
    const imageItems = Array.from(items).filter(i => i.type.startsWith('image/'));
    if (imageItems.length === 0) return;
    const files = imageItems.map(i => i.getAsFile()).filter(Boolean);
    if (files.length > 0) handleFilesSelected(files, 'desktop');
  });

  // ─── Browse button ──────────────────────────────────────────────────────────
  if (browseBtn && browseInput) {
    browseBtn.addEventListener('click', (e) => {
      e.preventDefault();
      if (state !== 'idle') return;
      browseInput.click();
    });

    browseInput.addEventListener('change', () => {
      if (!browseInput.files || browseInput.files.length === 0) return;
      handleFilesSelected(Array.from(browseInput.files), 'desktop');
      browseInput.value = ''; // reset so same file can be re-selected
    });
  }

  // ─── FAB → open multi-shot panel ────────────────────────────────────────────
  if (fab) {
    fab.addEventListener('click', () => {
      if (state !== 'idle') return;
      openCapturePanel();
    });
  }

  // ─── Multi-shot panel ───────────────────────────────────────────────────────
  function openCapturePanel() {
    capturedFiles = [];
    renderThumbs();
    updateMultishotUI();
    panel.classList.add('multishot-panel--open');
    setState('capturing');
    // Trigger camera immediately on first open
    if (fabInput) fabInput.click();
  }

  function closeCapturePanel() {
    panel.classList.remove('multishot-panel--open');
    capturedFiles = [];
    renderThumbs();
    setState('idle');
  }

  if (cancelBtn) {
    cancelBtn.addEventListener('click', closeCapturePanel);
  }

  // "Add another page" button
  if (addPageBtn && addPageInput) {
    addPageBtn.addEventListener('click', () => {
      addPageInput.click();
    });

    addPageInput.addEventListener('change', () => {
      if (!addPageInput.files || addPageInput.files.length === 0) return;
      Array.from(addPageInput.files).forEach(f => capturedFiles.push(f));
      renderThumbs();
      updateMultishotUI();
      addPageInput.value = '';
    });
  }

  // Initial FAB camera input
  if (fabInput) {
    fabInput.addEventListener('change', () => {
      if (!fabInput.files || fabInput.files.length === 0) return;
      Array.from(fabInput.files).forEach(f => capturedFiles.push(f));
      renderThumbs();
      updateMultishotUI();
      fabInput.value = '';
    });
  }

  // Submit multi-shot — source='multishot' keeps stitch-as-one behavior
  if (submitBtn) {
    submitBtn.addEventListener('click', () => {
      if (capturedFiles.length === 0) return;
      panel.classList.remove('multishot-panel--open');
      const files = capturedFiles.slice();
      capturedFiles = [];
      renderThumbs();
      handleFilesSelected(files, 'multishot');
    });
  }

  function renderThumbs() {
    if (!thumbStrip) return;
    thumbStrip.innerHTML = '';
    capturedFiles.forEach((file, idx) => {
      const url = URL.createObjectURL(file);
      const wrap = document.createElement('div');
      wrap.className = 'thumb-item';
      wrap.setAttribute('data-idx', String(idx));

      const img = document.createElement('img');
      img.className = 'thumb-img';
      img.src = url;
      img.alt = `Page ${idx + 1}`;
      img.onload = () => URL.revokeObjectURL(url);

      const del = document.createElement('button');
      del.className = 'thumb-del';
      del.setAttribute('type', 'button');
      del.setAttribute('aria-label', `Supprimer page ${idx + 1}`);
      del.textContent = '×';
      del.addEventListener('click', () => {
        capturedFiles.splice(idx, 1);
        renderThumbs();
        updateMultishotUI();
      });

      const num = document.createElement('span');
      num.className = 'thumb-num';
      num.textContent = String(idx + 1);

      wrap.appendChild(img);
      wrap.appendChild(del);
      wrap.appendChild(num);
      thumbStrip.appendChild(wrap);
    });
  }

  function updateMultishotUI() {
    const n = capturedFiles.length;
    if (panelCount) panelCount.textContent = n > 0 ? `${n} page${n > 1 ? 's' : ''}` : '';
    if (submitBtn) {
      submitBtn.disabled = n === 0;
      submitBtn.textContent = n === 0 ? 'Envoyer' : `Envoyer (${n} page${n > 1 ? 's' : ''})`;
    }
    if (addPageBtn) {
      addPageBtn.style.display = n === 0 ? 'none' : '';
    }
  }

  // ─── State machine ──────────────────────────────────────────────────────────
  function setState(newState) {
    state = newState;
    // Cancel any in-flight commit poll when resetting
    if (newState === 'idle' && commitPollTimer !== null) {
      clearTimeout(commitPollTimer);
      commitPollTimer = null;
    }
    renderStatus();
  }

  // ─── B2: Recap card helpers ──────────────────────────────────────────────────

  /** Format a number in fr-CH locale (decimal comma, narrow NNBSP thousands). */
  function fmtNum(n, decimals) {
    if (n === null || n === undefined) return '—';
    return Number(n).toLocaleString('fr-CH', {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    });
  }

  /** Refresh CSRF from session-ping (mirrors invoice-validate.js pattern). */
  function refreshCsrfForRecap(callback) {
    fetch('/api/session-ping.php', {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.csrf) {
          // Update <meta name="csrf-token"> so getCsrf() stays fresh
          var meta = document.querySelector('meta[name="csrf-token"]');
          if (meta) meta.setAttribute('content', data.csrf);
        }
        callback();
      })
      .catch(function () { callback(); });
  }

  /** Build the per-line recap table HTML from recap.lines array. */
  function buildRecapLinesHtml(lines) {
    if (!lines || lines.length === 0) {
      return '<p class="upload-recap__empty-lines">Aucune ligne parsée.</p>';
    }
    var rows = lines.map(function (l) {
      var miCell = '';
      if (l.mi_label) {
        miCell = '<code class="upload-recap__mi-code">' + escHtml(l.mi_label) + '</code>';
      } else if (l.accounting_class) {
        miCell = '<span class="upload-recap__acct">' + escHtml(l.accounting_class) + '</span>';
      } else if (l.mi_unresolved) {
        miCell = '<span class="upload-recap__unresolved" title="MI non résolu">⚠ non résolu</span>';
      }

      var flagsHtml = '';
      if (l.low_confidence) {
        flagsHtml += '<span class="upload-recap__flag upload-recap__flag--warn" title="Confiance faible">C</span>';
      }
      if (l.gate_failed) {
        flagsHtml += '<span class="upload-recap__flag upload-recap__flag--err" title="Gate failure">G</span>';
      }
      if (l.mi_unresolved) {
        flagsHtml += '<span class="upload-recap__flag upload-recap__flag--err" title="MI non résolu">?</span>';
      }

      var rowClass = (l.mi_unresolved || l.low_confidence || l.gate_failed)
        ? 'upload-recap__row upload-recap__row--warn'
        : 'upload-recap__row';

      var qty = (l.qty !== null && l.qty !== undefined)
        ? fmtNum(l.qty, 4).replace(/0+$/, '').replace(/[,.]$/, '')
          + (l.unit ? ' ' + escHtml(l.unit) : '')
        : '—';

      return '<tr class="' + rowClass + '">'
        + '<td class="upload-recap__col-idx">' + (l.line_index + 1) + '</td>'
        + '<td class="upload-recap__col-mi">' + miCell + '</td>'
        + '<td class="upload-recap__col-desc">' + escHtml(l.description || '') + '</td>'
        + '<td class="upload-recap__col-num">' + qty + '</td>'
        + '<td class="upload-recap__col-num">' + fmtNum(l.unit_price, 4) + '</td>'
        + '<td class="upload-recap__col-num">' + fmtNum(l.line_total, 2) + '</td>'
        + '<td class="upload-recap__col-flags">' + flagsHtml + '</td>'
        + '</tr>';
    }).join('');

    return '<div class="upload-recap__table-wrap">'
      + '<table class="upload-recap__table" aria-label="Lignes de la facture">'
      + '<thead><tr>'
      + '<th scope="col">#</th>'
      + '<th scope="col">MI</th>'
      + '<th scope="col">Description</th>'
      + '<th scope="col" class="upload-recap__col-num">Qté</th>'
      + '<th scope="col" class="upload-recap__col-num">PU</th>'
      + '<th scope="col" class="upload-recap__col-num">Total HT</th>'
      + '<th scope="col">Flags</th>'
      + '</tr></thead>'
      + '<tbody>' + rows + '</tbody>'
      + '</table></div>';
  }

  /**
   * Render the full recap card (commit_status='pending') inside #upload-status.
   * Includes Valider / Refuser buttons and watches commit_status until settled.
   */
  function renderRecapCard(d) {
    var recap  = d.recap;
    var uploadId = d.upload_id;

    statusBox.className = 'upload-status upload-status--recap';
    statusBox.hidden    = false;

    var supplierHtml = recap.supplier_name
      ? '<span class="upload-recap__supplier">' + escHtml(recap.supplier_name) + '</span>'
      : '<span class="upload-recap__unknown">?</span>';

    var refHtml = recap.invoice_ref
      ? '<span class="upload-recap__ref">' + escHtml(recap.invoice_ref) + '</span>'
      : '<span class="upload-recap__unknown">—</span>';

    var dateHtml = recap.invoice_date
      ? '<span class="upload-recap__date">' + escHtml(recap.invoice_date) + '</span>'
      : '';

    var totalHtml = (recap.total_ht !== null && recap.total_ht !== undefined)
      ? '<span class="upload-recap__amount">'
          + fmtNum(recap.total_ht, 2) + ' '
          + escHtml(recap.currency || 'CHF') + ' HT'
        + '</span>'
      : '';

    var hasIssues = (recap.lines || []).some(function (l) {
      return l.mi_unresolved || l.low_confidence || l.gate_failed;
    });
    var issueChip = hasIssues
      ? '<span class="upload-recap__chip upload-recap__chip--warn">⚠ lignes à vérifier</span>'
      : '<span class="upload-recap__chip upload-recap__chip--ok">Prêt à valider</span>';

    var linesHtml = buildRecapLinesHtml(recap.lines || []);

    statusBox.innerHTML =
      '<div class="upload-recap__header">'
        + '<div class="upload-recap__meta">'
          + supplierHtml + refHtml + dateHtml
        + '</div>'
        + '<div class="upload-recap__header-right">'
          + totalHtml + issueChip
        + '</div>'
      + '</div>'
      + linesHtml
      + '<div class="upload-recap__actions" id="upload-recap-actions">'
        + '<button class="upload-recap__btn upload-recap__btn--validate" id="upload-recap-validate" type="button">'
          + '✓ Valider'
        + '</button>'
        + '<button class="upload-recap__btn upload-recap__btn--reject" id="upload-recap-reject" type="button">'
          + '✗ Refuser'
        + '</button>'
        + '<a class="upload-recap__link" href="/modules/invoice-validate.php">'
          + 'Voir tout dans À valider →'
        + '</a>'
      + '</div>'
      + '<div class="upload-recap__commit-status" id="upload-recap-commit" hidden></div>';

    // Wire Valider
    var validateBtn = document.getElementById('upload-recap-validate');
    if (validateBtn) {
      validateBtn.addEventListener('click', function () {
        doRecapValidate(uploadId, validateBtn, false);
      });
    }

    // Wire Refuser (inline reject — no dialog in upload context; confirm inline)
    var rejectBtn = document.getElementById('upload-recap-reject');
    if (rejectBtn) {
      rejectBtn.addEventListener('click', function () {
        doRecapReject(uploadId, rejectBtn, false);
      });
    }
  }

  /** Disable/enable the two recap action buttons. */
  function setRecapBtnsDisabled(disabled) {
    var v = document.getElementById('upload-recap-validate');
    var r = document.getElementById('upload-recap-reject');
    if (v) v.disabled = disabled;
    if (r) r.disabled = disabled;
  }

  /** Update the commit-status inline indicator. */
  function setRecapCommitMsg(msg, cssClass) {
    var el = document.getElementById('upload-recap-commit');
    if (!el) return;
    el.hidden = false;
    el.className = 'upload-recap__commit-status upload-recap__commit-status--' + cssClass;
    el.textContent = msg;
  }

  /** Poll commit_status after Valider fired. */
  function pollRecapCommit(uploadId, attempt) {
    attempt = attempt || 0;
    if (attempt >= COMMIT_POLL_MAX) {
      setRecapBtnsDisabled(false);
      setRecapCommitMsg('Délai dépassé — vérifier dans À valider.', 'warn');
      return;
    }
    commitPollTimer = setTimeout(function () {
      fetch(STATUS_ENDPOINT + '?upload_id=' + encodeURIComponent(uploadId), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store',
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          var cs = data.commit_status;
          if (cs === 'done') {
            // Turn the card green
            statusBox.className = 'upload-status upload-status--recap upload-status--recap-done';
            var actionsEl = document.getElementById('upload-recap-actions');
            if (actionsEl) {
              actionsEl.innerHTML =
                '<span class="upload-recap__done-msg">✓ Écrit en base</span>'
                + '<button class="upload-recap__btn upload-recap__btn--new" id="upload-recap-new" type="button">'
                  + 'Nouveau document'
                + '</button>';
              var newBtn = document.getElementById('upload-recap-new');
              if (newBtn) newBtn.addEventListener('click', function () { setState('idle'); });
            }
            var commitEl = document.getElementById('upload-recap-commit');
            if (commitEl) commitEl.hidden = true;
          } else if (cs === 'failed') {
            setRecapBtnsDisabled(false);
            setRecapCommitMsg('Échec : ' + escHtml(data.commit_error || 'erreur inconnue'), 'err');
          } else {
            pollRecapCommit(uploadId, attempt + 1);
          }
        })
        .catch(function () {
          pollRecapCommit(uploadId, attempt + 1);
        });
    }, COMMIT_POLL_MS);
  }

  /** POST /api/invoice-validate.php for the inline recap card. */
  function doRecapValidate(uploadId, btn, isRetry) {
    setRecapBtnsDisabled(true);
    setRecapCommitMsg('Validation en cours…', 'busy');

    var fd = new FormData();
    fd.append('upload_id', String(uploadId));
    fd.append('csrf', getCsrf());

    fetch('/api/invoice-validate.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      body: fd,
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          setRecapCommitMsg('Commit en cours…', 'busy');
          commitPollCount = 0;
          pollRecapCommit(uploadId, 0);
        } else if (data.error === 'csrf_invalid' && !isRetry) {
          refreshCsrfForRecap(function () { doRecapValidate(uploadId, btn, true); });
        } else if (data.error === 'not_validatable') {
          // Already committed (race) — treat as done
          statusBox.className = 'upload-status upload-status--recap upload-status--recap-done';
          var actionsEl = document.getElementById('upload-recap-actions');
          if (actionsEl) {
            actionsEl.innerHTML =
              '<span class="upload-recap__done-msg">✓ Déjà validée</span>';
          }
        } else {
          setRecapBtnsDisabled(false);
          setRecapCommitMsg('Erreur : ' + escHtml(data.error || 'inconnue'), 'err');
        }
      })
      .catch(function (err) {
        setRecapBtnsDisabled(false);
        setRecapCommitMsg('Erreur réseau : ' + escHtml(err.message || 'inconnue'), 'err');
      });
  }

  /** POST /api/invoice-reject.php for the inline recap card. */
  function doRecapReject(uploadId, btn, isRetry) {
    // Inline confirm (no dialog) — button text changes to a 2-click confirm pattern
    if (!btn.dataset.confirming) {
      btn.dataset.confirming = '1';
      btn.textContent = '✗ Confirmer le refus';
      btn.style.outline = '2px solid var(--ember)';
      // Auto-cancel after 5 s
      setTimeout(function () {
        if (btn.dataset.confirming) {
          delete btn.dataset.confirming;
          btn.textContent = '✗ Refuser';
          btn.style.outline = '';
        }
      }, 5000);
      return;
    }
    delete btn.dataset.confirming;

    setRecapBtnsDisabled(true);
    setRecapCommitMsg('Refus en cours…', 'busy');

    var fd = new FormData();
    fd.append('upload_id', String(uploadId));
    fd.append('csrf', getCsrf());

    fetch('/api/invoice-reject.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      body: fd,
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          statusBox.className = 'upload-status upload-status--recap upload-status--recap-rejected';
          var actionsEl = document.getElementById('upload-recap-actions');
          if (actionsEl) {
            actionsEl.innerHTML =
              '<span class="upload-recap__rejected-msg">✗ Facture refusée</span>'
              + '<button class="upload-recap__btn upload-recap__btn--new" id="upload-recap-new2" type="button">'
                + 'Nouveau document'
              + '</button>';
            var newBtn = document.getElementById('upload-recap-new2');
            if (newBtn) newBtn.addEventListener('click', function () { setState('idle'); });
          }
          var commitEl = document.getElementById('upload-recap-commit');
          if (commitEl) commitEl.hidden = true;
        } else if (data.error === 'csrf_invalid' && !isRetry) {
          setRecapBtnsDisabled(false);
          refreshCsrfForRecap(function () { doRecapReject(uploadId, btn, true); });
        } else {
          setRecapBtnsDisabled(false);
          setRecapCommitMsg('Erreur : ' + escHtml(data.error || 'inconnue'), 'err');
        }
      })
      .catch(function (err) {
        setRecapBtnsDisabled(false);
        setRecapCommitMsg('Erreur réseau : ' + escHtml(err.message || 'inconnue'), 'err');
      });
  }

  // ─── File → upload ──────────────────────────────────────────────────────────
  /**
   * Called with 1+ File objects.
   * @param {File[]} files
   * @param {'desktop'|'multishot'} source
   */
  function handleFilesSelected(files, source) {
    if (files.length === 0) return;

    if (source === 'multishot') {
      // Mobile multi-shot: always stitch-as-one (existing behavior)
      handleSingleOrMultipage(files, 'multipage');
    } else {
      // Desktop (drag-drop / browse / paste):
      // - 1 file  → mode=single  → existing single path
      // - N files → mode=bulk    → N separate ingest jobs
      if (files.length === 1) {
        handleSingleOrMultipage(files, 'single');
      } else {
        handleBulk(files);
      }
    }
  }

  // ─── Single / multipage upload (original path) ───────────────────────────────
  function handleSingleOrMultipage(files, mode) {
    setState('uploading');
    startedAt = Date.now();
    stopElapsedTimer();

    const fd = new FormData();
    fd.append('csrf', getCsrf());
    fd.append('source', 'maltyweb-web');
    fd.append('mode', mode);

    if (mode === 'single') {
      // Single-file path — field name must be 'file' for backward compat
      fd.append('file', files[0], files[0].name);
    } else {
      // multipage — field name 'files[]', server stitches via img2pdf
      files.forEach((f, i) => {
        fd.append('files[]', f, f.name || `page-${i + 1}.jpg`);
      });
    }

    uploadCtrl = new AbortController();

    fetch(UPLOAD_ENDPOINT, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: fd,
      signal: uploadCtrl.signal,
    })
      .then(res => res.json().then(data => ({ ok: res.ok, status: res.status, data })))
      .then(({ ok, status, data }) => {
        if (!ok || !data.upload_id) {
          const msg = data.error || `Erreur HTTP ${status}`;
          showError(msg);
          return;
        }
        lastUploadId = data.upload_id;
        pollCount = 0;
        startedAt = Date.now();
        startElapsedTimer();
        setState('polling');
        schedulePoll(data.upload_id);
      })
      .catch(err => {
        if (err && err.name === 'AbortError') return; // navigated away
        showError('Connexion interrompue. Réessayer.');
      });
  }

  // ─── Bulk upload: N files = N separate ingest jobs ──────────────────────────
  function handleBulk(files) {
    setState('uploading');
    startedAt = Date.now();

    const fd = new FormData();
    fd.append('csrf', getCsrf());
    fd.append('source', 'maltyweb-web');
    fd.append('mode', 'bulk');
    files.forEach((f, i) => {
      fd.append('files[]', f, f.name || `document-${i + 1}.pdf`);
    });

    uploadCtrl = new AbortController();

    fetch(UPLOAD_ENDPOINT, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: fd,
      signal: uploadCtrl.signal,
    })
      .then(res => res.json().then(data => ({ ok: res.ok, status: res.status, data })))
      .then(({ ok, status, data }) => {
        if (!ok) {
          const msg = data.error || `Erreur HTTP ${status}`;
          showError(msg);
          return;
        }
        if (!data.uploads || !Array.isArray(data.uploads)) {
          showError('Réponse serveur inattendue.');
          return;
        }

        // Build per-file tracking state
        bulkItems = data.uploads.map(u => ({
          fileName:        u.file_name || '(inconnu)',
          uploadId:        u.upload_id || null,
          statusUrl:       u.status_url || null,
          status:          u.status === 'queued' ? 'ingest' : 'failed',
          pollCount:       0,
          pollTimer:       null,
          error:           u.error || null,
          redirectUrl:     null,
          awaitingValidate: false,
        }));

        setState('bulk-progress');

        // Start polling for each queued item
        bulkItems.forEach((item, idx) => {
          if (item.status === 'ingest' && item.statusUrl) {
            scheduleBulkPoll(idx);
          }
        });
      })
      .catch(err => {
        if (err && err.name === 'AbortError') return;
        showError('Connexion interrompue. Réessayer.');
      });
  }

  // ─── Bulk polling ───────────────────────────────────────────────────────────
  function scheduleBulkPoll(idx) {
    const item = bulkItems[idx];
    if (!item) return;
    const interval = item.pollCount < 10 ? POLL_INTERVAL_FAST : POLL_INTERVAL_SLOW;
    item.pollTimer = setTimeout(() => doBulkPoll(idx), interval);
  }

  function doBulkPoll(idx) {
    const item = bulkItems[idx];
    if (!item || state !== 'bulk-progress') return;
    if (item.status !== 'ingest') return;

    item.pollCount++;
    if (item.pollCount > POLL_MAX) {
      item.status = 'timeout';
      renderStatus();
      checkBulkAllDone();
      return;
    }

    const url = item.statusUrl;
    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(res => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
      })
      .then(data => {
        if (state !== 'bulk-progress') return;
        if (item.status !== 'ingest') return;

        const ps = data.pipeline_status;

        if (ps === 'processed') {
          item.status = 'done';
          item.redirectUrl = data.redirect_url || null;
          // Mark items that are awaiting operator validation so the CTA can count them
          item.awaitingValidate = (data.commit_status === 'pending' && !!data.recap);
          renderStatus();
          checkBulkAllDone();
          return;
        }

        if (ps === 'failed') {
          item.status = 'failed';
          item.error  = data.error_text || 'Le traitement a échoué.';
          renderStatus();
          checkBulkAllDone();
          return;
        }

        if (ps === 'timeout') {
          item.status = 'timeout';
          renderStatus();
          checkBulkAllDone();
          return;
        }

        // Still in-progress — re-render elapsed and keep polling
        renderStatus();
        scheduleBulkPoll(idx);
      })
      .catch(() => {
        if (state !== 'bulk-progress') return;
        if (item.status !== 'ingest') return;
        // Network blip — keep polling
        scheduleBulkPoll(idx);
      });
  }

  function checkBulkAllDone() {
    const allSettled = bulkItems.every(
      item => item.status === 'done' || item.status === 'failed' || item.status === 'timeout'
    );
    if (allSettled) {
      // Leave in bulk-progress state so list stays visible; retry button resets to idle.
      renderStatus();
    }
  }

  // ─── Polling (single/multipage path) ────────────────────────────────────────
  function schedulePoll(uploadId) {
    const interval = pollCount < 10 ? POLL_INTERVAL_FAST : POLL_INTERVAL_SLOW;
    pollTimer = setTimeout(() => doPoll(uploadId), interval);
  }

  function doPoll(uploadId) {
    if (state !== 'polling') return;

    pollCount++;
    if (pollCount > POLL_MAX) {
      stopElapsedTimer();
      setState('timeout');
      return;
    }

    const url = `${STATUS_ENDPOINT}?upload_id=${encodeURIComponent(uploadId)}`;
    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(res => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
      })
      .then(data => {
        if (state !== 'polling') return;

        const ps = data.pipeline_status;

        if (ps === 'processed') {
          stopElapsedTimer();
          lastPollData = data;
          setState('done');
          // Only auto-redirect when there is NO summary to show (ambiguous/DN docs
          // that have no invoice lines; redirect_url may still be set).
          if (data.redirect_url && !data.summary) {
            // Brief pause so operator sees the "Done" state before redirect
            setTimeout(() => {
              window.location.href = data.redirect_url;
            }, 1200);
          }
          return;
        }

        if (ps === 'failed') {
          stopElapsedTimer();
          lastPollData = data;
          showError(data.error_text || 'Le traitement a échoué. Vérifier le journal.');
          return;
        }

        if (ps === 'timeout') {
          stopElapsedTimer();
          setState('timeout');
          return;
        }

        // Still in-progress — keep polling
        schedulePoll(uploadId);
      })
      .catch(() => {
        if (state !== 'polling') return;
        // Network blip — keep trying
        schedulePoll(uploadId);
      });
  }

  // ─── Elapsed timer ──────────────────────────────────────────────────────────
  function startElapsedTimer() {
    stopElapsedTimer();
    elapsedTimer = setInterval(renderStatus, 1000);
  }

  function stopElapsedTimer() {
    if (elapsedTimer !== null) {
      clearInterval(elapsedTimer);
      elapsedTimer = null;
    }
  }

  // ─── Cleanup on navigation ───────────────────────────────────────────────────
  window.addEventListener('pagehide', () => {
    stopElapsedTimer();
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    if (uploadCtrl) { uploadCtrl.abort(); uploadCtrl = null; }
    if (commitPollTimer) { clearTimeout(commitPollTimer); commitPollTimer = null; }
    // Cancel any in-flight bulk poll timers
    bulkItems.forEach(item => {
      if (item.pollTimer) { clearTimeout(item.pollTimer); item.pollTimer = null; }
    });
  });

  // ─── Retry helper (POST to upload-retry.php then resume polling) ────────────
  /**
   * POST /api/upload-retry.php for the current lastUploadId.
   * On success: resume polling from the existing lastUploadId (zero new state).
   * On known-failure responses: show a targeted message and switch to idle.
   * @param {HTMLButtonElement} btn  The retry button (disabled while in flight).
   */
  function retryUpload(btn) {
    btn.disabled = true;
    const fd = new FormData();
    fd.append('csrf', getCsrf());
    fd.append('id', String(lastUploadId));
    fetch('/api/upload-retry.php', {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: fd,
    })
      .then(res => res.json().then(data => ({ httpOk: res.ok, data })))
      .then(({ httpOk, data }) => {
        if (data.ok) {
          // Worker re-triggered — resume polling loop (no new upload_id needed)
          pollCount = 0;
          startedAt = Date.now();
          startElapsedTimer();
          setState('polling');
          schedulePoll(lastUploadId);
          return;
        }
        // Known-failure cases: leave upload_id intact but show targeted message
        if (data.error === 'file_gone') {
          renderRetryGone('Le fichier n\'est plus disponible sur le serveur.');
        } else if (data.error === 'not_retryable') {
          renderRetryGone('Ce document est déjà en cours de traitement.');
        } else {
          renderRetryGone(data.error || 'Réessai impossible.');
        }
      })
      .catch(() => {
        btn.disabled = false;
        // Network blip — re-enable so operator can try again
      });
  }

  /**
   * Show a one-line warning and a single "Nouveau document" button.
   * Used when retry is not possible (file_gone / not_retryable).
   */
  function renderRetryGone(msg) {
    if (!statusBox) return;
    statusBox.className = 'upload-status upload-status--warn';
    statusBox.hidden = false;
    const safMsg = escHtml(msg);
    statusBox.innerHTML =
      '<span class="upload-status__warn-icon" aria-hidden="true">⚠</span>'
      + `<span class="upload-status__text">${safMsg}</span>`
      + '<button class="upload-status__retry upload-status__retry--sec" type="button">Nouveau document</button>';
    const btn = statusBox.querySelector('.upload-status__retry');
    if (btn) {
      btn.addEventListener('click', () => setState('idle'));
    }
  }

  // ─── Error helpers ──────────────────────────────────────────────────────────
  function showError(msg) {
    stopElapsedTimer();
    state = 'error';
    renderStatus(msg);
  }

  // ─── Bulk progress list helpers ─────────────────────────────────────────────
  function bulkStatusIcon(item) {
    switch (item.status) {
      case 'uploading': return '<span class="upload-spin" aria-hidden="true"></span>';
      case 'ingest':    return '<span class="upload-spin" aria-hidden="true"></span>';
      case 'done':      return '<span class="bulk-item__icon bulk-item__icon--done" aria-hidden="true">✓</span>';
      case 'failed':    return '<span class="bulk-item__icon bulk-item__icon--err" aria-hidden="true">✕</span>';
      case 'timeout':   return '<span class="bulk-item__icon bulk-item__icon--warn" aria-hidden="true">⚠</span>';
      default:          return '';
    }
  }

  function bulkStatusLabel(item) {
    switch (item.status) {
      case 'uploading': return 'envoi…';
      case 'ingest':    return 'traitement…';
      case 'done':
        if (item.awaitingValidate) {
          return '<span class="bulk-item__awaiting">à valider</span>';
        }
        if (item.redirectUrl) {
          const safe = item.redirectUrl.replace(/"/g, '&quot;');
          return `<a class="bulk-item__link" href="${safe}">voir dans triage →</a>`;
        }
        return 'reçu';
      case 'failed':    return escHtml(item.error || 'échec');
      case 'timeout':   return 'délai dépassé';
      default:          return item.status;
    }
  }

  function escHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // ─── Render status box ───────────────────────────────────────────────────────
  /** Renders the current state into #upload-status. msg only used for 'error'. */
  function renderStatus(errorMsg) {
    if (!statusBox) return;

    // Reset classes
    statusBox.className = 'upload-status';

    switch (state) {
      case 'idle': {
        statusBox.hidden = true;
        return;
      }
      case 'uploading': {
        statusBox.hidden = false;
        statusBox.className += ' upload-status--busy';
        statusBox.innerHTML =
          '<span class="upload-spin" aria-hidden="true"></span>'
          + '<span class="upload-status__text">Envoi en cours…</span>';
        break;
      }
      case 'polling': {
        const elapsed = Math.round((Date.now() - startedAt) / 1000);
        statusBox.hidden = false;
        statusBox.className += ' upload-status--busy';
        statusBox.innerHTML =
          '<span class="upload-spin" aria-hidden="true"></span>'
          + `<span class="upload-status__text">Traitement… <span class="upload-status__elapsed">(${elapsed}s)</span></span>`;
        break;
      }
      case 'done': {
        statusBox.hidden = false;
        const d = lastPollData || {};
        const s = d.summary || null;

        // B2: recap card — invoice awaiting validation (commit_status='pending' + recap present)
        if (d.recap && d.commit_status === 'pending') {
          renderRecapCard(d);
          break;
        }

        if (s) {
          // Rich summary card (invoice already committed or no recap available)
          statusBox.className += ' upload-status--done upload-status--summary';

          const fmtHT = typeof s.total_ht === 'number'
            ? s.total_ht.toLocaleString('fr-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            : '—';

          const supplierHtml = s.supplier_name
            ? escHtml(s.supplier_name)
            : '<span class="upload-sum__unknown">?</span>';

          const refHtml = s.invoice_ref
            ? escHtml(s.invoice_ref)
            : '<span class="upload-sum__unknown">—</span>';

          // "Trier" button shown when there are pending lines
          let triageHtml = '';
          if (s.lines_pending > 0 && d.redirect_url) {
            const safeUrl = d.redirect_url.replace(/"/g, '&quot;');
            triageHtml = `<a class="upload-sum__triage-btn" href="${safeUrl}">`
              + `Trier maintenant (${s.lines_pending} ligne${s.lines_pending !== 1 ? 's' : ''}) →</a>`;
          } else if (d.redirect_url) {
            const safeUrl = d.redirect_url.replace(/"/g, '&quot;');
            triageHtml = `<a class="upload-sum__triage-btn upload-sum__triage-btn--sec" href="${safeUrl}">Voir dans triage →</a>`;
          }

          statusBox.innerHTML =
            `<div class="upload-sum__header">`
            + `<span class="upload-status__check" aria-hidden="true">✓</span>`
            + `<span class="upload-sum__title">Ingéré avec succès</span>`
            + `<button class="upload-status__retry upload-sum__close" type="button" aria-label="Fermer">✕</button>`
            + `</div>`
            + `<div class="upload-sum__body">`
            + `<div class="upload-sum__row"><span class="upload-sum__label">Fournisseur</span><span class="upload-sum__val">${supplierHtml}</span></div>`
            + `<div class="upload-sum__row"><span class="upload-sum__label">Réf. facture</span><span class="upload-sum__val">${refHtml}</span></div>`
            + `<div class="upload-sum__row"><span class="upload-sum__label">Total HT</span><span class="upload-sum__val">${fmtHT} ${escHtml(s.currency || 'CHF')}</span></div>`
            + `<div class="upload-sum__row upload-sum__row--counts">`
            + `<span class="upload-sum__label">Lignes</span>`
            + `<span class="upload-sum__val">`
            + `<span class="upload-sum__badge upload-sum__badge--total">${s.lines_total} parsées</span>`
            + (s.lines_active   > 0 ? `<span class="upload-sum__badge upload-sum__badge--ok">  ${s.lines_active} active${s.lines_active   !== 1 ? 's' : ''}</span>`   : '')
            + (s.lines_pending  > 0 ? `<span class="upload-sum__badge upload-sum__badge--warn">${s.lines_pending} en attente</span>`  : '')
            + (s.lines_excluded > 0 ? `<span class="upload-sum__badge upload-sum__badge--mute">${s.lines_excluded} exclue${s.lines_excluded !== 1 ? 's' : ''}</span>` : '')
            + `</span>`
            + `</div>`
            + `</div>`
            + (triageHtml ? `<div class="upload-sum__footer">${triageHtml}</div>` : '');

          const closeBtn = statusBox.querySelector('.upload-sum__close');
          if (closeBtn) {
            closeBtn.addEventListener('click', () => setState('idle'));
          }
        } else {
          // No invoice summary (DN / ambiguous) — simple confirmation
          statusBox.className += ' upload-status--done';
          const hasRedirect = !!d.redirect_url;
          statusBox.innerHTML =
            '<span class="upload-status__check" aria-hidden="true">✓</span>'
            + '<span class="upload-status__text">Document reçu'
            + (hasRedirect ? ' — redirection en cours…' : ' — traitement terminé.')
            + '</span>';
        }
        break;
      }
      case 'timeout': {
        statusBox.hidden = false;
        statusBox.className += ' upload-status--warn';
        const uploadIdNote = lastUploadId ? ` (ID upload : ${lastUploadId})` : '';
        if (lastUploadId !== null) {
          statusBox.innerHTML =
            '<span class="upload-status__warn-icon" aria-hidden="true">⚠</span>'
            + `<span class="upload-status__text">Timeout après 3 minutes. Le worker pipeline est peut-être bloqué.${uploadIdNote}</span>`
            + '<button class="upload-status__retry" type="button">↩ Réessayer</button>'
            + '<button class="upload-status__retry upload-status__retry--sec" type="button">Nouveau document</button>';
          const retryBtn = statusBox.querySelector('.upload-status__retry:not(.upload-status__retry--sec)');
          if (retryBtn) {
            retryBtn.addEventListener('click', () => retryUpload(retryBtn));
          }
          const newBtn = statusBox.querySelector('.upload-status__retry--sec');
          if (newBtn) {
            newBtn.addEventListener('click', () => setState('idle'));
          }
        } else {
          // No upload_id available (pre-upload disconnect) — only new-document option
          statusBox.innerHTML =
            '<span class="upload-status__warn-icon" aria-hidden="true">⚠</span>'
            + '<span class="upload-status__text">Timeout — connexion interrompue.</span>'
            + '<button class="upload-status__retry" type="button">Nouveau document</button>';
          const newBtn = statusBox.querySelector('.upload-status__retry');
          if (newBtn) {
            newBtn.addEventListener('click', () => setState('idle'));
          }
        }
        break;
      }
      case 'error': {
        statusBox.hidden = false;
        statusBox.className += ' upload-status--err';
        const safMsg = (errorMsg || 'Erreur inconnue').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        if (lastUploadId !== null) {
          // Upload reached the server — worker may have failed; offer retry
          statusBox.innerHTML =
            '<span class="upload-status__warn-icon" aria-hidden="true">✕</span>'
            + `<span class="upload-status__text">${safMsg}</span>`
            + '<button class="upload-status__retry" type="button">↩ Réessayer</button>'
            + '<button class="upload-status__retry upload-status__retry--sec" type="button">Nouveau document</button>';
          const retryBtn = statusBox.querySelector('.upload-status__retry:not(.upload-status__retry--sec)');
          if (retryBtn) {
            retryBtn.addEventListener('click', () => retryUpload(retryBtn));
          }
          const newBtn = statusBox.querySelector('.upload-status__retry--sec');
          if (newBtn) {
            newBtn.addEventListener('click', () => setState('idle'));
          }
        } else {
          // Upload never reached the server (e.g. "Connexion interrompue") — only new upload
          statusBox.innerHTML =
            '<span class="upload-status__warn-icon" aria-hidden="true">✕</span>'
            + `<span class="upload-status__text">${safMsg}</span>`
            + '<button class="upload-status__retry" type="button">Nouveau document</button>';
          const newBtn = statusBox.querySelector('.upload-status__retry');
          if (newBtn) {
            newBtn.addEventListener('click', () => setState('idle'));
          }
        }
        break;
      }
      case 'bulk-progress': {
        statusBox.hidden = false;
        statusBox.className += ' upload-status--bulk';

        const allSettled = bulkItems.every(
          item => item.status === 'done' || item.status === 'failed' || item.status === 'timeout'
        );
        const doneCount    = bulkItems.filter(i => i.status === 'done').length;
        const failedCount  = bulkItems.filter(i => i.status === 'failed' || i.status === 'timeout').length;

        let headerClass = allSettled
          ? (failedCount === 0 ? 'bulk-header--done' : (doneCount === 0 ? 'bulk-header--err' : 'bulk-header--warn'))
          : 'bulk-header--busy';

        let headerText = allSettled
          ? `${doneCount} reçu${doneCount !== 1 ? 's' : ''}`
            + (failedCount > 0 ? `, ${failedCount} échec${failedCount !== 1 ? 's' : ''}` : '')
          : `${doneCount}/${bulkItems.length} traités…`;

        let rows = bulkItems.map(item => {
          const iconHtml  = bulkStatusIcon(item);
          const labelHtml = bulkStatusLabel(item);
          const rowClass  = `bulk-item bulk-item--${item.status}`;
          const safeName  = escHtml(item.fileName);
          return `<li class="${rowClass}">
            <span class="bulk-item__indicator">${iconHtml}</span>
            <span class="bulk-item__name" title="${safeName}">${safeName}</span>
            <span class="bulk-item__label">${labelHtml}</span>
          </li>`;
        }).join('');

        let retryHtml = allSettled
          ? '<button class="upload-status__retry" type="button">↩ Nouveau</button>'
          : '';

        // Count docs that are 'done' and awaiting validation (have recap/commit_status='pending')
        // We track this via bulkItems.awaitingValidate flag set during bulk polling.
        var awaitingValidateCount = bulkItems.filter(function (i) {
          return i.status === 'done' && i.awaitingValidate;
        }).length;

        let triageBtn = '';
        if (allSettled && doneCount > 0) {
          if (awaitingValidateCount > 0) {
            triageBtn =
              '<a class="bulk-triage-btn bulk-triage-btn--validate" href="/modules/invoice-validate.php">'
              + '→ Valider les ' + awaitingValidateCount + ' document'
              + (awaitingValidateCount !== 1 ? 's' : '') + '</a>';
          } else {
            triageBtn = '<a class="bulk-triage-btn" href="/modules/triage.php?tab=docs">Tout voir dans triage →</a>';
          }
        }

        statusBox.innerHTML =
          `<div class="bulk-header ${headerClass}">
            <span class="bulk-header__text">${headerText}</span>
            ${retryHtml}
          </div>
          <ul class="bulk-list">${rows}</ul>
          ${triageBtn}`;

        // Wire retry button
        const retryBtn = statusBox.querySelector('.upload-status__retry');
        if (retryBtn) {
          retryBtn.addEventListener('click', () => {
            bulkItems = [];
            setState('idle');
          });
        }
        break;
      }
    }
  }

  // Init
  setState('idle');

})();
