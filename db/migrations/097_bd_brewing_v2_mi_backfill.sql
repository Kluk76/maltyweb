-- db/migrations/097_bd_brewing_v2_mi_backfill.sql
-- What: Backfill mi_id_fk in bd_brewing_ingredients_parsed_v2 for rows where
--       mi_id_fk IS NULL but raw_name resolves via ref_mi.mi_id or ref_mi_aliases.alias.
--   Step 1: Direct mi_id match (raw_name matches ref_mi.mi_id exactly, case-insensitive).
--   Step 2: Alias match (raw_name matches ref_mi_aliases.alias, case-insensitive).
-- Why:  Migration 095 (Python upload) sets mi_id_fk=NULL + audit_flags='mi_unresolved:X'
--       when mi_id_resolved is absent from the xlsx. Some raw_names in the Ingredients
--       sheet pre-date the current mi_id scheme but match existing aliases.
-- Risk: Low. UPDATE only on rows WHERE mi_id_fk IS NULL. Exact + alias match only — no
--       fuzzy matching, no guesses. Leaves genuinely novel names NULL for operator triage.
-- Rollback: UPDATE bd_brewing_ingredients_parsed_v2 SET mi_id_fk=NULL
--           WHERE audit_flags LIKE '%mi_backfilled%';

-- ─── Step 1: direct mi_id match ──────────────────────────────────────────────

UPDATE bd_brewing_ingredients_parsed_v2 bp
JOIN ref_mi mi
  ON LOWER(TRIM(mi.mi_id)) = LOWER(TRIM(bp.raw_name))
SET
  bp.mi_id_fk = mi.id,
  bp.confidence = 'exact_mi_id',
  bp.updated_at = NOW()
WHERE bp.mi_id_fk IS NULL;

-- ─── Step 2: alias match ─────────────────────────────────────────────────────

UPDATE bd_brewing_ingredients_parsed_v2 bp
JOIN ref_mi_aliases al
  ON LOWER(TRIM(al.alias)) = LOWER(TRIM(bp.raw_name))
SET
  bp.mi_id_fk = al.mi_id_fk,
  bp.confidence = 'alias',
  bp.updated_at = NOW()
WHERE bp.mi_id_fk IS NULL;

-- ─── Step 3: residual NULL summary ───────────────────────────────────────────
-- Run these manually to find raw_names needing a new alias or MI entry.
--
-- SELECT bp.raw_name, bp.category, COUNT(*) AS n, MIN(bh.event_date) AS first_seen
-- FROM bd_brewing_ingredients_parsed_v2 bp
-- JOIN bd_brewing_ingredients_v2 bh ON bh.id = bp.header_id
-- WHERE bp.mi_id_fk IS NULL
-- GROUP BY bp.raw_name, bp.category ORDER BY n DESC LIMIT 50;

SET @migration_097_backfill_done = 1;
