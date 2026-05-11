-- 026e_recipe_id_columns.sql
-- Recipe FKs option B : colonne *_recipe_id parallèle au raw, FK vers ref_recipes.id.
-- Backfill 3-passes : (1) name + vintage = YEAR(event_date), (2) name + vintage='', (3) alias.
-- Raws conservés pour audit.
-- Pré-vol : `validate_fk_candidates.py` doit passer après 026b.
-- Rows EPH avec vintage absente du référentiel restent recipe_id=NULL (pas un blocker FK).
--
-- DDL non-idempotent — en cas d'échec mid-migration, restaurer depuis backup
-- et fixer la cause avant relance.
--
-- IMPORTANT : pour voir le récap unresolved (et dumper /tmp/026e-unresolved-recipes-*.log),
-- exécuter APRÈS `migrate.php` :
--   /var/www/maltytask/.venv/bin/python scripts/python/validate_fk_candidates.py --report-unresolved-recipes

-- =================================================================
-- bd_brewing_brewday.bd_beer → ref_recipes
-- =================================================================
ALTER TABLE bd_brewing_brewday
  ADD COLUMN bd_beer_recipe_id INT UNSIGNED NULL AFTER bd_beer;

-- Pass 1: name + vintage = YEAR(event_date)
UPDATE bd_brewing_brewday b
  JOIN ref_recipes r ON r.name = b.bd_beer
    AND r.vintage = CAST(YEAR(b.event_date) AS CHAR) COLLATE utf8mb4_unicode_ci
  SET b.bd_beer_recipe_id = r.id
  WHERE b.bd_beer IS NOT NULL AND b.bd_beer_recipe_id IS NULL AND b.event_date IS NOT NULL;

-- Pass 2: name + vintage=''
UPDATE bd_brewing_brewday b
  JOIN ref_recipes r ON r.name = b.bd_beer AND r.vintage = ''
  SET b.bd_beer_recipe_id = r.id
  WHERE b.bd_beer IS NOT NULL AND b.bd_beer_recipe_id IS NULL;

-- Pass 3: alias
UPDATE bd_brewing_brewday b
  JOIN ref_recipe_aliases a ON a.alias = b.bd_beer
  SET b.bd_beer_recipe_id = a.recipe_id
  WHERE b.bd_beer IS NOT NULL AND b.bd_beer_recipe_id IS NULL;

ALTER TABLE bd_brewing_brewday
  ADD CONSTRAINT fk_brewday_beer_recipe
    FOREIGN KEY (bd_beer_recipe_id) REFERENCES ref_recipes(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- =================================================================
-- bd_brewing_cooling.cool_beer → ref_recipes
-- =================================================================
ALTER TABLE bd_brewing_cooling
  ADD COLUMN cool_beer_recipe_id INT UNSIGNED NULL AFTER cool_beer;

UPDATE bd_brewing_cooling b
  JOIN ref_recipes r ON r.name = b.cool_beer AND r.vintage = CAST(YEAR(b.event_date) AS CHAR) COLLATE utf8mb4_unicode_ci
  SET b.cool_beer_recipe_id = r.id
  WHERE b.cool_beer IS NOT NULL AND b.cool_beer_recipe_id IS NULL AND b.event_date IS NOT NULL;

UPDATE bd_brewing_cooling b
  JOIN ref_recipes r ON r.name = b.cool_beer AND r.vintage = ''
  SET b.cool_beer_recipe_id = r.id
  WHERE b.cool_beer IS NOT NULL AND b.cool_beer_recipe_id IS NULL;

UPDATE bd_brewing_cooling b
  JOIN ref_recipe_aliases a ON a.alias = b.cool_beer
  SET b.cool_beer_recipe_id = a.recipe_id
  WHERE b.cool_beer IS NOT NULL AND b.cool_beer_recipe_id IS NULL;

ALTER TABLE bd_brewing_cooling
  ADD CONSTRAINT fk_cooling_beer_recipe
    FOREIGN KEY (cool_beer_recipe_id) REFERENCES ref_recipes(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- =================================================================
-- bd_brewing_gravity.beer → ref_recipes
-- =================================================================
ALTER TABLE bd_brewing_gravity
  ADD COLUMN beer_recipe_id INT UNSIGNED NULL AFTER beer;

UPDATE bd_brewing_gravity b
  JOIN ref_recipes r ON r.name = b.beer AND r.vintage = CAST(YEAR(b.event_date) AS CHAR) COLLATE utf8mb4_unicode_ci
  SET b.beer_recipe_id = r.id
  WHERE b.beer IS NOT NULL AND b.beer_recipe_id IS NULL AND b.event_date IS NOT NULL;

UPDATE bd_brewing_gravity b
  JOIN ref_recipes r ON r.name = b.beer AND r.vintage = ''
  SET b.beer_recipe_id = r.id
  WHERE b.beer IS NOT NULL AND b.beer_recipe_id IS NULL;

UPDATE bd_brewing_gravity b
  JOIN ref_recipe_aliases a ON a.alias = b.beer
  SET b.beer_recipe_id = a.recipe_id
  WHERE b.beer IS NOT NULL AND b.beer_recipe_id IS NULL;

ALTER TABLE bd_brewing_gravity
  ADD CONSTRAINT fk_gravity_beer_recipe
    FOREIGN KEY (beer_recipe_id) REFERENCES ref_recipes(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- =================================================================
-- bd_brewing_timings.beer → ref_recipes
-- =================================================================
ALTER TABLE bd_brewing_timings
  ADD COLUMN beer_recipe_id INT UNSIGNED NULL AFTER beer;

UPDATE bd_brewing_timings b
  JOIN ref_recipes r ON r.name = b.beer AND r.vintage = CAST(YEAR(b.event_date) AS CHAR) COLLATE utf8mb4_unicode_ci
  SET b.beer_recipe_id = r.id
  WHERE b.beer IS NOT NULL AND b.beer_recipe_id IS NULL AND b.event_date IS NOT NULL;

UPDATE bd_brewing_timings b
  JOIN ref_recipes r ON r.name = b.beer AND r.vintage = ''
  SET b.beer_recipe_id = r.id
  WHERE b.beer IS NOT NULL AND b.beer_recipe_id IS NULL;

UPDATE bd_brewing_timings b
  JOIN ref_recipe_aliases a ON a.alias = b.beer
  SET b.beer_recipe_id = a.recipe_id
  WHERE b.beer IS NOT NULL AND b.beer_recipe_id IS NULL;

ALTER TABLE bd_brewing_timings
  ADD CONSTRAINT fk_timings_beer_recipe
    FOREIGN KEY (beer_recipe_id) REFERENCES ref_recipes(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- =================================================================
-- bd_brewing_ingredients.ing_beer → ref_recipes
-- =================================================================
ALTER TABLE bd_brewing_ingredients
  ADD COLUMN ing_beer_recipe_id INT UNSIGNED NULL AFTER ing_beer;

UPDATE bd_brewing_ingredients b
  JOIN ref_recipes r ON r.name = b.ing_beer AND r.vintage = CAST(YEAR(b.event_date) AS CHAR) COLLATE utf8mb4_unicode_ci
  SET b.ing_beer_recipe_id = r.id
  WHERE b.ing_beer IS NOT NULL AND b.ing_beer_recipe_id IS NULL AND b.event_date IS NOT NULL;

UPDATE bd_brewing_ingredients b
  JOIN ref_recipes r ON r.name = b.ing_beer AND r.vintage = ''
  SET b.ing_beer_recipe_id = r.id
  WHERE b.ing_beer IS NOT NULL AND b.ing_beer_recipe_id IS NULL;

UPDATE bd_brewing_ingredients b
  JOIN ref_recipe_aliases a ON a.alias = b.ing_beer
  SET b.ing_beer_recipe_id = a.recipe_id
  WHERE b.ing_beer IS NOT NULL AND b.ing_beer_recipe_id IS NULL;

ALTER TABLE bd_brewing_ingredients
  ADD CONSTRAINT fk_ingredients_beer_recipe
    FOREIGN KEY (ing_beer_recipe_id) REFERENCES ref_recipes(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- =================================================================
-- bd_racking.neb_beer → ref_recipes  (passes 2+3 uniquement, pas d'event_date utilisable)
-- =================================================================
ALTER TABLE bd_racking
  ADD COLUMN neb_beer_recipe_id INT UNSIGNED NULL AFTER neb_beer;

UPDATE bd_racking b
  JOIN ref_recipes r ON r.name = b.neb_beer AND r.vintage = ''
  SET b.neb_beer_recipe_id = r.id
  WHERE b.neb_beer IS NOT NULL AND b.neb_beer_recipe_id IS NULL;

UPDATE bd_racking b
  JOIN ref_recipe_aliases a ON a.alias = b.neb_beer
  SET b.neb_beer_recipe_id = a.recipe_id
  WHERE b.neb_beer IS NOT NULL AND b.neb_beer_recipe_id IS NULL;

ALTER TABLE bd_racking
  ADD CONSTRAINT fk_racking_neb_beer_recipe
    FOREIGN KEY (neb_beer_recipe_id) REFERENCES ref_recipes(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- =================================================================
-- bd_racking.contract_beer → ref_recipes  (passes 2+3)
-- =================================================================
ALTER TABLE bd_racking
  ADD COLUMN contract_beer_recipe_id INT UNSIGNED NULL AFTER contract_beer;

UPDATE bd_racking b
  JOIN ref_recipes r ON r.name = b.contract_beer AND r.vintage = ''
  SET b.contract_beer_recipe_id = r.id
  WHERE b.contract_beer IS NOT NULL AND b.contract_beer_recipe_id IS NULL;

UPDATE bd_racking b
  JOIN ref_recipe_aliases a ON a.alias = b.contract_beer
  SET b.contract_beer_recipe_id = a.recipe_id
  WHERE b.contract_beer IS NOT NULL AND b.contract_beer_recipe_id IS NULL;

ALTER TABLE bd_racking
  ADD CONSTRAINT fk_racking_contract_beer_recipe
    FOREIGN KEY (contract_beer_recipe_id) REFERENCES ref_recipes(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- =================================================================
-- RÉCAP : unresolved recipe_id par couple (table, colonne)
-- Silencieux sous php scripts/migrate.php (PDO::exec discards SELECT output).
-- Pour récap+dump : `python scripts/python/validate_fk_candidates.py --report-unresolved-recipes`
-- =================================================================
SELECT 'bd_brewing_brewday.bd_beer' AS source, COUNT(*) AS unresolved
  FROM bd_brewing_brewday WHERE bd_beer IS NOT NULL AND bd_beer_recipe_id IS NULL
UNION ALL
SELECT 'bd_brewing_cooling.cool_beer', COUNT(*)
  FROM bd_brewing_cooling WHERE cool_beer IS NOT NULL AND cool_beer_recipe_id IS NULL
UNION ALL
SELECT 'bd_brewing_gravity.beer', COUNT(*)
  FROM bd_brewing_gravity WHERE beer IS NOT NULL AND beer_recipe_id IS NULL
UNION ALL
SELECT 'bd_brewing_timings.beer', COUNT(*)
  FROM bd_brewing_timings WHERE beer IS NOT NULL AND beer_recipe_id IS NULL
UNION ALL
SELECT 'bd_brewing_ingredients.ing_beer', COUNT(*)
  FROM bd_brewing_ingredients WHERE ing_beer IS NOT NULL AND ing_beer_recipe_id IS NULL
UNION ALL
SELECT 'bd_racking.neb_beer', COUNT(*)
  FROM bd_racking WHERE neb_beer IS NOT NULL AND neb_beer_recipe_id IS NULL
UNION ALL
SELECT 'bd_racking.contract_beer', COUNT(*)
  FROM bd_racking WHERE contract_beer IS NOT NULL AND contract_beer_recipe_id IS NULL;
