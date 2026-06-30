-- 447_shopify_order_notes.sql
-- Add Shopify customer note fields to inv_sales_orders.
-- customer_note: free-text order.note from Shopify
-- note_attributes: JSON array of {name,value} pairs from order.note_attributes

ALTER TABLE inv_sales_orders
  ADD COLUMN customer_note TEXT NULL AFTER tracking_company,
  ADD COLUMN note_attributes JSON NULL AFTER customer_note;
