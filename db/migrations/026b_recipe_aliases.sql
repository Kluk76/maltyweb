-- 026b_recipe_aliases.sql
-- Pattern parallèle aux autres tables alias (yeast, suppliers, MI).
-- Mono-cible volontaire : un alias pointe vers UN recipe_id précis.
-- Voir CLAUDE.md DETTE TECHNIQUE pour limitations + workarounds (préfixe année).

CREATE TABLE IF NOT EXISTS ref_recipe_aliases (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  alias       VARCHAR(128) NOT NULL,
  recipe_id   INT UNSIGNED NOT NULL,
  notes       TEXT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_alias (alias),
  KEY idx_recipe_id (recipe_id),
  CONSTRAINT fk_recipe_alias_recipe
    FOREIGN KEY (recipe_id) REFERENCES ref_recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed initial (5 entrées)
-- Diversion variants = notation racking abrégée (ex: bd_racking.neb_beer 'Div.Blanche')
-- MeltingPote - IPA = recette one-off mappée à Cropette
-- Malt Capone = batch 2023 → EPH4 vintage=2023 (id=74) ; si réapparaît sur autre vintage,
--               créer alias année-suffixé (ex: 'Malt Capone 2026' → EPH4 2026)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes) VALUES
  ('Div.Blanche',         26, 'Notation racking abrégée; canonique = Diversion Blanche'),
  ('Div.Gose',            27, 'Notation racking abrégée; canonique = Diversion Gose'),
  ('Div.Panaché',         28, 'Notation racking abrégée; canonique = Diversion Panaché'),
  ('MeltingPote - IPA',   41, 'Recette one-off mappée à MeltingPote - Cropette par décision opérateur 2026-05-08'),
  ('Malt Capone',         74, 'Batch 2023 mappé à EPH4 vintage=2023; si réapparaît, créer alias année-suffixé');
