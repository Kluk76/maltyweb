-- 355_ord_order_bc_lines_source_ref.sql
--
-- Extension of Build B (BC operational-layer) to cover collision-skipped
-- transitional doubles (the "Pepitium case"):
--
-- When a BC order is collision-skipped (a non-bc kept row owns the customer+SKU),
-- Build B currently skips the BC order entirely — no snapshot, no divergence detection.
-- This means operator-corrected lines on the KEPT web/import row go undetected
-- (e.g. Pepitium id=133 was corrected to DGDB; its BC twin ORD210072 still carries DGDF).
--
-- Fix: for every collision-skipped BC order, snapshot its lines against the KEPT
-- maltytask row and diff.  The snapshot's `bc_source_ref` records WHICH BC order
-- the snapshot came from, because the kept row (source='web') has no own BC source_ref.
--
-- KEY DECISION — UNIQUE(order_id_fk, bc_source_ref, bc_line_no):
--
--   The original UNIQUE(order_id_fk, bc_line_no) assumed one ord_orders row holds
--   at most one set of BC lines (the row's own).  With collision-snapshots, a kept
--   non-bc row (source='web') now holds a snapshot from its BC twin:
--
--     order_id_fk=133  bc_source_ref='bc:ORD210072'  bc_line_no=10000  ← collision twin
--
--   A true bc-sourced row's snapshot uses its own source_ref:
--
--     order_id_fk=142  bc_source_ref='bc:ORD210067'  bc_line_no=10000  ← own
--
--   In principle a kept non-bc row could collide with MULTIPLE BC twins (if BC has
--   two different orders for the same customer/SKU set, both skipped) — the three-column
--   unique allows that without collision on (order_id_fk, bc_line_no) alone.
--
--   For bc-sourced rows: bc_source_ref = order.source_ref, so the key degenerates to
--   UNIQUE(order_id_fk, bc_source_ref, bc_line_no) which is still perfectly selective
--   (one source_ref per bc row → same cardinality as the old two-column key for them).
--
-- 1. Add bc_source_ref column (nullable — old rows get NULL, backfilled below).
-- 2. Drop old UNIQUE(order_id_fk, bc_line_no).
-- 3. Add new UNIQUE(order_id_fk, bc_source_ref, bc_line_no).
-- 4. Backfill bc_source_ref for existing rows from ord_orders.source_ref.
-- 5. Update schema_meta note.

-- ── 1. Add bc_source_ref column ───────────────────────────────────────────────
ALTER TABLE `ord_order_bc_lines`
  ADD COLUMN `bc_source_ref` varchar(190)
    COLLATE utf8mb4_unicode_ci
    DEFAULT NULL
    COMMENT 'BC order No that generated this snapshot (e.g. ''bc:ORD210072''). For source=''bc'' orders equals the order''s own source_ref; for collision-snapshots it is the skipped BC twin''s ref.'
  AFTER `order_id_fk`;

-- ── 2. Drop old unique key ────────────────────────────────────────────────────
ALTER TABLE `ord_order_bc_lines`
  DROP INDEX `uniq_bc_line`;

-- ── 3. Add new three-column unique key ───────────────────────────────────────
ALTER TABLE `ord_order_bc_lines`
  ADD UNIQUE KEY `uniq_bc_line` (`order_id_fk`, `bc_source_ref`, `bc_line_no`);

-- ── 4. Backfill bc_source_ref from the parent ord_orders.source_ref ──────────
-- Only bc-sourced rows exist today (snapshot was only written for source='bc' orders).
-- For those, bc_source_ref = ord_orders.source_ref (the order's own BC ref).
UPDATE `ord_order_bc_lines` bl
  JOIN `ord_orders` o ON o.id = bl.order_id_fk
   SET bl.bc_source_ref = o.source_ref
 WHERE bl.bc_source_ref IS NULL;

-- ── 5. schema_meta update ─────────────────────────────────────────────────────
INSERT INTO `schema_meta` (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'ord_order_bc_lines',
  'source',
  'allowed',
  'ingest_bc_sales_orders.py',
  'BC-side line snapshot for divergence diffing. bc_source_ref records which BC order generated the snapshot: for source=bc rows it is the order own source_ref; for collision-skipped BC twins it is the skipped twin ref (the kept non-bc row is used as order_id_fk). UNIQUE(order_id_fk, bc_source_ref, bc_line_no). READ-ONLY reference — never drives stock or COGS.'
) ON DUPLICATE KEY UPDATE notes = VALUES(notes), writer_script = VALUES(writer_script);
