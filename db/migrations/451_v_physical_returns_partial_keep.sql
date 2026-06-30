-- Migration 451 — v_physical_returns: partial-keep refinement
-- Purpose: the previous view stripped ANY credit that has a same-day
-- shipment/invoice for the same customer+SKU (blunt — wrongly strips genuine
-- PARTIAL returns booked same-day as the shipment, e.g. NC214850).
-- Changed to: strip ONLY full reversals (credit qty == same-day shipped qty);
-- KEEP partials (credit qty < shipped qty on the same day).
--
-- Logic of new exclusion clause:
--   - No same-day shipment → COALESCE(-SUM, -1) = -1; credit (>0) <> -1 → KEEP
--   - Full reversal (credit == shipped) → l.qty_signed == -SUM → EXCLUDE
--   - Partial (credit < shipped) → l.qty_signed <> -SUM → KEEP
--
-- All other columns, predicates, and the rebate-exclusion clause are unchanged.

CREATE OR REPLACE ALGORITHM=UNDEFINED DEFINER=`maltytask_app`@`localhost`
  SQL SECURITY DEFINER
  VIEW `v_physical_returns` AS
SELECT
  `l`.`id`,
  `l`.`posting_date`,
  `l`.`doc_type`,
  `l`.`doc_type_raw`,
  `l`.`bc_document_no`,
  `l`.`bc_line_seq`,
  `l`.`bc_source_no`,
  `l`.`customer_id_fk`,
  `l`.`sku_code_raw`,
  `l`.`sku_id_fk`,
  `l`.`qty_signed`,
  `l`.`qty_invoiced`,
  `l`.`sales_amount_chf`,
  `l`.`hl_resolved`,
  `l`.`source_file`,
  `l`.`imported_at`,
  `l`.`dedup_key`
FROM `inv_sales_ledger` `l`
WHERE
  `l`.`doc_type` IN ('credit', 'return_receipt')
  AND `l`.`qty_signed` > 0
  AND `l`.`sku_id_fk` IS NOT NULL
  -- Partial-keep: exclude ONLY full reversals (credit qty == same-day shipped qty).
  -- KEEP when no same-day shipment (COALESCE → -1, credit >0 never equals -1),
  -- KEEP when credit qty < shipped qty (genuine partial return, e.g. NC214850).
  AND `l`.`qty_signed` <> (
      SELECT COALESCE(-SUM(`s`.`qty_signed`), -1)
        FROM `inv_sales_ledger` `s`
       WHERE `s`.`customer_id_fk` = `l`.`customer_id_fk`
         AND `s`.`sku_id_fk`      = `l`.`sku_id_fk`
         AND `s`.`doc_type`       IN ('shipment', 'invoice')
         AND `s`.`posting_date`   = `l`.`posting_date`
  )
  -- Rebate-exclusion: suppress lines that have been processed as a rebate
  -- disposition in the returns workflow (unchanged from previous version).
  AND NOT EXISTS (
      SELECT 1
        FROM `ord_returns` `r`
        JOIN `ord_return_lines` `rl` ON `rl`.`return_id_fk` = `r`.`id`
       WHERE `r`.`origin_bc_document_no` = `l`.`bc_document_no`
         AND `rl`.`sku_id_fk`            = `l`.`sku_id_fk`
         AND `rl`.`disposition`          = 'rebate'
  );
