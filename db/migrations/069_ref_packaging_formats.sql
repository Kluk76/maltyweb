-- db/migrations/069_ref_packaging_formats.sql
-- What: New table enumerating all packaging format codes with their HL-per-sellable-unit.
--       Composite formats (PD8, XMASPACK, PAL) have is_composite=1.
-- Why:  SKU builder UI needs a canonical list of formats to drive the packaging
--       slot template system. Also normalises the free-text ref_skus.format column.
-- Sources: reference_sku_naming_convention.md + sku-bom-audit-2026-05-21.md + ref_skus live data.
-- Risk: New table — no existing data affected.
-- Rollback: DROP TABLE ref_packaging_formats;

START TRANSACTION;

CREATE TABLE IF NOT EXISTS ref_packaging_formats (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  format_code     VARCHAR(16) NOT NULL,
  display_name    VARCHAR(96) NOT NULL,
  hl_per_unit     DECIMAL(10,6) NOT NULL,
  is_composite    BOOL NOT NULL DEFAULT 0,
  is_active       BOOL NOT NULL DEFAULT 1,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_format_code (format_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Bottle formats
-- ─────────────────────────────────────────────────────────────────────────────

-- -B / -B12: 24-pack or 12-pack loose bottle box
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('B',    '24-pack bottle box (24×33cl)', 0.079200, 0);

INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('B12',  '12-pack bottle box (12×33cl)', 0.039600, 0);

-- -4: 6×4 carton (24×33cl) — same total volume as B, different outer packaging
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('4',    '6×4-pack carton (24×33cl bottles)', 0.079200, 0);

-- -4PB: 4-pack loose bottles
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('4PB',  '4-pack loose bottles (4×33cl)', 0.013200, 0);

-- -BU: single bottle unit
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('BU',   'Bottle unit (single 33cl)', 0.003300, 0);

-- -X: full pallet crate (1027×33cl) — audit says 3.389100
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('X',    'Pallet crate (1027×33cl bottles)', 3.389100, 0);

-- ─────────────────────────────────────────────────────────────────────────────
-- Can formats
-- ─────────────────────────────────────────────────────────────────────────────

-- -C / -BC: 24×50cl can box
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('C',    '24-pack can box (24×50cl)',    0.120000, 0);

INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('BC',   '24-pack can box B variant (24×50cl)', 0.120000, 0);

-- -4C: 6×4 carton of cans (24×50cl)
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('4C',   '6×4-pack can carton (24×50cl)', 0.120000, 0);

-- -6C: 6-pack tray of cans (24×50cl) — same total; different tray format
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('6C',   '6-pack tray of cans (24×50cl)', 0.120000, 0);

-- -12C: 12-pack can box
-- NOTE: sku_naming_convention says 12×50cl = 0.06 HL. ref_skus shows EMB12C/MOO12C/STI12C/ZEP12C = 0.06000.
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('12C',  '12-pack can box (12×50cl)',    0.060000, 0);

-- -4PC: 4-pack loose cans (4×50cl)
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('4PC',  '4-pack loose cans (4×50cl)',   0.020000, 0);

-- -33C: single 33cl can (DIV33C in ref_skus)
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('33C',  'Single 33cl can',              0.003300, 0);

-- -CU: single 50cl can unit
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('CU',   'Can unit (single 50cl)',        0.005000, 0);

-- ─────────────────────────────────────────────────────────────────────────────
-- Keg / draft formats
-- ─────────────────────────────────────────────────────────────────────────────

-- -F: 20L keg
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('F',    'Fût 20L keg',                  0.200000, 0);

-- -P25: 25cl draft pour
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('P25',  'Draft pour 25cl',              0.002500, 0);

-- -P50: 50cl draft pour
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('P50',  'Draft pour 50cl',              0.005000, 0);

-- ─────────────────────────────────────────────────────────────────────────────
-- Cuve de service (liner format)
-- ─────────────────────────────────────────────────────────────────────────────

-- -V: cuve de service — ref_skus shows 0.01 HL (1L) for EMBV/MOOV/ZEPV
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('V',    'Cuve de service (1L liner)',   0.010000, 0);

-- ─────────────────────────────────────────────────────────────────────────────
-- Composite / bundle formats
-- ─────────────────────────────────────────────────────────────────────────────

-- PD8: Pack Découverte 8 (composite: 8×33cl bottles from various beers)
-- ref_skus: PD8 hl_per_unit=0.02640 = 8×33cl
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('PD8',      'Pack Découverte 8 (8×33cl composite)', 0.026400, 1);

-- XMASPACK: 3×33cl Xmas composite — ref_skus shows 0.00990
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('XMASPACK', 'Xmas Pack (3×33cl composite)',         0.009900, 1);

-- PAL: Pack Louis / pallet composite — ref_skus shows 0.03960 = 12×33cl
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('PAL',      'Pack Louis (12×33cl composite)',       0.039600, 1);

-- PAD: ref_skus shows 0.26400 = 80×33cl. PD8 (0.02640) is 8×33cl. They differ
-- by 10×, suggesting PAD is either a larger 80-bottle pack OR a typo in ref_skus
-- (extra zero). Earlier session notes called PAD a PD8 alias — needs operator
-- confirmation before we commit a canonical interpretation.
-- TODO(operator-2026-05-21): confirm PAD volume + relationship to PD8.
INSERT IGNORE INTO ref_packaging_formats (format_code, display_name, hl_per_unit, is_composite)
VALUES ('PAD',      'Pack Découverte large (80×33cl composite) [VERIFY]', 0.264000, 1);

COMMIT;
