/**
 * journal-saisies.js — Journal des saisies
 *
 * Renders the live feed from window.JOURNAL_DATA, polls for new events
 * every 20s (paused when tab hidden), prepends new rows with a pulse animation,
 * supports filter chips, "Charger plus" pagination, and a drill-down detail
 * dialog per row.
 *
 * Globals required (injected by PHP):
 *   window.JOURNAL_DATA   — initial feed [{source_table,row_pk,form_type,
 *                            event_date,submitted_at,operator_display,label}]
 *   window.JOURNAL_CURSOR — string "YYYY-MM-DD HH:MM:SS" or null
 */

(function () {
  'use strict';

  /* ── Escape helper ─────────────────────────────────────────────────────── */
  function escHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /* ── State ─────────────────────────────────────────────────────────────── */
  let cursor      = window.JOURNAL_CURSOR || null;   // max submitted_at seen
  let oldestCursor = null;                            // min submitted_at for load-more
  let allEvents   = (window.JOURNAL_DATA || []).slice();
  let activeFilter = 'all';
  let pollTimer   = null;
  let isPolling   = false;

  /* ── DOM refs ──────────────────────────────────────────────────────────── */
  const feedEl       = document.getElementById('js-feed');
  const emptyEl      = document.getElementById('js-empty');
  const loadMoreBtn  = document.getElementById('js-load-more');
  const liveStatus   = document.getElementById('js-live-status');
  const dialog       = document.getElementById('js-detail-dialog');
  const dialogTitle  = document.getElementById('jsd-title');
  const dialogBody   = document.getElementById('jsd-body');
  const dialogClose  = document.getElementById('jsd-close');

  /* ── Relative time helper ──────────────────────────────────────────────── */
  function relTime(dtStr) {
    if (!dtStr) return '—';
    // submitted_at is "YYYY-MM-DD HH:MM:SS" (UTC from MySQL)
    const d = new Date(dtStr.replace(' ', 'T') + 'Z');
    if (isNaN(d)) return dtStr;
    const diffSec = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diffSec < 60)  return 'à l\'instant';
    if (diffSec < 3600) return Math.floor(diffSec / 60) + ' min';
    if (diffSec < 86400) return Math.floor(diffSec / 3600) + ' h';
    if (diffSec < 604800) return Math.floor(diffSec / 86400) + ' j';
    // Fallback to date
    return d.toLocaleDateString('fr-CH', { day: '2-digit', month: '2-digit', year: 'numeric' });
  }

  /* ── Form-type → CSS modifier key ─────────────────────────────────────── */
  function formTypeKey(ft) {
    if (!ft) return 'other';
    if (ft.startsWith('Brassage'))         return 'brassage';
    if (ft === 'Fermentation')             return 'fermentation';
    if (ft === 'Transfert')                return 'transfert';
    if (ft === 'Conditionnement')          return 'conditionnement';
    return 'other';
  }

  /* ── Build a single feed row element ──────────────────────────────────── */
  function buildRow(ev, highlight) {
    const li = document.createElement('div');
    li.className = 'js-row js-row--' + formTypeKey(ev.form_type) +
                   (highlight ? ' js-row--new' : '');
    li.setAttribute('role', 'listitem');
    li.setAttribute('tabindex', '0');
    li.setAttribute('aria-label',
      escHtml(ev.form_type) + ' — ' + escHtml(ev.label));
    li.dataset.table  = ev.source_table;
    li.dataset.pk     = String(ev.row_pk);
    li.dataset.filter = formTypeKey(ev.form_type);

    li.innerHTML =
      '<div class="js-row__accent" aria-hidden="true"></div>' +
      '<div class="js-row__body">' +
        '<div class="js-row__top">' +
          '<span class="js-row__type">' + escHtml(ev.form_type) + '</span>' +
          '<span class="js-row__time">' + escHtml(relTime(ev.submitted_at)) + '</span>' +
        '</div>' +
        '<div class="js-row__label">' + escHtml(ev.label) + '</div>' +
        '<div class="js-row__meta">' +
          '<span class="js-row__op">' + escHtml(ev.operator_display) + '</span>' +
          (ev.event_date ? '<span class="js-row__date">' +
            escHtml(ev.event_date) + '</span>' : '') +
        '</div>' +
      '</div>' +
      '<div class="js-row__chevron" aria-hidden="true">›</div>';

    li.addEventListener('click', () => openDetail(ev.source_table, ev.row_pk, ev.form_type, ev.label));
    li.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openDetail(ev.source_table, ev.row_pk, ev.form_type, ev.label);
      }
    });

    return li;
  }

  /* ── Filter helper ─────────────────────────────────────────────────────── */
  function matchesFilter(ev) {
    if (activeFilter === 'all') return true;
    return formTypeKey(ev.form_type) === activeFilter;
  }

  /* ── Full render from allEvents ────────────────────────────────────────── */
  function renderAll() {
    feedEl.innerHTML = '';
    const visible = allEvents.filter(matchesFilter);
    emptyEl.hidden = visible.length > 0;
    visible.forEach(ev => feedEl.appendChild(buildRow(ev, false)));
    updateOldestCursor();
  }

  /* ── Prepend new events (live append) ──────────────────────────────────── */
  function prependEvents(newEvs) {
    const visible = newEvs.filter(matchesFilter);
    if (visible.length === 0) return;

    const motionOk = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Prepend in reverse so newest ends up at top
    [...visible].reverse().forEach(ev => {
      const el = buildRow(ev, motionOk);
      feedEl.insertBefore(el, feedEl.firstChild);
      if (motionOk) {
        // Remove class after animation completes (400ms)
        setTimeout(() => el.classList.remove('js-row--new'), 600);
      }
    });

    emptyEl.hidden = true;
  }

  /* ── Append older events (load-more) ───────────────────────────────────── */
  function appendOlder(olderEvs) {
    const visible = olderEvs.filter(matchesFilter);
    visible.forEach(ev => feedEl.appendChild(buildRow(ev, false)));
    emptyEl.hidden = feedEl.children.length > 0;
    updateOldestCursor();
  }

  /* ── Track oldest cursor for load-more ────────────────────────────────── */
  function updateOldestCursor() {
    if (allEvents.length === 0) {
      oldestCursor = null;
      return;
    }
    oldestCursor = allEvents[allEvents.length - 1].submitted_at || null;
  }

  /* ── Live poll ─────────────────────────────────────────────────────────── */
  async function poll() {
    if (document.hidden) return;          // tab not visible — skip
    if (!cursor) return;

    try {
      const url = '/api/journal-feed.php?since=' + encodeURIComponent(cursor) + '&limit=40';
      const resp = await fetch(url);
      if (!resp.ok) return;
      const newEvs = await resp.json();
      if (!Array.isArray(newEvs) || newEvs.length === 0) return;

      // Advance cursor
      cursor = newEvs[0].submitted_at;

      // Prepend to allEvents
      allEvents = [...newEvs, ...allEvents];

      prependEvents(newEvs);
      setLiveStatus('En direct');
    } catch (_e) {
      setLiveStatus('Reconnexion…');
    }
  }

  function setLiveStatus(text) {
    if (liveStatus) liveStatus.textContent = text;
  }

  function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(poll, 20000);   // every 20s
  }

  function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
  }

  // Pause when hidden, resume + immediate poll when visible
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stopPolling();
      setLiveStatus('Mis en pause');
    } else {
      poll();
      startPolling();
      setLiveStatus('En direct');
    }
  });

  /* ── Filter chips ──────────────────────────────────────────────────────── */
  document.querySelectorAll('.js-chip').forEach(btn => {
    btn.addEventListener('click', () => {
      activeFilter = btn.dataset.filter;
      document.querySelectorAll('.js-chip').forEach(b => b.classList.remove('js-chip--active'));
      btn.classList.add('js-chip--active');

      // Re-render the visible slice from allEvents
      feedEl.innerHTML = '';
      const visible = allEvents.filter(matchesFilter);
      emptyEl.hidden = visible.length > 0;
      visible.forEach(ev => feedEl.appendChild(buildRow(ev, false)));
    });
  });

  /* ── Load more ─────────────────────────────────────────────────────────── */
  if (loadMoreBtn) {
    loadMoreBtn.addEventListener('click', async () => {
      if (!oldestCursor) return;

      loadMoreBtn.disabled = true;
      loadMoreBtn.textContent = 'Chargement…';

      try {
        const url = '/api/journal-feed.php?before=' + encodeURIComponent(oldestCursor) + '&limit=40';
        const resp = await fetch(url);
        if (!resp.ok) throw new Error('http ' + resp.status);
        const olderEvs = await resp.json();
        if (!Array.isArray(olderEvs) || olderEvs.length === 0) {
          loadMoreBtn.textContent = 'Fin du journal';
          loadMoreBtn.disabled = true;
          return;
        }

        allEvents = [...allEvents, ...olderEvs];
        appendOlder(olderEvs);

        loadMoreBtn.disabled = false;
        loadMoreBtn.textContent = 'Charger plus';
      } catch (_e) {
        loadMoreBtn.disabled = false;
        loadMoreBtn.textContent = 'Erreur — réessayer';
      }
    });
  }

  /* ── Detail dialog ─────────────────────────────────────────────────────── */

  function openDetail(table, pk, formType, label) {
    if (!dialog) return;

    dialogTitle.textContent = (formType || '') + (label ? ' — ' + label : '');
    dialogBody.innerHTML    = '<div class="jsd-loading">Chargement…</div>';

    // Note: dialog has display:grid only when [open] (per catalog rule)
    dialog.showModal();

    const url = '/api/journal-detail.php?table=' +
      encodeURIComponent(table) + '&pk=' + encodeURIComponent(pk);

    fetch(url)
      .then(r => { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
      .then(data => renderDetail(data))
      .catch(_e => {
        dialogBody.innerHTML = '<p class="jsd-error">Impossible de charger les détails.</p>';
      });
  }

  function renderDetail(data) {
    if (!data || data.error) {
      dialogBody.innerHTML = '<p class="jsd-error">Données non disponibles.</p>';
      return;
    }

    let html = '';

    // ── Operator + submitted_at ──
    html += '<div class="jsd-meta">' +
      '<span class="jsd-meta__op">' + escHtml(data.operator_display) + '</span>' +
      (data.submitted_at
        ? ' · <span class="jsd-meta__ts">' + escHtml(data.submitted_at) + '</span>'
        : '') +
    '</div>';

    // ── Current field values ──
    if (data.fields && data.fields.length > 0) {
      html += '<section class="jsd-section"><h3 class="jsd-section__title">Valeurs actuelles</h3>';
      html += '<dl class="jsd-fields">';
      data.fields.forEach(f => {
        if (f.value === null || f.value === '') return;
        html += '<dt class="jsd-field__key">' + escHtml(f.key) + '</dt>' +
                '<dd class="jsd-field__val">' + escHtml(String(f.value)) + '</dd>';
      });
      html += '</dl></section>';
    }

    // ── Audit timeline ──
    html += '<section class="jsd-section"><h3 class="jsd-section__title">Historique</h3>';

    if (!data.has_audit || !data.audit || data.audit.length === 0) {
      html += '<p class="jsd-pre-audit">Saisie d\'origine — antérieure au journal d\'audit.</p>';
    } else {
      html += '<ol class="jsd-timeline">';
      data.audit.forEach((entry, idx) => {
        const isFirst = idx === 0;
        const qcClass = entry.qc_flag !== 'normal'
          ? ' jsd-timeline__entry--' + escHtml(entry.qc_flag)
          : '';

        html += '<li class="jsd-timeline__entry' + qcClass + '">';
        html += '<div class="jsd-timeline__node" aria-hidden="true"></div>';
        html += '<div class="jsd-timeline__content">';

        // Header
        html += '<div class="jsd-timeline__hdr">';
        if (entry.action === 'insert') {
          html += '<span class="jsd-badge jsd-badge--insert">Création</span>';
        } else {
          html += '<span class="jsd-badge jsd-badge--update">Modification</span>';
        }
        html += '<span class="jsd-timeline__actor">' + escHtml(entry.actor) + '</span>';
        html += '<span class="jsd-timeline__ts">'    + escHtml(entry.created_at) + '</span>';
        if (entry.qc_flag && entry.qc_flag !== 'normal') {
          html += '<span class="jsd-qc-flag jsd-qc-flag--' + escHtml(entry.qc_flag) + '">' +
                  escHtml(entry.qc_flag) + '</span>';
        }
        html += '</div>';

        // Comment
        if (entry.comment) {
          html += '<p class="jsd-timeline__comment">' + escHtml(entry.comment) + '</p>';
        }

        // Diff or snapshot
        if (entry.action === 'insert' && entry.after_snapshot) {
          const snap = entry.after_snapshot;
          const keys = Object.keys(snap).filter(k => snap[k] !== null && snap[k] !== '');
          if (keys.length > 0) {
            html += '<dl class="jsd-diff jsd-diff--snapshot">';
            keys.forEach(k => {
              html += '<dt>' + escHtml(k) + '</dt>' +
                      '<dd class="jsd-diff__new">' + escHtml(String(snap[k])) + '</dd>';
            });
            html += '</dl>';
          }
        } else if (entry.action === 'update' && entry.diff && entry.diff.length > 0) {
          html += '<dl class="jsd-diff">';
          entry.diff.forEach(d => {
            html += '<dt>' + escHtml(d.field) + '</dt>' +
                    '<dd class="jsd-diff__change">' +
                      '<span class="jsd-diff__old">' +
                        (d.old !== null ? escHtml(String(d.old)) : '<em>vide</em>') +
                      '</span>' +
                      '<span class="jsd-diff__arrow" aria-hidden="true">→</span>' +
                      '<span class="jsd-diff__new">' +
                        (d.new !== null ? escHtml(String(d.new)) : '<em>vide</em>') +
                      '</span>' +
                    '</dd>';
          });
          html += '</dl>';
        } else if (entry.action === 'update') {
          html += '<p class="jsd-no-diff">Aucun champ modifié enregistré.</p>';
        }

        html += '</div></li>';
      });
      html += '</ol>';
    }

    html += '</section>';
    dialogBody.innerHTML = html;
  }

  /* ── Dialog close handlers ─────────────────────────────────────────────── */
  if (dialogClose) {
    dialogClose.addEventListener('click', () => dialog.close());
  }

  // Close on backdrop click
  if (dialog) {
    dialog.addEventListener('click', (e) => {
      if (e.target === dialog) dialog.close();
    });
    // Escape key is handled natively by <dialog>
  }

  /* ── Boot ──────────────────────────────────────────────────────────────── */
  function init() {
    if (!allEvents.length) {
      // Initial cursor from PHP
      cursor = window.JOURNAL_CURSOR || null;
    } else {
      cursor = allEvents[0].submitted_at || null;
    }

    renderAll();
    startPolling();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

}());
