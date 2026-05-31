-- ============================================================================
-- Migration 248: backfill in-tank reads for the 3 recent web-backfill lot-days
--                from v1 bd_packaging (operator-sanctioned, natural-key matched)
--
-- WHAT:
--   Inserts 3 bd_tank_readings rows for the lot-days the operator web-entered on
--   2026-05-31 (before the form had an in-tank input), and links every live,
--   non-reuse bd_packaging_v2 row of each lot-day via tank_read_id_fk.
--
--   Lot-day              recipe  batch  read_date    co2_gl  o2_ppb   v2 rows
--   Stirling 170         52      170    2026-05-26   5.040   16.800   6709
--   Embuscade 233        32      233    2026-05-26   5.350   10.800   6710,6711
--   Zepp 210             57      210    2026-05-27   5.030    8.600   6712
--
-- WHY:
--   These four runs were entered via the packaging form on 2026-05-31, before
--   the in-tank gate existed (commit f93e250), so their tank_read_id_fk is NULL.
--   The operator pointed to v1 bd_packaging.tank_co2/tank_o2 as the source. Each
--   value was matched by NATURAL KEY (recipe/beer prefix + batch + day) — NOT the
--   unreliable sheet_row_index bridge — and cross-checked:
--     - Stirling 170/26-May: v1 1367228 (STI4C) → 5.040/16.800
--     - Embuscade 233/26-May: v1 1384661 (EMBF) + 1386842 (EMB4C) AGREE → 5.350/10.800
--     - Zepp 210/27-May: v1 1478445 (ZEPC) + 3 ZEPV rows AGREE → 5.030/8.600
--   6710 (EMBF, keg) legitimately has 0 in-filling reads (kegs aren't line-sampled);
--   only the in-tank read was missing.
--
--   This is a one-time correction for known recent rows; the general historical
--   v1→v2 in-tank backfill remains deferred pending a validated natural-key bridge.
--
-- NOTE: on a fresh schema rebuild the 3 readings insert and the UPDATE no-ops
--   (v2 rows 6709-6712 are live web data, absent from a schema-only rebuild) —
--   leaving 3 valid but unlinked in-tank readings, which is harmless.
--
-- ROLLBACK:
--   UPDATE bd_packaging_v2 SET tank_read_id_fk = NULL
--     WHERE tank_read_id_fk IN (SELECT id FROM bd_tank_readings WHERE source='rawdb_v1'
--           AND (recipe_id_fk,neb_batch,read_date) IN ((52,'170','2026-05-26'),(32,'233','2026-05-26'),(57,'210','2026-05-27')));
--   DELETE FROM bd_tank_readings WHERE source='rawdb_v1'
--     AND (recipe_id_fk,neb_batch,read_date) IN ((52,'170','2026-05-26'),(32,'233','2026-05-26'),(57,'210','2026-05-27'));
-- ============================================================================

-- 1. Insert the 3 in-tank readings (idempotent: lot-day UNIQUE → keep existing).
INSERT INTO bd_tank_readings
  (recipe_id_fk, neb_batch, read_date, co2_gl, o2_ppb, source, created_by)
VALUES
  (52, '170', '2026-05-26', 5.040, 16.800, 'rawdb_v1', 'mig248-operator-sanctioned'),
  (32, '233', '2026-05-26', 5.350, 10.800, 'rawdb_v1', 'mig248-operator-sanctioned'),
  (57, '210', '2026-05-27', 5.030,  8.600, 'rawdb_v1', 'mig248-operator-sanctioned')
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);

-- 2. Link every live, non-reuse v2 row of each lot-day to its in-tank reading.
--    Natural-key JOIN → each v2 row links only to its own lot-day's reading.
UPDATE bd_packaging_v2 p
  JOIN bd_tank_readings t
    ON t.recipe_id_fk = p.recipe_id_fk
   AND t.neb_batch    = p.neb_batch
   AND t.read_date    = p.event_date
   SET p.tank_read_id_fk = t.id
 WHERE p.reuses_packaging_id_fk IS NULL
   AND p.is_tombstoned = 0
   AND p.tank_read_id_fk IS NULL
   AND (
        (p.recipe_id_fk = 52 AND p.neb_batch = '170' AND p.event_date = '2026-05-26')
     OR (p.recipe_id_fk = 32 AND p.neb_batch = '233' AND p.event_date = '2026-05-26')
     OR (p.recipe_id_fk = 57 AND p.neb_batch = '210' AND p.event_date = '2026-05-27')
   );
