# Motion & animation craft — maltyweb context

> Deeper animation craft for when a task involves more than a trivial fade.
> Source: Emil Kowalski animation philosophy (mined 2026-06-07, rephrased in our voice).
> Prerequisite: read `ux-quality-rules.md §6` first for the budget summary + easing bug fix.
> This file deepens the why behind those constraints and adds operator-floor specifics.

Nothing here overrides SKILL.md's rendering-bug catalog or `ux-quality-rules.md §6`'s caps.

---

## M1 — Frequency gate (answer this FIRST, before picking any duration)

The most important question is whether to animate at all, not how. Frequency decides:

| How often operators see it | Decision |
|---|---|
| 100+×/day — search, type-ahead, keyboard-initiated actions, chip ✕ | **No animation, ever.** |
| Tens×/day — hover effects, row highlights, quick toggles | Minimal or none; any animation must be imperceptible. |
| Occasional — modals, drawers, toasts, page transitions | Standard durations apply (see M3). |
| Rare — onboarding, first-run, milestone celebrations | May delight; keep it short even here. |

**Never animate keyboard-initiated actions.** Operators use keyboard shortcuts to move fast; animation at those moments reads as the app fighting back. A search palette or command-bar with no open/close animation is correct, not broken.

Floor-tablet framing: operators check tank readouts 50–100× per shift. A chip ✕ that animates on every tap is noise that accumulates into fatigue. If in doubt, no animation.

---

## M2 — Custom easing tokens (CSS keyword easings are too weak to feel intentional)

Declare these at `:root`. Never use bare `ease-in` for UI motion — see `ux-quality-rules.md §6`.
(These three easing tokens are the sanctioned addition to the token block — SKILL.md's "don't
invent tokens" rule concerns *colour* tokens. If they're not yet in `app.css`, add them once,
globally, and tell `maltyweb-pm`.)

```css
:root {
  /* Strong ease-out — UI interactions: enters, exits, button press */
  --ease-out: cubic-bezier(0.23, 1, 0.32, 1);

  /* Strong ease-in-out — on-screen movement, morphing elements */
  --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);

  /* iOS-like drawer curve — drawers, bottom sheets, slide-in panels */
  --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
}
```

Why custom: the built-in CSS `ease`/`ease-out`/`ease-in-out` keywords are gentle curves tuned for marketing pages. At 150–300ms they produce motion that reads as "soft" rather than "responsive". The custom curves above start faster (more initial velocity) and decelerate harder — the operator perceives them as crisp rather than sluggish.

---

## M3 — Per-element duration table

| Element | Duration range |
|---|---|
| Button press feedback (`:active` scale) | 100–160ms |
| Tooltips, small popovers | 125–200ms |
| Dropdowns, selects | 150–250ms |
| Modals, drawers, sheet panels | 200–500ms |

The global cap is 500ms (from `ux-quality-rules.md §6`). Exits should run at ~60–70% of the enter duration — leaving feels snappier than arriving. A 300ms modal enter → ~190ms exit.

Practical calibration: a 180ms dropdown feels more responsive than a 400ms one at identical latency. The speed of the animation shapes perceived app speed, not just the load time.

---

## M4 — Entry scale: never from `scale(0)`

Nothing real appears from nothing. Starting from `scale(0)` looks like a teleport. Use at least `scale(0.95)` (range 0.9–0.98 depending on element size) combined with `opacity: 0`.

```css
/* Wrong */
.entering { transform: scale(0); }

/* Correct */
.entering { transform: scale(0.95); opacity: 0; }
```

The tiny initial scale gives the element a visible "shape" even when nearly transparent — like a balloon that's deflated but present. Elements entering from off-screen (toasts sliding in, drawers) use `translateY`/`translateX` instead.

---

## M5 — transform-origin: scale from where the element came from

Popovers and dropdowns should scale from their trigger edge, not from center. The visual tells the user "this came from that button." Modals are the exception — they stay `transform-origin: center` because they are viewport-centered, not anchored to a specific trigger.

```css
/* Popover: set via JS from trigger's getBoundingClientRect() */
.popover {
  transform-origin: var(--transform-origin, top left);
  /* --transform-origin written by JS based on trigger position */
}

/* Modal: always center */
.modal {
  transform-origin: center;
}
```

The difference is invisible to conscious attention but compounds into "this feels right vs. wrong" across hundreds of interactions.

---

## M6 — Tooltip skip-delay: instant on subsequent hovers

Tooltips need an initial delay (200–400ms) to prevent accidental activation while the pointer moves. But once one tooltip is open, the next tooltip should open instantly with no delay and no animation — the operator already confirmed intent by dwelling once.

Implement a `[data-instant]` window: when a tooltip opens, mark the toolbar or container with a flag; clear it when all tooltips close. Adjacent tooltips check the flag.

```css
/* Normal tooltip: delay + transition */
.tooltip {
  transition: opacity 125ms var(--ease-out), transform 125ms var(--ease-out);
  transform-origin: var(--transform-origin, top center);
}

/* While in the instant window: no delay, no animation */
.tooltip[data-instant] {
  transition-duration: 0ms;
  transition-delay: 0ms;
}
```

Effect: the whole toolbar feels faster, because the mental overhead of "hover → wait → appear" vanishes after the first activation.

---

## M7 — CSS transitions over `@keyframes` for rapid-succession elements

Toasts being added, chips being toggled, list items being filtered: these fire in rapid succession, sometimes mid-flight. CSS transitions can be interrupted and retarget smoothly from their current position. `@keyframes` restart from their first frame on interruption — the element snaps back and starts over, which looks broken.

Rule: use `transition` for anything a user can trigger repeatedly or quickly (toasts, chip state changes, row highlights, toggles). Reserve `@keyframes` for predetermined, non-interruptible sequences (spinners, loaders, once-per-page entrances).

```css
/* Use: retargets mid-flight */
.toast { transition: transform 300ms var(--ease-out), opacity 300ms var(--ease-out); }

/* Avoid for dynamic UI: restarts on interruption */
@keyframes slideIn { from { transform: translateY(100%); } to { transform: translateY(0); } }
```

---

## M8 — `@starting-style` for CSS-only entry from `display:none`

The old pattern for animating an element that starts hidden was a JS double-`requestAnimationFrame` class toggle: set `display:block`, wait one frame, add the `.entering` class. This works but requires JS and is fragile.

Modern CSS alternative (Chrome 117+, Firefox 129+, Safari 17.5+):

```css
.toast {
  opacity: 1;
  transform: translateY(0);
  transition: opacity 300ms var(--ease-out), transform 300ms var(--ease-out);

  @starting-style {
    opacity: 0;
    transform: translateY(8px);
  }
}
```

`@starting-style` declares the element's initial painted state when it first becomes visible — the browser applies the transition from those values automatically. No JS needed. Where browser support allows, prefer this over the JS class-toggle trick.

---

## M9 — Drag performance: mutate `transform` directly, not a CSS variable on a parent

During a drag or swipe gesture, the browser must update the element's position every pointer event (60+ times per second on a tablet). Two approaches:

```js
// Wrong: changing a CSS var recalculates styles for ALL children
element.style.setProperty('--swipe-amount', `${distance}px`);

// Correct: only affects this element's compositing layer
element.style.transform = `translateY(${distance}px)`;
```

On a floor tablet with a list of 50+ items, the wrong approach triggers style recalculation on every child per frame → visible jank. The correct approach is compositor-only and stays smooth.

---

## M10 — Deliberate vs system asymmetry

The animation speed should match who is in control at that moment:
- **Slow where the user decides**: a hold-to-delete action builds up over ~2s so the user can release if they change their mind.
- **Fast where the system responds**: the same element releases in ~200ms ease-out — the system answering the user's decision.

```css
/* System responds: fast */
.delete-overlay {
  transition: clip-path 200ms var(--ease-out);
}

/* User decides: slow and deliberate */
.delete-btn:active .delete-overlay {
  transition: clip-path 2s linear;
}
```

**Hold-to-delete pattern** using `clip-path: inset()`: overlay starts at `inset(0 100% 0 0)` (fully clipped right), transitions to `inset(0 0 0 0)` (fully visible) on `:active` over 2s. On release, snaps back in 200ms. Add `scale(0.97)` on the button itself for press feedback. This is a valid alternative to confirm dialogs for low-stakes destructive actions on floor tablets — gloves make dialogs slow to dismiss.

---

## M11 — Perceived performance

Perception of speed is not the same as actual speed:

- **< ~80ms** reads as instant — below human perception of cause-and-effect delay.
- A **faster-spinning spinner** makes a load feel shorter even at identical actual latency.
- A **180ms select animation** feels faster than a 400ms one even when the data returns at the same time.
- `ease-out` at 200ms *feels* faster than `ease-in` at 200ms because initial movement is immediate.

Counter-intuitive: an operation that completes instantly can read as "didn't really run." A brief progress signal (skeleton frame, 100ms spinner) confirms that real work happened. Calibrate: skip the signal for sub-100ms responses, show a skeleton for 300ms+ loads, use a progress bar for operations the operator can estimate (batch ingest, export).

**Tab active-state color transition with `clip-path`:** When a tab active indicator needs to transition color (not just position), layering two identical tab lists — one styled "normal", one styled "active" — and clipping the active layer to reveal only the current tab gives a seamless color change that opacity/color transitions can never achieve cleanly:

```css
/* Two lists stacked; the active overlay is clipped */
.tabs-active-overlay {
  clip-path: inset(0 calc(100% - var(--active-tab-right)) 0 var(--active-tab-left));
  transition: clip-path 200ms var(--ease-out);
  /* --active-tab-left/right set via JS on tab change */
}
```
