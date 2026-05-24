-- db/migrations/119_capacites_binding_seed.sql
-- What: Seed ref_container_mi and ref_packaging_format_mis with CONFIDENT bindings
--       from capacites-mi-binding-preview-2026-05-24.md (operator-approved 2026-05-24).
-- Why:  Materialise the Capacités↔MI links that drive the Salle des Machines UI and
--       future COGS/BOM resolution.
-- Risk: INSERT IGNORE only — additive, no rows touched in ref_mi or ref_packaging_formats.
-- Rollback: DELETE FROM ref_packaging_format_mis; DELETE FROM ref_container_mi;
--
-- DELTAS applied per operator sign-off:
--   1. F/PKG_KEG_SAFE role: 'container' → 'closure' (keg vessel is reusable; consumable = collars+safe).
--   2. 33C format EXCLUDED entirely (contract-only / Traquenard; client-supplied — defer to contract model).
--   3. PAD EXCLUDED (volume unconfirmed — defer).
--   4. AMBIGUOUS rows EXCLUDED: printed cans (PKG_CAN_ZEP/EMB/MOO/STI), all stickers,
--      33C box (BOX_24 qty logic unconfirmed for single-can unit).
--   5. No new ref_packaging_formats rows created (33cl can box formats deferred pending SKU code naming).

-- ==========================================================================
-- PART A — ref_container_mi: container → primary MI binding
-- Three rows: BOT_GLASS_33, CAN_ALU_50, CAN_ALU_33.
-- KEG_INOX_20 intentionally omitted — keg vessel is reusable; no consumption MI.
-- ==========================================================================

INSERT IGNORE INTO ref_container_mi
  (container_id, mi_id_fk, is_active, effective_from, effective_until)
SELECT
  ct.id,
  m.id,
  1,
  '1970-01-01 00:00:00',  -- retroactive commissioning baseline (table default)
  '9999-12-31 23:59:59'   -- SCD2 sentinel = current row
FROM dbc_container_types ct
JOIN ref_mi m ON m.mi_id = 'PKG_BOT_PIVO'
WHERE ct.container_code = 'BOT_GLASS_33';

INSERT IGNORE INTO ref_container_mi
  (container_id, mi_id_fk, is_active, effective_from, effective_until)
SELECT
  ct.id,
  m.id,
  1,
  '1970-01-01 00:00:00',
  '9999-12-31 23:59:59'
FROM dbc_container_types ct
JOIN ref_mi m ON m.mi_id = 'PKG_CAN_ALU_50'
WHERE ct.container_code = 'CAN_ALU_50';

INSERT IGNORE INTO ref_container_mi
  (container_id, mi_id_fk, is_active, effective_from, effective_until)
SELECT
  ct.id,
  m.id,
  1,
  '1970-01-01 00:00:00',
  '9999-12-31 23:59:59'
FROM dbc_container_types ct
JOIN ref_mi m ON m.mi_id = 'PKG_CAN_ALU_33'
WHERE ct.container_code = 'CAN_ALU_33';

-- ==========================================================================
-- PART B — ref_packaging_format_mis: format → secondary MI BOM bridge
-- CONFIDENT rows only. Resolved via subselects on format_code / mi_id.
-- ORDER: bottle formats → can formats → keg → composite.
-- ==========================================================================

-- --------------------------------------------------------------------------
-- BU (id=5): single 33cl bottle unit
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'container', 1, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_PIVO'
WHERE f.format_code = 'BU';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 1, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_CROWN_CAPS'
WHERE f.format_code = 'BU';

-- --------------------------------------------------------------------------
-- B (id=1): 24-pack bottle box
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'container', 24, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_PIVO'
WHERE f.format_code = 'B';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 24, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_CROWN_CAPS'
WHERE f.format_code = 'B';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'box', 1, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOX_24_BTL_BLANC'
WHERE f.format_code = 'B';

-- --------------------------------------------------------------------------
-- B12 (id=2): 12-pack bottle box (eshop)
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'container', 12, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_PIVO'
WHERE f.format_code = 'B12';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 12, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_CROWN_CAPS'
WHERE f.format_code = 'B12';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'box', 1, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_CARTON12_ESHOP'
WHERE f.format_code = 'B12';

-- --------------------------------------------------------------------------
-- 4 (id=3): 6×4-pack carton (24 bottles)
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'container', 24, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_PIVO'
WHERE f.format_code = '4';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 24, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_CROWN_CAPS'
WHERE f.format_code = '4';

-- --------------------------------------------------------------------------
-- 4PB (id=4): 4-pack loose bottles
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'container', 4, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_PIVO'
WHERE f.format_code = '4PB';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 4, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_CROWN_CAPS'
WHERE f.format_code = '4PB';

-- --------------------------------------------------------------------------
-- X (id=6): pallet crate (1027 bottles)
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'container', 1027, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_PIVO'
WHERE f.format_code = 'X';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 1027, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_CROWN_CAPS'
WHERE f.format_code = 'X';

-- --------------------------------------------------------------------------
-- CU (id=14): single 50cl can unit
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 1, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_CAN_LIDS'
WHERE f.format_code = 'CU';

-- --------------------------------------------------------------------------
-- C (id=7): 24-pack can box
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 24, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_CAN_LIDS'
WHERE f.format_code = 'C';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'box', 1, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOX_24_CAN_BLANC'
WHERE f.format_code = 'C';

-- --------------------------------------------------------------------------
-- BC (id=8): 24-pack can box B variant
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 24, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_CAN_LIDS'
WHERE f.format_code = 'BC';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'box', 1, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOX_24_CAN_BLANC'
WHERE f.format_code = 'BC';

-- --------------------------------------------------------------------------
-- 4C (id=9): 6×4-pack can carton (24 cans)
-- AMBIGUOUS printed cans (PKG_CAN_EMB/MOO/STI) excluded per delta #4.
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 24, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_CAN_LIDS'
WHERE f.format_code = '4C';

-- --------------------------------------------------------------------------
-- 4PC (id=12): 4-pack loose cans
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 4, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_CAN_LIDS'
WHERE f.format_code = '4PC';

-- --------------------------------------------------------------------------
-- 12C (id=11): 12-pack can box
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 12, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_CAN_LIDS'
WHERE f.format_code = '12C';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'box', 1, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOX_12_CAN_BLANC'
WHERE f.format_code = '12C';

-- --------------------------------------------------------------------------
-- 33C: EXCLUDED per delta #2 (contract-only / Traquenard; client-supplied — defer to contract model)
-- --------------------------------------------------------------------------

-- --------------------------------------------------------------------------
-- F (id=15): 20L keg
-- PKG_KEG_SAFE re-roled 'container' → 'closure' per delta #1.
-- Both PKG_KEG_SAFE and PKG_KEG_COLLARS are consumables (not reusable vessel).
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 1, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_KEG_SAFE'
WHERE f.format_code = 'F';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 1, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_KEG_COLLARS'
WHERE f.format_code = 'F';

-- --------------------------------------------------------------------------
-- PD8 (id=19): Pack Découverte 8 (8×33cl composite)
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'container', 8, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_PIVO'
WHERE f.format_code = 'PD8';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 8, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_CROWN_CAPS'
WHERE f.format_code = 'PD8';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'box', 1, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_PACK_DEC'
WHERE f.format_code = 'PD8';

-- --------------------------------------------------------------------------
-- PAL (id=21): Pack Louis (12×33cl composite)
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'container', 12, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_PIVO'
WHERE f.format_code = 'PAL';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 12, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_CROWN_CAPS'
WHERE f.format_code = 'PAL';

-- --------------------------------------------------------------------------
-- XMASPACK (id=20): Xmas Pack (3×33cl composite + glass)
-- --------------------------------------------------------------------------
INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'container', 3, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_PIVO'
WHERE f.format_code = 'XMASPACK';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'closure', 3, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_BOT_CROWN_CAPS'
WHERE f.format_code = 'XMASPACK';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'box', 1, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_XMAS_PAC'
WHERE f.format_code = 'XMASPACK';

INSERT IGNORE INTO ref_packaging_format_mis
  (format_id, mi_id_fk, role, qty_per_unit, is_active)
SELECT f.id, m.id, 'other', 1, 1
FROM ref_packaging_formats f JOIN ref_mi m ON m.mi_id = 'PKG_VERRE_25CL'
WHERE f.format_code = 'XMASPACK';

-- --------------------------------------------------------------------------
-- PAD: EXCLUDED per delta #3 (volume unconfirmed — defer)
-- --------------------------------------------------------------------------
