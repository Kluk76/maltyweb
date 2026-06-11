# UX quality rules — distilled for maltyweb

> Provenance: base corpus mined from the vetted `nextlevelbuilder/ui-ux-pro-max-skill`
> (vetted clean 2026-06-07 — prompt-injection scan, code/egress/credential battery, symlink/binary
> check all passed; we took the rule corpus, NOT the Python/CSV search engine or the
> palette/font/style pickers — maltyweb's design system is fixed, see SKILL.md).
> Second mining 2026-06-07: `emilkowalski/skill` (animation craft → also `motion.md`),
> `pbakaus/impeccable` reference docs (eight-states, cognitive load §10, severity §11,
> personas §12, UX copy formulas), `Leonxlnx/taste-skill` (perf bans, consistency locks,
> binary checklist framing) — all three vetted clean / markdown-only the same day.
> Everything below is filtered to what applies to a **server-rendered PHP web app used by brewery
> operators on desktop + floor tablets, French UI, fr-CH locale, kraft-paper theme via CSS tokens**.
> Dropped wholesale: React-Native/iOS/Android-native rules, VisionOS, AI-chat UX, stack guides,
> greenfield style/palette/font selection.

Use this file as a **review battery**: when building or reviewing an operator-facing surface,
sweep the relevant section(s). Severity tags kept from the source where they help triage.
Nothing here overrides SKILL.md — where the two touch (overflow-x, lazy-loading, body-class
scoping), SKILL.md's incident-backed rules win.

## Contents
1. Accessibility (CRITICAL)
2. Touch & interaction states (CRITICAL — floor tablets)
3. Forms & feedback (extends SKILL.md's operator-form patterns)
4. Layout & responsive
5. Typography & content formatting
6. Animation & motion
7. Performance (rendering)
8. Charts & dashboards — chart-type chooser + quality rules
9. Merged pre-delivery checklist
10. Cognitive load
11. Audit severity & scoring
12. Persona-based review

---

## 1. Accessibility (CRITICAL)

- **Skip-to-content link**: `<a href="#main-content">Aller au contenu</a>` as the first element
  in `<body>`, visually-hidden until focused (WCAG 2.4.1). Keyboard users would otherwise tab
  through the entire topbar on every page load.
- **Contrast 4.5:1** for body text, 3:1 for large text/UI glyphs — and test against the *actual
  surface* (paper `#f1e8d4` vs elevated `--bg-elev` cards; SKILL.md's `-deep` variant rule).
  Re-test any new token use in BOTH themes if a second theme ever lands.
- **Per-screen accent lock**: one primary accent per screen for same-role actions; a second accent
  only for a semantically distinct role (e.g. alert vs primary CTA). Accent drift between
  sections/states of one page creates visual noise and undermines the preattentive signal
  (`dataviz.md §2`).
- **Never remove focus rings without a replacement.** Visible `:focus-visible` ring (2–4px) on
  every interactive element. Keyboard tab order must match visual order.
- **Focus not obscured (WCAG 2.4.11).** When a component receives keyboard focus it must not be
  entirely hidden by another element (sticky topbar, fixed bottom bar, a `z-index` layer). Ensure
  focused elements scroll into the visible area and are not fully covered; partial obscuring is
  allowed but total obscuring is a failure.
- **Icon-only buttons need `aria-label`** (French, human wording — same register as the UI).
- **Color is never the only signal.** Error/success/state must pair color with icon or text —
  this also covers the red/green colorblind pair on status chips and charts.
- **Form errors are announced**: `role="alert"` or `aria-live="polite"` on the error container,
  not just a red border.
- **Semantic HTML over div soup**: `<nav>`, `<main>`, `<table>` for tabular data, `<button>` for
  actions (never a styled `<div>` with a click handler — it loses keyboard + screen reader for free).
- **Heading hierarchy sequential** (h1→h2→h3, no skips); headings chosen by structure, styled by CSS.
- **Alt text** on meaningful images (document previews: describe the document, e.g.
  `alt="Aperçu facture Univerre 2026-04"`); decorative SVG gets `aria-hidden="true"`.
- **`prefers-reduced-motion`**: any non-trivial animation (slides, parallax, chart entrances)
  must reduce/disable under `@media (prefers-reduced-motion: reduce)`.

## 2. Touch & interaction states (CRITICAL — operators on floor tablets)

All **eight interactive states** need a designed treatment for every interactive element:
default, hover, focus, active, disabled, loading, error, success. The most common miss is
designing hover without focus — keyboard users never see hover states.

- **Touch targets ≥ 44×44px, ≥ 8px gap between adjacent targets.** Chips, ✕-buttons on ledger
  lines, type-ahead results, table row actions — pad the hit area beyond the visual glyph if
  the glyph is small. This is our house rule, stricter than WCAG 2.5.8 Target Size (Minimum)
  which requires only 24×24 CSS px (with a spacing-based exception for closely packed controls).
  Floor tablets + gloved hands make 44 px the right floor; do not relax to 24 px just to pass
  the WCAG criterion.
- **`@media (pointer: coarse)`** to upsize touch targets programmatically. Input method decides
  — not screen width. A desktop touchscreen is `coarse`; a tablet with stylus is `fine`. Do not
  assume "tablet = narrow viewport."
- **Gate hover effects behind `@media (hover: hover) and (pointer: fine)`**. Touch devices fire
  hover on tap, and the effect then ghosts until the next tap elsewhere. This is complementary
  to the "never hover-only functionality" rule: the functionality must be reachable, AND
  the visual effect must not haunt tap interactions.
- **Never hover-only.** Anything revealed or actioned on hover must also work on tap/click
  (tooltips need a tap/focus path; row actions visible or reachable without hover).
- **Canonical pressed state**: `:active { transform: scale(0.97) }` (range 0.95–0.98 by element
  size), ~160ms `var(--ease-out)`. Apply to all buttons, chips, and row actions — operators
  press hard and the immediate scale feedback confirms the tap registered.
- **Every interactive element has visible hover AND active/pressed states** + `cursor:pointer`.
  Pressed feedback within ~100ms; use opacity/background/transform-scale — never a layout-shifting
  change (no border-width or size jumps that nudge neighbors).
- **Disabled ≠ read-only, and both ≠ enabled.** Disabled: reduced opacity + `cursor:not-allowed`
  + actual `disabled` attribute (an element that *looks* tappable but does nothing is a bug).
  Read-only values get their own quieter treatment, distinct from disabled.
- **Async buttons lock during flight**: disable + spinner on submit/add actions so a slow VPS
  round-trip can't double-POST (complements the CSRF retry-once pattern in SKILL.md).
- **`touch-action: manipulation`** on tap-heavy controls to kill the 300ms delay;
  **`overscroll-behavior: contain`** on scrollable panels/modals so a flick doesn't pull-to-refresh
  or scroll-chain the page behind.
- **No precision-required taps**: avoid tiny icons at panel edges; the brewery floor means gloves,
  wet screens, hurry.

## 3. Forms & feedback (extends SKILL.md's operator-form patterns — those win on conflict)

SKILL.md owns the resilience architecture (keepalive, autosave, line-append). These are the
field-level quality rules layered on top:

- **Visible label per input** — placeholder is never the label (it vanishes on type, fails
  autofill + screen readers). Helper text below complex inputs persists; placeholder is example
  text only (SKILL.md's "placeholder is never a data source" incident generalizes: nor a label).
- **Error message formula** — every error answers: what happened, why, and how to fix it. Use
  these French templates as the baseline:
  - Format: "[Champ] doit être [format]. Exemple : [exemple]"
  - Missing: "Veuillez saisir [quoi]"
  - Permission: "Vous n'avez pas accès à [chose]. [Alternative]"
  - Network: "Impossible de joindre [chose]. Vérifiez la connexion et [action]."
  - Server: "Erreur de notre côté. [Action alternative]"
  Never a lone "Valeur invalide" and never errors-only-at-top.
- **Validate on blur, not per keystroke; only after first submit attempt go live-per-input.**
  Error appears **below the offending field**, in French. For multi-error submits: summary at top
  with anchor links, and **auto-focus the first invalid field**.
- **Button labels: verb + object** — never "OK / Oui / Non / Soumettre" alone. Destructive actions
  name the destruction and count: "Supprimer 5 lignes".
- **Most confirm dialogs are design failures.** Prefer undo where feasible ("Ligne supprimée —
  Annuler"). When a confirm is unavoidable, name the action + consequence + specific labels
  ("Supprimer le projet" / "Conserver") — never generic "Oui / Non".
- **Semantic input types**: `type="number"`/`inputmode="decimal"` for quantities, `type="date"`
  honoring the system DMY format, `type="email"`, `autocomplete` attributes on identity fields —
  this drives the right tablet keyboard.
- **Required fields marked** (`*` + legend once per form).
- **Submit feedback is a full cycle**: loading state → PRG redirect → ok/error flash (and the
  draft-clear-on-confirmed-success rule from SKILL.md). Silent success is a bug.
- **Destructive buttons** use the danger treatment and are spatially separated from the primary CTA.
- **One primary CTA per screen/section**; secondary actions visually subordinate.
- **Card groups: pin CTAs to card bottom** (`flex-direction: column` + `margin-top: auto` on the
  CTA) so buttons align across unequal content heights. Shared elements (title/figure/CTA) should
  align at the same Y across side-by-side cards.
- **Toasts auto-dismiss (3–5s), never steal focus**, `aria-live="polite"`.
- **Empty states guide**: "Aucune livraison pour cette période" + the action to create/ingest one —
  never a blank section (also the fingerprint of a `window.X` payload bug; check data first,
  per SKILL.md playbook).
- **Multi-step flows show progress** ("Étape 2/4") and allow going back without loss.

## 4. Layout & responsive

- **Z-index scale, not arbitrary values**: pick from a small ladder (10/20/30/50/100) and document
  it; `z-index:9999` is the smell of a stacking-context misunderstanding — remember `<dialog>`
  top-layer beats all z-index (SKILL.md trap).
- **Flex/grid shrink trap**: flex children default to `min-width: auto`, which prevents shrinking
  below content size and causes overflow. Set `min-width: 0` on flex children (and `min-height: 0`
  on grid children) wherever truncation or `overflow: hidden` is intended. This is the silent
  cause of many "text overflows its container" reports.
- **Cockpit-density rule** for maximal-density panels (tank readouts, COGS tables): drop card
  boxes entirely — use 1px separator lines; apply `JetBrains Mono` / `tabular-nums` on ALL
  numeric columns so values align under varying digit counts.
- **Reserve space for async content** (`aspect-ratio` or fixed dimensions on images/previews/charts)
  — layout shift while data loads is jarring and breaks tap accuracy.
- **`min-height: 100dvh` over `100vh`** for full-height surfaces on tablets (browser chrome).
- **Fixed bars compensate**: sticky topbar → content padding; any fixed bottom bar → bottom inset
  on the scroll content so the last row isn't buried.
- **Wide tables get `overflow-x:auto` on a wrapper**, never on `body` (SKILL.md owns the
  body-overflow rules); on narrow viewports consider a card layout per row for the most-used tables.
- **Text measure 65–75ch max** for prose blocks (notes, doc text); don't let paragraphs span a
  full desktop viewport.
- **Don't blindly `overflow:hidden`** — test that no real content is clipped; prefer
  `overflow:auto` unless the page is a self-clipping sliding stage (SKILL.md exemption).
- **Test at 320/375/768/1024/1440** — the floor tablet and a narrow split-screen window are real
  operator conditions, not edge cases.

## 5. Typography & content formatting

- **Body ≥ 16px**, line-height 1.5–1.75; clear size+weight step between headings and body
  (the Fraunces/DM Sans/JetBrains Mono stack gives this for free — don't flatten it).
- **`text-wrap: balance`** on headings to distribute words evenly across lines;
  **`text-wrap: pretty`** on short prose blocks to eliminate orphaned last words. Both are
  cheap single-property wins with zero layout risk.
- **Light-on-dark compensation on three axes** (our dark sidebar + elevated dark cards):
  line-height +0.05–0.1, letter-spacing +0.01–0.02em, and optionally weight one notch up
  (e.g. Regular → Medium). Perceived weight drops on dark surfaces; fix all three — fixing only
  size leaves the text reading as thin and crowded.
- **All-caps labels**: letter-spacing 0.05–0.12em. Capitals at default tracking sit too close.
- **Optical vs mathematical alignment**: icon + label pairs (svg-tanks next to readouts) often
  need a 1–2px manual nudge to look centered even when the math says they are. Do it — it's
  cheap and the difference is visible.
- **Tabular figures for data columns** — quantities, CHF amounts, timers, HL readouts: JetBrains
  Mono already is monospaced; if a numeric column ever renders in DM Sans, set
  `font-variant-numeric: tabular-nums` so totals don't wiggle as values update.
- **fr-CH locale formatting everywhere numbers/dates render**: DMY dates from `system_settings`
  (never `01/02/03`-ambiguous), thousands separators on big quantities, CHF amounts with
  consistent decimals. Relative dates ("il y a 2 h") fine for activity feeds, absolute for records.
- **Truncation: prefer wrap; if truncating, ellipsis + full value reachable** (`title` attr,
  tooltip, or expand). Never silently cut a lot number or supplier name.
- **Realistic placeholder/sample data in `_design` mockups** — real SKU codes, real supplier
  names — lorem-ipsum mocks hide layout failures that real data triggers (long names, big numbers).

## 6. Animation & motion

- **150–300ms micro-interactions; ≤400ms transitions; nothing >500ms.** Exits ~60–70% of the
  enter duration (leaving should feel snappier than arriving).
- **`transform`/`opacity` only** — never animate width/height/top/left (reflow + CLS).
- **ease-out (or custom curve) for BOTH enter and exit.** Exits just run shorter (~60–70% of
  enter). **Never pure `ease-in` for UI motion** — it delays initial movement at exactly the
  moment the user is watching, reading as sluggish at any duration. `ease-in-out` is acceptable
  for on-screen morphing/movement that doesn't start or end at rest.
- **Animate 1–2 key elements per view, max.** Motion must mean something (cause→effect: modal
  grows from its trigger, deleted chip collapses); decorative perpetual motion only on loaders.
- **Stagger list entrances 30–50ms/item** if animating a ledger reveal at all.
- **Never block input during an animation**; in-progress animations are interruptible.
- **Skeleton/shimmer over spinner for >300ms loads** (chart panes, preview modals); spinner is
  fine for sub-second button-level feedback.
- All of it gated by `prefers-reduced-motion` (§1).

Deeper animation craft (frequency gate, easing tokens, per-element durations, origin rules,
perceived performance, hold-to-delete, `@starting-style`, drag perf):
read **`references/motion.md`** when the task involves any animation beyond a trivial fade.

## 7. Performance (rendering)

- **HARD BAN: `window.addEventListener('scroll', …)` for visual effects.** Use
  `IntersectionObserver` instead (or `animation-timeline: view()` when supported). Scroll
  handlers run every frame; floor tablets drop frames visibly. This is a correctness rule,
  not a preference.
- **`backdrop-filter` and grain/noise overlays ONLY on fixed/sticky elements.** Implement as
  a `position: fixed; inset: 0; pointer-events: none` pseudo-element. Never apply to scrolling
  containers — continuous GPU repaints kill tablet frame rates.
- **`content-visibility: auto`** on long static sections (vendor lists, historical log sections,
  accordion bodies) — CSS-only render deferral before reaching for JS windowing libraries.
- **Metric-matched font fallback** to eliminate layout shift on font swap: use `@font-face` with
  `size-adjust`, `ascent-override`, `descent-override`, `line-gap-override` on the fallback
  declaration so the fallback geometry matches Fraunces/DM Sans. Use `font-display: optional`
  when zero CLS matters more than guaranteed branded font on slow loads.
- **Images: right-sized + dimensions declared** (`width`/`height` or `aspect-ratio`) to kill CLS;
  WebP where we control generation (preview PNGs are fine, they're cache-warmed per SKILL.md).
- **`loading="lazy"` ONLY for naturally-scrolling visible lists** — never on modal/tab-revealed
  images (SKILL.md's deadlock incident, the #1 rule that overrides this section's lazy advice).
- **Defer non-critical scripts** (`defer` on page JS — it hydrates from `window.X`, which is
  inline and ready before DOMContentLoaded).
- **Debounce high-frequency handlers** (type-ahead filter, resize, scroll) — the RM picker filters
  on each keystroke over an in-memory list, which is fine; anything that touches the network or
  re-renders a big DOM gets 150–300ms debounce.
- **Long lists**: past a few hundred rows, paginate/window the DOM (an operator ledger with
  thousands of chips will jank on tablets).

## 8. Charts & dashboards

### Chart-type chooser (the decision table, pruned to our data shapes)

| Data shape | Use | Avoid / switch when | Notes for our dashboards |
|---|---|---|---|
| Trend over time (stock levels, HL/month, costs) | **Line** (area for volume feel) | <4 points → stat card; >6 series → split panels | Distinguish series by line *style* + color, never color alone |
| Compare categories (per-SKU cost, per-supplier spend) | **Bar, sorted descending** | >15 categories → horizontal bars; >50 → table with search | Value labels on bars by default; AAA-friendly |
| Part-to-whole (cost composition, mix) | **Stacked bar / waffle** | Pie/donut ONLY ≤5 slices with one dominant; slices <5% apart are unreadable | Pie is the weakest a11y chart — prefer stacked 100% bar |
| Cumulative ± components (P&L bridge, month-close variance, COGS build-up) | **Waterfall** | >12 bars → aggregate into "Autres" | Green/red + directional arrow per bar (not color alone); running-total column in the fallback table |
| KPI vs target (yield %, efficiency vs budget) | **Bullet chart** (grid of them) | Gauge only for a single hero KPI | Values always visible as text, thresholds labeled in words |
| Spread/outliers (QA measurements across batches) | **Box plot** | <20 points/group → just plot the points | Stats table (min/Q1/médiane/Q3/max) alongside |
| Intensity over 2 axes (activity by hour×day, tank×week) | **Heatmap** | <20 cells → bar | Numeric legend with scale ticks; value on hover |
| Funnel/stage flow (lot pipeline: brassage→fermentation→racking→packaging) | **Funnel/stage bars** | Stages not sequential → bar | Conversion/loss % as text between stages |
| Forecast + actual (utilities predictive model) | **Line + dashed forecast + confidence band** | — | Solid=réel, dashed=prévision — the legend must say so in words |

Skip entirely for operator UI: network graphs, sunbursts >2 levels, treemaps as primary view,
word clouds, 3D — all grade C/D on accessibility; if hierarchy must be shown, a collapsible
indented table is the primary view and the visual is supplementary.

### Chart quality rules (every chart, every time)

- **Legend always visible**, adjacent to the plot (not below a scroll fold); interactive
  (click-to-toggle series) where the lib allows.
- **Tooltips on hover AND tap** with exact values, locale-formatted (§5).
- **Axes labeled with units** (HL, CHF, kg); readable tick spacing, auto-skip ticks on narrow
  viewports rather than rotating/cramming; time axes state their granularity (jour/semaine/mois).
- **Direct-label small datasets** (≤6 values) on the marks themselves — less eye travel than a legend.
- **Gridlines low-contrast** (a faint ink-mute tone) so data wins; no heavy gradients/shadows on marks.
- **Series distinguishable without color**: line styles, marker shapes, or pattern fills.
- **Three non-data states are mandatory**: loading (skeleton, not an empty axis frame),
  empty ("Aucune donnée pour cette période" + guidance), error (message + retry — never a
  blank/broken frame). The blank-chart symptom is also the `window.X` payload-shape fingerprint —
  run the SKILL.md playbook before styling anything.
- **Data table fallback for a11y-critical or export-worthy charts** (CSV export where operators
  will want the numbers — they always do).
- **>1000 points: aggregate or downsample** before render; don't ship raw event streams to the browser.
- **Entrance animations respect reduced-motion**; data readable immediately, not after a 2s sweep.

## 9. Merged pre-delivery checklist

**If any box cannot be honestly ticked, the surface is not done — fix before delivering.**
Models trained with brevity bias treat "prefer"-phrased rules as optional; binary gates get
complied with. The most-recurred incidents in this project are named explicitly below.

**Auditing across the app — template-group sampling.** When running an accessibility or UX audit
over the full operator UI, identify template groups first: pages that share a layout shell or PHP
module pattern (e.g. all fiche pages, all list+filter pages, all PRG batch forms) audit as one
representative page per group plus every structurally unique page. This collapses ~30 module pages
to a handful of real audits — because a shared layout defect (missing focus ring on the topbar,
wrong touch-target size on a common chip component) is caught once and fixed at the source,
preventing the same finding from being logged 20 times redundantly.

Run this AFTER SKILL.md's "Verification before declaring a UI change done" (browser render,
network-first playbook, cache-bust, body-class scoping, escHtml, smoke battery). This adds the
UX-quality sweep:

- [ ] Contrast: body text ≥4.5:1, large/UI ≥3:1, on the actual surface it sits on
- [ ] Keyboard: tab through the whole surface — order logical, focus visible, no traps, Escape closes modals
- [ ] Skip-to-content `<a href="#main-content">` present as first body element
- [ ] Icon-only buttons have French `aria-label`s; errors use `role="alert"`/`aria-live`
- [ ] Touch targets ≥44px with ≥8px gaps — test the smallest control (chip ✕, row action)
- [ ] No hover-only functionality; hover/active/disabled states all present and distinct
- [ ] `:active` scale feedback present on buttons/chips/row actions
- [ ] Hover effects gated behind `@media (hover: hover) and (pointer: fine)`
- [ ] Async buttons disable during flight; submit shows the full loading→flash cycle
- [ ] Field errors: below the field, cause+fix wording (see §3 templates), first invalid field focused
- [ ] No layout shift on load (dimensions reserved for images/charts/async sections)
- [ ] Numbers/dates locale-formatted (DMY, separators, CHF decimals); numeric columns tabular
- [ ] Animations ≤300ms enter, exits shorter; `ease-out` (not `ease-in`) for both; reduced-motion respected
- [ ] No `window.addEventListener('scroll', …)` for visual effects; no `backdrop-filter` on scrolling containers
- [ ] Charts: legend + tooltips + labeled axes + loading/empty/error states + not-color-alone series
- [ ] Empty states say what's missing and what to do next (in French, no DB nomenclature)
- [ ] IA coherence: feature reveals complexity gradually / uses operator nouns+verbs / handles empty+loading consistently with neighboring features
- [ ] Tested at 375px and tablet width; wide tables scroll in their wrapper, not the page

---

## 10. Cognitive load

**Humans hold ≤4 items in working memory at once** (Cowan 2001). At any decision point:
≤4 options = fine; 5–7 = pushing; 8+ = overloaded. Practical limits:
- Navigation: ≤5 top-level items
- Form groups: ≤4 visible fields before a visual break
- Action buttons per section: 1 primary + 1–2 secondary, rest in a menu
- Dashboard KPIs above the fold: ≤4

**Eight violation patterns** — name these when found in a review:
1. **Wall of options** — 10+ choices at once with no hierarchy; group + progressive disclosure.
2. **Memory bridge** — user must remember info from step 1 to act at step 3; keep context visible.
3. **Hidden navigation** — no current-location signal; add breadcrumbs/active states/progress.
4. **Jargon barrier** — DB codes or domain jargon in the UI; use the label-map layer (SKILL.md).
5. **Visual noise floor** — every element has the same weight; establish a clear hierarchy.
6. **Inconsistent pattern** — same action, different widget in different places; standardize.
7. **Multi-task demand** — reading + deciding + navigating simultaneously; sequence the steps.
8. **Context switch** — user jumps between screens to gather info for one decision; co-locate.

---

## 11. Audit severity & scoring

Tag every finding with a priority level — this is the fix order:

| Priority | Meaning | Action |
|---|---|---|
| **P0** | Task cannot complete | Fix immediately — showstopper |
| **P1** | WCAG AA violation or serious difficulty | Fix before release. Rule of thumb: "if you'd contact support, it's ≥P1" |
| **P2** | Annoyance but workaround exists | Fix in next pass |
| **P3** | Polish — no real user impact | Fix if time permits |

For full audits that need a health score, use the **Nielsen-10 rubric (0–4 per heuristic, 40 total)**:
36–40 = excellent; 28–35 = good; 20–27 = acceptable; 12–19 = poor; 0–11 = critical redesign needed.
(Full per-heuristic 0–4 scoring table: `pbakaus/impeccable` → `skill/reference/critique.md` on
GitHub — not installed locally; fetch on demand if a full scored audit is ever commissioned.)

---

## 12. Persona-based review (ERP-adapted)

Run 2–3 of these for any significant new surface. Walk the primary operator flow as each persona
and name the specific failing elements — not generic descriptions.

**Power operator (brewery lead / Alex archetype)**
Red flags: no keyboard shortcuts for primary actions; one-at-a-time workflow where batch would
be natural; redundant confirms on low-risk actions (chip delete, status toggle); animations that
can't be skipped.
Test: can the core task be completed in under 60 seconds?

**A11y-dependent operator (Sam archetype)**
Red flags: click-only interactions (no keyboard path); missing or invisible focus indicators;
meaning conveyed by color alone (red status chip with no icon or text label); unlabeled form
fields or icon-only buttons.
Test: complete the full primary flow keyboard-only, no mouse.

**Stress tester / edge cases (Riley archetype)**
Red flags: silent failures (action appears to succeed but nothing changed); empty states with
no guidance; data lost on browser refresh mid-form; inconsistent behavior between similar
interactions.
Test: 0 items in a list and 1000 items — does the UI handle both without breaking?

**Floor operator on tablet (Casey archetype — adapted for brewery floor)**
Red flags: primary actions at top of screen (unreachable by thumb with one hand); no state
persistence when operator switches tabs mid-form; touch targets < 44px.
Test: thumb-reachable primary action? All targets ≥44px? State survives a tab switch?
