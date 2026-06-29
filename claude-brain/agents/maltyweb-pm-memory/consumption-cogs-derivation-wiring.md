# Consumption / COGS / COP derivation wiring — v1 vs v2, module-by-module (VERIFIED LIVE 2026-06-08)

> Load when: reasoning about which production version (v1 / v2) each computational module reads, why a month's `inv_consumption` is under-populated, the `inv_consumption` writer chain, the COGS/COP engine location, or "migrate the computational modules onto maltyweb". DB-verified 2026-06-08 — re-verify the row counts before quoting, but the WIRING shape is the durable fact.

## ✅ DERIVER ORPHAN-PRUNE — AS-BUILT (LANDED + PUSHED 2026-06-19, maltytask `3b21ba8`; `--apply` GATED with Kouros)
`scripts/derive-bd-consumption.ts` does a **WHOLE-TABLE re-derive** (no batch/header/run scope — reads ALL non-tombstoned `bd_brewing_ingredients_parsed_v2` lines + ALL non-tombstoned `bd_fermenting_v2` DryHop rows every run). It INSERT IGNOREs only → an idempotent re-derive that only ADDS leaves orphan rows when a `corriger`/re-parse DROPS a parsed line on an already-derived batch (the parsed_v2 row is hard-deleted but its `inv_consumption` row survives → an orphaned COP-bearing phantom). This is now the canonical "**a derived table that only INSERTs must also PRUNE**" anti-pattern (encoded in the `coder` skill SKILL.md + references/anti-patterns.md, and maltytask `feedback_derived_table_must_prune_orphans.md`).

**AS-BUILT (commit `3b21ba8`, pushed):** added **step 3** inside `applyWrites`' `withTransaction` (after the existing INSERT IGNORE + the legacy NULL-srid delete) — prunes `inv_consumption` rows whose `source_row_id` no longer maps to a live parsed line, via `NOT EXISTS` using the **SAME liveness predicate the derive functions read** (brewing: `parsed_v2 JOIN header is_tombstoned=0`; fermenting: `bd_fermenting_v2 event_type='DryHop' is_tombstoned=0`). Gated per `source_event` via the `sourceEvents` array (DO_BREWING/DO_FERMENTING/`--only`). Scope = **whole-table per source_event** (matches the deriver's existing whole-table-per-source_event re-derive — no per-batch prune invented; there is no per-batch run scope to respect). Added: `orphanPruned` counter on `WriteResult` + in the `[APPLY]` console line; `orphan_pruned` in the `logAudit` meta; a default-dry-run "orphan rows to prune" count per active event; and a **post-write convergence assertion** that re-counts and `console.error`s loudly if any orphan remains. `--dry-run` DEFAULT / `--apply` opts in / `--only brewing|fermenting`. TypeScript clean.

**Liveness verified (dry-run, read-only):** exactly **9 brewing / 0 fermenting** orphans. The 9 are GENUINE stale orphans, NOT data loss: parsed_v2 ids 790-793 + 3963-3967 are hard-deleted (return empty), all Double Oat **2025-02-06** — for which there is NO live brew, but there IS a live **2025-02-27** Double Oat brew with near-identical composition → a superseded re-dated brew; pruning removes a double-count. `inv_consumption` ids 10751-10759. Fermenting clean today, same structural gap covered.

**🔴 NOT YET `--apply`'d (gated with Kouros) — the 9 phantoms persist until someone runs `--apply`.** It's a full COP re-derivation touching historical (Feb 2025) numbers, so timing is left to Kouros / next-close prep. **SEAL SCOPE — Feb 2025 is OUT of the sealed-fiche scope → the 9-row prune is SILENT, NOT a CFO/Thierry restatement.** The `cogs_fiche` seal/seed model begins at the **April-2026 EXT-signed `cogs_fiche_seed` anchor** (opening of May = closing of April); `fin_closeable_months()` / `cogs_fiche_resolve_month()` only resolve April-2026-onward, and Feb 2025 has NO `cogs_fiche_seed` row, NO `cogs_fiche_monthly` row, and NO sealable representation. Feb 2025 only ever fed the legacy **Node/BSF** COGS/COP pipeline (`cop_monthly`/`cogs_monthly` are 0-row; the Sheet output is not the sealed fiche). So the prune corrects an `inv_consumption` double-count well below the fiche's signed horizon — no `cogs_fiche_sealed` row exists for it to restate, nothing for Thierry to re-sign. **Caveat to relay:** IF a future arc back-extends the fiche seed earlier than April-2026 (no plan to), revisit — but as of the current seal model, silent. (Seal model → financier-cogs-fiche.md §SEAL/RESTATE GATE + §`cogs_fiche_seed` mig 332.)

## 🔴 LIVE GAP 2026-06-29 (Kouros RAW-DB / phpMyAdmin probe) — PACKAGING deriver is STALE; bd_packaging_v2 itself intact
**Surface that prompted this = a RAW phpMyAdmin browse of `bd_packaging_v2` showing a "wall of NULLs" — NOT a UI page.** Two distinct findings, neither a regression:

1. **`bd_packaging_v2` is INTACT — no regression.** 2321 live rows, events through **2026-06-26**; all FACT columns fully populated: recipe_id_fk 0 NULL, run_type 0 NULL, event_date 0 NULL, prod_total_units 1 NULL. The "wall of NULLs" in the browse = the VESTIGIAL columns below, 100% NULL **by design**, not data loss.
2. **The live gap = PACKAGING-CONSUMPTION DERIVATION IS STALE** (supersedes the 06-28 "packaging … consumed 06-05 (current)" line below — that 06-05 max was already the STALE frontier, mis-read as current). `inv_consumption WHERE source_event='packaging'` max `consumed_at` = **2026-06-05** (8565 rows), but `bd_packaging_v2` runs go to **2026-06-26** → **555 packaging events have NO inv_consumption row**. `scripts/derive-packaging-consumption.ts` is NOT cron'd and has not been run since early June. This is the packaging analogue of the brewing/eshop deriver-lag pattern: the deriver is manual `npx tsx` (table row 28), no maltyweb/cron home (= build-sequence step 2 below, still owed). COP/COGS impact: 06-06→06-26 packaging consumption is missing from the feed.
   - 🔴 **NOT YET RUN — awaiting Kouros approval for a `--dry-run`** (then `--apply`). The deriver is whole-table per source_event INSERT-IGNORE + orphan-PRUNE (the `3b21ba8` prune step also covers packaging via the `sourceEvents` gate), so a re-run is idempotent + self-pruning; expected effect = +~555 events' worth of packaging conso rows, consumed_at advancing 06-05→06-26. **SEAL SCOPE:** June 2026 is the OPEN (unsealed) month → re-deriving it is normal close-prep, not a CFO/Thierry restatement; the cogs_fiche seal model only freezes signed months (April-2026-onward signed; June not yet sealed). Verify per VERIFICATION RECIPE before/after.

**VESTIGIAL columns on `bd_packaging_v2` (100% NULL BY DESIGN — do NOT treat as regressions, do NOT backfill, do NOT FK-join):**
- `session_id_fk` — legacy mother-shell linkage, never back-populated.
- `cip_tank_done` / `cip_tank_type` / `cip_tank_date` + `cip_machines_done` / `cip_machines_type` / `cip_machines_date` (all 6) — CIP data now lives in **`bd_cip_events`** (1386 rows); these cols are vestigial.
- `selection_can_mi_id_fk` — never wired.
- `submitted_by_user_id_fk` — NULL on the 1306 normalizer-seeded HISTORICAL rows (this one IS populated on web-entry rows, so it's a provenance gap on legacy rows, not a dead column).

**Companion table facts (Kouros 06-29 census):** there is **NO `bd_brewing_v2` table** — the brewing source family = `bd_brewing_brewday_v2` (headers) + `bd_brewing_ingredients_v2` (header lines) + `bd_brewing_ingredients_parsed_v2` (4998 rows) + v1 `bd_brewing_ingredients_parsed` (1836). `bd_fermenting_v2`=6781, `bd_racking_v2`=419, `bd_tank_readings`=1655, `bd_cip_events`=1386. (The deriver table above already keys off the correct brewing tables — no wiring error, just confirming the name.)

## 🟢 OBSERVED LIVE 2026-06-28 (PM probe) — brewing conso NO LONGER frozen; sales_derived still stale
`inv_consumption` GROUP BY source_event live: **brewing 4963 rows reaching consumed_at 2026-06-18, imported_at 2026-06-19** (the §"THE REAL GAP" 2024-06-17 freeze appears RESOLVED — derive-bd ran 06-19 with current lines; CONFIRM the parsed_v2 writer/backfill path before treating as durably fixed). fermenting 288 → 06-17. packaging 8565 → consumed 06-05 (🔴 **SUPERSEDED by the 06-29 finding above — this 06-05 max was STALE, not current**). **sales_derived 3633 STILL STALE at 2026-04-30 / imported 2026-05-22** — eshop/Shopify consumption NOT derived past April (the May/June `derive-eshop-consumption.ts` run is still owed). COGS/COP engine UNCHANGED: `cop_monthly`/`cogs_monthly` still Node/BSF (re-verify 0 rows). NB: this is `inv_consumption` (COP/COGS feed), a DIFFERENT fact from the FG-stock board's `fg_stock_compute()` (on-hand units) — they do not share a depletion path.

## TL;DR — the corrected premise (Kouros's May-close escalation 2026-06-08)
The "May saisie is thin / 287 consumption rows" alarm was **a false comparison**, BUT it surfaced a **real, separate gap**. Two distinct truths:
1. **May v2 PRODUCTION is complete** (17 brewdays / 113 fermenting / 57 packaging / 12 racking — all web-entry, max event_date 2026-05-29). Kouros is right that v2 fully represents May.
2. **The 287-vs-1022 row gap is mostly `sales_derived`**, NOT production. Strip `sales_derived` (which is NOT yet derived for May — last = 2026-04-30): April PRODUCTION conso = 73 brewing + 6 ferment + 189 pkg = **268**; May = 114 + 11 + 162 = **287**. May production conso is actually HIGHER than April. So inv_consumption is NOT "thin" for May in aggregate.
3. **BUT the brewing/fermenting conso is STALE + sourced from a FROZEN v2 child table** — see THE REAL GAP below. The packaging conso IS current.

## THE DERIVATION SCRIPTS — all live in the maltytask Node/TS repo, ALL already read v2, NONE on maltyweb, NONE cron'd
`/home/kluk/projects/maltytask/scripts/` — verified by grepping the FROM/JOIN clauses 2026-06-08:
| Module | Writes | Reads (source) | v1/v2 | On maltyweb? | Cron? |
|---|---|---|---|---|---|
| `derive-bd-consumption.ts` | `inv_consumption` brewing+fermenting | `bd_brewing_ingredients_parsed_v2` (JOIN header_id→`bd_brewing_ingredients_v2`), `bd_brewing_gravity_v2` (cooling_count), `bd_fermenting_v2` (event_type='DryHop') | **v2** | NO (Node/TS) | NO (manual) |
| `derive-packaging-consumption.ts` | `inv_consumption` packaging | `bd_packaging_v2` × `ref_sku_bom` × `ref_skus.units_per_pack` | **v2** | NO | NO (manual) |
| `parse-tank-simulation.ts` | tank-sim / WIP (Sheet `Tank_Balances`) | `bd_brewing_brewday_v2`, `bd_racking_v2`, `bd_packaging_v2`, `bd_brewing_ingredients_parsed_v2`; **EXCEPT `bd_brewing_cooling` = v1 (no v2 equivalent, still canonical)** | **v2** (1 v1 leg) | NO | NO |
| `build-sales-cogs.ts` | COGS (Sheet) | `inv_sales_bc` + `inv_sales_order_lines` × `ref_sku_bom` × WAC | (sales tables) | NO | NO |
| `derive-eshop-consumption.ts` | `inv_consumption` sales_derived | Shopify orders × outer-pkg | — | NO | NO |
| `parse-all-consumption.js` (LEGACY) | BSF `Consumption_Parsed` | BSF BrewingData/Ferment/Racking/Packaging (v1) | **v1/BSF** | NO | NO |
| `run-month-close.js` (orchestrator) | all of the above + COGS/COP report | — | — | NO | NO |

**KEY CORRECTION to prior memory:** the TS `derive-*-consumption.ts` + `parse-tank-simulation.ts` are ALREADY MIGRATED TO v2 sources (not v1). RESUME-POINT #3's "parse-tank-simulation.ts STILL name-keyed" is about the **KEYING** (matches on beer-NAME not `(recipe_id_fk,batch)` — COGS/WIP-impacting fragility), NOT the v1/v2 source. The source IS v2.

## 🔴 THE REAL GAP — `bd_brewing_ingredients_parsed_v2` is FROZEN at 2024-06-17
- **`bd_brewing_ingredients_parsed_v2` (the brewing ingredient LINES) only covers up to header `event_date` 2024-06-17.** It has NO `audit_flags` column → it is **normalizer-seeded ONLY** (RawDB snapshot), never extended by web-entry. Every web-entry brewday (hid 1339-1428, May-June 2026) has `parsed=0` children.
- Contrast: the HEADER `bd_brewing_ingredients_v2` DOES reach 2026-06-08 (682 rows) — but its parsed-line child does not. And `bd_brewing_gravity_v2`/`bd_fermenting_v2`/`bd_packaging_v2`/`bd_racking_v2` ARE current to May/June (web-entry). So the gap is SPECIFIC to the brewing-ingredient-LINE table.
- **Consequence:** `derive-bd-consumption.ts` reading `bd_brewing_ingredients_parsed_v2` for May → returns **0 brewing lines** (proven: `parsed_v2 lines for May brewdays: 0`). A re-run today would WIPE the existing May brewing conso, not refresh it.
- **Where the May brewing ingredient lines ACTUALLY live = v1 `bd_brewing_ingredients_parsed`** (NOT the _v2 variant): May=83, June=4 lines, proper `event_date`, current. This v1 table IS up to date (it mirrors live v1 input, per the canonical v1/v2 architecture: v1 is still-live).
- **The 114 May brewing rows currently in inv_consumption** are STALE (imported_at 2026-05-24/05-28) — a leftover from a derivation run that sourced the v1 `*_parsed` lines BEFORE derive-bd was pointed at the v2 child. They are NOT regenerable from v2 as-is.

## So WHY is May inv_consumption "only 287"? — the precise answer
- **Brewing (114) + fermenting (11):** present but STALE (imported 2026-05-28) and NOT refreshable from v2 because `bd_brewing_ingredients_parsed_v2` is frozen at 2024-06. The fermenting DryHop driver (`bd_fermenting_v2` event_type='DryHop') IS current (15 May DryHop events) → fermenting re-derivable; brewing is the broken leg.
- **Packaging (162):** CURRENT + correct — `derive-packaging-consumption.ts` re-ran 2026-06-06 (post the 24× fix `942431e`), reads `bd_packaging_v2` (current). NOT the historical PKG_* gap (that was fixed). June packaging (99) also present.
- **sales_derived (0 for May):** simply NOT YET DERIVED for May (last = 2026-04-30). This accounts for the bulk of the headline 287-vs-1022 delta. NOT a bug — `derive-eshop-consumption.ts` + sales ingest just hasn't been run for May.

**Root cause label = (a) the derivation reads a v2 child table (`bd_brewing_ingredients_parsed_v2`) that has NOT been populated past the migration snapshot for the brewing-ingredient LINES, while the live lines sit in v1 `bd_brewing_ingredients_parsed`.** This is the brewing-ingredient analogue of the v1/v2 split, AND a missing web-entry→parsed_v2 population step. NOT (b) "v2 not re-run" alone, NOT (c) the old PKG_* packaging gap.

## THE FIX — what populates `bd_brewing_ingredients_parsed_v2` for recent brewdays?
TWO candidate paths (CONFIRM before building — do NOT guess the writer):
1. **Web-entry brewing form should emit parsed_v2 lines on submit** (the `parse_ingredient_blob` path / brewing-phase-submit) — if the brewing saisie form captures ingredients but doesn't write `bd_brewing_ingredients_parsed_v2`, that's the missing write. GREP: `grep -rn "bd_brewing_ingredients_parsed_v2" /home/kluk/projects/maltyweb` to see if any PHP writes it.
2. **OR a backfill from v1 `bd_brewing_ingredients_parsed` → v2** (the lines exist in v1, current to June) — a one-shot/recurring sync keyed on `(beer,batch)`→`header_id`. This is the faster unblock for the May close.
The v2-only directive says the GO-FORWARD target is v2, so path 1 (form writes parsed_v2) is the durable answer; path 2 unblocks May now.

## COGS/COP ENGINE — NOT on maltyweb, NOT in MySQL yet
- `cop_monthly` / `cogs_monthly` / `mi_weighted_prices_monthly` = **0 rows each** (verified 2026-06-08). `schema_meta`: all `derived / blocked_with_redirect`, writers = the LEGACY Node scripts (`build-cogs-report.js`, `build-month-closure.js`, `build-*-consumption.js/.ts`).
- The maltyweb-native COGS/COP/WAC builder **does NOT exist.** COGS/COP are still computed by the Node pipeline writing to the BSF Google Sheet (NOT MySQL). This is computation-layer-architecture.md LANDMINE (a): the MySQL monthly shells are empty; building a maltyweb writer while Node still runs = two-writers divergence → Node must be retired/redirected FIRST.

## MIGRATION SCOPE — "migrate the computational modules onto maltyweb"
**What is ALREADY MySQL-canonical / reads v2:** all the saisie/event capture (bd_*_v2 via app.maltytask.ch forms), `ref_sku_bom` compile (maltyweb PHP `app/sku-bom-compile.php`, on-write), packaging consumption (`derive-packaging-consumption.ts` — TS but reads v2/MySQL), the tank-simulator READ-side (`app/tank-simulator.php` v2-only, commit `08c00de`).
**What is STILL legacy Node/TS reading MySQL-v2 but NOT on maltyweb (no PHP/cron home):** `derive-bd-consumption.ts`, `parse-tank-simulation.ts`, `build-sales-cogs.ts`, `derive-eshop-consumption.ts`, `run-month-close.js`. These read v2 but run as manual `npx tsx`/`node` from the maltytask repo — they are NOT PHP modules, NOT cron'd, do NOT write the MySQL monthly tables.
**What is STILL v1/BSF:** `parse-all-consumption.js` (legacy, writes BSF Consumption_Parsed), `bd_brewing_cooling` (the one v1 leg in tank-sim), the COGS/COP report output (BSF Sheet).

**Build sequence to land the rest on maltyweb against v2 (dependency order):**
1. **Close the brewing-ingredient-line gap** (parsed_v2 population — form-write OR v1→v2 backfill). PRECONDITION to everything brewing-side. ← unblocks May.
2. **Port `derive-bd-consumption` + `derive-packaging-consumption` + `derive-eshop-consumption` to a maltyweb cron/PHP** (or schedule the TS via the lock-cascade `finalize_session` hook — computation-layer-architecture.md). One writer per `inv_consumption` source_event, recorded in `schema_meta`.
3. **Port `parse-tank-simulation` to PHP/maltyweb** AND fix the `(recipe_id_fk,batch)` keying (RESUME #3 — COGS/WIP-impacting).
4. **Build the maltyweb-native COGS/COP/WAC writer** that POPULATES `cop_monthly`/`cogs_monthly`/`mi_weighted_prices_monthly` — but ONLY after retiring the Node BSF writers (landmine a). This is the Phase-D / computation-layer Phase-2/3 arc.

**Is the full migration a precondition to closing May? NO.** May can close on a one-off re-derivation (fix the parsed_v2 brewing gap → re-run `derive-bd-consumption` + `derive-packaging-consumption` + sales/eshop derive + `build-sales-cogs` over May) producing a correct v2-based COGS/COP report via the existing Node pipeline. The full maltyweb migration is a SEPARATE arc (the computation-layer phased path). Do NOT block the May close on the migration; do NOT skip fixing the parsed_v2 gap (that IS load-bearing for May brewing COGS).

## REVISED MAY-CLOSE GATING LIST (correct v2-based close)
1. **Confirm the parsed_v2 brewing-line writer/gap** — grep maltyweb for `bd_brewing_ingredients_parsed_v2` writes; decide form-write vs v1→v2 backfill. (BLOCKER for brewing COGS.)
2. **Populate `bd_brewing_ingredients_parsed_v2` for May brewdays** (hid 1317-1341) via the chosen path; verify lines > 0 per brewday.
3. **Re-run `derive-bd-consumption.ts` over May** → verify brewing+fermenting rows regenerate from v2 (not the stale 05-24 import).
4. **Re-run `derive-packaging-consumption.ts`** (already current — idempotent re-check; verify May=162 holds / corrects).
5. **Ingest May sales + run `derive-eshop-consumption.ts`** → sales_derived for May (currently 0).
6. **Verify WAC current** for May-consumed MIs (`compute-weighted-prices` reads `inv_deliveries`).
7. **Run `build-sales-cogs.ts` / `run-month-close.js`** over May → COGS/COP report.
8. **Opus independently verifies** the May COP/COGS numbers (per-beer brewing CHF/HL plausibility, packaging totals) BEFORE they reach Kouros — COGS-bearing-claim discipline.

## VERIFICATION RECIPE (re-confirm any time)
- Per-period conso breakdown: `SELECT DATE_FORMAT(consumed_at,'%Y-%m'), source_event, COUNT(*), MAX(imported_at) FROM inv_consumption GROUP BY 1,2`.
- parsed_v2 freeze: `SELECT MAX(h.event_date) FROM bd_brewing_ingredients_parsed_v2 p JOIN bd_brewing_brewday_v2 h ON h.id=p.header_id` → if ~2024-06, the gap is live.
- v1 lines current: `SELECT DATE_FORMAT(event_date,'%Y-%m'), COUNT(*) FROM bd_brewing_ingredients_parsed WHERE event_date>='2026-03-01' GROUP BY 1`.
- COGS/COP engine: `SELECT COUNT(*) FROM cop_monthly` → 0 = still Node/BSF.
- Packaging deriver lag (06-29 gap): compare `SELECT MAX(event_date) FROM bd_packaging_v2` vs `SELECT MAX(consumed_at) FROM inv_consumption WHERE source_event='packaging'` — a multi-week gap = deriver owed; count missing events with `SELECT COUNT(*) FROM bd_packaging_v2 p WHERE NOT EXISTS (SELECT 1 FROM inv_consumption c WHERE c.source_event='packaging' AND c.source_row_id=p.id)`.
