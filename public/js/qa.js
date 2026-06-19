/**
 * qa.js — QA/QC controls page interactions.
 *
 * Three async form handlers: net-content, cleaning-efficacy, bottle-reception.
 * Mirrors form-rm-stocktake.js fetch/CSRF/envelope pattern exactly.
 *
 * Envelope expected from each API endpoint:
 *   success: { ok: true, csrf: '...', reading|check: { ... } }
 *   failure: { ok: false, error: 'message', csrf: '...' }
 *   expired: { ok: false, reason: 'expired', csrf: '...' }
 *
 * Vanilla ES2020, no imports. Loaded deferred.
 */
(function () {
    'use strict';

    // ── State from server ──────────────────────────────────────────────────────
    var csrf = (window.QA && window.QA.csrf) ? window.QA.csrf : '';

    // ── Helpers ────────────────────────────────────────────────────────────────

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function updateAllCsrfInputs(token) {
        csrf = token;
        document.querySelectorAll('input[name="csrf"]').forEach(function (el) {
            el.value = token;
        });
        if (window.QA) window.QA.csrf = token;
    }

    function showMsg(msgEl, text, isErr) {
        if (!msgEl) return;
        msgEl.textContent = text;
        msgEl.className = 'qa-inline-msg ' + (isErr ? 'qa-inline-msg--err' : 'qa-inline-msg--ok');
        msgEl.hidden = false;
        clearTimeout(msgEl._t);
        if (!isErr) {
            msgEl._t = setTimeout(function () { msgEl.hidden = true; }, 4000);
        }
    }

    function postForm(url, formEl) {
        var fd = new FormData(formEl);
        fd.set('csrf', csrf);
        return fetch(url, { method: 'POST', body: fd }).then(function (r) {
            return r.json();
        });
    }

    // Set a datetime-local input to the current local time (YYYY-MM-DDTHH:MM)
    function setNow(id) {
        var el = document.getElementById(id);
        if (!el) return;
        var now = new Date();
        var y   = now.getFullYear();
        var mo  = String(now.getMonth() + 1).padStart(2, '0');
        var d   = String(now.getDate()).padStart(2, '0');
        var h   = String(now.getHours()).padStart(2, '0');
        var mi  = String(now.getMinutes()).padStart(2, '0');
        el.value = y + '-' + mo + '-' + d + 'T' + h + ':' + mi;
    }

    // ── Unit label synchronisation ─────────────────────────────────────────────

    function syncUnitsImmediate(typeSelId, unitIds) {
        var sel = document.getElementById(typeSelId);
        if (!sel) return;
        var u = sel.value === 'volume' ? 'mL' : 'g';
        unitIds.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.textContent = u;
        });
    }

    function syncUnits(typeSelId, unitIds) {
        var sel = document.getElementById(typeSelId);
        if (!sel) return;
        sel.addEventListener('change', function () {
            syncUnitsImmediate(typeSelId, unitIds);
        });
        syncUnitsImmediate(typeSelId, unitIds);
    }

    // ── Outcome / conform helpers ──────────────────────────────────────────────

    function outcomeClass(outcome) {
        var map = {
            pass:     'qa-outcome-pass',
            fail:     'qa-outcome-fail',
            marginal: 'qa-outcome-marginal',
            pending:  'qa-outcome-pending',
        };
        return map[outcome] || 'qa-outcome-pending';
    }

    function outcomeLabel(outcome) {
        var map = {
            pass:     'Conforme',
            fail:     'Non conforme',
            marginal: 'Marginal',
            pending:  'En attente',
        };
        return map[outcome] || escHtml(outcome);
    }

    function conformClass(v) {
        if (v === null || v === undefined) return 'qa-conform-na';
        return v ? 'qa-conform-ok' : 'qa-conform-fail';
    }

    function conformLabel(v) {
        if (v === null || v === undefined) return '—';
        return v ? '✓' : '✗';
    }

    function methodLabel(m) {
        var map = {
            atp:         'ATP (luminométrie)',
            swab:        'Écouvillonnage',
            visual:      'Visuel',
            rinse_water: 'Eau de rinçage',
        };
        return map[m] || escHtml(m);
    }

    // ── Table row insertion ────────────────────────────────────────────────────

    function prependRow(tbody, trHtml) {
        if (!tbody) return;
        var tr = document.createElement('tr');
        tr.innerHTML = trHtml;
        if (tbody.firstChild) {
            tbody.insertBefore(tr, tbody.firstChild);
        } else {
            tbody.appendChild(tr);
        }
        // Keep at most 20 rows
        var rows = tbody.querySelectorAll('tr');
        if (rows.length > 20) {
            tbody.removeChild(rows[rows.length - 1]);
        }
    }

    // ── Generic form submit factory ────────────────────────────────────────────

    function wireForm(formId, submitBtnId, msgId, tbodyId, onSuccess) {
        var form   = document.getElementById(formId);
        var btn    = document.getElementById(submitBtnId);
        var msgEl  = document.getElementById(msgId);
        var tbody  = document.getElementById(tbodyId);
        if (!form) return;

        var origText = btn ? btn.textContent.trim() : 'Enregistrer';

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            doSubmit(false);
        });

        function doSubmit(retried) {
            if (btn) {
                btn.disabled    = true;
                btn.textContent = 'Enregistrement…';
            }
            postForm(form.action, form).then(function (data) {
                if (btn) {
                    btn.disabled    = false;
                    btn.textContent = origText;
                }
                // CSRF expired — refresh token and retry once
                if (!data.ok && data.reason === 'expired' && !retried) {
                    updateAllCsrfInputs(data.csrf || csrf);
                    doSubmit(true);
                    return;
                }
                // Rotate CSRF on every response if provided
                if (data.csrf) updateAllCsrfInputs(data.csrf);

                if (!data.ok) {
                    showMsg(msgEl, data.error || "Erreur lors de l'enregistrement.", true);
                    return;
                }

                showMsg(msgEl, 'Enregistré.', false);

                if (tbody && onSuccess) onSuccess(tbody, data);

                // Reset form, preserving date/datetime-local values
                var dateFields = {};
                form.querySelectorAll('input[type="date"], input[type="datetime-local"]').forEach(function (f) {
                    dateFields[f.name] = f.value;
                });
                form.reset();
                Object.keys(dateFields).forEach(function (name) {
                    var f = form.querySelector('[name="' + name + '"]');
                    if (f) f.value = dateFields[name];
                });

                // Re-initialise datetime-local to now
                if (formId === 'qa-form-net')  setNow('net-at');
                if (formId === 'qa-form-cip')  setNow('cip-measured-at');
                if (formId === 'qa-form-eau')  setNow('eau-at');

                // Re-sync unit labels after reset
                if (formId === 'qa-form-net') {
                    syncUnitsImmediate('net-type', ['net-val-unit', 'net-target-unit', 'net-tol-unit', 'net-tare-unit']);
                }
                if (formId === 'qa-form-recep') {
                    syncUnitsImmediate('recep-type', ['recep-val-unit', 'recep-target-unit', 'recep-tol-unit']);
                }
            }).catch(function () {
                if (btn) {
                    btn.disabled    = false;
                    btn.textContent = origText;
                }
                showMsg(msgEl, 'Erreur réseau. Réessayez.', true);
            });
        }
    }

    // ── Initialise on load ─────────────────────────────────────────────────────

    // Default datetime-local fields to current time
    setNow('net-at');
    setNow('cip-measured-at');

    // Wire unit label updaters
    syncUnits('net-type',   ['net-val-unit', 'net-target-unit', 'net-tol-unit', 'net-tare-unit']);
    syncUnits('recep-type', ['recep-val-unit', 'recep-target-unit', 'recep-tol-unit']);

    // ── Panel A — Net content ──────────────────────────────────────────────────

    wireForm('qa-form-net', 'qa-net-submit', 'qa-net-msg', 'qa-net-tbody', function (tbody, data) {
        var r       = data.reading || {};
        var measAt  = r.measured_at ? escHtml(String(r.measured_at).substring(0, 16)) : '—';
        var pkgLabel = escHtml(r.pkg_label || '');
        var typeLabel = r.measure_type === 'volume' ? 'Volume' : 'Poids';
        var unit    = r.measure_type === 'volume' ? 'mL' : 'g';
        var measVal = (r.measured_value !== null && r.measured_value !== undefined)
            ? escHtml(parseFloat(r.measured_value).toFixed(3).replace('.', ',')) + ' ' + unit : '—';
        var targVal = (r.target_value !== null && r.target_value !== undefined)
            ? escHtml(parseFloat(r.target_value).toFixed(3).replace('.', ',')) : '—';
        var tolVal  = (r.tolerance_abs !== null && r.tolerance_abs !== undefined)
            ? '±' + escHtml(parseFloat(r.tolerance_abs).toFixed(3).replace('.', ',')) : '—';
        var isCon   = (r.is_conforming !== null && r.is_conforming !== undefined) ? !!r.is_conforming : null;

        prependRow(tbody,
            '<td class="qa-mono">' + measAt + '</td>' +
            '<td>' + pkgLabel + '</td>' +
            '<td class="qa-mono">' + escHtml(String(r.reading_seq || 1)) + '</td>' +
            '<td>' + typeLabel + '</td>' +
            '<td class="qa-mono">' + measVal + '</td>' +
            '<td class="qa-mono">' + targVal + '</td>' +
            '<td class="qa-mono">' + tolVal + '</td>' +
            '<td><span class="qa-conform ' + conformClass(isCon) + '">' + conformLabel(isCon) + '</span></td>' +
            '<td class="qa-comment">' + escHtml(r.comments || '') + '</td>'
        );
    });

    // ── Panel B — Cleaning efficacy ────────────────────────────────────────────

    wireForm('qa-form-cip', 'qa-cip-submit', 'qa-cip-msg', 'qa-cip-tbody', function (tbody, data) {
        var r      = data.check || {};
        var resVal = (r.result_value !== null && r.result_value !== undefined)
            ? escHtml(parseFloat(r.result_value).toFixed(2).replace('.', ','))
              + (r.result_unit ? ' ' + escHtml(r.result_unit) : '')
            : '—';
        var thrVal = (r.threshold_value !== null && r.threshold_value !== undefined)
            ? escHtml(parseFloat(r.threshold_value).toFixed(2).replace('.', ',')) : '—';

        prependRow(tbody,
            '<td class="qa-mono">' + escHtml(r.check_date || '—') + '</td>' +
            '<td>' + methodLabel(r.method || '') + '</td>' +
            '<td>' + escHtml(r.surface_label || '—') + '</td>' +
            '<td class="qa-mono">' + resVal + '</td>' +
            '<td class="qa-mono">' + thrVal + '</td>' +
            '<td><span class="qa-outcome ' + outcomeClass(r.outcome || 'pending') + '">' + outcomeLabel(r.outcome || 'pending') + '</span></td>' +
            '<td class="qa-comment">' + escHtml(r.corrective_action || '') + '</td>'
        );
    });

    // ── Panel C — Bottle reception ─────────────────────────────────────────────

    wireForm('qa-form-recep', 'qa-recep-submit', 'qa-recep-msg', 'qa-recep-tbody', function (tbody, data) {
        var r         = data.check || {};
        var unit      = r.measure_type === 'volume' ? 'mL' : 'g';
        var typeLabel = r.measure_type === 'volume' ? 'Volume' : 'Poids';
        var measVal   = (r.measured_value !== null && r.measured_value !== undefined)
            ? escHtml(parseFloat(r.measured_value).toFixed(3).replace('.', ',')) + ' ' + unit : '—';
        var targVal   = (r.target_value !== null && r.target_value !== undefined)
            ? escHtml(parseFloat(r.target_value).toFixed(3).replace('.', ',')) : '—';
        var sampleStr = (r.sample_size !== null && r.sample_size !== undefined)
            ? escHtml(String(r.sample_size)) : '—';

        prependRow(tbody,
            '<td class="qa-mono">' + escHtml(r.reception_date || '—') + '</td>' +
            '<td>' + escHtml(r.mi_name || '—') + '</td>' +
            '<td class="qa-mono">' + escHtml(r.lot_ref || '—') + '</td>' +
            '<td>' + typeLabel + '</td>' +
            '<td class="qa-mono">' + sampleStr + '</td>' +
            '<td class="qa-mono">' + measVal + '</td>' +
            '<td class="qa-mono">' + targVal + '</td>' +
            '<td><span class="qa-outcome ' + outcomeClass(r.outcome || 'pass') + '">' + outcomeLabel(r.outcome || 'pass') + '</span></td>'
        );
    });

    // ── Panel D — Water analysis param toggle ──────────────────────────────────

    function initEauParamToggle() {
        var paramSel  = document.getElementById('eau-param');
        var numWrap   = document.getElementById('eau-num-wrap');
        var paWrap    = document.getElementById('eau-pa-wrap');
        var numInput  = document.getElementById('eau-val');
        var paInput   = document.getElementById('eau-pa');
        var unitSpan  = document.getElementById('eau-val-unit');
        var hintSpan  = document.getElementById('eau-limit-hint');
        if (!paramSel) return;

        function applyParam() {
            var id = paramSel.value;
            var params = (window.QA_WATER_PARAMS && id) ? window.QA_WATER_PARAMS[id] : null;
            if (!params) {
                // No selection — show numeric, clear decorations
                numWrap.hidden = false;
                paWrap.hidden  = true;
                numInput.required = true;
                paInput.required  = false;
                if (unitSpan) unitSpan.textContent = '';
                if (hintSpan) hintSpan.textContent = '';
                return;
            }
            var isPA = params.limit_operator === 'presence_absence';
            numWrap.hidden = isPA;
            paWrap.hidden  = !isPA;
            numInput.required = !isPA;
            paInput.required  = isPA;

            // Unit suffix
            if (unitSpan) unitSpan.textContent = params.unit ? params.unit : '';

            // Limit hint
            if (hintSpan) {
                var hint = '';
                if (isPA) {
                    hint = '';
                } else if (params.limit_min !== null && params.limit_max !== null) {
                    hint = 'Limite : ' + params.limit_min + '–' + params.limit_max
                        + (params.unit ? ' ' + escHtml(params.unit) : '');
                } else if (params.limit_max !== null) {
                    hint = 'Limite : ≤ ' + params.limit_max
                        + (params.unit ? ' ' + escHtml(params.unit) : '');
                } else if (params.limit_min !== null) {
                    hint = 'Limite : ≥ ' + params.limit_min
                        + (params.unit ? ' ' + escHtml(params.unit) : '');
                } else if (params.limit_basis) {
                    hint = escHtml(params.limit_basis) + ' (à confirmer)';
                }
                hintSpan.textContent = hint;
            }
        }

        paramSel.addEventListener('change', applyParam);
        applyParam(); // run on init to match default-selected state
    }

    initEauParamToggle();

    // Default sampled_at to now
    setNow('eau-at');

    // ── Panel D — Water analysis submit ────────────────────────────────────────

    wireForm('qa-form-eau', 'qa-eau-submit', 'qa-eau-msg', 'qa-eau-tbody', function (tbody, data) {
        // Handle duplicate
        if (data.duplicate) {
            showMsg(document.getElementById('qa-eau-msg'), 'Déjà enregistré (doublon détecté).', false);
            return;
        }
        var r = data.analysis || {};

        // Result display
        var resultStr;
        if (r.measured_value !== null && r.measured_value !== undefined) {
            resultStr = escHtml(parseFloat(r.measured_value).toFixed(4).replace('.', ','));
            if (r.unit) resultStr += ' ' + escHtml(r.unit);
        } else if (r.measured_text) {
            resultStr = escHtml(r.measured_text);
        } else {
            resultStr = '—';
        }

        // Conformity badge
        var isCon = (r.is_conforming !== null && r.is_conforming !== undefined) ? !!r.is_conforming : null;
        var conBadge = '<span class="qa-conform ' + conformClass(isCon) + '">' + conformLabel(isCon) + '</span>';

        var limitStr = r.action_limit ? escHtml(r.action_limit) : '—';
        var sampledAt = r.sampled_at ? escHtml(String(r.sampled_at).substring(0, 16)) : '—';
        var spLabel = r.sp_code ? escHtml(r.sp_code + ' — ' + (r.sp_label || '')) : '—';
        var pLabel  = r.p_label ? escHtml(r.p_label) : '—';

        prependRow(tbody,
            '<td class="qa-mono">' + sampledAt + '</td>' +
            '<td>' + spLabel + '</td>' +
            '<td>' + pLabel + '</td>' +
            '<td class="qa-mono">' + resultStr + '</td>' +
            '<td class="qa-mono qa-comment">' + limitStr + '</td>' +
            '<td>' + conBadge + '</td>' +
            '<td class="qa-comment">' + escHtml(r.lab_name || '—') + '</td>' +
            '<td class="qa-mono qa-comment">' + escHtml(r.report_ref || '—') + '</td>'
        );

        // Re-init toggle after form reset
        initEauParamToggle();
    });

})();
