-- db/migrations/099_bd_brewing_ingredients_parsed_v2_fix_nk.sql
-- What: Fix the natural key of bd_brewing_ingredients_parsed_v2.
--       The existing UNIQUE KEY uq_natural_key (header_id, line_idx) is wrong:
--       line_idx resets per category (malt=0,1,2... and hops_kettle=0,1,2...
--       for the same batch — identical (header_id, line_idx) values collision).
--       Correct NK is (header_id, category, line_idx).
-- Why:  During migration 095 upload, 543 out of 1550 source lines were silently
--       overwritten by ON DUPLICATE KEY UPDATE because the old NK collapsed
--       cross-category line_idx duplicates. Result: 1007 rows instead of 1550.
-- Impact: Additive ALTER (drop old constraint, add new one). Requires re-truncating
--         the parsed table and re-running the Python upload to restore all 1550 rows.
-- Risk: Low on empty-ish table. Rollback below if needed.
-- Rollback:
--   ALTER TABLE bd_brewing_ingredients_parsed_v2
--     DROP INDEX uq_natural_key,
--     ADD UNIQUE KEY uq_natural_key (header_id, line_idx);
--   (then re-upload)

-- Step 1: Drop the incorrect unique constraint
ALTER TABLE bd_brewing_ingredients_parsed_v2
  DROP INDEX uq_natural_key;

-- Step 2: Add the correct 3-column unique constraint
ALTER TABLE bd_brewing_ingredients_parsed_v2
  ADD UNIQUE KEY uq_natural_key (header_id, category, line_idx);

-- Step 3: Truncate to remove the 1007 partially-correct rows
--         (they have the right data for the last line_idx per category,
--          but the first 543 overwrote rows are lost — re-upload restores all 1550).
TRUNCATE TABLE bd_brewing_ingredients_parsed_v2;

-- Step 4: Re-run the Python upload script to restore all 1550 rows:
--   cd /var/www/maltytask && sudo -u www-data .venv/bin/python3 scripts/python/ingest_bd_brewing_v2.py --apply --table ingredients
--
-- Verification after re-upload (run manually, expect 1550 total):
-- SELECT COUNT(*) AS total_lines FROM bd_brewing_ingredients_parsed_v2;
-- SELECT category, COUNT(*) AS n FROM bd_brewing_ingredients_parsed_v2 GROUP BY category;

SET @migration_099_nk_fix_done = 1;
