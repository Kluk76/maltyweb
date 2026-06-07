/* visite-guidee.js — Tour navigation.
   Reads TOTAL step count from data-total on #dots-container.
   No window.X payload; all steps are server-rendered PHP.
   Section steps (ember dot) are encoded in data-section="1" on the dot. */
(function () {
  'use strict';

  const dotsWrap  = document.getElementById('dots-container');
  const counter   = document.getElementById('step-counter');
  const btnPrev   = document.getElementById('btn-prev');
  const btnNext   = document.getElementById('btn-next');
  const TOTAL     = parseInt(dotsWrap ? dotsWrap.dataset.total : '0', 10);
  const DONE_URL  = (document.getElementById('vg-done-url') || {}).dataset.url
                    || '/modules/mon-tableau.php';

  if (!TOTAL || !dotsWrap || !counter || !btnPrev || !btnNext) return;

  const steps = Array.from(document.querySelectorAll('.vg-step'));
  let current = 0;

  /* ── Build dots ────────────────────────────────────────── */
  function buildDots() {
    dotsWrap.innerHTML = '';
    for (let i = 0; i < TOTAL; i++) {
      const isSection = steps[i] && steps[i].dataset.section === '1';
      const btn = document.createElement('button');
      btn.className = 'vg-dot' + (isSection ? ' vg-dot--section' : '');
      btn.setAttribute('role', 'tab');
      btn.setAttribute('aria-label', 'Aller à l\'étape ' + (i + 1));
      btn.setAttribute('aria-selected', String(i === current));
      btn.setAttribute('tabindex', i === current ? '0' : '-1');
      btn.dataset.idx = String(i);
      btn.addEventListener('click', () => goTo(parseInt(btn.dataset.idx, 10)));
      dotsWrap.appendChild(btn);
    }
  }

  /* ── Update dot states ─────────────────────────────────── */
  function updateDots() {
    const dotBtns = dotsWrap.querySelectorAll('.vg-dot');
    dotBtns.forEach((btn, i) => {
      btn.classList.remove('vg-dot--active', 'vg-dot--done');
      if (i === current) {
        btn.classList.add('vg-dot--active');
        btn.setAttribute('aria-selected', 'true');
        btn.setAttribute('tabindex', '0');
      } else {
        btn.setAttribute('aria-selected', 'false');
        btn.setAttribute('tabindex', '-1');
        if (i < current) btn.classList.add('vg-dot--done');
      }
    });
  }

  /* ── Transition to step idx ────────────────────────────── */
  function goTo(idx, direction) {
    if (idx < 0 || idx >= TOTAL) return;

    const oldEl = steps[current];
    const newEl = steps[idx];
    if (!oldEl || !newEl) return;

    if (direction === undefined) direction = idx > current ? 1 : -1;

    /* Exit old step */
    oldEl.classList.remove('vg-step--active');
    oldEl.classList.add('vg-step--exit');
    oldEl.setAttribute('aria-hidden', 'true');

    oldEl.addEventListener('transitionend', function onExitEnd() {
      oldEl.classList.remove('vg-step--exit');
      oldEl.removeEventListener('transitionend', onExitEnd);
    }, { once: true });

    /* Position incoming for direction-aware enter */
    newEl.style.transform = direction > 0 ? 'translateX(32px)' : 'translateX(-32px)';
    newEl.style.opacity = '0';
    newEl.style.visibility = 'visible';

    /* Force reflow */
    void newEl.offsetWidth;

    /* Animate to --active state */
    newEl.style.transform = '';
    newEl.style.opacity = '';
    newEl.style.visibility = '';

    current = idx;
    newEl.classList.add('vg-step--active');
    newEl.removeAttribute('aria-hidden');

    counter.textContent = 'étape ' + (current + 1) + ' / ' + TOTAL;
    updateDots();
    updateButtons();

    /* Scroll to top of stage on mobile */
    const stage = document.getElementById('main-content');
    if (stage) stage.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  /* ── Update prev/next buttons ──────────────────────────── */
  function updateButtons() {
    if (current === 0) {
      btnPrev.classList.add('vg-btn--hidden');
      btnPrev.setAttribute('aria-hidden', 'true');
      btnPrev.setAttribute('tabindex', '-1');
    } else {
      btnPrev.classList.remove('vg-btn--hidden');
      btnPrev.removeAttribute('aria-hidden');
      btnPrev.setAttribute('tabindex', '0');
    }

    if (current === TOTAL - 1) {
      btnNext.textContent = 'Terminer la visite ✓';
      btnNext.classList.remove('vg-btn--next');
      btnNext.classList.add('vg-btn--done');
      btnNext.setAttribute('aria-label', 'Terminer la visite guidée');
    } else {
      btnNext.textContent = 'Suivant →';
      btnNext.classList.remove('vg-btn--done');
      btnNext.classList.add('vg-btn--next');
      btnNext.setAttribute('aria-label', 'Étape suivante');
    }
  }

  /* ── Button listeners ──────────────────────────────────── */
  btnPrev.addEventListener('click', () => {
    if (current > 0) goTo(current - 1, -1);
  });

  btnNext.addEventListener('click', () => {
    if (current < TOTAL - 1) {
      goTo(current + 1, 1);
    } else {
      window.location.href = DONE_URL;
    }
  });

  /* ── Keyboard navigation ───────────────────────────────── */
  document.addEventListener('keydown', (e) => {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
      e.preventDefault();
      if (current < TOTAL - 1) goTo(current + 1, 1);
    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (current > 0) goTo(current - 1, -1);
    }
  });

  /* ── Dot roving tabindex ───────────────────────────────── */
  dotsWrap.addEventListener('keydown', (e) => {
    const dotBtns = Array.from(dotsWrap.querySelectorAll('.vg-dot'));
    const focused = document.activeElement;
    const focusIdx = dotBtns.indexOf(focused);
    if (focusIdx === -1) return;

    if (e.key === 'ArrowRight') {
      e.preventDefault();
      dotBtns[(focusIdx + 1) % dotBtns.length].focus();
    } else if (e.key === 'ArrowLeft') {
      e.preventDefault();
      dotBtns[(focusIdx - 1 + dotBtns.length) % dotBtns.length].focus();
    }
  });

  /* ── Touch swipe ───────────────────────────────────────── */
  let touchStartX = 0;
  let touchStartY = 0;

  document.addEventListener('touchstart', (e) => {
    touchStartX = e.touches[0].clientX;
    touchStartY = e.touches[0].clientY;
  }, { passive: true });

  document.addEventListener('touchend', (e) => {
    const dx = e.changedTouches[0].clientX - touchStartX;
    const dy = e.changedTouches[0].clientY - touchStartY;
    if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
      if (dx < 0 && current < TOTAL - 1) goTo(current + 1, 1);
      else if (dx > 0 && current > 0) goTo(current - 1, -1);
    }
  }, { passive: true });

  /* ── Init ──────────────────────────────────────────────── */
  buildDots();

  steps.forEach((step, i) => {
    if (i === 0) {
      step.classList.add('vg-step--active');
      step.removeAttribute('aria-hidden');
    } else {
      step.setAttribute('aria-hidden', 'true');
    }
  });

  updateDots();
  updateButtons();

})();
