/**
 * form-resilience.js — Session keepalive + form autosave for operator forms.
 *
 * Loaded globally via topbar.php on every authenticated page.
 *
 * ── Keepalive ────────────────────────────────────────────────────────────────
 * Pings /api/session-ping.php every 5 minutes while the page is VISIBLE.
 * Pauses when document.hidden (Page-Visibility API); resumes + immediate ping
 * on tab becoming visible again. On success, rewrites every input[name="csrf"]
 * on the page so a rotated or rebuilt token stays valid.
 * On 401 (truly expired, no remember-me), shows a non-destructive sticky banner
 * telling the operator to save — does NOT auto-reload (which would wipe fields).
 *
 * ── Autosave ─────────────────────────────────────────────────────────────────
 * FormResilience.initAutosave({ formSelector, storageKey }) — call from the
 * form's own JS (e.g. form-rm-stocktake.js) with a period-specific key.
 * Serialises all named inputs on every input/change event to localStorage.
 * On page load, if a draft exists, restores values and shows a dismissable
 * "Vos saisies ont été restaurées" banner.
 *
 * Clear-on-success logic:
 *   The form sets sessionStorage['form_just_submitted'] = storageKey on submit.
 *   The next page load (after PRG redirect) checks for this marker + an 'ok'
 *   flash banner and clears localStorage if both are present. This is the most
 *   reliable signal that a submit actually landed: PRG means a redirect happened,
 *   and the ok flash means the server confirmed success. Using sessionStorage (not
 *   localStorage) ensures the marker is per-tab and survives the redirect but not
 *   a fresh tab. The draft is NOT cleared on the submit click itself because the
 *   server might still reject it (CSRF fail, validation error) — we only clear
 *   once success is confirmed.
 *
 * Vanilla ES2020, no imports, no dependencies.
 */

(function () {
    'use strict';

    // ── Constants ──────────────────────────────────────────────────────────────
    var PING_INTERVAL_MS = 5 * 60 * 1000; // 5 minutes
    var PING_ENDPOINT    = '/api/session-ping.php';

    // ── Keepalive state ────────────────────────────────────────────────────────
    var pingTimer       = null;
    var expiredWarned   = false;

    // ── Keepalive ──────────────────────────────────────────────────────────────

    function ping() {
        if (document.hidden) return;

        fetch(PING_ENDPOINT, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.ok && data.csrf) {
                // Rewrite every CSRF hidden input on the page.
                // Needed after session-id rotation (15-min regen) or after a
                // destroyed session rebuilt from the mt_remember cookie — both
                // mint a fresh token.
                document.querySelectorAll('input[name="csrf"]').forEach(function (el) {
                    el.value = data.csrf;
                });
                expiredWarned = false; // reset warning flag if session came back
            } else {
                // ok=false or 401: session is truly gone, no remember-me
                showExpiredWarning();
            }
        })
        .catch(function () {
            // Network error — don't show the warning; the tab might just be offline.
            // The next successful ping will recover.
        });
    }

    function schedulePing() {
        if (pingTimer) clearTimeout(pingTimer);
        pingTimer = setTimeout(function () {
            ping();
            schedulePing(); // re-arm
        }, PING_INTERVAL_MS);
    }

    function showExpiredWarning() {
        if (expiredWarned) return;
        expiredWarned = true;

        // Non-destructive banner — does NOT reload; fields stay intact.
        // The autosave draft is also safe in localStorage.
        var banner = document.createElement('div');
        banner.className = 'db-flash db-flash--warn fr-expired-banner';
        banner.setAttribute('role', 'alert');
        banner.innerHTML =
            '<span>⚠ Votre session a expiré. Vos saisies sont préservées dans ce navigateur. '
            + 'Ouvrez un nouvel onglet pour vous reconnecter, puis revenez ici et re-soumettez.</span>'
            + '<button type="button" class="db-flash__dismiss" aria-label="Fermer">✕</button>';

        banner.querySelector('.db-flash__dismiss').addEventListener('click', function () {
            banner.remove();
            expiredWarned = false;
        });

        // Insert at the top of <main>, or before the first .op-form__header / form
        var anchor = document.querySelector('main .op-form__header, main form, main');
        if (anchor && anchor !== document.querySelector('main')) {
            anchor.parentNode.insertBefore(banner, anchor);
        } else if (anchor) {
            anchor.prepend(banner);
        } else {
            document.body.prepend(banner);
        }
    }

    // Page-Visibility: pause when hidden, ping immediately on becoming visible
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (pingTimer) clearTimeout(pingTimer);
            pingTimer = null;
        } else {
            // Tab regained focus — ping immediately then resume schedule
            ping();
            schedulePing();
        }
    });

    // Kick off the first ping schedule
    if (!document.hidden) {
        schedulePing();
    }

    // ── Autosave ───────────────────────────────────────────────────────────────

    var FormResilience = {
        /**
         * initAutosave({ formSelector, storageKey })
         *
         * formSelector — CSS selector for the form (e.g. '#rms-form')
         * storageKey   — localStorage key; include the period so drafts don't
         *                bleed across months (e.g. 'form-rm-stocktake:2026-05')
         *
         * Call this from the form's own deferred JS after the DOM is ready.
         */
        initAutosave: function (opts) {
            var formSel    = opts.formSelector || 'form';
            var storageKey = opts.storageKey;

            if (!storageKey) {
                console.warn('[FormResilience] initAutosave: storageKey requis');
                return;
            }

            var form = document.querySelector(formSel);
            if (!form) return;

            // ── Clear-on-success check ─────────────────────────────────────────
            // On page load: if we just submitted (sessionStorage marker) AND the
            // page is showing an ok flash, the submit landed — clear the draft.
            var justSubmittedKey = sessionStorage.getItem('form_just_submitted');
            if (justSubmittedKey === storageKey) {
                sessionStorage.removeItem('form_just_submitted');
                // Check for an ok flash banner on the page
                var okFlash = document.querySelector('.db-flash--ok');
                if (okFlash) {
                    localStorage.removeItem(storageKey);
                }
                // If there's no ok flash (error redirect), we keep the draft.
            }

            // ── Restore draft ──────────────────────────────────────────────────
            var rawDraft = localStorage.getItem(storageKey);
            if (rawDraft) {
                try {
                    var draft = JSON.parse(rawDraft);
                    var restored = 0;

                    Object.keys(draft).forEach(function (fieldName) {
                        // Use querySelectorAll to handle checkbox/radio groups
                        var els = form.querySelectorAll('[name="' + CSS.escape(fieldName) + '"]');
                        els.forEach(function (el) {
                            if (el.type === 'checkbox' || el.type === 'radio') {
                                el.checked = (el.value === draft[fieldName]);
                            } else {
                                // Only restore if the field currently has no value
                                // (don't overwrite server-preloaded sticky values)
                                if (el.value === '' || el.value === null) {
                                    el.value = draft[fieldName];
                                    restored++;
                                }
                            }
                        });
                    });

                    if (restored > 0) {
                        showRestoreBanner(form, storageKey);
                    }
                } catch (e) {
                    // Corrupt draft — discard silently
                    localStorage.removeItem(storageKey);
                }
            }

            // ── Save on input/change ───────────────────────────────────────────
            form.addEventListener('input', function () { saveDraft(form, storageKey); });
            form.addEventListener('change', function () { saveDraft(form, storageKey); });

            // ── Mark submit intent ─────────────────────────────────────────────
            // Set sessionStorage marker on submit so the next page load knows
            // a submit was attempted. Clear-on-success (above) then decides
            // whether to purge the draft based on the ok flash.
            form.addEventListener('submit', function () {
                sessionStorage.setItem('form_just_submitted', storageKey);
            });
        },
    };

    function saveDraft(form, storageKey) {
        var data = {};
        var elements = form.elements;
        for (var i = 0; i < elements.length; i++) {
            var el = elements[i];
            if (!el.name || el.name === 'csrf') continue; // never persist the CSRF token
            if (el.type === 'submit' || el.type === 'button' || el.type === 'reset') continue;
            if (el.type === 'checkbox' || el.type === 'radio') {
                if (el.checked) data[el.name] = el.value;
            } else {
                data[el.name] = el.value;
            }
        }
        try {
            localStorage.setItem(storageKey, JSON.stringify(data));
        } catch (e) {
            // localStorage full or blocked — degrade silently
        }
    }

    function showRestoreBanner(form, storageKey) {
        var banner = document.createElement('div');
        banner.className = 'db-flash db-flash--warn fr-restore-banner';
        banner.setAttribute('role', 'status');
        banner.innerHTML =
            '<span>↺ Vos saisies ont été restaurées depuis votre dernière session.</span>'
            + '<button type="button" class="db-flash__dismiss fr-restore-dismiss" aria-label="Fermer">✕</button>';

        banner.querySelector('.fr-restore-dismiss').addEventListener('click', function () {
            // Operator acknowledged and wants a clean slate
            localStorage.removeItem(storageKey);
            banner.remove();
        });

        // Insert immediately before the form
        form.parentNode.insertBefore(banner, form);
    }

    // Expose public API
    window.FormResilience = FormResilience;

})();
