/**
 * charges-bc.js — ChargesBC CSV upload page behaviour
 *
 * Flow:
 *   1. User selects CSV → file hint updates, upload button enabled.
 *   2. User clicks "Prévisualiser" → POST confirm=0 → preview panel shown.
 *   3. User clicks "Confirmer l'import" → POST confirm=1 with upload_id.
 *   4. Result notice shown; preview panel hidden; page partially refreshes summary.
 */

(function () {
  'use strict';

  const form        = document.getElementById('cbc-upload-form');
  const fileInput   = document.getElementById('cbc-file-input');
  const fileHint    = document.getElementById('cbc-file-hint');
  const dropzone    = document.getElementById('cbc-dropzone');
  const uploadBtn   = document.getElementById('cbc-upload-btn');
  const confirmFlag = document.getElementById('cbc-confirm-flag');

  const previewPanel    = document.getElementById('cbc-preview-panel');
  const previewTitle    = document.getElementById('cbc-preview-title');
  const previewStats    = document.getElementById('cbc-preview-stats');
  const previewTableWrap= document.getElementById('cbc-preview-table-wrap');
  const previewErrors   = document.getElementById('cbc-preview-errors');
  const commitBtn       = document.getElementById('cbc-commit-btn');
  const cancelBtn       = document.getElementById('cbc-cancel-btn');
  const resultEl        = document.getElementById('cbc-result');

  let currentUploadId = null;

  // ── Utility ────────────────────────────────────────────────────────────────

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fmtNum(val, decimals) {
    if (val === null || val === undefined || val === '') return '—';
    return Number(val).toFixed(decimals !== undefined ? decimals : 2);
  }

  function showResult(ok, msg) {
    resultEl.textContent  = msg;
    resultEl.className    = 'cbc-result ' + (ok ? 'ok' : 'err');
    resultEl.hidden       = false;
  }

  function hideResult() {
    resultEl.hidden = true;
  }

  function setUploadBusy(busy) {
    uploadBtn.disabled = busy;
    uploadBtn.innerHTML = busy
      ? '<span class="cbc-spinner" aria-hidden="true"></span> Analyse…'
      : '<span class="cbc-btn__icon" aria-hidden="true">↑</span> Prévisualiser';
  }

  function setCommitBusy(busy) {
    commitBtn.disabled = busy;
    commitBtn.innerHTML = busy
      ? '<span class="cbc-spinner" aria-hidden="true"></span> Import en cours…'
      : 'Confirmer l\'import';
  }

  // ── File selection ─────────────────────────────────────────────────────────

  fileInput.addEventListener('change', function () {
    const file = this.files[0];
    if (file) {
      fileHint.textContent = file.name;
      uploadBtn.disabled = false;
    } else {
      fileHint.textContent = 'Aucun fichier sélectionné';
      uploadBtn.disabled = true;
    }
    hideResult();
    previewPanel.hidden = true;
    currentUploadId = null;
  });

  // ── Drag-and-drop ──────────────────────────────────────────────────────────

  ['dragenter', 'dragover'].forEach(function (evt) {
    dropzone.addEventListener(evt, function (e) {
      e.preventDefault();
      dropzone.classList.add('drag-over');
    });
  });

  ['dragleave', 'drop'].forEach(function (evt) {
    dropzone.addEventListener(evt, function (e) {
      e.preventDefault();
      dropzone.classList.remove('drag-over');
    });
  });

  dropzone.addEventListener('drop', function (e) {
    const files = e.dataTransfer.files;
    if (files.length > 0) {
      // Assign to input (browsers that support it)
      try {
        const dt = new DataTransfer();
        dt.items.add(files[0]);
        fileInput.files = dt.files;
      } catch (_) { /* older browsers — skip */ }
      fileHint.textContent = files[0].name;
      uploadBtn.disabled = false;
      hideResult();
      previewPanel.hidden = true;
    }
  });

  // ── Step 1: Preview (dry-run) ──────────────────────────────────────────────

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    hideResult();

    if (!fileInput.files || !fileInput.files[0]) {
      showResult(false, 'Veuillez sélectionner un fichier CSV.');
      return;
    }

    confirmFlag.value = '0';
    setUploadBusy(true);

    const fd = new FormData(form);

    fetch('/api/charges-bc-upload.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        setUploadBusy(false);
        if (!data.ok) {
          showResult(false, data.error || 'Erreur inconnue.');
          return;
        }
        currentUploadId = data.upload_id;
        renderPreview(data);
      })
      .catch(function (err) {
        setUploadBusy(false);
        showResult(false, 'Erreur réseau : ' + err.message);
      });
  });

  function renderPreview(data) {
    // Stats bar
    previewStats.innerHTML =
      stat('Fichier',   escHtml(data.filename || '—')) +
      stat('Lignes lues', data.parsed) +
      stat('Nouvelles', data.inserted === 0 && !data.preview ? '—' : data.parsed) +
      stat('Doublons',  data.skipped_dupes);

    // Errors
    if (data.errors && data.errors.length > 0) {
      previewErrors.innerHTML =
        '<h4>Avertissements (' + data.errors.length + ')</h4><ul>' +
        data.errors.map(function (e) { return '<li>' + escHtml(e) + '</li>'; }).join('') +
        '</ul>';
      previewErrors.hidden = false;
    } else {
      previewErrors.hidden = true;
    }

    // Preview table
    if (data.preview && data.preview.length > 0) {
      const rows = data.preview;
      let html = '<table class="cbc-preview-table" role="grid"><thead><tr>' +
        '<th>Période</th><th>N° GL</th><th>Libellé</th><th>N° écriture</th>' +
        '<th>Description</th><th class="num">Débit</th><th class="num">Crédit</th>' +
        '<th>Date</th><th>Récap.</th>' +
        '</tr></thead><tbody>';
      rows.forEach(function (r) {
        html += '<tr>' +
          '<td>' + escHtml(r.period_text || '') + '</td>' +
          '<td class="cbc-gl-no">' + escHtml(r.gl_account_no || '') + '</td>' +
          '<td>' + escHtml(r.gl_account_name || '') + '</td>' +
          '<td>' + escHtml(r.entry_no || '') + '</td>' +
          '<td>' + escHtml((r.description || '').substring(0, 40)) + '</td>' +
          '<td class="num">' + fmtNum(r.debit_amount) + '</td>' +
          '<td class="num">' + fmtNum(r.credit_amount) + '</td>' +
          '<td>' + escHtml(r.posting_date || '') + '</td>' +
          '<td>' + (r.is_summary ? '✓' : '') + '</td>' +
          '</tr>';
      });
      html += '</tbody></table>';
      if (data.parsed > rows.length) {
        html += '<p style="padding:0.5rem 0.75rem;font-size:0.78rem;color:var(--ink-mute);font-family:\'DM Sans\',sans-serif;">' +
          '… ' + (data.parsed - rows.length) + ' ligne(s) supplémentaire(s) non affichée(s).</p>';
      }
      previewTableWrap.innerHTML = html;
    } else {
      previewTableWrap.innerHTML =
        '<p style="padding:1rem;font-family:\'DM Sans\',sans-serif;font-size:0.875rem;color:var(--ink-mute);">Aucune ligne transactionnelle trouvée.</p>';
    }

    previewTitle.textContent = 'Prévisualisation — ' + data.parsed + ' ligne(s) détectée(s)';
    commitBtn.disabled = (data.parsed === 0 || !currentUploadId);
    previewPanel.hidden = false;
  }

  function stat(label, value) {
    return '<span class="stat"><span class="stat-label">' + escHtml(label) +
      '</span><span class="stat-value">' + escHtml(String(value)) + '</span></span>';
  }

  // ── Preview close ──────────────────────────────────────────────────────────

  document.getElementById('cbc-preview-close').addEventListener('click', function () {
    previewPanel.hidden = true;
    currentUploadId = null;
  });

  cancelBtn.addEventListener('click', function () {
    previewPanel.hidden = true;
    currentUploadId = null;
  });

  // ── Step 2: Commit ─────────────────────────────────────────────────────────

  commitBtn.addEventListener('click', function () {
    if (!currentUploadId) {
      showResult(false, 'Aucun fichier en attente. Veuillez re-téléverser.');
      return;
    }

    setCommitBusy(true);

    // Re-use the same CSRF token from the form
    const csrf = form.querySelector('input[name="csrf"]').value;
    const fd   = new FormData();
    fd.append('csrf',      csrf);
    fd.append('confirm',   '1');
    fd.append('upload_id', currentUploadId);

    fetch('/api/charges-bc-upload.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        setCommitBusy(false);
        previewPanel.hidden = true;
        currentUploadId     = null;

        if (!data.ok) {
          showResult(false, data.error || 'Erreur lors de l\'import.');
          return;
        }

        const msg = data.inserted + ' ligne(s) insérée(s) · ' +
          data.skipped_dupes + ' doublon(s) ignoré(s)' +
          (data.errors.length > 0 ? ' · ' + data.errors.length + ' avertissement(s)' : '') + '.';
        showResult(true, msg);

        // Reload the page after a short delay so the summary panel refreshes
        setTimeout(function () { window.location.reload(); }, 1800);
      })
      .catch(function (err) {
        setCommitBusy(false);
        showResult(false, 'Erreur réseau : ' + err.message);
      });
  });

}());
