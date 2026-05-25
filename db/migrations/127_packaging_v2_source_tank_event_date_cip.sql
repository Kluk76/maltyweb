-- db/migrations/127_packaging_v2_source_tank_event_date_cip.sql
-- What: Add source-tank model (source_tank_type ENUM + bbt_source_fk + cct_source_fk
--       + ENFORCED CHECK), event_date DATE, and CIP fields to bd_packaging_v2.
-- Why:  Operator form rework (decisions 1-4): durable source-tank traceability per the
--       ENUM-type + N-mutually-exclusive-FK + CHECK pattern; event_date as a first-class
--       column distinct from submitted_at (for month-close); CIP questions mirrored from
--       bd_racking_v2 (the packaging analog — BBT/tank CIP + machines/line CIP).
-- Risk: LOW — ADD COLUMN (nullable) on existing table; ALGORITHM=INSTANT for each.
--       No data-loss. No existing consumer reads the new columns yet.
-- Rollback:
--   ALTER TABLE bd_packaging_v2
--     DROP FOREIGN KEY fk_bdpv2_bbt_source,
--     DROP FOREIGN KEY fk_bdpv2_cct_source,
--     DROP CONSTRAINT bd_packaging_v2_chk_source_tank_exclusive,
--     DROP COLUMN source_tank_type,
--     DROP COLUMN bbt_source_fk,
--     DROP COLUMN cct_source_fk,
--     DROP COLUMN event_date,
--     DROP COLUMN cip_tank_done,
--     DROP COLUMN cip_tank_type,
--     DROP COLUMN cip_tank_date,
--     DROP COLUMN cip_machines_done,
--     DROP COLUMN cip_machines_type,
--     DROP COLUMN cip_machines_date;

-- ── 1. Source-tank model ────────────────────────────────────────────────────
-- Operator decision 2: ENUM type + N mutually-exclusive FK + ENFORCED CHECK.
-- The packaging session draws from exactly one source tank (BBT in ~99% of cases,
-- CCT in edge cases). Both FK columns are INT UNSIGNED matching ref_bbt.id and
-- ref_cct.id (both INT UNSIGNED AUTO_INCREMENT — confirmed before migration).
-- The CHECK enforces: exactly one of bbt_source_fk / cct_source_fk is NOT NULL,
-- and it agrees with source_tank_type.
-- ON DELETE RESTRICT: we must never silently unlink a packaging row from its source
-- tank. CASCADE-FK columns cannot participate in a CHECK, so RESTRICT is correct.
-- Note: a CASCADE-FK column cannot sit in a CHECK (MySQL 8 limitation), so we use
-- RESTRICT + the CHECK validates the type/value pairing within the row.

ALTER TABLE bd_packaging_v2
  ADD COLUMN source_tank_type ENUM('BBT','CCT') COLLATE utf8mb4_unicode_ci NULL
    COMMENT 'Type of the source tank (BBT or CCT). Must match bbt_source_fk / cct_source_fk.',
  ADD COLUMN bbt_source_fk INT UNSIGNED NULL
    COMMENT 'FK to ref_bbt.id — set when source_tank_type=BBT. NULL for CCT.',
  ADD COLUMN cct_source_fk INT UNSIGNED NULL
    COMMENT 'FK to ref_cct.id — set when source_tank_type=CCT. NULL for BBT.';

-- Add FKs separately (must follow ADD COLUMN in MySQL 8 multi-part ALTER)
ALTER TABLE bd_packaging_v2
  ADD CONSTRAINT fk_bdpv2_bbt_source
    FOREIGN KEY (bbt_source_fk) REFERENCES ref_bbt(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_bdpv2_cct_source
    FOREIGN KEY (cct_source_fk) REFERENCES ref_cct(id) ON DELETE RESTRICT;

-- ENFORCED CHECK: when source_tank_type is set, exactly one FK is non-NULL and
-- matches the type. NULL source_tank_type = legacy rows where source was not recorded.
-- CHECK name is schema-unique (bd_packaging_v2_ prefix).
ALTER TABLE bd_packaging_v2
  ADD CONSTRAINT bd_packaging_v2_chk_source_tank_exclusive CHECK (
    source_tank_type IS NULL
    OR (
      source_tank_type = 'BBT'
      AND bbt_source_fk IS NOT NULL
      AND cct_source_fk IS NULL
    )
    OR (
      source_tank_type = 'CCT'
      AND cct_source_fk IS NOT NULL
      AND bbt_source_fk IS NULL
    )
  );

-- ── 2. event_date ────────────────────────────────────────────────────────────
-- Operator decision 3: explicit packaging date, defaults to today in the form.
-- submitted_at is the wall-clock submission timestamp; event_date is the actual
-- packaging day (operator-chosen, can differ if form is filled retrospectively).
-- Drives month-close attribution.

ALTER TABLE bd_packaging_v2
  ADD COLUMN event_date DATE NULL
    COMMENT 'Date of the packaging run (operator-chosen). Defaults to today in the form. Drives month-close.';

-- Index for date-ranged queries
ALTER TABLE bd_packaging_v2
  ADD KEY idx_event_date (event_date);

-- ── 3. CIP fields ────────────────────────────────────────────────────────────
-- Operator decision 4: mirror the CIP structure from bd_racking_v2 adapted for
-- the packaging context. The original PackagingData Google Form (per maltytask-spec)
-- tracked two CIP surfaces: BBT/tank CIP and machines/line CIP.
--
-- Mirroring bd_racking_v2 column model (cip_bbt_done / cip_bbt_type / cip_bbt_date
-- and last_cip_date / cip_type) — adapted for the two packaging CIP surfaces:
--   - cip_tank_*   : CIP of the source BBT/tank (equivalent to cip_bbt_* in racking)
--   - cip_machines_*: CIP of the packaging line / machines
--
-- All varchar for free-text operator entry (matching bd_racking_v2 convention).
-- NULL = not entered (no NOT NULL constraint — not all sessions will record CIP).

ALTER TABLE bd_packaging_v2
  ADD COLUMN cip_tank_done VARCHAR(8) COLLATE utf8mb4_unicode_ci NULL
    COMMENT 'CIP source tank (BBT/CCT) done? Oui/Non — mirrors cip_bbt_done in bd_racking_v2',
  ADD COLUMN cip_tank_type VARCHAR(64) COLLATE utf8mb4_unicode_ci NULL
    COMMENT 'CIP source tank type (e.g. NaOH, PAA, Rinse) — mirrors cip_bbt_type in bd_racking_v2',
  ADD COLUMN cip_tank_date VARCHAR(32) COLLATE utf8mb4_unicode_ci NULL
    COMMENT 'Date of source tank CIP (free-text as entered) — mirrors cip_bbt_date in bd_racking_v2',
  ADD COLUMN cip_machines_done VARCHAR(8) COLLATE utf8mb4_unicode_ci NULL
    COMMENT 'CIP machines/packaging-line done? Oui/Non',
  ADD COLUMN cip_machines_type VARCHAR(64) COLLATE utf8mb4_unicode_ci NULL
    COMMENT 'CIP machines type (e.g. NaOH rinse, PAA)',
  ADD COLUMN cip_machines_date VARCHAR(32) COLLATE utf8mb4_unicode_ci NULL
    COMMENT 'Date of machines CIP (free-text as entered)';

-- ── schema_meta: no new row needed ───────────────────────────────────────────
-- bd_packaging_v2 is already classified in schema_meta (source / allowed).
-- New columns on an existing table do not require a schema_meta update.
