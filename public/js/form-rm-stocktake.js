/**
 * form-rm-stocktake.js — Per-pallet RM stocktake form interactions.
 *
 * Responsibilities:
 *   1. Period navigation (Go button → reload page with ?period=YYYY-MM).
 *   2. Type-ahead MI picker (filter over window.RM_MIS).
 *   3. Add flow: POST to /api/rm-stocktake-line-add.php, update ledger live.
 *   4. Delete flow: POST to /api/rm-stocktake-line-delete.php, update ledger live.
 *   5. CSRF refresh-and-retry on expired token (reason: 'expired').
 *
 * Keepalive (from form-resilience.js loaded globally by topbar) remains active.
 * FormResilience.initAutosave is NOT called here — per-line instant-save supersedes it.
 *
 * Vanilla ES2020, no imports. Loaded deferred.
 */

(function () {
    'use strict';

    // ── State from server ──────────────────────────────────────────────────────
    var RM_MIS   = window.RM_MIS   || [];
    var csrf     = window.RMS_CSRF || '';
    var period   = window.RMS_PERIOD || '';

    // ── DOM refs ───────────────────────────────────────────────────────────────
    var periodInput  = document.getElementById('period-input');
    var periodGoBtn  = document.getElementById('rms-period-go');
    var searchInput  = document.getElementById('rms-mi-search');
    var dropdown     = document.getElementById('rms-mi-dropdown');
    var selectedWrap = document.getElementById('rms-selected-mi');
    var selectedName = document.getElementById('rms-selected-name');
    var selectedUnit = document.getElementById('rms-selected-unit');
    var clearMiBtn   = document.getElementById('rms-clear-mi');
    var miFkInput    = document.getElementById('rms-mi-id-fk');
    var qtyWrap      = document.getElementById('rms-qty-wrap');
    var qtyInput     = document.getElementById('rms-qty-input');
    var qtyUnit      = document.getElementById('rms-qty-unit');
    var addBtn       = document.getElementById('rms-add-btn');
    var entryMsg     = document.getElementById('rms-entry-msg');
    var ledger       = document.getElementById('rms-ledger');
    var ledgerRows   = document.getElementById('rms-ledger-rows');
    var ledgerEmpty  = document.getElementById('rms-ledger-empty');
    var grandTotalEl = document.getElementById('rms-grand-total');

    // Selected MI state
    var selectedMi = null; // { id, mi_id, name, unit }

    // ── Helpers ────────────────────────────────────────────────────────────────

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function fmtQty(v) {
        var n = parseFloat(v);
        if (isNaN(n)) return String(v);
        // Show up to 3 decimals, strip trailing zeros
        return parseFloat(n.toFixed(3)).toString();
    }

    function showMsg(text, isError) {
        if (!entryMsg) return;
        entryMsg.textContent = text;
        entryMsg.className = 'rms-entry-msg' + (isError ? ' rms-entry-msg--error' : ' rms-entry-msg--ok');
        entryMsg.hidden = false;
        clearTimeout(entryMsg._timer);
        entryMsg._timer = setTimeout(function () {
            entryMsg.hidden = true;
        }, isError ? 6000 : 3000);
    }

    function setLoading(on) {
        if (addBtn) {
            addBtn.disabled = on;
            addBtn.textContent = on ? '…' : '+ Ajouter';
        }
    }

    // ── Period navigation ──────────────────────────────────────────────────────

    function navigateToPeriod() {
        if (!periodInput) return;
        var val = periodInput.value.trim();
        if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(val)) {
            periodInput.style.borderColor = 'var(--ember)';
            return;
        }
        periodInput.style.borderColor = '';
        window.location.href = '/modules/form-rm-stocktake.php?period=' + encodeURIComponent(val);
    }

    if (periodGoBtn)  periodGoBtn.addEventListener('click', navigateToPeriod);
    if (periodInput) {
        periodInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); navigateToPeriod(); }
        });
        periodInput.addEventListener('change', navigateToPeriod);
    }

    // ── Type-ahead MI picker ───────────────────────────────────────────────────

    function filterMis(query) {
        if (!query) return [];
        var q = query.toLowerCase().trim();
        return RM_MIS.filter(function (m) {
            return m.name.toLowerCase().includes(q) || m.mi_id.toLowerCase().includes(q);
        }).slice(0, 10);
    }

    function renderDropdown(results) {
        if (!dropdown) return;
        if (!results.length) {
            dropdown.hidden = true;
            dropdown.innerHTML = '';
            return;
        }
        dropdown.innerHTML = results.map(function (m) {
            return '<li role="option" tabindex="-1" data-id="' + m.id + '">'
                + '<span class="rms-dd-name">' + escHtml(m.name) + '</span>'
                + '<span class="rms-dd-id">' + escHtml(m.mi_id) + '</span>'
                + '</li>';
        }).join('');
        dropdown.hidden = false;
    }

    function selectMi(mi) {
        selectedMi = mi;
        if (miFkInput)    miFkInput.value = String(mi.id);
        if (selectedName) selectedName.textContent = mi.name;
        if (selectedUnit) selectedUnit.textContent = mi.unit || '';
        if (qtyUnit)      qtyUnit.textContent = mi.unit || '';

        if (searchInput)  { searchInput.value = ''; searchInput.hidden = true; }
        if (dropdown)     { dropdown.hidden = true; dropdown.innerHTML = ''; }
        if (selectedWrap) selectedWrap.hidden = false;
        if (qtyWrap)      qtyWrap.hidden = false;
        if (addBtn)       addBtn.hidden = false;

        if (qtyInput) {
            qtyInput.value = '';
            qtyInput.focus();
        }
    }

    function clearMi() {
        selectedMi = null;
        if (miFkInput)    miFkInput.value = '';
        if (selectedWrap) selectedWrap.hidden = true;
        if (qtyWrap)      qtyWrap.hidden = true;
        if (addBtn)       addBtn.hidden = true;
        if (searchInput)  { searchInput.hidden = false; searchInput.value = ''; searchInput.focus(); }
        if (dropdown)     { dropdown.hidden = true; dropdown.innerHTML = ''; }
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            renderDropdown(filterMis(searchInput.value));
        });

        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' && dropdown && !dropdown.hidden) {
                var first = dropdown.querySelector('li');
                if (first) { e.preventDefault(); first.focus(); }
            }
            if (e.key === 'Escape') {
                dropdown.hidden = true;
                dropdown.innerHTML = '';
            }
        });

        // Close dropdown on outside click
        document.addEventListener('click', function (e) {
            if (!searchInput.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
                dropdown.hidden = true;
            }
        });
    }

    if (dropdown) {
        dropdown.addEventListener('click', function (e) {
            var li = e.target.closest('li[data-id]');
            if (!li) return;
            var id = parseInt(li.dataset.id, 10);
            var mi = RM_MIS.find(function (m) { return m.id === id; });
            if (mi) selectMi(mi);
        });

        dropdown.addEventListener('keydown', function (e) {
            var items = Array.from(dropdown.querySelectorAll('li'));
            var idx   = items.indexOf(document.activeElement);

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (idx < items.length - 1) items[idx + 1].focus();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (idx > 0) items[idx - 1].focus();
                else if (searchInput) searchInput.focus();
            } else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                var li = document.activeElement.closest('li[data-id]');
                if (li) {
                    var id = parseInt(li.dataset.id, 10);
                    var mi = RM_MIS.find(function (m) { return m.id === id; });
                    if (mi) selectMi(mi);
                }
            } else if (e.key === 'Escape') {
                dropdown.hidden = true;
                if (searchInput) searchInput.focus();
            }
        });
    }

    if (clearMiBtn) clearMiBtn.addEventListener('click', clearMi);

    // Allow Enter in qty input to trigger Add
    if (qtyInput) {
        qtyInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); doAdd(); }
        });
    }

    // ── Add line (fetch POST with CSRF-retry) ──────────────────────────────────

    function postJson(url, body) {
        var fd = new FormData();
        Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
        return fetch(url, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); });
    }

    function doAdd(retried) {
        if (!selectedMi) {
            showMsg('Sélectionnez d\'abord un ingrédient.', true);
            return;
        }
        if (!qtyInput) return;

        var qtyRaw = qtyInput.value.trim().replace(',', '.');
        if (qtyRaw === '' || isNaN(parseFloat(qtyRaw)) || parseFloat(qtyRaw) < 0) {
            showMsg('Quantité invalide (nombre ≥ 0 attendu).', true);
            qtyInput.focus();
            return;
        }

        setLoading(true);

        postJson('/api/rm-stocktake-line-add.php', {
            csrf:      csrf,
            period:    period,
            mi_id_fk:  selectedMi.id,
            qty:       qtyRaw,
        }).then(function (data) {
            setLoading(false);

            // CSRF expired — refresh token and retry once
            if (!data.ok && data.reason === 'expired' && !retried) {
                csrf = data.csrf || csrf;
                updateAllCsrfInputs(csrf);
                doAdd(true);
                return;
            }

            if (!data.ok) {
                showMsg(data.error || 'Erreur lors de l\'ajout.', true);
                return;
            }

            // Update CSRF from response
            if (data.csrf) {
                csrf = data.csrf;
                updateAllCsrfInputs(csrf);
            }

            // Update ledger
            applyLineAdded(data);

            // Reset entry zone
            qtyInput.value = '';
            clearMi();
            showMsg('Ajouté : ' + fmtQty(data.line.qty) + ' — ' + escHtml(data.line.mi_name) + '.', false);

        }).catch(function () {
            setLoading(false);
            showMsg('Erreur réseau. Réessayez.', true);
        });
    }

    if (addBtn) addBtn.addEventListener('click', function () { doAdd(false); });

    // ── Delete line ────────────────────────────────────────────────────────────

    function doDelete(lineId, miId, retried) {
        postJson('/api/rm-stocktake-line-delete.php', {
            csrf:    csrf,
            line_id: lineId,
            period:  period,
        }).then(function (data) {
            if (!data.ok && data.reason === 'expired' && !retried) {
                csrf = data.csrf || csrf;
                updateAllCsrfInputs(csrf);
                doDelete(lineId, miId, true);
                return;
            }
            if (!data.ok) {
                showMsg(data.error || 'Erreur lors de la suppression.', true);
                return;
            }
            if (data.csrf) {
                csrf = data.csrf;
                updateAllCsrfInputs(csrf);
            }
            applyLineDeleted(lineId, miId, data);
        }).catch(function () {
            showMsg('Erreur réseau. Réessayez.', true);
        });
    }

    // Delegate delete clicks on ledger chips
    if (ledgerRows) {
        ledgerRows.addEventListener('click', function (e) {
            var btn = e.target.closest('.rms-chip-del');
            if (!btn) return;
            var chip  = btn.closest('.rms-chip[data-line-id]');
            if (!chip) return;
            var lineId = parseInt(chip.dataset.lineId, 10);
            var miId   = chip.dataset.miId || '';
            doDelete(lineId, miId, false);
        });
    }

    // ── Ledger DOM updates ─────────────────────────────────────────────────────

    function applyLineAdded(data) {
        var miId     = data.line ? data.line.mi_name : '';
        var lineMiId = null;
        // Find mi_id string from lines_for_mi (they share mi_id from the response)
        // We injected mi_id_fk — look it up from RM_MIS
        var miEntry = RM_MIS.find(function (m) { return m.id === data.line.mi_id_fk; });
        var miIdStr = miEntry ? miEntry.mi_id : null;

        if (!ledgerRows || !miIdStr) {
            // Fallback: full page reload (rare)
            window.location.reload();
            return;
        }

        // Find or create the MI group in the ledger
        var group = ledgerRows.querySelector('.rms-ledger-mi[data-mi-id="' + CSS.escape(miIdStr) + '"]');
        if (!group) {
            group = buildMiGroup(miIdStr, data.line.mi_name, miEntry ? miEntry.unit : '');
            ledgerRows.appendChild(group);
        }

        // Add the new chip
        var chips = group.querySelector('.rms-ledger-chips');
        if (chips) {
            var chip = document.createElement('span');
            chip.className  = 'rms-chip';
            chip.dataset.lineId = String(data.line.id);
            chip.dataset.miId   = miIdStr;
            chip.innerHTML = escHtml(fmtQty(data.line.qty))
                + '<button type="button" class="rms-chip-del" aria-label="Supprimer cette ligne">✕</button>';
            chips.appendChild(chip);
        }

        // Update subtotal
        var subEl = document.getElementById('sub_' + miIdStr);
        if (subEl) {
            var unit = miEntry ? miEntry.unit : '';
            subEl.innerHTML = escHtml(fmtQty(String(data.mi_subtotal)))
                + ' <span class="rms-ledger-mi-unit">' + escHtml(unit) + '</span>';
        }

        // Update grand total
        if (grandTotalEl) grandTotalEl.textContent = fmtQty(String(data.grand_total));

        // Hide empty state
        if (ledgerEmpty) ledgerEmpty.hidden = true;
    }

    function applyLineDeleted(lineId, miIdStr, data) {
        if (!ledgerRows) return;

        // Remove the chip
        var chip = ledgerRows.querySelector('.rms-chip[data-line-id="' + lineId + '"]');
        if (chip) chip.remove();

        // Update subtotal
        var subEl = document.getElementById('sub_' + miIdStr);
        var miEntry = RM_MIS.find(function (m) { return m.mi_id === miIdStr; });
        var unit = miEntry ? miEntry.unit : '';

        if (data.mi_subtotal === 0) {
            // No more lines for this MI — remove the group
            var group = ledgerRows.querySelector('.rms-ledger-mi[data-mi-id="' + CSS.escape(miIdStr) + '"]');
            if (group) group.remove();
        } else {
            if (subEl) {
                subEl.innerHTML = escHtml(fmtQty(String(data.mi_subtotal)))
                    + ' <span class="rms-ledger-mi-unit">' + escHtml(unit) + '</span>';
            }
        }

        // Update grand total
        if (grandTotalEl) grandTotalEl.textContent = fmtQty(String(data.grand_total));

        // Show empty state if no more rows
        if (ledgerRows && ledgerEmpty) {
            var hasRows = ledgerRows.querySelectorAll('.rms-ledger-mi').length > 0;
            ledgerEmpty.hidden = hasRows;
        }
    }

    function buildMiGroup(miIdStr, miName, unit) {
        var div = document.createElement('div');
        div.className = 'rms-ledger-mi';
        div.dataset.miId = miIdStr;
        div.innerHTML = '<div class="rms-ledger-mi-header">'
            + '<span class="rms-ledger-mi-name">' + escHtml(miName) + '</span>'
            + '<span class="rms-ledger-mi-subtotal" id="sub_' + escHtml(miIdStr) + '">'
            +   '0 <span class="rms-ledger-mi-unit">' + escHtml(unit) + '</span>'
            + '</span>'
            + '</div>'
            + '<div class="rms-ledger-chips" id="chips_' + escHtml(miIdStr) + '"></div>';
        return div;
    }

    // ── CSRF helpers ───────────────────────────────────────────────────────────

    function updateAllCsrfInputs(token) {
        // The global keepalive (form-resilience.js) already rewrites all
        // input[name="csrf"] on ping. Sync our local variable to match.
        csrf = token;
        document.querySelectorAll('input[name="csrf"]').forEach(function (el) {
            el.value = token;
        });
    }

})();
