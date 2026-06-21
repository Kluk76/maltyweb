# Read-only lookup modules (packaging + brewing) — SHIPPED + LIVE + COMMITTED + PUSHED (revised 2026-06-21)

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
