-- db/migrations/273_ref_sku_bom_add_slot_name.sql
--
-- What: Add slot_name column to ref_sku_bom + update unique key to include slot_name.
--
-- Why:  Fix 4 of migration 272 adds a box_label slot to format 1 (B/24-box), which uses the
--       same label MI as the bottle label slot (e.g. PKG_LABEL_DGD appears on both the label
--       slot (qty=24) and the box_label slot (qty=1)). The existing unique key
--       (sku_id, ingredient_raw, source) rejects the second row.
--       Adding slot_name to the key allows the same MI to appear on two distinct slots.
--       The compiler already computes slot_name (it's in row_hash); it now also writes it.
--
-- Impact: ref_sku_bom.slot_name will be NULL for all existing rows until the next full
--         recompile. The new unique key (sku_id, ingredient_raw, source, slot_name) treats
--         NULL as a distinct value in MySQL, so old NULL rows and new non-NULL rows do not
--         collide. A full recompile backfills slot_name for all compiled rows.
--
-- schema_meta: ref_sku_bom is a derived table (corrections_policy='blocked_with_redirect');
--              the compile service is the sole writer. This migration only adds a column and
--              updates the index — no data is written to the table contents here.
--

-- ── 1. Add slot_name column if it does not already exist ─────────────────────────────
-- (The column may already be present if step 1 of a previous partial run succeeded.)
-- MySQL 8 has no ADD COLUMN IF NOT EXISTS; we guard via the migration file being applied
-- only once by schema_migrations. If re-running manually after a partial failure, skip
-- this ALTER if the column already exists.

ALTER TABLE ref_sku_bom
  ADD COLUMN slot_name VARCHAR(64) NULL DEFAULT NULL
    COMMENT 'Packaging slot name from ref_packaging_items (label, box_label, sticker, etc.) — NULL for pre-273 rows and liquid rows'
    AFTER source;

-- ── 2. Add standalone sku_id index so the FK fk_bom_sku (ON DELETE CASCADE) does not ──
-- depend on uniq_sku_ing_src as its backing index after we replace that key.

ALTER TABLE ref_sku_bom
  ADD INDEX idx_sku_id (sku_id);

-- ── 3. Drop old unique key (sku_id, ingredient_raw, source) ──────────────────────────
-- Safe now: fk_bom_sku's ON DELETE CASCADE is backed by idx_sku_id above.

ALTER TABLE ref_sku_bom
  DROP INDEX uniq_sku_ing_src;

-- ── 4. Add new unique key including slot_name ────────────────────────────────────────
--
-- MySQL treats two NULL values as DISTINCT for unique-key purposes (NULL ≠ NULL), so
-- old rows with slot_name=NULL will not collide with each other even after this change.
-- New compiled rows will have explicit slot_name values, providing real uniqueness.

ALTER TABLE ref_sku_bom
  ADD UNIQUE KEY uniq_sku_ing_src_slot (sku_id, ingredient_raw(191), source, slot_name(64));

-- ── 5. Widen ref_sku_packaging_choices.qty_per_unit to DECIMAL(14,6) ─────────────────
-- The original DECIMAL(10,4) cannot represent 0.000930 (eshop scotch per-box fraction:
-- 0.92m ÷ 990m roll). The value silently rounded to 0.0009 (3.2% error).
-- Widening to DECIMAL(14,6) matches ref_packaging_items.qty_per_unit precision.

ALTER TABLE ref_sku_packaging_choices
  MODIFY COLUMN qty_per_unit DECIMAL(14,6) NOT NULL DEFAULT 0.000000;
