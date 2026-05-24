-- db/migrations/112_parsed_v2_enum_extend.sql
-- What: Extend bd_brewing_ingredients_parsed_v2 ENUMs so recipe-retrofit rows fit:
--       category += 'adjunct','mineral','process'  (was malt/hops_kettle/hops_dry)
--       unit     += 'ml'                            (was kg/g)
-- Why:  The recipe-ingredient retrofit (scripts/retrofit-brewing-recipe-ingredients.ts)
--       materializes minerals (g), process aids (Phosphorique/Yeastvit/Clarex/Dehaze,
--       ml/g) and kettle adjuncts (Coriander/Orange Peel, g) that the observed brewing
--       form never captured. The existing ENUMs can't hold those category/unit values,
--       so the retrofit's --apply pre-flight aborts until this lands.
-- Note: appending ENUM members is non-destructive — existing rows (all malt/hops_*,
--       kg/g) remain valid. MODIFY on a ~1550-row table is near-instant.
-- Risk: Low. Pure widening of allowed values; no row rewrite of data.
-- Rollback (only if zero rows use the new members):
--   ALTER TABLE bd_brewing_ingredients_parsed_v2
--     MODIFY COLUMN category ENUM('malt','hops_kettle','hops_dry') NOT NULL,
--     MODIFY COLUMN unit     ENUM('kg','g') NULL;

ALTER TABLE bd_brewing_ingredients_parsed_v2
  MODIFY COLUMN category ENUM('malt','hops_kettle','hops_dry','adjunct','mineral','process') NOT NULL;

ALTER TABLE bd_brewing_ingredients_parsed_v2
  MODIFY COLUMN unit ENUM('kg','g','ml') NULL;

SET @migration_112_done = 1;
