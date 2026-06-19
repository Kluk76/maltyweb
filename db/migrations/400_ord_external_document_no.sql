-- 400_ord_external_document_no.sql
--
-- Surface BC's External_Document_No on ord_orders.
-- Allows the Expéditions board to display an external document reference
-- (e.g. customer PO or BL number) when BC carries it for an order.
-- BC field max is 35 chars; 50 gives headroom.

ALTER TABLE ord_orders
  ADD COLUMN external_document_no VARCHAR(50) NULL;
