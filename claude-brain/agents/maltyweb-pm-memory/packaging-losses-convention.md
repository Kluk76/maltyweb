# PACKAGING LOSSES & DISPOSITIONS CONVENTION (KEYSTONE — operator-confirmed, authoritative single source)

> **🟢 BUILD A1 + A2 + A3 SHIPPED + LIVE + COMMITTED 2026-05-31. A1+A2 = commit `424bcf5` (migs 231+232); A3 = commit `cef2a4a` (mig 233). The disposition convention below is now AS-BUILT, not just specified. A1 = write-path unblock + 4 dispo cols + 2 computed-store cols + per-run_type fields + the CRITICAL vendable/view scrap-subtraction fix. A2 = CO2/O2 child table `bd_packaging_co2o2_measures`. A3 = 6th bottle/can disposition `loss_untaxed_full_units` (full unit + full BOM, UNTAXED, loss KPI — like invendable but EXCLUDED from beer_tax_base). NEXT FREE MIG = 234. Remaining packaging work = Build C (white-label clients) ONLY — queued, NOT built. See §BUILD A3 (DONE) + §REVISED BUILD-A SCOPE (DONE) + §OPEN.**
>
> **This file is the operator-requested SINGLE REFERENCED SOURCE for the packaging losses & dispositions convention.** Operator fully specified + confirmed 2026-05-31. It is beer-tax + COGS + FG load-bearing — zero guessing. Load when touching `form-packaging.php` losses/vendable/dispositions, `bd_packaging_v2` loss/QA/keg columns, `v_bd_packaging_v2_vendable` / `compute_packaging_vendable_hl()`, the beer-tax base, general-FG volume, or any packaged-HL → COGS/COP/WIP path. All live schema facts below verified read-only on VPS 2026-05-31. Cross-links: volume-dimension.md (vendable formula + container volume ownership), packaging-brewing-pre-framework-pass.md, packaging-bom-model/README.md (white-label).
>
> **⚠️ CONVENTION REVERSAL (record):** an earlier draft of this file said half-filled loss must be a *measured litres* value and "never approximated as 0.5 units." The operator has now CONFIRMED THE OPPOSITE for bottles/cans: **half-filled = exactly 0.5 × unit volume, a fixed standing fraction.** The litre-measured channel survives ONLY for kegs (operator knows actual filled litres). Do not re-introduce the old leaning.

---

## CORE PRINCIPLE — format-dependent, TWO families
The disposition taxonomy splits by format family. The form knows the family per format row via **`run_type`** (ENUM `bot/can/can33/keg/cuv`, already live, one per `formats[N]` row):
- **BOTTLES / CANS** (`run_type` ∈ {`bot`,`can`,`can33`}) → **UNIT-based** inputs; the system applies the liquid fraction + per-category treatments.
- **KEGS / FÛTS** (`run_type` = `keg`) → **LITRE-based** inputs; operator knows each keg's actual fill volume and sums litres. (`cuv` serving-tank is litre-native already; treat its losses on the keg/litre side.)

---

## BOTTLES / CANS — all inputs in UNITS; system applies liquid fraction + treatments

| Category | Beer vol lost (liquid attribution) | Packaging consumed | Beer tax | General FG | Counts as loss KPI |
|---|---|---|---|---|---|
| **Vendable** (COMPUTED, not input — the sellable beer) | — | full | **TAXED** (on sale) | YES | no |
| **Invendable** | FULL unit | full | **TAXED** (fully lost but consumable) | no | **yes** |
| **Perte liquide – autre** | FULL unit | **full (INCL. crown cork)** | **NOT taxed** (not consumable) | no | **yes** |
| **Perte liquide – sans capsule** | FULL unit | full **MINUS crown cork** (uncapped = why beer lost) | not taxed (cannot be consumed) | no | **yes** |
| **Perte liquide – half-filled** | **½ unit** (exactly 0.5 × unit vol) | full | not taxed | no | **yes** |
| **Bibliothèque QA** | full unit, but **NOT counted as lost** (neutral) | full | not taxed | no | **NO** |
| **Mesures QA** | full unit, **NOT counted as lost** (neutral) | full | not taxed | no | **NO** |

Rules:
- **Perte liquide – autre** = a loss type "exactly like invendable but NOT taxed." FULL unit beer lost + FULL packaging consumed INCLUDING the crown cork (identical packaging treatment to invendable; the ONLY difference from invendable is the tax flag — NOT taxed because not consumable). Counts as loss KPI. Distinct from sans-capsule: both are untaxed full-volume losses, but sans-capsule consumes packaging MINUS the crown cork whereas perte-liquide-autre consumes the FULL packaging incl. cork. **Bottle/can ONLY** (kegs already have perte liquide fût for untaxed loss — N/A for kegs).
- **Half-filled = exactly 0.5 × unit volume** (confirmed standing fraction). Input is a UNIT count of half-filled units; liquid attribution = `count × 0.5 × hl_per_unit`.
- **Sans-capsule** consumes everything EXCEPT the crown cork (the bottle was filled but never capped → beer lost; the cork was never applied so it is NOT consumed).
- **Scrapped EMPTY crown corks** = a SEPARATE material loss (existing `loss_crown_cork_units`), distinct from "sans capsule" (a filled bottle losing beer because it was never capped). **Both are real and kept separate** — do not merge.
- **Bibliothèque QA + Mesures QA**: pull a full unit of beer + full packaging, but the volume is deliberately **NOT a "loss"** (operator does not want QA holds dinging packaging loss KPIs), **NOT in FG, NOT taxed.** **Bottles/cans ONLY — never applies to kegs.**

---

## KEGS / FÛTS — LITRE-based (operator knows each keg's actual fill volume)

| Disposition | Input | Beer tax | General FG | Counts as loss KPI |
|---|---|---|---|---|
| **Invendable** | **always blank** (never happens for kegs) | — | — | — |
| **Perte liquide (fût)** | **total LITRES** (operator sums actual filled litres across all lost / under-filled kegs, inputs the total) | not taxed | no | **yes** |
| **Fût taproom** | **total LITRES** (summed) | **TAXED** | no → routed to a SEPARATE taproom-segregated stock | **no** |
| **Perte capuchon fût** | UNITS (no beer) | — | — | material loss |

Rules:
- **RENAME** the existing field labelled "Pertes rinçage fût" (`loss_keg_save_units`) → **"Perte capuchon fût"** (lost keg caps; material; unit-count; no beer). Column NAME can stay (`loss_keg_save_units`); only the FR label changes.
- **Keg rinsing/purging BEER loss is NOT a form input** — it is already computed via the **SET PROCESS LOSSES** in Zeppelin settings ("Données générales"). Do NOT add a keg-rinse beer-loss field.
- **Fût taproom**: TAXED, NOT a loss, destined for a **separate taproom-segregated stock that is NOT BUILT YET.** For now: capture the litres + mark taxed + EXCLUDE from general FG. The segregated taproom stock is a FUTURE module.

---

## DERIVED CONSEQUENCES (formulas to implement)

### 1. General FG (sellable) volume
**= vendable only.** Everything else excluded: invendable, all pertes (sans-capsule, half-filled, perte liquide fût), bibliothèque QA, mesures QA, fût taproom.

### 2. Beer-tax base
- **TAXED:** vendable + invendable (bottle/can) + fût taproom (keg).
- **UNTAXED:** perte-liquide-autre, sans-capsule, half-filled, perte liquide fût, bibliothèque QA, mesures QA.

### 3. Packaging-consumable consumption
Every category that physically consumes packaging consumes its BOM:
- **vendable** — full BOM.
- **invendable** — full BOM (container + closure + label).
- **perte-liquide-autre** — full BOM **INCLUDING the crown cork** (identical to invendable; only the tax flag differs).
- **sans-capsule** — full BOM **MINUS the crown cork**.
- **half-filled** — full BOM.
- **bibliothèque QA** — full BOM.
- **mesures QA** — full BOM.
- **capuchon fût** — the cap (the consumed material).
- **NOTE on kegs:** kegs are DURABLE/returnable containers, NOT a consumed packaging like a bottle. Only the **cap/collar consumables** count for kegs (`loss_keg_save`/capuchon + `loss_keg_collar`). The keg body itself is not a consumed-packaging BOM line. Confirm keg consumable flow against `ref_sku_bom` when wiring consumption.

### 4. Loss KPI
**= invendable + perte-liquide-autre + sans-capsule + half-filled** (bottle/can, by lost beer volume) **+ perte liquide fût** (keg litres) **+ material losses** (crown corks, can lids, capuchon fût, 4pack/wrap/label/collar/container scraps).
**EXCLUDES:** bibliothèque QA, mesures QA, fût taproom.

### 5. Vendable HL formula
Must subtract all non-vendable liquid at the correct fraction:
- invendable / perte-liquide-autre / sans-capsule / bibliothèque QA / mesures QA → **full** unit vol (`× hl_per_unit`).
- half-filled → **½** unit vol (`× 0.5 × hl_per_unit`).
- kegs (perte liquide fût + fût taproom) → **by litres** (`÷ 100` → HL).
```
vendable_units(bottle/can) = prod_total + special(echo-guarded)
                           − unsaleable − loss_untaxed_full − sans_capsule − qa_library − qa_analyses
                           − (half_filled × 0.5)            ← half unit, fractional
   (material scraps are NEVER subtracted from vendable — beer dispositions only)
vendable_hl = (vendable_units / units_per_pack) × hl_per_unit
            − perte_liquide_fut_litres/100                  ← keg loss (litres)
            − fut_taproom_litres/100                        ← taproom segregated (litres)
            − [legacy loss_liquid_other_units/100 if retained for sub-unit residual]
```
**vendable_hl = the packaged-HL operand → COGS/COP/WIP/volume_monthly. LOAD-BEARING.**

> **🔴 AS-BUILT CRITICAL FIX (A1, `424bcf5`) — record so it never resurfaces:** the PRE-EXISTING `compute_packaging_vendable_hl()` AND `v_bd_packaging_v2_vendable` view were SUBTRACTING the 11 material-scrap quantities (crown-cork/lid/4pack/wrap/label/collar/container scraps etc.) from `vendable_units`. Those are MATERIAL scraps — they do NOT decrement the count of saleable FILLED units, so subtracting them understated vendable HL by every scrap unit → understated FG, COGS, beer-tax base. **Latent but never bit anyone because the form had NEVER written a live row** (the `tank_co2`/`tank_o2` rollback blocker, §1). A1 removed all 11 subtractions from BOTH the function and the view. The only quantities that legitimately decrement vendable_units are the BEER-disposition categories (invendable, sans-capsule, half-filled×0.5, QA library/analyses) + keg litres — NOT material scraps. Do NOT re-add material-scrap subtractions to vendable.

---

## DATA MODEL — bd_packaging_v2 column map (LIVE verified 2026-05-31)

### REUSE existing columns (no change)
| Category | Column | Type | Note |
|---|---|---|---|
| Invendable | `unsaleable_units` | int unsigned | full filled unit + full BOM, TAXED |
| Bibliothèque QA | `qa_library_units` | int unsigned | neutral, not loss, not FG, not taxed |
| Mesures QA | `qa_analyses_units` | int unsigned | neutral, not loss, not FG, not taxed |
| Scrapped empty crown corks | `loss_crown_cork_units` | int unsigned | material loss, distinct from sans-capsule |
| Capuchon fût (lost keg caps) | `loss_keg_save_units` | int unsigned | **RELABEL only** "Pertes rinçage fût" → "Perte capuchon fût"; column name stays |

### NEW columns required (mig 231)
| Category | New column | Type | Family |
|---|---|---|---|
| Perte liquide – autre | `loss_untaxed_full_units` | int unsigned NULL | **bottle/can — full unit beer lost, FULL BOM incl. crown cork, UNTAXED, loss KPI (mig 233)** |
| Perte liquide – sans capsule | `loss_uncapped_units` | int unsigned NULL | bottle/can — full unit beer lost, BOM minus crown cork, untaxed |
| Perte liquide – half-filled | `loss_half_filled_units` | int unsigned NULL | bottle/can — ½ unit beer lost, full BOM, untaxed |
| Perte liquide (fût) | `loss_keg_liquid_l` | decimal(10,3) NULL | keg — total litres lost, untaxed, loss KPI |
| Fût taproom | `taproom_keg_l` | decimal(10,3) NULL | keg — total litres, TAXED, segregated FG (future), NOT loss |

Decision on `loss_liquid_other_units` (existing decimal(10,3), litres): **do NOT overload it** for the new keg channels. It is a generic sub-unit residual bucket (foam-out/filler residual). Keep it as-is; the keg perte liquide gets its OWN typed column (`loss_keg_liquid_l`) so the beer-tax + KPI logic can branch cleanly on the named column rather than guess intent from a catch-all. (If operator later confirms `loss_liquid_other_units` is dead, retire it — flag in scrapping-backlog; for now leave untouched.)

### Exact ALTER (mig 231 — `231_packaging_dispositions_columns.sql`)
```sql
ALTER TABLE bd_packaging_v2
  ADD COLUMN loss_uncapped_units    INT UNSIGNED NULL AFTER loss_crown_cork_units,
  ADD COLUMN loss_half_filled_units INT UNSIGNED NULL AFTER loss_uncapped_units,
  ADD COLUMN loss_keg_liquid_l      DECIMAL(10,3) NULL AFTER loss_keg_save_units,
  ADD COLUMN taproom_keg_l          DECIMAL(10,3) NULL AFTER loss_keg_liquid_l;
```
- MySQL 8: no `IF NOT EXISTS` on ADD COLUMN. No `ALGORITHM=INSTANT` clause needed (plain ADD COLUMN nullable is INSTANT by default in 8.0). FK types n/a (no FK added). No new table → no `schema_meta` row (existing `bd_packaging_v2` row stands; update its `writer_script`/notes if the convention is documented there).
- **The relabel (`loss_keg_save_units` → "Perte capuchon fût") is a UI-LABEL change in form-packaging.php / packaging-form.js — NO migration.**

---

## FORMAT-AWARENESS (the right UI model)
- The form already iterates `formats[N]` rows, one per packaged format, each with `run_type ∈ {bot,can,can33,keg,cuv}` (the discriminator — NOT fmt_suffix; ref_skus has no fmt_suffix, it has `format` varchar + `run_type` mirrors it on the form).
- **Per-format-row field switching is the correct model:** for `run_type ∈ {bot,can,can33}` render the UNIT-taxonomy fields (invendable, sans-capsule, half-filled, bibliothèque QA, mesures QA, crown-cork/lid scraps); for `run_type ∈ {keg,cuv}` render the LITRE fields (perte liquide fût litres, fût taproom litres, capuchon fût units) and HIDE the bottle/can-only fields (invendable blank-for-kegs, QA holds never apply to kegs).
- Each `formats[N]` row posts its own disposition fields → resolved against that row's `run_type` server-side. Vendable + beer-tax + KPI all computed per row, summed per session.

---

## REVISED BUILD-A SCOPE — 🟢 A1 + A2 DONE (commit `424bcf5`, migs 231+232, LIVE 2026-05-31)
All A1 + A2 scope items below SHIPPED. As-built notes inline.
1. **✅ WRITE-PATH FIX DONE.** Removed the dead `tank_co2`/`tank_o2` row keys from the `bd_upsert()` payload → the form writes live `web_entry` rows for the FIRST time. (`$measurements bbt_co2`/`bbt_o2` left as-is, drives `bd_qc_flag()` only, no bug — confirmed.)
2. **✅ MIG 231 APPLIED** = the 4 new dispo columns (`loss_uncapped_units`, `loss_half_filled_units`, `loss_keg_liquid_l`, `taproom_keg_l`) + 2 computed-store columns (`beer_tax_base_hl`, `loss_kpi_hl`). Verified live.
3. **✅ RELABEL DONE** "Pertes rinçage fût" → **"Perte capuchon fût"** (`loss_keg_save_units` column name unchanged, UI label only).
4. **✅ PER-RUN_TYPE FIELDS DONE** — bottle/can unit taxonomy vs keg/cuv litre fields per `formats[N]`, wired into `bd_upsert`.
5. **✅ VENDABLE / BEER-TAX / LOSS-KPI DERIVATION DONE** per §DERIVED CONSEQUENCES, implemented EXACTLY per spec: half-filled ×0.5; tax base = vendable+invendable+taproom; loss_kpi excludes QA holds & taproom; general FG = vendable only. **PLUS the critical scrap-subtraction removal from BOTH `compute_packaging_vendable_hl()` AND `v_bd_packaging_v2_vendable`** (see §5 AS-BUILT CRITICAL FIX — the view understated vendable/FG/COGS/tax by every material-scrap unit; latent because the form never wrote). `vendable_hl` operator-override input REMOVED (always computed — closes divergence flag #3).
6. **✅ REFUSE-DON'T-NULL DONE** — `units_per_pack≤0` OR `hl_per_unit NULL` → `qc_flag='sku_meta_missing'` (not a silent wrong compute).

**A2 (CO2/O2 capture) AS-BUILT (mig 232 APPLIED):** new table **`bd_packaging_co2o2_measures`** (BIGINT UNSIGNED FK→`bd_packaging_v2.id` ON DELETE CASCADE; uq `(packaging_id_fk, reading_index)`; CHECK not-both-null; ≤20 CO2/O2 pairs per session, anchored to the `row_origin='main'` row; delete-and-reinsert idempotency). `schema_meta` row inserted (verified: table_name/table_class/writer_script/corrections_policy/upstream_hint/notes). Dead cuve reads/UI removed; QC rewired per-pair via `bd_qc_flag` (import-then-flag, NOT dropped). **This is where the previously-dropped tank CO2/O2 reading now lands** — closes divergence flag #1. Same table will later feed the daily-shell single-pair-append (pilot work).
- Skills used: `ui` + `sql` + `coder`. RULE-2 passed. form-packaging.php contention resolved (A1+A2 landed together). **Atom C (white-label) still queues behind on this file — NOT built.**

---

## 🟢 BUILD A3 — "Perte liquide – autre" (6th bottle/can category) — DONE (commit `cef2a4a`, mig 233, LIVE 2026-05-31)
Operator added a 6th bottle/can disposition 2026-05-31: **"exact loss type like invendable but not taxed."** Profile = FULL unit beer lost + FULL packaging consumed INCL. crown cork (identical to invendable) + NOT taxed (not consumable) + NOT into FG + counts as loss. Bottle/can only (kegs use perte liquide fût). **SHIPPED as one small atom on form-packaging.php.**
- **✅ Column DONE:** `loss_untaxed_full_units` INT UNSIGNED NULL, bottle/can only. (NOT the legacy litres `loss_liquid_other_units`.)
- **✅ Mig 233 APPLIED** (`233_*`): `ADD COLUMN` + `CREATE OR REPLACE VIEW v_bd_packaging_v2_vendable` with the new term in the bottle/can branch only. Keg arms BYTE-IDENTICAL. The tax-base view arm SUBTRACTS the untaxed-full units (trap guarded with an inline WARNING comment — it must NOT land in beer_tax_base). MySQL 8, no IF NOT EXISTS, no ALGORITHM hint, no schema_meta (existing table).
- **✅ `compute_packaging_vendable_hl()` delta DONE** (bottle/can branch): decrements `vendable` + `loss_kpi` (full unit, no ×0.5), EXCLUDED from `beer_tax_base` — the key difference from invendable (which KEEPS the tax base). Compute fn + view updated in lockstep.
- **✅ Form field + POST plumbing DONE** — new bottle/can-only "Perte liquide autre" (units) field, plumbed into `$partialRow` before compute + the `bd_upsert` payload.
- **Regression-traced:** untaxed_full drops vendable_hl + loss_kpi_hl + beer_tax_base_hl; CONTRAST invendable which keeps tax_base_hl. Scope was exactly: column + view + compute + form field/POST. No new table, no schema_meta, no keg change, no CO2/O2 touch.

## DIVERGENCE FLAGS
1. ✅ RESOLVED (A1) `tank_co2`/`tank_o2` dead row keys → hard rollback of every live packaging write — keys dropped; CO2/O2 now lands in A2's `bd_packaging_co2o2_measures` child table.
2. Overloading `loss_liquid_other_units` for keg litres would hide intent → use the typed `loss_keg_liquid_l` instead (A1 added the typed column; honoured).
3. ✅ RESOLVED (A1) `vendable_hl` operator-override input = divergence into a derived fact → input REMOVED, always computed.
4. Fût taproom volume MUST be excluded from general FG even though it's taxed — it is destined for a future segregated taproom stock; do NOT let it leak into the sellable-FG operand. (A1 honours: taproom in tax base, NOT in general FG. Segregated stock still UNBUILT.)
5. Bibliothèque QA / Mesures QA must NOT count as loss (operator explicit) — neutral, excluded from KPI, FG, and tax. (A1 honours.)
6. Half-filled is a FIXED ½ unit, NOT a measured litres value (reversal from the old draft) — forcing it to measured litres OR keg-style litres for bottles/cans would corrupt the standing fraction. (A1 implemented ×0.5.)
7. **NEW (A1 lesson):** material-scrap quantities must NEVER be subtracted from vendable units — only beer-disposition categories + keg litres decrement saleable HL. The pre-existing view violated this latently (see §5 AS-BUILT CRITICAL FIX); A1 fixed both the function and the view. Do not re-introduce.
8. **🟢 RESOLVED (read side) 2026-06-01 — `is_tombstoned` SOFT-DELETE ENFORCEMENT GAP.** `is_tombstoned` IS the house soft-delete flag for the whole `bd_*_v2` event family — every vendable/tax/COGS/loss/WIP read MUST filter `is_tombstoned=0`. **FIX SHIPPED via mig 255 + 2 code commits (committed NOT pushed, applied + PM-verified live):** (a) **mig 255** = `CREATE OR REPLACE v_bd_packaging_v2_vendable` adding `WHERE p.is_tombstoned=0`, **all column expressions byte-identical** (registered as A-LT2-must-preserve — the codegen MUST keep this predicate when it resumes); (b) **`app/packaging-stats.php`** — all 16 read sites patched (commit `480c3dd`, maltyweb); (c) **`lib/beer-tax.js`** — both loaders patched at L408 + L470 (commit `213974e`, maltytask). **VERIFIED LIVE:** view now returns exactly the 2251 non-tombstoned rows, 0 leak (was 2 leaking via the unfiltered view); packaging-stats runs clean; `pkg_qa_metrics` now correctly surfaces 69 events / 211 readings (the mig-244 historical in-filling reads now visible). This also makes the re-editable submit's orphan-on-shrink tombstones actually DROP from vendable/tax/dashboards (the latent leak is closed before it ever bit). **STILL INHERITS-AT-CUTOVER (acceptable, not a leak today):** legacy `public/modules/packaging.php` is still v1 and inherits the requirement at v2-cutover; the **PAUSED A-LT2 `packaging-loss-types.php` view-codegen MUST emit `is_tombstoned=0` when it regenerates the view** — this is the one remaining open obligation in the arc. ✅-correct consumers from the original audit (`tank-simulator.php`, `loss-metrics.php`, `sb-board.php`, `parse-tank-simulation.ts`, `derive-packaging-consumption.ts`, `build-efficiency-data.ts`) already filtered. P3 = never-derive.

### 🟢 EPH MATERIALIZATION-DUP DEDUP — DONE 2026-06-01 (mig 249, commit `1633d23`, applied + verified)
`bd_packaging_v2` had 4 byte-identical row PAIRS — `normalize-rawdb.py` materialised one RawDB source row twice. KEPT originals **103/266/275/399**, hard-DELETED twins **4575/4738/4747/4871** (vendable_hl 12.41/10.01/9.715/9.555 each → removed **+41.69 HL double-count** from vendable/FG/beer-tax). **AS-SHIPPED = mig 249 (commit `1633d23`):** hard-DELETE of the 4 twins with `audit_row_revisions` tombstone records (`action='update'` + `after_json {"_tombstone":...}` — no 'delete' ENUM member). **Verified live: 0 remaining v2 source-row dups.** Ruling rationale (kept for the standing convention): pure materialization artifacts = no real event to soft-delete; hard-DELETE removes the double-count regardless of any consumer's filter — and was deliberately INDEPENDENT of the (then-buggy) is_tombstoned enforcement, landed first as the operator's gate. **House-convention split (durable):** `is_tombstoned=1` = operator retracted a REAL event (soft-delete); hard-DELETE + audit-tombstone revision = removing a materialization DUPLICATE of a fact that still exists once.

### 🟢 HISTORICAL BACKFILL (in-tank + in-filling dimensions) — DONE 2026-06-01 (migs 243+244, applied + verified)
The two reserved slots are now SPENT. **Mig 243 (commit `ebbf947`) = in-tank dimension backfill:** 1437 rows from v1, 1765 v2 rows linked by natural key, 471 residue cleaned. **Mig 244 (commit `27e2d81`) = in-filling per-row link:** 852 parents ↔ 852 v2 runs (perfect 1:1 bijection PROVEN — the frozen uniqueness proof was the gate, NO broken `sheet_row_index` bridge used), 4663 reads linked, 139 ambiguous REFUSED (not forced). Both committed, applied, verified live. This is what surfaced the mig-244 historical in-filling reads now visible in `pkg_qa_metrics` (69 events / 211 readings) once flag #8's view filter landed. **243/244 NO LONGER reserved — NEXT FREE MIG after this arc = verify `migrate.php --status` (255 applied out-of-band per the view-codegen quirk; re-check before assigning).**

### 🟢 CONTRACT-LANE BACKFILL — DONE 2026-06-01 (mig 257 `257_backfill_historical_intank_contract_lane.sql`, commit `9377ef2`, applied + PM-verified live)
**`bd_tank_readings` SCHEMA-OF-RECORD (exact identifiers, for any writer):** two parallel lanes in ONE table, each its own UNIQUE that IS the inheritance mechanism (ER_DUP_ENTRY → read-back) — NEB lane `uq_tank_read_neb (recipe_id_fk, neb_batch, read_date)`; CONTRACT lane `uq_tank_read_contract (contract_beer, contract_batch, read_date)`; unused lane = NULL. **NO `is_tombstoned` col on this table.** `read_date` = `bd_packaging_v2.event_date` (=DATE(v1.submitted_at)), never raw submitted_at.

The in-tank dimension is now COMPLETE ON BOTH LANES. **Mig 257 = contract-lane in-tank backfill:** 170 dimension rows (recipe_id_fk NULL, contract_beer/contract_batch/read_date populated) + 226 v2 contract rows linked by the STRING natural key `(contract_beer COLLATE utf8mb4_unicode_ci, contract_batch, event_date)`; **18 residue + 4 conflicting lot-days SKIPPED (refuse-don't-guess, surfaced not picked).** v1↔v2 contract_beer had ZERO divergence (raw string join SAFE, NO alias resolution — the mig-217 QDG/DIG consolidation touched a NEB recipe, not a contract_beer string). **RESIDUE TOTAL OPEN (deferred, semantic-NULL on v2 side, v1 untouched, data not lost): 471 neb (incl. 35 conflicting lot-days) + 18 contract (incl. 4 conflicting lot-days).**

### 🟢 QA IN-TANK CO₂/O₂ CONFORMANCE TRACKER MVP — SHIPPED + LIVE 2026-06-01 (T1 `03de3e1` / T2 `f852597` / T3 `6d5b6cd`)
NEW `conformite` section on `public/modules/salle-de-controle.php` (QA/QC family — PM's recommended home: in-tank = pre-package RELEASE-GATE conformance = QA's domain; in-filling fill-quality grid STAYS on the packaging dashboard; the tracker CROSS-REFS it). **Data layer = NEW `app/qa-tank-stats.php`** (verified live on VPS): `qa_tank_beer_list()` + `qa_tank_series_all()` + `_qa_in_spec()`; `beer_key` = `neb:<recipe_id>` / `con:<contract_beer>`; spec band from `ref_recipes.co2_target`/`co2_tolerance` on the NEB lane ONLY (contract = no recipe spec → "no spec set", refuse-don't-NULL). Modelled on `app/packaging-stats.php` (integer-FK joins preferred; the contract lane is a STRING join → `COLLATE utf8mb4_unicode_ci` mandatory). State: 1615 readings / 53 beers (12 neb + 41 contract) / 10 neb spec'd. T2 = the panel UI (nav-rail `conformite` section + `window.SDC_TANK_*` hydration + SVG variation charts reusing packaging.js technique + spec band + in/out-of-spec point colouring + raw-readings table); the 5 existing SDC sections untouched (only the `$sec` whitelist changed). T3 = CO₂ spec EDITABLE at Recettes → Levure & garde → "Seuils QC CO₂" (`update_recipe_qc` handler + both-or-neither half-spec guard + PRG-restore carrying `&recipe=&sub=`). **PHASE 2 = racking→in-tank→in-filling unified CO₂/O₂ JOURNEY = DEFERRED.** ⚠️ PROCESS NOTE: T2 build agent accidentally ran `bin/deploy.sh` mid-work → caught a stale-JS divergence (VPS JS ≠ local), re-synced; agents since instructed to NEVER run `bin/deploy.sh`.

## 🟡 DATA-DRIVEN LOSS TYPES + 4 OPERATOR PACKAGING-FORM CHANGES + LINER + CO₂/O₂ REMODEL
> Compiled out 2026-06-05 → [packaging-losses-convention/loss-types-and-form-changes-arc.md](packaging-losses-convention/loss-types-and-form-changes-arc.md). Holds: DATA-DRIVEN LOSS TYPES arc (A-LT1/mig 234 DONE, A-LT2 view-codegen PAUSED on `loss_liquid_other_units`); the 4 operator -V/-F form changes (P1+PKG-CLIENT mig237+P2 mig238 SHIPPED, P3/C1 derived-QA); LINER consumption deferred wiring (mig 239 identity captured); the IN-TANK/IN-FILLING CO₂/O₂ REMODEL arc-split + superseded lot-day-read lineage; OPEN carry-overs.

## §ATOM C WHITE-LABEL — arc history (✅ SHIPPED ADDITIVE `40b00be` 2026-06-05)
> Compiled out 2026-06-05 → [packaging-losses-convention/white-label-atom-c-arc.md](packaging-losses-convention/white-label-atom-c-arc.md). (d) ADDITIVE SHIPPED+DEPLOYED+PUSHED is the live truth; (b) carve-out facts + (c) ⛔ RETRACTED subtractive kept for trace. Read when touching white-label packaging (ref_white_label_clients, form-packaging.php / packaging-form.js/.css white-label path).

## §PERTES-DASHBOARD — CANONICAL SOURCE MAP (ruled 2026-06-01, PM-verified live; CORRECTS a first-draft fabrication)
**⚠️ RETRACTION: a first draft of this section listed a `v_packaging_loss_kpi*` view family as "already live." THOSE VIEWS DO NOT EXIST.** A-LT2 (view-codegen) is PAUSED; the only live packaging objects are the `ref_packaging_loss_types` catalog (A-LT1/mig 234, INERT) and the single view `v_bd_packaging_v2_vendable`. Verify-before-record failure on my part; this is the corrected map.

**THE LOSS NUMBER IS BIGGER THAN THE TYPED COLS SHOW — and most of it sits in a DROPPED legacy bucket.** Two facts that together force an INLINE computation:
1. `v_bd_packaging_v2_vendable.loss_kpi_hl` only sums the NEW typed disposition cols (sans_capsule/half_filled/untaxed_full/keg_liquid) → 0.000 HL for 2021/2022, 3-17 HL for 2023-26. Stored `bd_packaging_v2.loss_kpi_hl` is present-but-stale (3 nonzero rows). NEITHER captures historical loss.
2. The operator historically entered liquid loss into the LEGACY litres bucket **`loss_liquid_other_units`** — which A3's mig-233 view DROPPED from loss_kpi_hl. PM-verified live (reuse-filtered, three concordant runs): **0 / 0 / 104.07 / 227.84 / 156.23 / 94.13 HL for 2021-2026** (bucket is 0 before 2023, then dominant). 2021/2022 near-zero = data-entry gap, NOT a true 0 — flag honestly.

**CANONICAL beer-loss for the dashboard (compute INLINE, reuse-filtered):**
```
beer_loss_hl  = v.loss_kpi_hl + (b.loss_liquid_other_units / 100)
vendable_hl   = v.vendable_hl                         -- 0 NULL all years, contract fallback already in
beer_loss_pct = 100 * beer_loss_hl / vendable_hl
FROM bd_packaging_v2 b JOIN v_bd_packaging_v2_vendable v ON v.id = b.id
WHERE b.reuses_packaging_id_fk IS NULL                -- the view does NOT expose reuses_packaging_id_fk OR event_date; filter + group on the BASE table b
-- date axis: b.event_date (view has NO date col). group/filter year+month on b.event_date.
```
Verified beer-loss% by year (three concordant runs): 2021 ~0 / 2022 ~0 / 2023 1.15 / 2024 2.26 / 2025 1.39 / 2026 1.76(partial). 2021/2022 near-zero = data-entry gap (legacy bucket 0 before 2023) — flag honestly, not a true 0% loss. (NOT my retracted ~0.5%/~1.6% first numbers.) The view does NOT drift on 2026; the "11.0/244-contract-NULL" blocker is dead (`16079a2`).
**MANDATE: the `loss_liquid_other_units/100` term is NOT optional — omit it and the % is ~10× understated.** It is the manual stand-in for what A-LT2 must eventually fold into the view (G1 failed exactly on this column's catalog mapping). Log to scrapping-backlog as temporary inline-debt; remove once A-LT2 resumes + absorbs it.

**"Losses trend per format" (operator directive 3)** = the same inline beer_loss_hl/% grouped by `YEAR(event_date),MONTH(event_date),run_type` (bot/can/can33/keg/cuv), reuse-filtered. Consumable scrap is a SEPARATE panel.

**CONSUMABLE RATE (operator directive 2 = wasted÷consumed per consumable)** — NO canonical consumed-per-consumable source (no SKU_BOM/ref_packaging_format_mis per-session consumed count; only loss count + production count exist). Honest derived consumed = matching good units produced + wasted, valid ONLY for 1:1 consumables: loss_label_btl_units, loss_crown_cork_units (≈ bottle prod + waste), loss_can_lid_units (≈ can prod + waste), loss_container_btl/can_units (≈ matching prod + waste) — all well-populated (300-420 nonzero rows). **NOT honest for loss_4pack_*_units (1 four-pack covers 4) NOR loss_wrap_*_units (variable multiple) → RAW counts + "rate pending pack-size wiring" flag.** loss_keg_collar_units + loss_keg_save_units = all-zero (12 rows, never used) → omit/show 0. Never fabricate a denominator.

**QA SECTION (operator directive 3 = QA reads + trends, drop invendables count)** — qa_analyses_units + qa_library_units monthly = real plottable trend (well-pop: ~800-1200 + ~560-900/yr 2021→26). O2/CO2 = `bd_packaging_co2o2_measures` ONLY (11 pairs / 3 events / 2026; **there are NO legacy o2_ppb/co2_gl scalar cols on the base table** — prior-memory "prefer over scalars" is moot) → points + n= + "coverage starting" note, NO trendline. Once mig-240 lot-day-read arc lands, O2/CO2 reads filter `tank_reading_session_id_fk IS NULL` (anchor only) to avoid inheritor double-count.

**KPI 2 (operator directive 1)** = "HL Nébuleuse par SKU" → horizontal rows one-per-SKU, months along the bar. Pure viz; data unchanged (per exact SKU code; Neb = ref_recipes.classification='Neb' via recipe_id_fk, 100% covered — NOT sku_id_fk).
