-- db/migrations/229_yeast_family_add_priming_hybrid.sql
--
-- What: Extend the yeast family ENUM in ref_yeast_strains and
--       ref_yeast_family_defaults to include 'priming' and 'hybrid'.
--       Seed matching rows in ref_yeast_family_defaults with NULL garde-min
--       (per-recipe fallback, no automatic racking gate).
--
-- Why : Biochimie catalog now classifies priming strains (refermentation in
--       bottle/can) and hybrid strains as first-class family values.
--       Operator explicitly chose ENUM-extend over a lookup table.
--       The companion PHP edit removes the deprecated 'type' UI field and
--       adds these families to YEAST_FAMILIES / YEAST_FAMILY_LABELS.
--
-- Risk: LOW — ENUM append is INSTANT in MySQL 8 (no table rebuild; values
--       appended at the end so ordinal positions of existing values are
--       unchanged). No existing data references 'priming' or 'hybrid' as
--       a family value (all 30 strains are currently NULL or one of the
--       original 5). ref_yeast_family_defaults PK extension follows
--       immediately so FK integrity is maintained.
--
-- Idempotency:
--   Both ALTER TABLEs are guarded via @ddl / PREPARE / EXECUTE so that if
--   the ENUM already contains the new values (partial prior run), MySQL
--   skips the DDL (DO 0). The INSERT ... ON DUPLICATE KEY UPDATE blocks
--   are no-ops if the rows already exist (PK = family value).
--   migrate.php records the filename in schema_migrations and does not
--   re-run this file — so idempotency guards are a safety net only.
--
-- Audit:
--   Seeding two new rows in ref_yeast_family_defaults is pure reference-data
--   bootstrap (equivalent to migration_167 for 'spontane'/'mixte').
--   Following the convention in migrations 219 and 167: no audit_row_revisions
--   entry for reference-bootstrap INSERTs (no pre-existing before-state to
--   capture). Audit trail lives in schema_migrations (migrate.php) + git log.
--
-- Rollback:
--   DELETE FROM ref_yeast_family_defaults WHERE family IN ('priming','hybrid');
--   -- Then revert ENUM via ALTER TABLE back to the original 5-value list.
--   -- ENUM shrinkage on a non-empty table requires a full table rebuild.
--
-- Date  : 2026-05-31
-- Author: migration_229


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 1: Extend ref_yeast_strains.family ENUM
--
-- Before: enum('ale','lager','non_alcool','spontane','mixte')  NULL
-- After : enum('ale','lager','non_alcool','spontane','mixte','priming','hybrid') NULL
--
-- Guard: check information_schema — if 'priming' is already in the ENUM,
-- skip the DDL (DO 0).
-- ═══════════════════════════════════════════════════════════════════════════════

SET @enum_has_priming_strains := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'ref_yeast_strains'
       AND COLUMN_NAME  = 'family'
       AND COLUMN_TYPE LIKE '%priming%'
);

SET @ddl_strains := IF(
    @enum_has_priming_strains = 0,
    "ALTER TABLE ref_yeast_strains
        MODIFY COLUMN family
        ENUM('ale','lager','non_alcool','spontane','mixte','priming','hybrid') NULL",
    "DO 0"
);
PREPARE stmt FROM @ddl_strains;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 2: Extend ref_yeast_family_defaults.family ENUM
--
-- Before: enum('ale','lager','non_alcool','spontane','mixte')  NOT NULL  PK
-- After : enum('ale','lager','non_alcool','spontane','mixte','priming','hybrid') NOT NULL  PK
--
-- The MODIFY on a PK column preserves PK status (key is unchanged, only
-- the domain is widened). Guard mirrors Step 1.
-- ═══════════════════════════════════════════════════════════════════════════════

SET @enum_has_priming_defaults := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'ref_yeast_family_defaults'
       AND COLUMN_NAME  = 'family'
       AND COLUMN_TYPE LIKE '%priming%'
);

SET @ddl_defaults := IF(
    @enum_has_priming_defaults = 0,
    "ALTER TABLE ref_yeast_family_defaults
        MODIFY COLUMN family
        ENUM('ale','lager','non_alcool','spontane','mixte','priming','hybrid') NOT NULL",
    "DO 0"
);
PREPARE stmt2 FROM @ddl_defaults;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 3: Seed ref_yeast_family_defaults rows for 'priming' and 'hybrid'
--
-- garde_days_min = NULL: no automatic racking gate for these families;
-- the form falls back to per-recipe override or hors-process path.
-- ferm_temp_min/max = NULL: highly variable; operator classifies per-strain.
-- is_produced = 0: neither family is a primary fermentation family at the
-- brewery (priming = refermentation aid; hybrid = edge-case).
-- is_active   = 1: visible in the catalog and eligible for strain assignment.
-- updated_by  = 'migration_229': bootstrap provenance.
--
-- ON DUPLICATE KEY UPDATE family=VALUES(family) is an idempotent no-op on
-- the PK — the row is left unchanged if it already exists.
-- ═══════════════════════════════════════════════════════════════════════════════

INSERT INTO ref_yeast_family_defaults
    (family, label_fr, garde_days_min, ferm_temp_min, ferm_temp_max, is_produced, is_active, updated_by)
VALUES
    ('priming', 'Priming (refermentation)', NULL, NULL, NULL, 0, 1, 'migration_229'),
    ('hybrid',  'Hybride',                  NULL, NULL, NULL, 0, 1, 'migration_229')
ON DUPLICATE KEY UPDATE
    family = VALUES(family);
