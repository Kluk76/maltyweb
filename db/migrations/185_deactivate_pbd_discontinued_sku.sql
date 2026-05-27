-- Migration 185: Deactivate discontinued composite sampler PBD
--
-- Purpose : PBD (id=294) is a distinct DISCONTINUED product (sampler pack,
--           last sold 2025-12-23). It is NOT an alias of PD8. Setting
--           is_active=0 prevents it from appearing in new BOM/composite/sales
--           flows while preserving all historical references (inv_sales_order_lines
--           has 210 rows spanning Dec 2025 — those stay intact as read-only history).
--           Zero BOM rows, zero composite slots, zero aliases — clean deactivation.
--           This closes a potential zero-cost-leak path had a new BOM line been
--           created for PBD inadvertently.
--
-- Safety check passed: 2026-05-27 (0 active BOM, 0 composite slots, 0 aliases,
--           0 inv_fg_stocktake, 0 inv_sales_bc, 0 bd_packaging string matches;
--           210 inv_sales_order_lines are historical Dec-2025, not FK-gating writes).
--
-- Date   : 2026-05-27
-- Author : web

UPDATE ref_skus
   SET is_active = 0
 WHERE id = 294
   AND sku_code = 'PBD';
