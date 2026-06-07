-- db/migrations/274_derived_format_gate_p25_p50.sql
--
-- What: P25/P50 draft-pour keg-share packaging lines (FIX-7, bounced from mig 272).
--
-- Three-part change:
--
-- Part 1 — Schema: add ref_packaging_formats.derived_from_format_id INT UNSIGNED NULL
--           FK → ref_packaging_formats(id). Generic discriminator: "this format is a
--           derived/fractional view of a parent format and inherits its commissioning."
--           Seed formats 16 (P25) and 17 (P50) → format 15 (F, 20L keg).
--
-- Part 2 — ref_packaging_bom_templates rows for formats 16 and 17.
--           Required so the metaQuery INNER JOIN bt ON bt.format_id = s.format_id resolves.
--           supply='we_supply', decoration_integral=0 — mirrors the keg format template (id=23).
--
-- Part 3 — ref_packaging_items fractional keg-share lines on formats 16 and 17.
--           Mirrors keg format items (ids 55/56): slot_name, mi_filter_pattern, default_mi_id_fk,
--           slot_scope='we_supply_only'. Fractional qty:
--             format 16 P25: 0.25 L / 20,000 L = 0.0125 (1/80 of a 20L keg)
--             format 17 P50: 0.50 L / 20,000 L = 0.025  (1/40 of a 20L keg)
--
-- FK type: ref_packaging_formats.id is BIGINT UNSIGNED (verified live 2026-06-07).
-- Keg format id=15 (format_code='F', hl_per_unit=0.200000, run_type='keg') — verified live.
-- PKG_KEG_COLLARS ref_mi.id=100, PKG_KEG_SAFE ref_mi.id=101 — verified live.
--
-- Note on schema_meta: no new TABLE is added — only a column + ref data rows.
-- The compiler is the writer for ref_sku_bom (not this migration).
--
-- Note: ref_packaging_items.qty_per_unit is DECIMAL(10,4), confirmed via SHOW COLUMNS.
-- 0.0125 and 0.025 fit in 4 decimal places exactly.

-- ── Part 1: ADD COLUMN + FK ──────────────────────────────────────────────────

ALTER TABLE ref_packaging_formats
  ADD COLUMN derived_from_format_id BIGINT UNSIGNED NULL
    COMMENT 'Non-NULL for formats derived/fractional from a parent format (e.g. draft pours from keg). Inherits buildability gate from parent.',
  ADD CONSTRAINT fk_rpf_derived_from
    FOREIGN KEY (derived_from_format_id)
    REFERENCES ref_packaging_formats(id)
    ON DELETE RESTRICT
    ON UPDATE RESTRICT;

-- ── Part 1 seed: P25 and P50 → 20L keg (id=15) ──────────────────────────────

UPDATE ref_packaging_formats SET derived_from_format_id = 15 WHERE id IN (16, 17);

-- ── Part 2: ref_packaging_bom_templates for P25 (16) and P50 (17) ────────────
-- Mirrors the keg template (id=23, format_id=15, supply='we_supply', decoration_integral=0).
-- Required for the compiler metaQuery JOIN.

INSERT INTO ref_packaging_bom_templates (format_id, supply, decoration_integral, is_active)
VALUES
  (16, 'we_supply', 0, 1),
  (17, 'we_supply', 0, 1);

-- ── Part 3: fractional keg-share items on P25 (format 16) ───────────────────

INSERT INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, slot_scope)
VALUES
  (16, 'keg_collars', 0.0125, 'PKG_KEG_COLLARS', 100, 1, 10, 'we_supply_only'),
  (16, 'keg_safe',    0.0125, 'PKG_KEG_SAFE',    101, 1, 20, 'we_supply_only');

-- ── Part 3: fractional keg-share items on P50 (format 17) ───────────────────

INSERT INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, slot_scope)
VALUES
  (17, 'keg_collars', 0.025, 'PKG_KEG_COLLARS', 100, 1, 10, 'we_supply_only'),
  (17, 'keg_safe',    0.025, 'PKG_KEG_SAFE',    101, 1, 20, 'we_supply_only');
