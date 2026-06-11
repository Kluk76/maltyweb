# v1 `bd_*` tables decommission — staged DROP roadmap

> 🔴 **ASAP PRIORITY (operator escalation 2026-06-11, verbatim): "we NEED to move away from any kind of v1 dependency ASAP."** This is the STANDING priority on the arc — not a someday-cleanup. Every session that touches a `bd_*` read should ask "is this still v1, and can I repoint it now?" Original request (2026-06-11): "i believe it is time to completely erase v1 from our DB. It keeps being used by mistake and this cannot happen anymore." Driver: v1 keeps getting read by mistake instead of v2 → silent wrong COGS/tank/recipe-profile output. Scope = a **PHASE OF THE BSF-EXIT ARC**, not a new arc (the v1 writer IS the BSF Google-Form Python ingest `scripts/python/ingest.py`, the same writer BSF-exit Phase 6 retires). PM has NOT authorized any DROP — this is the roadmap-coherent plan, gated on operator go-ahead (touches COGS-critical code).
>
> **🔴 STANDING RULE (the root lesson — D below): the `beer_raw` write-encoding flipped ~2026-05-29 to bare-canonical-name + a SEPARATE batch column. ANY surface still reverse-parsing `beer_raw` in `'PREFIX BATCH'` shape SILENTLY DROPS all post-May reads. AND: never read v1 `bd_*` tables — v2 is canonical AND cleaner (dedup'd, complete); GREP for v1 table names before declaring any `bd_*` read correct.**

## 🟢 ALL v1 CONSUMERS REPOINTED — STAGE 1 COMPLETE (2026-06-11, final reader wave)
**Verified by full both-repo grep.** Every v1 `bd_*` CONSUMER is now on `_v2`: tanks.php, sku-bom-compile.php, packaging.php, beer-tax.js, build-efficiency-data.ts, refresh_recipe_profile.py, parse-tank-simulation.ts. **Only the WRITER-STOP (Stage 3) and DROP (Stage 4) remain — both gated on operator go-ahead.**

Final-wave commits (2026-06-11):
- **`b17c686` (maltyweb)** — `app/sku-bom-compile.php` §2b observed malt/hops → `bd_brewing_ingredients_parsed_v2` (header_id → `bd_brewing_ingredients_v2`). **0 v1-only keys lost, 75 v2-only gained, 1496 identical.** The **6 differing keys were a v1 DATA BUG** (v1 stored 1g/2g/5g for hops additions physically 1/2/5 **kg**; v2 has correct 1000/2000/5000 g) → repoint **CORRECTS (raises) observed hops COGS for 6 batches** (rid 51 b45, rid 57 b126 ×4, rid 30 b48). sku-bom-compile.php is now **FULLY off v1** (batch-HL + malt/hops + dry-hop all v2).
- **`ed41319` (maltyweb)** — `scripts/python/refresh_recipe_profile.py` → all 7 v1 tables to _v2 (the biggest single v1 consumer). Removed the fragile `RECIPE_TO_PREFIX` + `beer_raw`-LIKE prefix parse (rule-D hazard) → JOIN `bd_fermenting_v2`→`ref_recipes` on `recipe_id_fk`; gravity LONG→WIDE via event_type→column map; packaging pct_loss from `loss_kpi_hl/objective_hl` + O2/CO2 from `bd_packaging_readings`. Dry-run clean across 61 recipes; output stable. 🔴 **CRON `/etc/cron.d/maltytask-recipe-profile` IS DISABLED pending manual smoke — OPERATOR ACTION ITEM: smoke + re-enable.**
- **`face01f` (maltytask)** — `scripts/parse-tank-simulation.ts` cooling read → `bd_brewing_gravity_v2` (`event_type='Cooling'`), aliased back to `cool_*` (CoolingRow/downstream unchanged). A reader MISSED earlier (truncated grep); now caught — reinforces the always-re-grep missing-reader rule.

**Remaining v1 references are NOT consumers (do NOT count as readers):**
- **v1 WRITER chain (= Stage 3 target):** `scripts/python/ingest.py` (writes v1 brewday/cooling/gravity/ingredients/timings/fermenting/packaging; dedup SELECT on bd_packaging) + `scripts/python/parse_bd_ingredients.py` (reads v1 bd_fermenting + bd_brewing_ingredients, writes v1 bd_brewing_ingredients_parsed) + `tab_*.py` builders.
- **Deferred (intentional):** `app/db-correct.php` (admin-only; UPDATEs v1 `bd_brewing_ingredients_parsed.mi_id_fk` — alias corrections; moot at DROP → remove then) + `scripts/python/validate_fk_candidates.py` (dev FK-audit utility, no cron — reads bd_racking/bd_brewing_brewday).
- `app/loss-metrics.php:42` = a comment, not a query.

**Separate ingest-root ticket:** recent `bd_brewing_timings_v2` rows have NULL `recipe_id_fk` — fix at the writer, don't paper over downstream.

## ✅ tanks.php — FULLY v1-FREE (Stage 1f DONE, 2026-06-11)
`public/modules/tanks.php` is now 100% free of v1 reads — `grep 'bd_brewing_timings\b'` → **0 hits**. Two commits on maltyweb main:
- `f398163` — fermentation reads / CC cards re-keyed OFF `beer_raw`-string parsing ONTO **(recipe_id_fk, batch)** on `bd_fermenting_v2` (the identity-fracture fix — see rule D).
- `e063ddf` — fermentation-start anchor repointed OFF v1 `bd_brewing_timings` ONTO `bd_brewing_timings_v2`. Anchor = `COALESCE(MAX(STR_TO_DATE(start_ferm,…)), MIN(event_date))` keyed on **(beer, batch)**. Since `start_ferm` is all-NULL on v2 (see A), this effectively uses `event_date` (the brew date) as fermentation day-0 — the v2-native anchor, needs NO backfill.
**Remove tanks.php from the v1-reader list.** (Verified live this session.)

## ✅ COOLING + PACKAGING REPOINTS DONE (Stages 1b + 1d + the cooling half of beer-tax/efficiency, 2026-06-11)
Three commits landed; cooling (1b) and packaging (1d) reader-repoints complete across BOTH repos. **`bd_brewing_cooling` (v1) now has ZERO remaining COGS/tax/efficiency readers** (only `refresh_recipe_profile.py` still reads it — Stage 1a). `bd_packaging` (v1) only remaining reader is `refresh_recipe_profile.py`.
- **`aa6fb0f` (maltyweb)** — v1 cooling + packaging repoint.
  - `app/sku-bom-compile.php` batch-HL (the COGS per-HL denominator) `bd_brewing_cooling` → `bd_brewing_gravity_v2 WHERE event_type='Cooling'`; date proxy `DATE(submitted_at)`; key **(recipe_id_fk, batch)**. (Stage 1b — the COGS-critical cooling read.)
  - `public/modules/packaging.php` 3 KPI queries `bd_packaging` → `bd_packaging_v2` (format→`run_type` enum, `YEAR(event_date)`, `+is_tombstoned=0`). (Stage 1d.)
  - **Delta verified + operator-signed-off:** 753 batches identical, **8 corrective** (v1 dup/missing-brew per rule B), e.g. Embuscade 236 32.8 → 128.0 HL. The corrective COGS shift is accepted.
- **`262945a` (maltytask Node)** — v1 cooling repoint: `lib/beer-tax.js` `loadOGByBeer` (OG median) + `scripts/build-efficiency-data.ts` (brewed volume) → `bd_brewing_gravity_v2` Cooling.
  - **🔴 Beer-tax independently verified (Opus per the COGS-bearing rule): ALL core-beer median OGs UNCHANGED (0.0°P delta), ZERO tax-category shifts.** v2 even drops a suspicious v1 NYL 23.2°P blank-batch outlier. Efficiency: only the known dup/missing-brew batches move.
  - **This closes the cooling half of beer-tax + efficiency.** (The OG-at-cooling tax-blast reader is now on v2.)

## Live audit (Kouros, 2026-06-11) + PM verification (2026-06-11, read-only against prod)
All figures PM-verified live unless noted.

### v1 still ACTIVELY WRITTEN (not dormant)
- Writer = `scripts/python/ingest.py` (+ `tab_brewing.py`, `tab_packaging.py`) — BSF Google-Form ingest. Writes v1: bd_brewing_brewday, bd_brewing_gravity, bd_brewing_cooling, bd_brewing_ingredients, bd_brewing_timings, bd_fermenting, bd_packaging.
- PM-verified freshness: `bd_brewing_brewday` 835 rows, MAX(event_date)=**2026-06-08**; `bd_fermenting` 6606; `bd_packaging` 2204; `bd_brewing_cooling` 2322 rows. v2 siblings fresh to 06-10/06-11. → ingest dual-populates v1+v2. **Stopping/repointing this writer is the same job as BSF-exit Phase 6.**

### start_ferm BLOCKER — ⚠️ CORRECTED 2026-06-11: backfill is LARGELY MOOT (A)
**Earlier "resolve via v1→v2 backfill" plan was WRONG — verified live this session:**
- `bd_brewing_timings_v2.start_ferm` = **0/2271 populated (100% NULL, BY DESIGN)** — the v2 brewing form captures `brew_start`/`brew_end`/`event_date`, NOT a separate fermentation-start. So there is nothing to backfill INTO that's semantically a ferment-start.
- v1 `bd_brewing_timings` ALSO has many gaps; AND **recently-brewed batches have ZERO rows in v1 timings at all** (e.g. Moonshine 125, brewed 2026-06-09) → a v1→v2 `start_ferm` backfill would NOT cover them. The backfill is not a reliable source.
- **Resolution that landed (in tanks.php `e063ddf`): use the v2-native anchor** = `COALESCE(MAX(STR_TO_DATE(start_ferm,…)), MIN(event_date))` from `bd_brewing_timings_v2`, keyed on **(beer, batch)**. Since `start_ferm` is all-NULL, this falls through to `event_date` (the brew date) as day-0. No backfill required for tanks.php.
- ⚠️ **KEYING FACT (load-bearing): `bd_brewing_timings_v2.recipe_id_fk` is NULL on recent rows** (incl. Moonshine 125's 2026-06-09 row). So **timings-v2 reads MUST key on (beer, batch), NOT recipe_id_fk.** This is a separate NULL-FK data-gap (recent v2 timings ingest isn't resolving `recipe_id_fk`) — **worth its own ticket; fix at the ingest/master-data root** (NULL-FK = unresolved-link smell per the minimize-NULL rule), do NOT paper over downstream.
- **ACTION: Stage 2 (start_ferm backfill) is largely MOOT.** Re-scoped below: only `refresh_recipe_profile.py` (the other `start_ferm` consumer) still needs deciding — if it can adopt the same `event_date` anchor, **drop the backfill stage entirely.**

### Cooling read (COGS-CRITICAL) — repoint target confirmed; ✅ v2 is CLEANER, not lossy (B, corrected 2026-06-11)
- `app/sku-bom-compile.php:2480-2488` reads `bd_brewing_cooling` for **batch HL = SUM(cool_final_volume_hl) per (cool_beer_recipe_id, cool_batch)** — the **denominator of every per-HL COGS number**. Highest blast radius.
- v1 `bd_brewing_cooling` has NO `_v2` table. Cooling folded into `bd_brewing_gravity_v2 WHERE event_type='Cooling'`. The volume column is **`final_volume`** (NOT `final_volume_hl` — that name doesn't exist in v2). PM-verified: gravity_v2 Cooling rows = 2286, with final_volume>0 = **2283**. So repoint = `SELECT recipe_id_fk, batch, SUM(final_volume) … FROM bd_brewing_gravity_v2 WHERE event_type='Cooling' AND is_tombstoned=0 GROUP BY recipe_id_fk,batch`.
- ⚠️ **JOIN BY THE v2 KEY, NOT v1's stale `cool_beer_recipe_id`.** The earlier "34 v1-only (recipe_id,batch) orphans" worry was WRONG: all 34 are EPH seasonal batches that DO exist in v2 under a **different recipe_id** (recipe_id remaps across vintages — e.g. EPH1 b25 v1 rid=61 → v2 rid=62; EPH2 → rid=6/76). **0 genuinely missing.** Repoints must join by the v2 `recipe_id_fk` (or beer name), not v1's stale id.
- ⚠️ **NO `event_date` column on `bd_brewing_gravity_v2`.** Cooling-date proxy = `DATE(submitted_at)` (verified to align with v1 `event_date` on samples) or JOIN `bd_brewing_brewday_v2`.
- 🔴 **The earlier "8 volume mismatches (25-50%)" were v1 being WRONG, not v2 losing data:** v1 `bd_brewing_cooling` has DUPLICATE brew rows (e.g. rid=51 b52 brew 3 duplicated → inflated 124.0 vs correct 93.1) AND MISSING brews (rid=44 b123: v1 has 2 brews=68.1, v2 has the correct 3=101.7). v2 is **dedup'd + complete**. OG (`final_gravity`) matches v1↔v2; only the volume SUM differed, because of v1's dupes/gaps.
- 🔴 **CONSEQUENCE — COGS/efficiency NUMBERS WILL MOVE as a CORRECTION.** Repointing `sku-bom-compile.php` (batch-HL = COGS per-HL denominator), `build-efficiency-data.ts` (brewed volume), and `beer-tax.js` (OG) onto gravity_v2 corrects v1's dupes/gaps. **This MUST be flagged to Kouros with a before/after batch-HL delta report for the affected batches BEFORE it lands** (per the COGS-bearing checklist rule) — COGS moves, even though the move is a fix.

### Single biggest v1 consumer
- `refresh_recipe_profile.py` (nightly recipe-profile cron) reads v1: cooling, gravity, timings, fermenting, packaging, racking, ingredients_parsed. **Must be FULLY repointed to v2 first** — it's the broadest reader.

### FULL v1-reader set (C — confirmed live BOTH repos, 2026-06-11; tanks.php + cooling + packaging now DONE)
**✅ DONE / REMOVED from the list:**
- ~~tanks.php~~ — Stage 1f, commits `f398163` + `e063ddf`.
- ~~`app/sku-bom-compile.php` cooling read~~ — Stage 1b, commit `aa6fb0f` (→ gravity_v2 Cooling).
- ~~`public/modules/packaging.php` ×3~~ — Stage 1d, commit `aa6fb0f` (→ packaging_v2).
- ~~`lib/beer-tax.js` cooling read~~ — commit `262945a` (→ gravity_v2 Cooling; Opus-verified 0.0°P / 0 tax shifts).
- ~~`scripts/build-efficiency-data.ts` cooling read~~ — commit `262945a` (→ gravity_v2 Cooling, brewed volume).

**✅ FINAL WAVE — NOW ALSO DONE / REMOVED from the list (2026-06-11):**
- ~~`app/sku-bom-compile.php` ingredients read~~ — commit `b17c686` (→ `bd_brewing_ingredients_parsed_v2` / header → `bd_brewing_ingredients_v2`). 0 keys lost, 6 differing = a v1 g/kg DATA BUG that v2 corrects (raises observed hops COGS for 6 batches). sku-bom-compile.php now FULLY v1-free.
- ~~`scripts/python/refresh_recipe_profile.py` (7 v1 tables)~~ — commit `ed41319` (→ all v2; removed beer_raw prefix-parse → recipe_id_fk JOIN; event_date anchor adopted for the start_ferm read). Dry-run clean / 61 recipes / output stable. 🔴 cron DISABLED pending operator smoke + re-enable.
- ~~`scripts/parse-tank-simulation.ts` cooling read~~ — commit `face01f` (→ gravity_v2 Cooling; earlier-missed reader, now caught).

**🟢 ZERO v1 CONSUMERS REMAIN.** All `bd_*` v1 tables now have only the WRITER chain referencing them (ingest.py + parse_bd_ingredients.py + tab_*.py) plus the two intentionally-deferred refs (db-correct.php UPDATE, validate_fk_candidates.py dev utility) and one comment. → **Stage 3 (stop the writer) is READY, gated on operator** → Stage 4 (snapshot + DROP, operator-gated + soak).

### NOT v1 — KEEP (no `_v2` suffix but current canonical; must NOT be dropped) — confirmed 2026-06-11 (C)
- `bd_packaging_readings` (child of bd_packaging_v2, keyed by packaging_v2_id; live r/w packaging-stats.php, kpi-handlers.php, form-packaging.php).
- `bd_cip_events` (current CIP; live r/w).
- `bd_tank_readings` (CONFIRMED current canonical — KEEP).
- Snapshot tables `bd_packaging_v2_*_snapshot_*` — backups, separate cleanup.

## STAGED SEQUENCE (gating order)
**Stage 0 (NOW, no-risk):** lock the writer's v1-vs-v2 understanding — confirm whether ingest.py v1 writes are dual-populate or v1-then-normalizer-builds-v2 (read ingest.py + tab_*.py). Determines whether stopping v1 writes needs the normalizer repointed to read the form directly. Inventory every v1 reader with grep on the VPS (don't trust the audit list as complete) — see "missing readers" below.

**Stage 1 — REPOINT READERS — ✅ COMPLETE (2026-06-11):**
- 1a. ✅ **refresh_recipe_profile.py → all v2 — DONE** (`ed41319`). All 7 v1 tables to _v2; beer_raw prefix-parse killed → `recipe_id_fk` JOIN; start_ferm read adopts the v2-native `event_date` anchor. Dry-run clean / 61 recipes. 🔴 cron DISABLED pending operator smoke + re-enable.
- 1b. ✅ **sku-bom-compile.php cooling read → gravity_v2 Cooling — DONE** (`aa6fb0f`). Delta operator-signed-off: 753 identical, 8 corrective (v1 dup/missing-brew, B), e.g. Embuscade 236 32.8→128.0 HL.
- 1c. ✅ **sku-bom-compile.php ingredients → ingredients_parsed_v2 — DONE** (`b17c686`; header → bd_brewing_ingredients_v2). 0 keys lost, 6 differing = v1 g/kg data bug corrected (raises hops COGS for 6 batches). db-correct.php is the deferred admin UPDATE branch (removed at DROP).
- 1d. ✅ **packaging.php (3 queries) → packaging_v2 — DONE** (`aa6fb0f`; format→run_type enum, YEAR(event_date), +is_tombstoned=0).
- 1e. **parse_bd_ingredients.py** = NOT repointed — it is a WRITER, not a consumer; it stops at Stage 3 along with ingest.py.
- 1f. ✅ **tanks.php — DONE** (`f398163` + `e063ddf`); re-keyed to (recipe_id_fk/beer, batch) on v2, fermentation anchor on `event_date`. 100% v1-free.
- (also done) ✅ **lib/beer-tax.js + build-efficiency-data.ts cooling reads → gravity_v2 Cooling** (`262945a`). Opus-verified: 0.0°P core-beer OG delta, 0 tax-category shifts.
- (also done) ✅ **scripts/parse-tank-simulation.ts cooling read → gravity_v2 Cooling** (`face01f`; earlier-missed reader).

**Stage 2 — start_ferm — ⚠️ RE-SCOPED 2026-06-11: BACKFILL LIKELY MOOT (A).**
- The v1→v2 `start_ferm` backfill is NOT viable (v2 `start_ferm` 100% NULL by design; v1 has gaps AND zero rows for recent batches). tanks.php already uses the v2-native `event_date` anchor with NO backfill.
- **REMAINING TASK = the SINGLE Stage-2 item: decide whether `refresh_recipe_profile.py` can adopt the same `event_date` anchor.** If yes → **DROP the backfill stage entirely** (no migration). If `refresh_recipe_profile` genuinely needs a true ferment-start date that `event_date` can't proxy, escalate as a data-model gap (capture ferment-start in the v2 form), do NOT resurrect a v1 backfill. **After Stage 1 + this decision, NO live reader touches any v1 table.**

**Stage 3 — STOP THE v1 WRITER (gated on Stages 1+2 = zero v1 readers):**
- Repoint/disable ingest.py v1 writes (= BSF-exit Phase 6 work; if Phase 6 form-rebuild isn't ready, minimal step = make ingest.py write v2 only / stop v1 INSERTs). v1 tables now frozen (no new writes, no reads).

**Stage 4 — SNAPSHOT + DROP (gated on Stage 3 + a soak period):**
- Let v1 sit frozen ≥1–2 weeks (catch any missed reader via a grep + an error-log watch). Snapshot each v1 table (CREATE TABLE …_v1_archive AS SELECT, or mysqldump) before DROP. Then DROP the v1 tables. schema_meta rows for dropped tables → mark retired/remove.

## What can be done NOW vs gated (updated 2026-06-11 — STAGE 1 COMPLETE)
- **✅ DONE — ALL READERS REPOINTED (Stage 1 complete):** tanks.php (`f398163`+`e063ddf`); sku-bom-compile cooling (`aa6fb0f`) + ingredients (`b17c686`); packaging.php (`aa6fb0f`); beer-tax.js + build-efficiency-data.ts cooling (`262945a`); parse-tank-simulation.ts cooling (`face01f`); refresh_recipe_profile.py whole-file (`ed41319`). **ZERO v1 CONSUMERS REMAIN.**
- **Stage 2** = MOOT (start_ferm anchored on `event_date`; refresh_recipe_profile adopted it). No migration.
- **🟢 READY (gated on operator go-ahead) — Stage 3 = STOP THE v1 WRITER:** ingest.py + parse_bd_ingredients.py + tab_*.py. Zero consumers depend on v1 reads now, so freezing the writes is safe. = BSF-exit Phase 6 work; minimal step = make ingest.py write v2 only / stop v1 INSERTs.
- **GATED — Stage 4 = SNAPSHOT + SOAK + DROP** (gated on Stage 3 + a ≥1-2wk soak): snapshot each v1 table, watch error logs, then DROP; remove the db-correct.php v1 UPDATE branch; mark schema_meta rows retired. **No DROP without operator go-ahead — COGS-critical.**
- **🔴 OPERATOR ACTION ITEM (independent of the gate):** re-enable `/etc/cron.d/maltytask-recipe-profile` — it is DISABLED pending a manual smoke of the v2-repointed `refresh_recipe_profile.py` (`ed41319`). Until re-enabled, the recipe-profile table is NOT refreshing nightly.
- **Separate ingest-root ticket:** recent `bd_brewing_timings_v2` rows have NULL `recipe_id_fk` — fix at the writer.

## Missing-reader hunt (PM standing concern — high blast radius)
PM rule = grep the FULL VPS/both repos for every v1 table name before declaring zero-readers. **Full both-repo grep 2026-06-11 confirms ZERO v1 CONSUMERS remain** — the only v1 references left are the writer chain (ingest.py + parse_bd_ingredients.py + tab_*.py), the two deferred refs (db-correct.php UPDATE, validate_fk_candidates.py dev utility), and one comment (loss-metrics.php:42). The `parse-tank-simulation.ts` cooling reader (`face01f`) was a real catch this session — an earlier grep had truncated and missed it — so **STILL re-grep before the Stage-3 writer-stop and again before Stage-4 DROP** (KPI handlers, verify/backfill scripts, any other `lib/` Node over the tunnel). All COGS/tax-bearing repoints in this arc were Opus numeric-verified (cooling OG 0.0°P / 0 tax shifts; ingredients 6-batch g/kg correction; batch-HL delta operator-signed-off).

## EQUIP
Repoints: `coder`+`sql` (+`ui`+`webapp-testing` for tanks.php/packaging.php deployed-page smoke). Python crons (refresh_recipe_profile, parse_bd_ingredients, ingest): `coder`+`sql`. Migration (start_ferm backfill, DROPs): `sql`. COGS-bearing repoints (1b/1c, beer-tax): Opus independent numeric verify per the checklist.
