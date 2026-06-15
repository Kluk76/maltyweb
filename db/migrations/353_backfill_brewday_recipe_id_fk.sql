-- 353_backfill_brewday_recipe_id_fk.sql
--
-- Backfill bd_brewing_brewday_v2.recipe_id_fk on the 1 live row where it is NULL.
--
-- Root cause (hardened in the same change): form-brewing.php read recipe_id_fk
-- from a JS-populated hidden field (post_int('recipe_id_fk')). The JS at
-- public/js/form-brewing.js only sets that hidden field on the dropdown 'change'
-- event. When the operator does not re-pick the recipe (preselected, sticky value,
-- or edit-mode reload), the hidden field stays empty and a NULL FK is saved.
-- The server never validated the FK before this fix.
--
-- Resolution: match each NULL-FK brewday row to the unique active recipe in
-- ref_recipes whose name equals bd_brewing_brewday_v2.beer. The subquery uses
-- GROUP BY name HAVING COUNT(*)=1 so that any beer with two or more active
-- recipes of the same name is left NULL (refused, not guessed).
--
-- Dry-run verification (confirmed 2026-06-15):
--   - 1 target row: id=1465, beer='Stirling', batch='172', cct=2,
--     event_date=2026-06-12
--   - Resolves unambiguously to ref_recipes.id=52 ('Stirling', is_active=1)
--   - 0 ambiguous names, 0 unresolved names
--
-- Idempotent: guarded by recipe_id_fk IS NULL AND is_tombstoned = 0 — a re-run
-- matches no rows after the first successful apply.

UPDATE bd_brewing_brewday_v2 b
JOIN (
  SELECT name, MIN(id) AS rid
    FROM ref_recipes
   WHERE is_active = 1
   GROUP BY name
  HAVING COUNT(*) = 1
) r ON r.name = b.beer
SET b.recipe_id_fk = r.rid
WHERE b.recipe_id_fk IS NULL AND b.is_tombstoned = 0;
