/**
 * bom-review.js
 *
 * Handles the BOM Review surface:
 *   - SKU tab switching within a recipe drill-down
 *   - Feed-3 anomaly flag accordion (toggle detail rows)
 *   - Browse tab: expand/collapse BOM panels, confirm-before-recompile modal
 *
 * Data is server-injected (no AJAX calls for navigation).
 * All write actions go through standard PRG forms.
 */
(function () {
    'use strict';

    // ── SKU tab switching ────────────────────────────────────────────────────
    function initSkuTabs() {
        var tabs    = document.querySelectorAll('.br-sku-tab[data-sku-id]');
        var panels  = document.querySelectorAll('.br-sku-panel');
        if (!tabs.length) return;

        function activateTab(skuId) {
            tabs.forEach(function (t) {
                t.classList.toggle('br-sku-tab--active', t.dataset.skuId === skuId);
                t.removeAttribute('aria-current');
                if (t.dataset.skuId === skuId) {
                    t.setAttribute('aria-current', 'true');
                }
            });
            panels.forEach(function (p) {
                var show = p.dataset.skuId === skuId;
                p.hidden = !show;
            });
        }

        // Activate first tab by default, or the one matching URL hash
        var hash     = window.location.hash.replace('#sku-', '');
        var firstId  = tabs[0].dataset.skuId;
        var targetId = hash || firstId;

        // Verify targetId exists
        var found = false;
        tabs.forEach(function (t) { if (t.dataset.skuId === targetId) found = true; });
        if (!found) targetId = firstId;

        activateTab(targetId);

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                activateTab(tab.dataset.skuId);
                history.replaceState(null, '', '#sku-' + tab.dataset.skuId);
            });
        });
    }

    // ── Price-edit toggle ────────────────────────────────────────────────────
    function initPriceEditToggle() {
        document.querySelectorAll('.br-price-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.dataset.toggleTarget);
                if (!target) return;
                var hidden = target.hidden;
                target.hidden = !hidden;
                btn.textContent = hidden ? 'Annuler' : 'Définir le prix';
            });
        });
    }

    // ── Accordion for defect feed rows ──────────────────────────────────────
    function initFeedAccordion() {
        document.querySelectorAll('.br-feed-row[data-accordion]').forEach(function (row) {
            row.style.cursor = 'pointer';
            row.addEventListener('click', function () {
                var detailId = row.dataset.accordion;
                var detail   = document.getElementById(detailId);
                if (!detail) return;
                detail.hidden = !detail.hidden;
                row.classList.toggle('br-feed-row--open', !detail.hidden);
            });
        });
    }

    // ── Browse tab: expand/collapse BOM panels ───────────────────────────────
    function initBrowseExpand() {
        document.querySelectorAll('.br-browse-expand-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = btn.dataset.target;
                if (!targetId) return;
                var panel = document.getElementById(targetId);
                if (!panel) return;
                var isOpen = !panel.hidden;
                panel.hidden = isOpen;
                btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
                btn.textContent = isOpen ? '▶ Voir BOM' : '▼ Masquer BOM';
            });
        });

        // On page load, open the card matching the URL hash (#browse-sku-NNN)
        var hash = window.location.hash;
        if (hash && hash.startsWith('#browse-sku-')) {
            var skuId   = hash.replace('#browse-sku-', '');
            var panelId = 'browse-bom-' + skuId;
            var panel   = document.getElementById(panelId);
            var btn     = document.querySelector('[data-target="' + panelId + '"]');
            if (panel && btn) {
                panel.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                btn.textContent = '▼ Masquer BOM';
                // Scroll to card
                var card = document.getElementById('browse-sku-' + skuId);
                if (card) {
                    setTimeout(function () { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 100);
                }
            }
        }
    }

    // ── Browse tab: confirm-before-recompile modal ───────────────────────────
    function initBrowseConfirmModal() {
        var modal      = document.getElementById('br-confirm-modal');
        var bodyEl     = document.getElementById('br-confirm-body');
        var blastEl    = document.getElementById('br-confirm-blast');
        var cancelBtn  = document.getElementById('br-confirm-cancel');
        var okBtn      = document.getElementById('br-confirm-ok');
        if (!modal) return;

        var pendingForm    = null;
        // Store per-form submit handlers so we can remove them after confirmation.
        // Each handler is keyed by the form element reference via a WeakMap.
        var handlerMap = typeof WeakMap !== 'undefined' ? new WeakMap() : null;

        // Human label map for action types
        var actionLabels = {
            set_sku_choice:     'choix SKU (Tier-1)',
            set_recipe_binding: 'liaison recette (Tier-2)',
            set_mi_price:       'mise à jour du prix MI'
        };

        // Intercept browse edit forms
        document.querySelectorAll('.br-browse-edit-form').forEach(function (form) {
            var handler = function (e) {
                e.preventDefault();
                pendingForm = form;

                var action     = form.dataset.action || '';
                var skuCode    = form.dataset.skuCode || '?';
                var recipeName = form.dataset.recipeName || '';
                var label      = actionLabels[action] || action;

                // Build body text
                var bodyText = 'Modifier ' + label + ' pour ' + escHtml(skuCode);
                if (bodyEl) bodyEl.textContent = bodyText;

                // Build blast list
                var blast = '';
                if (action === 'set_recipe_binding' && recipeName) {
                    blast = 'Recette : ' + recipeName + '\n'
                          + 'Impact : tous les SKUs actifs de cette recette seront recompilés.';
                } else if (action === 'set_sku_choice') {
                    blast = 'SKU : ' + skuCode + '\n'
                          + 'Impact : ce SKU uniquement.';
                } else if (action === 'set_mi_price') {
                    blast = 'SKU concerné : ' + skuCode + '\n'
                          + 'Impact : tous les SKUs qui référencent ce MI packaging\n'
                          + 'seront recompilés.';
                }
                if (blastEl) blastEl.textContent = blast;

                if (modal.showModal) {
                    modal.showModal();
                } else {
                    // Fallback for browsers without <dialog>
                    if (window.confirm(bodyText + '\n\n' + blast + '\n\nConfirmer ?')) {
                        pendingForm.submit();
                        pendingForm = null;
                    }
                }
            };
            if (handlerMap) handlerMap.set(form, handler);
            form.addEventListener('submit', handler);
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                pendingForm = null;
                modal.close();
            });
        }

        if (okBtn) {
            okBtn.addEventListener('click', function () {
                modal.close();
                if (pendingForm) {
                    // Remove the intercepting submit listener so the native submit goes through.
                    var h = handlerMap && handlerMap.get(pendingForm);
                    if (h) pendingForm.removeEventListener('submit', h);
                    pendingForm.submit();
                    pendingForm = null;
                }
            });
        }

        // Close on backdrop click
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                pendingForm = null;
                modal.close();
            }
        });

        // Close on Escape (browser handles it natively for <dialog>; this is belt+suspenders)
        modal.addEventListener('cancel', function () {
            pendingForm = null;
        });
    }

    // ── Utility ──────────────────────────────────────────────────────────────
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    document.addEventListener('DOMContentLoaded', function () {
        initSkuTabs();
        initPriceEditToggle();
        initFeedAccordion();
        initBrowseExpand();
        initBrowseConfirmModal();
    });
})();
