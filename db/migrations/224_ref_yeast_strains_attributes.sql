-- Migration 224: add strain-science columns to ref_yeast_strains
-- Adds flocculation, attenuation range, and fermentation temperature range
-- per strain (distinct from per-family defaults in ref_yeast_family_defaults).
-- These columns are editable from the Biochimie strain catalog in salle-de-controle.php.
-- No default values — all nullable, operator fills via the new per-strain editor.
--
-- Idempotent: each ADD COLUMN is guarded against re-application via
-- information_schema so a partial prior run cannot wedge migrate.php.
-- migrate.php tracks applied files by filename (schema_migrations.filename) and
-- inserts that row itself — do NOT INSERT into schema_migrations here.

SET @ddl := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ref_yeast_strains'
      AND COLUMN_NAME = 'flocculation') = 0,
  "ALTER TABLE ref_yeast_strains
     ADD COLUMN flocculation    ENUM('low','medium','high') NULL,
     ADD COLUMN attenuation_min DECIMAL(5,2) NULL COMMENT 'Attenuation min in %',
     ADD COLUMN attenuation_max DECIMAL(5,2) NULL COMMENT 'Attenuation max in %',
     ADD COLUMN temp_min        DECIMAL(4,1) NULL COMMENT 'Fermentation temp min in C',
     ADD COLUMN temp_max        DECIMAL(4,1) NULL COMMENT 'Fermentation temp max in C'",
  "DO 0"
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
