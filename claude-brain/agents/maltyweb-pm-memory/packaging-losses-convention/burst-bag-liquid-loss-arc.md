# BURST-BAG / PACKAGING-LIQUID-LOSS MIS-BOOKING — ✅ SHIPPED 2026-06-09 (maltyweb commit `3d96847`)

> COGS / WIP / beer-tax-impacting. ✅ **SHIPPED + COMMITTED 2026-06-09 — maltyweb `3d96847` "fix(packaging): track burst-bag liquid loss as loss, not negative production".** Parent index entry = RESUME #6b. The PM ruling below was the brief; the §SHIPPED RECORD is what actually landed. Cross-links: packaging-losses-convention.md, volume-dimension.md, the -V cost-source cutover gate (index #0 / #0a5) — **see the CRITICAL FINDING below: this build produced live confirming evidence that the -V hl_per_unit revert is urgent.**

## ✅ SHIPPED RECORD (2026-06-09, commit `3d96847`)
- **Compute-fn floor** — `compute_packaging_vendable_hl()` keg/cuv branch (form-packaging.php ~L298) now floors vendable at 0 via `bccomp` (NOT `max()` literal — bcmath). NO-OP on all 1015 real keg/cuv runs; only bites pure-loss rows. `beer_tax_base_hl` derives from the floored vendable.
- **View mirror** — migration **292_floor_keg_cuv_vendable_view.sql** wraps the keg/cuv vendable arm in `GREATEST(0,…)` and rewrites the tax arm to derive from it. Applied via direct `CREATE OR REPLACE VIEW` (NOT migrate.php apply-all — to avoid clobbering the parallel session's in-flight `291_tap_shop_page.sql`, which has since been committed by that session). `schema_migrations` row inserted manually. **MIGRATION HEAD = 292 / NEXT FREE = 293.**
- **Routing + form** — cuv/keg liquid loss now routes to the typed `loss_keg_liquid_l` (loss-KPI-bearing). The cuv form field is relabelled "Perte liquide / poche éclatée" (packaging-form.js, dynamic on `run_type`).
- **Tank-sim** — drain predicate broadened to `vendable_hl>0 OR loss_kpi_hl>0`; drain = `vendable+loss_kpi`; PHP guard skips rows whose sum ≤ 0 (protects the 1303 anomaly). Lost beer now depletes the tank (Kouros approved "deplete from tank") — Q2 wrinkle RESOLVED.
- **Backfill — 6 pure-loss rows fixed:** 4 ZEPV cuv (1297/1320/2160/2217) + 2 keg (1335 EMBF 20L, 1998 SPYF 2L — moved from `loss_liquid_other_units`; the 2 keg rows from §"2 negative-vendable KEG rows" WERE folded in, as recommended). All now vendable=0, beer_tax=0, loss_kpi=5/3.5/10/5/0.2/0.02. stored==view confirmed. **+23.72 HL restored to packaged-HL AND beer-tax base** (2024-06 +8.5, 2024-07 +0.2, 2025-12 +0.02, 2026-04 +10.0, 2026-05 +5.0 — the last two are OPEN months, COGS/tax live).
- **5 void all-zero cuv rows** (1385/1386/1406/1407/1408) HARD-DELETED + tombstoned (audit action='update', after_json `_tombstone`) — §"The 5 all-zero sibling rows" disposition executed.

## 🔴 CRITICAL FINDING — live confirming evidence for the HELD -V cutover (index #0 / #0a5)
The read-path view `v_bd_packaging_v2_vendable` joins `ref_skus s ON s.id=p.sku_id_fk` and computes vendable = `(prod/units_per_pack)×s.hl_per_unit`. Because EMBV/MOOV/ZEPV still carry the **un-reverted `ref_skus.hl_per_unit=1.0`**, the view is recomputing **all 245 cuv PRODUCTION rows (prod>0) at 100×** (e.g. id=1298 ZEPV prod=500: view=500.0 HL vs stored=5.0 HL). **Stored columns are CORRECT** (written when hl_per_unit was 0.01); only the VIEW is hot. This SELF-RESOLVES the moment the compiler/liner session reverts hl_per_unit→0.01. Our 6 burst rows are immune (prod=0 zeroes the term), which is why their stored==view check passed. **This is live proof the -V revert is urgent: any consumer reading the VIEW (not the stored col) sees 100× cuv volumes TODAY.** Per the interaction flag (§INTERACTION), the loss fix was basis-robust and landed independently; this finding is the new lever on the -V gate.

## ⏳ STILL OPEN → SUPERSEDED 2026-06-09 by the LOSS-MODEL CONSISTENCY ARC
Row **1303** was flagged here as "await Kouros true-loss restatement; bottle/can NOT floored." **REVERSED 2026-06-09:** Kouros CONFIRMED 4324.5 L is a CORRECT catastrophic-loss input (not a typo). The §Q-scope "negative can vendable is ALWAYS a data error" premise is DISPROVEN. Root cause = the loss-model SUBTRACTS liquid loss from vendable in BOTH branches; the fix is to STOP subtracting (route to loss_kpi), NOT to floor. Under the corrected model 1303 → vendable ≈ +19.7 HL (positive, the ~3967 good cans), loss_kpi ≈ +43.9 HL (the real loss, visible). **1303 needs NO separate restatement — the model fix resolves it. The mig-292 floor is also SUPERSEDED (delete it).** → [loss-model-consistency-arc.md](loss-model-consistency-arc.md) is now authoritative for the loss decomposition; this burst-bag arc's SHIPPED RECORD remains the as-built history of the interim floor.

---
*Below = the original PM ruling / brief (pre-build). Kept for the record. Where it diverges from the SHIPPED RECORD above, the SHIPPED RECORD is authoritative.*

# BURST-BAG / PACKAGING-LIQUID-LOSS MIS-BOOKING — PM ruling (2026-06-09; CORRECTED 2026-06-09 — floor-at-0 required, see below)

> COGS / WIP / beer-tax-impacting. Ruling recorded, NOT yet built. Parent index entry = RESUME #6b. Live-verified read-only on VPS 2026-06-09. Cross-links: packaging-losses-convention.md (the disposition convention — burst-bag = a cuv "perte liquide fût/sac" untaxed loss), volume-dimension.md, the -V cost-source cutover gate (index #0 / #0a5).

## The 4 genuine burst-bag rows (live verified)
All ZEPV, `run_type='cuv'`, `recipe_id_fk=57`, `sku_id_fk=61`, `row_origin='main'`, `is_tombstoned=0`, `reuses_packaging_id_fk=NULL`:

| id | event_date | lost L | stored vendable_hl | stored beer_tax_base_hl | stored loss_kpi_hl | bucket used |
|---|---|---|---|---|---|---|
| 1297 | 2024-06-05 | 500 | −5.000 | −5.000 | 0.000 | loss_liquid_other_units |
| 1320 | 2024-06-26 | 350 | −3.500 | −3.500 | 0.000 | loss_liquid_other_units |
| 2160 | 2026-04-09 | 1000 | −10.000 | −10.000 | 0.000 | loss_liquid_other_units |
| 2217 | 2026-05-13 | 500 | −5.000 | −5.000 | 0.000 | loss_liquid_other_units |

Total −23.5 HL. These 4 are the ENTIRE negative-vendable cuv footprint (254 cuv rows, only 4 negative). ids 2160 (2026-04) + 2217 (2026-05) fall in recent/open closure months → live beer-tax + COGS exposure.

## Root cause — wrong column, not a missing model
The cuv liquid-loss CONCEPT already has a canonical home. Verified in `public/modules/form-packaging.php` `compute_packaging_vendable_hl()` keg/cuv branch (L285-303) and the view `v_bd_packaging_v2_vendable` keg/cuv arm:

- cuv vendable_hl = `(prod/units_per_pack × hl_per_unit) − loss_keg_liquid_l/100 − taproom_keg_l/100 − loss_liquid_other_units/100`
- cuv beer_tax_base_hl = `vendable_hl + taproom_keg_l/100` (taproom taxed; loss buckets NOT in tax)
- cuv loss_kpi_hl = `loss_keg_liquid_l/100` **ONLY** (does NOT include loss_liquid_other_units)

The burst litres were entered into **`loss_liquid_other_units`** (the legacy generic litres residual) with `prod=0`. So: vendable = 0 − lostL/100 = NEGATIVE; tax = same negative (loss_liquid_other_units IS subtracted from the tax arm too); loss_kpi = 0 (loss_keg_liquid_l is NULL). The typed `loss_keg_liquid_l` column — added in mig 231 precisely for "Perte liquide (fût)" untaxed keg/cuv litre loss, loss-KPI-bearing — was left NULL.

**🔴 CORRECTION (2026-06-09, operator-verified live + PM re-read of the compute fn):** the original claim below — "litres in `loss_keg_liquid_l` ⇒ vendable_hl = 0 naturally, zero model change" — was **FACTUALLY WRONG.** `compute_packaging_vendable_hl()` keg/cuv branch (form-packaging.php L293) does `vendableHl = bcsub(hlGross, lKegLiquid/100)` — i.e. `loss_keg_liquid_l` SUBTRACTS from vendable. With prod=0 → hlGross=0 → vendable = `0 − lostL/100 = NEGATIVE` (−5/−3.5/−10/−5), NOT 0. hl_per_unit multiplies prodTotal=0 so it is IRRELEVANT (0×anything=0, then −lostL/100; the old "hl_per_unit=0.01 ≈ 0" claim was also wrong). **Live state after the backfill (verified 2026-06-09): the 4 rows have loss_kpi_hl=5/3.5/10/5 ✓ but vendable_hl AND beer_tax_base_hl are STILL −5/−3.5/−10/−5 ✗** — beer-tax base still negative, exactly what Kouros ruled against.

**The wrong-column move is necessary but NOT sufficient. A compute-fn change IS required:** floor vendable at 0 in the keg/cuv branch:
```
vendableHl = max(0, hlGross − loss_keg_liquid_l/100 − taproom/100 − loss_liquid_other/100)
```
Effect: burst (prod=0) → vendable = max(0,−5) = 0 ✓ → beer_tax_base = 0 + taproom = 0 ✓ (no second floor needed; tax arm derives from floored vendable) → loss_kpi = 5 ✓. Normal keg/cuv run (hlGross ≫ losses) → max() is a NO-OP, byte-unchanged. The floor only ever bites a PURE-loss row (prod=0 / all-volume-is-loss), which is exactly the burst-bag shape. Model rationale: the keg/cuv formula is gross-minus-loss where prodTotal is gross committed volume and the loss buckets are the non-sellable slice; for a pure-loss event the subtraction runs against a zero gross → spurious negative; flooring is the surgical repair. **PM ruling (2026-06-09): floor-at-0 CONFIRMED CORRECT. Scope = keg/cuv branch ONLY** (see §Q-scope below). (`loss_liquid_other_units` is a paused-arc legacy bucket for cuv, but routine/legit on can lines — don't grow new cuv dependence; don't vacate the can-line use.)

The OLD (wrong) line, kept for the record: ~~"With prod=0 and litres in loss_keg_liquid_l: vendable_hl = 0 … Exactly the target state, zero model change."~~

## Q-scope — keg/cuv ONLY, NOT bottle/can (ruled 2026-06-09, data-backed)
The bottle/can branch (form-packaging.php L246-282) ALSO subtracts `loss_liquid_other_units/100` from vendable, so it has the same structural shape — BUT it must NOT be floored. Verified live: `loss_liquid_other_units` is **routine + legitimate + widespread on can lines** — 48 can rows use it (50–414 L), EVERY one with positive vendable. It is the normal can-line liquid-loss bucket (foam/drip/QA pull/line purge). On a can line a legit loss can NEVER exceed production, so a negative-vendable there is ALWAYS a data-entry error, never a model artifact → flooring would MASK the bad data instead of surfacing it. The cuv burst case is the opposite (legit zero-production loss event → negative is a model artifact). Different root cause ⇒ different treatment. **Floor keg/cuv; leave bottle/can to surface its own garbage.** Only ONE bottle/can row is negative today = id 1303 (below).

## Corrected build sequence (replaces the old "backfill alone lands it")
1. Compute-fn floor in `compute_packaging_vendable_hl()` keg/cuv branch (~L293) → `max(0, …)`. **AND** the SAME `max(0,…)` edit on the hand-maintained view `v_bd_packaging_v2_vendable` keg/cuv arm (A-LT2 codegen PAUSED, so the view does NOT regenerate — edit it by hand or stored≠view diverges again).
2. Deploy.
3. RE-recompute the 4 rows through the FIXED compute fn (fetch → compute → UPDATE the 3 stored HL cols), txn + log_revision + snapshot, idempotent. Re-establishes stored==view. (Backfill already vacated loss_other→loss_keg_liquid_l + fixed kpi; this step fixes the still-negative vendable/tax.)
4. Assert: 4 rows vendable=0 / tax=0 / kpi=5,3.5,10,5; 0 negative-vendable cuv rows remain.

## Row 1303 — separate data-quality fix, do NOT floor (flag to Kouros)
id=1303, STI4C, run_type=can, 2024-06-17, recipe 52/sku 52: `loss_liquid_other_units=4324.5` L on a 4116-can (~1358 L) run → vendable −23.41, tax −22.72, kpi 0.69. Physically impossible (can't lose 3× what you produced). NOT a mis-route (the bucket is legit on cans — see Q-scope) — it is a mis-ENTRY of MAGNITUDE (decimal/unit slip, next-largest legit value is 414.5 L). Pollutes 2024-06 packaged-HL + beer-tax base. Tank-sim `drain≤0→skip` guard stops wrong depletion; only the stored negatives are wrong. **Surface the raw figure to Kouros for him to RE-STATE the true loss (tax-base-impacting — do NOT guess); then recompute the row.** Do not floor (would hide it).

## 2 negative-vendable KEG rows (NOT in the original 4; flag — same defect class)
id=1335 (2024-07-04, recipe 32, prod=0, loss_liquid_other_units=20 L → vend −0.20, kpi 0.00) + id=1998 (2025-12-05, recipe 51, prod=0, loss_other=2 L → vend −0.02, kpi 0.00). Same pure-loss-event shape as the 4 cuv burst rows but on the KEG side AND litres in the wrong bucket (loss_liquid_other_units, not loss_keg_liquid_l). The floor (covers keg too) fixes their vendable→0, but their loss_kpi stays 0 unless the litres are ALSO moved to loss_keg_liquid_l (same backfill as the 4 cuv rows). Tiny (0.22 HL total). RECOMMEND fold into the same backfill atom for consistency, or explicitly defer — operator's call.

## Target state per the 4 rows (after fix)
| col | target | reason |
|---|---|---|
| vendable_hl | 0 | not sellable; not reduced (was negative) |
| beer_tax_base_hl | 0 | pre-sale burst is never taxed (was negative) |
| loss_kpi_hl | lostL/100 (5/3.5/10/5) | the loss now lives in the loss KPI |
| loss_keg_liquid_l | lostL (500/350/1000/500) | typed untaxed keg/cuv loss bucket |
| loss_liquid_other_units | NULL | vacate the legacy bucket |

litres/100 → HL is correct INDEPENDENT of hl_per_unit (the keg/cuv loss term is `loss_keg_liquid_l/100`), so the fix survives the -V cutover (see interaction flag below).

## Q2 (tank/WIP depletion) — the crux, ruled
`app/tank-simulator.php` L924-937 is the ONLY consumer that DEPLETES the tank from packaging. It:
- selects `vendable_hl, COALESCE(loss_kpi_hl,0)`,
- **filters `CAST(vendable_hl AS DECIMAL(14,4)) > 0`** (L934) and `if ($vendableHl <= 0) return;` (L954),
- drains the tank by `vendable_hl + loss_kpi_hl` (L966).

So TODAY the 4 negative-vendable rows are **already excluded** from the sim — the negative is NOT doing double-duty as a depletion signal; it depletes NOTHING. The lost beer is **stranded in-tank** (sim never sees it leave). Zeroing vendable does not break depletion (there is none today).

**WRINKLE:** after the fix vendable_hl = 0 (not > 0) → the `>0` filter STILL drops the row → lost beer STILL stranded. To make the lost liquid actually leave the tank, broaden the tank-sim predicate to drain rows where `vendable_hl > 0 OR loss_kpi_hl > 0`, draining `vendable + loss_kpi`. This is the one place the litres must DEPLETE (not just report). Operator already accepts "a few HL discrepancy" on this sim (L919-921), so this is in-tolerance. Optional/low-priority — the WIP carrying 23.5 HL of phantom ZEPV is small and the operator may prefer to leave tank-sim untouched; flag it, let operator decide whether (c) is in scope.

## Q4 — stored vs derived
Stored `vendable_hl/beer_tax_base_hl/loss_kpi_hl` == the view byte-for-byte (verified). They are STORED ON SUBMIT by `compute_packaging_vendable_hl()` in form-packaging.php; the view recomputes the same formula for read paths that don't trust the stored col. A-LT2 view-codegen is PAUSED, so the view is hand-maintained. ⇒ the fix is BOTH: form-logic change (route the input) + data backfill (the 4 historical rows: UPDATE the buckets + recompute the 3 stored HL cols + log_revision + snapshot). The backfill must recompute via the SAME compute fn (or equivalently set the targets above), not hand-set, so stored == view stays an invariant.

## Q5 — saisie capture going forward
The keg/cuv branch of the form already has a "Perte liquide (fût)" litres input wired to `loss_keg_liquid_l` (mig 231 / convention). The build needs to ENSURE the cuv path surfaces that field (and label it for the serving-tank/burst-bag case, e.g. "Perte liquide — sac éclaté / fût (litres)") so the next burst is captured into `loss_keg_liquid_l` at source, NOT the legacy bucket. Verify against form-packaging.php's per-`run_type` field switching (cuv is on the keg/litre side per the convention) — it may already exist and only need a label/visibility check, not a new field. Confirm before assuming a build.

## Q6 — downstream consumers (what flips when the 4 rows are corrected)
Verified consumers of these columns (maltyweb): `app/packaging-stats.php`, `app/loss-metrics.php`, `app/kpi-handlers.php`, `app/sb-board.php`, `app/tank-simulator.php`, `public/modules/packaging.php`, the view, and maltytask `lib/beer-tax.js` + `build-sales-cogs`.
- **packaging-stats.php** — `SUM(p.vendable_hl)` headline packaged-HL (L129/187/241/297): negatives currently SUBTRACT 23.5 HL → after fix those 4 rows contribute 0, packaged-HL rises by 23.5 HL across the affected months/years (2024 +8.5, 2026-04 +10, 2026-05 +5). The beer-loss-% (L665) ALREADY adds `loss_liquid_other_units/100` as the loss numerator — after the fix that term moves to `loss_kpi_hl` (the view), so re-point the inline beer-loss formula to NOT double-count: today loss% = `(loss_kpi_hl + loss_liquid_other_units/100)/vendable`; after fix the litres are in loss_kpi_hl, so the legacy-bucket term for these rows becomes 0 automatically — net loss-HL unchanged, but the denominator (vendable) stops being depressed. NET: loss-% goes DOWN slightly (bigger, correct denominator), loss-HL unchanged.
- **kpi-handlers.php** — many `SUM(vendable_hl)` monthly packaged-HL KPIs: same +23.5 HL correction in affected months.
- **beer-tax base** — `beer_tax_base_hl` goes −23.5 → 0; beer-tax base RISES by 23.5 HL (burst beer was wrongly REDUCING the tax base; correct base excludes it = 0, not negative). Live impact on 2026-04 + 2026-05 tax. `lib/beer-tax.js` reads the view (filtered is_tombstoned=0); confirm whether it reads stored vs view.
- **build-sales-cogs / FG** — general FG = vendable only; burst rows contribute 0 either way (negative was wrong-signed, not a real FG). Confirm no SUM(vendable) negative leak into a FG aggregate.
- **sb-board.php / loss-metrics.php / tank-simulator.php** — all filter `vendable_hl > 0`, so the burst rows are invisible to them today and after the fix (until tank-sim predicate broadened per Q2).

Opus must independently verify the before/after deltas (per the COGS-bearing-claims discipline): packaged-HL +23.5 split by month, beer-tax base +23.5, loss-KPI +23.5 (newly visible), 0 negative-vendable rows remaining.

## INTERACTION with the -V cost-source cutover (index #0 / #0a5)
sku 61 (ZEPV) has `hl_per_unit=1.0` — the SAME per-HL cuv outlier the cutover gate must flip to 0.01 (per-litre) to kill the ~96k phantom in build-sales-cogs. The burst-bag fix is BASIS-ROBUST: `loss_keg_liquid_l/100` = HL regardless of hl_per_unit, so the two fixes are independent and can land in either order. **The one discipline: do not let two uncoordinated sessions both edit sku-61 metadata / the cuv compute path in the same window.** If the liner/cutover session is touching ZEPV hl_per_unit + recompiling -V, that session is the natural owner to also carry the burst-bag fix (same file family, same sku). Flag to coordinate.

## The 5 all-zero sibling rows
ids 1385/1386 (2024-07-30), 1406/1407/1408 (2024-08-12) — cuv, prod=0, vendable=0, tax=0, loss=0, all buckets NULL, is_tombstoned=0, reuses=NULL. = void/abandoned cuv submissions (no event, no volume, nothing booked). Model has NO objection to DELETE-disposition (hard-delete + audit tombstone per the house convention, since there's no real event to soft-delete). Operator confirms separately. NOT burst bags — keep distinct from the 4.

## Build sequencing (recommendation)
1. (a) form/compute: ensure the cuv burst-bag/liquid-loss litres input routes to `loss_keg_liquid_l` (verify the field already exists for keg, extend/label for cuv). + (b) one-off backfill of the 4 rows (vacate loss_liquid_other_units → loss_keg_liquid_l; recompute the 3 stored HL cols via the compute fn; log_revision + snapshot; idempotent). These two land together (form-logic + data) — same RULE-2 review.
2. (c) tank-sim predicate broaden to `vendable_hl>0 OR loss_kpi_hl>0` — OPTIONAL, operator-gated (small WIP impact, in stated tolerance).
3. Operator confirms 5 void rows → DELETE in the same or a follow-up atom.

**⚠️ SEQUENCING SUPERSEDED — the §"Corrected build sequence" section above is authoritative (compute-fn floor REQUIRED, the backfill alone does NOT land it). The 3 steps here remain valid as the surrounding atom shape (form field Q5 + void-row DELETE), but step 1's "backfill" must now be the floor + re-recompute per the corrected sequence.**

EQUIP: coder + sql + ui (ui only for the cuv "Perte liquide — sac éclaté / fût" form-field label/visibility, Q5; coder for the compute-fn floor + view arm + backfill; sql for the view DDL + txn'd recompute UPDATE + verification). Opus verifies the before/after COGS/tax/loss deltas independently per the COGS-bearing-claims discipline (packaged-HL +23.5 by month, beer-tax base +23.5, loss-KPI +23.5, 0 negative-vendable cuv rows).
