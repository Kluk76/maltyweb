-- Migration 188: bd_packaging_v2 NULL sku_id_fk resolution
-- Purpose: Resolves the 248 NULL sku_id_fk rows across 3 buckets.
--   Bucket B (4 rows): EPH{1-4} can rows — neb format C, recipe 58/63/68/72.
--     Now that ref_skus.id 29/32/35/38 carry sku_code EPH{n}C (migration 187),
--     these rows can be linked directly.
--   Bucket C (4 rows): Docks-NEIPA (recipe 29) + DrunkBeard-Galactic-Drift (recipe 31)
--     collab rows. Classification = Neb/CollabIn; route to neb_beer branch,
--     assign nebuleuse_format_suffix from run_type, link to DOC*/DGD* SKU ids.
--   Bucket D (244→240 rows after Bucket C): true contract runs — token-replace
--     sku_unresolved→contract_run in audit_flags. Applied LAST so the 4 Bucket C
--     rows (which gain neb_beer in this same migration) are excluded by WHERE.
--   CHECK constraint widened: chk_sku_or_flagged updated to also accept 'contract_run'
--     as a valid resolution marker (alongside sku_unresolved and parallel rows).
-- Author: web
-- Date: 2026-05-28

-- ── Widen CHECK constraint to accept contract_run flag ────────────────────────
-- The existing constraint requires: sku_id_fk IS NOT NULL OR 'sku_unresolved' in flags OR row_origin='parallel'.
-- True contract runs keep sku_id_fk=NULL by design; 'contract_run' is their resolution marker.
ALTER TABLE bd_packaging_v2
  DROP CHECK chk_sku_or_flagged,
  ADD CONSTRAINT chk_sku_or_flagged CHECK (
    (sku_id_fk IS NOT NULL)
    OR (LOCATE('sku_unresolved', COALESCE(audit_flags,'')) > 0)
    OR (LOCATE('contract_run',   COALESCE(audit_flags,'')) > 0)
    OR (row_origin = 'parallel')
  );

-- ── Bucket B: EPH can rows (4) ────────────────────────────────────────────────
-- predicate: sku_id_fk IS NULL + recipe IN EPH set + nebuleuse_format_suffix='C'
UPDATE bd_packaging_v2
SET sku_id_fk = CASE recipe_id_fk
    WHEN 58 THEN 29
    WHEN 63 THEN 32
    WHEN 68 THEN 35
    WHEN 72 THEN 38
END
WHERE sku_id_fk IS NULL
  AND recipe_id_fk IN (58, 63, 68, 72)
  AND nebuleuse_format_suffix = 'C';

-- ── Bucket C: Docks/DGD CollabIn rows (4) ────────────────────────────────────
-- recipe 29 = Docks-NEIPA → DOCB (id 21, bot) / DOCF (id 22, keg)
-- recipe 31 = DrunkBeard-Galactic-Drift → DGDB (id 8, bot) / DGDF (id 9, keg)
UPDATE bd_packaging_v2
SET
  nebuleuse_format_suffix = CASE run_type WHEN 'bot' THEN 'B' WHEN 'keg' THEN 'F' END,
  sku_id_fk = CASE
    WHEN recipe_id_fk = 29 AND run_type = 'bot' THEN 21
    WHEN recipe_id_fk = 29 AND run_type = 'keg' THEN 22
    WHEN recipe_id_fk = 31 AND run_type = 'bot' THEN 8
    WHEN recipe_id_fk = 31 AND run_type = 'keg' THEN 9
  END,
  neb_beer = CASE
    WHEN recipe_id_fk = 29 AND run_type = 'bot' THEN 'DOCB'
    WHEN recipe_id_fk = 29 AND run_type = 'keg' THEN 'DOCF'
    WHEN recipe_id_fk = 31 AND run_type = 'bot' THEN 'DGDB'
    WHEN recipe_id_fk = 31 AND run_type = 'keg' THEN 'DGDF'
  END
WHERE sku_id_fk IS NULL
  AND recipe_id_fk IN (29, 31)
  AND run_type IN ('bot', 'keg')
  AND contract_beer IS NOT NULL;

-- ── Bucket D: true contract run relabel (≈240, after Bucket C) ───────────────
-- Token-replace sku_unresolved→contract_run within the comma-list audit_flags.
-- The 4 Bucket C rows are now excluded because their neb_beer is SET (no longer NULL).
UPDATE bd_packaging_v2
SET audit_flags = REPLACE(audit_flags, 'sku_unresolved', 'contract_run')
WHERE contract_beer IS NOT NULL
  AND neb_beer IS NULL
  AND nebuleuse_format_suffix IS NULL
  AND audit_flags LIKE '%sku_unresolved%';
