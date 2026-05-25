-- db/migrations/139_gated_sku_bom_mi_id_check.sql
-- What: Add ENFORCED CHECK constraint to ref_sku_bom: mi_id IS NOT NULL OR bom_source='liquid'
--       This is the hard floor under the "refuse-don't-NULL" rule for the BOM compiler.
-- Why: Currently 26 ref_sku_bom rows have mi_id=NULL (printed-can lines for EMB/MOO/SPY/STI/ZEP
--      SKUs). Those silently zero out that component's COGS. The CHECK prevents any future
--      NULL lines from slipping in once the 26 are resolved by the Phase-3 recompute.
--      bom_source='liquid' is the only legitimate NULL: liquid lines carry no MI (they come
--      from observed brewing via recipe × hl_per_unit, not a ref_mi row).
--
-- !! GATED MIGRATION — DO NOT APPLY until the pre-check query below returns 0.
--    This means the Phase-3 recompute service has run and the 26 printed-can NULL lines
--    have been replaced by proper PKG_CAN_<beer> MI resolutions (or sku-bom-unresolved RQ rows).
--    Run the pre-check query above before applying. Applying while violations exist will fail.
--
-- Risk: ALTER TABLE on a 1749-row (and growing) table. ALGORITHM=INPLACE (adding CHECK
--       constraint is INPLACE in MySQL 8). Blocks writes briefly during the metadata lock.
--       LOW risk on row count; ZERO tolerance if violation count > 0 (MySQL refuses the ALTER).
-- Pre-check (run manually, result must be 0 before applying):
--   SELECT COUNT(*) FROM ref_sku_bom
--    WHERE mi_id IS NULL AND (bom_source IS NULL OR bom_source <> 'liquid');
-- Rollback: ALTER TABLE ref_sku_bom DROP CHECK chk_rsb_mi_id_not_null;

ALTER TABLE ref_sku_bom
  ADD CONSTRAINT chk_rsb_mi_id_not_null
    CHECK (mi_id IS NOT NULL OR bom_source = 'liquid')
    ENFORCED;
