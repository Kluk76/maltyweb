-- db/migrations/173_ref_mi_catalog_price_log_scotch_eshop.sql
--
-- What: Correct ref_mi.price for LOG_SCOTCH_ESHOP (id=201) to last-purchase, native currency.
--
-- Why:  Catalog 2.50 CHF/unit matched no actual purchase (deliveries: 4.55 on 2025-11-26,
--       then two on 2025-12-15 — 3.24 CHF/unit (del 72, 36 units) and 5.06 CHF/unit
--       (del 70, 6 units)). The "last-purchase" date is 2025-12-15 with two prices on the
--       same day; the operator resolved the ambiguity: use 3.24 (the main 36-unit line).
--       Accountant rule (per 171/172): catalog = LAST-PURCHASE, NATIVE currency. NO WAC/COP
--       impact — ref_mi.price is unread by both, and the MI has Active deliveries so WAC
--       values it today. Pure ref-data hygiene; de-risks future Phase-D COALESCE(WAC, catalog).
--
-- Convention (per 171/172): guard on old price; last_modified_by='web'; row_hash unchanged
--   (mi_id stable); currency unchanged (CHF, native).
--
-- Risk: LOW — no schema change, no WAC/COP impact, reversible. MySQL 8. No bare SELECT.
--
-- Old price verified live 2026-05-27: id=201 price=2.500000 CHF ✓
--
-- Rollback:
--   -- UPDATE ref_mi SET price=2.500000, last_modified_by='web' WHERE id=201 AND price=3.240000;

-- =============================================================================
-- id=201 LOG_SCOTCH_ESHOP — last-purchase per operator: del 72 (2025-12-15, 3.24 CHF/unit)
-- =============================================================================
UPDATE ref_mi
   SET price            = 3.240000,
       last_modified_by = 'web'
 WHERE id               = 201
   AND price            = 2.500000;
