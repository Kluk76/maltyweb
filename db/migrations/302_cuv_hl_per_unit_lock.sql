-- db/migrations/302_cuv_hl_per_unit_lock.sql
--
-- What: BEFORE INSERT + BEFORE UPDATE triggers on ref_skus that enforce the
--       canonical hl_per_unit for any SKU whose format is a cuv (serving tank).
--
--       Logic: look up ref_packaging_formats.hl_per_unit for the row's format_id
--       where run_type='cuv'. If found, force NEW.hl_per_unit to that canonical
--       value — silently correcting any attempt (script or human) to set a wrong
--       value. Non-cuv SKUs (keg/bottle/can) are untouched: the SELECT finds no
--       cuv row for their format_id, so the IF branch is never taken.
--
-- Why:  On 2026-06-09 an ad-hoc CLI (username 'cli-cuv-hl-fix') flipped all 3
--       cuv SKUs (EMBV/MOOV/ZEPV, ids 27/46/61) from 0.01 to 1.00000, causing
--       bd_packaging_v2 row 6753 (Zepp cuv b212) to store vendable_hl=500 instead
--       of 5.000 (500 L × 0.01 = 5 HL). The values were reverted to 0.01 but
--       without an audit trail. This trigger makes the canonical value (from
--       ref_packaging_formats) the only value that can ever be stored, so no
--       future script or human can corrupt it again.
--
-- Scope: STRICTLY cuv formats (run_type='cuv' in ref_packaging_formats). All
--        other format types are unaffected — their per-SKU hl_per_unit remains
--        freely editable.
--
-- Note on trigger creation: requires TRIGGER privilege on maltytask schema
--       (granted 2026-06-10 via root) and log_bin_trust_function_creators=ON
--       (persisted in mysqld.cnf 2026-06-10). Both are pre-conditions for this
--       migration to succeed.
--
-- Idempotency: DROP TRIGGER IF EXISTS before each CREATE TRIGGER.
-- No new tables → no schema_meta row needed.
--
-- Rollback:
--   DROP TRIGGER IF EXISTS trg_ref_skus_cuv_hl_before_insert;
--   DROP TRIGGER IF EXISTS trg_ref_skus_cuv_hl_before_update;

DROP TRIGGER IF EXISTS trg_ref_skus_cuv_hl_before_insert;

CREATE TRIGGER trg_ref_skus_cuv_hl_before_insert
BEFORE INSERT ON ref_skus
FOR EACH ROW
BEGIN
  DECLARE v_canon DECIMAL(10,6) DEFAULT NULL;
  SELECT rpf.hl_per_unit INTO v_canon
    FROM ref_packaging_formats rpf
   WHERE rpf.id = NEW.format_id
     AND rpf.run_type = 'cuv'
   LIMIT 1;
  IF v_canon IS NOT NULL THEN
    SET NEW.hl_per_unit = v_canon;
  END IF;
END;

DROP TRIGGER IF EXISTS trg_ref_skus_cuv_hl_before_update;

CREATE TRIGGER trg_ref_skus_cuv_hl_before_update
BEFORE UPDATE ON ref_skus
FOR EACH ROW
BEGIN
  DECLARE v_canon DECIMAL(10,6) DEFAULT NULL;
  SELECT rpf.hl_per_unit INTO v_canon
    FROM ref_packaging_formats rpf
   WHERE rpf.id = NEW.format_id
     AND rpf.run_type = 'cuv'
   LIMIT 1;
  IF v_canon IS NOT NULL THEN
    SET NEW.hl_per_unit = v_canon;
  END IF;
END;
