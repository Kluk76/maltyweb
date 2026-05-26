-- db/migrations/148_blanche_des_romands_white_label_mis.sql
-- What: Create the 3 BLA-branded packaging MIs for "Blanche des Romands" (BLA4),
--       an internal WHITE-LABEL of Moonshine (liquid = MOO, recipe 44), discontinued.
-- Why:  BLA4's liquid recipe = MOO, so the BOM compiler's {beer} slot resolution
--       (keyed off the recipe sku_prefix = MOO) would wrongly pick PKG_*_MOO for the
--       label / 4-pack / outer-box. The white-label model overrides those slots to
--       BLA-branded MIs via ref_sku_packaging_choices (done in follow-up DML). This
--       migration just CREATES the 3 brand MIs. "Blanche des Romands" (BLA) is a
--       DISTINCT brand from "Blonde des Romands" (BLO, recipe 10) — operator-confirmed
--       2026-05-26 — so these are new, not a reuse of the BLO set.
--       Attributes mirror the BLO siblings (PKG_LABEL_BLO id631, PKG_4PACK_BTL_BLO
--       id621, PKG_6X4_BTL_BLO id618). price = NULL (white-label, discontinued — no
--       guessed cost; an invoice/figure fills it if ever needed).
--       NB: the *_BLANC MIs (PKG_BOX_*_BTL_BLANC etc.) are the clear-glass COLOUR
--       variant, unrelated to the BLA brand.
-- Risk: LOW — INSERT IGNORE only; no schema change; no FK from BOM tables.
-- Rollback:
--   DELETE FROM ref_mi WHERE mi_id IN ('PKG_LABEL_BLA','PKG_4PACK_BTL_BLA','PKG_6X4_BTL_BLA');

INSERT IGNORE INTO ref_mi
  (mi_id, name, category_id, subcategory_id, input_unit, pricing_unit, conversion_factor,
   currency, gl_account, is_inventoried, consumption_unit, packaging_hl_equivalent,
   price, pack_size, row_hash, last_modified_by)
VALUES
-- label (mirror id 631 PKG_LABEL_BLO): cat 8 subcat 19 EUR gl 4206 hl 0.0033
('PKG_LABEL_BLA', 'Label BLA (Blanche des Romands)', 8, 19, 'unit', 'unit', 1.000000,
 'EUR', '4206', 1, NULL, 0.003300,
 NULL, NULL, SHA2('PKG_LABEL_BLA', 256), 'web'),

-- 4-pack bottle (mirror id 621 PKG_4PACK_BTL_BLO): cat 8 subcat 31 CHF gl 4207 hl 0.0132
('PKG_4PACK_BTL_BLA', '4-pack bottle BLA (Blanche des Romands)', 8, 31, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, 0.013200,
 NULL, NULL, SHA2('PKG_4PACK_BTL_BLA', 256), 'web'),

-- 6x4 outer carton (mirror id 618 PKG_6X4_BTL_BLO): cat 8 subcat 31 CHF gl 4207 hl 0.0792
('PKG_6X4_BTL_BLA', '6x4 bottle BLA (Blanche des Romands)', 8, 31, 'unit', 'unit', 1.000000,
 'CHF', '4207', 1, NULL, 0.079200,
 NULL, NULL, SHA2('PKG_6X4_BTL_BLA', 256), 'web');
