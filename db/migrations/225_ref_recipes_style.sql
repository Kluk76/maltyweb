-- Migration 225: add style column to ref_recipes
-- Brewing style, operator-editable from the recipe detail header in Salle de contrôle
-- (e.g. "West Coast IPA", "Gose", "Mixed Fermentation Saison").
-- NULL = not yet classified by operator; empty string stored as NULL via PHP trim.
--
-- Idempotent: ADD COLUMN is guarded against re-application via information_schema
-- so a partial prior run cannot wedge migrate.php.
-- migrate.php tracks applied files by filename (schema_migrations.filename) and
-- inserts that row itself — do NOT INSERT into schema_migrations here.

SET @ddl := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ref_recipes'
      AND COLUMN_NAME  = 'style') = 0,
  "ALTER TABLE ref_recipes
     ADD COLUMN style VARCHAR(64) NULL COMMENT 'Brewing style, operator-editable (e.g. West Coast IPA, Gose)'",
  "DO 0"
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
