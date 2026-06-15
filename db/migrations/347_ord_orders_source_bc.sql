-- Migration 347: add 'bc' to ord_orders.source ENUM
--
-- Required before ingest_bc_sales_orders.py can write BC-sourced orders.
-- Source column had: enum('web','email','import') NOT NULL DEFAULT 'web'
-- After:            enum('web','email','import','bc') NOT NULL DEFAULT 'web'
--
-- MySQL 8 INSTANT DDL — no table copy on ENUM append.
-- MySQL 8 syntax: no ADD COLUMN IF NOT EXISTS (MariaDB extension only).

ALTER TABLE `ord_orders`
  MODIFY COLUMN `source`
    ENUM('web','email','import','bc')
    COLLATE utf8mb4_unicode_ci
    NOT NULL
    DEFAULT 'web'
    COMMENT 'Order origin: web=manual UI, email=email-parsed, import=WeeklyOrders sheet, bc=Business Central sync';
