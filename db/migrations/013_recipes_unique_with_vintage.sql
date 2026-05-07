-- 013 — ref_recipes: UNIQUE(name) → UNIQUE(name, vintage).
-- BeerTypes has same beer names across multiple vintages (EPH1 2022 + EPH1 2023
-- are distinct recipes). The single-column UNIQUE silently dropped the second.
-- MySQL allows multiple NULL combinations in a UNIQUE index, so non-vintage
-- recipes (Zepp, etc. with vintage IS NULL) still uniquely identify by name.

ALTER TABLE ref_recipes
  DROP INDEX uniq_name,
  ADD UNIQUE KEY uniq_name_vintage (name, vintage);
