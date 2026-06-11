# Financier — Fiche COGS (variation de stock) build

> Read when a task touches the Financier page (`public/modules/financier.php`), the Fiche COGS / variation-de-stock tab, or the `cogs_fiche_*` / `ref_cogs_fiche_categories` tables. Verified live 2026-06-11.

## Page placement (Le Zeppelin IA)
- **`financier.php` = "Pôle Financier", `ref_pages` id=239, page_key=`financier`, label "Financier", `min_role='manager'`, `domain='general'`, is_active=1.** Belongs to **Le Cockpit** (commercial/financial family). Read-only fiscal hub — NEVER recalculates fiscal numbers; consumes pre-computed JSON artifacts (`interfaces/cogs-report-data.json` COP, `interfaces/sales-cogs-data.json` COGS/Marge) + lazy `/api/financier-data.php`.

## Page architecture (verified 2026-06-11)
- **Body class:** `<body class="home financier">`. CSS scoped `body.financier …`.
- **CSS file:** `public/css/financier.css` (linked at financier.php:330 with `?v=filemtime`). app.css linked first (line 329). NO inline CSS — add Fiche styles HERE.
- **JS:** `public/js/financier.js` (+ `kpi-charts.js`). Linked at bottom with `?v=filemtime`.
- **Hydration = PHP→window.JSON→JS** (NOT server-side rendered tables; server pre-renders panel shells + a default slice, JS hydrates). `window.FIN_COP`, `FIN_COP_MONTHS`, `FIN_SALES_TREND`, `FIN_SALES_MONTHS`, `FIN_SALES_DEFAULT_SLICE`, `FIN_GL_MONTHS` etc. injected in a `<script>` block (financier.php:886+) with `$JSON_FLAGS`. Heavy per-SKU detail is lazy-fetched per month via the API endpoint. Follow this pattern for the Fiche: inject seed/monthly series as `window.FIN_FICHE_*`, render in financier.js.

## Tab pattern (verified — 4 tabs today)
- `<nav class="fin-tabs" role="tablist">` with `.fin-tab` buttons carrying `data-tab="<key>"`, `aria-controls="fin-panel-<key>"`, `id="fin-tab-<key>"`, `aria-selected`. Active = `.fin-tab--active`.
- Panels: `<section class="fin-panel" id="fin-panel-<key>" hidden role="tabpanel" aria-labelledby="fin-tab-<key>">`. First panel = `.fin-panel--active` (not hidden).
- **Existing 4 tabs:** `cop` (default/active), `cogs`, `marge`, `sku`. (`sku` = "Coût par SKU", absorbed the retired standalone sku-costs.php per scrapping #32.)
- **Tab-switch JS (financier.js ~L505):** ONE delegated loop over `.fin-tab` — on click toggles `.fin-tab--active` + aria-selected on tabs, then `p.hidden = (p.id !== 'fin-panel-'+target)` + `.fin-panel--active` on panels. Plus a `keydown` arrow-nav handler (~L527). **A 5th "Fiche" tab = add the button + panel + reuse this exact loop (no JS rewrite needed).**
- **Reusable table classes:** `.fin-table` (+ `.fin-table-scroll` wrapper), `.fin-grid-table .fin-table-compact` (+ `.fin-grid-scroll`) for the GL accounting views. Reuse these for the Fiche table — do NOT invent new table classes.

## CSV export convention (app-wide)
- **Comma separator, double-quote enclosure:** `fputcsv($out, [...], ',', '"')`. Canonical exporter = `public/modules/warehouse-export.php` (GL recap CSV, `Content-Type: text/csv; charset=utf-8`, filename `…-{period}-{today}.csv`). financier.php has NO CSV export yet — if Fiche adds one, mirror warehouse-export.php (comma + double-quote, NOT semicolon). NB: `charges-bc.php` only *ingests* CSV (accept=".csv"), doesn't export.

## Tables (LIVE — migs 330 + 332 APPLIED on VPS 2026-06-11; applied=339, 0 pending)
**Correction to stale index note:** mig 332 was reported "NOT applied" when a parallel dashboard commit (`fb2778a`) swept it in — it is now APPLIED. Do NOT re-create.

### `ref_cogs_fiche_categories` (mig 330) — 12 rows, the fiche category master
`id`(PK uint), `category_key`(varchar32 UNIQUE), `label_fr`(varchar64), `inv_gl`(varchar16), `charge_gl`(varchar16), `display_order`(smallint, MUL), `is_brewing`(tinyint def 0), `is_active`(tinyint def 1), created_at, updated_at.
12 categories in display order: Malt(1200.1/4101,brewing) · Hops(1200.2/4102,brewing) · Ingredients/Ingrédients(1200.3/4104,brewing) · Yeast/Levures(1200.4/4103,brewing) · Cartons(1201.1/4207) · Inliner(1201.2/4205) · Capsules(1201.3/4200) · Verres(1203.0/6607) · Bouteilles(1204/4200) · Etiquettes(1205/4206) · Canettes(1207/4202) · CapsFuts(1221/4209). First 4 `is_brewing=1`, rest packaging.

### `cogs_fiche_seed` (mig 332) — 12 rows = the EXT-April-signed anchor (one per category for month 2026-04)
`id`, `month_key`(char7 MUL), `category_key`(varchar32 MUL), `rm_chf` `wip_chf` `fg_chf`(decimal14,4), `total_chf`, `opening_chf`, `variation_chf`, `seed_source`(varchar64 def 'EXT-April-signed'), `seeded_at`. **This is the immutable April-2026 signed anchor (opening of May = closing of April).** e.g. Malt total 54351.88 / opening 50604 / variation +3747.88.

### `cogs_fiche_monthly` (mig 332) — 0 rows (EMPTY — compute not yet wired)
Same shape as seed + `basis_adjustment_chf`(decimal14,4 NULL), `computed_at`(datetime), `row_hash`(char64 UNIQUE — idempotency key). This is where computed per-month per-category variation-de-stock rows will land. **Currently empty — the compute pipeline that populates it is the next build, and the Fiche tab reads seed (April) + monthly (May onward).**

## Build verdict / sequencing for the Fiche tab
- The Fiche tab is render-only over `cogs_fiche_seed` + `cogs_fiche_monthly`; both are canonical single-home tables, no parallel store. SOUND.
- **GATE:** `cogs_fiche_monthly` is EMPTY. A tab built today shows only April seed. Confirm with the operator whether the monthly-compute pipeline lands first (so the tab has >1 month) or the tab ships seed-only as a v1. Don't fabricate May rows.
- Categories/GLs come from `ref_cogs_fiche_categories` — JOIN, never hardcode the 12 labels or GLs.
- EQUIP ui+sql+coder (+webapp-testing for deployed-page smoke).

## ✅ B4 + B5 SHIPPED (built + verified 2026-06-11, LOCAL-ONLY — not yet deployed)
**Tasks B4 (Finance fiche tab) + B5 (CSV download) DONE. B6 (immutability guard + May publish) STILL PENDING.** Code is local in `/home/kluk/projects/maltyweb/`, NOT yet on VPS — gated on `bin/deploy.sh --apply` + operator smoke-test. 4 files:
- **`public/modules/financier.php`** (edited): L199 `fin_fmt_chf(float):string` helper (number_format, space thousands, 2 dec). L323–391 Fiche PDO data block (UNION over `cogs_fiche_seed` ∪ `cogs_fiche_monthly` × `ref_cogs_fiche_categories`) → `$ficheMonths/$ficheData/$ficheTotals/$ficheLatestKey/$ficheIsIncomplete/$ficheProvenance/$ficheBasisRows`. L448–452 5th nav tab button `data-tab="fiche"` (reuses the existing delegated tab loop — no JS rewrite, as the pattern predicted). L919+ `#fin-panel-fiche` section **PHP-rendered table PER MONTH, server-side, NO JS recompute** (`hidden` attr correct). L1095–1101 7 `window.FIN_FICHE_*` globals injected.
- **`public/js/financier.js`** (edited): L1579+ Module E IIFE — month switcher (show/hide `.fin-fiche-month-block`), provenance-chip update, incomplete-month warning, CSV href update on select change. **Switcher only — table itself is server-rendered, NOT hydrated.**
- **`public/css/financier.css`** (edited): ~120 lines before `@media` — `.fin-fiche-controls`, `.fin-fiche-provenance-chip` (seed/computed variants), `.fin-fiche-csv-btn`, `.fin-fiche-warn`, column sizing, variation coloring, total row, basis row.
- **`public/api/cogs-fiche-csv.php`** (NEW): auth-gated `require_page_access('financier')`, validates `?month=YYYY-MM` against actual DB months, **re-queries server-side (never echoes client floats)**, UTF-8 BOM for Excel, comma+double-quote CSV (mirrors warehouse-export convention as documented), totals + basis-adjustment rows.
- Both PHP files pass `php -l` (VPS PHP). **No deployed-page smoke yet** — REQUIRED before declaring done (php -l ≠ runtime; nested-fn / undefined-fn class of bug only surfaces in browser). 
- **April 2026 anchor totals confirmed from `cogs_fiche_seed`:** Valeur Stock (RM) 329 852.08 / Total Inventaire 394 367.74 / Variation Stock −36 986.26 CHF.
- **Architecture verdict: SOUND.** Render-only over the two canonical single-home tables; CSV re-queries server-side (no float-laundering); categories JOINed not hardcoded; tab reuses delegated loop. As-built matches the documented pattern.
- **B6 NEXT (still pending):** immutability guard on `cogs_fiche_seed`/published `cogs_fiche_monthly` rows + May publish. `cogs_fiche_monthly` still EMPTY — May publish is the first row-write into it. Don't fabricate; the seed April row is the immutable anchor (opening of May = closing of April).
