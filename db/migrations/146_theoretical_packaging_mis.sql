-- db/migrations/146_theoretical_packaging_mis.sql
-- What: Insert 28 theoretical per-beer packaging MIs so every active SKU's packaging
--       slots resolve (no NULL mi_id_fk in the BOM). Covers 7 packaging types across
--       the BLO, ZEP, EPH2, EPH4, DIV, DIG, DIP, DOC, DGD, MOO and EPH1–4 beer codes.
-- Why:  Operator directive 2026-05-25: input all theoretical MIs; have them available
--       for historical ingestion even where no invoice has landed yet. price = NULL
--       (operator/invoice fills later).
-- Risk: LOW — INSERT IGNORE only; no schema change; no FK from BOM tables (BOM uses
--       mi_id INT FK which the BOM compiler resolves at recompile time).
-- Rollback: DELETE FROM ref_mi WHERE mi_id IN (
--   'PKG_6X4_BTL_BLO',
--   'PKG_6X4_CAN_BLO','PKG_6X4_CAN_ZEP',
--   'PKG_4PACK_BTL_BLO','PKG_4PACK_BTL_EPH2','PKG_4PACK_BTL_EPH4',
--   'PKG_4PACK_CAN_ZEP',
--   'PKG_CAN_BLO','PKG_CAN_DIV','PKG_CAN_EPH1','PKG_CAN_EPH2','PKG_CAN_EPH3','PKG_CAN_EPH4',
--   'PKG_LABEL_BLO','PKG_LABEL_DIG','PKG_LABEL_DIP','PKG_LABEL_DOC',
--   'PKG_STICKER_DGD','PKG_STICKER_DIG','PKG_STICKER_DIP','PKG_STICKER_DIV',
--   'PKG_STICKER_DOA','PKG_STICKER_DOC','PKG_STICKER_EPH1','PKG_STICKER_EPH2',
--   'PKG_STICKER_EPH3','PKG_STICKER_EPH4','PKG_STICKER_MOO'
-- );

-- Sibling attrs copied per type (verified from live DB 2026-05-26):
--   6×4 BTL  (mirror id 131): cat=8 subcat=31 unit=unit price_unit=unit conv=1 CHF gl=4207 is_inv=1 hl_equiv=0.079200
--   6×4 CAN  (mirror id 138): cat=8 subcat=31 unit=unit price_unit=unit conv=1 CHF gl=4207 is_inv=1 hl_equiv=0.120000
--   4-pack BTL (mirror id 115): cat=8 subcat=31 unit=unit price_unit=unit conv=1 CHF gl=4207 is_inv=1 hl_equiv=0.013200
--   4-pack CAN (mirror id 122): cat=8 subcat=31 unit=unit price_unit=unit conv=1 CHF gl=4207 is_inv=1 hl_equiv=0.020000
--   printed CAN (mirror id 94): cat=8 subcat=13 unit=unit price_unit=unit conv=1 EUR gl=4202 is_inv=1 hl_equiv=0.005000
--   Label    (mirror id 147): cat=8 subcat=19 unit=unit price_unit=unit conv=1 EUR gl=4206 is_inv=1 hl_equiv=0.003300
--   Sticker  (mirror id 181): cat=8 subcat=18 unit=unit price_unit=unit conv=1 CHF gl=4207 is_inv=1 hl_equiv=NULL

INSERT IGNORE INTO ref_mi
  (mi_id, name, category_id, subcategory_id, input_unit, pricing_unit, conversion_factor,
   currency, gl_account, is_inventoried, consumption_unit, packaging_hl_equivalent,
   price, pack_size, row_hash, last_modified_by)
VALUES

-- ── 6×4 carton bottle (mirror id 131 PKG_6X4_BTL_EMB) ──────────────────────
('PKG_6X4_BTL_BLO', '6x4 bottle BLO', 8, 31, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, 0.079200,
 NULL, NULL, SHA2('PKG_6X4_BTL_BLO', 256), 'web'),

-- ── 6×4 carton can (mirror id 138 PKG_6X4_CAN_EMB) ─────────────────────────
('PKG_6X4_CAN_BLO', '6x4 can BLO', 8, 31, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, 0.120000,
 NULL, NULL, SHA2('PKG_6X4_CAN_BLO', 256), 'web'),

('PKG_6X4_CAN_ZEP', '6x4 can ZEP', 8, 31, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, 0.120000,
 NULL, NULL, SHA2('PKG_6X4_CAN_ZEP', 256), 'web'),

-- ── 4-pack bottle (mirror id 115 PKG_4PACK_BTL_EMB) ────────────────────────
('PKG_4PACK_BTL_BLO', '4-pack bottle BLO', 8, 31, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, 0.013200,
 NULL, NULL, SHA2('PKG_4PACK_BTL_BLO', 256), 'web'),

('PKG_4PACK_BTL_EPH2', '4-pack bottle EPH2', 8, 31, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, 0.013200,
 NULL, NULL, SHA2('PKG_4PACK_BTL_EPH2', 256), 'web'),

('PKG_4PACK_BTL_EPH4', '4-pack bottle EPH4', 8, 31, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, 0.013200,
 NULL, NULL, SHA2('PKG_4PACK_BTL_EPH4', 256), 'web'),

-- ── 4-pack can (mirror id 122 PKG_4PACK_CAN_EMB) ───────────────────────────
('PKG_4PACK_CAN_ZEP', '4-pack can ZEP', 8, 31, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, 0.020000,
 NULL, NULL, SHA2('PKG_4PACK_CAN_ZEP', 256), 'web'),

-- ── printed can container (mirror id 94 PKG_CAN_EMB) ───────────────────────
('PKG_CAN_BLO', 'Can BLO (printed)', 8, 13, 'unit', 'unit', 1.000000,
 'EUR', '4202', 1, NULL, 0.005000,
 NULL, NULL, SHA2('PKG_CAN_BLO', 256), 'web'),

('PKG_CAN_DIV', 'Can DIV (printed)', 8, 13, 'unit', 'unit', 1.000000,
 'EUR', '4202', 1, NULL, 0.005000,
 NULL, NULL, SHA2('PKG_CAN_DIV', 256), 'web'),

('PKG_CAN_EPH1', 'Can EPH1 (printed)', 8, 13, 'unit', 'unit', 1.000000,
 'EUR', '4202', 1, NULL, 0.005000,
 NULL, NULL, SHA2('PKG_CAN_EPH1', 256), 'web'),

('PKG_CAN_EPH2', 'Can EPH2 (printed)', 8, 13, 'unit', 'unit', 1.000000,
 'EUR', '4202', 1, NULL, 0.005000,
 NULL, NULL, SHA2('PKG_CAN_EPH2', 256), 'web'),

('PKG_CAN_EPH3', 'Can EPH3 (printed)', 8, 13, 'unit', 'unit', 1.000000,
 'EUR', '4202', 1, NULL, 0.005000,
 NULL, NULL, SHA2('PKG_CAN_EPH3', 256), 'web'),

('PKG_CAN_EPH4', 'Can EPH4 (printed)', 8, 13, 'unit', 'unit', 1.000000,
 'EUR', '4202', 1, NULL, 0.005000,
 NULL, NULL, SHA2('PKG_CAN_EPH4', 256), 'web'),

-- ── label (mirror id 147 PKG_LABEL_EMB) ─────────────────────────────────────
('PKG_LABEL_BLO', 'Label BLO', 8, 19, 'unit', 'unit', 1.000000,
 'EUR', '4206', 1, NULL, 0.003300,
 NULL, NULL, SHA2('PKG_LABEL_BLO', 256), 'web'),

('PKG_LABEL_DIG', 'Label DIG', 8, 19, 'unit', 'unit', 1.000000,
 'EUR', '4206', 1, NULL, 0.003300,
 NULL, NULL, SHA2('PKG_LABEL_DIG', 256), 'web'),

('PKG_LABEL_DIP', 'Label DIP', 8, 19, 'unit', 'unit', 1.000000,
 'EUR', '4206', 1, NULL, 0.003300,
 NULL, NULL, SHA2('PKG_LABEL_DIP', 256), 'web'),

('PKG_LABEL_DOC', 'Label DOC', 8, 19, 'unit', 'unit', 1.000000,
 'EUR', '4206', 1, NULL, 0.003300,
 NULL, NULL, SHA2('PKG_LABEL_DOC', 256), 'web'),

-- ── sticker (mirror id 181 PKG_STICKER_ALT) ─────────────────────────────────
('PKG_STICKER_DGD', 'Sticker DGD', 8, 18, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, NULL,
 NULL, NULL, SHA2('PKG_STICKER_DGD', 256), 'web'),

('PKG_STICKER_DIG', 'Sticker DIG', 8, 18, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, NULL,
 NULL, NULL, SHA2('PKG_STICKER_DIG', 256), 'web'),

('PKG_STICKER_DIP', 'Sticker DIP', 8, 18, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, NULL,
 NULL, NULL, SHA2('PKG_STICKER_DIP', 256), 'web'),

('PKG_STICKER_DIV', 'Sticker DIV', 8, 18, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, NULL,
 NULL, NULL, SHA2('PKG_STICKER_DIV', 256), 'web'),

('PKG_STICKER_DOA', 'Sticker DOA', 8, 18, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, NULL,
 NULL, NULL, SHA2('PKG_STICKER_DOA', 256), 'web'),

('PKG_STICKER_DOC', 'Sticker DOC', 8, 18, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, NULL,
 NULL, NULL, SHA2('PKG_STICKER_DOC', 256), 'web'),

('PKG_STICKER_EPH1', 'Sticker EPH1', 8, 18, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, NULL,
 NULL, NULL, SHA2('PKG_STICKER_EPH1', 256), 'web'),

('PKG_STICKER_EPH2', 'Sticker EPH2', 8, 18, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, NULL,
 NULL, NULL, SHA2('PKG_STICKER_EPH2', 256), 'web'),

('PKG_STICKER_EPH3', 'Sticker EPH3', 8, 18, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, NULL,
 NULL, NULL, SHA2('PKG_STICKER_EPH3', 256), 'web'),

('PKG_STICKER_EPH4', 'Sticker EPH4', 8, 18, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, NULL,
 NULL, NULL, SHA2('PKG_STICKER_EPH4', 256), 'web'),

('PKG_STICKER_MOO', 'Sticker MOO', 8, 18, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, NULL,
 NULL, NULL, SHA2('PKG_STICKER_MOO', 256), 'web');
