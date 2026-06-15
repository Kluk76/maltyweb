-- 361_ord_orders_bc_no_and_maltytask_source.sql
--
-- Phase-2 D1 — maltytask→BC ORDER-CREATE write spine.
--
-- 1. ord_orders.bc_no VARCHAR(40) NULL
--    BC-assigned order number returned on POST (e.g. 'ORD210070').
--    Distinct from source_ref ('bc:<No>' for BC-origin orders; 'mt:<id>' for
--    maltytask-native orders). Set in ONE local transaction alongside the
--    source_ref rekey when BC CREATE succeeds.
--    NULL until BC confirms the order.
--
-- 2. Add 'maltytask' to ord_orders.source ENUM.
--    Origin discriminator for orders born inside maltytask (e.g. manual B2B
--    entry from the Expéditions UI). Distinct from 'bc' (orders pulled FROM
--    Business Central). When push_bc_sales_orders.py publishes a maltytask-
--    native order to BC, source stays 'maltytask'; source_ref is rekeyed from
--    'mt:<id>' to 'bc:<No>' and bc_no is written in the same transaction.
--
-- 3. schema_meta update for ord_orders (note new writer script).
--
-- MySQL-8 INSTANT DDL used for ENUM extension (in-place; no table copy).
-- No ADD COLUMN IF NOT EXISTS (MariaDB-ism; schema_migrations provides
-- idempotency for the column add path).

-- ── 1. Add bc_no column ───────────────────────────────────────────────────────
ALTER TABLE `ord_orders`
  ADD COLUMN `bc_no` VARCHAR(40)
    COLLATE utf8mb4_unicode_ci
    NULL
    DEFAULT NULL
    COMMENT 'BC-assigned order number (e.g. ORD210070) returned on CREATE. NULL until published to BC. Written by push_bc_sales_orders.py in the same transaction as the source_ref rekey mt:<id>→bc:<No>.',
  ADD INDEX `idx_ord_orders_bc_no` (`bc_no`);

-- ── 2. Extend source ENUM to include ''maltytask'' ────────────────────────────
-- Full redefinition required by MySQL 8 to add an ENUM value.
-- Existing values: 'web','email','import','bc'
-- New value:       'maltytask'
-- CHARACTER SET + COLLATE must match the original column (utf8mb4_unicode_ci).
ALTER TABLE `ord_orders`
  MODIFY COLUMN `source`
    ENUM('web','email','import','bc','maltytask')
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci
    NOT NULL
    DEFAULT 'web'
    COMMENT 'Order origin: web=manual UI, email=email-parsed, import=WeeklyOrders sheet, bc=Business Central sync, maltytask=born in maltytask (pending BC publish)';

-- ── 3. schema_meta note ───────────────────────────────────────────────────────
UPDATE `schema_meta`
   SET writer_script = 'public/modules/expeditions.php, scripts/python/ingest_bc_sales_orders.py, scripts/python/push_bc_sales_orders.py',
       notes         = 'Order header. source=maltytask means born in maltytask, not yet published to BC. bc_no is set in the same transaction as the source_ref rekey (mt:<id>→bc:<No>) when push_bc_sales_orders.py confirms the BC CREATE. corrections_policy=allowed.',
       updated_at    = CURRENT_TIMESTAMP
 WHERE table_name = 'ord_orders';
