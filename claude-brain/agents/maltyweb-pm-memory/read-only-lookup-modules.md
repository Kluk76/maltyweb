# Read-only lookup modules (packaging + brewing) — SHIPPED + LIVE + COMMITTED + PUSHED 2026-06-21

> Read when touching: the shared lookup-panel component (`public/modules/partials/lookup-panel.php` / `public/js/lookup-panel.js` / `public/css/lookup-panel.css`); the packaging lookup on `packaging.php` (`public/api/packaging-lookup.php`); the **standalone brewing-lookup page** `public/modules/brewing-lookup.php` (`public/api/brewing-lookup.php`); or any "consulter/rechercher une saisie passée" read surface. Triggers: lookup panel / consulter saisie / consulter un brassin / lookup-panel / packaging-lookup / brewing-lookup / recherche par jour / SKU+lot lookup / recipe+lot lookup / read-only consultation.

Operator wanted an in-page way to LOOK UP past entries without leaving the production pages. Two read-only modules, one shared component. ALL read-only (no DB writes, no fiscal lane, no COGS/COP/WAC/BOM surface). Both endpoints PARAMETERIZED, prod-query-validated. Commit `e83c4d1` (Builds 1+2 together) on maltyweb main; surgical per-file rsync deploy (parallel email-orders session held a dirty tree; pathspec commit). NO migration. NO ref_pages row (panels on existing pages + API endpoints) → NO tour-steward dispatch.

## Shared component
- `public/modules/partials/lookup-panel.php` + `public/js/lookup-panel.js` + `public/css/lookup-panel.css` — the reusable lookup-panel UI. Reuse this for any future read-only lookup surface; do NOT re-inline a parallel panel.

## Build 1 — packaging lookup (on `packaging.php`, `require_login`)
- Endpoint `public/api/packaging-lookup.php`. Modes: **day** | **SKU+lot**.
- Reads `bd_packaging_v2 × v_bd_packaging_v2_vendable` + `bd_packaging_readings`.
- Auth = `require_login` (matches packaging.php's inventory/cost carve-out — NOT role-gated).

## Build 2 — brewing lookup — STANDALONE PAGE (`public/modules/brewing-lookup.php`, SHIPPED 2026-06-21)
- **Now its own dedicated read page** — `public/modules/brewing-lookup.php`, gated `require_page_access('brewing-lookup')` (page min_role=viewer → reachable by anyone with the preset grant). The brewing-lookup PANEL was REMOVED from `form-brewing.php` (the form is pure input again; single canonical home for the lookup).
- Endpoint `public/api/brewing-lookup.php` — gate changed `saisies`→`brewing-lookup`. Modes: **day** | **recipe+lot**.
- Reads `bd_brewing_brewday_v2` + gravity / timings / parsed-ingredients.
- **OG labelled correctly (never FG)** — honors the OG-not-FG discipline.
- **Matches on `(recipe_id_fk, batch)`** — the canonical brewing key, never beer name (honors [[feedback_match_on_recipe_id_not_beer_name]]). **v2-only** (no v1 bd_* reads — honors [[feedback_v1_bd_tables_forbidden]]).
- Reuses the shared `lookup-panel` partial + `lookup-panel.js` engine (unchanged — packaging.php still uses it); page-chrome copied from `journal-saisies.php`; page CSS `public/css/brewing-lookup.css`.

### Standalone page as-built (mig 425, commits `6075fb0` + `8120817`, all live+verified)
- Migration **425** `425_brewing_lookup_page.sql` — APPLIED to prod + recorded in `schema_migrations` MANUALLY (NOT migrate.php — parallel sessions pending). **Live mig head now 425.**
- `ref_pages` id **739**: page_key `brewing-lookup`, label "Consulter un brassin", icon 🔍, href `/modules/brewing-lookup.php`, min_role **viewer**, domain general, category_key **pilotage**, category_sort 30, sort 17, is_active 1.
- **Granted to ALL 7 non-admin presets** via `ref_access_preset_pages`: manager, production_operator, logistics_operator, marketing, sales_manager, finance_viewer, smoke_viewer. (Operator said "for everyone" → sales_manager INCLUDED here even though the journal-saisies mirror had only 6.)
- **Tour card SHIPPED** (`8120817`) — `$PAGE_DESCRIPTIONS['brewing-lookup']` + `$PAGE_ICONS['brewing-lookup']` added to `visite-guidee.php`; tour-gap-check now critical=0 minor=0.
- HTTP smoke: page / API / form-brewing all 302 (no fatals). ✅ ROADMAP FLAG (below) RESOLVED — the standalone page IS the relaxation it called for.

## ✅ ROADMAP FLAG — brewing-lookup access gate — RESOLVED 2026-06-21
~~Brewing lookup sat behind the `saisies` gate; operator wanted "anyone".~~ **DONE:** shipped as the standalone `require_page_access('brewing-lookup')` read page above (min_role viewer + granted to all 7 non-admin presets + tour card + mig 425). Brewing lookup is **no longer saisies-gated**. Nothing further open on this flag.
