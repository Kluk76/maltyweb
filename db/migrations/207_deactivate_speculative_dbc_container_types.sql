-- db/migrations/206_deactivate_speculative_dbc_container_types.sql
--
-- What: Set is_active=0 on three dbc_container_types catalog rows that have
--       no commissioned filler binding and no contracted-fill activation:
--         - BOT_GLASS_50 (id=2) — 50cl glass bottle
--         - BOT_PET_100 (id=3) — 1L PET bottle
--         - KEG_INOX_30 (id=7) — 30L stainless keg
--
-- Why : Audit 2026-05-28 (reports/vessel-commissioning-audit-2026-05-28.md
--       finding F5) flagged these as speculative seed rows from mig 115.
--       Operator confirmed 2026-05-28 (Kouros, direct response): mark
--       inactive. No commissioning planned in-house OR via the contracted-
--       out recurrence model (ref_packaging_bom_templates.supply='client_supply'
--       is dormant at 0 rows; gates on the same INNER-JOIN chain).
--
--       Follows the precedent already set for KEG_PET_20 (id=9, is_active=0
--       since mig 115). Refuse-don't-NULL hygiene — catalog spec ≠ commissioning
--       intent.
--
-- Safety check passed: 2026-05-28
--   - 0 ref_filler_containers rows reference any of (id=2, 3, 7) — verified
--     via SELECT COUNT(*) FROM ref_filler_containers WHERE container_id
--     IN (2,3,7). Active rows point at {1,4,5,6,8} only.
--   - 0 ref_packaging_format_mis edges anchored on these 3 (dormant table
--     anyway, minefield #5).
--   - 0 ref_container_mi rows for these 3.
--   - 0 bd_packaging_v2 events ever produced through them.
--   - dbc_container_types schema_meta corrections_policy='blocked' — this
--     migration is the sanctioned write path; manual UPDATE forbidden.
--
-- Risk: VERY LOW. Catalog flag only; no FK cascades, no downstream code
--       hard-codes is_active=1 on these IDs (verified grep).
--
-- Rollback:
--   UPDATE dbc_container_types SET is_active = 1
--    WHERE id IN (2, 3, 7);
--
-- Date   : 2026-05-28
-- Author : web

UPDATE dbc_container_types
   SET is_active = 0
 WHERE id IN (2, 3, 7)
   AND container_code IN ('BOT_GLASS_50', 'BOT_PET_100', 'KEG_INOX_30');
