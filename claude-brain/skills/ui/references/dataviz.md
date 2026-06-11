# Data visualisation rules — maltyweb context

> Source: NTCoding/claude-skillz · data-visualization/SKILL.md
> (https://github.com/NTCoding/claude-skillz/blob/main/data-visualization/SKILL.md)
> Mined 2026-06-07. We took the perceptual/interaction/rendering principles only — colour-palette
> pickers and tech-stack selection guides were dropped; our design system is fixed (see SKILL.md).
> Applicable to: vanilla-JS charts in `public/js/kpi-charts.js` (9 viz types), KPI cards,
> small-multiples panels, dark kraft-paper token palette, floor-tablet operators.

Nothing here overrides `references/ux-quality-rules.md §8` (chart-type chooser, mandatory states)
or SKILL.md's rendering-bug catalog. These rules deepen the why.

---

## 1. Cleveland-McGill perceptual encoding hierarchy

Visual encodings ranked from most to least perceptually accurate (Cleveland & McGill, 1984):

1. **Position along a common scale** — most accurate
2. Position on non-aligned scales
3. **Length**
4. **Angle / slope**
5. **Area**
6. Volume
7. **Color saturation / hue** — least accurate

**Practical implication for our charts:**
- Bar charts (position-based) are more accurately read than pie/donut charts (angle-based), which
  are more accurately read than bubble charts (area-based). Default to bar; justify any departure.
- KPI cards should encode the key number as a positional readout (a clear numeric with a progress
  bar) before reaching for a gauge or donut.
- Never use bubble size as the *only* encoding for a critical quantity — pair it with a label or
  position axis.

---

## 2. Preattentive attributes (processed in <250 ms, before conscious attention)

- **Color** (hue, saturation)
- **Form** (orientation, length, width, size, shape)
- **Spatial position**
- **Motion**

Use preattentive pop-out intentionally: one attention-grabbing signal per view. In our dark palette
the `--hop` green and `--ember` orange already function as preattentive signals on the ink-soft
background — don't add a third accent that competes. On charts: a single highlighted bar, a
threshold line in `--ember`, or an above/below color split are each one preattentive cue. Using
all three simultaneously cancels the effect.

---

## 3. Channel effectiveness by data type

| Data type | Best channels | Avoid |
|---|---|---|
| Quantitative | Position, length, (then angle, area) | Volume, color hue alone |
| Ordinal | Position, density, saturation | Hue (implies categories, not order) |
| Categorical | Shape, hue, spatial region | Length differences (imply magnitude) |

Applied: recipe names and beer families are **categorical** → distinguish by hue token
(`--hop`/`--ember`/`--oak`/`--cold`/`--bbt`) or spatial grouping, NOT by bar length differences.
Monthly HL, CHF amounts, and yield % are **quantitative** → position/length (bar, line) is
canonical; hue is a secondary redundant encoding only (never the primary signal).

---

## 4. Shneiderman's "overview first, zoom and filter, details on demand"

For dashboard interaction design:

1. **Overview** — Show the full dataset with full context on load. Never start zoomed-in;
   operators need the big picture before drilling (e.g. a full-year HL trend before month detail).
2. **Zoom & filter** — Period pickers, SKU/recipe filters, category toggles reduce the view.
   Filter controls are adjacent to the chart, never buried in a settings panel.
3. **Details on demand** — Tooltips on hover/tap, expandable row-detail panels, CSV export.
   Do NOT front-load labels for every data point; surface them on interaction.

In `kpi-charts.js` this maps to: default time-range covers the full available window → a range
picker lets the operator focus → tooltip on tap gives the exact value. Each chart should have all
three levels before shipping.

---

## 5. Rendering technology thresholds

| Element count | Technology | Reason |
|---|---|---|
| **< 1 000** | **SVG** | DOM events, CSS styling, ARIA accessibility, zoom-clean |
| **1 000 – 10 000** | **Canvas** | Batch render, lower memory; lose CSS/ARIA |
| **> 10 000** | **WebGL** | GPU acceleration; complex setup, avoid unless necessary |

**Our reality:** KPI charts and small-multiples typically stay well under 1 000 rendered elements.
Use SVG (or a library that emits SVG) for all standard dashboard charts — accessibility and
CSS-token integration come for free. Only consider Canvas for dense time-series explorers
(e.g. sub-daily energy readings). WebGL is out of scope for operator UI.

If a dataset approaches 1 000 visible points, **aggregate or downsample server-side** rather than
switching to Canvas — the data-quality rules already require this (§8 of ux-quality-rules.md:
">1 000 points: aggregate before render").

---

## 6. Colorblindness and "never rely on color alone"

- **~8% of men and ~0.5% of women** have some form of color vision deficiency.
  Red-green (deuteranopia/protanopia) is the most common pair — the `--hop` green /
  `--ember` orange accent pair falls in this danger zone.
- **Core rule: color is never the only encoding.** Every color-coded state or series distinction
  must have a second channel: shape (marker), line style (solid/dashed/dotted), pattern fill,
  direct label, or icon.
- Specific rules for our palette:
  - Status chips (success/error/warning): color + icon glyph + text label — never color alone
    (already required by ux-quality-rules.md §1, repeated here because chart series often
    re-use the same green/red pair).
  - Chart series: use `--hop`/`--ember`/`--oak`/`--cold`/`--bbt` AND distinct line styles
    (solid, dashed, dotted) or marker shapes — never two lines that differ only in green vs red.
  - Threshold / target lines: label them in words on the chart ("Objectif", "Seuil d'alerte"),
    not purely by color.
  - Heatmaps: choose a perceptually-uniform sequential scale (viridis-style) rather than a
    red-to-green diverging scale; add numeric annotations in cells where space allows.
- Run any new chart palette through a deuteranopia simulator before shipping.
