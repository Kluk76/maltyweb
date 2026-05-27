-- db/migrations/165_wac_yeast_full_delivery_remodel.sql
--
-- What: Remodel two yeast inv_deliveries rows (YEAST_US05 id=426, YEAST_W3470 id=425) so
--       that qty_delivered reflects the FULL quantity received (1.0 kg per row) instead of
--       the remaining stock (0.5 kg), and unit_price is corrected to the true per-kg
--       acquisition cost. Recomputes the two corresponding wac_snapshots rows for period
--       2026-04 under the corrected qty_delivered basis.
--
-- Why: Migration 162 collapsed BOTH qty_delivered AND qty_remaining to 0.5 for these two
--      yeast rows while leaving total_chf at the full 2-pack value, which doubled their
--      per-kg WAC (v_mi_wac computes total_chf/qty_delivered). Invoice F20260414-24022
--      shows 2×500 g packs = 1.0 kg received for each MI. The canonical convention
--      (proven by VERDANT id=429, already stored correctly at qty_delivered=1.0 kg with
--      qty_remaining=1.0 kg) is qty_delivered = full quantity received; the consumed pack
--      lives in the qty_delivered − qty_remaining gap. This migration restores that:
--      qty_delivered 0.5→1.0 and unit_price back to the true per-kg acquisition cost.
--      total_original and total_chf are NEVER touched — they are invoice truth.
--      Every UPDATE is WHERE-guarded with old values for idempotency.
--
-- Background: Reports/WAC audit 2026-05-27; operator-confirmed against invoice F20260414-24022.
--
-- Rollback (apply in reverse order if needed; OLD values listed verbatim):
--   -- wac_snapshots id=448 (YEAST_US05, mi_id_fk=65):
--   --   UPDATE wac_snapshots SET wac_chf=206.010000, qty_remaining_at_close=0.5000,
--   --     total_value_chf=103.0050, computed_at=NOW(),
--   --     row_hash='c233f0d8796adf2852900e06879a3cb16a2a0e3370b66c781adc25f976b8165f'
--   --   WHERE id=448 AND mi_id_fk=65 AND period='2026-04'
--   --     AND row_hash='0314ff80a9014050ddac3e7dfa8411a7c55200b4e8c8cad7b43b9fa3d2393f52';
--   -- wac_snapshots id=447 (YEAST_W3470, mi_id_fk=66):
--   --   UPDATE wac_snapshots SET wac_chf=339.066000, qty_remaining_at_close=0.5000,
--   --     total_value_chf=169.5330, computed_at=NOW(),
--   --     row_hash='9b4ecd5fa0c4250daea49e3088a3b709e5711e1abd73a2c5080b2dd59cddaefb'
--   --   WHERE id=447 AND mi_id_fk=66 AND period='2026-04'
--   --     AND row_hash='fcd6c5324ed3a7043622f6cd6b9de09b3bda9c304ec6fd2dd6979cd7b6a8cdd9';
--   -- inv_deliveries id=426 (YEAST_US05):
--   --   UPDATE inv_deliveries SET qty_delivered=0.5000, unit_price=218.000000
--   --   WHERE id=426 AND qty_delivered=1.0000 AND unit_price=109.000000;
--   -- inv_deliveries id=425 (YEAST_W3470):
--   --   UPDATE inv_deliveries SET qty_delivered=0.5000, unit_price=358.800000
--   --   WHERE id=425 AND qty_delivered=1.0000 AND unit_price=179.400000;
--
-- Invariant check:
--   id=426: 1.0 × 109.00 = 109.00 = total_original ✓ (qty_remaining stays 0.5; consumed gap 0.5 kg)
--   id=425: 1.0 × 179.40 = 179.40 = total_original ✓ (qty_remaining stays 0.5; consumed gap 0.5 kg)
--
-- Verified against live DB 2026-05-27. No schema changes in this migration.
-- No schema_meta row needed (165 modifies data only). MySQL 8 syntax.
-- No bare SELECT (migrate.php $pdo->exec()).

-- =============================================================================
-- SECTION 1: inv_deliveries — YEAST_US05 and YEAST_W3470 full-delivery remodel
--             qty_delivered corrected to full qty received (1.0 kg per pack × 1 pack).
--             unit_price corrected to true per-kg acquisition cost.
--             qty_remaining UNCHANGED (0.5 kg — one pack consumed, one pack in stock).
--             total_original and total_chf UNCHANGED (invoice truth).
--             target table: inv_deliveries  (schema_meta corrections_policy='allowed')
-- =============================================================================

-- id=426 YEAST_US05 (ref_mi.id=65, mi_id_fk=65)
-- Before (migration 162 state): qty_delivered=0.5000, unit_price=218.000000
-- After:                         qty_delivered=1.0000, unit_price=109.000000
-- Invariant: 1.0 × 109.00 = 109.00 = total_original ✓; qty_remaining stays 0.5000
UPDATE inv_deliveries
   SET qty_delivered = 1.0000,
       unit_price    = 109.000000
 WHERE id            = 426
   AND qty_delivered = 0.5000
   AND unit_price    = 218.000000;

-- id=425 YEAST_W3470 (ref_mi.id=66, mi_id_fk=66)
-- Before (migration 162 state): qty_delivered=0.5000, unit_price=358.800000
-- After:                         qty_delivered=1.0000, unit_price=179.400000
-- Invariant: 1.0 × 179.40 = 179.40 = total_original ✓; qty_remaining stays 0.5000
UPDATE inv_deliveries
   SET qty_delivered = 1.0000,
       unit_price    = 179.400000
 WHERE id            = 425
   AND qty_delivered = 0.5000
   AND unit_price    = 358.800000;

-- =============================================================================
-- SECTION 2: wac_snapshots — recompute YEAST_US05 and YEAST_W3470 for period 2026-04
--             Formula: wac_chf = SUM(qty_remaining × total_chf/qty_delivered)
--                                / SUM(qty_remaining)
--             (locked operator decision, migration 162 §6.1).
--             Each MI has a single Active delivery row; both reduce to:
--               wac = total_chf / qty_delivered (post-165 basis)
--
--             YEAST_US05 (mi_id_fk=65, wac_snapshots.id=448):
--               row 426 post-165: qty_delivered=1.0, total_chf=103.005, qty_remaining=0.5
--               wac = (0.5 × 103.005/1.0) / 0.5 = 103.005 CHF/kg
--               qty_at_close = 0.5000; total_value = 0.5 × 103.005 = 51.5025
--             YEAST_W3470 (mi_id_fk=66, wac_snapshots.id=447):
--               row 425 post-165: qty_delivered=1.0, total_chf=169.533, qty_remaining=0.5
--               wac = (0.5 × 169.533/1.0) / 0.5 = 169.533 CHF/kg
--               qty_at_close = 0.5000; total_value = 0.5 × 169.533 = 84.7665
--
--             row_hash formula (matches _warehouse-compute-wac-snapshot.ts line 154):
--               sha256(`${mi_id_fk}|${period}|${wac_chf.toFixed(6)}|${qty_remaining_at_close.toFixed(4)}`)
--             YEAST_US05 input: `65|2026-04|103.005000|0.5000`
--               → new hash: 0314ff80a9014050ddac3e7dfa8411a7c55200b4e8c8cad7b43b9fa3d2393f52
--               (old hash:  c233f0d8796adf2852900e06879a3cb16a2a0e3370b66c781adc25f976b8165f
--                was set by migration 162 for wac=206.010000; the WHERE guard ensures
--                this UPDATE is a no-op if 162 was not already applied.)
--             YEAST_W3470 input: `66|2026-04|169.533000|0.5000`
--               → new hash: fcd6c5324ed3a7043622f6cd6b9de09b3bda9c304ec6fd2dd6979cd7b6a8cdd9
--               (old hash:  9b4ecd5fa0c4250daea49e3088a3b709e5711e1abd73a2c5080b2dd59cddaefb
--                was set by migration 162 for wac=339.066000.)
--
--             target table: wac_snapshots  (schema_meta corrections_policy='blocked_with_redirect')
--             Exception granted: this migration IS the recompute redirect (same as migration 162 §6).
-- =============================================================================

-- YEAST_US05 (mi_id_fk=65, wac_snapshots.id=448)
-- Pre-165: wac=206.010000 (qty_delivered was 0.5 → total_chf/0.5 doubled the WAC)
-- Post-165: wac=103.005000 (qty_delivered=1.0 → total_chf/1.0 = true acquisition rate)
-- qty_remaining_at_close stays 0.5000; total_value_chf = 0.5 × 103.005 = 51.5025
UPDATE wac_snapshots
   SET wac_chf                = 103.005000,
       total_value_chf        = 51.5025,
       computed_at            = NOW(),
       row_hash               = '0314ff80a9014050ddac3e7dfa8411a7c55200b4e8c8cad7b43b9fa3d2393f52'
 WHERE id            = 448
   AND mi_id_fk      = 65
   AND period        = '2026-04'
   AND row_hash      = 'c233f0d8796adf2852900e06879a3cb16a2a0e3370b66c781adc25f976b8165f';

-- YEAST_W3470 (mi_id_fk=66, wac_snapshots.id=447)
-- Pre-165: wac=339.066000 (qty_delivered was 0.5 → total_chf/0.5 doubled the WAC)
-- Post-165: wac=169.533000 (qty_delivered=1.0 → total_chf/1.0 = true acquisition rate)
-- qty_remaining_at_close stays 0.5000; total_value_chf = 0.5 × 169.533 = 84.7665
UPDATE wac_snapshots
   SET wac_chf                = 169.533000,
       total_value_chf        = 84.7665,
       computed_at            = NOW(),
       row_hash               = 'fcd6c5324ed3a7043622f6cd6b9de09b3bda9c304ec6fd2dd6979cd7b6a8cdd9'
 WHERE id            = 447
   AND mi_id_fk      = 66
   AND period        = '2026-04'
   AND row_hash      = '9b4ecd5fa0c4250daea49e3088a3b709e5711e1abd73a2c5080b2dd59cddaefb';
