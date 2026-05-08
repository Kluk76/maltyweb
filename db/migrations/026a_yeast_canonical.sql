-- 026a_yeast_canonical.sql
-- Seed canonique des yeast strains + aliases. Self-contained.
-- Remplace fonctionnellement migration 025 (bug fresh-DB ordering).
-- 'New Yeast' réinséré is_active=0 = garde-fou anti-pollution silencieuse + audit historique.
--
-- DDL non-idempotent : en cas d'échec mid-migration, restaurer depuis backup
-- et fixer la cause avant relance. Les INSERT IGNORE et FK DROP/ADD sont
-- idempotents en revanche.

-- 0. Ajouter colonne is_active (manquante dans migration 012 d'origine — voir CLAUDE.md DETTE TECHNIQUE)
--    Non-idempotent : un re-run plantera ici si la colonne existe déjà.
ALTER TABLE ref_yeast_strains
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER name;

-- 1. Strains canoniques actifs (29 souches)
INSERT IGNORE INTO ref_yeast_strains (name, is_active) VALUES
  ('US-05', 1), ('W34/70', 1), ('WLP001', 1), ('LA-01', 1),
  ('BE-134', 1), ('Abbey', 1), ('London Fog', 1), ('Lona', 1),
  ('WLP830-O', 1), ('Pinnacle NA', 1), ('Belgian Wit', 1),
  ('Belle Saison', 1), ('Pinnacle Low Alcohol', 1), ('Marie', 1),
  ('Caulier', 1), ('CBC-1', 1), ('London Ale 3', 1), ('WB-06', 1),
  ('K-97', 1), ('Verdant', 1), ('S-189', 1), ('Angel NEIPA', 1),
  ('Diamond', 1), ('WHC', 1), ('New England', 1), ('DV-10', 1),
  ('Pomona', 1), ('Farmhouse', 1), ('WLP080-O', 1);

-- 2. 'New Yeast' = placeholder réintroduit avec is_active=0 :
--    garde-fou anti-pollution + audit historique préservé
INSERT IGNORE INTO ref_yeast_strains (name, is_active) VALUES ('New Yeast', 0);

-- 3. Aliases (9 entrées) — sub-select résout strain_id par lookup name
--    COLLATE utf8mb4_bin = match exact case-sensitive contre les canoniques
INSERT IGNORE INTO ref_yeast_strain_aliases (alias, strain_id) VALUES
  ('Diamond - Lallemand',                              (SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Diamond')),
  ('Lallamand Diamond Lager',                          (SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Diamond')),
  ('farmhouse',                                        (SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Farmhouse')),
  ('Pinnacle N/A',                                     (SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Pinnacle NA')),
  ('POMONA',                                           (SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Pomona')),
  ('Pomona - Second harvest from 1st GEN from SPY58',  (SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Pomona')),
  ('Pomona Lallemand',                                 (SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'Pomona')),
  ('WLP-001',                                          (SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'WLP001')),
  ('WLP080-O (Organic Cream Ale Yeast Blend)',         (SELECT id FROM ref_yeast_strains WHERE name COLLATE utf8mb4_bin = 'WLP080-O'));
