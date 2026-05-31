/**
 * form-rm-stocktake.js — RM stocktake form interactions.
 *
 * Single responsibility: period navigation (Go button → reload page with
 * ?period=YYYY-MM so PHP pre-loads sticky counts for that period).
 *
 * No module system — vanilla ES2020, no imports. Loaded deferred.
 */

(function () {
    'use strict';

    const periodInput = document.getElementById('period-input');
    const periodGoBtn = document.getElementById('rms-period-go');

    if (!periodInput || !periodGoBtn) return;

    function navigateToPeriod() {
        const val = periodInput.value.trim();
        if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(val)) {
            periodInput.style.borderColor = 'var(--ember)';
            return;
        }
        periodInput.style.borderColor = '';
        const url = new URL(window.location.href);
        url.searchParams.set('period', val);
        // Keep only period in the query string
        window.location.href = url.pathname + '?period=' + encodeURIComponent(val);
    }

    periodGoBtn.addEventListener('click', navigateToPeriod);

    // Allow Enter key in the period input to trigger navigation
    periodInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            navigateToPeriod();
        }
    });

    // When the month input changes natively (calendar picker), auto-navigate
    periodInput.addEventListener('change', navigateToPeriod);

})();
