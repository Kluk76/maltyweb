-- 354_ord_order_bc_lines.sql
--
-- Part of build "B" of the BC operational-layer program.
--
-- 1. ord_order_bc_lines  — BC-side line snapshot, SIBLING of ord_order_lines.
--    Stores what BC holds for each open order so we can diff against
--    the operator-maintained ord_order_lines.  READ-ONLY reference;
--    NEVER used as source for stock / COGS depletions.
--
-- 2. ord_orders.bc_completely_shipped TINYINT(1) — BC mirror field only.
--    The pull writes this; the UI displays it as a signal ("BC: BL imprimé ✓").
--    It NEVER drives the operational status column.
--
-- 3. ord_orders.divergence_status ENUM — set by the pull when the diff
--    detects that ord_order_lines diverge from ord_order_bc_lines.
--    'correction_compta_requise' = admin must issue a BC credit-note + re-invoice.
--
-- 4. ord_orders.divergence_detail TEXT — JSON blob with the per-line diff.
--    Populated alongside divergence_status.
--
-- 5. doc_review_queue.type ENUM — extends with 'bc-order-correction-required'.
--
-- 6. schema_meta rows for the new table.

-- ── 1. BC-line snapshot table ─────────────────────────────────────────────────
CREATE TABLE `ord_order_bc_lines` (
  `id`                bigint unsigned  NOT NULL AUTO_INCREMENT,
  `order_id_fk`       bigint unsigned  NOT NULL COMMENT 'FK→ord_orders(id) (bc-sourced orders only)',
  `bc_line_no`        int              NOT NULL COMMENT 'BC Line_No (e.g. 10000, 20000)',
  `bc_item_no`        varchar(30)      COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'BC No field — the item code',
  `uom_code`          varchar(20)      COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'BC Unit_of_Measure_Code',
  `bc_qty`            decimal(10,2)    NOT NULL COMMENT 'BC Quantity for this line',
  `resolved_sku_id`   int unsigned     DEFAULT NULL COMMENT 'ref_skus.id resolved from bc_item_no; NULL if unresolved',
  `snapshot_at`       datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        COMMENT 'Last upsert timestamp from the BC pull',
  `created_at`        timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bc_line` (`order_id_fk`, `bc_line_no`),
  KEY `idx_bc_lines_order`    (`order_id_fk`),
  KEY `idx_bc_lines_sku`      (`resolved_sku_id`),
  CONSTRAINT `fk_bc_lines_order` FOREIGN KEY (`order_id_fk`)
    REFERENCES `ord_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bc_lines_sku` FOREIGN KEY (`resolved_sku_id`)
    REFERENCES `ref_skus` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='BC-side line snapshot (SIBLING of ord_order_lines — diverges BY DESIGN). Read-only reference for divergence diffing; NEVER drives stock or COGS.';

-- ── 2. BC mirror column on ord_orders ────────────────────────────────────────
ALTER TABLE `ord_orders`
  ADD COLUMN `bc_completely_shipped` tinyint(1) NOT NULL DEFAULT 0
    COMMENT 'BC-mirror: 1 when BC Completely_Shipped=True. Informational signal only — never drives operational status.',
  ADD COLUMN `divergence_status` enum('none','correction_compta_requise')
    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none'
    COMMENT 'Set by bc-sync diff: correction_compta_requise = ord_order_lines diverge from BC snapshot. Drives admin badge + RQ row.',
  ADD COLUMN `divergence_detail` text
    COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'JSON: per-line diff produced by bc-sync when divergence_status != none.';

-- ── 3. Extend doc_review_queue.type ENUM ─────────────────────────────────────
-- MySQL requires full ENUM redefinition to add a value.
ALTER TABLE `doc_review_queue`
  MODIFY COLUMN `type` enum(
    'supplier-unknown','ingredient-unknown','gl-drift','archive-candidate',
    'inactive-candidate','dynamic-vs-take-drift','rm-stale','rm-negative',
    'rm-orphan-mi','invoice-no-dn','dn-no-invoice','photonote-audit',
    'sales-sku-unknown','doc-classify-ambiguous','invoice-line-items-needed',
    'dn-invoice-duplicate','dn-low-confidence-line','sku-bom-unresolved',
    'garde_seuil_overdue','contamination_flagged','mother_abandoned',
    'packaged_volume_anomaly','repack-unresolved-bundle',
    'bc-customer-identity-drift','bc-order-correction-required'
  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

-- ── 4. schema_meta rows ───────────────────────────────────────────────────────
INSERT INTO `schema_meta` (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'ord_order_bc_lines',
  'source',
  'allowed',
  'ingest_bc_sales_orders.py',
  'BC-side line snapshot for bc-sourced orders. Retains what BC holds (item, uom, qty, resolved sku_id) so the bc-sync pull can diff against ord_order_lines. Diverges from ord_order_lines BY DESIGN when the operator corrects lines after BL lock. READ-ONLY reference — never the source for stock depletion or COGS.'
) ON DUPLICATE KEY UPDATE notes = VALUES(notes), writer_script = VALUES(writer_script);
