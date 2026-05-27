-- db/migrations/169_wac_aligal2_eco_non_inventoried.sql
--
-- What: Reclassify PROC_ALIGAL2_ECO (ref_mi.id=591) as non-inventoried (is_inventoried=0)
--       with pricing_unit='unit', and delete its stray wac_snapshots row (id=455, period 2026-04).
--
-- Why:  The "ECO ORIGIN LCO2" eco-origin certification charge from Carbagas is structurally
--       a fixed-fee line (historically per-kg; going forward a fixed ~50 CHF/delivery).
--       The accountant ruled it is fixed-freight-in, NOT a per-unit consumable: it must be
--       its own non-inventoried line, GL-routed into period COP via GL 4300 (unchanged).
--       Keeping it as is_inventoried=1 with pricing_unit=NULL caused it to appear in
--       v_mi_wac with wac_unresolved=1 (NULL pricing_unit flags as unresolvable WAC).
--       Setting is_inventoried=0 removes it from the v_mi_wac surface (which filters
--       is_inventoried=1) and closes its wac_unresolved flag.
--       COP capture is PRESERVED: inv_deliveries id=445 (the 41.55 CHF line) is left
--       fully intact — it carries the GL-4300 period cost and provenance.
--       The CO2 base line (ref_mi.id=197, PROC_ALIGAL2_LIQ) and its snapshot (id=383)
--       are NOT touched by this migration.
--
-- Ops:
--   1. UPDATE ref_mi id=591 — set is_inventoried=0, pricing_unit='unit'
--      Guard: AND is_inventoried=1 AND pricing_unit IS NULL (old state)
--   2. DELETE wac_snapshots id=455 — stray inventory snapshot for a now-non-inventory line
--      Guard: AND mi_id_fk=591 AND period='2026-04'
--
-- Expected effect: v_mi_wac MI count 98→97; wac_unresolved 2→1.
--
-- Risk: LOW — no schema changes. inv_deliveries id=445 left untouched (COP preserved).
--       No schema_meta row needed (data-only migration, no new table).
--       MySQL 8 syntax. No bare SELECT (migrate.php $pdo->exec()).
--
-- Rollback (apply in reverse if needed):
--   -- Step 2 re-INSERT of snapshot 455 (exact old values, verified live 2026-05-27):
--   -- INSERT INTO wac_snapshots
--   --     (id, mi_id_fk, period, wac_chf, qty_remaining_at_close, total_value_chf,
--   --      delivery_row_ids, eur_chf_rate, replay_source, row_hash)
--   -- VALUES
--   --     (455, 591, '2026-04', 0.010000, 4155.0000, 41.5500,
--   --      '[445]', 0.945000, 'current_approximation',
--   --      '0cb8a1ae10fed457d5c134c2b361665a4c0dbf74385ebe760e136c2c39f5ed8b');
--   -- Step 1 reverse UPDATE:
--   -- UPDATE ref_mi
--   --    SET is_inventoried=1, pricing_unit=NULL, last_modified_by='web'
--   --  WHERE id=591 AND is_inventoried=0 AND pricing_unit='unit';
--
-- Verified against live DB 2026-05-27:
--   wac_snapshots id=455: mi_id_fk=591, period=2026-04, wac_chf=0.010000,
--     qty_remaining_at_close=4155.0000, total_value_chf=41.5500, delivery_row_ids=[445],
--     eur_chf_rate=0.945000, replay_source=current_approximation,
--     row_hash=0cb8a1ae10fed457d5c134c2b361665a4c0dbf74385ebe760e136c2c39f5ed8b
--   ref_mi id=591: is_inventoried=1, pricing_unit=NULL (confirmed via ISNULL()=1)

-- =============================================================================
-- STEP 1: ref_mi — reclassify PROC_ALIGAL2_ECO as non-inventoried fixed-fee line
--         pricing_unit='unit' makes it concrete (fixed per-delivery charge) and
--         prevents it appearing in canonical-unit WAC checks.
--         last_modified_by='web' marks it as human-curated so re-ingest won't clobber.
--         row_hash NOT recomputed — mi_id is stable, hash = sha256(mi_id) is unchanged.
-- =============================================================================
UPDATE ref_mi
   SET is_inventoried    = 0,
       pricing_unit      = 'unit',
       last_modified_by  = 'web'
 WHERE id                = 591
   AND is_inventoried    = 1
   AND pricing_unit      IS NULL;

-- =============================================================================
-- STEP 2: wac_snapshots — delete the stray inventory snapshot for PROC_ALIGAL2_ECO
--         period 2026-04. This row was created when the MI was still classified as
--         inventoried; with is_inventoried=0 it has no place in the WAC surface.
--         inv_deliveries id=445 (the source cost row) is KEPT UNCHANGED.
-- =============================================================================
DELETE FROM wac_snapshots
 WHERE id        = 455
   AND mi_id_fk  = 591
   AND period    = '2026-04';
