-- 015 — EPH recipes are one-off per vintage; display name must include the year
-- so "EPH1 2024" is never confused with "EPH1 2023" (different recipes entirely).
-- The bd_brewing_brewday convention is bd_batch = last-2-of-year (validated:
-- 19/19 rows match across 2021-2026), so vintage is recoverable from raw data.

UPDATE ref_recipes
   SET recipe_short_name = CONCAT(name, ' ', vintage)
 WHERE subtype = 'EPH';
