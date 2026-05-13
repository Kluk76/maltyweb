-- 041 — Widen bbt_o2 / bbt_co2 and add QA/QC flag columns on bd_racking.
--
-- Root cause: bbt_o2 DECIMAL(6,3) (max 999.999) was rejecting legitimate ppb
-- measurements ≥ 1000 with a MySQL data truncation error, crashing the BSF→MySQL
-- ingest cron on every run for weeks and freezing BBT/CCT visuals on the
-- packaging page.
--
-- Architectural decision (operator, durable): QA/QC outliers are IMPORTED and
-- FLAGGED, never rejected. KPI calculators downstream filter on the flag columns.
-- The schema must accept any plausible real-world measurement.
--
-- Changes:
--   1. bbt_o2  DECIMAL(6,3)  → DECIMAL(10,3)
--      O2 pickup is measured in ppb. Healthy range ≤ 50 ppb; process incidents
--      can reach 10,000–80,000 ppb. DECIMAL(10,3) accepts up to 9,999,999.999.
--
--   2. bbt_co2 DECIMAL(6,3)  → DECIMAL(8,3)
--      CO2 is measured in g/L. Normal range 2.5–5.0 g/L. Widening to DECIMAL(8,3)
--      (max 99,999.999) guards against unit-confusion typos (e.g. ppm/ppb entered
--      in a g/L column) being silently truncated rather than flagged.
--
--   3. bbt_o2_flag  ENUM('normal','elevated','outlier','missing') DEFAULT 'missing'
--      bbt_co2_flag ENUM('normal','elevated','outlier','missing') DEFAULT 'missing'
--      Auto-computed at ingest time by tab_racking.py. The DEFAULT 'missing' value
--      backfills all pre-existing rows without a separate UPDATE pass.
--      ENUM with 'missing' covers NULL/empty-cell cases (operator skipped
--      measurement).
--
--   4. Two indexes for KPI filter queries.
--
-- Down-migration:
--   ALTER TABLE bd_racking DROP INDEX idx_bd_racking_co2_flag;
--   ALTER TABLE bd_racking DROP INDEX idx_bd_racking_o2_flag;
--   ALTER TABLE bd_racking DROP COLUMN bbt_co2_flag;
--   ALTER TABLE bd_racking DROP COLUMN bbt_o2_flag;
--   ALTER TABLE bd_racking MODIFY bbt_co2 DECIMAL(6,3) NULL;
--   ALTER TABLE bd_racking MODIFY bbt_o2  DECIMAL(6,3) NULL;

ALTER TABLE bd_racking MODIFY bbt_o2  DECIMAL(10,3) NULL;
ALTER TABLE bd_racking MODIFY bbt_co2 DECIMAL(8,3)  NULL;

ALTER TABLE bd_racking
  ADD COLUMN bbt_o2_flag  ENUM('normal','elevated','outlier','missing')
    NOT NULL DEFAULT 'missing' AFTER bbt_o2,
  ADD COLUMN bbt_co2_flag ENUM('normal','elevated','outlier','missing')
    NOT NULL DEFAULT 'missing' AFTER bbt_co2;

CREATE INDEX idx_bd_racking_o2_flag  ON bd_racking(bbt_o2_flag);
CREATE INDEX idx_bd_racking_co2_flag ON bd_racking(bbt_co2_flag);
