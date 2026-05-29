/**
 * sb-board.js — Mother-shell board polling driver (Atom 6)
 *
 * Fetches /api/sb-board-data.php every 15 s, diffs incoming mothers vs DOM,
 * re-renders changed cards + heartbeat badges + ETA chips.
 * Pauses when document.hidden; resumes on tab focus / online event.
 * Exponential backoff on error, capped at 60 s.
 *
 * No external dependencies. IIFE-scoped; exposes window.SbBoard for debug.
 */
(function () {
  'use strict';

  const POLL_MS   = 15000;
  const MAX_BACKOFF_MS = 60000;
  const ENDPOINT  = '/api/sb-board-data.php';

  // Zone → CSS phase modifier (mirrors sbb_zone_phase_class() in PHP)
  const ZONE_PHASE = {
    brasserie:       'brewing',
    fermentation:    'fermenting',
    bbt:             'racking',
    conditionnement: 'packaging',
  };

  // French month abbreviations (mirrors sbb_date_fr() in PHP)
  const FR_MONTHS = ['jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];

  let pollTimer          = null;
  let consecutiveErrors  = 0;

  // ── Helpers ────────────────────────────────────────────────────────────────

  /** HTML-escape a value before inserting via innerHTML. */
  function esc(v) {
    if (v === null || v === undefined) return '';
    return String(v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /**
   * French short date: "3 mai" — mirrors sbb_date_fr() in PHP.
   * Input: ISO-like string "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS".
   */
  function dateFr(str) {
    if (!str) return '—';
    // Replace space separator so Date() parses it cross-browser
    var d = new Date(str.replace(' ', 'T'));
    if (isNaN(d.getTime())) return '—';
    return d.getDate() + ' ' + FR_MONTHS[d.getMonth()];
  }

  /** Display H:MM — used for the fetched_at timestamp pill. */
  function formatTime(isoStr) {
    if (!isoStr) return '';
    try {
      var d = new Date(isoStr.replace(' ', 'T'));
      return d.getHours() + ':' + String(d.getMinutes()).padStart(2, '0');
    } catch (e) { return ''; }
  }

  function backoffMs() {
    return Math.min(POLL_MS * Math.pow(2, consecutiveErrors), MAX_BACKOFF_MS);
  }

  // ── Polling ────────────────────────────────────────────────────────────────

  function scheduleNext(ms) {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = setTimeout(fetchBoard, ms);
  }

  async function fetchBoard() {
    if (document.hidden) return;
    try {
      var res = await fetch(ENDPOINT, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      var data = await res.json();
      if (!data.ok) throw new Error(data.error || 'server-error');

      consecutiveErrors = 0;
      renderUpdates(data);
      scheduleNext(POLL_MS);
    } catch (err) {
      consecutiveErrors++;
      console.warn('[sb-board] poll failed:', err.message);
      scheduleNext(backoffMs());
    }
  }

  // ── DOM diff + render ──────────────────────────────────────────────────────

  function renderUpdates(data) {
    var mothers = data.mothers || {};

    Object.keys(mothers).forEach(function (zone) {
      // expedition is a placeholder — never has cards, skip
      if (zone === 'expedition') return;

      var zoneEl = document.querySelector('.sb-zone--' + zone + ' .sb-zone__cards');
      if (!zoneEl) return;

      var incoming   = mothers[zone] || [];
      var incomingIds = new Set(incoming.map(function (m) { return String(m.id); }));

      // Remove cards that disappeared
      var existingCards = zoneEl.querySelectorAll('.sb-card[data-mother-id]');
      existingCards.forEach(function (card) {
        if (!incomingIds.has(card.getAttribute('data-mother-id'))) {
          card.classList.add('sb-card--leaving');
          setTimeout(function () {
            if (card.parentNode) card.parentNode.removeChild(card);
          }, 300);
        }
      });

      // Add new or update existing cards
      incoming.forEach(function (mother) {
        var id      = String(mother.id);
        var existing = zoneEl.querySelector('.sb-card[data-mother-id="' + id + '"]');
        if (existing) {
          updateCard(existing, mother);
        } else {
          var card = buildCard(mother, zone);
          card.classList.add('sb-card--entering');
          zoneEl.appendChild(card);
          // Trigger CSS transition on next frame
          requestAnimationFrame(function () {
            requestAnimationFrame(function () {
              card.classList.remove('sb-card--entering');
            });
          });
        }
      });

      // Sync the zone count badge
      var countEl = zoneEl.closest('.sb-zone').querySelector('.sb-zone__count');
      if (countEl) {
        countEl.textContent = String(incoming.length);
        countEl.classList.toggle('sb-zone__count--zero', incoming.length === 0);
      }

      // Toggle empty-state element visibility
      var emptyEl = zoneEl.querySelector('.sb-zone-empty');
      if (emptyEl) {
        emptyEl.style.display = incoming.length > 0 ? 'none' : '';
      }
      var stackEl = zoneEl.querySelector('.sb-cards-stack');
      if (stackEl) {
        stackEl.style.display = incoming.length === 0 ? 'none' : '';
      }
    });

    // Update last-fetch timestamp pill (if element present)
    var tsEl = document.querySelector('[data-sb-fetched-at]');
    if (tsEl && data.fetched_at) {
      tsEl.textContent = 'Synchronisé à ' + formatTime(data.fetched_at);
    }
  }

  /**
   * Update the mutable state surfaces of an already-rendered card:
   *   – heartbeat dot severity
   *   – ETA chip text
   *   – progress bar fill + warn modifier
   */
  function updateCard(card, mother) {
    // Heartbeat dot: .sb-heartbeat.sb-heartbeat--{severity}
    var dot = card.querySelector('.sb-heartbeat');
    if (dot) {
      dot.className = 'sb-heartbeat sb-heartbeat--' + esc(mother.heartbeat_severity || 'red');
      dot.setAttribute('aria-label', 'Activité : ' + esc(mother.heartbeat_severity || 'red'));
    }

    // ETA chip: .sb-eta inside .sb-card__meta
    var etaEl = card.querySelector('.sb-eta');
    if (mother.eta_close_date) {
      if (etaEl) {
        etaEl.textContent = 'ETA ' + esc(mother.eta_close_date);
      }
      // If ETA was absent and now present, a full rebuild would be needed —
      // for atom 6 we only update if the element already exists.
    }

    // Progress bar: .sb-progress__fill
    var fillEl = card.querySelector('.sb-progress__fill');
    if (fillEl && mother.pct_packaged !== null) {
      var pct = Math.round(Number(mother.pct_packaged));
      fillEl.style.width = pct + '%';
      fillEl.setAttribute('aria-valuenow', pct);
      fillEl.classList.toggle('sb-progress__fill--warn', pct >= 80);
    }
  }

  /**
   * Build a fresh card DOM that mirrors sbb_render_mother_card() in PHP.
   *
   * HTML shape:
   *   <div class="sb-card sb-card--{phase}" data-mother-id="{id}">
   *     <div class="sb-card__top">
   *       <span class="sb-card__batch">#{batch}</span>
   *       <span class="sb-heartbeat sb-heartbeat--{severity}" aria-label="Activité : {severity}"></span>
   *     </div>
   *     <div class="sb-card__name">{recipe_name}</div>
   *     <div class="sb-card__meta">
   *       <span class="sb-card__meta-item">Ouvert le {opened_at}</span>
   *       [<span class="sb-card__meta-dot" aria-hidden="true"></span>
   *        <span class="sb-card__meta-item sb-card__meta-item--vessel">{kind}-{number}</span>]
   *       [<span class="sb-card__meta-dot" aria-hidden="true"></span>
   *        <span class="sb-eta">ETA {eta_close_date}</span>]
   *     </div>
   *     [<div class="sb-progress">
   *        <div class="sb-progress__fill[--warn]" style="width:{pct}%" role="progressbar"
   *             aria-valuenow="{pct}" aria-valuemin="0" aria-valuemax="100"></div>
   *      </div>]
   *     <a href="/modules/sb-mother.php?id={id}" class="sb-card__link">
   *       Voir <span class="sb-card__link-arrow" aria-hidden="true">→</span>
   *     </a>
   *   </div>
   */
  function buildCard(mother, zone) {
    var id        = Number(mother.id);
    var severity  = esc(mother.heartbeat_severity || 'red');
    var phase     = ZONE_PHASE[zone] || '';
    var batch     = esc(mother.batch || '—');
    var name      = esc(mother.recipe_name || '—');
    var openedAt  = dateFr(mother.opened_at || '');
    var hasVessel = (mother.current_vessel_kind !== null && mother.current_vessel_kind !== undefined &&
                     mother.current_vessel_number !== null && mother.current_vessel_number !== undefined);
    var vesselLabel = hasVessel
      ? esc(String(mother.current_vessel_kind).toUpperCase()) + '-' + Number(mother.current_vessel_number)
      : '';
    var hasEta    = (mother.eta_close_date !== null && mother.eta_close_date !== undefined && mother.eta_close_date !== '');
    var eta       = hasEta ? esc(String(mother.eta_close_date)) : '';
    var hasPct    = (mother.pct_packaged !== null && mother.pct_packaged !== undefined);
    var pct       = hasPct ? Math.round(Number(mother.pct_packaged)) : 0;
    var pctWarn   = pct >= 80 ? ' sb-progress__fill--warn' : '';

    var html = '<div class="sb-card' + (phase ? ' sb-card--' + phase : '') + '" data-mother-id="' + id + '">'
      + '<div class="sb-card__top">'
      +   '<span class="sb-card__batch">#' + batch + '</span>'
      +   '<span class="sb-heartbeat sb-heartbeat--' + severity + '" aria-label="Activité : ' + severity + '"></span>'
      + '</div>'
      + '<div class="sb-card__name">' + name + '</div>'
      + '<div class="sb-card__meta">'
      +   '<span class="sb-card__meta-item">Ouvert le ' + openedAt + '</span>';

    if (hasVessel) {
      html += '<span class="sb-card__meta-dot" aria-hidden="true"></span>'
        + '<span class="sb-card__meta-item sb-card__meta-item--vessel">' + vesselLabel + '</span>';
    }
    if (hasEta) {
      html += '<span class="sb-card__meta-dot" aria-hidden="true"></span>'
        + '<span class="sb-eta">ETA ' + eta + '</span>';
    }

    html += '</div>';

    if (hasPct) {
      html += '<div class="sb-progress">'
        + '<div class="sb-progress__fill' + pctWarn + '" style="width:' + pct + '%" role="progressbar"'
        + ' aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100"></div>'
        + '</div>';
    }

    html += '<a href="/modules/sb-mother.php?id=' + id + '" class="sb-card__link">'
      + 'Voir <span class="sb-card__link-arrow" aria-hidden="true">→</span>'
      + '</a>'
      + '</div>';

    var wrapper = document.createElement('div');
    wrapper.innerHTML = html;
    return wrapper.firstElementChild;
  }

  // ── Lifecycle ──────────────────────────────────────────────────────────────

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      // Tab regained focus — fetch immediately, then resume normal schedule
      fetchBoard();
    }
  });

  window.addEventListener('online', function () {
    consecutiveErrors = 0; // reset backoff on network recovery
    fetchBoard();
  });

  function start() {
    fetchBoard();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  // Minimal debug surface
  window.SbBoard = {
    fetchNow:   fetchBoard,
    errors:     function () { return consecutiveErrors; },
  };
}());
