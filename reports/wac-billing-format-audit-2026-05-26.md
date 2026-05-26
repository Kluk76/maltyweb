# WAC Billing-Format Audit — Strict Verification + Canonical Unit-Normalization Model

**Date:** 2026-05-26
**Scope:** Independent re-verification of the operator's billing-format audit of `inv_deliveries`, resolution of the 3 ambiguous rows from source invoices, design of the canonical billing-format → canonical-unit normalization model, and the remediation + `v_mi_wac` plan.
**DB:** `maltytask` on VPS (`ssh maltyweb`), READ-ONLY this pass. No data, schema, or files modified.
**Auditor:** maltyweb-pm (quality gate + architect). Builds on the prior `wac-unit-truth-dba-2026-05-26.md` (DBA) and `unit-fidelity-audit-2026-05-26.md` (code).

---

## 0. Executive Summary

The operator's audit method is **sound** and the two-pass design is necessary: an invariant scan (`qty×price ≠ total`) catches gross mis-scales; a separate eff-price-vs-catalog scan catches *internally-consistent* rows that are nonetheless in a non-canonical unit (the invariant cannot see these). I re-ran both independently against live data and extended them DB-wide.

**Verdict on the 7 "confirmed":** 6 confirmed, **1 disputed** (ABBAYE — the proposed fix is wrong; the row is already WAC-correct).

| MI | row id | operator verdict | PM verdict | corrected canonical |
|---|---|---|---|---|
| MALT_MUNICH | 21 | price 509→0.509 | **CONFIRMED** | qty 26000 kg, price **0.509** CHF-eq/kg |
| YEAST_US05 | 426 | price 109→0.218 | **CONFIRMED** | qty 500 g, price **0.218** EUR/g |
| YEAST_W3470 | 425 | price 179.4→0.3588 | **CONFIRMED** | qty 500 g, price **0.3588** EUR/g |
| HOPS_GALAXY | 312 | qty 1→5, price→35.9 | **CONFIRMED** | qty **5** kg, price **35.9** EUR/kg |
| HOPS_SIMCOE | 105 | qty 1→5, price→14 | **CONFIRMED** | qty **5** kg, price **14** EUR/kg |
| QA_WATER_DEMIN | 12 | qty 4→20, price→0.6246 | **CONFIRMED** | qty **20** L, price **0.6246** CHF/L |
| YEAST_LALBREW_ABBAYE | 74, 418 | qty 1→0.5, price 178→356 | **DISPUTED — NO FIX** | already correct: qty 1.0 kg, price 178 CHF/kg |

**Verdict on the 3 "ambiguous":** 2 RESOLVED from source invoices (no operator decision needed), 1 remains operator-only.

| MI | row id | resolution |
|---|---|---|
| HOPS_SIMCOE | 294 | **RESOLVED — NOT an error.** Hoppy People sells Simcoe at 28.32 EUR/kg (qty already in kg). Legitimate price difference vs Bière-Appro's 14/kg. WAC blends both correctly. |
| PROC_DEHAZE | 230, 417 | **RESOLVED — NOT an error.** Source line "DeHAze 5kg" @ 995 = one 5 kg sachet; parser already normalized to qty=5 kg @ 199 CHF/kg. Correct. |
| HOPS_MOSAIC | 104 | **OPERATOR-ONLY.** Source "Houblon Mosaic, Yakima Chief; Sachet" 2 pcs @ 145 EUR. Specialty Yakima Chief product (Cryo/Incognito/Prysma-class), NOT the commodity T90 Mosaic of the sibling rows (11.5–12/kg). Need the product form + pack size from the operator to assign the canonical-kg basis. |

**Canonical-model decision:** `ref_mi_invoicing_units` is confirmed as the **single source of truth** for the billing-format → canonical-unit map, but its current convention is **drifted and incomplete** (17 rows; two contradictory conventions for the same physical 500 g brick). The fix is to adopt ONE rule (below) and complete the table, then make ingest normalize against it so `total/qty` is always per canonical unit.

**Remediation buckets:** (1) 3 unambiguous gross-misscale data fixes — but **the v_mi_wac formula choice can make 2 of the 3 moot** (see §6); (2) 3 unambiguous silent-unit fixes (GALAXY, SIMCOE-105, WATER_DEMIN); (3) `ref_mi_invoicing_units` de-drift + completion; (4) catalog-price hygiene (separate, non-WAC-blocking); (5) operator-gated (MOSAIC-104 product ID; the yeast qty_remaining=1→500 stock question).

**Residual operator questions:** 3 (MOSAIC-104 product; US05/W3470 `qty_remaining` 1→500 stock-value change; whether to also fix the stale catalog prices now or as a separate pass).

---

## 1. Method Verification

The operator's two-pass method is correct and I adopt it. Critically, the two passes are **not redundant** — they catch disjoint error classes:

- **Pass 1 (invariant `qty_delivered × unit_price ≠ total_original`):** catches *gross mis-scales* where qty and price are in different units. A DB-wide sweep (Active, `is_inventoried=1`, `exclusion_class IS NULL`, `qty_remaining>0`) returns **exactly 3 rows**: MALT_MUNICH (id=21, off ×1000), YEAST_W3470 (id=425, off ×500), YEAST_US05 (id=426, off ×500). No others DB-wide.
- **Pass 2 (eff_price = total/qty vs `ref_mi.price` catalog, clean-factor + sibling cross-check):** catches *internally-consistent* rows in a non-canonical unit — the invariant holds for these so Pass 1 is blind to them. GALAXY (×5), SIMCOE-105 (×5 vs sibling), WATER_DEMIN (×5).

**Extension I added:** a low-qty (`qty_delivered ≤ 2.5`) scan across all mass categories to hunt for *other* pack-size-as-qty=1 errors. It surfaced only the already-known suspects plus correctly-stored low-qty specialty hops (HOPS_KRUSH_CRYO 2 kg, HOPS_PRYSMA_MOSAIC 2 kg — ratio 1.00, real kg) — confirming GALAXY and SIMCOE-105 are the **only** clean-factor pack-size errors in the mass categories.

**Gap I closed in the catalog cross-check:** the `ref_mi.price` catalog is itself unreliable (stale on several MIs by ×10–×1400). So the clean-factor test must be confirmed against a **sibling delivery row** (same MI, known-good per-kg) or the **source invoice line**, never against catalog alone. I did this for every confirmed fix.

---

## 2. Strict Verification of the 7 "Confirmed"

All figures pulled live 2026-05-26 from `inv_deliveries JOIN ref_mi`.

### 2.1 MALT_MUNICH (id=21) — CONFIRMED, price 509 → 0.509
- Stored: qty=26000 (kg), unit_price=509, total_original=13234 EUR, eur_to_chf=0.945, catalog=0.509.
- Invariant: 26000 × 509 = 13,234,000 ≠ 13,234 → **off ×1000** (per-ton price against a per-kg qty).
- eff_price = 13234/26000 = **0.509** = catalog exactly.
- **Fix: `unit_price` 509 → 0.509.** qty stays 26000 kg. total_original (13234) and total_chf already correct.
- Note: `qty_remaining=26` (kg). Munich was largely consumed; the FIFO depletion appears to have used the correct `total_chf` (since it depletes by quantity, not by the wrong unit_price), so consumption cost is likely fine — but the WAC RATE is 1000× wrong until fixed.

### 2.2 YEAST_US05 (id=426) — CONFIRMED, price 109 → 0.218
- Stored: pricing_unit=g, qty=500 (g), unit_price=109 (per-500g-pack), total_original=109 EUR.
- Invariant: 500 × 109 = 54,500 ≠ 109 → **off ×500**. Root cause: a 2026-05-22 correction changed qty 1→500 (g) but did not divide unit_price by 500.
- eff_price = 109/500 = **0.218** EUR/g.
- **Fix: `unit_price` 109 → 0.218.**

### 2.3 YEAST_W3470 (id=425) — CONFIRMED, price 179.4 → 0.3588
- Same half-fix as US05. Invariant: 500 × 179.4 = 89,700 ≠ 179.4 → **off ×500**.
- eff_price = 179.4/500 = **0.3588** EUR/g.
- **Fix: `unit_price` 179.4 → 0.3588.**

### 2.4 HOPS_GALAXY (id=312) — CONFIRMED, qty 1 → 5, price → 35.9
- Stored: qty=1, unit_price=179.5, total_original=179.5 EUR, catalog=35.9. Invariant HOLDS (1×179.5=179.5) — Pass 1 blind.
- eff_price = 179.5/1 = 179.5; ratio to catalog = **exactly ×5** → a 5 kg pack stored as qty=1.
- **Fix: qty_delivered 1 → 5 (kg), unit_price 179.5 → 35.9.** total (179.5) unchanged. **`qty_remaining` is 1.0 (= un-depleted) → set to 5.0 too.**

### 2.5 HOPS_SIMCOE (id=105) — CONFIRMED, qty 1 → 5, price → 14
- Stored: qty=1, unit_price=70, total=70 EUR. Source invoice (Bière Appro F8389, line 1): `Houblon Simcoe 2021 RÉSERVÉ Nebuleuse` 1 pcs @ 70. The Bière-Appro Simcoe pack is 5 kg (`ref_mi_invoicing_units` id=10, supplier 19).
- 70/5 = **14** EUR/kg = sibling id=130's per-kg exactly. (Catalog 16.13 is the contaminated blend — do NOT use it as the truth; the sibling is.)
- **Fix: qty_delivered 1 → 5 (kg), unit_price 70 → 14.** total unchanged. **`qty_remaining` 1.0 → 5.0.**
- **Live blast radius:** the `wac_snapshots` 2026-04 row for HOPS_SIMCOE shows `wac_chf=25.2588` — this blend is contaminated by the id=105 error (it over-weights the 70 figure). Must be recomputed after the data fix.

### 2.6 QA_WATER_DEMIN (id=12) — CONFIRMED, qty 4 → 20, price → 0.6246
- Stored: pricing_unit=L, qty=4, unit_price=3.123, total=12.492 CHF, catalog=0.6244. Invariant HOLDS.
- 4 × 5 L bottles = 20 L; 3.123/5 = **0.6246** CHF/L = catalog. So qty is in *bottles* not *litres*.
- **Fix: qty_delivered 4 → 20 (L), unit_price 3.123 → 0.6246.** total unchanged. **`qty_remaining` 4.0 → 20.0.**
- The `wac_snapshots` 2026-04 row carries `wac_chf=3.123` (per-bottle as per-L) — recompute after fix.

### 2.7 YEAST_LALBREW_ABBAYE (id=74, 418) — DISPUTED, NO FIX
**The operator's proposed fix (qty 1→0.5, price 178→356) is incorrect.** Source invoices resolve it definitively:
- Good Beer Solutions inv 208 (id=74) and inv 160 (id=418): source line `Lallemand Lalbrew Abbaye` **2 pcs @ 89.00 = 178.00 CHF**.
- Lallemand Lalbrew Abbaye ships as a **500 g brick**. 2 bricks = 2 × 0.5 kg = **1.0 kg total**, for 178 CHF → **178 CHF/kg** (= 89 CHF per 500 g brick).
- The parser collapsed "2 pcs" into the stored `qty_delivered=1, unit_price=178, total=178`. The stored `qty=1` **already equals the correct 1.0 kg** (2 bricks), and `unit_price=178` **already equals the correct CHF/kg**. eff_price 178 = catalog 178, ratio 1.00.
- Cross-check vs the correctly-stored sibling YEAST_SAFALE_BE134 (id=313): 1 brick = 0.5 kg @ 125.4/kg = 62.70. Abbaye = 2 bricks = 1.0 kg @ 178/kg = 178. **Consistent.**
- The operator's confusion came from `ref_mi_invoicing_units` id=6 which (correctly) says one Abbaye *brick* is 0.5 kg — but the *delivery* was 2 bricks, so it nets to 1.0 kg. The 1.0 kg is real, not "1 pack".
- **Conclusion: id=74 and id=418 are WAC-correct as stored. Do NOT change them.** (Applying the operator's fix would *introduce* a ×2 error.)

This is exactly the value of the strict bar: the proposed fix would have corrupted a correct row.

---

## 3. Resolution of the 3 "Ambiguous"

All three resolved from `doc_invoice_lines` (the parsed source-invoice line text), confirmed against `ref_mi_invoicing_units` and sibling rows.

### 3.1 HOPS_SIMCOE id=294 — RESOLVED: not an error
- Source: Hoppy People inv 260276, line 0: `Houblons Pellet T90 4x5K/11L Simcoe` **200 kg @ 28.32 = 5664 EUR**.
- qty is already in **kg** (200), priced per kg (28.32). Internally consistent (200×28.32=5664). The "4x5K" is the supplier's pack description; the parser correctly emitted total kg.
- 28.32/kg vs Bière-Appro's 14/kg = a legitimate **supplier price difference** (different supplier, likely different vintage/form). WAC blending 14 and 28.32 across suppliers is *correct* WAC behaviour.
- **No fix.** Confirms the price-increase hypothesis, not an error.

### 3.2 PROC_DEHAZE id=230, 417 — RESOLVED: not an error
- Source: Good Beer Solutions inv 226 (id=230) and inv 233 (id=417), line `DeHAze 5kg` **1 pcs @ 995 = 995 CHF**.
- The product is a single 5 kg sachet. The delivery row stores qty=5 (kg), unit_price=199 (CHF/kg), total=995 — i.e. the parser **already normalized** 1 pack → 5 kg and 995/pack → 199/kg. `ref_mi_invoicing_units` id=2 confirms DEHAZE pack_size=5 kg (supplier 91).
- 995/5 = 199 CHF/kg = catalog. Internally consistent.
- **No fix.** Resolves the "5kg sachet at 199/kg" branch of the operator's question; it is NOT "5 sachets at 199/sachet".

### 3.3 HOPS_MOSAIC id=104 — OPERATOR-ONLY (escalate)
- Source: Bière Appro inv F8389, line 0: `Houblon Mosaic, Yakima Chief Hops; Houblon - Sachet` **2 pcs @ 145 = 290 EUR**.
- The sibling MOSAIC rows (ids 161/163/363) are commodity T90 pellets at 11.5–12 EUR/kg. id=104's 145/pcs is ~12× the per-kg — NOT a clean ×5/×10 pack-misscale.
- The "**Yakima Chief**" branding + the 145 price point matches the specialty Mosaic family already in the catalog at premium prices: HOPS_PRYSMA_MOSAIC (257/kg), HOPS_M_INCOGNITO (223/kg). 145 EUR for a Yakima Chief Mosaic "Sachet" is consistent with a **specialty form** (e.g. Cryo or a small specialty sachet), not commodity pellets.
- **Cannot be resolved without the operator** confirming: (a) which Mosaic product this is (commodity vs Cryo/Incognito/Prysma-class), and (b) the pack size (so the canonical-kg basis can be set). It may even belong to a *different MI* than the commodity HOPS_MOSAIC.
- **PM recommendation:** surface as a ReviewQueue / operator question. Do NOT guess — this is a COGS-impacting mapping. Until resolved, exclude id=104 from MOSAIC WAC or FLAG the MOSAIC WAC as containing an unresolved row (refuse-don't-NULL).

---

## 4. NEW Findings (not in the operator's audit) — surfaced by the DB-wide scan

These are **not WAC-blocking** (WAC uses `total/qty`, all are internally consistent) but matter for the catalog-price fallback and for data hygiene.

| MI | row | finding | bucket |
|---|---|---|---|
| ADJ_PEACH_TEA | 249 | delivery 4.99 CHF/kg (320 kg, consistent); catalog **49.90** = ×10 stale. Tea concentrate at ~5/kg is realistic; 49.90/kg is not. | Catalog hygiene |
| YEAST_FARMHOUSE | 427 | delivery 0.2216 EUR/g (500 g, consistent); catalog **2.545** = ~11.5× stale. | Catalog hygiene |
| PROC_ALIGAL2_LIQ | 86/143/238/444 | delivery 0.37–0.39 CHF/kg (liquid gas, consistent); catalog **550** = ~1400× stale. | Catalog hygiene (known) |
| PROC_ALIGAL2_ECO | 445 | `pricing_unit` is **blank** (empty string, not NULL); qty 4155 @ 0.01. | Minor — set pricing_unit |
| YEAST_SAFALE_BE134 | 313 | delivery 0.5 kg @ 125.4/kg (correct); catalog **62.70** is per-*brick* (500 g) not per-kg → catalog/delivery unit mismatch convention. | Catalog hygiene |

**Architectural note:** several `ref_mi.price` catalog values are per-*pack* / per-*brick* / stale, while the deliveries are per canonical unit. This is fine for WAC (which ignores catalog) but **lethal for the future `COALESCE(WAC, catalog)` fallback** — a MI with no Active deliveries would fall back to a 10×–1400× wrong catalog price. The catalog must be normalized to the canonical unit before that fallback is wired (Phase D).

---

## 5. The Canonical Billing-Format Model (architecture)

### 5.1 Canonical inventory + cost unit, per category (the contract)

| Category | Canonical unit | Input unit (form/invoice) | Notes |
|---|---|---|---|
| Malt | **kg** | kg (bulk), or ton/big-bag billed → normalize to kg | |
| Hops | **kg** | g (brew form input) or kg/pack (invoice) | consumed in g; priced/stored kg |
| Yeast | **kg** (preferred) or g | brick (500 g) or pack | see §5.3 — pick ONE per MI, consistently |
| Process Chemical | **kg** (solids/most) or **L** (liquids) | g/ml (brew) or kg/pack (invoice) | ml→kg needs density (`ref_mi.density_g_per_ml`, live since migs 157–160) |
| Brewing Adjunct | **kg** | g (brew) or kg (invoice) | |
| Brewing Mineral | **kg** | g (brew) or kg (invoice) | |
| Packaging | **unit/each** | unit/PCE/piece | |
| Liquids-by-volume (CO2, water, gas) | **L** | L or bottle (normalize bottle→L) | |

### 5.2 The single source of truth: `ref_mi_invoicing_units` with ONE rule

`ref_mi_invoicing_units` IS the intended billing-format map and the right SoT. Its current state (17 rows, partial Phase-2 backfill 2026-05-21) is **drifted and incomplete**. Adopt this ONE rule and complete the table:

> **RULE (canonical convention):**
> - `pack_size` is ALWAYS expressed in the MI's **canonical unit** (= `ref_mi.pricing_unit`), never in the count of items.
> - The normalization factor is **`pack_size`** itself: `canonical_qty = invoiced_pack_count × pack_size`, and `canonical_unit_price = invoiced_pack_price / pack_size`.
> - Therefore `ref_mi.pricing_unit` and the unit in which `pack_size` is measured MUST agree, per MI.

**Current drift this rule fixes (the 500 g brick, modelled three different ways):**

| MI | `ref_mi.pricing_unit` | `pack_size` | consistent under RULE? |
|---|---|---|---|
| YEAST_FARMHOUSE | g | 500.0 | ✅ (500 g) |
| YEAST_LALLEMAND_VERDANT | g | 500.0 | ✅ (500 g) |
| YEAST_LALBREW_ABBAYE | kg | 0.5 | ✅ (0.5 kg) |
| YEAST_SAFALE_BE134 | kg | 0.5 | ✅ (0.5 kg) |
| **YEAST_US05** | **g** | **0.5** | ❌ — pricing_unit=g but pack_size=0.5 (should be **500**) |
| **YEAST_W3470** | **g** | **0.5** | ❌ — same; should be **500** |

So even within the same physical brick, two consistent conventions coexist (g/500 and kg/0.5) and two BROKEN rows (US05, W3470: g/0.5). The de-drift = pick per-MI consistency and fix the 2 broken invoicing rows to g/500. (Separately, decide whether to standardize ALL yeast to kg or g — see §5.3.)

### 5.3 Yeast canonical-unit decision (operator-gated, recommend kg)
Yeast currently splits: some MIs `pricing_unit=g` (US05/W3470/FARMHOUSE/VERDANT), some `pricing_unit=kg` (ABBAYE/SAFALE/BE134). **PM recommendation: standardize all yeast to `kg`** (matches malt/hops/proc; one mass convention; the brick is naturally 0.5 kg). This is a `ref_mi.pricing_unit` + `conversion_factor` change and touches `wac_snapshots` — **defer to a deliberate operator-approved migration**, not folded into the WAC build. For the WAC build, the Class-C guard (do not ×conversion_factor when pricing_unit='g') handles the current mixed state safely.

### 5.4 How ingest MUST normalize going forward
The root cause of every confirmed error is that **the parser stored the invoice's native unit without normalizing to canonical**, and a later manual correction touched qty but not price (or vice-versa). The durable fix is at the master-data root (consistent with the "minimize NULL / fix at the root" principle):

1. On every parsed line, ingest looks up `ref_mi_invoicing_units` (by `mi_id_fk`, optionally `supplier_fk`) for the active default pack.
2. It converts to canonical BEFORE writing: `qty_delivered = invoiced_count × pack_size`; `unit_price = line_total / qty_delivered`; `total_original = line_total` (invariant: `qty_delivered × unit_price == total_original` MUST hold — assert it at write time).
3. Set `inv_deliveries.pricing_unit = ref_mi.pricing_unit` at write (it is 100% NULL today — populate it so the row is self-describing and the invariant assertion is auditable).
4. If no `ref_mi_invoicing_units` row exists for an MI whose invoice line is in a pack/non-canonical unit → **refuse: emit a `doc_review_queue` row, do not guess the pack size.**
5. A manual correction to qty MUST recompute unit_price from total (or be rejected if the invariant breaks). The US05/W3470/MUNICH class of error is a manual-correction-without-reprice; an invariant assertion at write would have caught all three.

`pack_converted` already exists on `doc_invoice_lines` (currently 0 on all the audited lines — confirming the parser did NOT convert; the conversion that happened on DEHAZE/SAFALE was a different code path). Make `pack_converted` truthfully reflect whether canonicalization was applied.

---

## 6. `v_mi_wac` Revised Plan

### 6.1 The formula choice changes which fixes are blockers
The prior DBA report and the live `wac_snapshots` use **`Σ(qty_remaining × unit_price × fx) / Σ(qty_remaining)`**. Under that formula:
- The 3 gross-misscale rows (MUNICH/US05/W3470) produce catastrophic WAC (×1000/×500) and are **hard blockers** — the data MUST be fixed first.

**There is a more robust alternative.** For the misscale rows, `total_original` is CORRECT (it is the real invoice line total); only `unit_price` (or the qty/price split) is wrong. A WAC computed as **`Σ(total_chf for the remaining fraction) / Σ(qty_remaining)`** would be correct for US05/W3470/MUNICH *even before the data fix*, because it never multiplies by the broken `unit_price`.

BUT: `Σ(total_chf)/Σ(qty_remaining)` is only equal to the true WAC when `qty_remaining == qty_delivered` (un-depleted) — once a row is partially depleted, `total_chf` is the *full* delivery value, not the remaining value. So the mathematically correct general form is:

```
wac_per_pricing_unit = Σ( qty_remaining × (total_chf / qty_delivered) ) / Σ( qty_remaining )
                     = Σ( qty_remaining × eff_unit_price_chf )       / Σ( qty_remaining )
```
where `eff_unit_price_chf = total_chf / qty_delivered` — the **per-row effective unit price derived from total**, not the stored `unit_price`.

**PM recommendation:** build `v_mi_wac` on `eff_unit_price = total_chf / qty_delivered`, NOT on the stored `unit_price`. This is more robust because:
- It is immune to the MUNICH/US05/W3470 class (qty/price split wrong but total right) — these stop being view blockers.
- It still requires the GALAXY/SIMCOE-105/WATER_DEMIN fixes, because for those the qty itself is in the wrong unit (1 pack vs 5 kg) — `total/qty` = 179.5/1 is still per-pack, not per-kg. The qty *must* be canonicalized.
- It is the same `total/qty` the eff-price audit relied on, so the view and the audit agree by construction.

This does NOT remove the need to fix the 3 misscale rows (the stored `unit_price` is still wrong and other consumers/snapshots read it), but it **decouples the view's correctness from those fixes** and removes them as a go-live gate. The fixes become a data-hygiene task that can land independently.

### 6.2 Normalize via the billing-format map; refuse-don't-NULL on gaps
- The view computes WAC per `ref_mi.pricing_unit` (canonical), then exposes the per-consumption-unit WAC via `× conversion_factor` with the **Class-C guard** (don't apply when `pricing_unit='g'` or `pricing_unit=consumption_unit`) — exactly as the DBA spec laid out (classes A–F).
- **FLAG, don't silently include, any MI with deliveries but a missing/ambiguous billing-format mapping.** Specifically, emit a flag column (or a companion `doc_review_queue` row) for:
  - Any MI whose row(s) FAIL the invariant `ABS(qty_delivered×unit_price − total_original) > tolerance` (the 3 misscale rows, until fixed).
  - HOPS_MOSAIC (unresolved product/pack — operator-only).
  - Any MI in a pack/non-canonical invoice unit with NO `ref_mi_invoicing_units` row.
- The view must NOT emit a NULL or 0 WAC for a flagged MI silently — it surfaces the flag (refuse-don't-NULL), consistent with the house rule.

### 6.3 Build mechanics (unchanged from the planning consult)
- Next migration number: **162** (data fixes — now OPTIONAL as a go-live gate if §6.1 adopted, but still recommended) then **163** (the view). Template = migration 161 `v_bip_canonical` (CREATE OR REPLACE VIEW + refuse-don't-NULL flag + `schema_meta` INSERT…ON DUP KEY UPDATE, `derived`).
- Filters: `status='Active' AND exclusion_class IS NULL AND ingredient_fk IS NOT NULL AND qty_remaining>0 AND m.is_inventoried=1`.
- FX: `IF(currency='EUR', COALESCE(eur_to_chf, 0.945), 1)` — but if building on `total_chf/qty_delivered`, `total_chf` is already FX-applied, so no FX multiply needed (use `total_chf` directly).
- `conversion_factor_snapshot` column: confirmed NOT needed (physical constant).
- ml-priced aids (PHOSPHORIQUE/DEHAZE/CLAREX): density now lives on `ref_mi.density_g_per_ml` (migrations 157–160). The view's per-consumption-unit leg should consume density-aware conversion (mirror `unit_to_canonical_factor()` / `v_bip_canonical`), NOT the ml=g approximation the DBA report tolerated.

---

## 7. Remediation Plan (PROPOSE — not applied this pass)

### Bucket A — Unambiguous silent-unit fixes (qty in wrong unit; the view REQUIRES these)
`ref_mi_invoicing_units`-backed, source-confirmed, qty_remaining == qty_delivered (clean, no FIFO reconciliation):

| Row | Change | Source of truth |
|---|---|---|
| id=312 HOPS_GALAXY | qty 1→5, qty_remaining 1→5, unit_price 179.5→35.9 | catalog ×5 + 5 kg pack |
| id=105 HOPS_SIMCOE | qty 1→5, qty_remaining 1→5, unit_price 70→14 | source invoice "1 pcs" of 5 kg pack; sibling 14/kg |
| id=12 QA_WATER_DEMIN | qty 4→20, qty_remaining 4→20, unit_price 3.123→0.6246 | 4×5 L bottles; catalog 0.6246/L |

### Bucket B — Unambiguous gross-misscale fixes (qty right, price wrong; needed for snapshots + non-view consumers; view-blocker only if §6.1 NOT adopted)
| Row | Change | Note |
|---|---|---|
| id=21 MALT_MUNICH | unit_price 509→0.509 | qty stays 26000 kg |
| id=426 YEAST_US05 | unit_price 109→0.218 | + operator-gated qty_remaining 1→500 (Bucket E) |
| id=425 YEAST_W3470 | unit_price 179.4→0.3588 | + operator-gated qty_remaining 1→500 (Bucket E) |

### Bucket C — `ref_mi_invoicing_units` de-drift + completion
- Fix the 2 broken rows: YEAST_US05 (id=14) and YEAST_W3470 (id=15) `pack_size` 0.5 → **500** (to match `pricing_unit='g'`).
- Complete the table: it has 17 rows but ~98 WAC-relevant MIs. Backfill a default pack row for every MI ever invoiced in a non-canonical (pack/brick/bottle/ton) unit — seeded from existing canonical delivery data and source invoices, NEVER invented. This is the data-driven bridge ingest will read going forward.
- Adopt the §5.2 RULE as the table's documented invariant.

### Bucket D — Catalog-price hygiene (separate pass; NOT WAC-blocking; gates the future COALESCE fallback)
Normalize `ref_mi.price` to the canonical unit for the stale rows: ADJ_PEACH_TEA (49.90→~4.99), YEAST_FARMHOUSE (2.545→~0.2216), PROC_ALIGAL2_LIQ (550→~0.39), YEAST_SAFALE_BE134 (per-brick→per-kg), and a full audit of catalog vs eff-price across all MIs. Set PROC_ALIGAL2_ECO (id=445) blank pricing_unit. **Do this before wiring `COALESCE(WAC, catalog)` in Phase D**, else MIs without Active deliveries fall back to a 10×–1400× wrong price.

### Bucket E — Operator-gated (do NOT guess)
1. **HOPS_MOSAIC id=104:** which product (commodity vs Cryo/Incognito/Prysma-class) and what pack size? May be a different MI. → operator/RQ.
2. **YEAST_US05/W3470 `qty_remaining` 1→500:** changes stock VALUE, not just rate. The 2026-05-22 correction set qty_delivered=500 but left qty_remaining=1. Is the true remaining 500 g (one full pack) or ~0 (consumed)? → operator confirm before changing.
3. **Yeast canonical-unit standardization** (§5.3): standardize all yeast to kg? → operator decision; deliberate migration if yes.

---

## 8. Residual Operator Questions (consolidated)

1. **MOSAIC id=104** — confirm the Yakima Chief Mosaic product + pack size (or that it's a distinct specialty MI). COGS-impacting; not guessable.
2. **US05/W3470 qty_remaining** — is the remaining stock 500 g (full pack) or near-zero? Changes inventory value.
3. **Yeast unit standardization** — adopt kg for all yeast? (recommend yes, via a dedicated migration; not in the WAC build).
4. **Catalog hygiene timing** — fix the stale catalog prices now (Bucket D) or as a separate pass before the Phase-D COALESCE fallback? (recommend before COALESCE wiring.)
5. **v_mi_wac formula** — adopt the `total_chf/qty_delivered` (effective-price) basis (§6.1, recommended) so the 3 misscale rows are not a go-live blocker? (PM recommends yes.)

---

## 9. Sequencing Verdict

1. Build `v_mi_wac` on the **effective-price (`total_chf/qty_delivered`) basis** with the Class-C guard + density-aware consumption leg + refuse-don't-NULL flag → migration 163 (view). This unblocks the WAC view NOW.
2. Land Bucket A (silent-unit qty fixes) — **required for the view** (qty must be canonical). Source-confirmed, unambiguous → migration 162.
3. Land Bucket B (gross-misscale price fixes) — fixes `wac_snapshots` + non-view consumers; can ride 162 or follow. Recompute the 4 affected `wac_snapshots` rows (US05, W3470, SIMCOE, WATER_DEMIN) after.
4. Bucket C (invoicing-units de-drift + completion) — the durable root fix; do before relying on ingest normalization.
5. Bucket D (catalog hygiene) — before Phase-D COALESCE wiring, not before the view.
6. Bucket E — operator answers gate MOSAIC inclusion + yeast stock value + yeast unit standardization.

---

*Generated 2026-05-26 by maltyweb-pm. Read-only; no data, schema, or files on the VPS modified. All DB queries were inline `php -r` (no helper scripts created). NB: pre-existing /tmp/*.php debris from prior sessions (May 12–25) was observed on the VPS — flagged for a hygiene sweep, not created by this audit.*

---

## 10. LOCKED OPERATOR DECISIONS + FINAL BUILD SPEC (2026-05-26)

Operator decisions taken (this session): (1) WAC basis = `total_chf ÷ qty_delivered` — **ADOPTED**. (2) FULL normalization in this build (silent-unit qty, gross-price, invoicing-units de-drift+completion, build the view, recompute the contaminated snapshots). (3) MOSAIC id=104 = correct as-is, NO fix (confirms WAC must weight-average across suppliers per ingredient_fk). (4) Yeast US05 + W3470 remaining = 0.5 kg each (operator's "1 kg" = 0.5+0.5 combined; only 0.5 kg of each was ever delivered); **standardize ALL yeast to kg canonical**.

### 10.1 CRITICAL ID-SPACE CORRECTION (re-verified against live, this pass)
The operator decision-list IDs (12, 21, 74, 105, 312, 418, 425, 426) are **`inv_deliveries.id`**, NOT `ref_mi.id`. Confirmed: `ref_mi.id` 21 = MALT_RICE_HULLS, 105 = PKG_LINER, 12 = MALT_CHOCO_RYE — different MIs entirely. All UPDATEs below key on `inv_deliveries.id` (delivery rows) or `ref_mi.id` (MI rows), labelled explicitly. `wac_snapshots.mi_id_fk` = `ref_mi.id`.

### 10.2 Re-verified live values (the build-of-record)

inv_deliveries (Bucket A silent-unit + Bucket B gross-price), all `status='Active'`:

| DEL id | MI (ref_mi.id) | live qty / qrem / unit_price / cur / total_chf | → canonical qty / qrem / unit_price | invariant check |
|---|---|---|---|---|
| 21 | MALT_MUNICH (1) | 26000 / **26** / 509 / EUR / 12506.13 | 26000 / 26 *(unchanged — see flag)* / **0.509** | 26000×0.509=13234=total_orig ✓ |
| 312 | HOPS_GALAXY (36) | 1 / 1 / 179.5 / EUR / 169.6275 | **5** / **5** / **35.9** | 5×35.9=179.5=total_orig ✓ |
| 105 | HOPS_SIMCOE (46) | 1 / 1 / 70 / EUR / 66.15 | **5** / **5** / **14** | 5×14=70=total_orig ✓ |
| 12 | QA_WATER_DEMIN (277) | 4 / 4 / 3.123 / CHF / 12.492 | **20** / **20** / **0.6246** | 20×0.6246=12.492=total_orig ✓ |
| 426 | YEAST_US05 (65) | 500 / **1** / 109 / EUR / 103.005 | **0.5** / **0.5** / **218** | 0.5×218=109=total_orig ✓ |
| 425 | YEAST_W3470 (66) | 500 / **1** / 179.4 / EUR / 169.533 | **0.5** / **0.5** / **358.8** | 0.5×358.8=179.4=total_orig ✓ |
| 427 | YEAST_FARMHOUSE (194) | 500 / 500 / 0.2216 / EUR / 104.706 | **0.5** / **0.5** / **221.6** | 0.5×221.6=110.8=total_orig ✓ |
| 429 | YEAST_VERDANT (196) | 1000 / 1000 / 0.1978 / EUR / 186.921 | **1.0** / **1.0** / **197.8** | 1.0×197.8=197.8=total_orig ✓ |

**total_chf stays the invoice truth on every row** (already FX-applied; we never touch it). `unit_price` is recomputed = `total_original / new_qty_delivered` so the invariant holds.

**SUPERSEDED:** the prior §2 per-g prices for US05 (0.218/g) and W3470 (0.3588/g) are SUPERSEDED by operator decision #4 (kg-canonical) → per-kg 218 / 358.8. Decision #2(b)'s per-g values were correct under the OLD g-basis; #4 is the later, fuller decision.

**ABBAYE (DEL 74, 418):** NO FIX — confirmed live: qty=1.0 (=1.0 kg, 2×500g bricks), unit_price=178 CHF/kg, total=178, snapshot wac=178 over qrem=2kg. Operator confirmed correct.

### 10.3 Yeast kg-standardization (ref_mi side) — decision #4
5 yeast MIs are currently `pricing_unit='g'`; flip ALL to kg-canonical (consumption stays in g via cf=0.001):
US05(65), W3470(66), FARMHOUSE(194), VERDANT(196), POMONA(238, no Active delivery but flipped for consistency).
Per MI: `pricing_unit g→kg`, `input_unit` stays `g`, `consumption_unit='g'`, `conversion_factor` stays `0.001` (pitched-g → priced-kg). The kg-priced yeast (ABBAYE, SAFALE_BE134, LACTO_HELV, LALL_LONA, LONDON_ALE3/FOG, PINNACLE) are already correct.

### 10.4 invoicing_units de-drift + completion (Bucket C) — decision #2(c)
ONE RULE: `pack_size` ALWAYS in the MI's canonical `pricing_unit`. Touched rows (yeast flip resolves the drift): FARMHOUSE iu(12) pack_size 500→**0.5**; VERDANT iu(13) 500→**0.5**; US05 iu(14) stays 0.5 (now matches kg); W3470 iu(15) stays 0.5 (now matches kg); their notes corrected to "pricing_unit=kg, pack_size=0.5". Recommendation: **seed-touched-rows + flag-rest** — completing all ~98 MIs is NOT in this build (no canonical source for most pack formats; inventing = guessing). The view's `wac_unresolved` flag surfaces missing rows where they actually break WAC.

### 10.5 Snapshot recompute set — GREW from 4 to 6
Re-verification found FARMHOUSE(194) + VERDANT(196) ALSO carry 2026-04 snapshots in per-g terms → flipping their MI to kg makes those snapshots stale too. Full recompute set (`wac_snapshots`, period 2026-04, `replay_source='current_approximation'`):
US05(65), W3470(66), SIMCOE(46), QA_WATER_DEMIN(277), **FARMHOUSE(194), VERDANT(196)**. MUNICH(1) has NO snapshot; ABBAYE(255) snapshot is correct (leave). Recompute formula = `Σ(qty_remaining × total_chf/qty_delivered)/Σ(qty_remaining)` over the post-162 canonical rows, rebuilding `wac_chf`, `total_value_chf`, `qty_remaining_at_close`, `delivery_row_ids`, `row_hash`.

### 10.6 wac_unresolved targets (refuse-don't-NULL) — currently 2 MIs in scope
PROC_ALIGAL2_ECO (591, pricing_unit NULL) + PKG_TEA_BOT_CH (561, pricing_unit='month' — TEA eco-tax, not a real WAC consumable; price stays NULL by convention). The view flags these, never NULLs them silently. 98 distinct MIs / 185 Active rows in scope total.

*Locked-decisions section appended 2026-05-26 by maltyweb-pm after strict live re-verification. Build dispatched to Sonnet sql+coder.*

---

## 11. BUILD-VERIFIED STATE + VERIFICATION GATE (RULE-2 GO, 2026-05-26)

### 11.1 RULE-2 verdict — GO on migrations 162 + 163
The commit-stage reviewer ran a **GO** on migration 162 (data fixes) and migration 163 (`v_mi_wac` view). Reviewer independently verified all 8 invariant checks from §10.2, plus:
- `v_mi_wac` row count = **98**.
- `wac_unresolved` flagged = **2** (PROC_ALIGAL2_ECO — NULL `pricing_unit`; PKG_TEA_BOT_CH — `pricing_unit='month'`, TEA eco-tax, NULL price by convention). Exactly the two §10.6 targets.
- The 6 recomputed `wac_snapshots` `row_hash`es match the TS writer `_warehouse-compute-wac-snapshot.ts:154` recipe `sha256(mi_id_fk|PERIOD|wac.toFixed(6)|sumQty.toFixed(4))` **exactly**.

**Technique (record):** the coder embedded **hardcoded pre-computed hashes** in migration 162 to avoid the SQL-`CAST`-vs-JS-`toFixed` precision trap — MySQL cannot reproduce JS `toFixed` rounding, so computing the hash in SQL would silently drift from the canonical TS writer. The fix is to compute the hash off-DB (the TS recipe) and write the literal. Reuse whenever a migration must materialize a row_hash that a TS/JS writer is the source of truth for.

### 11.2 ⚠️ ARITHMETIC CORRECTION — MUNICH WAC (PM slip, corrected)
An earlier WAC-spec verification gate stated the expected MUNICH WAC as **"12506.13 / 26 = 481.0 CHF/kg, driven by the qrem=26 mismatch."** **This was wrong.** The view computes:

```
wac = Σ( qty_remaining × total_chf / qty_delivered ) / Σ( qty_remaining )
```

For a single-supplier MI like MUNICH this reduces to `total_chf / qty_delivered = 12506.13 / 26000 = `**`0.481005 CHF/kg`**. **`qty_remaining` CANCELS** between numerator and denominator — it has NO effect on the WAC RATE. The qrem = 26-vs-26000 scale mismatch (flagged in §10.2) affects ONLY `total_value_chf` (inventory value = wac × qty_at_close), never the rate. The corrected figure was confirmed by the coder AND the RULE-2 reviewer each running the live `v_mi_wac` SELECT → **0.481005 CHF/kg**.

### 11.3 Independently-verified gate WAC values (build-of-record)
These are the post-162 weighted-average RATES per `ingredient_fk` (across ALL Active rows of each MI), as returned by the live `v_mi_wac`. They supersede any earlier per-spec figures:

| MI | verified `v_mi_wac` rate | note |
|---|---|---|
| MALT_MUNICH | **0.481005** CHF/kg | single supplier; rate = total_chf/qty_delivered (qrem cancels) |
| HOPS_SIMCOE | **24.997304** CHF/kg | blends corrected id=105 @14 with id=294 @28.32 across suppliers |
| HOPS_GALAXY | **33.926** CHF/kg | |
| YEAST_US05 | **206.010** CHF/kg | post kg-flip (decision #4) |
| YEAST_W3470 | **339.066** CHF/kg | post kg-flip |
| YEAST_FARMHOUSE | **209.412** CHF/kg | post kg-flip |
| YEAST_VERDANT | **186.921** CHF/kg | post kg-flip |
| QA_WATER_DEMIN | **0.6246** CHF/L | |
| HOPS_MOSAIC | **11.517** CHF/kg | multi-supplier, NO fix (decision #3) — commodity siblings only |

(The single-delivery eff-prices in §10.2 are per-row; these §11.3 figures are the per-MI weighted averages the view actually exposes — they differ wherever an MI has >1 Active delivery.)

### 11.4 LOW findings to carry to Phase D
- **LOW-3:** `v_mi_wac` filters `m.is_inventoried=1`; the TS snapshot writer `_warehouse-compute-wac-snapshot.ts` does NOT → the view and `wac_snapshots` cover DIFFERENT MI populations. Document in the view's `upstream_hint` and in the future `COALESCE(WAC, catalog)` consumer design so callers know which surface to prefer (view = inventoried-only; snapshots = broader).
- **LOW-2:** the 2026-04 snapshot recompute uses CURRENT live `qty_remaining` (`replay_source='current_approximation'`), NOT a true April-30 cutoff replay → SIMCOE's `qty_at_close` is approximate. A future period-cutoff recompute will refine it. Known, not a blocker.

### 11.5 5th yeast confirmed
The kg-standardization (decision #4, §10.3) covers `ref_mi.id` 65 (US05), 66 (W3470), 194 (FARMHOUSE), 196 (VERDANT), **238 (POMONA)** — POMONA = 238 was the 5th, confirmed `pricing_unit='g'` pre-migration.

*§11 appended 2026-05-26 by maltyweb-pm: RULE-2 GO recorded + the MUNICH arithmetic slip corrected (481→0.481005; qrem cancels in the rate, affects only inventory value). Migrations 162+163 being applied to the VPS; post-apply LANDED verification to follow.*
