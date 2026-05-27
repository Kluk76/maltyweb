-- Migration 186: Link COLLAB4PACK to format 4PB (loose bottles, no holder)
--
-- Purpose : COLLAB4PACK (id=302, sku_code='COLLAB4PACK') ships as 4 LOOSE bottles
--           (no cardboard holder). Format id=4 is '4PB' (4-pack loose bottles 4×33cl).
--           The -4PB convention means WITHOUT holder; the `holder` slot in format 4
--           has default_mi_id_fk=NULL (inert — will not phantom-fill a holder cost).
--           Format 4 has no outer-box/carton slot (only: bottle, crown_caps, label,
--           holder). This FK assignment is the prerequisite for the BOM recompile
--           step (NOT performed here — separate diff-gated step).
--
-- Safety check passed: 2026-05-27 — format 4 `holder` slot (ref_packaging_items id=21)
--           has default_mi_id_fk=NULL, is_default_checked=1, slot_scope=we_supply_only.
--           No outer-box slot exists on format 4. Holder is inert — no phantom billing.
--
-- Date   : 2026-05-27
-- Author : web

UPDATE ref_skus
   SET format_id = 4
 WHERE id = 302
   AND sku_code = 'COLLAB4PACK';
