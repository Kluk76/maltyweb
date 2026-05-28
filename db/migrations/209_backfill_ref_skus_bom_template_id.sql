-- db/migrations/209_backfill_ref_skus_bom_template_id.sql
--
-- What: Backfill ref_skus.bom_template_id from ref_packaging_bom_templates
--       via the (format_id, supply='we_supply', is_active=1) join, scoped
--       to active SKUs only.
--
-- Why : Audit 2026-05-28 finding F4. ref_skus.bom_template_id is NULL on
--       all 154 active SKUs because it was only intended to be set at SDC
--       activation time (web UI), never backfilled for imported/legacy SKUs.
--       The compiler doesn't use this column (it joins on format_id) so
--       there is ZERO operational impact — but the SDC display widget reads
--       it for the BOM-template selector, so all SKUs appear blank in that
--       dropdown.
--
--       The mapping is verifiably 1:1 today: each of the 15 formats with
--       active SKUs has exactly one active we_supply template. Confirmed
--       pre-flight 2026-05-29 (0 (format_id, decoration_integral)
--       collisions in active we_supply templates).
--
--       Operator confirmed 2026-05-29: backfill.
--
-- Safety check passed: 2026-05-29
--   - 15/15 formats with active we_supply templates have n=1 template
--     each → no disambiguation needed.
--   - 122 active SKUs will receive a bom_template_id.
--   - 32 active SKUs will stay NULL:
--       * 2 alias SKUs (format_id IS NULL → PACKDECX8, EPH24P)
--       * 30 SKUs on draft-pour (P25, P50) or composite formats (PD8,
--         XMASPACK, PAL, PAC) — these formats correctly have no
--         we_supply BOM template (draft pours have no packaging step;
--         composites resolve through the composite-slot path).
--   - The column is read-only consumed by the SDC widget; compiler
--     gates on format_id, so no risk to BOM compile/COGS.
--   - audit_row_revisions captures every UPDATE with before/after JSON.
--
-- Risk: VERY LOW. Cosmetic column backfill, no FK cascades, no
--       compiler dependency, fully reversible.
--
-- Rollback:
--   UPDATE ref_skus SET bom_template_id = NULL
--    WHERE id IN (SELECT target_pk FROM audit_row_revisions
--                  WHERE target_table='ref_skus'
--                    AND comment='backfill_bom_template_id_mig209');
--   DELETE FROM audit_row_revisions
--    WHERE target_table='ref_skus'
--      AND comment='backfill_bom_template_id_mig209';
--
-- Date   : 2026-05-29
-- Author : web

-- STEP 1: audit row revisions BEFORE the UPDATE (captures intent even
-- if the UPDATE fails)
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0                                            AS user_id,
  'migration_209'                              AS username,
  'ref_skus'                                   AS target_table,
  s.id                                         AS target_pk,
  'update'                                     AS action,
  JSON_OBJECT('bom_template_id', s.bom_template_id) AS before_json,
  JSON_OBJECT('bom_template_id', bt.id)        AS after_json,
  'backfill_bom_template_id_mig209'            AS comment
FROM ref_skus s
JOIN ref_packaging_bom_templates bt
  ON bt.format_id = s.format_id
 AND bt.supply = 'we_supply'
 AND bt.is_active = 1
WHERE s.is_active = 1
  AND s.bom_template_id IS NULL;

-- STEP 2: the backfill
UPDATE ref_skus s
JOIN ref_packaging_bom_templates bt
  ON bt.format_id = s.format_id
 AND bt.supply = 'we_supply'
 AND bt.is_active = 1
   SET s.bom_template_id = bt.id
 WHERE s.is_active = 1
   AND s.bom_template_id IS NULL;
