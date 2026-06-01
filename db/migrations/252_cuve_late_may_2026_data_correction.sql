-- db/migrations/252_cuve_late_may_2026_data_correction.sql
--
-- Operator-authorized cuve data correction (matches the raw operator form).
-- Yesterday's late-May 2026 cuve backlog was entered via the new form WHILE the
-- parallel-run compute was still broken (pre-mig 250/251), which left two errors:
--   1. The 2026-05-27 950 L serving-tank fill was tombstoned (id 6719, prod NULL).
--   2. The 2026-05-28 500 L fill was mis-entered as a 5th fill on 2026-05-21
--      (id 2233); the raw form has four 500 L fills on the 21st and one on the 28th.
--
-- NB cuve already stores explicit litres (ref_skus ZEPV/EMBV/MOOV: hl_per_unit=0.01,
-- units_per_pack=1 → 1 unit = 1 litre); there is NO units-vs-fixed-volume problem and
-- no schema/form change required. This is a pure data correction.
--
-- Already applied out-of-band via php -r on 2026-06-01 (recorded for the audit trail).
-- Idempotent: re-applying sets the same values. UPDATE-only (no result-set-leaving
-- statement) so it applies cleanly via migrate.php.
--
-- POST-CORRECTION (2026 Neb cuve, reuse-filtered, non-tombstoned): 315.00 HL gross,
-- per-day 21st 2000 / 27th 2450 / 28th 500 / 29th 1500 L — matches the raw form.
-- All four formats now reconcile exactly: keg 2828.2 / bot 1834.72 / can 363.60 /
-- cuv 315.00 → total 5341.52 HL gross.
--
-- ROLLBACK: re-tombstone 6719 (SET is_tombstoned=1) and re-date 2233 to 2026-05-21.
-- ============================================================================

-- 1. Restore the 27th's tombstoned 950 L fill (give it prod + special + SKU).
UPDATE bd_packaging_v2
   SET is_tombstoned = 0,
       prod_total_units = 950,
       special_qty_units = 950,
       sku_id_fk = 61            -- ZEPV (Zepp serving tank)
 WHERE id = 6719 AND run_type = 'cuv' AND recipe_id_fk = 57;

-- 2. Re-date the 28th's 500 L fill (mis-entered as a 5th fill on the 21st).
UPDATE bd_packaging_v2
   SET event_date = '2026-05-28'
 WHERE id = 2233 AND run_type = 'cuv' AND prod_total_units = 500;

-- 3. Recompute stored HL columns for the restored row from the corrected view.
UPDATE bd_packaging_v2 p
   JOIN v_bd_packaging_v2_vendable v ON v.id = p.id
   SET p.vendable_hl      = v.vendable_hl,
       p.beer_tax_base_hl = v.beer_tax_base_hl,
       p.loss_kpi_hl      = v.loss_kpi_hl
 WHERE p.id = 6719;

-- end migration 252
