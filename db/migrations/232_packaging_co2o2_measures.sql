-- Migration 232: per-session CO₂/O₂ pair measurements table for packaging runs
--
-- What: Creates bd_packaging_co2o2_measures to store up to 20 CO₂/O₂ reading
--       pairs per packaging session. Replaces the dead tank_co2/tank_o2 cuve
--       single-reading plumbing (removed in Atom A2) which wrote to columns that
--       never existed on bd_packaging_v2.
--
-- Why:  Operator needs to record multiple CO₂/O₂ readings taken at intervals
--       during a single packaging run. A single pre-fill value is inadequate.
--       Readings are anchored to the main row of the session (row_origin='main')
--       via FK to bd_packaging_v2.id (BIGINT UNSIGNED).
--
-- Rollback: DROP TABLE bd_packaging_co2o2_measures;
--
-- FK notes:
--   - packaging_id_fk is BIGINT UNSIGNED to match bd_packaging_v2.id exactly
--     (anti-pattern #3: FK type mismatch BIGINT vs INT silently fails on INSERT).
--   - ON DELETE CASCADE: child readings are meaningless without the parent row.
--   - CHECK constraint ensures at least one of co2_gl / o2_ppb is present per row.

CREATE TABLE bd_packaging_co2o2_measures (
  id                BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
  packaging_id_fk   BIGINT UNSIGNED     NOT NULL,
  reading_index     TINYINT UNSIGNED    NOT NULL,
  co2_gl            DECIMAL(6,3)        NULL,
  o2_ppb            DECIMAL(8,2)        NULL,
  measured_at       DATETIME(6)         NULL,
  source            VARCHAR(32)         NOT NULL DEFAULT 'web_entry',
  imported_at       DATETIME(6)         NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pkg_reading (packaging_id_fk, reading_index),
  CONSTRAINT fk_co2o2_packaging FOREIGN KEY (packaging_id_fk)
    REFERENCES bd_packaging_v2 (id) ON DELETE CASCADE,
  CONSTRAINT chk_co2o2_not_both_null CHECK (co2_gl IS NOT NULL OR o2_ppb IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
  (
    'bd_packaging_co2o2_measures',
    'source',
    'public/modules/form-packaging.php',
    'allowed',
    NULL,
    'CO2/O2 QA pair readings per packaging session; standard form ≤20 pairs; replaces dead tank_co2/tank_o2 cuve fields (Atom A2)'
  );
