# Read-only lookup modules (packaging + brewing) — SHIPPED + LIVE + COMMITTED + PUSHED 2026-06-21

> Read when touching: the shared lookup-panel component (`public/modules/partials/lookup-panel.php` / `public/js/lookup-panel.js` / `public/css/lookup-panel.css`); the packaging lookup on `packaging.php` (`public/api/packaging-lookup.php`); the brewing lookup on `form-brewing.php` (`public/api/brewing-lookup.php`); or any "consulter/rechercher une saisie passée" read surface. Triggers: lookup panel / consulter saisie / lookup-panel / packaging-lookup / brewing-lookup / recherche par jour / SKU+lot lookup / recipe+lot lookup / read-only consultation.

Operator wanted an in-page way to LOOK UP past entries without leaving the production pages. Two read-only modules, one shared component. ALL read-only (no DB writes, no fiscal lane, no COGS/COP/WAC/BOM surface). Both endpoints PARAMETERIZED, prod-query-validated. Commit `e83c4d1` (Builds 1+2 together) on maltyweb main; surgical per-file rsync deploy (parallel email-orders session held a dirty tree; pathspec commit). NO migration. NO ref_pages row (panels on existing pages + API endpoints) → NO tour-steward dispatch.

## Shared component
- `public/modules/partials/lookup-panel.php` + `public/js/lookup-panel.js` + `public/css/lookup-panel.css` — the reusable lookup-panel UI. Reuse this for any future read-only lookup surface; do NOT re-inline a parallel panel.

## Build 1 — packaging lookup (on `packaging.php`, `require_login`)
- Endpoint `public/api/packaging-lookup.php`. Modes: **day** | **SKU+lot**.
- Reads `bd_packaging_v2 × v_bd_packaging_v2_vendable` + `bd_packaging_readings`.
- Auth = `require_login` (matches packaging.php's inventory/cost carve-out — NOT role-gated).

## Build 2 — brewing lookup (on `form-brewing.php`, GATED `require_page_access('saisies')`)
- Endpoint `public/api/brewing-lookup.php`. Modes: **day** | **recipe+lot**.
- Reads `bd_brewing_brewday_v2` + gravity / timings / parsed-ingredients.
- **OG labelled correctly (never FG)** — honors the OG-not-FG discipline.
- **Matches on `(recipe_id_fk, batch)`** — the canonical brewing key, never beer name (honors [[feedback_match_on_recipe_id_not_beer_name]]). **v2-only** (no v1 bd_* reads — honors [[feedback_v1_bd_tables_forbidden]]).

## 🔴 ROADMAP FLAG — brewing-lookup access gate
The brewing lookup sits behind the **`saisies` page-access gate** (`require_page_access('saisies')`). The operator asked for "anyone" to be able to look up brewing entries — flagged to operator. Relaxing it cleanly is NOT a quick gate-swap: it would mean a **standalone `require_login` brewing READ page** = a NEW `ref_pages` row + a Visite-guidée tour card (RULE 3) + a preset grant (the standing "new ref_pages row is invisible until added to the access preset" rule). Currently parked behind `saisies` as the pragmatic landing; revisit if/when the operator wants the standalone read page.
