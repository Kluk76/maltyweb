-- 026d_simple_string_fks.sql
-- FKs string-direct sur référentiels simples (UNIQUE name/number).
-- Empêche la pollution silencieuse depuis l'ingest.
-- Pré-vol : `validate_fk_candidates.py` doit passer avant.
-- DDL non-idempotent — en cas d'échec mid-migration, restaurer depuis backup
-- et fixer la cause avant relance.

-- 1. bd_yeast → ref_yeast_strains.name (UNIQUE)
ALTER TABLE bd_brewing_brewday
  ADD CONSTRAINT fk_brewday_yeast
    FOREIGN KEY (bd_yeast) REFERENCES ref_yeast_strains(name)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- 2. bd_cct → ref_cct.number (UNIQUE) — type mismatch (varchar(32) → int)
--    Convertir empty strings en NULL avant le MODIFY (sinon → 0 = invalid)
UPDATE bd_brewing_brewday SET bd_cct = NULL WHERE bd_cct = '';
ALTER TABLE bd_brewing_brewday MODIFY COLUMN bd_cct INT UNSIGNED NULL;
ALTER TABLE bd_brewing_brewday
  ADD CONSTRAINT fk_brewday_cct
    FOREIGN KEY (bd_cct) REFERENCES ref_cct(number)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- 3. bd_yt → ref_yt.number (UNIQUE) — idem
UPDATE bd_brewing_brewday SET bd_yt = NULL WHERE bd_yt = '';
ALTER TABLE bd_brewing_brewday MODIFY COLUMN bd_yt INT UNSIGNED NULL;
ALTER TABLE bd_brewing_brewday
  ADD CONSTRAINT fk_brewday_yt
    FOREIGN KEY (bd_yt) REFERENCES ref_yt(number)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- 4. bd_racking.bbt_old → ref_bbt.number (UNIQUE) — source BBT du transfer
--    Données déjà numériques (audit confirmé). Empty strings → NULL.
UPDATE bd_racking SET bbt_old = NULL WHERE bbt_old = '';
ALTER TABLE bd_racking MODIFY COLUMN bbt_old INT UNSIGNED NULL;
ALTER TABLE bd_racking
  ADD CONSTRAINT fk_racking_bbt_old
    FOREIGN KEY (bbt_old) REFERENCES ref_bbt(number)
    ON DELETE RESTRICT ON UPDATE CASCADE;
