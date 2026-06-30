-- 452_sales_viewer_preset.sql
-- Adds 'sales_viewer' access preset (sales-team read-only: Mon tableau + Expéditions).
-- Idempotent: INSERT IGNORE on both tables (both carry the relevant UNIQUE keys).

INSERT IGNORE INTO ref_access_presets (preset_key, label, description)
VALUES ('sales_viewer',
        'Ventes (lecture)',
        'Équipe commerciale lecture seule — Mon tableau + Expéditions (B2B + Stock PF). Aucune écriture, aucune production.');

SET @pid := (SELECT id FROM ref_access_presets WHERE preset_key = 'sales_viewer');

INSERT IGNORE INTO ref_access_preset_pages (preset_id_fk, page_id_fk)
SELECT @pid, rp.id
  FROM ref_pages rp
 WHERE rp.page_key IN ('mon-tableau', 'expeditions');
