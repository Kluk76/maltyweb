/**
 * form-framework.js — Shared JS for operator input forms.
 *
 * Provides:
 *   FormFramework.init(config)       — wire up a form with soft-validation, diff-preview,
 *                                      localStorage draft, and unit labels.
 *   FormFramework.setThresholds(map) — hot-swap the active threshold map and re-render.
 *   FormFramework.refreshWarnings()  — re-run collectWarnings (incl. extraWarnings) +
 *                                      renderWarnings; call when non-threshold inputs change.
 *
 * config = {
 *   formId:        string           — <form> element id
 *   draftKey:      string           — localStorage key for draft persistence
 *   thresholds:    object           — { fieldName: { label, unit, warn:[lo,hi], outlier:[lo,hi] } }
 *   diffFields:    string[]         — fields to show in diff-preview panel
 *   onWarnings:    fn(warnings[])   — called with human-readable warning strings
 *   extraWarnings: fn() => Array    — optional; returns additional warning objects
 *                                    each may carry { message, level, commentTarget? }
 *                                    commentTarget: field name to inject the dialog comment into
 *                                    (defaults to 'fw_comment' when absent)
 * }
 *
 * Comment routing (submit dialog):
 *   - requireComment fires when ANY warning has level 'outlier' (existing behaviour).
 *   - When confirming with a comment, the target field is chosen as follows:
 *       1. If any outlier warning has commentTarget === 'loss_note' AND the form has a
 *          #loss_note element with non-empty id → inject into loss_note (loss context dominates).
 *       2. Otherwise → inject into the hidden fw_comment field (existing default).
 *   - A target that already has text is NOT overwritten (no-clobber rule).
 *   - Brewing and packaging forms pass no extraWarnings → all behaviour unchanged.
 *
 * No framework dependency. Vanilla ES2020.
 */

const FormFramework = (() => {

  // ── Draft persistence ────────────────────────────────────────────────────
  function saveDraft(form, key) {
    const data = {};
    for (const el of form.elements) {
      if (!el.name || el.type === 'hidden' || el.type === 'submit') continue;
      data[el.name] = el.value;
    }
    try { localStorage.setItem(key, JSON.stringify(data)); } catch (_) {}
  }

  function loadDraft(form, key) {
    let raw;
    try { raw = localStorage.getItem(key); } catch (_) { return; }
    if (!raw) return;
    let data;
    try { data = JSON.parse(raw); } catch (_) { return; }
    for (const [k, v] of Object.entries(data)) {
      const el = form.elements.namedItem(k);
      if (!el || el.type === 'hidden' || el.type === 'submit') continue;
      el.value = v;
    }
  }

  function clearDraft(key) {
    try { localStorage.removeItem(key); } catch (_) {}
  }

  // ── Threshold evaluation ─────────────────────────────────────────────────
  function evaluateField(name, rawVal, threshold) {
    if (rawVal === '' || rawVal === null || rawVal === undefined) return null;
    const v = parseFloat(String(rawVal).replace(',', '.'));
    if (isNaN(v)) return null;

    const [lo, hi] = threshold.outlier ?? [-Infinity, Infinity];
    const [wLo, wHi] = threshold.warn ?? [-Infinity, Infinity];

    let level = 'normal';
    if (v < lo || v > hi) {
      level = 'outlier';
    } else if (v < wLo || v > wHi) {
      level = 'elevated';
    }

    if (level === 'normal') return null;

    const unit = threshold.unit ?? '';
    const label = threshold.label ?? name;
    const [normLo, normHi] = threshold.warn ?? threshold.outlier ?? [wLo, wHi];
    return {
      field: name,
      level,
      message: level === 'outlier'
        ? `${label} ${v}${unit} est hors de la plage physique (${lo}–${hi}${unit}).`
        : `${label} ${v}${unit} est inhabituel — plage normale: ${normLo}–${normHi}${unit}.`,
    };
  }

  // ── Swap detection ───────────────────────────────────────────────────────
  // Pass pairs = [[nameA, nameB, rangeA, rangeB], ...] e.g. [['gravity','ph',[14,22],[3.8,5.5]]]
  function detectSwaps(form, pairs) {
    const warnings = [];
    for (const [a, b, rangeA, rangeB] of pairs) {
      const elA = form.elements.namedItem(a);
      const elB = form.elements.namedItem(b);
      if (!elA || !elB) continue;
      const va = parseFloat(elA.value);
      const vb = parseFloat(elB.value);
      if (isNaN(va) || isNaN(vb)) continue;
      const aInA = va >= rangeA[0] && va <= rangeA[1];
      const bInB = vb >= rangeB[0] && vb <= rangeB[1];
      const aInB = va >= rangeB[0] && va <= rangeB[1];
      const bInA = vb >= rangeA[0] && vb <= rangeA[1];
      if (!aInA && !bInB && aInB && bInA) {
        warnings.push({
          field: a,
          level: 'outlier',
          message: `Les champs "${a}" et "${b}" semblent échangés — vérifiez la saisie.`,
        });
      }
    }
    return warnings;
  }

  // ── Diff preview ─────────────────────────────────────────────────────────
  function buildDiff(form, fields, labels) {
    const diffs = [];
    for (const name of fields) {
      const el = form.elements.namedItem(name);
      const original = el ? (el.dataset.original ?? '') : '';
      const current  = el ? el.value : '';
      if (current !== original) {
        diffs.push({ label: labels[name] ?? name, from: original, to: current });
      }
    }
    return diffs;
  }

  // ── Warning panel ────────────────────────────────────────────────────────
  function renderWarnings(panel, warnings) {
    if (!panel) return;
    if (!warnings.length) {
      panel.hidden = true;
      panel.innerHTML = '';
      return;
    }
    panel.hidden = false;
    panel.innerHTML = '<ul>' + warnings.map(w =>
      `<li class="fw-warn fw-warn--${w.level}">${escHtml(w.message)}</li>`
    ).join('') + '</ul>';
  }

  function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Diff dialog ───────────────────────────────────────────────────────────
  function showDiffDialog(diffs, warnings, onConfirm, requireComment) {
    const existing = document.getElementById('fw-diff-dialog');
    if (existing) existing.remove();

    const hasOutlier = warnings.some(w => w.level === 'outlier');

    const dlg = document.createElement('div');
    dlg.id = 'fw-diff-dialog';
    dlg.setAttribute('role', 'dialog');
    dlg.setAttribute('aria-modal', 'true');
    dlg.setAttribute('aria-label', 'Confirmer la saisie');

    let diffHtml = '';
    if (diffs.length) {
      diffHtml = '<div class="fw-dialog__section"><div class="fw-dialog__section-title">Modifications</div><table class="fw-diff-table"><tbody>'
        + diffs.map(d =>
            `<tr><td class="fw-diff-label">${escHtml(d.label)}</td>`
            + `<td class="fw-diff-from">${d.from !== '' ? escHtml(d.from) : '<em class="fw-empty">vide</em>'}</td>`
            + `<td class="fw-diff-arrow">→</td>`
            + `<td class="fw-diff-to">${escHtml(d.to)}</td></tr>`
          ).join('')
        + '</tbody></table></div>';
    } else {
      diffHtml = '<div class="fw-dialog__section"><p class="fw-dialog__nodiff">Aucune modification détectée.</p></div>';
    }

    let warnHtml = '';
    if (warnings.length) {
      warnHtml = '<div class="fw-dialog__section fw-dialog__section--warn">'
        + '<div class="fw-dialog__section-title fw-dialog__section-title--warn">Avertissements QC</div>'
        + '<ul>' + warnings.map(w =>
            `<li class="fw-warn fw-warn--${w.level}">${escHtml(w.message)}</li>`
          ).join('') + '</ul></div>';
    }

    const commentBlock = (hasOutlier || requireComment)
      ? `<div class="fw-dialog__section">
           <label class="fw-dialog__comment-label">
             Commentaire <span class="fw-required">${hasOutlier ? '(obligatoire — valeur outlier)' : '(optionnel)'}</span>
           </label>
           <textarea id="fw-comment" class="fw-dialog__textarea" rows="3" placeholder="Contexte de la mesure…" ${hasOutlier ? 'required' : ''}></textarea>
         </div>`
      : '';

    dlg.innerHTML = `
      <div class="fw-dialog__backdrop"></div>
      <div class="fw-dialog__box">
        <div class="fw-dialog__header">
          <span class="fw-dialog__title">Confirmer la saisie</span>
          <button type="button" class="fw-dialog__close" aria-label="Fermer">✕</button>
        </div>
        ${diffHtml}
        ${warnHtml}
        ${commentBlock}
        <div class="fw-dialog__footer">
          <button type="button" class="fw-dialog__btn fw-dialog__btn--cancel">Corriger</button>
          <button type="button" class="fw-dialog__btn fw-dialog__btn--confirm">Oui, enregistrer</button>
        </div>
      </div>`;

    document.body.appendChild(dlg);

    // Wire close
    dlg.querySelector('.fw-dialog__close').addEventListener('click', () => dlg.remove());
    dlg.querySelector('.fw-dialog__btn--cancel').addEventListener('click', () => dlg.remove());
    dlg.querySelector('.fw-dialog__backdrop').addEventListener('click', () => dlg.remove());

    const confirmBtn = dlg.querySelector('.fw-dialog__btn--confirm');
    confirmBtn.addEventListener('click', () => {
      const commentEl = dlg.querySelector('#fw-comment');
      const comment = commentEl ? commentEl.value.trim() : '';
      if (hasOutlier && commentEl && comment === '') {
        commentEl.classList.add('fw-dialog__textarea--error');
        commentEl.focus();
        return;
      }
      dlg.remove();
      onConfirm(comment);
    });

    // Focus the dialog
    (dlg.querySelector('#fw-comment') || confirmBtn).focus();
  }

  // ── Init ─────────────────────────────────────────────────────────────────
  //
  // activeThresholds: mutable reference so setThresholds() can swap the map
  // at runtime without replacing the blur/submit closures.
  let _activeForm           = null;
  let _activeThresholds     = {};
  let _activeSwapPairs      = [];
  let _activeWarningPanel   = null;
  let _activeExtraWarnings  = null;  // fn() => warning[] | null

  function init({
    formId,
    draftKey,
    thresholds = {},
    swapPairs  = [],
    diffFields = [],
    diffLabels = {},
    warningPanelId = null,
    extraWarnings  = null,
  }) {
    const form = document.getElementById(formId);
    if (!form) return;

    _activeForm          = form;
    _activeThresholds    = thresholds;
    _activeSwapPairs     = swapPairs;
    _activeWarningPanel  = warningPanelId ? document.getElementById(warningPanelId) : null;
    _activeExtraWarnings = (typeof extraWarnings === 'function') ? extraWarnings : null;

    // Load draft on page load
    if (draftKey) loadDraft(form, draftKey);

    // Save draft on any input change
    if (draftKey) {
      form.addEventListener('input', () => saveDraft(form, draftKey));
      form.addEventListener('change', () => saveDraft(form, draftKey));
    }

    // Store original values for diff
    for (const name of diffFields) {
      const el = form.elements.namedItem(name);
      if (el) el.dataset.original = el.value;
    }

    // Live soft-validation on blur — reads _activeThresholds at call time
    // so setThresholds() affects subsequent blur events automatically.
    for (const name of Object.keys(thresholds)) {
      const el = form.elements.namedItem(name);
      if (!el) continue;
      el.addEventListener('blur', () => {
        const allWarnings = collectAllWarnings(form, _activeThresholds, _activeSwapPairs, _activeExtraWarnings);
        renderWarnings(_activeWarningPanel, allWarnings);
      });
    }

    // Intercept submit
    form.addEventListener('submit', (e) => {
      e.preventDefault();

      const allWarnings = collectAllWarnings(form, _activeThresholds, _activeSwapPairs, _activeExtraWarnings);
      const diffs = buildDiff(form, diffFields, diffLabels);

      if (allWarnings.length || diffs.length) {
        showDiffDialog(diffs, allWarnings, (comment) => {
          // Determine comment target: if any outlier carries commentTarget='loss_note'
          // and the form has a non-empty loss_note field, route there (loss context dominates).
          // Otherwise, inject into the default fw_comment hidden field.
          // No-clobber: only inject when the target is currently empty.
          const hasLossNoteTarget = allWarnings.some(
            w => w.level === 'outlier' && w.commentTarget === 'loss_note'
          );
          const lossNoteEl = form.querySelector('#loss_note');
          const routeToLossNote = hasLossNoteTarget && lossNoteEl;

          if (routeToLossNote) {
            // Inject into loss_note only when it's empty (no-clobber)
            if (lossNoteEl.value.trim() === '') {
              lossNoteEl.value = comment;
            }
          } else {
            // Default: inject into the fw_comment hidden field
            let commentInput = form.querySelector('input[name="fw_comment"]');
            if (!commentInput) {
              commentInput = document.createElement('input');
              commentInput.type = 'hidden';
              commentInput.name = 'fw_comment';
              form.appendChild(commentInput);
            }
            if (commentInput.value.trim() === '') {
              commentInput.value = comment;
            }
          }
          if (draftKey) clearDraft(draftKey);
          form.submit();
        });
      } else {
        if (draftKey) clearDraft(draftKey);
        form.submit();
      }
    });

    // Expose reload-and-clear for success pages
    window.__fwClearDraft = () => { if (draftKey) clearDraft(draftKey); };
  }

  /**
   * Replace the active threshold map and immediately re-evaluate warnings.
   * Called by racking-form.js when the operator selects a beer card, so the
   * warning bands switch to per-recipe values without a page reload.
   *
   * Brewing and packaging forms never call this — they pass static thresholds
   * to init() and are completely unaffected by this addition.
   *
   * @param {Object} map  Field-name-keyed threshold map (same shape as the
   *                      `thresholds` option passed to init()).
   */
  function setThresholds(map) {
    _activeThresholds = map || {};
    if (_activeForm) {
      const allWarnings = collectAllWarnings(_activeForm, _activeThresholds, _activeSwapPairs, _activeExtraWarnings);
      renderWarnings(_activeWarningPanel, allWarnings);
    }
  }

  /**
   * Re-run warning collection (threshold + extraWarnings) and re-render the panel.
   * Call this from the host page whenever a non-threshold input (e.g. a loss field)
   * changes so the panel updates live without waiting for a blur event.
   *
   * No-op when init() has not been called.
   */
  function refreshWarnings() {
    if (!_activeForm) return;
    const allWarnings = collectAllWarnings(_activeForm, _activeThresholds, _activeSwapPairs, _activeExtraWarnings);
    renderWarnings(_activeWarningPanel, allWarnings);
  }

  // Internal: merge threshold-derived warnings with extra warnings from the provider.
  function collectAllWarnings(form, thresholds, swapPairs, extraWarningsFn) {
    const warnings = [];
    for (const [name, threshold] of Object.entries(thresholds)) {
      const el = form.elements.namedItem(name);
      if (!el) continue;
      const w = evaluateField(name, el.value, threshold);
      if (w) warnings.push(w);
    }
    warnings.push(...detectSwaps(form, swapPairs));
    if (typeof extraWarningsFn === 'function') {
      const extra = extraWarningsFn();
      if (Array.isArray(extra)) warnings.push(...extra);
    }
    return warnings;
  }

  // Legacy alias: collectWarnings retained for internal callers that don't need extraWarnings.
  function collectWarnings(form, thresholds, swapPairs) {
    return collectAllWarnings(form, thresholds, swapPairs, null);
  }

  return { init, setThresholds, refreshWarnings };
})();
