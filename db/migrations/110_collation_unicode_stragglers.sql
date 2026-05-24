-- db/migrations/110_collation_unicode_stragglers.sql
-- What: Convert the last four utf8mb4_0900_ai_ci tables to utf8mb4_unicode_ci,
--       the DB-wide standard. These are the only tables left on 0900_ai_ci.
-- Why:  DBA Phase-1-boundary RED #1. A text-JOIN across the collation boundary
--       throws "Illegal mix of collations". Two live coupling points make this
--       not hypothetical:
--         1. legacy bd_brewing_ingredients_parsed (0900) is text-JOINed to its
--            v2 successor (unicode) during any cutover-diff;
--         2. refresh_recipe_profile.py joins bd_brewing_ingredients_parsed to
--            ref_recipe_profile* — converting only the profile tables would
--            CREATE a mismatch where none exists today, so all four move together.
-- Note: both 0900_ai_ci and unicode_ci are accent- and case-insensitive, so
--       comparison/dedup semantics are preserved. FK columns here are INT (no
--       collation), so referential integrity is unaffected.
-- Risk: Low. CONVERT TO on small tables (1813 + three small profile tables),
--       rebuild is near-instant. Idempotent (re-converting to the same collation
--       is a no-op). The recipe-profile cron runs at 03:00 UTC — applied outside
--       that window.
-- Rollback (not recommended): CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci.

ALTER TABLE bd_brewing_ingredients_parsed CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ref_recipe_profile            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ref_recipe_profile_hops       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE ref_recipe_profile_malt       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @migration_110_done = 1;
