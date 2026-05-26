-- db/migrations/164_wac_data_corrections_peachtea_mosaic.sql
--
-- What: Two operator-confirmed WAC data corrections surfaced by the v_mi_wac
--       catalog-divergence pressure test (the model itself reconciled 98/98):
--         CORRECTION 1 (SECTION 1+4) — ADJ_PEACH_TEA inv_deliveries.id=249 silent-unit
--           error: stored qty=320 (count of 100 g units) @ 4.99/100g. Canonical
--           (pricing_unit=kg): qty 320→32 kg, qty_remaining 284→28.4 kg, unit_price
--           4.99→49.90/kg. total_original/total_chf UNCHANGED (32×49.90=1596.80 ✓).
--           Brings WAC 4.99→49.90/kg (= the catalog price — divergence closed).
--         CORRECTION 2 (SECTION 2) — HOPS_MOSAIC inv_deliveries.id=104: stored qty=2
--           (sachet count) @ 145 EUR/sachet. The Bière Appro invoice 8389 (doc_invoices
--           id=45, file 46) states "Sachet 5 kg" → 2 × 5 kg = 10 kg @ 29 EUR/kg.
--           Canonical: qty 2→10 kg, qty_remaining 2→10 kg, unit_price 145→29/kg.
--           total_original/total_chf UNCHANGED (10×29=290 EUR ✓).
--           ** SEE THE OPERATOR-GATE BANNER BELOW SECTION 2 — the Cryo reclassification
--              premise is CONTRADICTED by the source invoice; the reassignment to
--              HOPS_MOSAIC_CRYO (id=54) is HELD pending operator re-confirmation. **
--
-- Why: total_chf/qty_delivered (the v_mi_wac basis) is only canonical when qty is in the
--      MI's pricing_unit. Both rows stored qty as a pack/unit COUNT, not the canonical
--      mass — a silent-unit error (invariant qty×price=total HELD, so Pass-1 blind; same
--      class as the GALAXY/SIMCOE/WATER_DEMIN fixes in migration 162).
--
-- Background: reports/wac-billing-format-audit-2026-05-26.md; PM spec 2026-05-27.
--   total_original and total_chf are NEVER touched — invoice truth, already FX-applied.
--   Every UPDATE is WHERE-guarded with old values for idempotency. Migration-162 idiom.
--
-- Invariant check:
--   id=249: 32  × 49.90 = 1596.80 = total_original ✓ (CHF; total_chf=1596.80 unchanged)
--   id=104: 10  × 29.00 =  290.00 = total_original ✓ (EUR; total_chf=274.05 unchanged)
--
-- Rollback (apply in reverse order if needed):
--   -- wac_snapshots PEACH_TEA id=425:
--   --   UPDATE wac_snapshots SET wac_chf=4.990000, qty_remaining_at_close=284.0000,
--   --     total_value_chf=1417.1600, computed_at=NOW(),
--   --     row_hash='3b828346100da6f6fe3114565ec844921cc616b3d916f726d06e78a1ec888cfa'
--   --   WHERE id=425 AND mi_id_fk=72 AND period='2026-04'
--   --     AND row_hash='58f8447ff569af6ff4932ea55777395874ec3d69ce2ff6c2de581d6ecd82b5cc';
--   -- inv_deliveries id=104:
--   --   UPDATE inv_deliveries SET qty_delivered=2.0000, qty_remaining=2.0000,
--   --     unit_price=145.000000 WHERE id=104 AND qty_delivered=10.0000;
--   -- inv_deliveries id=249:
--   --   UPDATE inv_deliveries SET qty_delivered=320.0000, qty_remaining=284.0000,
--   --     unit_price=4.990000 WHERE id=249 AND qty_delivered=32.0000;
--
-- Verified against live DB 2026-05-27. No schema changes; no schema_meta row needed
-- (164 modifies data only). MySQL 8 syntax. No bare SELECT (migrate.php $pdo->exec()).

-- =============================================================================
-- SECTION 1: inv_deliveries — ADJ_PEACH_TEA silent-unit fix (Correction 1)
--             status='Active', exclusion_class IS NULL, ingredient_fk=72.
--             target table: inv_deliveries (schema_meta corrections_policy='allowed')
-- =============================================================================

-- id=249 ADJ_PEACH_TEA (ref_mi.id=72, pricing_unit=kg)
-- qty was stored as 320 (count of 100 g units); canonical is 32 kg.
-- qty_remaining 284 (100 g units) → 28.4 kg (scaled ÷10, same as qty).
-- unit_price was per-100g (4.99); canonical is per-kg (49.90).
-- total_original=1596.80 unchanged; 32×49.90=1596.80 ✓
UPDATE inv_deliveries
   SET qty_delivered = 32.0000,
       qty_remaining = 28.4000,
       unit_price    = 49.900000
 WHERE id            = 249
   AND qty_delivered = 320.0000
   AND qty_remaining = 284.0000
   AND unit_price    = 4.990000
   AND total_original = 1596.8000;

-- =============================================================================
-- SECTION 2: inv_deliveries — HOPS_MOSAIC pack-as-qty fix (Correction 2, data part)
--             status='Active', exclusion_class IS NULL, ingredient_fk=40, supplier 19.
--             Source: Bière Appro invoice 8389 (doc_invoices id=45) — "Sachet 5 kg",
--             2 pcs @ 145 EUR = 290 EUR → 10 kg @ 29 EUR/kg.
--             target table: inv_deliveries (schema_meta corrections_policy='allowed')
-- =============================================================================

-- id=104 HOPS_MOSAIC (ref_mi.id=40, pricing_unit=kg)
-- qty was stored as 2 (sachet count); canonical is 10 kg (2 × 5 kg sachet).
-- qty_remaining 2 (sachets) → 10 kg (none consumed; scaled with qty).
-- unit_price was per-sachet (145); canonical is per-kg (29).
-- total_original=290 unchanged; 10×29=290 ✓ (total_chf=274.05 EUR→CHF unchanged)
UPDATE inv_deliveries
   SET qty_delivered = 10.0000,
       qty_remaining = 10.0000,
       unit_price    = 29.000000
 WHERE id            = 104
   AND qty_delivered = 2.0000
   AND qty_remaining = 2.0000
   AND unit_price    = 145.000000
   AND total_original = 290.0000;

-- =============================================================================
-- ⚠️ OPERATOR-GATE — HOPS_MOSAIC_CRYO reassignment HELD (do NOT uncomment without
--    operator re-confirmation; see PM report §Pack-size verdict + §Divergence flag).
--
--    The brief's premise was: "this is Cryo Mosaic (lupulin-concentrate), a DISTINCT
--    product; give it its own MI HOPS_MOSAIC_CRYO and reassign id=104 into it."
--    The SOURCE INVOICE CONTRADICTS that premise:
--      - OCR line (doc_invoices id=45): "Houblon Mosaic®, Yakima Chief Hops ; Houblon
--        - Sachet … Yakima 5Kg … Sachet 5 kg : Origin USA - 2023 Lot P92-IUMOS3364
--        (AA: 11.7%)". No "Cryo"/"LupuLN2"/"Incognito"/"Prysma" anywhere.
--      - 145 €/sachet ÷ 5 kg = 29 €/kg — squarely in this same invoice's COMMODITY
--        pellet band (Amarillo 5kg @ 149.5 = 29.9/kg; Eldorado 5kg @ 104.5 = 20.9/kg;
--        Simcoe 5kg @ 70 = 14/kg). It is NOT a specialty/Cryo price.
--      - HOPS_MOSAIC_CRYO ALREADY EXISTS (ref_mi.id=54, name 'Mosaic Cryo', cat 2/
--        subcat 8 'Special') — so NO new MI INSERT is required in either path; the
--        question is purely whether id=104 belongs in fk=40 or fk=54.
--    Verdict: id=104 is COMMODITY pellet Mosaic from a 2nd supplier (Bière Appro) at a
--    higher price than the IGN stock (11.5-12/kg) — the legitimate multi-supplier spread
--    v_mi_wac is built to blend. Reassigning it to a Cryo MI on a contradicted premise
--    would corrupt both MIs' WAC. RECOMMENDATION: keep id=104 on fk=40 (commodity) with
--    the qty fix in SECTION 2 above. If the operator, seeing the invoice text, still
--    asserts the physical product was Cryo (e.g. a known mislabel by Bière Appro), THEN
--    and only then uncomment the reassignment below.
--
-- -- id=104 → HOPS_MOSAIC_CRYO (ref_mi.id=54) [HELD — operator gate]
-- -- UPDATE inv_deliveries
-- --    SET ingredient_fk = 54
-- --  WHERE id            = 104
-- --    AND ingredient_fk = 40
-- --    AND qty_delivered = 10.0000;   -- after SECTION 2 fix
-- =============================================================================

-- =============================================================================
-- SECTION 3: ref_mi — HOPS_MOSAIC_CRYO creation — NOT NEEDED.
--   HOPS_MOSAIC_CRYO already exists (ref_mi.id=54). No INSERT.
--   (If it had been missing, the clone-from-fk=40 spec is in the PM report §B.)
-- =============================================================================

-- =============================================================================
-- SECTION 4: wac_snapshots — recompute ADJ_PEACH_TEA period 2026-04 (id=425).
--   HOPS_MOSAIC (fk=40) and HOPS_MOSAIC_CRYO (fk=54) have NO 2026-04 snapshot
--   (verified live) → the Mosaic correction touches NO wac_snapshots row.
--   Formula = SUM(qty_remaining × total_chf/qty_delivered)/SUM(qty_remaining)
--   (locked operator decision, migration 162 §6.1).
--     PEACH_TEA single row id=249 post-164: qrem=28.4, total_chf=1596.80, qdel=32
--       wac = (28.4 × 1596.80/32)/28.4 = 1596.80/32 = 49.900000 CHF/kg
--       qty_at_close = 28.4 ; total_value = 28.4 × 49.90 = 1417.16 (unchanged)
--   row_hash = sha256("72|2026-04|49.900000|28.4000")
--            = 58f8447ff569af6ff4932ea55777395874ec3d69ce2ff6c2de581d6ecd82b5cc
--   (Hash hardcoded — see migration 162 note: the SQL CAST-vs-JS-toFixed precision
--    trap means the hash must be precomputed to match _warehouse-compute-wac-snapshot.ts
--    line 154 sha256(`${mi_id_fk}|${PERIOD}|${wac.toFixed(6)}|${sumQty.toFixed(4)}`).
--    Recipe verified live: the pre-164 hash 3b828346… reproduces from "72|2026-04|
--    4.990000|284.0000".)
--   eur_chf_rate (0.945000) + replay_source ('current_approximation') unchanged.
--   target table: wac_snapshots (corrections_policy='blocked_with_redirect') —
--     exception: this migration IS the recompute redirect, same as migration 162 §6.
-- =============================================================================

UPDATE wac_snapshots
   SET wac_chf                = 49.900000,
       qty_remaining_at_close = 28.4000,
       total_value_chf        = 1417.1600,
       computed_at            = NOW(),
       row_hash               = '58f8447ff569af6ff4932ea55777395874ec3d69ce2ff6c2de581d6ecd82b5cc'
 WHERE id            = 425
   AND mi_id_fk      = 72
   AND period        = '2026-04'
   AND wac_chf       = 4.990000
   AND row_hash      = '3b828346100da6f6fe3114565ec844921cc616b3d916f726d06e78a1ec888cfa';

-- =============================================================================
-- SECTION 5: ref_mi_invoicing_units — billing-format seeds.
--   PEACH_TEA (fk=72): pack format from invoice 12884 = 100 g unit billing. Under the
--     ONE RULE (pack_size in canonical pricing_unit=kg), 100 g = 0.1 kg. Seed it so the
--     next ingest normalizes 100 g-billed Peach Tea automatically (count×0.1 → kg).
--   HOPS_MOSAIC (fk=40), supplier 19 (Bière Appro): "Sachet 5 kg" → pack_size 5.0 kg.
--     Supplier-scoped (the IGN stock for fk=40 may use a different pack — leave default
--     to the supplier-19 row; do NOT set is_default=1 globally without operator confirm).
--   These are NON-default supplemental seeds (is_default=0) — they describe the observed
--   billing format for THIS supplier/invoice; they do not claim to be the MI's only pack.
--   target table: ref_mi_invoicing_units (corrections_policy='allowed')
--   Schema: (mi_id_fk, supplier_fk NULL-able, invoicing_unit, pack_size DECIMAL(10,4),
--            is_default, active, notes). Idempotent via NOT EXISTS guard.
-- =============================================================================

-- PEACH_TEA 100 g unit billing (supplier-agnostic — Swiss Fair Trade inv 12884).
-- invoicing_unit='pack' matches the existing house vocabulary (all live rows use
-- 'pack'); pack_size 0.1 kg carries the 100 g format.
INSERT INTO ref_mi_invoicing_units (mi_id_fk, supplier_fk, invoicing_unit, pack_size, is_default, active, notes)
SELECT 72, NULL, 'pack', 0.1000, 0, 1,
       'Migration 164 2026-05-27 — Peach Tea billed per 100 g; pack_size 0.1 kg (canonical=kg). Seeded from inv_deliveries id=249 / invoice 12884.'
  FROM DUAL
 WHERE NOT EXISTS (
       SELECT 1 FROM ref_mi_invoicing_units
        WHERE mi_id_fk = 72 AND invoicing_unit = 'pack' AND pack_size = 0.1000
       );

-- HOPS_MOSAIC 5 kg sachet, supplier 19 (Bière Appro), inv 8389.
INSERT INTO ref_mi_invoicing_units (mi_id_fk, supplier_fk, invoicing_unit, pack_size, is_default, active, notes)
SELECT 40, 19, 'pack', 5.0000, 0, 1,
       'Migration 164 2026-05-27 — Bière Appro Mosaic 5 kg sachet; pack_size 5 kg (canonical=kg). Seeded from inv_deliveries id=104 / invoice 8389.'
  FROM DUAL
 WHERE NOT EXISTS (
       SELECT 1 FROM ref_mi_invoicing_units
        WHERE mi_id_fk = 40 AND supplier_fk = 19 AND invoicing_unit = 'pack' AND pack_size = 5.0000
       );
