-- db/migrations/208_deactivate_format_6c.sql
--
-- What: Deactivate ref_packaging_formats id=10 (6C — "6-pack tray of cans
--       24×50cl") and its orphan BOM template id=11.
--
-- Why : Audit 2026-05-28 finding F1 (reports/vessel-commissioning-audit-
--       2026-05-28.md). Format 6C is a physically distinct 6-can tray that
--       has no matching dbc_packaging_format_templates row (catalog_id=NULL)
--       and no active SKU. The only SKU ever pointed at it (ZEP6C, id=57)
--       was deactivated in mig 191. F1 + the 6C entry in F2 (single 2024
--       event, 0 active SKUs) are the same architectural smell: speculative
--       format never modelled in the dbc catalog.
--
--       Operator confirmed 2026-05-29: deactivate. If a 6-can tray ever
--       recurs the right path is to add a SIX_TRAY_CAN_50 dbc template +
--       matching dbc_container_types entry, then re-activate.
--
-- Safety check passed: 2026-05-28
--   - 0 ACTIVE SKUs with format_id=10. The single SKU (ZEP6C id=57) was
--     already is_active=0 via mig 191.
--   - 0 ref_packaging_format_mis edges on format_id=10 (dormant table anyway).
--   - 1 ref_packaging_bom_templates row (id=11, supply='we_supply',
--     decoration_integral=1, is_active=1) hangs off format 10 — deactivated
--     in the same migration to avoid orphan-template audit drift.
--   - Format 10 was never the only format covering a SKU's needs (it has no
--     active SKUs at all), so no downstream BOM compile path breaks.
--   - 1 historical bd_packaging_v2 event (Feb 2024, ZEP6C contractor run)
--     preserved as production record — deactivation is forward-only.
--
-- Risk: VERY LOW. Catalog flags only. No FK cascades. No active downstream
--       consumer relies on format 10.
--
-- Rollback:
--   UPDATE ref_packaging_formats     SET is_active = 1 WHERE id = 10;
--   UPDATE ref_packaging_bom_templates SET is_active = 1 WHERE id = 11;
--
-- Date   : 2026-05-29
-- Author : web

UPDATE ref_packaging_formats
   SET is_active = 0
 WHERE id = 10
   AND format_code = '6C';

UPDATE ref_packaging_bom_templates
   SET is_active = 0
 WHERE id = 11
   AND format_id = 10;
