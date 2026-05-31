-- ============================================================================
-- Migration 241: CREATE bd_tank_readings — in-tank (BBT pre-fill) CO₂/O₂ dimension
--
-- WHAT:
--   Creates table `bd_tank_readings` — one row per (beer, batch, day) lot-day,
--   capturing the single in-tank CO₂ and O₂ measurement taken from the BBT
--   (or CCT for serving-tank fills) before the filling session begins.
--
--   Two identity lanes:
--     Neb lane    — recipe_id_fk (INT UNSIGNED, FK→ref_recipes.id) + neb_batch
--     Contract    — contract_beer VARCHAR + contract_batch (recipe_id_fk NULL)
--
--   Two UNIQUE keys enforce "one in-tank read per lot-day per lane":
--     uq_tank_read_neb      (recipe_id_fk, neb_batch, read_date)
--     uq_tank_read_contract (contract_beer, contract_batch, read_date)
--
--   bbt_source_fk (INT UNSIGNED NULL) carries traceability toward the vessel
--   table; it carries NO FK constraint because the vessel table (ref_tanks /
--   ref_vessels) is not yet confirmed — the column is additive-only now and
--   will be constrained once that table lands.
--
--   source column distinguishes operator web-entry ('web_entry') from
--   retroactive RawDB import ('rawdb_v1').
--
-- WHY:
--   The current bd_packaging_co2o2_measures table is session-scoped (one row
--   per packaging run × sensor type).  The in-tank (BBT pre-fill) reading is
--   physically taken once per tank-fill event for a given lot and day, not
--   once per individual packaging run that draws from that tank.  Separating
--   the in-tank dimension into its own table avoids the session-bleed problem
--   (mig 240's self-FK inheritance mechanism) and gives the form a clean,
--   single-row surface to write the BBT read against, with the packaging runs
--   pointing at it via bd_packaging_v2.tank_read_id_fk (added by mig 242).
--
-- ROLLBACK:
--   -- Must drop the FK on bd_packaging_v2 first (mig 242).
--   -- Then:
--   DROP TABLE bd_tank_readings;
--   DELETE FROM schema_meta WHERE table_name = 'bd_tank_readings';
-- ============================================================================

CREATE TABLE `bd_tank_readings` (
  `id`               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `recipe_id_fk`     INT UNSIGNED     NULL     COMMENT 'Neb lane: FK to ref_recipes.id. NULL for contract lane.',
  `neb_batch`        VARCHAR(32)      NULL     COMMENT 'Neb lane batch identifier (e.g. "233"). NULL for contract lane.',
  `contract_beer`    VARCHAR(128)     NULL     COMMENT 'Contract lane beer name. NULL for Neb lane.',
  `contract_batch`   VARCHAR(32)      NULL     COMMENT 'Contract lane batch identifier. NULL for Neb lane.',
  `read_date`        DATE             NOT NULL COMMENT 'Date of the in-tank read = bd_packaging_v2.event_date. Use event_date, NEVER DATE(submitted_at).',
  `co2_gl`           DECIMAL(6,3)     NULL     COMMENT 'Dissolved CO₂ in g/L at read time.',
  `o2_ppb`           DECIMAL(8,2)     NULL     COMMENT 'Dissolved O₂ in ppb at read time.',
  `measured_at`      DATETIME(6)      NULL     COMMENT 'Exact timestamp of the instrument reading, when available.',
  `bbt_source_fk`    INT UNSIGNED     NULL     COMMENT 'Traceability: vessel (BBT/CCT) this read was taken from. NO FK constraint — vessel table not yet confirmed. Will be constrained in a future migration.',
  `source`           VARCHAR(32)      NOT NULL DEFAULT 'web_entry' COMMENT '"web_entry" = operator submitted via form-packaging.php; "rawdb_v1" = retroactive import from RawDB normalisation.',
  `created_by`       VARCHAR(255)     NULL     COMMENT 'Username or script identifier that created this row.',
  `created_at`       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tank_read_neb`      (`recipe_id_fk`, `neb_batch`, `read_date`),
  UNIQUE KEY `uq_tank_read_contract` (`contract_beer`, `contract_batch`, `read_date`),
  KEY `idx_tank_read_lotday` (`recipe_id_fk`, `neb_batch`, `read_date`),
  CONSTRAINT `fk_tank_read_recipe`
    FOREIGN KEY (`recipe_id_fk`) REFERENCES `ref_recipes` (`id`)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='In-tank (BBT/CCT pre-fill) CO₂/O₂ readings — one row per lot-day. Linked from bd_packaging_v2 via tank_read_id_fk (mig 242).';

-- schema_meta classification
INSERT INTO schema_meta
  (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
  ('bd_tank_readings', 'source', 'allowed',
   'public/modules/form-packaging.php',
   'In-tank (BBT pre-fill) CO₂/O₂ dimension: one row per (beer, batch, day) lot-day. Edit via the packaging form or direct SQL correction. Downstream: bd_packaging_v2.tank_read_id_fk points here (mig 242).');
