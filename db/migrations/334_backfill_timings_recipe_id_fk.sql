-- 334_backfill_timings_recipe_id_fk.sql
--
-- Backfill bd_brewing_timings_v2.recipe_id_fk on the 28 live rows where it is NULL.
--
-- Root cause (fixed same change): form-brewing.php built every sibling row
-- (brewday_v2, ingredients_v2, gravity_v2) with 'recipe_id_fk' => $recipeId but
-- OMITTED it from the timings_v2 row — so every brewing saisie since the v2 form
-- went live wrote a NULL-FK timings row. The writer now sets it; this backfills
-- the rows already written.
--
-- Resolution: the canonical per-(beer,batch) recipe assignment lives on the
-- brewday_v2 sibling (the form wrote $recipeId there). Dry-run verified all 28
-- NULL rows resolve unambiguously (brewday_v2 rid == ref_recipes base rid; 0
-- ambiguous, 0 unresolved). MAX() is safe — exactly one recipe_id per (beer,batch).
--
-- Guarded by recipe_id_fk IS NULL — never overwrites a populated FK. Idempotent
-- (a re-run matches no rows).

UPDATE bd_brewing_timings_v2 t
JOIN (
  SELECT beer, batch, MAX(recipe_id_fk) AS rid
    FROM bd_brewing_brewday_v2
   WHERE recipe_id_fk IS NOT NULL AND is_tombstoned = 0
   GROUP BY beer, batch
) b ON b.beer = t.beer AND b.batch = t.batch
SET t.recipe_id_fk = b.rid
WHERE t.recipe_id_fk IS NULL AND t.is_tombstoned = 0;
