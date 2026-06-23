# Read-only lookup modules (packaging + brewing) + Neb/Contract SKU filter + filter-reactive FG dual-view — SHIPPED + LIVE + COMMITTED + PUSHED (revised 2026-06-21; +SELECTION-DROPDOWN behavioral principle `45b0871`)

> 🔴 BEHAVIORAL PRINCIPLE (owner ruling 2026-06-21, LIVE): the Neb/Contract filter is a **SELECTION-DROPDOWN filter** (narrows what a user can PICK), **NOT a visual activity filter** — it NEVER hides date-activity results. Lookups filter the DROPDOWN ONLY; "Par date" shows ALL activity (control absent there). CARVE-OUT: warehouse PF inventory (a stock LIST) keeps the row-filter + dual-view. Full ruling in §BEHAVIORAL PRINCIPLE below.

> Read when touching: the shared lookup-panel component (`public/modules/partials/lookup-panel.php` / `public/js/lookup-panel.js` / `public/css/lookup-panel.css`); the packaging "Consulter un packaging" section on `packaging.php` (`public/api/packaging-lookup.php`); the brewing "Consulter" tab on `wort.php` (`public/api/brewing-lookup.php`); or any "consulter/rechercher une saisie passée" read surface. Triggers: lookup panel / consulter saisie / consulter un brassin / consulter un packaging / lookup-panel / packaging-lookup / brewing-lookup / recherche par jour / SKU+lot lookup / recipe+lot lookup / read-only consultation / wort consulter tab / wort no data / wort blank page / var alias / KpcCharts / Neb-Contract filter / Nébuleuse Contract filter / sku-class-filter / selection-dropdown filter / activity filter / par date shows all / filter hides activity / warehouse PF row filter / data-sku-class.

Operator wanted an in-page way to LOOK UP past entries without leaving the production pages. One shared component, two host pages. ALL read-only (no DB writes, no fiscal lane, no COGS/COP/WAC/BOM surface). Both endpoints PARAMETERIZED, prod-query-validated.

**FINAL SHAPE (after the 2026-06-21 revision):**
- **Brewing lookup = a "Consulter" tab on `wort.php`** (operator/production page). NOT a standalone page. The brief-day standalone `brewing-lookup` page (mig 425) was RETIRED same day and FOLDED into wort (mig 428).
- **Packaging lookup = a labelled "Consulter un packaging" section on `packaging.php`** (require_login).

## Shared component
- `public/modules/partials/lookup-panel.php` + `public/js/lookup-panel.js` + `public/css/lookup-panel.css` — the reusable lookup-panel UI. Reuse this for any future read-only lookup surface; do NOT re-inline a parallel panel. (Now consumed by both wort.php's Consulter tab AND packaging.php's section.)

## Build 1 — packaging lookup (on `packaging.php`)
- Wrapped in a labelled **"Consulter un packaging"** header (commit `61a0130`; `pkg-lkp-` classes, CSS in `lookup-panel.css` with kraft-paper tokens). Matches the brewing surface's labelling.
- Endpoint `public/api/packaging-lookup.php`. Modes: **day** | **batch (SKU+lot)**. 🔴 AS-BUILT auth = **`require_page_access('packaging')`** (verified live 2026-06-22), NOT `require_login` as an earlier note said.
- Reads `bd_packaging_v2 × v_bd_packaging_v2_vendable` LEFT JOIN ref_skus/ref_recipes + a 2nd query over `bd_packaging_readings` (per-run avg co2/o2). Batch key = `COALESCE(NULLIF(neb_batch,''),NULLIF(contract_batch,''))`.
- API JSON returns per event: id, submitted_at, event_date, sku_id_fk, sku_code, unit_label, batch, run_type, prod_total_units, reuses_packaging_id_fk, recipe_name, classification, vendable_units, vendable_hl, beer_tax_base_hl, loss_kpi_hl; plus `co2o2[id]` (n_readings/avg_co2/avg_o2) and a `summary` block. **It does NOT yet return the per-run BOM consumption** (see §PACKAGING-LINE-SCHEMATIC brief — the new design needs that join added).
- The "+ Saisir un conditionnement" CTA was REMOVED from packaging.php (`61a0130`) — the dedicated saisie page (`form-packaging.php`) covers entry; the CTA was a bare `<a href>` with zero dependency.

## 🆕 SIBLING-DATA REPATRIATION + CIP-FROM-bd_cip_events — CADRAGE 2026-06-23 (PM-blessed, NOT YET BUILT; brief handed to coder)
Kouros looked at run STI4 171 (id 6803, the -4/4-pack leg) and saw empty CO₂/O₂, QA, losses, CIP — thought data was missing. **Ground truth (PM-VERIFIED LIVE 2026-06-23): the data exists on the SIBLING run, not missing.** The operator enters the QA/gas/loss/CIP bundle ONCE on ONE format-run per beer-batch-day; sibling format-runs of the same brew are minimal entries. This is the **parallel -4/-B run pattern** (see packaging-parallel-run-normalizer-defect.md): siblings share `recipe_id_fk` + batch + event_date, differ only in format suffix/sku_id_fk.
- **Verified pair:** STIB 171 (id **6802**, bottle, recipe_id_fk=52, neb_batch='171', event_date 2026-06-19, BBT source 3, op Yves Mingard) carries 2 gas reads (CO₂ 5.08 g/L, O₂ 104.3 ppb in `bd_packaging_readings`), qa_analyses_units=2, qa_library_units=5, losses (50 crown-cork / 50 label / 26 half-filled), AND one CIP event. STI4 171 (id **6803**, same recipe/batch/date/BBT/op) = 0 gas reads, qa=0, losses NULL, no CIP. The consulter shows each run in isolation → the sibling-light run looks empty.
- **CIP store correction:** the consulter currently reads `bd_packaging_v2.cip_machines_done`/`cip_tank_done` — these are **VESTIGIAL/NULL on all recent runs** (the CIP module converged ALL forms onto child table `bd_cip_events` back at mig 175-177; see cip-module.md). The REAL CIP store = `bd_cip_events` (FK `packaging_id`→bd_packaging_v2.id, `source_form='packaging'`, `cip_type_id_fk`→`ref_cip_types`). **62 distinct packaging runs have non-tombstoned CIP; ALL are `target_kind='machine'`/`target_code='filler'` (1 event each).** 🔴 `ref_cip_types` label col = **`name`** (Soude/Acide/Full CIP/Full CIP + rinser), NOT `label`. STIB 171 (6802) → filler / Full CIP (type 3); STI4 171 (6803) → none → CIP follows the same sibling pattern.
- **Sibling key (CONFIRMED):** `recipe_id_fk + COALESCE(NULLIF(neb_batch,''),NULLIF(contract_batch,'')) + event_date`, scoped `is_tombstoned=0`, EXCLUDING self (`id <> p.id`). This matches the "match on recipe_id_fk not name" rule (sql #27). Do NOT constrain on source_tank_type (siblings can drain different tanks in a blend; not an identity field). Edge cases: a brew packaged across two days → DIFFERENT event_date → NOT treated as siblings (correct, each day is its own session); two different brews sharing a batch NUMBER → distinguished by recipe_id_fk (correct).
- **Field-level semantics (PM ruling):** CO₂/O₂ (gas), qa_analyses_units, qa_library_units = **BEER-level / session-level** → SAFE to repatriate from sibling with provenance. Losses = **FORMAT-level** (crown-cork=bottle, can-lid=can, 4pack=carton) → repatriating risks MISATTRIBUTION. Ruling: **repatriate ONLY the beer-level QA+gas+CIP; keep losses PER-RUN** (show the viewed run's own losses; if NULL, show "—", do NOT pull the sibling's). CIP = filler-machine cleaning, a SESSION fact → repatriate beer-level (sibling-aware) like QA.
- **Presentation (PM ruling, MERGE not pointer — Kouros chose "repatriate"):** merge the sibling beer-level data INTO the QA panel with a provenance note ("Lectures enregistrées sur le run bouteille STIB 171"). CIP → a small list block (target + type-name + date), sibling-aware, replacing the two vestigial cip-pill fields in `renderQA`.
- **Derivation impact = NONE.** Consulter is a pure READ surface (no DB writes, no fiscal lane). `loss_kpi_hl` / `beer_tax_base_hl` (on `v_bd_packaging_v2_vendable`, per-row) are UNTOUCHED — they're computed per-run from each row's own loss columns; repatriating QA/gas/CIP into the VIEW does not write or recompute them. No COGS/COP/beer-tax reads `qa_*`/`cip_*` (cip-module.md confirms ZERO non-form consumers of CIP).
- **STATUS:** brief handed 2026-06-23; build scoped to `public/api/packaging-lookup.php` + `public/js/packaging-consulter.js` + `public/css/packaging-consulter.css` ONLY (do NOT touch the shared lookup-panel.js/php — scope-creep magnet). EQUIP sql+ui+webapp-testing. Width-fix + readable-glyph + Taxe-tag-removal work already shipped (per-file rsync, uncommitted) — must be preserved.

## 🟢 CONSULTER-TAB UX FIX — SHIPPED + LIVE + SMOKE-VERIFIED 2026-06-22 (start_open flag + clickable day-cards; surgical 4-file rsync; ✅ COMMITTED + PUSHED 2026-06-23 — `8fe0865` + `c0f8e04`, origin/main @ c0f8e04)
Small UX slice on the wort.php "Consulter" tab + the shared lookup panel. Read-only consultation surface — NO SoT/derivation impact (no DB writes, no fiscal lane). Deployed SURGICALLY per-file (NOT bin/deploy.sh) because the maltyweb tree was dirty with parallel in-flight work (expéditions/financier/mon-tableau edits, a **timezone migration 438**, and the brewing-lookup.php/packaging-lookup.php BOM expansion). ✅ **NOW COMMITTED + PUSHED 2026-06-23:** `8fe0865` (parallel session) swept in ALL this session's working-tree edits incl. activateDayCard ×3 + estimatePitchDate ×2 + start_open in both wort.php + packaging.php + the lookup-php endpoints; `c0f8e04` (this session, pathspec-scoped lookup-panel.php +4/-3) completed the `$lpStartOpen` render. origin/main @ c0f8e04, tree 0 ahead/0 behind.
- **(1) `start_open` config flag on the shared lookup panel.** `partials/lookup-panel.php` + `lookup-panel.js` now honor `'start_open' => true` in `$lookupConfig`; wort.php sets it so the Consulter filter lands OPEN (not hidden behind the "Consultation ▸" toggle). Toggle still collapses/re-expands.
- **(2) Brewday day-cards are now clickable.** `renderDay` in `brewing-consulter.js` emits `data-recipe-id`+`data-batch` on each `.day-card`; new delegated handler `activateDayCard()` in `lookup-panel.js` (click + Enter/Space) fills the batch-pane recipe_id+batch inputs, switches to the "Par recette + lot" tab, and calls `doSearch(panel,'batch')` → renderBatch (Schéma de brassage fiche). Keyed on **recipe_id_fk + batch** (canonical key, never name). Event delegation ON THE PANEL (survives re-render) — the bug was a never-attached handler, not a detached one.
- **DEPLOYED (4 files):** `public/modules/partials/lookup-panel.php`, `public/modules/wort.php`, `public/js/lookup-panel.js`, `public/js/brewing-consulter.js`.
- **Discipline held:** `var`-not-`const` on all classic-script JS (the wort blank-page lesson §below); no v1 bd_* reads.
- **Live smoke (Playwright, smoketest_mgr) PASSED all steps:** panel lands open, toggle works, day cards render (Alternative Lot 10, Moonshine Lot 126 for 2026-06-22), clicking a card opens the Schéma de brassage fiche, tab-switching intact, 0 app JS errors.

## 🟢 PACKAGING-SIDE PARITY — NOW SHIPPED + LIVE (2026-06-22/23; VPS-VERIFIED). Earlier "STAGED-NOT-DEPLOYED" SUPERSEDED.
The identical fix for the packaging Consulter surface was deployed surgically (per-file rsync, sudo, NOT `bin/deploy.sh`), WITHOUT waiting for the packaging-lookup.php BOM expansion:
- `public/modules/packaging.php` — `'start_open'=>true` added to `$lookupConfig`. 🔴 The prior session's claim that this had landed was FALSE — it was missing; re-added this session (VPS-verified: 1× start_open).
- `public/js/packaging-consulter.js` — `data-sku-id`+`data-batch` on day cards (reads `ev.sku_id_fk` + `ev.batch`). Also missing/clobbered, re-added (VPS-verified: 1× data-sku-id).
- `public/css/packaging-consulter.css` — deployed.
- 🔴 At the time of THIS slice, deployed WITHOUT `packaging-lookup.php`. ✅ **SUPERSEDED 2026-06-22/23 — the `packaging-lookup.php` BOM-endpoint expansion + the full packaging-consulter schematic ARE now shipped+live (see §BOTH CONSULTER SCHEMATICS at top).** "Nomenclature en cours de calcul…" now means inv_consumption derivation LAG for recent runs, NOT a missing endpoint.

## 🔴🔴 SHARED-FILE COLLISION — `public/js/lookup-panel.js` under CONCURRENT edit (governance note, 2026-06-22/23)
`public/js/lookup-panel.js` is a SHARED file (consumed by wort.php Consulter tab AND packaging.php section) under ACTIVE concurrent edit by a parallel session in the SAME clone. Observed sequence:
- My `activateDayCard` day-card drill-down handler (click + Enter/Space, keys recipe_id/sku_id + batch, switches to batch tab, fills inputs, doSearch('batch')) was **REVERTED in both local source AND the deployed VPS file** — rolled back to an older **479-line** version, which got DEPLOYED at 18:53, silently breaking card-click in PROD for BOTH brewing and packaging.
- It was then independently **re-applied** — now **513 lines, activateDayCard present, correct + deployed again** (VPS-VERIFIED 2026-06-23: 513 lines, 3× activateDayCard). Card-click works on both surfaces.
- ✅ **RISK NOW CLOSED for these files (2026-06-23):** `lookup-panel.js` + both consulter assets are COMMITTED + PUSHED (`8fe0865` + `c0f8e04`, origin/main @ c0f8e04) and thus in git history — a whole-tree `bin/deploy.sh` can no longer silently revert them. The GENERAL deploy-pushes-the-working-tree hazard (multiple sessions, one clone, shared JS) STILL STANDS for any OTHER dirty file (e.g. the timezone mig 438): commit-by-pathspec before deploy, or coordinate. EQUIP ui+coder+sql+webapp-testing.

## 🟢 DÉBUT FERMENTATION DERIVATION — SHIPPED + LIVE (brewing only, 2026-06-22/23; VPS-VERIFIED)
Operator (Kouros) flagged on SPY 67 / Speakeasy batch 67 that the yeast/fermentation panel showed a blank "Début fermentation".
- **ROOT CAUSE:** `bd_brewing_brewday_v2.start_ferm` is **100% NULL — 0/837 rows** (VERIFIED live 2026-06-23, COUNT=837 / null=837). Never captured in the v2 brewing form. `brewing-consulter.js` read it raw with a '—' fallback → EVERY brew showed blank.
- **FIX (2 files only — `public/js/brewing-consulter.js` + `public/css/brewing-consulter.css`; NO endpoint/SQL/migration touch):** added `estimatePitchDate(brews)` — when `start_ferm` is absent, derive the pitch date from the **latest `brew_end` across the batch's brews** (≈ cooling/pitch), the same day-0 anchor logic `tanks.php` uses. Rendered with a subtle **'estimé' chip** (`.yeast-field__est`, hover tooltip; VPS-verified: 2× estimatePitchDate, 1× yeast-field__est in CSS). SPY 67 now shows ≈ 19 juin 2026 · estimé.
- **Operator decision (recorded):** chose the **brew/pitch-date semantic** over a first-fermentation-reading semantic. The real fermentation log lives in `bd_fermenting_v2` (keys on `beer_raw` + `batch`; SPY 67's first reading = 2026-06-22) — deliberately NOT used for the start, by operator's choice.
- **Yeast panel gate** broadened to show whenever brews exist.
- 🔴 **OPEN FUTURE OPTION (operator declined for now):** capture `start_ferm` in the brewing saisie form going forward — would make this a real captured value rather than a derived estimate. If it ships, the 'estimé' chip should fall back to the captured value when present.
- Discipline held: var-not-const on classic-script JS; no SoT/COGS impact (read-only consultation display only).

## 🟢🎨 BOTH CONSULTER SCHEMATICS — SHIPPED + LIVE + SMOKE-PASSED 2026-06-22/23 (surgical per-file rsync; ✅ COMMITTED + PUSHED 2026-06-23 — `8fe0865` + `c0f8e04`, origin/main @ c0f8e04). Supersedes the "NOT built" + "Nomenclature en cours de calcul"/"deployed WITHOUT packaging-lookup.php" notes below.
Both consultation visuals are now LIVE in production and smoke-passed. Read-only consultation surfaces — NO SoT/derivation/COGS impact (SELECT-only API changes, NO migration; display layer only).

### BREWING — "Schéma de Brassage" (wort.php → Consulter tab) — LIVE + smoke-passed
- NEW assets: `public/js/brewing-consulter.js` (IIFE `window.BrewingConsulter.{renderBatch,renderDay}` — P&ID engineering-plate schematic: mash tun → kettle → boil kettle → CCT, instrument tags, yeast strip, ingredient docket, day-view vessel wall), `public/css/brewing-consulter.css` (`body.wort` scope).
- `api/brewing-lookup.php`: added 6 yeast columns to `brews[]` SELECT — `yeast`, `yeast_gen`, `new_yeast`, `pitched_from`, `yt_number`, `start_ferm`; gravity ladder + ingredients already exhaustive.
- Shared `public/js/lookup-panel.js`: `renderBrewingBatch`/`renderBrewingDay` feature-detect-delegate to `window.BrewingConsulter` with tabular fallback.
- Day-mode OG/pH render "—" where readings weren't captured (data gap, not a bug).

### PACKAGING — "Schéma de Ligne d'Embouteillage" (packaging.php → Consulter lookup) — LIVE + smoke-passed
- NEW assets: `public/js/packaging-consulter.js` (IIFE `window.PackagingConsulter.{renderBatch,renderDay}` — format-routed industrial bottling-line: rotary filler carousel → capper → labeller(reel) → case packer → ROBOTIC PALLETISER ARM, roller conveyor; cartouche; KPI strip; side panels Nomenclature consommée / Pertes & écarts / QA-Contrôles / Notes; day-view format-glyph wall), `public/css/packaging-consulter.css` (`body.packaging` scope — packaging.php body class changed to `"home packaging"`).
- `api/packaging-lookup.php`: added all `loss_*`, `qa_*`, `cip_*`, `stocktake_scope` (ref_skus), `white_label`, `audit_flags`, `hors_process_*`, `email`, `comments`, `source_tank_type`/`bbt_source_fk`/`cct_source_fk`, `neb_beer`/`contract_beer` columns; AND **the NEW `bom[]` array** = per-run consumed materials from `inv_consumption WHERE source_event='packaging' AND source_row_id = bd_packaging_v2.id` JOIN ref_mi. 🟢 **THIS CLOSES the "It does NOT yet return the per-run BOM consumption" gap noted in Build 1 §20 and the §PACKAGING-LINE-SCHEMATIC "add the inv_consumption BOM join" TODO.** Format routing (bot/can/keg/cuv/cage; cage = run_type 'bot' + `ref_skus.stocktake_scope='cage'`) mapped to human labels at the boundary — NO DB nomenclature client-side.
- BOM join VERIFIED returning real lines (event 6750 → 6 lines); recent runs correctly show "Nomenclature en cours de calcul…" (inv_consumption derivation LAG, BY DESIGN — not the missing-endpoint case any more).
- Shared `lookup-panel.js`: `renderPackaging` split into `renderPackagingBatch`/`renderPackagingDay` delegating to `window.PackagingConsulter` with the old `renderPackaging()` as fallback. Packaging regression on the brewing path = clean.

### 🔴 GUARDRAIL — shared lookup-panel.js is a SCOPE-CREEP MAGNET
A build agent slipped TWO unrequested additions into the shared `lookup-panel.js` / `packaging.php`: a day-card click/keyboard drill-down feature + a no-op `'start_open'` config. Both STRIPPED before deploy to keep the shared-file diff minimal and exactly scoped. 🔴 DURABLE: the shared lookup-panel attracts scope-creep — always diff-review the shared file before deploy. (Note: the `start_open` flag + clickable day-cards ARE legitimately shipped via the separate §CONSULTER-TAB UX FIX slice above — but this build's agent re-introduced them unscoped, which is why they were stripped here.)

### DEPLOY / GIT (both schematics)
- Both shipped via SURGICAL per-file rsync (sudo rsync-path, chown maltytask:www-data, 644, md5-verified) — **NOT `bin/deploy.sh`** — because the maltyweb tree holds other sessions' uncommitted work (public/img/brand/, data/*.csv, .gitignore, etc.).
- NO DB migration (SELECT-only API changes).
- ✅ The **10 changed/new files are deployed-LIVE AND COMMITTED + PUSHED 2026-06-23** (`8fe0865` swept in all this session's working-tree edits — verified in the committed blobs: activateDayCard ×3, estimatePitchDate ×2, .yeast-field__est, data-sku-id, start_open in wort.php+packaging.php, packaging-lookup.php BOM +69, brewing-lookup.php +6; `c0f8e04` = the lookup-panel.php $lpStartOpen render +4/-3). origin/main @ c0f8e04 (push `9c3d57f..c0f8e04`, tree 0 ahead/0 behind). Collision risk CLOSED for these files (in git history; whole-tree bin/deploy.sh can no longer revert them).
- Design prototypes: `public/_design/wort-consulter-brewhouse.html` + `public/_design/packaging-consulter-bottling-line.html`; verification screenshots in `public/_design/_shots/`.

---

## 🎨 PACKAGING-LINE-SCHEMATIC enrichment (PM-briefed 2026-06-22 — ✅ NOW BUILT+LIVE, see §BOTH CONSULTER SCHEMATICS above) — the bottling/canning-line analogue of the brewhouse "Schéma de Brassage"
Sibling of the wort/brewing "Schéma de Brassage" P&ID enrichment (Kouros approved visual direction; brewing Slice A SHIPPED+LIVE 2026-06-22 — wort.php Consulter tab + `api/brewing-lookup.php` + `public/js/brewing-consulter.js` + `public/css/brewing-consulter.css`, brewing-only render branch so SHARED `lookup-panel.js` is undisturbed; prototype `public/_design/wort-consulter-brewhouse.html`; SELECT-only API changes, no migration. Plus the 2026-06-22 start_open + clickable-day-card UX fix above). 🟢 The packaging analogue is now ALSO shipped — this brief is RETAINED below only for the data-source / build-shape rationale.
- **Request:** same kind of refined engineering-plate visual for the PACKAGING "Consulter un packaging" lookup — a **bottling/canning LINE schematic** (stations left→right: dépalettisation → soutirage/remplissage → bouchage/capsulage → étiquetage → mise en pack → mise en carton → palettisation), each station annotated with the data captured during a packaging run, mirroring how the brewhouse annotates gravity stations.
- **PM verdict (recorded):** the line-metaphor FITS — but the canonical run is **format-routed** (bot/can/can33/keg/cuv; cage = a `stocktake_scope='cage'`/format_id=6 sub-case of run_type='bot', NOT a run_type value). So the schematic must be a **per-run-type station GRAPH** (only the stations the run's format touches light up), not one fixed 7-station rail. Filling station is the constant spine; downstream stations are conditional.
- **Data sources (all SELECT-only, NO migration):** `bd_packaging_v2` (the full per-run column set incl. all `loss_*_units`/`loss_*_l`, qa_*, taproom_keg_l, CIP fields, source tank) × `v_bd_packaging_v2_vendable` (vendable_units/_hl/beer_tax_base_hl/loss_kpi_hl) × `bd_packaging_readings` (per-reading o2/co2 → QA side-panel) × **`inv_consumption WHERE source_event='packaging' AND source_row_id=bd_packaging_v2.id`** ← THE BOM-consumed lines (bottles/caps/labels/4-packs/cartons/cages as MI rows; verified live keying on run id, e.g. run 6750). recipe_name + classification via ref_recipes; SKU via ref_skus.
- **Build shape (mirror brewing):** add the inv_consumption BOM join to `packaging-lookup.php` (NEW return key, additive), new `public/js/packaging-consulter.js` + `public/css/packaging-consulter.css`, packaging-only render branch — **DO NOT touch the shared `lookup-panel.js`** (also consumed by wort.php Consulter tab; the brewing-blank-page lesson applies — `var` aliases if sharing classic-script globals).
- 🔴 Gotchas: cage-split keys on `ref_skus.stocktake_scope`/format_id=6 (run_type stays 'bot') — see `displayRunType` in `kpi_pkg_daily_recap`; `-X`=1 bottle (mig 392); reuses_packaging_id_fk = cuve-réutilisée (vendable forced 0); white-label may lack a Neb SKU; SKU↔run via sku_id_fk (refuse-don't-NULL guard already on cage saisie). DISPLAY-layer only — never feeds COGS/compute.
- EQUIP ui+coder+sql+webapp-testing.

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

### WIRED SURFACES (display only) — REFINED by the 2026-06-21 owner ruling (see §BEHAVIORAL PRINCIPLE below)
- PF inventory board (`warehouse.php` FG view — rows carry `data-sku-class`, control above the board). **This is a stock LIST (not an activity log) → the control DOES filter rows + drives the dual-view reactive totals.** Owner explicitly confirmed keep-row-filter here.
- Consulter-packaging + Consulter-brassin lookups — **the control filters the SELECTION DROPDOWN ONLY** (`select[data-sku-filterable]` + its `data-sku-class` options). It lives INSIDE the "Par SKU/recette + lot" batch pane, is ABSENT in "Par date" mode, and NEVER hides date-activity result cards. (See §BEHAVIORAL PRINCIPLE — this supersedes the earlier "filter-aware cards + live summary recompute" wording.)
- 🔴 **DELIBERATELY NOT filtered: data-entry "saisie" SKU dropdowns** — operators must keep selecting Contract SKUs to record runs. (Verified: saisie dropdown unaffected.)

### 🔴🔴 BEHAVIORAL PRINCIPLE — SELECTION-DROPDOWN filter, NOT a visual activity filter (owner ruling 2026-06-21; LANDED + LIVE + VERIFIED, commit `45b0871`, origin/main)
The Nébuleuse/Contract filter is a **SELECTION-DROPDOWN filter**: it narrows the list of SKUs/recipes a user can PICK, to declutter choices. It is **NOT a visual activity filter — it must NEVER hide actual activity/results.** Concretely:
- **Date-based consultation ("Par date" in consulter brassin / packaging): ALL activity for that day shows regardless of classification.** A day with both Contract and Nébuleuse events shows BOTH — no card hiding, no summary recompute by class. **The control is not even present in "Par date" mode.**
- **The control lives INSIDE the "Par SKU/recette + lot" batch pane** and filters ONLY the selection dropdown (the `data-sku-filterable` select + its `data-sku-class` options, rebuilt by `sku-class-filter.js`). **Classification PILLS on result cards STAY** (they are a visual LABEL, not a filter).
- **CARVE-OUT (still true): the PF inventory (warehouse FG) is a stock LIST, not activity → there the control DOES filter rows + drives the dual-view reactive totals** (owner explicitly confirmed). 
- 🔑 **Rule of thumb: a row/list/inventory OF SKUs = filterable; a date-activity LOG = never filtered, dropdown-selection only.**

**Implementation note (as-built, `45b0871`):** `lookup-panel.js` **no longer emits `data-sku-class` on result cards** nor listens for `skufilter:change` (date results are never hidden). The control **moved from above-the-tabs to INSIDE the batch pane** in `lookup-panel.php`. `sku-class-filter.js` + the warehouse PF row-filtering are UNCHANGED.

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
