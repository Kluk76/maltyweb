-- db/migrations/162_wac_unit_normalization.sql
--
-- What: WAC unit-normalization — corrects 8 inv_deliveries rows where qty or unit_price
--       was stored in non-canonical units, standardises 5 yeast MIs from g→kg in ref_mi,
--       de-drifts 2 ref_mi_invoicing_units rows, and recomputes the 6 contaminated
--       wac_snapshots rows for period 2026-04 under the new total_chf/qty_delivered basis.
--
-- Why: The live v_mi_wac view (migration 163) aggregates across qty_remaining and
--      total_chf/qty_delivered. Rows where qty is in a non-canonical unit produce
--      a wrong eff_unit_price (e.g. 1 pack instead of 5 kg → price ×5 too high).
--      Rows where unit_price is internally inconsistent (qty×unit_price≠total_original)
--      contaminate the old snapshot formula and remain wrong for non-view consumers.
--      This migration fixes both classes and recomputes affected snapshots so the DB
--      is self-consistent before the view is installed.
--
-- Background: See reports/wac-billing-format-audit-2026-05-26.md §10 (locked decisions).
--   total_original and total_chf are NEVER touched — they are invoice truth.
--   Every UPDATE is WHERE-guarded with old values for idempotency.
--
-- Rollback (apply in reverse order if needed):
--   -- wac_snapshots: re-apply the snapshot writer script for period 2026-04
--   --   (npx tsx scripts/_warehouse-compute-wac-snapshot.ts --period=2026-04 --apply
--   --    after reverting the inv_deliveries rows and ref_mi rows below)
--   -- ref_mi_invoicing_units id=12: UPDATE SET pack_size=500.0000 WHERE id=12 AND pack_size=0.5000
--   -- ref_mi_invoicing_units id=13: UPDATE SET pack_size=500.0000 WHERE id=13 AND pack_size=0.5000
--   -- ref_mi (yeast kg→g): UPDATE ref_mi SET pricing_unit='g' WHERE id IN (65,66,194,196,238) AND pricing_unit='kg'
--   -- inv_deliveries Bucket B id=21: UPDATE SET unit_price=509.000000 WHERE id=21 AND unit_price=0.509000
--   -- inv_deliveries Bucket A id=312: UPDATE SET qty_delivered=1, qty_remaining=1, unit_price=179.500000 WHERE id=312 AND qty_delivered=5
--   -- inv_deliveries Bucket A id=105: UPDATE SET qty_delivered=1, qty_remaining=1, unit_price=70.000000 WHERE id=105 AND qty_delivered=5
--   -- inv_deliveries Bucket A id=12: UPDATE SET qty_delivered=4, qty_remaining=4, unit_price=3.123000 WHERE id=12 AND qty_delivered=20
--   -- inv_deliveries Yeast id=426: UPDATE SET qty_delivered=500, qty_remaining=1, unit_price=109.000000 WHERE id=426 AND qty_delivered=0.5
--   -- inv_deliveries Yeast id=425: UPDATE SET qty_delivered=500, qty_remaining=1, unit_price=179.400000 WHERE id=425 AND qty_delivered=0.5
--   -- inv_deliveries Yeast id=427: UPDATE SET qty_delivered=500, qty_remaining=500, unit_price=0.221600 WHERE id=427 AND qty_delivered=0.5
--   -- inv_deliveries Yeast id=429: UPDATE SET qty_delivered=1000, qty_remaining=1000, unit_price=0.197800 WHERE id=429 AND qty_delivered=1.0
--
-- Invariant check (all Bucket A + Yeast rows):
--   id=12:  20 × 0.6246   = 12.4920  = total_original ✓  (CHF, total_chf unchanged)
--   id=312: 5  × 35.9     = 179.5000 = total_original ✓
--   id=105: 5  × 14       = 70.0000  = total_original ✓
--   id=426: 0.5× 218      = 109.0000 = total_original ✓
--   id=425: 0.5× 358.8    = 179.4000 = total_original ✓
--   id=427: 0.5× 221.6    = 110.8000 = total_original ✓
--   id=429: 1.0× 197.8    = 197.8000 = total_original ✓
--   id=21 (Bucket B): qty unchanged=26000; 26000×0.509=13234 = total_original ✓ (price fix only)
--
-- Verified against live DB 2026-05-26. No schema changes in this migration.
-- No schema_meta row needed (162 modifies data, creates no tables).

-- =============================================================================
-- SECTION 1: inv_deliveries — Bucket A (silent-unit qty + price fixes)
--             All rows: status='Active', exclusion_class IS NULL
--             target table: inv_deliveries  (schema_meta corrections_policy='allowed')
-- =============================================================================

-- id=312 HOPS_GALAXY (ref_mi.id=36)
-- qty was stored as 1 (one 5 kg pack); canonical is 5 kg.
-- unit_price was the per-pack price (179.5); canonical is per-kg (35.9).
-- total_original=179.5 unchanged; 5×35.9=179.5 ✓
UPDATE inv_deliveries
   SET qty_delivered = 5.0000,
       qty_remaining = 5.0000,
       unit_price    = 35.900000
 WHERE id            = 312
   AND qty_delivered = 1.0000
   AND qty_remaining = 1.0000
   AND unit_price    = 179.500000
   AND total_original = 179.5000;

-- id=105 HOPS_SIMCOE (ref_mi.id=46)
-- qty was stored as 1 (one 5 kg Bière-Appro pack); canonical is 5 kg.
-- unit_price was the per-pack price (70); canonical is per-kg (14).
-- total_original=70.0 unchanged; 5×14=70 ✓
UPDATE inv_deliveries
   SET qty_delivered = 5.0000,
       qty_remaining = 5.0000,
       unit_price    = 14.000000
 WHERE id            = 105
   AND qty_delivered = 1.0000
   AND qty_remaining = 1.0000
   AND unit_price    = 70.000000
   AND total_original = 70.0000;

-- id=12 QA_WATER_DEMIN (ref_mi.id=277)
-- qty was stored as 4 (bottles); canonical is 20 L (4 × 5 L bottles).
-- unit_price was per-bottle (3.123); canonical is per-L (0.6246).
-- total_original=12.492 unchanged; 20×0.6246=12.492 ✓
UPDATE inv_deliveries
   SET qty_delivered = 20.0000,
       qty_remaining = 20.0000,
       unit_price    = 0.624600
 WHERE id            = 12
   AND qty_delivered = 4.0000
   AND qty_remaining = 4.0000
   AND unit_price    = 3.123000
   AND total_original = 12.4920;

-- =============================================================================
-- SECTION 2: inv_deliveries — Bucket B (gross price fix, qty unchanged)
--             target table: inv_deliveries  (schema_meta corrections_policy='allowed')
-- =============================================================================

-- id=21 MALT_MUNICH (ref_mi.id=1)
-- unit_price was stored as per-ton price (509 EUR/t); canonical is per-kg (0.509 EUR/kg).
-- qty_delivered=26000 kg unchanged; 26000×0.509=13234=total_original ✓
-- qty_remaining=26 (FIFO-depleted by operator) — NOT touched (operator-gated scope).
-- total_chf=12506.13 (already correct) — not touched.
UPDATE inv_deliveries
   SET unit_price = 0.509000
 WHERE id            = 21
   AND qty_delivered = 26000.0000
   AND unit_price    = 509.000000
   AND total_original = 13234.0000;

-- =============================================================================
-- SECTION 3: inv_deliveries — Yeast kg-standardization
--             All 4 yeast delivery rows move from g-basis to kg-basis.
--             qty_delivered and qty_remaining change from g-count to kg-count.
--             unit_price recomputed as total_original / new_qty_delivered (per-kg).
--             total_original and total_chf are untouched (invoice truth).
--             target table: inv_deliveries  (schema_meta corrections_policy='allowed')
-- =============================================================================

-- id=426 YEAST_US05 (ref_mi.id=65)
-- Before: qty_delivered=500 (g), qty_remaining=1 (g), unit_price=109.000000 EUR/g(invalid—leftover from partial fix)
-- After:  qty_delivered=0.5 (kg), qty_remaining=0.5 (kg), unit_price=218.000000 EUR/kg
-- 0.5×218=109=total_original ✓; operator confirmed: remaining stock = 0.5 kg (one full pack).
-- Note: qty_remaining changes from 1→0.5 (g→kg: 1g would be 0.001 kg, but live value 1.0 was
-- the stale g-remnant of the 2026-05-22 partial correction; operator confirmed full 0.5 kg remaining).
UPDATE inv_deliveries
   SET qty_delivered = 0.5000,
       qty_remaining = 0.5000,
       unit_price    = 218.000000
 WHERE id            = 426
   AND qty_delivered = 500.0000
   AND unit_price    = 109.000000
   AND total_original = 109.0000;

-- id=425 YEAST_W3470 (ref_mi.id=66)
-- Before: qty_delivered=500 (g), qty_remaining=1 (g), unit_price=179.400000 EUR/g(invalid)
-- After:  qty_delivered=0.5 (kg), qty_remaining=0.5 (kg), unit_price=358.800000 EUR/kg
-- 0.5×358.8=179.4=total_original ✓; operator confirmed: remaining stock = 0.5 kg.
UPDATE inv_deliveries
   SET qty_delivered = 0.5000,
       qty_remaining = 0.5000,
       unit_price    = 358.800000
 WHERE id            = 425
   AND qty_delivered = 500.0000
   AND unit_price    = 179.400000
   AND total_original = 179.4000;

-- id=427 YEAST_FARMHOUSE (ref_mi.id=194)
-- Before: qty_delivered=500 (g), qty_remaining=500 (g), unit_price=0.221600 EUR/g
-- After:  qty_delivered=0.5 (kg), qty_remaining=0.5 (kg), unit_price=221.600000 EUR/kg
-- 0.5×221.6=110.8=total_original ✓  (live total_original confirmed=110.8000)
UPDATE inv_deliveries
   SET qty_delivered = 0.5000,
       qty_remaining = 0.5000,
       unit_price    = 221.600000
 WHERE id            = 427
   AND qty_delivered = 500.0000
   AND qty_remaining = 500.0000
   AND unit_price    = 0.221600
   AND total_original = 110.8000;

-- id=429 YEAST_LALLEMAND_VERDANT (ref_mi.id=196)
-- Before: qty_delivered=1000 (g), qty_remaining=1000 (g), unit_price=0.197800 EUR/g
-- After:  qty_delivered=1.0 (kg), qty_remaining=1.0 (kg), unit_price=197.800000 EUR/kg
-- 1.0×197.8=197.8=total_original ✓  (live total_original confirmed=197.8000)
UPDATE inv_deliveries
   SET qty_delivered = 1.0000,
       qty_remaining = 1.0000,
       unit_price    = 197.800000
 WHERE id            = 429
   AND qty_delivered = 1000.0000
   AND qty_remaining = 1000.0000
   AND unit_price    = 0.197800
   AND total_original = 197.8000;

-- =============================================================================
-- SECTION 4: ref_mi — Yeast pricing_unit g→kg (5 MIs)
--             consumption_unit stays NULL (consumed in g, conversion_factor=0.001
--             already encodes the bridge: pitched-g → priced-kg).
--             target table: ref_mi  (schema_meta corrections_policy='allowed')
-- =============================================================================

-- ref_mi.id 65 = YEAST_US05, 66 = YEAST_W3470,
-- 194 = YEAST_FARMHOUSE, 196 = YEAST_LALLEMAND_VERDANT, 238 = YEAST_LALLEMAND_POMONA
-- All confirmed pricing_unit='g' in live DB before this migration.
-- POMONA (238) has no Active delivery rows; flipped for consistency per operator decision.
UPDATE ref_mi
   SET pricing_unit = 'kg'
 WHERE id IN (65, 66, 194, 196, 238)
   AND pricing_unit = 'g';

-- =============================================================================
-- SECTION 5: ref_mi_invoicing_units — de-drift rows 12 and 13 (FARMHOUSE, VERDANT)
--             These two rows have pack_size=500 (in g) while ref_mi.pricing_unit
--             just changed to 'kg'. Under the ONE RULE (pack_size always in the
--             canonical pricing_unit), pack_size must become 0.5 (in kg).
--             Rows 14 (US05) and 15 (W3470) already have pack_size=0.5 and
--             their notes referenced 'pricing_unit=kg' from the initial backfill
--             — they are correct under the new convention; only a note update.
--             target table: ref_mi_invoicing_units  (corrections_policy='allowed')
-- =============================================================================

-- Row 12: FARMHOUSE — pack_size 500.0000 g → 0.5000 kg; update note.
UPDATE ref_mi_invoicing_units
   SET pack_size = 0.5000,
       notes     = 'Migration 162 2026-05-26 — 500 g brick; pricing_unit flipped to kg; pack_size now 0.5 kg'
 WHERE id        = 12
   AND mi_id_fk  = 194
   AND pack_size = 500.0000;

-- Row 13: VERDANT — pack_size 500.0000 g → 0.5000 kg; update note.
UPDATE ref_mi_invoicing_units
   SET pack_size = 0.5000,
       notes     = 'Migration 162 2026-05-26 — 500 g brick; pricing_unit flipped to kg; pack_size now 0.5 kg'
 WHERE id        = 13
   AND mi_id_fk  = 196
   AND pack_size = 500.0000;

-- Row 14: US05 — pack_size=0.5 already correct; note correction only.
UPDATE ref_mi_invoicing_units
   SET notes = 'Migration 162 2026-05-26 — 500 g brick; pricing_unit=kg confirmed; pack_size=0.5 kg'
 WHERE id        = 14
   AND mi_id_fk  = 65
   AND pack_size = 0.5000;

-- Row 15: W3470 — pack_size=0.5 already correct; note correction only.
UPDATE ref_mi_invoicing_units
   SET notes = 'Migration 162 2026-05-26 — 500 g brick; pricing_unit=kg confirmed; pack_size=0.5 kg'
 WHERE id        = 15
   AND mi_id_fk  = 66
   AND pack_size = 0.5000;

-- =============================================================================
-- SECTION 6: wac_snapshots — recompute 6 contaminated rows for period 2026-04
--             Formula used: wac_chf = SUM(qty_remaining × total_chf/qty_delivered)
--                                     / SUM(qty_remaining)
--             This is the locked operator decision from the audit (§6.1 + §10).
--             total_chf and qty_delivered are READ from inv_deliveries POST-162 fix.
--
--             Pre-162 snapshot values (for rollback reference):
--               SIMCOE   (46): wac=25.258800, qty_at_close=225.0000, total_value=5683.2300, hash=b801aa09...
--               US05     (65): wac=103.005000, qty_at_close=1.0000,  total_value=103.0050,  hash=6341b098...
--               W3470    (66): wac=169.533000, qty_at_close=1.0000,  total_value=169.5330,  hash=22619963...
--               FARMHOUSE(194):wac=0.209412,   qty_at_close=500.0000,total_value=104.7060,  hash=21cc5005...
--               VERDANT  (196):wac=0.186921,   qty_at_close=1000.0000,total_value=186.9210, hash=b40240c4...
--               WATERDEMIN(277):wac=3.123000,  qty_at_close=4.0000,  total_value=12.4920,   hash=441234ac...
--
--             Post-162 recomputed values (hand-verified 2026-05-26):
--               SIMCOE   (46): rows 105(qrem=5,tot=66.15,qdel=5), 130(qrem=25,tot=2844.45,qdel=215), 294(qrem=200,tot=5352.48,qdel=200)
--                              wac=(5×13.23 + 25×13.23 + 200×26.7624)/230 = 5749.38/230 = 24.997304 CHF/kg
--               US05     (65): row 426(qrem=0.5,tot=103.005,qdel=0.5); wac=103.005/0.5=206.010000 CHF/kg
--               W3470    (66): row 425(qrem=0.5,tot=169.533,qdel=0.5); wac=169.533/0.5=339.066000 CHF/kg
--               FARMHOUSE(194):row 427(qrem=0.5,tot=104.706,qdel=0.5); wac=104.706/0.5=209.412000 CHF/kg
--               VERDANT  (196):row 429(qrem=1.0,tot=186.921,qdel=1.0); wac=186.921/1.0=186.921000 CHF/kg
--               WATERDEMIN(277):row 12(qrem=20,tot=12.492,qdel=20);    wac=12.492/20=0.624600 CHF/L
--
--             row_hash formula (matches _warehouse-compute-wac-snapshot.ts line 154):
--               sha256(`${mi_id_fk}|${PERIOD}|${wac_chf.toFixed(6)}|${sumQty.toFixed(4)}`)
--
--             target table: wac_snapshots  (schema_meta corrections_policy='blocked_with_redirect')
--             Exception granted: this migration IS the recompute redirect that schema_meta points at.
--             The writer_script is 'compute-weighted-prices.ts' (legacy) but the locked formula
--             change (total_chf/qty_delivered basis) is deliberate; the next snapshot run will use
--             the new formula via _phase2d-recompute-wac.ts.
-- =============================================================================

-- SIMCOE (mi_id_fk=46, wac_snapshots.id=394)
-- Pre-162 contaminated by id=105 (1 pack as 1 kg). Post-162: 5 kg + 25 kg + 200 kg = 230 kg.
-- New wac_chf = 24.997304 CHF/kg (note: precision carried to 6dp per toFixed(6))
-- New delivery_row_ids = [105, 130, 294] (id=105 remains Active post-fix, qrem>0)
UPDATE wac_snapshots
   SET wac_chf                = 24.997304,
       qty_remaining_at_close = 230.0000,
       total_value_chf        = 5749.3800,
       delivery_row_ids       = JSON_ARRAY(105, 130, 294),
       computed_at            = NOW(),
       row_hash               = '26089cb551f18dd225ef25b907f712c49bfc7bf42b04b0b9d95a95a8bc7bd02a'
 WHERE id            = 394
   AND mi_id_fk      = 46
   AND period        = '2026-04'
   AND wac_chf       = 25.258800
   AND row_hash      = 'b801aa09a4c226aa4f50c10176c5a5e8746a69c9db5f0bcede1da994d18e4c16';

-- US05 (mi_id_fk=65, wac_snapshots.id=448)
-- Pre-162: wac=103.005 (g-basis, one g-qty row). Post-162: one 0.5 kg row.
UPDATE wac_snapshots
   SET wac_chf                = 206.010000,
       qty_remaining_at_close = 0.5000,
       total_value_chf        = 103.0050,
       delivery_row_ids       = JSON_ARRAY(426),
       computed_at            = NOW(),
       row_hash               = 'c233f0d8796adf2852900e06879a3cb16a2a0e3370b66c781adc25f976b8165f'
 WHERE id            = 448
   AND mi_id_fk      = 65
   AND period        = '2026-04'
   AND wac_chf       = 103.005000
   AND row_hash      = '6341b098a8e3a49c873af2ef3fedac95b2f051aaebf5d25060f8629dbad488bc';

-- W3470 (mi_id_fk=66, wac_snapshots.id=447)
-- Pre-162: wac=169.533 (g-basis). Post-162: one 0.5 kg row.
UPDATE wac_snapshots
   SET wac_chf                = 339.066000,
       qty_remaining_at_close = 0.5000,
       total_value_chf        = 169.5330,
       delivery_row_ids       = JSON_ARRAY(425),
       computed_at            = NOW(),
       row_hash               = '9b4ecd5fa0c4250daea49e3088a3b709e5711e1abd73a2c5080b2dd59cddaefb'
 WHERE id            = 447
   AND mi_id_fk      = 66
   AND period        = '2026-04'
   AND wac_chf       = 169.533000
   AND row_hash      = '226199634a537243875c2a3baa1550faee4fbb6eb605eca93427b3d33a248c74';

-- FARMHOUSE (mi_id_fk=194, wac_snapshots.id=449)
-- Pre-162: wac=0.209412 (g-basis, 500g row). Post-162: one 0.5 kg row.
UPDATE wac_snapshots
   SET wac_chf                = 209.412000,
       qty_remaining_at_close = 0.5000,
       total_value_chf        = 104.7060,
       delivery_row_ids       = JSON_ARRAY(427),
       computed_at            = NOW(),
       row_hash               = 'f5ba3da20c6a8e15956b1f33ad6d08f41b531a6f2a429693f73cfdf69e6657bf'
 WHERE id            = 449
   AND mi_id_fk      = 194
   AND period        = '2026-04'
   AND wac_chf       = 0.209412
   AND row_hash      = '21cc5005cedd0179646ea0d560c32b57682d11305412d5cd940ea3d94f7c64b3';

-- VERDANT (mi_id_fk=196, wac_snapshots.id=451)
-- Pre-162: wac=0.186921 (g-basis, 1000g row). Post-162: one 1.0 kg row.
UPDATE wac_snapshots
   SET wac_chf                = 186.921000,
       qty_remaining_at_close = 1.0000,
       total_value_chf        = 186.9210,
       delivery_row_ids       = JSON_ARRAY(429),
       computed_at            = NOW(),
       row_hash               = 'd806f50f6b88661a767a4bce4f762f468858dd80446abe8e15558910728a4d20'
 WHERE id            = 451
   AND mi_id_fk      = 196
   AND period        = '2026-04'
   AND wac_chf       = 0.186921
   AND row_hash      = 'b40240c4d92cdf4d6a7af571409287c8daa0421cdbdc180aed1c1c8f3924f701';

-- QA_WATER_DEMIN (mi_id_fk=277, wac_snapshots.id=345)
-- Pre-162: wac=3.123 (per-bottle). Post-162: one 20 L row.
UPDATE wac_snapshots
   SET wac_chf                = 0.624600,
       qty_remaining_at_close = 20.0000,
       total_value_chf        = 12.4920,
       delivery_row_ids       = JSON_ARRAY(12),
       computed_at            = NOW(),
       row_hash               = '1107426bbaadac144667630697cf2a753778053ffeba27cd73ced300003229e9'
 WHERE id            = 345
   AND mi_id_fk      = 277
   AND period        = '2026-04'
   AND wac_chf       = 3.123000
   AND row_hash      = '441234acd8b41665a5ccfa24574ef7f5a80147c437ce4b8d5b2fb017f4a54368';
