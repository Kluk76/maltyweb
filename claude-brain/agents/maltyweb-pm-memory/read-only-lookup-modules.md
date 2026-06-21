# Read-only lookup modules (packaging + brewing) + Neb/Contract SKU filter + filter-reactive FG dual-view — SHIPPED + LIVE + COMMITTED + PUSHED (revised 2026-06-21)

> Read when touching: the shared lookup-panel component (`public/modules/partials/lookup-panel.php` / `public/js/lookup-panel.js` / `public/css/lookup-panel.css`); the packaging "Consulter un packaging" section on `packaging.php` (`public/api/packaging-lookup.php`); the brewing "Consulter" tab on `wort.php` (`public/api/brewing-lookup.php`); or any "consulter/rechercher une saisie passée" read surface. Triggers: lookup panel / consulter saisie / consulter un brassin / consulter un packaging / lookup-panel / packaging-lookup / brewing-lookup / recherche par jour / SKU+lot lookup / recipe+lot lookup / read-only consultation / wort consulter tab / wort no data / wort blank page / var alias / KpcCharts.

Operator wanted an in-page way to LOOK UP past entries without leaving the production pages. One shared component, two host pages. ALL read-only (no DB writes, no fiscal lane, no COGS/COP/WAC/BOM surface). Both endpoints PARAMETERIZED, prod-query-validated.

**FINAL SHAPE (after the 2026-06-21 revision):**
- **Brewing lookup = a "Consulter" tab on `wort.php`** (operator/production page). NOT a standalone page. The brief-day standalone `brewing-lookup` page (mig 425) was RETIRED same day and FOLDED into wort (mig 428).
- **Packaging lookup = a labelled "Consulter un packaging" section on `packaging.php`** (require_login).

## Shared component
- `public/modules/partials/lookup-panel.php` + `public/js/lookup-panel.js` + `public/css/lookup-panel.css` — the reusable lookup-panel UI. Reuse this for any future read-only lookup surface; do NOT re-inline a parallel panel. (Now consumed by both wort.php's Consulter tab AND packaging.php's section.)

## Build 1 — packaging lookup (on `packaging.php`, `require_login`)
- Wrapped in a labelled **"Consulter un packaging"** header (commit `61a0130`; `pkg-lkp-` classes, CSS in `lookup-panel.css` with kraft-paper tokens). Matches the brewing surface's labelling.
- Endpoint `public/api/packaging-lookup.php`. Modes: **day** | **SKU+lot**.
- Reads `bd_packaging_v2 × v_bd_packaging_v2_vendable` + `bd_packaging_readings`.
- Auth = `require_login` (matches packaging.php's inventory/cost carve-out — NOT role-gated).
- The "+ Saisir un conditionnement" CTA was REMOVED from packaging.php (`61a0130`) — the dedicated saisie page (`form-packaging.php`) covers entry; the CTA was a bare `<a href>` with zero dependency.

## Build 2 — brewing lookup — a "Consulter" TAB on `wort.php` (REVISED 2026-06-21, mig 428)
- **Lives as a 3rd tab on `wort.php`** — `data-tab=lookup`, panel `#wort-panel-lookup`. Operator/production gate (wort.php's own gate). NOT a separate page, NOT viewer-level.
- `$lookupConfig` + the recipe-options query were added to wort.php's top PHP block (guarded). Reuses the shared `lookup-panel` partial + `lookup-panel.js` engine (unchanged).
- Endpoint `public/api/brewing-lookup.php` — gate repointed `require_page_access('brewing-lookup')` → `('wort')`. **Endpoint KEPT, the standalone page GONE.** Modes: **day** | **recipe+lot**.
- Reads `bd_brewing_brewday_v2` + gravity / timings / parsed-ingredients.
- **OG labelled correctly (never FG)** — honors the OG-not-FG discipline.
- **Matches on `(recipe_id_fk, batch)`** — the canonical brewing key, never beer name (honors [[feedback_match_on_recipe_id_not_beer_name]]). **v2-only** (no v1 bd_* reads — honors [[feedback_v1_bd_tables_forbidden]]).

### Retirement of the standalone page (mig 425 → SUPERSEDED by mig 428)
- The standalone `brewing-lookup` page existed for only part of 2026-06-21 (mig 425, commits `6075fb0`+`8120817`, ref_pages id=739, min_role=viewer, granted to all 7 non-admin presets). Operator then directed: fold it into wort.php (operator/production gate) instead. The "for everyone / all-7-presets" access is GONE by design.
- **Migration 428** `428_retire_brewing_lookup_page.sql` — APPLIED to prod + recorded in `schema_migrations` MANUALLY (NOT migrate.php — parallel sessions pending; avoided the apply-ALL cascade). **Live mig head now 428.**
  - Audit-tombstoned the `ref_pages` id=739 row via `action='update'` + `after_json` `_tombstone` (audit_row_revisions.action ENUM has no 'delete').
  - DELETED `ref_pages` id=739 + its 7 `ref_access_preset_pages` grants via CASCADE.
- DELETED `public/modules/brewing-lookup.php` + `public/css/brewing-lookup.css` (VPS + git).
- Removed the 2 orphaned brewing-lookup Visite-guidée cards from `visite-guidee.php` → tour-gap-check now critical=0 / no-orphan.
- Commit `aef78aa` carries the fold + gate repoint + mig + file deletions.

## 🔴 DURABLE LESSON — wort.php blank-page bug (commit `5561145`, the (1) of the 3-commit revision)
**ROOT CAUSE:** `public/js/wort-kpis.js` aliased 6 `window.KpcCharts` primitives (escHtml, fmt, svgEl, MONTHS_FR, buildBarChart, paceTintResult) with top-level **`const`**, which collided with `kpi-charts.js`'s top-level `function escHtml` / `function fmt` ACROSS classic (non-module) `<script>` tags → Chromium `SyntaxError: Identifier already declared` → wort-kpis.js died **at parse** → `initTabSwitcher()` (at the file bottom) never ran → whole page dead / no-data.

**FIX:** changed those 6 to **`var`** (reuses the global-object slot, no lexical clash). NOT a const→let; specifically `var`.

**DURABLE RULE worth carrying:** when two classic (non-module) scripts share globals, alias shared funcs with **`var`, never `const`/`let`**. `kpi-charts.js` exposes its funcs BOTH as top-level `function` AND on `window.KpcCharts`, so ANY consumer of KpcCharts must use `var` aliases. The three live consumers — **wort, packaging, mon-tableau** — were all re-verified rendering clean after the fix. (This durable lesson is also encoded in the index banner and should be folded into the `ui` skill's rendering-bug catalog on the next `ui` touch — it is the same family as the nested-fn-not-hoisted 500 class.)

## 🟢 LOOKUP REDESIGN + NEB/CONTRACT SKU FILTER — SHIPPED + LIVE + VERIFIED 2026-06-21 (commit `7c3392c`, origin/main `f8ed60a`; Playwright-verified)

Built as the two-request consult ruled. AS-BUILT below; PM ruling honored with the noted minor deviations from the pre-build sketch.

### CANONICAL CLASSIFICATION (CONFIRMED + CORRECTED)
🟢 **SKU-level Neb-vs-Contract = `ref_skus.recipe_id → ref_recipes.classification` ENUM('Neb','Contract').** `ref_recipes` also has `subtype` ENUM(Core/EPH/CollabIn/CollabOut/WhiteLabel/Archive). 🔴 **NOT `ref_beer_types` for SKU filtering** — that table is NAME-keyed (`beer_name`, NO SKU FK) and stays the **beer-tax classifier SoT**; only `ref_recipes.classification` is FK-reachable from a SKU. The two never disagree on their name-join, but per-SKU filtering MUST use the recipe path. 🔴 **6 composite SKUs have `recipe_id` NULL → 'Neb' by construction** (PD8/XMASPACK/PAC/PAL/EXP12C/EXP24C). 🔴 **ALWAYS `LEFT JOIN ref_recipes + COALESCE(classification,'Neb')` — NEVER INNER JOIN** (INNER drops the 6 composites = broken-FK trap). Live split: **153 Neb (147 real + 6 composite) / 33 Contract / 186 active.**

### NEW CANONICAL ACCESSOR — `app/sku_catalog.php` (closes the "every page hand-rolls SELECT FROM ref_skus" gap)
- `sku_catalog(PDO $pdo, array $opts)` → SKU rows incl. `classification`/`subtype`/`beer_name`. `$opts`: `active_only`, `classification` 'all'|'Neb'|'Contract', `packaging_line_only`, `order_by` 'sku_code'|'beer'.
- `sku_classification_label()` → 'Nébuleuse'|'Contract'.
- 🔴 **NEW SKU-list consumers MUST call `sku_catalog()`** — do not re-inline `SELECT FROM ref_skus` ("call the accessor, never copy the literal"). (Deviation from ruling: filename is `sku_catalog.php` underscore, not `sku-catalog.php`; return shape adds beer_name, drops the speculative scope_in opt — `packaging_line_only` covers the real need.)

### NEW GENERIC PER-USER UI-PREF STORE — mig 430 `user_ui_prefs` (REUSABLE)
- mig **430** `user_ui_prefs` (id, `user_id_fk` FK users.id ON DELETE CASCADE, `pref_key`, `pref_value`, UNIQUE(user_id_fk,pref_key)); schema_meta class=**config**. Next-free mig after this = 431.
- Helpers `app/user-prefs.php`: `user_pref_get()` / `user_pref_set()`.
- Writer endpoint `public/api/ui-pref.php` (POST {csrf,key,value}; key whitelist currently just `'sku_class_filter'`; value whitelist all|Neb|Contract). **Generic store — reuse for any future per-user UI pref** (add to the key/value whitelists).

### SHARED FILTER CONTROL (one component, multi-host)
- `public/modules/partials/sku-class-filter.php` — 3-way segmented **Toutes | Nébuleuse | Contract**; caller sets `$skuClassFilterValue` from the pref; self-contained CSRF via its own `window.SKUF_CSRF`.
- `public/js/sku-class-filter.js` — filters `[data-sku-class]` elements via `.skuf-hidden`; rebuilds `select[data-sku-filterable]` options (keeps placeholders); persists pref via ui-pref.php; dispatches document `'skufilter:change'`; exposes `window.SkuClassFilter.apply`.
- `public/css/sku-class-filter.css`.
- 🟢 **DECISION (owner): default = Nébuleuse, memorized per user.**

### WIRED SURFACES (display only)
- PF inventory board (`warehouse.php` FG view — rows carry `data-sku-class`, control above the board).
- Consulter-packaging + Consulter-brassin lookups (filter-aware dropdown + cards + live summary recompute).
- 🔴 **DELIBERATELY NOT filtered: data-entry "saisie" SKU dropdowns** — operators must keep selecting Contract SKUs to record runs. (Verified: saisie dropdown unaffected.)

### 🔴🔴 HARD RULE (recorded as-built) — classification filter is DISPLAY / UX-LAYER ONLY
**NEVER in a COGS/fiscal/compute query** (would silently drop Contract beer tax). Confirmed untouched: `cogs-fiche-compute`, `sku-bom-compile`, `financier`, `fg-stock` compute queries.

### LOOKUP REDESIGN (Request 1) — plain tables → aged-oak CARDS
- `public/modules/partials/lookup-panel.php` + `public/js/lookup-panel.js` + `public/css/lookup-panel.css` — results now render as polished cards: `.lp-card`, classification pill `.lp-pill-neb`/`.lp-pill-contract`, `.lp-stat-grid`. **Brewing batch view keeps gravity/ingredients tabular.**
- `lookup-panel.php` gained config keys `show_class_filter` and per-`batch_field` `class_col` + `filterable`.
- APIs `packaging-lookup.php` + `brewing-lookup.php` now return `COALESCE(r.classification,'Neb')` per row.
- Two-mode toggle KEPT (day vs batch). Placement UNCHANGED: brewing = Consulter tab on wort.php (mig 428); packaging = labelled section on packaging.php (`61a0130`). ONE shared component, both hosts inherit.

### ✅ FILTER-REACTIVE FG TILES + GL RECAP — DUAL VIEW SHIPPED + LIVE + VERIFIED 2026-06-21 (commit `2ff03f5`, on origin/main; Playwright-verified)
The prior v1 limitation (FG headline tiles + GL recap staying portfolio-wide) is **CLOSED**. The warehouse FG (PF inventory) **5 KPI tiles + GL recap are now FILTER-REACTIVE with a DUAL VIEW** (operator decision: keep BOTH the filtered figure AND the portfolio total visible).
- **Server** computes the 5 tiles + GL recap per classification bucket (`all` / `Neb` / `Contract`) inside the EXISTING FG row loop — bucket `all` == Neb + Contract (a TRUE partition; same per-row valuation, NO new SQL, NO compute-query change). Emitted display-ready as `window.WH_FG_BUCKETS`.
- **Each tile:** filtered figure (primary) + a muted `Total : …` portfolio sub-line (hidden when filter = Toutes). GL recap rebuilds per class with a `(portefeuille : …)` reference (hidden on Toutes).
- **`public/js/warehouse-fg-kpis.js`** listens for `skufilter:change` and swaps figures from `window.WH_FG_BUCKETS`. CSS scoped under `.wh-kpis--5` / `.wh-fg-gl-recap` in app.css.
- Honors the §HARD RULE above: this is DISPLAY-layer bucketing only (a true partition of the same FG valuation), NOT a COGS/fiscal compute change.
- **Verified live (Playwright):** tiles + GL react; Neb+Contract == Toutes exact; sub-lines hide on Toutes; no app JS errors. (Dataset currently has 0 Contract FG stock → Contract bucket = 0 / Nébuleuse = portfolio; mechanism still proven via the Contract=0-vs-Total contrast.)

### VERIFIED LIVE (Playwright, `smoketest_mgr`)
All three surfaces PASS; per-user persistence via ui-pref.php works; fresh-load default = Nébuleuse on both lookup pages; zero app JS console errors; saisie dropdown unaffected.

### TOUR
No new `ref_pages` added (lookup folded into existing pages; warehouse pre-existing) → no Visite-guidée card needed.
