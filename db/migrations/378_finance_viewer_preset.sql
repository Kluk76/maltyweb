-- 378_finance_viewer_preset.sql
-- CFO read-only access preset (Thierry, user 22).
-- Page keys verified against ref_pages on 2026-06-16.
-- 'fermentation' omitted: not a ref_pages entry (gated under 'saisies').

INSERT INTO ref_access_presets (preset_key, label)
VALUES ('finance_viewer', 'Financier (lecture)');

INSERT IGNORE INTO ref_access_preset_pages (preset_id_fk, page_id_fk)
SELECT (SELECT id FROM ref_access_presets WHERE preset_key='finance_viewer'), rp.id
  FROM ref_pages rp
 WHERE rp.page_key IN (
   'zeppelin','wort','packaging','qa',
   'mon-tableau','sb-board','sb-guerre','journal-saisies','planning','triage','saisies','settings',
   'approvisionnement','expeditions','warehouse','rm-comparison','tap-shop','email-orders',
   'financier','charges-bc'
 );

INSERT IGNORE INTO user_page_access (user_id_fk, page_id_fk, granted)
SELECT 22, (SELECT id FROM ref_pages WHERE page_key='charges-bc'), 1;
