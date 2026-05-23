-- db/migrations/089_bd_packaging_v2_backfill.sql
-- What: Three-pass backfill for bd_packaging_v2 and ref_skus
--   Step 1: Backfill ref_skus.format_id from sku_code suffix → ref_packaging_formats.format_code
--           (longest-match wins — e.g. 'STIBC' → 'BC' not 'C', 'ZEP33C' → '33C' not 'C')
--   Step 2: Re-derive bd_packaging_v2.sku_id_fk using the now-populated format_id JOIN
--           (recipe_id_fk × format_id). Clears sku_unresolved audit flag on resolved rows.
--   Step 3: Best-effort client_fk backfill (name-match against ref_clients).
-- Why: Migration 088 pattern-matched 88% (1988/2178 main rows). The remaining 248 have
--      sku_unresolved because ref_skus.format_id was 100% NULL, blocking the canonical JOIN.
--      After this migration ~4 rows (C-suffix EPH, fmt_bc_folded) and ~244 rows (NULL
--      nebuleuse_format_suffix) will remain unresolved — both categories are genuinely
--      unresolvable without operator input.
-- Risk: Low. UPDATE on ref_skus.format_id (NULL→value, never overwrites); UPDATE on
--       bd_packaging_v2 only touches sku_id_fk IS NULL rows.
-- Rollback: UPDATE ref_skus SET format_id = NULL;
--           UPDATE bd_packaging_v2 SET sku_id_fk = NULL, audit_flags = CONCAT_WS(',', audit_flags, 'sku_unresolved') WHERE ...

-- ─── Step 1: backfill ref_skus.format_id ─────────────────────────────────────
-- For each SKU, find the LONGEST format_code that is a suffix of sku_code.
-- Longest-match ensures 'STIBC' → 'BC' (not 'C'), 'XMASPACK' → 'XMASPACK' (not other suffix).
-- Only updates rows where format_id IS NULL (idempotent).

UPDATE ref_skus rs
SET rs.format_id = (
  SELECT rf.id
  FROM ref_packaging_formats rf
  WHERE rs.sku_code LIKE CONCAT('%', rf.format_code)
  ORDER BY LENGTH(rf.format_code) DESC
  LIMIT 1
),
rs.last_modified_by = 'ingest'
WHERE rs.format_id IS NULL;

-- ─── Step 2: re-derive bd_packaging_v2.sku_id_fk ─────────────────────────────
-- Join on recipe_id_fk × format_id (now populated). Clears sku_unresolved flag.
-- Only touches rows where sku_id_fk IS NULL, recipe_id_fk IS NOT NULL,
-- and nebuleuse_format_suffix IS NOT NULL (NULL-suffix rows can't be resolved this way).

UPDATE bd_packaging_v2 v2
JOIN ref_packaging_formats rf
  ON rf.format_code = v2.nebuleuse_format_suffix
JOIN ref_skus rs
  ON rs.recipe_id = v2.recipe_id_fk
  AND rs.format_id = rf.id
SET
  v2.sku_id_fk = rs.id,
  v2.audit_flags = NULLIF(
    TRIM(BOTH ',' FROM REGEXP_REPLACE(COALESCE(v2.audit_flags, ''), 'sku_unresolved,?|,?sku_unresolved', '')),
    ''
  ),
  v2.updated_at = NOW()
WHERE v2.sku_id_fk IS NULL
  AND v2.recipe_id_fk IS NOT NULL
  AND v2.nebuleuse_format_suffix IS NOT NULL;

-- ─── Step 3: client_fk best-effort backfill ───────────────────────────────────
-- keg_client_delivered is free-text event names; ref_clients holds company names.
-- Expected match rate ~0% (event names ≠ company names) — acceptable per spec.
-- Included for completeness and future data quality as ref_clients is populated.

UPDATE bd_packaging_v2 v2
JOIN ref_clients c
  ON LOWER(TRIM(c.name)) = LOWER(TRIM(v2.keg_client_delivered))
SET
  v2.client_fk = c.id,
  v2.updated_at = NOW()
WHERE v2.keg_client_delivered IS NOT NULL
  AND v2.client_fk IS NULL;
