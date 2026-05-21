-- db/migrations/070_ref_packaging_items.sql
-- What: New table defining the packaging slot template per format.
--       Each row = one slot (bottle, label, scotch, etc.) with qty_per_unit,
--       a pattern hint for MI resolution, and an optional default MI.
--       {beer} in mi_filter_pattern is a placeholder resolved at SKU build time.
-- Why:  SKU builder UI uses this as a template to pre-populate per-SKU choices.
--       Temporal columns v1 dormant (NULL=always-current).
-- Sources: sku-bom-audit-2026-05-21.md + ref_mi production data.
-- Risk: New table — INSERT IGNORE safe to re-run.
-- Rollback: DROP TABLE ref_packaging_items;
-- Note: default_mi_id_fk uses subselect against ref_mi.mi_id to avoid hardcoded IDs.
--       NULL default = slot has no global default (beer-specific, set in ref_sku_packaging_choices).

START TRANSACTION;

CREATE TABLE IF NOT EXISTS ref_packaging_items (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  format_id            BIGINT UNSIGNED NOT NULL,
  slot_name            VARCHAR(48) NOT NULL,
  qty_per_unit         DECIMAL(10,4) NOT NULL,
  mi_filter_pattern    VARCHAR(96) NOT NULL COMMENT '{beer} placeholder resolved at SKU build time',
  default_mi_id_fk     INT UNSIGNED NULL,
  is_default_checked   BOOL NOT NULL DEFAULT 1,
  display_order        INT NOT NULL DEFAULT 0,
  effective_from       DATE NULL,
  effective_until      DATE NULL,
  UNIQUE KEY uk_format_slot (format_id, slot_name),
  KEY idx_effective (effective_from, effective_until),
  CONSTRAINT fk_rpi_format   FOREIGN KEY (format_id)        REFERENCES ref_packaging_formats(id),
  CONSTRAINT fk_rpi_mi_dflt  FOREIGN KEY (default_mi_id_fk) REFERENCES ref_mi(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Helper: format_id lookup shorthand (all INSERTs use subselects)
-- ─────────────────────────────────────────────────────────────────────────────

-- ─────────────────────────────────────────────────────────────────────────────
-- FORMAT: B  (24-pack bottle box, 24×33cl)
-- Slots sourced from audit: bottle(24), crown_caps(24), label(24), scotch(~0.0009 roll),
--   outer_box(1), [sticker — beer-specific: is_default_checked=0 for generic -B beers]
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='B'),
   'bottle',     24.0000, 'PKG_BOT_PIVO',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOT_PIVO'), 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='B'),
   'crown_caps', 24.0000, 'PKG_BOT_CROWN_CAPS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOT_CROWN_CAPS'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='B'),
   'label',      24.0000, 'PKG_LABEL_{beer}%',
   NULL, 1, 30),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='B'),
   'scotch',     0.0009, 'PKG_SCOTCH_(TRANSP|{beer})%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_SCOTCH_TRANSP'), 1, 40),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='B'),
   'outer_box',  1.0000, 'PKG_BOX_24_BTL%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOX_24_BTL_BLANC'), 1, 50),

  -- Sticker: only on generic-B beers (branded use PKG_SCOTCH_{beer} instead)
  ((SELECT id FROM ref_packaging_formats WHERE format_code='B'),
   'sticker',    24.0000, 'PKG_STICKER_{beer}%',
   NULL, 0, 60);

-- ─────────────────────────────────────────────────────────────────────────────
-- FORMAT: B12  (12-pack bottle box, 12×33cl)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='B12'),
   'bottle',     12.0000, 'PKG_BOT_PIVO',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOT_PIVO'), 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='B12'),
   'crown_caps', 12.0000, 'PKG_BOT_CROWN_CAPS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOT_CROWN_CAPS'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='B12'),
   'label',      12.0000, 'PKG_LABEL_{beer}%',
   NULL, 1, 30),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='B12'),
   'scotch',     0.00045, 'PKG_SCOTCH_(TRANSP|{beer})%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_SCOTCH_TRANSP'), 1, 40),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='B12'),
   'outer_box',  1.0000, 'PKG_BOX_12_BTL%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOX_12_BTL_BLANC'), 1, 50),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='B12'),
   'sticker',    12.0000, 'PKG_STICKER_{beer}%',
   NULL, 0, 60);

-- ─────────────────────────────────────────────────────────────────────────────
-- FORMAT: 4  (6×4-pack carton, 24×33cl)
-- Outer packaging uses per-beer 6x4 carton MI (PKG_6X4_BTL_{beer})
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='4'),
   'bottle',     24.0000, 'PKG_BOT_PIVO',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOT_PIVO'), 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='4'),
   'crown_caps', 24.0000, 'PKG_BOT_CROWN_CAPS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOT_CROWN_CAPS'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='4'),
   'label',      24.0000, 'PKG_LABEL_{beer}%',
   NULL, 1, 30),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='4'),
   'scotch',     0.0009, 'PKG_SCOTCH_(TRANSP|{beer})%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_SCOTCH_TRANSP'), 1, 40),

  -- 6×4 format-specific outer: per-beer 4-pack trays (PKG_4PACK_BTL_{beer})
  ((SELECT id FROM ref_packaging_formats WHERE format_code='4'),
   'outer_tray', 6.0000, 'PKG_4PACK_BTL_{beer}%',
   NULL, 1, 50);

-- ─────────────────────────────────────────────────────────────────────────────
-- FORMAT: 4PB  (4-pack loose bottles, 4×33cl)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='4PB'),
   'bottle',     4.0000, 'PKG_BOT_PIVO',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOT_PIVO'), 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='4PB'),
   'crown_caps', 4.0000, 'PKG_BOT_CROWN_CAPS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOT_CROWN_CAPS'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='4PB'),
   'label',      4.0000, 'PKG_LABEL_{beer}%',
   NULL, 1, 30),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='4PB'),
   'holder',     1.0000, 'PKG_4PACK_BTL_{beer}%',
   NULL, 1, 40);

-- ─────────────────────────────────────────────────────────────────────────────
-- FORMAT: BU  (single 33cl bottle unit)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='BU'),
   'bottle',     1.0000, 'PKG_BOT_PIVO',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOT_PIVO'), 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='BU'),
   'crown_caps', 1.0000, 'PKG_BOT_CROWN_CAPS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOT_CROWN_CAPS'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='BU'),
   'label',      1.0000, 'PKG_LABEL_{beer}%',
   NULL, 1, 30);

-- ─────────────────────────────────────────────────────────────────────────────
-- FORMAT: X  (pallet crate, 1027×33cl)
-- Audit: 3 pre-existing packaging lines per -X SKU (scotch, box, pallet)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='X'),
   'bottle',     1027.0000, 'PKG_BOT_PIVO',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOT_PIVO'), 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='X'),
   'crown_caps', 1027.0000, 'PKG_BOT_CROWN_CAPS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOT_CROWN_CAPS'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='X'),
   'label',      1027.0000, 'PKG_LABEL_{beer}%',
   NULL, 1, 30),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='X'),
   'scotch',     1.0000, 'PKG_SCOTCH_(TRANSP|{beer})%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_SCOTCH_TRANSP'), 1, 40),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='X'),
   'outer_box',  1.0000, 'PKG_BOX_%',
   NULL, 1, 50),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='X'),
   'pallet',     1.0000, 'PKG_PALETTE%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_PALETTE_NOMOQ'), 0, 60);

-- ─────────────────────────────────────────────────────────────────────────────
-- FORMAT: 4C  (6×4-pack cans, 24×50cl)
-- Audit: printed cans (PKG_CAN_{beer}), 4-pack tray (PKG_4PACK_CAN_{beer}),
--        can lids, outer box
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='4C'),
   'can',        24.0000, 'PKG_CAN_{beer}%',
   NULL, 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='4C'),
   'can_lids',   24.0000, 'PKG_CAN_LIDS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_CAN_LIDS'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='4C'),
   'outer_tray', 6.0000, 'PKG_4PACK_CAN_{beer}%',
   NULL, 1, 30),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='4C'),
   'intercal',   1.0000, 'PKG_INTERCAL%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_INTERCAL_NOMOQ'), 0, 40);

-- FORMAT: C  (24-pack can box) — same slots as 4C but outer is box not trays
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='C'),
   'can',        24.0000, 'PKG_CAN_{beer}%',
   NULL, 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='C'),
   'can_lids',   24.0000, 'PKG_CAN_LIDS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_CAN_LIDS'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='C'),
   'outer_box',  1.0000, 'PKG_BOX_24_CAN%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOX_24_CAN_BLANC'), 1, 30);

-- FORMAT: BC  (24-pack can box, B-label variant) — same as C
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='BC'),
   'can',        24.0000, 'PKG_CAN_{beer}%',
   NULL, 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='BC'),
   'can_lids',   24.0000, 'PKG_CAN_LIDS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_CAN_LIDS'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='BC'),
   'outer_box',  1.0000, 'PKG_BOX_24_CAN%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOX_24_CAN_BLANC'), 1, 30);

-- FORMAT: 6C  (6-pack tray cans, 24×50cl)
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='6C'),
   'can',        24.0000, 'PKG_CAN_{beer}%',
   NULL, 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='6C'),
   'can_lids',   24.0000, 'PKG_CAN_LIDS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_CAN_LIDS'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='6C'),
   'outer_tray', 4.0000, 'PKG_6X4_CAN_{beer}%',
   NULL, 1, 30);

-- FORMAT: 12C  (12-pack can box, 12×50cl)
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='12C'),
   'can',        12.0000, 'PKG_CAN_{beer}%',
   NULL, 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='12C'),
   'can_lids',   12.0000, 'PKG_CAN_LIDS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_CAN_LIDS'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='12C'),
   'outer_box',  1.0000, 'PKG_BOX_12_CAN%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_BOX_12_CAN_BLANC'), 1, 30);

-- FORMAT: 4PC  (4-pack loose cans, 4×50cl)
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='4PC'),
   'can',        4.0000, 'PKG_CAN_{beer}%',
   NULL, 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='4PC'),
   'can_lids',   4.0000, 'PKG_CAN_LIDS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_CAN_LIDS'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='4PC'),
   'holder',     1.0000, 'PKG_4PACK_CAN_{beer}%',
   NULL, 1, 30);

-- FORMAT: 33C  (single 33cl can)
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='33C'),
   'can',        1.0000, 'PKG_CAN_{beer}%',
   NULL, 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='33C'),
   'can_lids',   1.0000, 'PKG_CAN_LIDS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_CAN_LIDS'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='33C'),
   'sticker',    1.0000, 'PKG_STICKER_{beer}%',
   NULL, 0, 30);

-- FORMAT: CU  (single 50cl can unit)
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='CU'),
   'can',        1.0000, 'PKG_CAN_{beer}%',
   NULL, 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='CU'),
   'can_lids',   1.0000, 'PKG_CAN_LIDS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_CAN_LIDS'), 1, 20);

-- FORMAT: F  (20L keg)
-- Audit: keg_collars(1), keg_safe(0.0something), crown caps for bung
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='F'),
   'keg_collars', 1.0000, 'PKG_KEG_COLLARS',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_KEG_COLLARS'), 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='F'),
   'keg_safe',   1.0000, 'PKG_KEG_SAFE',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_KEG_SAFE'), 0, 20);

-- FORMAT: P25  (draft pour 25cl) — no physical packaging consumables
-- FORMAT: P50  (draft pour 50cl) — no physical packaging consumables

-- FORMAT: V  (cuve de service / liner)
-- Per reference_v_liner_and_yeastvit_in_recipes.md: 1 client liner + optionally 1 transport liner
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='V'),
   'liner_client',    1.0000, 'PKG_LINER_%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_LINER_10HL_EDS25'), 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='V'),
   'liner_transport', 1.0000, 'PKG_LINER_%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_LINER_10HL_EDS25'), 0, 20);

-- ─────────────────────────────────────────────────────────────────────────────
-- COMPOSITE FORMATS — define inner-bundle packaging slots only
-- Liquid lines are managed via ref_sku_composite_slots (migration 071)
-- ─────────────────────────────────────────────────────────────────────────────

-- FORMAT: PD8  (Pack Découverte 8 — 8×33cl composite)
-- Audit: outer box = PKG_PACK_DEC, renforts double+single per bottle type
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='PD8'),
   'outer_box',       1.0000, 'PKG_PACK_DEC',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_PACK_DEC'), 1, 10),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='PD8'),
   'renforts_double', 1.0000, 'PKG_RENFORTS_PD_DBL',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_RENFORTS_PD_DBL'), 1, 20),

  ((SELECT id FROM ref_packaging_formats WHERE format_code='PD8'),
   'renforts_single', 1.0000, 'PKG_RENFORTS_PD_SGL',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_RENFORTS_PD_SGL'), 1, 30);

-- FORMAT: PAL  (Pack Louis — 12×33cl composite)
-- Audit: packaging lines are bottles+caps+labels from constituent beers
-- Outer box: PKG_PACK_LOUIS does not yet exist in ref_mi — slot defined but default NULL
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='PAL'),
   'outer_box',  1.0000, 'PKG_PACK_LOUIS',
   NULL, 1, 10),
  -- individual bottle packaging deferred to constituent beer slots via composite_slots

  ((SELECT id FROM ref_packaging_formats WHERE format_code='PAL'),
   'pallet',     1.0000, 'PKG_PALETTE%',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_PALETTE_NOMOQ'), 0, 20);

-- FORMAT: XMASPACK  (3×33cl)
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk, is_default_checked, display_order)
VALUES
  ((SELECT id FROM ref_packaging_formats WHERE format_code='XMASPACK'),
   'outer_box',  1.0000, 'PKG_XMAS_PAC',
   (SELECT id FROM ref_mi WHERE mi_id='PKG_XMAS_PAC'), 1, 10);

COMMIT;
