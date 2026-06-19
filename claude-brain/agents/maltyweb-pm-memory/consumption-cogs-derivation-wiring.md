# Consumption / COGS / COP derivation wiring — v1 vs v2, module-by-module (VERIFIED LIVE 2026-06-08)

> Load when: reasoning about which production version (v1 / v2) each computational module reads, why a month's `inv_consumption` is under-populated, the `inv_consumption` writer chain, the COGS/COP engine location, or "migrate the computational modules onto maltyweb". DB-verified 2026-06-08 — re-verify the row counts before quoting, but the WIRING shape is the durable fact.

## 🔴 DERIVER ORPHAN-PRUNE GAP — Speakeasy-67 flag (build BRIEFED 2026-06-19, coder dispatched)
`scripts/derive-bd-consumption.ts` does a **WHOLE-TABLE re-derive** (no batch/header/run scope — reads ALL non-tombstoned `bd_brewing_ingredients_parsed_v2` lines + ALL non-tombstoned `bd_fermenting_v2` DryHop rows every run). Its only delete (L405-413) removes `source_event=? AND source_row_id IS NULL` (the legacy-NULL retrofit). **nullsrid is now 0 across ALL source_events (verified live 06-19)** → that delete is a historical no-op going forward. **GAP:** when a `corriger`/re-parse DROPS a parsed line on an already-derived batch, the parsed_v2 row is hard-deleted but its `inv_consumption` row survives (INSERT IGNORE only adds; the NULL-srid delete doesn't touch it) → an orphaned COP-bearing phantom. **PROVEN LIVE 06-19: 9 orphan brewing rows** (Double Oat 2025-02-06, srid 790-793/3963-3967 — all line-gone-entirely, header NOT tombstoned). Fermenting clean today but same structural gap. FIX (architecturally correct, Kouros greenlit) = reconcile-to-parsed **orphan-prune** added ALONGSIDE the legacy-NULL delete in the same txn: after deriving, DELETE brewing/fermenting `inv_consumption` rows whose non-NULL `source_row_id` no longer maps to a live parsed line. Because the deriver is whole-table, the prune scope = whole-table per source_event (NOT per batch — there is no per-batch run scope to respect). `inv_consumption` has `uk_row_hash` + `uk_dedup(mi_id_fk,consumed_at,source_event,source_row_id,qty)`; row_hash = `sha256("{event}|{parsed_id}|{mi_fk}")`. Prune keys on `source_row_id` existence, idempotent. Audit: existing `logAudit({action:'consumption-rederived'})` — extend meta with `orphan_pruned`. Runs `npx tsx`, `--dry-run` DEFAULT / `--apply` opts in / `--only brewing|fermenting`. → full as-built once landed.

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
