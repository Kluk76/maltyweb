/**
 * bom-review.js
 *
 * Handles the BOM Review surface:
 *   - SKU tab switching within a recipe drill-down
 *   - Feed-3 anomaly flag accordion (toggle detail rows)
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

    document.addEventListener('DOMContentLoaded', function () {
        initSkuTabs();
        initPriceEditToggle();
        initFeedAccordion();
    });
})();
