# Operator Form Mockups — Design Rationale
**Date:** 2026-05-23  
**Files:** `public/_design/operator-form-{overview,brewing,fermenting,new-mi}.html`

---

## Design Decisions

### Aesthetic Direction

Dark aged-oak palette (`--bg #15100a`, `--bg-elev #1d160d`) from the maltyweb canonical theme. Type stack: Fraunces (display), DM Sans (body), JetBrains Mono (labels, lot numbers, MI IDs, technical readouts). Grain texture overlay on `body::before` to evoke stainless-and-wood brewery environment. No light-cream, no purple gradients, no Inter.

Each form uses a different state accent:
- Brewing → `--ember #c5664a` (active wort)
- Fermenting → `--bbt #6593b8` (clarified beer in CCT)
- New MI → `--oak #a07a48`
- Overview → Fraunces display serif, multi-accent per card type

Touch targets: all interactive controls ≥ 44px minimum height. The "+ Add ingredient" button is 54px tall with a generous hit area.

---

## Anti-Pattern Resolution (from `project_maltyweb_native_form_inputting_design.md`)

| # | Anti-pattern | How addressed in mockup |
|---|---|---|
| 1 | Silent truncation | `DECIMAL(10,3)` documented in rationale; form accepts any numeric input without clamping |
| 2 | Silent MySQL strict failures | Not a form concern; documented in integration checklist below |
| 3 | Cron crash global | Eliminated: direct POST, no cron, no intermediate step |
| 4 | Panne invisible | Confirmation strip shows "submitted by you at HH:MM — N ingredients normalized" immediately after POST |
| 5 | Swap colonnes | Fermenting form: if `FG ∈ [3.5, 7.5]` and `pH > 8`, a non-blocking swap-detection panel fires |
| 6 | Re-soumission au lieu d'édition | Confirmation strip links to "Voir / réviser →"; edit flow returns UPDATE not INSERT |
| 7 | Phantoms MySQL | Form POSTs with `batch_id + event_type + operator_id` composite key; backend UPSERT |
| 8 | Inversion ligne correcte | Diff visual (v1→v2) planned via confirmation strip; mockup shows the strip |
| 9 | Confusion d'unités | Every field shows unit inline: `°Plato`, `g/L`, `ppb`, `°C`, `kg` — never ambiguous |
| 10 | Validation aveugle au type produit | Fermenting form: beer-type ranges read from recipe (mock: Pale Ale 13-18°P OG, 1-5°P FG, pH 4.1-4.6) |
| 11 | Pas de retry surgical | LocalStorage draft: closing tab preserves all entered rows; restores on reopen |
| 12 | Deep link source cassé | Confirmation strip uses anchor `#B2503` style links, not hardcoded tab GIDs |

---

## Soft Validation Rules Encoded (Fermenting form)

All rules are **non-blocking** — operator always submits. Outlier → comment required (not blocking, documenting).

| Field | Beer type | Typical range | Outlier threshold | Action |
|---|---|---|---|---|
| OG | Pale Ale | 13–18°P | < 11°P or > 20°P | Warning panel: "OG hors range" |
| FG | Pale Ale | 1–5°P | < 0.5°P or > 6°P | Warning panel: "FG hors range" |
| pH | Pale Ale | 4.1–4.6 | < 3.5 or > 5.5 | Warning panel: "pH hors range" |
| Temp | Fermentis US-05 | 18–22°C | < 14°C or > 26°C | Warning: "température inhabituelle" |
| Attenuation | Pale Ale | 75–85% | < 65% or > 90% | Visual change on attenuation display |
| Swap detect | All | FG≠pH | FG ∈ [3.5,7.5] AND pH > 8 | Swap-detection panel (non-blocking) |

For brewing form, quantity outlier threshold per MI (mock values from `OUTLIER_THRESHOLDS` in `operator-form-brewing.html`):
- `MALT_PILSENER`: 35 kg (single batch)
- `HOPS_HERKULES`: 500 g
- `YEAST_W34`: 1500 g
- `MIN_CACL2`: 50 g
- Default: 1000 (any unit)

---

## Memorable Detail Per Page

| Page | Detail |
|---|---|
| `operator-form-brewing.html` | Faint hop-cone SVG watermark bottom-right (`body::after`). Category-chip **color-pop** animation on MI pick (scale 1→1.35→1, 300ms). |
| `operator-form-fermenting.html` | Fermentation **S-curve sparkline** watermark top-right (`body::after`), OG→FG descent curve in `--bbt #6593b8` at 12% opacity. Range-bar cursor slides smoothly as operator types density values. |
| `operator-form-new-mi.html` | MI_ID **takes shape as you type**: prefix appears when category selected (colored per category), suffix builds character-by-character from the name field, blinking cursor visible until both prefix and suffix are present. Left-edge accent on the preview card transitions color when category changes. |
| `operator-form-overview.html` | Each event card has a **technical SVG illustration** (engineering-drawing aesthetic): kettle with steam puffs + wort fill for Brewing; CCT cross-section with bubble animation for Fermenting; conveyor belt + bottles for Packaging; CCT→BBT transfer with butterfly valve for Racking. All drawn in stainless (`--steel #b8bcc0`) with state fluid fills. |

---

## PHP Integration Checklist

When integrating into live maltyweb modules:

### Auth
- Session `$_SESSION['user_id']` → embed as hidden field or in POST body
- CSRF token: `$_SESSION['csrf_token']` → `<input type="hidden" name="csrf">` + server-side verify

### POST Endpoints
| Form | Endpoint | Table | Action |
|---|---|---|---|
| Brewing | `POST /forms/brewing` | `bd_brewing_ingredients_parsed` | UPSERT on `(brassin_id, mi_id)` |
| Fermenting | `POST /forms/fermenting` | `bd_fermenting` | UPSERT on `(brassin_id, event_date, metric)` |
| New MI | `POST /api/mi/create` | `ref_mi` | INSERT; return `mi_id` JSON |

### ref_mi Search SQL (for typeahead autocomplete endpoint)
```sql
SELECT
  mi_id,
  name,
  category,
  unit
FROM ref_mi
WHERE is_active = 1
  AND (
    LOWER(mi_id)   LIKE CONCAT('%', LOWER(:q), '%')
    OR LOWER(name) LIKE CONCAT('%', LOWER(:q), '%')
    OR LOWER(category) LIKE CONCAT('%', LOWER(:q), '%')
  )
ORDER BY
  CASE WHEN LOWER(mi_id) LIKE CONCAT(LOWER(:q), '%') THEN 0 ELSE 1 END,
  mi_id
LIMIT 15;
```
Emit as JSON array: `[{id, name, cat, unit}, …]` — matches the `MI_CATALOG` shape in mock data.

### LocalStorage Draft Migration
- Draft key pattern: `brewing_draft_{brassin_id}`, `ferm_draft_{brassin_id}`
- On successful POST: `localStorage.removeItem(draftKey)` (already wired in mock)
- Server should return `{ ok: true, submission_id: N, submitted_at: ISO }` for the confirmation strip

### Audit Log
Every write must emit to `audit_log`:
```sql
INSERT INTO audit_log (table_name, row_id, user_id, action, before_json, after_json, created_at)
VALUES (?, ?, ?, 'INSERT'|'UPDATE', ?, ?, NOW())
```

### Soft Validation on Server Side
Client-side validation is UX only. Server must also:
1. Compute `qc_flag = 'normal'|'elevated'|'outlier'` from physical thresholds (ref `bd_*` schema widened thresholds, chantier 1 bis)
2. Require `comment` column not null when `qc_flag = 'outlier'` (soft constraint: accept but log)
3. Detect swap: if `gravity_fg BETWEEN 3.5 AND 7.5 AND ph > 8`, set `qc_flag = 'possible_swap'`

### Scoping
Port CSS selectors under `.home` prefix as per maltyweb convention. Remove `body::before`/`body::after` grain/watermark from inline `<style>` — those already live in `app.css` at the page level. Import Fraunces/DM Sans/JetBrains Mono from `public/css/app.css` `@import` (already loaded globally).

---

*Mockups only. No PHP files modified. No existing files moved or deleted.*
