-- Migration 053: widen gl_account columns from VARCHAR(8) → VARCHAR(16)
-- Reason: operator audit (Phase G, 2026-05-20) uses asset-tag GL values like
--   "IMM-000439" / "IMM-000449" for immobilisation entries — these exceed
--   the legacy 8-char width. Widening to 16 makes room for asset tags AND
--   any future GL-account scheme change.
--
-- Tables affected: ref_mi, ref_mi_categories, ref_mi_subcategories, ref_suppliers
-- Safe: VARCHAR widening is a metadata-only operation in MySQL 5.7+/8.0 (no rewrite).

ALTER TABLE ref_mi               MODIFY COLUMN gl_account         VARCHAR(16) COLLATE utf8mb4_unicode_ci NULL;
ALTER TABLE ref_mi_categories    MODIFY COLUMN default_gl_account VARCHAR(16) COLLATE utf8mb4_unicode_ci NULL;
ALTER TABLE ref_mi_subcategories MODIFY COLUMN gl_account         VARCHAR(16) COLLATE utf8mb4_unicode_ci NULL;
ALTER TABLE ref_suppliers        MODIFY COLUMN gl_account         VARCHAR(16) COLLATE utf8mb4_unicode_ci NULL;
