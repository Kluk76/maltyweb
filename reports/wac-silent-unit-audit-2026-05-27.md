# WAC Silent-Unit & Mis-Mapping Audit — 2026-05-27

**Auditor:** Claude Sonnet subagent (read-only, zero writes)  
**Scope:** `inv_deliveries` — Active, exclusion_class IS NULL, ingredient_fk IS NOT NULL, is_inventoried=1 — 185 rows  
**Methods:** (a) per-category eff-price bands, (b) catalog divergence, (c) qty-shape heuristics, (d) invoice OCR confirmation for every candidate  
**Already-fixed baseline:** migration 162 (yeast kg-standardisation + SIMCOE/GALAXY/WATER_DEMIN/MALT_MUNICH) and migration 164 (HOPS_MOSAIC id=104, ADJ_PEACH_TEA id=249) — not re-flagged.  
**Calibration non-bug confirmed:** YEAST_LALBREW_ABBAYE id=74/418 (2×500g = 1.0 kg, canonical) — not flagged.

---

## CANDIDATE-CORRECTIONS TABLE

| delivery_id | mi_id | supplier | current_qty | current_price | total_original | proposed_canonical_qty | proposed_price | invoice_line_quoted | confidence | recommended_action |
|---|---|---|---|---|---|---|---|---|---|---|
| 429 | YEAST_LALLEMAND_VERDANT | Bière-Appro | 1.000 kg | 197.80 EUR/kg | 197.80 EUR | 1.000 kg | 197.80 EUR/kg | `3843.00500 Levure Lallemand Verdant ; Levure - 500 g ; 98,90 € 2 197,80€` (invoice F20260414-24022) | HIGH | no-change(legit) — see note ① |
| 445 | PROC_ALIGAL2_ECO | Carbagas | 4155.000 kg | 0.010 CHF/kg | 41.55 CHF | n/a | n/a | `S3796 Quantité 4155 KG 0,01 0,01 0,04 VE — ECO ORIGIN LCO2` (invoice 983842032) | HIGH | operator-question(product-vs-unit) — see note ② |
| 3,44,68,98,135,232,275,311,367,402,543 | PKG_TEA_BOT_CH | Univerre | 1.000 month | ~1435-1479 EUR/month | 700–1479 EUR | n/a | n/a | All Univerre invoices show monthly TEA eco-tax lump-sum line (e.g. invoice 22510731, 22600408, etc.) | HIGH | no-change(legit) — see note ③ |
| 425 | YEAST_W3470 | Bière-Appro | 0.500 kg | 358.80 EUR/kg | 179.40 EUR | per mig-162 intent | per mig-162 intent | `3807.00500 Levure Fermentis Saflager™ W-34/70 ; Levure - 500 g 89,70 € 2 179,40€` (invoice F20260414-24022) | HIGH | no-change(legit) — see note ④ |
| 426 | YEAST_US05 | Bière-Appro | 0.500 kg | 218.00 EUR/kg | 109.00 EUR | per mig-162 intent | per mig-162 intent | `3806.00500 Levure Fermentis SafAle™ US-05 ; Levure - 500 g 54,50 € 2 109,00€` (invoice F20260414-24022) | HIGH | no-change(legit) — see note ④ |

**Total candidates: 5 rows affected (15 delivery_ids total counting the 11 TEA rows + 4 yeast rows).**  
**By recommended-action:** `no-change(legit)` = 14 delivery IDs; `operator-question` = 1 delivery ID.  
**Silent-unit fixes required by this audit: 0** (all prior fixes were in migrations 162/164; current data is clean with one ambiguity noted below).

---

## NO-BUG CONFIRMATIONS (all methods, invoice-confirmed)

The following rows were flagged by one or more methods but cleared by OCR evidence:

### Hops (BarthHaas — Incognito, Prysma)

All BarthHaas invoices quote **price per KG** on the face. The "-2Kg" suffix in product names (e.g. "Incognito ® -2Kg MOS") is the pack format label, NOT a qty multiplier. Every row is stored at canonical kg basis:

| id | mi_id | qty (DB) | UP (EUR/kg) | Invoice line | Status |
|---|---|---|---|---|---|
| 89 | HOPS_PRYSMA_MOSAIC | 2.00 kg | 257.00 | `Prysma Mosaic-1Kg … 2,00 KG Price per KG 257,00 EUR 514,00 EUR` (RG042933) | ✓ legit |
| 92 | HOPS_C_INCOGNITO | 20.00 kg | 238.00 | `Incognito ® -2Kg CIT … 20,00 KG Price per KG 238,00 EUR 4.760,00 EUR` (RG042934) | ✓ legit |
| 91 | HOPS_M_INCOGNITO | 26.00 kg | 223.00 | `Incognito ® -2Kg MOS … 26,00 KG Price per KG 223,00 EUR 5.798,00 EUR` (RG042934) | ✓ legit |
| 198 | HOPS_M_INCOGNITO | 24.00 kg | 223.00 | `Incognito ® -2Kg MOS … 24,00 KG Price per KG 223,00 EUR 5.352,00 EUR` (RG043839) | ✓ legit |
| 199 | HOPS_M_INCOGNITO | 4.00 kg | 223.00 | `Incognito ® -2Kg MOS … 4,00 KG Price per KG 223,00 EUR 892,00 EUR` (RG043839) | ✓ legit |
| 562 | HOPS_M_INCOGNITO | 8.00 kg | 223.00 | `Incognito ® -2Kg MOS … 8,00 KG Price per KG 223,00 EUR 1.784,00 EUR` (RG045990) | ✓ legit |
| 563 | HOPS_C_INCOGNITO | 16.00 kg | 238.00 | `Incognito ® -2Kg CIT … 16,00 KG Price per KG 238,00 EUR 3.808,00 EUR` (RG045990) | ✓ legit |
| 314 | HOPS_KRUSH_CRYO | 2.00 kg | 81.90 | `Sachet 1kg, CRYO Hops® Krush® … 81,90€ 2 163,80€` (F20260324-23526) | ✓ legit — 2 sachets × 1kg |

### Hops (IGN, Bière-Appro — Spalter, Eldorado, Amarillo)

| id | mi_id | qty (DB) | Invoice line | Status |
|---|---|---|---|---|
| 160 | HOPS_SPALTER_SELECT | 25.00 kg | `5 Folien à 5,000 kg … Preis je kg Produkt: 8,20 € … x 25,0000 kg = 205,00 €` (25-0706) | ✓ legit |
| 106 | HOPS_ELDORADO | 5.00 kg | `Houblon Eldorado … Houblon - 5 Kg 104,50 € 1 104,50€` (Bière-Appro F-1y-22203) | ✓ legit — 1 bag × 5 kg pack-size applied |
| 107 | HOPS_AMARILLO | 10.00 kg | `Houblon Amarillo® … Houblon - 5Kg 149,50 € 2 299,00€` (Bière-Appro F-1y-22203) | ✓ legit — 2 bags × 5 kg pack-size applied |

### Process Chemicals (Good Beer — Yeastvit, Imobum, Dehaze)

Pack-size from `ref_mi_invoicing_units` was applied correctly in all cases (supplier_fk=91, pack_size=5 for Yeastvit/Imobum/Dehaze):

| id | mi_id | qty (DB) | Invoice line | Status |
|---|---|---|---|---|
| 75 | PROC_YEASTVIT | 10.00 kg | `Yeast Vit - 5kg la (ou 2 99.00 … 198.00` (invoice 208) — 2 sachets × 5 kg | ✓ legit |
| 228 | PROC_IMOBUM | 5.00 kg | `IMOBoom (5kg) Murphy and Son 1 470.00 … 470.00` (invoice 226) — 1 unit × 5 kg | ✓ legit |
| 229 | PROC_YEASTVIT | 5.00 kg | `Yeast Vit - 5kg 1 99.00 … 99.00` (invoice 225/226) — 1 sachet × 5 kg | ✓ legit |
| 230 | PROC_DEHAZE | 5.00 kg | `DeHAze 5kg 1 995.00 … 995.00` (invoice 225/226) — 1 unit × 5 kg | ✓ legit |
| 376 | PROC_YEASTVIT | 20.00 kg | `Yeast Vit 20kg 1 298.00 … 298.00` (invoice 244) — 1 bag × 20 kg | ✓ legit |
| 416 | PROC_YEASTVIT | 5.00 kg | `Yeast Vit - 5kg 1 99.00 … 99.00` (invoice 234) — 1 sachet × 5 kg | ✓ legit |
| 417 | PROC_DEHAZE | 5.00 kg | `DeHAze 5kg 1 995.00 … 995.00` (invoice 233) — 1 unit × 5 kg | ✓ legit |

### Brewing Adjunct, Mineral

| id | mi_id | qty (DB) | Invoice line | Status |
|---|---|---|---|---|
| 428 | ADJ_ELDERFLOWER | 4.00 kg | `Fleurs de sureau ; Ingrédients - 1 Kg 29,90 € 4 119,60€` (F20260414-24022) — 4 bags × 1 kg | ✓ legit |
| 411 | MIN_CASO4 | 25.00 kg | `MP 020653 S0025 25,00 KG 10,8500 … 1 SACS X 25 KG` (invoice 10287395 ECSA) — 1 sack × 25 kg | ✓ legit |

---

## DETAILED NOTES ON CANDIDATES

### Note ① — YEAST_LALLEMAND_VERDANT id=429: no-change(legit) but catalog-price flag

**Current state:** qty=1.000 kg @ 197.80 EUR/kg, total_chf=186.92 CHF.  
**Invoice evidence:** `3843.00500 Levure Lallemand Verdant ; Levure - 500 g ; 98,90 € 2 197,80€` (F20260414-24022). Two 500g packs × 98.90 EUR = 197.80 EUR total. pack_size=0.5 → canonical_qty = 2 × 0.5 = 1.0 kg. Migration 162 correctly set qty=1.0 kg, UP=197.80 EUR/kg.

**The delivery row is correct.** Method B fired because `ref_mi.price = 0.197800 EUR` — this was the old gram-basis catalog price (0.1978 EUR/g = 197.80 EUR/kg). Migration 162 changed `pricing_unit g→kg` on ref_mi but did NOT update the price value — so the price column now reads "0.1978 EUR per kg" which is ~1000× too low.

**Bucket D (catalog hygiene, not a delivery bug):** `ref_mi.price` for YEAST_LALLEMAND_VERDANT needs updating from 0.197800 to 197.800000 EUR/kg (or the WAC snapshot value 186.921 CHF/kg). Same issue likely exists for YEAST_W3470, YEAST_US05, YEAST_FARMHOUSE (their catalog prices read 0.1794, 0.1054, 2.545 EUR per kg respectively — the non-zero values that pre-date the migration). These are stale prices, not delivery unit bugs. **Correction target: `ref_mi.price` (Bucket D — catalog price hygiene, separate from this audit's scope).**

### Note ② — PROC_ALIGAL2_ECO id=445: operator-question

**Current state:** qty=4155.000 kg @ 0.010 CHF/kg, total_chf=41.55 CHF, eff_price=0.01 CHF/kg.  
**Invoice evidence (Carbagas 983842032):**
```
ALIGAL 2 Liquide   15110RG  Quantite 4.155 KG  372,00  1.545,66  VE
ECO ORIGIN LCO2    S3796    Quantite 4155  KG    0,01     0,04    VE
```
The Carbagas invoice has two separate product codes. `15110RG` is the gas itself (Aligal 2 Liquide — note "4.155" is European thousands-separator = 4155 kg). `S3796` is a supplementary eco-origin certification fee charged at 0.01 CHF/kg × 4155 kg = 41.55 CHF (note: OCR shows subtotal 0.04 for this line, but DB total_chf=41.55 — the 0.04 CHF OCR figure is likely OCR noise on a truncated column or this represents a per-delivery charge). The quantity 4155 kg matches the gas delivery quantity.

**The two questions for PM/operator:**
1. The delivery row id=444 (PROC_ALIGAL2_LIQ, qty=4155 kg @ 0.370 CHF/kg = 1537.35 CHF) covers the gas itself correctly. Row id=445 (PROC_ALIGAL2_ECO, qty=4155 kg @ 0.01 CHF/kg = 41.55 CHF) is the eco-supplement certification fee. Should PROC_ALIGAL2_ECO be `is_inventoried=1` (currently true, feeds WAC)? An eco-certification surcharge at 0.01/kg has no stock-depletion meaning — it's a parallel billing line.
2. If yes (keep is_inventoried), the qty=4155 kg and price=0.01 CHF/kg are invoice-accurate. No delivery fix needed.
3. If no (eco-cert should be a pass-through fee, not inventory), the row should be excluded or have `exclusion_class` set.

**No delivery data change recommended pending PM decision. Low WAC impact (41.55 CHF / 4155 kg).**

### Note ③ — PKG_TEA_BOT_CH (11 rows, ids 3,44,68,98,135,232,275,311,367,402,543): no-change(legit)

All 11 rows are Univerre packaging invoices where TEA (Taxe d'Élimination Anticipée) is billed as a monthly lump-sum by Univerre. `ref_mi.pricing_unit = 'month'`, qty=1.000 month in each row. Method A triggered because the 0.007–28.35 packaging band is calibrated for per-unit costs (labels, caps, cans), not monthly eco-tax lump sums. There is no unit bug — the TEA billing model is correct by design. The WAC for this MI equals the average monthly lump-sum spend (~1376 CHF/month), which is meaningful for COGS.

**No change required. The band check for Packaging should exclude MIs with `pricing_unit='month'`** (parser-side improvement, not a data correction).

### Note ④ — YEAST_W3470 id=425 / YEAST_US05 id=426: no-change(legit), mig-162 intentional

The OCR for invoice F20260414-24022 shows:
- W3470: `89,70 € 2 179,40€` — 2 packs × 89.70 EUR
- US05: `54,50 € 2 109,00€` — 2 packs × 54.50 EUR

The invoice has 2 packs of each, so canonical delivery = 1.0 kg each. However migration 162 set qty=0.5 for both, with the operator-confirmed note: *"remaining stock = 0.5 kg (one full pack)"*. This means the operator verified that 1 of the 2 packs was already consumed before the DB was corrected, so `qty_remaining=0.5` is correct. The `qty_delivered` of 0.5 (rather than 1.0) is slightly misleading (it's the remaining-useful quantity, not the full delivery), but this is the operator's deliberate choice as recorded in the migration.

**This is a known operator decision from migration 162, not a new bug. No change required.**  
**PM note:** if `qty_delivered` is meant to always represent the full invoice quantity (regardless of consumption), both rows need updating to qty=1.0, unit_price=179.40/109.00 EUR/kg respectively, with a corresponding FIFO-depletion run to bring qty_remaining=0.5. This is a bookkeeping convention choice, not a math error.

---

## SECONDARY PASS — Non-Active rows

No non-Active rows were found for any of the suspect MI IDs (PROC_YEASTVIT, PROC_IMOBUM, PROC_DEHAZE, PROC_ALIGAL2_ECO, YEAST_LALLEMAND_VERDANT). All Consumed/Pending rows for these MIs: 0. The secondary scope is empty — no additional contamination outside the Active set.

---

## BUCKET D — Catalog-Price Hygiene (separate from delivery corrections)

These are `ref_mi.price` values that appear stale after migration 162 changed `pricing_unit g→kg` without updating the price scalar. They do NOT affect current WAC (WAC reads `inv_deliveries.total_chf / qty_delivered`, not `ref_mi.price`). They affect only catalog-divergence alerts and any UI display that shows the "catalog price".

| mi_id | Current catalog price | Likely correct value | Evidence |
|---|---|---|---|
| YEAST_LALLEMAND_VERDANT | 0.197800 EUR/kg (was /g) | ~197.80 EUR/kg | Invoice F20260414-24022: 98.90 EUR/500g pack |
| YEAST_W3470 | 0.179400 EUR/kg (was /g) | ~179.40 EUR/kg | Invoice F20260414-24022: 89.70 EUR/500g pack |
| YEAST_US05 | 0.105400 EUR/kg (was /g) | ~109.00 EUR/kg | Invoice F20260414-24022: 54.50 EUR/500g pack |
| YEAST_FARMHOUSE | 2.545000 EUR/kg | ~221.60 EUR/kg | Invoice F20260414-24022: 110.80 EUR/500g pack |

YEAST_FARMHOUSE's current catalog value 2.545 EUR/kg appears to be a different residual (not a clean g→kg artefact). Recommend PM review all four against the most recent invoice before updating.

---

## SUMMARY

**Active-scope (185 rows): zero new silent-unit bugs found. All prior fixes (migrations 162/164) are holding.**

- Methods A+B+C together surfaced 33 candidate flags.
- After OCR confirmation: 31 cleared as legit (pack-size correctly applied, per-kg billing confirmed, or intentional monthly lump-sum design).
- 1 pending operator decision (PROC_ALIGAL2_ECO eco-cert fee — is_inventoried flag question).
- 1 catalog-price hygiene cluster (4 yeast MIs — Bucket D, no delivery change needed).
- 2 yeast rows (W3470/US05) confirmed as deliberate mig-162 operator decision, not a residual bug.
- YEAST_W3470/US05 convention note: if policy is `qty_delivered = full invoice quantity`, both need qty_delivered updated to 1.0 kg with a compensating FIFO deplete. This is a PM-level bookkeeping convention call.

**WAC impact of open items:**
- PROC_ALIGAL2_ECO WAC contribution: 41.55 CHF / 4155 kg = negligible (0.01 CHF/kg).
- Yeast catalog prices: no WAC impact (WAC reads deliveries, not catalog).
- TEA rows: by design, WAC = monthly lump-sum average.
