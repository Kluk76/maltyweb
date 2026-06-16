/**
 * expeditions-set-site.js
 *
 * Inline fulfilment-site override on the B2B Commandes board.
 * Each B2B order row carries a .exp-site-chip-wrap with:
 *   - a .exp-site-chip (auto or override variant) — click to reveal select
 *   - a .exp-site-select (hidden <select> with Automatique + sites options)
 *
 * On select change → POST /api/expeditions-set-site.php → swap chip text +
 * override marker from JSON response.
 *
 * CSRF: reads window.EXP_CSRF; refreshes on 'expired' response (one retry).
 * Only B2B ord_orders rows get this — eshop auto-rows have no .exp-site-chip-wrap.
 *
 * Loaded via <script> on commandes view only.
 */
(function () {
  'use strict';

  var currentCsrf = (window.EXP_CSRF || '');

  /**
   * POST a site override change.
   */
  function postSetSite(orderId, siteId, csrf, cb) {
    fetch('/api/expeditions-set-site.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({
        csrf:                  csrf,
        order_id:              orderId,
        fulfilment_site_id_fk: siteId,  // '' for Automatique, int for explicit site
      }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) { cb(null, data); })
    .catch(function (err) { cb(err, null); });
  }

  /**
   * Re-render the chip inside a .exp-site-chip-wrap after a successful write.
   * Uses data.ui_state ('override'|'auto'|'unassigned') when present;
   * falls back to data.is_override for backward compat.
   */
  function applyChip(wrap, data) {
    var oldChip = wrap.querySelector('.exp-site-chip');
    if (oldChip) oldChip.remove();

    var chip    = document.createElement('span');
    var uiState = data.ui_state || (data.is_override ? 'override' : 'auto');

    if (uiState === 'override') {
      chip.className   = 'exp-site-chip exp-site-chip--override';
      chip.title       = 'Site forcé manuellement — cliquer pour modifier';
      chip.textContent = '✎ ' + (data.resolved_site_name || '—');
    } else if (uiState === 'unassigned') {
      chip.className   = 'exp-site-chip exp-site-chip--unassigned';
      chip.title       = 'Aucun lieu de départ — renseigner pour l\'enregistrer comme défaut du client';
      chip.textContent = '⚠ à renseigner';
    } else {
      chip.className   = 'exp-site-chip exp-site-chip--auto';
      chip.title       = 'Site résolu automatiquement — cliquer pour forcer';
      chip.textContent = '📍 ' + (data.resolved_site_name || '—');
    }
    chip.setAttribute('role', 'button');
    chip.setAttribute('tabindex', '0');

    // Insert before the select
    var sel = wrap.querySelector('.exp-site-select');
    wrap.insertBefore(chip, sel || null);

    // Update select value to match (tracks the ORDER's override, not resolved site)
    if (sel) {
      sel.value = data.fulfilment_site_id_fk != null ? String(data.fulfilment_site_id_fk) : '';
      sel.setAttribute('hidden', '');
    }

    // Update wrap data attribute
    wrap.dataset.currentSiteId = data.fulfilment_site_id_fk != null ? String(data.fulfilment_site_id_fk) : '0';

    // Re-wire the chip click
    wireChip(chip, wrap);
  }

  /**
   * Wire the chip click → show/hide select.
   */
  function wireChip(chip, wrap) {
    chip.addEventListener('click', function () {
      var sel = wrap.querySelector('.exp-site-select');
      if (!sel) return;
      sel.removeAttribute('hidden');
      chip.setAttribute('hidden', '');
      sel.focus();
    });
    chip.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        chip.click();
      }
    });
  }

  /**
   * Wire the inline select change → POST.
   */
  function wireSelect(sel, wrap) {
    // Hide select + restore chip on blur without change
    sel.addEventListener('blur', function () {
      // A short delay lets the change event fire first if the user selected something
      setTimeout(function () {
        if (!sel.hasAttribute('hidden')) {
          sel.setAttribute('hidden', '');
          var chip = wrap.querySelector('.exp-site-chip');
          if (chip) chip.removeAttribute('hidden');
        }
      }, 150);
    });

    sel.addEventListener('change', function () {
      var orderId = parseInt(wrap.dataset.orderId || '0', 10);
      var newSite = sel.value; // '' = Automatique, '2' = site id

      if (!orderId) return;

      sel.disabled = true;

      function doRequest(csrf) {
        postSetSite(orderId, newSite === '' ? '' : parseInt(newSite, 10), csrf, function (err, data) {
          sel.disabled = false;
          if (err || !data) {
            alert('Erreur réseau — site non mis à jour.');
            sel.setAttribute('hidden', '');
            var chip = wrap.querySelector('.exp-site-chip');
            if (chip) chip.removeAttribute('hidden');
            return;
          }
          if (!data.ok) {
            if (data.reason === 'expired' && data.csrf) {
              currentCsrf = data.csrf;
              doRequest(currentCsrf);
              return;
            }
            alert('Erreur : ' + (data.error || 'Site non mis à jour.'));
            sel.setAttribute('hidden', '');
            var chip = wrap.querySelector('.exp-site-chip');
            if (chip) chip.removeAttribute('hidden');
            return;
          }
          // Success
          if (data.csrf) currentCsrf = data.csrf;
          applyChip(wrap, data);
        });
      }

      doRequest(currentCsrf);
    });
  }

  /**
   * Wire one .exp-site-chip-wrap.
   */
  function wireWrap(wrap) {
    var chip = wrap.querySelector('.exp-site-chip');
    var sel  = wrap.querySelector('.exp-site-select');
    if (!chip || !sel) return;

    wireChip(chip, wrap);
    wireSelect(sel, wrap);
  }

  // ── Init on DOMContentLoaded ──────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.exp-site-chip-wrap').forEach(wireWrap);
  });
}());
