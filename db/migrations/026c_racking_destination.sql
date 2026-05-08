-- 026c_racking_destination.sql
-- Modélisation polymorphe de la destination racking : ENUM type + 3 _id mutuellement exclusives + CHECK.
-- Préserve bd_racking.bbt en raw pour audit (suppression dans 028+ une fois la nouvelle UI MaltyTask validée).
--
-- DDL non-idempotent — en cas d'échec mid-migration, restaurer depuis backup
-- et fixer la cause avant relance. Seul le DROP CONSTRAINT IF EXISTS du CHECK
-- (chk_racking_destination, ligne ~52) est natively re-run-safe en MySQL 8.0.
-- ADD CONSTRAINT FK + ADD COLUMN + ADD KEY échouent au re-run.

-- 1. Nouvelles colonnes (NULLable jusqu'au backfill)
--    Non-idempotent : re-run plantera si les colonnes existent déjà.
ALTER TABLE bd_racking
  ADD COLUMN racking_destination_type ENUM('BBT','CCT','YT') NULL AFTER bbt,
  ADD COLUMN bbt_id INT UNSIGNED NULL AFTER racking_destination_type,
  ADD COLUMN cct_id INT UNSIGNED NULL AFTER bbt_id,
  ADD COLUMN yt_id  INT UNSIGNED NULL AFTER cct_id;

-- 2. FKs vers les 3 référentiels (ON DELETE RESTRICT — bloque la suppression d'un BBT/CCT/YT référencé)
ALTER TABLE bd_racking
  ADD CONSTRAINT fk_racking_dest_bbt
    FOREIGN KEY (bbt_id) REFERENCES ref_bbt(id) ON DELETE RESTRICT;

ALTER TABLE bd_racking
  ADD CONSTRAINT fk_racking_dest_cct
    FOREIGN KEY (cct_id) REFERENCES ref_cct(id) ON DELETE RESTRICT;

ALTER TABLE bd_racking
  ADD CONSTRAINT fk_racking_dest_yt
    FOREIGN KEY (yt_id)  REFERENCES ref_yt(id)  ON DELETE RESTRICT;

-- 3. Indexes pour les futurs filtres dropdown MaltyTask (type=BBT → liste BBTs disponibles)
--    NOTE: ADD KEY n'a pas IF NOT EXISTS en MySQL 8 ; sera no-op au 1er run, échouera au re-run.
--    Si la migration plante après cette section, les index seront déjà présents → safe à commenter pour relance.
ALTER TABLE bd_racking
  ADD KEY idx_racking_type_bbt (racking_destination_type, bbt_id),
  ADD KEY idx_racking_type_cct (racking_destination_type, cct_id),
  ADD KEY idx_racking_type_yt  (racking_destination_type, yt_id);

-- 4. Backfill — pattern "BBT N"
UPDATE bd_racking r
  JOIN ref_bbt b ON b.number = CAST(SUBSTRING_INDEX(r.bbt, ' ', -1) AS UNSIGNED)
  SET r.racking_destination_type = 'BBT',
      r.bbt_id = b.id
  WHERE r.bbt REGEXP '^BBT[[:space:]]+[0-9]+$';

-- 5. Backfill — pattern "CCT N" (1 ligne actuellement : la ligne 'CCT 5')
UPDATE bd_racking r
  JOIN ref_cct c ON c.number = CAST(SUBSTRING_INDEX(r.bbt, ' ', -1) AS UNSIGNED)
  SET r.racking_destination_type = 'CCT',
      r.cct_id = c.id
  WHERE r.bbt REGEXP '^CCT[[:space:]]+[0-9]+$';

-- 6. CHECK constraint — exactement une des 3 _id remplie ssi type est défini.
--    Formulation par count : somme des _id non-NULL = 1 si type défini, 0 sinon.
--    Note MySQL : nécessite que les FKs sur bbt_id/cct_id/yt_id n'aient pas
--    ON UPDATE CASCADE / SET NULL (cf. CLAUDE.md DETTE TECHNIQUE — limitations DDL).
ALTER TABLE bd_racking
  ADD CONSTRAINT chk_racking_destination CHECK (
    (CASE WHEN bbt_id IS NOT NULL THEN 1 ELSE 0 END
   + CASE WHEN cct_id IS NOT NULL THEN 1 ELSE 0 END
   + CASE WHEN yt_id  IS NOT NULL THEN 1 ELSE 0 END)
    = (CASE WHEN racking_destination_type IS NOT NULL THEN 1 ELSE 0 END)
  );
