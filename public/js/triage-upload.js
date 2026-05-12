/**
 * triage-upload.js — Upload UI for MaltyTask triage page
 *
 * Handles:
 *  - Desktop drag-drop + browse single/multi file
 *  - Mobile multi-shot camera capture panel
 *  - FormData POST to /api/upload-document.php
 *  - Polling /api/upload-status.php with backoff
 *  - State machine: idle → capturing → uploading → polling → done/timeout/error
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

  // ─── State ──────────────────────────────────────────────────────────────────
  /** @type {'idle'|'capturing'|'uploading'|'polling'|'done'|'timeout'|'error'} */
  let state       = 'idle';
  let pollCount   = 0;
  let pollTimer   = null;
  let uploadCtrl  = null; // AbortController for fetch
  let capturedFiles = []; // File[] collected in multi-shot mode
  let startedAt   = 0;    // Date.now() when upload POST fired
  let elapsedTimer = null;

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
    if (files.length > 0) handleFilesSelected(files);
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
    if (files.length > 0) handleFilesSelected(files);
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
      handleFilesSelected(Array.from(browseInput.files));
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

  // Submit multi-shot
  if (submitBtn) {
    submitBtn.addEventListener('click', () => {
      if (capturedFiles.length === 0) return;
      panel.classList.remove('multishot-panel--open');
      const files = capturedFiles.slice();
      capturedFiles = [];
      renderThumbs();
      handleFilesSelected(files);
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
  /** Called with 1+ File objects. Builds FormData, POSTs, starts polling. */
  function handleFilesSelected(files) {
    if (files.length === 0) return;
    setState('uploading');
    startedAt = Date.now();
    stopElapsedTimer();

    const fd = new FormData();
    fd.append('csrf', getCsrf());
    fd.append('source', 'maltyweb-web');

    if (files.length === 1) {
      // Single-file path — field name must be 'file' for backward compat
      fd.append('file', files[0], files[0].name);
    } else {
      // Multi-file path — field name 'files[]'
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

  // ─── Polling ────────────────────────────────────────────────────────────────
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
          setState('done');
          if (data.redirect_url) {
            // Brief pause so operator sees the "Done" state before redirect
            setTimeout(() => {
              window.location.href = data.redirect_url;
            }, 1200);
          }
          return;
        }

        if (ps === 'failed') {
          stopElapsedTimer();
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
  });

  // ─── Error helpers ──────────────────────────────────────────────────────────
  function showError(msg) {
    stopElapsedTimer();
    state = 'error';
    renderStatus(msg);
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
        statusBox.className += ' upload-status--done';
        statusBox.innerHTML =
          '<span class="upload-status__check" aria-hidden="true">✓</span>'
          + '<span class="upload-status__text">Document reçu — redirection en cours…</span>';
        break;
      }
      case 'timeout': {
        statusBox.hidden = false;
        statusBox.className += ' upload-status--warn';
        statusBox.innerHTML =
          '<span class="upload-status__warn-icon" aria-hidden="true">⚠</span>'
          + '<span class="upload-status__text">Délai dépassé — vérifier le Triage manuellement</span>'
          + '<button class="upload-status__retry" type="button">↩ Actualiser</button>';
        const retryBtn = statusBox.querySelector('.upload-status__retry');
        if (retryBtn) {
          retryBtn.addEventListener('click', () => {
            setState('idle');
            window.location.reload();
          });
        }
        break;
      }
      case 'error': {
        statusBox.hidden = false;
        statusBox.className += ' upload-status--err';
        const safMsg = (errorMsg || 'Erreur inconnue').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        statusBox.innerHTML =
          '<span class="upload-status__warn-icon" aria-hidden="true">✕</span>'
          + `<span class="upload-status__text">${safMsg}</span>`
          + '<button class="upload-status__retry" type="button">↩ Réessayer</button>';
        const retryBtn = statusBox.querySelector('.upload-status__retry');
        if (retryBtn) {
          retryBtn.addEventListener('click', () => {
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
