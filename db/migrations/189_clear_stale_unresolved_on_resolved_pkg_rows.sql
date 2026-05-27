-- Migration 189: clear stale `sku_unresolved` flag from the 8 bd_packaging_v2 rows
--                that migration 188 resolved (sku_id_fk now set).
--
-- After 188, 8 rows carry a now-false `sku_unresolved` flag despite being resolved:
--   - 4 EPH cans (ids 103/266/275/399): BC->C resolved to EPH{n}C. Keep their
--     legitimate historical markers (fmt_bc_folded, qa_extraction_overshot);
--     only drop the stale sku_unresolved token.
--   - 4 collab rows (ids 1982/1983/2181/2182): Docks/DrunkBeard CollabIn, resolved
--     to DOCB/DOCF/DGDB/DGDF. Replace sku_unresolved with collabin_rerouted_to_neb
--     (the same flag the normalizer now emits for these rerouted rows).
--
-- All 8 satisfy chk_sku_or_flagged via the sku_id_fk-NOT-NULL branch, so removing the
-- flag is safe. Token-safe edits within the comma-list (no whole-column overwrite).
--
-- Date   : 2026-05-28
-- Author : web

UPDATE bd_packaging_v2
   SET audit_flags = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', audit_flags, ','), ',sku_unresolved,', ','))
 WHERE id IN (103, 266, 275, 399)
   AND sku_id_fk IS NOT NULL
   AND audit_flags LIKE '%sku_unresolved%';

UPDATE bd_packaging_v2
   SET audit_flags = REPLACE(audit_flags, 'sku_unresolved', 'collabin_rerouted_to_neb')
 WHERE id IN (1982, 1983, 2181, 2182)
   AND sku_id_fk IS NOT NULL
   AND audit_flags LIKE '%sku_unresolved%';
