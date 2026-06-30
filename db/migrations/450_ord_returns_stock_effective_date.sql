-- Migration 450 — ord_returns: add stock_effective_date
-- Purpose: decouple the physical FG re-entry date from origin_posting_date
-- (the BC NC posting date) so the BC fact is never falsified.
-- NULL => restock leg falls back to origin_posting_date (existing rows unchanged).
-- This column is INERT until the fg-stock.php leg change lands (separate item).
-- No FK, no CHECK. No new schema_meta row (ord_returns already classified).

ALTER TABLE ord_returns
  ADD COLUMN stock_effective_date DATE NULL
  COMMENT 'Physical re-entry / FG-restock cutoff, decoupled from origin_posting_date (the BC NC posting date) so the BC fact is never falsified. NULL => restock leg falls back to origin_posting_date (existing rows unchanged).'
  AFTER origin_posting_date;
