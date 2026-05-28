-- Migration 191: Deactivate discontinued contracted-out SKU ZEP6C
--
-- Purpose : ZEP6C (id=57, format 10=6C, can-format, contractor-packaged) is a
--           DISCONTINUED single-run SKU. One packaging event (bd_packaging_v2 id=1213,
--           batch 126, 42912 cans = ~214.56 hl) in Feb 2024. The 2024 sales occurred
--           in the legacy pre-MySQL accounting era (our sales tables only cover
--           2025-12 → 2026-04). No future production planned (operator confirmed
--           2026-05-28). The 2024 contractor invoice hit account 4208 "frais
--           packaging externe" via direct supplier invoice at the time — already
--           in 2024 COGS at period level; no per-SKU allocation existed then and
--           there is nothing to reconstruct retroactively.
--
--           Setting is_active=0 prevents ZEP6C from appearing in new BOM/sales
--           flows. Same defensive pattern as migration 185 (PBD deactivation).
--
--           This deactivation is ONE OF TWO layers protecting against the ZEP6C
--           phantom-fill hazard (the other being the compiler buildability gate
--           in app/sku-bom-compile.php which rejects format 10 because its
--           catalog_id=NULL — un-commissioned. See 'gate hardening' diff to
--           that file landing in the same commit as this migration).
--
-- Safety check passed: 2026-05-28
--           - 0 inv_sales_bc rows (sku_code='ZEP6C' OR sku_id_fk=57)
--           - 0 inv_sales_order_lines rows
--           - 7 ref_sku_bom rows (all source='Brewing', preserved as historical liquid BOM)
--           - 1 bd_packaging_v2 event (id=1213, preserved as historical production record)
--           - Compiler gate (post-patch) reports SKU 57 as "format_id=10 not commissioned"
--             — deactivation closes the door at the SKU level, gate closes it at the
--             format level. Defense in depth.
--
-- Note   : If ZEP6C-style contracted-out packaging recurs, the path is documented
--          in PM memory: new SKU code + ref_packaging_bom_templates.supply='client_supply'
--          on the relevant (format, recipe) row + synthetic per-unit MI mapped to
--          account 4208 for the contractor fee (so per-SKU contractor cost is honest
--          in COGS, allocated to sold units). The compiler gate naturally protects
--          the rest.
--
-- Date   : 2026-05-28
-- Author : web

UPDATE ref_skus
   SET is_active = 0
 WHERE id = 57
   AND sku_code = 'ZEP6C';
