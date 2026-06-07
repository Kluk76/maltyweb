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
    renderStatus();
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
          fileName:   u.file_name || '(inconnu)',
          uploadId:   u.upload_id || null,
          statusUrl:  u.status_url || null,
          status:     u.status === 'queued' ? 'ingest' : 'failed',
          pollCount:  0,
          pollTimer:  null,
          error:      u.error || null,
          redirectUrl: null,
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

        if (s) {
          // Rich summary card
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

        let triageBtn = (allSettled && doneCount > 0)
          ? '<a class="bulk-triage-btn" href="/modules/triage.php?tab=docs">Tout voir dans triage →</a>'
          : '';

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
