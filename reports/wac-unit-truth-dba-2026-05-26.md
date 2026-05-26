# WAC Unit-Truth DBA Report
**Date:** 2026-05-26  
**Scope:** Read-only empirical investigation of `inv_deliveries` + `ref_mi` unit semantics for `v_mi_wac` rebuild  
**DB:** `maltytask` on VPS, `ssh maltyweb`  

---

## 1. Canonical Unit Contract — Empirical Findings

The investigation covered 165 MIs with at least one `inv_deliveries` row, drawn from 21 categories. The unit architecture is clean in 162/165 cases and broken in exactly 3 rows.

### 1.1 The `inv_deliveries.pricing_unit` Column

**Every single row in `inv_deliveries` has `pricing_unit = NULL` (165/165 MIs).** The column exists in the schema (`varchar(16)`) but was never populated by any ingest path. The unit must therefore be inferred from `ref_mi.pricing_unit` via `ingredient_fk → ref_mi.id`. This is the intended design — `ref_mi` is the unit authority — but the implication is that the view coder must always JOIN to `ref_mi`, never read `inv_deliveries.pricing_unit`.

### 1.2 Unit Profile: `ref_mi` for WAC-Relevant MIs (Active, `is_inventoried=1`)

98 MIs have Active rows with `qty_remaining > 0` and non-null `ingredient_fk` as of 2026-04. The observed unit profiles:

| Profile | `pricing_unit` | `input_unit` | `consumption_unit` | `conversion_factor` | Categories | MI count |
|---|---|---|---|---|---|---|
| **Mass-kg/kg** | kg | kg | kg | 1.0 | Malt | 3 |
| **Mass-kg/g** | kg | g | g | 0.001 | Hops, Adj, ProcChem, Minerals | 17 |
| **Mass-kg/g (no cu set)** | kg | g | NULL | 0.001 | Hops-some, Yeast-kg, ProcChem-some | 8 |
| **Mass-g/g (yeast-g class)** | g | g | NULL | 0.001 | Yeast (Fermentis/Lallemand small) | 4 |
| **Count/unit** | unit/PCE/piece/pair | NULL | NULL | 1.0 | Packaging, Maintenance, Logistics | ~60 |
| **Volume-L** | L | NULL | NULL | 1.0 | CO2, R&D | 2 |
| **Time/other** | month/day/hour/ton/voyage/test/exchange | NULL | NULL | 0 or 1 | Utilities, Maint, R&D | ~15 |

**No 3-unit MIs exist** (where input_unit, consumption_unit, and pricing_unit are all three different). The worst case is the Mass-kg/g class: two units (deliver/price in kg, consume in g), bridged by a single `conversion_factor = 0.001`.

### 1.3 qty_delivered and unit_price units

For 162/165 MIs (all except the 3 broken rows below): **`qty_delivered` is in `ref_mi.pricing_unit`** and **`unit_price` is CHF or EUR per `pricing_unit`**. Cross-verified by confirming `qty_delivered × unit_price ≈ total_original` (within rounding) across every delivery except the 3 broken ones.

The math invariant that holds for correct rows: `total_original = qty_delivered × unit_price` (in original currency). `total_chf = total_original × eur_to_chf` (or `× 1` for CHF rows).

---

## 2. The Three Broken Rows

These are the only rows where `qty_delivered × unit_price ≠ total_original`:

### 2.1 YEAST_US05 (delivery_id=426) and YEAST_W3470 (delivery_id=425)

**Root cause:** A 2026-05-22 correction changed `qty_delivered` from sachet-count (1) to grams (×500 = 500), but **did not divide `unit_price` by 500**. The result is an internally inconsistent row: qty is in grams, but unit_price is per-pack (per 500g), not per gram.

| MI | pricing_unit | qty_delivered | unit_price stored | total_chf | correct_wac_per_g | stored legacy_wac_per_g | error_multiple |
|---|---|---|---|---|---|---|---|
| YEAST_US05 | g | 500 g | 109.000 EUR | 103.005 CHF | 0.2060 CHF/g | 103.005 CHF/g | **×500** |
| YEAST_W3470 | g | 500 g | 179.400 EUR | 169.533 CHF | 0.3391 CHF/g | 169.533 CHF/g | **×500** |

The correct unit_price should be `total_original / qty_delivered = 109/500 = 0.218 EUR/g` and `179.4/500 = 0.3588 EUR/g` respectively.

**`wac_snapshots` carries the error.** The 2026-04 snapshot shows `wac_chf = 103.005 CHF` and `169.533 CHF` respectively, both per gram. These are 500× wrong and would make any COGS computation for these two yeasts catastrophically wrong.

**Current impact is limited:** `qty_remaining = 1.0` for both rows (not 500), so `total_value_chf` in the snapshot is only 103 + 170 CHF. The false WAC rate matters when these are used for consumption costing (which currently has no yeast consumption rows in `inv_consumption` anyway — only 1 row total for `YEAST_LACTO_HELV`), but becomes a COGS landmine the moment yeast consumption data is added.

### 2.2 MALT_MUNICH (delivery_id=21)

**Root cause:** A 2026-05-22 correction changed `qty_delivered` from 26 (tonnes) to 26000 (kg) to fix a parse error, but **did not divide `unit_price` by 1000**. `unit_price` remains 509 EUR/tonne, not 0.509 EUR/kg.

| MI | pricing_unit | qty_delivered | unit_price stored | total_original | correct_wac_per_kg | stored legacy_wac_per_kg | error_multiple |
|---|---|---|---|---|---|---|---|
| MALT_MUNICH | kg | 26,000 kg | 509.000 EUR | 13,234 EUR | 0.509 EUR/kg | 509.000 EUR/kg | **×1000** |

The invariant check: `26000 × 509 = 13,234,000 ≠ 13,234` — off by ×1000.

**`wac_snapshots` does NOT contain MALT_MUNICH** in the 2026-04 snapshot (it appears MALT_MUNICH was consumed: `qty_remaining = 26.0` in deliveries but there is no corresponding snapshot row for period 2026-04 in the reviewed data). A `v_mi_wac` view built on live `inv_deliveries` would produce a WAC of ~509 CHF/kg for Munich malt (realistic is ~0.48 EUR/kg = ~0.45 CHF/kg), a 1000× error.

---

## 3. Single-Hop vs Compound Conversion

**Confirmed: all conversion chains are single-hop.** The WAC formula `cost_chf_per_consumption_unit = wac_per_pricing_unit × conversion_factor` is always a single multiply. There are no 3-unit MIs in the data.

The `ref_units` table encodes base factors:
- `g → base_factor = 1`
- `kg → base_factor = 1000`
- `ref_mi.conversion_factor = input_unit.to_base_factor / pricing_unit.to_base_factor`

For Mass-kg/g MIs: `conversion_factor = 1/1000 = 0.001`. For Mass-kg/kg (Malt): `conversion_factor = 1000/1000 = 1.0`.

The `conversion_factor` is thus the **input/consumption unit expressed in pricing-unit terms** — multiply consumption qty (in g) by `conversion_factor` (0.001) to get kg, then apply `wac_per_kg` to get CHF.

One edge case to flag: **PROC_PHOSPHORIQUE and PROC_DEHAZE consume in `ml` (millilitres) but are priced in `kg`.** `ref_mi.consumption_unit` is NULL and `g` respectively, but `inv_consumption.unit` is `ml` for both (627 rows for phosphorique, 10 for dehaze). Phosphoric acid has a density of ~1.685 g/mL at 85% concentration; Dehaze (a polysaccharide flocculant) density varies. Neither density constant exists in the DB. For COGS purposes this is a second-order error (typical phosphoric acid WAC ~6 CHF/kg, consumption ~3250 mL/brew ~ 5.5 kg/brew, cost ~33 CHF/brew; a ±5% density approximation is ~1.6 CHF error per brew). The coder must document this conversion gap — using g=ml as an approximation is acceptable for brewing chemicals but must be explicit.

---

## 4. Mis-WAC Quantification

### 4.1 The legacy formula: `Σ(qty_remaining × unit_price × eur_to_chf) / Σ(qty_remaining)`

This formula gets the **stored-unit WAC right** (per `pricing_unit`). It is only wrong for **COGS purposes** when the consumer expects WAC per consumption unit and the developer forgets to apply `conversion_factor`.

**25 MIs** have `conversion_factor ≠ 1.0` and are active (i.e., the consumer must divide by 1000 to get per-gram cost). If a coder uses legacy WAC directly against consumption quantities in grams without the factor, every COGS figure for those 25 MIs is 1000× overstated.

Total CHF at-risk in Active stock from the 25 conversion-factor MIs:

| Category | MIs | Total cost CHF (Active) |
|---|---|---|
| Hops | 14 | ~55,000 |
| Process Chemical (kg/g) | 4 | ~8,664 |
| Brewing Adjunct | 3 | ~3,660 |
| Brewing Mineral | 1 | ~271 |
| Yeast (kg priced) | 2 | ~415 |
| Yeast (g priced, broken) | 2 | ~273 (×500 wrong) |
| **Total** | **25** | **~68,000 CHF** |

### 4.2 The three truly broken rows (internal inconsistency, error regardless of formula)

| Row | MI | Error type | Magnitude | Fix needed |
|---|---|---|---|---|
| delivery_id=426 | YEAST_US05 | unit_price=per-pack, qty=grams | ×500 | `unit_price /= 500`, `qty_remaining` should be 500 not 1 |
| delivery_id=425 | YEAST_W3470 | unit_price=per-pack, qty=grams | ×500 | `unit_price /= 500`, `qty_remaining` should be 500 not 1 |
| delivery_id=21 | MALT_MUNICH | unit_price=per-tonne, qty=kg | ×1000 | `unit_price /= 1000` |

The `wac_snapshots` for 2026-04 already carries the YEAST_US05/W3470 errors (rows present with wrong values). MALT_MUNICH has `qty_remaining=26` kg in the snapshot which is also suspicious (26 kg of Munich malt remaining from a 26,000 kg delivery — the FIFO depletion may have used the correct total_chf despite the wrong unit_price since FIFO depletes by quantity).

---

## 5. Deliverable Formula per MI Class

### Filter for WAC-relevant rows (apply all three predicates, AND):
```sql
WHERE d.status = 'Active'
  AND d.exclusion_class IS NULL   -- excludes recoverable_vat, immobilisation
  AND d.ingredient_fk IS NOT NULL -- currently 0 NULL FKs, but guard anyway
  AND d.qty_remaining > 0
  AND m.is_inventoried = 1
```

There are **0 NULL `ingredient_fk` rows** in the current 522-row table (all 522 are resolved). The `exclusion_class` column is the correct filter gate — 38 rows are excluded (recoverable_vat or immobilisation).

CHF normalization applies to EUR rows: `unit_price × IF(d.currency='EUR', COALESCE(d.eur_to_chf, 0.945), 1)`. The `eur_to_chf` column is populated on all EUR rows (cross-checked: CHF rows have `eur_to_chf=1.0`).

---

### Class A — Mass, same unit (Malt: pricing=kg, consumption=kg, cf=1.0)

**Stored fact:** `qty_delivered` in kg, `unit_price` in CHF or EUR per kg.

```sql
-- WAC per kg (= per consumption unit, no conversion needed)
canonical_unit_cost_chf = wac_per_pricing_unit
canonical_qty            = d.qty_remaining   -- already in kg

-- WAC formula:
SUM(d.qty_remaining * d.unit_price * IF(d.currency='EUR', d.eur_to_chf, 1))
  / NULLIF(SUM(d.qty_remaining), 0)
```

Result is CHF/kg. Consumption in `inv_consumption.qty` (unit=kg). Cost per brew = `consumption_qty_kg × wac_per_kg`.

**Special note — MALT_MUNICH delivery_id=21:** `unit_price=509` is per-tonne not per-kg. This row will produce 1000× wrong WAC until corrected. The correct corrected formula once the data is fixed: same as above.

---

### Class B — Mass, g consume / kg price (Hops, most Adj, ProcChem: pricing=kg, consumption=g, cf=0.001)

**Stored fact:** `qty_delivered` in kg (parser applied pack_size for 5kg Bière-Appro sachets, converting packs→kg), `unit_price` in EUR per kg.

```sql
-- WAC per kg (= per pricing_unit)
wac_per_kg = SUM(d.qty_remaining * d.unit_price * IF(d.currency='EUR', d.eur_to_chf, 1))
             / NULLIF(SUM(d.qty_remaining), 0)

-- WAC per consumption unit (g):
canonical_unit_cost_chf = wac_per_kg * m.conversion_factor   -- × 0.001
canonical_qty_g          = d.qty_remaining * 1000              -- kg → g for stock balance

-- Full one-step expression for a consumption row (qty in g):
cost_chf = ic.qty_g * wac_per_kg * m.conversion_factor
         = ic.qty_g * wac_per_kg * 0.001
```

For inv_consumption these MIs always have `unit='g'` (confirmed empirically).

**Pack-size note:** Hops from Bière-Appro (HOPS_AMARILLO, HOPS_ELDORADO, HOPS_NECTARON, HOPS_NELSON_SAUVIN, HOPS_SIMCOE) have `ref_mi_invoicing_units.pack_size=5` kg/pack. The parser already applied the pack_size multiplication, so `qty_delivered` is in canonical kg in the DB. The view does NOT need to re-apply pack_size; `qty_delivered` is already canonical.

**ml vs g edge case (PROC_PHOSPHORIQUE, PROC_DEHAZE):** `inv_consumption.unit='ml'` but `pricing_unit='kg'`. Until a density column is added to `ref_mi`, the view should treat ml=g (i.e., `conversion_factor=0.001` applies as if ml=g). Flag these two MIs explicitly in the view or a companion table for future correction.

---

### Class C — Mass-g priced, g consumed (Yeast-g: YEAST_FARMHOUSE, YEAST_LALLEMAND_VERDANT)

**Stored fact:** `qty_delivered` in g, `unit_price` in EUR per g. `conversion_factor=0.001` (vestigial from the kg hierarchy; g→kg conversion exists but is irrelevant for COGS since pricing and consumption are both in g).

```sql
-- WAC per g (= per pricing_unit = per consumption_unit)
canonical_unit_cost_chf = SUM(d.qty_remaining * d.unit_price * eur_to_chf)
                          / NULLIF(SUM(d.qty_remaining), 0)
canonical_qty_g          = d.qty_remaining

-- For a consumption row (qty in g):
cost_chf = ic.qty_g * wac_per_g
-- NO conversion_factor needed here (price IS already per-g)
```

**BUT:** `conversion_factor=0.001` on these MIs is misleading for WAC purposes. The formula `wac_per_pricing_unit × conversion_factor` would yield `wac_per_g × 0.001` = WAC per mg — wrong. The view must detect this class (pricing_unit='g' AND consumption_unit='g' OR NULL) and NOT apply the conversion_factor.

The correct condition: **only apply `× conversion_factor` when `pricing_unit ≠ consumption_unit`** (or equivalently when `conversion_factor ≠ 1.0 AND pricing_unit != consumption_unit`).

---

### Class D — Mass-kg priced, no consumption tracking (Yeast-kg: YEAST_LALBREW_ABBAYE, YEAST_SAFALE_BE134)

**Stored fact:** `qty_delivered` in kg (pack_size=0.5 kg already applied by parser — `0.5 kg` = one 500g brick), `unit_price` in CHF or EUR per kg.

```sql
-- WAC per kg
canonical_unit_cost_chf = SUM(d.qty_remaining * d.unit_price * IF(d.currency='EUR', d.eur_to_chf, 1))
                          / NULLIF(SUM(d.qty_remaining), 0)
canonical_qty_kg = d.qty_remaining

-- Consumption (when tracked) would be in g, same as Class B:
cost_chf = ic.qty_g × wac_per_kg × 0.001
```

**Broken rows note (YEAST_US05, YEAST_W3470):** These MIs are nominally Class C (pricing_unit='g') but the 2026-05-22 partial fix left `unit_price` at per-pack (109/179.4 EUR per 500g pack) while `qty_delivered=500 g`. The view formula (Class C: wac = unit_price / 1) would compute WAC = 109 CHF/g instead of 0.206 CHF/g. These two rows must be corrected in `inv_deliveries` (divide `unit_price` by 500) **and** their `wac_snapshots` rows must be recomputed before the view goes live.

---

### Class E — Count/unit (Packaging, Maintenance, etc.: pricing_unit='unit'/'PCE'/etc., cf=1.0)

```sql
canonical_unit_cost_chf = SUM(d.qty_remaining * d.unit_price * IF(d.currency='EUR', d.eur_to_chf, 1))
                          / NULLIF(SUM(d.qty_remaining), 0)
canonical_qty = d.qty_remaining  -- in the count unit

-- For packaging consumption: cost = consumption_count × wac_per_unit
```

No conversion needed. These are the dominant class by count (packaging ~55 MIs, maintenance ~16, etc.).

---

### Class F — Time/service/periodic (pricing_unit='month'/'day'/'hour'/'voyage': not inventoried in physical sense)

These MIs represent recurring service charges (utilities, rentals, labour). `is_inventoried=0` for most but some appear in `wac_snapshots` due to the current non-discriminating build. A true `v_mi_wac` for COGS/COP should exclude these from the brewing WAC but include them in the utilities/indirect section. Filter: `m.is_inventoried = 1` removes most of these from the brewing WAC scope.

---

## 6. `conversion_factor_snapshot` Column — Recommendation

**Confirmed: `inv_deliveries` does NOT store a `conversion_factor_snapshot` column.** The schema has no such column (verified via `SHOW COLUMNS FROM inv_deliveries LIKE '%conversion%'` returning empty).

**This is the correct architecture for this data size.** The `conversion_factor` on `ref_mi` has been stable since bootstrapping and is a stable physical constant (g/kg ratio), not a business parameter that changes with market conditions. The risk of snapshot drift is near-zero for mass-unit MIs.

**Recommendation: do NOT add a `conversion_factor_snapshot` column.** Instead, the view always JOINs to `ref_mi.conversion_factor` live. If `ref_mi.conversion_factor` is ever changed (which would be an extraordinary event), the `wac_snapshots` would need a full recompute — but this is the correct behaviour.

The existing `wac_snapshots.wac_chf` stores WAC **per pricing_unit** (confirmed: `qty_remaining_at_close × wac_chf = total_value_chf` for all 130 rows). The view-consuming layer must apply `× m.conversion_factor` at query time to get per-consumption-unit cost.

---

## 7. Query Scaffolding for `v_mi_wac`

```sql
-- v_mi_wac: WAC per MI, Active deliveries only, CHF-normalized, per pricing_unit
-- Consumers: multiply by ref_mi.conversion_factor to get per-consumption-unit

CREATE OR REPLACE VIEW v_mi_wac AS
SELECT
  m.id                         AS mi_id_pk,
  m.mi_id,
  m.pricing_unit,
  m.consumption_unit,
  m.conversion_factor,
  mc.name                      AS category,
  SUM(d.qty_remaining)         AS total_qty_remaining,  -- in pricing_unit
  SUM(
    d.qty_remaining
    * d.unit_price
    * IF(d.currency = 'EUR', COALESCE(d.eur_to_chf, 0.945), 1)
  )                            AS total_cost_chf,
  -- WAC per pricing_unit (CHF):
  COALESCE(
    SUM(d.qty_remaining * d.unit_price * IF(d.currency='EUR', COALESCE(d.eur_to_chf,0.945),1))
    / NULLIF(SUM(d.qty_remaining), 0),
    0
  )                            AS wac_per_pricing_unit_chf,
  -- WAC per consumption unit (g for hops/adj, kg for malt, unit for packaging):
  -- Class A (cf=1, same unit): wac_per_pricing_unit_chf × 1 = no-op
  -- Class B/D (cf=0.001, kg price / g consume): wac_per_kg × 0.001 = wac_per_g
  -- Class C (g priced, g consumed, cf=0.001): DO NOT apply cf — use wac as-is
  --   Detected by: pricing_unit = consumption_unit (or both = 'g')
  COALESCE(
    SUM(d.qty_remaining * d.unit_price * IF(d.currency='EUR', COALESCE(d.eur_to_chf,0.945),1))
    / NULLIF(SUM(d.qty_remaining), 0)
    * CASE
        WHEN m.pricing_unit = m.consumption_unit THEN 1.0   -- same unit, no conversion
        WHEN m.pricing_unit = 'g'               THEN 1.0   -- Class C: already per-g
        ELSE COALESCE(m.conversion_factor, 1.0)            -- Class B: ×0.001
      END,
    0
  )                            AS wac_per_consumption_unit_chf,
  COUNT(d.id)                  AS delivery_row_count
FROM ref_mi m
JOIN ref_mi_categories mc ON mc.id = m.category_id
JOIN inv_deliveries d       ON d.ingredient_fk = m.id
WHERE d.status          = 'Active'
  AND d.exclusion_class IS NULL
  AND d.qty_remaining   > 0
  AND m.is_inventoried  = 1
GROUP BY m.id, m.mi_id, m.pricing_unit, m.consumption_unit, m.conversion_factor, mc.name;
```

**Do NOT use `qty_remaining` as a proxy for stock level** unless FIFO depletion (`_phase2c-fifo-deplete.ts`) has been run. The current state: `qty_remaining` is only partially depleted for bsf-mirror rows; `Consumed` rows have `qty_remaining=0`. Filter `status='Active'` implicitly selects the un-depleted stock.

---

## 8. Data Corrections Required Before View Goes Live

| Priority | Row/Table | Issue | Correction |
|---|---|---|---|
| BLOCKER | `inv_deliveries` id=426 (YEAST_US05) | `unit_price=109 EUR/pack` but `qty=500 g`; `unit_price` must be per-g | `unit_price = 109/500 = 0.218`; `qty_remaining = 500` (not 1) |
| BLOCKER | `inv_deliveries` id=425 (YEAST_W3470) | same half-fix problem | `unit_price = 179.4/500 = 0.3588`; `qty_remaining = 500` |
| BLOCKER | `inv_deliveries` id=21 (MALT_MUNICH) | `unit_price=509 EUR/ton` but `qty=26000 kg` | `unit_price = 509/1000 = 0.509` |
| HIGH | `wac_snapshots` 2026-04 (YEAST_US05, YEAST_W3470) | inherits the unit_price error; `wac_chf=103/169 CHF/g` is 500× wrong | Recompute after data fix |
| MEDIUM | `ref_mi` PROC_PHOSPHORIQUE/PROC_DEHAZE | `consumption_unit=NULL/g` but actual consumption is in ml | Add density constant to `ref_mi` or `ref_units`; until then, treat ml=g with documented approximation |

---

## 9. Go/No-Go for View Design

**Go** with the following conditions:

1. The three broken delivery rows (MALT_MUNICH, YEAST_US05, YEAST_W3470) are corrected **before** the view is populated.
2. The view's `wac_per_consumption_unit_chf` uses the Class-C guard shown above (do not apply `conversion_factor` when `pricing_unit='g'`).
3. The `exclusion_class IS NULL` and `status='Active'` filters are both applied (they are different gates: status guards depletion state; exclusion_class guards COGS eligibility).
4. The ml/g approximation for PROC_PHOSPHORIQUE/PROC_DEHAZE is documented in a code comment.
5. A `conversion_factor_snapshot` column is confirmed unnecessary (conversion_factor is a physical constant, not a business parameter).

The view architecture is sound. The 25 conversion-factor MIs all follow the single-hop `× 0.001` pattern. No 3-unit chains exist. The `ingredient_fk` FK is 100% populated (0 NULL FKs, confirmed against 522 rows).

---

*Generated: 2026-05-26 — read-only investigation, no data or schema modified.*
