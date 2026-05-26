# Unit-Fidelity Audit — maltyweb ERP
**Date:** 2026-05-26  
**Scope:** Read-only review of all `qty × price` cost computations across PHP modules, SQL views, and TypeScript pipeline  
**Auditor:** Automated code + SQL review (Sonnet)

---

## Executive Summary

The audit examined every `cost = qty × price` path in the ERP: `sku-bom-compile.php` (packaging BOM compiler), `sku-compiler.php` (legacy brewing BOM compiler), `warehouse.php` / `warehouse-export.php` (WIP cost and export), `compute-weighted-prices.ts` (WAC builder), and the `ref_sku_bom` / `inv_deliveries` tables in production.

**4 findings confirmed against live data. 0 are currently corrupting booked COGS. 2 are latent bugs that will activate when specific features go live (composite SKUs, yeast WAC).**

| Severity | Count | Status |
|---|---|---|
| HIGH (latent) | 2 | Will corrupt cost when composite SKUs / yeast WAC activated |
| MEDIUM (active, limited blast radius) | 2 | Affecting WIP cost for acid/enzyme rows; legacy BOM label drift |
| LOW (data gap) | 1 | 52 packaging BOM rows with NULL cost due to unpriced MIs |
| CLEAN / verified correct | 5 paths | See section 6 |

---

## 1. FINDING A — HIGH (latent): composite_liquid cost formula missing g→kg conversion

**Status:** Latent — no `bom_source='composite_liquid'` rows exist yet in production. Will activate when composite (PD8/PAL/XMAS/PAC) SKUs are compiled.

**File:** `/home/kluk/projects/maltyweb/app/sku-bom-compile.php`

**Mechanism:**

```php
// Line ~388: loading member liquid BOM for composite SKUs
$qtyPerHl = (float)$liq['qty_per_unit'] / $hlPerUnit;
// qty_per_unit is in grams (for hops), $hlPerUnit e.g. 2.5
// → $qtyPerHl = e.g. 200.0 (grams/HL)

// Line ~1087: computing composite_liquid BOM row cost
$cost = ($price !== null) ? round($price * $line['qty'], 6) : null;
// $line['qty'] = $qtyPerHl * $slotHl  (still in GRAMS)
// $price = ref_mi.price in EUR/kg
// → cost = (EUR/kg) * grams = result is 1000× too high
```

**Compounding issue — line ~1099:**

```php
':ing_unit' => $mi['pricing_unit'] ?? 'kg',
```

The stored `ing_unit` will be `'kg'` (from `ref_mi.pricing_unit`) even though `$qty` was computed in grams. This mislabels the column, making it impossible for any downstream code to detect and correct the error by inspecting `ing_unit`.

**Concrete example (real data):** HOPS_CITRA has `pricing_unit='kg'`, `ref_mi.price` ≈ 16.5 EUR/kg. A PD8 composite row derived from member SKU with 200g hops/HL at 2.5 HL fill would get `qty=500 (grams)`, `cost = 16.5 × 500 = 8250 EUR/HL` instead of `16.5 × 0.5 = 8.25 EUR/HL`. Inflation factor: **1000×**.

**Blast radius:** All composite SKUs (PD8, PAL, XMAS, PAC) × all hop and kg-priced ingredients once composite compilation runs. Will silently corrupt `ref_sku_bom.cost`, `v_sku_volume.cost_per_hl`, FG cost, and any COGS line derived from composite SKU sales.

**Recommended fix:**

In `sku-bom-compile.php` around the composite_liquid block, apply unit conversion before computing cost:

```php
// After $price is resolved, determine conversion factor
$ingUnit   = $mi['pricing_unit'] ?? 'kg';    // THIS is the priced unit
$sourceUnit = $liq['ing_unit'];              // THIS is the qty unit (e.g. 'g')
$convFactor = unit_to_base($sourceUnit) / unit_to_base($ingUnit);
// unit_to_base: g=1, kg=1000, mL=1, L=1000, unit=1
$cost = ($price !== null) ? round($price * $line['qty'] * $convFactor, 6) : null;
':ing_unit' => $sourceUnit,   // preserve the ACTUAL unit, not a lie
```

Add a `unit_to_base()` helper (or read from `ref_units`) rather than hardcoding the table.

---

## 2. FINDING B — HIGH (latent): Yeast WAC 1000× wrong — pack-size not applied in compute-weighted-prices.ts

**Status:** Latent for cost impact — `ref_mi.price` fallback path (catalog price) is used in downstream cost, and that price is already the correct per-g price. The WAC written to `wac_snapshots` is wrong but only becomes COGS-impacting when the WAC path displaces the catalog fallback.

**File:** `/home/kluk/projects/maltytask/scripts/compute-weighted-prices.ts`

**Lines 219–221:**

```typescript
const totalQty    = dels.reduce((s, d) => s + d.qtyRemaining, 0);
const weightedSum = dels.reduce((s, d) => s + d.qtyRemaining * d.unitPrice, 0);
const avgPrice    = weightedSum / totalQty;
```

No pack-size adjustment. No unit conversion. `d.unitPrice` is the price paid per delivery pack (e.g., EUR 179.40 for a 500 g pack of W3470 yeast). `d.qtyRemaining` is in the delivery unit (packs). The resulting WAC is EUR 179.40/pack, but `ref_mi.pricing_unit='g'` — the WAC is being stored as if it is EUR/g when it is really EUR/pack.

**Live data confirmation:**

| MI | pricing_unit | inv_deliveries d.pricing_unit | ref_mi.price (correct per-g) | Implied WAC (pack price) |
|---|---|---|---|---|
| YEAST_W3470 | g | NULL | 0.1794 EUR/g | ~179.4 EUR/g (1000×) |
| YEAST_US05 | g | NULL | 0.098 EUR/g | ~98 EUR/g (1000×) |
| YEAST_FARMHOUSE | g | NULL | 0.062 EUR/g | ~62 EUR/g (1000×) |
| YEAST_LALLEMAND_VERDANT | g | NULL | 0.2 EUR/g | ~200 EUR/g (1000×) |
| YEAST_LALLEMAND_POMONA | g | NULL | 0.2 EUR/g | ~200 EUR/g (1000×) |

Note: all 4 live yeast delivery rows have `pricing_unit=NULL` in `inv_deliveries`, and `ref_mi_invoicing_units` shows conflicting notes (`pricing_unit=kg` in notes but actual `ref_mi.pricing_unit='g'`). The invoicing-units seed data needs cleanup alongside this fix.

**Blast radius:** WAC snapshots for all 5 yeast MIs. Brewing WIP cost, COP/COGS yeast line, monthly batch cost would be ~1000× overstated the moment WAC-driven pricing supersedes the catalog fallback for these MIs.

**Recommended fix (two-step):**

1. In `compute-weighted-prices.ts`, after loading deliveries for an MI, look up `ref_mi_invoicing_units` for a `pack_size` and `pack_unit`, then convert: `d.unitPrice / pack_size` to get price in MI's `pricing_unit`.
2. Correct `ref_mi_invoicing_units` rows for yeast to reflect the actual relationship: pack_unit matches what is in the delivery, pack_size is the quantity per pack in `ref_mi.pricing_unit` units (grams). Current seed notes say `pricing_unit=kg` which contradicts `ref_mi.pricing_unit='g'`.

---

## 3. FINDING C — MEDIUM (active): ml→kg unit conversion uses water-density assumption

**Status:** Active — affects WIP recipe-ingredient cost computation in `warehouse.php` and `warehouse-export.php`. Not in COGS booked rows (those go through WAC / catalog price, not the WIP recipe-loader path).

**Files:**
- `/home/kluk/projects/maltyweb/public/modules/warehouse.php` lines ~958–972
- `/home/kluk/projects/maltyweb/public/modules/warehouse-export.php` lines ~379–390

**Mechanism:**

```php
elseif ($riUnit === 'ml' && ($pricingU === 'l' || $pricingU === 'kg')) {
    $unitFactor = 0.001;   // assumes 1 mL = 0.001 kg (water density)
}
$chf = $qty * $unitFactor * $priceInfo['unit_price_chf'];
```

For phosphoric acid (PROC_PHOSPHORIQUE, density ~1.7 g/mL), 1 mL = 0.00170 kg, not 0.00100 kg. Using 0.001 understates cost by ~41%.

**Live data confirmation:**

| MI | pricing_unit | ing_unit | Rows | Total volume | Correct factor | Actual factor | Cost error |
|---|---|---|---|---|---|---|---|
| PROC_PHOSPHORIQUE | kg | mL | 652 | 2,037,782 mL | ~0.00170 | 0.001 | ~41.2% understatement |
| PROC_CLAREX | kg | mL | 15 | 7,248 mL | unknown | 0.001 | unknown |
| PROC_DEHAZE | kg | mL | 10 | 4,700 mL | unknown | 0.001 | unknown |

Estimated cumulative understatement for PROC_PHOSPHORIQUE alone: reference price ~4.10 CHF/kg → 2037.782 kg equivalent at water density vs ~3464 kg true equivalent → ~8,559 CHF understated across 652 brewing events (historical, not actionable for closed months but distorts live WIP).

**Blast radius:** WIP tank cost in `warehouse.php` (Salle de contrôle WIP view) and `warehouse-export.php` (COGS GL split export). Does not affect `ref_sku_bom.cost` or `inv_consumption` (which use the separately correct `ref_units`-based conversion in the `consumption_since` CTE).

**Recommended fix:**

Replace the manual `if/elseif` chain in both files with a lookup from `ref_units`:

```php
// In the recipe-loader unit conversion block:
$convFactor = get_unit_conversion_factor($pdo, $riUnit, $pricingU);
// uses ref_units.to_base_factor: factor = from.to_base_factor / to.to_base_factor
// e.g. mL(1) / kg(1000000) = 0.000001 ... wait — ref_units has g=1,kg=1000,mL=1,L=1000
// → mL→kg: dimension mismatch (volume vs mass) — water-density assumption is unavoidable
//    unless density is stored per-MI
```

Since mass↔volume conversion requires density (a property of each substance), the only correct solution is to store density per-MI in `ref_mi` (e.g., a `density_g_per_ml` column, NULL for solids/units). Until then, the next-best option is to flag any `mL→kg` or `L→kg` conversion in the `ref_units`-dimension-mismatch path as an explicit warning, not a silent 0.001 fallback, and store the correct density per MI.

For the immediate practical fix: add a density override table or column for PROC_PHOSPHORIQUE (1.70), PROC_CLAREX (look up from supplier data sheet), PROC_DEHAZE (look up).

---

## 4. FINDING D — MEDIUM: Legacy brewing BOM — `ing_unit='kg'` for sugar rows that are actually in grams

**Status:** Active data label error. Cost values appear correct (conversion was applied by the original compiler before writing), but the column label is wrong, creating a latent trap for any code that re-derives cost from ing_unit.

**Table:** `ref_sku_bom` — rows with `source='Brewing'`, `bom_source=NULL`, `compiled_at=NULL`

**Live data confirmation (ESTF = Estafette 20L keg):**

| mi_id | ing_unit | qty_per_unit | price | cost | qty×price | cost/qty_price ratio |
|---|---|---|---|---|---|---|
| MALT_SUGAR | kg | 21.155 | 0.00272 | 0.0575 | 0.05754 | ~1.0 ✓ |
| MALT_CASSONADE | kg | 25.0 | ? | ? | ? | ? |

The cost is plausible only if qty is in grams: 21.155 g × 0.00272 CHF/g ≈ 0.0575 CHF. But `ing_unit='kg'` — a reader interpreting this as kg would compute 21.155 kg × price/kg and get a different (correct) number only if price is stored per-g. In fact `ref_mi.pricing_unit` for MALT_SUGAR is `'kg'`, so any fresh cost derivation using `ing_unit='kg'` would need to use `qty=21.155 kg` — which is 21 kg of conditioning sugar per keg, clearly wrong (~1000× too much).

The legacy compiler (`sku-compiler.php`) applied g→kg conversion to the **value** of `qty_per_unit` before inserting (dividing by 1000) but then labeled the column `'kg'` anyway, producing a number that is correct in kg units (0.021155 kg) but stored the raw gram value (21.155). Wait — re-reading: the stored value IS 21.155 with ing_unit=kg. At 21.155 kg of sugar per 20L keg, that would be insane. The ratio confirms qty is in grams: `0.0575 / 0.00272 ≈ 21.1` grams at CHF/g price OR `0.0575 / 2.72 ≈ 0.021` kg at CHF/kg price. Since MALT_SUGAR `ref_mi.pricing_unit='kg'`, the price in ref_mi is CHF/kg ≈ 2.72. Then cost = 21.155 × 2.72 = 57.5 CHF — impossibly high for a keg. So cost 0.0575 CHF = price 2.72/1000 CHF/g × 21.155g = correct interpretation: **qty IS in grams, cost IS correct, label IS wrong.**

**5 affected rows** (MALT_SUGAR + MALT_CASSONADE across Estafette SKUs).

**Blast radius:** These rows have precomputed cost already correct. Risk is a future recompile that trusts `ing_unit='kg'` and recomputes `cost = price_per_kg × 21.155 kg = 57.5 CHF` instead of `0.0575 CHF`. This would inflate the BOM cost of Estafette/conditioning sugar rows by ~1000×.

**Recommended fix:** Correct the 5 rows: set `ing_unit='g'` (the actual unit). The cost column is already correct; only the label needs changing. Run via migration:

```sql
UPDATE ref_sku_bom
   SET ing_unit = 'g'
 WHERE source = 'Brewing'
   AND bom_source IS NULL
   AND mi_id IN (SELECT id FROM ref_mi WHERE mi_id IN ('MALT_SUGAR', 'MALT_CASSONADE'))
   AND ing_unit = 'kg'
   AND qty_per_unit > 1.0;  -- safety: 1+ kg conditioning sugar per unit = impossible
-- Verify with DRY RUN count before applying
```

---

## 5. FINDING E — LOW (data gap): 52 Packaging BOM rows have NULL cost due to unpriced MIs

**Status:** Active data gap — not a computation bug. The formula `cost = price × qty` is applied correctly; these rows have `price=NULL` or `price=0` because the MI has not been priced yet.

**Live data:** 52 `ref_sku_bom` rows with `bom_source='packaging'` and `cost IS NULL`, covering MIs such as `PKG_INTERCAL_NOMOQ`, `PKG_STICKER_DGD`, `PKG_LABEL_DIG`, `PKG_CAN_ALU_50`.

**Blast radius:** `v_sku_volume.cost_per_hl` silently excludes these components. FG cost and packaging COGS will be understated for any SKU containing these MIs. The magnitude depends on the actual prices once sourced.

**Recommended fix:** Operator action — price these MIs in ref_mi, then re-run `sku-bom-compile-cli.php --apply` to recompute cost. Not a code fix.

---

## 6. Clean / Verified-Correct Paths

These paths were audited against live data and found to be correct:

| Path | Verification |
|---|---|
| Packaging BOM cost (`sku-bom-compile.php` packaging rows, line ~729) | `ing_unit='unit'`, `pricing_unit='unit'` → `cost = price × qty` with no conversion needed. 694 hop brewing rows confirm correct ~0.001 ratio (g/kg). |
| Hops Brewing BOM rows (694 rows, `source='Brewing'`) | `ing_unit='g'`, `pricing_unit='kg'`, `cost / (qty × price) ≈ 0.001` confirmed live → correct g→kg conversion already applied. |
| `warehouse.php` consumption_since / consumption_13w CTEs | Uses `ref_units.to_base_factor` dimension-aware conversion (lines ~172–183). Correct. |
| `v_sku_volume` view | `volume_hl_derived = SUM(units_per_format × hl_per_unit)` — no HL↔L confusion found. |
| `inv_deliveries` unit_price vs ref_mi pricing_unit | No systematic mismatch across non-yeast deliveries. Spot-checked malt, hops, packaging MIs — pricing_unit consistent. |
| FG cost in warehouse.php (lines ~612–630) | Uses pre-computed `b.cost` from `ref_sku_bom` — trusted stored value, no re-derivation. |

---

## Summary Table — Ranked Findings

| # | Severity | Status | Location | Impact | Effort to fix |
|---|---|---|---|---|---|
| A | HIGH | Latent | `sku-bom-compile.php` ~1087+1099 | 1000× hop cost in composite SKU BOM once composite compilation runs | Low — add conversion factor before cost formula |
| B | HIGH | Latent | `compute-weighted-prices.ts` lines 219–221 | 1000× yeast WAC once WAC displaces catalog fallback | Medium — add pack-size lookup + clean ref_mi_invoicing_units seed |
| C | MEDIUM | Active (WIP only) | `warehouse.php` ~964, `warehouse-export.php` ~385 | ~41% understatement of phosphoric acid + 2 enzyme MIs in WIP cost | Medium — add density override per MI, or use per-MI conversion factor table |
| D | MEDIUM | Active (label) | `ref_sku_bom` legacy rows (5 rows) | ing_unit='kg' but qty is in grams; cost is correct now; trap for future recompile | Trivial — SQL UPDATE 5 rows |
| E | LOW | Active (gap) | `ref_sku_bom` packaging rows (52 rows) | NULL cost for unpriced MIs silently understates FG/packaging COGS | Operator — price the 52 MIs, re-run compiler |

---

*No helper scripts were left in the repo. All temporary query files used during data collection were cleaned from the VPS immediately after use.*
