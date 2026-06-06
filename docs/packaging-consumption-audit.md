# Packaging Bill-of-Materials & Consumption-Derivation — Computational-Correctness Audit

**Auditor:** Group Production Director / VP Brewing Operations (independent review)
**Scope:** `scripts/derive-packaging-consumption.ts` (maltytask) — sole canonical writer of `inv_consumption(source_event='packaging')` — post the 2026-06-06 "~24× inflation" fix.
**Method:** full code read + live MySQL interrogation (`ref_skus`, `ref_sku_bom`, `bd_packaging_v2`, `inv_consumption`, `inv_deliveries`, `ref_mi`). Read-only; no code or data changed.
**Date:** 2026-06-06.

---

## Verdict (TL;DR)

**The core post-fix computation is sound and trustworthy for COGS.** The ~24× inflation is gone; the arithmetic is dimensionally correct, idempotent, and reconciles against deliveries to the right order of magnitude. Caps ≈ bottles ≈ labels hold to <0.2%, and 4-pack ≈ bottles/4 holds exactly.

**No ❌ in the derivation engine itself.** All ❌/⚠️ findings are in the **reference data it reads** (BOM rows, `ref_mi.price`) and in **out-of-scope coverage** (contract runs) — i.e. the script faithfully computes from inputs that have gaps. Those gaps understate COGS/RM and must be closed at the master-data root, not in the script.

Ranked by COGS impact:
1. **⚠️ 681,099 units of computed consumption valuate to CHF 0** — 43 packaging MIs (incl. all stickers, several labels, both can bodies) have NULL/zero `ref_mi.price`. The units are recorded correctly; the valuation layer drops them. **Largest live COGS understatement.**
2. **⚠️ Contract / white-label gap — 975,327 bottles+cans + 9,078 kegs of real material consumption never recorded** (244 runs skipped, Phase 2 not built). RM-stock + per-client COGS gap.
3. **❌ `DIV33C` (33 cl can) BOM points at the 50 cl can body `PKG_CAN_ALU_50`** — wrong material; the correct `PKG_CAN_ALU_33` exists and is unused. 14,158 units mis-mapped.
4. **❌ `BLO4` BOM is incomplete** — a real (now-inactive) Nébuleuse SKU with 171,468 bottles carries only caps+bottles: no label, no 4-pack carrier, no box. Historical understatement.
5. **⚠️ ~530 loss units silently dropped** (50 loss cells with no BOM match) — minor COGS, but a fragility signal.

---

## 1. `units_per_pack` correctness — ✅ correct

`ref_skus.units_per_pack` is populated for **100% of 169 SKUs — zero NULL, zero ≤0**.

| units_per_pack | SKU count | meaning |
|---|---|---|
| 1 | 88 | BU / keg / cuv / can singles |
| 4 | 13 | 4-pack |
| 12 | 18 | 12-pack |
| 24 | 42 | 6×4 carton / 24-box |
| 1027 | 8 | `-X` bulk SKUs (ZEP-X … ALT-X) |

**Self-consistency with BOM verified.** For per-container materials the BOM `qty_per_unit` equals `units_per_pack` exactly (DIVB caps=24 & upp=24; DIVBU caps=1 & upp=1; DIV4PB caps=4 & upp=4; ZEP12C can=12 & upp=12; DIV-X caps=1027 & upp=1027). So `sellable_units × BOM_qty_per_unit = (units/upp) × upp = units` — the model is algebraically exact for per-container items. ✅

The `1027` value (`-X` bulk SKUs) is **internally consistent** (BOM qty_per_unit is also 1027), so even if such a SKU were ever packaged the arithmetic collapses to `units`. No live `bd_packaging_v2` rows use the `-X` SKUs, so it is presently inert. ⚠️-low: 1027 is an odd magic number to carry in master data; document its provenance.

**FAIL-LOUD guard (lines 197–202):** correct. A missing `units_per_pack` skips the event with a LOUD `console.error` and a counted `skippedNoUnitsPerPack` — no silent default-to-1. This is exactly the guard whose absence caused the original bug.

---

## 2. BOM completeness / correctness — ⚠️ / ❌ (data gaps, not engine)

Representative SKUs traced (`source='Packaging'`):

| SKU | upp | BOM materials (qty_per_unit) | verdict |
|---|---|---|---|
| DIVB (24-box btl) | 24 | crown 24, pivo 24, box_24 1, label 24, scotch 0.0009, sticker 24 | ✅ complete |
| DIVBU (single btl) | 1 | crown 1, pivo 1, label 1 | ✅ |
| DIV4PB (4-pack btl) | 4 | 4pack-carrier 1, crown 4, pivo 4, label 4 | ✅ |
| ZEP12C (12-can) | 12 | box_12 1, lids 12, can_zep 12 | ✅ |
| ZEPC (24-can) | 24 | box_24 1, lids 24, can_zep 24, scotch 0.0009, sticker 24 | ✅ |
| DIVF (keg) | 1 | keg_collar 1, keg_safe 1 | ✅ (no liquid-pack material — correct) |
| ZEPBU (single btl) | 1 | crown 1, pivo 1, label 1 | ✅ |

**❌ `DIV33C` (Can33 / 33 cl) → `PKG_CAN_ALU_50`.** A 33 cl can run is mapped to the **50 cl** can body. `PKG_CAN_ALU_33` exists in `ref_mi` but is unused. 14,158 units (2 runs) mis-mapped. *Currently masked because `PKG_CAN_ALU_50` has no price (see §8) so it contributes CHF 0 either way — but the day a 50 cl price is entered, 33 cl runs will be costed at the wrong unit price.* **Fix the BOM mapping.**

**❌ `BLO4` ("Blonde des Romands", recipe_id=10, `is_active=0`) BOM = caps + bottles only.** No label, no 4-pack carrier, no box, no wrap — yet it's a 6×4 carton SKU (upp=24) with **29 runs / 171,468 bottles** historically. At ~CHF 0.024/label + ~0.26/4-pack that is ≈ CHF 4,100 labels + ~1,850 carriers + boxes understated over the SKU's life. Inactive ⇒ **historical-only** COGS impact, but it pollutes back-period RM/COGS and is the *only* bottle SKU in the dataset missing a label.

No double-counting of materials observed. Stickers vs labels are **not** conflated — bottle SKUs carry `PKG_LABEL_*`; can boxes carry `PKG_STICKER_*` + box; both legitimately present where expected.

---

## 3. The arithmetic — ✅ correct (hand-traced)

**Trace A — DIVB id=2202 (24-box bottle), prod=13843, upp=24 → sellable=576.7917 boxes.** `inv_consumption` written:

| material | written | check |
|---|---|---|
| crown caps | 13843 (+100 loss) | 576.7917×24 = 13843 ✅ |
| pivo bottle | 13843 (+65 loss) | ✅ |
| box_24 | 576.7917 | 576.7917×1 ✅ (partial box — fractional, legitimate) |
| label | 13843 (+120 loss) | ✅ |
| scotch | 0.5191 | 576.7917×0.0009 ✅ |
| sticker | 13843 | ✅ |

**Trace B — ALT4 id=2065 (6×4 carton), prod=8574, upp=24 → sellable=357.25 boxes.** crown=8574, pivo=8574, label=8574 (all = units ✅); 6×4 wrap=357.25 (=boxes ✅); 4-pack=357.25×6=**2143.5** = 8574/4 ✅. Dimensionally exact.

**Per-beer aggregate (Diversion, all-time):** pivo 855,172 ≈ crown 856,714 ≈ label 859,011 (spread <0.5%, explained by per-beer loss cells); 4pack 166,921.25 ≈ bottles/4. ✅ The invariants caps ≈ bottles ≈ labels and 4pack ≈ bottles/4 hold.

No off-by-factor error across bottle / can / keg / single / 4-pack / 12-pack / 24-box. ✅

---

## 4. Fractional 4-pack quantities — ✅ legitimate artifact, NOT an error

The non-integer 4-pack totals (e.g. 218,834.8) are a **correct loss-/partial-pack allocation artifact**, fully explained:

- Operators report **`prod_total_units` = individual bottles filled**, which is frequently **not a multiple of the pack size** (8574 is not divisible by 24 or by 4).
- `sellable_units = units / upp` is therefore fractional (8574/24 = 357.25 boxes), and any per-pack BOM material inherits the fraction: 4-pack = 357.25 × 6 = 2143.5 = exactly 8574/4.
- Mathematically, 4-pack count = bottles ÷ 4; when bottles are odd-mod-4 you get a .25/.5/.75 tail. This is physically meaningful (a partially-filled final pack) and rounding it would *break* the bottles↔packs identity.

`round4()` caps accumulation error at 1e-4 per row — negligible. **No computational error.** ✅ Recommend documenting it so it isn't "fixed" by a well-meaning future edit; if integer pack counts are ever required for procurement, `CEIL` at the reporting layer, never in the derivation.

---

## 5. Loss handling — ⚠️ two distinct issues

**5a. Filled-then-discarded units (unsaleable / QA / half-filled / untaxed-full) are NOT dropped — ✅.** `prod_total_units` ("Production Totale", source col 48 in `normalize-rawdb.py`) is the **gross** count of bottles physically filled. Verified arithmetically: id=1391 prod=13056 (>) vendable-equiv 12269 + unsaleable 214 + QA 18 — i.e. the gross count already *includes* the 12,114 unsaleable + 5,264 QA-analysis + 4,302 QA-library + 335 untaxed-full + 13 half-filled units across the dataset. Their bottle+cap+label material is therefore captured by the BOM expansion. **Correct — no action.** (These columns intentionally have no LOSS_MAP entry.)

**5b. ⚠️ ~530 units of genuine pre-fill material loss are silently dropped** — the "50 loss cells with no BOM match". `LOSS_MAPS` attributes a loss only if the SKU's BOM carries the matching material. Where it doesn't, the loss is counted as `unmatchedLoss` and discarded:

- `loss_4pack_btl` on **DIVB** (a 24-box SKU with no 4-pack carrier in BOM) → operator logged 4-pack-carrier breakage against a plain-box run; 158 carriers dropped.
- `loss_4pack_btl` on **BLO4** (BOM missing the carrier entirely, §2) → dropped.
- `loss_wrap_btl` 75 units / 24 cells; `loss_label_btl` 295 units / 8 cells — all on SKUs whose BOM lacks the material.

COGS impact is small (~CHF 100–200, mostly cheap carriers/wraps). The real value is as a **data-quality signal**: an unmatched loss means either a wrong SKU on the run or an incomplete BOM (§2, BLO4). Recommend the script emit a ReviewQueue row (or at least log the SKU+col) instead of a silent counter.

---

## 6. Parallel -4/-B runs — ✅ correct, no double-count

`row_origin='parallel'` rows use `special_qty_units` (line 189); `main` rows use `prod_total_units`. Verified:

- All 68 parallel rows have `special_qty_units = prod_total_units` (so the source choice is moot in current data, but the logic is right).
- A real pair — Alternative batch 7, 2026-06-01: **main id=6732 (sku 1=ALT4, prod=4032)** + **parallel id=6733 (sku 2=ALTB, special=1746)** → counted additively against **different SKUs** (4032 + 1746 = 5778 distinct bottles). Matches the documented "prod_total = main only, qte_unites = parallel only, ADD not subtract" rule. ✅
- No shared `source_sheet_row_index` linkage between main and parallel, so there is no path by which the same physical bottles are counted twice. ✅

---

## 7. Coverage gap — contract / white-label — ⚠️ real RM & per-client COGS gap

244 runs skipped (`sku_id_fk IS NULL`, line 183). These are **not** liquid-only — they physically draw Nébuleuse stock:

| run_type | runs | units |
|---|---|---|
| bot | 123 | 877,779 |
| can33 | 20 | 97,548 |
| keg | 101 | 9,078 |

⇒ **975,327 bottles/cans** consume caps + labels + glass/alu, plus **9,078 keg collars/clips**, with **zero `inv_consumption` rows written**. At a blended ~CHF 0.20/filled unit (glass 0.168 + cap 0.0075 + label 0.024) the un-recorded material is **on the order of CHF 195,000 of consumption** over the dataset window.

Whether this is a *COGS* understatement or merely an *RM-depletion* gap depends on the per-client material-responsibility model (CLAUDE.md: procure-and-recharge vs client-supplies-own). Either way RM stock is being drawn down without a recorded flow, so `RM_Stock_Dynamic` will over-state on-hand for these materials. **This is the single largest *coverage* gap; close it via the Phase-2 `ref_clients` material-responsibility model the script's header already anticipates.** The 0 white-label flag on all 244 rows suggests these are contract (not WL) — worth confirming with the operator before assuming any are zero-cost to Nébuleuse.

The separately-skipped **254 cuv/serving-tank runs (162,899 L)** are **correctly** excluded — serving tanks consume no packaging. One ⚠️: **`ZEP6C` (discontinued 6-pack can, 42,912 units) has no packaging BOM** → its cans/lids consumption is dropped (historical only, SKU inactive).

---

## 8. Reconciliation vs deliveries — ✅ order-of-magnitude sane; ⚠️ valuation gap

**Crown caps** consumption is physically plausible and stable year-over-year:

| year | caps consumed |
|---|---|
| 2021 | 976,618 |
| 2022 | 968,322 |
| 2023 | 1,043,299 |
| 2024 | 1,009,317 |
| 2025 | 1,224,079 |
| 2026 (part) | 607,858 |

A single 2026-04-09 delivery of **1,157,000** caps ≈ one year's run-rate — a plausible bulk annual buy. **Caps (5,829,493) ≈ bottles (5,820,447)** all-time, gap ~9k = loss cells + rounding. ✅ No physically implausible magnitude. (Bottle *deliveries* are only 709,646 because deliveries-as-inventory began May 2026 — not comparable to all-time consumption; that's expected, not a defect.)

**⚠️ Valuation gap (largest live COGS impact):** **681,099 units of correctly-computed packaging consumption across 19 MIs valuate to CHF 0** because `ref_mi.price` is NULL/zero on 43 packaging MIs — including:

| MI | unpriced units (all-time) |
|---|---|
| PKG_CAN_ALU_50 | 206,369 |
| PKG_STICKER_DIV | 188,343 |
| PKG_STICKER_MOO | 96,499 |
| PKG_INTERCAL_NOMOQ | 33,697 |
| PKG_4PACK_CAN_ZEP | 25,253 |
| PKG_LABEL_BLA | 20,725 |
| PKG_STICKER_EPH1–4 | ~64,000 |
| PKG_LABEL_EPH2 / DIP | ~18,000 |

The derivation is **correct in units**; the COGS understatement is entirely at the price layer. Stickers (used at bottle-magnitude on every 24-box run) and both can bodies being unpriced is the biggest single euro leak. **Populate `ref_mi.price` for these before trusting packaging COGS in absolute terms.**

---

## Idempotency / engine hygiene — ✅ with one latent ⚠️

- `--dry-run` is the default; `--apply` required for the DELETE+INSERT rebuild. ✅
- Live state: 8,565 rows, **8,565 distinct `row_hash`** — clean. `uk_row_hash` UNIQUE + `uk_dedup(mi_id_fk,consumed_at,source_event,source_row_id,qty)` UNIQUE both present. ✅
- BOM `row_hash = sha256(packaging|{event}|{mi}|bom)`; loss `…|loss|{col}` — distinct namespaces, no collision in live data. ✅
- **⚠️ latent (not live-reachable):** `uk_dedup` includes `qty`, so a BOM row and a loss row for the *same* (event, mi) with an *identical qty* would silently lose one to `INSERT IGNORE` even though their `row_hash` differs. Reachable only for a single-unit (upp=1) bottle run where `bom_qty (=1) == loss_qty (=1)`. Zero such runs exist today (no BU bot runs), but the invariant is fragile — prefer making `row_hash` the sole dedup key, or fold the loss-column discriminator into `qty`-independent identity.
- ⚠️-low: `consumed_at = DATE(submitted_at)`; for 27 rows `DATE(submitted_at) ≠ event_date`. Immaterial for monthly COGS but, per the bd_* family's canonical rule ("event_date = DATE(submitted_at)"), consider keying on `event_date` for consistency with the rest of the pipeline.

---

## Final verdict

**The post-fix packaging-consumption *computation* is trustworthy for COGS.** The 24× inflation is genuinely fixed, the `units_per_pack`-based denominator is correct and self-consistent with the BOM, the arithmetic hand-traces cleanly across every SKU type, the fractional 4-packs are legitimate (not a bug), filled-but-discarded units are *not* dropped, parallel runs don't double-count, and material totals reconcile to the right order of magnitude against deliveries.

**Before the absolute COGS numbers can be relied on, three master-data fixes are required (none in this script):**

1. **Populate `ref_mi.price`** for the 43 unpriced packaging MIs (681k units at CHF 0 — biggest leak; stickers + can bodies first). — *valuation*
2. **Build the Phase-2 contract material-responsibility model** so the 244 skipped runs (975k units) record consumption / RM depletion. — *coverage*
3. **Correct two BOM rows:** `DIV33C → PKG_CAN_ALU_33` (not _50); add label/carrier/box to `BLO4`. — *correctness*

Plus two low-priority hardening items: surface the ~530 dropped loss units (RQ instead of silent counter), and tighten the `uk_dedup`/`row_hash` interaction against the latent single-unit collision.

The engine is sound. The remaining risk lives in the reference data it consumes.
