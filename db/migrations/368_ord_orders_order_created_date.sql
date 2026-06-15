-- Migration 368: Add order_created_date to ord_orders
--
-- Persists BC Order_Date (the date the order was placed in BC) alongside
-- requested_date (BC Document_Date = delivery/loading date at client).
-- Lead time = DATEDIFF(requested_date, order_created_date).
-- This is a raw BC field — no derived flag is stored here; flagging is
-- computed at display time.

ALTER TABLE ord_orders
    ADD COLUMN order_created_date DATE NULL
        COMMENT 'BC Order_Date: date the order was placed. Lead-time = DATEDIFF(requested_date, order_created_date).'
        AFTER requested_date;

-- schema_meta note: ord_orders now tracks order creation date for lead-time analysis
UPDATE schema_meta
   SET notes = CONCAT(COALESCE(notes, ''), ' | 368: +order_created_date (BC Order_Date, lead-time source)')
 WHERE table_name = 'ord_orders';
