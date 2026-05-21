-- db/migrations/067_inv_fg_stocktake_qty_decimal.sql
-- What: Change inv_fg_stocktake.qty from INT to DECIMAL(10,4)
-- Why:  Cage / loose-bottle inventory unit (e.g. ZEP-X) is reported as a fraction of
--       a crate (0.5 = half-crate, 0.25 = quarter-crate). INT truncates these to 0.
--       All existing integer qty values are preserved exactly as-is (INT subset of DECIMAL).
-- Risk: Low — no data loss. INT values survive DECIMAL(10,4) representation.
--       PHP consumers: grep for inv_fg_stocktake qty reads — DECIMAL comes back as string
--       from PDO; callers that did (int) cast will silently truncate fractions. Checked:
--       no PHP callers do hard (int) cast on this column (float/floatval safe).
-- Rollback:
--   ALTER TABLE inv_fg_stocktake MODIFY COLUMN qty INT NOT NULL DEFAULT 0;
--   (fractional rows would truncate — only safe if no fractional rows exist)

ALTER TABLE inv_fg_stocktake
  MODIFY COLUMN qty DECIMAL(10,4) NOT NULL DEFAULT 0;
