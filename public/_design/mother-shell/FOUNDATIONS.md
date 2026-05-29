# FOUNDATIONS — Mother Shell Board (Concept C: Theater of Operations)
## La Nébuleuse Brewery · MaltyTask · Stage A

> **Status:** Stage A locked — valid for Stages B, C, D  
> **Date:** 2026-05-29  
> **Output files:** `FOUNDATIONS.md` (this file) + `board-populated.html` (hero mockup)

---

## 1. Aesthetic Direction

### The Fusion: Swiss Precision Brewing · WWII Ops Room · Kraft Paper Mill

The chosen aesthetic fuses three lineages into one coherent visual world:

**Swiss engineering schematic (1930–1960).** Swiss industrial drawing tradition — precise, confident linework on off-white cartridge paper. Dimension lines. Registration marks in the corners. Zone boundaries rendered as crisp single-pixel rules, not heavy borders. The diorama reads like a cross-section drawing of the brewery from above, annotated in JetBrains Mono. The aesthetic respects that the operator *understands the equipment* — no skeuomorphic decoration that would patronize a professional.

**WWII operations room.** The four zones are not sections of a website — they are theater sectors on a plotting board. Batch cards are unit markers advancing through hostile territory (fermentation, time, biology). The zone labels (`BRASSERIE`, `CAVE`, `SALLE BBT`, `CONDITIONNEMENT`) feel like sector call-signs. Ink stamps (`MOÛT`, `CLÔTURÉ`) echo the rubber stamps of a dispatch office. The heartbeat pulse is the radio signal — green means unit is active, amber means last contact was a while ago, red means the unit needs attention. The "Salle de Guerre" (W shortcut) is the panic-room overlay — it escalates the visual register to urgent dispatch.

**Kraft paper mill.** The surface is warm paper — not sterile white, not dark walnut, but the specific warmth of manila kraft. The grain overlay (CSS `feTurbulence`) gives it the slight texture of bond paper. Radial gradient washes (oak warm from top-left, hop cool from bottom-right) give the page dimensionality without competing with the data. This connects to the physical brewery: the operator has handled kraft-paper labels, cardboard boxes, invoice paper. The UI should feel like it belongs in that material world.

**Why this fusion wins over pure alternatives.** A pure engineering schematic would be cold and clinical — wrong for a craft brewery where batch names like "Embuscade" carry personality. A pure WWII ops room would be dramatic but would fight the light-kraft palette constraint. A pure heritage-brewery aesthetic (copper kettles, gold leaf) would be decorative noise that distracts from operational information. The fusion takes the *precision* of schematics, the *strategic clarity* of an ops room, and the *material warmth* of the kraft palette, and layers them without conflict.

---

## 2. Typography Scale

Font stack: **Fraunces** (display serif, optical size variants) · **DM Sans** (body, UI) · **JetBrains Mono** (data, labels, codes)

| Role | Font | Size | Weight | Tracking | Line-height |
|---|---|---|---|---|---|
| Page title (board name) | Fraunces `opsz:144` | 1.6rem | 200 | −0.025em | 1.1 |
| Zone header label | JetBrains Mono | 0.57rem | 500 | +0.18em (UPPERCASE) | 1 |
| Card title (beer name) | Fraunces `opsz:96` | 1.0rem | 300 | −0.01em | 1.15 |
| Body text (descriptions) | DM Sans | 0.82rem | 400 | 0 | 1.55 |
| Mono data (KPIs, vessel IDs, batch codes) | JetBrains Mono | 0.62rem | 500 | +0.06–0.10em | 1 |
| Micro text (timestamps, labels, badges) | JetBrains Mono | 0.52–0.56rem | 400–500 | +0.10–0.18em | 1 |
| Stamp text (MOÛT, CLÔTURÉ) | JetBrains Mono | 0.52rem | 600 | +0.14em (UPPERCASE) | 1 |
| ETA badge | JetBrains Mono | 0.54rem | 400 | +0.06em | 1 |
| Navigation tabs | JetBrains Mono | 0.62rem | 400–500 | +0.10em | 1 |

**Typographic decisions:**
- Beer names (`Embuscade`, `Zepp`, `Stirling`) always in Fraunces, usually with `<em>` italic for the name part — this is the design system convention from `session-shell.css`.
- Batch numbers (`#244`, `#312`) in JetBrains Mono immediately following, to distinguish the poetic (beer identity) from the operational (instance reference).
- Vessel IDs (`CCT-4`, `BBT-2`) in JetBrains Mono with a cold-tinted pill background — signals "physical location, not identity."
- All caps labels use +0.14em minimum tracking; wider tracking (0.18–0.22em) for very small labels (zone headers, section dividers).

---

## 3. Color Usage Map

All colors are token references — no raw hex. Tokens come from `app.css :root`.

| Token | Value | Semantic Role |
|---|---|---|
| `--bg` | #f1e8d4 | Page ground — kraft paper |
| `--bg-elev` | #ece0c6 | Card surfaces, headers — manila |
| `--bg-side` | #dcc9a4 | Table header bands, zone accent bands — kraft |
| `--ink` | #241b10 | Primary text — near-black ink |
| `--ink-soft` | #3a2a18 | Secondary text, card body |
| `--ink-mute` | #4a3820 | Labels, meta, timestamps |
| `--ink-faint` | #c8b48a | Dividers used as text (placeholder zones), ghost-card text |
| `--hairline` | #c8b48a | Thin rules, card borders |
| `--hairline-2` | #a08060 | Stronger dividers, hover borders |
| `--oak` | #8b5e2a | Links, secondary accent, racking zone accent — wood |
| `--oak-deep` | #5a3a12 | Oak hover state, text on elevated surfaces |
| `--hop` | #567020 | Primary accent, active states, packaging zone — green, output, completion |
| `--hop-soft` | #7a8f3a | Muted hop accent |
| `--hop-deep` | #3f4d14 | Deep hop for text on elev |
| `--ember` | #b34428 | Brewing zone — hot, active, danger |
| `--cold` | #2f5575 | Fermenting zone — cool, biological, lagering |
| `--bbt` | #2f6d99 | Racking zone / BBT accent — transfer blue |
| `--ok` | #3d6826 | Heartbeat green (< 24h) |
| `--steel` / `--steel-mid` / `--steel-deep` | various | SVG tank metal — graphical only, never as text |
| `--tank-empty` | #cfc6b2 | Empty tank fill |
| `--kf-stamp-red` | #7a2f25 | MOÛT / CLÔTURÉ stamp color variant |
| `--kf-stamp-navy` | #27384a | Alternative stamp for closed lots |

**Zone color assignments (the semantic foundation of the diorama):**
```
BRASSERIE       → --ember (fire, heat, boil)
CAVE FERMENTATION → --cold (cold, blue, biology, time)
SALLE BBT       → --bbt  (transfer blue, racking)
CONDITIONNEMENT → --hop  (green, completion, output)
EXPÉDITION      → --ink-faint (greyed/dashed, Stage-3)
```

**Heartbeat pulse states:**
```
< 24h last activity  → --ok    (green, healthy, active unit)
24–72h last activity → --oak   (amber, watch this, not urgent)
> 72h last activity  → --ember (red, attention required, unit silent)
```

**Status states:**
```
MOÛT stamp       → --oak border + color (wort contract)
CLÔTURÉ stamp    → --ink-faint border + color (ghost mode)
Garde seuil flag → --oak (approaching) / --ember (exceeded, act now)
ETA badge        → --cold (informational blue, historical median)
```

**No new tokens needed.** The existing palette is sufficient for the full diorama. The engineering-schematic registration marks and crosshatch textures use CSS only (repeating gradients in `--hairline`/`--ink`), not additional tokens.

---

## 4. Iconography and SVG Language

### Philosophy
Linework is the primary mode. Everything is *drawn*, not photographed or illustrated with gradients for effect. The line weight convention: vessel outlines at 1.0–1.2px stroke; fittings and pipes at 0.5–0.7px; annotation lines at 0.3–0.4px. Filled shapes use token-referenced colors at reduced opacity (0.7–0.85) for liquid fills; steel surfaces use gradients but the gradient stops reference `--steel-*` tokens.

### CCT Cylinder (fermentation tank)
- Tall cylindrical vessel with conical bottom. Ratio: approximately 1:2 height-to-width.
- Body: `--steel-deep` fill with `--steel-mid` stroke.
- Specular band: vertical light gradient on left quarter (the key-light comes from upper-left), using `rgba(255,255,255,0.15)` — this is the critical fix from the `3c57e5b` uglification analysis. On a light ground, steel reads as steel because it reflects light specularly, not because it is dark. A near-white specular band on the left edge makes the cylinder read as rounded brushed metal.
- Contact shadow: `--steel-shadow` soft ellipse at base.
- Fittings: thermometer port (left side), sample valve (right side), pressure gauge (small), drain at cone bottom.
- Fill indicator: liquid fill block clipped to vessel shape, colored by state.
- Number label: JetBrains Mono, center of body, `rgba(0,0,0,0.75)` with white paint-order stroke for legibility on any fill.

### BBT Cylinder (bright beer tank)
- Cylindrical vessel with elliptic top and bottom caps (no cone). Wider and squatter proportions than CCT.
- Same steel treatment as CCT.
- Two horizontal strap lines (structural bands) at 1/3 and 2/3 height — visually distinguishes BBT from CCT at a glance.
- Pressure gauge on left side (more prominent than CCT — BBTs are pressurized).

### Brewhouse Kettle Silhouette (Brasserie zone)
- Squat vessel, wide at top, narrowing to a short cylindrical bottom — classic brew kettle profile.
- Heating jacket suggested by horizontal hatch pattern at base.
- Steam/vapour suggestion via CSS animation when active: a subtle radial gradient pulsing at opacity 0.0→0.08 at the top of the vessel area.
- Whirlpool indicator: small circular arrow glyph near top.

### Packaging Line Silhouette (Conditionnement zone)
- Horizontal conveyor belt silhouette: two rollers on ends, flat belt surface with subtle dashed motion lines.
- Filler head(s): vertical forms above belt.
- Animation when active: dashed belt lines translate 6px left every 2s, suggesting motion.

### Dispatch Truck Silhouette (EXPÉDITION zone — greyed)
- Simple side-profile box truck silhouette. Two wheels (circles). Cab on right.
- Rendered in `--ink-faint` fill at 50% opacity.
- Dashed border outline in `--hairline`.
- "Phase 3" label in JetBrains Mono below, also faint.

### MOÛT Stamp
- Rectangle with slightly rotated (`rotate(-2deg)`) border in `--oak` (1.5px stroke).
- Inside: "MOÛT" in JetBrains Mono uppercase, `--oak` color.
- Visual register: rubber stamp. The rotation is essential — a perfectly straight stamp is a printed label, not a stamp.
- Positioned in the top-right corner of the card.

### CLÔTURÉ Stamp
- Same shape as MOÛT stamp but in `--ink-faint` color.
- Text: "CLÔTURÉ".
- Ghost cards in the closed strip use this stamp at 65% opacity total.

### Heartbeat Pulse Dot
- 7px circle with `box-shadow` glow matching the fill color.
- Three animation rhythms by state: green = slow 2.8s breathe; amber = medium 2.2s breathe; red = fast 1.5s breathe. The rhythm communicates urgency through cadence, not just color.
- Text alternative on hover: "Dernière activité: il y a Xh" (tooltip).

---

## 5. Animation Language

| Motion | Trigger | Spec | Justification |
|---|---|---|---|
| Heartbeat pulse (green) | always-on | opacity 0.5→1.0, scale 0.85→1.0, 2.8s ease-in-out infinite | Communicates active, healthy unit |
| Heartbeat pulse (amber) | always-on | opacity 0.45→1.0, scale 0.8→1.0, 2.2s, faster rhythm | Communicates watchfulness |
| Heartbeat pulse (red) | always-on | opacity 0.3→1.0, scale 0.7→1.1, 1.5s, urgent rhythm | Communicates attention required |
| Kettle steam | active brewing batch | radial gradient opacity 0.0→0.08 at zone top, 3.5s ease-in-out infinite | Suggests heat, without distracting |
| Conveyor belt | active packaging batch | CSS transform translateX 0→−6px on dashes, 2s steps(1) infinite | Suggests motion, physically grounded |
| Card row-in | new mother spawned | opacity 0→1 + translateY 8px→0, 0.3s ease-out | Spatial grounding on appearance |
| Zone drawer slide-in | click zone header | translateX 100%→0 on right panel, 250ms ease-out | Direction matches spatial model (from right) |
| Card hover lift | hover | translateY 0→−1px, 0.12s ease | Affordance: "this is clickable" |
| Active card flash | click highlights row | background flash to hop tint, 0.6s ease-out | Confirms the cross-panel link |
| Card MOÛT stamp pulse | always-on for wort contracts | none (stamp is static, distinguished by color+rotation alone) | Animation would compete with heartbeat |
| Salle de Guerre entry | press W | full-screen overlay fade-in + backdrop blur, 0.2s | Escalation is visible, not jarring |

**What does NOT animate:** zone backgrounds, card borders, typography, dividers, vessel fills on load. Animation is reserved for living data signals (heartbeat) and user feedback (hover, click).

---

## 6. Spacing and Layout Grid

Base unit: **8px**. All spacing is a multiple of 4px (half-unit) or 8px. Exceptions noted.

| Surface | Spacing |
|---|---|
| Page padding (no sidebar) | 0 — board uses full width |
| Topbar height | 50px |
| Zone header height | ~42px (9px top/bottom padding) |
| Card padding | 10px 11px 9px |
| Card gap (within zone) | 8px |
| Section gaps (within card) | 4–6px |
| Diorama height | `calc(56vh - 50px)` to `calc(68vh - 50px)` — approx 55–60% of viewport |
| Batch list height | `max-height: 44vh` (collapsible) |
| Zone column widths (5-col) | `1fr 2fr 1.5fr 1.2fr 0.7fr` — EXPÉDITION narrower as a visual indicator of its placeholder status |
| Vessel icon width (CCT small) | 44px SVG `viewBox="0 0 80 155"` |
| Vessel icon width (CCT large) | 56px |
| Vessel gap within row | 10px |
| Zone body bottom padding | 20px (ground-line clearance) |
| Batch list column structure | `90px 1fr 100px 120px 80px 70px 80px 1fr` |

**Diorama vs list split:** 56% diorama / 44% list at 1440px viewport width. On smaller screens (tablet), diorama compresses to 2×2 grid, list remains below.

---

## 7. Component Vocabulary

Complete list of `sb-*` components (all implemented in `_shared.css`):

| Component | Description |
|---|---|
| `sb-topbar` | Page-level navigation bar with brand + nav tabs + right cluster |
| `sb-wordmark` | Brand mark: name + section label |
| `sb-nav` / `sb-nav__item` | Horizontal tab navigation |
| `sb-nav__badge` | Count chip on nav tab |
| `sb-topbar__clock` | Live timestamp display |
| `sb-avatar` | User avatar circle |
| `sb-diorama` | The theater canvas: 5-column grid of zones |
| `sb-zone` | One zone (Brasserie / Cave / BBT / Conditionnement / Expédition) |
| `sb-zone__header` | Zone header strip with label + count |
| `sb-zone__label` | Zone name (JetBrains Mono, uppercase, tracked) |
| `sb-zone__count` | Active batch chip count |
| `sb-zone__body` | Zone content area: vessels + cards |
| `sb-zone--placeholder` | Greyed hatched variant for EXPÉDITION |
| `sb-zone__corner` | Engineering crosshatch corner decoration |
| `sb-ground` | Horizontal schematic ground line at zone base |
| `sb-vessel-row` | Row of vessel SVGs aligned at base |
| `sb-vessel` | One vessel wrapper (SVG + label) |
| `sb-vessel__label` | Vessel ID label below SVG |
| `sb-vessel__label--occupied` | Occupied state (cold tint) |
| `sb-cards-stack` | Stack of mother cards overlaid on zone body |
| `sb-card` | One mother shell card |
| `sb-card--brewing` / `--fermenting` / `--racking` / `--packaging` / `--mout` | Phase accent bar variant |
| `sb-card--active` | Active / highlighted card |
| `sb-card--flash` | Click-flash animation |
| `sb-card__top` | Top row of card: batch ref + stamp |
| `sb-card__batch` | Batch reference (JetBrains Mono) |
| `sb-card__stamp` | MOÛT or CLÔTURÉ rubber stamp |
| `sb-card__name` | Beer name in Fraunces |
| `sb-card__meta` | Row of meta chips |
| `sb-card__meta-item` | One meta data point |
| `sb-card__meta-item--vessel` | Vessel chip (cold tint) |
| `sb-card__meta-dot` | Separator dot between meta items |
| `sb-heartbeat` | Pulse dot |
| `sb-heartbeat--green` / `--amber` / `--red` | State variants |
| `sb-eta` | ETA badge chip (cold tint) |
| `sb-card__link` | "Voir détails →" link |
| `sb-card__merged-badge` | Fusion badge on surviving mother |
| `sb-progress` | Progress bar (packaging fill) |
| `sb-progress__fill` | Progress bar fill |
| `sb-zone-empty` | Empty zone placeholder |
| `sb-batch-list` | Bottom list panel |
| `sb-batch-list__head` | Sticky column headers |
| `sb-batch-list__th` | Column header cell |
| `sb-batch-row` | One batch list row |
| `sb-batch-row--active` | Highlighted row |
| `sb-batch-row__ref` | Batch reference column |
| `sb-batch-row__name` | Beer name column |
| `sb-phase-pill` | Phase indicator pill (per phase variant) |
| `sb-batch-row__td` | Generic data cell |
| `sb-batch-row__link` | Row action link |
| `sb-closed-strip` | Ghost-card strip (recently closed) |
| `sb-ghost-card` | One ghost card |
| `sb-ghost-card__closed-stamp` | CLÔTURÉ stamp on ghost |
| `sb-shell` | Mother drill-in page shell |
| `sb-shell__header` | Drill-in sticky header |
| `sb-shell__back` | Back navigation link |
| `sb-shell__title` | Drill-in title (Fraunces) |
| `sb-shell__meta` | Drill-in meta row |
| `sb-arc` | 4-zone progress arc strip |
| `sb-arc__zone` | One arc zone (done/active/future) |
| `sb-shell__body` | 2-column body: content + rail |
| `sb-shell__footer` | Sticky footer with CTAs |
| `sb-live-card` | Live status card |
| `sb-live-dot` | Live pulse dot |
| `sb-timeline` | Child session timeline |
| `sb-timeline-event` | One event row |
| `sb-face-tabs` | Production/Coût/Qualité tabs (backlog v1) |
| `sb-composition` | Blend/merge composition panel |
| `sb-comp-source` | One source mother in composition |
| `sb-guerre` | Salle de Guerre panic overlay |
| `sb-guerre__card` | Critical alert card in Guerre mode |
| `sb-modal-backdrop` / `sb-modal` | Cuve vide confirmation modal |
| `sb-kpi` / `sb-kpi-row` | KPI tile and row |
| `sb-btn` | Button (variants: primary/secondary/danger/cta) |
| `sb-label-badge` | Status badge (hop/ember/cold/oak) |
| `sb-parent-strip` | Daily shell parent-link strip |
| `sb-close-card` | Close-reason card in modal |

---

## 8. Interaction Model

| Action | Trigger | Response |
|---|---|---|
| Highlight batch | Click vessel SVG in diorama | Flash `.sb-card--flash` on matching card; scroll batch-list row into view; add `--active` class to row |
| Open batch shell | Click card / row link | Navigate to `session-shell.php?id=…` (href="#" in mockup) |
| Zone summary drawer | Click zone header | Slide-in right panel (250ms ease-out translateX) with zone KPIs and batch list filtered to this zone |
| Card micro-tooltip | Hover card | Show vessel ID, last event description, opener name (native `title` attribute in mockup, custom tooltip in production) |
| Salle de Guerre | Press `W` | Full-screen `.sb-guerre--visible` toggle; Escape or button closes it |
| Live refresh indicator | Auto every 15s | "Dernière màj: il y a Xs" counter in topbar right |
| Row active feedback | Click row | Add `--active` to row; flash matching diorama card |
| Zone drawer close | Click outside or ×  | Reverse slide-out animation |

---

## 9. Accessibility Notes

- Zone headers are `<h2>` elements visually styled via `.sb-zone__label`.
- Card beer names are `<h3>` elements.
- Landmark `<nav>` wraps the topbar navigation.
- Vessel SVGs include `<title>CCT 4</title>` and `aria-label="Cuve de fermentation CCT 4"`.
- Heartbeat dots include `title` attribute: "Dernière activité: il y a 2h" — text alternative to color-only signal.
- Phase accent bars (left edge of cards) are visual only — the phase is also stated in text via the meta chip.
- Zone color is never the only zone signal — zone headers always include the text label.
- All tap targets ≥ 40px height in the batch list (7px row padding + content height).
- Tablet/iPad landscape (≥ 1024px wide): diorama remains 5-column; below 1000px: 2×2 grid; below 768px: vertical stack.
- Color contrast: all tokens used as text verified ≥ 4.5:1 against `--bg` (#f1e8d4) and `--bg-elev` (#ece0c6) per the WCAG AA rule in `conventions-and-helpers.md`. `--oak` passes on paper (4.62:1) but not on `--bg-elev` — use `--oak-deep` for card-surface text.

---

## 10. Risks and Open Design Questions

**R1 — Diorama height on small screens.** At 1280px height, a 56vh diorama leaves adequate room for the batch list (44vh). At 900px height (common 13" laptop), the diorama may feel cramped at 56vh × 900px = 504px, with a topbar eating 50px = 454px usable. The zone headers alone are 42px, leaving 412px for vessels + cards. Test on 1280×800 viewport.

**R2 — CCT vessel row width in Cave Fermentation zone.** 8 CCTs at 44px each = 352px + 7 × 10px gaps = 422px. The Cave Fermentation zone at 2fr in a 1440px board is approximately 480px — tight but workable. Vessels scale down via `flex: 0 0 auto` with a smaller explicit width if the zone narrows. **Validated in mockup: 8 CCTs visible at 44px each.**

**R3 — Vessel SVG rendering on the light ground.** The current `svg-tanks.php` post-`3c57e5b` uses dark-on-dark shadow treatment (inverted highlights). The mockup uses a reworked SVG directly (inline, light-ground-aware). The production build (Stage B) must reconcile `svg-tanks.php` with this mockup's improved rendering. The rework strategy: add a specular band (`rgba(255,255,255,0.15)`) on the left face, keep the contact shadow ellipse. Do NOT invert highlights to dark values — that is the root cause of the uglification.

**R4 — Garde seuil flag (amber pulse for STI 88 in mockup).** The garde seuil threshold is a commissioning setting (SDC). In the mockup, STI 88 shows amber pulse + a garde seuil approaching badge. In production, this requires the ETA + garde resolver to run at read time. The badge must never appear if the resolver hasn't fired — guard with `$garde_remaining_days !== null`.

**R5 — Ghost card strip vs. 24h window.** The closed-strip shows lots closed within the last 24 hours. In v1, "ghost mode" is listed as backlog — so the strip shows truly-closed lots, not fading ghosts. The `CLÔTURÉ` stamp + faded styling (65% opacity) achieves the ghost effect without requiring a separate phase.

**R6 — Card count per zone.** The `sb-cards-stack` has `max-height: calc(100% - 24px)` and scrolls when overflowing. If 4+ batches are in fermentation simultaneously, only 2–3 cards are visible without scroll. This is acceptable operational information density for v1. A zone count chip always shows total.

**R7 — "Lots en cours" nav tab placement.** The topbar follows the existing `app.css` `.home .tb__nav` pattern. In the mockup, nav tabs are: `[Lots en cours ●7] [Journal de bord] [Cuves] [Fournisseurs]`. The existing `sessions.php` is "Journal de bord." The topbar order will need to be confirmed with the operator when `sb-board.php` ships.

**R8 — Operator validation needed: ghost card strip vs. batch list.** Currently the bottom section is the batch list. The ghost strip (`sb-closed-strip`) is a horizontal scroll below the list. In tight viewports this may require the list to collapse. Consider a toggle: "En cours (7)" / "Récents (3)" tab on the batch list header. Flag for Stage C critic.

---

*FOUNDATIONS v1.0 — locked for Stage A. Stage B builds to this vocabulary.*
