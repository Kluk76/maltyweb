-- Add source_ref to ord_orders for idempotent WeeklyOrders cutover import.
-- Multiple NULLs are allowed under a MySQL UNIQUE key (web/email orders unaffected).
ALTER TABLE ord_orders ADD COLUMN source_ref VARCHAR(190) NULL AFTER source_file_id_fk;
ALTER TABLE ord_orders ADD UNIQUE KEY uniq_ord_source_ref (source_ref);
