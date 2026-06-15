-- 366_ord_bc_credit_memos.sql
--
-- Phase-2 D2 — maltytask→BC CREDIT-MEMO write spine.
--
-- 1. ord_bc_credit_memos — tracking table for D2 avoir+re-invoice pairs.
--    One row per ord_orders correction (order_id_fk UNIQUE → one correction
--    per order; echo_ref UNIQUE → idempotency on re-runs).
--    bc_credit_memo_no   : NULL until the avoir is confirmed by BC.
--    bc_reinvoice_no     : NULL until the corrected re-invoice is confirmed.
--    Both are written in ONE local txn on successful BC CREATE.
--
-- 2. Extend ord_orders.divergence_status ENUM with terminal value
--    'correction_compta_emise'.
--    Semantics: flipped from 'correction_compta_requise' on successful avoir
--    create so a drained correction cannot re-drain.
--    MySQL 8 MODIFY COLUMN — full ENUM redefinition (no IF-NOT-EXISTS).
--
-- 3. schema_meta row for ord_bc_credit_memos (class=derived, corrections_policy=allowed).
--
-- 4. Update schema_meta notes for ord_orders to reflect D2 writer.
--
-- MySQL-8 clean: no bare SELECT, no ADD COLUMN IF NOT EXISTS.
-- Idempotency via schema_migrations.

-- ── 1. ord_bc_credit_memos tracking table ────────────────────────────────────
CREATE TABLE `ord_bc_credit_memos` (
  `id`                  bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id_fk`         bigint unsigned NOT NULL
    COMMENT 'FK→ord_orders(id) — the correction order. UNIQUE: one correction per order.',
  `bc_credit_memo_no`   varchar(40)     COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'BC-assigned credit-memo number (e.g. NC212500). NULL until avoir confirmed.',
  `bc_reinvoice_no`     varchar(40)     COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'BC-assigned re-invoice order number. NULL until re-invoice confirmed.',
  `echo_ref`            varchar(64)     COLLATE utf8mb4_unicode_ci NOT NULL
    COMMENT 'Echo tag written to BC externalDocumentNumber: mt:cm:<order_id>. Idempotency guard.',
  `created_at`          timestamp       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          timestamp       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cm_order`    (`order_id_fk`),
  UNIQUE KEY `uniq_cm_echo_ref` (`echo_ref`),
  KEY `idx_cm_bc_no`            (`bc_credit_memo_no`),
  CONSTRAINT `fk_cm_order` FOREIGN KEY (`order_id_fk`)
    REFERENCES `ord_orders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='D2 tracking: BC avoir (SalesCreditMemo) + re-invoice (SalesOrder) pairs emitted by push_bc_credit_memos.py. One row per corrected ord_orders. bc_credit_memo_no/bc_reinvoice_no written in one txn on confirmed BC CREATE.';

-- ── 2. Extend divergence_status ENUM with terminal value ─────────────────────
-- Full ENUM redefinition required by MySQL 8 to add a value.
-- Existing values: 'none', 'correction_compta_requise'
-- New terminal value: 'correction_compta_emise'
-- CHARACTER SET + COLLATE must match original column (utf8mb4_unicode_ci).
ALTER TABLE `ord_orders`
  MODIFY COLUMN `divergence_status`
    ENUM('none','correction_compta_requise','correction_compta_emise')
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci
    NOT NULL
    DEFAULT 'none'
    COMMENT 'Set by bc-sync diff: correction_compta_requise = lines diverge; correction_compta_emise = avoir+re-invoice emitted (terminal; prevents re-drain).';

-- ── 3. schema_meta row for ord_bc_credit_memos ───────────────────────────────
INSERT INTO `schema_meta` (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'ord_bc_credit_memos',
  'derived',
  'allowed',
  'scripts/python/push_bc_credit_memos.py',
  'D2 tracking table: one row per ord_orders correction. Stores BC-assigned avoir number (bc_credit_memo_no) and re-invoice number (bc_reinvoice_no) written in a single local txn on successful BC CREATE. echo_ref is the idempotency guard written to BC externalDocumentNumber. corrections_policy=allowed: admin may correct bc_credit_memo_no if BC-side number drifts.'
) ON DUPLICATE KEY UPDATE notes = VALUES(notes), writer_script = VALUES(writer_script);

-- ── 4. Update schema_meta notes for ord_orders ───────────────────────────────
UPDATE `schema_meta`
   SET writer_script = 'expeditions.php, ingest_bc_sales_orders.py, push_bc_sales_orders.py, push_bc_credit_memos.py',
       notes         = 'Order header. source=maltytask: born in maltytask, not yet in BC. bc_no set on CREATE (push_bc_sales_orders). divergence_status: none → correction_compta_requise (bc-sync diff) → correction_compta_emise (D2 avoir+re-invoice emitted, terminal). corrections_policy=allowed.',
       updated_at    = CURRENT_TIMESTAMP
 WHERE table_name = 'ord_orders';
